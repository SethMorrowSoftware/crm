<?php

declare(strict_types=1);

/**
 * How a visit comes to exist, against a real database.
 *
 * Admission at the Castle is free and there is no gate scan, so a visit is
 * DERIVED — from a party that happened, from a guest ticked in at the desk,
 * and eventually from the CenterEdge card ledger. Three writers for one fact,
 * which is why `VisitRecorder` exists and why these tests are mostly about
 * what happens when two of them describe the same afternoon.
 *
 * What rides on this being right:
 *
 *   * **Every lapsed-guest and loyalty automation keys off `last_visit_at`.**
 *     Before this existed nothing in the application wrote a visit, so a
 *     family who had been to fourteen parties read as never having visited and
 *     the win-back sequence went to them instead of to the people who had
 *     actually drifted away.
 *   * **Lifetime value is on the contact page in dollars.** It is DERIVED from
 *     the visits on every write rather than incremented, so a corrected or
 *     deleted visit leaves the figure right. An incremented counter is only
 *     ever as correct as the last write that touched it.
 *   * **One visit per family per day.** The eleven children at a party are
 *     ticked in one at a time; the thank-you must not go eleven times.
 */

use App\Core\DB;
use App\Core\Event;
use App\Services\VisitRecorder;
use Tests\Assert;
use Tests\Harness;

Harness::suite('integration/visits');

if (!test_boot()) {
    Harness::skip('visits suite', 'no database available');

    return;
}

// ------------------------------------------------------------------ fixtures

function vi_clear(): void
{
    DB::query("DELETE FROM {visits} WHERE contact_id IN (SELECT id FROM {contacts} WHERE source = 'vitest')");
    DB::query(
        "DELETE FROM {booking_payments} WHERE booking_id IN (
            SELECT id FROM {bookings} WHERE contact_id IN (SELECT id FROM {contacts} WHERE source = 'vitest'))"
    );
    DB::query(
        "DELETE FROM {booking_guests} WHERE booking_id IN (
            SELECT id FROM {bookings} WHERE contact_id IN (SELECT id FROM {contacts} WHERE source = 'vitest'))"
    );
    DB::query("DELETE FROM {bookings} WHERE contact_id IN (SELECT id FROM {contacts} WHERE source = 'vitest')");
    DB::query("DELETE FROM {contacts} WHERE source = 'vitest'");
}

function vi_contact(string $name = 'Visitor'): int
{
    return (int) DB::insert('contacts', [
        'first_name'      => $name,
        'last_name'       => 'ViTest',
        'email'           => 'vitest.' . strtolower($name) . '.' . bin2hex(random_bytes(3)) . '@example.test',
        'source'          => 'vitest',
        'lifecycle_stage' => 'lead',
        'created_at'      => now_utc(),
        'updated_at'      => now_utc(),
    ]);
}

/** @param array<string,mixed> $overrides */
function vi_booking(int $contactId, string $date, array $overrides = []): int
{
    return (int) DB::insert('bookings', $overrides + [
        'booking_number' => 'VI-' . bin2hex(random_bytes(4)),
        'contact_id'     => $contactId,
        'event_date'     => $date,
        'start_time'     => '14:00',
        'end_time'       => '16:00',
        'guest_count'    => 12,
        'status'         => 'confirmed',
        'total_cents'    => 40000,
        'deposit_cents'  => 10000,
        'created_at'     => now_utc(),
        'updated_at'     => now_utc(),
    ]);
}

/** @return array<string,mixed>|null */
function vi_visit(int $contactId, string $date): ?array
{
    return DB::first(
        'SELECT * FROM {visits} WHERE contact_id = :c AND visit_date = :d',
        ['c' => $contactId, 'd' => $date]
    );
}

/** @return array<string,mixed> */
function vi_contact_row(int $contactId): array
{
    return (array) DB::first(
        'SELECT visit_count, lifetime_value_cents, avg_spend_cents, first_visit_at, last_visit_at
           FROM {contacts} WHERE id = :id',
        ['id' => $contactId]
    );
}

/** @return list<string> */
function vi_events(): array
{
    $names = [];

    foreach (Event::recorded() as $fired) {
        if (str_starts_with($fired['event'], 'visit.')) {
            $names[] = $fired['event'];
        }
    }

    return $names;
}

vi_clear();

// ------------------------------------------------------------------- the row

Harness::test('a visit is recorded, and the first one says so', function (): void {
    vi_clear();
    $contactId = vi_contact('First');

    Event::record();
    $result = VisitRecorder::record([
        'contact_id'  => $contactId,
        'visit_date'  => '2026-05-01',
        'source'      => 'check_in',
        'spend_cents' => 2500,
        'party_size'  => 9,
    ]);
    $events = vi_events();
    Event::record(false);

    Assert::true($result['recorded']);
    Assert::true($result['first'], 'nobody had visited before');
    Assert::null($result['skipped']);

    $visit = vi_visit($contactId, '2026-05-01');
    Assert::notNull($visit);
    Assert::same('check_in', (string) $visit['source']);
    Assert::same(2500, (int) $visit['spend_cents']);
    Assert::same(9, (int) $visit['party_size']);

    // BOTH events, because "somebody visited" and "somebody visited for the
    // first time" start different sequences.
    Assert::inArray('visit.recorded', $events);
    Assert::inArray('visit.first', $events);
});

Harness::test('the contact’s own figures are derived from the visits', function (): void {
    vi_clear();
    $contactId = vi_contact('Figures');

    VisitRecorder::record(['contact_id' => $contactId, 'visit_date' => '2026-05-01', 'spend_cents' => 1000]);
    VisitRecorder::record(['contact_id' => $contactId, 'visit_date' => '2026-05-10', 'spend_cents' => 3000]);

    $row = vi_contact_row($contactId);

    Assert::same(2, (int) $row['visit_count']);
    Assert::same(4000, (int) $row['lifetime_value_cents']);
    Assert::same(2000, (int) $row['avg_spend_cents'], 'average per visit, which is what the page says');
    Assert::same('2026-05-01 00:00:00', (string) $row['first_visit_at']);
    Assert::same('2026-05-10 00:00:00', (string) $row['last_visit_at'], 'the newest, not the newest written');

    // DERIVED, not incremented: deleting a visit and refreshing puts the
    // contact right. An incremented counter could not come back down.
    DB::query('DELETE FROM {visits} WHERE contact_id = :c AND visit_date = :d', ['c' => $contactId, 'd' => '2026-05-10']);
    VisitRecorder::refresh($contactId);

    $after = vi_contact_row($contactId);

    Assert::same(1, (int) $after['visit_count']);
    Assert::same(1000, (int) $after['lifetime_value_cents']);
    Assert::same('2026-05-01 00:00:00', (string) $after['last_visit_at']);
});

Harness::test('the second visit of the day is the SAME visit, and is not announced again', function (): void {
    /*
     * The party case: eleven children ticked in one at a time, each tick
     * calling the recorder. One visit, one event — a thank-you sent eleven
     * times is the most visible failure this class prevents.
     */
    vi_clear();
    $contactId = vi_contact('Twice');

    VisitRecorder::record(['contact_id' => $contactId, 'visit_date' => '2026-05-01', 'source' => 'check_in', 'party_size' => 1]);

    Event::record();
    $second = VisitRecorder::record([
        'contact_id' => $contactId,
        'visit_date' => '2026-05-01',
        'source'     => 'check_in',
        'party_size' => 6,
    ]);
    $events = vi_events();
    Event::record(false);

    Assert::false($second['recorded'], 'nothing new was recorded');
    Assert::false($second['first']);
    Assert::same([], $events, 'and nothing was announced');

    Assert::same(1, (int) DB::value(
        'SELECT COUNT(*) FROM {visits} WHERE contact_id = :c',
        ['c' => $contactId]
    ));

    // An EQUAL source may still revise its own figures: the desk now knows
    // six children arrived rather than one.
    Assert::same(6, (int) vi_visit($contactId, '2026-05-01')['party_size']);
});

Harness::test('a later visit is announced, but is not a first visit', function (): void {
    vi_clear();
    $contactId = vi_contact('Returning');

    VisitRecorder::record(['contact_id' => $contactId, 'visit_date' => '2026-05-01']);

    Event::record();
    $second = VisitRecorder::record(['contact_id' => $contactId, 'visit_date' => '2026-06-02']);
    $events = vi_events();
    Event::record(false);

    Assert::true($second['recorded']);
    Assert::false($second['first'], 'they had been before');
    Assert::inArray('visit.recorded', $events);
    Assert::false(in_array('visit.first', $events, true), 'visit.first is once per guest');
});

// ------------------------------------------------------------ source priority

Harness::test('THE CARD LEDGER OUTRANKS THE DESK, AND THE DESK CANNOT UNDO IT', function (): void {
    /*
     * The schema states this order in a comment and nothing enforced it. The
     * import knows what was actually spent; the check-in screen only knows
     * somebody arrived with no money attached. A later tick at the desk must
     * not be able to zero the spend the ledger recorded, or the venue's
     * lifetime value quietly falls to nothing as the afternoon goes on.
     */
    vi_clear();
    $contactId = vi_contact('Ledger');

    VisitRecorder::record([
        'contact_id'   => $contactId,
        'visit_date'   => '2026-05-01',
        'source'       => 'pos_import',
        'spend_cents'  => 8750,
        'external_ref' => 'CARD-123',
    ]);

    $down = VisitRecorder::record([
        'contact_id'  => $contactId,
        'visit_date'  => '2026-05-01',
        'source'      => 'check_in',
        'spend_cents' => 0,
    ]);

    Assert::same('lower_priority_source', $down['skipped']);

    $visit = vi_visit($contactId, '2026-05-01');
    Assert::same('pos_import', (string) $visit['source'], 'the stronger source stands');
    Assert::same(8750, (int) $visit['spend_cents'], 'and keeps its money');
    Assert::same('CARD-123', (string) $visit['external_ref']);
    Assert::same(8750, (int) vi_contact_row($contactId)['lifetime_value_cents']);
});

Harness::test('a stronger source replaces a weaker one, money and all', function (): void {
    vi_clear();
    $contactId = vi_contact('Upgraded');

    VisitRecorder::record(['contact_id' => $contactId, 'visit_date' => '2026-05-01', 'source' => 'check_in']);

    $up = VisitRecorder::record([
        'contact_id'  => $contactId,
        'visit_date'  => '2026-05-01',
        'source'      => 'pos_import',
        'spend_cents' => 4200,
    ]);

    Assert::null($up['skipped']);
    Assert::false($up['recorded'], 'still one visit, not a new one');

    $visit = vi_visit($contactId, '2026-05-01');
    Assert::same('pos_import', (string) $visit['source']);
    Assert::same(4200, (int) $visit['spend_cents']);
    Assert::same(4200, (int) vi_contact_row($contactId)['lifetime_value_cents'], 'the contact follows');
});

// ------------------------------------------------------------------ refusals

Harness::test('a visit with nobody attached is dropped, not stored as an orphan', function (): void {
    vi_clear();
    $before = (int) DB::value('SELECT COUNT(*) FROM {visits}');

    Event::record();
    $none = VisitRecorder::record(['contact_id' => 0, 'visit_date' => '2026-05-01']);
    $events = vi_events();
    Event::record(false);

    Assert::same('no_contact', $none['skipped']);
    Assert::null($none['visit_id']);
    Assert::same([], $events);
    Assert::same($before, (int) DB::value('SELECT COUNT(*) FROM {visits}'), 'nothing was written');
});

Harness::test('a visit for a deleted contact is dropped', function (): void {
    vi_clear();
    $contactId = vi_contact('Deleted');
    DB::update('contacts', ['deleted_at' => now_utc()], 'id = :id', ['id' => $contactId]);

    $result = VisitRecorder::record(['contact_id' => $contactId, 'visit_date' => '2026-05-01']);

    Assert::same('no_contact', $result['skipped']);
    Assert::null(vi_visit($contactId, '2026-05-01'));
});

Harness::test('a source this version does not know is refused', function (): void {
    // The enum would refuse it at the database and the error would reach the
    // guest being checked in. Refused here, by name, with nothing written.
    vi_clear();
    $contactId = vi_contact('Unknown');

    $result = VisitRecorder::record([
        'contact_id' => $contactId,
        'visit_date' => '2026-05-01',
        'source'     => 'telepathy',
    ]);

    Assert::same('unknown_source', $result['skipped']);
    Assert::null(vi_visit($contactId, '2026-05-01'));
});

Harness::test('an absurd party size is clamped rather than failing the check-in', function (): void {
    // `party_size` is SMALLINT UNSIGNED. A typo of 70000 would be a database
    // error on a path that must not fail: the guest is standing at the desk.
    vi_clear();
    $contactId = vi_contact('Clamped');

    $result = VisitRecorder::record([
        'contact_id' => $contactId,
        'visit_date' => '2026-05-01',
        'party_size' => 70000,
    ]);

    Assert::true($result['recorded']);
    Assert::same(65535, (int) vi_visit($contactId, '2026-05-01')['party_size']);
});

// -------------------------------------------------------------- from a party

Harness::test('a party becomes a visit on its OWN date, sized by who turned up', function (): void {
    vi_clear();
    $contactId = vi_contact('Party');
    $bookingId = vi_booking($contactId, '2026-05-01');

    foreach ([true, true, false] as $index => $arrived) {
        DB::insert('booking_guests', [
            'booking_id'    => $bookingId,
            'name'          => 'Guest ' . $index,
            'rsvp_status'   => 'yes',
            'checked_in_at' => $arrived ? now_utc() : null,
            'created_at'    => now_utc(),
        ]);
    }

    DB::insert('booking_payments', [
        'booking_id'   => $bookingId,
        'amount_cents' => 10000,
        'type'         => 'deposit',
        'occurred_at'  => now_utc(),
        'created_at'   => now_utc(),
    ]);

    $booking = (array) DB::first('SELECT * FROM {bookings} WHERE id = :id', ['id' => $bookingId]);
    $result  = VisitRecorder::fromBooking($booking);

    Assert::true($result['recorded']);

    // The booking's date, NOT today: a party ticked in after midnight on a
    // late lock-in is still that day's visit.
    $visit = vi_visit($contactId, '2026-05-01');
    Assert::notNull($visit);
    Assert::same('check_in', (string) $visit['source']);
    Assert::same(2, (int) $visit['party_size'], 'who turned up, not who was invited');
    Assert::same(10000, (int) $visit['spend_cents'], 'what the party actually paid');
    Assert::same($bookingId, (int) $visit['booking_id']);
});

Harness::test('completing the party outranks the desk and takes the guest count from it', function (): void {
    vi_clear();
    $contactId = vi_contact('Completed');
    $bookingId = vi_booking($contactId, '2026-05-01', ['guest_count' => 14]);

    $booking = (array) DB::first('SELECT * FROM {bookings} WHERE id = :id', ['id' => $bookingId]);

    VisitRecorder::fromBooking($booking, 'check_in');
    VisitRecorder::fromBooking($booking, 'booking');

    $visit = vi_visit($contactId, '2026-05-01');

    Assert::same('booking', (string) $visit['source']);
    Assert::same(14, (int) $visit['party_size'], 'the booked count, since nobody was ticked in');
    Assert::same(1, (int) DB::value('SELECT COUNT(*) FROM {visits} WHERE contact_id = :c', ['c' => $contactId]));
});

Harness::test('a booking with no contact on it records nothing', function (): void {
    // A walk-in party taken on the phone with no contact attached yet. There
    // is nobody to attribute the visit to, and a visit with no contact cannot
    // be deduplicated or counted.
    vi_clear();

    $result = VisitRecorder::fromBooking(['id' => 0, 'contact_id' => 0, 'event_date' => '2026-05-01']);

    Assert::same('no_contact', $result['skipped']);
    Assert::false($result['recorded']);
});

vi_clear();
