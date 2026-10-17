<?php

declare(strict_types=1);

/**
 * Memberships against a real database: enrolling, using, freezing, renewing
 * and the nightly sweep.
 *
 * `unit/membership` pins the rules. This pins what they DO to rows, and in
 * particular the three things that would cost a venue money or goodwill:
 *
 *   - **A visit must not burn several punches.** A family is checked in one
 *     child at a time, which is several writes for one afternoon. Five
 *     punches off a ten-visit card for one visit is the kind of error a
 *     venue only hears about from an angry guest.
 *   - **Freezing must give the time back.** A pass frozen for two months
 *     and unfrozen with two months less on it is worse than not offering
 *     freezing at all.
 *   - **Renewing early must not cost them the time they already paid for.**
 *     A term that restarts today instead of at the end of the current one
 *     silently takes a fortnight off somebody who renewed a fortnight early
 *     — which is exactly the behaviour a venue wants to encourage.
 */

use App\Core\DB;
use App\Core\Event;
use App\Services\Membership;
use App\Services\MembershipLifecycle;
use App\Services\VisitRecorder;
use Tests\Assert;
use Tests\Harness;

Harness::suite('integration/memberships');

if (!test_boot()) {
    Harness::skip('memberships suite', 'no database available');

    return;
}

// ------------------------------------------------------------------ fixtures

function mi_clear(): void
{
    DB::query(
        "DELETE FROM {membership_usage} WHERE membership_id IN (
            SELECT id FROM {memberships} WHERE member_number LIKE 'MI%'
               OR plan_id IN (SELECT id FROM {membership_plans} WHERE slug LIKE 'mitest-%'))"
    );
    DB::query(
        "DELETE FROM {membership_payments} WHERE membership_id IN (
            SELECT id FROM {memberships} WHERE plan_id IN
                (SELECT id FROM {membership_plans} WHERE slug LIKE 'mitest-%'))"
    );
    DB::query(
        "DELETE FROM {membership_members} WHERE membership_id IN (
            SELECT id FROM {memberships} WHERE plan_id IN
                (SELECT id FROM {membership_plans} WHERE slug LIKE 'mitest-%'))"
    );
    DB::query(
        "DELETE FROM {memberships} WHERE plan_id IN
            (SELECT id FROM {membership_plans} WHERE slug LIKE 'mitest-%')"
    );
    DB::query("DELETE FROM {membership_plans} WHERE slug LIKE 'mitest-%'");
    DB::query("DELETE FROM {visits} WHERE contact_id IN (SELECT id FROM {contacts} WHERE source = 'mitest')");
    DB::query("DELETE FROM {contacts} WHERE source = 'mitest'");
}

function mi_plan(array $overrides = []): int
{
    static $n = 0;
    $n++;

    return (int) DB::insert('membership_plans', $overrides + [
        'name'          => 'MI Plan ' . $n,
        'slug'          => 'mitest-' . $n . '-' . bin2hex(random_bytes(2)),
        'type'          => 'season_pass',
        'price_cents'   => 9900,
        'duration_days' => 365,
        'is_active'     => 1,
        'created_at'    => now_utc(),
        'updated_at'    => now_utc(),
    ]);
}

function mi_contact(string $name = 'Member'): int
{
    return (int) DB::insert('contacts', [
        'first_name'      => $name,
        'last_name'       => 'MiTest',
        'email'           => 'mitest.' . strtolower($name) . '.' . bin2hex(random_bytes(3)) . '@example.test',
        'source'          => 'mitest',
        'lifecycle_stage' => 'customer',
        'email_consent'   => 1,
        'created_at'      => now_utc(),
        'updated_at'      => now_utc(),
    ]);
}

/** @return array<string,mixed> */
function mi_row(int $id): array
{
    return (array) DB::first('SELECT * FROM {memberships} WHERE id = :id', ['id' => $id]);
}

/** @return list<string> */
function mi_events(): array
{
    $names = [];

    foreach (Event::recorded() as $fired) {
        if (str_starts_with($fired['event'], 'membership.')) {
            $names[] = $fired['event'];
        }
    }

    return $names;
}

mi_clear();

// ================================================================= enrolling

Harness::test('a pass sold today works today', function (): void {
    /*
     * At the counter. A pass that waited for a nightly job to make it active
     * would mean a guest standing there with something the system says has
     * not started.
     */
    mi_clear();
    $planId    = mi_plan(['duration_days' => 365, 'price_cents' => 9900]);
    $contactId = mi_contact('Today');

    Event::record();
    $id = MembershipLifecycle::enrol(['plan_id' => $planId, 'contact_id' => $contactId]);
    $fired = mi_events();
    Event::record(false);

    $row = mi_row($id);

    Assert::same('active', (string) $row['status']);
    Assert::same(MembershipLifecycle::today(), substr((string) $row['started_at'], 0, 10));
    Assert::same(
        Membership::expiresOn(['duration_days' => 365], MembershipLifecycle::today()),
        substr((string) $row['expires_at'], 0, 10)
    );
    Assert::matches('/^[A-Z]+-\d{6}$/', (string) $row['member_number']);
    Assert::inArray('membership.started', $fired);

    // The holder is on their own membership, so "who is covered" has one
    // answer rather than "the holder, plus these".
    Assert::same(1, (int) DB::value(
        'SELECT COUNT(*) FROM {membership_members} WHERE membership_id = :m AND is_primary = 1',
        ['m' => $id]
    ));

    // And the money is recorded where a report can find it.
    Assert::same(9900, (int) DB::value(
        'SELECT amount_cents FROM {membership_payments} WHERE membership_id = :m',
        ['m' => $id]
    ));
});

Harness::test('a pass sold for the summer waits until the summer', function (): void {
    mi_clear();
    $planId = mi_plan();

    Event::record();
    $id = MembershipLifecycle::enrol([
        'plan_id'    => $planId,
        'contact_id' => mi_contact('Later'),
        'started_at' => Membership::addDays(MembershipLifecycle::today(), 30),
    ]);
    $fired = mi_events();
    Event::record(false);

    Assert::same('pending', (string) mi_row($id)['status']);
    Assert::same([], $fired, 'nothing starts until it starts');
});

Harness::test('every membership number is unique', function (): void {
    mi_clear();
    $planId  = mi_plan();
    $numbers = [];

    for ($i = 0; $i < 5; $i++) {
        $id = MembershipLifecycle::enrol(['plan_id' => $planId, 'contact_id' => mi_contact('N' . $i)]);
        $numbers[] = (string) mi_row($id)['member_number'];
    }

    Assert::same(5, count(array_unique($numbers)), 'a collision fails the sale at the counter');
});

Harness::test('enrolling refuses a plan or a guest that is not there', function (): void {
    mi_clear();

    Assert::throws(RuntimeException::class, static function (): void {
        MembershipLifecycle::enrol(['plan_id' => 999999, 'contact_id' => mi_contact('Ghost')]);
    });

    $planId = mi_plan();

    Assert::throws(RuntimeException::class, static function () use ($planId): void {
        MembershipLifecycle::enrol(['plan_id' => $planId, 'contact_id' => 0]);
    });
});

// =================================================================== family

Harness::test('a family plan holds its limit and no more', function (): void {
    mi_clear();
    $planId = mi_plan(['is_family_plan' => 1, 'max_members' => 3]);
    $id     = MembershipLifecycle::enrol(['plan_id' => $planId, 'contact_id' => mi_contact('Holder')]);

    // The holder counts as one of the three.
    Assert::true(MembershipLifecycle::addMember($id, mi_contact('Two'), null, 'partner')['added']);
    Assert::true(MembershipLifecycle::addMember($id, mi_contact('Three'), null, 'child')['added']);

    $full = MembershipLifecycle::addMember($id, mi_contact('Four'), null, 'child');

    Assert::false($full['added']);
    Assert::contains('full', $full['reason']);
});

Harness::test('A SINGLE-PERSON PLAN COVERS THE HOLDER AND NOBODY ELSE', function (): void {
    /*
     * The plan form says "blank means one — the holder", and this is what
     * makes that true. Reading a blank limit as unlimited would let a
     * counter add a whole family to a pass sold for one person, which is a
     * revenue leak nobody would ever see.
     */
    mi_clear();
    $id = MembershipLifecycle::enrol(['plan_id' => mi_plan(), 'contact_id' => mi_contact('Alone')]);

    $refused = MembershipLifecycle::addMember($id, mi_contact('Hopeful'), null, 'partner');

    Assert::false($refused['added']);
    Assert::contains('full', $refused['reason']);
});

Harness::test('somebody is not added to the same membership twice', function (): void {
    mi_clear();
    $id      = MembershipLifecycle::enrol([
        'plan_id'    => mi_plan(['is_family_plan' => 1, 'max_members' => 4]),
        'contact_id' => mi_contact('Holder'),
    ]);
    $partner = mi_contact('Partner');

    Assert::true(MembershipLifecycle::addMember($id, $partner, null, 'partner')['added']);

    $again = MembershipLifecycle::addMember($id, $partner, null, 'partner');

    Assert::false($again['added']);
    Assert::contains('already', $again['reason']);
});

// ==================================================================== usage

Harness::test('A VISIT RECORDS ONE USE, HOWEVER MANY TIMES IT IS REPORTED', function (): void {
    mi_clear();
    $planId    = mi_plan(['visit_allowance' => 10, 'duration_days' => null, 'type' => 'multi_visit']);
    $contactId = mi_contact('Puncher');
    $id        = MembershipLifecycle::enrol(['plan_id' => $planId, 'contact_id' => $contactId]);

    $payload = ['contact_id' => $contactId, 'visit_date' => MembershipLifecycle::today()];

    // The same afternoon reported five times, which is what a family checked
    // in one child at a time looks like.
    for ($i = 0; $i < 5; $i++) {
        MembershipLifecycle::recordVisitUsage($payload);
    }

    Assert::same(1, (int) mi_row($id)['visits_used'], 'one punch, not five');
    Assert::same(1, (int) DB::value(
        'SELECT COUNT(*) FROM {membership_usage} WHERE membership_id = :m',
        ['m' => $id]
    ));
});

Harness::test('a different day is a different use', function (): void {
    mi_clear();
    $contactId = mi_contact('Regular');
    $id        = MembershipLifecycle::enrol(['plan_id' => mi_plan(), 'contact_id' => $contactId]);

    MembershipLifecycle::recordVisitUsage([
        'contact_id' => $contactId,
        'visit_date' => Membership::addDays(MembershipLifecycle::today(), -2),
    ]);
    MembershipLifecycle::recordVisitUsage([
        'contact_id' => $contactId,
        'visit_date' => Membership::addDays(MembershipLifecycle::today(), -1),
    ]);

    Assert::same(2, (int) mi_row($id)['visits_used']);
    Assert::notNull(mi_row($id)['last_used_at']);
});

Harness::test('A FAMILY MEMBER USING IT COUNTS, NOT ONLY THE HOLDER', function (): void {
    // The point of a family plan. Reading `memberships.contact_id` instead of
    // the member list would mean a pass that only the bill-payer can use.
    mi_clear();
    $planId  = mi_plan(['is_family_plan' => 1, 'max_members' => 4]);
    $id      = MembershipLifecycle::enrol(['plan_id' => $planId, 'contact_id' => mi_contact('Parent')]);
    $childish = mi_contact('Teenager');

    MembershipLifecycle::addMember($id, $childish, null, 'child');
    MembershipLifecycle::recordVisitUsage(['contact_id' => $childish]);

    Assert::same(1, (int) mi_row($id)['visits_used']);
});

Harness::test('a used-up card records nothing more', function (): void {
    mi_clear();
    $planId    = mi_plan(['visit_allowance' => 1, 'duration_days' => null, 'type' => 'multi_visit']);
    $contactId = mi_contact('Spent');
    $id        = MembershipLifecycle::enrol(['plan_id' => $planId, 'contact_id' => $contactId]);

    MembershipLifecycle::recordVisitUsage(['contact_id' => $contactId]);
    MembershipLifecycle::recordVisitUsage([
        'contact_id' => $contactId,
        'visit_date' => Membership::addDays(MembershipLifecycle::today(), 1),
    ]);

    Assert::same(1, (int) mi_row($id)['visits_used'], 'the allowance is a limit, not a suggestion');
});

Harness::test('recording a visit anywhere in the application reaches the pass', function (): void {
    /*
     * Through the EVENT SEAM rather than a direct call: `VisitRecorder`
     * knows nothing about memberships, and the listener in app/listeners.php
     * is the whole of the wiring.
     */
    mi_clear();
    $contactId = mi_contact('Walker');
    $id        = MembershipLifecycle::enrol(['plan_id' => mi_plan(), 'contact_id' => $contactId]);

    VisitRecorder::record([
        'contact_id'  => $contactId,
        'visit_date'  => MembershipLifecycle::today(),
        'source'      => 'check_in',
        'spend_cents' => 1200,
    ]);

    Assert::same(1, (int) mi_row($id)['visits_used'], 'a check-in used their pass');
});

// ================================================================= freezing

Harness::test('FREEZING GIVES THE TIME BACK', function (): void {
    mi_clear();
    $id = MembershipLifecycle::enrol(['plan_id' => mi_plan(), 'contact_id' => mi_contact('Frozen')]);

    $before = substr((string) mi_row($id)['expires_at'], 0, 10);

    Assert::true(MembershipLifecycle::freeze($id)['ok']);
    Assert::same('frozen', (string) mi_row($id)['status']);

    /*
     * Frozen ten days ago, so unfreezing should move the end date on by ten —
     * IN THE VENUE'S CALENDAR, which is the one `MembershipLifecycle::today()`
     * reads. Built with `gmdate()` this passed all afternoon and failed between
     * 8pm and midnight Eastern, when UTC has rolled over and the venue has not:
     * ten UTC days back is nine local days back, and the test read 9.
     */
    DB::update('memberships', [
        'updated_at' => test_local_day(10, '12:00:00'),
    ], 'id = :id', ['id' => $id]);

    $result = MembershipLifecycle::unfreeze($id);

    Assert::true($result['ok']);
    Assert::same(10, $result['days']);
    Assert::same('active', (string) mi_row($id)['status']);
    Assert::same(
        Membership::addDays($before, 10),
        substr((string) mi_row($id)['expires_at'], 0, 10),
        'the time frozen is given back'
    );
});

Harness::test('a frozen pass does not work at the counter', function (): void {
    mi_clear();
    $contactId = mi_contact('Paused');
    $id        = MembershipLifecycle::enrol(['plan_id' => mi_plan(), 'contact_id' => $contactId]);

    MembershipLifecycle::freeze($id);
    MembershipLifecycle::recordVisitUsage(['contact_id' => $contactId]);

    Assert::same(0, (int) mi_row($id)['visits_used']);
});

Harness::test('only a frozen membership unfreezes', function (): void {
    mi_clear();
    $id = MembershipLifecycle::enrol(['plan_id' => mi_plan(), 'contact_id' => mi_contact('Live')]);

    Assert::false(MembershipLifecycle::unfreeze($id)['ok']);
});

// ================================================================ renewing

Harness::test('RENEWING EARLY KEEPS THE TIME THEY ALREADY PAID FOR', function (): void {
    mi_clear();
    $planId = mi_plan(['duration_days' => 365]);
    $id     = MembershipLifecycle::enrol(['plan_id' => $planId, 'contact_id' => mi_contact('Early')]);

    // A fortnight left on the current term.
    $ends = Membership::addDays(MembershipLifecycle::today(), 14);
    DB::update('memberships', ['expires_at' => $ends, 'status' => 'expiring'], 'id = :id', ['id' => $id]);

    Event::record();
    $result = MembershipLifecycle::renew($id);
    $fired  = mi_events();
    Event::record(false);

    Assert::true($result['ok']);

    // The new year starts the day after the old one ends, so the fortnight
    // is not lost. Restarting from today would quietly take it off them —
    // and renewing early is exactly what a venue wants to encourage.
    Assert::same(
        Membership::expiresOn(['duration_days' => 365], Membership::addDays($ends, 1)),
        $result['expires_at']
    );

    Assert::inArray('membership.renewed', $fired);
    Assert::same(0, (int) mi_row($id)['visits_used'], 'a new term starts at nought visits');
    Assert::null(mi_row($id)['renewal_notice_30_at'], 'and the ladder resets');
});

Harness::test('renewing after it has run out starts from today', function (): void {
    mi_clear();
    $planId = mi_plan(['duration_days' => 30]);
    $id     = MembershipLifecycle::enrol(['plan_id' => $planId, 'contact_id' => mi_contact('Late')]);

    DB::update('memberships', [
        'expires_at' => Membership::addDays(MembershipLifecycle::today(), -20),
        'status'     => 'expired',
    ], 'id = :id', ['id' => $id]);

    $result = MembershipLifecycle::renew($id);

    Assert::same(
        Membership::expiresOn(['duration_days' => 30], MembershipLifecycle::today()),
        $result['expires_at'],
        'they do not get the twenty days they were away'
    );
    Assert::same('active', (string) mi_row($id)['status']);
});

Harness::test('a cancelled membership is not renewed', function (): void {
    mi_clear();
    $id = MembershipLifecycle::enrol(['plan_id' => mi_plan(), 'contact_id' => mi_contact('Gone')]);

    MembershipLifecycle::cancel($id, 'moved away');

    Assert::false(MembershipLifecycle::renew($id)['ok']);
});

// ============================================================== cancelling

Harness::test('cancelling records why, and fires once', function (): void {
    mi_clear();
    $id = MembershipLifecycle::enrol(['plan_id' => mi_plan(), 'contact_id' => mi_contact('Leaver')]);

    Event::record();
    Assert::true(MembershipLifecycle::cancel($id, 'moved out of the area')['ok']);
    $fired = mi_events();

    Assert::inArray('membership.cancelled', $fired);

    $payload = null;

    foreach (Event::recorded() as $event) {
        if ($event['event'] === 'membership.cancelled') {
            $payload = $event['payload'];
        }
    }

    Event::record(false);

    Assert::same('moved out of the area', (string) ($payload['reason'] ?? ''));

    $row = mi_row($id);

    Assert::same('cancelled', (string) $row['status']);
    Assert::notNull($row['cancelled_at']);
    Assert::same(0, (int) $row['auto_renew'], 'and it will not bill them again');
});

// ==================================================================== sweep

Harness::test('THE SWEEP ADVANCES EVERY MEMBERSHIP AND FIRES WHAT IT SHOULD', function (): void {
    mi_clear();
    $planId = mi_plan(['duration_days' => 365]);

    // One starting today, one 20 days from the end, one that ran out
    // yesterday, one that ran out three months ago.
    $starting = MembershipLifecycle::enrol([
        'plan_id'    => $planId,
        'contact_id' => mi_contact('Starting'),
        'started_at' => MembershipLifecycle::today(),
    ]);
    DB::update('memberships', ['status' => 'pending'], 'id = :id', ['id' => $starting]);

    $expiring = MembershipLifecycle::enrol(['plan_id' => $planId, 'contact_id' => mi_contact('Expiring')]);
    DB::update('memberships', [
        'expires_at' => Membership::addDays(MembershipLifecycle::today(), 20),
    ], 'id = :id', ['id' => $expiring]);

    $expired = MembershipLifecycle::enrol(['plan_id' => $planId, 'contact_id' => mi_contact('Expired')]);
    DB::update('memberships', [
        'expires_at' => Membership::addDays(MembershipLifecycle::today(), -1),
    ], 'id = :id', ['id' => $expired]);

    $lapsed = MembershipLifecycle::enrol(['plan_id' => $planId, 'contact_id' => mi_contact('Lapsed')]);
    DB::update('memberships', [
        'status'     => 'expired',
        'expires_at' => Membership::addDays(MembershipLifecycle::today(), -90),
    ], 'id = :id', ['id' => $lapsed]);

    Event::record();
    $counts = MembershipLifecycle::sweep();
    $fired  = mi_events();
    Event::record(false);

    Assert::same('active', (string) mi_row($starting)['status']);
    Assert::same('expiring', (string) mi_row($expiring)['status']);
    Assert::same('expired', (string) mi_row($expired)['status']);
    Assert::same('lapsed', (string) mi_row($lapsed)['status']);

    Assert::inArray('membership.started', $fired);
    Assert::inArray('membership.expired', $fired);
    Assert::greaterThan(3, $counts['changed']);
});

Harness::test('the sweep is idempotent', function (): void {
    // It runs every cron tick. A second pass must write nothing and fire
    // nothing, or every welcome series would go out once a minute.
    mi_clear();
    $planId = mi_plan();
    $id     = MembershipLifecycle::enrol(['plan_id' => $planId, 'contact_id' => mi_contact('Steady')]);

    MembershipLifecycle::sweep();

    Event::record();
    $second = MembershipLifecycle::sweep();
    $fired  = mi_events();
    Event::record(false);

    Assert::same(0, $second['changed'], 'nothing left to change');
    Assert::same([], $fired, 'and nothing fired twice');
    Assert::same('active', (string) mi_row($id)['status']);
});

Harness::test('the sweep leaves frozen and cancelled memberships alone', function (): void {
    mi_clear();
    $planId = mi_plan();

    $frozen = MembershipLifecycle::enrol(['plan_id' => $planId, 'contact_id' => mi_contact('Ice')]);
    MembershipLifecycle::freeze($frozen);
    DB::update('memberships', [
        'expires_at' => Membership::addDays(MembershipLifecycle::today(), -5),
    ], 'id = :id', ['id' => $frozen]);

    $cancelled = MembershipLifecycle::enrol(['plan_id' => $planId, 'contact_id' => mi_contact('Done')]);
    MembershipLifecycle::cancel($cancelled);

    MembershipLifecycle::sweep();

    Assert::same('frozen', (string) mi_row($frozen)['status'], 'frozen does not run down');
    Assert::same('cancelled', (string) mi_row($cancelled)['status']);
});

Harness::test('THE RENEWAL LADDER IS STAMPED ONCE PER RUNG', function (): void {
    mi_clear();
    $planId = mi_plan(['duration_days' => 365]);
    $id     = MembershipLifecycle::enrol(['plan_id' => $planId, 'contact_id' => mi_contact('Renewing')]);

    DB::update('memberships', [
        'status'     => 'expiring',
        'expires_at' => Membership::addDays(MembershipLifecycle::today(), 20),
    ], 'id = :id', ['id' => $id]);

    Assert::same(1, MembershipLifecycle::stampRenewalNotices(), 'the 30-day rung');
    Assert::notNull(mi_row($id)['renewal_notice_30_at']);

    // Run again the same day: nothing, because the rung is stamped.
    Assert::same(0, MembershipLifecycle::stampRenewalNotices());

    // A fortnight out: the next rung, and only that one.
    DB::update('memberships', [
        'expires_at' => Membership::addDays(MembershipLifecycle::today(), 10),
    ], 'id = :id', ['id' => $id]);

    Assert::same(1, MembershipLifecycle::stampRenewalNotices());
    Assert::notNull(mi_row($id)['renewal_notice_14_at']);
    Assert::null(mi_row($id)['renewal_notice_3_at'], 'not the last one yet');
});

mi_clear();
