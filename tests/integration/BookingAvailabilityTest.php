<?php

declare(strict_types=1);

/**
 * The seam between stored UTC and the venue's wall clock.
 *
 * `AvailabilityEngine` is pure and tested without a database;
 * `BookingRepository` is the half that reads rows and converts timestamps, and
 * THE CONVERSION IS WHERE THIS GETS QUIETLY WRONG. Every case here is one
 * where an off-by-an-hour or an off-by-a-day would not look like a bug — it
 * would look like a room being double-booked, or a Saturday evening party
 * appearing on Sunday's run sheet.
 *
 * The venue is America/New_York, so the offset is -4 in summer and -5 in
 * winter, and both appear below on purpose. A test written only in July
 * passes against code that has hard-coded the summer offset.
 */

use App\Core\DB;
use App\Core\Settings;
use App\Services\AvailabilityEngine as A;
use App\Services\BookingRepository as R;
use Tests\Assert;
use Tests\Harness;

Harness::suite('integration/booking-availability');

if (!test_boot()) {
    Harness::skip('booking availability suite', 'no database available');

    return;
}

Assert::same(
    'America/New_York',
    Settings::timezone(),
    'precondition: these tests are written against the venue\'s own zone'
);

/** A booking with its resource allocation, written the way the app writes it. */
function ba_booking(string $date, int $startMinutes, int $endMinutes, int $resourceId, string $status = 'confirmed'): int
{
    static $counter = 0;
    $counter++;

    $id = DB::insert('bookings', [
        'booking_number' => 'BA-' . $counter . '-' . bin2hex(random_bytes(3)),
        'type'           => 'birthday',
        'status'         => $status,
        'event_date'     => $date,
        'start_time'     => A::timeValue($startMinutes),
        'end_time'       => A::timeValue($endMinutes),
        'guest_count'    => 12,
        'guest_of_honor_name' => 'Test Honoree ' . $counter,
        'created_at'     => now_utc(),
        'updated_at'     => now_utc(),
    ]);

    R::allocate($id, $date, $startMinutes, $endMinutes, [$resourceId]);

    return $id;
}

function ba_room(?int $capacity = 20): int
{
    static $counter = 0;
    $counter++;

    // `table` is a real member of the resources type enum that the venue's
    // seed leaves empty, so a query for it returns this suite's rows and
    // nothing else. Using `party_room` would pick up the six seeded rooms and
    // every "only my room is free" assertion below would be meaningless.
    return DB::insert('resources', [
        'name'       => 'Test Room ' . $counter . ' ' . bin2hex(random_bytes(2)),
        'type'       => 'table',
        'capacity'   => $capacity,
        'is_active'  => 1,
        'created_at' => now_utc(),
        'updated_at' => now_utc(),
    ]);
}

/**
 * The next $dow at least three weeks out.
 *
 * FIXTURE DATES HAVE TO BE IN THE FUTURE, because availability is answered
 * against the venue's real today and a package carries a notice rule. Writing
 * them as literals works until the literal goes past — which it did: this
 * suite was written with July 2026 dates and every availability assertion came
 * back `past` in October. Three weeks clears the seeded seven-day rule with
 * room to spare, and the weekday is asserted where a test turns on it.
 */
function ba_next(int $dow, int $minDaysAway = 21): string
{
    $date = A::addDays(R::today(), $minDaysAway);

    for ($i = 0; $i < 7; $i++) {
        if (A::dayOfWeek($date) === $dow) {
            return $date;
        }

        $date = A::addDays($date, 1);
    }

    return $date;
}

function ba_saturday(): string
{
    static $date = null;

    return $date ??= ba_next(6);
}

function ba_sunday(): string
{
    // The day after the fixture Saturday, so "the next day the venue is open"
    // is a fact about these two dates rather than a hope.
    return A::addDays(ba_saturday(), 1);
}

/** An attraction nothing else points at, so its resource pool is this test's. */
function ba_attraction(): int
{
    static $counter = 0;
    $counter++;

    return DB::insert('attractions', [
        'name'              => 'BA Test Attraction ' . $counter,
        'slug'              => 'ba-test-' . bin2hex(random_bytes(4)),
        'is_seasonal'       => 0,
        'is_party_eligible' => 1,
        'is_party_main'     => 1,
        'requires_waiver'   => 0,
        'is_active'         => 1,
        'created_at'        => now_utc(),
        'updated_at'        => now_utc(),
    ]);
}

function ba_cleanup(array $bookingIds, array $resourceIds): void
{
    foreach ($bookingIds as $id) {
        DB::delete('booking_resources', 'booking_id = :id', ['id' => $id]);
        DB::delete('bookings', 'id = :id', ['id' => $id]);
    }

    foreach ($resourceIds as $id) {
        DB::delete('resources', 'id = :id', ['id' => $id]);
    }
}

Harness::test('the fixture dates really are the weekdays the tests rely on', function (): void {
    // ba_saturday() searches forward for a weekday. If that search were
    // wrong, "the next day the venue is open" and the 11am opening below
    // would both be assertions about the wrong day — and they would still
    // pass or fail for reasons that had nothing to do with availability.
    Assert::same(6, A::dayOfWeek(ba_saturday()), 'the fixture Saturday is a Saturday');
    Assert::same(0, A::dayOfWeek(ba_sunday()), 'and the day after it is a Sunday');
    Assert::greaterThan(
        13,
        A::daysBetween(R::today(), ba_saturday()),
        'and it is far enough out to clear the seeded seven-day notice rule'
    );
});

// ------------------------------------------------------- the two conversions

Harness::test('a local time converts to UTC at the right offset in both seasons', function (): void {
    // -4 in July, -5 in January. A hard-coded offset passes one of these.
    Assert::same('2026-07-11 18:00:00', R::localToUtc('2026-07-11', 14 * 60), '2pm EDT is 18:00 UTC');
    Assert::same('2026-01-10 19:00:00', R::localToUtc('2026-01-10', 14 * 60), '2pm EST is 19:00 UTC');
});

Harness::test('a UTC timestamp reads back as the local date and minute it was', function (): void {
    $summer = R::utcToLocal('2026-07-11 18:00:00');
    $winter = R::utcToLocal('2026-01-10 19:00:00');

    Assert::same('2026-07-11', $summer['date']);
    Assert::same(14 * 60, $summer['minutes']);
    Assert::same('2026-01-10', $winter['date']);
    Assert::same(14 * 60, $winter['minutes']);
});

Harness::test('a LATE EVENING party stays on its own local date', function (): void {
    // THE DATE-SHIFT CASE. 10pm on Saturday in New York is 02:00 UTC on
    // SUNDAY. A calendar that groups by the stored date puts the party on the
    // wrong day, and the run sheet is printed for the wrong shift.
    $utc = R::localToUtc('2026-07-11', 22 * 60);

    Assert::same('2026-07-12 02:00:00', $utc, 'the stored timestamp really is the next UTC day');

    $back = R::utcToLocal($utc);

    Assert::same('2026-07-11', $back['date'], 'and it still belongs to Saturday');
    Assert::same(22 * 60, $back['minutes']);
});

Harness::test('an end time past midnight lands on the following date', function (): void {
    // Minutes past 1440 are normalised by date arithmetic rather than handed
    // to a parser as "25:00".
    Assert::same('2026-07-12 05:00:00', R::localToUtc('2026-07-11', 25 * 60), '1am Sunday EDT');
});

Harness::test('the spring-forward hour resolves forward rather than failing', function (): void {
    // 2026-03-08 02:30 does not exist in New York. The venue opens at 11am so
    // this is moot in practice, but a silent null here would mean an
    // allocation that was never written.
    $utc = R::localToUtc('2026-03-08', 2 * 60 + 30);

    Assert::notNull($utc);
    Assert::same('2026-03-08 07:30:00', $utc, 'resolved to 03:30 EDT');
});

Harness::test('the fall-back hour picks one of the two consistently', function (): void {
    // 2026-11-01 01:30 happens twice. Which one matters less than always
    // choosing the same one: the alternative is an allocation that reads as a
    // different hour each time it is loaded.
    $first  = R::localToUtc('2026-11-01', 90);
    $second = R::localToUtc('2026-11-01', 90);

    Assert::same($first, $second);
    Assert::same('2026-11-01 05:30:00', $first, 'the earlier, still-daylight reading');
});

Harness::test('a round trip through both conversions is lossless all year', function (): void {
    // Every Sunday of 2026 at 2pm, which covers both changeovers and both
    // offsets without asserting what either of them is.
    $date  = '2026-01-04';
    $drift = 0;

    for ($week = 0; $week < 52; $week++) {
        $utc  = R::localToUtc($date, 14 * 60);
        $back = R::utcToLocal((string) $utc);

        if ($back['date'] !== $date || $back['minutes'] !== 14 * 60) {
            $drift++;
        }

        $date = A::addDays($date, 7);
    }

    Assert::same(0, $drift, '52 round trips, no drift');
});

Harness::test('an unreadable timestamp is null rather than an exception', function (): void {
    Assert::null(R::utcToLocal(''));
    Assert::null(R::utcToLocal('0000-00-00 00:00:00'));
    Assert::null(R::localToUtc('not-a-date', 600));
});

// --------------------------------------------------------- reading the rows

Harness::test('an allocation comes back as the local minutes it was written as', function (): void {
    $room    = ba_room();
    $booking = ba_booking(ba_saturday(), 14 * 60, 16 * 60, $room);

    try {
        $allocations = R::allocationsFor(ba_saturday());
        $mine        = array_values(array_filter(
            $allocations,
            static fn (array $a): bool => $a['booking_id'] === $booking
        ));

        Assert::count(1, $mine);
        Assert::same(14 * 60, $mine[0]['start']);
        Assert::same(16 * 60, $mine[0]['end']);
        Assert::same($room, $mine[0]['resource_id']);
        Assert::contains('Test Honoree', $mine[0]['title'], 'named for the run sheet and the calendar');
    } finally {
        ba_cleanup([$booking], [$room]);
    }
});

Harness::test('a late party is found on its local date and not the UTC one', function (): void {
    // The same shift as the conversion test, now through the query: the row
    // is stored on 2026-07-12 UTC and must be found by asking for the 11th.
    $room    = ba_room();
    $booking = ba_booking(ba_saturday(), 21 * 60, 23 * 60, $room);

    try {
        $stored = (string) DB::value(
            'SELECT start_time FROM {booking_resources} WHERE booking_id = :id',
            ['id' => $booking]
        );

        // The expected UTC date is COMPUTED, not written out: 9pm local is the
        // next UTC day at either offset, which is the property being checked,
        // and hard-coding it would make this test a statement about one week
        // of the year.
        Assert::contains(ba_sunday(), $stored, 'precondition: stored on the next UTC day');

        $saturday = array_filter(
            R::allocationsFor(ba_saturday()),
            static fn (array $a): bool => $a['booking_id'] === $booking
        );
        $sunday = array_filter(
            R::allocationsFor(ba_sunday()),
            static fn (array $a): bool => $a['booking_id'] === $booking
        );

        Assert::count(1, $saturday, 'found on the local date it belongs to');
        Assert::count(0, $sunday, 'and not on the next one');
    } finally {
        ba_cleanup([$booking], [$room]);
    }
});

Harness::test('A CANCELLED BOOKING HOLDS NOTHING', function (): void {
    // A room freed at four o'clock on Friday has to be bookable at five.
    // This is the single most common reason a venue goes looking for
    // availability at all, and leaving cancelled rows in the allocation set
    // would make the calendar look permanently full.
    $room      = ba_room();
    $cancelled = ba_booking(ba_saturday(), 14 * 60, 16 * 60, $room, 'cancelled');
    $noShow    = ba_booking(ba_saturday(), 17 * 60, 19 * 60, $room, 'no_show');
    $live      = ba_booking(ba_saturday(), 20 * 60, 22 * 60, $room, 'confirmed');

    try {
        $ids = array_column(R::allocationsFor(ba_saturday()), 'booking_id');

        Assert::inArray($live, $ids);
        Assert::false(in_array($cancelled, $ids, true), 'a cancelled party blocks nothing');
        Assert::false(in_array($noShow, $ids, true), 'nor does a no-show');
    } finally {
        ba_cleanup([$cancelled, $noShow, $live], [$room]);
    }
});

Harness::test('allocating twice replaces rather than accumulates', function (): void {
    // Delete-then-insert is the contract. A reschedule that left the old
    // allocation behind would block the room it had just freed.
    $room    = ba_room();
    $booking = ba_booking(ba_saturday(), 14 * 60, 16 * 60, $room);

    try {
        R::allocate($booking, ba_saturday(), 18 * 60, 20 * 60, [$room]);

        $rows = DB::all(
            'SELECT start_time FROM {booking_resources} WHERE booking_id = :id',
            ['id' => $booking]
        );

        Assert::count(1, $rows, 'one allocation, not two');

        $local = R::utcToLocal((string) $rows[0]['start_time']);

        Assert::same(18 * 60, $local['minutes'], 'and it is the new time');
    } finally {
        ba_cleanup([$booking], [$room]);
    }
});

Harness::test('allocating no resources clears the booking\'s rooms', function (): void {
    $room    = ba_room();
    $booking = ba_booking(ba_saturday(), 14 * 60, 16 * 60, $room);

    try {
        R::allocate($booking, ba_saturday(), 14 * 60, 16 * 60, []);

        Assert::same(
            0,
            (int) DB::value('SELECT COUNT(*) FROM {booking_resources} WHERE booking_id = :id', ['id' => $booking])
        );
    } finally {
        ba_cleanup([$booking], [$room]);
    }
});

Harness::test('a month of allocations comes back grouped by local date', function (): void {
    $room  = ba_room();
    $first = ba_saturday();
    $later = A::addDays($first, 7);
    $a     = ba_booking($first, 14 * 60, 16 * 60, $room);
    $b     = ba_booking($later, 21 * 60, 23 * 60, $room);

    try {
        $byDate = R::allocationsBetween(A::addDays($first, -7), A::addDays($later, 7));

        Assert::hasKey($first, $byDate);
        Assert::hasKey($later, $byDate, 'the late party is grouped under its local date');
        Assert::same('2:00 PM', $byDate[$first][0]['start_label']);
        Assert::same('9:00 PM', $byDate[$later][0]['start_label']);
    } finally {
        ba_cleanup([$a, $b], [$room]);
    }
});

// ------------------------------------------------- the engine, wired up

Harness::test('a real allocation removes the real slot from the real day', function (): void {
    // End to end: a booking written through allocate(), read back through
    // allocationsFor(), and refused by the engine — with the same package the
    // venue actually has seeded.
    $room    = ba_room();
    $package = DB::first('SELECT * FROM {party_packages} WHERE is_active = 1 ORDER BY id LIMIT 1');

    Assert::notNull($package, 'precondition: the seed has a party package');

    $booking = ba_booking(ba_saturday(), 14 * 60, 16 * 60, $room);

    try {
        $taken = R::check(ba_saturday(), 14 * 60, $package, 12, null, 'table');

        Assert::false($taken['ok']);
        Assert::same('fully_booked', $taken['reason']);

        $free = R::check(ba_saturday(), 17 * 60, $package, 12, null, 'table');

        Assert::true($free['ok'], 'and a time clear of it and the buffer is available');

        // Editing the booking that holds the room must not collide with
        // itself, or saving a party nobody rescheduled refuses itself.
        $itself = R::check(ba_saturday(), 14 * 60, $package, 12, $booking, 'table');

        Assert::true($itself['ok']);
    } finally {
        ba_cleanup([$booking], [$room]);
    }
});

Harness::test('alternatives skips the days that cannot take the party', function (): void {
    // A fully booked Saturday should offer the Sunday, and the engine's own
    // rules — not a date arithmetic shortcut — decide which days qualify.
    $room    = ba_room();
    $package = DB::first('SELECT * FROM {party_packages} WHERE is_active = 1 ORDER BY id LIMIT 1');
    $all     = [];

    // Block every room-hour of the Saturday, then ask for alternatives.
    $booking = ba_booking(ba_saturday(), 11 * 60, 23 * 60, $room);
    $all[]   = $booking;

    try {
        $offered = R::alternatives(ba_saturday(), $package, 12, 2, 'table');

        Assert::count(2, $offered);
        Assert::same(ba_sunday(), $offered[0]['date'], 'the next day the venue is open and free');
        Assert::contains('Sunday', $offered[0]['label']);
        Assert::greaterThan(0, $offered[0]['slots']);
        Assert::notEmpty($offered[0]['first'], 'with a time to offer');
    } finally {
        ba_cleanup($all, [$room]);
    }
});

Harness::test('a room too small for the party is refused with the largest size', function (): void {
    $small   = ba_room(8);
    $package = DB::first('SELECT * FROM {party_packages} WHERE is_active = 1 ORDER BY id LIMIT 1');

    try {
        $result = R::check(ba_saturday(), 14 * 60, $package, 30, null, 'table');

        Assert::false($result['ok']);
        Assert::same('too_large', $result['reason']);
        Assert::same(8, $result['largest_capacity']);
    } finally {
        ba_cleanup([], [$small]);
    }
});

// --------------------------------------------------------------- settings

Harness::test('the tax rate defaults to the venue\'s own and is clamped when stored', function (): void {
    $before = Settings::get(R::TAX_RATE_SETTING);

    try {
        Settings::forget(R::TAX_RATE_SETTING);

        Assert::same(R::DEFAULT_TAX_RATE, R::taxRate(), 'Orange County NY, 8.125%');
        Assert::same(8125, R::DEFAULT_TAX_RATE);

        Settings::set(R::TAX_RATE_SETTING, 999999);

        Assert::same(100000, R::taxRate(), 'no rate over 100%');

        Settings::set(R::TAX_RATE_SETTING, -5);

        Assert::same(0, R::taxRate());

        Settings::set(R::TAX_RATE_SETTING, 0);

        Assert::same(0, R::taxRate(), 'and zero is a legitimate stored rate, not an absent one');
    } finally {
        if ($before === null) {
            Settings::forget(R::TAX_RATE_SETTING);
        } else {
            Settings::set(R::TAX_RATE_SETTING, $before);
        }
    }
});

Harness::test('the buffer and step have defaults and bounds', function (): void {
    $buffer = Settings::get(R::BUFFER_SETTING);
    $step   = Settings::get(R::STEP_SETTING);

    try {
        Settings::forget(R::BUFFER_SETTING);
        Settings::forget(R::STEP_SETTING);

        Assert::same(30, R::bufferMinutes());
        Assert::same(30, R::stepMinutes());

        Settings::set(R::BUFFER_SETTING, 0);
        Settings::set(R::STEP_SETTING, 1);

        Assert::same(0, R::bufferMinutes(), 'back-to-back parties are a venue\'s choice to make');
        Assert::same(5, R::stepMinutes(), 'but a one-minute step would offer 700 times a day');
    } finally {
        foreach ([R::BUFFER_SETTING => $buffer, R::STEP_SETTING => $step] as $key => $value) {
            if ($value === null) {
                Settings::forget($key);
            } else {
                Settings::set($key, $value);
            }
        }
    }
});

Harness::test('the public booking page is off until it is turned on', function (): void {
    $before = Settings::get(R::PUBLIC_SETTING);

    try {
        Settings::forget(R::PUBLIC_SETTING);

        Assert::false(R::publicEnabled(), 'a fresh install does not take bookings from the internet');
    } finally {
        if ($before !== null) {
            Settings::set(R::PUBLIC_SETTING, $before);
        }
    }
});

Harness::test('the venue\'s own today is used, not the server\'s', function (): void {
    // The server runs UTC. Between midnight and 5am UTC the venue is still on
    // the previous date, and a notice rule counted against the wrong one turns
    // away a booking that should be allowed.
    //
    // The instant is INJECTED rather than read from the clock, because a test
    // that can only run the real clock can only check this for nineteen hours
    // a day — and the first version of it, which compared R::today() against
    // gmdate() and allowed a day either way, passed with the conversion
    // removed entirely. MEASURED: that mutation now fails here.
    $utc = static fn (string $when): DateTimeImmutable => new DateTimeImmutable(
        $when,
        new DateTimeZone('UTC')
    );

    Assert::same('2026-07-11', R::today($utc('2026-07-12 03:00:00')), '11pm Saturday EDT is still Saturday');
    Assert::same('2026-07-12', R::today($utc('2026-07-12 04:00:00')), 'and midnight is Sunday');
    Assert::same('2026-01-10', R::today($utc('2026-01-11 04:00:00')), '11pm Saturday EST is still Saturday');
    Assert::same('2026-01-11', R::today($utc('2026-01-11 05:00:00')), 'and midnight is Sunday');
    Assert::true(A::isDate(R::today()), 'and the real clock still answers a date');
});

Harness::test('an allocation spanning local midnight blocks its hours on BOTH dates', function (): void {
    // The venue shuts at 11pm so nothing it books can do this today, which is
    // precisely why the clamp needs a test: it exists for the day somebody
    // adds a lock-in or a late event, and an untested branch is a branch that
    // will be wrong then. The row is written directly because no form will
    // produce one yet.
    $room    = ba_room();
    $date    = ba_saturday();
    $booking = ba_booking($date, 22 * 60, 23 * 60, $room);

    try {
        DB::delete('booking_resources', 'booking_id = :id', ['id' => $booking]);
        DB::insert('booking_resources', [
            'booking_id'  => $booking,
            'resource_id' => $room,
            'start_time'  => (string) R::localToUtc($date, 22 * 60),
            'end_time'    => (string) R::localToUtc($date, 25 * 60),
        ]);

        $mine = static fn (string $on): array => array_values(array_filter(
            R::allocationsFor($on),
            static fn (array $a): bool => $a['booking_id'] === $booking
        ));

        $saturday = $mine($date);
        $sunday   = $mine(A::addDays($date, 1));

        Assert::count(1, $saturday);
        Assert::same(22 * 60, $saturday[0]['start']);
        Assert::same(1440, $saturday[0]['end'], 'clamped to the end of its own date');

        Assert::count(1, $sunday, 'and the small hours are blocked on the next date');
        Assert::same(0, $sunday[0]['start'], 'clamped to the start of that one');
        Assert::same(60, $sunday[0]['end']);
    } finally {
        ba_cleanup([$booking], [$room]);
    }
});

// ---------------------------------------------- which resource holds a party

Harness::test('a package uses its ATTRACTION\'S resource when one can hold the party', function (): void {
    // This is what makes two laser parties at three o'clock impossible: there
    // is one arena, and the engine places a party in one resource. The
    // positive control for the fallback below — without it, "falls back to a
    // party room" would be satisfied by a method that always returned one.
    $attraction = ba_attraction();
    $room       = ba_room(30);

    DB::update('resources', ['attraction_id' => $attraction], 'id = :id', ['id' => $room]);

    try {
        $package = ['main_attraction_id' => $attraction];

        Assert::same('table', R::resourceTypeFor($package, 12), 'twelve guests fit, so the attraction wins');
        Assert::same('table', R::resourceTypeFor($package, 30), 'and so do exactly thirty');
        Assert::same(
            'table',
            R::resourceTypeFor($package, 0),
            'and with no headcount given, capacity is not weighed at all'
        );
    } finally {
        ba_cleanup([], [$room]);
        DB::delete('attractions', 'id = :id', ['id' => $attraction]);
    }
});

Harness::test('BUT IT FALLS BACK TO A PARTY ROOM WHEN NONE OF THEM COULD HOLD IT', function (): void {
    // MEASURED on the seeded venue: the Bowling Birthday package points at the
    // bowling attraction, whose resources are twenty-four lanes of SIX. The
    // engine needs one resource with room for the whole party, so every
    // bowling enquiry — including one for the package's own default of eight
    // guests — was refused with "No room of that type holds 8 guests. The
    // largest holds 6", on every date, with no alternative to offer. The
    // package was unbookable and nothing on the page said why.
    $attraction = ba_attraction();
    $lane       = ba_room(6);

    DB::update('resources', ['attraction_id' => $attraction], 'id = :id', ['id' => $lane]);

    try {
        $package = ['main_attraction_id' => $attraction];

        Assert::same('table', R::resourceTypeFor($package, 6), 'six fit in the lane');
        Assert::same(
            R::ROOM_TYPE,
            R::resourceTypeFor($package, 7),
            'seven do not, so the party is held in a party room'
        );

        // AND THE PARTY IS THEN ACTUALLY BOOKABLE, which is the point. A
        // fallback that returned a type with no usable room in it would pass
        // the assertion above and leave the package exactly as unbookable.
        $result = A::day(R::inputFor(
            ba_saturday(),
            ['main_attraction_id' => $attraction, 'duration_minutes' => 120, 'min_notice_days' => 7],
            10,
            null,
            R::resourceTypeFor($package, 10)
        ));

        Assert::true(($result['ok'] ?? false) === true, 'a ten-guest party can be booked: '
            . (string) ($result['message'] ?? ''));
    } finally {
        ba_cleanup([], [$lane]);
        DB::delete('attractions', 'id = :id', ['id' => $attraction]);
    }
});

Harness::test('CAPACITY, NEVER AVAILABILITY, decides the fallback', function (): void {
    // The dangerous version of the rule above. Falling back because the arena
    // is BOOKED would quietly put a second laser party in a party room at the
    // same three o'clock — the double-booking this whole model exists to
    // prevent. A capacity is a static fact about the building; a collision is
    // a real "fully booked" and has to read as one.
    $attraction = ba_attraction();
    $arena      = ba_room(30);
    $date       = ba_saturday();

    DB::update('resources', ['attraction_id' => $attraction], 'id = :id', ['id' => $arena]);

    $booking = ba_booking($date, 15 * 60, 17 * 60, $arena);

    try {
        $package = ['main_attraction_id' => $attraction];

        Assert::same(
            'table',
            R::resourceTypeFor($package, 12),
            'the type does not change just because the only one of them is busy'
        );

        $check = R::check(
            $date,
            15 * 60,
            ['main_attraction_id' => $attraction, 'duration_minutes' => 120, 'min_notice_days' => 7],
            12,
            null,
            R::resourceTypeFor($package, 12)
        );

        Assert::false(($check['ok'] ?? false) === true, 'and three o\'clock is refused, not rehoused');
        Assert::same('fully_booked', (string) $check['reason']);
    } finally {
        ba_cleanup([$booking], [$arena]);
        DB::delete('attractions', 'id = :id', ['id' => $attraction]);
    }
});

Harness::test('a resource with NO capacity recorded is treated as unlimited', function (): void {
    // The engine reads an unrecorded capacity as "no limit" (usable()), so
    // this has to agree with it — otherwise the fallback fires on a resource
    // the engine would have accepted, and a venue that simply never filled
    // the capacity column in finds its packages quietly rehoused.
    $attraction = ba_attraction();
    $unknown    = ba_room(null);

    DB::update('resources', ['attraction_id' => $attraction], 'id = :id', ['id' => $unknown]);

    try {
        Assert::same(
            'table',
            R::resourceTypeFor(['main_attraction_id' => $attraction], 500),
            'five hundred guests do not disqualify a room whose capacity is unknown'
        );
    } finally {
        ba_cleanup([], [$unknown]);
        DB::delete('attractions', 'id = :id', ['id' => $attraction]);
    }
});

Harness::test('a package with no attraction, and one whose attraction has no resource, use a party room', function (): void {
    // The two ordinary cases, which the fallback above must not have broken.
    Assert::same(R::ROOM_TYPE, R::resourceTypeFor(null, 12), 'no package at all');
    Assert::same(R::ROOM_TYPE, R::resourceTypeFor([], 12), 'a package with no attraction');
    Assert::same(
        R::ROOM_TYPE,
        R::resourceTypeFor(['main_attraction_id' => null], 12),
        'an explicit null attraction'
    );

    $bare = ba_attraction();

    try {
        Assert::same(
            R::ROOM_TYPE,
            R::resourceTypeFor(['main_attraction_id' => $bare], 12),
            'and an attraction with no resource of its own'
        );
    } finally {
        DB::delete('attractions', 'id = :id', ['id' => $bare]);
    }
});
