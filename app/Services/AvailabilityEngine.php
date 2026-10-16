<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Can we host N guests doing A at time T on date D?
 *
 * `docs/SPEC.md` §8.5 specifies this as a pure service and lists the five
 * things it has to intersect: operating hours and their exceptions, an
 * attraction's season, the resources of the required type minus what is
 * already allocated, the package's duration and buffer, and the package's
 * minimum notice. All five are here, and NONE of them is read from the
 * database, the settings or the clock by this class — every input is passed
 * in, including today's date. That is what makes a venue's own awkward cases
 * (a party on the last day of the season, a 7-day notice rule hit on the
 * seventh day, a room freed by a cancellation) writable as tests instead of
 * reproducible only by waiting for a Saturday.
 *
 * `BookingRepository` is the other half: it does the reading, converts stored
 * UTC timestamps into the local minute offsets used here, and hands the result
 * back. Nothing in this file decides anything about I/O, and nothing in that
 * file decides anything about availability.
 *
 * WHY MINUTES SINCE LOCAL MIDNIGHT. A party is scheduled in wall-clock time —
 * "two o'clock on the 14th" means two o'clock whatever the UTC offset is that
 * week. Working in local minutes keeps every comparison in this file honest
 * about that, and confines the one genuinely hard conversion (local wall clock
 * to UTC on a DST changeover date, where 2am may not exist or may happen
 * twice) to the repository, where a real DateTimeZone can do it properly.
 *
 * A REFUSAL IS A RESULT, NOT AN ERROR. The public booking form has to turn
 * "no" into something useful: another date, another time, or the venue's phone
 * number. So every refusal carries a machine-readable `reason`, a sentence a
 * guest can read, and — where one exists — the earliest date that would work.
 * Returning an empty slot list with no explanation is what makes a booking
 * form feel broken when it is in fact working correctly.
 */
final class AvailabilityEngine
{
    /**
     * Every reason this engine can refuse for.
     *
     * `past` and `too_soon` are deliberately separate: one is a mistake, the
     * other is a rule with a date attached that the form can offer instead.
     */
    public const REASONS = [
        'past',
        'too_soon',
        'closed',
        'out_of_season',
        'attraction_unavailable',
        'too_large',
        'window_too_short',
        'no_resource',
        'fully_booked',
    ];

    /** Default spacing between offered start times. */
    public const DEFAULT_STEP_MINUTES = 30;

    /** Clean-up and turnaround between two parties in the same room. */
    public const DEFAULT_BUFFER_MINUTES = 30;

    /**
     * Work out what is available on one date.
     *
     * @param array{
     *     date:string,
     *     today:string,
     *     duration_minutes?:int,
     *     min_notice_days?:int,
     *     buffer_minutes?:int,
     *     step_minutes?:int,
     *     guest_count?:int,
     *     hours?:list<array<string,mixed>>,
     *     exception?:array<string,mixed>|null,
     *     attraction?:array<string,mixed>|null,
     *     attraction_closed?:bool,
     *     attraction_note?:string,
     *     resources?:list<array<string,mixed>>,
     *     allocations?:list<array<string,mixed>>,
     *     exclude_booking_id?:int|null,
     *     earliest_start?:int|null,
     *     latest_end?:int|null
     * } $input
     * @return array<string,mixed>
     */
    public static function day(array $input): array
    {
        $date  = (string) ($input['date'] ?? '');
        $today = (string) ($input['today'] ?? '');

        $duration = max(0, (int) ($input['duration_minutes'] ?? 0));
        $buffer   = max(0, (int) ($input['buffer_minutes'] ?? self::DEFAULT_BUFFER_MINUTES));
        $step     = max(5, (int) ($input['step_minutes'] ?? self::DEFAULT_STEP_MINUTES));
        $guests   = max(0, (int) ($input['guest_count'] ?? 0));
        $notice   = max(0, (int) ($input['min_notice_days'] ?? 0));

        if (!self::isDate($date) || !self::isDate($today)) {
            return self::refuse($date, 'past', 'That date could not be read.');
        }

        // --- 1. the date itself ------------------------------------------
        $daysAway = self::daysBetween($today, $date);

        if ($daysAway < 0) {
            return self::refuse($date, 'past', 'That date has already passed.');
        }

        if ($daysAway < $notice) {
            // The rule is in CALENDAR days, not 24-hour periods: a venue that
            // says "seven days' notice" means seven dates on the wall, and a
            // booking made at 11pm on the 1st for the 8th satisfies it.
            $earliest = self::addDays($today, $notice);

            return self::refuse(
                $date,
                'too_soon',
                $notice === 1
                    ? 'That package needs at least a day\'s notice.'
                    : 'That package needs ' . $notice . ' days\' notice, so the earliest date is '
                        . self::dateLabel($earliest) . '.',
                ['earliest_date' => $earliest, 'min_notice_days' => $notice]
            );
        }

        // --- 2. opening hours --------------------------------------------
        $window = self::openWindow($date, $input['hours'] ?? [], $input['exception'] ?? null);

        if ($window === null) {
            $note = isset($input['exception']['note']) && (string) $input['exception']['note'] !== ''
                ? ' (' . (string) $input['exception']['note'] . ')'
                : '';

            return self::refuse($date, 'closed', 'The venue is closed on ' . self::dateLabel($date) . $note . '.');
        }

        // An explicit floor or ceiling narrows the window further — the public
        // form uses it to keep parties out of the last hour before close even
        // when the duration would technically fit.
        $opens  = max($window['opens'], (int) ($input['earliest_start'] ?? $window['opens']));
        $closes = min($window['closes'], (int) ($input['latest_end'] ?? $window['closes']));

        // --- 3. the attraction's season ----------------------------------
        $attraction = $input['attraction'] ?? null;

        if (is_array($attraction) && $attraction !== []) {
            $name = (string) ($attraction['name'] ?? 'That attraction');

            if (isset($attraction['is_active']) && (int) $attraction['is_active'] === 0) {
                return self::refuse($date, 'attraction_unavailable', $name . ' is not currently offered.');
            }

            if (!self::inSeason($date, $attraction)) {
                return self::refuse(
                    $date,
                    'out_of_season',
                    $name . ' is seasonal and is not open on ' . self::dateLabel($date) . '.',
                    ['season' => self::seasonLabel($attraction)]
                );
            }
        }

        if (($input['attraction_closed'] ?? false) === true) {
            $note = (string) ($input['attraction_note'] ?? '');

            return self::refuse(
                $date,
                'attraction_unavailable',
                trim(
                    (is_array($attraction) ? (string) ($attraction['name'] ?? 'That attraction') : 'That attraction')
                    . ' is unavailable on ' . self::dateLabel($date) . ($note !== '' ? ' (' . $note . ')' : '') . '.'
                )
            );
        }

        // --- 4. resources big enough for the party -----------------------
        $resources = self::usable($input['resources'] ?? [], $guests);

        if ($resources['usable'] === [] ) {
            // Separate reasons, because they need different answers: nothing
            // of this type exists at all, versus nothing of it is big enough.
            return $resources['too_small'] > 0
                ? self::refuse(
                    $date,
                    'too_large',
                    'No room of that type holds ' . $guests . ' guests. The largest holds '
                        . $resources['largest'] . '.',
                    ['largest_capacity' => $resources['largest'], 'guest_count' => $guests]
                )
                : self::refuse($date, 'no_resource', 'There is no room of that type set up yet.');
        }

        // --- 5. the duration has to fit inside the open window -----------
        if ($duration > 0 && $opens + $duration > $closes) {
            return self::refuse(
                $date,
                'window_too_short',
                'A ' . self::durationLabel($duration) . ' party does not fit into '
                    . self::dateLabel($date) . '\'s opening hours ('
                    . self::timeLabel($window['opens']) . ' to ' . self::timeLabel($window['closes']) . ').',
                ['opens' => $window['opens'], 'closes' => $window['closes']]
            );
        }

        // --- 6. walk the window ------------------------------------------
        $busy  = self::busyByResource($input['allocations'] ?? [], $input['exclude_booking_id'] ?? null, $buffer);
        $slots = [];
        $taken = [];

        $last = $duration > 0 ? $closes - $duration : $closes;

        for ($start = $opens; $start <= $last; $start += $step) {
            $end  = $duration > 0 ? $start + $duration : $closes;
            $free = [];

            foreach ($resources['usable'] as $resource) {
                $id = (int) $resource['id'];

                if (!self::collides($busy[$id] ?? [], $start, $end)) {
                    $free[] = $resource;
                }
            }

            if ($free === []) {
                // Offered to STAFF so the calendar can say "3pm — all rooms
                // taken" rather than showing a gap, which reads as a bug.
                $taken[] = [
                    'start'       => $start,
                    'end'         => $end,
                    'start_label' => self::timeLabel($start),
                    'end_label'   => self::timeLabel($end),
                ];

                continue;
            }

            $slots[] = [
                'start'          => $start,
                'end'            => $end,
                'start_label'    => self::timeLabel($start),
                'end_label'      => self::timeLabel($end),
                'resource_ids'   => array_map(static fn (array $r): int => (int) $r['id'], $free),
                'resource_names' => array_map(static fn (array $r): string => (string) ($r['name'] ?? ''), $free),
                'free_count'     => count($free),
            ];
        }

        if ($slots === []) {
            return self::refuse(
                $date,
                $taken === [] ? 'window_too_short' : 'fully_booked',
                $taken === []
                    ? 'There is no time on ' . self::dateLabel($date) . ' long enough for that party.'
                    : self::dateLabel($date) . ' is fully booked for that package.',
                ['taken' => $taken, 'opens' => $window['opens'], 'closes' => $window['closes']]
            );
        }

        return [
            'ok'          => true,
            'date'        => $date,
            'reason'      => null,
            'message'     => count($slots) === 1
                ? 'One time is available on ' . self::dateLabel($date) . '.'
                : count($slots) . ' times are available on ' . self::dateLabel($date) . '.',
            'opens'       => $window['opens'],
            'closes'      => $window['closes'],
            'opens_label' => self::timeLabel($window['opens']),
            'closes_label' => self::timeLabel($window['closes']),
            'slots'       => $slots,
            'taken'       => $taken,
            'resources'   => $resources['usable'],
        ];
    }

    /**
     * Is one specific time free?
     *
     * The booking form posts a time; this answers whether that exact request
     * can be honoured and which resources could take it. It runs day() and
     * then looks for the slot, rather than reimplementing the rules — so a
     * time the calendar offered and a time the save accepts can never come
     * apart, which is the bug this shape exists to prevent.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function at(array $input, int $startMinutes): array
    {
        // The requested time may not sit on the engine's own step, and a guest
        // asking for 2:15 should not be told the venue is fully booked. Step
        // down to a one-minute walk so any valid start is reachable.
        $day = self::day(['step_minutes' => 5] + $input);

        if (($day['ok'] ?? false) !== true) {
            return $day;
        }

        foreach ($day['slots'] as $slot) {
            if ($slot['start'] === $startMinutes) {
                return [
                    'ok'      => true,
                    'date'    => $day['date'],
                    'reason'  => null,
                    'message' => self::timeLabel($startMinutes) . ' is available.',
                    'slot'    => $slot,
                    'slots'   => $day['slots'],
                ];
            }
        }

        foreach ($day['taken'] as $slot) {
            if ($slot['start'] === $startMinutes) {
                return self::refuse(
                    $day['date'],
                    'fully_booked',
                    'Every room is taken at ' . self::timeLabel($startMinutes) . ' on '
                        . self::dateLabel($day['date']) . '.',
                    ['slots' => $day['slots']]
                );
            }
        }

        return self::refuse(
            $day['date'],
            'window_too_short',
            self::timeLabel($startMinutes) . ' is outside the hours a party can run on '
                . self::dateLabel($day['date']) . ' (' . $day['opens_label'] . ' to ' . $day['closes_label'] . ').',
            ['slots' => $day['slots'], 'opens' => $day['opens'], 'closes' => $day['closes']]
        );
    }

    // =================================================================
    // The five intersections, each on its own so each can be tested
    // =================================================================

    /**
     * The hours the venue is open on this date, or null when it is closed.
     *
     * An exception row wins outright: a date in `operating_exceptions` is
     * either closed or has its own hours, and the weekly pattern does not get
     * a say. That is the point of the table — Thanksgiving is not "whatever
     * Thursdays usually are".
     *
     * @param list<array<string,mixed>>  $hours
     * @param array<string,mixed>|null   $exception
     * @return array{opens:int,closes:int}|null
     */
    public static function openWindow(string $date, array $hours, ?array $exception): ?array
    {
        if (is_array($exception) && $exception !== []) {
            if ((int) ($exception['is_closed'] ?? 0) === 1) {
                return null;
            }

            $opens  = self::minutes((string) ($exception['opens_at'] ?? ''));
            $closes = self::minutes((string) ($exception['closes_at'] ?? ''));

            // A row that says "open" without saying when is not usable on its
            // own, so the weekly pattern is consulted after all. The venue
            // meant something by the row; closing the day would be a guess,
            // and so would inventing hours.
            if ($opens !== null && $closes !== null && $closes > $opens) {
                return ['opens' => $opens, 'closes' => $closes];
            }
        }

        $dow  = (int) self::dayOfWeek($date);
        $best = null;

        foreach ($hours as $row) {
            if ((int) ($row['day_of_week'] ?? -1) !== $dow) {
                continue;
            }

            $from = (string) ($row['effective_from'] ?? '');
            $to   = (string) ($row['effective_to'] ?? '');

            if ($from !== '' && $date < $from) {
                continue;
            }

            if ($to !== '' && $date > $to) {
                continue;
            }

            $opens  = self::minutes((string) ($row['opens_at'] ?? ''));
            $closes = self::minutes((string) ($row['closes_at'] ?? ''));

            if ($opens === null || $closes === null || $closes <= $opens) {
                continue;
            }

            // A dated row beats the open-ended standard pattern: that is what
            // a seasonal schedule IS. Among dated rows, and among undated
            // ones, the widest window wins, because two overlapping rows for
            // one day mean the venue is open for the union of them.
            $dated = $from !== '' || $to !== '';

            if ($best === null
                || ($dated && !$best['dated'])
                || ($dated === $best['dated'] && ($closes - $opens) > ($best['closes'] - $best['opens']))
            ) {
                $best = ['opens' => $opens, 'closes' => $closes, 'dated' => $dated];
            }
        }

        return $best === null ? null : ['opens' => $best['opens'], 'closes' => $best['closes']];
    }

    /**
     * Is a seasonal attraction in season on this date?
     *
     * THE SEASON CAN WRAP THE NEW YEAR, and this is where a naive string
     * comparison gets it exactly backwards. Ice skating from 11-15 to 03-15 is
     * a real Castle season; `'12-25' >= '11-15' && '12-25' <= '03-15'` is
     * false, which would close the rink for the whole of its own season and
     * open it for the eight months it is shut. A wrapped range is therefore
     * detected (`end < start`) and tested as the UNION of its two halves.
     *
     * Both ends are INCLUSIVE: a season advertised as ending 09-30 includes
     * the 30th.
     *
     * @param array<string,mixed> $attraction
     */
    public static function inSeason(string $date, array $attraction): bool
    {
        if ((int) ($attraction['is_seasonal'] ?? 0) !== 1) {
            return true;
        }

        $start = self::monthDay((string) ($attraction['season_start_md'] ?? ''));
        $end   = self::monthDay((string) ($attraction['season_end_md'] ?? ''));

        // Flagged seasonal with no season recorded is a half-filled row, not a
        // closure. Refusing every date on that basis would take an attraction
        // off the booking form for a missing field.
        if ($start === null || $end === null) {
            return true;
        }

        $md = substr($date, 5, 5);

        return $start <= $end
            ? ($md >= $start && $md <= $end)
            : ($md >= $start || $md <= $end);
    }

    /**
     * Which resources could take this party, and why the others could not.
     *
     * A NULL capacity means "no stated limit", not "holds nobody". The schema
     * allows it and several real rows have it (the skate rink has no seat
     * count), so excluding them would quietly make those attractions
     * unbookable.
     *
     * @param list<array<string,mixed>> $resources
     * @return array{usable:list<array<string,mixed>>,too_small:int,largest:int}
     */
    public static function usable(array $resources, int $guests): array
    {
        $usable   = [];
        $tooSmall = 0;
        $largest  = 0;

        foreach ($resources as $resource) {
            if (isset($resource['is_active']) && (int) $resource['is_active'] === 0) {
                continue;
            }

            $capacity = $resource['capacity'] ?? null;

            if ($capacity === null || $capacity === '') {
                $usable[] = $resource;

                continue;
            }

            $capacity = (int) $capacity;
            $largest  = max($largest, $capacity);

            if ($guests > 0 && $capacity < $guests) {
                $tooSmall++;

                continue;
            }

            $usable[] = $resource;
        }

        return ['usable' => $usable, 'too_small' => $tooSmall, 'largest' => $largest];
    }

    /**
     * Allocations grouped by resource, each widened by the buffer.
     *
     * The buffer is applied to what is ALREADY booked rather than to the party
     * being placed, so one setting produces the same gap on both sides without
     * being counted twice. `exclude_booking_id` is what lets a booking be
     * edited without colliding with its own current allocation.
     *
     * @param list<array<string,mixed>> $allocations
     * @return array<int,list<array{start:int,end:int}>>
     */
    public static function busyByResource(array $allocations, ?int $excludeBookingId, int $buffer): array
    {
        $busy = [];

        foreach ($allocations as $allocation) {
            if ($excludeBookingId !== null
                && isset($allocation['booking_id'])
                && (int) $allocation['booking_id'] === $excludeBookingId
            ) {
                continue;
            }

            $id    = (int) ($allocation['resource_id'] ?? 0);
            $start = (int) ($allocation['start'] ?? 0);
            $end   = (int) ($allocation['end'] ?? 0);

            if ($id <= 0 || $end <= $start) {
                continue;
            }

            $busy[$id][] = ['start' => $start - $buffer, 'end' => $end + $buffer];
        }

        return $busy;
    }

    /**
     * Does [start, end) overlap anything already taken?
     *
     * Half-open on purpose: a party ending at 13:00 and one starting at 13:00
     * do not overlap. The gap between them is the buffer's job, which is
     * already baked into the ranges this receives.
     *
     * @param list<array{start:int,end:int}> $taken
     */
    public static function collides(array $taken, int $start, int $end): bool
    {
        foreach ($taken as $range) {
            if ($start < $range['end'] && $end > $range['start']) {
                return true;
            }
        }

        return false;
    }

    // =================================================================
    // Small pure helpers — dates and clock labels, no DateTime zones
    // =================================================================

    /** "14:30:00" or "14:30" into minutes since midnight. Null if unreadable. */
    public static function minutes(string $time): ?int
    {
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', trim($time), $match) !== 1) {
            return null;
        }

        $hours   = (int) $match[1];
        $minutes = (int) $match[2];

        if ($hours > 24 || $minutes > 59) {
            return null;
        }

        return $hours * 60 + $minutes;
    }

    /** Minutes since midnight as "14:30:00", for a TIME column. */
    public static function timeValue(int $minutes): string
    {
        $minutes = max(0, $minutes);

        return sprintf('%02d:%02d:00', intdiv($minutes, 60) % 24, $minutes % 60);
    }

    /** Minutes since midnight as "2:30 PM", for a person. */
    public static function timeLabel(int $minutes): string
    {
        $minutes %= 1440;
        $hour24  = intdiv($minutes, 60);
        $hour12  = $hour24 % 12 === 0 ? 12 : $hour24 % 12;

        return $hour12 . ':' . sprintf('%02d', $minutes % 60) . ' ' . ($hour24 < 12 ? 'AM' : 'PM');
    }

    /** 150 minutes as "2½ hour" reads badly; "2 hours 30 minutes" does not. */
    public static function durationLabel(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest  = $minutes % 60;

        if ($hours === 0) {
            return $rest . ' minute' . ($rest === 1 ? '' : 's');
        }

        $label = $hours . ' hour' . ($hours === 1 ? '' : 's');

        return $rest === 0 ? $label : $label . ' ' . $rest . ' minutes';
    }

    /** "2026-07-04" as "Saturday 4 July". */
    public static function dateLabel(string $date): string
    {
        if (!self::isDate($date)) {
            return $date;
        }

        [$y, $m, $d] = array_map('intval', explode('-', $date));

        $days   = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $months = [
            1 => 'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December',
        ];

        return $days[self::dayOfWeek($date)] . ' ' . $d . ' ' . ($months[$m] ?? (string) $m) . ' ' . $y;
    }

    /** 0 = Sunday, matching `operating_hours.day_of_week`. */
    public static function dayOfWeek(string $date): int
    {
        [$y, $m, $d] = array_map('intval', explode('-', $date));

        // Zeller, rather than a DateTime round trip: this runs inside the slot
        // walk and has no business allocating an object per call. Verified
        // against gmmktime() across 1970-2100 by the test suite.
        if ($m < 3) {
            $m += 12;
            $y--;
        }

        $k = $y % 100;
        $j = intdiv($y, 100);

        $h = ($d + intdiv(13 * ($m + 1), 5) + $k + intdiv($k, 4) + intdiv($j, 4) + 5 * $j) % 7;

        // Zeller's 0 is Saturday; this returns 0 for Sunday.
        return ($h + 6) % 7;
    }

    /** Whole days from $from to $to, negative when $to is earlier. */
    public static function daysBetween(string $from, string $to): int
    {
        return self::dayNumber($to) - self::dayNumber($from);
    }

    /** $date plus $days, as Y-m-d. */
    public static function addDays(string $date, int $days): string
    {
        return self::fromDayNumber(self::dayNumber($date) + $days);
    }

    public static function isDate(string $date): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $match) !== 1) {
            return false;
        }

        return checkdate((int) $match[2], (int) $match[3], (int) $match[1]);
    }

    /**
     * A date as a count of days, for arithmetic without a DateTime.
     *
     * The proleptic Gregorian day number (Fliegel–Van Flandern), so adding and
     * subtracting days cannot be bitten by a DST changeover — a local midnight
     * that does not exist is exactly the case that breaks a timestamp-based
     * "+7 days", and a notice rule counted in calendar days must not care.
     */
    private static function dayNumber(string $date): int
    {
        [$y, $m, $d] = array_map('intval', explode('-', $date));

        $a = intdiv(14 - $m, 12);
        $yy = $y + 4800 - $a;
        $mm = $m + 12 * $a - 3;

        return $d + intdiv(153 * $mm + 2, 5) + 365 * $yy + intdiv($yy, 4) - intdiv($yy, 100) + intdiv($yy, 400) - 32045;
    }

    private static function fromDayNumber(int $jdn): string
    {
        $a = $jdn + 32044;
        $b = intdiv(4 * $a + 3, 146097);
        $c = $a - intdiv(146097 * $b, 4);
        $dd = intdiv(4 * $c + 3, 1461);
        $e = $c - intdiv(1461 * $dd, 4);
        $mm = intdiv(5 * $e + 2, 153);

        $day   = $e - intdiv(153 * $mm + 2, 5) + 1;
        $month = $mm + 3 - 12 * intdiv($mm, 10);
        $year  = 100 * $b + $dd - 4800 + intdiv($mm, 10);

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /** "05-01" validated, or null. */
    private static function monthDay(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('/^(\d{2})-(\d{2})$/', $value, $match) !== 1) {
            return null;
        }

        $month = (int) $match[1];
        $day   = (int) $match[2];

        // 02-29 is valid as a season boundary even though it is not a date in
        // every year, so the day ceiling is per month rather than via checkdate.
        $lengths = [1 => 31, 29, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

        if ($month < 1 || $month > 12 || $day < 1 || $day > $lengths[$month]) {
            return null;
        }

        return $value;
    }

    /** @param array<string,mixed> $attraction */
    private static function seasonLabel(array $attraction): string
    {
        $start = self::monthDay((string) ($attraction['season_start_md'] ?? ''));
        $end   = self::monthDay((string) ($attraction['season_end_md'] ?? ''));

        if ($start === null || $end === null) {
            return '';
        }

        return self::mdLabel($start) . ' to ' . self::mdLabel($end);
    }

    private static function mdLabel(string $md): string
    {
        $months = [
            1 => 'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December',
        ];

        [$m, $d] = array_map('intval', explode('-', $md));

        return $d . ' ' . ($months[$m] ?? (string) $m);
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private static function refuse(string $date, string $reason, string $message, array $extra = []): array
    {
        return $extra + [
            'ok'      => false,
            'date'    => $date,
            'reason'  => $reason,
            'message' => $message,
            'slots'   => [],
            'taken'   => [],
        ];
    }
}
