<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Acl;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Services\AvailabilityEngine;
use App\Services\BookingRepository;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * The diary.
 *
 * Month, week and day across every booking, a resource-lane view for one day,
 * and an iCal feed a party host can subscribe to on their own phone.
 *
 * ONE DELIBERATE SIMPLIFICATION RUNS THROUGH ALL OF IT: the calendar reads a
 * booking's OWN `event_date` (DATE) and `start_time`/`end_time` (TIME), which
 * are local wall clock, rather than converting the UTC timestamps in
 * `cc_booking_resources`. The two agree — `BookingRepository::allocate()`
 * writes the booking's own window to every room it takes — and the local
 * columns are what a diary is about. A month grid built by converting 150 UTC
 * timestamps would be slower, harder to read and no more correct, and the one
 * place the conversion genuinely matters (is this room free?) already lives in
 * the repository where it is tested.
 *
 * THE ICAL FEED IS A BEARER TOKEN IN A URL, and that shapes what goes in it.
 * A calendar application cannot sign in, so the token is the whole of the
 * authentication, and such a URL ends up pasted into shared calendars and
 * phone backups. So the feed carries what a host needs to show up at the right
 * place — the time, the room, the headcount and the name on the party — and it
 * does NOT carry the allergy note, the money or the family's contact details.
 * Those stay behind a login, on the run sheet. The token is also checked
 * against the live user and role on EVERY fetch, so deactivating an account or
 * taking `view_bookings` away from a role stops the feed at once.
 */
final class CalendarController extends Controller
{
    public const VIEWS = ['month', 'week', 'day'];

    /** Sunday first, matching `operating_hours.day_of_week`. */
    public const DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    /** The name every calendar token is stored under, so one user has one. */
    public const TOKEN_NAME = 'Calendar feed';

    /** What the feed's token may do, and nothing else. */
    public const TOKEN_ABILITY = 'calendar.read';

    /** How far either side of today the feed reaches. */
    public const FEED_PAST_DAYS   = 60;
    public const FEED_FUTURE_DAYS = 365;

    /** Bookings in one feed. A venue doing 20 parties a day fits comfortably. */
    public const FEED_MAX = 2000;

    public function index(Request $request): Response
    {
        if ($refusal = $this->authorize('view_bookings')) {
            return $refusal;
        }

        $view = $request->str('view', 'month');
        $view = in_array($view, self::VIEWS, true) ? $view : 'month';

        $date = $request->str('date');
        $date = AvailabilityEngine::isDate($date) ? $date : BookingRepository::today();

        [$from, $to] = $this->window($view, $date);

        $bookings = $this->bookingsBetween($from, $to);

        return $this->view('calendar/index', [
            'pageTitle' => 'Calendar',
            'view'      => $view,
            'date'      => $date,
            'today'     => BookingRepository::today(),
            'from'      => $from,
            'to'        => $to,
            'title'     => $this->windowTitle($view, $date, $from, $to),
            'previous'  => $this->step($view, $date, -1),
            'next'      => $this->step($view, $date, 1),
            'grid'      => $view === 'month' ? $this->monthGrid($date, $bookings) : [],
            'days'      => $view === 'week' ? $this->weekDays($from, $bookings) : [],
            'lanes'     => $view === 'day' ? $this->lanes($date) : [],
            'dayHours'  => $view === 'day' ? $this->dayHours($date) : null,
            'bookings'  => $bookings,
            'showMoney' => Acl::can('view_revenue'),
            'canManage' => Acl::can('bookings_manage'),
            'feedUrl'   => $this->feedUrl(),
            'dateLabel' => static fn (string $value): string => AvailabilityEngine::isDate($value)
                ? AvailabilityEngine::dateLabel($value)
                : '—',
            'timeLabel' => static function (string $time): string {
                $minutes = AvailabilityEngine::minutes($time);

                return $minutes === null ? '' : AvailabilityEngine::timeLabel($minutes);
            },
        ]);
    }

    // =================================================================
    // The iCal feed
    // =================================================================

    /**
     * The feed itself. No session: the token in the query string is the whole
     * of the authentication, which is why it carries so little.
     */
    public function feed(Request $request): Response
    {
        $token = $request->str('token');

        // Length checked before the hash so a truncated or absent token costs
        // nothing, and compared on the HASH so the stored value is not the
        // secret. A high-entropy random token needs no slow hash: there is
        // nothing to guess offline.
        if (strlen($token) !== 64 || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return $this->refuseFeed();
        }

        $row = DB::first(
            'SELECT t.id, t.user_id, t.abilities, t.expires_at,
                    u.name AS user_name, u.is_active, u.deleted_at, u.role_id
               FROM {api_tokens} t
               JOIN {users} u ON u.id = t.user_id
              WHERE t.token_hash = :hash
              LIMIT 1',
            ['hash' => hash('sha256', $token)]
        );

        if ($row === null
            || (int) $row['is_active'] !== 1
            || $row['deleted_at'] !== null
            || ($row['expires_at'] !== null && (string) $row['expires_at'] < now_utc())
        ) {
            return $this->refuseFeed();
        }

        $abilities = json_decode((string) ($row['abilities'] ?? '[]'), true);

        if (!is_array($abilities) || !in_array(self::TOKEN_ABILITY, $abilities, true)) {
            return $this->refuseFeed();
        }

        // Re-checked on EVERY fetch, not stamped into the token: taking
        // view_bookings off a role, or deactivating the account, has to stop
        // the feed without anybody remembering to revoke anything.
        $allowed = DB::exists(
            'SELECT 1 FROM {role_permissions} rp
               JOIN {permissions} p ON p.id = rp.permission_id
              WHERE rp.role_id = :rid AND p.slug = :slug',
            ['rid' => (int) ($row['role_id'] ?? 0), 'slug' => 'view_bookings']
        );

        if (!$allowed) {
            return $this->refuseFeed();
        }

        DB::update('api_tokens', ['last_used_at' => now_utc()], 'id = :id', ['id' => (int) $row['id']]);

        $from = AvailabilityEngine::addDays(BookingRepository::today(), -self::FEED_PAST_DAYS);
        $to   = AvailabilityEngine::addDays(BookingRepository::today(), self::FEED_FUTURE_DAYS);

        return Response::text($this->ical($this->bookingsBetween($from, $to, self::FEED_MAX)))
            ->headers([
                'Content-Type'        => 'text/calendar; charset=utf-8',
                // Named so a subscriber's calendar list reads sensibly, and
                // inline so a browser shows it rather than downloading it.
                'Content-Disposition' => 'inline; filename="castle-bookings.ics"',
                // A calendar client polls this; there is no point caching a
                // diary that changes whenever somebody takes a booking.
                'Cache-Control'       => 'no-store, max-age=0',
                'X-Robots-Tag'        => 'noindex, nofollow',
            ]);
    }

    /**
     * Mint (or re-mint) this user's feed token.
     *
     * One token per user, by name: re-minting replaces the old one, which is
     * also how it is revoked and re-issued after a URL has been shared further
     * than intended.
     */
    public function createToken(Request $request): Response
    {
        if ($refusal = $this->authorize('view_bookings')) {
            return $refusal;
        }

        $userId = Auth::id();

        if ($userId === null) {
            return $this->refuse($request, 'Sign in again and try that.', '/calendar');
        }

        $token = bin2hex(random_bytes(32));

        DB::transaction(function () use ($userId, $token): void {
            DB::delete(
                'api_tokens',
                'user_id = :uid AND name = :name',
                ['uid' => $userId, 'name' => self::TOKEN_NAME]
            );

            DB::insert('api_tokens', [
                'user_id'    => $userId,
                'name'       => self::TOKEN_NAME,
                'token_hash' => hash('sha256', $token),
                'abilities'  => json_encode([self::TOKEN_ABILITY]),
                'created_at' => now_utc(),
            ]);
        });

        Audit::record('calendar.feed_token_issued', 'api_tokens', null, null, ['user_id' => $userId]);

        // Flashed rather than stored: this is the one and only time the token
        // itself exists outside the subscriber's calendar application.
        flash(
            'success',
            'Your calendar address is ' . url('/calendar/feed.ics?token=' . $token)
                . ' — treat it like a password. Anybody holding it can read the diary.'
        );

        return $this->redirect('/calendar');
    }

    public function revokeToken(Request $request): Response
    {
        if ($refusal = $this->authorize('view_bookings')) {
            return $refusal;
        }

        $userId = Auth::id();

        if ($userId === null) {
            return $this->refuse($request, 'Sign in again and try that.', '/calendar');
        }

        DB::delete(
            'api_tokens',
            'user_id = :uid AND name = :name',
            ['uid' => $userId, 'name' => self::TOKEN_NAME]
        );

        Audit::record('calendar.feed_token_revoked', 'api_tokens', null, ['user_id' => $userId], null);

        return $this->success($request, 'That calendar address no longer works.', '/calendar');
    }

    // =================================================================
    // Reading
    // =================================================================

    /**
     * Bookings between two local dates, by local date.
     *
     * `event_date` is a DATE in the venue's own day, so this needs no
     * conversion — see the class docblock. Cancelled bookings ARE included:
     * the diary is a record of what was going to happen, and a cancelled party
     * still explains why a room sat empty. The views render them struck
     * through rather than hiding them.
     *
     * @return array<string,list<array<string,mixed>>>
     */
    private function bookingsBetween(string $from, string $to, int $limit = 600): array
    {
        $rows = DB::all(
            'SELECT b.id, b.booking_number, b.type, b.status, b.event_date, b.start_time, b.end_time,
                    b.guest_count, b.guest_of_honor_name, b.allergies, b.host_user_id,
                    c.first_name, c.last_name,
                    u.name AS host_name,
                    p.name AS package_name,
                    (SELECT GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR \', \')
                       FROM {booking_resources} br
                       JOIN {resources} r ON r.id = br.resource_id
                      WHERE br.booking_id = b.id) AS rooms
               FROM {bookings} b
               LEFT JOIN {contacts} c ON c.id = b.contact_id
               LEFT JOIN {users} u ON u.id = b.host_user_id
               LEFT JOIN {party_packages} p ON p.id = b.package_id
              WHERE b.deleted_at IS NULL AND b.event_date BETWEEN :from AND :to
              ORDER BY b.event_date, b.start_time, b.id
              LIMIT ' . max(1, $limit),
            ['from' => $from, 'to' => $to]
        );

        $byDate = [];

        foreach ($rows as $row) {
            $byDate[(string) $row['event_date']][] = $row;
        }

        return $byDate;
    }

    /**
     * One day's rooms, each with what is in it.
     *
     * The room comes from `booking_resources` and the TIMES come from the
     * booking's own local columns, which is the simplification the class
     * docblock describes: allocate() writes the same window to every room, so
     * the two agree and this needs no timezone work.
     *
     * @return list<array<string,mixed>>
     */
    private function lanes(string $date): array
    {
        $rooms = DB::all(
            'SELECT id, name, type, capacity FROM {resources}
              WHERE is_active = 1 ORDER BY type, sort_order, name'
        );

        $allocated = DB::all(
            'SELECT br.resource_id, b.id, b.booking_number, b.status, b.type,
                    b.start_time, b.end_time, b.guest_count, b.guest_of_honor_name,
                    c.first_name, c.last_name
               FROM {booking_resources} br
               JOIN {bookings} b ON b.id = br.booking_id
               LEFT JOIN {contacts} c ON c.id = b.contact_id
              WHERE b.deleted_at IS NULL AND b.event_date = :date
              ORDER BY b.start_time, b.id',
            ['date' => $date]
        );

        $byRoom = [];

        foreach ($allocated as $row) {
            $byRoom[(int) $row['resource_id']][] = $row;
        }

        $lanes = [];

        foreach ($rooms as $room) {
            $lanes[] = [
                'room'     => $room,
                'bookings' => $byRoom[(int) $room['id']] ?? [],
            ];
        }

        // A LANE FOR WHAT IS IN NO ROOM.
        //
        // The booking form always allocates one now, but a booking can still
        // reach this state: a room deleted, an import, or a row written before
        // that rule existed. Without this lane such a party is on the month
        // and week views and invisible on the day view — which is the view
        // somebody runs the floor from. MEASURED: the "a booking appears in
        // each view" test failed on exactly this.
        $unassigned = DB::all(
            'SELECT b.id, b.booking_number, b.status, b.type, b.start_time, b.end_time,
                    b.guest_count, b.guest_of_honor_name,
                    c.first_name, c.last_name
               FROM {bookings} b
               LEFT JOIN {contacts} c ON c.id = b.contact_id
              WHERE b.deleted_at IS NULL AND b.event_date = :date
                AND NOT EXISTS (SELECT 1 FROM {booking_resources} br WHERE br.booking_id = b.id)
              ORDER BY b.start_time, b.id',
            ['date' => $date]
        );

        if ($unassigned !== []) {
            $lanes[] = [
                'room'     => ['id' => 0, 'name' => 'No room allocated', 'type' => 'unassigned', 'capacity' => null],
                'bookings' => $unassigned,
            ];
        }

        return $lanes;
    }

    /**
     * The hours to draw a day view across.
     *
     * The venue's own opening hours for that date, widened to cover anything
     * booked outside them — a party that somehow starts before opening must
     * still be visible, or the lane it sits in looks empty.
     *
     * @return array{opens:int,closes:int,opens_label:string,closes_label:string}|null
     */
    private function dayHours(string $date): ?array
    {
        $window = AvailabilityEngine::openWindow(
            $date,
            BookingRepository::hoursFor($date),
            BookingRepository::exceptionFor($date)
        );

        $edges = DB::first(
            'SELECT MIN(start_time) AS earliest, MAX(end_time) AS latest
               FROM {bookings}
              WHERE deleted_at IS NULL AND event_date = :date',
            ['date' => $date]
        ) ?? [];

        $earliest = AvailabilityEngine::minutes((string) ($edges['earliest'] ?? ''));
        $latest   = AvailabilityEngine::minutes((string) ($edges['latest'] ?? ''));

        if ($window === null && $earliest === null) {
            return null;
        }

        $opens  = min($window['opens'] ?? 1440, $earliest ?? 1440);
        $closes = max($window['closes'] ?? 0, $latest ?? 0);

        if ($closes <= $opens) {
            return null;
        }

        return [
            'opens'        => $opens,
            'closes'       => $closes,
            'opens_label'  => AvailabilityEngine::timeLabel($opens),
            'closes_label' => AvailabilityEngine::timeLabel($closes),
        ];
    }

    // =================================================================
    // The grid
    // =================================================================

    /**
     * The dates a view covers.
     *
     * The month grid runs from the Sunday on or before the 1st to the Saturday
     * on or after the last — six weeks at most, five usually — because that is
     * what a month looks like on a wall. Sunday first matches
     * `operating_hours.day_of_week`, so the two never disagree about which
     * column Monday is.
     *
     * @return array{0:string,1:string}
     */
    private function window(string $view, string $date): array
    {
        if ($view === 'day') {
            return [$date, $date];
        }

        if ($view === 'week') {
            $start = AvailabilityEngine::addDays($date, -AvailabilityEngine::dayOfWeek($date));

            return [$start, AvailabilityEngine::addDays($start, 6)];
        }

        $first = substr($date, 0, 8) . '01';
        $start = AvailabilityEngine::addDays($first, -AvailabilityEngine::dayOfWeek($first));

        $last = AvailabilityEngine::addDays(
            AvailabilityEngine::addDays($first, 31),
            -(int) substr(AvailabilityEngine::addDays($first, 31), 8, 2)
        );

        $end = AvailabilityEngine::addDays($last, 6 - AvailabilityEngine::dayOfWeek($last));

        return [$start, $end];
    }

    /**
     * @param array<string,list<array<string,mixed>>> $bookings
     * @return list<list<array<string,mixed>>>
     */
    private function monthGrid(string $date, array $bookings): array
    {
        [$start, $end] = $this->window('month', $date);

        $month = substr($date, 0, 7);
        $today = BookingRepository::today();
        $weeks = [];
        $week  = [];
        $day   = $start;

        while ($day <= $end) {
            $week[] = [
                'date'       => $day,
                'number'     => (int) substr($day, 8, 2),
                'in_month'   => str_starts_with($day, $month),
                'is_today'   => $day === $today,
                'is_past'    => $day < $today,
                'bookings'   => $bookings[$day] ?? [],
            ];

            if (count($week) === 7) {
                $weeks[] = $week;
                $week    = [];
            }

            $day = AvailabilityEngine::addDays($day, 1);
        }

        if ($week !== []) {
            $weeks[] = $week;
        }

        return $weeks;
    }

    /**
     * @param array<string,list<array<string,mixed>>> $bookings
     * @return list<array<string,mixed>>
     */
    private function weekDays(string $start, array $bookings): array
    {
        $today = BookingRepository::today();
        $days  = [];

        for ($offset = 0; $offset < 7; $offset++) {
            $day = AvailabilityEngine::addDays($start, $offset);

            $days[] = [
                'date'     => $day,
                'label'    => AvailabilityEngine::dateLabel($day),
                // The weekday NAME, worked out here rather than in the view:
                // a template that reaches into the service layer is a template
                // whose dependencies have to be read to be known.
                'name'     => self::DAY_NAMES[AvailabilityEngine::dayOfWeek($day)],
                'is_today' => $day === $today,
                'is_past'  => $day < $today,
                'bookings' => $bookings[$day] ?? [],
            ];
        }

        return $days;
    }

    private function step(string $view, string $date, int $direction): string
    {
        if ($view === 'day') {
            return AvailabilityEngine::addDays($date, $direction);
        }

        if ($view === 'week') {
            return AvailabilityEngine::addDays($date, 7 * $direction);
        }

        // A month at a time, landing on the 1st so a 31st never slides into
        // the following month — "31 March plus one month" has no answer, and
        // taking the obvious one gives 1 May.
        $first = substr($date, 0, 8) . '01';

        return $direction < 0
            ? substr(AvailabilityEngine::addDays($first, -1), 0, 8) . '01'
            : substr(AvailabilityEngine::addDays($first, 31), 0, 8) . '01';
    }

    private function windowTitle(string $view, string $date, string $from, string $to): string
    {
        if ($view === 'day') {
            return AvailabilityEngine::dateLabel($date);
        }

        if ($view === 'week') {
            return 'Week of ' . AvailabilityEngine::dateLabel($from);
        }

        $months = [
            1 => 'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December',
        ];

        $month = (int) substr($date, 5, 2);

        return ($months[$month] ?? '') . ' ' . substr($date, 0, 4);
    }

    // =================================================================
    // iCal
    // =================================================================

    /**
     * An RFC 5545 calendar.
     *
     * Times go out as UTC with a Z suffix rather than with a VTIMEZONE block:
     * an absolute instant needs no timezone definition to be unambiguous, and
     * hand-writing a correct VTIMEZONE (with its DST rules, for a venue that
     * may move zones) is a liability for no gain. Every subscriber converts a
     * Z time to its own local clock correctly.
     *
     * @param array<string,list<array<string,mixed>>> $byDate
     */
    private function ical(array $byDate): string
    {
        $venue = Settings::venueName();
        $host  = 'castle-crm';

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Castle CRM//Bookings//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . $this->icalText($venue . ' bookings'),
            'X-WR-TIMEZONE:' . $this->icalText(Settings::timezone()),
        ];

        $stamp = gmdate('Ymd\THis\Z');

        foreach ($byDate as $date => $bookings) {
            foreach ($bookings as $booking) {
                $start = AvailabilityEngine::minutes((string) ($booking['start_time'] ?? ''));
                $end   = AvailabilityEngine::minutes((string) ($booking['end_time'] ?? ''));

                if ($start === null) {
                    // No time means no event a calendar can place. Skipped
                    // rather than guessed at midnight, which would put a
                    // phantom party at the top of somebody's day.
                    continue;
                }

                if ($end === null || $end <= $start) {
                    $end = $start + 120;
                }

                $dtStart = $this->icalStamp((string) $date, $start);
                $dtEnd   = $this->icalStamp((string) $date, $end);

                if ($dtStart === null || $dtEnd === null) {
                    continue;
                }

                $name = trim((string) ($booking['guest_of_honor_name'] ?? ''));
                $type = ucfirst(str_replace('_', ' ', (string) $booking['type']));

                $summary = ($name === '' ? $type : $name . ' — ' . $type)
                    . ' (' . (int) $booking['guest_count'] . ')';

                // Deliberately NOT in here: the allergy note, the money, and
                // the family's contact details. See the class docblock — this
                // is a bearer token in a URL.
                $description = array_filter([
                    (string) $booking['booking_number'],
                    ($booking['package_name'] ?? null) === null ? '' : (string) $booking['package_name'],
                    ($booking['host_name'] ?? null) === null ? '' : 'Host: ' . (string) $booking['host_name'],
                    'Status: ' . str_replace('_', ' ', (string) $booking['status']),
                ], static fn (string $part): bool => $part !== '');

                $lines[] = 'BEGIN:VEVENT';
                $lines[] = 'UID:booking-' . (int) $booking['id'] . '@' . $host;
                $lines[] = 'DTSTAMP:' . $stamp;
                $lines[] = 'DTSTART:' . $dtStart;
                $lines[] = 'DTEND:' . $dtEnd;
                $lines[] = 'SUMMARY:' . $this->icalText($summary);
                $lines[] = 'DESCRIPTION:' . $this->icalText(implode("\n", $description));

                if (($booking['rooms'] ?? null) !== null && (string) $booking['rooms'] !== '') {
                    $lines[] = 'LOCATION:' . $this->icalText((string) $booking['rooms']);
                }

                // A cancelled party is published as cancelled rather than
                // dropped, so a subscriber's calendar removes it instead of
                // keeping a stale copy for ever.
                $lines[] = 'STATUS:' . (in_array((string) $booking['status'], ['cancelled', 'no_show'], true)
                    ? 'CANCELLED'
                    : 'CONFIRMED');
                $lines[] = 'END:VEVENT';
            }
        }

        $lines[] = 'END:VCALENDAR';

        // CRLF and folding are both required by the RFC, and a client that
        // rejects the file gives no reason — so neither is optional.
        return implode("\r\n", array_map([$this, 'icalFold'], $lines)) . "\r\n";
    }

    /** A local date and minute offset as an absolute UTC iCal stamp. */
    private function icalStamp(string $date, int $minutes): ?string
    {
        $utc = BookingRepository::localToUtc($date, $minutes);

        if ($utc === null) {
            return null;
        }

        try {
            return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->format('Ymd\THis\Z');
        } catch (Exception) {
            return null;
        }
    }

    /** RFC 5545 escaping: backslash, semicolon, comma and newline. */
    private function icalText(string $value): string
    {
        return str_replace(
            ['\\', "\r\n", "\n", "\r", ';', ','],
            ['\\\\', '\\n', '\\n', '\\n', '\\;', '\\,'],
            $value
        );
    }

    /**
     * Fold a content line to 75 octets, continuing with a leading space.
     *
     * Octets, not characters: the limit is on bytes, and splitting a multibyte
     * character across a fold produces a line no client can read. A guest of
     * honour called Zoë is enough to hit that.
     */
    private function icalFold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $out       = '';
        $remaining = $line;
        $limit     = 75;

        while (strlen($remaining) > $limit) {
            $cut = $limit;

            // Walk back off a continuation byte so a character is never split.
            while ($cut > 1 && (ord($remaining[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }

            $out      .= substr($remaining, 0, $cut) . "\r\n ";
            $remaining = substr($remaining, $cut);
            $limit     = 74;
        }

        return $out . $remaining;
    }

    /**
     * A 404 for every feed refusal.
     *
     * The same answer whether the token is absent, malformed, revoked, expired
     * or belongs to a deactivated account — so the URL reveals nothing about
     * which tokens exist. A calendar client shows the user an error either way.
     */
    private function refuseFeed(): Response
    {
        return Response::text("Not found.\n", 404)->headers([
            'Cache-Control' => 'no-store, max-age=0',
            'X-Robots-Tag'  => 'noindex, nofollow',
        ]);
    }

    /** The feed address, if this user has one. Never the token itself. */
    private function feedUrl(): ?string
    {
        $userId = Auth::id();

        if ($userId === null) {
            return null;
        }

        $exists = DB::exists(
            'SELECT 1 FROM {api_tokens} WHERE user_id = :uid AND name = :name',
            ['uid' => $userId, 'name' => self::TOKEN_NAME]
        );

        // The token is hashed at rest, so there is nothing to show: the page
        // can say a feed exists and offer to replace it, and that is all.
        return $exists ? url('/calendar/feed.ics') : null;
    }
}
