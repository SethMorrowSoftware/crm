<?php

declare(strict_types=1);

/**
 * Router.
 *
 * Every constrained-placeholder test here exists because of a real bug:
 * preg_quote escapes ':' and '-', so quoting the whole pattern before
 * substituting placeholders broke every route with a constraint —
 * /playbooks/{slug:[a-z0-9-]+} matched nothing at all.
 */

use App\Core\Router;
use Tests\Assert;
use Tests\Harness;

Harness::suite('unit/router');

/**
 * Compile a route pattern, reaching through to the private method — the
 * alternative is exposing an API only tests would use.
 *
 * @return array{0:string,1:list<string>}
 */
function route_compile(string $pattern): array
{
    $method = new ReflectionMethod(Router::class, 'compile');
    $method->setAccessible(true);

    return $method->invoke(new Router(), $pattern);
}

function route_matches(string $pattern, string $path): bool
{
    [$regex] = route_compile($pattern);

    return preg_match($regex, $path) === 1;
}

/** @return array<string,string> */
function route_params(string $pattern, string $path): array
{
    [$regex, $names] = route_compile($pattern);

    if (preg_match($regex, $path, $matches) !== 1) {
        return [];
    }

    array_shift($matches);

    return array_combine($names, $matches);
}

// ----------------------------------------------------------- static paths

Harness::test('the root path matches only itself', function (): void {
    Assert::true(route_matches('/', '/'));
    Assert::false(route_matches('/', '/anything'));
});

Harness::test('a static path matches exactly', function (): void {
    Assert::true(route_matches('/settings/venue', '/settings/venue'));
    Assert::false(route_matches('/settings/venue', '/settings/venues'));
    Assert::false(route_matches('/settings/venue', '/settings'));
    Assert::false(route_matches('/settings/venue', '/settings/venue/extra'));
});

Harness::test('a dot in a path is literal, not a wildcard', function (): void {
    Assert::true(route_matches('/health.json', '/health.json'));
    Assert::false(route_matches('/health.json', '/healthXjson'), 'the dot must be escaped');
});

// --------------------------------------------------- unconstrained params

Harness::test('an unconstrained placeholder captures one segment', function (): void {
    Assert::true(route_matches('/contacts/{id}', '/contacts/42'));
    Assert::true(route_matches('/contacts/{id}', '/contacts/anything'));
    Assert::false(route_matches('/contacts/{id}', '/contacts/42/edit'), 'must not cross a slash');
    Assert::false(route_matches('/contacts/{id}', '/contacts/'));
});

Harness::test('a placeholder value is captured under its name', function (): void {
    Assert::same(['id' => '42'], route_params('/contacts/{id}', '/contacts/42'));
});

// ----------------------------------------------------- constrained params

Harness::test('a hyphenated character class works', function (): void {
    // The original failure: preg_quote turned [a-z0-9-] into [a\-z0\-9\-].
    Assert::true(
        route_matches('/playbooks/{slug:[a-z0-9-]+}', '/playbooks/birthday-celebration-sales'),
        'a hyphenated slug must match'
    );
    Assert::false(route_matches('/playbooks/{slug:[a-z0-9-]+}', '/playbooks/Bad_Slug'));
});

Harness::test('a numeric constraint rejects non-numbers', function (): void {
    Assert::true(route_matches('/settings/users/{id:\d+}', '/settings/users/7'));
    Assert::false(route_matches('/settings/users/{id:\d+}', '/settings/users/abc'));
    Assert::false(route_matches('/settings/users/{id:\d+}', '/settings/users/7x'));
});

Harness::test('a braced quantifier inside a constraint survives compilation', function (): void {
    // {token:[a-f0-9]{64}} — the inner {64} must not be read as the end of
    // the placeholder.
    $pattern = '/reset-password/{token:[a-f0-9]{64}}';

    Assert::true(route_matches($pattern, '/reset-password/' . str_repeat('a', 64)));
    Assert::false(route_matches($pattern, '/reset-password/' . str_repeat('a', 63)));
    Assert::false(route_matches($pattern, '/reset-password/' . str_repeat('z', 64)), 'z is not hex');
    Assert::false(route_matches($pattern, '/reset-password/short'));
});

Harness::test('a token is captured from a constrained placeholder', function (): void {
    $token = str_repeat('ab', 32);
    $params = route_params('/reset-password/{token:[a-f0-9]{64}}', '/reset-password/' . $token);

    Assert::same($token, $params['token'] ?? null);
});

Harness::test('multiple placeholders capture in order', function (): void {
    $params = route_params('/a/{one}/b/{two:\d+}', '/a/xyz/b/99');

    Assert::same('xyz', $params['one'] ?? null);
    Assert::same('99', $params['two'] ?? null);
});

// ---------------------------------------------------------- registration

Harness::test('a named route generates its URL with parameters substituted', function (): void {
    $router = new Router();
    $router->get('/settings/users/{id:\d+}', ['UserController', 'edit'], [], 'users.edit');

    Assert::same('/settings/users/42', $router->url('users.edit', ['id' => 42]));
});

Harness::test('generating an unknown named route throws', function (): void {
    $router = new Router();

    Assert::throws(RuntimeException::class, static fn () => $router->url('nope'));
});

Harness::test('groups compose prefixes and middleware', function (): void {
    $router = new Router();

    $router->group('/settings', ['auth'], static function (Router $router): void {
        $router->get('/venue', ['SettingsController', 'venue'], [], 'settings.venue');
    });

    Assert::same('/settings/venue', $router->url('settings.venue'));
});

Harness::test('nested groups compose prefixes', function (): void {
    $router = new Router();

    $router->group('/a', [], static function (Router $router): void {
        $router->group('/b', [], static function (Router $router): void {
            $router->get('/c', ['X', 'y'], [], 'abc');
        });
    });

    Assert::same('/a/b/c', $router->url('abc'));
});

Harness::test('a trailing slash is normalised away at registration', function (): void {
    $router = new Router();
    $router->get('/contacts/', ['X', 'y'], [], 'contacts');

    Assert::same('/contacts', $router->url('contacts'));
});

Harness::test('every route in the real table compiles', function (): void {
    // Catches a malformed constraint in app/routes.php before it 404s in
    // production. The handlers are not invoked, only the patterns compiled.
    $router = new Router();

    Assert::doesNotThrow(static function () use ($router): void {
        require CC_ROOT . '/app/routes.php';
    }, 'app/routes.php must compile without error');

    Assert::greaterThan(30, $router->routeCount(), 'the route table should be populated');
});

// --------------------------------------------------- request path resolution

/**
 * Build a Request for a given REQUEST_URI and SCRIPT_NAME.
 *
 * Request reads the superglobals in its constructor, so they are set, read and
 * restored around each case.
 */
function request_for(string $requestUri, string $scriptName): App\Core\Request
{
    $previous = [$_SERVER['REQUEST_URI'] ?? null, $_SERVER['SCRIPT_NAME'] ?? null, $_GET];

    $_SERVER['REQUEST_URI'] = $requestUri;
    $_SERVER['SCRIPT_NAME'] = $scriptName;
    $_GET = [];

    try {
        return App\Core\Request::capture();
    } finally {
        [$_SERVER['REQUEST_URI'], $_SERVER['SCRIPT_NAME'], $_GET] = $previous;
    }
}

Harness::test('a route is resolved from the front controller at the document root', function (): void {
    Assert::same('/settings/backups', request_for('/settings/backups', '/index.php')->path());
});

Harness::test('a subdirectory install has its prefix stripped', function (): void {
    $request = request_for('/crm/settings/backups', '/crm/index.php');

    Assert::same('/crm', $request->basePath());
    Assert::same('/settings/backups', $request->path());
});

Harness::test('a filename in a route parameter survives path resolution', function (): void {
    // PHP's built-in server — and some nginx fastcgi_split_path_info setups —
    // report SCRIPT_NAME as the whole request path whenever the last segment
    // contains a dot. Taking dirname() of that used to eat the route: a
    // backup download arrived at the router as just "/download" and 404d.
    $uri = '/settings/backups/castle-crm-20260101-120000.sql.gz/download';

    $request = request_for($uri, $uri);

    Assert::same('', $request->basePath(), 'SCRIPT_NAME that is not a .php file must not set a base path');
    Assert::same($uri, $request->path());
});

Harness::test('that route then matches the real route table', function (): void {
    $router = new Router();
    require CC_ROOT . '/app/routes.php';

    $uri = '/settings/backups/castle-crm-20260101-120000.sql.gz/download';
    $request = request_for($uri, $uri);

    // dispatch() would run middleware, so match the compiled regex instead.
    $reflection = new ReflectionProperty(Router::class, 'routes');
    $reflection->setAccessible(true);

    $matched = false;

    foreach ($reflection->getValue($router)['GET'] as $route) {
        if (preg_match($route['regex'], $request->path()) === 1) {
            $matched = true;
            break;
        }
    }

    Assert::true($matched, 'the backup download route should match its own URL');
});

Harness::test('a traversal attempt is reduced to the root path', function (): void {
    foreach ([
        '/settings/backups/..%2f..%2fconfig%2fconfig.php/download',
        '/../config/config.php',
        '/a/../../b',
    ] as $uri) {
        Assert::same('/', request_for($uri, '/index.php')->path(), 'input: ' . $uri);
    }
});

Harness::test('duplicate slashes collapse and a trailing slash is dropped', function (): void {
    Assert::same('/settings/mail', request_for('//settings///mail/', '/index.php')->path());
});

Harness::test('a query string is not part of the path', function (): void {
    Assert::same(
        '/settings/backups/export',
        request_for('/settings/backups/export?table=contacts', '/index.php')->path()
    );
});

Harness::test('a protocol-relative-looking path keeps its first segment', function (): void {
    // parse_url('//settings/mail') reports `settings` as a HOST and '/mail' as
    // the path, which would route the request to the wrong place — and, worse,
    // would hide the first segment from the protected-directory guard in
    // index.php. The path is cut out of REQUEST_URI by hand for this reason.
    Assert::same('/settings/mail', request_for('//settings///mail/', '/index.php')->path());
    Assert::same('/app/helpers.php', request_for('//app/helpers.php', '/index.php')->path());
});

Harness::test('an absolute-form request target resolves to its path', function (): void {
    // Only a proxy should ever receive one, but a crafted request line can
    // produce it, and reading "http:" as the first path segment would be wrong.
    Assert::same(
        '/settings/mail',
        request_for('http://elsewhere.example/settings/mail', '/index.php')->path()
    );
});
