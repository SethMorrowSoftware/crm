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

    Settings::load(true);

    $booted = true;

    return true;
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
