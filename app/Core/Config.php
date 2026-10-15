<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Immutable access to config/config.php.
 *
 * Config values are things that must exist before the database does (DB
 * credentials, the encryption key, paths). Everything an operator can change
 * from the UI belongs in Settings instead.
 */
final class Config
{
    /** @var array<string,mixed> */
    private static array $items = [];

    private static bool $loaded = false;

    /** @param array<string,mixed> $items */
    public static function load(array $items): void
    {
        self::$items = $items;
        self::$loaded = true;
    }

    public static function isLoaded(): bool
    {
        return self::$loaded;
    }

    /**
     * Read a value by dotted path: Config::get('db.host').
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        // Treat an explicit null or empty string as "not configured" so callers
        // can rely on the default without repeating null coalescing everywhere.
        return ($value === null || $value === '') ? $default : $value;
    }

    /**
     * Set a value for the current request only. Does not touch config.php.
     * Used by the installer, which configures itself progressively.
     */
    public static function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $ref = &self::$items;

        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $ref[$segment] = $value;
                break;
            }
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
    }

    public static function isDebug(): bool
    {
        return (bool) self::get('debug', false);
    }

    public static function isProduction(): bool
    {
        return self::get('env', 'production') === 'production';
    }
}
