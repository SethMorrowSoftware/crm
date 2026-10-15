<?php

declare(strict_types=1);

/**
 * Reset a user's password from the command line.
 *
 * This exists because Castle CRM can otherwise lock you out completely: the
 * reset flow mints a valid token but needs a working mail provider to deliver
 * it, and a fresh install has none. Rather than leave the only recovery path
 * as "read a link out of a log file through cPanel File Manager", this script
 * does the job directly.
 *
 * On cPanel it runs from Terminal, or as a one-off cron line:
 *
 *   php /home/USER/public_html/tools/reset-password.php owner@example.com
 *
 * Usage:
 *   php tools/reset-password.php                        list the accounts
 *   php tools/reset-password.php <email>                set a password (prompted)
 *   php tools/reset-password.php <email> --generate     set a random one and print it
 *   php tools/reset-password.php <email> --unlock       clear a lockout, keep the password
 *   php tools/reset-password.php <email> --make-owner   also promote to Owner
 *
 * Refuses to run over the web: it has no authentication, by design, because
 * filesystem access IS the authentication.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found.\n");
}

define('CC_ROOT', dirname(__DIR__));

// ---------------------------------------------------------------------------
// Preflight
// ---------------------------------------------------------------------------

if (!is_file(CC_ROOT . '/config/config.php')) {
    fwrite(STDERR, "Castle CRM is not installed yet — there is no config/config.php.\n");
    fwrite(STDERR, "Run the web installer at /install/ first.\n");
    exit(1);
}

require CC_ROOT . '/app/bootstrap.php';

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Settings;

try {
    DB::connect();
} catch (Throwable $e) {
    fwrite(STDERR, "Could not reach the database: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Check the credentials in config/config.php.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Arguments
// ---------------------------------------------------------------------------

$arguments = array_slice($argv, 1);
$flags = array_values(array_filter($arguments, static fn (string $a): bool => str_starts_with($a, '--')));
$positional = array_values(array_filter($arguments, static fn (string $a): bool => !str_starts_with($a, '--')));

$hasFlag = static fn (string $flag): bool => in_array('--' . $flag, $flags, true);

if ($hasFlag('help')) {
    echo file_get_contents(__FILE__, false, null, 0, 1500);
    exit(0);
}

echo "Castle CRM — password reset\n";
echo str_repeat('─', 60) . "\n";

// ---------------------------------------------------------------------------
// No email given: list the accounts so the operator can pick one
// ---------------------------------------------------------------------------

if ($positional === []) {
    $users = DB::all(
        'SELECT u.id, u.name, u.email, u.is_active, u.locked_until, u.last_login_at,
                r.name AS role_name, r.slug AS role_slug
           FROM {users} u
           LEFT JOIN {roles} r ON r.id = u.role_id
          WHERE u.deleted_at IS NULL
          ORDER BY u.id'
    );

    if ($users === []) {
        fwrite(STDERR, "There are no accounts at all. Re-run the web installer.\n");
        exit(1);
    }

    printf("%-4s %-26s %-28s %-16s %s\n", 'ID', 'NAME', 'EMAIL', 'ROLE', 'STATE');

    foreach ($users as $user) {
        $locked = $user['locked_until'] !== null && strtotime((string) $user['locked_until']) > time();

        $state = (int) $user['is_active'] !== 1 ? 'inactive' : ($locked ? 'LOCKED' : 'active');

        printf(
            "%-4d %-26s %-28s %-16s %s\n",
            (int) $user['id'],
            substr((string) $user['name'], 0, 25),
            substr((string) $user['email'], 0, 27),
            substr((string) ($user['role_name'] ?? '—'), 0, 15),
            $state
        );
    }

    echo "\nTo reset one:\n";
    echo "  php tools/reset-password.php " . $users[0]['email'] . "\n";
    echo "  php tools/reset-password.php " . $users[0]['email'] . " --generate\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Find the account
// ---------------------------------------------------------------------------

$identifier = $positional[0];

$user = DB::first(
    'SELECT * FROM {users} WHERE (email = :email OR id = :id) AND deleted_at IS NULL',
    ['email' => strtolower(trim($identifier)), 'id' => ctype_digit($identifier) ? (int) $identifier : 0]
);

if ($user === null) {
    fwrite(STDERR, "No active account matches \"" . $identifier . "\".\n");
    fwrite(STDERR, "Run without arguments to list the accounts.\n");
    exit(1);
}

printf("Account: %s <%s>  (id %d)\n", $user['name'], $user['email'], (int) $user['id']);

// ---------------------------------------------------------------------------
// --unlock only: clear the lockout and leave the password alone
// ---------------------------------------------------------------------------

if ($hasFlag('unlock')) {
    DB::update('users', [
        'locked_until'    => null,
        'failed_attempts' => 0,
        'is_active'       => 1,
        'updated_at'      => gmdate('Y-m-d H:i:s'),
    ], 'id = :id', ['id' => (int) $user['id']]);

    Audit::event('password.cli_unlock', ['via' => 'tools/reset-password.php'], 'user', (int) $user['id']);

    echo "Unlocked and reactivated. The existing password still works.\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Choose the new password
// ---------------------------------------------------------------------------

if ($hasFlag('generate')) {
    // Four words plus digits: long, typeable, and strong enough that it does
    // not matter that it appears on screen once.
    $words = ['castle', 'dragon', 'bowling', 'arcade', 'coaster', 'laser', 'rink',
              'kart', 'tower', 'bridge', 'lantern', 'anchor', 'harbor', 'meadow'];

    $picked = [];

    for ($i = 0; $i < 4; $i++) {
        $picked[] = $words[random_int(0, count($words) - 1)];
    }

    $password = implode('-', $picked) . '-' . random_int(100, 999);
} else {
    $password = prompt_password('New password (at least 12 characters): ');

    if ($password === '') {
        fwrite(STDERR, "Nothing entered — no change made.\n");
        exit(1);
    }

    $confirm = prompt_password('Confirm it: ');

    if ($password !== $confirm) {
        fwrite(STDERR, "Those did not match — no change made.\n");
        exit(1);
    }
}

// The real policy, not a weaker CLI one.
$problems = Auth::passwordProblems($password, (string) $user['email'], (string) $user['name']);

if ($problems !== []) {
    fwrite(STDERR, "That password was rejected:\n");

    foreach ($problems as $problem) {
        fwrite(STDERR, '  - ' . $problem . "\n");
    }

    exit(1);
}

// ---------------------------------------------------------------------------
// Apply
// ---------------------------------------------------------------------------

$ownerRoleId = (int) DB::value("SELECT id FROM {roles} WHERE slug = 'owner'");

$update = [
    'password_hash'   => Auth::hash($password),
    'failed_attempts' => 0,
    'locked_until'    => null,
    'is_active'       => 1,
    'updated_at'      => gmdate('Y-m-d H:i:s'),
];

if ($hasFlag('make-owner') && $ownerRoleId > 0) {
    $update['role_id'] = $ownerRoleId;
}

DB::transaction(static function () use ($user, $update): void {
    DB::update('users', $update, 'id = :id', ['id' => (int) $user['id']]);

    // Any live session belongs to whoever had the old password. Changing it
    // from the console and leaving those sessions open would defeat the point.
    DB::delete('sessions', 'user_id = :uid', ['uid' => (int) $user['id']]);
});

Audit::event('password.cli_reset', [
    'via'         => 'tools/reset-password.php',
    'promoted'    => isset($update['role_id']),
    'generated'   => in_array('--generate', $flags, true),
], 'user', (int) $user['id']);

echo "\n" . str_repeat('─', 60) . "\n";
echo "Password changed.\n";

if ($hasFlag('generate')) {
    echo "\n  " . $password . "\n\n";
    echo "Copy it now — it is not stored anywhere in readable form.\n";
}

if (isset($update['role_id'])) {
    echo "Promoted to Owner.\n";
}

echo "Other sessions for this account have been signed out.\n";

$appUrl = Settings::get('venue.website');
echo "\nSign in at " . (is_string($appUrl) && $appUrl !== '' ? rtrim($appUrl, '/') : 'your Castle CRM URL') . "/login\n";

exit(0);

// ===========================================================================
// Helpers
// ===========================================================================

/**
 * Read a password without echoing it.
 *
 * Uses `stty -echo` where a real terminal is attached. When it is not — a
 * cron run, a pipe — it falls back to a visible read and says so, rather than
 * silently hanging or silently echoing.
 */
function prompt_password(string $label): string
{
    echo $label;

    $hidden = false;

    if (function_exists('shell_exec') && stripos(PHP_OS_FAMILY, 'win') === false) {
        $sttyPath = trim((string) @shell_exec('command -v stty 2>/dev/null'));

        if ($sttyPath !== '' && @shell_exec('stty -g 2>/dev/null') !== null) {
            $previous = trim((string) @shell_exec('stty -g 2>/dev/null'));

            if ($previous !== '') {
                @shell_exec('stty -echo 2>/dev/null');
                $hidden = true;

                // Restore the terminal even if the script dies mid-prompt,
                // otherwise the operator's shell is left with echo off.
                register_shutdown_function(static function () use ($previous): void {
                    @shell_exec('stty ' . escapeshellarg($previous) . ' 2>/dev/null');
                });
            }
        }
    }

    if (!$hidden) {
        echo "\n(Input will be visible — no terminal is attached.)\n> ";
    }

    $handle = fopen('php://stdin', 'r');

    if ($handle === false) {
        return '';
    }

    $value = fgets($handle);
    fclose($handle);

    if ($hidden) {
        @shell_exec('stty echo 2>/dev/null');
        echo "\n";
    }

    return $value === false ? '' : trim($value, "\r\n");
}
