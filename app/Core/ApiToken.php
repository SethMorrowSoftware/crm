<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Bearer tokens for the REST API.
 *
 * Four properties, and each one is here because the obvious implementation
 * gets it wrong:
 *
 * **THE PLAINTEXT IS SHOWN ONCE AND NEVER STORED.** `api_tokens` holds a
 * SHA-256 of the token and nothing else, so a database that leaks — a stolen
 * backup, a mis-scoped read replica, an SQL injection somebody finds in a
 * year — leaks no usable credential. It is hashed with SHA-256 rather than
 * bcrypt deliberately: a token is 32 bytes of CSPRNG output, not a password,
 * so there is nothing to brute-force and a lookup has to be a single indexed
 * query rather than a scan of every row trying `password_verify` on each.
 *
 * **A TOKEN NEVER GRANTS MORE THAN ITS OWNER.** Abilities NARROW the user's
 * permissions; they never add. So a token minted by the front desk cannot be
 * given `contacts_export` by anybody, including an owner editing the row by
 * hand, and a user demoted tomorrow loses everything their tokens could do
 * tonight — without anybody remembering to revoke them. The alternative, a
 * parallel permission system keyed on the token, is how an API becomes the
 * way around the roles.
 *
 * **IT CARRIES ITS OWN PREFIX.** `cc_live_` makes a leaked token findable:
 * secret scanners, grep over a repository, and a person reading a log all
 * recognise it, which is worth more than the four bytes it costs.
 *
 * **EXPIRY IS CHECKED ON USE, NOT BY A SWEEP.** A token that expired while
 * cron was down must not work.
 */
final class ApiToken
{
    /** What a token looks like. Deliberately recognisable. */
    public const PREFIX = 'cc_live_';

    /** 32 bytes, hex-encoded. */
    private const BYTES = 32;

    /** An ability list of exactly this means "everything the owner can do". */
    public const ALL = '*';

    /**
     * Mint a token.
     *
     * Returns the PLAINTEXT, which is the only time it exists in readable
     * form. The caller shows it once and does not store it.
     *
     * @param list<string> $abilities Permission slugs, or ['*'].
     * @return array{id:int,token:string}
     */
    public static function create(
        int $userId,
        string $name,
        array $abilities = [self::ALL],
        ?string $expiresAt = null
    ): array {
        $plain = self::PREFIX . bin2hex(random_bytes(self::BYTES));

        $id = DB::insert('api_tokens', [
            'user_id'    => $userId,
            'name'       => $name,
            'token_hash' => self::hash($plain),
            'abilities'  => json_encode(self::cleanAbilities($abilities), JSON_THROW_ON_ERROR),
            'expires_at' => $expiresAt,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return ['id' => $id, 'token' => $plain];
    }

    /**
     * Resolve a presented token to its row, or null.
     *
     * Null for every failure — unknown, expired, owner deactivated, owner
     * deleted — and the caller says only "that token is not valid". Telling a
     * caller WHICH of those it was is a way to enumerate.
     *
     * @return array<string,mixed>|null
     */
    public static function resolve(string $presented): ?array
    {
        $presented = trim($presented);

        if ($presented === '' || !str_starts_with($presented, self::PREFIX)) {
            return null;
        }

        $row = DB::first(
            'SELECT t.*, u.is_active AS user_active, u.deleted_at AS user_deleted
               FROM {api_tokens} t
               JOIN {users} u ON u.id = t.user_id
              WHERE t.token_hash = :h',
            ['h' => self::hash($presented)]
        );

        if ($row === null) {
            return null;
        }

        // The owner has to still be here and still be allowed in. A token is
        // a key cut for a person, not an account of its own.
        if ((int) $row['user_active'] !== 1 || $row['user_deleted'] !== null) {
            return null;
        }

        if (self::hasExpired($row['expires_at'] ?? null)) {
            return null;
        }

        return $row;
    }

    /**
     * Record that a token was used.
     *
     * To the MINUTE, not the second: this is a write on every API request,
     * and a venue reading "last used" does not care about the second. Writing
     * only when the stored value is older than a minute turns a write per
     * request into a write per minute per token, which matters when an
     * integration polls.
     */
    public static function touch(array $token): void
    {
        $last = $token['last_used_at'] ?? null;

        if (is_string($last) && $last !== '' && strtotime($last) > time() - 60) {
            return;
        }

        try {
            DB::update(
                'api_tokens',
                ['last_used_at' => gmdate('Y-m-d H:i:s')],
                'id = :id',
                ['id' => (int) $token['id']]
            );
        } catch (\Throwable $e) {
            // A failed bookkeeping write must not fail the caller's request.
            Log::warning('Could not stamp an API token', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Does this token allow a permission?
     *
     * Only ever NARROWS: the caller has already established that the USER
     * holds it. `['*']` is the full set the owner has, not a bypass of
     * anything.
     *
     * @param array<string,mixed> $token
     */
    public static function allows(array $token, string $permission): bool
    {
        $abilities = self::abilities($token);

        /*
         * AN EMPTY LIST GRANTS NOTHING. Only `['*']` grants everything.
         *
         * This read `$abilities === [] || in_array(ALL, …)` — an empty set as
         * a synonym for unrestricted — and that is the wrong way for an
         * authorization check to be wrong. It is reachable, not theoretical:
         * `cleanAbilities()` DROPS any ability that is not in the permission
         * catalogue, on purpose, so a token asked for with a renamed or
         * misspelled permission (`['contacts_create']`, a stale checkbox name,
         * a hand-rolled POST) was cleaned to `[]` and stored as `[]` — and
         * then read back as full access to everything its user holds. The
         * narrowest request anybody could make produced the widest token, and
         * the page would have shown no abilities beside it.
         *
         * Failing closed means such a token simply refuses every call, which
         * is visible immediately. `ApiTokenController` now also declines to
         * mint one, so it cannot get this far.
         */
        if ($abilities === []) {
            return false;
        }

        if (in_array(self::ALL, $abilities, true)) {
            return true;
        }

        return in_array($permission, $abilities, true);
    }

    /**
     * The abilities on a token.
     *
     * NO VALUE AT ALL means unrestricted — a row whose column is null or
     * empty predates the column and was created when every token was a
     * full-access token, and reading those as powerless would silently break
     * a venue's working integration on upgrade. An empty JSON LIST is a
     * different thing: somebody chose a set and nothing in it survived, so it
     * grants nothing (see `allows()`). The two must not be conflated, which
     * is why this returns `[]` rather than `[ALL]` for `'[]'`.
     *
     * @return list<string>
     */
    public static function abilities(array $token): array
    {
        $raw = $token['abilities'] ?? null;

        if (!is_string($raw) || trim($raw) === '') {
            return [self::ALL];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            // Unparseable is not a reason to widen a token either, but it is
            // also not a choice anybody made — a corrupt value reads as the
            // legacy case above rather than as a deliberate empty set.
            return [self::ALL];
        }

        return array_values(array_filter($decoded, 'is_string'));
    }

    public static function revoke(int $id): bool
    {
        return DB::delete('api_tokens', 'id = :id', ['id' => $id]) > 0;
    }

    /**
     * Tokens for a user, newest first, WITHOUT the hash.
     *
     * The hash is left out of the listing on purpose: it is not useful to
     * anybody reading the page, and a value that is never rendered cannot be
     * rendered by accident.
     *
     * @return list<array<string,mixed>>
     */
    public static function forUser(int $userId): array
    {
        return DB::all(
            'SELECT id, user_id, name, abilities, last_used_at, expires_at, created_at
               FROM {api_tokens} WHERE user_id = :u ORDER BY id DESC',
            ['u' => $userId]
        );
    }

    /** Every token on the install, for the owner's settings page. */
    public static function all(): array
    {
        return DB::all(
            'SELECT t.id, t.user_id, t.name, t.abilities, t.last_used_at, t.expires_at,
                    t.created_at, u.name AS user_name, u.email AS user_email
               FROM {api_tokens} t
               JOIN {users} u ON u.id = t.user_id
              ORDER BY t.id DESC'
        );
    }

    /**
     * Drop tokens that expired a long time ago.
     *
     * Not a security measure — `resolve()` already refuses them — but a
     * tidiness one, so the settings page is a list of live keys rather than a
     * museum.
     */
    public static function purgeExpired(int $graceDays = 30): int
    {
        return DB::delete(
            'api_tokens',
            'expires_at IS NOT NULL AND expires_at < :cutoff',
            ['cutoff' => gmdate('Y-m-d H:i:s', time() - ($graceDays * 86400))]
        );
    }

    // ------------------------------------------------------------------
    // Plumbing
    // ------------------------------------------------------------------

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public static function hasExpired(mixed $expiresAt): bool
    {
        if (!is_string($expiresAt) || $expiresAt === '') {
            return false;
        }

        $when = strtotime($expiresAt . ' UTC');

        return $when !== false && $when <= time();
    }

    /**
     * Keep only real permission slugs.
     *
     * A typo'd ability is DROPPED rather than kept, and that direction is
     * deliberate: an unrecognised ability that was honoured would be a
     * permission nothing checks, while one that is dropped narrows the token.
     * Failing closed is the only safe way to be wrong here.
     *
     * @param list<string> $abilities
     * @return list<string>
     */
    public static function cleanAbilities(array $abilities): array
    {
        $clean = [];

        foreach ($abilities as $ability) {
            if (!is_string($ability)) {
                continue;
            }

            $ability = trim($ability);

            if ($ability === self::ALL) {
                return [self::ALL];
            }

            if (isset(Acl::CATALOG[$ability])) {
                $clean[] = $ability;
            }
        }

        return array_values(array_unique($clean));
    }

    /** A date a venue typed, as a UTC timestamp, or null. */
    public static function expiryFrom(?string $date): ?string
    {
        if ($date === null || trim($date) === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($date)) !== 1) {
            return null;
        }

        try {
            // End of that day in the VENUE's zone: somebody typing today's
            // date means "until the end of today", not "already expired".
            return (new DateTimeImmutable(trim($date) . ' 23:59:59', venue_tz()))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }
}
