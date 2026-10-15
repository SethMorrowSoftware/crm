<?php

declare(strict_types=1);

/**
 * The post-upload upgrade path.
 *
 * On shared hosting an upgrade is "extract the new zip over the old files".
 * Nothing else runs, so if pending schema files are not applied on the next
 * request, a release that adds a table ships a database without it. These
 * tests use a real temporary schema file, because the failure mode being
 * guarded against is specifically "the file was on disk and nothing read it".
 */

use App\Core\DB;
use App\Core\Migration;
use App\Core\Settings;
use App\Core\Upgrade;
use Tests\Assert;
use Tests\Harness;

Harness::suite('integration/upgrade');

if (!test_boot()) {
    Harness::skip('upgrade suite', 'no database available');

    return;
}

/**
 * Write a schema file that a pending-migration run will pick up, run the
 * callback, then remove the file and the table it made.
 */
function with_pending_migration(string $sql, callable $callback): mixed
{
    // 900 so it sorts after every real file, and is obviously not one.
    $path = CC_ROOT . '/install/schema/900_test_pending.sql';

    file_put_contents($path, $sql);

    try {
        return $callback('900_test_pending.sql');
    } finally {
        @unlink($path);

        try {
            DB::pdo()->exec('DROP TABLE IF EXISTS `cc_upgrade_probe`');
            DB::delete('migrations', 'filename = :m', ['m' => '900_test_pending.sql']);
        } catch (Throwable) {
            // Nothing to clean up.
        }
    }
}

Harness::test('a pending schema file is applied and recorded', function (): void {
    with_pending_migration(
        "CREATE TABLE IF NOT EXISTS `{prefix}upgrade_probe` (\n"
        . "    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
        . "    `note` VARCHAR(40) DEFAULT NULL,\n"
        . "    PRIMARY KEY (`id`)\n"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n",
        static function (string $name): void {
            $migration = new Migration();

            Assert::inArray($name, $migration->pending(), 'the new file should be seen as pending');

            $result = Upgrade::run('9.9.9-test');

            Assert::true($result['ran']);
            Assert::null($result['error']);
            Assert::inArray($name, $result['applied']);

            Assert::true(DB::tableExists('upgrade_probe'), 'the table the release added should exist');
            Assert::same([], (new Migration())->pending(), 'and nothing should still be pending');

            Settings::load(true);

            Assert::same(
                '9.9.9-test',
                (string) Settings::get('system.version'),
                'the marker must advance, or every later request re-runs the upgrade'
            );
            Assert::notNull(Settings::get('system.upgraded_at'));
        }
    );
});

Harness::test('a version bump with no schema change just records the version', function (): void {
    Assert::same([], (new Migration())->pending(), 'precondition: nothing pending');

    $result = Upgrade::run('9.9.10-test');

    Assert::false($result['ran'], 'there was nothing to run');
    Assert::null($result['error']);

    Settings::load(true);

    Assert::same('9.9.10-test', (string) Settings::get('system.version'));
});

Harness::test('a failing schema file leaves the version marker alone', function (): void {
    // The important half of the contract: a half-applied upgrade must look
    // unfinished, so the next request tries again rather than serving a
    // database it believes is current.
    Settings::set('system.version', '1.0.0-before');
    Settings::load(true);

    with_pending_migration(
        "CREATE TABLE IF NOT EXISTS `{prefix}upgrade_probe` (\n"
        . "    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
        . "    PRIMARY KEY (`id`)\n"
        . ") ENGINE=InnoDB;\n"
        . "THIS IS NOT VALID SQL AT ALL;\n",
        static function (): void {
            $result = Upgrade::run('9.9.11-test');

            Assert::notNull($result['error'], 'a broken file must be reported, not swallowed');

            Settings::load(true);

            Assert::same(
                '1.0.0-before',
                (string) Settings::get('system.version'),
                'the marker must not advance past a failed upgrade'
            );
        }
    );
});

Harness::test('re-running a partially applied file completes it', function (): void {
    // MySQL DDL is not transactional, so this is the normal recovery path
    // rather than an edge case. Every CREATE uses IF NOT EXISTS and the runner
    // tolerates "already exists", which is what makes a retry safe.
    with_pending_migration(
        "CREATE TABLE IF NOT EXISTS `{prefix}upgrade_probe` (\n"
        . "    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
        . "    PRIMARY KEY (`id`)\n"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n",
        static function (string $name): void {
            // Simulate the first half having already run.
            DB::pdo()->exec(
                'CREATE TABLE IF NOT EXISTS `cc_upgrade_probe` ('
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );

            $result = (new Migration())->run();

            Assert::inArray($name, $result['applied'], 'the retry should complete the file, not fail on it');
            Assert::same([], (new Migration())->pending());
        }
    );
});

Harness::test('the version marker ends up matching the shipped VERSION file', function (): void {
    // Leave the test database describing itself honestly for later suites.
    $shipped = trim((string) file_get_contents(CC_ROOT . '/VERSION'));

    Assert::notEmpty($shipped, 'the VERSION file should not be empty');

    Upgrade::run($shipped);
    Settings::load(true);

    Assert::same($shipped, (string) Settings::get('system.version'));
});

// ------------------------------------- which failures mean "already applied"

Harness::test('only genuine already-exists codes are tolerated', function (): void {
    // Deciding this wrongly is how a half-applied release gets recorded as
    // complete. 1005 is ambiguous: errno 121 is a duplicate constraint NAME
    // (already there), errno 150 is a foreign key MySQL refuses to build at
    // all (a real error). Tolerating 1005 outright meant a release whose new
    // table had a mismatched FK column type was recorded as applied and the
    // version marker advanced over a table that did not exist.
    $tolerated = [
        [1050, 'Table \'cc_contacts\' already exists'],
        [1060, 'Duplicate column name \'email\''],
        [1061, 'Duplicate key name \'ix_contacts_email\''],
        [1826, 'Duplicate foreign key constraint name \'fk_x\''],
        [1831, 'Duplicate index'],
        [1005, "Can't create table (errno: 121 \"Duplicate key on write or update\")"],
    ];

    foreach ($tolerated as [$code, $message]) {
        Assert::true(
            Migration::meansAlreadyApplied($code, $message),
            $code . ' should be treated as already applied'
        );
    }

    $fatal = [
        [1005, "Can't create table `cc_loyalty` (errno: 150 \"Foreign key constraint is incorrectly formed\")"],
        [1005, "Can't create table (errno: 13 \"Permission denied\")"],
        [1005, "Can't create table"],
        [1022, "Duplicate entry 'x' for key 'PRIMARY'"],
        [1064, 'You have an error in your SQL syntax'],
        [1146, "Table 'cc_missing' doesn't exist"],
        [1452, 'Cannot add or update a child row: a foreign key constraint fails'],
        [0, 'unknown'],
    ];

    foreach ($fatal as [$code, $message]) {
        Assert::false(
            Migration::meansAlreadyApplied($code, $message),
            $code . ' must NOT be swallowed: ' . $message
        );
    }
});

Harness::test('a malformed foreign key fails the upgrade instead of being recorded', function (): void {
    // MEASURED as the review reported it: contact_id INT UNSIGNED against
    // contacts.id BIGINT UNSIGNED is an ordinary slip, MySQL rejects the whole
    // CREATE with 1005/errno 150, and the table is never created.
    Settings::set('system.version', '1.0.0-before-bad-fk');
    Settings::load(true);

    with_pending_migration(
        "CREATE TABLE IF NOT EXISTS `{prefix}upgrade_probe` (\n"
        . "    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
        . "    `contact_id` INT UNSIGNED DEFAULT NULL,\n"
        . "    PRIMARY KEY (`id`),\n"
        . "    CONSTRAINT `{prefix}fk_probe_contact`\n"
        . "        FOREIGN KEY (`contact_id`) REFERENCES `{prefix}contacts` (`id`)\n"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n",
        static function (string $name): void {
            $result = Upgrade::run('9.9.20-test');

            Assert::notNull($result['error'], 'a rejected CREATE TABLE must be reported');
            Assert::false(
                DB::tableExists('upgrade_probe'),
                'precondition: MySQL really did refuse to create it'
            );
            Assert::inArray(
                $name,
                (new Migration())->pending(),
                'the file must stay pending, not be recorded as applied'
            );

            Settings::load(true);

            Assert::same(
                '1.0.0-before-bad-fk',
                (string) Settings::get('system.version'),
                'and the version marker must not advance over a missing table'
            );
        }
    );
});

Harness::test('every foreign-key constraint name carries the table prefix', function (): void {
    // InnoDB requires FK constraint names to be unique per DATABASE, not per
    // table. The installer invites sharing a database (step 3 offers the
    // prefix precisely so two installs can coexist), so unprefixed names meant
    // a second install collided: MEASURED before the fix, files 001-003 were
    // recorded as applied without throwing and produced a partial schema.
    $unprefixed = [];

    foreach ((array) glob(CC_ROOT . '/install/schema/*.sql') as $file) {
        $sql = (string) file_get_contents((string) $file);

        if (preg_match_all('/CONSTRAINT\s+`([^`]+)`/i', $sql, $matches) === 0) {
            continue;
        }

        foreach ($matches[1] as $name) {
            if (!str_starts_with($name, '{prefix}')) {
                $unprefixed[] = basename((string) $file) . ': ' . $name;
            }
        }
    }

    Assert::same(
        [],
        $unprefixed,
        "these constraint names would collide with a second install in the same database:\n  - "
            . implode("\n  - ", $unprefixed)
    );
});
