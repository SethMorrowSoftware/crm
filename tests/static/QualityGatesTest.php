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

        if (!str_contains($route['middleware'], 'auth') && !str_contains($route['middleware'], 'guest')) {
            $offenders[] = $route['method'] . ' ' . $route['path'] . ' (routes.php:' . $route['line'] . ')';
        }
    }

    Assert::same([], $offenders, "unguarded routes:\n   " . implode("\n   ", $offenders));
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
