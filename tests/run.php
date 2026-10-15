<?php

declare(strict_types=1);

/**
 * Test runner.
 *
 *   php tests/run.php                  everything
 *   php tests/run.php --unit           unit and static only (no database)
 *   php tests/run.php --no-http        skip the browser-level suite
 *   php tests/run.php --filter=router  only tests whose suite or name matches
 *   php tests/run.php --stop           stop at the first failure
 *   php tests/run.php --junit=out.xml  also write a JUnit report for CI
 *
 * Environment:
 *   CC_TEST_DB_HOST / _PORT / _NAME / _USER / _PASS   database to use
 *   CC_TEST_SKIP_DB=1                                 skip database suites
 *   CC_TEST_SKIP_HTTP=1                               skip the HTTP suite
 */

$options = getopt('', [
    'unit', 'no-http', 'no-db', 'filter::', 'stop', 'junit::', 'help',
    'require-db', 'require-http', 'min-tests::',
]) ?: [];

if (isset($options['help'])) {
    echo file_get_contents(__FILE__, false, null, 0, 1200);
    exit(0);
}

if (isset($options['unit']) || isset($options['no-db'])) {
    putenv('CC_TEST_SKIP_DB=1');
}

if (isset($options['unit']) || isset($options['no-http'])) {
    putenv('CC_TEST_SKIP_HTTP=1');
}

require __DIR__ . '/bootstrap.php';

use Tests\Harness;

$filter = isset($options['filter']) && $options['filter'] !== false
    ? array_filter(explode(',', (string) $options['filter']))
    : [];

Harness::configure($filter, isset($options['stop']));

echo "Castle CRM test suite\n";
echo 'PHP ' . PHP_VERSION . "\n";

// ---------------------------------------------------------------------------
// Database availability
// ---------------------------------------------------------------------------

$databaseReady = false;

if (test_db_enabled()) {
    $config = test_db_config();
    echo sprintf("Database %s@%s:%d/%s\n", $config['user'], $config['host'], $config['port'], $config['name']);

    $databaseReady = test_boot(true);

    if (!$databaseReady) {
        echo "  → unavailable; database suites will be skipped\n";
    }
} else {
    echo "Database skipped (CC_TEST_SKIP_DB=1)\n";
}

// A suite that silently skips is the most dangerous kind of green: CI reports
// success having tested nothing. With --require-db, an unreachable database is
// a hard failure instead.
if (isset($options['require-db']) && !$databaseReady) {
    fwrite(STDERR, "\nFAIL: --require-db was given but the database suites could not run.\n");

    if (getenv('GITHUB_ACTIONS') !== false) {
        fwrite(STDERR, "::error::The database was required but unavailable; no integration test ran.\n");
    }

    exit(1);
}

// ---------------------------------------------------------------------------
// HTTP server
//
// Started here rather than in CI config, so `php tests/run.php` does the whole
// job on a developer's machine with no extra steps.
// ---------------------------------------------------------------------------

$serverProcess = null;
$serverPipes = [];

$httpEnabled = getenv('CC_TEST_SKIP_HTTP') !== '1' && $databaseReady;

if ($httpEnabled) {
    $result = start_test_server();

    if ($result === null) {
        echo "  → could not start a test server; the HTTP suite will be skipped\n";
        $httpEnabled = false;
    } else {
        [$serverProcess, $serverPipes, $baseUrl] = $result;
        echo 'HTTP server ' . $baseUrl . "\n";
        putenv('CC_TEST_BASE_URL=' . $baseUrl);
    }
} elseif (getenv('CC_TEST_SKIP_HTTP') === '1') {
    echo "HTTP suite skipped (CC_TEST_SKIP_HTTP=1)\n";
}

if (isset($options['require-http']) && !$httpEnabled) {
    fwrite(STDERR, "\nFAIL: --require-http was given but the HTTP suite could not run.\n");

    if (getenv('GITHUB_ACTIONS') !== false) {
        fwrite(STDERR, "::error::The HTTP suite was required but could not start a server.\n");
    }

    stop_test_server($serverProcess, $serverPipes);
    exit(1);
}

echo "\n";

// ---------------------------------------------------------------------------
// Run the suites
// ---------------------------------------------------------------------------

$suiteDirectories = ['unit', 'static', 'integration', 'http'];

foreach ($suiteDirectories as $directory) {
    if ($directory === 'http' && !$httpEnabled) {
        continue;
    }

    if (in_array($directory, ['integration'], true) && !$databaseReady) {
        continue;
    }

    // The HTTP fixtures are seeded here, not at server start: the integration
    // suites clear the transactional tables (including users) as part of their
    // own setup, which would otherwise leave the browser tests with no
    // accounts to sign in as.
    if ($directory === 'http') {
        seed_http_fixtures();
    }

    foreach (glob(__DIR__ . '/' . $directory . '/*Test.php') ?: [] as $file) {
        require $file;
    }
}

// ---------------------------------------------------------------------------
// Report and clean up
// ---------------------------------------------------------------------------

$junit = isset($options['junit']) && $options['junit'] !== false
    ? (string) $options['junit']
    : (getenv('CI') !== false ? __DIR__ . '/junit.xml' : null);

$exitCode = Harness::report($junit);

// The second half of the "green but empty" guard: assert that a plausible
// number of tests actually ran. A filter legitimately reduces the count, so
// this only applies when one was not used.
$minimum = isset($options['min-tests']) && $options['min-tests'] !== false
    ? (int) $options['min-tests']
    : 0;

if ($minimum > 0 && $filter === []) {
    $ran = Harness::testCount();

    if ($ran < $minimum) {
        fwrite(STDERR, sprintf(
            "\nFAIL: only %d tests ran but at least %d were expected.\n"
            . "Either a suite was skipped silently, or --min-tests needs updating.\n",
            $ran,
            $minimum
        ));

        if (getenv('GITHUB_ACTIONS') !== false) {
            fwrite(STDERR, sprintf(
                "::error::Expected at least %d tests, only %d ran.\n",
                $minimum,
                $ran
            ));
        }

        $exitCode = 1;
    }
}

stop_test_server($serverProcess, $serverPipes);

exit($exitCode);

// ===========================================================================
// Server management
// ===========================================================================

/**
 * Write a throwaway config and start PHP's built-in server against it.
 *
 * @return array{0:resource,1:array<int,resource>,2:string}|null
 */
function start_test_server(): ?array
{
    $config = test_db_config();
    $cronToken = 'test-cron-' . bin2hex(random_bytes(8));

    // The HTTP suite talks to a real front controller, which needs a real
    // config file. It is written to the normal location, having first stashed
    // any existing one so a developer's working install is not clobbered.
    $configPath = CC_ROOT . '/config/config.php';
    $backupPath = CC_ROOT . '/config/config.php.testbackup';

    if (is_file($configPath) && !is_file($backupPath)) {
        @rename($configPath, $backupPath);
    }

    $export = static fn (mixed $value): string => var_export($value, true);

    $contents = "<?php\n\n// Written by tests/run.php. Safe to delete.\n\nreturn [\n"
        . "    'app_url'      => null,\n"
        . "    'env'          => 'testing',\n"
        . "    'debug'        => true,\n"
        . "    'app_key'      => " . $export(base64_encode(random_bytes(32))) . ",\n"
        . "    'db'           => [\n"
        . "        'host'    => " . $export($config['host']) . ",\n"
        . "        'port'    => " . $export($config['port']) . ",\n"
        . "        'name'    => " . $export($config['name']) . ",\n"
        . "        'user'    => " . $export($config['user']) . ",\n"
        . "        'pass'    => " . $export($config['pass']) . ",\n"
        . "        'charset' => 'utf8mb4',\n"
        . "        'prefix'  => 'cc_',\n"
        . "        'socket'  => " . $export($config['socket']) . ",\n"
        . "    ],\n"
        // Explicit, not null: the cache and the log have to land where this
        // process expects them, so that clearing a rate-limit counter here
        // clears the one the server just wrote. Relying on both sides to
        // derive the same default from a null is how that stops being true.
        . "    'storage_path' => " . $export(CC_ROOT . '/storage') . ",\n"
        . "    'session'      => [\n"
        . "        'name'             => 'castle_crm_test_session',\n"
        . "        'idle_timeout'     => 3600,\n"
        . "        'absolute_timeout' => 86400,\n"
        . "        'cookie_secure'    => false,\n"
        . "        'cookie_samesite'  => 'Lax',\n"
        . "    ],\n"
        // True only here, in a throwaway config for a loopback-only server, so
        // each TestBrowser can present its own client address and not have the
        // whole suite rate limited as one IP (see TestBrowser's constructor).
        // The installer and config.sample.php both write false, and nothing in
        // the application changes behaviour on this flag except Request::ip().
        . "    'trust_proxy'  => true,\n"
        . "    'cron_token'   => " . $export($cronToken) . ",\n"
        . "    'log_level'    => 'error',\n"
        . "];\n";

    if (@file_put_contents($configPath, $contents) === false) {
        return null;
    }

    putenv('CC_TEST_CRON_TOKEN=' . $cronToken);

    // The installer must be locked, or the front controller redirects to it.
    @file_put_contents(CC_ROOT . '/install/.lock', "Locked by the test runner.\n");

    $port = find_free_port();

    if ($port === null) {
        return null;
    }

    // Passed as an array so no intermediate shell is spawned: proc_terminate()
    // then signals the PHP server directly, instead of killing a wrapper and
    // leaving the server orphaned for the CI runner to complain about.
    //
    // apc.enable_cli=0 asks the server for Cache's FILE backend rather than
    // APCu, so the backend does not differ between a developer's machine and
    // CI for no stated reason. It is NOT what keeps the suites out of the rate
    // limiter, and it is deliberately not described as making the server's
    // cache clearable from here: that was tried, with this flag set and the
    // files unlinked directly, and CI still answered 429 from the sixth POST.
    // Why it did not work was never established — the flag should have been
    // enough, which is the point. Nothing in this process can be SURE of
    // reaching state the server process owns, so the suites no longer try;
    // each TestBrowser presents its own client address instead.
    $command = [
        PHP_BINARY,
        '-d', 'apc.enable_cli=0',
        '-S', '127.0.0.1:' . $port,
        '-t', CC_ROOT,
        CC_ROOT . '/tools/dev-server.php',
    ];

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', CC_ROOT . '/storage/logs/test-server.log', 'a'],
        2 => ['file', CC_ROOT . '/storage/logs/test-server.log', 'a'],
    ];

    $process = proc_open($command, $descriptors, $pipes, CC_ROOT);

    if (!is_resource($process)) {
        return null;
    }

    $baseUrl = 'http://127.0.0.1:' . $port;

    // Wait for it to answer rather than sleeping a fixed amount.
    for ($attempt = 0; $attempt < 50; $attempt++) {
        usleep(100000);

        $handle = @curl_init($baseUrl . '/login');

        if ($handle !== false) {
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 2,
            ]);

            $body = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            curl_close($handle);

            if ($status === 200 && is_string($body)) {
                return [$process, $pipes, $baseUrl];
            }
        }
    }

    stop_test_server($process, $pipes);

    return null;
}

/**
 * Create the two accounts the HTTP suite signs in as.
 */
function seed_http_fixtures(): void
{
    App\Core\DB::disconnect();
    App\Core\DB::connect();

    // Clear the rate-limit counters before the HTTP suite.
    //
    // They live in the file cache, which — unlike the database and the config
    // file — survives between runs, so running the suite twice inside the
    // window used to start failing with 429s that looked for all the world
    // like broken authentication. Flushing here makes a run independent of the
    // one before it.
    //
    // It is NOT what keeps the suites from rate limiting themselves within a
    // run: this clears whichever backend THIS process resolves, and the
    // counters that matter belong to the server process. Each TestBrowser
    // presents its own client address instead. (This comment used to claim the
    // throttle was "still exercised by the test that deliberately trips it".
    // There was no such test — nothing covered the limiter at all until
    // http/smoke got one.)
    App\Core\Cache::flush();

    $accounts = [
        ['owner@test.invalid', 'Owner', 'owner', 'owner-test-passphrase-9'],
        ['desk@test.invalid',  'Front Desk Dana', 'front_desk', 'desk-test-passphrase-9'],
        // Holds users_manage but not every permission — the profile the
        // privilege-escalation tests need, and the one a real venue has.
        ['gm@test.invalid',    'General Manager Gail', 'general_manager', 'gm-test-passphrase-9'],
    ];

    foreach ($accounts as [$email, $name, $roleSlug, $password]) {
        $roleId = (int) App\Core\DB::value('SELECT id FROM {roles} WHERE slug = :s', ['s' => $roleSlug]);

        $existing = App\Core\DB::first('SELECT id FROM {users} WHERE email = :e', ['e' => $email]);

        $row = [
            'name'          => $name,
            'password_hash' => App\Core\Auth::hash($password),
            'role_id'       => $roleId,
            'is_active'     => 1,
            'deleted_at'    => null,
            'locked_until'  => null,
            'failed_attempts' => 0,
            'updated_at'    => gmdate('Y-m-d H:i:s'),
        ];

        if ($existing !== null) {
            App\Core\DB::update('users', $row, 'id = :id', ['id' => (int) $existing['id']]);
            continue;
        }

        App\Core\DB::insert('users', array_merge($row, [
            'email'      => $email,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]));
    }

    // The owner must be user id 1 for the owner-guard tests to address it.
    $ownerId = (int) App\Core\DB::value("SELECT id FROM {users} WHERE email = 'owner@test.invalid'");

    if ($ownerId !== 1) {
        // Only possible on a dirty database; renumbering is not worth it, so
        // the guard tests address id 1 and will simply exercise whoever that is.
        App\Core\Log::debug('Owner fixture is not id 1', ['id' => $ownerId]);
    }

    // Safe Mode on, so the HTTP suite exercises the protected posture.
    App\Core\Settings::set('system.safe_mode', true, 'bool');
    App\Core\Settings::set('venue.name', 'The Castle Fun Center');
    App\Core\Settings::forget('system.cron_last_run');
    App\Core\Settings::load(true);
}

function find_free_port(int $from = 8750, int $to = 8850): ?int
{
    for ($port = $from; $port <= $to; $port++) {
        $socket = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $error);

        if ($socket !== false) {
            fclose($socket);

            return $port;
        }
    }

    return null;
}

/**
 * @param resource|null              $process
 * @param array<int,resource>        $pipes
 */
function stop_test_server(mixed $process, array $pipes): void
{
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            @fclose($pipe);
        }
    }

    if (is_resource($process)) {
        @proc_terminate($process, SIGTERM);

        // Give it a moment to exit on its own before insisting, so the server
        // closes its listening socket and the port is free for the next run.
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $status = @proc_get_status($process);

            if (!is_array($status) || $status['running'] !== true) {
                break;
            }

            usleep(50000);
        }

        $status = @proc_get_status($process);

        if (is_array($status) && $status['running'] === true) {
            @proc_terminate($process, SIGKILL);
        }

        @proc_close($process);
    }

    // Restore whatever config was there before the run.
    $configPath = CC_ROOT . '/config/config.php';
    $backupPath = CC_ROOT . '/config/config.php.testbackup';

    if (is_file($backupPath)) {
        @unlink($configPath);
        @rename($backupPath, $configPath);
    }
}
