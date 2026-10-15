<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Operator-editable configuration, stored in the database.
 *
 * The whole table is loaded in one query per request and cached, because
 * almost every page reads at least a few settings (venue name, timezone,
 * Safe Mode). Encrypted values are decrypted lazily, so merely rendering a
 * page does not touch the API credentials.
 */
final class Settings
{
    /** @var array<string,array{value:string|null,type:string,is_encrypted:bool}> */
    private static array $items = [];

    private static bool $loaded = false;

    /** @var array<string,mixed> */
    private static array $decrypted = [];

    public static function load(bool $force = false): void
    {
        if (self::$loaded && !$force) {
            return;
        }

        self::$items = [];
        self::$decrypted = [];

        if (!DB::isConnected() && !Config::isLoaded()) {
            return;
        }

        try {
            $rows = DB::all('SELECT `key`, `value`, `type`, `is_encrypted` FROM {settings}');
        } catch (\Throwable $e) {
            // Before the installer runs there is no settings table. Callers
            // fall back to their defaults, which is the correct behaviour.
            Log::debug('Settings table unavailable', ['error' => $e->getMessage()]);
            self::$loaded = true;

            return;
        }

        foreach ($rows as $row) {
            self::$items[(string) $row['key']] = [
                'value'        => $row['value'],
                'type'         => (string) ($row['type'] ?? 'string'),
                'is_encrypted' => (bool) $row['is_encrypted'],
            ];
        }

        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();

        if (!isset(self::$items[$key])) {
            return $default;
        }

        if (array_key_exists($key, self::$decrypted)) {
            return self::$decrypted[$key];
        }

        $item  = self::$items[$key];
        $value = $item['value'];

        if ($value === null) {
            return $default;
        }

        if ($item['is_encrypted']) {
            $value = Encryption::decrypt($value);

            if ($value === null) {
                return $default;
            }
        }

        $cast = self::cast($value, $item['type']);
        self::$decrypted[$key] = $cast;

        return $cast;
    }

    public static function has(string $key): bool
    {
        self::load();

        return isset(self::$items[$key]);
    }

    /**
     * Write a setting. Pass $encrypt for credentials; the value is then
     * unreadable in the database and in any dump of it.
     */
    public static function set(string $key, mixed $value, ?string $type = null, bool $encrypt = false): void
    {
        self::load();

        $type ??= self::inferType($value);
        $stored = self::serialize($value, $type);

        if ($encrypt && $stored !== null) {
            $stored = Encryption::encrypt($stored);
        }

        $exists = DB::exists('SELECT id FROM {settings} WHERE `key` = :k', ['k' => $key]);

        if ($exists) {
            DB::update('settings', [
                'value'        => $stored,
                'type'         => $type,
                'is_encrypted' => $encrypt ? 1 : 0,
                'updated_at'   => gmdate('Y-m-d H:i:s'),
            ], '`key` = :k', ['k' => $key]);
        } else {
            DB::insert('settings', [
                'group'        => self::groupFor($key),
                'key'          => $key,
                'value'        => $stored,
                'type'         => $type,
                'is_encrypted' => $encrypt ? 1 : 0,
                'created_at'   => gmdate('Y-m-d H:i:s'),
                'updated_at'   => gmdate('Y-m-d H:i:s'),
            ]);
        }

        self::$items[$key] = [
            'value'        => $stored,
            'type'         => $type,
            'is_encrypted' => $encrypt,
        ];
        unset(self::$decrypted[$key]);
    }

    /**
     * Write several settings in one transaction.
     *
     * @param array<string,mixed> $values
     */
    public static function setMany(array $values): void
    {
        DB::transaction(static function () use ($values): void {
            foreach ($values as $key => $value) {
                self::set($key, $value);
            }
        });
    }

    public static function forget(string $key): void
    {
        DB::delete('settings', '`key` = :k', ['k' => $key]);
        unset(self::$items[$key], self::$decrypted[$key]);
    }

    /**
     * Every setting in a group, keyed without the group prefix.
     *
     * @return array<string,mixed>
     */
    public static function group(string $group): array
    {
        self::load();
        $out = [];

        foreach (array_keys(self::$items) as $key) {
            if (str_starts_with($key, $group . '.')) {
                $out[substr($key, strlen($group) + 1)] = self::get($key);
            }
        }

        return $out;
    }

    // -----------------------------------------------------------------
    // Convenience accessors used across the app
    // -----------------------------------------------------------------

    public static function venueName(): string
    {
        return (string) self::get('venue.name', 'The Castle Fun Center');
    }

    public static function timezone(): string
    {
        return (string) self::get('venue.timezone', 'America/New_York');
    }

    /**
     * Safe Mode redirects all outbound email and SMS to the owner's address.
     * It defaults to ON so a fresh install cannot message real guests before
     * an operator has reviewed the seeded templates.
     */
    public static function safeMode(): bool
    {
        return (bool) self::get('system.safe_mode', true);
    }

    /**
     * The global outbound kill switch. Distinct from Safe Mode: Safe Mode
     * redirects, this one stops sending entirely.
     */
    public static function sendingPaused(): bool
    {
        return (bool) self::get('system.sending_paused', false);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    private static function cast(string $value, string $type): mixed
    {
        return match ($type) {
            'bool', 'boolean' => in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true),
            'int', 'integer'  => (int) $value,
            'float'           => (float) $value,
            'json', 'array'   => json_decode($value, true) ?? [],
            default           => $value,
        };
    }

    private static function serialize(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'bool', 'boolean' => $value ? '1' : '0',
            'json', 'array'   => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            default           => (string) $value,
        };
    }

    private static function inferType(mixed $value): string
    {
        return match (true) {
            is_bool($value)  => 'bool',
            is_int($value)   => 'int',
            is_float($value) => 'float',
            is_array($value) => 'json',
            default          => 'string',
        };
    }

    private static function groupFor(string $key): string
    {
        $pos = strpos($key, '.');

        return $pos === false ? 'general' : substr($key, 0, $pos);
    }
}
