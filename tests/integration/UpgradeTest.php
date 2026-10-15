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
