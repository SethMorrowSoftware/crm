<?php

declare(strict_types=1);

/**
 * Take a database backup from the command line.
 *
 * The escape hatch for the case the web page cannot handle: a database too
 * large to dump inside a shared host's max_execution_time. Run from cPanel's
 * Terminal, or as a cron line of its own:
 *
 *   php tools/backup.php
 *   php tools/backup.php --no-compress --keep=14
 *   php tools/backup.php --list
 *   php tools/backup.php --export=contacts > contacts.csv
 *
 * There is no time limit here, because a CLI process is not killed by the web
 * server, which is the entire reason this file exists.
 */

if (PHP_SAPI !== 'cli') {
    // Never reachable over HTTP. The web route for this is
    // /settings/backups, which is behind authentication and a permission.
    http_response_code(404);
    exit;
}

define('CC_ROOT', dirname(__DIR__));

require CC_ROOT . '/app/bootstrap.php';

use App\Core\Backup;
use App\Core\Config;
use App\Core\Settings;

if (!is_file(CC_ROOT . '/config/config.php')) {
    fwrite(STDERR, "Castle CRM is not installed yet — there is no database to back up.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Arguments
// ---------------------------------------------------------------------------

$args = array_slice($argv, 1);
$flags = [];

// An allowlist, not a shape check. `--kep=1` matches the pattern perfectly
// well, and accepting it would silently keep the default retention while the
// operator believed they had changed it. A tool that quietly discards a
// misspelled flag is a tool that quietly deletes the wrong backups.
$known = ['help', 'list', 'export', 'no-compress', 'include-volatile', 'keep', 'quiet'];

foreach ($args as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m) === 1 && in_array($m[1], $known, true)) {
        $flags[$m[1]] = $m[2] ?? true;
        continue;
    }

    fwrite(STDERR, 'Unrecognised argument: ' . $arg . "\n");
    fwrite(STDERR, 'Known options: --' . implode(', --', $known) . "\n");
    exit(2);
}

if (isset($flags['help'])) {
    fwrite(STDOUT, <<<TXT
    Castle CRM backup

      php tools/backup.php                  Take a backup
      php tools/backup.php --list           List stored backups
      php tools/backup.php --export=TABLE   Write one table to stdout as CSV

    Options
      --no-compress       Write plain .sql instead of .sql.gz
      --include-volatile  Also dump sessions, jobs and delivery logs
      --keep=N            Keep only the newest N backups (default: the setting)
      --quiet             Print the filename and nothing else

    TXT);
    exit(0);
}

// ---------------------------------------------------------------------------
// --list
// ---------------------------------------------------------------------------

if (isset($flags['list'])) {
    $backups = Backup::all();

    if ($backups === []) {
        fwrite(STDOUT, "No backups in " . Backup::directory() . "\n");
        exit(0);
    }

    fwrite(STDOUT, Backup::directory() . ":\n");

    foreach ($backups as $backup) {
        fwrite(STDOUT, sprintf(
            "  %-40s %10s  %s\n",
            $backup['filename'],
            number_format($backup['bytes'] / 1024, 0) . ' KB',
            gmdate('Y-m-d H:i', $backup['created_at']) . ' UTC'
        ));
    }

    exit(0);
}

// ---------------------------------------------------------------------------
// --export=TABLE
// ---------------------------------------------------------------------------

if (isset($flags['export'])) {
    $table = (string) $flags['export'];

    if (!in_array($table, Backup::exportableTables(), true)) {
        fwrite(STDERR, 'That table cannot be exported. Choose one of: '
            . implode(', ', Backup::exportableTables()) . "\n");
        exit(2);
    }

    $handle = fopen('php://stdout', 'wb');

    if ($handle === false) {
        fwrite(STDERR, "Could not open stdout.\n");
        exit(1);
    }

    $rows = Backup::exportCsv($table, $handle);
    fclose($handle);

    // To stderr, so piping stdout to a file keeps the CSV clean.
    fwrite(STDERR, $rows . " rows exported.\n");
    exit(0);
}

// ---------------------------------------------------------------------------
// Take the backup
// ---------------------------------------------------------------------------

$quiet = isset($flags['quiet']);

if (!$quiet) {
    fwrite(STDOUT, 'Backing up ' . Config::get('db.name', '(database)') . " …\n");
}

$result = Backup::create([
    'compress'         => !isset($flags['no-compress']) && function_exists('gzopen'),
    'include_volatile' => isset($flags['include-volatile']),
    // No deadline worth speaking of: a CLI run is not killed at 30 seconds.
    'deadline'         => microtime(true) + 3600.0,
    'reason'           => 'cli',
]);

if (!$result['ok']) {
    Settings::set('system.backup_last_error', (string) $result['error']);
    fwrite(STDERR, 'Failed: ' . $result['error'] . "\n");
    exit(1);
}

Settings::set('system.backup_last_run', gmdate('Y-m-d H:i:s'));
Settings::forget('system.backup_last_error');

$keep = isset($flags['keep']) ? max(1, (int) $flags['keep']) : (int) Settings::get('system.backup_keep', 7);
$pruned = Backup::prune($keep);

$path = Backup::directory() . '/' . $result['filename'];

if ($quiet) {
    fwrite(STDOUT, $path . "\n");
    exit(0);
}

fwrite(STDOUT, sprintf(
    "Wrote %s\n  %s, %d tables, %s rows, %ss.\n",
    $path,
    number_format($result['bytes'] / 1024, 0) . ' KB',
    $result['tables'],
    number_format($result['rows']),
    $result['seconds']
));

if ($pruned > 0) {
    fwrite(STDOUT, '  Pruned ' . $pruned . ' older backup(s), keeping ' . $keep . ".\n");
}

fwrite(
    STDOUT,
    "\nRemember: config/config.php is NOT in this file, and the provider passwords\n"
    . "inside the backup cannot be decrypted without it. Keep a copy of that file\n"
    . "somewhere separate.\n"
);

exit(0);
