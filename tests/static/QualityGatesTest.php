<?php

declare(strict_types=1);

/**
 * Static checks that enforce the spec's quality gates (§13.2).
 *
 * These are the rules that are easy to state and easy to forget: no
 * interpolated SQL, every mutating route CSRF-protected, every permission in
 * the catalogue, every view that a controller renders actually present. A
 * reviewer cannot reliably hold all of them in their head across 99 tables and
 * 50 routes, so they are tested instead.
 *
 * They need no database and no HTTP server.
 */

use App\Core\Acl;
use App\Core\Event;
use App\Services\AutomationTriggers;
use Tests\Assert;
use Tests\Harness;

Harness::suite('static/quality-gates');

/**
 * Every PHP file in the application, excluding the test suite itself.
 *
 * @return list<string>
 */
function project_php_files(): array
{
    $files = [];

    foreach (['app', 'install', 'tools'] as $directory) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(CC_ROOT . '/' . $directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    foreach (['index.php', 'cron.php'] as $root) {
        if (is_file(CC_ROOT . '/' . $root)) {
            $files[] = CC_ROOT . '/' . $root;
        }
    }

    sort($files);

    return $files;
}

function relative_path(string $path): string
{
    return str_replace(CC_ROOT . '/', '', $path);
}

// -------------------------------------------------------------- SQL safety

Harness::test('no SQL statement interpolates a variable', function (): void {
    // The single most important rule in the codebase. Every value goes through
    // a bound parameter; a dynamic identifier goes through quoteIdentifier()
    // after an allow-list check.
    $offenders = [];

    foreach (project_php_files() as $file) {
        $contents = (string) file_get_contents($file);
        $lines = preg_split('/\R/', $contents) ?: [];

        foreach ($lines as $number => $line) {
            $trimmed = ltrim($line);

            // Comments and docblocks often contain illustrative SQL; they are
            // not executed.
            if ($trimmed === ''
                || str_starts_with($trimmed, '*')
                || str_starts_with($trimmed, '//')
                || str_starts_with($trimmed, '#')
                || str_starts_with($trimmed, '/*')
            ) {
                continue;
            }

            // Look for a SQL keyword and a PHP variable on the same line,
            // inside a quoted string.
            if (preg_match('/\b(SELECT|INSERT\s+INTO|UPDATE|DELETE\s+FROM|WHERE|VALUES)\b/i', $line) !== 1) {
                continue;
            }

            // Interpolation inside a double-quoted or heredoc SQL string.
            if (preg_match('/["\'][^"\']*\b(?:SELECT|WHERE|VALUES|UPDATE|DELETE)\b[^"\']*\$[a-zA-Z_]/i', $line) === 1) {
                // Allowances, each deliberate and reviewed:
                //  - Migration and the test bootstrap build DDL from a trusted
                //    prefix, not from user input
                //  - a {table} placeholder is expanded by DB::expand()
                //  - concatenating a pre-validated identifier is explicit
                $allowed = str_contains($file, 'Core/Migration.php')
                    || str_contains($file, 'tests/')
                    || str_contains($line, 'quoteIdentifier')
                    || str_contains($line, '{prefix}');

                if (!$allowed) {
                    $offenders[] = relative_path($file) . ':' . ($number + 1) . ' — ' . trim($line);
                }
            }
        }
    }

    Assert::same(
        [],
        $offenders,
        "SQL must never interpolate a variable:\n   " . implode("\n   ", $offenders)
    );
});

Harness::test('PDO never runs with emulated prepares', function (): void {
    $db = (string) file_get_contents(CC_ROOT . '/app/Core/DB.php');

    Assert::contains('PDO::ATTR_EMULATE_PREPARES   => false', $db);
});

Harness::test('DB::delete refuses an empty WHERE clause', function (): void {
    $db = (string) file_get_contents(CC_ROOT . '/app/Core/DB.php');

    Assert::contains('Refusing to delete without a WHERE clause', $db);
});

// ------------------------------------------------------------- route table

/**
 * Parse app/routes.php for its registrations.
 *
 * @return list<array{method:string,path:string,middleware:string,line:int}>
 */
function parsed_routes(): array
{
    $contents = (string) file_get_contents(CC_ROOT . '/app/routes.php');
    $lines = preg_split('/\R/', $contents) ?: [];

    $routes = [];
    $groupMiddleware = '';

    foreach ($lines as $number => $line) {
        // Track group middleware so a route inheriting 'auth' from its group
        // is not reported as unguarded.
        if (preg_match("/->group\(\s*'[^']*'\s*,\s*\[([^\]]*)\]/", $line, $group) === 1) {
            $groupMiddleware = $group[1];
        }

        if (preg_match(
            "/->(get|post|put|patch|delete)\(\s*'([^']*)'\s*,\s*(?:\[[^\]]*\]|\w+)\s*(?:,\s*\[([^\]]*)\])?/",
            $line,
            $match
        ) === 1) {
            $routes[] = [
                'method'     => strtoupper($match[1]),
                'path'       => $match[2],
                'middleware' => ($match[3] ?? '') . ',' . $groupMiddleware,
                'line'       => $number + 1,
            ];
        }
    }

    return $routes;
}

Harness::test('the route table parses and is populated', function (): void {
    Assert::greaterThan(30, count(parsed_routes()));
});

Harness::test('every mutating route is CSRF-protected', function (): void {
    // Exemptions are listed here with their reason, so a NEW un-protected
    // route still fails this test. Adding to this list should feel costly.
    $exempt = [
        // RFC 8058 one-click unsubscribe: the recipient's mail provider
        // submits this on their behalf, so no session token can exist. The
        // signed token in the path is the authorisation.
        'POST /u/{token:[A-Za-z0-9_-]+}',

        // Inbound texts, posted by the SMS provider. There is no session and
        // never will be. The authorisation is the PROVIDER'S OWN SIGNATURE,
        // checked in `SmsWebhookController::verify()` — Twilio's HMAC-SHA1
        // scheme, Telnyx's Ed25519, or a shared secret for anything else —
        // and a request that cannot be verified is refused, INCLUDING when
        // nothing is configured to verify against. That last part is what
        // makes this exemption defensible: the endpoint is closed by default
        // rather than open until somebody remembers to secure it. An
        // unsigned endpoint here would let anybody forge a JOIN from a
        // stranger's number — making the venue text them, with its own
        // records saying they consented — or a STOP from a customer's.
        'POST /webhooks/sms',
    ];

    $offenders = [];

    foreach (parsed_routes() as $route) {
        if (!in_array($route['method'], ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            continue;
        }

        if (in_array($route['method'] . ' ' . $route['path'], $exempt, true)) {
            continue;
        }

        /*
         * THE REST API, which authenticates with a BEARER TOKEN and never a
         * cookie. CSRF is an attack on ambient credentials: a form on
         * somebody else's site can make a browser send its cookies, and
         * cannot make it send an Authorization header. So a route guarded by
         * `api` has nothing to forge.
         *
         * That reasoning only holds while the route accepts NO session, which
         * is why the next test asserts that `api` and `auth` never appear
         * together. Without it this exemption would quietly cover a route
         * that a logged-in tab could drive.
         */
        if (str_contains($route['middleware'], 'api')) {
            continue;
        }

        if (!str_contains($route['middleware'], 'csrf')) {
            $offenders[] = $route['method'] . ' ' . $route['path'] . ' (routes.php:' . $route['line'] . ')';
        }
    }

    Assert::same(
        [],
        $offenders,
        "every state-changing route needs the csrf middleware:\n   " . implode("\n   ", $offenders)
    );
});

Harness::test('every can: middleware names a catalogued permission', function (): void {
    // A typo here would otherwise throw at request time on a route nobody
    // visits often — or worse, be silently mis-spelled in a way that passes.
    $offenders = [];

    foreach (parsed_routes() as $route) {
        if (preg_match_all("/can:([a-z_]+)/", $route['middleware'], $matches) === 0) {
            continue;
        }

        foreach ($matches[1] as $permission) {
            if (!isset(Acl::CATALOG[$permission])) {
                $offenders[] = $permission . ' on ' . $route['method'] . ' ' . $route['path'];
            }
        }
    }

    Assert::same([], $offenders, "unknown permissions:\n   " . implode("\n   ", $offenders));
});

Harness::test('every authenticated non-API route requires auth', function (): void {
    $public = [
        '/login', '/logout', '/forgot-password', '/reset-password',
        '/health.json', '/f/', '/w/', '/u/', '/q/',
        // Provider webhooks carry a provider SIGNATURE instead of a session;
        // see the CSRF exemption above for why that is enough, and why the
        // endpoint is closed rather than open when nothing is configured.
        '/webhooks/',
        // The Text Club sign-up, which is how a guest who has never heard of
        // this software gets on the list. Honeypotted, throttled, capped per
        // NUMBER, CSRF-protected, and it grants no consent by itself — the
        // confirmation text does that.
        '/text-club/join',
        // Feedback, from a link in an email. A parent answering one question
        // the morning after a party has no account and never will, so the
        // 32-character token in the path is the authorisation — the same
        // arrangement as the waiver and the quote above. Both pages are
        // noindex and uncached, the POST is CSRF-protected, and both are
        // throttled.
        '/s/',
        // The review click-through. It redirects to the venue's OWN
        // configured link for that platform and never to anything in the
        // request, so there is nothing here an attacker could aim.
        '/r/',
        // The public review wall: the one page in this application that is
        // MEANT to be found, cached and framed, because it is the venue's own
        // marketing on their own website. It reads featured reviews and
        // nothing else — no token, no guest data, no query the visitor
        // controls.
        '/reviews',
    ];

    $offenders = [];

    foreach (parsed_routes() as $route) {
        $isPublic = false;

        foreach ($public as $prefix) {
            if (str_starts_with($route['path'], $prefix)) {
                $isPublic = true;
                break;
            }
        }

        if ($isPublic) {
            continue;
        }

        // `api` is authentication too — a bearer token resolved to a real
        // user, who is then subject to the same Acl as a browser session.
        if (!str_contains($route['middleware'], 'auth')
            && !str_contains($route['middleware'], 'guest')
            && !str_contains($route['middleware'], 'api')
        ) {
            $offenders[] = $route['method'] . ' ' . $route['path'] . ' (routes.php:' . $route['line'] . ')';
        }
    }

    Assert::same([], $offenders, "unguarded routes:\n   " . implode("\n   ", $offenders));
});

Harness::test('AN API ROUTE ACCEPTS A TOKEN OR A SESSION, NEVER BOTH', function (): void {
    /*
     * This is what makes the CSRF exemption above safe, and it is worth a
     * test of its own because the mistake is so easy to make: adding `auth`
     * to an API route "so it also works from the app" looks helpful and is
     * the whole vulnerability.
     *
     * A route carrying both would accept a session COOKIE, which a browser
     * attaches to any cross-site request a page can provoke — and the API
     * has no form, so no CSRF token exists to stop it. Every write in the
     * REST API would become forgeable by any page the venue's staff happen
     * to visit while logged in.
     *
     * The rule is therefore absolute rather than a judgement: `api` and
     * `auth` must never appear on the same route.
     */
    $offenders = [];

    foreach (parsed_routes() as $route) {
        $middleware = $route['middleware'];

        if (!str_contains($middleware, 'api')) {
            continue;
        }

        if (str_contains($middleware, 'auth')) {
            $offenders[] = $route['method'] . ' ' . $route['path']
                . ' (routes.php:' . $route['line'] . ') — carries both api and auth';
        }

        // And the API is throttled, because a token with no rate limit is a
        // way to read a venue's whole contact list as fast as the host
        // allows.
        if (!str_contains($middleware, 'throttle')) {
            $offenders[] = $route['method'] . ' ' . $route['path']
                . ' (routes.php:' . $route['line'] . ') — no throttle';
        }
    }

    Assert::same(
        [],
        $offenders,
        "API routes that accept a session or run unthrottled:\n   " . implode("\n   ", $offenders)
    );
});

Harness::test('login and password reset are rate limited', function (): void {
    $throttled = [];

    foreach (parsed_routes() as $route) {
        if ($route['method'] !== 'POST') {
            continue;
        }

        if (in_array($route['path'], ['/login', '/forgot-password', '/reset-password'], true)) {
            $throttled[$route['path']] = str_contains($route['middleware'], 'throttle');
        }
    }

    foreach ($throttled as $path => $isThrottled) {
        Assert::true($isThrottled, $path . ' must be rate limited against brute force');
    }

    Assert::greaterThan(2, count($throttled), 'all three auth endpoints should be present');
});

// -------------------------------------------------------- controller wiring

Harness::test('every route handler class and method exists', function (): void {
    $contents = (string) file_get_contents(CC_ROOT . '/app/routes.php');
    $offenders = [];

    // Only pairs whose first element names a controller. Middleware arrays
    // such as ['auth', 'csrf'] have the same shape and must not be mistaken
    // for handlers.
    preg_match_all("/\[\s*'(\w*Controller)'\s*,\s*'(\w+)'\s*\]/", $contents, $matches, PREG_SET_ORDER);

    Assert::greaterThan(20, count($matches), 'handlers should have been found at all');

    foreach ($matches as $match) {
        $class  = 'App\\Controllers\\' . $match[1];
        $action = $match[2];

        if (!class_exists($class)) {
            $offenders[] = 'missing class ' . $class;
            continue;
        }

        if (!method_exists($class, $action)) {
            $offenders[] = 'missing method ' . $match[1] . '::' . $action . '()';
        }
    }

    Assert::same([], $offenders, implode("\n   ", $offenders));
});

Harness::test('no controller action shadows a base Controller helper', function (): void {
    // A real bug once: HealthController::json() collided with the base
    // json(array $data) helper and fatally broke the route.
    $reserved = ['view', 'json', 'redirect', 'back', 'validate', 'authorize',
                 'notFound', 'refuse', 'success', 'intendedUrl', 'pagination'];

    $offenders = [];

    foreach (glob(CC_ROOT . '/app/Controllers/*.php') ?: [] as $file) {
        $name = basename($file, '.php');

        if ($name === 'Controller') {
            continue;
        }

        $class = 'App\\Controllers\\' . $name;

        if (!class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            if (in_array($method->getName(), $reserved, true)) {
                $offenders[] = $name . '::' . $method->getName() . '() shadows a base helper';
            }
        }
    }

    Assert::same([], $offenders, implode("\n   ", $offenders));
});

Harness::test('every icon a view asks for exists in the set', function (): void {
    // `icon()` renders NOTHING for a name it does not know. That is the right
    // failure for a decorative glyph — a missing icon must never break a page
    // — and a completely silent one for whoever wrote the view. Found three
    // at once in the inbox views, which had been written against a different
    // icon library's names.
    $source = (string) file_get_contents(CC_ROOT . '/app/Views/partials/icons.php');

    preg_match_all("/'([a-z-]+)' +=>/", $source, $defined);

    $known = $defined[1];

    Assert::greaterThan(20, count($known), 'the icon set was parsed');

    $offenders = [];

    // Recursive, because five views sit at the top of app/Views and a
    // two-level glob misses them. It can only see names written as literals
    // at the call — one returned from a helper is invisible here, which is
    // why the inbox view writes its three out longhand rather than through a
    // match().
    $views = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(CC_ROOT . '/app/Views', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($views as $entry) {
        $file = (string) $entry;

        if (!str_ends_with($file, '.php') || str_ends_with($file, 'icons.php')) {
            continue;
        }

        preg_match_all("/icon\(\s*'([a-z-]+)'/", (string) file_get_contents($file), $asked);

        foreach ($asked[1] as $name) {
            if (!in_array($name, $known, true)) {
                $offenders[] = basename(dirname($file)) . '/' . basename($file) . ' asks for "' . $name . '"';
            }
        }
    }

    // And the navigation, whose icon names are data rather than calls.
    $layout = (string) file_get_contents(CC_ROOT . '/app/Views/layouts/app.php');

    preg_match_all("/\['[^']+', '\/[^']*', '[a-z_]+', '([a-z-]+)'\]/", $layout, $nav);

    foreach ($nav[1] as $name) {
        if (!in_array($name, $known, true)) {
            $offenders[] = 'the navigation asks for "' . $name . '"';
        }
    }

    Assert::same([], array_values(array_unique($offenders)), implode("\n   ", array_unique($offenders)));
});

Harness::test('NO CONTROLLER THROWS AWAY AN AUTHORIZE REFUSAL', function (): void {
    // `Controller::authorize()` RETURNS the 403 rather than throwing, so a
    // bare `$this->authorize('view_x');` compiles, lints, reads like a guard
    // and does nothing at all — the page renders for everybody. Caught while
    // writing InboxController, where all seven calls were written that way.
    //
    // The shape that works is `if ($refusal = $this->authorize(...)) { return
    // $refusal; }`, so anything else on that line is the bug.
    $offenders = [];

    foreach (glob(CC_ROOT . '/app/Controllers/*.php') ?: [] as $file) {
        foreach (file($file) ?: [] as $number => $line) {
            if (!str_contains($line, '$this->authorize(')) {
                continue;
            }

            // The return value must be used: assigned, returned, or tested.
            if (preg_match('/(if\s*\(|return |=\s*\$this->authorize|\?\?|!==|===)/', $line) === 1) {
                continue;
            }

            $offenders[] = basename($file) . ':' . ($number + 1) . ' discards the refusal';
        }
    }

    Assert::same([], $offenders, implode("\n   ", $offenders));
});

Harness::test('every view rendered by a controller exists', function (): void {
    $offenders = [];

    foreach (glob(CC_ROOT . '/app/Controllers/*.php') ?: [] as $file) {
        $contents = (string) file_get_contents($file);

        preg_match_all("/\\\$this->view\(\s*'([^']+)'/", $contents, $matches);

        foreach ($matches[1] as $view) {
            $path = CC_ROOT . '/app/Views/' . str_replace('.', '/', $view) . '.php';

            if (!is_file($path)) {
                $offenders[] = basename($file) . ' renders missing view "' . $view . '"';
            }
        }
    }

    Assert::same([], $offenders, implode("\n   ", $offenders));
});

Harness::test('every layout referenced by a view or controller exists', function (): void {
    foreach (['layouts/app', 'layouts/bare'] as $layout) {
        Assert::true(
            is_file(CC_ROOT . '/app/Views/' . $layout . '.php'),
            $layout . ' must exist'
        );
    }
});

// ---------------------------------------------------------------- schema

Harness::test('every schema file uses the prefix placeholder', function (): void {
    $files = glob(CC_ROOT . '/install/schema/*.sql') ?: [];

    Assert::greaterThan(5, count($files), 'the schema should be split across files');

    foreach ($files as $file) {
        $contents = (string) file_get_contents($file);

        Assert::contains('{prefix}', $contents, basename($file) . ' must use {prefix}');

        // A hard-coded cc_ would break any install using another prefix.
        Assert::notContains(
            '`cc_',
            $contents,
            basename($file) . ' must not hard-code the default prefix'
        );
    }
});

Harness::test('every schema table is InnoDB and utf8mb4', function (): void {
    foreach (glob(CC_ROOT . '/install/schema/*.sql') ?: [] as $file) {
        $contents = (string) file_get_contents($file);

        $creates = preg_match_all('/CREATE TABLE IF NOT EXISTS/i', $contents);
        $engines = preg_match_all('/ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci/i', $contents);

        Assert::same(
            $creates,
            $engines,
            basename($file) . ': every CREATE TABLE needs the InnoDB/utf8mb4 clause'
        );
    }
});

Harness::test('schema files use IF NOT EXISTS so a partial apply can be completed', function (): void {
    foreach (glob(CC_ROOT . '/install/schema/*.sql') ?: [] as $file) {
        $contents = (string) file_get_contents($file);

        $plain = preg_match_all('/CREATE TABLE(?!\s+IF NOT EXISTS)/i', $contents);

        Assert::same(0, $plain, basename($file) . ' has a CREATE TABLE without IF NOT EXISTS');
    }
});

Harness::test('no schema column could hold card data', function (): void {
    // Castle CRM is explicitly out of PCI scope (spec §12.4). The guarantee is
    // structural: there is nowhere to put a card number.
    $forbidden = ['card_number', 'cardnumber', 'pan', 'cvv', 'cvc',
                  'card_expiry', 'expiry_month', 'security_code'];

    $offenders = [];

    foreach (glob(CC_ROOT . '/install/schema/*.sql') ?: [] as $file) {
        $contents = strtolower((string) file_get_contents($file));

        foreach ($forbidden as $column) {
            if (preg_match('/`' . preg_quote($column, '/') . '`/', $contents) === 1) {
                $offenders[] = basename($file) . ' declares `' . $column . '`';
            }
        }
    }

    Assert::same([], $offenders, implode("\n   ", $offenders));
});

Harness::test('money columns are integers, never floats', function (): void {
    // A cent of float drift per booking becomes a real reconciliation problem
    // against the POS.
    $offenders = [];

    foreach (glob(CC_ROOT . '/install/schema/*.sql') ?: [] as $file) {
        $lines = preg_split('/\R/', (string) file_get_contents($file)) ?: [];

        foreach ($lines as $number => $line) {
            if (preg_match('/`\w*_cents`\s+(FLOAT|DOUBLE|REAL)/i', $line) === 1) {
                $offenders[] = basename($file) . ':' . ($number + 1) . ' — ' . trim($line);
            }
        }
    }

    Assert::same([], $offenders, implode("\n   ", $offenders));
});

// ----------------------------------------------------------------- events

Harness::test('the event catalogue is well formed', function (): void {
    Assert::greaterThan(40, count(Event::CATALOG));

    foreach (Event::CATALOG as $name => $description) {
        Assert::matches('/^[a-z_]+\.[a-z_]+$/', (string) $name, 'event name: ' . $name);
        Assert::notEmpty($description, 'description for ' . $name);
    }

    // Every event named as PENDING has to be IN the catalogue, or the
    // automation pages cannot label it and `isDeliverable()` — which asks the
    // catalogue first — would call it deliverable by omission.
    $orphans = array_diff(array_keys(Event::PENDING), array_keys(Event::CATALOG));

    Assert::same([], array_values($orphans), 'PENDING names an event the catalogue does not: '
        . implode(', ', $orphans));

    // An event cannot be both a trigger and internal.
    $both = array_intersect(array_keys(Event::INTERNAL), array_keys(Event::CATALOG));

    Assert::same([], array_values($both), 'both a trigger and internal: ' . implode(', ', $both));
});

/**
 * Every event name the application actually fires.
 *
 * Parsed rather than grepped, because the interesting fires are not plain
 * literals: `Event::fire('booking.' . $to, …)` and
 * `Event::fire($granted ? 'contact.consent_granted' : 'contact.consent_revoked', …)`
 * are both real, and a line-based search reads the first as nothing and the
 * second as two events on one line.
 *
 * So the first argument of each call is scanned for string literals, and each
 * one is taken as either a whole event name or — when it ends in a dot — a
 * PREFIX, which covers every catalogue entry in that namespace. That is
 * deliberately loose: it cannot tell which of `booking.held` and
 * `booking.confirmed` a dynamic fire can actually produce. It is tight
 * everywhere there is no dynamic fire, which is most places, and it is
 * exactly as tight as a reader of the code would be.
 *
 * @return array{names:list<string>,prefixes:list<string>}
 */
function fired_event_names(): array
{
    $names    = [];
    $prefixes = [];

    $classish = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];

    foreach (project_php_files() as $file) {
        $tokens = token_get_all((string) file_get_contents($file));
        $count  = count($tokens);

        for ($i = 2; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING || $tokens[$i][1] !== 'fire') {
                continue;
            }

            if (!is_array($tokens[$i - 1]) || $tokens[$i - 1][0] !== T_DOUBLE_COLON) {
                continue;
            }

            $class = $tokens[$i - 2];

            if (!is_array($class)
                || !in_array($class[0], $classish, true)
                || !str_ends_with((string) $class[1], 'Event')) {
                continue;
            }

            $open = $i + 1;

            while ($open < $count && $tokens[$open] !== '(') {
                $open++;
            }

            $depth = 0;

            for ($k = $open; $k < $count; $k++) {
                $token = $tokens[$k];

                if ($token === '(' || $token === '[') {
                    $depth++;
                    continue;
                }

                if ($token === ')' || $token === ']') {
                    $depth--;

                    if ($depth <= 0) {
                        break;
                    }

                    continue;
                }

                // The end of the FIRST argument: the payload after it is full
                // of strings that are not event names.
                if ($token === ',' && $depth === 1) {
                    break;
                }

                if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $literal = trim((string) $token[1], "'\"");

                    if (preg_match('/^[a-z_]+\.[a-z_]+$/', $literal) === 1) {
                        $names[$literal] = true;
                    } elseif (preg_match('/^[a-z_]+\.$/', $literal) === 1) {
                        $prefixes[$literal] = true;
                    }
                }
            }
        }
    }

    ksort($names);
    ksort($prefixes);

    return ['names' => array_keys($names), 'prefixes' => array_keys($prefixes)];
}

function event_is_fired(string $event, array $fired): bool
{
    if (in_array($event, $fired['names'], true)) {
        return true;
    }

    foreach ($fired['prefixes'] as $prefix) {
        if (str_starts_with($event, $prefix)) {
            return true;
        }
    }

    return false;
}

Harness::test('every event in the catalogue is fired, or says what it is waiting for', function (): void {
    /*
     * THE SILENT FAILURE THIS EXISTS TO PREVENT.
     *
     * The automation builder offers the catalogue. An entry nothing fires is a
     * trigger an operator can choose, switch on, and wait for for ever — and
     * the automation is working exactly as written, so nothing in the UI and
     * nothing in the log could say so. MEASURED when the engine was built:
     * nineteen of the catalogue's entries were in that state, six of them
     * used by a seeded automation.
     *
     * The answer is not to remove them — a venue asking "can I automate a
     * review coming in?" deserves a better answer than silence — but to NAME
     * them, in `Event::PENDING`, with the feature that will fire them. So this
     * gate says: fired, or scanned by cron, or honestly labelled.
     */
    $fired = fired_event_names();

    Assert::greaterThan(20, count($fired['names']), 'the parser found almost no fires, so it is broken');

    $silent = [];

    foreach (array_keys(Event::CATALOG) as $event) {
        if (isset(Event::PENDING[$event])) {
            continue;
        }

        if (isset(AutomationTriggers::SCANNED[$event])) {
            continue;
        }

        if (!event_is_fired($event, $fired)) {
            $silent[] = $event;
        }
    }

    Assert::same(
        [],
        $silent,
        "these events are offered as triggers and nothing fires them.\n"
        . "   Fire them, or name them in Event::PENDING with what will:\n   "
        . implode("\n   ", $silent)
    );
});

Harness::test('nothing named as pending is already being fired', function (): void {
    // The other direction, and the one that rots: when the reputation module
    // lands and starts firing `review.received`, leaving it in PENDING would
    // have the automation page tell a venue their working automation is
    // waiting on a feature that is not built.
    $fired = fired_event_names();

    $stale = [];

    foreach (array_keys(Event::PENDING) as $event) {
        // Prefixes are not consulted here. A dynamic fire in the same
        // namespace is not evidence that THIS event is fired, and treating it
        // as such would quietly empty this list.
        if (in_array($event, $fired['names'], true)) {
            $stale[] = $event;
        }
    }

    Assert::same(
        [],
        $stale,
        "these are fired now, so take them out of Event::PENDING:\n   " . implode("\n   ", $stale)
    );
});

Harness::test('every event fired is in the catalogue or deliberately internal', function (): void {
    // An event a module fires that the catalogue does not name is a business
    // moment the builder cannot offer — invisible capability, and the reason
    // `booking.deposit_paid` went unoffered while the money ladder had been
    // climbing all along.
    $fired   = fired_event_names();
    $unknown = [];

    foreach ($fired['names'] as $event) {
        if (isset(Event::CATALOG[$event]) || isset(Event::INTERNAL[$event])) {
            continue;
        }

        if (isset(AutomationTriggers::SCANNED[$event])) {
            continue;
        }

        $unknown[] = $event;
    }

    Assert::same(
        [],
        $unknown,
        "fired but not in the catalogue. Add them as triggers, or to Event::INTERNAL:\n   "
        . implode("\n   ", $unknown)
    );
});

Harness::test('every seeded automation trigger is in the trigger catalogue', function (): void {
    /*
     * Read from the SEED FILE, not the database, so this runs with no MySQL —
     * `integration/schema` makes the same assertion against what was actually
     * installed, including whatever an operator has added since.
     *
     * The allowlist this gate used to carry is gone: it listed ten triggers
     * "whose emitters land in later phases", which is the same claim
     * `Event::PENDING` now makes in the application itself, where the UI can
     * read it.
     */
    $seedFile = (string) file_get_contents(CC_ROOT . '/install/seeds/playbooks.php');

    preg_match_all("/',\s*'([a-z_]+\.[a-z_]+|cron|manual)',\s*'/", $seedFile, $matches);

    Assert::greaterThan(50, count($matches[1]), 'the seed parser found almost no triggers');

    $catalogue = AutomationTriggers::catalogue();
    $unknown   = [];

    foreach (array_unique($matches[1]) as $trigger) {
        if (!isset($catalogue[$trigger])) {
            $unknown[] = $trigger;
        }
    }

    Assert::same([], $unknown, "automation triggers nothing can deliver:\n   " . implode("\n   ", $unknown));
});

// ------------------------------------------------------------- hygiene

Harness::test('no file contains a leftover debugging call', function (): void {
    $offenders = [];

    foreach (project_php_files() as $file) {
        $lines = preg_split('/\R/', (string) file_get_contents($file)) ?: [];

        foreach ($lines as $number => $line) {
            if (preg_match('/^\s*(var_dump|print_r|dd|dump)\s*\(/', $line) === 1) {
                $offenders[] = relative_path($file) . ':' . ($number + 1);
            }
        }
    }

    Assert::same([], $offenders, "debugging left in:\n   " . implode("\n   ", $offenders));
});

Harness::test('no file has a UTF-8 BOM', function (): void {
    // A BOM emits bytes before headers, which breaks every redirect and cookie.
    $offenders = [];

    foreach (project_php_files() as $file) {
        $handle = fopen($file, 'rb');

        if ($handle === false) {
            continue;
        }

        $first = (string) fread($handle, 3);
        fclose($handle);

        if ($first === "\xEF\xBB\xBF") {
            $offenders[] = relative_path($file);
        }
    }

    Assert::same([], $offenders, "files with a BOM:\n   " . implode("\n   ", $offenders));
});

Harness::test('every class file declares strict types', function (): void {
    $offenders = [];

    foreach (glob(CC_ROOT . '/app/Core/*.php') ?: [] as $file) {
        if (!str_contains((string) file_get_contents($file), 'declare(strict_types=1)')) {
            $offenders[] = relative_path($file);
        }
    }

    foreach (glob(CC_ROOT . '/app/Controllers/*.php') ?: [] as $file) {
        if (!str_contains((string) file_get_contents($file), 'declare(strict_types=1)')) {
            $offenders[] = relative_path($file);
        }
    }

    Assert::same([], $offenders, implode("\n   ", $offenders));
});

Harness::test('the protected directories carry a deny-all .htaccess', function (): void {
    // storage/backups gets its own, shipped rather than created on first use:
    // a dump of every guest's name, email and phone is the single most
    // sensitive file this application writes, and the window between "the
    // directory exists" and "the first backup created the guard" should not
    // exist at all.
    foreach (['app', 'config', 'storage', 'storage/backups'] as $directory) {
        $file = CC_ROOT . '/' . $directory . '/.htaccess';

        Assert::true(is_file($file), $directory . '/.htaccess must exist');

        $contents = (string) file_get_contents($file);
        Assert::contains('Require all denied', $contents, $directory . ' must deny access');
    }
});

Harness::test('the front controller refuses protected paths in PHP too', function (): void {
    // Defence in depth: a host that ignores .htaccess must not serve
    // config/config.php as plain text.
    $contents = (string) file_get_contents(CC_ROOT . '/index.php');

    Assert::contains('app|config|storage', $contents);
});

Harness::test('config.php is gitignored', function (): void {
    $ignore = (string) file_get_contents(CC_ROOT . '/.gitignore');

    Assert::contains('config/config.php', $ignore, 'the DB password must never be committed');
});

Harness::test('the sample config ships no real credentials', function (): void {
    $sample = (string) file_get_contents(CC_ROOT . '/config/config.sample.php');

    Assert::matches("/'app_key'\s*=>\s*''/", $sample, 'app_key must be blank in the sample');
    Assert::matches("/'pass'\s*=>\s*''/", $sample, 'the DB password must be blank in the sample');
});

Harness::test('no unit test touches the database', function (): void {
    // tests/unit is the suite CI's lint job runs with no MySQL service at all,
    // which is what makes it the fast gate the other two jobs depend on. A
    // single Settings::set() in there does not fail the test that wrote it —
    // it ERRORS, which reads as a broken build rather than a misplaced test,
    // and it takes the whole pipeline down with it because the matrix and the
    // installer walk-through both need: lint. (That is not hypothetical: four
    // link-host tests were written here and did exactly that.)
    //
    // The deny-list names the application services that open a connection on
    // first use. Pure helpers on the same classes — Auth::hash(),
    // Mailer::merge() — are fine and deliberately not listed.
    // The FULLY-QUALIFIED spelling has to match too, and the first version of
    // this gate did not catch it: its lookbehind was [A-Za-z0-9_\\], so a
    // backslash SUPPRESSED the match and `App\Core\DB::value()` sailed through.
    // That is not an exotic spelling — tests/unit/RouterTest.php already writes
    // `App\Core\Request::capture()`, so it is the established style in this very
    // directory. The gate reported 27/27 green on a file whose every line was a
    // database call, while CI's lint job on the same tree exited 1. A gate that
    // gives false assurance is worse than no gate, because the next person
    // writes the call fully qualified, sees green, and pushes.
    //
    // A bare `use` of one of these in a unit file is flagged on its own line
    // too, which closes the aliasing hole (`use App\Core\DB as Database;`)
    // without trying to track aliases through the file.
    $forbidden = ['DB', 'Settings', 'Cache', 'Audit', 'Migration', 'Backup', 'Upgrade', 'Model'];
    $offenders = [];

    foreach (glob(CC_ROOT . '/tests/unit/*.php') ?: [] as $file) {
        $lines = preg_split('/\R/', (string) file_get_contents($file)) ?: [];

        foreach ($lines as $number => $line) {
            if (preg_match('/^\s*(\*|\/\/)/', $line) === 1) {
                continue; // a comment may name one
            }

            foreach ($forbidden as $class) {
                // Optional leading backslash, optional App\Core\ prefix. The
                // lookbehind still stops MyDB:: and SubModel:: matching, but no
                // longer stops a backslash.
                $call = '/(?<![A-Za-z0-9_])\\\\?(?:App\\\\Core\\\\)?' . $class . '::/';

                if (preg_match($call, $line) === 1) {
                    $offenders[] = relative_path($file) . ':' . ($number + 1)
                        . ' uses ' . $class . '::';
                }

                $import = '/^\s*use\s+\\\\?App\\\\Core\\\\' . $class . '\s*(;|\s+as\s)/';

                if (preg_match($import, $line) === 1) {
                    $offenders[] = relative_path($file) . ':' . ($number + 1)
                        . ' imports ' . $class;
                }
            }
        }
    }

    Assert::same(
        [],
        $offenders,
        "a unit test must run with no database — move these to tests/integration:\n   "
            . implode("\n   ", $offenders)
    );
});

// ------------------------------------------------- writes against the schema

/**
 * Every column the schema declares, by table name without its prefix.
 *
 * Parsed from the schema files rather than read from a database, so this gate
 * runs in the lint job with no MySQL — which is the point: a write naming a
 * column that does not exist is a 500 on a page, and finding it needs neither
 * a server nor a seed.
 *
 * @return array<string,list<string>>
 */
function schema_columns(): array
{
    static $tables = null;

    if ($tables !== null) {
        return $tables;
    }

    $tables = [];
    $sql    = '';

    foreach (glob(CC_ROOT . '/install/schema/*.sql') ?: [] as $file) {
        $sql .= (string) file_get_contents($file) . "\n";
    }

    // Each CREATE TABLE up to its closing paren, then every `column` at the
    // start of a line inside it. A key or constraint line starts with a
    // keyword rather than a backtick, so it is skipped by the same pattern.
    if (preg_match_all(
        '/CREATE TABLE IF NOT EXISTS `\{prefix\}([a-z_]+)`\s*\((.*?)\n\)\s*ENGINE/s',
        $sql,
        $matches,
        PREG_SET_ORDER
    ) === 0) {
        return $tables;
    }

    foreach ($matches as $match) {
        $columns = [];

        foreach (preg_split('/\R/', $match[2]) ?: [] as $line) {
            if (preg_match('/^\s{4}`([a-z_]+)`\s+[A-Za-z]/', $line, $column) === 1) {
                $columns[] = $column[1];
            }
        }

        $tables[$match[1]] = $columns;
    }

    /*
     * THEN THE ALTERS.
     *
     * A later schema file may add a column rather than create a table — it has
     * to, because the migration runner records which FILES it has applied, so
     * a change edited into an earlier file reaches nobody who has already
     * installed. Without this the gate reported every such column as one the
     * schema does not declare, which is a false alarm that would train
     * somebody to ignore it.
     *
     * ADD COLUMN only. A MODIFY or a DROP does not change which columns exist
     * (and a DROP that did would be caught by the write being wrong).
     */
    if (preg_match_all(
        '/ALTER TABLE\s+`\{prefix\}([a-z_]+)`\s+ADD COLUMN\s+`([a-z_]+)`/i',
        $sql,
        $added,
        PREG_SET_ORDER
    ) > 0) {
        foreach ($added as $match) {
            if (isset($tables[$match[1]]) && !in_array($match[2], $tables[$match[1]], true)) {
                $tables[$match[1]][] = $match[2];
            }
        }
    }

    return $tables;
}

Harness::test('the schema parser finds the tables and columns it is asked about', function (): void {
    // A gate that parsed nothing would pass every test below while checking
    // nothing at all, which is the failure mode of every static check.
    $tables = schema_columns();

    Assert::greaterThan(90, count($tables), 'the whole schema should be parsed');
    Assert::hasKey('bookings', $tables);
    Assert::inArray('booking_number', $tables['bookings']);
    Assert::inArray('allergies', $tables['bookings']);
    Assert::inArray('created_at', $tables['bookings']);
    Assert::false(
        in_array('created_at', $tables['booking_tasks'], true),
        'cc_booking_tasks really has no timestamps — the case this gate exists for'
    );
    Assert::false(in_array('PRIMARY', $tables['bookings'], true), 'keys are not columns');
    Assert::false(in_array('KEY', $tables['bookings'], true));

    // A column added by a later file's ALTER, which is how a schema change
    // reaches an install that has already run the earlier files.
    Assert::inArray('issued_at', $tables['coupon_redemptions']);
    Assert::inArray('redeemed_at', $tables['coupon_redemptions'], 'the original columns survive the merge');
});

/**
 * The TOP-LEVEL keys of the array literal a DB::insert/update call passes.
 *
 * A balanced scan rather than a regex, because the first version of this gate
 * used `(.*?)\n\s*\]` and ran past the end of the array into unrelated code
 * — reporting `bookings.venue` from a view payload fifty lines later. It also
 * has to stay at depth 1: a nested `json_encode([...])` inside an `evidence`
 * value carries keys of its own that belong to no table.
 *
 * @return list<array{table:string,columns:list<string>}>
 */
function schema_writes(string $contents): array
{
    /*
     * Tokenised with PHP's own lexer rather than scanned character by
     * character.
     *
     * The hand-written scanner this replaces tracked bracket depth and
     * skipped string literals, but NOT comments — so a comment containing a
     * quote character desynchronised it and it ran on past the end of the
     * array, collecting every `'key' =>` in the rest of the file as a column
     * of whatever table it had last seen. MEASURED: one comment containing
     * the words "your party on Saturday" made this gate report five
     * non-existent columns on `automation_runs`, every one of them a `match`
     * arm in a method eighty lines further down.
     *
     * The lexer knows what a comment is, what a string is and where one
     * ends, so the only thing left to do here is count brackets.
     *
     * @return list<array{table:string, columns:list<string>}>
     */
    $tokens = @token_get_all("<?php " . $contents);
    $writes = [];
    $count  = count($tokens);

    /** The next significant token at or after $i, skipping whitespace and comments. */
    $next = static function (array $tokens, int $i, int $count): array {
        for (; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token)
                && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return [$i, $token];
        }

        return [$count, null];
    };

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        // Looking for: DB :: insert|update ( 'table' , [
        if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== 'DB') {
            continue;
        }

        [$j, $t] = $next($tokens, $i + 1, $count);

        if (!is_array($t) || $t[0] !== T_DOUBLE_COLON) {
            continue;
        }

        [$j, $t] = $next($tokens, $j + 1, $count);

        if (!is_array($t) || $t[0] !== T_STRING || !in_array($t[1], ['insert', 'update'], true)) {
            continue;
        }

        [$j, $t] = $next($tokens, $j + 1, $count);

        if ($t !== '(') {
            continue;
        }

        [$j, $t] = $next($tokens, $j + 1, $count);

        if (!is_array($t) || $t[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }

        $table = trim($t[1], "'\"");

        if (preg_match('/^[a-z_]+$/', $table) !== 1) {
            continue;
        }

        [$j, $t] = $next($tokens, $j + 1, $count);

        if ($t !== ',') {
            continue;
        }

        [$j, $t] = $next($tokens, $j + 1, $count);

        // A write whose columns come from a variable names none here, which
        // is not evidence of anything — `fail()` builds its array first.
        if ($t !== '[' && !(is_array($t) && $t[0] === T_ARRAY)) {
            continue;
        }

        if (is_array($t) && $t[0] === T_ARRAY) {
            [$j, $t] = $next($tokens, $j + 1, $count);

            if ($t !== '(') {
                continue;
            }
        }

        $open    = $t === '[' ? '[' : '(';
        $close   = $open === '[' ? ']' : ')';
        $depth   = 1;
        $columns = [];

        for ($k = $j + 1; $k < $count && $depth > 0; $k++) {
            $inner = $tokens[$k];

            if ($inner === $open || $inner === '(') {
                $depth++;

                continue;
            }

            if ($inner === $close || $inner === ')') {
                $depth--;

                continue;
            }

            if ($inner === '[' || $inner === ']') {
                $depth += $inner === '[' ? 1 : -1;

                continue;
            }

            // A single-quoted key at the TOP level of this array, followed
            // by =>, is a column name.
            if ($depth !== 1
                || !is_array($inner)
                || $inner[0] !== T_CONSTANT_ENCAPSED_STRING
                || $inner[1][0] !== "'") {
                continue;
            }

            $text = trim($inner[1], "'");

            if (preg_match('/^[a-z_]+$/', $text) !== 1) {
                continue;
            }

            [, $after] = $next($tokens, $k + 1, $count);

            if (is_array($after) && $after[0] === T_DOUBLE_ARROW) {
                $columns[] = $text;
            }
        }

        $writes[] = ['table' => $table, 'columns' => $columns];
        $i        = $j;
    }

    return $writes;
}

Harness::test('the write parser reads the array it is given and stops there', function (): void {
    $sample = <<<'PHP'
        DB::insert('bookings', [
            'booking_number' => $number,
            'internal_notes' => json_encode(['nested' => 1, 'keys' => 2]),
        ]);

        return $this->view('bookings/show', ['venue' => $venue, 'tasks' => $tasks]);
        PHP;

    $writes = schema_writes($sample);

    Assert::count(1, $writes, 'one write, not two');
    Assert::same('bookings', $writes[0]['table']);
    Assert::same(['booking_number', 'internal_notes'], $writes[0]['columns']);
});

Harness::test(
    'A COMMENT CANNOT DESYNCHRONISE THE WRITE PARSER',
    function (): void {
        /*
         * MEASURED: the hand-written scanner this parser replaced tracked
         * bracket depth and skipped string literals but NOT comments, so a
         * comment containing an apostrophe or a quote ran it on past the end
         * of the array — collecting every `'key' =>` in the rest of the file
         * as a column of whatever table it had last seen. One comment
         * containing the words "your party on Saturday" made the gate below
         * report five non-existent columns on `automation_runs`, each of them
         * a `match` arm eighty lines further down.
         *
         * Every shape of comment, and an apostrophe in a double-quoted
         * string, because those are the two ways the old scanner lost track.
         */
        $sample = <<<'PHP'
            DB::insert('bookings', [
                'booking_number' => $number,
                // A comment with "double quotes" and an apostrophe's worth of trouble.
                'event_date' => $date,
                # A hash comment with a stray [ bracket and a ' quote.
                /* A block comment with "quotes" and ] brackets. */
                'guest_count' => $guests,
                'internal_notes' => "a double-quoted string with an apostrophe's quote",
            ]);

            $other = ['wait' => 1, 'exit' => 2, 'condition' => 3];

            return match ($type) {
                'wait' => 'a',
                'exit' => 'b',
            };
            PHP;

        $writes = schema_writes($sample);

        Assert::count(1, $writes, 'one write, and it ends where the array ends');
        Assert::same(
            ['booking_number', 'event_date', 'guest_count', 'internal_notes'],
            $writes[0]['columns'],
            'the comments contribute nothing and nothing after the array is collected'
        );
    }
);

Harness::test(
    'the write parser still catches a column that does not exist',
    function (): void {
        // The positive control. A parser that returned nothing at all would
        // satisfy every assertion above and make the gate below useless.
        $tables = schema_columns();

        Assert::hasKey('bookings', $tables, 'precondition: the schema is readable');

        $writes = schema_writes("DB::update('bookings', ['no_such_column' => 1], 'id = :id', ['id' => 1]);");

        Assert::count(1, $writes);
        Assert::same(['no_such_column'], $writes[0]['columns']);
        Assert::false(
            in_array('no_such_column', $tables['bookings'], true),
            'and the gate would report it'
        );
    }
);

Harness::test('every DB::insert and DB::update names columns the schema has', function (): void {
    // MEASURED, twice, while building the bookings module: a write to
    // cc_booking_resources and another to cc_booking_tasks each set a
    // created_at that those tables do not declare. Both were 500s on a real
    // page — one on saving a booking, one on confirming it — and neither
    // needed a database to find. This is that check.
    $tables    = schema_columns();
    $offenders = [];

    foreach (project_php_files() as $file) {
        foreach (schema_writes((string) file_get_contents($file)) as $write) {
            // A table this parser does not know is not evidence of anything:
            // the upgrade tests write to probe tables of their own.
            if (!isset($tables[$write['table']])) {
                continue;
            }

            foreach ($write['columns'] as $column) {
                if (!in_array($column, $tables[$write['table']], true)) {
                    $offenders[] = relative_path($file) . ': ' . $write['table'] . '.' . $column;
                }
            }
        }
    }

    Assert::same(
        [],
        array_values(array_unique($offenders)),
        "a write names a column the schema does not declare:\n   "
            . implode("\n   ", array_unique($offenders))
    );
});

// ---------------------------------------------------- Styles a view asks for

/**
 * Every class name the stylesheets define.
 *
 * A selector, not a declaration: this reads `.badge-ok` out of
 * `.badge-ok { ... }` and out of `.tab.is-active`, which is what a view has to
 * be able to name.
 *
 * @return array<string,true>
 */
function defined_css_classes(): array
{
    $known = [];

    foreach (glob(CC_ROOT . '/assets/css/*.css') ?: [] as $file) {
        preg_match_all('/\.(-?[A-Za-z_][A-Za-z0-9_-]*)/', (string) file_get_contents($file), $matches);

        foreach ($matches[1] as $class) {
            $known[$class] = true;
        }
    }

    return $known;
}

/**
 * The marker that stands in for every piece of PHP inside a view's markup.
 *
 * A NUL: it cannot appear in a real class attribute, and unlike a `"` it does
 * not end one — so a part-dynamic class attribute still parses as an attribute
 * and its literal half is still checked. Only the token carrying the marker is
 * skipped, which is the difference between checking `preview-result` and
 * throwing it away because something dynamic stood beside it.
 */
const CC_INTERPOLATED = "\x00";

/**
 * The literal class names a view asks for.
 *
 * **TOKENISED, not pattern-matched**, and both halves of that matter:
 *
 * The markup is taken from `T_INLINE_HTML` tokens only, so a `class="..."`
 * that lives inside a PHP string — `icons.php` builds its SVG with
 * `sprintf('<svg class="icon%s" ...')` — is not read as markup at all. It is
 * not markup; it is a format string, and `icon%s` is not a class name anybody
 * wrote.
 *
 * Everything between the markup is replaced by a marker, so an attribute
 * assembled at render time out of data is recognisable as interpolated however
 * it is written — across lines, around a ternary, with quotes of its own. The
 * first version of this used one regex over the raw file and reported eleven
 * fragments of PHP as missing classes, which is how a gate gets switched off
 * rather than fixed.
 *
 * @return list<array{class:string,file:string}>
 */
function view_css_classes(): array
{
    $found = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(CC_ROOT . '/app/Views', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $markup = '';

        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (is_array($token) && $token[0] === T_INLINE_HTML) {
                $markup .= $token[1];

                continue;
            }

            // Everything else is code. One marker per run of it is enough:
            // consecutive markers cannot make an interpolated attribute look
            // literal, only more obviously not.
            if (!str_ends_with($markup, CC_INTERPOLATED)) {
                $markup .= CC_INTERPOLATED;
            }
        }

        if (!preg_match_all('/\sclass="([^"]*)"/', $markup, $matches)) {
            continue;
        }

        foreach ($matches[1] as $attribute) {
            /*
             * WHITESPACE ONLY. `trim()`'s default list includes `\0`, so a
             * bare `trim()` here strips the interpolation marker off the end
             * of `cal-<NUL>` and hands on `cal-` as though somebody had
             * written it — which is precisely the false alarm the marker
             * exists to prevent, and it did exactly that first time round.
             */
            foreach (preg_split('/\s+/', trim($attribute, " \t\n\r")) ?: [] as $class) {
                /*
                 * A name with a marker in it was built from data — `dot-`
                 * followed by a tone, say — so there is no fixed name to check
                 * and guessing at one produces a false alarm.
                 */
                if ($class === '' || str_contains($class, CC_INTERPOLATED)) {
                    continue;
                }

                $found[] = ['class' => $class, 'file' => relative_path($file->getPathname())];
            }
        }
    }

    return $found;
}

Harness::test('every class a view names is one the stylesheet defines', function (): void {
    /*
     * MEASURED, three times over, while building the webhooks page: the API
     * tokens page said `badge-warning`, the saved-reports list said the same,
     * and the Text Club filter bar said `tab-bar` / `tab` / `is-active`. None
     * of those exist in the stylesheet. Nothing failed, nothing 500'd, no test
     * noticed — the markup simply rendered unstyled, so an "Expired" badge was
     * grey text and a row of tabs was a row of bare links.
     *
     * That is the failure mode this gate exists for: a typo in a class name is
     * invisible to every other check in the suite, and visible to a user
     * immediately.
     */
    $known     = defined_css_classes();
    $offenders = [];

    Assert::true(count($known) > 100, 'the stylesheet was read');

    foreach (view_css_classes() as $use) {
        if (isset($known[$use['class']])) {
            continue;
        }

        $offenders[$use['file'] . ': ' . $use['class']] = true;
    }

    $offenders = array_keys($offenders);
    sort($offenders);

    Assert::same(
        [],
        $offenders,
        "a view names a class the stylesheet does not define:\n   "
            . implode("\n   ", $offenders)
    );
});

Harness::test('the class gate reads real selectors and ignores interpolation', function (): void {
    // The gate above is only worth having if it is reading what it claims to.
    $known = defined_css_classes();

    Assert::true(isset($known['badge-ok']), 'a plain class is found');
    Assert::true(isset($known['is-active']), 'a class in a compound selector is found');
    Assert::false(isset($known['badge-warning']), 'and a name nothing defines is not');

    $uses    = view_css_classes();
    $classes = array_column($uses, 'class');

    Assert::true(in_array('table-scroll', $classes, true), 'a literal attribute is collected');

    // The two shapes that defeated the first version of this gate.
    Assert::false(in_array('cal-', $classes, true), 'an interpolated attribute is skipped whole');
    Assert::false(in_array('icon%s', $classes, true), 'a class inside a PHP string is not markup');

    foreach ($classes as $class) {
        Assert::true(
            (bool) preg_match('/^-?[A-Za-z_][A-Za-z0-9_-]*$/', $class),
            'every collected name is a plausible class, not a fragment of PHP: ' . $class
        );
    }
});

// ------------------------------------- an audit row is not a notification

/**
 * Every function body in a file, keyed by name.
 *
 * Token-based, with real brace matching, because the crude alternative —
 * splitting on `function ` — merges a body with its neighbour and then cannot
 * tell which of the two contained what. That turns the gate below into a check
 * that silently passes.
 *
 * @return array<string,string>
 */
function php_function_bodies(string $source): array
{
    $tokens  = token_get_all($source);
    $bodies  = [];
    $count   = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
            continue;
        }

        // The name, if it has one. A closure does not, and its body belongs
        // to whatever function encloses it.
        $name = null;

        for ($j = $i + 1; $j < $count; $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                $name = $tokens[$j][1];

                break;
            }

            if ($tokens[$j] === '(') {
                break;
            }
        }

        if ($name === null) {
            continue;
        }

        // Walk to the opening brace, then to its match.
        $depth = 0;
        $body  = '';

        for ($j = $i + 1; $j < $count; $j++) {
            $text = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];

            if ($text === '{') {
                $depth++;
            } elseif ($text === '}') {
                $depth--;

                if ($depth === 0) {
                    break;
                }
            } elseif ($text === ';' && $depth === 0) {
                // An abstract or interface method: no body at all.
                break;
            }

            if ($depth > 0) {
                $body .= $text;
            }
        }

        // A name can repeat across classes in one file; keep both.
        $bodies[$name . '#' . $i] = $body;
    }

    return $bodies;
}

Harness::test('a write that audits a real event also fires it', function (): void {
    /*
     * MEASURED, and it cost a whole feature quietly.
     *
     * `ContactController::store()` — the front desk typing a guest in, which
     * is the commonest way a contact is created — recorded
     * `Audit::record('contact.created', …)` and fired nothing. The event WAS
     * fired from the five places a guest arrives on their own (a form, the
     * public booking page, an inbound text, an inbound email, the API), so
     * every other gate in this suite passed: the event is catalogued, it is
     * fired somewhere, nothing is unreachable.
     *
     * The effect was that a venue's "welcome a new guest" sequence ran for
     * everybody except the people the desk added, and a webhook subscribed to
     * `contact.*` never heard about them. Nothing failed. The sequence simply
     * never started.
     *
     * The rule: an audit row RECORDS what happened; an event TELLS something
     * about it. A function that writes the first and not the second has
     * decided, usually by accident, that nothing should react.
     *
     * (If a path ever genuinely should audit without telling anybody, the
     * honest fix is to audit it under a name that is not an event's.)
     */
    $offenders = [];

    foreach (project_php_files() as $file) {
        foreach (php_function_bodies((string) file_get_contents($file)) as $key => $body) {
            if (!preg_match_all("/Audit::record\(\s*'([a-z_]+\.[a-z_]+)'/", $body, $matches)) {
                continue;
            }

            foreach (array_unique($matches[1]) as $action) {
                // Only an action that names a trigger an operator can attach
                // an automation to. `settings.mail_updated` is an audit name
                // and nothing else.
                if (!Event::isDeliverable($action)) {
                    continue;
                }

                if (str_contains($body, "Event::fire('" . $action . "'")) {
                    continue;
                }

                /*
                 * A dynamic fire covers it: `Event::fire('booking.' . $to, …)`
                 * and `Event::fire($event, …)` both reach actions this parser
                 * cannot name, and both sit beside exactly such an audit row.
                 */
                if (preg_match("/Event::fire\(\s*(?:'[a-z_]+\.'\s*\.|\\\$)/", $body)) {
                    continue;
                }

                $offenders[] = relative_path($file) . ': ' . explode('#', (string) $key)[0]
                    . '() audits ' . $action . ' and fires nothing';
            }
        }
    }

    $offenders = array_values(array_unique($offenders));
    sort($offenders);

    Assert::same(
        [],
        $offenders,
        "an audit row is not a notification:\n   " . implode("\n   ", $offenders)
    );
});

Harness::test('the audit-versus-event gate reads function bodies correctly', function (): void {
    // The gate is only worth having if the body extraction is sound, and a
    // crude split on `function ` is not — it merges neighbours.
    $bodies = php_function_bodies(
        '<?php class A { public function one(): void { $x = 1; }'
        . ' public function two(): void { $y = 2; if ($y) { $y++; } } }'
    );

    $joined = [];

    foreach ($bodies as $key => $body) {
        $joined[explode('#', (string) $key)[0]] = $body;
    }

    Assert::same(['one', 'two'], array_keys($joined));
    Assert::true(str_contains($joined['one'], '$x = 1'), 'the first body is its own');
    Assert::false(str_contains($joined['one'], '$y = 2'), 'and does not swallow the next one');
    Assert::true(str_contains($joined['two'], '$y++'), 'a nested brace does not end a body early');

    // A real file, so the gate is reading what it claims to.
    $real = php_function_bodies((string) file_get_contents(CC_ROOT . '/app/Controllers/ContactController.php'));
    $store = null;

    foreach ($real as $key => $body) {
        if (explode('#', (string) $key)[0] === 'store') {
            $store = $body;
        }
    }

    Assert::notNull($store, 'ContactController::store was found');
    Assert::true(str_contains((string) $store, "Event::fire('contact.created'"), 'and it fires the event');
});

Harness::test('a horizontal scroll container is also a containing block', function (): void {
    /*
     * MEASURED in a browser at 390px, on five pages at once.
     *
     * `.sr-only` is `position: absolute`. A `<caption class="sr-only">` or an
     * `<span class="sr-only">Actions</span>` inside a table that is wider than
     * the phone is laid out at its static position — past the right edge. If
     * the scroll container is not itself positioned, the nearest positioned
     * ancestor is the page, so that 1px span joins the PAGE's scrollable
     * overflow: the table scrolls correctly AND the whole layout scrolls
     * sideways behind it, with every row button pushed off screen.
     *
     * Found on `/settings/webhooks` (scrollWidth 482 against 390), then on
     * `/settings/users` after fixing only the first of the two table wrappers
     * — which is why this is a rule about every such container rather than a
     * fix to the two that were caught.
     *
     * `position: relative` costs nothing on a container that has no absolute
     * descendants, and makes the next one correct by default.
     */
    $offenders = [];

    foreach (glob(CC_ROOT . '/assets/css/*.css') ?: [] as $file) {
        $css = (string) file_get_contents($file);

        // Each top-level rule block: its selector, and its declarations.
        if (!preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER)) {
            continue;
        }

        foreach ($rules as $rule) {
            $selector     = trim(preg_replace('/\s+/', ' ', $rule[1]) ?? '');
            $declarations = $rule[2];

            // A nested block's selector picks up the enclosing at-rule's text;
            // harmless, since we only care whether the two properties are in
            // the same block.
            if (!preg_match('/overflow(-x)?\s*:\s*(auto|scroll)/', $declarations)) {
                continue;
            }

            if (preg_match('/position\s*:\s*(relative|absolute|fixed|sticky)/', $declarations)) {
                continue;
            }

            $offenders[] = basename($file) . ': ' . $selector;
        }
    }

    $offenders = array_values(array_unique($offenders));
    sort($offenders);

    Assert::same(
        [],
        $offenders,
        "a scroll container with no position lets an absolutely-positioned child "
            . "escape it and widen the page:\n   " . implode("\n   ", $offenders)
    );
});

Harness::test('no class is defined by two separate rule blocks', function (): void {
    /*
     * MEASURED: `.code-block` was defined twice, 500 lines apart, differing in
     * padding and in whether it set a margin. What a code block looked like
     * was therefore whichever block cascaded last — and an edit to the first
     * one changed nothing at all, silently.
     *
     * Only SIMPLE, single-class selectors are checked. A variant
     * (`.badge-ok`), a descendant (`.card .table`) and a state
     * (`.tab.is-active`) are all legitimately separate rules; two bare
     * `.thing { }` blocks are the thing that fools a reader.
     *
     * Blocks inside an at-rule are excluded: a media query redefining a class
     * is the whole point of a media query.
     */
    $offenders = [];

    foreach (glob(CC_ROOT . '/assets/css/*.css') ?: [] as $file) {
        $css = (string) file_get_contents($file);

        // Strip at-rule bodies, so a media query's overrides are not counted.
        $flat = (string) preg_replace('/@[a-z-]+[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/i', '', $css);

        if (!preg_match_all('/([^{}]+)\{[^{}]*\}/', $flat, $rules, PREG_SET_ORDER)) {
            continue;
        }

        $seen = [];

        foreach ($rules as $rule) {
            $selector = trim(preg_replace('/\s+/', ' ', $rule[1]) ?? '');

            // One simple class, nothing else.
            if (!preg_match('/^\.[A-Za-z_][A-Za-z0-9_-]*$/', $selector)) {
                continue;
            }

            if (isset($seen[$selector])) {
                $offenders[] = basename($file) . ': ' . $selector;
            }

            $seen[$selector] = true;
        }
    }

    $offenders = array_values(array_unique($offenders));
    sort($offenders);

    Assert::same(
        [],
        $offenders,
        "a class defined twice means an edit to one of them changes nothing:\n   "
            . implode("\n   ", $offenders)
    );
});

// ------------------------------- what a request can reach that it should not

/**
 * Does anything in this file EXECUTE when it is required?
 *
 * A class, an interface, a function declaration and a `use` statement all do
 * nothing on their own; a `$thing = …`, a call, an `if` or an `echo` at the
 * top level does. Token-based with brace matching, because a `return` inside
 * a method body is not a top-level statement and a regex cannot tell.
 */
function php_has_top_level_code(string $source): bool
{
    $tokens = token_get_all($source);
    $depth  = 0;
    $skipTo = null;

    foreach ($tokens as $token) {
        $text = is_array($token) ? $token[1] : $token;
        $id   = is_array($token) ? $token[0] : null;

        if ($text === '{') {
            $depth++;

            continue;
        }

        if ($text === '}') {
            $depth = max(0, $depth - 1);

            continue;
        }

        if ($depth > 0) {
            continue;
        }

        if ($id !== null && in_array($id, [
            T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML, T_WHITESPACE, T_COMMENT,
            T_DOC_COMMENT, T_DECLARE, T_NAMESPACE, T_USE, T_CLASS, T_INTERFACE,
            T_TRAIT, T_ENUM, T_FUNCTION, T_FINAL, T_ABSTRACT, T_CONST,
            T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED,
            T_LNUMBER, T_DNUMBER, T_CONSTANT_ENCAPSED_STRING, T_STATIC,
        ], true)) {
            continue;
        }

        if (in_array($text, ['(', ')', ';', ',', '=', '&', '|', '?', ':', '\\'], true)) {
            continue;
        }

        // A variable, a call to a language construct, a control structure.
        if ($id === T_VARIABLE || $id === T_IF || $id === T_FOREACH || $id === T_ECHO
            || $id === T_REQUIRE || $id === T_REQUIRE_ONCE || $id === T_INCLUDE
            || $id === T_INCLUDE_ONCE || $id === T_EXIT || $id === T_PRINT
            || $id === T_RETURN || $id === T_WHILE || $id === T_FOR || $id === T_SWITCH
        ) {
            return true;
        }
    }

    return false;
}

Harness::test('every directly reachable PHP file is guarded or meant to be public', function (): void {
    /*
     * MEASURED, over HTTP: `GET /tests/run.php` returned 500 — which means it
     * RAN. That file boots against the configured database and, with
     * `--fresh`, calls `test_drop_all()`.
     *
     * The cause is a rule the rest of the application depends on: Apache's
     * rewrite lets a REAL FILE through untouched, so the stylesheet and the
     * installer work — and so does every other `.php` on disk outside the
     * directories `.htaccess` names. `tests` and `tools` were not named.
     *
     * The `.htaccess` is fixed, and this gate exists because that file is not
     * always read: a host serving through nginx, or an Apache vhost with
     * `AllowOverride None`, never sees it. So the PHP has to refuse too.
     *
     * Four files are deliberately public and say why:
     *   - `index.php`, the front controller;
     *   - `cron.php`, which a cPanel cron job may call over HTTP with a token
     *     (its own check is a timing-safe token comparison, not a SAPI test);
     *   - `install/` — the web installer is the whole point of it;
     *   - `app/` is denied by path and never entered directly anyway.
     */
    $offenders = [];

    $public = [
        'index.php' => true,
        'cron.php'  => true,
    ];

    foreach (['tests', 'tools'] as $directory) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(CC_ROOT . '/' . $directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = relative_path($file->getPathname());

            if (isset($public[$relative])) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            /*
             * A TEST CASE is not an entry point. A file calling
             * `Harness::suite()` registers cases against a harness that only
             * the runner creates; fetched on its own it fatals on an undefined
             * class before reaching anything of its own, and the runner that
             * would give it a database is itself guarded.
             *
             * Everything ELSE in these two directories is a script or a
             * library that something requires, and both of those run when
             * they are fetched. That is the set this gate is about — and the
             * set a new file lands in by default, which is the point.
             */
            if (str_contains($source, 'Harness::suite(')) {
                continue;
            }

            /*
             * A library of nothing but declarations — a class, an interface,
             * functions — does nothing when fetched. Detected by asking
             * whether anything executes at the top level, rather than by
             * guessing from the filename.
             */
            if (!php_has_top_level_code($source)) {
                continue;
            }

            /*
             * Any shape of SAPI check counts, and `cli-server` has to be one
             * of them: `tools/dev-server.php` is the ROUTER SCRIPT for
             * `php -S`, re-executed on every request the development server
             * handles, and under the built-in server `PHP_SAPI` is
             * `cli-server`. A plain `!== 'cli'` guard there 404s the whole
             * application the moment it is added — which is what it did, for
             * about a minute.
             */
            if (str_contains($source, 'PHP_SAPI') || str_contains($source, 'php_sapi_name')) {
                continue;
            }

            $offenders[] = $relative;
        }
    }

    sort($offenders);

    Assert::same(
        [],
        $offenders,
        "a script outside the front controller runs with no CLI guard:\n   "
            . implode("\n   ", $offenders)
    );
});

Harness::test('the shipped .htaccess denies what a request has no business reaching', function (): void {
    // The other half of the pair above, and the one that catches a NEW
    // directory of scripts rather than a new file in an old one.
    $htaccess = (string) file_get_contents(CC_ROOT . '/.htaccess');

    foreach (['app', 'config', 'storage', 'tests', 'tools'] as $directory) {
        Assert::true(
            (bool) preg_match('/RedirectMatch 404[^\n]*\b' . $directory . '\b/', $htaccess),
            $directory . '/ is denied by path'
        );
    }

    /*
     * A DOTTED DIRECTORY, not just a dotted file. `<FilesMatch "^\.">` tests
     * the file's own name, so it denies `/.env` and lets `/.git/config`
     * through — a file called `config` inside a directory called `.git`.
     * MEASURED: that URL returned 200, publishing the whole repository
     * history on an install deployed by cloning.
     */
    Assert::true(
        str_contains($htaccess, '(^|/)\\.'),
        'a path containing a dotted directory is denied, not just a dotted file'
    );

    /*
     * AND `.well-known` IS EXEMPT. cPanel's AutoSSL answers its ACME
     * challenge there; denying it stops certificate renewal — an outage that
     * arrives ninety days after somebody tightens this file, by which time
     * nobody connects the two.
     */
    Assert::true(
        str_contains($htaccess, 'well-known'),
        'ACME challenges still reach the filesystem, or TLS renewal stops'
    );

    // Directory listings off, and the front controller still reachable.
    Assert::true(str_contains($htaccess, 'Options -Indexes'));
    Assert::true(str_contains($htaccess, 'RewriteRule ^ index.php [L]'));
});

Harness::test('every table a view draws says what it is', function (): void {
    /*
     * MEASURED in a browser, across 31 pages in two themes at two widths:
     * eleven tables had no `<caption>` and no `aria-label`. A screen reader
     * announces such a table as "table, 7 columns, 42 rows" and then starts
     * reading cells — so somebody arrives in the middle of a grid of names
     * and dates with nothing having said what the grid is.
     *
     * A caption is one line, it is invisible to everybody else (`sr-only`),
     * and this is the cheapest accessibility rule in the application to keep.
     * Pinned here rather than only in the browser audit, because the browser
     * audit is not what runs in CI.
     */
    $offenders = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(CC_ROOT . '/app/Views', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = relative_path($file->getPathname());

        /*
         * AN EMAIL IS EXEMPT, and not as a convenience. A table in an HTML
         * email is almost always LAYOUT — it is how every mail client has
         * been laid out for twenty years — and a `<caption>` there would be
         * VISIBLE text in the message, because the `sr-only` class that hides
         * one on a page does not exist in an email. An accessible email is a
         * well-ordered document with a plain-text alternative, which is what
         * `Mailer` sends.
         */
        if (str_contains($relative, '/emails/') || str_ends_with($relative, '/email.php')) {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (!preg_match_all('/<table\b[^>]*>/', $source, $tables, PREG_OFFSET_CAPTURE)) {
            continue;
        }

        foreach ($tables[0] as $table) {
            [$tag, $offset] = $table;

            // A label on the tag itself counts.
            if (str_contains($tag, 'aria-label') || str_contains($tag, 'aria-labelledby')) {
                continue;
            }

            /*
             * A caption must be the FIRST child of the table, per HTML — so
             * looking a little way past the opening tag is both correct and
             * enough. Searching the whole file would let one table's caption
             * vouch for another's.
             */
            $window = substr($source, $offset + strlen($tag), 400);

            if (!str_contains($window, '<caption')) {
                $offenders[] = $relative;
            }
        }
    }

    $offenders = array_values(array_unique($offenders));
    sort($offenders);

    Assert::same(
        [],
        $offenders,
        "a table with no caption announces itself to a screen reader as a grid "
            . "of cells and nothing else:\n   " . implode("\n   ", $offenders)
    );
});

Harness::test('the .htaccess deny patterns match what they are meant to, and nothing else', function (): void {
    /*
     * NOTHING IN CI RUNS APACHE. The installer walk drives PHP's built-in
     * server, which ignores `.htaccess` entirely — so every rule in that file
     * is, from the suite's point of view, a string nobody has ever evaluated.
     *
     * Two of those rules were added after a live probe found `/tests/run.php`
     * executing and `/.git/config` readable, and one of them carries a
     * negative lookahead that protects TLS renewal. A typo in it is an outage
     * ninety days later that nobody connects to this file.
     *
     * So the patterns are pulled out of the real file and run against real
     * paths here. PCRE is PCRE: Apache 2.4 uses it, and `RedirectMatch`
     * matches against the URL path, which is what these cases are.
     */
    $htaccess = (string) file_get_contents(CC_ROOT . '/.htaccess');

    preg_match_all('/^RedirectMatch\s+404\s+(\S.*)$/m', $htaccess, $matches);

    $patterns = array_map('trim', $matches[1]);

    Assert::greaterThan(1, count($patterns), 'the deny patterns were found');

    /** Does any deny rule catch this path? */
    $denied = static function (string $path) use ($patterns): bool {
        foreach ($patterns as $pattern) {
            if (@preg_match('#' . $pattern . '#', $path) === 1) {
                return true;
            }
        }

        return false;
    };

    // Every pattern has to COMPILE, first of all. A broken one is silently
    // ignored by Apache, which denies nothing and says nothing.
    foreach ($patterns as $pattern) {
        Assert::true(
            @preg_match('#' . $pattern . '#', '/probe') !== false,
            'this pattern compiles: ' . $pattern
        );
    }

    foreach ([
        // The two the live probe found.
        '/tests/run.php',
        '/tests/bootstrap.php',
        '/tests/install/walk-installer.php',
        '/tools/backup.php',
        '/tools/dev-server.php',
        '/.git/config',
        '/.git/HEAD',
        '/.env',
        // And the ones that were already covered.
        '/app/Core/DB.php',
        '/config/config.php',
        '/storage/logs/castle.log',
        '/storage/imports/import-1.csv',
        '/storage/backups/castle-crm-20260101-000000.sql.gz',
        '/install/schema/001_core.sql',
        '/install/seeds/demo.php',
        '/docs/AUDIT.md',
        '/VERSION.md',
    ] as $path) {
        Assert::true($denied($path), 'denied: ' . $path);
    }

    foreach ([
        // The application itself.
        '/',
        '/index.php',
        '/contacts',
        '/contacts/42',
        '/api/v1/contacts',
        '/cron.php',
        // The assets, which must keep working — a deny rule that catches the
        // stylesheet is an unstyled application.
        '/assets/css/app.css',
        '/assets/js/app.js',
        '/assets/img/logo.svg',
        '/assets/fonts/inter.woff2',
        // The installer, which is the product's whole promise.
        '/install/',
        '/install/index.php',
        '/install/probe.php',
        /*
         * AND THE ACME CHALLENGE. cPanel's AutoSSL answers here; denying it
         * stops certificate renewal. This is the case the negative lookahead
         * exists for, and the reason this test exists at all.
         */
        '/.well-known/acme-challenge/abc123',
        '/.well-known/acme-challenge/' . str_repeat('x', 43),
    ] as $path) {
        Assert::false($denied($path), 'reachable: ' . $path);
    }
});
