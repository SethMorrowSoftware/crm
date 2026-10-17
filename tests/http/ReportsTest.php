<?php

declare(strict_types=1);

/**
 * The reporting pages, over HTTP.
 *
 * What only this layer can pin:
 *
 *   - **Twenty-three pages render.** Each report is a hand-written query and
 *     a generic table; a column type the table does not know, a totals row
 *     that does not line up or a null where a format expects a number is a
 *     PHP warning on the page, not a failed assertion anywhere else. So every
 *     report in the catalogue is fetched and read for warnings.
 *   - **Money is scrubbed, not hidden — and the CSV is scrubbed too.** A
 *     role with `view_reports` but not `view_revenue` must get every count
 *     and no dollars, in BOTH the page and the export. The export is the
 *     hole: it is a separate endpoint, and the obvious implementation gates
 *     it on `reports_export` alone and hands a technician the whole money
 *     column.
 *   - **A saved report keeps the period by NAME.** "Last 30 days" has to
 *     still mean the last 30 days next month, not the 30 days it was saved
 *     on — which is what storing the resolved dates would do.
 */

use App\Core\Auth;
use App\Core\DB;
use App\Services\Reports;
use Tests\Assert;
use Tests\Harness;

Harness::suite('http/reports');

$baseUrl = getenv('CC_TEST_BASE_URL') ?: '';

if ($baseUrl === '') {
    Harness::skip('reports suite', 'no server; run via tests/run.php');

    return;
}

if (!test_boot()) {
    Harness::skip('reports suite', 'no database available');

    return;
}

require_once CC_ROOT . '/tests/TestBrowser.php';

// ------------------------------------------------------------------ fixtures

/*
 * DATA ONLY. The roles and accounts built below are signed-in sessions for the
 * whole file, so a clear that removed them logged every scrub test out and the
 * failures read as 302s from the report pages — which looks like a permission
 * bug in the product. `hr_clear_roles()` is separate and runs once at each
 * end.
 */
function hr_clear(): void
{
    DB::query("DELETE FROM {saved_reports} WHERE name LIKE 'HR %'");
    DB::query("DELETE FROM {bookings} WHERE source = 'hrtest'");
}

function hr_clear_roles(): void
{
    $roleIds = DB::column("SELECT id FROM {roles} WHERE slug LIKE 'hrtest-%'");

    foreach ($roleIds as $roleId) {
        DB::query('DELETE FROM {users} WHERE role_id = :r', ['r' => (int) $roleId]);
        DB::query('DELETE FROM {role_permissions} WHERE role_id = :r', ['r' => (int) $roleId]);
        DB::query('DELETE FROM {roles} WHERE id = :r', ['r' => (int) $roleId]);
    }
}

/**
 * A role holding exactly the permissions named.
 *
 * None of the seeded roles has `view_reports` WITHOUT `view_revenue`, which
 * is the combination the scrubbing is for — so without building one here the
 * scrub tests would pass because everybody could see everything.
 *
 * @param array<string,string> $permissions slug => scope
 */
function hr_role(string $slug, array $permissions): int
{
    $roleId = (int) DB::insert('roles', [
        'slug'        => 'hrtest-' . $slug,
        'name'        => 'HR ' . ucfirst($slug),
        'description' => 'Created by the reports test suite.',
        'is_system'   => 0,
        'sort_order'  => 210,
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
function hr_account(int $roleId, string $label): array
{
    $email    = 'hr-' . $label . '-' . bin2hex(random_bytes(3)) . '@test.invalid';
    $password = 'reports-suite-passphrase-9';

    DB::insert('users', [
        'name'          => 'HR ' . ucfirst($label),
        'email'         => $email,
        'password_hash' => Auth::hash($password),
        'role_id'       => $roleId,
        'is_active'     => 1,
        'created_at'    => now_utc(),
        'updated_at'    => now_utc(),
    ]);

    return ['email' => $email, 'password' => $password];
}

function hr_booking(string $eventDate, int $cents = 45000): void
{
    DB::insert('bookings', [
        'booking_number'    => 'HR-' . bin2hex(random_bytes(4)),
        'type'              => 'birthday',
        'status'            => 'confirmed',
        'event_date'        => $eventDate,
        'guest_count'       => 14,
        'total_cents'       => $cents,
        'deposit_cents'     => 10000,
        'balance_due_cents' => $cents - 10000,
        'source'            => 'hrtest',
        'created_at'        => now_utc(),
        'updated_at'        => now_utc(),
    ]);
}

hr_clear();
hr_clear_roles();

$owner = new TestBrowser($baseUrl, 'reports-owner');
$owner->login('owner@test.invalid', 'owner-test-passphrase-9');

// Front Desk holds no view_reports at all.
$desk = new TestBrowser($baseUrl, 'reports-desk');
$desk->login('desk@test.invalid', 'desk-test-passphrase-9');

// Reports but no money, and no export either: the technician's profile.
$techRole = hr_role('tech', ['view_reports' => 'all']);
$techUser = hr_account($techRole, 'tech');
$tech     = new TestBrowser($baseUrl, 'reports-tech');
$tech->login($techUser['email'], $techUser['password']);

// Money on screen, but no export. The read-only manager.
$screenRole = hr_role('screen', ['view_reports' => 'all', 'view_revenue' => 'all']);
$screenUser = hr_account($screenRole, 'screen');
$screen     = new TestBrowser($baseUrl, 'reports-screen');
$screen->login($screenUser['email'], $screenUser['password']);

Harness::test('the fixtures signed in', function () use ($owner, $desk, $tech, $screen): void {
    Assert::same(200, $owner->get('/account')['status']);
    Assert::same(200, $desk->get('/account')['status']);
    Assert::same(200, $tech->get('/account')['status'], 'the no-money role signed in');
    Assert::same(200, $screen->get('/account')['status']);
});

// ================================================================= the pages

Harness::test('the picker lists every family and every report', function () use ($owner): void {
    $response = $owner->get('/reports');

    Assert::same(200, $response['status']);

    foreach (Reports::FAMILIES as $label) {
        Assert::contains($label, $response['body'], 'the ' . $label . ' family is missing');
    }

    foreach (Reports::CATALOG as $id => $report) {
        Assert::contains(
            'href="/reports/' . $id . '"',
            $response['body'],
            $id . ' is in the catalogue but not linked from the picker'
        );

        // The question, not just the name: that is the sentence somebody who
        // has never used the system can read.
        Assert::contains(
            htmlspecialchars($report['question'], ENT_QUOTES),
            $response['body'],
            $id . '\'s question is not on the page'
        );
    }
});

Harness::test('every report renders, with no warnings and no notices', function () use ($owner): void {
    hr_clear();

    // Some real data, so the tables, totals and charts are exercised rather
    // than every report taking its empty path.
    hr_booking(test_local_day(2));
    hr_booking(test_local_day(2), 30000);
    hr_booking(test_local_day(9), 60000);

    foreach (array_keys(Reports::CATALOG) as $id) {
        $response = $owner->get('/reports/' . $id . '?range=last_30');

        Assert::same(200, $response['status'], $id);
        Assert::notContains('Fatal error', $response['body'], $id);
        Assert::notContains('Warning:', $response['body'], $id);
        Assert::notContains('Undefined ', $response['body'], $id);
        Assert::notContains('Deprecated:', $response['body'], $id);
        Assert::notContains('Something went wrong', $response['body'], $id);
        Assert::notContains('could not run', $response['body'], $id);
    }
});

Harness::test('every report renders on every preset, including the empty ones', function () use ($owner): void {
    // The window code is where a report breaks without any data to break on:
    // a month boundary, a year with no rows, a custom pair. Two reports are
    // enough to exercise it on every preset without fetching 23 × 8 pages.
    foreach (['revenue_by_day', 'nps_trend'] as $id) {
        foreach (array_keys(Reports::PRESETS) as $preset) {
            $response = $owner->get('/reports/' . $id . '?range=' . $preset);

            Assert::same(200, $response['status'], $id . ' on ' . $preset);
            Assert::notContains('Warning:', $response['body'], $id . ' on ' . $preset);
            Assert::notContains('Undefined ', $response['body'], $id . ' on ' . $preset);
        }
    }
});

Harness::test('a report that does not exist is a 404, not a 500', function () use ($owner): void {
    Assert::same(404, $owner->get('/reports/not_a_report')['status']);
    Assert::same(404, $owner->get('/reports/not_a_report/export')['status']);
});

Harness::test('a custom window is honoured and echoed back', function () use ($owner): void {
    hr_clear();

    $day = test_local_day(20);
    hr_booking($day, 77700);

    $response = $owner->get('/reports/revenue_by_day?range=custom&from=' . $day . '&to=' . $day);

    Assert::same(200, $response['status']);
    Assert::contains('$777.00', $response['body'], 'the booking inside the custom window');
    Assert::contains('1 day', $response['body'], 'the window says how long it is');
});

Harness::test('an absurd window is clamped on the page, and says so', function () use ($owner): void {
    $response = $owner->get('/reports/revenue_by_day?range=custom&from=1995-01-01&to=' . test_local_day());

    Assert::same(200, $response['status']);
    Assert::contains('shortened', $response['body'], 'a silent clamp under-reports by decades');
});

// ================================================================== the gates

Harness::test('a role without view_reports cannot reach any of it', function () use ($desk): void {
    Assert::same(403, $desk->get('/reports')['status']);
    Assert::same(403, $desk->get('/reports/revenue_by_day')['status']);
    Assert::same(403, $desk->get('/reports/revenue_by_day/export')['status']);
});

Harness::test('a role with no revenue reads the counts and none of the money', function () use ($tech): void {
    hr_clear();

    $day = test_local_day(3);
    hr_booking($day, 123400);

    $response = $tech->get('/reports/revenue_by_day?range=last_30');

    Assert::same(200, $response['status']);

    // The count is theirs to read.
    Assert::contains('Parties', $response['body']);

    // The figure is not — in any formatting.
    Assert::notContains('$1,234.00', $response['body']);
    Assert::notContains('1234.00', $response['body']);
    Assert::notContains('123400', $response['body']);

    // And the column is still THERE, empty, with the reason said out loud:
    // removing it would renumber the table between two people discussing the
    // same report.
    Assert::contains('Booked', $response['body'], 'the money column stays, so the rows line up');
    Assert::contains('does not include revenue', $response['body'], 'and the page says why it is empty');
});

Harness::test('the headline comparison goes with the money, not half of it', function () use ($tech): void {
    hr_clear();
    hr_booking(test_local_day(3), 500000);

    $response = $tech->get('/reports/revenue_by_day?range=last_30');

    // The comparison on this report is a money figure. Scrubbing the columns
    // and leaving it would print the total in the one place nothing nulled
    // it; nulling only its value would read as "no prior period", which is a
    // different and wrong statement. So the whole block goes.
    Assert::notContains('$5,000.00', $response['body']);
    Assert::notContains('Against the', $response['body']);

    // A report whose comparison is a COUNT is still shown to them: it is not
    // money, and dropping it would cost a technician context for no reason.
    $visits = $tech->get('/reports/new_vs_returning?range=last_30');
    Assert::same(200, $visits['status']);
    Assert::contains('Against visits in', $visits['body']);
});

Harness::test('exporting is its own permission', function () use ($screen, $owner): void {
    // Money on screen does not mean money in a file: an export leaves the
    // building and is the thing a leaver walks out with.
    Assert::same(403, $screen->get('/reports/revenue_by_day/export')['status']);
    Assert::same(200, $owner->get('/reports/revenue_by_day/export')['status']);
});

Harness::test('the export is scrubbed for a role with no revenue', function () use ($techRole): void {
    hr_clear();
    hr_booking(test_local_day(4), 98700);

    // Give the technician the export permission and nothing else. This is the
    // exact hole: `reports_export` alone on the route would hand them the
    // whole money column in a file.
    $permissionId = (int) DB::value("SELECT id FROM {permissions} WHERE slug = 'reports_export'");
    DB::insert('role_permissions', [
        'role_id'       => $techRole,
        'permission_id' => $permissionId,
        'scope'         => 'all',
    ]);

    $exporter = hr_account($techRole, 'exporter');
    $browser  = new TestBrowser(getenv('CC_TEST_BASE_URL') ?: '', 'reports-exporter');
    $browser->login($exporter['email'], $exporter['password']);

    $response = $browser->get('/reports/revenue_by_day/export?range=last_30');

    Assert::same(200, $response['status']);
    Assert::contains('text/csv', strtolower((string) $response['headers']));

    // The header keeps the column — a spreadsheet with a different shape per
    // role is unusable — and the cell is empty.
    Assert::contains('Booked', $response['body']);
    Assert::notContains('987.00', $response['body']);
    Assert::notContains('98700', $response['body']);

    // The count is still there, so the file is worth having.
    Assert::contains('Parties', $response['body']);
});

Harness::test('an export is a CSV with the report\'s own filename', function () use ($owner): void {
    hr_clear();
    hr_booking(test_local_day(5), 55500);

    $response = $owner->get('/reports/revenue_by_day/export?range=last_30');

    Assert::same(200, $response['status']);

    Assert::contains('attachment', (string) $response['headers']);
    Assert::contains(
        'revenue-by-day-',
        (string) $response['headers'],
        'named so it can be found in a downloads folder'
    );

    // Dollars as a plain decimal: "$555.00" is not a number a spreadsheet can
    // total, and 55500 is not money.
    Assert::contains('555.00', $response['body']);
    Assert::notContains('$555.00', $response['body']);
});

Harness::test('an exported report is recorded in the audit log', function () use ($owner): void {
    $before = (int) DB::value("SELECT COUNT(*) FROM {audit_log} WHERE action = 'report_exported'");

    $owner->get('/reports/lead_sources/export?range=last_7');

    $after = (int) DB::value("SELECT COUNT(*) FROM {audit_log} WHERE action = 'report_exported'");

    Assert::same($before + 1, $after, 'data leaving the building is an auditable event');

    $row = DB::first(
        "SELECT changes FROM {audit_log} WHERE action = 'report_exported' ORDER BY id DESC LIMIT 1"
    );

    Assert::contains('lead_sources', (string) ($row['changes'] ?? ''), 'and it records which report');
});

// =========================================================== saved reports

Harness::test('a saved report keeps its period by name, not by date', function () use ($owner): void {
    hr_clear();

    $response = $owner->postWithToken('/reports/revenue_by_day', '/reports/saved', [
        'report' => 'revenue_by_day',
        'name'   => 'HR Monthly revenue',
        'range'  => 'last_30',
        'from'   => test_local_day(29),
        'to'     => test_local_day(),
    ]);

    Assert::same(302, $response['status']);

    $saved = DB::first("SELECT * FROM {saved_reports} WHERE name = 'HR Monthly revenue'");

    Assert::notNull($saved);
    Assert::same('revenue_by_day', (string) $saved['type']);

    $filters = json_decode((string) $saved['filters'], true);

    Assert::same('last_30', $filters['preset']);

    // THE POINT: the dates are NOT stored for a preset. Storing them would
    // turn "Last 30 days" into "the 30 days ending the day I saved this",
    // which next month is a report about last month under a name that says
    // otherwise.
    Assert::null($filters['from']);
    Assert::null($filters['to']);
});

Harness::test('a saved custom window keeps its dates, because that is what custom means', function () use ($owner): void {
    $owner->postWithToken('/reports/win_loss', '/reports/saved', [
        'report' => 'win_loss',
        'name'   => 'HR Last summer',
        'range'  => 'custom',
        'from'   => '2026-06-01',
        'to'     => '2026-08-31',
    ]);

    $saved   = DB::first("SELECT * FROM {saved_reports} WHERE name = 'HR Last summer'");
    $filters = json_decode((string) ($saved['filters'] ?? '{}'), true);

    Assert::same('custom', $filters['preset']);
    Assert::same('2026-06-01', $filters['from']);
    Assert::same('2026-08-31', $filters['to']);
});

Harness::test('a saved report is listed, opens its report, and deletes', function () use ($owner): void {
    hr_clear();

    $owner->postWithToken('/reports/top_guests', '/reports/saved', [
        'report' => 'top_guests',
        'name'   => 'HR Best guests',
        'range'  => 'last_90',
    ]);

    $saved = DB::first("SELECT * FROM {saved_reports} WHERE name = 'HR Best guests'");
    Assert::notNull($saved);

    $id = (int) $saved['id'];

    // Listed on the picker, by name and by which report it is.
    $picker = $owner->get('/reports');
    Assert::contains('HR Best guests', $picker['body']);
    Assert::contains('Last 90 days', $picker['body']);

    // Opening it lands on the report with the saved period applied.
    $open = $owner->get('/reports/saved/' . $id);
    Assert::same(302, $open['status']);
    Assert::contains('/reports/top_guests', (string) $open['location']);
    Assert::contains('range=last_90', (string) $open['location']);

    // And it records that it was opened, so a venue can see which of its
    // saved reports anybody actually uses.
    $after = DB::first('SELECT last_run_at FROM {saved_reports} WHERE id = :id', ['id' => $id]);
    Assert::notNull($after['last_run_at']);

    $owner->postWithToken('/reports', '/reports/saved/' . $id . '/delete', []);

    Assert::null(
        DB::first('SELECT id FROM {saved_reports} WHERE id = :id', ['id' => $id]),
        'deleting a saved report deletes it'
    );
});

Harness::test('a saved report whose report no longer exists is listed, not hidden', function () use ($owner): void {
    hr_clear();

    // A venue that renamed or removed a report wants to know their saved one
    // stopped working. Hiding it means they find out when somebody asks where
    // it went.
    DB::insert('saved_reports', [
        'name'       => 'HR Orphan',
        'type'       => 'a_report_that_was_removed',
        'filters'    => json_encode(['preset' => 'last_30', 'from' => null, 'to' => null]),
        'created_at' => now_utc(),
        'updated_at' => now_utc(),
    ]);

    $picker = $owner->get('/reports');

    Assert::same(200, $picker['status']);
    Assert::contains('HR Orphan', $picker['body']);
    Assert::contains('no longer exists', $picker['body']);

    hr_clear();
});

Harness::test('saving a report that is not in the catalogue is refused', function () use ($owner): void {
    $before = (int) DB::value('SELECT COUNT(*) FROM {saved_reports}');

    $response = $owner->postWithToken('/reports', '/reports/saved', [
        'report' => 'made_up_report',
        'name'   => 'HR Nonsense',
        'range'  => 'last_30',
    ]);

    Assert::same(302, $response['status']);
    Assert::same($before, (int) DB::value('SELECT COUNT(*) FROM {saved_reports}'));
});

Harness::test('saving needs the CSRF token the page minted', function () use ($owner): void {
    // A GET-driven report page is harmless; a POST that writes a row is not.
    // Signed in, with cookies, and no token — which is what a form on
    // somebody else's site posts.
    $response = $owner->post('/reports/saved', [
        'report' => 'revenue_by_day',
        'name'   => 'HR Forged',
        'range'  => 'last_30',
    ]);

    Assert::notSame(302, $response['status'], 'a forged POST must not be accepted');
    Assert::null(DB::first("SELECT id FROM {saved_reports} WHERE name = 'HR Forged'"));
});

hr_clear();
hr_clear_roles();
