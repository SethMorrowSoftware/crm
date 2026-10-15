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
});

Harness::test('every seeded automation trigger is a real event or a known kind', function (): void {
    // A seeded automation pointing at an event that does not exist would never
    // fire, and nobody would notice until a guest was not followed up.
    $seedFile = (string) file_get_contents(CC_ROOT . '/install/seeds/playbooks.php');

    preg_match_all("/',\s*'([a-z_]+\.[a-z_]+|cron|manual)',\s*'/", $seedFile, $matches);

    $known = array_merge(
        array_keys(Event::CATALOG),
        // Non-event triggers, plus the events whose emitters land in later
        // phases. Listed explicitly so adding one is a deliberate act.
        ['cron', 'manual',
         'company.recurring_due', 'ticket.abandoned', 'ticket.purchased',
         'text_club.confirmed', 'feedback.resolved', 'coupon.issued',
         'donation.declined', 'visit.first'],
    );

    $unknown = [];

    foreach (array_unique($matches[1]) as $trigger) {
        if (!in_array($trigger, $known, true)) {
            $unknown[] = $trigger;
        }
    }

    Assert::same([], $unknown, "automation triggers with no emitter:\n   " . implode("\n   ", $unknown));
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
