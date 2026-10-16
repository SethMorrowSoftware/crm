<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Named middleware, resolved by the router.
 *
 * Declaring these as strings on a route ("auth", "can:deals_manage") keeps the
 * route table readable and means a permission check can never be forgotten on
 * a new route — it is part of the route's definition, not the controller body.
 *
 * Controllers still re-check before a write (see the quality gates in the
 * spec): middleware guards the door, the controller guards the till.
 */
final class Middleware
{
    /**
     * @param callable(Request):Response $next
     */
    public static function run(string $definition, Request $request, callable $next): Response
    {
        // "can:deals_manage" → name "can", argument "deals_manage"
        [$name, $argument] = array_pad(explode(':', $definition, 2), 2, null);

        return match ($name) {
            'auth'     => self::auth($request, $next),
            'guest'    => self::guest($request, $next),
            'csrf'     => self::csrf($request, $next),
            'can'      => self::can($request, $next, (string) $argument),
            'throttle' => self::throttle($request, $next, (string) $argument),
            'installed' => self::installed($request, $next),
            default    => throw new RuntimeException("Unknown middleware '{$name}'."),
        };
    }

    /** Require an authenticated user. */
    private static function auth(Request $request, callable $next): Response
    {
        if (Auth::check()) {
            return $next($request);
        }

        if ($request->wantsJson()) {
            return Response::error('Authentication required.', 401);
        }

        // Remember where they were going so login can return them there.
        Session::put('intended_url', $request->path());

        return Response::redirect(url('/login'));
    }

    /** Require that nobody is logged in — for the login page itself. */
    private static function guest(Request $request, callable $next): Response
    {
        if (Auth::check()) {
            return Response::redirect(url('/'));
        }

        return $next($request);
    }

    /** Reject mutating requests without a valid CSRF token. */
    private static function csrf(Request $request, callable $next): Response
    {
        if (!$request->isMutating()) {
            return $next($request);
        }

        if (Csrf::verify($request)) {
            return $next($request);
        }

        Log::warning('CSRF check failed', [
            'path' => $request->path(),
            'ip'   => $request->ip(),
        ]);

        if ($request->wantsJson()) {
            return Response::error('Your session expired. Reload the page and try again.', 419);
        }

        return Response::html(View::renderError(
            419,
            'Your session expired',
            ['message' => 'For your security the form was not submitted. Reload the page and try again.']
        ), 419);
    }

    /** Require a permission. */
    private static function can(Request $request, callable $next, string $permission): Response
    {
        if ($permission === '') {
            throw new RuntimeException('The "can" middleware needs a permission, e.g. can:deals_manage.');
        }

        if (!isset(Acl::CATALOG[$permission])) {
            // A typo must not become an open door.
            throw new RuntimeException("Permission '{$permission}' is not in the Acl catalogue.");
        }

        if (Acl::can($permission)) {
            return $next($request);
        }

        if (!Auth::check()) {
            return self::auth($request, $next);
        }

        Log::notice('Permission denied', [
            'user_id'    => Auth::id(),
            'permission' => $permission,
            'path'       => $request->path(),
        ]);

        if ($request->wantsJson()) {
            return Response::error('You do not have permission to do that.', 403);
        }

        return Response::html(View::renderError(403, 'Not permitted', [
            'message' => 'Your role does not include this. An administrator can grant it in Settings → Roles.',
        ]), 403);
    }

    /**
     * Rate limit by IP and route.
     *
     * Argument form: "attempts,minutes" — e.g. "throttle:10,1".
     *
     * THE METHOD IS PART OF THE KEY, and leaving it out was a real bug rather
     * than a nicety. Four paths in this application answer both GET and POST,
     * and three of them set a generous limit on reading and a tight one on
     * writing — a public form, the waiver, the booking page. Keyed on the path
     * alone, those two limits share one counter, so READING the page spends the
     * allowance for SENDING it: a family comparing packages and dates makes a
     * dozen GETs of /book before they type anything, and the eleventh of them
     * made the enquiry itself answer 429 for the next ten minutes. MEASURED in
     * a browser walk, which is the only place it shows — every one of those
     * requests is individually well inside its own limit.
     *
     * A method is also the right grain on its own terms: a reader and a writer
     * of the same URL are not the same action, and the tight limit exists to
     * bound the writes.
     */
    private static function throttle(Request $request, callable $next, string $argument): Response
    {
        [$attempts, $minutes] = array_pad(explode(',', $argument), 2, null);

        $maxAttempts = max(1, (int) ($attempts ?? 60));
        $windowMins  = max(1, (int) ($minutes ?? 1));

        $key = 'throttle:' . sha1($request->method() . '|' . $request->ip() . '|' . $request->path());

        $hits = Cache::increment($key, $windowMins * 60);

        if ($hits > $maxAttempts) {
            $retryAfter = Cache::ttl($key) ?? ($windowMins * 60);

            Log::warning('Rate limit hit', [
                'ip'   => $request->ip(),
                'path' => $request->path(),
                'hits' => $hits,
            ]);

            $response = $request->wantsJson()
                ? Response::error('Too many requests. Slow down.', 429)
                : Response::html(View::renderError(429, 'Too many requests', [
                    'message' => 'You have tried that too many times. Wait a moment and try again.',
                ]), 429);

            return $response->header('Retry-After', (string) $retryAfter);
        }

        return $next($request);
    }

    /** Redirect to the installer when the app has no configuration yet. */
    private static function installed(Request $request, callable $next): Response
    {
        if (!Config::isLoaded() || Config::get('db.name') === null) {
            return Response::redirect(url('/install/'));
        }

        return $next($request);
    }
}
