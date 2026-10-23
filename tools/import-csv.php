<?php

declare(strict_types=1);

/**
 * Import a CSV from the command line: the same importer as Settings → Import
 * a file, with the same dry run, the same refusals and the same report, but
 * without uploading anything through a browser.
 *
 * It exists for files too big to be comfortable through an upload form, and
 * for tools/centeredge/import.sh, which exports CenterEdge and feeds the three
 * files through here one after another.
 *
 * Usage (as the web server's user, which is the only one that can read
 * config/config.php on a server installed with install.sh):
 *
 *   sudo -u www-data php tools/import-csv.php <guests|children|visits> FILE [options]
 *   sudo -u www-data php tools/import-csv.php guests - < contacts.csv      (from stdin)
 *
 *   --check-only        Dry run and report; write nothing.
 *   --consent-claimed   The opt-in column is real consent the guests gave
 *                       (Importer's consent affirmation). Without it nobody
 *                       imported is emailed.
 *   --on-duplicate=MODE fill (default), overwrite or skip.
 *
 * Every import is a dry run first. If the dry run finds a blocking problem
 * with the columns, nothing is written. The job appears under Settings →
 * Import a file like any other, with its full line-by-line report.
 *
 * Refuses to run over the web.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found.\n");
}

define('CC_ROOT', dirname(__DIR__));

if (!is_readable(CC_ROOT . '/config/config.php')) {
    fwrite(STDERR, "Cannot read config/config.php. Run this as the web server's user:\n");
    fwrite(STDERR, "  sudo -u www-data php " . implode(' ', $argv) . "\n");
    exit(1);
}

require CC_ROOT . '/app/bootstrap.php';

use App\Core\Audit;
use App\Core\Config;
use App\Core\DB;
use App\Services\ImportRunner;
use App\Services\Importer;

// ---------------------------------------------------------------------------
// Arguments
// ---------------------------------------------------------------------------

$arguments  = array_slice($argv, 1);
$flags      = array_values(array_filter($arguments, static fn (string $a): bool => str_starts_with($a, '--')));
$positional = array_values(array_filter($arguments, static fn (string $a): bool => $a === '-' || !str_starts_with($a, '-')));

$hasFlag = static fn (string $flag): bool => in_array('--' . $flag, $flags, true);

$flagValue = static function (string $flag, string $default) use ($flags): string {
    foreach ($flags as $given) {
        if (str_starts_with($given, '--' . $flag . '=')) {
            return substr($given, strlen($flag) + 3);
        }
    }

    return $default;
};

// The words the screen uses, and the entity names underneath.
$entities = ['guests' => 'contacts', 'contacts' => 'contacts', 'children' => 'children', 'visits' => 'visits'];

if ($hasFlag('help') || count($positional) !== 2 || !isset($entities[$positional[0]])) {
    echo file_get_contents(__FILE__, false, null, 0, 1700);
    exit($hasFlag('help') ? 0 : 1);
}

$entity = $entities[$positional[0]];
$source = $positional[1];
$mode   = $flagValue('on-duplicate', 'fill');

if (!Importer::isEntity($entity)) {
    fwrite(STDERR, "This version of Castle CRM cannot import {$positional[0]}.\n");
    exit(1);
}

if (!isset(Importer::ON_DUPLICATE[$mode])) {
    fwrite(STDERR, "--on-duplicate takes one of: " . implode(', ', array_keys(Importer::ON_DUPLICATE)) . "\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Store the file where the importer keeps uploads
// ---------------------------------------------------------------------------

$input = $source === '-' ? STDIN : @fopen($source, 'rb');

if ($input === false) {
    fwrite(STDERR, "Cannot open {$source}.\n");
    exit(1);
}

$stored = 'import-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.csv';
$target = ImportRunner::directory() . '/' . $stored;
$output = @fopen($target, 'xb');

if ($output === false) {
    fwrite(STDERR, "Cannot write to " . ImportRunner::directory() . ". Check that storage/imports is writable.\n");
    exit(1);
}

$size = stream_copy_to_stream($input, $output, Importer::MAX_BYTES + 1);
fclose($output);
@chmod($target, 0640);

$discard = static function (string $message) use ($target): never {
    @unlink($target);
    fwrite(STDERR, $message . "\n");
    exit(1);
};

if ($size === false || $size <= 0) {
    $discard("{$source} is empty.");
}

if ($size > Importer::MAX_BYTES) {
    $discard("{$source} is larger than the importer's limit of " . bytes_human(Importer::MAX_BYTES)
        . '. Split it and import the parts.');
}

$handle = fopen($target, 'rb');
$header = fgetcsv($handle, escape: '');
fclose($handle);

if ($header === false || $header === [null]) {
    $discard("{$source} has no column headings in its first row.");
}

$header = array_map(static fn ($cell): string => (string) $cell, $header);

if (($headerProblem = Importer::headerProblem($header)) !== null) {
    $discard($headerProblem);
}

if (count($header) > Importer::MAX_COLUMNS) {
    $discard("{$source} has " . count($header) . ' columns, which is more than the importer reads.');
}

// The same automatic mapping the screen starts from, kept only where it is sure.
$mapping  = Importer::guessMapping($header, $entity);
$mapping  = array_filter($mapping, static fn ($field): bool => is_string($field) && $field !== '');
$blockers = Importer::blockers(Importer::mappingProblems($mapping, $entity));

if ($blockers !== []) {
    $discard("The columns of {$source} cannot be imported as {$positional[0]}: " . $blockers[0]);
}

$options = [
    'date_order'      => '',
    'on_duplicate'    => $mode,
    'consent_claimed' => $hasFlag('consent-claimed'),
    'visit_source'    => 'pos_import',
];

$id = DB::insert('imports', [
    'entity'        => $entity,
    'original_name' => Importer::cap($source === '-' ? $positional[0] . '.csv' : basename($source), 255),
    'stored_path'   => $stored,
    'bytes'         => $size,
    'checksum'      => hash_file('sha256', $target) ?: null,
    'status'        => 'mapping',
    'headers'       => json_encode($header),
    'mapping'       => json_encode($mapping),
    'options'       => json_encode($options),
    'created_by'    => null,
    'created_at'    => gmdate('Y-m-d H:i:s'),
    'updated_at'    => gmdate('Y-m-d H:i:s'),
]);

Audit::event('import_uploaded', [
    'import_id' => $id,
    'type'      => $entity,
    'file'      => basename($source),
    'bytes'     => $size,
    'via'       => 'tools/import-csv.php',
], 'imports', $id);

echo "Import #{$id}: {$positional[0]} from " . ($source === '-' ? 'stdin' : $source) . ', '
    . bytes_human((int) $size) . ', ' . count($mapping) . " columns mapped.\n";

// ---------------------------------------------------------------------------
// Run it: a dry run, then (unless --check-only) the real thing
// ---------------------------------------------------------------------------

/*
 * CRON IS HELD OFF WHILE THIS RUNS. Cron walks running imports forward too,
 * and two walkers on one job would read the same rows twice. Taking cron's
 * own lock waits for a tick in progress to finish, then keeps the next ones
 * out until this is done.
 */
$lockPath = (string) Config::get('storage_path', CC_ROOT . '/storage') . '/cache/cron.lock';
$lock     = @fopen($lockPath, 'c');

if ($lock === false || !flock($lock, LOCK_EX)) {
    fwrite(STDERR, "Could not take the cron lock at {$lockPath}.\n");
    exit(1);
}

$pass = static function (bool $commit) use ($id, $entity, $options, $mapping): array {
    DB::delete('import_rows', 'import_id = :i', ['i' => $id]);

    DB::update('imports', [
        'status'      => $commit ? 'importing' : 'dry_run',
        'cursor'      => 0,
        'created'     => 0,
        'updated'     => 0,
        'skipped'     => 0,
        'refused'     => 0,
        'total_rows'  => null,
        'error'       => null,
        'started_at'  => gmdate('Y-m-d H:i:s'),
        'finished_at' => null,
        'updated_at'  => gmdate('Y-m-d H:i:s'),
    ], 'id = :id', ['id' => $id]);

    Audit::event($commit ? 'import_started' : 'import_checked', [
        'import_id'       => $id,
        'type'            => $entity,
        'consent_claimed' => $options['consent_claimed'],
        'on_duplicate'    => $options['on_duplicate'],
        'columns'         => count($mapping),
        'via'             => 'tools/import-csv.php',
    ], 'imports', $id);

    $rows = 0;

    do {
        $result = ImportRunner::work($id);
        $rows  += $result['rows'];
        fprintf(STDOUT, "\r  %s  %s rows read", $commit ? 'importing' : 'checking ', number_format($rows));
    } while (!$result['finished']);

    echo "\n";

    return DB::first('SELECT * FROM {imports} WHERE id = :id', ['id' => $id]) ?? [];
};

$report = static function (array $job, bool $dryRun) use ($id): void {
    $verb = $dryRun ? 'would be' : 'were';

    printf("  %s %s added, %s %s updated, %s skipped, %s refused.\n",
        number_format((int) $job['created']), $verb,
        number_format((int) $job['updated']), $verb,
        number_format((int) $job['skipped']),
        number_format((int) $job['refused']));

    $reasons = DB::all(
        "SELECT outcome, reason, COUNT(*) AS rows_count, MAX(message) AS example
           FROM {import_rows}
          WHERE import_id = :i AND outcome IN ('refused','skipped')
          GROUP BY outcome, reason
          ORDER BY rows_count DESC
          LIMIT 5",
        ['i' => $id]
    );

    foreach ($reasons as $reason) {
        printf("    %7s %s: %s\n", number_format((int) $reason['rows_count']), $reason['outcome'],
            (string) ($reason['example'] ?: $reason['reason']));
    }
};

$check = $pass(false);

if ((string) ($check['status'] ?? '') !== 'dry_run_done') {
    fwrite(STDERR, "The check stopped: " . (string) ($check['error'] ?? 'unknown error') . "\n");
    exit(1);
}

echo "Check:\n";
$report($check, true);

if ($hasFlag('check-only') || (int) $check['created'] + (int) $check['updated'] === 0) {
    echo $hasFlag('check-only') ? "Nothing written (--check-only).\n" : "Nothing new to import.\n";
    exit(0);
}

$done = $pass(true);

if ((string) ($done['status'] ?? '') !== 'done') {
    fwrite(STDERR, "The import stopped: " . (string) ($done['error'] ?? 'unknown error') . "\n");
    fwrite(STDERR, "What was written stays. Settings → Import a file → #{$id} has the report.\n");
    exit(1);
}

echo "Imported:\n";
$report($done, false);

flock($lock, LOCK_UN);
fclose($lock);

// The importer is finished with the copy; the report lives in the database.
@unlink($target);
