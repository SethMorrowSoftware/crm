<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Authentication: who is making this request, and may they log in at all.
 *
 * Authorization — what they may *do* — lives in Acl.
 */
final class Auth
{
    /** @var array<string,mixed>|null */
    private static ?array $user = null;

    private static bool $resolved = false;

    /**
     * The API token this request arrived with, if any.
     *
     * Held so `Acl::can()` can NARROW the user's permissions to the token's
     * abilities. The session path leaves it null and behaves exactly as it
     * always has.
     *
     * @var array<string,mixed>|null
     */
    private static ?array $apiToken = null;

    /** Lock an account after this many consecutive failures. */
    private const MAX_ATTEMPTS = 8;

    /** How long a lockout lasts, in seconds. */
    private const LOCKOUT_SECONDS = 900;

    /** Argon2id parameters, kept modest so a shared host can afford them. */
    private const HASH_OPTIONS = [
        'memory_cost' => 47104, // 46 MiB
        'time_cost'   => 3,
        'threads'     => 1,
    ];

    /** Bcrypt cost, for a PHP built without libargon2. */
    private const BCRYPT_OPTIONS = ['cost' => 12];

    /**
     * Hashes of a fixed string, verified against for an unknown address so the
     * response takes as long as a real check. One per algorithm, because
     * verifying an Argon2 hash on a PHP that has no Argon2 returns at once —
     * which is the timing difference this exists to remove.
     */
    private const DECOY_ARGON2ID = '$argon2id$v=19$m=47104,t=3,p=1$aaaaaaaaaaaaaaaaaaaaaa$aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const DECOY_BCRYPT = '$2y$12$2ml1Ed55iiudNz0QIGqZoOooMOjZy2I.5wG2.k2Hchyg7Rp8Cl7YS';

    // -----------------------------------------------------------------
    // Current user
    // -----------------------------------------------------------------

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }

        self::$resolved = true;
        $userId = Session::userId();

        if ($userId === null) {
            return self::$user = null;
        }

        $user = DB::first(
            'SELECT u.*, r.slug AS role_slug, r.name AS role_name
               FROM {users} u
               LEFT JOIN {roles} r ON r.id = u.role_id
              WHERE u.id = :id AND u.deleted_at IS NULL',
            ['id' => $userId]
        );

        // A user deactivated or deleted mid-session loses access on their next
        // request rather than when their session happens to expire.
        if ($user === null || (int) $user['is_active'] !== 1) {
            Session::destroy();

            return self::$user = null;
        }

        return self::$user = $user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /**
     * Authenticate this request as the owner of an API token.
     *
     * NO SESSION IS STARTED. A bearer token is not a login: it sets no
     * cookie, rotates no CSRF token, and leaves nothing behind for the next
     * request — so a token cannot be used to obtain a browser session, and a
     * stolen token is only ever as useful as the one request it is used in.
     *
     * It also updates no `last_login_at`: an integration polling every minute
     * would otherwise make the users page read as though somebody were
     * signing in all night.
     *
     * @param array<string,mixed> $token A row from `ApiToken::resolve()`.
     */
    public static function useApiToken(array $token): bool
    {
        $user = DB::first(
            'SELECT u.*, r.slug AS role_slug, r.name AS role_name
               FROM {users} u
               LEFT JOIN {roles} r ON r.id = u.role_id
              WHERE u.id = :id AND u.deleted_at IS NULL AND u.is_active = 1',
            ['id' => (int) $token['user_id']]
        );

        if ($user === null) {
            return false;
        }

        self::$user     = $user;
        self::$resolved = true;
        self::$apiToken = $token;

        return true;
    }

    /**
     * The API token this request carries, or null for a browser session.
     *
     * @return array<string,mixed>|null
     */
    public static function apiToken(): ?array
    {
        return self::$apiToken;
    }

    /** True when this request came in on a token rather than a session. */
    public static function isApi(): bool
    {
        return self::$apiToken !== null;
    }

    public static function id(): ?int
    {
        $user = self::user();

        return $user === null ? null : (int) $user['id'];
    }

    public static function name(): string
    {
        return (string) (self::user()['name'] ?? 'Guest');
    }

    public static function roleSlug(): ?string
    {
        $slug = self::user()['role_slug'] ?? null;

        return is_string($slug) ? $slug : null;
    }

    public static function isOwner(): bool
    {
        return self::roleSlug() === 'owner';
    }

    /** Clear the memoised user — call after changing the current user's row. */
    public static function refresh(): void
    {
        self::$resolved = false;
        self::$user = null;

        // The token goes too. Keeping it would leave the NEXT resolution
        // narrowed by a token that is no longer the reason anybody is here —
        // which in the test suite, where one process serves many requests,
        // means a session-authenticated call silently losing permissions.
        self::$apiToken = null;
    }

    // -----------------------------------------------------------------
    // Login / logout
    // -----------------------------------------------------------------

    /**
     * Attempt a login.
     *
     * @return array{ok:bool,error?:string,retry_after?:int}
     */
    public static function attempt(string $email, string $password, Request $request): array
    {
        $email = strtolower(trim($email));

        $user = DB::first(
            'SELECT * FROM {users} WHERE email = :email AND deleted_at IS NULL',
            ['email' => $email]
        );

        // Always spend time hashing, even for an unknown address, so response
        // timing does not reveal which accounts exist.
        if ($user === null) {
            password_verify($password, self::hasArgon2() ? self::DECOY_ARGON2ID : self::DECOY_BCRYPT);
            self::recordFailure($email, $request);

            return ['ok' => false, 'error' => 'Those credentials do not match our records.'];
        }

        if ((int) $user['is_active'] !== 1) {
            self::audit('login.inactive', (int) $user['id'], $request);

            return ['ok' => false, 'error' => 'That account is not active. Ask an administrator to re-enable it.'];
        }

        $lockedUntil = $user['locked_until'] === null ? 0 : (strtotime((string) $user['locked_until']) ?: 0);

        if ($lockedUntil > time()) {
            return [
                'ok'          => false,
                'error'       => 'Too many failed attempts. Try again shortly.',
                'retry_after' => $lockedUntil - time(),
            ];
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            self::recordFailure($email, $request, (int) $user['id']);

            $remaining = self::MAX_ATTEMPTS - ((int) $user['failed_attempts'] + 1);

            return [
                'ok'    => false,
                'error' => $remaining > 0 && $remaining <= 3
                    ? sprintf('Those credentials do not match. %d attempt%s left before a temporary lockout.', $remaining, $remaining === 1 ? '' : 's')
                    : 'Those credentials do not match our records.',
            ];
        }

        // Upgrade the stored hash if the cost parameters have changed since it
        // was written. Safe here because we hold the plaintext exactly once.
        if (self::needsRehash((string) $user['password_hash'])) {
            DB::update('users', [
                'password_hash' => self::hash($password),
            ], 'id = :id', ['id' => (int) $user['id']]);
        }

        self::login((int) $user['id'], $request);

        return ['ok' => true];
    }

    /** Establish a session for a user id. */
    public static function login(int $userId, Request $request): void
    {
        Session::put('user_id', $userId);
        // Rotate both the session id and the CSRF token across the privilege
        // boundary (session fixation).
        Session::regenerate();
        Csrf::rotate();

        DB::update('users', [
            'last_login_at'   => gmdate('Y-m-d H:i:s'),
            'failed_attempts' => 0,
            'locked_until'    => null,
        ], 'id = :id', ['id' => $userId]);

        self::refresh();
        self::audit('login.success', $userId, $request);
    }

    public static function logout(Request $request): void
    {
        $userId = self::id();

        if ($userId !== null) {
            self::audit('logout', $userId, $request);
        }

        Session::destroy();
        self::$user = null;
        self::$resolved = true;
    }

    // -----------------------------------------------------------------
    // Passwords
    // -----------------------------------------------------------------

    public static function hash(string $password): string
    {
        if (self::hasArgon2()) {
            $hash = password_hash($password, PASSWORD_ARGON2ID, self::HASH_OPTIONS);

            if (is_string($hash)) {
                return $hash;
            }
        }

        // Argon2 needs libargon2 at compile time; bcrypt is always available.
        return (string) password_hash($password, PASSWORD_BCRYPT, self::BCRYPT_OPTIONS);
    }

    /**
     * Whether a stored hash should be rewritten with today's algorithm.
     *
     * The same choice hash() makes, and it has to be: naming PASSWORD_ARGON2ID
     * here directly is an undefined-constant Error on a PHP built without
     * libargon2, thrown AFTER the password verified — so every correct sign-in
     * failed with a 500 and nobody could get in at all. The static PHP 8.1–8.3
     * builds used for local CI are exactly that build, and so are some hosts.
     */
    public static function needsRehash(string $hash): bool
    {
        return self::hasArgon2()
            ? password_needs_rehash($hash, PASSWORD_ARGON2ID, self::HASH_OPTIONS)
            : password_needs_rehash($hash, PASSWORD_BCRYPT, self::BCRYPT_OPTIONS);
    }

    private static function hasArgon2(): bool
    {
        return defined('PASSWORD_ARGON2ID');
    }

    /**
     * Check a password against policy.
     *
     * Length is the dominant factor, so the rule is a 12-character minimum
     * plus a block-list of obvious choices, rather than a character-class
     * puzzle that pushes people toward "Password1!".
     *
     * @return list<string> Problems found; empty means acceptable.
     */
    public static function passwordProblems(string $password, string $email = '', string $name = ''): array
    {
        $problems = [];

        if (strlen($password) < 12) {
            $problems[] = 'Use at least 12 characters.';
        }

        if (strlen($password) > 200) {
            $problems[] = 'Use fewer than 200 characters.';
        }

        $lower = strtolower($password);

        $weak = [
            'password', 'password123', '123456789', 'qwertyuiop', 'letmein',
            'castlefun', 'thecastle', 'castlecrm', 'changeme', 'welcome1',
            'administrator', 'iloveyou', 'monkey123',
        ];

        foreach ($weak as $candidate) {
            if (str_contains($lower, $candidate)) {
                $problems[] = 'That contains a very common password. Choose something less guessable.';
                break;
            }
        }

        // A password containing the account's own name or address is trivially
        // guessable by anyone who knows the person.
        foreach ([$email, $name] as $personal) {
            $personal = strtolower(trim($personal));
            $local    = strtok($personal, '@') ?: $personal;

            if ($local !== '' && strlen($local) >= 4 && str_contains($lower, $local)) {
                $problems[] = 'Do not include your name or email address.';
                break;
            }
        }

        if (preg_match('/^(.)\1+$/', $password) === 1) {
            $problems[] = 'Use more than one repeated character.';
        }

        return array_values(array_unique($problems));
    }

    public static function changePassword(int $userId, string $newPassword, Request $request): void
    {
        DB::update('users', [
            'password_hash' => self::hash($newPassword),
            'updated_at'    => gmdate('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $userId]);

        // Any session other than this one may belong to whoever prompted the
        // change, so they all go.
        Session::destroyOthersFor($userId);

        self::audit('password.changed', $userId, $request);
    }

    // -----------------------------------------------------------------
    // Throttling
    // -----------------------------------------------------------------

    private static function recordFailure(string $email, Request $request, ?int $userId = null): void
    {
        if ($userId !== null) {
            $attempts = (int) DB::value(
                'SELECT failed_attempts FROM {users} WHERE id = :id',
                ['id' => $userId]
            ) + 1;

            $update = ['failed_attempts' => $attempts];

            if ($attempts >= self::MAX_ATTEMPTS) {
                $update['locked_until']    = gmdate('Y-m-d H:i:s', time() + self::LOCKOUT_SECONDS);
                $update['failed_attempts'] = 0;

                Log::warning('Account locked after repeated failures', [
                    'user_id' => $userId,
                    'ip'      => $request->ip(),
                ]);
            }

            DB::update('users', $update, 'id = :id', ['id' => $userId]);
        }

        Log::notice('Failed login', ['email' => $email, 'ip' => $request->ip()]);
        self::audit('login.failed', $userId, $request, ['email' => $email]);
    }

    /** @param array<string,mixed> $extra */
    private static function audit(string $action, ?int $userId, Request $request, array $extra = []): void
    {
        try {
            DB::insert('audit_log', [
                'user_id'     => $userId,
                'action'      => $action,
                'entity_type' => 'user',
                'entity_id'   => $userId,
                'changes'     => $extra === [] ? null : json_encode($extra),
                'ip'          => $request->ip(),
                'user_agent'  => $request->userAgent(),
                'created_at'  => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Never let an audit write failure block a login attempt.
            Log::error('Audit write failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }
}
