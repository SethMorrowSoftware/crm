<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Acl;
use App\Core\Audit;
use App\Core\DB;
use App\Core\Event;
use App\Core\Request;
use App\Core\Response;
use App\Services\AvailabilityEngine;
use App\Services\BookingRepository;

/**
 * The desk on the day.
 *
 * This is the only page in the application somebody uses while a queue of
 * parents is forming, so it is written for that: today's parties, biggest
 * first problem at the top, one tap per guest, and no figures anywhere. It
 * gates on `checkin` rather than `bookings_manage`, because checking a child
 * in is what the front desk does and re-pricing a party is not.
 *
 * TWO THINGS IT REFUSES TO GUESS.
 *
 * It does not mark a guest as waiver-covered on a name that nearly matches.
 * A wrong tick at a check-in desk is worse than no tick: somebody walks past
 * a waiver nobody signed, and the venue believes it has one. Linking happens
 * on an exact name match when the waiver is signed (see WaiverController) and
 * by hand here otherwise.
 *
 * It does not invent a headcount. The booking says 14 guests; the list may
 * hold eight names because that is all the family sent. The page shows both
 * numbers and never reconciles them, because the difference is real
 * information — six children arriving unlisted is a thing the host needs to
 * know, not an error to smooth over.
 */
final class CheckinController extends Controller
{
    /** Statuses a party can be checked in against. */
    public const ARRIVABLE = ['confirmed', 'deposit_paid', 'paid', 'completed'];

    public function index(Request $request): Response
    {
        if ($refusal = $this->authorize('checkin')) {
            return $refusal;
        }

        $date = $request->str('date');
        $date = AvailabilityEngine::isDate($date) ? $date : BookingRepository::today();

        $bookings = DB::all(
            'SELECT b.id, b.booking_number, b.type, b.status, b.event_date, b.start_time, b.end_time,
                    b.guest_count, b.guest_of_honor_name, b.allergies, b.host_user_id,
                    c.first_name, c.last_name, c.phone,
                    u.name AS host_name,
                    (SELECT COUNT(*) FROM {booking_guests} g WHERE g.booking_id = b.id) AS listed,
                    (SELECT COUNT(*) FROM {booking_guests} g
                      WHERE g.booking_id = b.id AND g.checked_in_at IS NOT NULL) AS arrived,
                    (SELECT COUNT(*) FROM {booking_guests} g
                      WHERE g.booking_id = b.id AND g.waiver_id IS NOT NULL) AS waivered,
                    (SELECT GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR \', \')
                       FROM {booking_resources} br
                       JOIN {resources} r ON r.id = br.resource_id
                      WHERE br.booking_id = b.id) AS rooms
               FROM {bookings} b
               LEFT JOIN {contacts} c ON c.id = b.contact_id
               LEFT JOIN {users} u ON u.id = b.host_user_id
              WHERE b.deleted_at IS NULL AND b.event_date = :date
                AND b.status NOT IN (\'cancelled\', \'no_show\')
              ORDER BY b.start_time, b.id',
            ['date' => $date]
        );

        return $this->view('checkin/index', [
            'pageTitle' => 'Check-in',
            'date'      => $date,
            'today'     => BookingRepository::today(),
            'previous'  => AvailabilityEngine::addDays($date, -1),
            'next'      => AvailabilityEngine::addDays($date, 1),
            'label'     => AvailabilityEngine::dateLabel($date),
            'bookings'  => $bookings,
            'waiverUrl' => url('/waiver'),
            'hasWaiver' => DB::exists('SELECT 1 FROM {waivers} WHERE is_active = 1'),
            'timeLabel' => static function (string $time): string {
                $minutes = AvailabilityEngine::minutes($time);

                return $minutes === null ? '' : AvailabilityEngine::timeLabel($minutes);
            },
        ]);
    }

    public function show(Request $request): Response
    {
        if ($refusal = $this->authorize('checkin')) {
            return $refusal;
        }

        $booking = $this->find($request->routeInt('id'));

        if ($booking === null) {
            return $this->notFound('That booking does not exist, or it was deleted.');
        }

        $id = (int) $booking['id'];

        return $this->view('checkin/booking', [
            'pageTitle' => 'Check-in ' . $booking['booking_number'],
            'booking'   => $booking,
            'guests'    => DB::all(
                'SELECT g.id, g.name, g.age, g.parent_name, g.parent_phone, g.rsvp_status,
                        g.checked_in_at, g.waiver_id,
                        s.signer_name, s.signed_at, s.expires_at, s.is_guardian
                   FROM {booking_guests} g
                   LEFT JOIN {waiver_signatures} s ON s.id = g.waiver_id
                  WHERE g.booking_id = :id
                  ORDER BY g.checked_in_at IS NOT NULL, g.name
                  LIMIT 400',
                ['id' => $id]
            ),
            // Signatures on the party that are not tied to a named guest yet,
            // so the desk can match one by hand rather than asking a parent to
            // sign again.
            'loose'     => DB::all(
                'SELECT s.id, s.signer_name, s.signed_at, s.expires_at, s.is_guardian, s.minors
                   FROM {waiver_signatures} s
                  WHERE s.booking_id = :id
                    AND NOT EXISTS (SELECT 1 FROM {booking_guests} g WHERE g.waiver_id = s.id)
                  ORDER BY s.signed_at DESC
                  LIMIT 100',
                ['id' => $id]
            ),
            'rooms'     => DB::all(
                'SELECT r.name FROM {booking_resources} br
                   JOIN {resources} r ON r.id = br.resource_id
                  WHERE br.booking_id = :id ORDER BY r.name',
                ['id' => $id]
            ),
            'needsWaiver' => DB::all(
                'SELECT a.name FROM {booking_attractions} ba
                   JOIN {attractions} a ON a.id = ba.attraction_id
                  WHERE ba.booking_id = :id AND a.requires_waiver = 1',
                ['id' => $id]
            ),
            'waiverUrl' => url('/waiver?booking=' . urlencode((string) $booking['booking_number'])),
            'hasWaiver' => DB::exists('SELECT 1 FROM {waivers} WHERE is_active = 1'),
            'canManage' => Acl::can('bookings_manage'),
            'arrivable' => in_array((string) $booking['status'], self::ARRIVABLE, true),
            'today'     => BookingRepository::today(),
            'timeLabel' => static function (string $time): string {
                $minutes = AvailabilityEngine::minutes($time);

                return $minutes === null ? '' : AvailabilityEngine::timeLabel($minutes);
            },
        ]);
    }

    /** One guest arriving, or a mis-tap being undone. */
    public function toggleGuest(Request $request): Response
    {
        if ($refusal = $this->authorize('checkin')) {
            return $refusal;
        }

        $id      = $request->routeInt('id');
        $booking = $this->find($id);

        if ($booking === null) {
            return $this->notFound('That booking does not exist, or it was deleted.');
        }

        $guestId = $request->int('guest_id', 0);

        $guest = DB::first(
            'SELECT id, name, checked_in_at FROM {booking_guests} WHERE id = :gid AND booking_id = :bid',
            ['gid' => $guestId, 'bid' => $id]
        );

        if ($guest === null) {
            return $this->refuse($request, 'That guest is not on this booking.', '/checkin/' . $id);
        }

        $arriving = $guest['checked_in_at'] === null;

        DB::update(
            'booking_guests',
            ['checked_in_at' => $arriving ? now_utc() : null],
            'id = :gid',
            ['gid' => $guestId]
        );

        if ($arriving) {
            Event::fire('guest.checked_in', [
                'booking_id' => $id,
                'guest_id'   => $guestId,
            ]);
        }

        return $this->success(
            $request,
            $arriving
                ? (string) $guest['name'] . ' is in.'
                : (string) $guest['name'] . ' is back off the list.',
            '/checkin/' . $id
        );
    }

    /**
     * Everybody at once, for a coach party.
     *
     * Only ever marks people AS ARRIVED — never the reverse. A mis-tap on a
     * button that unchecks forty guests is not recoverable from memory, and
     * the individual toggles already undo one at a time.
     */
    public function arriveAll(Request $request): Response
    {
        if ($refusal = $this->authorize('checkin')) {
            return $refusal;
        }

        $id      = $request->routeInt('id');
        $booking = $this->find($id);

        if ($booking === null) {
            return $this->notFound('That booking does not exist, or it was deleted.');
        }

        $changed = DB::query(
            'UPDATE {booking_guests} SET checked_in_at = :now
              WHERE booking_id = :id AND checked_in_at IS NULL',
            ['now' => now_utc(), 'id' => $id]
        )->rowCount();

        if ($changed === 0) {
            return $this->refuse($request, 'Everybody on that list is already in.', '/checkin/' . $id);
        }

        Audit::record('booking.all_arrived', 'bookings', $id, null, ['guests' => $changed]);

        return $this->success(
            $request,
            $changed . ' guest' . ($changed === 1 ? '' : 's') . ' marked as arrived.',
            '/checkin/' . $id
        );
    }

    /**
     * Tie a signature to a guest by hand.
     *
     * The exact-name link at signing time catches most of them; this is for
     * "Mum signed as Elizabeth and the list says Beth", which no matching
     * rule should ever decide on its own.
     */
    public function linkWaiver(Request $request): Response
    {
        if ($refusal = $this->authorize('checkin')) {
            return $refusal;
        }

        $id      = $request->routeInt('id');
        $booking = $this->find($id);

        if ($booking === null) {
            return $this->notFound('That booking does not exist, or it was deleted.');
        }

        $guestId     = $request->int('guest_id', 0);
        $signatureId = $request->int('signature_id', 0);

        $guest = DB::first(
            'SELECT id, name FROM {booking_guests} WHERE id = :gid AND booking_id = :bid',
            ['gid' => $guestId, 'bid' => $id]
        );

        if ($guest === null) {
            return $this->refuse($request, 'That guest is not on this booking.', '/checkin/' . $id);
        }

        // Unlinking is a legitimate correction, so a zero clears it.
        if ($signatureId === 0) {
            DB::update('booking_guests', ['waiver_id' => null], 'id = :gid', ['gid' => $guestId]);

            return $this->success($request, 'Waiver unlinked from ' . $guest['name'] . '.', '/checkin/' . $id);
        }

        // The signature has to be on THIS booking. Otherwise a crafted id
        // would let one party's waiver cover another's guest, which is exactly
        // the wrong tick this module refuses to make by guessing.
        $signature = DB::first(
            'SELECT id, signer_name FROM {waiver_signatures} WHERE id = :sid AND booking_id = :bid',
            ['sid' => $signatureId, 'bid' => $id]
        );

        if ($signature === null) {
            return $this->refuse(
                $request,
                'That signature is not on this booking. A waiver signed for another party does '
                    . 'not cover this one.',
                '/checkin/' . $id
            );
        }

        DB::update('booking_guests', ['waiver_id' => $signatureId], 'id = :gid', ['gid' => $guestId]);

        Audit::record('booking.waiver_linked', 'bookings', $id, null, [
            'guest'     => (string) $guest['name'],
            'signature' => $signatureId,
        ]);

        return $this->success(
            $request,
            (string) $signature['signer_name'] . '\'s waiver now covers ' . $guest['name'] . '.',
            '/checkin/' . $id
        );
    }

    /**
     * Add a guest who turned up unlisted.
     *
     * The common case at a children's party, and the reason this is not
     * `bookings_manage`: the person adding the name is the person at the desk
     * with the child in front of them.
     */
    public function storeGuest(Request $request): Response
    {
        if ($refusal = $this->authorize('checkin')) {
            return $refusal;
        }

        $id      = $request->routeInt('id');
        $booking = $this->find($id);

        if ($booking === null) {
            return $this->notFound('That booking does not exist, or it was deleted.');
        }

        $data = $this->validate($request, [
            'name'         => 'required|string|min:1|max:120',
            'age'          => 'nullable|int|min:0|max:120',
            'parent_name'  => 'nullable|string|max:120',
            'parent_phone' => 'nullable|string|max:32',
        ], ['name' => 'Name']);

        if ($data instanceof Response) {
            return $data;
        }

        $listed = (int) DB::value(
            'SELECT COUNT(*) FROM {booking_guests} WHERE booking_id = :id',
            ['id' => $id]
        );

        if ($listed >= BookingController::MAX_GUESTS_LISTED) {
            return $this->refuse(
                $request,
                'That guest list already holds ' . BookingController::MAX_GUESTS_LISTED . ' names.',
                '/checkin/' . $id
            );
        }

        $guestId = DB::insert('booking_guests', [
            'booking_id'   => $id,
            'name'         => $data['name'],
            'age'          => $data['age'] ?? null,
            'parent_name'  => $data['parent_name'] ?? null,
            'parent_phone' => normalize_phone($data['parent_phone'] ?? null),
            // Added AT the desk, so they are here: ticking them in separately
            // would be a second tap for no information.
            'rsvp_status'   => 'yes',
            'checked_in_at' => now_utc(),
            'created_at'    => now_utc(),
        ]);

        Event::fire('guest.checked_in', ['booking_id' => $id, 'guest_id' => $guestId]);

        return $this->success($request, $data['name'] . ' added and checked in.', '/checkin/' . $id);
    }

    /** @return array<string,mixed>|null */
    private function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        return DB::first(
            'SELECT b.*, c.first_name, c.last_name, c.phone,
                    u.name AS host_name,
                    p.name AS package_name
               FROM {bookings} b
               LEFT JOIN {contacts} c ON c.id = b.contact_id
               LEFT JOIN {users} u ON u.id = b.host_user_id
               LEFT JOIN {party_packages} p ON p.id = b.package_id
              WHERE b.id = :id AND b.deleted_at IS NULL',
            ['id' => $id]
        );
    }
}
