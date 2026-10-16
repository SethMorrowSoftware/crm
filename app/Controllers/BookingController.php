<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Acl;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Event;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Services\AvailabilityEngine;
use App\Services\BookingRepository;
use App\Services\QuoteCalculator;
use PDOException;
use Throwable;

/**
 * Bookings: the party itself.
 *
 * This is the operational heart of the application. A deal is a conversation
 * and a quote is an offer; a booking is a promise with a date on it, and on
 * the morning of that date somebody prints the run sheet and runs the party
 * from it. Four things follow from that, and they shape everything here.
 *
 * THE ARITHMETIC IS DERIVED, NEVER POSTED. Every money figure on a booking
 * comes out of `QuoteCalculator` from the package, the headcount and the
 * add-ons. Nothing a form sends is trusted as a total, and the rate the tax
 * was worked out at is stored alongside the figure so no number on the page
 * is anonymous.
 *
 * THE SLOT IS RE-CHECKED INSIDE THE TRANSACTION. Availability shown on a form
 * is availability as it was when the page was drawn. Two people confirming the
 * last room in the same second is not a hypothetical at a venue with six
 * party rooms and a Saturday in May, so the write re-asks
 * `BookingRepository::check()` with the room already locked and refuses the
 * second one.
 *
 * A STATUS THAT CLAIMS MONEY HAS BEEN TAKEN COMES FROM THE MONEY. `deposit_paid`
 * and `paid` are not offered on the status form at all — they are set by
 * recording a payment, exactly as a quote's `sent` is set only by sending it.
 * Otherwise the front desk can mark a party paid, the run sheet says the
 * balance is clear, and nobody asks the family for £300 on the day.
 *
 * REINSTATING A CANCELLED PARTY RE-CHECKS THE ROOM. The room was freed the
 * moment it was cancelled — that is the point of cancelling — so somebody else
 * may be in it. Moving back out of `cancelled` is the one status change that
 * can be refused by availability.
 */
final class BookingController extends Controller
{
    /** The `cc_bookings.type` enum, read from the schema rather than guessed. */
    public const TYPES = [
        'birthday', 'group', 'corporate', 'field_trip', 'camp',
        'event_center', 'fundraiser', 'other',
    ];

    /** The `cc_bookings.status` enum. */
    public const STATUSES = [
        'inquiry', 'held', 'confirmed', 'deposit_paid', 'paid',
        'completed', 'cancelled', 'no_show',
    ];

    /**
     * Which status may be chosen by hand, from which.
     *
     * `deposit_paid` and `paid` appear in no list: see the class docblock.
     * `cancelled` is reachable from everywhere live, and has its own
     * permission, so it is not offered here either — cancel() handles it.
     *
     * @var array<string,list<string>>
     */
    public const TRANSITIONS = [
        'inquiry'      => ['held', 'confirmed'],
        'held'         => ['inquiry', 'confirmed'],
        'confirmed'    => ['held', 'completed', 'no_show'],
        'deposit_paid' => ['confirmed', 'completed', 'no_show'],
        'paid'         => ['completed', 'no_show'],
        'completed'    => [],
        'cancelled'    => ['inquiry'],
        'no_show'      => ['completed'],
    ];

    /** Statuses that hold a room. Mirrors BookingRepository's allocation query. */
    public const LIVE = ['inquiry', 'held', 'confirmed', 'deposit_paid', 'paid', 'completed'];

    /** Statuses whose figures may still be edited. */
    public const PRICEABLE = ['inquiry', 'held', 'confirmed', 'deposit_paid'];

    /** Add-on rows per booking. */
    public const MAX_ADDONS = 40;

    /** Guests on the list. The Event Center Hall seats a few hundred. */
    public const MAX_GUESTS_LISTED = 400;

    /**
     * The date and time formatters every booking view shares.
     *
     * Passed in rather than called from the template, the way the quote views
     * take `$calendarDate`: a view that reaches into the service layer is a
     * view that has to be read to find out what it depends on.
     *
     * @return array{dateLabel:callable(string):string,timeLabel:callable(string):string}
     */
    private function formatters(): array
    {
        return [
            'dateLabel' => static fn (string $date): string => AvailabilityEngine::isDate($date)
                ? AvailabilityEngine::dateLabel($date)
                : '—',
            'timeLabel' => static function (string $time): string {
                $minutes = AvailabilityEngine::minutes($time);

                return $minutes === null ? '' : AvailabilityEngine::timeLabel($minutes);
            },
        ];
    }

    // =================================================================
    // Reading
    // =================================================================

    public function index(Request $request): Response
    {
        if ($refusal = $this->authorize('view_bookings')) {
            return $refusal;
        }

        $filters = $this->filterValues($request);
        // Named $filter, not the obvious $where: see bookingFilter()'s docblock.
        [$filter, $params] = $this->bookingFilter($filters);

        $page  = $this->pagination($request, 25, 100);
        $total = (int) DB::value('SELECT COUNT(*) FROM {bookings} b WHERE ' . $filter, $params);

        $rows = DB::all(
            'SELECT b.id, b.booking_number, b.type, b.status, b.event_date, b.start_time, b.end_time,
                    b.guest_count, b.guest_of_honor_name, b.total_cents, b.balance_due_cents,
                    b.deposit_cents, b.deposit_paid_at, b.allergies, b.host_user_id,
                    c.first_name, c.last_name, c.phone, c.email,
                    p.name AS package_name,
                    u.name AS host_name,
                    (SELECT GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR \', \')
                       FROM {booking_resources} br
                       JOIN {resources} r ON r.id = br.resource_id
                      WHERE br.booking_id = b.id) AS rooms
               FROM {bookings} b
               LEFT JOIN {contacts} c ON c.id = b.contact_id
               LEFT JOIN {party_packages} p ON p.id = b.package_id
               LEFT JOIN {users} u ON u.id = b.host_user_id
              WHERE ' . $filter . '
              ORDER BY b.event_date ' . ($filters['past'] ? 'DESC' : 'ASC') . ', b.start_time, b.id
              LIMIT :limit OFFSET :offset',
            $params + ['limit' => $page['perPage'], 'offset' => $page['offset']]
        );

        return $this->view('bookings/index', [
            'pageTitle'  => 'Bookings',
            'bookings'   => $this->scrubRows($rows),
            'total'      => $total,
            'page'       => $page['page'],
            'lastPage'   => max(1, (int) ceil($total / $page['perPage'])),
            'filters'    => $filters,
            'types'      => self::TYPES,
            'statuses'   => self::STATUSES,
            'hosts'      => $this->hostOptions(),
            'canManage'  => Acl::can('bookings_manage'),
            'showMoney'  => Acl::can('view_revenue'),
            'summary'    => $this->summary($filter, $params),
        ] + $this->formatters());
    }

    public function show(Request $request): Response
    {
        if ($refusal = $this->authorize('view_bookings')) {
            return $refusal;
        }

        $booking = $this->find($request->routeInt('id'));

        if ($booking === null) {
            return $this->notFound('That booking does not exist, or it was deleted.');
        }

        $id = (int) $booking['id'];

        return $this->view('bookings/show', [
            'pageTitle'   => 'Booking ' . $booking['booking_number'],
            'booking'     => $this->scrubRow($booking),
            'addons'      => $this->addons($id),
            'rooms'       => $this->rooms($id),
            'attractions' => $this->schedule($id),
            'guests'      => $this->guests($id),
            'tasks'       => $this->tasks($id),
            'payments'    => Acl::can('view_revenue') ? $this->payments($id) : [],
            'waivers'     => $this->waiverProgress($id),
            'next'        => self::TRANSITIONS[(string) $booking['status']] ?? [],
            'canManage'   => Acl::can('bookings_manage'),
            'canCancel'   => Acl::can('bookings_cancel'),
            'canPay'      => Acl::can('payments_record'),
            'canCheckIn'  => Acl::can('checkin'),
            'showMoney'   => Acl::can('view_revenue'),
            'priceable'   => in_array((string) $booking['status'], self::PRICEABLE, true),
            'liveStatus'  => in_array((string) $booking['status'], self::LIVE, true),
            // There is no rate column on cc_bookings and this phase does not
            // add one: the rate shown is derived from the figures either side
            // of it, so it always agrees with the money and a later change to
            // the venue's rate cannot rewrite what an old booking says it was
            // charged. See QuoteCalculator::effectiveRate().
            'taxLabel'    => QuoteCalculator::rateLabel(QuoteCalculator::effectiveRate(
                (int) $booking['tax_cents'],
                (int) $booking['subtotal_cents'] - (int) $booking['discount_cents']
            )),
            'hosts'       => $this->hostOptions(),
        ] + $this->formatters());
    }

    /**
     * The run sheet.
     *
     * A print view for the party host, and the one page in the application
     * somebody reads while holding a tray. Allergies are the reason it exists:
     * they are printed in bold and they are printed whether or not the reader
     * holds `view_revenue`, because a nut allergy is not financial information
     * and a host without the money permission still has to know about it.
     *
     * Rendered in the NORMAL layout, like the quote's printable copy: the
     * stylesheet's @media print block already drops the sidebar, the top bar
     * and every button, so what prints is this sheet alone. A second layout
     * would be a second thing to keep in step with the theme, and the bare
     * one wraps its content in a narrow card meant for a sign-in form.
     */
    public function runSheet(Request $request): Response
    {
        if ($refusal = $this->authorize('view_bookings')) {
            return $refusal;
        }

        $booking = $this->find($request->routeInt('id'));

        if ($booking === null) {
            return $this->notFound('That booking does not exist, or it was deleted.');
        }

        $id = (int) $booking['id'];

        // Stamped so the detail page can say when it was last printed — which
        // is how a venue notices that the 10am party's sheet went out before
        // the allergy note was added.
        if (Acl::can('bookings_manage')) {
            DB::update(
                'bookings',
                ['run_sheet_printed_at' => now_utc(), 'updated_at' => now_utc()],
                'id = :id',
                ['id' => $id]
            );
        }

        return $this->view('bookings/run-sheet', [
            'pageTitle'   => 'Run sheet ' . $booking['booking_number'],
            'booking'     => $this->scrubRow($booking),
            'addons'      => $this->addons($id),
            'rooms'       => $this->rooms($id),
            'attractions' => $this->schedule($id),
            'guests'      => $this->guests($id),
            'tasks'       => $this->tasks($id),
            'venue'       => $this->venue(),
            'showMoney'   => Acl::can('view_revenue'),
        ] + $this->formatters());
    }

    // =================================================================
    // Creating and editing
    // =================================================================

    public function create(Request $request): Response
    {
        if ($refusal = $this->authorize('bookings_manage')) {
            return $refusal;
        }

        $date    = $request->str('date');
        $date    = AvailabilityEngine::isDate($date) ? $date : '';
        $package = $this->package($request->int('package_id', 0));
        $guests  = max(0, $request->int('guest_count', 0));

        $availability = $date === ''
            ? null
            : AvailabilityEngine::day(BookingRepository::inputFor(
                $date,
                $package,
                $guests,
                null,
                BookingRepository::resourceTypeFor($package, $guests)
            ));

        // A time arrived at by clicking one of the offered slots. Validated
        // against the slots that are actually free rather than taken on
        // trust, so a hand-edited URL cannot pre-fill a time the day does not
        // have — and so the rooms ticked below are the ones free AT it.
        $chosen      = AvailabilityEngine::minutes($request->str('start'));
        $chosenStart = '';
        $chosenRooms = [];

        foreach ($availability['slots'] ?? [] as $slot) {
            if ($chosen !== null && (int) $slot['start'] === $chosen) {
                $chosenStart = substr(AvailabilityEngine::timeValue($chosen), 0, 5);
                $chosenRooms = $slot['resource_ids'];
            }
        }

        return $this->view('bookings/form', [
            'pageTitle'   => 'New booking',
            'booking'     => null,
            'addons'      => [],
            'rooms'       => $chosenRooms,
            'date'        => $date,
            'chosenStart' => $chosenStart,
            'availability' => $availability,
            // What the "check the day" step chose, carried into the form
            // itself. MEASURED: without this the availability panel used the
            // package from the query string while the form's own select
            // defaulted to "no package", so the booking posted with none and
            // was refused for having no duration — on a form that was showing
            // the right package's slots at the time.
            'prefill'     => [
                'package_id'  => $package === null ? '' : (string) $package['id'],
                'guest_count' => $guests > 0 ? (string) $guests : '',
                'event_date'  => $date,
            ],
        ] + $this->formData($package) + $this->formatters());
    }

    public function store(Request $request): Response
    {
        if ($refusal = $this->authorize('bookings_manage')) {
            return $refusal;
        }

        $read = $this->readBooking($request, null);

        if ($read instanceof Response) {
            return $read;
        }

        try {
            $id = DB::transaction(function () use ($read): ?int {
                $available = $this->lockAndCheck($read, null);

                if ($available !== null) {
                    return null;
                }

                return $this->insertBooking($read);
            });
        } catch (PDOException) {
            return $this->refuse(
                $request,
                'Could not allocate a booking number — two bookings were created at once. Try again.',
                '/bookings/new'
            );
        }

        if ($id === null) {
            return $this->refuse($request, $this->unavailableMessage($read), '/bookings/new');
        }

        Event::fire('booking.created', ['booking_id' => $id, 'status' => $read['columns']['status']]);
        Audit::record('booking.created', 'bookings', $id, null, [
            'status'      => $read['columns']['status'],
            'event_date'  => $read['columns']['event_date'],
            'guest_count' => $read['columns']['guest_count'],
            'total_cents' => $read['columns']['total_cents'],
        ]);

        return $this->success($request, 'Booking created.', '/bookings/' . $id);
    }

    public function edit(Request $request): Response
    {
        if ($refusal = $this->authorize('bookings_manage')) {
            return $refusal;
        }

        $booking = $this->find($request->routeInt('id'));

        if ($booking === null) {
            return $this->notFound('That booking does not exist, or it was deleted.');
        }

        if (!in_array((string) $booking['status'], self::PRICEABLE, true)) {
            return $this->refuse($request, $this->frozenMessage($booking), '/bookings/' . (int) $booking['id']);
        }

        $id      = (int) $booking['id'];
        $package = $this->package((int) ($booking['package_id'] ?? 0));

        return $this->view('bookings/form', [
            'pageTitle' => 'Edit booking ' . $booking['booking_number'],
            'booking'   => $booking,
            'chosenStart' => '',
            'prefill'   => [],
            'addons'    => $this->addons($id),
            'rooms'     => array_map(static fn (array $r): int => (int) $r['id'], $this->rooms($id)),
            'date'      => (string) $booking['event_date'],
            'availability' => AvailabilityEngine::day(BookingRepository::inputFor(
                (string) $booking['event_date'],
                $package,
                (int) $booking['guest_count'],
                $id,
                BookingRepository::resourceTypeFor($package, (int) $booking['guest_count'])
            )),
        ] + $this->formData($package) + $this->formatters());
    }

    public function update(Request $request): Response
    {
        if ($refusal = $this->authorize('bookings_manage')) {
            return $refusal;
        }

        $id      = $request->routeInt('id');
        $booking = $this->find($id);

        if ($booking === null) {
            return $this->notFound('That booking does not exist, or it was deleted.');
        }

        if (!in_array((string) $booking['status'], self::PRICEABLE, true)) {
            return $this->refuse($request, $this->frozenMessage($booking), '/bookings/' . $id);
        }

        $read = $this->readBooking($request, $booking);

        if ($read instanceof Response) {
            return $read;
        }

        $before = $this->auditSnapshot($booking);

        $saved = DB::transaction(function () use ($read, $id, $booking): bool {
            if ($this->lockAndCheck($read, $id) !== null) {
                return false;
            }

            // The status is not editable here: it has its own endpoint with
            // its own rules, and a form that could set it would step around
            // them. Keep what the row already has.
            $columns = $read['columns'];
            unset($columns['status']);

            $columns['updated_at'] = now_utc();

            // Money already taken is not re-derived from the form. The ledger
            // is the record of what was paid; only what is OWED moves.
            $columns['balance_due_cents'] = max(0, $columns['total_cents'] - $this->paidCents($id));

            DB::update('bookings', $columns, 'id = :id', ['id' => $id]);

            $this->writeAddons($id, $read['addons']);
            $this->writeSchedule($id, $read['attractions']);

            BookingRepository::allocate(
                $id,
                $columns['event_date'],
                $read['start_minutes'],
                $read['end_minutes'],
                $read['resource_ids']
            );

            return true;
        });

        if (!$saved) {
            return $this->refuse($request, $this->unavailableMessage($read), '/bookings/' . $id . '/edit');
        }

        $after = $this->auditSnapshot($this->find($id) ?? []);

        Audit::record('booking.updated', 'bookings', $id, $before, $after);

        if ($before['event_date'] !== $after['event_date'] || $before['start_time'] !== $after['start_time']) {
            Event::fire('booking.rescheduled', [
                'booking_id' => $id,
                'from'       => $before['event_date'] . ' ' . $before['start_time'],
                'to'         => $after['event_date'] . ' ' . $after['start_time'],
            ]);
        }

        return $this->success($request, 'Booking updated.', '/bookings/' . $id);
    }

    // =================================================================
    // Status
    // =================================================================

    public function status(Request $request): Response
    {
        if ($refusal = $this->authorize('bookings_manage')) {
            return $refusal;
        }

        $id      = $request->routeInt('id');
        $booking = $this->find($id);

        if ($booking === null) {
            return $this->notFound('That booking does not exist, or it was deleted.');
        }

        $from = (string) $booking['status'];
        $to   = $request->str('status');

        if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            return $this->refuse(
                $request,
                in_array($to, ['deposit_paid', 'paid'], true)
                    // The rule, in words, at the moment somebody tries it.
                    ? 'A booking is marked paid by recording the payment, not by changing its status.'
                    : 'A booking that is ' . $from . ' cannot be moved to ' . ($to === '' ? 'nothing' : $to) . '.',
                '/bookings/' . $id
            );
        }

        // Coming back from cancelled is the one transition availability can
        // refuse: the room was freed when it was cancelled, which is the whole
        // point, so somebody else may be in it now.
        if ($from === 'cancelled') {
            $package = $this->package((int) ($booking['package_id'] ?? 0));
            $start   = AvailabilityEngine::minutes((string) $booking['start_time']);

            if ($start !== null) {
                $check = BookingRepository::check(
                    (string) $booking['event_date'],
                    $start,
                    $package,
                    (int) $booking['guest_count'],
                    $id,
                    BookingRepository::resourceTypeFor($package, (int) $booking['guest_count'])
                );

                if (($check['ok'] ?? false) !== true) {
                    return $this->refuse(
                        $request,
                        'That slot is no longer free: ' . $check['message']
                            . ' Edit the booking onto another date or time first.',
                        '/bookings/' . $id
                    );
                }
            }
        }

        $columns = ['status' => $to, 'updated_at' => now_utc()];

        if ($to === 'confirmed' && $booking['confirmed_at'] === null) {
            $columns['confirmed_at'] = now_utc();
        }

        if ($from === 'cancelled') {
            // Reinstated: the cancellation is no longer the current state, and
            // leaving its reason on the row would read as if it still were.
            $columns['cancelled_at']        = null;
            $columns['cancellation_reason'] = null;
        }

        DB::transaction(function () use ($id, $columns, $to, $booking): void {
            DB::update('bookings', $columns, 'id = :id', ['id' => $id]);

            // A reinstated booking has to get its room back: cancelling does
            // not delete the allocation, but a room is only held while the
            // status is live, so nothing more is needed here than the status
            // write itself. The allocation rows are still there.
            if ($to === 'confirmed' && (int) $booking['package_id'] > 0) {
                $this->seedTasks($id, $booking);
            }
        });

        Event::fire('booking.' . $to, ['booking_id' => $id, 'from' => $from]);
        Audit::record('booking.status', 'bookings', $id, ['status' => $from], ['status' => $to]);

        return $this->success($request, 'Booking moved to ' . str_replace('_', ' ', $to) . '.', '/bookings/' . $id);
    }

    /**
     * Cancel a booking, with its own permission and a reason.
     *
     * The allocation rows are deliberately NOT deleted. The room frees up
     * because `BookingRepository` ignores cancelled bookings, and keeping the
     * rows means a reinstated party goes back into the room it had rather than
     * into no room at all — and that the calendar's history is still true
     * about where that party was going to be.
     */
    public function cancel(Request $request): Response
    {
        if ($refusal = $this->authorize('bookings_cancel')) {
            return $refusal;
        }

        $id      = $request->routeInt('id');
        $booking = $this->find($id);

        if ($booking === null) {
            return $this->notFound('That booking does not exist, or it was deleted.');
        }

        if ((string) $booking['status'] === 'cancelled') {
            return $this->refuse($request, 'That booking is already cancelled.', '/bookings/' . $id);
        }

        $data = $this->validate($request, [
            'cancellation_reason' => 'required|string|min:3|max:255',
        ], ['cancellation_reason' => 'Reason']);

        if ($data instanceof Response) {
            return $data;
        }

        $paid = $this->paidCents($id);

        DB::update('bookings', [
            'status'              => 'cancelled',
            'cancelled_at'        => now_utc(),
            'cancellation_reason' => $data['cancellation_reason'],
            'updated_at'          => now_utc(),
        ], 'id = :id', ['id' => $id]);

        Event::fire('booking.cancelled', [
            'booking_id' => $id,
            'reason'     => $data['cancellation_reason'],
            'paid_cents' => $paid,
        ]);
        Audit::record('booking.cancelled', 'bookings', $id, ['status' => $booking['status']], [
            'status' => 'cancelled',
            'reason' => $data['cancellation_reason'],
            // Surfaced in the audit row because a cancelled party that has
            // taken money is a refund somebody has to action, and the audit
            // log is where that gets noticed after the fact.
            'paid_cents' => $paid,
        ]);

        return $this->success(
            $request,
            $paid > 0
                ? 'Booking cancelled. ' . money($paid) . ' has been taken on it — arrange the refund.'
                : 'Booking cancelled.',
            '/bookings/' . $id
        );
    }

    // =================================================================
    // Payments
    // =================================================================

    /**
     * Record money taken.
     *
     * NO CARD DATA, ever: `method` is a word and `reference` is whatever the
     * POS or the hosted payment page calls the transaction. There is no column
     * anywhere in this schema that could hold a card number, and this is the
     * endpoint somebody would be tempted to add one to.
     *
     * The booking's own money columns are DERIVED from the ledger afterwards
     * rather than adjusted in place, so a mistyped payment that gets deleted
     * leaves the balance correct.
     */
    public function storePayment(Request $request): Response
    {
        if ($refusal = $this->authorize('payments_record')) {
            return $refusal;
        }

        $id      = $request->routeInt('id');
        $booking = $this->find($id);

        if ($booking === null) {
            return $this->notFound('That booking does not exist, or it was deleted.');
        }

        $data = $this->validate($request, [
            'amount'      => 'required|string|max:20',
            'type'        => 'required|in:deposit,balance,refund,adjustment',
            'method'      => 'nullable|string|max:32',
            'reference'   => 'nullable|string|max:80',
            'note'        => 'nullable|string|max:255',
            'occurred_on' => 'nullable|date',
        ], ['amount' => 'Amount', 'occurred_on' => 'Date taken']);

        if ($data instanceof Response) {
            return $data;
        }

        $amount = QuoteCalculator::parseMoney((string) $data['amount']);

        if ($amount === null || $amount <= 0) {
            return $this->refuse($request, 'Enter the amount in dollars and cents.', '/bookings/' . $id);
        }

        // A refund is stored negative, so the ledger sums to what is actually
        // held and nothing downstream has to know the sign convention.
        $signed = $data['type'] === 'refund' ? -$amount : $amount;

        $occurred = isset($data['occurred_on']) && $data['occurred_on'] !== ''
            ? (string) BookingRepository::localToUtc((string) $data['occurred_on'], 12 * 60)
            : now_utc();

        DB::transaction(function () use ($id, $signed, $data, $occurred): void {
            DB::insert('booking_payments', [
                'booking_id'   => $id,
                'amount_cents' => $signed,
                'type'         => $data['type'],
                'method'       => $data['method'] ?? null,
                'reference'    => $data['reference'] ?? null,
                'recorded_by'  => Auth::id(),
                'occurred_at'  => $occurred,
                'note'         => $data['note'] ?? null,
                'created_at'   => now_utc(),
            ]);

            $this->settle($id);
        });

        Event::fire('booking.payment_recorded', ['booking_id' => $id, 'amount_cents' => $signed]);
        Audit::record('booking.payment', 'bookings', $id, null, [
            'amount_cents' => $signed,
            'type'         => $data['type'],
            'method'       => $data['method'] ?? null,
        ]);

        return $this->success($request, money(abs($signed)) . ' recorded.', '/bookings/' . $id);
    }

    public function destroyPayment(Request $request): Response
    {
        if ($refusal = $this->authorize('payments_record')) {
            return $refusal;
        }

        $id      = $request->routeInt('id');
        $booking = $this->find($id);

        if ($booking === null) {
            return $this->notFound('That booking does not exist, or it was deleted.');
        }

        $paymentId = $request->int('payment_id', 0);

        $payment = DB::first(
            'SELECT * FROM {booking_payments} WHERE id = :pid AND booking_id = :id',
            ['pid' => $paymentId, 'id' => $id]
        );

        if ($payment === null) {
            return $this->refuse($request, 'That payment is not on this booking.', '/bookings/' . $id);
        }

        DB::transaction(function () use ($paymentId, $id): void {
            DB::delete('booking_payments', 'id = :pid', ['pid' => $paymentId]);

            $this->settle($id);
        });

        Audit::record('booking.payment_deleted', 'bookings', $id, [
            'amount_cents' => (int) $payment['amount_cents'],
            'type'         => (string) $payment['type'],
        ], null);

        return $this->success($request, 'Payment removed.', '/bookings/' . $id);
    }

    // =================================================================
    // The checklist, the guest list and the host
    // =================================================================

    public function storeTask(Request $request): Response
    {
        if ($refusal = $this->authorize('bookings_manage')) {
            return $refusal;
        }

        $id = $request->routeInt('id');

        if ($this->find($id) === null) {
            return $this->notFound('That booking does not exist, or it was deleted.');
        }

        $data = $this->validate($request, [
            'title'       => 'required|string|min:2|max:191',
            'assigned_to' => 'nullable|int|exists:users',
        ], ['title' => 'What needs doing']);

        if ($data instanceof Response) {
            return $data;
        }

        DB::insert('booking_tasks', [
            'booking_id'  => $id,
            'title'       => $data['title'],
            'assigned_to' => $this->nullableId($data['assigned_to'] ?? null),
            // No created_at: cc_booking_tasks has no timestamps. The audit
            // log is the record of when a checklist changed.
            'sort_order'  => 1 + (int) DB::value(
                'SELECT COALESCE(MAX(sort_order), 0) FROM {booking_tasks} WHERE booking_id = :id',
                ['id' => $id]
            ),
        ]);

        return $this->success($request, 'Added to the checklist.', '/bookings/' . $id);
    }

    /**
     * Tick or untick a checklist item.
     *
     * `bookings_manage`, like every other write here. The task module's
     * lower gate — where the person a job is assigned to may close it without
     * the write permission — was copied here at first and does not apply: the
     * roles that run parties, Front Desk and Party Host, both already hold
     * `bookings_manage`, so nobody gained anything from the looser gate while
     * the seeded Read Only role gained the ability to tick things off. A
     * read-only role writing to a booking is the bug; the convenience was
     * imaginary.
     */
    public function toggleTask(Request $request): Response
    {
        if ($refusal = $this->authorize('bookings_manage')) {
            return $refusal;
        }

        $id = $request->routeInt('id');

        if ($this->find($id) === null) {
            return $this->notFound('That booking does not exist, or it was deleted.');
        }

        $taskId = $request->int('task_id', 0);

        $task = DB::first(
            'SELECT id, completed_at FROM {booking_tasks} WHERE id = :tid AND booking_id = :id',
            ['tid' => $taskId, 'id' => $id]
        );

        if ($task === null) {
            return $this->refuse($request, 'That item is not on this booking.', '/bookings/' . $id);
        }

        DB::update(
            'booking_tasks',
            ['completed_at' => $task['completed_at'] === null ? now_utc() : null],
            'id = :tid',
            ['tid' => $taskId]
        );

        return $this->success(
            $request,
            $task['completed_at'] === null ? 'Ticked off.' : 'Put back on the list.',
            '/bookings/' . $id
        );
    }

    public function storeGuest(Request $request): Response
    {
        if ($refusal = $this->authorize('bookings_manage')) {
            return $refusal;
        }

        $id = $request->routeInt('id');

        if ($this->find($id) === null) {
            return $this->notFound('That booking does not exist, or it was deleted.');
        }

        $listed = (int) DB::value('SELECT COUNT(*) FROM {booking_guests} WHERE booking_id = :id', ['id' => $id]);

        if ($listed >= self::MAX_GUESTS_LISTED) {
            return $this->refuse(
                $request,
                'That guest list already holds ' . self::MAX_GUESTS_LISTED . ' names.',
                '/bookings/' . $id
            );
        }

        $data = $this->validate($request, [
            'name'         => 'required|string|min:1|max:120',
            'age'          => 'nullable|int|min:0|max:120',
            'parent_name'  => 'nullable|string|max:120',
            'parent_phone' => 'nullable|string|max:32',
            'rsvp_status'  => 'nullable|in:unknown,yes,no,maybe',
        ], ['name' => 'Name']);

        if ($data instanceof Response) {
            return $data;
        }

        DB::insert('booking_guests', [
            'booking_id'   => $id,
            'name'         => $data['name'],
            'age'          => $data['age'] ?? null,
            'parent_name'  => $data['parent_name'] ?? null,
            'parent_phone' => normalize_phone($data['parent_phone'] ?? null),
            'rsvp_status'  => $data['rsvp_status'] ?? 'unknown',
            'created_at'   => now_utc(),
        ]);

        return $this->success($request, 'Guest added.', '/bookings/' . $id);
    }

    public function destroyGuest(Request $request): Response
    {
        if ($refusal = $this->authorize('bookings_manage')) {
            return $refusal;
        }

        $id = $request->routeInt('id');

        if ($this->find($id) === null) {
            return $this->notFound('That booking does not exist, or it was deleted.');
        }

        DB::delete(
            'booking_guests',
            'id = :gid AND booking_id = :id',
            ['gid' => $request->int('guest_id', 0), 'id' => $id]
        );

        return $this->success($request, 'Guest removed.', '/bookings/' . $id);
    }

    public function assignHost(Request $request): Response
    {
        if ($refusal = $this->authorize('bookings_manage')) {
            return $refusal;
        }

        $id      = $request->routeInt('id');
        $booking = $this->find($id);

        if ($booking === null) {
            return $this->notFound('That booking does not exist, or it was deleted.');
        }

        $data = $this->validate($request, [
            'host_user_id' => 'nullable|int|exists:users',
        ], ['host_user_id' => 'Party host']);

        if ($data instanceof Response) {
            return $data;
        }

        $host = $this->nullableId($data['host_user_id'] ?? null);

        DB::update('bookings', [
            'host_user_id' => $host,
            'updated_at'   => now_utc(),
        ], 'id = :id', ['id' => $id]);

        Audit::record(
            'booking.host_assigned',
            'bookings',
            $id,
            ['host_user_id' => $booking['host_user_id']],
            ['host_user_id' => $host]
        );

        return $this->success($request, $host === null ? 'Host cleared.' : 'Host assigned.', '/bookings/' . $id);
    }

    // =================================================================
    // Availability, as JSON, for the form
    // =================================================================

    /**
     * What is free on a date.
     *
     * Progressive enhancement only: the form works without it (an unavailable
     * slot comes back from the POST with the times that ARE free), and this
     * makes it pleasant rather than possible. Gated on `view_bookings` because
     * it reports where the venue has gaps, which is commercial information.
     */
    public function availability(Request $request): Response
    {
        if ($refusal = $this->authorize('view_bookings')) {
            return $refusal;
        }

        $date = $request->str('date');

        if (!AvailabilityEngine::isDate($date)) {
            return $this->json(['ok' => false, 'reason' => 'past', 'message' => 'Choose a date.'], 422);
        }

        $package = $this->package($request->int('package_id', 0));
        $guests  = max(0, $request->int('guest_count', 0));
        $exclude = $request->int('exclude', 0);

        $result = AvailabilityEngine::day(BookingRepository::inputFor(
            $date,
            $package,
            $guests,
            $exclude > 0 ? $exclude : null,
            BookingRepository::resourceTypeFor($package, $guests)
        ));

        if (($result['ok'] ?? false) !== true) {
            $result['alternatives'] = BookingRepository::alternatives(
                $date,
                $package,
                $guests,
                3,
                BookingRepository::resourceTypeFor($package, $guests)
            );
        }

        return $this->json($result);
    }

    // =================================================================
    // Reading the form
    // =================================================================

    /**
     * Everything the write needs, or a Response explaining why not.
     *
     * One method rather than a scatter of reads in store() and update(), so
     * the two cannot validate differently — which is how a field ends up
     * editable but not creatable.
     *
     * @param array<string,mixed>|null $existing
     * @return array<string,mixed>|Response
     */
    private function readBooking(Request $request, ?array $existing): array|Response
    {
        $back = $existing === null ? '/bookings/new' : '/bookings/' . (int) $existing['id'] . '/edit';

        $data = $this->validate($request, [
            'type'                => 'required|in:' . implode(',', self::TYPES),
            'package_id'          => 'nullable|int|exists:party_packages',
            'contact_id'          => 'nullable|int|exists:contacts',
            'deal_id'             => 'nullable|int|exists:deals',
            'event_date'          => 'required|date',
            'guest_count'         => 'required|int|min:0|max:' . QuoteCalculator::MAX_GUESTS,
            'adult_count'         => 'nullable|int|min:0|max:' . QuoteCalculator::MAX_GUESTS,
            'child_count'         => 'nullable|int|min:0|max:' . QuoteCalculator::MAX_GUESTS,
            'guest_of_honor_name' => 'nullable|string|max:120',
            'guest_of_honor_age'  => 'nullable|int|min:0|max:120',
            'host_user_id'        => 'nullable|int|exists:users',
            'allergies'           => 'nullable|string|max:500',
            'dietary_notes'       => 'nullable|string|max:500',
            'cake_notes'          => 'nullable|string|max:500',
            'special_requests'    => 'nullable|string|max:5000',
            'internal_notes'      => 'nullable|string|max:5000',
            'source'              => 'nullable|string|max:40',
        ], [
            'event_date'          => 'Event date',
            'guest_count'         => 'Guests',
            'guest_of_honor_name' => 'Guest of honour',
        ]);

        if ($data instanceof Response) {
            return $data;
        }

        $date = (string) $data['event_date'];

        if (!AvailabilityEngine::isDate($date)) {
            return $this->refuse($request, 'That is not a date the calendar has.', $back);
        }

        $package = $this->package($this->nullableId($data['package_id'] ?? null) ?? 0);

        // The start time is the one field the slot picker and a typed entry
        // both land in, so it is read by hand rather than by rule.
        $start = AvailabilityEngine::minutes($request->str('start_time'));

        if ($start === null) {
            return $this->refuse($request, 'Choose a start time.', $back);
        }

        $duration = (int) ($package['duration_minutes'] ?? 0);

        if ($duration <= 0) {
            $typedEnd = AvailabilityEngine::minutes($request->str('end_time'));

            if ($typedEnd === null || $typedEnd <= $start) {
                return $this->refuse(
                    $request,
                    'With no package to take a duration from, give the booking an end time after its start.',
                    $back
                );
            }

            $duration = $typedEnd - $start;
        }

        $addons = $this->readAddons($request);

        if (is_string($addons)) {
            return $this->refuse($request, $addons, $back);
        }

        $discount = QuoteCalculator::parseMoney($request->str('discount'));

        if ($discount === null) {
            return $this->refuse($request, 'The discount must be an amount in dollars and cents.', $back);
        }

        $depositRaw = trim($request->str('deposit'));
        $deposit    = $depositRaw === '' ? null : QuoteCalculator::parseMoney($depositRaw);

        if ($depositRaw !== '' && $deposit === null) {
            return $this->refuse($request, 'The deposit must be an amount in dollars and cents.', $back);
        }

        $taxExempt = $request->str('tax_exempt') !== '';
        $rate      = BookingRepository::taxRate();

        $priced = QuoteCalculator::price($package ?? [], (int) $data['guest_count'], $addons, [
            'duration_minutes' => $duration,
            'discount_cents'   => $discount,
            'tax_rate'         => $rate,
            'tax_exempt'       => $taxExempt,
            'deposit_cents'    => $deposit,
        ]);

        $resourceIds = array_map('intval', $request->array('resource_ids'));

        return [
            'package'       => $package,
            'addons'        => $priced['addon_lines'],
            'attractions'   => $this->readSchedule($request),
            'resource_ids'  => $resourceIds,
            'resource_type' => BookingRepository::resourceTypeFor($package, (int) $data['guest_count']),
            'start_minutes' => $start,
            'end_minutes'   => $start + $duration,
            'priced'        => $priced,
            'columns'       => [
                'type'                => $data['type'],
                'status'              => $existing === null
                    ? ($request->str('status') === 'held' ? 'held' : 'inquiry')
                    : (string) $existing['status'],
                'package_id'          => $this->nullableId($data['package_id'] ?? null),
                'contact_id'          => $this->nullableId($data['contact_id'] ?? null),
                'deal_id'             => $this->nullableId($data['deal_id'] ?? null),
                'event_date'          => $date,
                'start_time'          => AvailabilityEngine::timeValue($start),
                'end_time'            => AvailabilityEngine::timeValue($start + $duration),
                'guest_count'         => (int) $data['guest_count'],
                'adult_count'         => (int) ($data['adult_count'] ?? 0),
                'child_count'         => (int) ($data['child_count'] ?? 0),
                'guest_of_honor_name' => $data['guest_of_honor_name'] ?? null,
                'guest_of_honor_age'  => $data['guest_of_honor_age'] ?? null,
                'host_user_id'        => $this->nullableId($data['host_user_id'] ?? null),
                'allergies'           => $data['allergies'] ?? null,
                'dietary_notes'       => $data['dietary_notes'] ?? null,
                'cake_notes'          => $data['cake_notes'] ?? null,
                'special_requests'    => $data['special_requests'] ?? null,
                'internal_notes'      => $data['internal_notes'] ?? null,
                'source'              => $data['source'] ?? ($existing === null ? 'staff' : null),
                'subtotal_cents'      => $priced['subtotal_cents'],
                'discount_cents'      => $priced['discount_cents'],
                'tax_cents'           => $priced['tax_cents'],
                'total_cents'         => $priced['total_cents'],
                'deposit_cents'       => $priced['deposit_cents'],
                'balance_due_cents'   => $priced['balance_cents'],
            ],
        ];
    }

    /**
     * Add-on rows from the form.
     *
     * @return list<array<string,mixed>>|string
     */
    private function readAddons(Request $request): array|string
    {
        $rows = $request->array('addons');
        $out  = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $addonId = isset($row['addon_id']) ? (int) $row['addon_id'] : 0;
            $name    = trim((string) ($row['description'] ?? ''));
            $qtyRaw  = trim((string) ($row['qty'] ?? ''));

            if ($addonId <= 0 && $name === '') {
                continue;
            }

            // A BLANK QUANTITY ON A CATALOGUED ADD-ON MEANS "NOT WANTED".
            //
            // The form renders every item in the catalogue with a quantity
            // box, so a blank one is the normal state of almost all of them.
            // Reading blank as "one" put all fourteen seeded add-ons on a
            // booking nobody had asked for them on — MEASURED on a real
            // install: $5,569 of extras on a $511 party, with the arithmetic
            // perfectly correct and the figure completely wrong.
            //
            // A hand-typed line is the opposite case: somebody who wrote a
            // description and a price plainly wants one of it.
            if ($addonId > 0 && $qtyRaw === '') {
                continue;
            }

            if (count($out) >= self::MAX_ADDONS) {
                return 'A booking can carry at most ' . self::MAX_ADDONS . ' add-ons.';
            }

            $qty = $qtyRaw === '' ? 100 : QuoteCalculator::parseQty($qtyRaw);

            if ($qty === null) {
                return 'Add-on ' . (count($out) + 1) . ': the quantity must be a number with at most two decimals.';
            }

            // Nor is a quantity of zero a line: it would store a row worth
            // nothing and print on the run sheet as an item the party has.
            if ($qty === 0) {
                continue;
            }

            if ($addonId > 0) {
                // A catalogued add-on is priced from the CATALOGUE, not from
                // the form: a posted price on a named item is how a discount
                // gets applied without anybody deciding to give one.
                $catalogue = DB::first(
                    'SELECT id, name, price_cents, unit FROM {package_addons} WHERE id = :id AND is_active = 1',
                    ['id' => $addonId]
                );

                if ($catalogue === null) {
                    return 'One of the add-ons chosen is no longer offered.';
                }

                $out[] = [
                    'addon_id'       => (int) $catalogue['id'],
                    'name'           => (string) $catalogue['name'],
                    'unit'           => (string) $catalogue['unit'],
                    'price_cents'    => (int) $catalogue['price_cents'],
                    'qty_hundredths' => $qty,
                ];

                continue;
            }

            // A one-off line typed by hand. Its price IS the form's, because
            // there is nowhere else for it to come from.
            $price = QuoteCalculator::parseMoney((string) ($row['unit'] ?? ''));

            if ($price === null) {
                return 'Add-on "' . $name . '": the price must be an amount in dollars and cents.';
            }

            $unit = (string) ($row['unit_basis'] ?? 'flat');

            $out[] = [
                'addon_id'       => null,
                'name'           => mb_substr($name, 0, 191),
                'unit'           => in_array($unit, QuoteCalculator::UNITS, true) ? $unit : 'flat',
                'price_cents'    => $price,
                'qty_hundredths' => $qty,
            ];
        }

        return $out;
    }

    /**
     * The attraction schedule within the party.
     *
     * @return list<array{attraction_id:int,start:string|null,end:string|null}>
     */
    private function readSchedule(Request $request): array
    {
        $out = [];

        foreach ($request->array('attractions') as $row) {
            if (!is_array($row)) {
                continue;
            }

            $attractionId = (int) ($row['attraction_id'] ?? 0);

            if ($attractionId <= 0) {
                continue;
            }

            $start = AvailabilityEngine::minutes((string) ($row['start_time'] ?? ''));
            $end   = AvailabilityEngine::minutes((string) ($row['end_time'] ?? ''));

            $out[] = [
                'attraction_id' => $attractionId,
                'start'         => $start === null ? null : AvailabilityEngine::timeValue($start),
                'end'           => $end === null ? null : AvailabilityEngine::timeValue($end),
            ];
        }

        return $out;
    }

    // =================================================================
    // Writing
    // =================================================================

    /**
     * Re-ask availability with the write about to happen.
     *
     * Returns null when the slot is free, or the engine's refusal when it is
     * not. Called inside the transaction so two people confirming the last
     * room in the same second cannot both succeed.
     *
     * $read is taken BY REFERENCE because this also settles which rooms the
     * booking takes — see the comment on the allocation below. The rooms a
     * booking gets have to be rooms the check just proved were free, so the
     * two decisions belong in one place.
     *
     * @param array<string,mixed> $read
     * @return array<string,mixed>|null
     */
    private function lockAndCheck(array &$read, ?int $excludeBookingId): ?array
    {
        // Lock the candidate rooms for the duration of the transaction, so the
        // check and the write see the same world. A FOR UPDATE on the resource
        // rows is enough: every allocation write goes through a booking whose
        // rooms are locked here first.
        $ids = array_values(array_filter($read['resource_ids'], static fn (int $id): bool => $id > 0));

        if ($ids !== []) {
            $placeholders = [];
            $params       = [];

            foreach ($ids as $index => $id) {
                $placeholders[]        = ':r' . $index;
                $params['r' . $index]  = $id;
            }

            DB::all(
                'SELECT id FROM {resources} WHERE id IN (' . implode(', ', $placeholders) . ') FOR UPDATE',
                $params
            );
        }

        $check = BookingRepository::check(
            (string) $read['columns']['event_date'],
            (int) $read['start_minutes'],
            $read['package'],
            (int) $read['columns']['guest_count'],
            $excludeBookingId,
            (string) $read['resource_type']
        );

        if (($check['ok'] ?? false) !== true) {
            return $check;
        }

        // A BOOKING ALWAYS TAKES A ROOM.
        //
        // The form's room tick-boxes are an override, not a requirement, and
        // somebody taking a party over the phone will not always touch them.
        // Without this, a booking saved with none held nothing: the slot
        // stayed "free", six roomless parties could be taken at two o'clock,
        // and the calendar would show every room available while the diary
        // said otherwise. MEASURED — the double-booking test caught it by
        // creating the second party successfully.
        //
        // The engine has already told us which rooms are actually free at
        // this time, so the first of them is taken. Nothing is invented: if
        // there were no room, the check above would have refused.
        $chosen = array_values(array_filter(
            $read['resource_ids'],
            static fn (int $id): bool => in_array($id, $check['slot']['resource_ids'] ?? [], true)
        ));

        $read['resource_ids'] = $chosen !== []
            ? $chosen
            : array_slice($check['slot']['resource_ids'] ?? [], 0, 1);

        return null;
    }

    /** @param array<string,mixed> $read */
    private function insertBooking(array $read): ?int
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $columns = $read['columns'];
            $columns['booking_number'] = $this->nextNumber();
            $columns['created_at']     = now_utc();
            $columns['updated_at']     = now_utc();

            try {
                $id = DB::insert('bookings', $columns);
            } catch (PDOException $exception) {
                // 1062 is a duplicate key: another request took this number
                // between our read and our write. Try the next one.
                if ((int) ($exception->errorInfo[1] ?? 0) !== 1062 || $attempt === 4) {
                    throw $exception;
                }

                continue;
            }

            $this->writeAddons($id, $read['addons']);
            $this->writeSchedule($id, $read['attractions']);

            BookingRepository::allocate(
                $id,
                (string) $columns['event_date'],
                (int) $read['start_minutes'],
                (int) $read['end_minutes'],
                $read['resource_ids']
            );

            return $id;
        }

        return null;
    }

    /** @param list<array<string,mixed>> $lines */
    private function writeAddons(int $bookingId, array $lines): void
    {
        DB::delete('booking_addons', 'booking_id = :id', ['id' => $bookingId]);

        foreach ($lines as $line) {
            DB::insert('booking_addons', [
                'booking_id'  => $bookingId,
                'addon_id'    => $line['addon_id'],
                'description' => $line['name'],
                // The stored quantity is the DECIMAL the schema declares, and
                // the stored total is the one the calculator worked out — so
                // the row can never disagree with itself.
                'qty'         => QuoteCalculator::qtyLabel((int) $line['qty_hundredths']),
                'unit_cents'  => (int) $line['price_cents'],
                'total_cents' => (int) $line['total_cents'],
            ]);
        }
    }

    /** @param list<array<string,mixed>> $rows */
    private function writeSchedule(int $bookingId, array $rows): void
    {
        DB::delete('booking_attractions', 'booking_id = :id', ['id' => $bookingId]);

        foreach ($rows as $index => $row) {
            DB::insert('booking_attractions', [
                'booking_id'    => $bookingId,
                'attraction_id' => $row['attraction_id'],
                'start_time'    => $row['start'],
                'end_time'      => $row['end'],
                'sort_order'    => $index,
            ]);
        }
    }

    /**
     * Recompute what has been paid and what is owed, from the ledger.
     *
     * Derived rather than adjusted, so deleting a mistyped payment leaves the
     * booking correct — and so the three money columns on the row can never
     * drift from the payments behind them.
     */
    private function settle(int $bookingId): void
    {
        $booking = DB::first(
            'SELECT total_cents, deposit_cents, status FROM {bookings} WHERE id = :id',
            ['id' => $bookingId]
        );

        if ($booking === null) {
            return;
        }

        $paid    = $this->paidCents($bookingId);
        $total   = (int) $booking['total_cents'];
        $deposit = (int) $booking['deposit_cents'];
        $status  = (string) $booking['status'];

        $columns = [
            'balance_due_cents' => max(0, $total - $paid),
            'updated_at'        => now_utc(),
        ];

        // The first payment that covers the deposit stamps the date; a later
        // refund that takes it back below clears it again, because the stamp
        // means "the deposit is held" and not "a payment once arrived".
        $columns['deposit_paid_at'] = $deposit > 0 && $paid >= $deposit
            ? (DB::value(
                'SELECT MIN(occurred_at) FROM {booking_payments} WHERE booking_id = :id AND amount_cents > 0',
                ['id' => $bookingId]
            ) ?: now_utc())
            : null;

        $columns['paid_in_full_at'] = $total > 0 && $paid >= $total ? now_utc() : null;

        // A status that claims money has been taken is set HERE and nowhere
        // else. Only ever forwards through the money ladder, and never over a
        // status that has moved past it: a completed party that takes a late
        // balance payment does not go back to `paid`.
        if (in_array($status, ['inquiry', 'held', 'confirmed', 'deposit_paid', 'paid'], true)) {
            if ($total > 0 && $paid >= $total) {
                $columns['status'] = 'paid';
            } elseif ($deposit > 0 && $paid >= $deposit) {
                $columns['status'] = 'deposit_paid';
            } elseif ($paid <= 0 && in_array($status, ['deposit_paid', 'paid'], true)) {
                // The money came back out. The status has to come back too,
                // or the run sheet says the deposit is held when it is not.
                $columns['status'] = 'confirmed';
            }
        }

        DB::update('bookings', $columns, 'id = :id', ['id' => $bookingId]);
    }

    private function paidCents(int $bookingId): int
    {
        return (int) DB::value(
            'SELECT COALESCE(SUM(amount_cents), 0) FROM {booking_payments} WHERE booking_id = :id',
            ['id' => $bookingId]
        );
    }

    /**
     * The checklist a confirmed party starts with.
     *
     * Only ever added once, and only to a booking with no checklist, so
     * confirming a party twice does not produce two of everything.
     *
     * @param array<string,mixed> $booking
     */
    private function seedTasks(int $bookingId, array $booking): void
    {
        $existing = (int) DB::value(
            'SELECT COUNT(*) FROM {booking_tasks} WHERE booking_id = :id',
            ['id' => $bookingId]
        );

        if ($existing > 0) {
            return;
        }

        $items = ['Confirm headcount with the family', 'Room set up', 'Cake ordered or confirmed'];

        if (trim((string) ($booking['allergies'] ?? '')) !== '') {
            // First on the list, because it is the one that matters.
            array_unshift($items, 'Brief the kitchen on allergies');
        }

        $items[] = 'Balance collected';

        foreach ($items as $index => $title) {
            DB::insert('booking_tasks', [
                'booking_id' => $bookingId,
                'title'      => $title,
                'sort_order' => $index,
            ]);
        }
    }

    private function nextNumber(): string
    {
        $prefix = 'B-' . gmdate('Y') . '-';

        $last = DB::value(
            'SELECT booking_number FROM {bookings}
              WHERE booking_number LIKE :prefix
              ORDER BY LENGTH(booking_number) DESC, booking_number DESC
              LIMIT 1',
            ['prefix' => $prefix . '%']
        );

        $sequence = is_string($last) && $last !== ''
            ? (int) substr($last, strlen($prefix))
            : 0;

        return $prefix . str_pad((string) ($sequence + 1), 4, '0', STR_PAD_LEFT);
    }

    // =================================================================
    // Reads shared by the views
    // =================================================================

    /** @return array<string,mixed>|null */
    private function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        return DB::first(
            'SELECT b.*, c.first_name, c.last_name, c.email, c.phone, c.phone_normalized,
                    p.name AS package_name, p.duration_minutes AS package_duration,
                    p.included_guests, p.additional_guest_cents, p.main_attraction_id,
                    u.name AS host_name,
                    d.title AS deal_title,
                    co.name AS company_name
               FROM {bookings} b
               LEFT JOIN {contacts} c ON c.id = b.contact_id
               LEFT JOIN {party_packages} p ON p.id = b.package_id
               LEFT JOIN {users} u ON u.id = b.host_user_id
               LEFT JOIN {deals} d ON d.id = b.deal_id
               LEFT JOIN {companies} co ON co.id = b.company_id
              WHERE b.id = :id AND b.deleted_at IS NULL',
            ['id' => $id]
        );
    }

    /** @return array<string,mixed>|null */
    private function package(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        return DB::first('SELECT * FROM {party_packages} WHERE id = :id', ['id' => $id]);
    }


    /** @return list<array<string,mixed>> */
    private function addons(int $bookingId): array
    {
        return DB::all(
            'SELECT ba.id, ba.addon_id, ba.description, ba.qty, ba.unit_cents, ba.total_cents,
                    pa.unit AS unit_basis
               FROM {booking_addons} ba
               LEFT JOIN {package_addons} pa ON pa.id = ba.addon_id
              WHERE ba.booking_id = :id
              ORDER BY ba.id',
            ['id' => $bookingId]
        );
    }

    /** @return list<array<string,mixed>> */
    private function rooms(int $bookingId): array
    {
        return DB::all(
            'SELECT r.id, r.name, r.type, r.capacity, br.start_time, br.end_time
               FROM {booking_resources} br
               JOIN {resources} r ON r.id = br.resource_id
              WHERE br.booking_id = :id
              ORDER BY r.name',
            ['id' => $bookingId]
        );
    }

    /** @return list<array<string,mixed>> */
    private function schedule(int $bookingId): array
    {
        return DB::all(
            'SELECT ba.id, ba.attraction_id, ba.start_time, ba.end_time, a.name, a.requires_waiver
               FROM {booking_attractions} ba
               JOIN {attractions} a ON a.id = ba.attraction_id
              WHERE ba.booking_id = :id
              ORDER BY ba.sort_order, ba.id',
            ['id' => $bookingId]
        );
    }

    /** @return list<array<string,mixed>> */
    private function guests(int $bookingId): array
    {
        return DB::all(
            'SELECT id, name, age, parent_name, parent_phone, rsvp_status, checked_in_at, waiver_id
               FROM {booking_guests}
              WHERE booking_id = :id
              ORDER BY name
              LIMIT ' . self::MAX_GUESTS_LISTED,
            ['id' => $bookingId]
        );
    }

    /** @return list<array<string,mixed>> */
    private function tasks(int $bookingId): array
    {
        return DB::all(
            'SELECT bt.id, bt.title, bt.assigned_to, bt.due_at, bt.completed_at, u.name AS assignee
               FROM {booking_tasks} bt
               LEFT JOIN {users} u ON u.id = bt.assigned_to
              WHERE bt.booking_id = :id
              ORDER BY bt.completed_at IS NOT NULL, bt.sort_order, bt.id',
            ['id' => $bookingId]
        );
    }

    /** @return list<array<string,mixed>> */
    private function payments(int $bookingId): array
    {
        return DB::all(
            'SELECT bp.id, bp.amount_cents, bp.type, bp.method, bp.reference, bp.occurred_at,
                    bp.note, u.name AS recorded_by_name
               FROM {booking_payments} bp
               LEFT JOIN {users} u ON u.id = bp.recorded_by
              WHERE bp.booking_id = :id
              ORDER BY bp.occurred_at, bp.id',
            ['id' => $bookingId]
        );
    }

    /**
     * How far through the waivers this party is.
     *
     * @return array{required:bool,signed:int,guests:int,attractions:list<string>}
     */
    private function waiverProgress(int $bookingId): array
    {
        $needs = DB::all(
            'SELECT a.name
               FROM {booking_attractions} ba
               JOIN {attractions} a ON a.id = ba.attraction_id
              WHERE ba.booking_id = :id AND a.requires_waiver = 1',
            ['id' => $bookingId]
        );

        return [
            'required'    => $needs !== [],
            'attractions' => array_map(static fn (array $r): string => (string) $r['name'], $needs),
            'signed'      => (int) DB::value(
                'SELECT COUNT(*) FROM {waiver_signatures} WHERE booking_id = :id',
                ['id' => $bookingId]
            ),
            'guests'      => (int) DB::value(
                'SELECT COUNT(*) FROM {booking_guests} WHERE booking_id = :id',
                ['id' => $bookingId]
            ),
        ];
    }

    /** @return array<string,mixed> */
    private function formData(?array $package): array
    {
        return [
            'types'       => self::TYPES,
            'packages'    => DB::all(
                'SELECT id, name, base_price_cents, included_guests, additional_guest_cents,
                        duration_minutes, min_notice_days, deposit_cents, deposit_percent,
                        main_attraction_id
                   FROM {party_packages} WHERE is_active = 1 ORDER BY sort_order, name'
            ),
            'addonCatalogue' => DB::all(
                'SELECT id, name, price_cents, unit, description
                   FROM {package_addons} WHERE is_active = 1 ORDER BY sort_order, name'
            ),
            'attractionList' => DB::all(
                'SELECT id, name, requires_waiver FROM {attractions}
                  WHERE is_active = 1 AND is_party_eligible = 1 ORDER BY sort_order, name'
            ),
            'contacts'    => $this->contactOptions(),
            'hosts'       => $this->hostOptions(),
            'package'     => $package,
            'taxRate'     => BookingRepository::taxRate(),
            'taxLabel'    => QuoteCalculator::rateLabel(BookingRepository::taxRate()),
            'showMoney'   => Acl::can('view_revenue'),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function contactOptions(): array
    {
        return DB::all(
            'SELECT id, first_name, last_name, email
               FROM {contacts}
              WHERE deleted_at IS NULL
              ORDER BY COALESCE(updated_at, created_at) DESC, id DESC
              LIMIT 200'
        );
    }

    /** @return list<array<string,mixed>> */
    private function hostOptions(): array
    {
        return DB::all(
            'SELECT id, name FROM {users} WHERE is_active = 1 AND deleted_at IS NULL ORDER BY name'
        );
    }

    /** @return array<string,string> */
    private function venue(): array
    {
        return [
            'name'    => Settings::venueName(),
            'phone'   => (string) Settings::get('venue.phone_display', ''),
            'address' => trim(implode(', ', array_filter([
                (string) Settings::get('venue.address_1', ''),
                (string) Settings::get('venue.city', ''),
                (string) Settings::get('venue.state', ''),
                (string) Settings::get('venue.postal_code', ''),
            ]))),
        ];
    }

    // =================================================================
    // Filtering, summarising, scrubbing
    // =================================================================

    /** @return array<string,mixed> */
    private function filterValues(Request $request): array
    {
        $status = $request->str('status');
        $type   = $request->str('type');
        $view   = $request->str('view', 'upcoming');

        return [
            'q'      => trim($request->str('q')),
            'status' => in_array($status, self::STATUSES, true) ? $status : '',
            'type'   => in_array($type, self::TYPES, true) ? $type : '',
            'host'   => max(0, $request->int('host', 0)),
            'view'   => in_array($view, ['upcoming', 'today', 'past', 'all', 'unassigned'], true)
                ? $view
                : 'upcoming',
            'past'   => $view === 'past',
        ];
    }

    /**
     * The WHERE both the list and its summary share.
     *
     * Returned as `$filter` at every call site rather than `$where`, which
     * reads better and is also the only spelling the interpolated-SQL gate in
     * tests/static accepts: it matches `\bWHERE\b` case-insensitively, so a
     * variable called `$where` on a line that also carries a quoted string and
     * one other variable reads to it as interpolated SQL. DealController hit
     * the same thing.
     *
     * @param array<string,mixed> $filters
     * @return array{0:string,1:array<string,mixed>}
     */
    private function bookingFilter(array $filters): array
    {
        $clauses = ['b.deleted_at IS NULL'];
        $params  = [];
        $today   = BookingRepository::today();

        switch ($filters['view']) {
            case 'today':
                $clauses[]        = 'b.event_date = :today';
                $params['today']  = $today;
                break;
            case 'past':
                $clauses[]        = 'b.event_date < :today';
                $params['today']  = $today;
                break;
            case 'unassigned':
                $clauses[]        = 'b.host_user_id IS NULL AND b.event_date >= :today';
                $params['today']  = $today;
                break;
            case 'all':
                break;
            default:
                $clauses[]        = 'b.event_date >= :today';
                $params['today']  = $today;
                break;
        }

        if ($filters['status'] !== '') {
            $clauses[]         = 'b.status = :status';
            $params['status']  = $filters['status'];
        }

        if ($filters['type'] !== '') {
            $clauses[]       = 'b.type = :type';
            $params['type']  = $filters['type'];
        }

        if ($filters['host'] > 0) {
            $clauses[]       = 'b.host_user_id = :host';
            $params['host']  = $filters['host'];
        }

        if ($filters['q'] !== '') {
            // A DISTINCT NAME PER OCCURRENCE. Emulated prepares are off, and
            // the native driver answers HY093 to a repeated named parameter —
            // which is how every search in the deals and tasks modules became
            // a 500 before anybody searched with a term. See docs/TESTING.md.
            $like = '%' . $filters['q'] . '%';

            $clauses[] = '(b.booking_number LIKE :q_number'
                . ' OR b.guest_of_honor_name LIKE :q_honoree'
                . ' OR EXISTS (SELECT 1 FROM {contacts} fc WHERE fc.id = b.contact_id'
                . ' AND (fc.first_name LIKE :q_first OR fc.last_name LIKE :q_last)))';

            $params['q_number']  = $like;
            $params['q_honoree'] = $like;
            $params['q_first']   = $like;
            $params['q_last']    = $like;
        }

        return [implode(' AND ', $clauses), $params];
    }

    /**
     * The figures above the list.
     *
     * @param array<string,mixed> $params
     * @return array<string,int>
     */
    private function summary(string $filter, array $params): array
    {
        $row = DB::first(
            'SELECT COUNT(*) AS bookings,
                    COALESCE(SUM(b.guest_count), 0) AS guests,
                    COALESCE(SUM(b.total_cents), 0) AS total_cents,
                    COALESCE(SUM(b.balance_due_cents), 0) AS balance_cents
               FROM {bookings} b WHERE ' . $filter,
            $params
        ) ?? [];

        $summary = [
            'bookings' => (int) ($row['bookings'] ?? 0),
            'guests'   => (int) ($row['guests'] ?? 0),
        ];

        // Money only for roles that may see it, as everywhere else.
        if (Acl::can('view_revenue')) {
            $summary['total_cents']   = (int) ($row['total_cents'] ?? 0);
            $summary['balance_cents'] = (int) ($row['balance_cents'] ?? 0);
        }

        return $summary;
    }

    /**
     * Remove money from a row for a role without `view_revenue`.
     *
     * Server-side, like every other dollar in this application: hiding a
     * column in a view is not a permission.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function scrubRow(array $row): array
    {
        if (Acl::can('view_revenue')) {
            return $row;
        }

        foreach ([
            'subtotal_cents', 'discount_cents', 'tax_cents', 'total_cents',
            'deposit_cents', 'balance_due_cents', 'additional_guest_cents',
        ] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = null;
            }
        }

        return $row;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function scrubRows(array $rows): array
    {
        if (Acl::can('view_revenue')) {
            return $rows;
        }

        return array_map([$this, 'scrubRow'], $rows);
    }

    /** @param array<string,mixed> $read */
    private function unavailableMessage(array $read): string
    {
        $check = BookingRepository::check(
            (string) $read['columns']['event_date'],
            (int) $read['start_minutes'],
            $read['package'],
            (int) $read['columns']['guest_count'],
            null,
            (string) $read['resource_type']
        );

        $message = (string) ($check['message'] ?? 'That slot is not available.');
        $slots   = $check['slots'] ?? [];

        if ($slots === []) {
            return $message;
        }

        $times = array_slice(array_column($slots, 'start_label'), 0, 6);

        return $message . ' Free that day: ' . implode(', ', $times) . '.';
    }

    /** @param array<string,mixed> $booking */
    private function frozenMessage(array $booking): string
    {
        return 'Booking ' . $booking['booking_number'] . ' is ' . str_replace('_', ' ', (string) $booking['status'])
            . ', so its figures are settled. Move it back before re-pricing it.';
    }

    /**
     * @param array<string,mixed> $booking
     * @return array<string,mixed>
     */
    private function auditSnapshot(array $booking): array
    {
        return [
            'status'      => $booking['status'] ?? null,
            'event_date'  => $booking['event_date'] ?? null,
            'start_time'  => $booking['start_time'] ?? null,
            'guest_count' => $booking['guest_count'] ?? null,
            'total_cents' => $booking['total_cents'] ?? null,
            'package_id'  => $booking['package_id'] ?? null,
        ];
    }

    private function nullableId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === '0') {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }
}
