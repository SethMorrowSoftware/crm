<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Acl;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;

/**
 * Shared controller behaviour.
 *
 * The validation helpers matter most: validate() either returns clean data or
 * throws a redirect-carrying exception, so a controller action reads as a
 * straight line rather than nesting every write inside an if-passes block.
 */
abstract class Controller
{
    /**
     * Render a view in the application layout.
     *
     * @param array<string,mixed> $data
     */
    protected function view(string $view, array $data = [], string $layout = 'layouts/app'): Response
    {
        return Response::html(View::render($view, $data, $layout));
    }

    /** @param array<string,mixed> $data */
    protected function json(array $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    protected function redirect(string $path, int $status = 302): Response
    {
        return Response::redirect(url($path), $status);
    }

    /** Back to the referring page, or a fallback when there is no referrer. */
    protected function back(Request $request, string $fallback = '/'): Response
    {
        $referrer = $request->referrer();

        // Only follow a referrer on our own host — an open redirect from a
        // Referer header is a phishing primitive.
        if ($referrer !== '' && $this->isSameHost($referrer, $request)) {
            return Response::redirect($referrer);
        }

        return $this->redirect($fallback);
    }

    /**
     * Validate request input.
     *
     * On failure the errors and the submitted values are flashed and a redirect
     * back is returned, so the caller does:
     *
     *   $result = $this->validate($request, [...]);
     *   if ($result instanceof Response) { return $result; }
     *
     * @param array<string,string> $rules
     * @param array<string,string> $labels
     * @return array<string,mixed>|Response
     */
    protected function validate(Request $request, array $rules, array $labels = []): array|Response
    {
        $validator = Validator::make($request->all(), $rules, $labels);

        if ($validator->passes()) {
            return $validator->validated();
        }

        Session::put('_errors', $validator->errors());

        // Keep what they typed, minus anything sensitive — a password should
        // never survive a redirect, even in a server-side session.
        $input = $request->all();
        unset(
            $input['password'],
            $input['password_confirmation'],
            $input['current_password'],
            $input['_token']
        );
        Session::put('_old_input_next', $input);

        flash('error', $validator->firstError() ?? 'Please check the form and try again.');

        return $this->back($request);
    }

    /**
     * Re-check a permission inside an action.
     *
     * Middleware already guards the route; this is the second check the spec's
     * quality gates call for, for the case where an action is reachable by more
     * than one route or a later refactor moves it.
     */
    protected function authorize(string $permission): ?Response
    {
        if (Acl::can($permission)) {
            return null;
        }

        return Response::html(View::renderError(403, 'Not permitted', [
            'message' => 'Your role does not include this. An administrator can grant it in Settings → Roles.',
        ]), 403);
    }

    protected function notFound(string $message = 'That record does not exist, or it was deleted.'): Response
    {
        return Response::html(View::renderError(404, 'Not found', ['message' => $message]), 404);
    }

    /**
     * A 422 for an action that cannot proceed for a business reason rather
     * than a validation one — "that date is already booked", say.
     */
    protected function refuse(Request $request, string $message, string $fallback = '/'): Response
    {
        if ($request->wantsJson()) {
            return Response::error($message, 422);
        }

        flash('error', $message);

        return $this->back($request, $fallback);
    }

    protected function success(Request $request, string $message, string $redirectTo): Response
    {
        if ($request->wantsJson()) {
            return Response::json(['ok' => true, 'message' => $message]);
        }

        flash('success', $message);

        return $this->redirect($redirectTo);
    }

    /**
     * Where to send a user after login — their intended destination, if it was
     * a safe local path.
     */
    protected function intendedUrl(string $fallback = '/'): string
    {
        $intended = Session::pull('intended_url');

        if (!is_string($intended) || $intended === '') {
            return $fallback;
        }

        // Must be a local absolute path. Rejecting "//evil.test" matters here:
        // a browser reads that as a protocol-relative URL.
        if (!str_starts_with($intended, '/') || str_starts_with($intended, '//')) {
            return $fallback;
        }

        return $intended;
    }

    private function isSameHost(string $url, Request $request): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        // A relative referrer has no host, which means same-origin.
        if ($host === null || $host === false) {
            return true;
        }

        return strcasecmp((string) $host, (string) parse_url('https://' . $request->host(), PHP_URL_HOST)) === 0;
    }

    /**
     * Read pagination parameters, clamped so a crafted ?per_page=100000 cannot
     * ask the database for the whole table.
     *
     * @return array{page:int,perPage:int,offset:int}
     */
    protected function pagination(Request $request, int $default = 50, int $max = 200): array
    {
        $page    = max(1, $request->int('page', 1));
        $perPage = $request->int('per_page', $default);
        $perPage = max(10, min($max, $perPage));

        return [
            'page'    => $page,
            'perPage' => $perPage,
            'offset'  => ($page - 1) * $perPage,
        ];
    }
}
