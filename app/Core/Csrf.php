<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Cross-site request forgery protection.
 *
 * One token per session, compared in constant time. Checked by middleware on
 * every mutating verb — a controller never has to remember to do it, which is
 * the only way this stays reliable as the app grows.
 */
final class Csrf
{
    public const FIELD  = '_token';
    public const HEADER = 'X-CSRF-Token';

    private const SESSION_KEY = '_csrf_token';

    /** The current token, minted on first use. */
    public static function token(): string
    {
        $token = Session::get(self::SESSION_KEY);

        if (!is_string($token) || strlen($token) !== 64) {
            $token = Encryption::randomToken(32);
            Session::put(self::SESSION_KEY, $token);
        }

        return $token;
    }

    /**
     * Is the request's token valid?
     *
     * Accepts the token from the form field, the X-CSRF-Token header (for
     * fetch/XHR), or the X-XSRF-Token header some clients send by convention.
     */
    public static function verify(Request $request): bool
    {
        $expected = Session::get(self::SESSION_KEY);

        if (!is_string($expected) || $expected === '') {
            // No token was ever issued for this session, so nothing legitimate
            // could be submitting one.
            return false;
        }

        $provided = $request->post(self::FIELD);

        if (!is_string($provided) || $provided === '') {
            $provided = $request->header(self::HEADER);
        }

        if ($provided === '') {
            $provided = $request->header('X-XSRF-Token');
        }

        if (!is_string($provided) || $provided === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    /** A ready-to-print hidden input. */
    public static function field(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            self::FIELD,
            htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8')
        );
    }

    /** A meta tag so JavaScript can read the token without inlining it in a script. */
    public static function metaTag(): string
    {
        return sprintf(
            '<meta name="csrf-token" content="%s">',
            htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * Rotate the token. Called on login and logout so a token captured from a
     * pre-authentication page cannot be reused against the authenticated one.
     */
    public static function rotate(): string
    {
        $token = Encryption::randomToken(32);
        Session::put(self::SESSION_KEY, $token);

        return $token;
    }
}
