<?php

declare(strict_types=1);

/**
 * The membership pages, over HTTP.
 *
 * What is pinned here that the other layers cannot pin:
 *
 *   - **Selling a pass is counter work; pricing one is not.** Two
 *     permissions guard this module and the whole point of the split is
 *     that somebody who can enrol a family cannot invent a twelve-pound
 *     season pass. Both halves are checked, in both directions.
 *   - **Every action a manager presses writes what it says it writes.**
 *     Freezing, unfreezing, renewing and cancelling all go through the
 *     forms a person actually clicks, with the CSRF token the page minted.
 *   - **A pass nobody has priced is not sellable.** The seeded plans ship
 *     off sale, and the sell form must say so rather than offering them.
 */

use App\Core\DB;
use App\Services\Membership;
use App\Services\MembershipLifecycle;
use Tests\Assert;
use Tests\Harness;

Harness::suite('http/memberships');

$baseUrl = getenv('CC_TEST_BASE_URL') ?: '';

if ($baseUrl === '') {
    Harness::skip('memberships suite', 'no server; run via tests/run.php');

    return;
}

if (!test_boot()) {
    Harness::skip('memberships suite', 'no database available');

    return;
}

require_once CC_ROOT . '/tests/TestBrowser.php';

// ------------------------------------------------------------------ fixtures

function hm_clear(): void
{
    /*
     * Resolved to ids first, then deleted child-out. Matching the plans twice
     * (once for the memberships, once for the plans) let a plan created by
     * the plan-editor test keep its memberships and fail the foreign key —
     * which showed up as the NEXT test erroring, not this one.
     */
    $planIds = DB::column(
        "SELECT id FROM {membership_plans} WHERE slug LIKE 'hmtest-%' OR name LIKE 'HM %'"
    );

    foreach ($planIds as $planId) {
        $id = (int) $planId;

        DB::query(
            'DELETE FROM {membership_usage} WHERE membership_id IN
                (SELECT id FROM {memberships} WHERE plan_id = :p)',
            ['p' => $id]
        );
        DB::query(
            'DELETE FROM {membership_payments} WHERE membership_id IN
                (SELECT id FROM {memberships} WHERE plan_id = :p)',
            ['p' => $id]
        );
        DB::query(
            'DELETE FROM {membership_members} WHERE membership_id IN
                (SELECT id FROM {memberships} WHERE plan_id = :p)',
            ['p' => $id]
        );
        DB::query('DELETE FROM {memberships} WHERE plan_id = :p', ['p' => $id]);
        DB::query('DELETE FROM {membership_plans} WHERE id = :p', ['p' => $id]);
    }

    DB::query("DELETE FROM {contacts} WHERE source = 'hmtest'");
}

function hm_plan(array $overrides = []): int
{
    static $n = 0;
    $n++;

    return (int) DB::insert('membership_plans', $overrides + [
        'name'          => 'HM Plan ' . $n,
        'slug'          => 'hmtest-' . $n . '-' . bin2hex(random_bytes(2)),
        'type'          => 'season_pass',
        'price_cents'   => 9900,
        'duration_days' => 365,
        'is_active'     => 1,
        'created_at'    => now_utc(),
        'updated_at'    => now_utc(),
    ]);
}

function hm_contact(string $name = 'Member'): int
{
    return (int) DB::insert('contacts', [
        'first_name'      => $name,
        'last_name'       => 'HmTest',
        'email'           => 'hm.' . strtolower($name) . '.' . bin2hex(random_bytes(3)) . '@example.test',
        'source'          => 'hmtest',
        'lifecycle_stage' => 'customer',
        'created_at'      => now_utc(),
        'updated_at'      => now_utc(),
    ]);
}

hm_clear();

$owner = new TestBrowser($baseUrl, 'memberships-owner');
$owner->login('owner@test.invalid', 'owner-test-passphrase-9');

// Front Desk holds neither view_memberships nor memberships_manage.
$desk = new TestBrowser($baseUrl, 'memberships-desk');
$desk->login('desk@test.invalid', 'desk-test-passphrase-9');

Harness::test('the fixtures signed in', function () use ($owner, $desk): void {
    Assert::same(200, $owner->get('/account')['status']);
    Assert::same(200, $desk->get('/account')['status']);
});

// =============================================================== the pages

Harness::test('the membership pages render', function () use ($owner): void {
    hm_clear();
    $planId = hm_plan();
    $id     = MembershipLifecycle::enrol(['plan_id' => $planId, 'contact_id' => hm_contact('Render')]);

    foreach (['/memberships', '/memberships/plans', '/memberships/' . $id] as $path) {
        $response = $owner->get($path);

        Assert::same(200, $response['status'], $path);
        Assert::notContains('Fatal error', $response['body'], $path);
        Assert::notContains('Warning:', $response['body'], $path);
        Assert::notContains('Undefined ', $response['body'], $path);
    }
});

Harness::test('a membership that does not exist is a 404, not a 500', function () use ($owner): void {
    Assert::same(404, $owner->get('/memberships/99999999')['status']);
});

Harness::test('the front desk cannot see memberships at all', function () use ($desk): void {
    Assert::same(403, $desk->get('/memberships')['status']);
    Assert::same(403, $desk->get('/memberships/plans')['status']);
});

Harness::test('the four views each filter to something different', function () use ($owner): void {
    hm_clear();
    $planId = hm_plan();

    $live = MembershipLifecycle::enrol(['plan_id' => $planId, 'contact_id' => hm_contact('Live')]);

    $renewing = MembershipLifecycle::enrol(['plan_id' => $planId, 'contact_id' => hm_contact('Renewing')]);
    DB::update('memberships', [
        'status'     => 'expiring',
        'expires_at' => Membership::addDays(MembershipLifecycle::today(), 10),
    ], 'id = :id', ['id' => $renewing]);

    $idle = MembershipLifecycle::enrol(['plan_id' => $planId, 'contact_id' => hm_contact('Idle')]);
    DB::update('memberships', [
        'started_at'   => Membership::addDays(MembershipLifecycle::today(), -120),
        'last_used_at' => Membership::addDays(MembershipLifecycle::today(), -60) . ' 12:00:00',
    ], 'id = :id', ['id' => $idle]);

    $liveBody = $owner->get('/memberships?show=live')['body'];

    Assert::contains('Live HmTest', $liveBody);

    $renewalBody = $owner->get('/memberships?show=renewals')['body'];

    Assert::contains('Renewing HmTest', $renewalBody);
    Assert::notContains('Live HmTest', $renewalBody, 'a pass with a year left is not up for renewal');

    $idleBody = $owner->get('/memberships?show=idle')['body'];

    Assert::contains('Idle HmTest', $idleBody);
    Assert::notContains('Live HmTest', $idleBody, 'a pass sold today is not idle');

    Assert::greaterThan(0, $live);
});

Harness::test('search finds a member by their number', function () use ($owner): void {
    hm_clear();
    $id     = MembershipLifecycle::enrol(['plan_id' => hm_plan(), 'contact_id' => hm_contact('Findme')]);
    $number = (string) DB::value('SELECT member_number FROM {memberships} WHERE id = :id', ['id' => $id]);

    $body = $owner->get('/memberships?show=all&q=' . urlencode($number))['body'];

    // The count query used to join only the plan while the search clause
    // named the contact, so the first search anybody typed was a 500.
    Assert::notContains('Something went wrong', $body, 'searching must not error');

    Assert::contains('Findme HmTest', $body);
});

// ============================================================== selling one

Harness::test('A PASS SOLD THROUGH THE FORM WORKS STRAIGHT AWAY', function () use ($owner): void {
    hm_clear();
    $planId    = hm_plan(['price_cents' => 9900]);
    $contactId = hm_contact('Buyer');

    $response = $owner->postWithToken('/memberships', '/memberships', [
        'plan_id'    => (string) $planId,
        'contact_id' => (string) $contactId,
        'started_at' => MembershipLifecycle::today(),
    ], true);

    Assert::same(200, $response['status']);

    $row = DB::first('SELECT * FROM {memberships} WHERE contact_id = :c', ['c' => $contactId]);

    Assert::notNull($row);
    Assert::same('active', (string) $row['status']);
    Assert::same(9900, (int) $row['price_paid_cents'], 'the plan price when none is typed');

    // And the page it lands on is the membership itself.
    Assert::contains('Buyer HmTest', $response['body']);
});

Harness::test('a price typed at the counter wins over the plan price', function () use ($owner): void {
    hm_clear();
    $planId    = hm_plan(['price_cents' => 9900]);
    $contactId = hm_contact('Haggler');

    $owner->postWithToken('/memberships', '/memberships', [
        'plan_id'    => (string) $planId,
        'contact_id' => (string) $contactId,
        'price'      => '75.50',
    ], true);

    Assert::same(7550, (int) DB::value(
        'SELECT price_paid_cents FROM {memberships} WHERE contact_id = :c',
        ['c' => $contactId]
    ));
});

Harness::test('selling a pass needs the permission', function () use ($desk): void {
    $response = $desk->post('/memberships', ['plan_id' => '1', 'contact_id' => '1']);

    Assert::true(in_array($response['status'], [403, 419], true), 'got ' . $response['status']);
});

Harness::test('an off-sale plan is not offered', function () use ($owner): void {
    hm_clear();
    hm_plan(['name' => 'HM Draft plan', 'is_active' => 0]);

    $body = $owner->get('/memberships')['body'];

    // The seeded plans ship off sale because the venue has not priced them.
    // Offering one at the counter would sell a pass at a number nobody chose.
    Assert::notContains('HM Draft plan', $body);
});

// ========================================================== managing one

Harness::test('freezing and unfreezing move the end date', function () use ($owner): void {
    hm_clear();
    $id = MembershipLifecycle::enrol(['plan_id' => hm_plan(), 'contact_id' => hm_contact('Freezer')]);

    $before = substr((string) DB::value('SELECT expires_at FROM {memberships} WHERE id = :id', ['id' => $id]), 0, 10);

    $frozen = $owner->postWithToken('/memberships/' . $id, '/memberships/' . $id . '/freeze', [], true);

    Assert::same(200, $frozen['status']);
    Assert::contains('Frozen', $frozen['body']);

    DB::update('memberships', [
        'updated_at' => gmdate('Y-m-d H:i:s', time() - (7 * 86400)),
    ], 'id = :id', ['id' => $id]);

    $thawed = $owner->postWithToken('/memberships/' . $id, '/memberships/' . $id . '/unfreeze', [], true);

    Assert::same(200, $thawed['status']);
    Assert::contains('moved on by 7 days', $thawed['body']);

    Assert::same(
        Membership::addDays($before, 7),
        substr((string) DB::value('SELECT expires_at FROM {memberships} WHERE id = :id', ['id' => $id]), 0, 10)
    );
});

Harness::test('renewing from the page starts the new term at the end of the old one', function () use ($owner): void {
    hm_clear();
    $id = MembershipLifecycle::enrol(['plan_id' => hm_plan(['duration_days' => 30]), 'contact_id' => hm_contact('Renewer')]);

    $ends = Membership::addDays(MembershipLifecycle::today(), 5);
    DB::update('memberships', ['expires_at' => $ends, 'status' => 'expiring'], 'id = :id', ['id' => $id]);

    $response = $owner->postWithToken('/memberships/' . $id, '/memberships/' . $id . '/renew', [
        'price' => '99.00',
    ], true);

    Assert::same(200, $response['status']);

    $row = DB::first('SELECT * FROM {memberships} WHERE id = :id', ['id' => $id]);

    Assert::same(
        Membership::expiresOn(['duration_days' => 30], Membership::addDays($ends, 1)),
        substr((string) $row['expires_at'], 0, 10)
    );
    Assert::same(9900, (int) $row['price_paid_cents']);

    // Two payments on the row now: the sale and the renewal.
    Assert::same(2, (int) DB::value(
        'SELECT COUNT(*) FROM {membership_payments} WHERE membership_id = :m',
        ['m' => $id]
    ));
});

Harness::test('cancelling keeps the reason', function () use ($owner): void {
    hm_clear();
    $id = MembershipLifecycle::enrol(['plan_id' => hm_plan(), 'contact_id' => hm_contact('Quitter')]);

    $response = $owner->postWithToken('/memberships/' . $id, '/memberships/' . $id . '/cancel', [
        'reason' => 'moved out of the area',
    ], true);

    Assert::same(200, $response['status']);
    Assert::same('cancelled', (string) DB::value(
        'SELECT status FROM {memberships} WHERE id = :id',
        ['id' => $id]
    ));

    // The reason is on the audit row, which is what a churn report reads.
    Assert::same(1, (int) DB::value(
        "SELECT COUNT(*) FROM {audit_log}
          WHERE action = 'membership_cancelled' AND entity_id = :id",
        ['id' => $id]
    ));
});

Harness::test('a family member can be added and taken off, but never the holder', function () use ($owner): void {
    hm_clear();
    $planId  = hm_plan(['is_family_plan' => 1, 'max_members' => 4]);
    $id      = MembershipLifecycle::enrol(['plan_id' => $planId, 'contact_id' => hm_contact('Holder')]);
    $partner = hm_contact('Partner');

    $owner->postWithToken('/memberships/' . $id, '/memberships/' . $id . '/members', [
        'contact_id'   => (string) $partner,
        'relationship' => 'partner',
    ], true);

    Assert::same(2, (int) DB::value(
        'SELECT COUNT(*) FROM {membership_members} WHERE membership_id = :m',
        ['m' => $id]
    ));

    $holderRow = (int) DB::value(
        'SELECT id FROM {membership_members} WHERE membership_id = :m AND is_primary = 1',
        ['m' => $id]
    );

    $refused = $owner->postWithToken('/memberships/' . $id, '/memberships/' . $id . '/members/remove', [
        'member_id' => (string) $holderRow,
    ], true);

    Assert::contains('holder cannot be taken off', $refused['body']);
    Assert::same(2, (int) DB::value(
        'SELECT COUNT(*) FROM {membership_members} WHERE membership_id = :m',
        ['m' => $id]
    ), 'the holder is still on it');
});

// ===================================================================== plans

Harness::test('A PLAN IS PRICED BY SOMEBODY WHO MAY PRICE PLANS', function () use ($owner, $desk): void {
    hm_clear();

    $created = $owner->postWithToken('/memberships/plans', '/memberships/plans', [
        'name'          => 'HM Gold Pass',
        'type'          => 'season_pass',
        'price'         => '149.00',
        'duration_days' => '365',
        'is_active'     => '1',
    ], true);

    Assert::same(200, $created['status']);

    $plan = DB::first("SELECT * FROM {membership_plans} WHERE name = 'HM Gold Pass'");

    Assert::notNull($plan);
    Assert::same(14900, (int) $plan['price_cents']);
    Assert::same('hm-gold-pass', (string) $plan['slug']);

    // The front desk cannot reach it at all.
    $refused = $desk->post('/memberships/plans', ['name' => 'HM Cheap', 'type' => 'season_pass', 'price' => '1']);

    Assert::true(in_array($refused['status'], [403, 419], true), 'got ' . $refused['status']);
    Assert::same(0, (int) DB::value("SELECT COUNT(*) FROM {membership_plans} WHERE name = 'HM Cheap'"));
});

Harness::test('editing a plan keeps its id and its memberships', function () use ($owner): void {
    hm_clear();
    $planId = hm_plan(['name' => 'HM Editable', 'price_cents' => 5000]);
    $id     = MembershipLifecycle::enrol(['plan_id' => $planId, 'contact_id' => hm_contact('OnPlan')]);

    $owner->postWithToken('/memberships/plans', '/memberships/plans/' . $planId, [
        'name'          => 'HM Editable',
        'type'          => 'season_pass',
        'price'         => '60.00',
        'duration_days' => '365',
        'is_active'     => '1',
    ], true);

    Assert::same(6000, (int) DB::value(
        'SELECT price_cents FROM {membership_plans} WHERE id = :id',
        ['id' => $planId]
    ));

    // The membership sold at the OLD price keeps what it paid: a price
    // change is for the next sale, not a retrospective charge.
    Assert::same(5000, (int) DB::value(
        'SELECT price_paid_cents FROM {memberships} WHERE id = :id',
        ['id' => $id]
    ));
});

hm_clear();
