<?php

declare(strict_types=1);

/**
 * Can we host this party?
 *
 * The cases here are the venue's awkward ones, which is the whole argument for
 * `docs/SPEC.md` §8.5 specifying this service as pure: a seasonal attraction
 * whose season wraps the new year, a seven-day notice rule hit on the seventh
 * day, a room freed by the cancellation of the booking being edited, a party
 * that fits the day but not the hours, and a Sunday that is open while the
 * Monday before it is not. None of them needs a database and none of them
 * needs to wait for a Saturday.
 *
 * Dates below are real 2026 dates and their weekdays are asserted where the
 * test turns on them, because "the 4th of July" meaning Saturday is exactly
 * the kind of assumption that silently inverts a test.
 */

use App\Services\AvailabilityEngine as A;
use Tests\Assert;
use Tests\Harness;

Harness::suite('unit/availability');

/** The venue's seeded weekly pattern: late opening Mon-Thu, 11am Fri-Sun. */
function av_hours(): array
{
    return [
        ['day_of_week' => 0, 'opens_at' => '11:00:00', 'closes_at' => '20:00:00'],
        ['day_of_week' => 1, 'opens_at' => '15:00:00', 'closes_at' => '20:00:00'],
        ['day_of_week' => 2, 'opens_at' => '15:00:00', 'closes_at' => '20:00:00'],
        ['day_of_week' => 3, 'opens_at' => '15:00:00', 'closes_at' => '20:00:00'],
        ['day_of_week' => 4, 'opens_at' => '15:00:00', 'closes_at' => '21:00:00'],
        ['day_of_week' => 5, 'opens_at' => '11:00:00', 'closes_at' => '23:00:00'],
        ['day_of_week' => 6, 'opens_at' => '11:00:00', 'closes_at' => '23:00:00'],
    ];
}

/** Two party rooms, as the seed has them. */
function av_rooms(int $count = 2, ?int $capacity = 20): array
{
    $rooms = [];

    for ($i = 1; $i <= $count; $i++) {
        $rooms[] = ['id' => $i, 'name' => 'Party Room ' . $i, 'type' => 'party_room', 'capacity' => $capacity];
    }

    return $rooms;
}

/** A Saturday in high summer, well clear of any notice rule. */
function av_day(array $overrides = []): array
{
    return array_merge([
        'date'             => '2026-07-11',
        'today'            => '2026-06-01',
        'duration_minutes' => 120,
        'min_notice_days'  => 7,
        'buffer_minutes'   => 30,
        'step_minutes'     => 30,
        'guest_count'      => 12,
        'hours'            => av_hours(),
        'resources'        => av_rooms(),
        'allocations'      => [],
    ], $overrides);
}

// ----------------------------------------------------- the calendar itself

Harness::test('the weekday arithmetic agrees with PHP across 130 years', function (): void {
    // Zeller's congruence is used instead of a DateTime per call because this
    // runs inside the slot walk. It is only worth having if it is right.
    $mismatches = 0;

    for ($year = 1970; $year <= 2100; $year++) {
        foreach ([[1, 1], [2, 28], [3, 1], [7, 4], [12, 31]] as [$month, $day]) {
            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);

            if (A::dayOfWeek($date) !== (int) gmdate('w', (int) gmmktime(0, 0, 0, $month, $day, $year))) {
                $mismatches++;
            }
        }
    }

    Assert::same(0, $mismatches);
    Assert::same(6, A::dayOfWeek('2026-07-11'), 'the fixture date is a Saturday');
    Assert::same(0, A::dayOfWeek('2026-07-12'), 'and 0 means Sunday, matching the hours table');
});

Harness::test('date arithmetic crosses months, years and leap days', function (): void {
    // Day numbers rather than timestamps, so a local midnight that does not
    // exist on a DST changeover cannot shift a notice rule by a day.
    Assert::same('2026-03-08', A::addDays('2026-03-01', 7));
    Assert::same('2027-01-04', A::addDays('2026-12-30', 5));
    Assert::same('2024-03-01', A::addDays('2024-02-28', 2), 'a leap year has a 29th');
    Assert::same('2026-03-02', A::addDays('2026-02-28', 2), 'and a common year does not');
    Assert::same(7, A::daysBetween('2026-03-08', '2026-03-15'), 'the US DST changeover week');
    Assert::same(-1, A::daysBetween('2026-07-11', '2026-07-10'));
    Assert::same(0, A::daysBetween('2026-07-11', '2026-07-11'));
});

Harness::test('a date that is not a date is refused, not guessed at', function (): void {
    Assert::false(A::isDate('2026-02-30'), 'February has no 30th');
    Assert::true(A::isDate('2024-02-29'));
    Assert::false(A::isDate('2026-7-4'), 'and the format is fixed');

    $result = A::day(av_day(['date' => 'next Saturday']));

    Assert::false($result['ok']);
});

// ------------------------------------------------------------------ notice

Harness::test('the notice rule is satisfied ON the seventh day, not after it', function (): void {
    // THE BOUNDARY. A venue that says "seven days' notice" means seven dates
    // on the wall. Off by one here either turns away a legitimate booking or
    // accepts one the kitchen cannot staff.
    $seventh = A::day(av_day(['today' => '2026-07-04', 'date' => '2026-07-11']));

    Assert::true($seventh['ok'], 'exactly seven days away is allowed');

    $sixth = A::day(av_day(['today' => '2026-07-05', 'date' => '2026-07-11']));

    Assert::false($sixth['ok']);
    Assert::same('too_soon', $sixth['reason']);
});

Harness::test('a refusal for notice names the earliest date that would work', function (): void {
    // What the public form turns into an offer instead of a dead end.
    $result = A::day(av_day(['today' => '2026-07-05', 'date' => '2026-07-08']));

    Assert::same('too_soon', $result['reason']);
    Assert::same('2026-07-12', $result['earliest_date']);
    Assert::contains('7 days', $result['message']);
    Assert::contains('Sunday 12 July 2026', $result['message']);
});

Harness::test('a single day of notice is phrased as a day, not "1 days"', function (): void {
    $result = A::day(av_day(['today' => '2026-07-11', 'date' => '2026-07-11', 'min_notice_days' => 1]));

    Assert::same('too_soon', $result['reason']);
    Assert::contains("a day's notice", $result['message']);
});

Harness::test('a date in the past is refused as past, not as too soon', function (): void {
    // Two different answers: one is a mistake to correct, the other is a rule
    // with a date attached.
    $result = A::day(av_day(['today' => '2026-07-12', 'date' => '2026-07-11']));

    Assert::same('past', $result['reason']);
    Assert::contains('already passed', $result['message']);
});

Harness::test('a package with no notice rule can be booked for today', function (): void {
    // Walk-in group bookings are a real thing, and min_notice_days 0 is how a
    // venue says so.
    $result = A::day(av_day(['today' => '2026-07-11', 'date' => '2026-07-11', 'min_notice_days' => 0]));

    Assert::true($result['ok']);
});

// ----------------------------------------------------------- opening hours

Harness::test('the weekly pattern decides the window, by weekday', function (): void {
    $saturday = A::day(av_day(['date' => '2026-07-11']));
    $monday   = A::day(av_day(['date' => '2026-07-13']));

    Assert::same(11 * 60, $saturday['opens'], 'Saturdays open at 11');
    Assert::same(23 * 60, $saturday['closes']);
    Assert::same(15 * 60, $monday['opens'], 'Mondays open at 3');
    Assert::same(20 * 60, $monday['closes']);
});

Harness::test('a day with no row in the hours table is closed', function (): void {
    $result = A::day(av_day(['hours' => [['day_of_week' => 1, 'opens_at' => '15:00', 'closes_at' => '20:00']]]));

    Assert::false($result['ok']);
    Assert::same('closed', $result['reason']);
    Assert::contains('closed on Saturday 11 July 2026', $result['message']);
});

Harness::test('an exception closes a day the weekly pattern says is open', function (): void {
    // Thanksgiving is not "whatever Thursdays usually are".
    $result = A::day(av_day([
        'exception' => ['is_closed' => 1, 'note' => 'Independence Day'],
    ]));

    Assert::same('closed', $result['reason']);
    Assert::contains('Independence Day', $result['message'], 'and the note explains why');
});

Harness::test('an exception can give a day its own hours', function (): void {
    $result = A::day(av_day([
        'exception' => ['is_closed' => 0, 'opens_at' => '09:00:00', 'closes_at' => '14:00:00'],
    ]));

    Assert::true($result['ok']);
    Assert::same(9 * 60, $result['opens'], 'the exception wins outright');
    Assert::same(14 * 60, $result['closes']);
});

Harness::test('an exception that says open without saying when falls back to the pattern', function (): void {
    // The venue meant SOMETHING by that row. Closing the day would be a
    // guess, and so would inventing hours for it.
    $result = A::day(av_day(['exception' => ['is_closed' => 0]]));

    Assert::true($result['ok']);
    Assert::same(11 * 60, $result['opens']);
});

Harness::test('a dated seasonal row beats the open-ended standard one', function (): void {
    $hours = array_merge(av_hours(), [
        [
            'day_of_week'    => 6,
            'opens_at'       => '10:00:00',
            'closes_at'      => '23:59:00',
            'effective_from' => '2026-06-15',
            'effective_to'   => '2026-09-07',
            'season_label'   => 'Summer',
        ],
    ]);

    $summer = A::openWindow('2026-07-11', $hours, null);
    $spring = A::openWindow('2026-05-09', $hours, null);

    Assert::same(10 * 60, $summer['opens'], 'in season, the dated row applies');
    Assert::same(11 * 60, $spring['opens'], 'outside it, the standard pattern does');
});

Harness::test('two rows for the same day open for the union of them', function (): void {
    $hours = [
        ['day_of_week' => 6, 'opens_at' => '11:00', 'closes_at' => '16:00'],
        ['day_of_week' => 6, 'opens_at' => '10:00', 'closes_at' => '23:00'],
    ];

    $window = A::openWindow('2026-07-11', $hours, null);

    Assert::same(10 * 60, $window['opens']);
    Assert::same(23 * 60, $window['closes']);
});

Harness::test('a nonsense window is ignored rather than producing negative time', function (): void {
    $window = A::openWindow('2026-07-11', [
        ['day_of_week' => 6, 'opens_at' => '23:00', 'closes_at' => '09:00'],
    ], null);

    Assert::null($window, 'closing before opening is not an overnight window, it is a typo');
});

// ---------------------------------------------------------------- seasons

Harness::test('a summer season includes both of its end days', function (): void {
    $pool = ['name' => 'Water Slide', 'is_seasonal' => 1, 'season_start_md' => '05-01', 'season_end_md' => '09-30'];

    Assert::true(A::inSeason('2026-05-01', $pool), 'the first day is in season');
    Assert::true(A::inSeason('2026-09-30', $pool), 'and so is the last');
    Assert::true(A::inSeason('2026-07-11', $pool));
    Assert::false(A::inSeason('2026-04-30', $pool));
    Assert::false(A::inSeason('2026-10-01', $pool));
});

Harness::test('A SEASON THAT WRAPS THE NEW YEAR is tested as a union', function (): void {
    // THE TRAP. Ice skating from 15 November to 15 March is a real Castle
    // season. A naive range comparison — md >= start AND md <= end — is false
    // for every day of it and true for the eight months the rink is shut,
    // which closes the attraction for its own season and opens it for the
    // off-season. Exactly backwards, and it would look like a booking-form
    // bug rather than a date bug.
    $rink = ['name' => 'Ice Rink', 'is_seasonal' => 1, 'season_start_md' => '11-15', 'season_end_md' => '03-15'];

    Assert::true(A::inSeason('2026-12-25', $rink), 'Christmas is in the skating season');
    Assert::true(A::inSeason('2026-01-02', $rink), 'and so is the second of January');
    Assert::true(A::inSeason('2026-11-15', $rink), 'the first day');
    Assert::true(A::inSeason('2026-03-15', $rink), 'and the last');
    Assert::false(A::inSeason('2026-07-11', $rink), 'July is not');
    Assert::false(A::inSeason('2026-03-16', $rink));
    Assert::false(A::inSeason('2026-11-14', $rink));
});

Harness::test('a non-seasonal attraction is always in season', function (): void {
    Assert::true(A::inSeason('2026-01-01', ['is_seasonal' => 0, 'season_start_md' => '05-01', 'season_end_md' => '09-30']));
});

Harness::test('seasonal with no season recorded is open, not shut', function (): void {
    // A half-filled row is a missing field, not a closure. Refusing every
    // date on that basis would take an attraction off the form silently.
    Assert::true(A::inSeason('2026-01-01', ['is_seasonal' => 1]));
    Assert::true(A::inSeason('2026-01-01', ['is_seasonal' => 1, 'season_start_md' => '05-01']));
    Assert::true(A::inSeason('2026-01-01', ['is_seasonal' => 1, 'season_start_md' => 'summer', 'season_end_md' => 'autumn']));
});

Harness::test('29 February is a valid season boundary', function (): void {
    $odd = ['is_seasonal' => 1, 'season_start_md' => '02-29', 'season_end_md' => '03-31'];

    Assert::true(A::inSeason('2026-03-01', $odd), 'readable even in a non-leap year');
    Assert::false(A::inSeason('2026-02-28', $odd));
});

Harness::test('an out-of-season attraction refuses with its season in the payload', function (): void {
    $result = A::day(av_day([
        'attraction' => [
            'name'            => 'Water Slide',
            'is_seasonal'     => 1,
            'season_start_md' => '05-01',
            'season_end_md'   => '06-30',
        ],
    ]));

    Assert::same('out_of_season', $result['reason']);
    Assert::contains('Water Slide is seasonal', $result['message']);
    Assert::same('1 May to 30 June', $result['season']);
});

Harness::test('a one-off closure of an attraction refuses for that date only', function (): void {
    $closed = A::day(av_day([
        'attraction'        => ['name' => 'Laser Tag Arena'],
        'attraction_closed' => true,
        'attraction_note'   => 'annual maintenance',
    ]));

    Assert::same('attraction_unavailable', $closed['reason']);
    Assert::contains('annual maintenance', $closed['message']);

    $open = A::day(av_day(['attraction' => ['name' => 'Laser Tag Arena']]));

    Assert::true($open['ok'], 'and the next date is unaffected');
});

Harness::test('an inactive attraction is not bookable', function (): void {
    $result = A::day(av_day(['attraction' => ['name' => 'Old Ride', 'is_active' => 0]]));

    Assert::same('attraction_unavailable', $result['reason']);
});

// -------------------------------------------------------------- resources

Harness::test('a party larger than every room says so, with the largest size', function (): void {
    $result = A::day(av_day(['guest_count' => 40, 'resources' => av_rooms(2, 20)]));

    Assert::same('too_large', $result['reason']);
    Assert::same(20, $result['largest_capacity']);
    Assert::contains('largest holds 20', $result['message']);
});

Harness::test('no rooms of that type at all is a different answer from none big enough', function (): void {
    // One is "call us"; the other is "set your rooms up in settings".
    $result = A::day(av_day(['resources' => []]));

    Assert::same('no_resource', $result['reason']);
    Assert::contains('no room of that type set up', $result['message']);
});

Harness::test('a room with no stated capacity is usable, not unusable', function (): void {
    // The skate rink has no seat count. Excluding NULL capacity would make
    // every attraction without one silently unbookable.
    $result = A::usable([['id' => 1, 'name' => 'Skate Rink', 'capacity' => null]], 300);

    Assert::count(1, $result['usable']);
});

Harness::test('an inactive room is not offered', function (): void {
    $result = A::usable([
        ['id' => 1, 'name' => 'Party Room 1', 'capacity' => 20, 'is_active' => 1],
        ['id' => 2, 'name' => 'Party Room 2', 'capacity' => 20, 'is_active' => 0],
    ], 12);

    Assert::count(1, $result['usable']);
    Assert::same(1, (int) $result['usable'][0]['id']);
});

// ------------------------------------------------------------ the slot walk

Harness::test('slots run from opening until the last one that ends by close', function (): void {
    // Saturday 11:00-23:00, a two-hour party on the half hour: 11:00 through
    // 21:00 inclusive is 21 starts. A party that runs past closing is the
    // thing this bound exists to prevent.
    $result = A::day(av_day());

    Assert::true($result['ok']);
    Assert::count(21, $result['slots']);
    Assert::same('11:00 AM', $result['slots'][0]['start_label']);
    Assert::same('1:00 PM', $result['slots'][0]['end_label']);
    Assert::same('9:00 PM', $result['slots'][20]['start_label']);
    Assert::same('11:00 PM', $result['slots'][20]['end_label']);
});

Harness::test('a party that cannot fit the open hours says so with the hours', function (): void {
    // Monday is 15:00-20:00. A six-hour party does not fit, and the answer
    // names the window rather than reporting an empty day.
    $result = A::day(av_day(['date' => '2026-07-13', 'duration_minutes' => 360]));

    Assert::same('window_too_short', $result['reason']);
    Assert::contains('6 hours', $result['message']);
    Assert::contains('3:00 PM to 8:00 PM', $result['message']);
});

Harness::test('an existing booking takes its room out of the overlapping slots', function (): void {
    // Room 1 booked 14:00-16:00. With a 30-minute buffer that blocks
    // 13:30-16:30, so a two-hour party cannot start between 12:00 and 16:00.
    $result = A::day(av_day([
        'resources'   => av_rooms(1),
        'allocations' => [['resource_id' => 1, 'booking_id' => 99, 'start' => 14 * 60, 'end' => 16 * 60]],
    ]));

    $starts = array_column($result['slots'], 'start_label');

    Assert::inArray('11:30 AM', $starts, 'a party ending at 1:30 clears the buffer');
    Assert::notContains('12:00 PM', implode('|', $starts), 'but one ending at 2:00 does not');
    Assert::notContains('3:30 PM', implode('|', $starts));
    Assert::inArray('4:30 PM', $starts, 'and one starting after the buffer is fine');
});

Harness::test('the buffer is what separates two parties in the same room', function (): void {
    // Without a buffer, back-to-back is allowed: half-open intervals mean a
    // party ending at 14:00 and one starting at 14:00 do not overlap.
    $none = A::day(av_day([
        'resources'      => av_rooms(1),
        'buffer_minutes' => 0,
        'allocations'    => [['resource_id' => 1, 'booking_id' => 99, 'start' => 14 * 60, 'end' => 16 * 60]],
    ]));

    Assert::inArray('12:00 PM', array_column($none['slots'], 'start_label'), 'ends exactly as the next begins');
    Assert::inArray('4:00 PM', array_column($none['slots'], 'start_label'), 'and begins exactly as it ends');
});

Harness::test('a second room keeps the slot available', function (): void {
    $result = A::day(av_day([
        'resources'   => av_rooms(2),
        'allocations' => [['resource_id' => 1, 'booking_id' => 99, 'start' => 14 * 60, 'end' => 16 * 60]],
    ]));

    $noon = null;

    foreach ($result['slots'] as $slot) {
        if ($slot['start_label'] === '12:00 PM') {
            $noon = $slot;
        }
    }

    Assert::notNull($noon, 'the slot survives because room 2 is free');
    Assert::same([2], $noon['resource_ids'], 'and it offers only the room that is actually free');
    Assert::same(1, $noon['free_count']);
});

Harness::test('a fully booked day reports which times exist but are taken', function (): void {
    // The staff calendar has to be able to say "3pm, all rooms taken" rather
    // than showing a gap, which reads as a bug in the calendar.
    $result = A::day(av_day([
        'resources'   => av_rooms(1),
        'allocations' => [['resource_id' => 1, 'booking_id' => 99, 'start' => 11 * 60, 'end' => 23 * 60]],
    ]));

    Assert::false($result['ok']);
    Assert::same('fully_booked', $result['reason']);
    Assert::count(21, $result['taken'], 'every slot that would have existed');
    Assert::same('11:00 AM', $result['taken'][0]['start_label']);
});

Harness::test('editing a booking does not collide with its own allocation', function (): void {
    // Without this, saving a booking you have not rescheduled refuses itself.
    $allocations = [['resource_id' => 1, 'booking_id' => 42, 'start' => 14 * 60, 'end' => 16 * 60]];

    $blocked = A::day(av_day(['resources' => av_rooms(1), 'allocations' => $allocations]));
    $editing = A::day(av_day([
        'resources'          => av_rooms(1),
        'allocations'        => $allocations,
        'exclude_booking_id' => 42,
    ]));

    Assert::notContains('2:00 PM', implode('|', array_column($blocked['slots'], 'start_label')));
    Assert::inArray('2:00 PM', array_column($editing['slots'], 'start_label'));
});

Harness::test('an allocation with no end, or none at all, is ignored rather than blocking the day', function (): void {
    $result = A::day(av_day([
        'resources'   => av_rooms(1),
        'allocations' => [
            ['resource_id' => 1, 'booking_id' => 1, 'start' => 14 * 60, 'end' => 14 * 60],
            ['resource_id' => 0, 'booking_id' => 2, 'start' => 11 * 60, 'end' => 23 * 60],
        ],
    ]));

    Assert::true($result['ok'], 'a zero-length or unassigned allocation blocks nothing');
    Assert::count(21, $result['slots']);
});

Harness::test('an explicit floor and ceiling narrow the day further', function (): void {
    // The public form keeps parties out of the last hour before close even
    // when the duration would technically fit.
    $result = A::day(av_day(['earliest_start' => 12 * 60, 'latest_end' => 20 * 60]));

    Assert::same('12:00 PM', $result['slots'][0]['start_label']);
    Assert::same(
        '6:00 PM',
        $result['slots'][count($result['slots']) - 1]['start_label'],
        'the last two-hour party ends at 8'
    );
});

Harness::test('the step controls how closely together times are offered', function (): void {
    $half = A::day(av_day(['step_minutes' => 30]));
    $hour = A::day(av_day(['step_minutes' => 60]));

    Assert::count(21, $half['slots']);
    Assert::count(11, $hour['slots']);
});

// ---------------------------------------------------- one specific time

Harness::test('a requested time is answered against the same rules the calendar used', function (): void {
    // at() runs day() rather than reimplementing anything, so a time the
    // calendar offered and a time the save accepts cannot come apart.
    $result = A::at(av_day(['resources' => av_rooms(1)]), 14 * 60);

    Assert::true($result['ok']);
    Assert::same(14 * 60, $result['slot']['start']);
    Assert::same([1], $result['slot']['resource_ids']);
});

Harness::test('a requested time off the usual step is still answerable', function (): void {
    // A guest asking for 2:15 must not be told the venue is fully booked.
    $result = A::at(av_day(['resources' => av_rooms(1)]), 14 * 60 + 15);

    Assert::true($result['ok']);
    Assert::same('2:15 PM', $result['slot']['start_label']);
});

Harness::test('a taken time is refused as booked, and an impossible one as out of hours', function (): void {
    $booked = A::at(av_day([
        'resources'   => av_rooms(1),
        'allocations' => [['resource_id' => 1, 'booking_id' => 7, 'start' => 14 * 60, 'end' => 16 * 60]],
    ]), 14 * 60);

    Assert::same('fully_booked', $booked['reason']);
    Assert::contains('2:00 PM', $booked['message']);
    Assert::notEmpty($booked['slots'], 'and the times that ARE free come back with the refusal');

    $late = A::at(av_day(), 22 * 60 + 30);

    Assert::same('window_too_short', $late['reason']);
    Assert::contains('outside the hours', $late['message']);
});

Harness::test('a day-level refusal is passed through by the single-time check', function (): void {
    $result = A::at(av_day(['exception' => ['is_closed' => 1]]), 14 * 60);

    Assert::same('closed', $result['reason']);
});

// ------------------------------------------------------------------ labels

Harness::test('times and durations are rendered the way a person reads them', function (): void {
    Assert::same('12:00 AM', A::timeLabel(0));
    Assert::same('12:00 PM', A::timeLabel(12 * 60));
    Assert::same('12:30 AM', A::timeLabel(30));
    Assert::same('11:59 PM', A::timeLabel(23 * 60 + 59));
    Assert::same('2 hours', A::durationLabel(120));
    Assert::same('1 hour', A::durationLabel(60));
    Assert::same('2 hours 30 minutes', A::durationLabel(150));
    Assert::same('45 minutes', A::durationLabel(45));
    Assert::same('1 minute', A::durationLabel(1));
});

Harness::test('a TIME column value round-trips through minutes', function (): void {
    Assert::same(14 * 60 + 30, A::minutes('14:30:00'));
    Assert::same(14 * 60 + 30, A::minutes('14:30'));
    Assert::same('14:30:00', A::timeValue(14 * 60 + 30));
    Assert::null(A::minutes('25:00'));
    Assert::null(A::minutes('14:70'));
    Assert::null(A::minutes(''));
    Assert::null(A::minutes('2pm'));
});

Harness::test('every refusal reason is one the engine declares', function (): void {
    // The public form branches on these, so a reason invented in passing
    // would fall through its match and show a blank page.
    $cases = [
        av_day(['today' => '2026-07-12', 'date' => '2026-07-11']),
        av_day(['today' => '2026-07-10']),
        av_day(['exception' => ['is_closed' => 1]]),
        av_day(['attraction' => ['is_seasonal' => 1, 'season_start_md' => '01-01', 'season_end_md' => '01-02']]),
        av_day(['attraction_closed' => true]),
        av_day(['guest_count' => 400]),
        av_day(['resources' => []]),
        av_day(['duration_minutes' => 900]),
        av_day([
            'resources'   => av_rooms(1),
            'allocations' => [['resource_id' => 1, 'booking_id' => 1, 'start' => 0, 'end' => 1439]],
        ]),
    ];

    foreach ($cases as $index => $case) {
        $result = A::day($case);

        Assert::false($result['ok'], 'case ' . $index . ' should refuse');
        Assert::inArray($result['reason'], A::REASONS, 'case ' . $index . ' reason: ' . $result['reason']);
        Assert::notEmpty($result['message'], 'case ' . $index . ' needs a sentence a guest can read');
        Assert::same([], $result['slots']);
    }
});
