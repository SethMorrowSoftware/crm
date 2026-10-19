<?php

declare(strict_types=1);

namespace App\Core;

use PDOException;
use RuntimeException;

/**
 * Applies the numbered .sql files in install/schema.
 *
 * MySQL DDL is not transactional, so a file that fails halfway leaves partial
 * state. Two things make that survivable: every CREATE uses
 * `IF NOT EXISTS`, and the runner treats "this object already exists" as
 * success rather than an error. Re-running a half-applied file therefore
 * completes it instead of dying on the tables it already made.
 */
final class Migration
{
    /**
     * MySQL error codes meaning "the thing you asked me to create is already
     * there". Tolerated, because that is the desired end state.
     *
     * Every code here must mean that and ONLY that. 1005 ("can't create
     * table") used to be in this list on the theory that it is how older MySQL
     * reports a duplicate foreign-key name — but it is also how MySQL reports
     * a foreign key it refuses to build at all, such as one whose column type
     * does not match the column it references. MEASURED on MariaDB 10.11: a
     * release whose new table declared `contact_id INT UNSIGNED` against
     * `contacts.id BIGINT UNSIGNED` failed with 1005 (errno 150), the table was
     * NOT created, the file was recorded as applied, and Upgrade::run() then
     * advanced system.version — so the next request saw nothing pending and the
     * venue ran on a schema missing a table, with the marker claiming the
     * upgrade had succeeded. That is the one failure the version marker exists
     * to prevent.
     *
     * 1022 went for the same reason: it is "duplicate key" in the sense of a
     * duplicate VALUE, which is a data conflict, not an object that already
     * exists. It is back, but ONLY for a statement that adds a constraint —
     * see meansAlreadyApplied() — because dropping it outright broke retries
     * on MySQL 5.7.
     *
     * WHAT EACH ENGINE ACTUALLY REPORTS for a duplicate foreign-key constraint
     * name, MEASURED rather than assumed — this comment previously claimed
     * "1826 on MySQL 5.7+ and MariaDB" and was wrong about both:
     *
     *   MySQL 5.7.44     ALTER … ADD CONSTRAINT  →  1022 "Can't write;
     *                    duplicate key in table '#sql-…'"   (CI, 2026-10-15)
     *   MariaDB 10.11    ALTER … ADD CONSTRAINT  →  1005 + errno 121
     *                    (and the same for CREATE TABLE)    (measured locally)
     *   MySQL 8.0        tolerated — it passes the retry test, but WHICH of the
     *                    codes below it uses was not measured. Do not write
     *                    "1826" here as though it were established.
     *
     * The malformed-FK case differs again: 1005 + errno 150 on MariaDB, and
     * 1215 "Cannot add foreign key constraint" on MySQL 5.7. Neither is
     * tolerated, which is the point.
     */
    private const ALREADY_EXISTS = [
        1050, // table exists
        1060, // duplicate column
        1061, // duplicate key name
        1826, // duplicate foreign key constraint name
        1831, // duplicate index
    ];

    /**
     * 1005 is ambiguous, so it is decided on the driver's own errno:
     *   121 — duplicate constraint name. Already there; tolerate.
     *   150 — the foreign key is malformed. A real error; must throw.
     */
    private const DUPLICATE_CONSTRAINT_ERRNO = 121;

    /**
     * MySQL 5.7's answer to a duplicate constraint name, and ONLY trusted when
     * the statement is adding one. On its own it means a duplicate VALUE, so a
     * data-migration INSERT hitting a real conflict reports the same number —
     * and swallowing that would record a half-applied file as complete, which
     * is the exact failure the narrowing in this release set out to stop.
     */
    private const DUPLICATE_ON_ADD = 1022;

    private string $schemaPath;

    private string $prefix;

    /** @var list<string> */
    private array $messages = [];

    public function __construct(?string $schemaPath = null, ?string $prefix = null)
    {
        $this->schemaPath = $schemaPath ?? CC_ROOT . '/install/schema';
        $this->prefix     = $prefix ?? DB::prefix();
    }

    /**
     * Apply every pending file.
     *
     * @return array{applied:list<string>,skipped:list<string>,messages:list<string>}
     */
    public function run(): array
    {
        $this->ensureMigrationsTable();

        $applied = [];
        $skipped = [];

        $already = $this->alreadyRun();
        $batch   = $this->nextBatch();

        foreach ($this->files() as $file) {
            $name = basename($file);

            if (in_array($name, $already, true)) {
                $skipped[] = $name;
                continue;
            }

            $this->apply($file, $name, $batch);
            $applied[] = $name;
        }

        return [
            'applied'  => $applied,
            'skipped'  => $skipped,
            'messages' => $this->messages,
        ];
    }

    /** @return list<string> */
    public function pending(): array
    {
        $already = $this->tableExists() ? $this->alreadyRun() : [];

        return array_values(array_filter(
            array_map('basename', $this->files()),
            static fn (string $name): bool => !in_array($name, $already, true)
        ));
    }

    /** @return list<string> */
    public function files(): array
    {
        $files = glob($this->schemaPath . '/*.sql') ?: [];

        // Numeric prefixes give the order; sort() is enough because they are
        // zero-padded to the same width.
        sort($files, SORT_STRING);

        return array_values($files);
    }

    private function apply(string $file, string $name, int $batch): void
    {
        $sql = file_get_contents($file);

        if ($sql === false) {
            throw new RuntimeException("Could not read schema file {$name}.");
        }

        $sql = str_replace('{prefix}', $this->prefix, $sql);

        $statements = $this->split($sql);

        if ($statements === []) {
            throw new RuntimeException("Schema file {$name} contained no statements.");
        }

        foreach ($statements as $index => $statement) {
            try {
                DB::pdo()->exec($statement);
            } catch (PDOException $e) {
                $code = (int) ($e->errorInfo[1] ?? 0);

                if ($this->meansAlreadyApplied($code, $e->getMessage(), $statement)) {
                    // Expected when re-running a partially applied file.
                    $this->messages[] = sprintf('%s: statement %d already applied.', $name, $index + 1);
                    continue;
                }

                Log::error('Schema statement failed', [
                    'file'      => $name,
                    'statement' => $index + 1,
                    'code'      => $code,
                    'error'     => $e->getMessage(),
                    // The first line is usually enough to identify the object.
                    'sql'       => substr($statement, 0, 200),
                ]);

                throw new RuntimeException(sprintf(
                    'Schema file %s failed at statement %d: %s',
                    $name,
                    $index + 1,
                    $e->getMessage()
                ), 0, $e);
            }
        }

        // Into THIS run's migrations table. DB::insert() would expand the
        // global prefix, so a run against another prefix applied its
        // statements there and recorded them here — and the next run under
        // the global prefix believed files applied that never were.
        DB::pdo()->prepare(sprintf(
            'INSERT INTO `%smigrations` (`filename`, `batch`, `ran_at`) VALUES (:filename, :batch, :ran_at)',
            $this->prefix
        ))->execute([
            'filename' => $name,
            'batch'    => $batch,
            'ran_at'   => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Does this failure mean the object already exists, and nothing else?
     *
     * Public so the behaviour can be tested directly: deciding it wrongly is
     * how a half-applied release gets recorded as complete.
     */
    public static function meansAlreadyApplied(int $code, string $message, string $sql = ''): bool
    {
        if (in_array($code, self::ALREADY_EXISTS, true)) {
            return true;
        }

        // 1022 is "already there" ONLY when the statement adds a constraint.
        // Without the statement there is no way to tell it from a data
        // conflict, so no $sql means no tolerance.
        if ($code === self::DUPLICATE_ON_ADD && self::addsConstraint($sql)) {
            return true;
        }

        // 1005 is only "already there" in its duplicate-constraint-name form.
        return $code === 1005
            && preg_match('/errno:?\s*(\d+)/i', $message, $m) === 1
            && (int) $m[1] === self::DUPLICATE_CONSTRAINT_ERRNO;
    }

    /**
     * Does this statement ADD a named constraint?
     *
     * Deliberately narrow: only ADD CONSTRAINT and ADD FOREIGN KEY, which are
     * the nine non-idempotent statements in the shipped schema. An added
     * UNIQUE/INDEX/KEY reports 1061, which is tolerated on its own merits, and
     * anything that writes DATA must never reach this.
     */
    private static function addsConstraint(string $sql): bool
    {
        return preg_match('/\bADD\s+(?:CONSTRAINT\b|FOREIGN\s+KEY\b)/i', $sql) === 1;
    }

    /**
     * Split a file into statements.
     *
     * Comments are stripped first so a semicolon inside one cannot split a
     * statement in two. The schema deliberately contains no stored routines,
     * so there are no DELIMITER blocks to worry about.
     *
     * @return list<string>
     */
    private function split(string $sql): array
    {
        $lines = preg_split('/\R/', $sql) ?: [];
        $clean = [];

        foreach ($lines as $line) {
            $trimmed = ltrim($line);

            // Full-line comments only; a `--` inside a quoted string would be
            // mangled by a greedy strip, and the schema has no such strings.
            if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
                continue;
            }

            $clean[] = $line;
        }

        $body = implode("\n", $clean);

        $statements = [];

        foreach (explode(';', $body) as $statement) {
            $statement = trim($statement);

            if ($statement !== '') {
                $statements[] = $statement;
            }
        }

        return $statements;
    }

    private function ensureMigrationsTable(): void
    {
        if ($this->tableExists()) {
            return;
        }

        DB::pdo()->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS `%smigrations` (
                `id`       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `filename` VARCHAR(191) NOT NULL,
                `batch`    SMALLINT NOT NULL DEFAULT 1,
                `ran_at`   DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_migrations_filename` (`filename`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            $this->prefix
        ));
    }

    private function tableExists(): bool
    {
        try {
            return (int) DB::value(
                'SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = :t',
                ['t' => $this->prefix . 'migrations']
            ) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return list<string> */
    private function alreadyRun(): array
    {
        return array_map('strval', DB::column(
            sprintf('SELECT filename FROM `%smigrations`', $this->prefix)
        ));
    }

    private function nextBatch(): int
    {
        $max = DB::value(sprintf('SELECT MAX(batch) FROM `%smigrations`', $this->prefix));

        return $max === null ? 1 : (int) $max + 1;
    }
}
