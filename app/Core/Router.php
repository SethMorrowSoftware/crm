<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * An explicit route table.
 *
 * No annotation scanning, no reflection, no directory crawling — routes are
 * declared in app/routes.php and matched with one compiled regex per route.
 * That keeps cold-start cost near zero on shared hosting and means `grep` can
 * answer "what handles this URL?".
 */
final class Router
{
    /**
     * @var array<string, list<array{
     *     pattern:string, regex:string, params:list<string>,
     *     handler:array{0:string,1:string}|callable,
     *     middleware:list<string>, name:?string
     * }>>
     */
    private array $routes = [
        'GET' => [], 'POST' => [], 'PUT' => [], 'PATCH' => [], 'DELETE' => [],
    ];

    /** @var array<string,string> */
    private array $named = [];

    /** @var list<string> Middleware applied to every route in the current group. */
    private array $groupMiddleware = [];

    private string $groupPrefix = '';

    // -----------------------------------------------------------------
    // Declaration
    // -----------------------------------------------------------------

    /**
     * @param array{0:string,1:string}|callable $handler
     * @param list<string>                      $middleware
     */
    public function get(string $path, array|callable $handler, array $middleware = [], ?string $name = null): self
    {
        return $this->add('GET', $path, $handler, $middleware, $name);
    }

    /**
     * @param array{0:string,1:string}|callable $handler
     * @param list<string>                      $middleware
     */
    public function post(string $path, array|callable $handler, array $middleware = [], ?string $name = null): self
    {
        return $this->add('POST', $path, $handler, $middleware, $name);
    }

    /**
     * @param array{0:string,1:string}|callable $handler
     * @param list<string>                      $middleware
     */
    public function put(string $path, array|callable $handler, array $middleware = [], ?string $name = null): self
    {
        return $this->add('PUT', $path, $handler, $middleware, $name);
    }

    /**
     * @param array{0:string,1:string}|callable $handler
     * @param list<string>                      $middleware
     */
    public function patch(string $path, array|callable $handler, array $middleware = [], ?string $name = null): self
    {
        return $this->add('PATCH', $path, $handler, $middleware, $name);
    }

    /**
     * @param array{0:string,1:string}|callable $handler
     * @param list<string>                      $middleware
     */
    public function delete(string $path, array|callable $handler, array $middleware = [], ?string $name = null): self
    {
        return $this->add('DELETE', $path, $handler, $middleware, $name);
    }

    /**
     * Group routes under a shared prefix and middleware stack.
     *
     * @param list<string>   $middleware
     * @param callable(self):void $callback
     */
    public function group(string $prefix, array $middleware, callable $callback): self
    {
        $previousPrefix     = $this->groupPrefix;
        $previousMiddleware = $this->groupMiddleware;

        $this->groupPrefix     = $previousPrefix . rtrim($prefix, '/');
        $this->groupMiddleware = array_merge($previousMiddleware, $middleware);

        $callback($this);

        $this->groupPrefix     = $previousPrefix;
        $this->groupMiddleware = $previousMiddleware;

        return $this;
    }

    /**
     * @param array{0:string,1:string}|callable $handler
     * @param list<string>                      $middleware
     */
    private function add(
        string $method,
        string $path,
        array|callable $handler,
        array $middleware,
        ?string $name
    ): self {
        $path = $this->groupPrefix . $path;
        $path = '/' . trim($path, '/');

        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        [$regex, $params] = $this->compile($path);

        $this->routes[$method][] = [
            'pattern'    => $path,
            'regex'      => $regex,
            'params'     => $params,
            'handler'    => $handler,
            'middleware' => array_merge($this->groupMiddleware, $middleware),
            'name'       => $name,
        ];

        if ($name !== null) {
            $this->named[$name] = $path;
        }

        return $this;
    }

    /**
     * Turn "/contacts/{id}" into a regex plus the parameter names it captures.
     * An optional constraint follows a colon: "{id:\d+}", "{token:[a-f0-9]{64}}".
     *
     * The literal parts of the path are quoted and the constraints are spliced
     * in between them. Quoting the whole path first would not work: preg_quote
     * escapes the colon and the hyphen, which mangles both the delimiter and
     * character classes like [a-z0-9-].
     *
     * @return array{0:string,1:list<string>}
     */
    private function compile(string $path): array
    {
        // The constraint may itself contain {n} or {n,m} quantifiers, so the
        // inner alternative allows a braced digit group without letting the
        // match run past the placeholder's own closing brace.
        $placeholder = '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::((?:[^{}]|\{\d+(?:,\d*)?\})+))?\}/';

        $params = [];
        $regex  = '';
        $offset = 0;

        if (preg_match_all($placeholder, $path, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                [$whole, $wholeOffset] = $match[0];

                // The literal text between the previous placeholder and this one.
                $regex .= preg_quote(substr($path, $offset, $wholeOffset - $offset), '#');

                $params[] = $match[1][0];

                $constraint = isset($match[2]) && $match[2][0] !== '' ? $match[2][0] : '[^/]+';
                $regex .= '(' . $constraint . ')';

                $offset = $wholeOffset + strlen($whole);
            }
        }

        // Whatever follows the last placeholder.
        $regex .= preg_quote(substr($path, $offset), '#');

        return ['#^' . $regex . '$#', $params];
    }

    // -----------------------------------------------------------------
    // Dispatch
    // -----------------------------------------------------------------

    /**
     * Match the request and run its handler through the middleware stack.
     */
    public function dispatch(Request $request): Response
    {
        $method = $request->method();
        $path   = $request->path();

        // HEAD is GET without a body; let it fall through to GET handlers.
        $lookup = $method === 'HEAD' ? 'GET' : $method;

        if (!isset($this->routes[$lookup])) {
            return $this->methodNotAllowed($request, []);
        }

        foreach ($this->routes[$lookup] as $route) {
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }

            array_shift($matches);

            $params = [];
            foreach ($route['params'] as $i => $name) {
                $params[$name] = $matches[$i] ?? '';
            }

            $request->setRouteParams($params);

            return $this->runStack($route, $request);
        }

        // The path exists under a different verb — say so rather than 404ing,
        // which is a confusing answer to a form posting to a GET-only route.
        $allowed = $this->allowedMethodsFor($path);

        if ($allowed !== [] && $lookup !== 'GET') {
            return $this->methodNotAllowed($request, $allowed);
        }

        return $this->notFound($request);
    }

    /**
     * @param array{handler:array{0:string,1:string}|callable, middleware:list<string>} $route
     */
    private function runStack(array $route, Request $request): Response
    {
        // Build the chain inside-out so each middleware wraps the next.
        $next = function (Request $request) use ($route): Response {
            return $this->invoke($route['handler'], $request);
        };

        foreach (array_reverse($route['middleware']) as $name) {
            $current = $next;
            $next = static function (Request $request) use ($name, $current): Response {
                return Middleware::run($name, $request, $current);
            };
        }

        return $next($request);
    }

    /**
     * @param array{0:string,1:string}|callable $handler
     */
    private function invoke(array|callable $handler, Request $request): Response
    {
        if (is_callable($handler) && !is_array($handler)) {
            $result = $handler($request);

            return $result instanceof Response ? $result : Response::html((string) $result);
        }

        [$class, $action] = $handler;

        $fqcn = str_starts_with($class, 'App\\') ? $class : 'App\\Controllers\\' . $class;

        if (!class_exists($fqcn)) {
            throw new RuntimeException("Controller {$fqcn} does not exist.");
        }

        $controller = new $fqcn();

        if (!method_exists($controller, $action)) {
            throw new RuntimeException("Controller {$fqcn} has no action {$action}().");
        }

        $result = $controller->{$action}($request);

        if ($result instanceof Response) {
            return $result;
        }

        // A controller that returns a string is rendering HTML; one that
        // returns an array is answering an API call.
        if (is_array($result)) {
            return Response::json($result);
        }

        return Response::html((string) $result);
    }

    /** @return list<string> */
    private function allowedMethodsFor(string $path): array
    {
        $allowed = [];

        foreach ($this->routes as $method => $routes) {
            foreach ($routes as $route) {
                if (preg_match($route['regex'], $path) === 1) {
                    $allowed[] = $method;
                    break;
                }
            }
        }

        return $allowed;
    }

    // -----------------------------------------------------------------
    // Error responses
    // -----------------------------------------------------------------

    private function notFound(Request $request): Response
    {
        if ($request->wantsJson()) {
            return Response::error('Not found.', 404);
        }

        return Response::html(View::renderError(404, 'Page not found', [
            'message' => 'That page does not exist, or it moved.',
        ]), 404);
    }

    /** @param list<string> $allowed */
    private function methodNotAllowed(Request $request, array $allowed): Response
    {
        $response = $request->wantsJson()
            ? Response::error('Method not allowed.', 405, ['allowed' => $allowed])
            : Response::html(View::renderError(405, 'Method not allowed', [
                'message' => 'That action used the wrong request method.',
            ]), 405);

        if ($allowed !== []) {
            $response->header('Allow', implode(', ', $allowed));
        }

        return $response;
    }

    // -----------------------------------------------------------------
    // URL generation
    // -----------------------------------------------------------------

    /**
     * Build a URL for a named route, substituting {placeholders}.
     *
     * @param array<string,string|int> $params
     */
    public function url(string $name, array $params = []): string
    {
        if (!isset($this->named[$name])) {
            throw new RuntimeException("No route named '{$name}'.");
        }

        $path = $this->named[$name];

        foreach ($params as $key => $value) {
            $path = (string) preg_replace(
                '/\{' . preg_quote((string) $key, '/') . '(?::[^}]+)?\}/',
                rawurlencode((string) $value),
                $path
            );
        }

        return $path;
    }

    public function hasName(string $name): bool
    {
        return isset($this->named[$name]);
    }

    /** @return array<string,string> */
    public function namedRoutes(): array
    {
        return $this->named;
    }

    public function routeCount(): int
    {
        return array_sum(array_map('count', $this->routes));
    }
}
