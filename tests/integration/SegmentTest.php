<?php

declare(strict_types=1);

/**
 * Does a segment select the RIGHT people?
 *
 * The unit suite proves the compiled SQL is safe and well-formed. It cannot
 * prove it is correct: a rule joined to the wrong column, or with its
 * condition inverted, compiles perfectly and runs without error. The only way
 * to know is to put known guests in a database and check exactly who comes
 * back.
 *
 * So every test here builds a small, fully-specified population and asserts
 * membership BOTH WAYS — who is in and who is out. A rule that matched
 * everybody would pass a "the right guest is included" test on its own, and
 * matching everybody is the failure mode that actually costs money.
 */

use App\Core\DB;
use App\Services\SegmentEngine as S;
use Tests\Assert;
use Tests\Harness;

Harness::suite('integration/segments');

if (!test_boot()) {
    Harness::skip('segments suite', 'no database available');

    return;
}

// ------------------------------------------------------------------ fixtures

/**
 * A guest of this suite's own.
 *
 * Named by a marker in `source` rather than by first name, because several
 * tests need the name itself to be the thing under test.
 */
function sg_contact(array $overrides = []): int
{
    static $n = 0;
    $n++;

    return DB::insert('contacts', array_merge([
        'first_name'      => 'SegGuest' . $n,
        'last_name'       => 'Case' . $n,
        'email'           => 'seg.' . bin2hex(random_bytes(4)) . '@example.test',
        'lifecycle_stage' => 'lead',
        'source'          => 'segtest',
        'created_at'      => now_utc(),
        'updated_at'      => now_utc(),
    ], $overrides));
}

/**
 * Who does this rule match, out of this suite's own guests?
 *
 * Scoped to `source = 'segtest'` so the seeded demo data cannot make a test
 * pass or fail — and the scoping is ANDed onto the engine's own clause rather
 * than replacing it, so the engine's deleted-guest guard is still exercised.
 *
 * @param array<string,mixed> $rules
 * @return list<int>
 */
function sg_matches(array $rules): array
{
    $compiled = S::compile($rules);

    $rows = DB::all(
        'SELECT c.id FROM {contacts} c
          WHERE ' . $compiled['sql'] . " AND c.source = 'segtest'
          ORDER BY c.id",
        $compiled['params']
    );

    return array_map(static fn (array $row): int => (int) $row['id'], $rows);
}

/** @param array<string,mixed> $rules */
function sg_assert(array $rules, array $expected, array $excluded, string $because): void
{
    $matched = sg_matches($rules);

    foreach ($expected as $id) {
        Assert::inArray($id, $matched, $because . ' — ' . $id . ' should be in');
    }

    foreach ($excluded as $id) {
        Assert::false(
            in_array($id, $matched, true),
            $because . ' — ' . $id . ' should be OUT (matched: ' . implode(',', $matched) . ')'
        );
    }
}

function sg_clear(): void
{
    DB::query(
        "DELETE FROM {taggables} WHERE entity_type = 'contact' AND entity_id IN (
            SELECT id FROM {contacts} WHERE source = 'segtest'
         )"
    );
    DB::query(
        "DELETE FROM {children} WHERE contact_id IN (
            SELECT id FROM {contacts} WHERE source = 'segtest'
         )"
    );
    DB::query(
        "DELETE FROM {children} WHERE household_id IN (
            SELECT id FROM {households} WHERE name LIKE 'SegTest%'
         )"
    );
    DB::query(
        "DELETE FROM {booking_resources} WHERE booking_id IN (
            SELECT id FROM {bookings} WHERE contact_id IN (
                SELECT id FROM {contacts} WHERE source = 'segtest'
            )
         )"
    );
    DB::query(
        "DELETE FROM {bookings} WHERE contact_id IN (
            SELECT id FROM {contacts} WHERE source = 'segtest'
         )"
    );
    DB::query(
        "DELETE FROM {memberships} WHERE contact_id IN (
            SELECT id FROM {contacts} WHERE source = 'segtest'
         )"
    );
    DB::query(
        "DELETE FROM {messages} WHERE contact_id IN (
            SELECT id FROM {contacts} WHERE source = 'segtest'
         )"
    );
    DB::query("DELETE FROM {contacts} WHERE source = 'segtest'");
    DB::query("DELETE FROM {households} WHERE name LIKE 'SegTest%'");
    DB::query("DELETE FROM {tags} WHERE name LIKE 'SegTest%'");
    DB::query("DELETE FROM {segments} WHERE slug LIKE 'segtest-%'");
}

sg_clear();

// ------------------------------------------------------------- the direction

Harness::test('AN EMPTY SEGMENT MATCHES NOBODY, against real rows', function (): void {
    // The unit suite asserts the SQL says `1 = 0`. This asserts what that
    // means with a populated table behind it — which is the claim somebody
    // actually relies on before pressing send.
    $a = sg_contact();
    $b = sg_contact();

    try {
        Assert::same([], sg_matches([]), 'no rules, nobody');
        Assert::same([], sg_matches(['match' => 'all', 'rules' => []]));

        // The control: these guests ARE findable, so the empty result above
        // is the rules and not an empty table.
        Assert::same(
            [$a, $b],
            sg_matches(['match' => 'all', 'rules' => [['field' => 'source', 'op' => 'is', 'value' => 'segtest']]]),
            'and they are there to be found'
        );
    } finally {
        sg_clear();
    }
});

Harness::test('A DELETED GUEST IS NEVER MATCHED', function (): void {
    $live    = sg_contact(['email_consent' => 1]);
    $deleted = sg_contact(['email_consent' => 1, 'deleted_at' => now_utc()]);

    try {
        sg_assert(
            ['match' => 'all', 'rules' => [['field' => 'email_consent', 'op' => 'is_true']]],
            [$live],
            [$deleted],
            'a guest who asked to be erased is not a recipient'
        );
    } finally {
        sg_clear();
    }
});

// ------------------------------------------------------------------ columns

Harness::test('consent, both ways round', function (): void {
    $yes    = sg_contact(['email_consent' => 1]);
    $no     = sg_contact(['email_consent' => 0]);
    $unset  = sg_contact();

    try {
        sg_assert(
            ['match' => 'all', 'rules' => [['field' => 'email_consent', 'op' => 'is_true']]],
            [$yes],
            [$no, $unset],
            'said yes'
        );

        // "is no" must include the guests whose flag was never set. An unset
        // consent flag means no, and a rule that only found explicit zeros
        // would miss most of a real list.
        sg_assert(
            ['match' => 'all', 'rules' => [['field' => 'email_consent', 'op' => 'is_false']]],
            [$no, $unset],
            [$yes],
            'has not said yes'
        );
    } finally {
        sg_clear();
    }
});

Harness::test('AN "IS NOT" INCLUDES THE GUESTS WITH NOTHING IN THAT COLUMN', function (): void {
    // `column <> 'x'` is FALSE for a NULL in SQL, so "city is not Chester"
    // naively written excludes every guest whose town is unknown — which is
    // most of a real list, and the opposite of what anybody means.
    $chester = sg_contact(['city' => 'Chester']);
    $goshen  = sg_contact(['city' => 'Goshen']);
    $unknown = sg_contact(['city' => null]);
    $blank   = sg_contact(['city' => '']);

    try {
        sg_assert(
            ['match' => 'all', 'rules' => [['field' => 'city', 'op' => 'is_not', 'value' => 'Chester']]],
            [$goshen, $unknown, $blank],
            [$chester],
            'everybody not in Chester, including the ones we do not know about'
        );

        sg_assert(
            ['match' => 'all', 'rules' => [['field' => 'city', 'op' => 'is', 'value' => 'Chester']]],
            [$chester],
            [$goshen, $unknown, $blank],
            'and the plain match is exact'
        );

        sg_assert(
            ['match' => 'all', 'rules' => [['field' => 'city', 'op' => 'is_set']]],
            [$chester, $goshen],
            [$unknown, $blank],
            'is_set treats an empty string as empty, not as set'
        );
    } finally {
        sg_clear();
    }
});

Harness::test('a LIKE value is matched literally, wildcards and all', function (): void {
    // A guest whose surname contains a per-cent sign is a guest, not a
    // pattern. If the escaping were missing, `100%` would match everybody.
    $literal = sg_contact(['last_name' => 'Smith 100% Fan']);
    $other   = sg_contact(['last_name' => 'Jones']);
    $tricky  = sg_contact(['last_name' => 'Smith 1004 Fan']);

    try {
        sg_assert(
            ['match' => 'all', 'rules' => [['field' => 'last_name', 'op' => 'contains', 'value' => '100%']]],
            [$literal],
            [$other, $tricky],
            'the per-cent sign is a per-cent sign'
        );

        // An underscore is SQL's single-character wildcard, so the same rule
        // applies: "100_" must not match "1004".
        sg_assert(
            ['match' => 'all', 'rules' => [['field' => 'last_name', 'op' => 'contains', 'value' => '100_']]],
            [],
            [$literal, $other, $tricky],
            'and an underscore matches only an underscore'
        );
    } finally {
        sg_clear();
    }
});

Harness::test('MONEY TYPED IN DOLLARS FINDS THE RIGHT SPENDERS', function (): void {
    // The failure this prevents: reading "200" as cents would compare a
    // two-dollar threshold against lifetime spend and match everybody who
    // ever came through the door.
    $big   = sg_contact(['lifetime_value_cents' => 45000]);  // $450
    $exact = sg_contact(['lifetime_value_cents' => 20000]);  // $200
    $small = sg_contact(['lifetime_value_cents' => 1500]);   // $15
    $none  = sg_contact();

    try {
        sg_assert(
            ['match' => 'all', 'rules' => [['field' => 'lifetime_value_cents', 'op' => 'gte', 'value' => '200']]],
            [$big, $exact],
            [$small, $none],
            'at least two hundred dollars'
        );

        sg_assert(
            ['match' => 'all', 'rules' => [['field' => 'lifetime_value_cents', 'op' => 'gt', 'value' => '200']]],
            [$big],
            [$exact, $small, $none],
            'and more than it excludes exactly it'
        );
    } finally {
        sg_clear();
    }
});

Harness::test('a relative window finds the recent and not the old', function (): void {
    $recent = sg_contact(['last_visit_at' => gmdate('Y-m-d H:i:s', time() - 10 * 86400)]);
    $old    = sg_contact(['last_visit_at' => gmdate('Y-m-d H:i:s', time() - 200 * 86400)]);
    $never  = sg_contact();

    try {
        sg_assert(
            ['match' => 'all', 'rules' => [['field' => 'last_visit_at', 'op' => 'in_last_days', 'value' => 90]]],
            [$recent],
            [$old, $never],
            'visited in the last ninety days'
        );

        sg_assert(
            ['match' => 'all', 'rules' => [['field' => 'last_visit_at', 'op' => 'is_not_set']]],
            [$never],
            [$recent, $old],
            'and never visited is its own question'
        );
    } finally {
        sg_clear();
    }
});

Harness::test('a between is inclusive at both ends', function (): void {
    $two   = sg_contact(['visit_count' => 2]);
    $five  = sg_contact(['visit_count' => 5]);
    $ten   = sg_contact(['visit_count' => 10]);
    $one   = sg_contact(['visit_count' => 1]);
    $eleven = sg_contact(['visit_count' => 11]);

    try {
        sg_assert(
            ['match' => 'all', 'rules' => [['field' => 'visit_count', 'op' => 'between', 'value' => [2, 10]]]],
            [$two, $five, $ten],
            [$one, $eleven],
            'two to ten includes two and ten'
        );
    } finally {
        sg_clear();
    }
});

// ------------------------------------------------------------- other tables

Harness::test('A TAG MATCHES ONLY TAGS ON CONTACTS', function (): void {
    // `taggables` is polymorphic. Without the entity type in the join, a tag
    // put on a BOOKING whose id happens to equal this contact's would read as
    // a tag on the guest — and this fixture arranges exactly that collision.
    $tagged   = sg_contact();
    $untagged = sg_contact();

    $tagId = DB::insert('tags', [
        'name'       => 'SegTest VIP',
        'created_at' => now_utc(),
    ]);

    try {
        DB::query(
            'INSERT INTO {taggables} (tag_id, entity_type, entity_id, created_at)
             VALUES (:t, :type, :id, :at)',
            ['t' => $tagId, 'type' => 'contact', 'id' => $tagged, 'at' => now_utc()]
        );

        // The same tag, the same id, a DIFFERENT entity type. A join that
        // ignored the type would report $untagged as tagged.
        DB::query(
            'INSERT INTO {taggables} (tag_id, entity_type, entity_id, created_at)
             VALUES (:t, :type, :id, :at)',
            ['t' => $tagId, 'type' => 'booking', 'id' => $untagged, 'at' => now_utc()]
        );

        sg_assert(
            ['match' => 'all', 'rules' => [['field' => 'tag', 'op' => 'has_tag', 'value' => 'SegTest VIP']]],
            [$tagged],
            [$untagged],
            'a tag on a booking is not a tag on a guest'
        );

        sg_assert(
            ['match' => 'all', 'rules' => [['field' => 'tag', 'op' => 'not_has_tag', 'value' => 'SegTest VIP']]],
            [$untagged],
            [$tagged],
            'and the absence is the complement'
        );
    } finally {
        sg_clear();
        DB::delete('tags', 'id = :id', ['id' => $tagId]);
    }
});

Harness::test('A CANCELLED PARTY IS NOT A PARTY THEY HAD', function (): void {
    // "Guests who have had a party" meaning "guests who once enquired and
    // cancelled" would put a thank-you offer in front of somebody whose party
    // never happened.
    $had       = sg_contact();
    $cancelled = sg_contact();
    $none      = sg_contact();

    try {
        foreach ([[$had, 'completed'], [$cancelled, 'cancelled']] as [$contactId, $status]) {
            DB::insert('bookings', [
                'booking_number' => 'SEG-' . bin2hex(random_bytes(4)),
                'type'           => 'birthday',
                'status'         => $status,
                'contact_id'     => $contactId,
                'event_date'     => gmdate('Y-m-d', time() - 30 * 86400),
                'start_time'     => '14:00:00',
                'end_time'       => '16:00:00',
                'guest_count'    => 12,
                'created_at'     => now_utc(),
                'updated_at'     => now_utc(),
            ]);
        }

        sg_assert(
            ['match' => 'all', 'rules' => [['field' => 'has_booking', 'op' => 'ever']]],
            [$had],
            [$cancelled, $none],
            'a cancelled party does not count'
        );

        sg_assert(
            ['match' => 'all', 'rules' => [['field' => 'has_booking', 'op' => 'never']]],
            [$cancelled, $none],
            [$had],
            'and "never" says so'
        );

        sg_assert(
            ['match' => 'all', 'rules' => [['field' => 'has_booking', 'op' => 'in_last_days', 'value' => 60]]],
            [$had],
            [$cancelled, $none],
            'within the window'
        );

        sg_assert(
            ['match' => 'all', 'rules' => [['field' => 'has_booking', 'op' => 'in_last_days', 'value' => 7]]],
            [],
            [$had, $cancelled, $none],
            'and a tighter window finds nobody'
        );
    } finally {
        sg_clear();
    }
});

Harness::test('A CHILD\'S BIRTH MONTH REACHES THE HOUSEHOLD, and both month sources', function (): void {
    // The reason a party venue wants segments at all: an offer six weeks
    // before a child's birthday is the most valuable message this application
    // sends. Three fixtures, each a real shape:
    //
    //   * a child on the contact, with a full birth date
    //   * a child on the HOUSEHOLD and not the contact — a party booked by
    //     one parent with the child recorded against the other
    //   * a child whose parent gave the MONTH and not the day, which is
    //     common and which a birth_date-only rule would miss entirely
    $direct    = sg_contact();
    $viaHouse  = sg_contact();
    $monthOnly = sg_contact();
    $noKids    = sg_contact();
    $wrongMonth = sg_contact();

    $householdId = DB::insert('households', [
        'name'       => 'SegTest Household',
        'created_at' => now_utc(),
        'updated_at' => now_utc(),
    ]);

    DB::update('contacts', ['household_id' => $householdId], 'id = :id', ['id' => $viaHouse]);

    try {
        DB::insert('children', [
            'contact_id' => $direct,
            'first_name' => 'Direct Child',
            'birth_date' => '2018-05-14',
            'birth_month' => 5,
            'created_at' => now_utc(),
            'updated_at' => now_utc(),
        ]);

        DB::insert('children', [
            'household_id' => $householdId,
            'first_name'   => 'Household Child',
            'birth_date'   => '2019-05-02',
            'birth_month'  => 5,
            'created_at'   => now_utc(),
            'updated_at'   => now_utc(),
        ]);

        // THE MONTH-ONLY CASE: no birth_date at all.
        DB::insert('children', [
            'contact_id'  => $monthOnly,
            'first_name'  => 'Month Only Child',
            'birth_date'  => null,
            'birth_month' => 5,
            'created_at'  => now_utc(),
            'updated_at'  => now_utc(),
        ]);

        DB::insert('children', [
            'contact_id'  => $wrongMonth,
            'first_name'  => 'November Child',
            'birth_date'  => '2017-11-20',
            'birth_month' => 11,
            'created_at'  => now_utc(),
            'updated_at'  => now_utc(),
        ]);

        sg_assert(
            ['match' => 'all', 'rules' => [['field' => 'child_birth_month', 'op' => 'is', 'value' => 5]]],
            [$direct, $viaHouse, $monthOnly],
            [$noKids, $wrongMonth],
            'a May birthday, however it is recorded and whichever parent holds it'
        );

        sg_assert(
            ['match' => 'all', 'rules' => [['field' => 'child_birth_month', 'op' => 'is', 'value' => 11]]],
            [$wrongMonth],
            [$direct, $viaHouse, $monthOnly, $noKids],
            'and November is a different question'
        );

        sg_assert(
            ['match' => 'all', 'rules' => [['field' => 'child_birth_month', 'op' => 'is_not', 'value' => 5]]],
            [$noKids, $wrongMonth],
            [$direct, $viaHouse, $monthOnly],
            '"not May" includes the guests with no children at all'
        );

        // A REMOVED CHILD IS NOT A REASON TO MAIL.
        DB::update('children', ['deleted_at' => now_utc()], 'contact_id = :id', ['id' => $direct]);

        sg_assert(
            ['match' => 'all', 'rules' => [['field' => 'child_birth_month', 'op' => 'is', 'value' => 5]]],
            [$viaHouse, $monthOnly],
            [$direct],
            'a deleted child stops matching'
        );
    } finally {
        sg_clear();
        DB::delete('households', 'id = :id', ['id' => $householdId]);
    }
});

Harness::test('a membership status matches the status, not the mere existence', function (): void {
    $active    = sg_contact();
    $expired   = sg_contact();
    $noMember  = sg_contact();

    // `memberships.plan_id` and `member_number` are NOT NULL with no default,
    // so a membership needs a plan behind it — which is also truer to life
    // than a bare status row.
    $planId = DB::insert('membership_plans', [
        'name'        => 'SegTest Season Pass',
        'slug'        => 'segtest-season-' . bin2hex(random_bytes(3)),
        'type'        => 'season_pass',
        'price_cents' => 19900,
        'created_at'  => now_utc(),
        'updated_at'  => now_utc(),
    ]);

    try {
        foreach ([[$active, 'active'], [$expired, 'expired']] as [$contactId, $status]) {
            DB::insert('memberships', [
                'plan_id'       => $planId,
                'member_number' => 'SEG-' . bin2hex(random_bytes(4)),
                'contact_id'    => $contactId,
                'status'        => $status,
                'created_at'    => now_utc(),
                'updated_at'    => now_utc(),
            ]);
        }

        sg_assert(
            ['match' => 'all', 'rules' => [['field' => 'membership_status', 'op' => 'is', 'value' => 'active']]],
            [$active],
            [$expired, $noMember],
            'only the live members'
        );

        sg_assert(
            ['match' => 'all', 'rules' => [['field' => 'membership_status', 'op' => 'is', 'value' => 'expired']]],
            [$expired],
            [$active, $noMember],
            'and the lapsed ones are their own group — the reason to mail them'
        );

        sg_assert(
            ['match' => 'all', 'rules' => [['field' => 'membership_status', 'op' => 'never']]],
            [$noMember],
            [$active, $expired],
            'never a member'
        );
    } finally {
        sg_clear();
        DB::delete('membership_plans', 'id = :id', ['id' => $planId]);
    }
});

Harness::test('campaign engagement counts campaign messages only', function (): void {
    // A guest who opened a booking CONFIRMATION has not engaged with a
    // campaign, and counting it would make every guest look engaged.
    $opened      = sg_contact();
    $transactional = sg_contact();
    $nothing     = sg_contact();

    $campaignId = DB::insert('campaigns', [
        'name'       => 'SegTest campaign',
        'channel'    => 'email',
        'status'     => 'sent',
        'created_at' => now_utc(),
    ]);

    try {
        DB::insert('messages', [
            'contact_id'  => $opened,
            'direction'   => 'outbound',
            'channel'     => 'email',
            'campaign_id' => $campaignId,
            'status'      => 'sent',
            'opened_at'   => gmdate('Y-m-d H:i:s', time() - 5 * 86400),
            'created_at'  => now_utc(),
        ]);

        // Opened, but not a campaign — so it must not count.
        DB::insert('messages', [
            'contact_id' => $transactional,
            'direction'  => 'outbound',
            'channel'    => 'email',
            'status'     => 'sent',
            'opened_at'  => gmdate('Y-m-d H:i:s', time() - 5 * 86400),
            'created_at' => now_utc(),
        ]);

        sg_assert(
            ['match' => 'all', 'rules' => [['field' => 'campaign_opened', 'op' => 'ever']]],
            [$opened],
            [$transactional, $nothing],
            'a confirmation they opened is not campaign engagement'
        );

        sg_assert(
            ['match' => 'all', 'rules' => [['field' => 'campaign_opened', 'op' => 'in_last_days', 'value' => 30]]],
            [$opened],
            [$transactional, $nothing],
            'recently'
        );

        sg_assert(
            ['match' => 'all', 'rules' => [['field' => 'campaign_opened', 'op' => 'not_in_last_days', 'value' => 30]]],
            [$transactional, $nothing],
            [$opened],
            'and the re-engagement segment is the complement — the people to win back'
        );

        sg_assert(
            ['match' => 'all', 'rules' => [['field' => 'campaign_clicked', 'op' => 'ever']]],
            [],
            [$opened, $transactional, $nothing],
            'opening is not clicking'
        );
    } finally {
        sg_clear();
        DB::delete('campaigns', 'id = :id', ['id' => $campaignId]);
    }
});

// ------------------------------------------------------------------ nesting

Harness::test('A REAL SEGMENT: consented spenders with a July child or a recent party', function (): void {
    // The shape a venue actually builds, and the one where precedence
    // matters: `a AND b OR c` is not `a AND (b OR c)`, and the difference
    // between them is who gets the message.
    $julyChild = sg_contact(['email_consent' => 1, 'lifetime_value_cents' => 30000]);
    $recent    = sg_contact(['email_consent' => 1, 'lifetime_value_cents' => 30000]);
    $noConsent = sg_contact(['email_consent' => 0, 'lifetime_value_cents' => 30000]);
    $tooCheap  = sg_contact(['email_consent' => 1, 'lifetime_value_cents' => 500]);
    $neither   = sg_contact(['email_consent' => 1, 'lifetime_value_cents' => 30000]);

    try {
        DB::insert('children', [
            'contact_id'  => $julyChild,
            'first_name'  => 'July Child',
            'birth_date'  => '2018-07-04',
            'birth_month' => 7,
            'created_at'  => now_utc(),
            'updated_at'  => now_utc(),
        ]);

        // $noConsent also has a July child, so only the consent rule keeps
        // them out — which is what makes the AND load-bearing.
        DB::insert('children', [
            'contact_id'  => $noConsent,
            'first_name'  => 'Other July Child',
            'birth_date'  => '2019-07-11',
            'birth_month' => 7,
            'created_at'  => now_utc(),
            'updated_at'  => now_utc(),
        ]);

        DB::insert('bookings', [
            'booking_number' => 'SEG-' . bin2hex(random_bytes(4)),
            'type'           => 'birthday',
            'status'         => 'completed',
            'contact_id'     => $recent,
            'event_date'     => gmdate('Y-m-d', time() - 20 * 86400),
            'start_time'     => '14:00:00',
            'end_time'       => '16:00:00',
            'guest_count'    => 12,
            'created_at'     => now_utc(),
            'updated_at'     => now_utc(),
        ]);

        $rules = [
            'match' => 'all',
            'rules' => [
                ['field' => 'email_consent', 'op' => 'is_true'],
                ['field' => 'lifetime_value_cents', 'op' => 'gte', 'value' => '100'],
                [
                    'match' => 'any',
                    'rules' => [
                        ['field' => 'child_birth_month', 'op' => 'is', 'value' => 7],
                        ['field' => 'has_booking', 'op' => 'in_last_days', 'value' => 60],
                    ],
                ],
            ],
        ];

        sg_assert(
            $rules,
            [$julyChild, $recent],
            [$noConsent, $tooCheap, $neither],
            'both branches of the OR are in, and every AND still bites'
        );

        // And it reads back as something a person can check before sending.
        $described = S::describe($rules);

        Assert::contains('Said yes to email is yes', $described);
        Assert::contains('July', $described);
        Assert::contains(' or ', $described);
    } finally {
        sg_clear();
    }
});

Harness::test('and the same rules under "any" instead of "all" widen as expected', function (): void {
    // The control for the test above. If the match mode were ignored, both
    // would return the same people and neither test would mean anything.
    $consented = sg_contact(['email_consent' => 1, 'lifetime_value_cents' => 0]);
    $spender   = sg_contact(['email_consent' => 0, 'lifetime_value_cents' => 50000]);
    $neither   = sg_contact(['email_consent' => 0, 'lifetime_value_cents' => 0]);

    $rules = [
        ['field' => 'email_consent', 'op' => 'is_true'],
        ['field' => 'lifetime_value_cents', 'op' => 'gte', 'value' => '100'],
    ];

    try {
        sg_assert(
            ['match' => 'all', 'rules' => $rules],
            [],
            [$consented, $spender, $neither],
            'nobody is both'
        );

        sg_assert(
            ['match' => 'any', 'rules' => $rules],
            [$consented, $spender],
            [$neither],
            'while either alone is enough under "any"'
        );
    } finally {
        sg_clear();
    }
});

// ------------------------------------------------------------------- storage

/**
 * The same tree, ignoring the order of an object's keys.
 *
 * Needed because `segments.rules` is a JSON column and the two engines this
 * application supports treat that differently: MySQL 8's JSON is a real type
 * that NORMALISES on storage — it sorts object keys by length and then by
 * name, so `{"field":…,"op":…}` reads back as `{"op":…,"field":…}` — while
 * MariaDB's JSON is an alias for LONGTEXT and stores the string verbatim.
 *
 * Key order carries no meaning in JSON and the engine reads these rules by
 * key, so what matters is that the tree is the same, not that the bytes are.
 * @param array<mixed> $tree
 * @return array<mixed>
 */
function sg_sorted(array $tree): array
{
    $out = [];

    foreach ($tree as $key => $value) {
        $out[$key] = is_array($value) ? sg_sorted($value) : $value;
    }

    ksort($out);

    return $out;
}

Harness::test('a stored segment round-trips through JSON and still selects the same people', function (): void {
    // Rules are stored as JSON in `segments.rules`. A segment that compiled
    // when it was built and not when it was read back would fail at send
    // time, which is the worst possible moment.
    $wanted = sg_contact(['email_consent' => 1, 'visit_count' => 5]);
    $other  = sg_contact(['email_consent' => 1, 'visit_count' => 1]);

    $rules = [
        'match' => 'all',
        'rules' => [
            ['field' => 'email_consent', 'op' => 'is_true'],
            ['field' => 'visit_count', 'op' => 'gte', 'value' => 3],
        ],
    ];

    try {
        $segmentId = DB::insert('segments', [
            'name'       => 'SegTest regulars',
            'slug'       => 'segtest-regulars',
            'rules'      => json_encode($rules, JSON_UNESCAPED_SLASHES),
            'is_dynamic' => 1,
            'created_at' => now_utc(),
            'updated_at' => now_utc(),
        ]);

        $stored = DB::value('SELECT rules FROM {segments} WHERE id = :id', ['id' => $segmentId]);
        $read   = json_decode((string) $stored, true);

        Assert::true(is_array($read), 'the rules come back as an array');
        /*
         * The same TREE, not the same bytes. Asserting byte-for-byte equality
         * here was asserting the storage engine's normalisation rather than
         * anything about this application: MySQL 8 reorders a JSON object's
         * keys and MariaDB does not, so the test passed on one engine and
         * failed on the other for a difference that cannot affect behaviour —
         * every rule is read by key. MEASURED against mysql:8.0.46.
         *
         * Types are still compared strictly, so a value that came back as the
         * string "3" instead of the number 3 would still fail.
         */
        Assert::same(sg_sorted($rules), sg_sorted($read), 'the same tree, whatever order the keys come back in');

        sg_assert(
            $read,
            [$wanted],
            [$other],
            'and it selects the same people after the round trip'
        );
    } finally {
        sg_clear();
    }
});

Harness::test('the suite leaves nothing behind', function (): void {
    sg_clear();

    Assert::same(
        0,
        (int) DB::value("SELECT COUNT(*) FROM {contacts} WHERE source = 'segtest'")
    );
});
