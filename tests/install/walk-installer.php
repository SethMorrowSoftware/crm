<?php

declare(strict_types=1);

/**
 * Drive the web installer end to end over HTTP, as a person with cPanel and a
 * browser would.
 *
 * This is the test that matters most to the product's actual promise: that
 * Castle CRM installs on an ordinary shared host with no shell access. It
 * starts from nothing — no config file, no tables — walks all eight steps,
 * then proves the installed application works and has locked the installer
 * behind it.
 *
 * Run directly:
 *   php tests/install/walk-installer.php
 *
 * Environment (CI sets these):
 *   CC_INSTALL_DB_HOST / _PORT / _NAME / _USER / _PASS
 */

define('CC_ROOT', dirname(__DIR__, 2));

$config = [
    'host' => getenv('CC_INSTALL_DB_HOST') ?: '127.0.0.1',
    'port' => getenv('CC_INSTALL_DB_PORT') ?: '3306',
    'name' => getenv('CC_INSTALL_DB_NAME') ?: 'castle_install_test',
    'user' => getenv('CC_INSTALL_DB_USER') ?: 'root',
    'pass' => getenv('CC_INSTALL_DB_PASS') !== false ? (string) getenv('CC_INSTALL_DB_PASS') : '',
];

$ownerEmail    = 'installer-owner@test.invalid';
$ownerPassword = 'installer-test-passphrase-7';
$venueName     = 'The Castle Fun Center';

$failures = 0;
$step = 0;

/** Print a step heading. */
function heading(string $text): void
{
    global $step;
    $step++;

    echo "\n" . str_repeat('─', 68) . "\n";
    printf("STEP %d · %s\n", $step, $text);
    echo str_repeat('─', 68) . "\n";
}

function ok(string $message): void
{
    echo '  ✓ ' . $message . "\n";
}

function bad(string $message): void
{
    global $failures;
    $failures++;

    echo '  ✗ ' . $message . "\n";

    if (getenv('GITHUB_ACTIONS') !== false) {
        echo '::error::' . $message . "\n";
    }
}

function check(bool $condition, string $message): bool
{
    $condition ? ok($message) : bad($message);

    return $condition;
}

function fatal(string $message): never
{
    bad($message);
    echo "\nABORTED — the installer could not be completed.\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// A cookie-jar HTTP client
// ---------------------------------------------------------------------------

final class InstallerClient
{
    private string $jar;

    public function __construct(private string $baseUrl)
    {
        $this->jar = sys_get_temp_dir() . '/cc-installer-' . getmypid() . '.cookies';
        @unlink($this->jar);
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    /**
     * @param array<string,string>|null $post
     * @return array{status:int,body:string,location:string}
     */
    public function request(string $path, ?array $post = null): array
    {
        $handle = curl_init($this->baseUrl . $path);

        if ($handle === false) {
            throw new RuntimeException('cURL unavailable');
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_COOKIEJAR      => $this->jar,
            CURLOPT_COOKIEFILE     => $this->jar,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 60,
        ];

        if ($post !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($post);
        }

        curl_setopt_array($handle, $options);

        $raw = curl_exec($handle);

        if ($raw === false) {
            $error = curl_error($handle);
            curl_close($handle);

            throw new RuntimeException('Request to ' . $path . ' failed: ' . $error);
        }

        $status     = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        curl_close($handle);

        $headers = substr((string) $raw, 0, $headerSize);
        $body    = substr((string) $raw, $headerSize);
        $location = '';

        if (preg_match('/^Location:\s*(.+)$/mi', $headers, $match) === 1) {
            $location = trim($match[1]);
        }

        return ['status' => $status, 'body' => $body, 'location' => $location];
    }

    public function get(string $path): array
    {
        return $this->request($path);
    }

    /** @param array<string,string> $data */
    public function post(string $path, array $data): array
    {
        return $this->request($path, $data);
    }

    /** Read the installer's own CSRF token off the page. */
    public function token(string $path): string
    {
        $body = $this->get($path)['body'];

        if (preg_match('/name="_token" value="([a-f0-9]{64})"/', $body, $match) !== 1) {
            throw new RuntimeException('No installer token on ' . $path);
        }

        return $match[1];
    }
}

// ---------------------------------------------------------------------------
// Start from genuinely nothing
// ---------------------------------------------------------------------------

heading('Reset to a bare install');

@unlink(CC_ROOT . '/config/config.php');
@unlink(CC_ROOT . '/install/.lock');

check(!is_file(CC_ROOT . '/config/config.php'), 'no config file');
check(!is_file(CC_ROOT . '/install/.lock'), 'installer unlocked');

foreach (['logs', 'cache', 'uploads', 'exports', 'backups', 'sessions'] as $directory) {
    $path = CC_ROOT . '/storage/' . $directory;

    if (!is_dir($path)) {
        @mkdir($path, 0775, true);
    }
}

// Drop every table, so the installer genuinely builds the schema.
try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s', $config['host'], (int) $config['port'], $config['name']);
    $pdo = new PDO($dsn, $config['user'], $config['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $tables = $pdo->query(
        'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()'
    )->fetchAll(PDO::FETCH_COLUMN);

    if ($tables !== []) {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($tables as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', (string) $table) . '`');
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    ok('database emptied (' . count($tables) . ' tables dropped)');
} catch (PDOException $e) {
    fatal('could not reach the database: ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
// Serve the application
// ---------------------------------------------------------------------------

heading('Start a web server');

$port = null;

for ($candidate = 8900; $candidate <= 8980; $candidate++) {
    $socket = @stream_socket_server('tcp://127.0.0.1:' . $candidate, $errno, $error);

    if ($socket !== false) {
        fclose($socket);
        $port = $candidate;
        break;
    }
}

if ($port === null) {
    fatal('no free port available');
}

$logPath = CC_ROOT . '/storage/logs/installer-server.log';

$process = proc_open(
    sprintf(
        '%s -S 127.0.0.1:%d -t %s %s',
        escapeshellarg(PHP_BINARY),
        $port,
        escapeshellarg(CC_ROOT),
        escapeshellarg(CC_ROOT . '/tools/dev-server.php')
    ),
    [0 => ['pipe', 'r'], 1 => ['file', $logPath, 'a'], 2 => ['file', $logPath, 'a']],
    $pipes,
    CC_ROOT
);

if (!is_resource($process)) {
    fatal('could not start the web server');
}

$baseUrl = 'http://127.0.0.1:' . $port;
$client = new InstallerClient($baseUrl);

register_shutdown_function(static function () use ($process, $pipes): void {
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            @fclose($pipe);
        }
    }

    if (is_resource($process)) {
        @proc_terminate($process);
        @proc_close($process);
    }
});

$ready = false;

for ($attempt = 0; $attempt < 60; $attempt++) {
    usleep(150000);

    try {
        if ($client->get('/install/')['status'] === 200) {
            $ready = true;
            break;
        }
    } catch (Throwable) {
        // Still starting.
    }
}

if (!$ready) {
    echo "  server log:\n" . (string) @file_get_contents($logPath) . "\n";
    fatal('the server never answered');
}

ok('serving on ' . $baseUrl);

// An uninstalled application must send a visitor to the installer rather than
// erroring.
$root = $client->get('/');
check(
    $root['status'] === 302 && str_contains($root['location'], 'install'),
    'an uninstalled app redirects to the installer'
);

// ---------------------------------------------------------------------------
// Step 1 — welcome
// ---------------------------------------------------------------------------

heading('Installer step 1 — welcome');

$page = $client->get('/install/index.php?step=1');

check($page['status'] === 200, 'step 1 renders');
check(str_contains($page['body'], 'Set up Castle CRM'), 'shows the welcome heading');
check(str_contains($page['body'], 'Safe Mode'), 'warns that nothing will message a guest');

$response = $client->post('/install/index.php?step=1', [
    '_token' => $client->token('/install/index.php?step=1'),
]);

check($response['status'] === 302, 'accepting moves on');

// ---------------------------------------------------------------------------
// Step 2 — requirements
// ---------------------------------------------------------------------------

heading('Installer step 2 — requirements');

$page = $client->get('/install/index.php?step=2');

check($page['status'] === 200, 'step 2 renders');
check(str_contains($page['body'], 'Required'), 'distinguishes required from optional');
check(
    str_contains($page['body'], 'pdo_mysql') && str_contains($page['body'], 'openssl'),
    'checks the extensions the app needs'
);
check(
    str_contains($page['body'], 'This server can run Castle CRM'),
    'all hard requirements pass in this environment'
);

$response = $client->post('/install/index.php?step=2', [
    '_token' => $client->token('/install/index.php?step=2'),
]);

check($response['status'] === 302, 'requirements accepted');

// ---------------------------------------------------------------------------
// Step 3 — database
// ---------------------------------------------------------------------------

heading('Installer step 3 — database');

$page = $client->get('/install/index.php?step=3');
check($page['status'] === 200, 'step 3 renders');
check(str_contains($page['body'], 'cPanel'), 'explains the cPanel naming convention');

// A deliberately wrong password must be refused with a useful message rather
// than a stack trace.
$response = $client->post('/install/index.php?step=3', [
    '_token'    => $client->token('/install/index.php?step=3'),
    'db_host'   => $config['host'],
    'db_port'   => $config['port'],
    'db_name'   => $config['name'],
    'db_user'   => $config['user'],
    'db_pass'   => 'definitely-the-wrong-password',
    'db_prefix' => 'cc_',
]);

$body = $response['status'] === 302
    ? $client->get('/install/index.php?step=3')['body']
    : $response['body'];

check(
    str_contains($body, 'Could not connect') || str_contains($body, 'rejected'),
    'bad credentials are refused with an explanation'
);
check(!is_file(CC_ROOT . '/config/config.php'), 'and nothing was written to disk');

// Now the real credentials.
$response = $client->post('/install/index.php?step=3', [
    '_token'    => $client->token('/install/index.php?step=3'),
    'db_host'   => $config['host'],
    'db_port'   => $config['port'],
    'db_name'   => $config['name'],
    'db_user'   => $config['user'],
    'db_pass'   => $config['pass'],
    'db_prefix' => 'cc_',
]);

if ($response['status'] !== 302) {
    echo "  response body:\n" . substr($response['body'], 0, 2000) . "\n";
    fatal('the correct credentials were not accepted');
}

ok('credentials accepted and privileges verified');

// ---------------------------------------------------------------------------
// Step 4 — schema
// ---------------------------------------------------------------------------

heading('Installer step 4 — build the database');

$page = $client->get('/install/index.php?step=4');
check($page['status'] === 200, 'step 4 renders');
check(str_contains($page['body'], '001_core.sql'), 'lists the schema files to apply');

$response = $client->post('/install/index.php?step=4', [
    '_token' => $client->token('/install/index.php?step=4'),
]);

if ($response['status'] !== 302) {
    echo "  response body:\n" . substr($response['body'], 0, 3000) . "\n";
    fatal('the schema could not be applied');
}

$tableCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name LIKE 'cc_%'"
)->fetchColumn();

check($tableCount >= 95, sprintf('schema applied (%d tables)', $tableCount));

$migrationCount = (int) $pdo->query('SELECT COUNT(*) FROM cc_migrations')->fetchColumn();
$onDisk = count(glob(CC_ROOT . '/install/schema/*.sql') ?: []);

check($migrationCount === $onDisk, sprintf('all %d schema files recorded', $onDisk));

// ---------------------------------------------------------------------------
// Step 5 — venue
// ---------------------------------------------------------------------------

heading('Installer step 5 — venue details');

$page = $client->get('/install/index.php?step=5');
check($page['status'] === 200, 'step 5 renders');
check(str_contains($page['body'], 'Castle'), 'pre-filled with the venue');
check(str_contains($page['body'], 'Check the pre-filled values'), 'asks the operator to verify them');

// An invalid timezone must be refused.
$response = $client->post('/install/index.php?step=5', [
    '_token'     => $client->token('/install/index.php?step=5'),
    'venue_name' => $venueName,
    'timezone'   => 'Mars/Olympus',
]);

$body = $response['status'] === 302
    ? $client->get('/install/index.php?step=5')['body']
    : $response['body'];

check(str_contains($body, 'valid timezone'), 'an invalid timezone is refused');

$response = $client->post('/install/index.php?step=5', [
    '_token'      => $client->token('/install/index.php?step=5'),
    'venue_name'  => $venueName,
    'venue_phone' => '(845) 469-2116',
    'address_1'   => '109 Brookside Ave',
    'city'        => 'Chester',
    'state'       => 'NY',
    // The regression that broke the live save: a numeric-looking ZIP.
    'postal_code' => '10918',
    'timezone'    => 'America/New_York',
    'tax_rate'    => '8.125',
]);

check($response['status'] === 302, 'venue details accepted');

// ---------------------------------------------------------------------------
// Step 6 — owner account
// ---------------------------------------------------------------------------

heading('Installer step 6 — owner account');

$page = $client->get('/install/index.php?step=6');
check($page['status'] === 200, 'step 6 renders');

// A weak password must be refused by the real policy.
$response = $client->post('/install/index.php?step=6', [
    '_token'                      => $client->token('/install/index.php?step=6'),
    'owner_name'                  => 'Installer Owner',
    'owner_email'                 => $ownerEmail,
    'owner_password'              => 'short',
    'owner_password_confirmation' => 'short',
]);

$body = $response['status'] === 302
    ? $client->get('/install/index.php?step=6')['body']
    : $response['body'];

check(str_contains($body, '12 characters'), 'a short password is refused');

// Mismatched confirmation.
$response = $client->post('/install/index.php?step=6', [
    '_token'                      => $client->token('/install/index.php?step=6'),
    'owner_name'                  => 'Installer Owner',
    'owner_email'                 => $ownerEmail,
    'owner_password'              => $ownerPassword,
    'owner_password_confirmation' => 'something-else-entirely',
]);

$body = $response['status'] === 302
    ? $client->get('/install/index.php?step=6')['body']
    : $response['body'];

check(str_contains($body, 'do not match'), 'a mismatched confirmation is refused');

// The real thing.
$response = $client->post('/install/index.php?step=6', [
    '_token'                      => $client->token('/install/index.php?step=6'),
    'owner_name'                  => 'Installer Owner',
    'owner_email'                 => $ownerEmail,
    'owner_password'              => $ownerPassword,
    'owner_password_confirmation' => $ownerPassword,
]);

check($response['status'] === 302, 'owner account accepted');

// ---------------------------------------------------------------------------
// Step 7 — seeds
// ---------------------------------------------------------------------------

heading('Installer step 7 — starting data');

$page = $client->get('/install/index.php?step=7');
check($page['status'] === 200, 'step 7 renders');
check(str_contains($page['body'], '111 automations'), 'offers the playbook library');
check(
    str_contains($page['body'], 'arrives switched off'),
    'states plainly that nothing starts running'
);

$response = $client->post('/install/index.php?step=7', [
    '_token'         => $client->token('/install/index.php?step=7'),
    'seed_venue'     => '1',
    'seed_playbooks' => '1',
]);

check($response['status'] === 302, 'seed choices accepted');

// ---------------------------------------------------------------------------
// Step 8 — finish
// ---------------------------------------------------------------------------

heading('Installer step 8 — finish');

$page = $client->get('/install/index.php?step=8');
check($page['status'] === 200, 'the summary renders');
check(str_contains($page['body'], $config['name']), 'confirms the database');
check(str_contains($page['body'], $ownerEmail), 'confirms the owner');

$response = $client->post('/install/index.php?step=8', [
    '_token' => $client->token('/install/index.php?step=8'),
]);

if ($response['status'] !== 302) {
    echo "  response body:\n" . substr($response['body'], 0, 3000) . "\n";
    fatal('setup could not be completed');
}

$page = $client->get('/install/index.php?step=8');

check(str_contains($page['body'], 'Castle CRM is installed'), 'reports success');
check(str_contains($page['body'], 'cron.php'), 'gives the cron line');
check(str_contains($page['body'], 'Cron Jobs'), 'names the cPanel screen for it');
check(str_contains($page['body'], 'v=spf1'), 'gives the SPF record');
check(str_contains($page['body'], '_dmarc'), 'gives the DMARC record');
check(str_contains($page['body'], 'Email Deliverability'), 'names the cPanel screen for DKIM');
check(str_contains($page['body'], 'Safe Mode is ON'), 'states the protected posture');
check(
    stripos($page['body'], 'delete the <code>install</code> folder') !== false
        || stripos($page['body'], 'Delete the install folder') !== false,
    'tells the operator to remove the installer'
);

// ---------------------------------------------------------------------------
// What the installer actually produced
// ---------------------------------------------------------------------------

heading('Verify the installed state');

check(is_file(CC_ROOT . '/config/config.php'), 'config/config.php was written');
check(is_file(CC_ROOT . '/install/.lock'), 'the installer locked itself');

/** @var array<string,mixed> $written */
$written = require CC_ROOT . '/config/config.php';

check(is_array($written), 'the config file returns an array');
check(($written['db']['name'] ?? '') === $config['name'], 'it carries the database name');
check(($written['db']['prefix'] ?? '') === 'cc_', 'and the table prefix');
check(strlen((string) ($written['app_key'] ?? '')) >= 40, 'an encryption key was generated');
check(
    strlen((string) base64_decode((string) ($written['app_key'] ?? ''), true)) === 32,
    'the key is 32 bytes'
);
check(strlen((string) ($written['cron_token'] ?? '')) >= 32, 'a cron token was generated');
check(($written['env'] ?? '') === 'production', 'it defaults to production');
check(($written['debug'] ?? true) === false, 'with debug off');

$raw = (string) file_get_contents(CC_ROOT . '/config/config.php');
check(!str_contains($raw, '<?php' . PHP_EOL . PHP_EOL . 'return'), 'the file is documented');

// Seeded data.
$counts = [
    'users'       => 1,
    'roles'       => 8,
    'attractions' => 16,
    'party_packages' => 4,
    'playbooks'   => 8,
];

foreach ($counts as $table => $expected) {
    $actual = (int) $pdo->query('SELECT COUNT(*) FROM cc_' . $table)->fetchColumn();

    check($actual === $expected, sprintf('%s: %d (expected %d)', $table, $actual, $expected));
}

$automations = (int) $pdo->query('SELECT COUNT(*) FROM cc_automations')->fetchColumn();
check($automations >= 100, sprintf('automations: %d', $automations));

$activeAutomations = (int) $pdo->query(
    'SELECT COUNT(*) FROM cc_automations WHERE is_active = 1'
)->fetchColumn();
check($activeAutomations === 0, 'NOTHING is active on a fresh install');

$activePlaybooks = (int) $pdo->query('SELECT COUNT(*) FROM cc_playbooks WHERE is_active = 1')->fetchColumn();
check($activePlaybooks === 0, 'no playbook is active either');

$safeMode = (string) $pdo->query(
    "SELECT value FROM cc_settings WHERE `key` = 'system.safe_mode'"
)->fetchColumn();
check($safeMode === '1', 'Safe Mode is on');

$venueConfirmed = (string) $pdo->query(
    "SELECT value FROM cc_settings WHERE `key` = 'venue.confirmed'"
)->fetchColumn();
check($venueConfirmed === '0', 'the venue still needs confirming, so the checklist shows it');

$zip = (string) $pdo->query(
    "SELECT value FROM cc_settings WHERE `key` = 'venue.postal_code'"
)->fetchColumn();
check($zip === '10918', 'the ZIP was saved correctly');

// The owner must hold every permission.
$ownerPermissions = (int) $pdo->query(
    "SELECT COUNT(*) FROM cc_role_permissions rp
       JOIN cc_roles r ON r.id = rp.role_id
      WHERE r.slug = 'owner'"
)->fetchColumn();

$allPermissions = (int) $pdo->query('SELECT COUNT(*) FROM cc_permissions')->fetchColumn();

check($ownerPermissions === $allPermissions, 'the owner holds every permission');

// ---------------------------------------------------------------------------
// The installer must now refuse to run again
// ---------------------------------------------------------------------------

heading('Installer is locked');

$page = $client->get('/install/');

check($page['status'] === 403, 'revisiting the installer is forbidden');
check(str_contains($page['body'], 'already installed'), 'and says why');
check(str_contains($page['body'], 'delete the'), 'and repeats the cleanup instruction');

$page = $client->get('/install/index.php?step=3');
check($page['status'] === 403, 'a deep link into a step is forbidden too');
check(
    !str_contains($page['body'], $config['pass'] !== '' ? $config['pass'] : '__no_password__'),
    'no credential is echoed back'
);

// ---------------------------------------------------------------------------
// The installed application works
// ---------------------------------------------------------------------------

heading('The installed application works');

$page = $client->get('/');
check($page['status'] === 302 && str_contains($page['location'], 'login'), 'the app now asks for a sign-in');

$page = $client->get('/login');
check($page['status'] === 200, 'the login page renders');

if (preg_match('/name="_token" value="([a-f0-9]{64})"/', $page['body'], $match) !== 1) {
    fatal('no CSRF token on the login page');
}

$response = $client->post('/login', [
    '_token'   => $match[1],
    'email'    => $ownerEmail,
    'password' => $ownerPassword,
]);

check(
    $response['status'] === 302 && !str_contains($response['location'], 'login'),
    'the owner account created by the installer can sign in'
);

$dashboard = $client->get('/');

check($dashboard['status'] === 200, 'the dashboard renders');
check(str_contains($dashboard['body'], 'Dashboard'), 'and looks like a dashboard');
check(str_contains($dashboard['body'], 'Safe Mode'), 'showing the Safe Mode badge');
check(str_contains($dashboard['body'], 'Finish setting up'), 'and the setup checklist');

foreach (['Fatal error', 'Warning:', 'Notice:', 'Undefined '] as $problem) {
    check(!str_contains($dashboard['body'], $problem), 'no "' . $problem . '" on the dashboard');
}

// Every page the owner can reach.
$pages = ['/settings', '/settings/venue', '/settings/system', '/settings/users',
          '/settings/roles', '/settings/health', '/settings/audit', '/playbooks', '/account'];

$pagesOk = true;

foreach ($pages as $path) {
    $response = $client->get($path);

    if ($response['status'] !== 200) {
        bad($path . ' returned ' . $response['status']);
        $pagesOk = false;
    }
}

if ($pagesOk) {
    ok(count($pages) . ' settings and module pages render');
}

// Cron must work with the generated token.
$cronResponse = $client->get('/cron.php?token=' . urlencode((string) $written['cron_token']));
check($cronResponse['status'] === 200, 'cron runs with the generated token');
check(str_contains($cronResponse['body'], 'Done in'), 'and completes');
check(!str_contains(strtolower($cronResponse['body']), '<!doctype'), 'without rendering a page');

check($client->get('/cron.php?token=wrong')['status'] === 403, 'and refuses a wrong token');

// ---------------------------------------------------------------------------
// Result
// ---------------------------------------------------------------------------

echo "\n" . str_repeat('═', 68) . "\n";

if ($failures === 0) {
    echo "PASS — Castle CRM installs cleanly through the web installer\n";
    echo str_repeat('═', 68) . "\n";
    exit(0);
}

printf("FAIL — %d check%s did not pass\n", $failures, $failures === 1 ? '' : 's');
echo str_repeat('═', 68) . "\n";
exit(1);
