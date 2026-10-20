<?php

declare(strict_types=1);

/**
 * Install Castle CRM headlessly, as `install.sh` does on an Ubuntu server, and
 * prove the result is the same install the web wizard produces.
 *
 * The sibling of walk-installer.php. That one proves the product's promise to a
 * cPanel venue: eight screens, no shell. This one proves the other promise —
 * clone the repository onto a server, run one script, and the CRM is up — and
 * it guards the half of the installer the two share (install/engine.php), where
 * a change made for one caller would otherwise reach the other silently.
 *
 * Run directly:
 *   php tests/install/cli-install.php
 *
 * Environment (CI sets these):
 *   CC_INSTALL_DB_HOST / _PORT / _NAME / _USER / _PASS
 *
 * It drops every table in that database, installs, checks, and puts the
 * checkout back as it found it.
 */

/*
 * CLI ONLY. This DROPS EVERY TABLE in the configured database and deletes
 * config/config.php. Apache's rewrite lets a real file through untouched, and
 * a host that ignores the shipped .htaccess never denies tests/.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('CC_ROOT', dirname(__DIR__, 2));

$config = [
    'host' => getenv('CC_INSTALL_DB_HOST') ?: '127.0.0.1',
    'port' => getenv('CC_INSTALL_DB_PORT') ?: '3306',
    'name' => getenv('CC_INSTALL_DB_NAME') ?: 'castle_install_test',
    'user' => getenv('CC_INSTALL_DB_USER') ?: 'root',
    'pass' => getenv('CC_INSTALL_DB_PASS') !== false ? (string) getenv('CC_INSTALL_DB_PASS') : '',
];

$ownerEmail    = 'cli-owner@test.invalid';
$ownerPassword = 'cli-install-passphrase-7';
$venueName     = 'The Castle Fun Center';
$baseUrl       = 'https://crm.test.invalid';

$failures = 0;
$step = 0;

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
    echo "\nABORTED — the headless install could not be completed.\n";
    exit(1);
}

/**
 * Run tools/cli-install.php, with the secrets where it insists on them.
 *
 * @param list<string>          $arguments
 * @param array<string,string>  $secrets
 * @return array{status:int,output:string}
 */
function installer(array $arguments, array $secrets = []): array
{
    $command = array_merge([PHP_BINARY, CC_ROOT . '/tools/cli-install.php'], $arguments);

    // An ARRAY, so nothing goes through a shell; and an explicit environment,
    // so a stray CC_ADMIN_PASSWORD in the caller's shell cannot make a test
    // that is supposed to fail pass instead.
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        CC_ROOT,
        $secrets + ['PATH' => getenv('PATH') ?: '/usr/bin:/bin']
    );

    if (!is_resource($process)) {
        fatal('could not run tools/cli-install.php');
    }

    fclose($pipes[0]);
    $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['status' => proc_close($process), 'output' => $output];
}

// ---------------------------------------------------------------------------
// Start from genuinely nothing
// ---------------------------------------------------------------------------

heading('Reset to a bare checkout');

@unlink(CC_ROOT . '/config/config.php');
@unlink(CC_ROOT . '/install/.lock');

check(!is_file(CC_ROOT . '/config/config.php'), 'no config file');
check(!is_file(CC_ROOT . '/install/.lock'), 'installer unlocked');

try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s', $config['host'], (int) $config['port'], $config['name']);
    $pdo = new PDO($dsn, $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

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

$common = [
    '--db-host=' . $config['host'],
    '--db-port=' . $config['port'],
    '--db-name=' . $config['name'],
    '--db-user=' . $config['user'],
    '--admin-email=' . $ownerEmail,
    '--admin-name=Headless Owner',
    '--venue-name=' . $venueName,
    '--timezone=America/New_York',
    '--base-url=' . $baseUrl,
];

$secrets = ['CC_DB_PASS' => $config['pass'], 'CC_ADMIN_PASSWORD' => $ownerPassword];

// ---------------------------------------------------------------------------
// What it refuses
// ---------------------------------------------------------------------------

heading('What it refuses');

$result = installer(array_merge($common, ['--timezone=Mars/Olympus']), $secrets);
check($result['status'] !== 0, 'an invalid timezone is refused');
check(str_contains($result['output'], 'timezone'), 'and says so');

$result = installer(array_merge($common, ['--admin-email=not-an-address']), $secrets);
check($result['status'] !== 0, 'an invalid owner address is refused');

$result = installer($common, ['CC_DB_PASS' => $config['pass'], 'CC_ADMIN_PASSWORD' => 'short']);
check($result['status'] !== 0, 'a password the real policy rejects is refused');
check(str_contains($result['output'], '12 characters'), 'with the policy’s own words');

/*
 * A PASSWORD CANNOT BE PASSED AS AN OPTION, on purpose: `ps auxww` shows every
 * process's command line to every account on the machine. The option simply
 * does not exist, so this install has no password at all and must fail rather
 * than quietly inventing one.
 */
$result = installer(
    array_merge($common, ['--admin-password=' . $ownerPassword]),
    ['CC_DB_PASS' => $config['pass']]
);
check($result['status'] !== 0, 'a password passed as an option is not accepted');

$result = installer(
    array_merge($common, ['--db-name=' . $config['name'], '--db-user=definitely-not-a-user']),
    ['CC_DB_PASS' => 'definitely-the-wrong-password', 'CC_ADMIN_PASSWORD' => $ownerPassword]
);
check($result['status'] !== 0, 'bad database credentials are refused');
check(
    str_contains($result['output'], 'Could not connect') || str_contains($result['output'], 'rejected'),
    'with an explanation rather than a stack trace'
);

check(!is_file(CC_ROOT . '/config/config.php'), 'and nothing has been written to disk yet');

$remaining = (int) $pdo->query(
    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'
)->fetchColumn();
check($remaining === 0, 'nor to the database');

// ---------------------------------------------------------------------------
// The install itself
// ---------------------------------------------------------------------------

heading('Install, in one command');

$result = installer(array_merge($common, ['--seed-demo']), $secrets);

if ($result['status'] !== 0) {
    echo $result['output'] . "\n";
    fatal('the headless install failed');
}

ok('exited 0');

$output = $result['output'];

check(str_contains($output, 'Castle CRM is installed'), 'reports success');
check(str_contains($output, 'cron.php'), 'gives the cron line');
check(str_contains($output, 'Safe Mode is ON'), 'states the protected posture');
check(str_contains($output, $ownerEmail), 'confirms the owner it created');
check(!str_contains($output, $ownerPassword), 'and never echoes the password back');
check(
    $config['pass'] === '' || !str_contains($output, $config['pass']),
    'nor the database password'
);

// ---------------------------------------------------------------------------
// The same install the web wizard produces
// ---------------------------------------------------------------------------

heading('Verify the installed state');

check(is_file(CC_ROOT . '/config/config.php'), 'config/config.php was written');
check(is_file(CC_ROOT . '/install/.lock'), 'the installer locked itself');

$permissions = @fileperms(CC_ROOT . '/config/config.php');
check(
    $permissions !== false && ($permissions & 0o004) === 0,
    'and is not world-readable, because it holds the database password'
);

/** @var array<string,mixed> $written */
$written = require CC_ROOT . '/config/config.php';

check(is_array($written), 'the config file returns an array');
check(($written['db']['name'] ?? '') === $config['name'], 'it carries the database name');
check(($written['db']['prefix'] ?? '') === 'cc_', 'and the table prefix');
check(($written['env'] ?? '') === 'production', 'it defaults to production');
check(($written['debug'] ?? true) === false, 'with debug off');
check(
    strlen((string) base64_decode((string) ($written['app_key'] ?? ''), true)) === 32,
    'a 32-byte encryption key was generated'
);
check(strlen((string) ($written['cron_token'] ?? '')) >= 32, 'a cron token was generated');

$tableCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name LIKE 'cc_%'"
)->fetchColumn();
check($tableCount >= 95, sprintf('schema applied (%d tables)', $tableCount));

$migrationCount = (int) $pdo->query('SELECT COUNT(*) FROM cc_migrations')->fetchColumn();
$onDisk = count(glob(CC_ROOT . '/install/schema/*.sql') ?: []);
check($migrationCount === $onDisk, sprintf('all %d schema files recorded', $onDisk));

// The owner can actually sign in: the hash is the one the policy made, and the
// account is active and unlocked.
$owner = $pdo->query(
    "SELECT u.name, u.email, u.password_hash, u.is_active, u.locked_until, u.timezone, r.slug AS role
       FROM cc_users u JOIN cc_roles r ON r.id = u.role_id"
)->fetch(PDO::FETCH_ASSOC);

if ($owner === false) {
    fatal('no user account was created');
}

check($owner['email'] === $ownerEmail, 'the owner has the address it was given');
check($owner['role'] === 'owner', 'and the Owner role');
check((int) $owner['is_active'] === 1, 'and is active');
check($owner['locked_until'] === null, 'and is not locked out');
check($owner['timezone'] === 'America/New_York', 'and is on the venue’s clock');
check(
    password_verify($ownerPassword, (string) $owner['password_hash']),
    'and the password given on the command line is the one that works'
);

$setting = static function (string $key) use ($pdo): string {
    $statement = $pdo->prepare('SELECT value FROM cc_settings WHERE `key` = :key');
    $statement->execute(['key' => $key]);

    return (string) $statement->fetchColumn();
};

check($setting('venue.name') === $venueName, 'the venue name was saved');
check($setting('venue.timezone') === 'America/New_York', 'and its timezone');
check($setting('venue.confirmed') === '0', 'and still needs confirming, so the checklist shows it');
check($setting('system.safe_mode') === '1', 'Safe Mode is on');
check($setting('system.install_url') === $baseUrl, 'the base URL was recorded for links in email');
check($setting('mail.driver') === 'log', 'mail goes to a log file, so nothing is sent yet');
check(
    $setting('mail.from_email') === 'noreply@crm.test.invalid',
    'the from-address came from the base URL, not from a request header'
);
check(
    $setting('system.force_https') === '' || $setting('system.force_https') === '0',
    'force-HTTPS is off, so a pre-certificate install is not locked out of itself'
);

$count = static fn (string $sql): int => (int) $pdo->query($sql)->fetchColumn();

check($count('SELECT COUNT(*) FROM cc_automations') >= 100, 'the automation library was seeded');
check(
    $count('SELECT COUNT(*) FROM cc_automations WHERE is_active = 1') === 0,
    'and NOTHING is active on a fresh install'
);
check($count('SELECT COUNT(*) FROM cc_playbooks') === 8, 'the eight playbooks are there');
check($count('SELECT COUNT(*) FROM cc_playbooks WHERE is_active = 1') === 0, 'and none is running');
check($count('SELECT COUNT(*) FROM cc_attractions') === 16, 'the venue seed ran');
check(
    $count('SELECT COUNT(*) FROM cc_surveys WHERE is_active = 1') === 0,
    'neither feedback survey is running, so nobody is emailed on install day'
);

// --seed-demo was asked for, so the example guests must be there — and marked
// as examples, which is what lets the venue delete them later.
check($count("SELECT COUNT(*) FROM cc_contacts WHERE source = 'demo'") > 0, 'the example guests were seeded');

$ownerPermissions = $count(
    "SELECT COUNT(*) FROM cc_role_permissions rp JOIN cc_roles r ON r.id = rp.role_id WHERE r.slug = 'owner'"
);
check(
    $ownerPermissions === $count('SELECT COUNT(*) FROM cc_permissions'),
    'the owner holds every permission'
);

// ---------------------------------------------------------------------------
// And it refuses to do it again
// ---------------------------------------------------------------------------

heading('Installing twice');

$before = (string) file_get_contents(CC_ROOT . '/config/config.php');

$result = installer($common, $secrets);

check($result['status'] !== 0, 'a second install is refused');
check(str_contains($result['output'], '--force'), 'and names the flag that would override it');
check(
    (string) file_get_contents(CC_ROOT . '/config/config.php') === $before,
    'leaving the existing config — and its encryption key — untouched'
);

// ---------------------------------------------------------------------------
// Leave the checkout as we found it
// ---------------------------------------------------------------------------

@unlink(CC_ROOT . '/config/config.php');
@unlink(CC_ROOT . '/install/.lock');

echo "\n" . str_repeat('═', 68) . "\n";

if ($failures === 0) {
    echo "PASS — Castle CRM installs headlessly, with the same safe posture\n";
    echo str_repeat('═', 68) . "\n";
    exit(0);
}

printf("FAIL — %d check%s did not pass\n", $failures, $failures === 1 ? '' : 's');
echo str_repeat('═', 68) . "\n";
exit(1);
