<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Database-backed sessions.
 *
 * Shared hosts garbage-collect /tmp aggressively and several hosts share one
 * session directory, so PHP's default file handler loses sessions
 * unpredictably and leaks them between accounts. Keeping sessions in our own
 * table makes them durable, lets an admin see and revoke active sessions, and
 * gives idle/absolute timeouts we control.
 */
final class Session
{
    private static bool $started = false;

    private static ?string $id = null;

    /** @var array<string,mixed> */
    private static array $data = [];

    private static bool $dirty = false;

    private static ?Request $request = null;

    public static function start(Request $request): void
    {
        if (self::$started) {
            return;
        }

        self::$request = $request;
        self::$started = true;

        $cookieName = (string) Config::get('session.name', 'castle_crm_session');
        $token      = $request->cookie($cookieName);

        if ($token !== null && preg_match('/^[a-f0-9]{64}$/', $token) === 1) {
            self::load($token);
        }

        if (self::$id === null) {
            // No valid session yet. Defer creating a row until something is
            // actually stored, so crawlers and asset requests do not fill the
            // table with empty sessions.
            self::$id   = null;
            self::$data = [];
        }

        self::gcOccasionally();
    }

    private static function load(string $token): void
    {
        $row = DB::first(
            'SELECT id, user_id, payload, last_activity, created_at
               FROM {sessions} WHERE id = :id',
            ['id' => $token]
        );

        if ($row === null) {
            return;
        }

        $idleTimeout     = (int) Config::get('session.idle_timeout', 28800);
        $absoluteTimeout = (int) Config::get('session.absolute_timeout', 2592000);

        $lastActivity = strtotime((string) $row['last_activity']) ?: 0;
        $createdAt    = strtotime((string) $row['created_at']) ?: 0;
        $now          = time();

        if ($now - $lastActivity > $idleTimeout || $now - $createdAt > $absoluteTimeout) {
            // Expired. Remove it rather than leaving it to the GC, so a stolen
            // cookie cannot be replayed in the window before GC runs.
            DB::delete('sessions', 'id = :id', ['id' => $token]);

            return;
        }

        $payload = json_decode((string) $row['payload'], true);

        self::$id   = $token;
        self::$data = is_array($payload) ? $payload : [];

        // Only touch last_activity once a minute: every request writing to the
        // same row would serialise concurrent requests from one user.
        if ($now - $lastActivity > 60) {
            DB::update('sessions', [
                'last_activity' => gmdate('Y-m-d H:i:s'),
                'ip'            => self::$request?->ip(),
                'user_agent'    => self::$request?->userAgent(),
            ], 'id = :id', ['id' => $token]);
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$data[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        self::$data[$key] = $value;
        self::$dirty = true;
    }

    public static function has(string $key): bool
    {
        return isset(self::$data[$key]);
    }

    public static function forget(string $key): void
    {
        if (array_key_exists($key, self::$data)) {
            unset(self::$data[$key]);
            self::$dirty = true;
        }
    }

    /** Read a key and remove it — used for one-shot values. */
    public static function pull(string $key, mixed $default = null): mixed
    {
        $value = self::get($key, $default);
        self::forget($key);

        return $value;
    }

    public static function id(): ?string
    {
        return self::$id;
    }

    public static function userId(): ?int
    {
        $id = self::get('user_id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Rotate the session identifier while keeping the data.
     *
     * Called on login and on any privilege change, so a session id captured
     * before authentication cannot be used afterwards (session fixation).
     */
    public static function regenerate(): void
    {
        $old  = self::$id;
        $data = self::$data;

        self::$id = Encryption::randomToken(32);

        DB::insert('sessions', [
            'id'            => self::$id,
            'user_id'       => $data['user_id'] ?? null,
            'payload'       => self::encode($data),
            'ip'            => self::$request?->ip(),
            'user_agent'    => self::$request?->userAgent(),
            'last_activity' => gmdate('Y-m-d H:i:s'),
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ]);

        if ($old !== null) {
            DB::delete('sessions', 'id = :id', ['id' => $old]);
        }

        self::$data  = $data;
        self::$dirty = false;

        self::queueCookie();
    }

    /** Destroy this session entirely (logout). */
    public static function destroy(): void
    {
        if (self::$id !== null) {
            DB::delete('sessions', 'id = :id', ['id' => self::$id]);
        }

        self::$id    = null;
        self::$data  = [];
        self::$dirty = false;

        self::$pendingCookie = ['', ['expires' => time() - 3600]];
    }

    /** Revoke every other session for a user — used after a password change. */
    public static function destroyOthersFor(int $userId): void
    {
        DB::delete(
            'sessions',
            'user_id = :uid AND id <> :current',
            ['uid' => $userId, 'current' => self::$id ?? '']
        );
    }

    /**
     * Persist the session and return the cookie to set, if any.
     *
     * @return array{0:string,1:array<string,mixed>}|null
     */
    public static function save(): ?array
    {
        if (!self::$started) {
            return null;
        }

        // Nothing stored and no existing session: write nothing.
        if (self::$id === null && self::$data === []) {
            return self::$pendingCookie;
        }

        if (self::$id === null) {
            self::regenerate();

            return self::$pendingCookie;
        }

        if (self::$dirty) {
            DB::update('sessions', [
                'user_id'       => self::$data['user_id'] ?? null,
                'payload'       => self::encode(self::$data),
                'last_activity' => gmdate('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => self::$id]);

            self::$dirty = false;
        }

        if (self::$pendingCookie === null) {
            self::queueCookie();
        }

        return self::$pendingCookie;
    }

    /** @var array{0:string,1:array<string,mixed>}|null */
    private static ?array $pendingCookie = null;

    private static function queueCookie(): void
    {
        $secure = Config::get('session.cookie_secure');

        if ($secure === null) {
            $secure = self::$request?->isSecure() ?? false;
        }

        self::$pendingCookie = [(string) self::$id, [
            // A session cookie (expires 0) plus server-side absolute timeout:
            // closing the browser ends the session, and a kept-open browser
            // still cannot hold one past the absolute limit.
            'expires'  => 0,
            'path'     => (self::$request?->basePath() ?? '') . '/',
            'secure'   => (bool) $secure,
            'httponly' => true,
            'samesite' => (string) Config::get('session.cookie_samesite', 'Lax'),
        ]];
    }

    public static function cookieName(): string
    {
        return (string) Config::get('session.name', 'castle_crm_session');
    }

    /** @param array<string,mixed> $data */
    private static function encode(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);

        return $json === false ? '{}' : $json;
    }

    /**
     * Expire old sessions on roughly 1 request in 500.
     */
    private static function gcOccasionally(): void
    {
        if (random_int(1, 500) !== 1) {
            return;
        }

        $idle = (int) Config::get('session.idle_timeout', 28800);

        try {
            DB::delete(
                'sessions',
                'last_activity < :cutoff',
                ['cutoff' => gmdate('Y-m-d H:i:s', time() - $idle)]
            );
        } catch (\Throwable $e) {
            Log::debug('Session GC skipped', ['error' => $e->getMessage()]);
        }
    }
}
