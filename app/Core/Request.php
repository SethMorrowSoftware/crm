<?php

declare(strict_types=1);

namespace App\Core;

/**
 * The current HTTP request.
 *
 * Input is returned raw — escaping happens at output time (see View::e) and
 * validation happens explicitly (see Validator). A getter that silently
 * sanitised would make it impossible to tell what the user actually sent.
 */
final class Request
{
    /** @var array<string,mixed> */
    private array $query;

    /** @var array<string,mixed> */
    private array $post;

    /** @var array<string,mixed> */
    private array $files;

    /** @var array<string,string> */
    private array $cookies;

    /** @var array<string,string> */
    private array $headers;

    /** @var array<string,string> */
    private array $routeParams = [];

    private string $method;

    private string $path;

    /**
     * Resolved once per request, not once per process: a static cache here
     * made the value leak between Request objects, which is wrong in the test
     * suite and in any long-running worker.
     */
    private ?string $basePath = null;

    private ?string $rawBody = null;

    /** @var array<string,mixed>|null */
    private ?array $jsonCache = null;

    private ?string $jsonShapeCache = null;

    public function __construct()
    {
        $this->query   = $_GET;
        $this->post    = $_POST;
        $this->files   = $_FILES;
        $this->cookies = array_map('strval', $_COOKIE);
        $this->headers = $this->collectHeaders();
        $this->method  = $this->resolveMethod();
        $this->path    = $this->resolvePath();
    }

    public static function capture(): self
    {
        return new self();
    }

    // -----------------------------------------------------------------
    // Basics
    // -----------------------------------------------------------------

    public function method(): string
    {
        return $this->method;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    /** True for verbs that change state and therefore need a CSRF token. */
    public function isMutating(): bool
    {
        return in_array($this->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    /** The request path, always leading-slashed and without a trailing slash. */
    public function path(): string
    {
        return $this->path;
    }

    public function isSecure(): bool
    {
        if (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }

        // Only believe a forwarded header when the operator has told us we are
        // behind a proxy; otherwise a client could claim its own scheme.
        if (Config::get('trust_proxy', false)) {
            $proto = strtolower($this->header('X-Forwarded-Proto', ''));
            if ($proto === 'https') {
                return true;
            }
        }

        return false;
    }

    public function host(): string
    {
        if (Config::get('trust_proxy', false)) {
            $forwarded = $this->header('X-Forwarded-Host', '');
            if ($forwarded !== '') {
                return trim(explode(',', $forwarded)[0]);
            }
        }

        return (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
    }

    /**
     * The client IP. Honours X-Forwarded-For only when trust_proxy is on,
     * because rate limiting and the audit log depend on this being truthful.
     */
    public function ip(): string
    {
        if (Config::get('trust_proxy', false)) {
            $forwarded = $this->header('X-Forwarded-For', '');

            if ($forwarded !== '') {
                foreach (explode(',', $forwarded) as $candidate) {
                    $candidate = trim($candidate);
                    if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                        return $candidate;
                    }
                }
            }
        }

        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : '0.0.0.0';
    }

    public function userAgent(): string
    {
        return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    }

    public function referrer(): string
    {
        return substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 1000);
    }

    // -----------------------------------------------------------------
    // Input
    // -----------------------------------------------------------------

    /** Query string, then body, then route parameters. */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key]
            ?? $this->query[$key]
            ?? $this->routeParams[$key]
            ?? $this->json()[$key]
            ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    /**
     * Every posted field, and ONLY the posted fields.
     *
     * Unlike `all()`, which merges the query string, the JSON body and the
     * route parameters. A provider that signs its webhook signs exactly the
     * form fields it posted, so reproducing that signature needs this set
     * and not a superset — one extra route parameter in the mix and the
     * signature never matches again.
     *
     * @return array<string,mixed>
     */
    public function postAll(): array
    {
        return $this->post;
    }

    /** A trimmed string, guaranteed scalar. Arrays become ''. */
    public function str(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);

        if (is_array($value) || is_object($value)) {
            return '';
        }

        return trim((string) $value);
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->input($key, $default);

        return is_numeric($value) ? (float) $value : $default;
    }

    /** HTML checkboxes send nothing when unchecked, so absence means false. */
    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->input($key, null);

        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return list<mixed> */
    public function array(string $key): array
    {
        $value = $this->input($key, []);

        return is_array($value) ? array_values($value) : [];
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return array_merge($this->query, $this->post, $this->json(), $this->routeParams);
    }

    /**
     * Only the listed keys, and only those actually present — so a partial
     * form submission cannot blank out fields it did not include.
     *
     * @param list<string> $keys
     * @return array<string,mixed>
     */
    public function only(array $keys): array
    {
        $all = $this->all();
        $out = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $all)) {
                $out[$key] = $all[$key];
            }
        }

        return $out;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function filled(string $key): bool
    {
        $value = $this->input($key);

        return $value !== null && $value !== '' && $value !== [];
    }

    // -----------------------------------------------------------------
    // Body / JSON
    // -----------------------------------------------------------------

    public function rawBody(): string
    {
        if ($this->rawBody === null) {
            $this->rawBody = (string) file_get_contents('php://input');
        }

        return $this->rawBody;
    }

    public function isJson(): bool
    {
        return str_contains(strtolower($this->header('Content-Type', '')), 'application/json');
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        if ($this->jsonCache !== null) {
            return $this->jsonCache;
        }

        if (!$this->isJson()) {
            return $this->jsonCache = [];
        }

        $decoded = json_decode($this->rawBody(), true);

        return $this->jsonCache = is_array($decoded) ? $decoded : [];
    }

    /**
     * What the body ACTUALLY is: 'object', 'list', 'scalar', 'empty' or
     * 'invalid'.
     *
     * `json()` cannot answer this and is not meant to: it is typed `: array`
     * because every caller wants a set of fields, so a list, a scalar and
     * unparseable text all flatten into `[]` — indistinguishable from `{}`.
     * A handler reading only that cannot tell "you sent nothing" from "you
     * sent [1,2,3]", and so answers the second with a message about a field
     * the caller never had any way to send. This is the missing half, kept
     * separate so `json()`'s contract does not change.
     *
     * Decoded WITHOUT the assoc flag on purpose: `json_decode('{}', true)`
     * and `json_decode('[]', true)` are both `[]`, and only leaving objects
     * as objects tells the empty object from the empty list.
     *
     * Describes the bytes, not the Content-Type header — `json()` returns
     * `[]` for a body that is not labelled `application/json`, so a JSON
     * object sent with the wrong label still reads as 'object' here and is
     * then refused by whatever field check comes next, exactly as before.
     */
    public function jsonShape(): string
    {
        if ($this->jsonShapeCache !== null) {
            return $this->jsonShapeCache;
        }

        if (trim($this->rawBody()) === '') {
            return $this->jsonShapeCache = 'empty';
        }

        $decoded = json_decode($this->rawBody());

        // `json_decode('null')` is a successful decode that returns null, so
        // the error code is what separates a null body from unparseable text.
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->jsonShapeCache = 'invalid';
        }

        if ($decoded instanceof \stdClass) {
            return $this->jsonShapeCache = 'object';
        }

        return $this->jsonShapeCache = is_array($decoded) ? 'list' : 'scalar';
    }

    /** Does the client want JSON back? */
    public function wantsJson(): bool
    {
        if ($this->isJson()) {
            return true;
        }

        if (str_contains($this->header('Accept', ''), 'application/json')) {
            return true;
        }

        return $this->isAjax() || str_starts_with($this->path, '/api/');
    }

    public function isAjax(): bool
    {
        return strtolower($this->header('X-Requested-With', '')) === 'xmlhttprequest';
    }

    // -----------------------------------------------------------------
    // Headers, cookies, files
    // -----------------------------------------------------------------

    public function header(string $name, string $default = ''): string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('Authorization');

        if (preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        return $this->cookies[$name] ?? $default;
    }

    /** @return array<string,mixed>|null */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;

        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        return $file;
    }

    // -----------------------------------------------------------------
    // Route parameters (set by the router after matching)
    // -----------------------------------------------------------------

    /** @param array<string,string> $params */
    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function route(string $key, ?string $default = null): ?string
    {
        return $this->routeParams[$key] ?? $default;
    }

    public function routeInt(string $key, int $default = 0): int
    {
        $value = $this->routeParams[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    // -----------------------------------------------------------------
    // Resolution helpers
    // -----------------------------------------------------------------

    private function resolveMethod(): string
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        // HTML forms can only GET or POST, so a hidden _method field lets a
        // form express PUT/PATCH/DELETE. Only honoured on a real POST.
        if ($method === 'POST') {
            $override = strtoupper((string) ($this->post['_method'] ?? ''));

            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $override;
            }
        }

        return $method;
    }

    private function resolvePath(): string
    {
        // ?_route=/path is the fallback for hosts without mod_rewrite.
        $raw = (string) ($this->query['_route'] ?? self::rawPath());

        // Strip the subdirectory the app is installed under, so routes are
        // always written from the application root.
        $base = $this->basePath();

        if ($base !== '' && str_starts_with($raw, $base)) {
            $raw = substr($raw, strlen($base));
        }

        $path = '/' . trim(rawurldecode($raw), '/');

        // Collapse duplicate slashes and reject traversal attempts outright.
        $path = (string) preg_replace('#/+#', '/', $path);

        if (str_contains($path, '..')) {
            $path = '/';
        }

        // Trailing slashes are stripped so '/contacts' and '/contacts/' are the
        // same route; the root path survives as '/'.
        $trimmed = rtrim($path, '/');

        return $trimmed === '' ? '/' : $trimmed;
    }

    /**
     * The request path, cut out of REQUEST_URI by hand.
     *
     * Deliberately NOT parse_url(). A URI beginning with two slashes is a
     * protocol-relative URL, so parse_url('//app/thing') reports `app` as the
     * HOST and `/thing` as the path — which silently drops the first segment.
     * Routing on that is wrong (`//settings/mail` would resolve to `/mail`),
     * and any check that looks for a protected first segment would be looking
     * at a path that no longer has one.
     *
     * Cutting at the first '?' or '#' and collapsing the slashes ourselves has
     * neither problem, and REQUEST_URI is never a full URL in practice — not
     * even for an absolute-form request line, which only a proxy receives.
     */
    public static function rawPath(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');

        foreach (['?', '#'] as $delimiter) {
            $position = strpos($uri, $delimiter);

            if ($position !== false) {
                $uri = substr($uri, 0, $position);
            }
        }

        // An absolute-form target (http://host/path) would otherwise be read
        // as a path. Keep only what follows the authority.
        if (preg_match('#^[a-z][a-z0-9+.-]*://[^/]*(/.*)?$#i', $uri, $match) === 1) {
            $uri = $match[1] ?? '/';
        }

        $uri = (string) preg_replace('#/{2,}#', '/', $uri);

        return $uri === '' ? '/' : $uri;
    }

    /**
     * The subdirectory this install is served from, derived from the front
     * controller's location — so the same code works at a domain root and at
     * /crm/ without configuration.
     */
    public function basePath(): string
    {
        if ($this->basePath !== null) {
            return $this->basePath;
        }

        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));

        // SCRIPT_NAME is only meaningful here when it actually names the front
        // controller, which is how Apache and most FastCGI setups report it
        // (`/index.php`, or `/crm/index.php` for a subdirectory install).
        //
        // PHP's built-in server reports something else: for a path whose last
        // segment contains a dot it sets SCRIPT_NAME to the WHOLE REQUEST PATH,
        // because it guesses the request is for a file. Taking dirname() of
        // that strips real route segments — so a route with a filename in a
        // parameter, such as /settings/backups/castle-crm-….sql.gz/download,
        // arrived at the router as just /download and 404d. Some nginx
        // configurations with fastcgi_split_path_info get this wrong the same
        // way, so the guard belongs here rather than in the dev server.
        if (!str_ends_with(strtolower($script), '.php')) {
            return $this->basePath = '';
        }

        $dir = rtrim(dirname($script), '/');

        return $this->basePath = ($dir === '/' || $dir === '.') ? '' : $dir;
    }

    /** @return array<string,string> */
    private function collectHeaders(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }

        // These two arrive without the HTTP_ prefix.
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $server => $name) {
            if (isset($_SERVER[$server])) {
                $headers[$name] = (string) $_SERVER[$server];
            }
        }

        /*
         * THE AUTHORIZATION HEADER ON cPANEL.
         *
         * Apache with mod_rewrite does not pass `Authorization` through to
         * CGI/FastCGI unless it is told to, and the venue this is built for
         * runs on exactly that. The symptom is the worst kind: every API
         * token is rejected with "this endpoint needs an API token", the
         * header is visibly in the request, and nothing anywhere says the
         * server ate it.
         *
         * `.htaccess` asks for it back under this name (see the rule there),
         * and this is the other half. Only used when the real header is
         * absent, so a server that passes it properly is unaffected.
         */
        if (!isset($headers['authorization'])) {
            foreach (['REDIRECT_HTTP_AUTHORIZATION', 'HTTP_X_AUTHORIZATION'] as $fallback) {
                if (isset($_SERVER[$fallback]) && (string) $_SERVER[$fallback] !== '') {
                    $headers['authorization'] = (string) $_SERVER[$fallback];

                    break;
                }
            }
        }

        return $headers;
    }
}
