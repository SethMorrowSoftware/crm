<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Database backup and table export.
 *
 * Why this exists at all: a venue on cPanel shared hosting will be told by
 * their host that backups are "included", which usually means a weekly
 * account-wide snapshot somebody has to open a ticket to restore. The moment
 * that matters — a member of staff deletes the wrong contact list on a Friday
 * — nobody is going to open a ticket. So Castle CRM takes its own, keeps
 * several, and lets an operator download one from a web page.
 *
 * Why it is written by hand rather than shelling out to `mysqldump`:
 *
 * - `exec()` and friends are disabled on a great many shared hosts, and
 *   `mysqldump` is often not installed at all. A backup feature that works
 *   only on a VPS is not a backup feature for this audience.
 * - The password would have to be passed to a child process, where it is
 *   visible in the process list to every other account on the box.
 *
 * The constraints that shaped the implementation:
 *
 * - **Memory.** Rows are read in keyset-paginated chunks and written as they
 *   arrive, so dumping a 2 GB table does not need 2 GB of RAM. PHP's memory
 *   limit on shared hosting is routinely 128 MB.
 * - **Time.** Shared hosts kill long requests. The write goes to a `.part`
 *   file which is renamed only on success, so a killed run leaves no
 *   half-written file that looks like a backup. A deadline is honoured and
 *   reported rather than hit.
 * - **Blast radius.** Only tables carrying this installation's prefix are
 *   touched, because the database is frequently shared with a WordPress
 *   install next door.
 */
final class Backup
{
    /**
     * Tables whose DATA is not worth keeping: either rebuilt from scratch
     * (sessions), transient (jobs), or an append-only log whose loss costs
     * nothing (webhook deliveries, click tracking). Their STRUCTURE is always
     * dumped, so a restore still produces a working database.
     */
    public const VOLATILE = [
        'sessions',
        'jobs',
        'job_failures',
        'webhook_deliveries',
        'link_clicks',
    ];

    /** Rows per SELECT. Small enough for a 128 MB limit, large enough to be quick. */
    private const CHUNK = 500;

    /** Bytes buffered before a write. One fwrite per ~512 KB rather than per row. */
    private const FLUSH_AT = 524288;

    // -----------------------------------------------------------------
    // Locations
    // -----------------------------------------------------------------

    public static function directory(): string
    {
        $dir = (string) Config::get('storage_path', CC_ROOT . '/storage') . '/backups';

        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        self::protect($dir);

        return $dir;
    }

    /**
     * Belt and braces against a misconfigured docroot.
     *
     * The shipped .htaccess already refuses /storage/ and any .sql, but an
     * operator who installs into a subdirectory, or a host running nginx,
     * might not get that file's protection. A dump is the single most
     * sensitive file this application ever writes — every guest's name, email
     * and phone number in one download — so it gets its own guard.
     */
    private static function protect(string $dir): void
    {
        $htaccess = $dir . '/.htaccess';

        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, <<<'TXT'
            # Database backups. Never servable over HTTP under any circumstances.
            <IfModule mod_authz_core.c>
                Require all denied
            </IfModule>
            <IfModule !mod_authz_core.c>
                Order allow,deny
                Deny from all
            </IfModule>
            TXT);
        }

        // Defeats a directory listing on a server that ignores .htaccess.
        if (!is_file($dir . '/index.html')) {
            @file_put_contents($dir . '/index.html', "<!doctype html><title>Nothing here</title>\n");
        }
    }

    /**
     * Resolve a filename to a real path inside the backup directory.
     *
     * Returns null for anything that is not a backup this class wrote. The
     * name is pattern-matched AND the resolved path is checked against the
     * directory, so neither `../` nor a symlink can reach another file.
     */
    public static function pathFor(string $filename): ?string
    {
        if (!preg_match('/^castle-crm-\d{8}-\d{6}\.sql(\.gz)?$/', $filename)) {
            return null;
        }

        $dir  = realpath(self::directory());
        $path = realpath($dir . '/' . $filename);

        if ($dir === false || $path === false) {
            return null;
        }

        return str_starts_with($path, $dir . DIRECTORY_SEPARATOR) && is_file($path) ? $path : null;
    }

    // -----------------------------------------------------------------
    // Listing
    // -----------------------------------------------------------------

    /**
     * Every backup on disk, newest first.
     *
     * @return list<array{filename:string,bytes:int,created_at:int,compressed:bool}>
     */
    public static function all(): array
    {
        $found = [];

        foreach ((array) glob(self::directory() . '/castle-crm-*.sql*') as $path) {
            if (!is_string($path) || !is_file($path)) {
                continue;
            }

            $name = basename($path);

            // Ignore a .part file from a run that was killed partway.
            if (self::pathFor($name) === null) {
                continue;
            }

            $found[] = [
                'filename'   => $name,
                'bytes'      => (int) filesize($path),
                'created_at' => (int) filemtime($path),
                'compressed' => str_ends_with($name, '.gz'),
            ];
        }

        usort($found, static fn (array $a, array $b): int => $b['created_at'] <=> $a['created_at']);

        return $found;
    }

    public static function totalBytes(): int
    {
        return array_sum(array_column(self::all(), 'bytes'));
    }

    // -----------------------------------------------------------------
    // Creating
    // -----------------------------------------------------------------

    /**
     * Write a backup.
     *
     * @param array{include_volatile?:bool,compress?:bool,deadline?:float,reason?:string} $options
     * @return array{
     *     ok:bool, filename:?string, bytes:int, tables:int, rows:int,
     *     seconds:float, compressed:bool, error:?string
     * }
     */
    public static function create(array $options = []): array
    {
        $started  = microtime(true);
        $deadline = $options['deadline'] ?? ($started + 120.0);
        $compress = $options['compress'] ?? function_exists('gzopen');
        $withVolatile = $options['include_volatile'] ?? false;

        $fail = static fn (string $error): array => [
            'ok' => false, 'filename' => null, 'bytes' => 0, 'tables' => 0, 'rows' => 0,
            'seconds' => 0.0, 'compressed' => false, 'error' => $error,
        ];

        $tables = self::tables();

        if ($tables === []) {
            return $fail('No Castle CRM tables were found. Is the database connected?');
        }

        $dir = self::directory();

        if (!is_writable($dir)) {
            return $fail('storage/backups is not writable. In cPanel File Manager set its permissions to 0750.');
        }

        $name = 'castle-crm-' . gmdate('Ymd-His') . '.sql' . ($compress ? '.gz' : '');
        $part = $dir . '/' . $name . '.part';
        $final = $dir . '/' . $name;

        // A long run is expected; ask for the time if the host allows it. Many
        // do not, which is why the deadline below exists regardless.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $handle = $compress ? @gzopen($part, 'wb6') : @fopen($part, 'wb');

        if ($handle === false) {
            return $fail('Could not create ' . basename($part) . ' in storage/backups.');
        }

        $buffer = '';
        $bytes  = 0;

        $write = static function (string $sql) use (&$buffer, &$bytes, $handle, $compress): void {
            $buffer .= $sql;

            if (strlen($buffer) < self::FLUSH_AT) {
                return;
            }

            $bytes += $compress ? (int) gzwrite($handle, $buffer) : (int) fwrite($handle, $buffer);
            $buffer = '';
        };

        $rows = 0;
        $dumped = 0;
        $truncated = null;

        try {
            $write(self::header($tables, $withVolatile));

            foreach ($tables as $table) {
                if (microtime(true) > $deadline) {
                    $truncated = $table;
                    break;
                }

                $write(self::structure($table));
                $dumped++;

                $short = substr($table, strlen(DB::prefix()));

                if (!$withVolatile && in_array($short, self::VOLATILE, true)) {
                    $write("-- Data skipped: transient table.\n\n");
                    continue;
                }

                $rows += self::data($table, $write, $deadline);
                $write("\n");
            }

            $write(self::footer($rows, $dumped, $truncated));

            // Final flush.
            if ($buffer !== '') {
                $bytes += $compress ? (int) gzwrite($handle, $buffer) : (int) fwrite($handle, $buffer);
            }
        } catch (Throwable $e) {
            $compress ? gzclose($handle) : fclose($handle);
            @unlink($part);

            Log::error('Backup failed', ['error' => $e->getMessage()]);

            return $fail('The backup failed and no file was kept: ' . $e->getMessage());
        }

        $compress ? gzclose($handle) : fclose($handle);

        if ($truncated !== null) {
            // A partial dump must never be presented as a backup. Better no
            // file than a file somebody restores from in an emergency and
            // discovers is missing half their bookings.
            @unlink($part);

            return $fail(
                'The backup ran out of time at table ' . $truncated . ' and was discarded rather than '
                . 'left incomplete. Run it from the command line instead: php tools/backup.php'
            );
        }

        if (!@rename($part, $final)) {
            @unlink($part);

            return $fail('Could not finalise the backup file.');
        }

        @chmod($final, 0640);

        $result = [
            'ok'         => true,
            'filename'   => $name,
            'bytes'      => (int) filesize($final),
            'tables'     => $dumped,
            'rows'       => $rows,
            'seconds'    => round(microtime(true) - $started, 2),
            'compressed' => $compress,
            'error'      => null,
        ];

        Log::info('Backup written', [
            'file'   => $name,
            'bytes'  => $result['bytes'],
            'tables' => $dumped,
            'rows'   => $rows,
            'reason' => $options['reason'] ?? 'manual',
        ]);

        return $result;
    }

    // -----------------------------------------------------------------
    // Retention
    // -----------------------------------------------------------------

    /**
     * Delete all but the newest $keep backups.
     *
     * Shared hosting sells disk by the gigabyte and a venue will not notice a
     * backup directory filling up until something else fails for want of
     * space, so this runs nightly whether or not anyone asked for it.
     */
    public static function prune(int $keep): int
    {
        $keep = max(1, $keep);
        $all  = self::all();

        if (count($all) <= $keep) {
            return 0;
        }

        $removed = 0;

        foreach (array_slice($all, $keep) as $old) {
            $path = self::pathFor($old['filename']);

            if ($path !== null && @unlink($path)) {
                $removed++;
            }
        }

        if ($removed > 0) {
            Log::info('Pruned old backups', ['removed' => $removed, 'kept' => $keep]);
        }

        return $removed;
    }

    public static function delete(string $filename): bool
    {
        $path = self::pathFor($filename);

        return $path !== null && @unlink($path);
    }

    // -----------------------------------------------------------------
    // CSV export
    // -----------------------------------------------------------------

    /**
     * Stream one table to an open handle as CSV, header row first.
     *
     * Separate from the SQL dump because the two answer different questions:
     * a dump answers "put everything back", a CSV answers "give me my contact
     * list" — for a spreadsheet, for another system, or because a guest
     * exercised a right to a copy of their data.
     *
     * @param resource $handle
     */
    public static function exportCsv(string $table, $handle): int
    {
        if (!in_array($table, self::exportableTables(), true)) {
            throw new RuntimeException('That table cannot be exported.');
        }

        $full = DB::table($table);
        $columns = self::columns($full);

        if ($columns === []) {
            throw new RuntimeException('That table does not exist.');
        }

        // Excel reads a bare UTF-8 CSV as Windows-1252 and mangles every
        // accented name in the file. A BOM is the only thing that stops it.
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $columns);

        $written = 0;

        foreach (self::chunks($full, $columns) as $row) {
            fputcsv($handle, array_map(
                static fn (mixed $value): string => $value === null ? '' : (string) $value,
                $row
            ));

            $written++;

            if ($written % 200 === 0) {
                flush();
            }
        }

        return $written;
    }

    /**
     * Tables an operator may export.
     *
     * An allowlist, not a denylist. `sessions` holds live session payloads and
     * `api_tokens` holds credentials; neither belongs in a spreadsheet, and a
     * denylist would silently expose the next sensitive table somebody adds.
     *
     * @return list<string>
     */
    public static function exportableTables(): array
    {
        return [
            'contacts', 'households', 'children', 'companies',
            'deals', 'quotes', 'bookings', 'booking_payments',
            'visits', 'memberships', 'waiver_signatures',
            'consent_log', 'suppressions',
            'tasks', 'reviews', 'form_submissions', 'audit_log',
        ];
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Every table belonging to this installation, in dependency-friendly order.
     *
     * @return list<string>
     */
    public static function tables(): array
    {
        $prefix = DB::prefix();

        // `_` and `%` are LIKE wildcards, and the default prefix `cc_` ends in
        // one. Without escaping, a prefix of `cc_` would also match `ccx`.
        $pattern = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $prefix) . '%';

        $names = [];

        foreach (DB::pdo()->query('SHOW TABLES LIKE ' . DB::pdo()->quote($pattern))->fetchAll(PDO::FETCH_NUM) as $row) {
            $names[] = (string) $row[0];
        }

        sort($names);

        return $names;
    }

    /** @param list<string> $tables */
    private static function header(array $tables, bool $withVolatile): string
    {
        $version  = (string) Settings::get('system.version', 'unknown');
        $written  = gmdate('Y-m-d H:i:s');
        $count    = count($tables);
        $volatile = $withVolatile
            ? 'included'
            : 'structure only (sessions, jobs, delivery logs)';
        $server   = DB::serverVersion();
        $prefix   = DB::prefix();

        return <<<SQL
        -- ===================================================================
        -- Castle CRM database backup
        -- ===================================================================
        --
        -- Castle CRM : {$version}
        -- Written    : {$written} UTC
        -- Tables     : {$count}
        -- Row data   : {$volatile}
        -- Server     : {$server}
        -- Prefix     : {$prefix}
        --
        -- TO RESTORE, in cPanel:
        --   1. phpMyAdmin -> select the database -> Import -> choose this file.
        --      A .gz file can be imported as-is; do not unzip it first.
        --   2. Or from a shell: mysql -u USER -p DATABASE < this-file.sql
        --
        -- IMPORTANT — THIS FILE IS NOT A COMPLETE BACKUP ON ITS OWN.
        --
        --   * config/config.php is NOT in here, and it holds the encryption
        --     key. Provider passwords and API tokens in the `settings` table
        --     are encrypted with that key and are UNREADABLE without it. Keep
        --     a copy of config/config.php somewhere safe and separate.
        --   * storage/uploads is NOT in here. Back that folder up with
        --     cPanel's File Manager if you store attachments.
        --
        -- IMPORTANT — THIS FILE IS CONFIDENTIAL.
        --
        --   It contains every guest's name, email address, phone number and
        --   visit history. Treat a copy of it exactly as you would treat the
        --   database itself: do not email it, and do not leave it in a
        --   Downloads folder on a shared computer.
        -- ===================================================================

        /*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
        /*!40101 SET NAMES utf8mb4 */;
        /*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
        /*!40103 SET TIME_ZONE='+00:00' */;
        /*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
        /*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;


        SQL;
    }

    private static function footer(int $rows, int $tables, ?string $truncated): string
    {
        $note = $truncated === null
            ? '-- Complete.'
            : '-- INCOMPLETE: ran out of time at ' . $truncated;

        return "\n/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;\n"
            . "/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n"
            . "/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;\n"
            . "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n\n"
            . '-- ' . $tables . " tables, " . $rows . " rows.\n"
            . $note . "\n";
    }

    private static function structure(string $table): string
    {
        $row = DB::pdo()
            ->query('SHOW CREATE TABLE ' . self::quote($table))
            ->fetch(PDO::FETCH_NUM);

        if ($row === false || !isset($row[1])) {
            throw new RuntimeException('Could not read the structure of ' . $table . '.');
        }

        return "-- ------------------------------------------------------------\n"
            . '-- Table: ' . $table . "\n"
            . "-- ------------------------------------------------------------\n\n"
            . 'DROP TABLE IF EXISTS ' . self::quote($table) . ";\n"
            . $row[1] . ";\n\n";
    }

    /**
     * Dump a table's rows as multi-row INSERT statements.
     *
     * @param callable(string):void $write
     */
    private static function data(string $table, callable $write, float $deadline): int
    {
        $columns = self::columns($table);

        if ($columns === []) {
            return 0;
        }

        $columnList = implode(', ', array_map([self::class, 'quote'], $columns));
        $insertInto = 'INSERT INTO ' . self::quote($table) . ' (' . $columnList . ") VALUES\n";

        $pdo = DB::pdo();
        $written = 0;
        $batch = [];

        foreach (self::chunks($table, $columns) as $row) {
            if ($written % self::CHUNK === 0 && microtime(true) > $deadline) {
                throw new RuntimeException('Ran out of time while dumping ' . $table . '.');
            }

            $values = [];

            foreach ($columns as $column) {
                $values[] = self::literal($pdo, $row[$column] ?? null);
            }

            $batch[] = '(' . implode(',', $values) . ')';
            $written++;

            // Keep each statement well under max_allowed_packet (1 MB on many
            // shared hosts) so the file imports as readily as it writes.
            if (count($batch) >= 100) {
                $write($insertInto . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        }

        if ($batch !== []) {
            $write($insertInto . implode(",\n", $batch) . ";\n");
        }

        if ($written === 0) {
            $write("-- No rows.\n");
        }

        return $written;
    }

    /**
     * Read a table in chunks.
     *
     * Keyset pagination on a single-column primary key where one exists, which
     * stays fast however deep into the table it gets. OFFSET would be O(n²)
     * over a whole dump. Tables with a composite key (the join tables) are
     * small by construction and read in one go.
     *
     * @param list<string> $columns
     * @return iterable<array<string,mixed>>
     */
    private static function chunks(string $table, array $columns): iterable
    {
        $key = self::singleColumnPrimaryKey($table);
        $quoted = self::quote($table);

        if ($key === null) {
            yield from DB::pdo()->query('SELECT * FROM ' . $quoted)->fetchAll(PDO::FETCH_ASSOC);

            return;
        }

        $last = null;
        $keyQuoted = self::quote($key);

        while (true) {
            if ($last === null) {
                $statement = DB::pdo()->prepare(
                    'SELECT * FROM ' . $quoted . ' ORDER BY ' . $keyQuoted . ' LIMIT ' . self::CHUNK
                );
                $statement->execute();
            } else {
                $statement = DB::pdo()->prepare(
                    'SELECT * FROM ' . $quoted . ' WHERE ' . $keyQuoted . ' > :last'
                    . ' ORDER BY ' . $keyQuoted . ' LIMIT ' . self::CHUNK
                );
                $statement->execute(['last' => $last]);
            }

            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

            if ($rows === []) {
                return;
            }

            foreach ($rows as $row) {
                yield $row;
            }

            $last = $rows[count($rows) - 1][$key] ?? null;

            if ($last === null || count($rows) < self::CHUNK) {
                return;
            }
        }
    }

    private static function singleColumnPrimaryKey(string $table): ?string
    {
        $key = null;

        foreach (
            DB::pdo()->query('SHOW KEYS FROM ' . self::quote($table))->fetchAll(PDO::FETCH_ASSOC) as $row
        ) {
            if (($row['Key_name'] ?? '') !== 'PRIMARY') {
                continue;
            }

            // More than one column in the key: not usable for keyset paging.
            if ($key !== null) {
                return null;
            }

            $key = (string) $row['Column_name'];
        }

        return $key;
    }

    /** @return list<string> */
    private static function columns(string $table): array
    {
        $columns = [];

        foreach (
            DB::pdo()->query('SHOW COLUMNS FROM ' . self::quote($table))->fetchAll(PDO::FETCH_ASSOC) as $row
        ) {
            $columns[] = (string) $row['Field'];
        }

        return $columns;
    }

    /**
     * Render one value as a SQL literal.
     *
     * Binary and invalid-UTF-8 content becomes a hex literal: quoting it would
     * work, but a dump that is not valid UTF-8 cannot be opened in a text
     * editor or imported through phpMyAdmin, which is where these files
     * actually get used.
     */
    private static function literal(PDO $pdo, mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $string = (string) $value;

        if ($string === '') {
            return "''";
        }

        if (str_contains($string, "\0") || !mb_check_encoding($string, 'UTF-8')) {
            return '0x' . bin2hex($string);
        }

        return $pdo->quote($string);
    }

    /**
     * Quote an identifier that came from the server, not from a user.
     *
     * Still escaped rather than trusted: a backtick in a table name is legal
     * in MySQL, and the one place a dump must not break is the restore.
     */
    private static function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
