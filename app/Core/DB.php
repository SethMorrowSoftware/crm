<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * The single database gateway.
 *
 * Every statement in Castle CRM goes through here, and every one of these
 * methods binds its parameters. Interpolating a value into SQL is a
 * review-blocking defect — if you need a dynamic identifier (a column to sort
 * by, say), validate it against an allowlist first and use quoteIdentifier().
 */
final class DB
{
    private static ?PDO $pdo = null;

    private static string $prefix = '';

    private static int $queryCount = 0;

    /** @var list<array{sql:string,time:float,params:array<mixed>}> */
    private static array $log = [];

    private static bool $logQueries = false;

    private static int $transactionDepth = 0;

    // -----------------------------------------------------------------
    // Connection
    // -----------------------------------------------------------------

    /**
     * Connect using config/config.php, or an explicit override (the installer
     * passes credentials that are not written to disk yet).
     *
     * @param array<string,mixed>|null $override
     */
    public static function connect(?array $override = null): PDO
    {
        if (self::$pdo instanceof PDO && $override === null) {
            return self::$pdo;
        }

        $cfg = $override ?? [
            'host'    => Config::get('db.host', 'localhost'),
            'port'    => Config::get('db.port', 3306),
            'name'    => Config::get('db.name'),
            'user'    => Config::get('db.user'),
            'pass'    => Config::get('db.pass', ''),
            'charset' => Config::get('db.charset', 'utf8mb4'),
            'prefix'  => Config::get('db.prefix', 'cc_'),
            'socket'  => Config::get('db.socket'),
        ];

        $charset = $cfg['charset'] ?? 'utf8mb4';

        if (!empty($cfg['socket'])) {
            $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $cfg['socket'], $cfg['name'], $charset);
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $cfg['host'] ?? 'localhost',
                (int) ($cfg['port'] ?? 3306),
                $cfg['name'] ?? '',
                $charset
            );
        }

        try {
            $pdo = new PDO($dsn, (string) ($cfg['user'] ?? ''), (string) ($cfg['pass'] ?? ''), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Real prepared statements, not client-side interpolation.
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $e) {
            // The message can contain the DB password on some drivers, so it is
            // never surfaced to a user — only the logged, sanitised form.
            Log::critical('Database connection failed', ['error' => $e->getMessage()]);
            throw new RuntimeException('Could not connect to the database.', 0, $e);
        }

        // Predictable date/number handling regardless of server defaults, and
        // strict mode so a truncated value errors instead of silently corrupting.
        $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
        $pdo->exec("SET SESSION time_zone = '+00:00'");

        self::$prefix = (string) ($cfg['prefix'] ?? 'cc_');

        if ($override === null) {
            self::$pdo = $pdo;
        }

        return $pdo;
    }

    public static function pdo(): PDO
    {
        if (!self::$pdo instanceof PDO) {
            self::connect();
        }

        /** @var PDO */
        return self::$pdo;
    }

    public static function isConnected(): bool
    {
        return self::$pdo instanceof PDO;
    }

    public static function disconnect(): void
    {
        self::$pdo = null;
        self::$transactionDepth = 0;
    }

    public static function prefix(): string
    {
        return self::$prefix;
    }

    public static function setPrefix(string $prefix): void
    {
        self::$prefix = $prefix;
    }

    /**
     * Expand {table} placeholders into prefixed, backtick-quoted identifiers.
     * Lets queries read as `SELECT * FROM {contacts}` while remaining
     * prefix-agnostic.
     */
    public static function expand(string $sql): string
    {
        return (string) preg_replace_callback(
            '/\{([a-z0-9_]+)\}/i',
            static fn (array $m): string => '`' . self::$prefix . $m[1] . '`',
            $sql
        );
    }

    public static function table(string $name): string
    {
        return self::$prefix . $name;
    }

    /**
     * Quote an identifier for the rare case where one must be dynamic.
     * Rejects anything that is not a plain name — never pass user input here
     * without checking it against an allowlist first.
     */
    public static function quoteIdentifier(string $name): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new RuntimeException('Refusing to quote an unsafe identifier.');
        }

        return '`' . $name . '`';
    }

    // -----------------------------------------------------------------
    // Querying
    // -----------------------------------------------------------------

    /**
     * Run a statement. Named (:name) or positional (?) placeholders both work.
     *
     * @param array<string|int,mixed> $params
     */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $sql   = self::expand($sql);
        $start = microtime(true);

        try {
            $stmt = self::pdo()->prepare($sql);

            foreach ($params as $key => $value) {
                $placeholder = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
                $stmt->bindValue($placeholder, $value, self::pdoType($value));
            }

            $stmt->execute();
        } catch (PDOException $e) {
            Log::error('Query failed', [
                'sql'   => $sql,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        self::$queryCount++;

        if (self::$logQueries) {
            self::$log[] = [
                'sql'    => $sql,
                'time'   => (microtime(true) - $start) * 1000,
                'params' => $params,
            ];
        }

        return $stmt;
    }

    /**
     * @param array<string|int,mixed> $params
     * @return array<string,mixed>|null
     */
    public static function first(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string|int,mixed> $params
     * @return list<array<string,mixed>>
     */
    public static function all(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /**
     * First column of the first row — for COUNT(*), MAX(), EXISTS, etc.
     *
     * @param array<string|int,mixed> $params
     */
    public static function value(string $sql, array $params = []): mixed
    {
        $value = self::query($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * A flat list of the first column across all rows.
     *
     * @param array<string|int,mixed> $params
     * @return list<mixed>
     */
    public static function column(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Key/value map from the first two selected columns.
     *
     * @param array<string|int,mixed> $params
     * @return array<string,mixed>
     */
    public static function pairs(string $sql, array $params = []): array
    {
        $out = [];

        foreach (self::all($sql, $params) as $row) {
            $values = array_values($row);
            $out[(string) $values[0]] = $values[1] ?? null;
        }

        return $out;
    }

    /**
     * @param array<string|int,mixed> $params
     */
    public static function exists(string $sql, array $params = []): bool
    {
        return self::first($sql, $params) !== null;
    }

    // -----------------------------------------------------------------
    // Writing
    // -----------------------------------------------------------------

    /**
     * Insert a row and return its id.
     *
     * @param array<string,mixed> $data
     */
    public static function insert(string $table, array $data): int
    {
        if ($data === []) {
            throw new RuntimeException('Refusing to insert an empty row.');
        }

        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            self::quoteIdentifier(self::table($table)),
            implode(', ', array_map([self::class, 'quoteIdentifier'], $columns)),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns))
        );

        self::query($sql, $data);

        return (int) self::pdo()->lastInsertId();
    }

    /**
     * Insert many rows in one statement. All rows must share the same keys.
     *
     * @param list<array<string,mixed>> $rows
     * @return int Rows affected.
     */
    public static function insertMany(string $table, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $columns = array_keys($rows[0]);
        $params  = [];
        $groups  = [];

        foreach ($rows as $i => $row) {
            $placeholders = [];
            foreach ($columns as $column) {
                $key = $column . '_' . $i;
                $placeholders[] = ':' . $key;
                $params[$key]   = $row[$column] ?? null;
            }
            $groups[] = '(' . implode(', ', $placeholders) . ')';
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            self::quoteIdentifier(self::table($table)),
            implode(', ', array_map([self::class, 'quoteIdentifier'], $columns)),
            implode(', ', $groups)
        );

        return self::query($sql, $params)->rowCount();
    }

    /**
     * Update rows matching a WHERE fragment.
     *
     * @param array<string,mixed>     $data
     * @param array<string|int,mixed> $whereParams
     * @return int Rows affected.
     */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        if ($data === []) {
            return 0;
        }

        $sets   = [];
        $params = [];

        foreach ($data as $column => $value) {
            // Prefix set-clause bindings so they cannot collide with a WHERE
            // binding of the same column name.
            $sets[]              = self::quoteIdentifier($column) . ' = :set_' . $column;
            $params['set_' . $column] = $value;
        }

        foreach ($whereParams as $key => $value) {
            $params[is_int($key) ? $key : ltrim($key, ':')] = $value;
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            self::quoteIdentifier(self::table($table)),
            implode(', ', $sets),
            $where
        );

        return self::query($sql, $params)->rowCount();
    }

    /**
     * @param array<string|int,mixed> $params
     * @return int Rows affected.
     */
    public static function delete(string $table, string $where, array $params = []): int
    {
        if (trim($where) === '') {
            throw new RuntimeException('Refusing to delete without a WHERE clause.');
        }

        $sql = sprintf('DELETE FROM %s WHERE %s', self::quoteIdentifier(self::table($table)), $where);

        return self::query($sql, $params)->rowCount();
    }

    // -----------------------------------------------------------------
    // Transactions
    // -----------------------------------------------------------------

    /**
     * Run a callback in a transaction, committing on return and rolling back
     * on any throwable. Nests safely via savepoints.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        self::begin();

        try {
            $result = $callback();
            self::commit();

            return $result;
        } catch (\Throwable $e) {
            self::rollback();
            throw $e;
        }
    }

    public static function begin(): void
    {
        if (self::$transactionDepth === 0) {
            self::pdo()->beginTransaction();
        } else {
            self::pdo()->exec('SAVEPOINT cc_sp_' . self::$transactionDepth);
        }

        self::$transactionDepth++;
    }

    public static function commit(): void
    {
        if (self::$transactionDepth === 0) {
            return;
        }

        self::$transactionDepth--;

        if (self::$transactionDepth === 0) {
            self::pdo()->commit();
        } else {
            self::pdo()->exec('RELEASE SAVEPOINT cc_sp_' . self::$transactionDepth);
        }
    }

    public static function rollback(): void
    {
        if (self::$transactionDepth === 0) {
            return;
        }

        self::$transactionDepth--;

        if (self::$transactionDepth === 0) {
            if (self::pdo()->inTransaction()) {
                self::pdo()->rollBack();
            }
        } else {
            self::pdo()->exec('ROLLBACK TO SAVEPOINT cc_sp_' . self::$transactionDepth);
        }
    }

    public static function inTransaction(): bool
    {
        return self::$transactionDepth > 0;
    }

    // -----------------------------------------------------------------
    // Introspection
    // -----------------------------------------------------------------

    public static function tableExists(string $table): bool
    {
        $full = self::table($table);

        return self::value(
            'SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = :t',
            ['t' => $full]
        ) > 0;
    }

    public static function columnExists(string $table, string $column): bool
    {
        return self::value(
            'SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c',
            ['t' => self::table($table), 'c' => $column]
        ) > 0;
    }

    public static function serverVersion(): string
    {
        return (string) self::pdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
    }

    // -----------------------------------------------------------------
    // Diagnostics
    // -----------------------------------------------------------------

    public static function enableQueryLog(bool $on = true): void
    {
        self::$logQueries = $on;
    }

    public static function queryCount(): int
    {
        return self::$queryCount;
    }

    /** @return list<array{sql:string,time:float,params:array<mixed>}> */
    public static function queryLog(): array
    {
        return self::$log;
    }

    private static function pdoType(mixed $value): int
    {
        return match (true) {
            is_int($value)  => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_INT, // MySQL has no real boolean
            $value === null => PDO::PARAM_NULL,
            default         => PDO::PARAM_STR,
        };
    }
}
