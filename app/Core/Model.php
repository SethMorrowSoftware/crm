<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * A deliberately thin active-record base.
 *
 * Subclasses declare a table, a fillable allow-list, and casts. Anything
 * complicated belongs in a Service — this exists to remove the boilerplate of
 * find/create/update/delete, not to become an ORM.
 *
 * The fillable allow-list is the important part: fill() ignores anything not
 * listed, so a crafted form field cannot set `role_id` or `lifetime_value_cents`.
 */
abstract class Model
{
    /** Table name without the configured prefix. */
    protected static string $table = '';

    /** Columns that may be set from user input. */
    protected static array $fillable = [];

    /** column => 'int'|'float'|'bool'|'json'|'datetime' */
    protected static array $casts = [];

    /** Does this table use created_at / updated_at? */
    protected static bool $timestamps = true;

    /** Does this table use deleted_at soft deletes? */
    protected static bool $softDeletes = false;

    // -----------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $sql = 'SELECT * FROM {' . static::$table . '} WHERE id = :id';

        if (static::$softDeletes) {
            $sql .= ' AND deleted_at IS NULL';
        }

        $row = DB::first($sql, ['id' => $id]);

        return $row === null ? null : static::castRow($row);
    }

    /**
     * Find or throw — for routes where a missing record is a 404, handled by
     * the controller catching RuntimeException.
     *
     * @return array<string,mixed>
     */
    public static function findOrFail(int $id): array
    {
        $row = static::find($id);

        if ($row === null) {
            throw new RuntimeException(static::label() . ' not found.');
        }

        return $row;
    }

    /** @return array<string,mixed>|null */
    public static function findBy(string $column, mixed $value): ?array
    {
        $sql = 'SELECT * FROM {' . static::$table . '} WHERE ' . DB::quoteIdentifier($column) . ' = :value';

        if (static::$softDeletes) {
            $sql .= ' AND deleted_at IS NULL';
        }

        $row = DB::first($sql . ' LIMIT 1', ['value' => $value]);

        return $row === null ? null : static::castRow($row);
    }

    /**
     * A simple filtered list. For anything with joins or complex conditions,
     * write the query in a Service instead of bending this.
     *
     * @param array<string,mixed> $conditions column => value (NULL-safe)
     * @return list<array<string,mixed>>
     */
    public static function where(array $conditions = [], string $orderBy = 'id DESC', int $limit = 0, int $offset = 0): array
    {
        [$where, $params] = static::buildWhere($conditions);

        $sql = 'SELECT * FROM {' . static::$table . '} WHERE ' . $where;

        // Only allow an order-by we can validate, so this can never carry
        // injected SQL even if a caller passes user input.
        $sql .= ' ORDER BY ' . static::safeOrderBy($orderBy);

        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . max(0, $offset);
        }

        return array_map([static::class, 'castRow'], DB::all($sql, $params));
    }

    /** @param array<string,mixed> $conditions */
    public static function count(array $conditions = []): int
    {
        [$where, $params] = static::buildWhere($conditions);

        return (int) DB::value(
            'SELECT COUNT(*) FROM {' . static::$table . '} WHERE ' . $where,
            $params
        );
    }

    /** @param array<string,mixed> $conditions */
    public static function exists(array $conditions): bool
    {
        return static::count($conditions) > 0;
    }

    /** @return list<array<string,mixed>> */
    public static function all(string $orderBy = 'id DESC'): array
    {
        return static::where([], $orderBy);
    }

    // -----------------------------------------------------------------
    // Writing
    // -----------------------------------------------------------------

    /**
     * Insert, honouring the fillable allow-list.
     *
     * @param array<string,mixed> $data
     */
    public static function create(array $data): int
    {
        $row = static::fill($data);

        if (static::$timestamps) {
            $now = gmdate('Y-m-d H:i:s');
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }

        $id = DB::insert(static::$table, $row);

        Event::fire(static::eventName('created'), ['id' => $id, 'attributes' => $row]);

        return $id;
    }

    /**
     * Insert bypassing the allow-list. For trusted, system-generated rows
     * only (seeders, importers, automation output) — never request data.
     *
     * @param array<string,mixed> $row
     */
    public static function forceCreate(array $row): int
    {
        if (static::$timestamps) {
            $now = gmdate('Y-m-d H:i:s');
            $row['created_at'] ??= $now;
            $row['updated_at'] ??= $now;
        }

        return DB::insert(static::$table, $row);
    }

    /**
     * Update, honouring the fillable allow-list.
     *
     * @param array<string,mixed> $data
     * @return int Rows affected.
     */
    public static function update(int $id, array $data): int
    {
        $row = static::fill($data);

        if ($row === []) {
            return 0;
        }

        if (static::$timestamps) {
            $row['updated_at'] = gmdate('Y-m-d H:i:s');
        }

        $before = Audit::enabled() ? static::find($id) : null;

        $affected = DB::update(static::$table, $row, 'id = :id', ['id' => $id]);

        if ($affected > 0) {
            if ($before !== null) {
                Audit::record(static::eventName('updated'), static::$table, $id, $before, $row);
            }

            Event::fire(static::eventName('updated'), ['id' => $id, 'changes' => $row]);
        }

        return $affected;
    }

    /**
     * Delete — soft when the table supports it, hard otherwise.
     */
    public static function delete(int $id): int
    {
        if (static::$softDeletes) {
            $affected = DB::update(
                static::$table,
                ['deleted_at' => gmdate('Y-m-d H:i:s')],
                'id = :id AND deleted_at IS NULL',
                ['id' => $id]
            );
        } else {
            $affected = DB::delete(static::$table, 'id = :id', ['id' => $id]);
        }

        if ($affected > 0) {
            Audit::record(static::eventName('deleted'), static::$table, $id, null, null);
            Event::fire(static::eventName('deleted'), ['id' => $id]);
        }

        return $affected;
    }

    /** Undo a soft delete. */
    public static function restore(int $id): int
    {
        if (!static::$softDeletes) {
            throw new RuntimeException(static::$table . ' does not use soft deletes.');
        }

        return DB::update(
            static::$table,
            ['deleted_at' => null],
            'id = :id',
            ['id' => $id]
        );
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Reduce arbitrary input to the fillable columns, applying casts.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    protected static function fill(array $data): array
    {
        $row = [];

        foreach (static::$fillable as $column) {
            if (!array_key_exists($column, $data)) {
                continue;
            }

            $row[$column] = static::castForStorage($column, $data[$column]);
        }

        return $row;
    }

    protected static function castForStorage(string $column, mixed $value): mixed
    {
        $cast = static::$casts[$column] ?? null;

        if ($value === null) {
            return null;
        }

        return match ($cast) {
            'int'      => (int) $value,
            'float'    => (float) $value,
            'bool'     => (int) (bool) $value, // MySQL has no boolean
            'json'     => is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE),
            'datetime' => static::toUtc($value),
            default    => $value,
        };
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    protected static function castRow(array $row): array
    {
        foreach (static::$casts as $column => $cast) {
            if (!array_key_exists($column, $row) || $row[$column] === null) {
                continue;
            }

            $row[$column] = match ($cast) {
                'int'   => (int) $row[$column],
                'float' => (float) $row[$column],
                'bool'  => (bool) $row[$column],
                'json'  => json_decode((string) $row[$column], true) ?? [],
                default => $row[$column],
            };
        }

        return $row;
    }

    private static function toUtc(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }

        $timestamp = strtotime((string) $value);

        return $timestamp === false ? null : gmdate('Y-m-d H:i:s', $timestamp);
    }

    /**
     * @param array<string,mixed> $conditions
     * @return array{0:string,1:array<string,mixed>}
     */
    protected static function buildWhere(array $conditions): array
    {
        $clauses = [];
        $params  = [];

        if (static::$softDeletes) {
            $clauses[] = 'deleted_at IS NULL';
        }

        foreach ($conditions as $column => $value) {
            $quoted = DB::quoteIdentifier($column);

            if ($value === null) {
                $clauses[] = $quoted . ' IS NULL';
                continue;
            }

            if (is_array($value)) {
                if ($value === []) {
                    // An empty IN list matches nothing; say so explicitly
                    // rather than emitting invalid SQL.
                    $clauses[] = '1 = 0';
                    continue;
                }

                $placeholders = [];

                foreach (array_values($value) as $i => $item) {
                    $key = 'w_' . $column . '_' . $i;
                    $placeholders[] = ':' . $key;
                    $params[$key]   = $item;
                }

                $clauses[] = $quoted . ' IN (' . implode(', ', $placeholders) . ')';
                continue;
            }

            $key = 'w_' . $column;
            $clauses[] = $quoted . ' = :' . $key;
            $params[$key] = $value;
        }

        return [$clauses === [] ? '1 = 1' : implode(' AND ', $clauses), $params];
    }

    /**
     * Validate an ORDER BY clause: a comma-separated list of
     * `column [ASC|DESC]` only. Anything else falls back to `id DESC`.
     */
    protected static function safeOrderBy(string $orderBy): string
    {
        $parts = [];

        foreach (explode(',', $orderBy) as $segment) {
            $segment = trim($segment);

            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)(\s+(ASC|DESC))?$/i', $segment, $m) !== 1) {
                Log::warning('Rejected an unsafe ORDER BY', ['clause' => $segment]);

                return '`id` DESC';
            }

            $parts[] = DB::quoteIdentifier($m[1]) . (isset($m[3]) ? ' ' . strtoupper($m[3]) : '');
        }

        return $parts === [] ? '`id` DESC' : implode(', ', $parts);
    }

    protected static function eventName(string $verb): string
    {
        return rtrim(static::$table, 's') . '.' . $verb;
    }

    protected static function label(): string
    {
        return ucfirst(str_replace('_', ' ', rtrim(static::$table, 's')));
    }

    public static function tableName(): string
    {
        return static::$table;
    }
}
