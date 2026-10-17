<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Event;
use App\Core\Log;
use DateTimeImmutable;
use Throwable;

/**
 * The one way a visit gets recorded.
 *
 * Admission at the Castle is free and there is no gate scan, so a "visit" is
 * DERIVED — from a party that happened, from a guest ticked in at the desk,
 * from the CenterEdge card ledger. Four surfaces will eventually want to say
 * "this family was here on this day", and if each writes its own row the venue
 * ends up with three visits for one afternoon and a lifetime value three times
 * too big.
 *
 * So the rules live here, once:
 *
 * 1. **ONE VISIT PER CONTACT PER DAY.** The schema's unique key says so; this
 *    class is what makes the second writer an UPDATE rather than an error.
 * 2. **A LOWER-PRIORITY SOURCE NEVER OVERWRITES A HIGHER ONE.** The card
 *    ledger knows what was actually spent; the check-in screen only knows
 *    somebody arrived. When the import catches up it replaces the desk's row,
 *    and nothing the desk does afterwards undoes that.
 * 3. **THE CONTACT'S OWN FIGURES ARE DERIVED, NEVER INCREMENTED.** Visit
 *    count, first and last visit, lifetime value and average spend are
 *    recomputed from the visits themselves on every write, so a corrected or
 *    deleted visit leaves the contact correct — the same reasoning as a
 *    booking's money columns being derived from its payment ledger.
 * 4. **`visit.recorded` FIRES ONCE FOR ONE VISIT.** A party with eleven guests
 *    ticked in one at a time is one visit and one event; the automation that
 *    thanks the family must not send eleven times. An upgrade of an existing
 *    visit is silent for the same reason.
 *
 * `visit.first` is fired beside it when the contact had no visit before this
 * one, which is the moment a first-timer becomes a guest the venue can try to
 * turn into a regular. It is "first as far as the venue knew at the time": a
 * later import of a decade of history cannot un-fire it, but it does correct
 * `first_visit_at`, because that column is derived.
 */
final class VisitRecorder
{
    /**
     * The sources, scored. Highest wins; see the enum in 005_visits.sql, whose
     * comment states this order as the schema's own intent.
     *
     * @var array<string,int>
     */
    public const SOURCES = [
        'pos_import'    => 60,
        'pos_event'     => 50,
        'booking'       => 40,
        'online_ticket' => 30,
        'check_in'      => 20,
        'manual'        => 10,
    ];

    /** The columns a higher-or-equal source may overwrite. */
    private const CARRIED = ['spend_cents', 'party_size', 'booking_id', 'external_ref', 'attractions'];

    /**
     * Record a visit, or fold it into the one already there.
     *
     * @param array{contact_id:int,visit_date?:?string,source?:string,spend_cents?:int,party_size?:?int,booking_id?:?int,external_ref?:?string,attractions?:mixed} $visit
     * @return array{visit_id:?int,recorded:bool,first:bool,skipped:?string}
     */
    public static function record(array $visit): array
    {
        $nothing = static fn (string $why): array => [
            'visit_id' => null, 'recorded' => false, 'first' => false, 'skipped' => $why,
        ];

        $contactId = (int) ($visit['contact_id'] ?? 0);

        if ($contactId <= 0) {
            // A visit with nobody attached cannot be deduplicated (the unique
            // key is on the contact) and cannot update anybody's figures, so
            // it is dropped rather than stored as an orphan. An anonymous
            // arrival becomes a visit when the import matches its card to a
            // contact, which is the only moment it becomes useful.
            return $nothing('no_contact');
        }

        $source = (string) ($visit['source'] ?? 'manual');

        if (!isset(self::SOURCES[$source])) {
            return $nothing('unknown_source');
        }

        $date = self::date($visit['visit_date'] ?? null);

        try {
            $contact = DB::first(
                'SELECT id, household_id FROM {contacts} WHERE id = :id AND deleted_at IS NULL',
                ['id' => $contactId]
            );

            if ($contact === null) {
                return $nothing('no_contact');
            }

            $existing = DB::first(
                'SELECT id, source FROM {visits} WHERE contact_id = :id AND visit_date = :d',
                ['id' => $contactId, 'd' => $date]
            );

            if ($existing !== null) {
                $kept = self::upgrade($existing, $visit, $source);
                self::refresh($contactId);

                return [
                    'visit_id' => (int) $existing['id'],
                    'recorded' => false,
                    'first'    => false,
                    'skipped'  => $kept ? null : 'lower_priority_source',
                ];
            }

            // Asked BEFORE the insert, and counted over the whole table rather
            // than trusting `contacts.visit_count`: that column is derived, and
            // a venue that has just imported its history has visits whose
            // counters were never refreshed.
            $first = (int) DB::value(
                'SELECT COUNT(*) FROM {visits} WHERE contact_id = :id',
                ['id' => $contactId]
            ) === 0;

            $visitId = DB::insert('visits', [
                'contact_id'   => $contactId,
                'household_id' => $visit['household_id'] ?? $contact['household_id'] ?? null,
                'visit_date'   => $date,
                'source'       => $source,
                'spend_cents'  => max(0, (int) ($visit['spend_cents'] ?? 0)),
                'party_size'   => self::partySize($visit['party_size'] ?? null),
                'booking_id'   => isset($visit['booking_id']) && (int) $visit['booking_id'] > 0
                    ? (int) $visit['booking_id']
                    : null,
                'external_ref' => self::ref($visit['external_ref'] ?? null),
                'attractions'  => self::attractions($visit['attractions'] ?? null),
                'created_at'   => now_utc(),
                'updated_at'   => now_utc(),
            ]);

            self::refresh($contactId);
        } catch (Throwable $e) {
            // A visit is a derived record. Losing one must not fail the party
            // being completed or the guest being ticked in, both of which are
            // the real work and both of which have already happened.
            Log::error('Could not record a visit', [
                'contact' => $contactId,
                'date'    => $date,
                'source'  => $source,
                'error'   => $e->getMessage(),
            ]);

            return $nothing('error');
        }

        $payload = [
            'visit_id'   => $visitId,
            'contact_id' => $contactId,
            'visit_date' => $date,
            'source'     => $source,
            'spend_cents' => max(0, (int) ($visit['spend_cents'] ?? 0)),
            'booking_id' => isset($visit['booking_id']) && (int) $visit['booking_id'] > 0
                ? (int) $visit['booking_id']
                : null,
        ];

        Event::fire('visit.recorded', $payload);

        if ($first) {
            Event::fire('visit.first', $payload);
        }

        return ['visit_id' => $visitId, 'recorded' => true, 'first' => $first, 'skipped' => null];
    }

    /**
     * Everybody who was ticked in on a booking, as one visit for the family
     * that booked it.
     *
     * The guest list is children at a party; the CONTACT is the parent who
     * booked, and they are the person the venue can email afterwards. The
     * booking's own date is used rather than today, so a party ticked in after
     * midnight on a late lock-in is still that day's visit.
     *
     * @param array<string,mixed> $booking
     * @return array{visit_id:?int,recorded:bool,first:bool,skipped:?string}
     */
    public static function fromBooking(array $booking, string $source = 'check_in'): array
    {
        $contactId = (int) ($booking['contact_id'] ?? 0);
        $bookingId = (int) ($booking['id'] ?? 0);

        if ($contactId <= 0 || $bookingId <= 0) {
            return ['visit_id' => null, 'recorded' => false, 'first' => false, 'skipped' => 'no_contact'];
        }

        $size = null;
        $spend = 0;

        try {
            if ($source === 'check_in') {
                // How many actually turned up, which is the number the run
                // sheet and the follow-up both want — not the number invited.
                $size = (int) DB::value(
                    'SELECT COUNT(*) FROM {booking_guests} WHERE booking_id = :id AND checked_in_at IS NOT NULL',
                    ['id' => $bookingId]
                );
            } else {
                $size = isset($booking['guest_count']) && (int) $booking['guest_count'] > 0
                    ? (int) $booking['guest_count']
                    : null;
            }

            // What the party actually paid, from its ledger. A deposit on a
            // party that then happened is money this family spent; the
            // booking's own total is what it was quoted.
            $spend = max(0, (int) DB::value(
                'SELECT COALESCE(SUM(amount_cents), 0) FROM {booking_payments} WHERE booking_id = :id',
                ['id' => $bookingId]
            ));
        } catch (Throwable $e) {
            Log::debug('Could not size a visit from its booking', ['booking' => $bookingId, 'error' => $e->getMessage()]);
        }

        return self::record([
            'contact_id'  => $contactId,
            'visit_date'  => self::dateOf($booking),
            'source'      => $source,
            'spend_cents' => $spend,
            'party_size'  => $size,
            'booking_id'  => $bookingId,
        ]);
    }

    /**
     * Recompute the contact's visit figures from the visits themselves.
     *
     * Public because deleting or editing a visit has to be able to put the
     * contact right, and because the import will want it after a batch.
     */
    public static function refresh(int $contactId): void
    {
        if ($contactId <= 0) {
            return;
        }

        try {
            $totals = DB::first(
                'SELECT COUNT(*) AS visits,
                        COALESCE(SUM(spend_cents), 0) AS spend,
                        MIN(visit_date) AS first_date,
                        MAX(visit_date) AS last_date
                   FROM {visits} WHERE contact_id = :id',
                ['id' => $contactId]
            );

            if ($totals === null) {
                return;
            }

            $count = (int) $totals['visits'];
            $spend = (int) $totals['spend'];

            DB::update('contacts', [
                'visit_count'          => $count,
                'lifetime_value_cents' => $spend,
                // Per visit, matching the words on the contact page
                // ("Average $x a visit"). Integer division, in cents: a
                // rounded-up average would make three $10 visits read as $10.01
                // each and the arithmetic on the page would not add up.
                'avg_spend_cents'      => $count > 0 ? intdiv($spend, $count) : 0,
                // A DATE becomes a DATETIME at midnight. The visit itself has
                // no clock time — a derived visit never did — and inventing
                // one would make "last seen" read as 3am.
                'first_visit_at'       => $totals['first_date'] === null
                    ? null
                    : (string) $totals['first_date'] . ' 00:00:00',
                'last_visit_at'        => $totals['last_date'] === null
                    ? null
                    : (string) $totals['last_date'] . ' 00:00:00',
                'updated_at'           => now_utc(),
            ], 'id = :id', ['id' => $contactId]);
        } catch (Throwable $e) {
            Log::error('Could not refresh a contact after a visit', [
                'contact' => $contactId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Fold a second writer's visit into the row already there.
     *
     * Returns whether anything was taken. An equal source MAY overwrite — the
     * check-in screen revising its own party size is the ordinary case — but a
     * weaker one may not, and a weaker one must not be able to zero the spend
     * the card ledger recorded.
     *
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $visit
     */
    private static function upgrade(array $existing, array $visit, string $source): bool
    {
        $was = self::SOURCES[(string) $existing['source']] ?? 0;

        if (self::SOURCES[$source] < $was) {
            return false;
        }

        $columns = ['source' => $source, 'updated_at' => now_utc()];

        foreach (self::CARRIED as $key) {
            if (!array_key_exists($key, $visit)) {
                continue;
            }

            $columns[$key] = match ($key) {
                'spend_cents'  => max(0, (int) $visit[$key]),
                'party_size'   => self::partySize($visit[$key]),
                'booking_id'   => (int) $visit[$key] > 0 ? (int) $visit[$key] : null,
                'external_ref' => self::ref($visit[$key]),
                default        => self::attractions($visit[$key]),
            };
        }

        DB::update('visits', $columns, 'id = :id', ['id' => (int) $existing['id']]);

        return true;
    }

    /** The venue's local date, which is the day a guest would say they came. */
    private static function date(?string $date): string
    {
        if ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}/', $date) === 1) {
            return substr($date, 0, 10);
        }

        return (new DateTimeImmutable('now', venue_tz()))->format('Y-m-d');
    }

    /** @param array<string,mixed> $booking */
    private static function dateOf(array $booking): ?string
    {
        foreach (['event_date', 'visit_date', 'start_date'] as $key) {
            $value = $booking[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private static function partySize(mixed $size): ?int
    {
        if ($size === null || $size === '') {
            return null;
        }

        // SMALLINT UNSIGNED. A party of 70,000 is a typo, and letting it
        // through is a database error on a path that must not fail.
        return max(0, min(65535, (int) $size));
    }

    private static function ref(mixed $ref): ?string
    {
        $ref = trim((string) ($ref ?? ''));

        return $ref === '' ? null : mb_substr($ref, 0, 64);
    }

    private static function attractions(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? null : $json;
    }
}
