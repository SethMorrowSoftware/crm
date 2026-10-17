<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Settings;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * The reading and writing half of availability.
 *
 * `AvailabilityEngine` decides; this fetches what it needs and stores what
 * comes out. Keeping them apart is deliberate — every awkward rule lives in
 * the pure half where a test can reach it, and every awkward conversion lives
 * here where a real DateTimeZone can do it properly. Nothing in this file
 * decides whether a party can be hosted, and nothing in that one knows a
 * database exists.
 *
 * TIMES ARE STORED UTC AND REASONED ABOUT LOCALLY, and this class is the only
 * place both are true at once.
 *
 *   - `cc_bookings.event_date` (DATE) and `start_time`/`end_time` (TIME) are
 *     local wall clock, because that is what a party IS: "two o'clock on the
 *     14th" means two o'clock whatever the offset is that week. A zoneless
 *     DATE + TIME is the right shape for it.
 *   - `cc_booking_resources.start_time`/`end_time` (DATETIME) are UTC, like
 *     every other DATETIME in this application. A reader who assumes UTC of a
 *     DATETIME column here is right, which is worth more than the convenience
 *     of storing local and having one column in the schema behave differently
 *     from all the others.
 *
 * So the conversion happens exactly twice — `allocationsFor()` turns UTC rows
 * into the local minute offsets the engine works in, and `allocate()` turns a
 * local date and minute offset back into UTC. Both go through
 * `localToUtc()`/`utcToLocal()` rather than being written out by hand at a
 * call site, because the DST cases are where this gets quietly wrong: a local
 * time that does not exist (the spring-forward hour) and one that happens
 * twice (the autumn one). PHP resolves both by rule rather than throwing, and
 * the rule is documented on those methods.
 */
final class BookingRepository
{
    /** Thousandths of one percent — see QuoteCalculator's class docblock. */
    public const TAX_RATE_SETTING = 'bookings.tax_rate';

    /**
     * Orange County NY at the time of writing: 4% state + 3.75% county +
     * 0.375% MCTD. A SEEDED DEFAULT THE VENUE HAS TO CONFIRM, exactly like
     * the party prices — it came from a published rate table, not from the
     * venue's accountant, and whether a given party is taxable at all is not
     * a question this application can answer. Every booking shows the rate it
     * used so the figure is never anonymous.
     */
    public const DEFAULT_TAX_RATE = 8125;

    public const BUFFER_SETTING = 'bookings.buffer_minutes';
    public const STEP_SETTING   = 'bookings.slot_step_minutes';

    /** The public booking page is OFF until the venue turns it on. */
    public const PUBLIC_SETTING = 'bookings.public_enabled';

    /** How far ahead the public page will look for an alternative date. */
    public const ALTERNATIVE_DAYS = 28;

    /** Resource types a party package draws its room from, by attraction type. */
    public const ROOM_TYPE = 'party_room';

    // =================================================================
    // Settings
    // =================================================================

    public static function taxRate(): int
    {
        $stored = Settings::get(self::TAX_RATE_SETTING);

        if ($stored === null || $stored === '') {
            return self::DEFAULT_TAX_RATE;
        }

        return max(0, min(QuoteCalculator::MAX_RATE, (int) $stored));
    }

    public static function bufferMinutes(): int
    {
        $stored = Settings::get(self::BUFFER_SETTING);

        return $stored === null || $stored === ''
            ? AvailabilityEngine::DEFAULT_BUFFER_MINUTES
            : max(0, min(240, (int) $stored));
    }

    public static function stepMinutes(): int
    {
        $stored = Settings::get(self::STEP_SETTING);

        return $stored === null || $stored === ''
            ? AvailabilityEngine::DEFAULT_STEP_MINUTES
            : max(5, min(240, (int) $stored));
    }

    public static function publicEnabled(): bool
    {
        return (bool) Settings::get(self::PUBLIC_SETTING, false);
    }

    /**
     * Today, in the venue's own day — not the server's.
     *
     * The server runs UTC. Between midnight and 5am UTC the venue is still on
     * the previous date, and a notice rule counted against the wrong one turns
     * away a booking that should be allowed — or accepts one the kitchen
     * cannot staff. `$now` is injectable for exactly that reason: a test that
     * can only run the real clock can only check this for nineteen hours a
     * day, which is how a bug like it survives.
     */
    public static function today(?DateTimeImmutable $now = null): string
    {
        return ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(venue_tz())
            ->format('Y-m-d');
    }

    // =================================================================
    // Reading what the engine needs
    // =================================================================

    /**
     * Everything `AvailabilityEngine::day()` needs for one date and package.
     *
     * One method rather than a scatter of lookups at the call sites, so the
     * calendar, the booking form and the public page all ask the same question
     * and can never disagree about the answer.
     *
     * @param array<string,mixed>|null $package A row from cc_party_packages.
     * @return array<string,mixed>
     */
    public static function inputFor(
        string $date,
        ?array $package,
        int $guestCount,
        ?int $excludeBookingId = null,
        ?string $resourceType = null
    ): array {
        $attractionId = $package === null ? null : (
            isset($package['main_attraction_id']) && $package['main_attraction_id'] !== null
                ? (int) $package['main_attraction_id']
                : null
        );

        $attraction = $attractionId === null ? null : self::attraction($attractionId);
        $override   = $attractionId === null ? null : self::attractionOverride($attractionId, $date);

        // The room comes from the attraction when it names one — the laser
        // arena for a laser party — and from the party rooms otherwise.
        $type = $resourceType ?? self::ROOM_TYPE;

        return [
            'date'              => $date,
            'today'             => self::today(),
            'duration_minutes'  => (int) ($package['duration_minutes'] ?? 0),
            'min_notice_days'   => (int) ($package['min_notice_days'] ?? 0),
            'buffer_minutes'    => self::bufferMinutes(),
            'step_minutes'      => self::stepMinutes(),
            'guest_count'       => $guestCount,
            'hours'             => self::hoursFor($date),
            'exception'         => self::exceptionFor($date),
            'attraction'        => $attraction,
            'attraction_closed' => $override !== null && (int) $override['is_available'] === 0,
            'attraction_note'   => (string) ($override['note'] ?? ''),
            'resources'         => self::resourcesOfType($type),
            'allocations'       => self::allocationsFor($date),
            'exclude_booking_id' => $excludeBookingId,
        ];
    }

    /**
     * The weekly rows that could apply to this date.
     *
     * Every row for the weekday is returned, dated and undated alike, and the
     * engine picks — because which one wins is a rule, and rules live there.
     *
     * @return list<array<string,mixed>>
     */
    public static function hoursFor(string $date): array
    {
        if (!AvailabilityEngine::isDate($date)) {
            return [];
        }

        return DB::all(
            'SELECT day_of_week, opens_at, closes_at, season_label, effective_from, effective_to
               FROM {operating_hours}
              WHERE day_of_week = :dow
              ORDER BY id',
            ['dow' => AvailabilityEngine::dayOfWeek($date)]
        );
    }

    /** @return array<string,mixed>|null */
    public static function exceptionFor(string $date): ?array
    {
        if (!AvailabilityEngine::isDate($date)) {
            return null;
        }

        return DB::first(
            'SELECT date, is_closed, opens_at, closes_at, note
               FROM {operating_exceptions} WHERE date = :date LIMIT 1',
            ['date' => $date]
        );
    }

    /** @return array<string,mixed>|null */
    public static function attraction(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        return DB::first(
            'SELECT id, name, slug, is_seasonal, season_start_md, season_end_md,
                    capacity_per_slot, requires_waiver, is_active
               FROM {attractions} WHERE id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    /** @return array<string,mixed>|null */
    public static function attractionOverride(int $attractionId, string $date): ?array
    {
        if ($attractionId <= 0 || !AvailabilityEngine::isDate($date)) {
            return null;
        }

        return DB::first(
            'SELECT is_available, note FROM {attraction_availability}
              WHERE attraction_id = :id AND date = :date LIMIT 1',
            ['id' => $attractionId, 'date' => $date]
        );
    }

    /** @return list<array<string,mixed>> */
    /**
     * Which kind of resource holds a party on this package.
     *
     * The attraction's own resource when it has one — the laser arena for a
     * laser party, the rink for a skating party — and a party room otherwise.
     * That is what makes two laser parties at three o'clock impossible: there
     * is one arena, and the engine places a party in one resource.
     *
     * UNLESS NONE OF THEM COULD HOLD THE PARTY, which is not a hypothetical.
     * MEASURED on the seeded venue: the Bowling Birthday package points at the
     * bowling attraction, whose resources are TWENTY-FOUR LANES OF SIX. The
     * engine needs one resource with room for the whole party, so every
     * bowling enquiry — including one for the package's own default of eight
     * guests — was refused with "No room of that type holds 8 guests. The
     * largest holds 6", on every date, with no alternative to offer. The
     * package was unbookable and nothing said why.
     *
     * A ten-guest bowling party is really held in a party room and plays on
     * however many lanes it needs; the lanes are an operational detail the
     * office assigns, not the thing the diary contends over. So when a single
     * resource of the attraction's type cannot physically take the party, the
     * party room is the honest answer.
     *
     * Capacity ONLY — never availability. Falling back because the arena is
     * booked would quietly put a second laser party in a party room at the
     * same three o'clock, which is the double-booking this whole model exists
     * to prevent. A capacity is a static fact about the building; a collision
     * is a real "fully booked" and must read as one.
     *
     * $guestCount 0 means "do not weigh capacity" — for a page asking which
     * type a package uses in the abstract, before anyone has said how many
     * are coming.
     *
     * @param array<string,mixed>|null $package
     */
    public static function resourceTypeFor(?array $package, int $guestCount = 0): string
    {
        $attractionId = $package === null ? 0 : (int) ($package['main_attraction_id'] ?? 0);

        if ($attractionId <= 0) {
            return self::ROOM_TYPE;
        }

        $type = DB::value(
            'SELECT type FROM {resources}
              WHERE attraction_id = :id AND is_active = 1 AND type <> :room
              ORDER BY id LIMIT 1',
            ['id' => $attractionId, 'room' => self::ROOM_TYPE]
        );

        if (!is_string($type) || $type === '') {
            return self::ROOM_TYPE;
        }

        if ($guestCount > 0 && self::largestOfType($type) < $guestCount) {
            return self::ROOM_TYPE;
        }

        return $type;
    }

    /**
     * The biggest single resource of a type, or PHP_INT_MAX if any has no
     * capacity recorded — an unrecorded capacity means "unknown", and the
     * engine already treats it as "no limit", so this has to agree with it or
     * the fallback above fires on a resource the engine would have accepted.
     */
    public static function largestOfType(string $type): int
    {
        // COUNT(capacity) skips nulls where COUNT(*) does not, so the two
        // together say how many have none recorded — one pass, and standard
        // SQL rather than MySQL's boolean-as-integer.
        $row = DB::first(
            'SELECT COUNT(*) - COUNT(capacity) AS unknown, COALESCE(MAX(capacity), 0) AS largest
               FROM {resources} WHERE type = :type AND is_active = 1',
            ['type' => $type]
        );

        if ($row === null) {
            return 0;
        }

        return (int) $row['unknown'] > 0 ? PHP_INT_MAX : (int) $row['largest'];
    }

    public static function resourcesOfType(string $type): array
    {
        return DB::all(
            'SELECT id, name, type, capacity, attraction_id, is_active
               FROM {resources}
              WHERE type = :type AND is_active = 1
              ORDER BY sort_order, name',
            ['type' => $type]
        );
    }

    /**
     * What is already allocated on this LOCAL date, as minute offsets.
     *
     * The bounds are built by converting the local day's own midnights to UTC,
     * so the query is an indexed range over the stored column rather than a
     * function over it — and so a party that starts at 11pm local in winter is
     * found on the date it is actually on rather than the UTC one.
     *
     * A cancelled booking holds nothing: a room freed at four o'clock on
     * Friday has to be bookable at five, which is the single most common
     * reason a venue goes looking for availability in the first place.
     *
     * @return list<array{resource_id:int,booking_id:int,start:int,end:int,booking_number:string,title:string}>
     */
    public static function allocationsFor(string $date): array
    {
        if (!AvailabilityEngine::isDate($date)) {
            return [];
        }

        // One day either side, because a UTC window for a local day reaches
        // into both neighbours and an allocation is matched on its local date
        // below rather than by the SQL.
        $from = AvailabilityEngine::addDays($date, -1) . ' 00:00:00';
        $to   = AvailabilityEngine::addDays($date, 2) . ' 00:00:00';

        $rows = DB::all(
            'SELECT br.resource_id, br.booking_id, br.start_time, br.end_time,
                    b.booking_number, b.guest_of_honor_name, b.type, b.status
               FROM {booking_resources} br
               JOIN {bookings} b ON b.id = br.booking_id
              WHERE br.start_time < :to AND br.end_time > :from
                AND b.deleted_at IS NULL
                AND b.status NOT IN (\'cancelled\', \'no_show\')
              ORDER BY br.start_time',
            ['from' => $from, 'to' => $to]
        );

        $out = [];

        foreach ($rows as $row) {
            $start = self::utcToLocal((string) $row['start_time']);
            $end   = self::utcToLocal((string) $row['end_time']);

            if ($start === null || $end === null) {
                continue;
            }

            // Only the part of the allocation that lands on this local date.
            // A party cannot currently cross midnight (the venue shuts at 11),
            // but clamping rather than skipping means that if one ever does,
            // the hours it occupies on each date still block that date.
            $startMinutes = $start['date'] === $date
                ? $start['minutes']
                : ($start['date'] < $date ? 0 : null);

            $endMinutes = $end['date'] === $date
                ? $end['minutes']
                : ($end['date'] > $date ? 1440 : null);

            if ($startMinutes === null || $endMinutes === null || $endMinutes <= $startMinutes) {
                continue;
            }

            $out[] = [
                'resource_id'    => (int) $row['resource_id'],
                'booking_id'     => (int) $row['booking_id'],
                'start'          => $startMinutes,
                'end'            => $endMinutes,
                'booking_number' => (string) $row['booking_number'],
                'title'          => self::allocationTitle($row),
            ];
        }

        return $out;
    }

    /**
     * Every allocation between two local dates, grouped by local date.
     *
     * The calendar's month view needs a month in one query rather than thirty
     * — this is that query. Same clamping as allocationsFor(), which it shares
     * through dayRows().
     *
     * @return array<string,list<array<string,mixed>>>
     */
    public static function allocationsBetween(string $from, string $to): array
    {
        if (!AvailabilityEngine::isDate($from) || !AvailabilityEngine::isDate($to)) {
            return [];
        }

        $rows = DB::all(
            'SELECT br.resource_id, br.booking_id, br.start_time, br.end_time,
                    r.name AS resource_name, r.type AS resource_type,
                    b.booking_number, b.guest_of_honor_name, b.type, b.status
               FROM {booking_resources} br
               JOIN {bookings} b ON b.id = br.booking_id
               LEFT JOIN {resources} r ON r.id = br.resource_id
              WHERE br.start_time < :to AND br.end_time > :from
                AND b.deleted_at IS NULL
                AND b.status NOT IN (\'cancelled\', \'no_show\')
              ORDER BY br.start_time',
            [
                'from' => AvailabilityEngine::addDays($from, -1) . ' 00:00:00',
                'to'   => AvailabilityEngine::addDays($to, 2) . ' 00:00:00',
            ]
        );

        $byDate = [];

        foreach ($rows as $row) {
            $start = self::utcToLocal((string) $row['start_time']);
            $end   = self::utcToLocal((string) $row['end_time']);

            if ($start === null || $end === null) {
                continue;
            }

            if ($start['date'] < $from || $start['date'] > $to) {
                continue;
            }

            $byDate[$start['date']][] = [
                'resource_id'   => (int) $row['resource_id'],
                'resource_name' => (string) ($row['resource_name'] ?? ''),
                'resource_type' => (string) ($row['resource_type'] ?? ''),
                'booking_id'    => (int) $row['booking_id'],
                'booking_number' => (string) $row['booking_number'],
                'start'         => $start['minutes'],
                'end'           => $end['date'] === $start['date'] ? $end['minutes'] : 1440,
                'start_label'   => AvailabilityEngine::timeLabel($start['minutes']),
                'end_label'     => AvailabilityEngine::timeLabel($end['minutes']),
                'title'         => self::allocationTitle($row),
                'status'        => (string) $row['status'],
            ];
        }

        return $byDate;
    }

    // =================================================================
    // Writing
    // =================================================================

    /**
     * Replace a booking's resource allocations.
     *
     * Delete-then-insert rather than a diff: the set is small, the write is
     * inside the caller's transaction, and a diff would have to decide what
     * "the same allocation" means across a reschedule — which is exactly the
     * kind of cleverness that leaves a room double-booked.
     *
     * @param list<int> $resourceIds
     */
    public static function allocate(
        int $bookingId,
        string $date,
        int $startMinutes,
        int $endMinutes,
        array $resourceIds
    ): void {
        DB::delete('booking_resources', 'booking_id = :id', ['id' => $bookingId]);

        if ($resourceIds === [] || $endMinutes <= $startMinutes || !AvailabilityEngine::isDate($date)) {
            return;
        }

        $start = self::localToUtc($date, $startMinutes);
        $end   = self::localToUtc($date, $endMinutes);

        if ($start === null || $end === null) {
            return;
        }

        foreach (array_unique(array_map('intval', $resourceIds)) as $resourceId) {
            if ($resourceId <= 0) {
                continue;
            }

            // No created_at: this table does not have one. It is a ledger of
            // where a party is, rewritten whenever the party moves, and the
            // audit log is what records when that happened.
            DB::insert('booking_resources', [
                'booking_id'  => $bookingId,
                'resource_id' => $resourceId,
                'start_time'  => $start,
                'end_time'    => $end,
            ]);
        }
    }

    /**
     * Is this booking's requested slot still free?
     *
     * Called immediately before the write, inside the same transaction, so
     * two people confirming the last room in the same second cannot both
     * succeed. The engine answers; this only supplies the rows.
     *
     * @param array<string,mixed>|null $package
     * @return array<string,mixed>
     */
    public static function check(
        string $date,
        int $startMinutes,
        ?array $package,
        int $guestCount,
        ?int $excludeBookingId = null,
        ?string $resourceType = null
    ): array {
        return AvailabilityEngine::at(
            self::inputFor($date, $package, $guestCount, $excludeBookingId, $resourceType),
            $startMinutes
        );
    }

    /**
     * Dates near the requested one that WOULD work.
     *
     * What turns the public form's "no" into something useful. Bounded by
     * ALTERNATIVE_DAYS and by a count, because this runs one availability
     * pass per date and a guest asking for a Saturday in January should not
     * cost a year of them.
     *
     * @param array<string,mixed>|null $package
     * @return list<array{date:string,label:string,slots:int,first:string}>
     */
    public static function alternatives(
        string $date,
        ?array $package,
        int $guestCount,
        int $limit = 3,
        ?string $resourceType = null
    ): array {
        if (!AvailabilityEngine::isDate($date) || $limit < 1) {
            return [];
        }

        $found = [];

        for ($offset = 1; $offset <= self::ALTERNATIVE_DAYS && count($found) < $limit; $offset++) {
            $candidate = AvailabilityEngine::addDays($date, $offset);

            $result = AvailabilityEngine::day(
                self::inputFor($candidate, $package, $guestCount, null, $resourceType)
            );

            if (($result['ok'] ?? false) !== true || $result['slots'] === []) {
                continue;
            }

            $found[] = [
                'date'  => $candidate,
                'label' => AvailabilityEngine::dateLabel($candidate),
                'slots' => count($result['slots']),
                'first' => (string) $result['slots'][0]['start_label'],
            ];
        }

        return $found;
    }

    // =================================================================
    // The two conversions, in one place each
    // =================================================================

    /**
     * A local date and minute offset as a UTC timestamp.
     *
     * DST, which is the whole reason this is a method and not an expression:
     *
     *   - SPRING FORWARD. 2026-03-08 02:30 does not exist in New York. PHP
     *     resolves it forward to 03:30 EDT rather than throwing. That is the
     *     right behaviour for a booking — the party happens at the next real
     *     moment — and it is also moot in practice, because the venue opens at
     *     11am. It is documented because a reader should not have to find out
     *     by experiment.
     *   - FALL BACK. 2026-11-01 01:30 happens twice. PHP takes the FIRST
     *     (still daylight time). Consistently taking one of them is what
     *     matters: the alternative is an allocation that reads as a different
     *     hour each time it is loaded.
     */
    public static function localToUtc(string $date, int $minutes): ?string
    {
        if (!AvailabilityEngine::isDate($date)) {
            return null;
        }

        // Normalise past midnight by date arithmetic rather than by handing
        // "25:00" to a parser, so a cross-midnight end time lands on the
        // right day.
        $date    = AvailabilityEngine::addDays($date, intdiv($minutes, 1440));
        $minutes %= 1440;

        try {
            return (new DateTimeImmutable(
                $date . ' ' . AvailabilityEngine::timeValue($minutes),
                venue_tz()
            ))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (Exception) {
            return null;
        }
    }

    /**
     * A stored UTC timestamp as a local date and minute offset.
     *
     * @return array{date:string,minutes:int}|null
     */
    public static function utcToLocal(string $utc): ?array
    {
        if ($utc === '' || str_starts_with($utc, '0000')) {
            return null;
        }

        try {
            $local = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(venue_tz());
        } catch (Exception) {
            return null;
        }

        return [
            'date'    => $local->format('Y-m-d'),
            'minutes' => ((int) $local->format('G')) * 60 + (int) $local->format('i'),
        ];
    }

    /** @param array<string,mixed> $row */
    private static function allocationTitle(array $row): string
    {
        $honoree = trim((string) ($row['guest_of_honor_name'] ?? ''));

        if ($honoree !== '') {
            return $honoree;
        }

        $type = (string) ($row['type'] ?? '');

        return $type === '' ? (string) ($row['booking_number'] ?? 'Booking') : ucfirst(str_replace('_', ' ', $type));
    }
}
