<?php

declare(strict_types=1);

namespace App\Core;

/**
 * A small cache with two backends: APCu when the host has it, files otherwise.
 *
 * Used for the settings snapshot, rate-limit counters, and segment counts.
 * Deliberately not database-backed — a rate limiter that needs a query per
 * request is a rate limiter that amplifies the attack it is meant to blunt.
 */
final class Cache
{
    private static ?bool $apcu = null;

    private static ?string $dir = null;

    private static function useApcu(): bool
    {
        if (self::$apcu !== null) {
            return self::$apcu;
        }

        // apcu_enabled() reports false on CLI unless apc.enable_cli is on, so
        // the file backend is what cron actually uses on most hosts.
        return self::$apcu = function_exists('apcu_fetch')
            && function_exists('apcu_enabled')
            && apcu_enabled();
    }

    private static function dir(): string
    {
        if (self::$dir !== null) {
            return self::$dir;
        }

        $base = (string) Config::get('storage_path', CC_ROOT . '/storage');
        $dir  = rtrim($base, '/') . '/cache';

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return self::$dir = $dir;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::useApcu()) {
            $success = false;
            $value = apcu_fetch(self::prefixed($key), $success);

            return $success ? $value : $default;
        }

        $file = self::path($key);

        if (!is_file($file)) {
            return $default;
        }

        $raw = @file_get_contents($file);

        if ($raw === false) {
            return $default;
        }

        $payload = @unserialize($raw, ['allowed_classes' => false]);

        if (!is_array($payload) || !array_key_exists('expires', $payload)) {
            return $default;
        }

        if ($payload['expires'] !== 0 && $payload['expires'] < time()) {
            @unlink($file);

            return $default;
        }

        return $payload['value'];
    }

    /** @param int $ttl Seconds; 0 means never expire. */
    public static function put(string $key, mixed $value, int $ttl = 300): bool
    {
        if (self::useApcu()) {
            return apcu_store(self::prefixed($key), $value, $ttl);
        }

        $payload = serialize([
            'expires' => $ttl === 0 ? 0 : time() + $ttl,
            'value'   => $value,
        ]);

        $file = self::path($key);

        // Write to a temp file and rename so a concurrent reader never sees a
        // half-written entry.
        $temp = $file . '.' . getmypid() . '.tmp';

        if (@file_put_contents($temp, $payload, LOCK_EX) === false) {
            return false;
        }

        if (!@rename($temp, $file)) {
            @unlink($temp);

            return false;
        }

        self::pruneOccasionally();

        return true;
    }

    /**
     * Fetch, or compute and store.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public static function remember(string $key, int $ttl, callable $callback): mixed
    {
        $sentinel = '__cc_cache_miss__';
        $value = self::get($key, $sentinel);

        if ($value !== $sentinel) {
            /** @var T */
            return $value;
        }

        $value = $callback();
        self::put($key, $value, $ttl);

        return $value;
    }

    public static function forget(string $key): bool
    {
        if (self::useApcu()) {
            return apcu_delete(self::prefixed($key));
        }

        $file = self::path($key);

        return !is_file($file) || @unlink($file);
    }

    public static function flush(): void
    {
        if (self::useApcu()) {
            apcu_clear_cache();

            return;
        }

        foreach (glob(self::dir() . '/*.cache') ?: [] as $file) {
            @unlink($file);
        }
    }

    /**
     * Increment a counter, creating it with the given TTL on first hit.
     * Returns the new value. Used by the rate limiter.
     */
    public static function increment(string $key, int $ttl = 60): int
    {
        if (self::useApcu()) {
            $prefixed = self::prefixed($key);
            $success  = false;

            $value = apcu_inc($prefixed, 1, $success, $ttl);

            return $success && is_int($value) ? $value : 1;
        }

        // The file backend is not atomic across processes. For rate limiting
        // that is acceptable — a racing request may undercount by one, which
        // is a far smaller problem than a per-request database write.
        $current = self::get($key);
        $count   = is_int($current) ? $current + 1 : 1;

        // Preserve the original expiry so a steady stream of requests cannot
        // keep pushing the window forward and never trip the limit.
        $remaining = self::ttl($key);
        self::put($key, $count, $remaining !== null && $remaining > 0 ? $remaining : $ttl);

        return $count;
    }

    /** Seconds until a key expires, or null when unknown / already gone. */
    public static function ttl(string $key): ?int
    {
        if (self::useApcu()) {
            // APCu does not expose a per-key TTL without iterating the cache,
            // which is not worth it here.
            return null;
        }

        $file = self::path($key);

        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);

        if ($raw === false) {
            return null;
        }

        $payload = @unserialize($raw, ['allowed_classes' => false]);

        if (!is_array($payload) || ($payload['expires'] ?? 0) === 0) {
            return null;
        }

        $remaining = (int) $payload['expires'] - time();

        return $remaining > 0 ? $remaining : null;
    }

    private static function prefixed(string $key): string
    {
        return 'cc_' . $key;
    }

    private static function path(string $key): string
    {
        return self::dir() . '/' . sha1($key) . '.cache';
    }

    /** Clear expired file entries on roughly 1 write in 100. */
    private static function pruneOccasionally(): void
    {
        if (random_int(1, 100) !== 1) {
            return;
        }

        $now = time();

        foreach (glob(self::dir() . '/*.cache') ?: [] as $file) {
            $raw = @file_get_contents($file);

            if ($raw === false) {
                continue;
            }

            $payload = @unserialize($raw, ['allowed_classes' => false]);

            if (!is_array($payload)) {
                @unlink($file);
                continue;
            }

            $expires = (int) ($payload['expires'] ?? 0);

            if ($expires !== 0 && $expires < $now) {
                @unlink($file);
            }
        }
    }
}
