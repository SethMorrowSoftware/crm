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

// The half of the installer that asks nothing: connecting, seeding, writing
// the config, locking up. Shared with tools/cli-install.php, which is how a
// server with no browser in front of it gets installed. See install/engine.php.
require __DIR__ . '/engine.php';

use App\Core\Auth;
use App\Core\Migration;

// ---------------------------------------------------------------------------
// Lock
// ---------------------------------------------------------------------------

// The installer session has to start before the lock is checked, because
// whoever just finished setup is allowed to see the finish page once (see
// below) and that is recorded in their session.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('castle_crm_install');
    session_start();
}

$state = &$_SESSION['install'];
$state ??= [];

$step = max(1, min(8, (int) ($_GET['step'] ?? 1)));

$lockFile = __DIR__ . '/.lock';

if (is_file($lockFile)) {
    // Completing setup writes the lock, and the POST then redirects to step 8
    // to show the summary. Without this exception the operator would be sent
    // straight to the lockout page and would never see the cron line or the
    // DNS records — the two things the install is useless without.
    $justFinished = $step === 8 && !empty($state['complete']);

    if (!$justFinished) {
        installer_lockout();
        exit;
    }
}
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
                // The two request-shaped values the engine records, read here
                // where there is a request to read them from.
                $summary = installer_finalise($state, [
                    'host'     => (string) ($_SERVER['HTTP_HOST'] ?? ''),
                    'base_url' => installer_currentBaseUrl(),
                ]);
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
 * The base URL the operator is reaching this installer on.
 *
 * Captured because this is the only moment the application can learn its own
 * address without asking the request — see system.install_url above. The
 * installer lives one directory below the app root, so /install is stripped.
 */
function installer_currentBaseUrl(): string
{
    $host = explode('/', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0];

    if ($host === '' || str_contains($host, '@') || preg_match('/^[A-Za-z0-9.-]+(:\d{1,5})?$/', $host) !== 1) {
        return '';
    }

    $secure = (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;

    $path = preg_replace('#/install(/[^/]*)?$#', '', (string) ($_SERVER['SCRIPT_NAME'] ?? '')) ?? '';

    return ($secure ? 'https://' : 'http://') . $host . rtrim($path, '/');
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
        . '<p style="font-size:.9rem">Installed on your own server from a git checkout? '
        . 'Do the opposite — leave the folder alone. <code>install/schema</code> is what applies '
        . 'the next version&rsquo;s tables after a <code>git pull</code>, and this page being '
        . 'locked is what closes the installer.</p>'
        . '<p style="margin-top:1.5rem"><a href="' . $esc($base === '' ? '/' : $base . '/') . '">'
        . 'Go to Castle CRM &rarr;</a></p>'
        . '<p style="font-size:.85rem;color:#8b95a3">Need to start over? Delete '
        . '<code>config/config.php</code> and <code>install/.lock</code>, then reload this page. '
        . 'That does not remove your data.</p>'
        . '</main></body></html>';
}
