<?php

declare(strict_types=1);

/**
 * Backups and CSV export, against a real database.
 *
 * A backup is only worth having if it restores, so these tests check the file
 * is actually importable SQL and not merely non-empty: the structure, the
 * data, the foreign-key guards around it, and the ordering that makes it
 * possible to import into a database that already has tables.
 */

use App\Core\Backup;
use App\Core\DB;
use Tests\Assert;
use Tests\Harness;

Harness::suite('integration/backup');

if (!test_boot()) {
    Harness::skip('backup suite', 'no database available');

    return;
}

/** Read a backup file back, transparently un-gzipping it. */
function read_backup(string $filename): string
{
    $path = Backup::pathFor($filename);

    Assert::notNull($path, 'the backup should be readable at ' . $filename);

    $contents = str_ends_with($filename, '.gz')
        ? (string) implode('', (array) gzfile($path))
        : (string) file_get_contents($path);

    return $contents;
}

/** Remove every backup, so a test starts from a known count. */
function clear_backups(): void
{
    foreach (Backup::all() as $backup) {
        Backup::delete($backup['filename']);
    }
}

// ---------------------------------------------------------------- the basics

Harness::test('the backup directory is created and protected', function (): void {
    $dir = Backup::directory();

    Assert::true(is_dir($dir));
    Assert::true(
        is_file($dir . '/.htaccess'),
        'a dump of every guest is the last file that should ever be web-servable'
    );
    Assert::contains('Require all denied', (string) file_get_contents($dir . '/.htaccess'));
});

Harness::test('only this installation\'s tables are dumped', function (): void {
    $tables = Backup::tables();

    Assert::greaterThan(50, count($tables), 'the schema has 99 tables; a handful means the match is wrong');

    foreach ($tables as $table) {
        Assert::true(
            str_starts_with($table, DB::prefix()),
            $table . ' is outside the prefix — the database is often shared with another app'
        );
    }
});

// --------------------------------------------------------------- creating one

Harness::test('a backup produces importable SQL', function (): void {
    clear_backups();

    $result = Backup::create(['reason' => 'test']);

    Assert::true($result['ok'], (string) $result['error']);
    Assert::notNull($result['filename']);
    Assert::greaterThan(0, $result['bytes']);
    Assert::greaterThan(50, $result['tables']);

    $sql = read_backup((string) $result['filename']);

    // Structure.
    Assert::contains('DROP TABLE IF EXISTS `cc_contacts`', $sql, 'a restore must be able to replace a table');
    Assert::contains('CREATE TABLE `cc_contacts`', $sql);

    // Data from a seeded table.
    Assert::contains('INSERT INTO `cc_permissions`', $sql);
    Assert::contains('INSERT INTO `cc_templates`', $sql);

    // The guards that make an out-of-order import work at all.
    Assert::contains('FOREIGN_KEY_CHECKS=0', $sql);
    Assert::contains('SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS', $sql);
    Assert::contains('SET NAMES utf8mb4', $sql);

    // And the warning that this file is not a complete backup on its own.
    Assert::contains('config/config.php', $sql);
    Assert::contains('CONFIDENTIAL', $sql);
    Assert::contains('-- Complete.', $sql, 'the footer proves the write finished');
});

Harness::test('transient tables are dumped as structure without data', function (): void {
    clear_backups();

    DB::insert('sessions', [
        'id'            => bin2hex(random_bytes(16)),
        'user_id'       => null,
        'payload'       => 'test',
        'last_activity' => gmdate('Y-m-d H:i:s'),
        'created_at'    => gmdate('Y-m-d H:i:s'),
    ]);

    $result = Backup::create(['reason' => 'test']);
    $sql = read_backup((string) $result['filename']);

    Assert::contains('CREATE TABLE `cc_sessions`', $sql, 'a restore still needs the table to exist');
    Assert::notContains(
        'INSERT INTO `cc_sessions`',
        $sql,
        "restoring last Tuesday's sign-in sessions helps nobody"
    );

    DB::pdo()->exec('DELETE FROM `cc_sessions`');
});

Harness::test('--include-volatile does include the data', function (): void {
    clear_backups();

    DB::insert('sessions', [
        'id'            => bin2hex(random_bytes(16)),
        'user_id'       => null,
        'payload'       => 'included-please',
        'last_activity' => gmdate('Y-m-d H:i:s'),
        'created_at'    => gmdate('Y-m-d H:i:s'),
    ]);

    $result = Backup::create(['include_volatile' => true, 'reason' => 'test']);
    $sql = read_backup((string) $result['filename']);

    Assert::contains('INSERT INTO `cc_sessions`', $sql);

    DB::pdo()->exec('DELETE FROM `cc_sessions`');
});

Harness::test('values are escaped so a quote in guest data cannot break the dump', function (): void {
    clear_backups();

    // The classic: an apostrophe in a surname, and a deliberate injection
    // attempt. Both have to come back out as data, not as SQL.
    $id = test_make_contact([
        'last_name'     => "O'Brien",
        'notes_summary' => "'; DROP TABLE cc_contacts; -- ",
    ]);

    $result = Backup::create(['reason' => 'test']);
    $sql = read_backup((string) $result['filename']);

    // Both quotes come through backslash-escaped, so neither terminates its
    // own string literal. Asserting the escaped form is the point: asserting
    // the absence of the raw payload would pass trivially and prove nothing,
    // since the escaped text still contains it character for character.
    Assert::contains("O\\'Brien", $sql, 'the apostrophe must be escaped, not dropped');
    Assert::contains("\\'; DROP TABLE", $sql, 'the payload must not be able to close its own quote');

    // Prove it by replaying the INSERT for that row into a scratch table.
    // Statement boundaries are "; at the end of a line", which is exactly why
    // the dump writes them that way. Splitting on a bare semicolon would cut
    // this statement in half, because the injected payload contains two.
    preg_match('/INSERT INTO `cc_contacts`.*?;$/ms', $sql, $m);
    Assert::notEmpty($m, 'the contacts INSERT should be in the dump');

    DB::pdo()->exec('DROP TABLE IF EXISTS `cc_contacts_restore_test`');
    DB::pdo()->exec('CREATE TABLE `cc_contacts_restore_test` LIKE `cc_contacts`');
    DB::pdo()->exec(str_replace('`cc_contacts`', '`cc_contacts_restore_test`', $m[0]));

    $restored = DB::first(
        'SELECT last_name, notes_summary FROM `cc_contacts_restore_test` WHERE id = :id',
        ['id' => $id]
    );

    Assert::notNull($restored, 'the row should have been recreated from the dump');
    Assert::same("O'Brien", $restored['last_name'], 'the round trip through the dump must be lossless');
    Assert::same(
        "'; DROP TABLE cc_contacts; -- ",
        $restored['notes_summary'],
        'the injection attempt restores as the text it always was'
    );

    // And the table it tried to drop is still standing.
    Assert::true(DB::tableExists('contacts'));

    DB::pdo()->exec('DROP TABLE `cc_contacts_restore_test`');
    DB::delete('contacts', 'id = :id', ['id' => $id]);
});

Harness::test('a NULL stays NULL rather than becoming an empty string', function (): void {
    clear_backups();

    $id = test_make_contact(['phone' => null, 'city' => null]);

    $sql = read_backup((string) Backup::create(['reason' => 'test'])['filename']);

    preg_match('/INSERT INTO `cc_contacts`.*?;$/ms', $sql, $m);

    Assert::contains('NULL', $m[0] ?? '', 'an empty string is not the same value as NULL');

    DB::delete('contacts', 'id = :id', ['id' => $id]);
});

Harness::test('a multi-byte name survives the dump intact', function (): void {
    clear_backups();

    $id = test_make_contact(['first_name' => 'Zoë', 'last_name' => '中村']);

    $sql = read_backup((string) Backup::create(['reason' => 'test'])['filename']);

    Assert::contains('Zoë', $sql);
    Assert::contains('中村', $sql, 'utf8mb4 in, utf8mb4 out');

    DB::delete('contacts', 'id = :id', ['id' => $id]);
});

Harness::test('a run that exceeds its deadline leaves no file behind', function (): void {
    clear_backups();

    // A deadline already in the past: the run must abort on the first table.
    $result = Backup::create(['deadline' => microtime(true) - 1.0, 'reason' => 'test']);

    Assert::false($result['ok']);
    Assert::contains('ran out of time', strtolower((string) $result['error']));
    Assert::count(
        0,
        Backup::all(),
        'a partial dump somebody restores from in an emergency is worse than no dump'
    );

    // And no stray .part file either.
    Assert::count(0, (array) glob(Backup::directory() . '/*.part'));
});

// ------------------------------------------------------------- path safety

Harness::test('pathFor refuses anything that is not a backup file', function (): void {
    foreach ([
        '../config/config.php',
        '..%2fconfig%2fconfig.php',
        '/etc/passwd',
        'castle-crm-20260101-000000.sql/../../../etc/passwd',
        'castle-crm-2026-01-01.sql',
        'castle-crm-20260101-000000.txt',
        'config.php',
        '',
        '.',
    ] as $attempt) {
        Assert::null(Backup::pathFor($attempt), 'should refuse: ' . $attempt);
    }
});

Harness::test('pathFor returns null for a well-formed name that does not exist', function (): void {
    Assert::null(Backup::pathFor('castle-crm-19990101-000000.sql.gz'));
});

Harness::test('delete refuses a path outside the backup directory', function (): void {
    Assert::false(Backup::delete('../../VERSION'));
    Assert::true(is_file(CC_ROOT . '/VERSION'), 'and the file is still there');
});

// --------------------------------------------------------------- retention

Harness::test('prune keeps the newest and removes the rest', function (): void {
    clear_backups();

    // Three files with distinct, ordered timestamps in their names.
    $dir = Backup::directory();
    $names = [
        'castle-crm-20260101-010000.sql',
        'castle-crm-20260102-010000.sql',
        'castle-crm-20260103-010000.sql',
    ];

    foreach ($names as $i => $name) {
        file_put_contents($dir . '/' . $name, '-- test ' . $i . "\n");
        touch($dir . '/' . $name, 1_767_000_000 + ($i * 86400));
    }

    Assert::count(3, Backup::all());

    $removed = Backup::prune(2);

    Assert::same(1, $removed);
    Assert::count(2, Backup::all());

    $remaining = array_column(Backup::all(), 'filename');

    Assert::inArray('castle-crm-20260103-010000.sql', $remaining, 'the newest must survive');
    Assert::false(
        in_array('castle-crm-20260101-010000.sql', $remaining, true),
        'the oldest should have gone'
    );

    clear_backups();
});

Harness::test('prune never deletes the only backup, whatever it is asked', function (): void {
    clear_backups();

    $dir = Backup::directory();
    file_put_contents($dir . '/castle-crm-20260104-010000.sql', "-- only one\n");

    Assert::same(0, Backup::prune(0), 'keep=0 must still be treated as keep=1');
    Assert::count(1, Backup::all());

    clear_backups();
});

Harness::test('listing ignores a half-written .part file', function (): void {
    clear_backups();

    $dir = Backup::directory();
    file_put_contents($dir . '/castle-crm-20260105-010000.sql.part', '-- interrupted');

    Assert::count(0, Backup::all(), 'an interrupted run must not look like a backup');

    unlink($dir . '/castle-crm-20260105-010000.sql.part');
});

// -------------------------------------------------------------- CSV export

Harness::test('a table exports as CSV with a header row and a BOM', function (): void {
    $id = test_make_contact(['first_name' => 'Export', 'last_name' => 'Test']);

    $handle = fopen('php://temp', 'r+');
    $rows = Backup::exportCsv('contacts', $handle);
    rewind($handle);
    $csv = (string) stream_get_contents($handle);
    fclose($handle);

    Assert::greaterThan(0, $rows);
    Assert::true(str_starts_with($csv, "\xEF\xBB\xBF"), 'without a BOM Excel mangles every accented name');
    Assert::contains('first_name', $csv, 'the header row names the columns');
    Assert::contains('Export', $csv);

    DB::delete('contacts', 'id = :id', ['id' => $id]);
});

Harness::test('export refuses a table that is not on the allowlist', function (): void {
    $handle = fopen('php://temp', 'r+');

    try {
        Assert::throws(
            RuntimeException::class,
            static fn () => Backup::exportCsv('sessions', $handle),
            'live session payloads do not belong in a spreadsheet'
        );

        Assert::throws(
            RuntimeException::class,
            static fn () => Backup::exportCsv('api_tokens', $handle),
            'neither do credentials'
        );

        Assert::throws(
            RuntimeException::class,
            static fn () => Backup::exportCsv('users; DROP TABLE cc_users', $handle)
        );
    } finally {
        fclose($handle);
    }
});

Harness::test('the exportable allowlist names only tables that exist', function (): void {
    $actual = Backup::tables();

    foreach (Backup::exportableTables() as $table) {
        Assert::inArray(
            DB::prefix() . $table,
            $actual,
            $table . ' is offered for export but is not in the schema'
        );
    }
});

// ------------------------------------------------- formula injection (CSV)

Harness::test('exported cells cannot execute as spreadsheet formulas', function (): void {
    // The Backups page tells the operator that "any cell beginning = or + [is]
    // prefixed with an apostrophe, so a guest's 'name' cannot execute as a
    // formula on a staff machine". It was not true of this path: the guard
    // lived in Response::csv() (the audit export) and Backup::exportCsv()
    // wrote raw values. A guest types their own first name on a booking form,
    // an owner opens the download, and Excel runs it.
    $payloads = [
        '=HYPERLINK("http://evil.test","Click")',
        '+1+1',
        '-2+3',
        '@SUM(A1:A9)',
        "\tleading tab",
        "\rleading cr",
    ];

    $ids = [];

    foreach ($payloads as $i => $payload) {
        $ids[] = test_make_contact(['first_name' => $payload, 'last_name' => 'Injected' . $i]);
    }

    $handle = fopen('php://temp', 'r+');
    Backup::exportCsv('contacts', $handle);
    rewind($handle);
    $csv = (string) stream_get_contents($handle);
    fclose($handle);

    foreach ($payloads as $payload) {
        // fputcsv doubles inner quotes and wraps the field, so compare against
        // the CSV-encoded form rather than the raw string.
        $expected = str_replace('"', '""', "'" . $payload);

        Assert::contains(
            $expected,
            $csv,
            'this cell must be prefixed with an apostrophe: ' . addcslashes($payload, "\t\r")
        );
    }

    // And the raw form must not appear at the start of a field.
    Assert::false(
        (bool) preg_match('/(^|,)"?=HYPERLINK/m', $csv),
        'an unneutralised formula reached the file'
    );

    foreach ($ids as $id) {
        DB::delete('contacts', 'id = :id', ['id' => $id]);
    }
});

Harness::test('a harmless value is not mangled by the guard', function (): void {
    // The other half: the guard must not rewrite ordinary data. A phone number
    // stored as "+18455550118" legitimately starts with +, so it gains the
    // apostrophe — that is the documented trade — but a plain name must not.
    $id = test_make_contact(['first_name' => 'Zoë', 'last_name' => "O'Brien"]);

    $handle = fopen('php://temp', 'r+');
    Backup::exportCsv('contacts', $handle);
    rewind($handle);
    $csv = (string) stream_get_contents($handle);
    fclose($handle);

    Assert::contains('Zoë', $csv);
    Assert::notContains("'Zoë", $csv, 'a safe value must be written unchanged');

    DB::delete('contacts', 'id = :id', ['id' => $id]);
});

// ----------------------------------------------------------- concurrency

Harness::test('a second concurrent backup is refused rather than interleaved', function (): void {
    // Two runs starting in the same second computed the same .part path and
    // both opened it with a truncating mode, so their streams interleaved and
    // the first rename() published the splice as a good backup — corrupt from
    // that point, discoverable only during a restore.
    clear_backups();

    $lockPath = Backup::directory() . '/.backup.lock';
    $held = fopen($lockPath, 'c');

    Assert::notSame(false, $held, 'should be able to open the lock file');
    Assert::true(flock($held, LOCK_EX | LOCK_NB), 'the test should get the lock first');

    try {
        $result = Backup::create(['reason' => 'test']);

        Assert::false($result['ok'], 'a backup must not start while another holds the lock');
        Assert::contains('already running', strtolower((string) $result['error']));
        Assert::count(0, Backup::all(), 'and nothing should have been published');
    } finally {
        flock($held, LOCK_UN);
        fclose($held);
    }

    // With the lock free again it works, proving the guard is not stuck on.
    $after = Backup::create(['reason' => 'test']);

    Assert::true($after['ok'], (string) $after['error']);

    clear_backups();
});

Harness::test('each run writes a uniquely named temporary file', function (): void {
    clear_backups();

    $result = Backup::create(['reason' => 'test']);

    Assert::true($result['ok'], (string) $result['error']);
    Assert::count(
        0,
        (array) glob(Backup::directory() . '/*.part*'),
        'the temporary file must be gone once the backup is published'
    );

    clear_backups();
});

Harness::test('two backups in the same second both survive', function (): void {
    // The published name carries a second-resolution timestamp. An earlier fix
    // for concurrent writes refused the second run outright, which broke a
    // legitimate "back up, change something, back up again". Neither file may
    // be lost and neither may be overwritten.
    clear_backups();

    $first = Backup::create(['reason' => 'test']);
    $second = Backup::create(['reason' => 'test']);

    Assert::true($first['ok'], (string) $first['error']);
    Assert::true($second['ok'], (string) $second['error']);
    Assert::notSame($first['filename'], $second['filename'], 'the names must differ');
    Assert::count(2, Backup::all(), 'both must be on disk');

    foreach ([$first, $second] as $result) {
        $path = Backup::pathFor((string) $result['filename']);

        Assert::notNull($path, 'each must be a listable, downloadable backup');
        Assert::greaterThan(1000, (int) filesize((string) $path));
    }

    clear_backups();
});
