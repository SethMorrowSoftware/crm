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

Harness::test('1022 is already-applied only for a statement that adds a constraint', function (): void {
    // MEASURED on CI, 2026-10-15: MySQL 5.7.44 answers a duplicate
    // `ALTER TABLE … ADD CONSTRAINT` foreign-key name with
    // "1022 Can't write; duplicate key in table '#sql-1_b'". This release had
    // dropped 1022 outright, so on 5.7 a schema file that died after one of
    // the shipped schema's nine ADD CONSTRAINT statements could never be
    // completed: every retry threw and the upgrade was wedged, where before it
    // recovered. The matrix found it because the retry test above finally
    // exercises the tolerance.
    //
    // It is back for that statement shape ALONE. On its own, 1022 is a
    // duplicate VALUE — a data conflict — so a data-migration INSERT that hits
    // a real one reports the same number, and swallowing that would record a
    // half-applied file as complete. That is the precise failure this
    // release's narrowing existed to stop, so the narrow form is the whole
    // point and this test is what keeps it narrow.
    $duplicate = "Can't write; duplicate key in table '#sql-1_b'";

    $addsOne = [
        'ALTER TABLE `cc_contacts` ADD CONSTRAINT `cc_fk_contacts_owner` '
            . 'FOREIGN KEY (`owner_id`) REFERENCES `cc_users` (`id`)',
        "ALTER TABLE `cc_deals`\n  ADD CONSTRAINT `cc_fk_deals_contact`\n  FOREIGN KEY (`contact_id`)",
        'alter table `cc_x` add foreign key (`a`) references `cc_y` (`id`)',
    ];

    foreach ($addsOne as $sql) {
        Assert::true(
            Migration::meansAlreadyApplied(1022, $duplicate, $sql),
            'a retry must get past a constraint it already added: ' . $sql
        );
    }

    $doesNot = [
        // The dangerous one: real data conflict during a migration.
        'INSERT INTO `cc_contacts` (`email`) SELECT `email` FROM `cc_legacy_contacts`',
        'UPDATE `cc_contacts` SET `email` = LOWER(`email`)',
        // An added index reports 1061, which is tolerated on its own merits —
        // 1022 from one of these is not something to guess about.
        'ALTER TABLE `cc_contacts` ADD UNIQUE KEY `uq_contacts_email` (`email`)',
        'CREATE TABLE `cc_x` (`id` BIGINT UNSIGNED NOT NULL)',
        // No statement context at all must never tolerate it.
        '',
    ];

    foreach ($doesNot as $sql) {
        Assert::false(
            Migration::meansAlreadyApplied(1022, $duplicate, $sql),
            '1022 must stay fatal here, or a data conflict is recorded as success: '
                . ($sql === '' ? '(no statement)' : $sql)
        );
    }

    // And 5.7's MALFORMED-fk code is still fatal, even on an ADD CONSTRAINT.
    Assert::false(
        Migration::meansAlreadyApplied(
            1215,
            'Cannot add foreign key constraint',
            'ALTER TABLE `cc_x` ADD CONSTRAINT `cc_fk_x` FOREIGN KEY (`a`) REFERENCES `cc_y` (`id`)'
        ),
        "1215 is MySQL 5.7 refusing to BUILD the key, not finding it already there"
    );
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

Harness::test('a retry tolerates a foreign key the first attempt already added', function (): void {
    // THE test behind the retry contract, which did not exist.
    //
    // 're-running a partially applied file completes it' above looks like it
    // covers this and does not: `CREATE TABLE IF NOT EXISTS` on an existing
    // table raises no error at all, only a Note, so meansAlreadyApplied() is
    // never entered. MEASURED by mutation — making that method return false
    // unconditionally leaves that test and all 34 schema tests green.
    //
    // The statements in the shipped schema that genuinely depend on the
    // tolerance are the nine `ALTER TABLE … ADD CONSTRAINT {prefix}fk_…`, which
    // are NOT idempotent: re-running one after it succeeded raises a duplicate
    // constraint error, and whether the runner tolerates that code decides
    // whether a half-applied release can EVER be completed or is wedged
    // forever. This release narrowed the tolerated set — 1022 removed, 1005
    // made conditional on errno 121 — so it is exactly the wrong moment for
    // this path to be untested.
    //
    // Engine codes: MEASURED 1005 + "errno: 121" on MariaDB 10.11; MySQL 8.0
    // standardises on 1826. MySQL 5.7's code was NOT established from the
    // documentation, and third-party reports say 1022 — which this release no
    // longer tolerates. This test runs on that cell of the matrix, so CI
    // answers the question instead of an assumption carrying it.
    $pdo = DB::pdo();

    $pdo->exec('DROP TABLE IF EXISTS `cc_upgrade_fk_child`');
    $pdo->exec('DROP TABLE IF EXISTS `cc_upgrade_fk_parent`');
    $pdo->exec(
        'CREATE TABLE `cc_upgrade_fk_parent` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
        . 'PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE `cc_upgrade_fk_child` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
        . '`parent_id` BIGINT UNSIGNED DEFAULT NULL, PRIMARY KEY (`id`), KEY (`parent_id`)) '
        . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    try {
        with_pending_migration(
            "ALTER TABLE `{prefix}upgrade_fk_child`\n"
            . "    ADD CONSTRAINT `{prefix}fk_upgrade_probe_parent`\n"
            . "    FOREIGN KEY (`parent_id`) REFERENCES `{prefix}upgrade_fk_parent` (`id`)\n"
            . "    ON DELETE SET NULL;\n",
            static function (string $name) use ($pdo): void {
                // The state a retry starts from: this statement already
                // succeeded, and the file died somewhere after it.
                $add = 'ALTER TABLE `cc_upgrade_fk_child` '
                    . 'ADD CONSTRAINT `cc_fk_upgrade_probe_parent` '
                    . 'FOREIGN KEY (`parent_id`) REFERENCES `cc_upgrade_fk_parent` (`id`) '
                    . 'ON DELETE SET NULL';

                $pdo->exec($add);

                // Ask THIS engine what it says, and hold the classifier to that
                // answer rather than to a hardcoded list. 5.7's code (1022) had
                // to be learned from a CI failure; this way an engine that
                // answers something new reports the number itself.
                $engine = (string) DB::value('SELECT VERSION()');
                $observed = null;

                try {
                    $pdo->exec($add);
                } catch (Throwable $e) {
                    $observed = $e;
                }

                Assert::notNull(
                    $observed,
                    'adding the same constraint twice should fail on ' . $engine
                        . '; if it silently succeeds, this test is not testing anything'
                );

                $code = (int) (($observed instanceof PDOException ? $observed->errorInfo[1] : null) ?? 0);

                Assert::true(
                    Migration::meansAlreadyApplied($code, $observed->getMessage(), $add),
                    'this engine reports ' . $code . ' for a duplicate constraint name and the '
                        . 'runner does not tolerate it, so a half-applied release can never be '
                        . 'completed here. Engine: ' . $engine . '. Message: '
                        . $observed->getMessage()
                );

                // Caught, because an untolerated code THROWS here — and a bare
                // RuntimeException in the report tells whoever sees it far less
                // than a sentence naming the engine and what to do about it.
                try {
                    $result = (new Migration())->run();
                } catch (Throwable $e) {
                    Assert::fail(
                        'the retry threw instead of tolerating a duplicate constraint name, so a '
                            . 'half-applied release can never be completed on this engine. Engine: '
                            . (string) DB::value('SELECT VERSION()') . '. Error: ' . $e->getMessage()
                            . ' — if this is MySQL 5.7 answering 1022, that code was dropped from '
                            . 'Migration::ALREADY_EXISTS in this release and has to be tolerated for '
                            . 'this case specifically (it is ambiguous in general, which is why it '
                            . 'was dropped).'
                    );

                    return;
                }

                Assert::inArray(
                    $name,
                    $result['applied'],
                    'a duplicate constraint name must read as "already applied", or a release that '
                        . 'half-applied can never be completed. Engine: '
                        . (string) DB::value('SELECT VERSION()') . '. Runner said: '
                        . (string) ($result['error'] ?? '(no error reported)')
                );
                Assert::same([], (new Migration())->pending());
            }
        );
    } finally {
        $pdo->exec('DROP TABLE IF EXISTS `cc_upgrade_fk_child`');
        $pdo->exec('DROP TABLE IF EXISTS `cc_upgrade_fk_parent`');
    }
});

// --------------------------------------------------- leave the database honest

Harness::test('the suite leaves the version marker describing the shipped release', function (): void {
    // This has to be the LAST test in the file, and it is a repair as well as
    // an assertion.
    //
    // Several tests above deliberately set system.version to a release that
    // does not exist ('1.0.0-before', '1.0.0-before-bad-fk') and then assert
    // the marker must NOT advance — so the fake value is still there when they
    // end. 'the version marker ends up matching the shipped VERSION file' used
    // to be the last word on that, but the tests added later in this file run
    // after it, and UpgradeTest is the last integration file: the http layer
    // was being handed a database claiming to be a release nobody shipped.
    //
    // That is not harmless. app/bootstrap.php calls cc_apply_pending_upgrade()
    // on every web request, so the first request of the http layer re-entered
    // the production upgrade path mid-suite — including its Cache::flush() —
    // which is exactly the sort of coupling that turns an unrelated cache or
    // rate-limit assertion into a mystery three suites later.
    //
    // If you add a test below this one, move this back to the bottom.
    $shipped = trim((string) file_get_contents(CC_ROOT . '/VERSION'));

    Assert::notEmpty($shipped, 'the VERSION file should not be empty');

    Settings::set('system.version', $shipped);
    Settings::load(true);

    Assert::same(
        $shipped,
        (string) Settings::get('system.version'),
        'later suites read this, and a lie here is invisible until something else breaks'
    );
});
