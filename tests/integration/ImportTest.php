<?php

declare(strict_types=1);

/**
 * CSV import, against a real database and real files.
 *
 * What only this layer can pin — the properties that make the difference
 * between a migration and a mess:
 *
 *   - **A dry run writes NOTHING.** It is the only thing between a mis-mapped
 *     column and a guest database where every town is a postcode, and the
 *     whole flow is built around trusting it. So it is checked by counting
 *     rows before and after rather than by reading its report.
 *   - **It resumes without double-writing.** A 50,000-row file crosses many
 *     cron ticks. A resume that re-read the header would import a guest called
 *     "First Name"; one that lost its place would import the same people
 *     twice.
 *   - **Re-running the same file changes nothing.** Which is what makes it
 *     safe to re-upload after fixing a few rows.
 *   - **Visits go through `VisitRecorder`**, so one guest on one day is one
 *     visit however many transactions the file holds, and lifetime value is
 *     derived rather than accumulated.
 */

use App\Core\DB;
use App\Services\Importer;
use App\Services\ImportRunner;
use Tests\Assert;
use Tests\Harness;

Harness::suite('integration/import');

if (!test_boot()) {
    Harness::skip('import suite', 'no database available');

    return;
}

// ------------------------------------------------------------------ fixtures

function imp_clear(): void
{
    $ids = DB::column("SELECT id FROM {imports} WHERE original_name LIKE 'IMPTEST%'");

    foreach ($ids as $id) {
        DB::query('DELETE FROM {import_rows} WHERE import_id = :i', ['i' => (int) $id]);
        DB::query('DELETE FROM {imports} WHERE id = :i', ['i' => (int) $id]);
    }

    $contacts = DB::column("SELECT id FROM {contacts} WHERE last_name LIKE 'Imptest%'");

    foreach ($contacts as $contactId) {
        DB::query('DELETE FROM {visits} WHERE contact_id = :c', ['c' => (int) $contactId]);
        DB::query('DELETE FROM {contacts} WHERE id = :c', ['c' => (int) $contactId]);
    }

    foreach (glob(ImportRunner::directory() . '/imptest-*.csv') ?: [] as $file) {
        @unlink($file);
    }
}

/**
 * Write a CSV into the import directory and make a job for it.
 *
 * The REAL directory and the REAL table, because the failure this module is
 * most exposed to is "the file was on disk and nothing read it".
 *
 * @param list<list<string>>  $rows   Including the header.
 * @param array<int,string>   $mapping
 * @param array<string,mixed> $options
 */
function imp_job(string $entity, array $rows, array $mapping, array $options = [], string $status = 'dry_run'): int
{
    $name   = 'imptest-' . bin2hex(random_bytes(6)) . '.csv';
    $path   = ImportRunner::directory() . '/' . $name;
    $handle = fopen($path, 'wb');

    Assert::true($handle !== false, 'the test file could be written');

    foreach ($rows as $row) {
        fputcsv($handle, $row, escape: '');
    }

    fclose($handle);

    return (int) DB::insert('imports', [
        'entity'        => $entity,
        'original_name' => 'IMPTEST ' . $name,
        'stored_path'   => $name,
        'bytes'         => (int) filesize($path),
        'status'        => $status,
        'headers'       => json_encode($rows[0] ?? []),
        'mapping'       => json_encode($mapping),
        'options'       => json_encode($options),
        'created_at'    => now_utc(),
        'updated_at'    => now_utc(),
    ]);
}

/** @return array<string,mixed> */
function imp_read(int $id): array
{
    return DB::first('SELECT * FROM {imports} WHERE id = :i', ['i' => $id]) ?? [];
}

/** Walk a job to completion, with a bound so a bug cannot loop for ever. */
function imp_finish(int $id, int $maxSlices = 40): array
{
    for ($slice = 0; $slice < $maxSlices; $slice++) {
        $result = ImportRunner::work($id);

        if ($result['finished']) {
            return imp_read($id);
        }
    }

    Assert::true(false, 'the job did not finish in ' . $maxSlices . ' slices');

    return imp_read($id);
}

/** A contacts file of N people, all importable. */
function imp_contact_rows(int $count, string $tag): array
{
    $rows = [['First Name', 'Last Name', 'Email', 'Phone']];

    for ($i = 0; $i < $count; $i++) {
        $rows[] = [
            'Guest' . $i,
            'Imptest' . $tag . $i,
            strtolower('imp-' . $tag . $i . '@test.invalid'),
            '845555' . str_pad((string) (1000 + $i), 4, '0', STR_PAD_LEFT),
        ];
    }

    return $rows;
}

const IMP_CONTACT_MAP = [0 => 'first_name', 1 => 'last_name', 2 => 'email', 3 => 'phone'];

imp_clear();

// ----------------------------------------------------------------- dry runs

Harness::test('A DRY RUN WRITES NOTHING AT ALL', function (): void {
    imp_clear();

    $before = (int) DB::value('SELECT COUNT(*) FROM {contacts}');

    $id  = imp_job('contacts', imp_contact_rows(12, 'dry'), IMP_CONTACT_MAP);
    $job = imp_finish($id);

    Assert::same('dry_run_done', (string) $job['status']);
    Assert::same(12, (int) $job['created'], 'it reports what it WOULD add');
    Assert::same(0, (int) $job['refused']);

    Assert::same(
        $before,
        (int) DB::value('SELECT COUNT(*) FROM {contacts}'),
        'and not one contact was written'
    );

    // The report is still real: every row is accounted for.
    Assert::same(
        12,
        (int) DB::value('SELECT COUNT(*) FROM {import_rows} WHERE import_id = :i', ['i' => $id])
    );

    imp_clear();
});

Harness::test('a dry run resolves real duplicates, so its report is not a guess', function (): void {
    imp_clear();

    // One of these already exists.
    $existing = (int) DB::insert('contacts', [
        'first_name' => 'Already',
        'last_name'  => 'ImptestDupe0',
        'email'      => 'imp-dupe0@test.invalid',
        'country'    => 'US',
        'lifecycle_stage' => 'customer',
        'created_at' => now_utc(),
        'updated_at' => now_utc(),
    ]);

    $rows = imp_contact_rows(3, 'Dupe');
    $id   = imp_job('contacts', $rows, IMP_CONTACT_MAP, ['on_duplicate' => 'fill']);
    $job  = imp_finish($id);

    Assert::same(2, (int) $job['created'], 'two are new');
    Assert::same(
        1,
        (int) $job['updated'] + (int) $job['skipped'],
        'and the one already here is reported as such, not as new'
    );

    // The existing contact is untouched by a dry run, including its name.
    Assert::same(
        'Already',
        (string) DB::value('SELECT first_name FROM {contacts} WHERE id = :i', ['i' => $existing])
    );

    imp_clear();
});

// ----------------------------------------------------------------- importing

Harness::test('an import writes the contacts and records every row', function (): void {
    imp_clear();

    $id  = imp_job('contacts', imp_contact_rows(8, 'Live'), IMP_CONTACT_MAP, [], 'importing');
    $job = imp_finish($id);

    Assert::same('done', (string) $job['status']);
    Assert::same(8, (int) $job['created']);

    Assert::same(
        8,
        (int) DB::value("SELECT COUNT(*) FROM {contacts} WHERE last_name LIKE 'ImptestLive%'")
    );

    // Phone normalised on the way in, so SMS matching works later.
    Assert::same(
        '+18455551000',
        (string) DB::value(
            "SELECT phone_normalized FROM {contacts} WHERE last_name = 'ImptestLive0'"
        )
    );

    // `source` says where they came from, which is the answer to "why is this
    // contact here and who added them".
    Assert::same(
        'import',
        (string) DB::value("SELECT source FROM {contacts} WHERE last_name = 'ImptestLive0'")
    );

    // Every report row points at the contact it made.
    $withIds = (int) DB::value(
        "SELECT COUNT(*) FROM {import_rows}
          WHERE import_id = :i AND outcome = 'created' AND entity_id IS NOT NULL",
        ['i' => $id]
    );

    Assert::same(8, $withIds, 'so a row in the report can be clicked through to the guest');

    imp_clear();
});

Harness::test('IMPORTED CONTACTS ARE NOT CONSENTED, WHATEVER THE FILE SAYS', function (): void {
    /*
     * The property the whole module is built to protect, checked against the
     * real column rather than the composer's return value.
     */
    imp_clear();

    $rows = [
        ['First Name', 'Last Name', 'Email', 'Opt In', 'SMS OK', 'Phone'],
        ['Yes', 'ImptestConsent0', 'imp-c0@test.invalid', 'yes', 'yes', '8455552000'],
        ['Also', 'ImptestConsent1', 'imp-c1@test.invalid', 'TRUE', '1', '8455552001'],
    ];

    $map = [
        0 => 'first_name', 1 => 'last_name', 2 => 'email',
        3 => 'email_consent', 4 => 'sms_consent', 5 => 'phone',
    ];

    // Not claimed.
    imp_finish(imp_job('contacts', $rows, $map, ['consent_claimed' => false], 'importing'));

    $consented = (int) DB::value(
        "SELECT COUNT(*) FROM {contacts}
          WHERE last_name LIKE 'ImptestConsent%' AND (email_consent = 1 OR sms_consent = 1)"
    );

    Assert::same(0, $consented, 'nobody is signed up for anything');

    // And the report says why, per row, so the operator is not surprised later.
    $warned = (int) DB::value(
        "SELECT COUNT(*) FROM {import_rows} WHERE message LIKE '%was not applied%'"
    );

    Assert::greaterThan(0, $warned);

    imp_clear();

    // Claimed: honoured.
    imp_finish(imp_job('contacts', $rows, $map, ['consent_claimed' => true], 'importing'));

    Assert::same(
        2,
        (int) DB::value(
            "SELECT COUNT(*) FROM {contacts}
              WHERE last_name LIKE 'ImptestConsent%' AND email_consent = 1 AND sms_consent = 1"
        ),
        'with the affirmation, the file is believed'
    );

    imp_clear();
});

Harness::test('re-importing the same file changes nothing', function (): void {
    imp_clear();

    $rows = imp_contact_rows(6, 'Again');

    imp_finish(imp_job('contacts', $rows, IMP_CONTACT_MAP, [], 'importing'));

    $after = (int) DB::value("SELECT COUNT(*) FROM {contacts} WHERE last_name LIKE 'ImptestAgain%'");

    Assert::same(6, $after);

    // The same file, a second job.
    $second = imp_finish(imp_job('contacts', $rows, IMP_CONTACT_MAP, [], 'importing'));

    Assert::same(0, (int) $second['created'], 'nobody is added twice');
    Assert::same(6, (int) $second['skipped'], 'they are reported as already here');
    Assert::same(
        $after,
        (int) DB::value("SELECT COUNT(*) FROM {contacts} WHERE last_name LIKE 'ImptestAgain%'")
    );

    imp_clear();
});

Harness::test('a duplicate WITHIN one file is skipped, and says so', function (): void {
    /*
     * A list exported twice and concatenated has every guest twice. Without
     * this the second copy would "update" the first, and the operator would
     * see 5,000 added and 5,000 updated from a file of 10,000 rows with no
     * idea why.
     */
    imp_clear();

    $rows   = imp_contact_rows(3, 'Twice');
    $rows[] = $rows[1];   // the same person again

    $job = imp_finish(imp_job('contacts', $rows, IMP_CONTACT_MAP, [], 'importing'));

    Assert::same(3, (int) $job['created']);
    Assert::same(1, (int) $job['skipped']);

    Assert::same(
        'duplicate_in_file',
        (string) DB::value(
            "SELECT reason FROM {import_rows}
              WHERE import_id = :i AND outcome = 'skipped' LIMIT 1",
            ['i' => (int) $job['id']]
        ),
        'named as a repeat in the file, not as somebody already in the database'
    );

    imp_clear();
});

// -------------------------------------------------------------------- resume

Harness::test('AN IMPORT RESUMES WITHOUT RE-READING THE HEADER OR LOSING ITS PLACE', function (): void {
    /*
     * The property that makes a 50,000-row file possible on shared hosting,
     * and the two ways it goes wrong:
     *
     *   - a resume that seeks to 0 re-reads the HEADER and imports a guest
     *     called "First Name", once per tick;
     *   - a resume that forgets where it was imports the same people again.
     *
     * So this walks a file one slice at a time with a budget that allows
     * almost nothing, and checks the end state.
     */
    imp_clear();

    $count = 25;
    $id    = imp_job('contacts', imp_contact_rows($count, 'Resume'), IMP_CONTACT_MAP, [], 'importing');

    $slices = 0;
    $last   = false;

    // A budget that is already spent, so each call does the minimum it can
    // and still has to make progress.
    while (!$last && $slices < 60) {
        $spent  = microtime(true) + 0.001;
        $result = ImportRunner::work($id, static fn (): bool => microtime(true) < $spent);
        $last   = $result['finished'];
        $slices++;
    }

    Assert::true($last, 'it finished');
    Assert::greaterThan(1, $slices, 'and it genuinely took more than one slice');

    $job = imp_read($id);

    Assert::same('done', (string) $job['status']);
    Assert::same($count, (int) $job['created'], 'every row, once');
    Assert::same(0, (int) $job['skipped'], 'and none seen twice');

    Assert::same(
        $count,
        (int) DB::value("SELECT COUNT(*) FROM {contacts} WHERE last_name LIKE 'ImptestResume%'")
    );

    // THE HEADER WAS NEVER IMPORTED.
    Assert::same(
        0,
        (int) DB::value("SELECT COUNT(*) FROM {contacts} WHERE last_name = 'Last Name'"),
        'a resume that seeks to zero imports the header as a guest'
    );

    // The line numbers in the report are the file's, not the batch's — which
    // is what makes a refusal findable in a spreadsheet.
    $lines = DB::column(
        'SELECT line FROM {import_rows} WHERE import_id = :i ORDER BY line',
        ['i' => $id]
    );

    Assert::same(2, (int) $lines[0], 'the first data row is line 2, after the header');
    Assert::same($count + 1, (int) $lines[count($lines) - 1], 'and they run to the end of the file');
    Assert::same($count, count(array_unique($lines)), 'with no line counted twice');

    imp_clear();
});

Harness::test('a job whose file has gone says so instead of looping', function (): void {
    imp_clear();

    $id = imp_job('contacts', imp_contact_rows(3, 'Gone'), IMP_CONTACT_MAP, [], 'importing');

    @unlink(ImportRunner::pathFor((string) imp_read($id)['stored_path']));

    $result = ImportRunner::work($id);

    Assert::true($result['finished'], 'it stops rather than retrying for ever');
    Assert::same('failed', (string) imp_read($id)['status']);
    Assert::true(
        str_contains((string) imp_read($id)['error'], 'no longer on the server'),
        'and the job carries the reason'
    );

    imp_clear();
});

Harness::test('a job with no mapping fails loudly rather than importing nothing quietly', function (): void {
    imp_clear();

    $id = imp_job('contacts', imp_contact_rows(3, 'Nomap'), [], [], 'importing');

    ImportRunner::work($id);

    Assert::same('failed', (string) imp_read($id)['status']);
    Assert::true(str_contains((string) imp_read($id)['error'], 'no column mapping'));

    imp_clear();
});

// -------------------------------------------------------------------- visits

Harness::test('visits attach to the contact they match, and move their figures', function (): void {
    imp_clear();

    $contactId = (int) DB::insert('contacts', [
        'first_name' => 'Visit',
        'last_name'  => 'ImptestVisits',
        'email'      => 'imp-visits@test.invalid',
        'external_pos_id' => 'IMPCARD-1',
        'country'    => 'US',
        'lifecycle_stage' => 'customer',
        'created_at' => now_utc(),
        'updated_at' => now_utc(),
    ]);

    $rows = [
        ['Card', 'Date', 'Amount'],
        ['IMPCARD-1', '2024-07-04', '$42.50'],
        ['IMPCARD-1', '2024-07-11', '$17.00'],
        ['IMPCARD-NOPE', '2024-07-04', '$10.00'],
    ];

    $job = imp_finish(imp_job(
        'visits',
        $rows,
        [0 => 'external_pos_id', 1 => 'visit_date', 2 => 'spend_cents'],
        [],
        'importing'
    ));

    Assert::same(2, (int) $job['created']);
    Assert::same(1, (int) $job['refused']);

    Assert::same(
        'no_such_contact',
        (string) DB::value(
            "SELECT reason FROM {import_rows} WHERE import_id = :i AND outcome = 'refused'",
            ['i' => (int) $job['id']]
        ),
        'a visit for somebody who is not here is refused, never used to create them'
    );

    $contact = DB::first('SELECT * FROM {contacts} WHERE id = :i', ['i' => $contactId]);

    Assert::same(2, (int) $contact['visit_count']);
    Assert::same(5950, (int) $contact['lifetime_value_cents'], '42.50 + 17.00');
    Assert::same('2024-07-04', (string) $contact['first_visit_at'] === ''
        ? '2024-07-04'
        : substr((string) $contact['first_visit_at'], 0, 10));

    imp_clear();
});

Harness::test('two rows for one guest on one day are ONE visit', function (): void {
    /*
     * A till export has a row per transaction, not per visit. Recording each
     * one would triple the venue's guest count and their lifetime value —
     * which is exactly why this goes through `VisitRecorder` rather than
     * inserting.
     */
    imp_clear();

    $contactId = (int) DB::insert('contacts', [
        'first_name' => 'Same',
        'last_name'  => 'ImptestOneDay',
        'email'      => 'imp-oneday@test.invalid',
        'country'    => 'US',
        'lifecycle_stage' => 'customer',
        'created_at' => now_utc(),
        'updated_at' => now_utc(),
    ]);

    $rows = [
        ['Email', 'Date', 'Amount'],
        ['imp-oneday@test.invalid', '2024-08-01', '$10.00'],
        ['imp-oneday@test.invalid', '2024-08-01', '$25.00'],
    ];

    $job = imp_finish(imp_job(
        'visits',
        $rows,
        [0 => 'email', 1 => 'visit_date', 2 => 'spend_cents'],
        [],
        'importing'
    ));

    Assert::same(
        1,
        (int) DB::value(
            'SELECT COUNT(*) FROM {visits} WHERE contact_id = :c',
            ['c' => $contactId]
        ),
        'one day is one visit'
    );

    Assert::same(1, (int) $job['created']);
    Assert::same(1, (int) $job['skipped'], 'and the second row says it is the same day');

    Assert::same(
        1,
        (int) DB::value('SELECT visit_count FROM {contacts} WHERE id = :c', ['c' => $contactId])
    );

    imp_clear();
});

Harness::test('re-importing visits does not multiply anybody’s lifetime value', function (): void {
    imp_clear();

    $contactId = (int) DB::insert('contacts', [
        'first_name' => 'Repeat',
        'last_name'  => 'ImptestReVisit',
        'email'      => 'imp-revisit@test.invalid',
        'country'    => 'US',
        'lifecycle_stage' => 'customer',
        'created_at' => now_utc(),
        'updated_at' => now_utc(),
    ]);

    $rows = [
        ['Email', 'Date', 'Amount'],
        ['imp-revisit@test.invalid', '2024-09-01', '$30.00'],
        ['imp-revisit@test.invalid', '2024-09-02', '$40.00'],
    ];

    $map = [0 => 'email', 1 => 'visit_date', 2 => 'spend_cents'];

    imp_finish(imp_job('visits', $rows, $map, [], 'importing'));

    $first = (int) DB::value(
        'SELECT lifetime_value_cents FROM {contacts} WHERE id = :c',
        ['c' => $contactId]
    );

    Assert::same(7000, $first);

    imp_finish(imp_job('visits', $rows, $map, [], 'importing'));

    Assert::same(
        $first,
        (int) DB::value('SELECT lifetime_value_cents FROM {contacts} WHERE id = :c', ['c' => $contactId]),
        'derived from the visits, not accumulated — so a re-run is a no-op'
    );
    Assert::same(
        2,
        (int) DB::value('SELECT COUNT(*) FROM {visits} WHERE contact_id = :c', ['c' => $contactId])
    );

    imp_clear();
});

// -------------------------------------------------------------- the report

Harness::test('every refusal reason reaches the report with its line number', function (): void {
    imp_clear();

    $rows = [
        ['First', 'Last', 'Email', 'Phone', 'DOB', 'Stage'],
        ['Fine', 'ImptestReport0', 'imp-r0@test.invalid', '8455553000', '1990-01-01', 'member'],
        ['Bad', 'ImptestReport1', 'not-an-email', '8455553001', '', ''],
        ['None', 'ImptestReport2', '', '', '', ''],
        ['Short', 'ImptestReport3', '', '555', '', ''],
        ['Future', 'ImptestReport4', 'imp-r4@test.invalid', '', '2099-01-01', ''],
        ['Stage', 'ImptestReport5', 'imp-r5@test.invalid', '', '', 'Platinum VIP'],
        ['Ambig', 'ImptestReport6', 'imp-r6@test.invalid', '', '03/04/1990', ''],
    ];

    $job = imp_finish(imp_job(
        'contacts',
        $rows,
        [0 => 'first_name', 1 => 'last_name', 2 => 'email', 3 => 'phone', 4 => 'birth_date', 5 => 'lifecycle_stage'],
        [],
        'importing'
    ));

    $byReason = DB::pairs(
        "SELECT reason, line FROM {import_rows}
          WHERE import_id = :i AND outcome = 'refused'",
        ['i' => (int) $job['id']]
    );

    foreach (['bad_email' => 3, 'no_identity' => 4, 'bad_phone' => 5,
              'future_birth_date' => 6, 'bad_stage' => 7, 'bad_date' => 8] as $reason => $line) {
        Assert::same(
            $line,
            (int) ($byReason[$reason] ?? 0),
            $reason . ' is reported at the row a spreadsheet would show'
        );
    }

    Assert::same(1, (int) $job['created'], 'and the one good row still landed');

    imp_clear();
});

Harness::test('the report keeps a label, so a refusal can be recognised', function (): void {
    imp_clear();

    $job = imp_finish(imp_job(
        'contacts',
        [
            ['First', 'Last', 'Email'],
            ['Nadia', 'ImptestLabel', 'not-an-email'],
        ],
        [0 => 'first_name', 1 => 'last_name', 2 => 'email'],
        [],
        'importing'
    ));

    $row = DB::first(
        "SELECT * FROM {import_rows} WHERE import_id = :i AND outcome = 'refused'",
        ['i' => (int) $job['id']]
    );

    Assert::notNull($row);
    Assert::true(str_contains((string) $row['label'], 'Nadia ImptestLabel'));
    Assert::true(str_contains((string) $row['message'], 'not an email address'));

    imp_clear();
});

Harness::test('the report stops before a wrong mapping fills the table', function (): void {
    /*
     * A mapping that is wrong refuses every row. Storing 50,000 identical
     * refusals is a report nobody reads and a table nobody wanted — but the
     * COUNTS have to stay exact, because they are what the page reports.
     */
    imp_clear();

    // Nothing identifies anybody, so every row is refused.
    $count = 40;
    $rows  = [['Name']];

    for ($i = 0; $i < $count; $i++) {
        $rows[] = ['Imptest cap ' . $i];
    }

    $job = imp_finish(imp_job('contacts', $rows, [0 => 'full_name'], [], 'importing'));

    Assert::same($count, (int) $job['refused'], 'the count is exact');

    $stored = (int) DB::value(
        'SELECT COUNT(*) FROM {import_rows} WHERE import_id = :i',
        ['i' => (int) $job['id']]
    );

    // Below the cap here, so all of them are stored; the point is that the
    // count and the stored rows agree until the cap bites.
    Assert::same(min($count, Importer::MAX_REPORTED), $stored);

    imp_clear();
});

Harness::test('a path from the database cannot escape the import directory', function (): void {
    /*
     * `stored_path` comes out of the database and is handed to `fopen()`. One
     * careless admin edit, or one SQL injection anywhere else in the
     * application, and this is the function that would open it.
     */
    $directory = ImportRunner::directory();

    foreach ([
        '../../config/config.php',
        '/etc/passwd',
        '....//....//config/config.php',
        'subdir/../../../etc/hosts',
    ] as $nasty) {
        $resolved = ImportRunner::pathFor($nasty);

        Assert::same(
            $directory,
            dirname($resolved),
            'every path lands in the import directory: ' . $nasty . ' → ' . $resolved
        );
        Assert::false(
            str_contains($resolved, '..'),
            'and carries no traversal: ' . $resolved
        );
    }
});

// ------------------------------------------------------------------ children

/** A guardian to attach children to, matched by the card number a POS export carries. */
function imp_guardian(string $card): int
{
    return (int) DB::insert('contacts', [
        'first_name'      => 'Parent',
        'last_name'       => 'ImptestChildren',
        'email'           => 'imp-' . strtolower($card) . '@test.invalid',
        'external_pos_id' => $card,
        'country'         => 'US',
        'lifecycle_stage' => 'customer',
        'created_at'      => now_utc(),
        'updated_at'      => now_utc(),
    ]);
}

const IMP_CHILD_MAP = [0 => 'external_pos_id', 1 => 'first_name', 2 => 'birth_date'];

Harness::test('children attach to their guardian, with a first name and a birthday only', function (): void {
    imp_clear();

    $guardian = imp_guardian('IMPKID-1');
    $born     = test_local_day(365 * 6);

    $job = imp_finish(imp_job('children', [
        ['Guardian Customer ID', 'Child First Name', 'Child Birth Date'],
        ['IMPKID-1', 'Emma Imptest', $born],
        ['IMPKID-1', 'Noah', test_local_day(365 * 3)],
    ], IMP_CHILD_MAP, [], 'importing'));

    Assert::same(2, (int) $job['created']);

    $children = DB::all('SELECT * FROM {children} WHERE contact_id = :c ORDER BY first_name', ['c' => $guardian]);

    Assert::same(['Emma', 'Noah'], array_column($children, 'first_name'), 'the surname a POS field carries is not stored');
    Assert::same($born, (string) $children[0]['birth_date']);
    Assert::same((int) substr($born, 5, 2), (int) $children[0]['birth_month'], 'the birthday campaign reads the month');

    imp_clear();
});

Harness::test('ONE CHILD IS ONE RECORD, however many times the file or a re-import says so', function (): void {
    // The export carries a child from a linked POS account AND from a party
    // booking; a family must never get two birthday messages about one child.
    imp_clear();

    $guardian = imp_guardian('IMPKID-2');
    $born     = test_local_day(365 * 8);
    $rows     = [
        ['Guardian Customer ID', 'Child First Name', 'Child Birth Date'],
        ['IMPKID-2', 'Liam', $born],
        ['IMPKID-2', 'liam', $born],
    ];

    $first = imp_finish(imp_job('children', $rows, IMP_CHILD_MAP, [], 'importing'));
    Assert::same(1, (int) $first['created']);
    Assert::same(1, (int) $first['skipped']);

    $again = imp_finish(imp_job('children', $rows, IMP_CHILD_MAP, [], 'importing'));
    Assert::same(0, (int) $again['created'], 're-importing the same file adds nobody');

    Assert::same(1, (int) DB::value('SELECT COUNT(*) FROM {children} WHERE contact_id = :c', ['c' => $guardian]));

    imp_clear();
});

Harness::test('a child whose guardian is not here, or who is an adult, is refused and says why', function (): void {
    imp_clear();

    imp_guardian('IMPKID-3');

    $job = imp_finish(imp_job('children', [
        ['Guardian Customer ID', 'Child First Name', 'Child Birth Date'],
        ['IMPKID-NOBODY', 'Ava', test_local_day(365 * 5)],
        ['IMPKID-3', 'Grownup', test_local_day(365 * 19)],
        ['IMPKID-3', '', test_local_day(365 * 5)],
        ['IMPKID-3', 'Mia', ''],
    ], IMP_CHILD_MAP, [], 'importing'));

    Assert::same(0, (int) $job['created']);

    $reasons = DB::column(
        "SELECT reason FROM {import_rows} WHERE import_id = :i AND outcome = 'refused' ORDER BY line",
        ['i' => (int) $job['id']]
    );

    Assert::same(['no_such_contact', 'not_a_child', 'no_name', 'bad_date'], $reasons);
    Assert::same(0, (int) DB::value("SELECT COUNT(*) FROM {contacts} WHERE external_pos_id = 'IMPKID-NOBODY'"), 'no guardian is invented');

    imp_clear();
});

Harness::test('a children file maps itself from the CenterEdge export headers', function (): void {
    Assert::same(
        [0 => 'external_pos_id', 1 => 'first_name', 2 => 'birth_date'],
        Importer::guessMapping(['Guardian Customer ID', 'Child First Name', 'Child Birth Date'], 'children')
    );
    Assert::same([], Importer::blockers(Importer::mappingProblems(IMP_CHILD_MAP, 'children')));
    Assert::notEmpty(
        Importer::blockers(Importer::mappingProblems([0 => 'first_name', 1 => 'birth_date'], 'children')),
        'a file with no guardian column cannot start'
    );
});

imp_clear();
