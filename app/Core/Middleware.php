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
            'api'      => self::api($request, $next),
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

    /**
     * Authenticate a REST API request with a bearer token.
     *
     * Four things this does that the `auth` middleware does not, each of
     * which matters:
     *
     * **IT REPLACES `auth` RATHER THAN SITTING BESIDE IT.** A route carrying
     * both would accept a browser SESSION on the API, which means every API
     * route becomes reachable from a logged-in tab — and therefore CSRF-able,
     * since the API has no token form field. The API accepts one credential
     * and it is not a cookie.
     *
     * **IT NARROWS, VIA `Acl`.** `Auth::useApiToken()` records the token and
     * `Acl::can()` intersects every later check with its abilities, so the
     * `can:` middleware and every controller re-check work unchanged.
     *
     * **IT FAILS THE SAME WAY FOR EVERY REASON.** Unknown token, expired
     * token, deactivated owner, deleted owner: all 401 with one sentence.
     * Saying which would let a caller sort valid tokens from invalid ones.
     *
     * **IT NEVER REDIRECTS.** A 302 to the login page is a 200-with-HTML to
     * most HTTP clients, so an integration whose token expired would see a
     * login form where it expected JSON and report success.
     */
    private static function api(Request $request, callable $next): Response
    {
        $presented = self::bearer($request);

        if ($presented === null) {
            return Response::json(
                ['error' => 'This endpoint needs an API token in an Authorization: Bearer header.'],
                401
            )->header('WWW-Authenticate', 'Bearer realm="Castle CRM"');
        }

        $token = ApiToken::resolve($presented);

        if ($token === null) {
            // Logged, because a stream of these is how a venue finds out
            // somebody is guessing — and the ONLY place it is recorded, since
            // the response says nothing.
            Log::warning('API token rejected', [
                'ip'   => $request->ip(),
                'path' => $request->path(),
            ]);

            return Response::json(['error' => 'That API token is not valid.'], 401)
                ->header('WWW-Authenticate', 'Bearer realm="Castle CRM"');
        }

        if (!Auth::useApiToken($token)) {
            return Response::json(['error' => 'That API token is not valid.'], 401);
        }

        ApiToken::touch($token);

        return $next($request);
    }

    /**
     * The bearer token on this request.
     *
     * Read from the Authorization header, which some shared hosts strip from
     * the CGI environment — so `X-Api-Key` is accepted as well. The query
     * string is NOT: a token in a URL ends up in access logs, in the Referer
     * header of every outbound link, and in browser history.
     */
    private static function bearer(Request $request): ?string
    {
        $header = trim((string) $request->header('Authorization'));

        if ($header !== '' && stripos($header, 'bearer ') === 0) {
            $value = trim(substr($header, 7));

            return $value === '' ? null : $value;
        }

        $alternative = trim((string) $request->header('X-Api-Key'));

        return $alternative === '' ? null : $alternative;
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
