<?php

declare(strict_types=1);

/**
 * Castle CRM web installer.
 *
 * Eight steps, each re-runnable until setup completes. Designed for someone
 * with cPanel access and no shell: every failure names the cPanel screen that
 * fixes it, and nothing requires a command line.
 *
 * Safety:
 *   - refuses to run once install/.lock exists
 *   - never echoes a database password back into the page
 *   - writes config/config.php only at the final step, atomically
 *   - leaves Safe Mode ON so a finished install cannot message a real guest
 */

define('CC_ROOT', dirname(__DIR__));
define('CC_INSTALLER', true);

require CC_ROOT . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;
use App\Core\DB;
use App\Core\Encryption;
use App\Core\Migration;
use App\Core\Settings;

// ---------------------------------------------------------------------------
// Lock
// ---------------------------------------------------------------------------

$lockFile = __DIR__ . '/.lock';

if (is_file($lockFile)) {
    installer_lockout();
    exit;
}

// ---------------------------------------------------------------------------
// Installer session
//
// Not the application's DB-backed session — there is no database yet. A plain
// PHP session carries the wizard's state between steps.
// ---------------------------------------------------------------------------

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('castle_crm_install');
    session_start();
}

$state = &$_SESSION['install'];
$state ??= [];

$step   = max(1, min(8, (int) ($_GET['step'] ?? 1)));
$errors = [];
$notices = [];

// A CSRF token for the installer's own forms.
$_SESSION['install_token'] ??= bin2hex(random_bytes(32));
$token = $_SESSION['install_token'];

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

if ($isPost && !hash_equals($token, (string) ($_POST['_token'] ?? ''))) {
    $errors[] = 'That form expired. Try again.';
    $isPost = false;
}

// ---------------------------------------------------------------------------
// Step handling
// ---------------------------------------------------------------------------

if ($isPost) {
    switch ($step) {
        // -------------------------------------------------------------- 1
        case 1:
            $state['accepted'] = true;
            installer_redirect(2);
            break;

        // -------------------------------------------------------------- 2
        case 2:
            // Requirements are re-checked on submit so a user who fixed
            // something and pressed back cannot skip past a hard failure.
            $checks = installer_requirements();

            if (installer_hasHardFailure($checks)) {
                $errors[] = 'Some requirements are still unmet. Fix the items marked "Required" and re-check.';
            } else {
                $state['requirements_ok'] = true;
                installer_redirect(3);
            }
            break;

        // -------------------------------------------------------------- 3
        case 3:
            $db = [
                'host'   => trim((string) ($_POST['db_host'] ?? 'localhost')),
                'port'   => (int) ($_POST['db_port'] ?? 3306),
                'name'   => trim((string) ($_POST['db_name'] ?? '')),
                'user'   => trim((string) ($_POST['db_user'] ?? '')),
                'pass'   => (string) ($_POST['db_pass'] ?? ''),
                'prefix' => trim((string) ($_POST['db_prefix'] ?? 'cc_')),
                'socket' => trim((string) ($_POST['db_socket'] ?? '')) ?: null,
            ];

            if ($db['name'] === '' || $db['user'] === '') {
                $errors[] = 'Enter the database name and username.';
                break;
            }

            if (preg_match('/^[a-z0-9_]*$/i', $db['prefix']) !== 1) {
                $errors[] = 'The table prefix may contain only letters, numbers and underscores.';
                break;
            }

            $test = installer_testDatabase($db);

            if (!$test['ok']) {
                $errors[] = $test['error'];
                break;
            }

            foreach ($test['warnings'] as $warning) {
                $notices[] = $warning;
            }

            // Held in the installer session only; written to disk at step 8.
            $state['db'] = $db;
            $state['db_version'] = $test['version'];

            if ($test['warnings'] !== [] && ($_POST['confirm_existing'] ?? '') !== '1') {
                // Make the operator acknowledge a non-empty database rather
                // than silently installing into someone else's data.
                $state['needs_confirm'] = true;
                break;
            }

            installer_redirect(4);
            break;

        // -------------------------------------------------------------- 4
        case 4:
            if (!isset($state['db'])) {
                installer_redirect(3);
            }

            try {
                installer_connect($state['db']);

                $migration = new Migration(CC_ROOT . '/install/schema', $state['db']['prefix']);
                $result = $migration->run();

                $state['schema'] = $result;
                $state['schema_ok'] = true;

                installer_redirect(5);
            } catch (Throwable $e) {
                $errors[] = 'The schema could not be applied: ' . $e->getMessage();
            }
            break;

        // -------------------------------------------------------------- 5
        case 5:
            $venue = [
                'name'        => trim((string) ($_POST['venue_name'] ?? '')),
                'email'       => trim((string) ($_POST['venue_email'] ?? '')),
                'phone'       => trim((string) ($_POST['venue_phone'] ?? '')),
                'address_1'   => trim((string) ($_POST['address_1'] ?? '')),
                'city'        => trim((string) ($_POST['city'] ?? '')),
                'state'       => trim((string) ($_POST['state'] ?? '')),
                'postal_code' => trim((string) ($_POST['postal_code'] ?? '')),
                'website'     => trim((string) ($_POST['website'] ?? '')),
                'timezone'    => trim((string) ($_POST['timezone'] ?? 'America/New_York')),
                'tax_rate'    => (float) ($_POST['tax_rate'] ?? 0),
            ];

            if ($venue['name'] === '') {
                $errors[] = 'Enter the venue name.';
                break;
            }

            if (!in_array($venue['timezone'], DateTimeZone::listIdentifiers(), true)) {
                $errors[] = 'Choose a valid timezone.';
                break;
            }

            if ($venue['email'] !== '' && filter_var($venue['email'], FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = 'That venue email address is not valid.';
                break;
            }

            $state['venue'] = $venue;
            installer_redirect(6);
            break;

        // -------------------------------------------------------------- 6
        case 6:
            $owner = [
                'name'  => trim((string) ($_POST['owner_name'] ?? '')),
                'email' => strtolower(trim((string) ($_POST['owner_email'] ?? ''))),
            ];

            $password = (string) ($_POST['owner_password'] ?? '');
            $confirm  = (string) ($_POST['owner_password_confirmation'] ?? '');

            if ($owner['name'] === '' || $owner['email'] === '') {
                $errors[] = 'Enter your name and email address.';
                break;
            }

            if (filter_var($owner['email'], FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = 'That email address is not valid.';
                break;
            }

            if ($password !== $confirm) {
                $errors[] = 'The two passwords do not match.';
                break;
            }

            // The real policy, not a weaker installer-only one.
            $problems = Auth::passwordProblems($password, $owner['email'], $owner['name']);

            if ($problems !== []) {
                foreach ($problems as $problem) {
                    $errors[] = $problem;
                }
                break;
            }

            $state['owner'] = $owner;
            // Held in the session for one step, never written to a file or log.
            $state['owner_password'] = $password;

            installer_redirect(7);
            break;

        // -------------------------------------------------------------- 7
        case 7:
            $state['seeds'] = [
                'venue'     => isset($_POST['seed_venue']),
                'playbooks' => isset($_POST['seed_playbooks']),
                'demo'      => isset($_POST['seed_demo']),
            ];

            installer_redirect(8);
            break;

        // -------------------------------------------------------------- 8
        case 8:
            try {
                $summary = installer_finalise($state);
                $state['summary'] = $summary;
                $state['complete'] = true;

                // Clear the password from the session the instant it is used.
                unset($state['owner_password']);

                installer_redirect(8);
            } catch (Throwable $e) {
                $errors[] = 'Setup could not be completed: ' . $e->getMessage();
            }
            break;
    }
}

// ---------------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------------

$checks = $step === 2 ? installer_requirements() : [];

require __DIR__ . '/steps/layout.php';

// ===========================================================================
// Functions
// ===========================================================================

function installer_redirect(int $step): void
{
    $base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    header('Location: ' . $base . '/index.php?step=' . $step, true, 302);
    exit;
}

/**
 * The requirement matrix.
 *
 * Each entry: label, ok, required, detail, fix. `required` false makes it a
 * warning the operator can proceed past.
 *
 * @return list<array{label:string,ok:bool,required:bool,detail:string,fix:?string}>
 */
function installer_requirements(): array
{
    $checks = [];

    $checks[] = [
        'label'    => 'PHP 8.1 or newer',
        'ok'       => PHP_VERSION_ID >= 80100,
        'required' => true,
        'detail'   => 'Running ' . PHP_VERSION,
        'fix'      => 'In cPanel, open MultiPHP Manager, tick this domain and choose PHP 8.1 or later.',
    ];

    foreach ([
        'pdo_mysql' => 'Database access',
        'mbstring'  => 'Text handling',
        'json'      => 'Data encoding',
        'openssl'   => 'Credential encryption',
        'curl'      => 'Email and SMS providers',
        'fileinfo'  => 'Safe file uploads',
    ] as $extension => $purpose) {
        $checks[] = [
            'label'    => 'PHP extension: ' . $extension,
            'ok'       => extension_loaded($extension),
            'required' => true,
            'detail'   => $purpose,
            'fix'      => 'In cPanel, open "Select PHP Version" → Extensions and tick ' . $extension . '.',
        ];
    }

    foreach ([
        'zip'  => 'Backup downloads',
        'gd'   => 'Image resizing for logos',
        'apcu' => 'Faster caching',
        'intl' => 'Locale-aware formatting',
    ] as $extension => $purpose) {
        $checks[] = [
            'label'    => 'Optional: ' . $extension,
            'ok'       => extension_loaded($extension),
            'required' => false,
            'detail'   => $purpose . ' (degrades gracefully without it)',
            'fix'      => null,
        ];
    }

    // The POS feed is explicitly optional (spec §8.3).
    $posDrivers = array_values(array_intersect(['sqlsrv', 'dblib', 'odbc'], PDO::getAvailableDrivers()));

    $checks[] = [
        'label'    => 'Optional: CenterEdge POS driver',
        'ok'       => $posDrivers !== [],
        'required' => false,
        'detail'   => $posDrivers === []
            ? 'No MSSQL driver. Visits will come from bookings and front-desk check-in instead of the card ledger.'
            : 'Found: ' . implode(', ', $posDrivers),
        'fix'      => $posDrivers === []
            ? 'Installing pdo_sqlsrv or pdo_dblib needs server-level access. On shared hosting the POS feed '
                . 'may need to be pushed from the venue instead of pulled from here.'
            : null,
    ];

    foreach ([
        'config'           => CC_ROOT . '/config',
        'storage/logs'     => CC_ROOT . '/storage/logs',
        'storage/cache'    => CC_ROOT . '/storage/cache',
        'storage/uploads'  => CC_ROOT . '/storage/uploads',
        'storage/exports'  => CC_ROOT . '/storage/exports',
        'storage/backups'  => CC_ROOT . '/storage/backups',
    ] as $label => $path) {
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }

        $checks[] = [
            'label'    => 'Writable: ' . $label,
            'ok'       => is_dir($path) && is_writable($path),
            'required' => true,
            'detail'   => is_dir($path) ? 'Directory exists' : 'Directory is missing',
            'fix'      => 'In cPanel File Manager, select the folder, choose Permissions and set it to 0775.',
        ];
    }

    $rewrite = installer_testRewrite();

    $checks[] = [
        'label'    => 'URL rewriting',
        'ok'       => $rewrite,
        // Not required: the app falls back to index.php?_route=/path.
        'required' => false,
        'detail'   => $rewrite
            ? 'mod_rewrite is active'
            : 'Could not confirm mod_rewrite. Castle CRM will still work using longer URLs.',
        'fix'      => $rewrite
            ? null
            : 'Usually fine on cPanel. If links 404 after setup, check that .htaccess was uploaded and that '
                . 'the host allows overrides.',
    ];

    $checks[] = [
        'label'    => 'HTTPS',
        'ok'       => installer_isSecure(),
        'required' => false,
        'detail'   => installer_isSecure()
            ? 'This page is encrypted'
            : 'This page is NOT encrypted. Passwords typed here would travel in the clear.',
        'fix'      => installer_isSecure()
            ? null
            : 'Enable AutoSSL in cPanel → SSL/TLS Status, then reload this installer over https://. '
                . 'Castle CRM stores guest contact details and staff passwords.',
    ];

    return $checks;
}

/** @param list<array{ok:bool,required:bool}> $checks */
function installer_hasHardFailure(array $checks): bool
{
    foreach ($checks as $check) {
        if ($check['required'] && !$check['ok']) {
            return true;
        }
    }

    return false;
}

/**
 * Fetch our own rewrite probe. Live testing beats module introspection, which
 * is unavailable under PHP-FPM.
 */
function installer_testRewrite(): bool
{
    $scheme = installer_isSecure() ? 'https' : 'http';
    $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $base   = rtrim(str_replace('\\', '/', dirname(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')))), '/');
    $url    = $scheme . '://' . $host . $base . '/__rewrite_probe';

    if (!function_exists('curl_init')) {
        return false;
    }

    $ch = curl_init($url);

    if ($ch === false) {
        return false;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_FOLLOWLOCATION => false,
        // A self-signed or still-provisioning certificate must not make this
        // look like a rewrite failure.
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);

    $body = curl_exec($ch);
    curl_close($ch);

    return is_string($body) && str_contains($body, 'rewrite-ok');
}

function installer_isSecure(): bool
{
    if (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }

    return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/**
 * Test the supplied credentials, verify the privileges we need, and report
 * anything already in the database.
 *
 * @param array<string,mixed> $db
 * @return array{ok:bool,error:string,warnings:list<string>,version:string}
 */
function installer_testDatabase(array $db): array
{
    $warnings = [];

    try {
        $pdo = installer_connect($db);
    } catch (Throwable $e) {
        return [
            'ok' => false,
            // The driver's own message is the most useful thing here, and it
            // does not contain the password.
            'error' => 'Could not connect: ' . installer_friendlyDbError($e->getMessage()),
            'warnings' => [],
            'version' => '',
        ];
    }

    $version = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);

    // Prove we can actually create and drop, rather than discovering it
    // halfway through applying the schema.
    $probe = $db['prefix'] . 'install_probe';

    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS `' . $probe . '` (`id` INT NOT NULL) ENGINE=InnoDB');
        $pdo->exec('ALTER TABLE `' . $probe . '` ADD COLUMN `probe` INT NULL');
        $pdo->exec('DROP TABLE `' . $probe . '`');
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'error' => 'Connected, but this user cannot create tables. In cPanel → MySQL Databases, '
                . 'add the user to the database with ALL PRIVILEGES.',
            'warnings' => [],
            'version' => $version,
        ];
    }

    // Already-installed detection.
    $ours = (int) $pdo->query(
        'SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name LIKE ' . $pdo->quote($db['prefix'] . '%')
    )->fetchColumn();

    if ($ours > 0) {
        $warnings[] = sprintf(
            'This database already contains %d table%s with the prefix "%s". Setup will add anything '
            . 'missing and leave existing data alone.',
            $ours,
            $ours === 1 ? '' : 's',
            $db['prefix']
        );
    }

    $foreign = (int) $pdo->query(
        'SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name NOT LIKE ' . $pdo->quote($db['prefix'] . '%')
    )->fetchColumn();

    if ($foreign > 0) {
        $warnings[] = sprintf(
            'This database also holds %d table%s belonging to something else. That is safe — Castle CRM '
            . 'only touches tables starting with "%s" — but double-check you picked the right database.',
            $foreign,
            $foreign === 1 ? '' : 's',
            $db['prefix']
        );
    }

    if (version_compare($version, '5.7.8', '<') && !str_contains(strtolower($version), 'mariadb')) {
        $warnings[] = 'MySQL ' . $version . ' is older than the tested 5.7.8. Setup may fail on JSON columns.';
    }

    return ['ok' => true, 'error' => '', 'warnings' => $warnings, 'version' => $version];
}

/**
 * Translate a driver error into something actionable in cPanel.
 */
function installer_friendlyDbError(string $message): string
{
    if (str_contains($message, 'Access denied')) {
        return 'the username or password was rejected. In cPanel → MySQL Databases, confirm the user is '
            . 'assigned to this database with ALL PRIVILEGES. Remember cPanel prefixes both names with '
            . 'your account, like "cpaneluser_castlecrm".';
    }

    if (str_contains($message, 'Unknown database')) {
        return 'that database does not exist. Create it in cPanel → MySQL Databases first, and use the '
            . 'full prefixed name.';
    }

    if (str_contains($message, "Can't connect") || str_contains($message, 'Connection refused')) {
        return 'the server could not be reached. On cPanel the host is almost always "localhost".';
    }

    return $message;
}

/**
 * @param array<string,mixed> $db
 */
function installer_connect(array $db): PDO
{
    Config::set('db.host', $db['host']);
    Config::set('db.port', $db['port']);
    Config::set('db.name', $db['name']);
    Config::set('db.user', $db['user']);
    Config::set('db.pass', $db['pass']);
    Config::set('db.prefix', $db['prefix']);
    Config::set('db.socket', $db['socket']);

    DB::disconnect();
    $pdo = DB::connect();
    DB::setPrefix((string) $db['prefix']);

    return $pdo;
}

/**
 * Write the configuration, create the owner, run the seeds.
 *
 * Ordered so the config file is written LAST: if seeding fails, the install is
 * still marked incomplete and the operator can retry rather than landing in a
 * half-configured application.
 *
 * @param array<string,mixed> $state
 * @return array<string,mixed>
 */
function installer_finalise(array &$state): array
{
    if (!isset($state['db'], $state['venue'], $state['owner'], $state['owner_password'])) {
        throw new RuntimeException('Some setup steps are incomplete. Start again from step 1.');
    }

    installer_connect($state['db']);

    $appKey    = Encryption::generateKey();
    $cronToken = bin2hex(random_bytes(24));

    // The key must be live before anything encrypted is written.
    Config::set('app_key', $appKey);
    Config::set('cron_token', $cronToken);

    $summary = ['seeds' => []];

    DB::transaction(static function () use ($state, &$summary): void {
        // --- Permissions and roles (always; the app is unusable without them)
        $seedPermissions = require CC_ROOT . '/install/seeds/permissions.php';
        $summary['seeds']['permissions'] = $seedPermissions();

        // --- Venue settings
        $venue = $state['venue'];

        Settings::setMany([
            'venue.name'        => $venue['name'],
            'venue.email'       => $venue['email'] ?: null,
            'venue.phone'       => $venue['phone'] ?: null,
            'venue.address_1'   => $venue['address_1'] ?: null,
            'venue.city'        => $venue['city'] ?: null,
            'venue.state'       => $venue['state'] ?: null,
            'venue.postal_code' => $venue['postal_code'] ?: null,
            'venue.website'     => $venue['website'] ?: null,
            'venue.timezone'    => $venue['timezone'],
            'venue.tax_rate'    => $venue['tax_rate'],
            'venue.brand_color' => '#6b4ea8',
            // The operator still has to confirm these on the dashboard
            // checklist, because the seeded prices came from a public website.
            'venue.confirmed'   => false,
        ]);

        Settings::setMany([
            // Safe Mode ON. This is the single most important default in the
            // installer: a finished install cannot message a real guest.
            'system.safe_mode'            => true,
            'system.sending_paused'       => false,
            'system.quiet_hours_start'    => '09:00',
            'system.quiet_hours_end'      => '20:00',
            'system.message_cap_per_week' => 4,
            'system.lapse_days'           => 120,
            'system.attribution_days'     => 30,
            'system.installed_at'         => gmdate('Y-m-d H:i:s'),
            'system.version'              => trim((string) @file_get_contents(CC_ROOT . '/VERSION')),
        ]);

        // --- Owner account
        $ownerRoleId = (int) DB::value("SELECT id FROM {roles} WHERE slug = 'owner'");

        if ($ownerRoleId === 0) {
            throw new RuntimeException('The owner role was not created. Re-run step 4.');
        }

        $existing = DB::first(
            'SELECT id FROM {users} WHERE email = :email',
            ['email' => $state['owner']['email']]
        );

        if ($existing !== null) {
            DB::update('users', [
                'name'          => $state['owner']['name'],
                'password_hash' => Auth::hash($state['owner_password']),
                'role_id'       => $ownerRoleId,
                'is_active'     => 1,
                'timezone'      => $state['venue']['timezone'],
                'updated_at'    => gmdate('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => (int) $existing['id']]);

            $summary['owner_id'] = (int) $existing['id'];
        } else {
            $summary['owner_id'] = DB::insert('users', [
                'name'          => $state['owner']['name'],
                'email'         => $state['owner']['email'],
                'password_hash' => Auth::hash($state['owner_password']),
                'role_id'       => $ownerRoleId,
                'is_active'     => 1,
                'timezone'      => $state['venue']['timezone'],
                'created_at'    => gmdate('Y-m-d H:i:s'),
                'updated_at'    => gmdate('Y-m-d H:i:s'),
            ]);
        }

        // --- Optional seeds
        if (!empty($state['seeds']['venue'])) {
            $seedVenue = require CC_ROOT . '/install/seeds/venue.php';
            $summary['seeds']['venue'] = $seedVenue();
        }

        if (!empty($state['seeds']['playbooks'])) {
            $seedPlaybooks = require CC_ROOT . '/install/seeds/playbooks.php';
            $summary['seeds']['playbooks'] = $seedPlaybooks();
        }
    });

    // --- Write the config file, atomically.
    installer_writeConfig($state['db'], $appKey, $cronToken);

    // --- Lock the installer.
    @file_put_contents(
        __DIR__ . '/.lock',
        'Castle CRM was installed on ' . gmdate('c') . " UTC.\n"
        . "Delete the whole install/ directory now that setup is finished.\n"
    );

    $summary['cron_line'] = sprintf(
        '* * * * * %s %s/cron.php >/dev/null 2>&1',
        PHP_BINARY !== '' && !str_contains(PHP_BINARY, 'fpm') ? PHP_BINARY : '/usr/local/bin/php',
        CC_ROOT
    );

    $summary['cron_token'] = $cronToken;
    $summary['dns'] = installer_dnsRecords($state['venue']);

    return $summary;
}

/**
 * @param array<string,mixed> $db
 */
function installer_writeConfig(array $db, string $appKey, string $cronToken): void
{
    $export = static fn (mixed $v): string => var_export($v, true);

    $contents = <<<PHP
<?php
/**
 * Castle CRM configuration.
 *
 * Written by the installer on {$export(gmdate('c'))}.
 * Keep this file out of version control: it holds the database password and
 * the encryption key.
 */

return [
    'app_url'   => null,
    'env'       => 'production',
    'debug'     => false,

    // Encrypts provider credentials at rest. CHANGING THIS MAKES EXISTING
    // ENCRYPTED SETTINGS UNREADABLE — you would have to re-enter them.
    'app_key'   => {$export($appKey)},

    'db' => [
        'host'    => {$export($db['host'])},
        'port'    => {$export((int) $db['port'])},
        'name'    => {$export($db['name'])},
        'user'    => {$export($db['user'])},
        'pass'    => {$export($db['pass'])},
        'charset' => 'utf8mb4',
        'prefix'  => {$export($db['prefix'])},
        'socket'  => {$export($db['socket'])},
    ],

    'storage_path' => null,

    'session' => [
        'name'             => 'castle_crm_session',
        'idle_timeout'     => 28800,
        'absolute_timeout' => 2592000,
        'cookie_secure'    => null,
        'cookie_samesite'  => 'Lax',
    ],

    // Enable ONLY behind a proxy you control. With no proxy, this would let a
    // client spoof its own IP and defeat rate limiting.
    'trust_proxy' => false,

    // Shared secret for triggering cron over HTTP where CLI cron is unavailable.
    'cron_token' => {$export($cronToken)},

    'log_level' => 'info',
];

PHP;

    $target = CC_ROOT . '/config/config.php';
    $temp   = $target . '.' . getmypid() . '.tmp';

    if (@file_put_contents($temp, $contents, LOCK_EX) === false) {
        throw new RuntimeException(
            'Could not write config/config.php. Set the config folder to 0775 in cPanel File Manager.'
        );
    }

    // Readable by the web server, not by the world.
    @chmod($temp, 0640);

    if (!@rename($temp, $target)) {
        @unlink($temp);
        throw new RuntimeException('Could not finalise config/config.php. Check the config folder permissions.');
    }
}

/**
 * The DNS records to add in cPanel → Zone Editor, so mail from Castle CRM is
 * not treated as spam.
 *
 * @param array<string,mixed> $venue
 * @return list<array{type:string,name:string,value:string,note:string}>
 */
function installer_dnsRecords(array $venue): array
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'your-domain.com');
    $domain = preg_replace('/^www\./', '', explode(':', $host)[0]) ?? $host;

    return [
        [
            'type'  => 'TXT',
            'name'  => $domain,
            'value' => 'v=spf1 +a +mx include:_spf.' . $domain . ' ~all',
            'note'  => 'Authorises this server to send mail for the domain. If an SPF record already '
                . 'exists, ADD to it rather than creating a second one — two SPF records break both.',
        ],
        [
            'type'  => 'TXT',
            'name'  => '_dmarc.' . $domain,
            'value' => 'v=DMARC1; p=none; rua=mailto:postmaster@' . $domain,
            'note'  => 'Starts DMARC in report-only mode. Tighten to p=quarantine once reports look clean.',
        ],
        [
            'type'  => 'TXT',
            'name'  => 'default._domainkey.' . $domain,
            'value' => '(generated by cPanel)',
            'note'  => 'DKIM signs your mail. In cPanel open Email Deliverability and click Repair — it '
                . 'generates and installs this record for you.',
        ],
    ];
}

function installer_lockout(): void
{
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');

    $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $base = rtrim(str_replace('\\', '/', dirname(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')))), '/');

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Already installed</title>'
        . '<style>body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#f6f7f9;'
        . 'color:#1b1f26;display:grid;place-items:center;min-height:100vh;margin:0;padding:1rem}'
        . 'main{background:#fff;padding:2.5rem;border-radius:12px;max-width:34rem;'
        . 'box-shadow:0 1px 3px rgba(0,0,0,.08)}h1{margin:0 0 1rem;font-size:1.5rem;color:#6b4ea8}'
        . 'p{color:#5b6472;line-height:1.6}code{background:#f1f3f5;padding:.15rem .4rem;border-radius:4px}'
        . '.warn{background:#fff4e5;border-left:3px solid #f59e0b;padding:.85rem 1rem;border-radius:4px}'
        . 'a{color:#6b4ea8}</style></head><body><main>'
        . '<h1>Castle CRM is already installed</h1>'
        . '<p>The installer has locked itself to stop anyone reconfiguring this install.</p>'
        . '<div class="warn"><strong>One job left:</strong> delete the <code>install</code> folder. '
        . 'In cPanel open File Manager, select it, and choose Delete.</div>'
        . '<p style="margin-top:1.5rem"><a href="' . $esc($base === '' ? '/' : $base . '/') . '">'
        . 'Go to Castle CRM &rarr;</a></p>'
        . '<p style="font-size:.85rem;color:#8b95a3">Need to start over? Delete '
        . '<code>config/config.php</code> and <code>install/.lock</code>, then reload this page. '
        . 'That does not remove your data.</p>'
        . '</main></body></html>';
}
