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
     */
    private const ALREADY_EXISTS = [
        1050, // table exists
        1060, // duplicate column
        1061, // duplicate key name
        1022, // duplicate key
        1826, // duplicate foreign key constraint name
        1005, // can't create table (usually a duplicate FK name on older MySQL)
        1831, // duplicate index
    ];

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

                if (in_array($code, self::ALREADY_EXISTS, true)) {
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

        DB::insert('migrations', [
            'filename' => $name,
            'batch'    => $batch,
            'ran_at'   => gmdate('Y-m-d H:i:s'),
        ]);
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
