<?php

declare(strict_types=1);

/**
 * Test bootstrap.
 *
 * Boots the application against a throwaway database, applies the schema and
 * runs the seeds, so integration tests work against the same code path the
 * installer uses rather than a fixture that can drift from it.
 *
 * Database connection comes from the environment, which is what CI sets:
 *   CC_TEST_DB_HOST  (default 127.0.0.1)
 *   CC_TEST_DB_PORT  (default 3306)
 *   CC_TEST_DB_NAME  (default castle_crm_test)
 *   CC_TEST_DB_USER  (default root)
 *   CC_TEST_DB_PASS  (default empty)
 *
 * With CC_TEST_SKIP_DB=1 the database-backed suites are skipped, so the unit
 * and static suites can run anywhere with nothing but PHP.
 */

/*
 * CLI ONLY. The runner is guarded, and so is this: it is the file that knows
 * how to connect to the test database and drop every table in it, and a
 * `require` of it over HTTP would put those functions one call away from
 * whatever else got included. See the note in `tests/run.php`.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}


define('CC_ROOT', dirname(__DIR__));
define('CC_TESTING', true);

require CC_ROOT . '/tests/Assert.php';
require CC_ROOT . '/tests/Harness.php';
require CC_ROOT . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\DB;
use App\Core\Settings;

/**
 * Database configuration from the environment.
 *
 * @return array<string,mixed>
 */
function test_db_config(): array
{
    return [
        'host'    => getenv('CC_TEST_DB_HOST') ?: '127.0.0.1',
        'port'    => (int) (getenv('CC_TEST_DB_PORT') ?: 3306),
        'name'    => getenv('CC_TEST_DB_NAME') ?: 'castle_crm_test',
        'user'    => getenv('CC_TEST_DB_USER') ?: 'root',
        'pass'    => getenv('CC_TEST_DB_PASS') !== false ? (string) getenv('CC_TEST_DB_PASS') : '',
        'charset' => 'utf8mb4',
        'prefix'  => 'cc_',
        'socket'  => getenv('CC_TEST_DB_SOCKET') ?: null,
    ];
}

function test_db_enabled(): bool
{
    return getenv('CC_TEST_SKIP_DB') !== '1';
}

/**
 * Boot the application for testing. Returns false when the database is
 * unavailable, so the caller can skip rather than fail — a developer running
 * the unit suite on a laptop should not need MySQL.
 */
function test_boot(bool $fresh = false): bool
{
    static $booted = false;

    Config::load([
        'env'          => 'testing',
        'debug'        => true,
        'app_key'      => base64_encode(str_repeat("\x2a", 32)),
        'db'           => test_db_config(),
        'storage_path' => CC_ROOT . '/storage',
        'cron_token'   => 'test-cron-token',
        'trust_proxy'  => false,
        'log_level'    => 'error',
        'session'      => [
            'name'             => 'castle_crm_test_session',
            'idle_timeout'     => 3600,
            'absolute_timeout' => 86400,
            'cookie_secure'    => false,
            'cookie_samesite'  => 'Lax',
        ],
    ]);

    if (!test_db_enabled()) {
        return false;
    }

    if ($booted && !$fresh) {
        return true;
    }

    try {
        DB::disconnect();
        DB::connect();
    } catch (Throwable $e) {
        fwrite(STDERR, "\n[test bootstrap] Database unavailable: " . $e->getMessage() . "\n");

        return false;
    }

    if ($fresh) {
        test_drop_all();
    }

    $migration = new App\Core\Migration(CC_ROOT . '/install/schema', 'cc_');
    $migration->run();

    // The seeds are part of what we are testing, so they run through their own
    // files rather than being duplicated here.
    (require CC_ROOT . '/install/seeds/permissions.php')();
    (require CC_ROOT . '/install/seeds/venue.php')();
    (require CC_ROOT . '/install/seeds/playbooks.php')();
    // Mirrors the installer, which runs this seed unconditionally: the
    // password-reset path resolves a template by slug and fails without it.
    (require CC_ROOT . '/install/seeds/templates.php')();
    // Also unconditional in the installer: survey_responses.survey_id is a
    // non-null foreign key, so the reputation module needs a survey to exist.
    (require CC_ROOT . '/install/seeds/surveys.php')();

    test_apply_venue_tz_override();

    Settings::load(true);

    $booted = true;

    return true;
}

/**
 * Move the venue's timezone for the run, so the suite can be audited for
 * UTC-versus-local date bugs without waiting for the clock.
 *
 *   CC_TEST_VENUE_TZ=Pacific/Kiritimati php tests/run.php     local is AHEAD
 *   CC_TEST_VENUE_TZ=Pacific/Midway     php tests/run.php     local is BEHIND
 *
 * The bug this exists to find — see `test_local_day()` below — only shows
 * itself while the venue's calendar day and UTC's disagree, which for
 * `America/New_York` is the four hours between 8pm and midnight Eastern. A
 * test written in the wrong frame therefore passes all day and fails in the
 * evening, which is both the worst way to find out and the hardest state to
 * reproduce on purpose.
 *
 * Moving the venue instead of the clock reproduces it on demand, but WHICH
 * side of midnight you get still depends on the hour you run at, so it is
 * worth knowing exactly what each one buys:
 *
 *   Pacific/Midway     (UTC-11) is a day BEHIND  UTC from 00:00 to 10:59 UTC
 *   Pacific/Kiritimati (UTC+14) is a day AHEAD of UTC from 10:00 to 23:59 UTC
 *
 * Between them, some skew is available at every hour of the day, and the two
 * directions together are the whole space. Neither observes daylight saving,
 * so a failure under one of them is a frame error and never a DST artefact.
 * To choose the direction rather than take what the hour gives — running the
 * venue-ahead case in the morning, say — put the clock where you need it with
 * `faketime`, and start MySQL under the same offset so that `UTC_TIMESTAMP()`
 * and PHP's `time()` still agree.
 *
 * This writes the same settings row the installer writes, which is what makes
 * it reach the HTTP suite too: that runs in a separate web-server process,
 * so an override held only in this process's memory would not apply to it.
 */
function test_apply_venue_tz_override(): void
{
    $zone = getenv('CC_TEST_VENUE_TZ');

    if ($zone === false || $zone === '') {
        return;
    }

    try {
        new DateTimeZone($zone);
    } catch (Throwable) {
        fwrite(STDERR, "\n[test bootstrap] CC_TEST_VENUE_TZ=\"{$zone}\" is not a timezone.\n");
        exit(1);
    }

    Settings::set('venue.timezone', $zone);

    echo 'Venue timezone overridden: ' . $zone . "\n";
}

/**
 * The venue's calendar day, `$daysAgo` days ago.
 *
 * ```php
 * test_local_day()                 // today, at the venue
 * test_local_day(7)                // a week ago, at the venue
 * test_local_day(-7)               // a week from now: negative is the future
 * test_local_day(10, ' 12:00:00')  // that day, as a timestamp
 * ```
 *
 * **Use this, and not `gmdate('Y-m-d', time() - $n * 86400)`, for any date a
 * test expects the application to reason about as a day.**
 *
 * The application reasons in the VENUE's calendar day, not UTC. The venue is
 * `America/New_York` (`venue_tz()` in `app/helpers.php`), so from 8pm Eastern
 * until midnight it is already tomorrow in UTC and still today at the Castle.
 * `MembershipLifecycle::today()` reads the venue's day; so does every report
 * window and every rollup bucket, through `App\Services\LocalDay`.
 *
 * A test that builds its dates with `gmdate()` is therefore making a claim in
 * the wrong calendar for four hours out of every twenty-four. It does not
 * fail flakily — it fails *deterministically, in the evening*, and passes
 * every time anyone looks at it the next morning. Three tests did exactly
 * that the moment a run crossed midnight UTC:
 *
 *   - `FREEZING GIVES THE TIME BACK` wanted 10 days back and got 9, because
 *     the UTC date it wrote into `updated_at` was a day further from the
 *     venue's today than it meant to be;
 *   - `freezing and unfreezing move the end date` lost its "moved on by
 *     7 days" for the same reason, over HTTP;
 *   - `the rollup agrees with the report it summarises` compared 96000
 *     against 25000, because the UTC "yesterday" it booked revenue on was
 *     still today at the venue, and the nightly rollup only covers days that
 *     have finished.
 *
 * The suffix is appended verbatim, for the columns that want a timestamp
 * rather than a date. Midday is the right time of day to ask for: it is the
 * same calendar day in the venue's frame and in UTC whatever the offset, so
 * the value says what it means however the column is later read.
 */
function test_local_day(int $daysAgo = 0, string $suffix = ''): string
{
    $day = (new DateTimeImmutable('now', venue_tz()))
        ->modify(sprintf('%+d days', -$daysAgo));

    return $day->format('Y-m-d') . $suffix;
}

/**
 * Drop every table in the test database, so a fresh run is genuinely fresh.
 *
 * Foreign key checks go off for the duration: dropping 99 interlinked tables
 * in dependency order is not worth the complexity in a disposable database.
 */
function test_drop_all(): void
{
    $tables = DB::column(
        'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()'
    );

    if ($tables === []) {
        return;
    }

    DB::pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');

    foreach ($tables as $table) {
        DB::pdo()->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', (string) $table) . '`');
    }

    DB::pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
}

/**
 * Create a user for a test and return its id.
 *
 * @param array<string,mixed> $overrides
 */
function test_make_user(string $roleSlug = 'owner', array $overrides = []): int
{
    $roleId = (int) DB::value('SELECT id FROM {roles} WHERE slug = :s', ['s' => $roleSlug]);

    static $counter = 0;
    $counter++;

    return DB::insert('users', array_merge([
        'name'          => 'Test User ' . $counter,
        'email'         => 'test' . $counter . '-' . bin2hex(random_bytes(4)) . '@example.test',
        'password_hash' => App\Core\Auth::hash('test-passphrase-' . $counter . '-long'),
        'role_id'       => $roleId,
        'is_active'     => 1,
        'created_at'    => gmdate('Y-m-d H:i:s'),
        'updated_at'    => gmdate('Y-m-d H:i:s'),
    ], $overrides));
}

/**
 * Create a contact and return its id.
 *
 * @param array<string,mixed> $overrides
 */
function test_make_contact(array $overrides = []): int
{
    static $counter = 0;
    $counter++;

    return DB::insert('contacts', array_merge([
        'first_name'       => 'Guest',
        'last_name'        => 'Number' . $counter,
        'email'            => 'guest' . $counter . '-' . bin2hex(random_bytes(4)) . '@example.test',
        'phone_normalized' => '+1845000' . str_pad((string) $counter, 4, '0', STR_PAD_LEFT),
        'lifecycle_stage'  => 'lead',
        'created_at'       => gmdate('Y-m-d H:i:s'),
        'updated_at'       => gmdate('Y-m-d H:i:s'),
    ], $overrides));
}

/** Remove the rows a test created, leaving the seeded data in place. */
function test_reset_transactional_data(): void
{
    DB::pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');

    foreach ([
        'audit_log', 'sessions', 'password_resets', 'jobs', 'job_failures',
        'notifications', 'contacts', 'households', 'children', 'companies',
        'deals', 'bookings', 'visits', 'conversations', 'messages',
        'automation_runs', 'tasks', 'consent_log', 'activities',
        'suppressions', 'usage_ledger', 'booking_payments', 'taggables',
    ] as $table) {
        try {
            DB::pdo()->exec('DELETE FROM `cc_' . $table . '`');
        } catch (Throwable) {
            // A table that does not exist in this schema version is not a
            // reason to abort the reset.
        }
    }

    // Users other than any the current test made are cleared too, but the
    // seeded roles and venue data stay.
    try {
        DB::pdo()->exec('DELETE FROM `cc_users`');
    } catch (Throwable) {
    }

    DB::pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
}
