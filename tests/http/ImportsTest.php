<?php

declare(strict_types=1);

/**
 * The import screens, over HTTP.
 *
 * What only this layer can pin:
 *
 *   - **A commit without a dry run is REFUSED.** The dry run is the only thing
 *     between a mis-mapped column and a guest database where every town is a
 *     postcode. The button is not drawn before a check has run, but a hidden
 *     button is not a guard — the POST has to refuse too.
 *   - **`backup_manage` guards all seven endpoints.** Importing is the mirror
 *     image of exporting: one takes the guest database out of the building,
 *     the other puts a file of unknown provenance into it with consent claims
 *     attached.
 *   - **A file that is not a CSV never lands on the disk.** Checked from the
 *     temporary upload, before anything is stored.
 *   - **The consent affirmation travels through the real form**, and its
 *     absence means nobody is signed up for anything.
 *   - **The refusals download as a CSV** with the row numbers a spreadsheet
 *     shows, which is the whole point of the reporting.
 */

use App\Core\Auth;
use App\Core\DB;
use App\Services\Importer;
use App\Services\ImportRunner;
use Tests\Assert;
use Tests\Harness;

Harness::suite('http/imports');

$baseUrl = getenv('CC_TEST_BASE_URL') ?: '';

if ($baseUrl === '') {
    Harness::skip('imports suite', 'no server; run via tests/run.php');

    return;
}

if (!test_boot()) {
    Harness::skip('imports suite', 'no database available');

    return;
}

require_once CC_ROOT . '/tests/TestBrowser.php';

// ------------------------------------------------------------------ fixtures

/*
 * DATA ONLY — the roles and accounts below are signed-in sessions for the
 * whole file, and a clear that removed them would log the permission tests out
 * and their 302s would read as a product bug.
 */
function hi_clear(): void
{
    $ids = DB::column("SELECT id FROM {imports} WHERE original_name LIKE 'hi-%'");

    foreach ($ids as $id) {
        DB::query('DELETE FROM {import_rows} WHERE import_id = :i', ['i' => (int) $id]);
        DB::query('DELETE FROM {imports} WHERE id = :i', ['i' => (int) $id]);
    }

    $contacts = DB::column("SELECT id FROM {contacts} WHERE last_name LIKE 'Hitest%'");

    foreach ($contacts as $contactId) {
        DB::query('DELETE FROM {visits} WHERE contact_id = :c', ['c' => (int) $contactId]);
        DB::query('DELETE FROM {contacts} WHERE id = :c', ['c' => (int) $contactId]);
    }

    foreach (glob(ImportRunner::directory() . '/import-*.csv') ?: [] as $file) {
        // Only the ones this suite's uploads made: they are the only files in
        // there during a test run, and leaving them accumulates.
        @unlink($file);
    }
}

function hi_clear_roles(): void
{
    $roleIds = DB::column("SELECT id FROM {roles} WHERE slug LIKE 'hitest-%'");

    foreach ($roleIds as $roleId) {
        DB::query('DELETE FROM {users} WHERE role_id = :r', ['r' => (int) $roleId]);
        DB::query('DELETE FROM {role_permissions} WHERE role_id = :r', ['r' => (int) $roleId]);
        DB::query('DELETE FROM {roles} WHERE id = :r', ['r' => (int) $roleId]);
    }
}

/** @param array<string,string> $permissions */
function hi_role(string $slug, array $permissions): int
{
    $roleId = (int) DB::insert('roles', [
        'slug'        => 'hitest-' . $slug,
        'name'        => 'HI ' . ucfirst($slug),
        'description' => 'Created by the imports test suite.',
        'is_system'   => 0,
        'sort_order'  => 220,
        'created_at'  => now_utc(),
        'updated_at'  => now_utc(),
    ]);

    foreach ($permissions as $permissionSlug => $scope) {
        $permissionId = (int) DB::value(
            'SELECT id FROM {permissions} WHERE slug = :s',
            ['s' => $permissionSlug]
        );

        Assert::greaterThan(0, $permissionId, 'permission should be seeded: ' . $permissionSlug);

        DB::insert('role_permissions', [
            'role_id'       => $roleId,
            'permission_id' => $permissionId,
            'scope'         => $scope,
        ]);
    }

    return $roleId;
}

/** @return array{email:string,password:string} */
function hi_account(int $roleId, string $label): array
{
    $email    = 'hi-' . $label . '-' . bin2hex(random_bytes(3)) . '@test.invalid';
    $password = 'imports-suite-passphrase-9';

    DB::insert('users', [
        'name'          => 'HI ' . ucfirst($label),
        'email'         => $email,
        'password_hash' => Auth::hash($password),
        'role_id'       => $roleId,
        'is_active'     => 1,
        'created_at'    => now_utc(),
        'updated_at'    => now_utc(),
    ]);

    return ['email' => $email, 'password' => $password];
}

/** A CSV on disk in the scratch area, for uploading. */
function hi_file(string $name, string $contents): string
{
    $path = sys_get_temp_dir() . '/hi-' . bin2hex(random_bytes(4)) . '-' . $name;
    file_put_contents($path, $contents);

    return $path;
}

/** The guest file this suite uploads, as text. */
function hi_guest_csv(int $count = 4, string $tag = 'A'): string
{
    $lines = ['First Name,Last Name,Email,Phone,Opt In'];

    for ($i = 0; $i < $count; $i++) {
        $lines[] = 'Guest' . $i . ',Hitest' . $tag . $i . ','
            . 'hi-' . strtolower($tag) . $i . '@test.invalid,'
            . '845555' . str_pad((string) (4000 + $i), 4, '0', STR_PAD_LEFT) . ',yes';
    }

    return implode("\n", $lines) . "\n";
}

/**
 * Upload a file and return the job id.
 *
 * A multipart POST, because that is the only shape this route accepts and the
 * `$_FILES` handling is part of what is being tested.
 */
function hi_upload(TestBrowser $browser, string $baseUrl, string $path, string $entity = 'contacts'): array
{
    $token = $browser->csrfToken('/imports');

    $command = 'curl -s -o /dev/null -w "%{http_code} %{redirect_url}" '
        . '--noproxy "*" '
        . '-H ' . escapeshellarg('Cookie: ' . $browser->cookieHeader()) . ' '
        . '-F ' . escapeshellarg('_token=' . $token) . ' '
        . '-F ' . escapeshellarg('entity=' . $entity) . ' '
        . '-F ' . escapeshellarg('file=@' . $path . ';type=text/csv') . ' '
        . escapeshellarg($baseUrl . '/imports');

    $output = trim((string) shell_exec($command));
    [$status, $location] = array_pad(explode(' ', $output, 2), 2, '');

    $id = 0;

    if (preg_match('#/imports/(\d+)#', (string) $location, $m) === 1) {
        $id = (int) $m[1];
    }

    return ['status' => (int) $status, 'location' => (string) $location, 'id' => $id];
}

/**
 * A job and its file, written directly.
 *
 * **FOR THE TESTS THAT ARE NOT TESTING THE UPLOAD.** `POST /imports` is
 * throttled, as it must be — it writes a file to the server's disk — and a
 * suite that went through it for every fixture spent the quota and then failed
 * with 404s that read exactly like a product bug. (MEASURED: that is what
 * happened.) The upload path has its own tests; everything else builds its job
 * here.
 */
function hi_job(string $entity, string $csv, array $mapping, string $status = 'mapping'): int
{
    $name = 'import-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.csv';
    file_put_contents(ImportRunner::directory() . '/' . $name, $csv);

    $header = str_getcsv((string) strstr($csv, "\n", true), escape: '');

    return (int) DB::insert('imports', [
        'entity'        => $entity,
        'original_name' => 'hi-' . $entity . '.csv',
        'stored_path'   => $name,
        'bytes'         => strlen($csv),
        'status'        => $status,
        'headers'       => json_encode($header),
        'mapping'       => json_encode($mapping),
        'created_by'    => (int) DB::value("SELECT id FROM {users} WHERE email = 'owner@test.invalid'"),
        'created_at'    => now_utc(),
        'updated_at'    => now_utc(),
    ]);
}

hi_clear();
hi_clear_roles();

$owner = new TestBrowser($baseUrl, 'imports-owner');
$owner->login('owner@test.invalid', 'owner-test-passphrase-9');

// `contacts_manage` but NOT `backup_manage`: the profile that can edit one
// guest and must not be able to load five thousand.
$editorRole = hi_role('editor', ['contacts_manage' => 'all', 'view_contacts' => 'all']);
$editorUser = hi_account($editorRole, 'editor');
$editor     = new TestBrowser($baseUrl, 'imports-editor');
$editor->login($editorUser['email'], $editorUser['password']);

$desk = new TestBrowser($baseUrl, 'imports-desk');
$desk->login('desk@test.invalid', 'desk-test-passphrase-9');

Harness::test('the fixtures signed in', function () use ($owner, $editor, $desk): void {
    Assert::same(200, $owner->get('/account')['status']);
    Assert::same(200, $editor->get('/account')['status']);
    Assert::same(200, $desk->get('/account')['status']);
});

// --------------------------------------------------------------------- gates

Harness::test('every import endpoint needs backup_manage', function () use ($editor, $desk, $baseUrl): void {
    /*
     * `contacts_manage` is not enough, deliberately. Editing one guest is a
     * different decision from loading a file of five thousand with consent
     * claims attached — and from writing a file to the server's disk.
     */
    hi_clear();

    $id = (int) DB::insert('imports', [
        'entity'        => 'contacts',
        'original_name' => 'hi-gate.csv',
        'stored_path'   => 'hi-gate.csv',
        'bytes'         => 10,
        'status'        => 'mapping',
        'headers'       => '["Email"]',
        'mapping'       => '{"0":"email"}',
        'created_at'    => now_utc(),
        'updated_at'    => now_utc(),
    ]);

    foreach ([$editor, $desk] as $browser) {
        foreach (['/imports', '/imports/' . $id, '/imports/' . $id . '/export'] as $path) {
            $response = $browser->get($path);

            Assert::true(
                in_array($response['status'], [302, 403], true),
                $path . ' is closed: got ' . $response['status']
            );
        }

        foreach (['/imports', '/imports/' . $id . '/start',
                  '/imports/' . $id . '/cancel', '/imports/' . $id . '/delete'] as $path) {
            $response = $browser->postWithToken('/account', $path, ['entity' => 'contacts']);

            Assert::true(
                in_array($response['status'], [302, 403], true),
                $path . ' is closed to writes: got ' . $response['status']
            );
        }
    }

    // And an upload from them creates nothing.
    $before = (int) DB::value('SELECT COUNT(*) FROM {imports}');
    hi_upload($editor, $baseUrl, hi_file('sneak.csv', hi_guest_csv(1, 'Sneak')));

    Assert::same(
        $before,
        (int) DB::value('SELECT COUNT(*) FROM {imports}'),
        'no job was created by a role without the permission'
    );

    hi_clear();
});

Harness::test('the page renders, says what it will not do, and links from settings', function () use ($owner): void {
    hi_clear();

    $page = $owner->get('/imports');

    Assert::same(200, $page['status']);
    Assert::false(str_contains($page['body'], 'Warning:'), 'no PHP warnings');
    Assert::false(str_contains($page['body'], 'Notice:'));

    // The two promises the page has to make before anybody uploads anything.
    Assert::true(
        str_contains($page['body'], 'not signed up for anything'),
        'it says imported guests are not consented'
    );
    Assert::true(
        str_contains($page['body'], 'Nothing is written until'),
        'and that nothing is written until the check has run'
    );

    $settings = $owner->get('/settings');

    Assert::same(200, $settings['status']);
    Assert::true(str_contains($settings['body'], '/imports'), 'the settings tile links it');
});

// ------------------------------------------------------------- bad uploads

Harness::test('A FILE THAT IS NOT A CSV NEVER LANDS ON THE DISK', function () use ($owner, $baseUrl): void {
    hi_clear();

    $before = count(glob(ImportRunner::directory() . '/*.csv') ?: []);

    // A binary blob. MEASURED on a live walk: this was accepted before the
    // header check existed, and sat on the mapping screen with one column
    // named by a control character.
    $binary = hi_file('photo.csv', "\x00\x01\x02\xFF\xFE\x00binary");
    $result = hi_upload($owner, $baseUrl, $binary);

    Assert::same(302, $result['status']);
    Assert::same(0, $result['id'], 'it did not reach a mapping screen');

    // A file with values but no headings: every row would be misread,
    // including the first, which would quietly become a guest.
    $noHeadings = hi_file('nohead.csv', "1,2,3\n4,5,6\n");

    Assert::same(0, hi_upload($owner, $baseUrl, $noHeadings)['id']);

    // Empty.
    Assert::same(0, hi_upload($owner, $baseUrl, hi_file('empty.csv', ''))['id']);

    Assert::same(
        0,
        (int) DB::value('SELECT COUNT(*) FROM {imports}'),
        'not one job was created'
    );
    Assert::same(
        $before,
        count(glob(ImportRunner::directory() . '/*.csv') ?: []),
        'and nothing was stored'
    );

    // The reason is on the page for the operator to read.
    $page = $owner->get('/imports');

    Assert::true(
        str_contains($page['body'], 'not look like a text file')
            || str_contains($page['body'], 'no column names')
            || str_contains($page['body'], 'empty'),
        'with a reason somebody can act on'
    );

    hi_clear();
});

// ---------------------------------------------------------------- the flow

Harness::test('uploading guesses the mapping and offers no import button yet', function () use ($owner, $baseUrl): void {
    hi_clear();

    $result = hi_upload($owner, $baseUrl, hi_file('guests.csv', hi_guest_csv(4, 'Flow')));

    Assert::same(302, $result['status']);
    Assert::greaterThan(0, $result['id'], 'it redirects to the job');

    $job = DB::first('SELECT * FROM {imports} WHERE id = :i', ['i' => $result['id']]);

    Assert::notNull($job);
    Assert::same('mapping', (string) $job['status']);
    Assert::greaterThan(0, (int) $job['created_by'], 'who uploaded it is recorded');
    Assert::same(64, strlen((string) $job['checksum']), 'and the file is fingerprinted');

    // The guesses, from the real header row.
    $mapping = ImportRunner::decodeMapping($job['mapping']);

    Assert::same('first_name', $mapping[0] ?? null);
    Assert::same('last_name', $mapping[1] ?? null);
    Assert::same('email', $mapping[2] ?? null);
    Assert::same('phone', $mapping[3] ?? null);
    Assert::same('email_consent', $mapping[4] ?? null);

    $page = $owner->get('/imports/' . $result['id']);

    Assert::same(200, $page['status']);
    Assert::false(str_contains($page['body'], 'Warning:'));
    Assert::true(str_contains($page['body'], 'Which column means what'));

    /*
     * THE IMPORT BUTTON IS NOT DRAWN. A hidden button is not a guard — the
     * next test checks the POST too — but drawing it before a check has run
     * invites exactly the mistake the check exists to prevent.
     */
    Assert::false(
        str_contains($page['body'], 'Import it for real'),
        'no import button before a check'
    );
    Assert::true(str_contains($page['body'], 'Check the file'));

    // And the consent box is unticked.
    Assert::false(
        (bool) preg_match('/name="consent_claimed"[^>]*checked/', $page['body']),
        'the consent affirmation is never ticked for you'
    );

    hi_clear();
});

Harness::test('A COMMIT WITHOUT A DRY RUN IS REFUSED BY THE SERVER', function () use ($owner): void {
    hi_clear();

    $id = hi_job('contacts', hi_guest_csv(3, 'NoCheck'), [
        0 => 'first_name', 1 => 'last_name', 2 => 'email',
    ]);

    $response = $owner->postWithToken('/imports/' . $id, '/imports/' . $id . '/start', [
        'commit'  => '1',
        'map[0]'  => 'first_name',
        'map[1]'  => 'last_name',
        'map[2]'  => 'email',
    ], true);

    Assert::same(200, $response['status']);
    Assert::true(
        str_contains($response['body'], 'Run the check first'),
        'and it says why, rather than just refusing'
    );

    Assert::same(
        0,
        (int) DB::value("SELECT COUNT(*) FROM {contacts} WHERE last_name LIKE 'HitestNoCheck%'"),
        'nothing was written'
    );
    Assert::same(
        'mapping',
        (string) DB::value('SELECT status FROM {imports} WHERE id = :i', ['i' => $id]),
        'and the job did not move'
    );

    hi_clear();
});

Harness::test('a mapping with no identity column is refused, with the reason', function () use ($owner): void {
    hi_clear();

    $id = hi_job('contacts', hi_guest_csv(3, 'NoId'), [0 => 'first_name', 1 => 'last_name']);

    $response = $owner->postWithToken('/imports/' . $id, '/imports/' . $id . '/start', [
        'map[0]' => 'first_name',
        'map[1]' => 'last_name',
    ], true);

    Assert::same(200, $response['status']);
    Assert::true(
        str_contains($response['body'], 'two different families called Smith'),
        'it explains why a name is not identity'
    );
    Assert::same(
        'mapping',
        (string) DB::value('SELECT status FROM {imports} WHERE id = :i', ['i' => $id])
    );

    hi_clear();
});

Harness::test('the dry run reports, writes nothing, and then the import writes', function () use ($owner): void {
    hi_clear();

    $id = hi_job('contacts', hi_guest_csv(5, 'Live'), [
        0 => 'first_name', 1 => 'last_name', 2 => 'email', 3 => 'phone', 4 => 'email_consent',
    ]);

    $checked = $owner->postWithToken('/imports/' . $id, '/imports/' . $id . '/start', [
        'map[0]' => 'first_name',
        'map[1]' => 'last_name',
        'map[2]' => 'email',
        'map[3]' => 'phone',
        'map[4]' => 'email_consent',
    ], true);

    Assert::same(200, $checked['status']);
    Assert::false(str_contains($checked['body'], 'Warning:'));

    Assert::same(
        'dry_run_done',
        (string) DB::value('SELECT status FROM {imports} WHERE id = :i', ['i' => $id]),
        'a small file finishes inside the request that started it'
    );
    Assert::same(
        0,
        (int) DB::value("SELECT COUNT(*) FROM {contacts} WHERE last_name LIKE 'HitestLive%'"),
        'and wrote nothing'
    );

    // The page says both of those things in words.
    Assert::true(str_contains($checked['body'], 'would be added'));
    Assert::true(str_contains($checked['body'], 'Nothing has been written yet'));
    Assert::true(str_contains($checked['body'], 'Import it for real'), 'and now offers the import');

    // Commit.
    $imported = $owner->postWithToken('/imports/' . $id, '/imports/' . $id . '/start', [
        'commit' => '1',
        'map[0]' => 'first_name',
        'map[1]' => 'last_name',
        'map[2]' => 'email',
        'map[3]' => 'phone',
        'map[4]' => 'email_consent',
    ], true);

    Assert::same(200, $imported['status']);
    Assert::same(
        'done',
        (string) DB::value('SELECT status FROM {imports} WHERE id = :i', ['i' => $id])
    );
    Assert::same(
        5,
        (int) DB::value("SELECT COUNT(*) FROM {contacts} WHERE last_name LIKE 'HitestLive%'")
    );
    Assert::false(str_contains($imported['body'], 'would be'), 'and stops saying "would"');

    /*
     * THE CONSENT COLUMN SAID YES ON EVERY ROW AND THE BOX WAS NOT TICKED.
     * Checked here, through the real form, because this is the property the
     * whole module exists to protect and the form is where it could be lost.
     */
    Assert::same(
        0,
        (int) DB::value(
            "SELECT COUNT(*) FROM {contacts}
              WHERE last_name LIKE 'HitestLive%' AND email_consent = 1"
        ),
        'nobody is signed up for anything'
    );

    hi_clear();
});

Harness::test('the affirmation travels through the form and is audited', function () use ($owner): void {
    hi_clear();

    $id = hi_job('contacts', hi_guest_csv(3, 'Consent'), [
        0 => 'first_name', 1 => 'last_name', 2 => 'email', 3 => 'phone', 4 => 'email_consent',
    ]);

    $fields = [
        'map[0]' => 'first_name',
        'map[1]' => 'last_name',
        'map[2]' => 'email',
        'map[3]' => 'phone',
        'map[4]' => 'email_consent',
        'consent_claimed' => '1',
    ];

    $owner->postWithToken('/imports/' . $id, '/imports/' . $id . '/start', $fields, true);
    $owner->postWithToken('/imports/' . $id, '/imports/' . $id . '/start', $fields + ['commit' => '1'], true);

    Assert::same(
        3,
        (int) DB::value(
            "SELECT COUNT(*) FROM {contacts}
              WHERE last_name LIKE 'HitestConsent%' AND email_consent = 1"
        ),
        'with the affirmation, the file is believed'
    );

    /*
     * AND IT IS IN THE AUDIT LOG, because "who said these people agreed to be
     * emailed" is the question a complaint turns into, and the answer has to
     * have a name on it.
     */
    $audit = DB::first(
        "SELECT * FROM {audit_log}
          WHERE action = 'import_started' ORDER BY id DESC LIMIT 1"
    );

    Assert::notNull($audit);
    Assert::true(
        str_contains((string) $audit['changes'], 'consent_claimed'),
        'the claim is recorded'
    );
    Assert::greaterThan(0, (int) $audit['user_id'], 'with the person who made it');

    hi_clear();
});

Harness::test('the date-order setting changes what is refused', function () use ($owner): void {
    hi_clear();

    $csv = "First,Last,Email,DOB\n"
        . "Ambi,HitestDate0,hi-date0@test.invalid,03/04/1990\n"
        . "Clear,HitestDate1,hi-date1@test.invalid,1990-04-03\n";

    $id = hi_job('contacts', $csv, [
        0 => 'first_name', 1 => 'last_name', 2 => 'email', 3 => 'birth_date',
    ]);

    $fields = [
        'map[0]' => 'first_name',
        'map[1]' => 'last_name',
        'map[2]' => 'email',
        'map[3]' => 'birth_date',
    ];

    // Default: the ambiguous one is refused, and the message names the fix.
    $strict = $owner->postWithToken('/imports/' . $id, '/imports/' . $id . '/start', $fields, true);

    Assert::same(
        1,
        (int) DB::value('SELECT refused FROM {imports} WHERE id = :i', ['i' => $id])
    );
    Assert::true(
        str_contains($strict['body'], 'day-first or month-first'),
        'and points at the setting that would read it'
    );

    // Told the order: nothing is refused.
    $told = $owner->postWithToken(
        '/imports/' . $id,
        '/imports/' . $id . '/start',
        $fields + ['date_order' => 'dmy'],
        true
    );

    Assert::same(200, $told['status']);
    Assert::same(
        0,
        (int) DB::value('SELECT refused FROM {imports} WHERE id = :i', ['i' => $id])
    );
    Assert::same(
        2,
        (int) DB::value('SELECT created FROM {imports} WHERE id = :i', ['i' => $id])
    );

    hi_clear();
});

// -------------------------------------------------------------- the report

Harness::test('the refusals download as a CSV with the file’s own row numbers', function () use ($owner): void {
    hi_clear();

    $csv = "First,Last,Email\n"
        . "Good,HitestRep0,hi-rep0@test.invalid\n"
        . "Bad,HitestRep1,not-an-email\n"
        . "None,HitestRep2,\n";

    $id = hi_job('contacts', $csv, [0 => 'first_name', 1 => 'last_name', 2 => 'email']);

    $owner->postWithToken('/imports/' . $id, '/imports/' . $id . '/start', [
        'map[0]' => 'first_name',
        'map[1]' => 'last_name',
        'map[2]' => 'email',
    ], true);

    $download = $owner->get('/imports/' . $id . '/export?show=refused');

    Assert::same(200, $download['status']);

    $lines = array_values(array_filter(explode("\n", trim($download['body']))));

    Assert::same(3, count($lines), 'a header and two refusals');
    Assert::true(str_contains($lines[0], 'Row in your file'), 'the row number is the first column');

    // Rows 3 and 4 of the file: the header is row 1, so a spreadsheet agrees.
    Assert::true(str_starts_with(ltrim($lines[1], "\xEF\xBB\xBF"), '3,'), 'the bad email is row 3');
    Assert::true(str_starts_with($lines[2], '4,'), 'and the empty one is row 4');
    Assert::true(str_contains($download['body'], 'bad_email'));

    // The export is audited: it is a file of guest identifiers leaving the
    // building, like every other export here.
    Assert::notNull(
        DB::first("SELECT id FROM {audit_log} WHERE action = 'import_report_exported' ORDER BY id DESC LIMIT 1")
    );

    hi_clear();
});

Harness::test('the report groups by reason, and each tab renders', function () use ($owner): void {
    hi_clear();

    $csv = "First,Last,Email\nA,HitestTab0,hi-tab0@test.invalid\nB,HitestTab1,nope\n";
    $id  = hi_job('contacts', $csv, [0 => 'first_name', 1 => 'last_name', 2 => 'email']);

    $owner->postWithToken('/imports/' . $id, '/imports/' . $id . '/start', [
        'map[0]' => 'first_name',
        'map[1]' => 'last_name',
        'map[2]' => 'email',
    ], true);

    foreach (['refused', 'skipped', 'created', 'updated', 'all'] as $tab) {
        $page = $owner->get('/imports/' . $id . '?show=' . $tab);

        Assert::same(200, $page['status'], $tab . ' renders');
        Assert::false(str_contains($page['body'], 'Warning:'), $tab . ' has no warnings');
        Assert::false(str_contains($page['body'], 'Notice:'));
    }

    // An unknown tab falls back rather than erroring.
    Assert::same(200, $owner->get('/imports/' . $id . '?show=nonsense')['status']);

    // The grouped panel is drawn and names the reason.
    $page = $owner->get('/imports/' . $id);

    Assert::true(str_contains($page['body'], 'Why rows were not imported'));
    Assert::true(str_contains($page['body'], 'bad email'), 'with the reason in words');

    hi_clear();
});

// ---------------------------------------------------------- cancel & delete

Harness::test('cancelling stops the job and says what stays', function () use ($owner): void {
    hi_clear();

    $id = hi_job('contacts', hi_guest_csv(3, 'Cancel'), [0 => 'first_name', 1 => 'last_name', 2 => 'email']);

    DB::update('imports', ['status' => 'importing'], 'id = :i', ['i' => $id]);

    $response = $owner->postWithToken('/imports/' . $id, '/imports/' . $id . '/cancel', [], true);

    Assert::same(200, $response['status']);
    Assert::same(
        'cancelled',
        (string) DB::value('SELECT status FROM {imports} WHERE id = :i', ['i' => $id])
    );

    /*
     * WHAT WAS WRITTEN STAYS WRITTEN, and the page says so. Rolling back would
     * mean deleting contacts that may already have been edited, and guessing
     * which — so the honest answer is to say what happened and point at the
     * report.
     */
    Assert::true(
        str_contains($response['body'], 'still here'),
        'it says that anything already imported remains'
    );

    hi_clear();
});

Harness::test('deleting the job removes the file but not the guests', function () use ($owner): void {
    hi_clear();

    $id = hi_job('contacts', hi_guest_csv(3, 'Del'), [0 => 'first_name', 1 => 'last_name', 2 => 'email']);

    $fields = ['map[0]' => 'first_name', 'map[1]' => 'last_name', 'map[2]' => 'email'];

    $owner->postWithToken('/imports/' . $id, '/imports/' . $id . '/start', $fields, true);
    $owner->postWithToken('/imports/' . $id, '/imports/' . $id . '/start', $fields + ['commit' => '1'], true);

    $stored = (string) DB::value('SELECT stored_path FROM {imports} WHERE id = :i', ['i' => $id]);
    $path   = ImportRunner::pathFor($stored);

    Assert::true(is_file($path), 'the uploaded file is on disk');

    $response = $owner->postWithToken('/imports/' . $id, '/imports/' . $id . '/delete', [], true);

    Assert::same(200, $response['status']);

    // `is_file()` caches, and the assertion above populated that cache with a
    // `true` — so without this the check reads the old answer and the test
    // fails on a delete that worked perfectly.
    clearstatcache(true, $path);

    Assert::false(is_file($path), 'and the file goes with the job');
    Assert::same(
        0,
        (int) DB::value('SELECT COUNT(*) FROM {imports} WHERE id = :i', ['i' => $id])
    );
    Assert::same(
        0,
        (int) DB::value('SELECT COUNT(*) FROM {import_rows} WHERE import_id = :i', ['i' => $id]),
        'the report cascades'
    );

    // But the guests are ordinary records now.
    Assert::same(
        3,
        (int) DB::value("SELECT COUNT(*) FROM {contacts} WHERE last_name LIKE 'HitestDel%'")
    );
    Assert::true(str_contains($response['body'], 'Nothing that was imported'));

    hi_clear();
});

Harness::test('a running job cannot be deleted out from under itself', function () use ($owner): void {
    hi_clear();

    $id = hi_job('contacts', hi_guest_csv(2, 'Busy'), [0 => 'first_name', 1 => 'last_name', 2 => 'email']);

    DB::update('imports', ['status' => 'importing'], 'id = :i', ['i' => $id]);

    $response = $owner->postWithToken('/imports/' . $id, '/imports/' . $id . '/delete', [], true);

    Assert::same(200, $response['status']);
    Assert::true(str_contains($response['body'], 'Stop it first'));
    Assert::same(
        1,
        (int) DB::value('SELECT COUNT(*) FROM {imports} WHERE id = :i', ['i' => $id])
    );

    hi_clear();
});

Harness::test('a job that is gone answers 404 rather than 500', function () use ($owner): void {
    Assert::same(404, $owner->get('/imports/99999999')['status']);
    Assert::same(404, $owner->get('/imports/99999999/export')['status']);

    foreach (['start', 'cancel', 'delete'] as $action) {
        $response = $owner->postWithToken('/account', '/imports/99999999/' . $action, []);

        Assert::same(404, $response['status'], $action . ' on a missing job');
    }
});

Harness::test('the import limits are reported on the page, not just enforced', function () use ($owner): void {
    $page = $owner->get('/imports');

    Assert::same(200, $page['status']);

    /*
     * `upload_max_filesize` is set by the HOST, and a file over it arrives at
     * PHP as "no file was uploaded" with no number anywhere. So the page works
     * out the real figure and prints it — otherwise an operator whose 12MB
     * file vanishes has nothing to go on.
     */
    Assert::true(
        (bool) preg_match('/Up to [\d.]+ (?:B|KB|MB|GB)/', $page['body']),
        'a size limit is stated'
    );
});

hi_clear();
hi_clear_roles();
