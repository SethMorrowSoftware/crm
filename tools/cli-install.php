<?php

declare(strict_types=1);

/**
 * Install Castle CRM from the command line, with nobody in front of a browser.
 *
 * The web installer at /install/ is still the path a cPanel venue takes, and
 * this is the same engine behind it (install/engine.php) — same schema, same
 * seeds, same Safe Mode posture, same config file. What it replaces is the
 * eight screens: everything they ask for arrives as an option instead.
 *
 * `install.sh` runs this as the last step of a server install. It is also the
 * fastest way to rebuild a staging box, or to re-install after a restore.
 *
 *   php tools/cli-install.php \
 *       --db-name=castle_crm --db-user=castle_crm \
 *       --admin-name="Seth Morrow" --admin-email=seth@example.com \
 *       --base-url=https://crm.example.com
 *
 * PASSWORDS ARE NEVER TAKEN ON THE COMMAND LINE, because every process on the
 * machine can read that (`ps auxww`). Each one comes from an environment
 * variable, a file, or a prompt:
 *
 *   CC_DB_PASS=…             or --db-pass-file=FILE     (empty is allowed)
 *   CC_ADMIN_PASSWORD=…      or --admin-password-file=FILE
 *                            or --generate-admin-password  (printed once)
 *
 * Options, all optional unless marked:
 *
 *   --db-name=NAME           REQUIRED. The database, which must already exist.
 *   --db-user=USER           Database user (default: the database name).
 *   --db-host=HOST           Default localhost.
 *   --db-port=PORT           Default 3306.
 *   --db-prefix=PREFIX       Default cc_.
 *   --db-socket=PATH         Instead of host and port, where a host needs it.
 *
 *   --admin-email=EMAIL      REQUIRED. The owner account's sign-in address.
 *   --admin-name=NAME        Default "Owner".
 *
 *   --venue-name=NAME        Default "The Castle Fun Center".
 *   --venue-email=EMAIL      Also becomes the from-address for mail.
 *   --venue-phone=PHONE
 *   --address-1=LINE  --city=CITY  --state=ST  --postal-code=ZIP
 *   --website=URL
 *   --timezone=AREA/CITY     Default America/New_York. The venue's calendar.
 *   --tax-rate=PERCENT       Default 0.
 *
 *   --base-url=URL           The address staff will use. Recorded so links in
 *                            email are never built from a request header.
 *
 *   --no-seed-venue          Skip the Castle's attractions, packages, hours.
 *   --no-seed-playbooks      Skip the eight playbooks and 112 automations.
 *   --seed-demo              Add example guests, deals and bookings.
 *
 *   --force                  Re-install over an existing config/config.php.
 *   --quiet                  Only the summary.
 *
 * TWO REFUSALS:
 *   - it will not overwrite config/config.php without --force, because a new
 *     install mints a new app_key and that makes every stored provider
 *     credential unreadable;
 *   - it will not run once install/.lock exists, for the same reason the web
 *     installer locks itself.
 *
 * What it leaves behind is what the web installer leaves behind: Safe Mode on,
 * every automation and playbook inactive, mail going to a log file, and a
 * venue nobody has confirmed yet. Nothing can reach a guest.
 */

/*
 * CLI ONLY. This writes config/config.php and creates an owner account with a
 * password it is handed — there is no authentication in front of it, because
 * filesystem access IS the authentication. Apache's rewrite lets a real file
 * through untouched, and a host that ignores the shipped .htaccess (nginx in
 * front, AllowOverride None) has nothing else in the way.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found.\n");
}

define('CC_ROOT', dirname(__DIR__));

$options = getopt('', [
    'help',
    'db-name:', 'db-user:', 'db-host:', 'db-port:', 'db-prefix:', 'db-socket:', 'db-pass-file:',
    'admin-email:', 'admin-name:', 'admin-password-file:', 'generate-admin-password',
    'venue-name:', 'venue-email:', 'venue-phone:', 'address-1:', 'city:', 'state:',
    'postal-code:', 'website:', 'timezone:', 'tax-rate:',
    'base-url:',
    'no-seed-venue', 'no-seed-playbooks', 'seed-demo',
    'force', 'quiet',
]);

if ($options === false || isset($options['help'])) {
    echo file_get_contents(__FILE__, false, null, 0, 3100);
    exit($options === false ? 1 : 0);
}

$has = static fn (string $flag): bool => array_key_exists($flag, $options);
$opt = static fn (string $name, string $default = ''): string => isset($options[$name])
    ? trim((string) $options[$name])
    : $default;

$quiet = $has('quiet');

$say = static function (string $line = '') use ($quiet): void {
    if (!$quiet) {
        echo $line . "\n";
    }
};

$fail = static function (string $message, string $hint = ''): never {
    fwrite(STDERR, 'Install failed: ' . $message . "\n");

    if ($hint !== '') {
        fwrite(STDERR, $hint . "\n");
    }

    exit(1);
};

// ---------------------------------------------------------------------------
// Refusals, before anything is touched
// ---------------------------------------------------------------------------

if (is_file(CC_ROOT . '/install/.lock') && !$has('force')) {
    $fail(
        'Castle CRM is already installed on this copy (install/.lock exists).',
        'Pass --force to install over it, or delete config/config.php and install/.lock to start again. '
        . 'Neither removes your data.'
    );
}

if (is_file(CC_ROOT . '/config/config.php') && !$has('force')) {
    $fail(
        'config/config.php already exists.',
        'Re-installing writes a NEW encryption key, which makes any stored provider credential '
        . 'unreadable. Pass --force if that is what you want; use tools/reset-password.php if you only '
        . 'need to get back into the application.'
    );
}

// The engine writes here, and the application logs here from its first line.
foreach (['logs', 'cache', 'uploads', 'exports', 'backups', 'sessions'] as $directory) {
    $path = CC_ROOT . '/storage/' . $directory;

    if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
        $fail('Could not create storage/' . $directory . '.', 'Check who owns ' . CC_ROOT . '/storage.');
    }
}

// ---------------------------------------------------------------------------
// Collect the answers the eight steps would have asked for
// ---------------------------------------------------------------------------

$dbName = $opt('db-name');

if ($dbName === '') {
    $fail('--db-name is required.', 'Run with --help for the full list.');
}

$db = [
    'host'   => $opt('db-host', 'localhost'),
    'port'   => (int) $opt('db-port', '3306'),
    'name'   => $dbName,
    // Defaulted rather than required: on the servers this runs on the user and
    // the database are created together and given the same name.
    'user'   => $opt('db-user', $dbName),
    'pass'   => '',
    'prefix' => $opt('db-prefix', 'cc_'),
    'socket' => $opt('db-socket') !== '' ? $opt('db-socket') : null,
];

if (preg_match('/^[a-z0-9_]*$/i', $db['prefix']) !== 1) {
    $fail('The table prefix may contain only letters, numbers and underscores.');
}

$db['pass'] = cli_install_secret(
    'db',
    $options,
    'db-pass-file',
    'CC_DB_PASS',
    'Database password for ' . $db['user'] . '@' . $db['host'] . ' (empty is allowed): '
);

$ownerEmail = strtolower($opt('admin-email'));

if ($ownerEmail === '') {
    $fail('--admin-email is required: it is the address the owner signs in with.');
}

if (filter_var($ownerEmail, FILTER_VALIDATE_EMAIL) === false) {
    $fail('"' . $ownerEmail . '" is not a valid email address.');
}

$ownerName = $opt('admin-name', 'Owner');

$generatedPassword = false;

if ($has('generate-admin-password')) {
    $ownerPassword = cli_install_generatePassword();
    $generatedPassword = true;
} else {
    $ownerPassword = cli_install_secret(
        'admin',
        $options,
        'admin-password-file',
        'CC_ADMIN_PASSWORD',
        'Password for ' . $ownerEmail . ' (at least 12 characters): '
    );
}

if ($ownerPassword === '') {
    $fail(
        'No owner password was given.',
        'Set CC_ADMIN_PASSWORD, pass --admin-password-file=FILE, or use --generate-admin-password.'
    );
}

$timezone = $opt('timezone', 'America/New_York');

/*
 * Asked of the constructor, not of listIdentifiers().
 *
 * `DateTimeZone::listIdentifiers()` returns the canonical group only, which
 * leaves out `Etc/UTC` — and `Etc/UTC` is what `timedatectl` reports on a
 * stock Ubuntu server that nobody has set a timezone on, which is most of
 * them. Validating against that list refused the commonest machine there is
 * with "not a timezone PHP knows", about a zone PHP plainly knows.
 */
try {
    new DateTimeZone($timezone);
} catch (Throwable) {
    $fail('"' . $timezone . '" is not a timezone PHP knows.', 'Use a name like America/New_York.');
}

$venueEmail = $opt('venue-email');

if ($venueEmail !== '' && filter_var($venueEmail, FILTER_VALIDATE_EMAIL) === false) {
    $fail('"' . $venueEmail . '" is not a valid venue email address.');
}

$venue = [
    'name'        => $opt('venue-name', 'The Castle Fun Center'),
    'email'       => $venueEmail,
    'phone'       => $opt('venue-phone'),
    'address_1'   => $opt('address-1'),
    'city'        => $opt('city'),
    'state'       => $opt('state'),
    'postal_code' => $opt('postal-code'),
    'website'     => $opt('website'),
    'timezone'    => $timezone,
    'tax_rate'    => (float) $opt('tax-rate', '0'),
];

if ($venue['name'] === '') {
    $fail('--venue-name cannot be empty.');
}

$baseUrl = rtrim($opt('base-url'), '/');

if ($baseUrl !== '' && filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
    $fail('"' . $baseUrl . '" is not a URL.', 'It should look like https://crm.example.com.');
}

// The host the engine derives a from-address from, and the one the DNS advice
// is written for. Over HTTP this is the Host header; here it is whatever the
// operator said the install will be reached on.
$host = $baseUrl !== '' ? (string) (parse_url($baseUrl, PHP_URL_HOST) ?? '') : '';

if ($host !== '' && ($port = parse_url($baseUrl, PHP_URL_PORT)) !== null) {
    $host .= ':' . $port;
}

$seeds = [
    'venue'     => !$has('no-seed-venue'),
    'playbooks' => !$has('no-seed-playbooks'),
    'demo'      => $has('seed-demo'),
];

// ---------------------------------------------------------------------------
// Boot the application and the installer's engine
// ---------------------------------------------------------------------------

require CC_ROOT . '/app/bootstrap.php';
require CC_ROOT . '/install/engine.php';

use App\Core\Auth;
use App\Core\DB;
use App\Core\Migration;

$say('Castle CRM — command-line install');
$say(str_repeat('─', 68));
$say('PHP ' . PHP_VERSION . ' · ' . CC_ROOT);
$say();

// The real password policy, not a weaker one for scripts. Checked after the
// bootstrap because it lives in the application.
$problems = Auth::passwordProblems($ownerPassword, $ownerEmail, $ownerName);

if ($problems !== []) {
    $fail('that owner password was rejected: ' . implode(' ', $problems));
}

// ---------------------------------------------------------------------------
// The database
// ---------------------------------------------------------------------------

$test = installer_testDatabase($db);

if (!$test['ok']) {
    $fail(
        $test['error'],
        'Castle CRM does not create its own database. Create it and a user with full privileges on it '
        . 'first — install.sh does that for you on Ubuntu.'
    );
}

$say('Database   ' . $db['user'] . '@' . $db['host'] . '/' . $db['name'] . ' — ' . $test['version']);

foreach ($test['warnings'] as $warning) {
    $say('  note: ' . $warning);
}

// ---------------------------------------------------------------------------
// Schema
// ---------------------------------------------------------------------------

try {
    $migration = new Migration(CC_ROOT . '/install/schema', (string) $db['prefix']);
    $applied = $migration->run();
} catch (Throwable $e) {
    $fail('the schema could not be applied: ' . $e->getMessage());
}

$say(sprintf(
    'Schema     %d file%s applied, %d already present',
    count($applied['applied']),
    count($applied['applied']) === 1 ? '' : 's',
    count($applied['skipped'])
));

foreach ($applied['messages'] as $message) {
    $say('  note: ' . $message);
}

// ---------------------------------------------------------------------------
// Everything else, by the same engine the web installer uses
// ---------------------------------------------------------------------------

$state = [
    'db'             => $db,
    'venue'          => $venue,
    'owner'          => ['name' => $ownerName, 'email' => $ownerEmail],
    'owner_password' => $ownerPassword,
    'seeds'          => $seeds,
];

try {
    $summary = installer_finalise($state, ['host' => $host, 'base_url' => $baseUrl]);
} catch (Throwable $e) {
    $fail('setup could not be completed: ' . $e->getMessage(), 'Nothing was written to config/config.php.');
}

$tables = (int) DB::value(
    'SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name LIKE :prefix',
    ['prefix' => $db['prefix'] . '%']
);

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

$seeded = array_keys($summary['seeds']);
$locked = is_file(CC_ROOT . '/install/.lock');

if ($quiet) {
    // install.sh prints its own summary, and two of them in a row is noise.
    printf(
        "Installed: %d tables, seeded %s, owner %s.\n",
        $tables,
        $seeded === [] ? 'nothing' : implode(', ', $seeded),
        $ownerEmail
    );

    if (!$locked) {
        fwrite(STDERR, "WARNING: install/.lock could not be written — /install/ is still open.\n");
    }

    exit(0);
}

echo "\n" . str_repeat('═', 68) . "\n";
echo "Castle CRM is installed.\n";
echo str_repeat('═', 68) . "\n\n";

printf("  Tables      %d\n", $tables);
printf("  Seeded      %s\n", $seeded === [] ? 'nothing' : implode(', ', $seeded));
printf("  Owner       %s <%s>\n", $ownerName, $ownerEmail);

if ($generatedPassword) {
    printf("  Password    %s\n", $ownerPassword);
    echo "              ↑ copy this now; it is not stored anywhere readable.\n";
}

printf("  Sign in at  %s\n", $baseUrl !== '' ? $baseUrl . '/login' : '/login on this server');
echo "\n";
echo "  Safe Mode is ON: every email and text is redirected to the owner, every\n";
echo "  playbook is off and all 112 automations are inactive. Nothing can reach a\n";
echo "  guest until somebody turns that off deliberately.\n\n";
echo "  Scheduled work needs one cron line — install.sh writes it for you, or:\n";
echo '    ' . $summary['cron_line'] . "\n\n";

if ($locked) {
    echo "  The web installer has closed itself (install/.lock), so /install/ now\n";
    echo "  answers 403.\n";
} else {
    echo "  WARNING: install/.lock could not be written, so /install/ would still let\n";
    echo "  somebody reconfigure this application. Create the file as root:\n";
    echo '    echo "installed" | sudo tee ' . CC_ROOT . "/install/.lock > /dev/null\n";
}

echo "\n";
echo "  Deployed from a git checkout — which is how install.sh does it — KEEP the\n";
echo "  install/ directory. A cPanel install is told to delete it, because there an\n";
echo "  upgrade is a fresh unzip that puts it back; here install/schema is what\n";
echo "  applies the next version's tables on the first request after a git pull, and\n";
echo "  deleting tracked files only makes that pull a conflict. The lock above, and\n";
echo "  the deny rules in .htaccess and tools/server/, are what close it off.\n";

exit(0);

// ===========================================================================
// Helpers
// ===========================================================================

/**
 * A secret, from a file, the environment, or a prompt — in that order.
 *
 * Never from an option: the command line of every process on the machine is
 * world-readable, so `--db-pass=…` would publish it to any account on the box
 * for as long as the install ran, and to the shell history for ever.
 *
 * @param array<string,mixed> $options
 */
function cli_install_secret(
    string $label,
    array $options,
    string $fileOption,
    string $envName,
    string $prompt
): string {
    if (isset($options[$fileOption])) {
        $path = (string) $options[$fileOption];
        $contents = @file_get_contents($path);

        if ($contents === false) {
            fwrite(STDERR, 'Install failed: could not read ' . $path . "\n");
            exit(1);
        }

        // A single trailing newline is how every editor and `echo` ends a
        // file; it is not part of the password.
        return preg_replace('/\R$/', '', $contents) ?? '';
    }

    $fromEnv = getenv($envName);

    if ($fromEnv !== false) {
        return (string) $fromEnv;
    }

    // No file, no variable: ask, if there is anybody to ask.
    if (!stream_isatty(STDIN)) {
        return '';
    }

    return cli_install_prompt($prompt);
}

/**
 * Read a line without echoing it.
 *
 * Lifted from tools/reset-password.php, which explains the stty dance and why
 * the terminal is restored from a shutdown handler.
 */
function cli_install_prompt(string $label): string
{
    echo $label;

    $hidden = false;

    if (function_exists('shell_exec') && stripos(PHP_OS_FAMILY, 'win') === false) {
        $previous = trim((string) @shell_exec('stty -g 2>/dev/null'));

        if ($previous !== '') {
            @shell_exec('stty -echo 2>/dev/null');
            $hidden = true;

            register_shutdown_function(static function () use ($previous): void {
                @shell_exec('stty ' . escapeshellarg($previous) . ' 2>/dev/null');
            });
        }
    }

    $handle = fopen('php://stdin', 'r');
    $value = $handle === false ? false : fgets($handle);

    if (is_resource($handle)) {
        fclose($handle);
    }

    if ($hidden) {
        @shell_exec('stty echo 2>/dev/null');
        echo "\n";
    }

    return $value === false ? '' : trim($value, "\r\n");
}

/**
 * Four words and three digits: long enough for the policy, typeable by somebody
 * reading it off a terminal, and strong enough that printing it once is fine.
 *
 * The same shape tools/reset-password.php --generate produces.
 */
function cli_install_generatePassword(): string
{
    $words = ['castle', 'dragon', 'bowling', 'arcade', 'coaster', 'laser', 'rink',
              'kart', 'tower', 'bridge', 'lantern', 'anchor', 'harbor', 'meadow'];

    $picked = [];

    for ($i = 0; $i < 4; $i++) {
        $picked[] = $words[random_int(0, count($words) - 1)];
    }

    return implode('-', $picked) . '-' . random_int(100, 999);
}
