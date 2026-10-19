<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\DB;
use App\Core\Event;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Services\AvailabilityEngine;
use App\Services\BookingRepository;
use App\Services\QuoteCalculator;
use PDOException;

/**
 * Asking for a party, from the venue's own website.
 *
 * `docs/SPEC.md` §8.5 describes the public booking form as the thing that
 * turns the availability engine's refusals into "either alternative slots or
 * the please-call fallback", and that sentence is the whole design.
 *
 * IT IS OFF UNTIL THE VENUE TURNS IT ON. A fresh install does not take
 * bookings from the internet: `bookings.public_enabled` defaults to false and
 * the route answers 404 until somebody decides otherwise. That is not
 * timidity — a venue that has not yet checked its packages, prices and
 * opening hours should not be accepting commitments against them.
 *
 * AN ENQUIRY HOLDS THE ROOM. It is taken at `inquiry`, which is a live status,
 * so the room is really held and the family is not told yes and then phoned
 * back with a no. The cost of that is obvious — a stranger can tie a room up —
 * so the exposure is bounded two ways: the route is rate limited, and a date
 * that already carries PUBLIC_MAX_PER_DAY unanswered public enquiries stops
 * taking them and shows the phone number instead. A bot cannot blank the
 * diary; at worst it fills one day's allowance and the venue gets a phone
 * call, which is where this page's fallback leads anyway.
 *
 * NOTHING IS CHARGED AND NOTHING IS PROMISED. The page shows what the party
 * would cost, worked out by the same QuoteCalculator the office uses, and says
 * plainly that the venue will be in touch to confirm it and take the deposit.
 * No card details are collected here or anywhere else in this application.
 *
 * AN ENQUIRY IS NOT AN OPT-IN. Phase 2's rule, and it holds here: marketing
 * consent comes only from a ticked box, with its time, source and the wording
 * the guest saw, and never for a channel the guest cannot be reached on.
 */
final class PublicBookingController extends Controller
{
    /** Unanswered public enquiries one date will take before it closes. */
    public const PUBLIC_MAX_PER_DAY = 6;

    /** How far ahead the page will look. A year is further than any family plans. */
    public const MAX_DAYS_AHEAD = 365;

    /** The field no human is ever shown. */
    public const HONEYPOT = 'company_website';

    /** Marketing wording shown beside the opt-in, stored as its evidence. */
    public const OPT_IN_TEXT = 'Email me about offers, events and party ideas at the venue. '
        . 'You can unsubscribe from any message.';

    public function show(Request $request): Response
    {
        if (!BookingRepository::publicEnabled()) {
            return $this->closed();
        }

        $packages = $this->packages();

        if ($packages === []) {
            // Nothing to ask for. The venue has turned the page on without
            // setting a package up, and offering an empty form would take an
            // enquiry nobody can price.
            return $this->closed();
        }

        $package = $this->chosenPackage($packages, $request->int('package_id', 0));
        $guests  = $this->chosenGuests($request, $package);
        $date    = $request->str('date');
        $date    = AvailabilityEngine::isDate($date) ? $date : '';

        $availability = $this->availability($package, $guests, $date);

        return $this->page(
            $package,
            $packages,
            $guests,
            $date,
            $availability,
            $this->chosenStart($request, $availability)
        );
    }

    public function store(Request $request): Response
    {
        if (!BookingRepository::publicEnabled()) {
            return $this->closed();
        }

        $packages = $this->packages();

        if ($packages === []) {
            return $this->closed();
        }

        // Spam check one: the honeypot. A field no human is shown, so anything
        // in it came from something filling in every input on the page. The
        // rate limit on the route is check two.
        if (trim($request->str(self::HONEYPOT)) !== '') {
            // Answered as if it worked. Telling a bot it was caught teaches it
            // how to pass, and this page has nothing to store for one.
            return $this->thanks(null, null);
        }

        $package = $this->chosenPackage($packages, $request->int('package_id', 0));
        $guests  = $this->chosenGuests($request, $package);
        $date    = $request->str('date');

        $name  = trim($request->str('name'));
        $email = trim($request->str('email'));
        $phone = trim($request->str('phone'));

        $errors = [];

        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors['name'] = 'Please tell us your name.';
        }

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'That does not look like an email address.';
        }

        if ($email === '' && $phone === '') {
            $errors['email'] = 'Please leave an email address or a phone number so we can reply.';
        }

        if (!AvailabilityEngine::isDate($date)) {
            $errors['date'] = 'Please choose a date.';
        }

        $start = AvailabilityEngine::minutes($request->str('start_time'));

        if ($start === null) {
            $errors['start_time'] = 'Please choose a time.';
        }

        if ($errors !== []) {
            return $this->refusal($request, $errors);
        }

        if (AvailabilityEngine::daysBetween(BookingRepository::today(), $date) > self::MAX_DAYS_AHEAD) {
            return $this->refusal($request, [
                'date' => 'We only take bookings up to a year ahead. Please give us a ring for anything further out.',
            ]);
        }

        // The day's allowance. Checked before the availability pass so a date
        // that is closed to the public says so rather than offering times it
        // will then refuse.
        if ($this->pendingOnDate($date) >= self::PUBLIC_MAX_PER_DAY) {
            return $this->refusal($request, [
                '_form' => 'We have had a lot of enquiries for that date and would rather talk it '
                    . 'through. ' . $this->callSentence(),
            ]);
        }

        $resourceType = BookingRepository::resourceTypeFor($package, $guests);

        try {
            $outcome = DB::transaction(function () use (
                $package,
                $guests,
                $date,
                $start,
                $name,
                $email,
                $phone,
                $resourceType,
                $request
            ): array {
                // Re-checked inside the transaction, like the office form: the
                // times this page offered were the times when it was drawn.
                // The rooms are LOCKED first, for the same reason the office
                // form locks them — without it two families clicking the last
                // two o'clock in the same second both get it, and the check
                // above each of their writes honestly said yes.
                $this->lockRooms($resourceType);

                $check = BookingRepository::check($date, $start, $package, $guests, null, $resourceType);

                if (($check['ok'] ?? false) !== true) {
                    return ['refused' => $check];
                }

                $contactId = $this->resolveContact($name, $email, $phone);

                $priced = QuoteCalculator::price($package, $guests, [], [
                    'tax_rate' => BookingRepository::taxRate(),
                ]);

                $bookingId = $this->insert($package, $priced, [
                    'contact_id'  => $contactId,
                    'event_date'  => $date,
                    'start'       => $start,
                    'guests'      => $guests,
                    'honoree'     => trim($request->str('guest_of_honor_name')),
                    'honoree_age' => $request->int('guest_of_honor_age', 0),
                    'allergies'   => trim($request->str('allergies')),
                    'requests'    => trim($request->str('special_requests')),
                ]);

                if ($bookingId === null) {
                    return ['refused' => ['message' => 'Something went wrong taking that down.']];
                }

                BookingRepository::allocate(
                    $bookingId,
                    $date,
                    $start,
                    $start + (int) $package['duration_minutes'],
                    array_slice($check['slot']['resource_ids'] ?? [], 0, 1)
                );

                // An enquiry is not an opt-in. Only a ticked box grants
                // anything, and only for a channel the guest can be reached
                // on — the same rule, and the same guard, as the web forms.
                if ($contactId !== null && $request->str('opt_in') !== '') {
                    $this->recordConsent($contactId, $request);
                }

                return ['booking_id' => $bookingId, 'priced' => $priced, 'contact_id' => $contactId];
            });
        } catch (PDOException) {
            return $this->refusal($request, [
                '_form' => 'Something went wrong taking that down. Please try again, or '
                    . lcfirst($this->callSentence('give us a')),
            ]);
        }

        if (isset($outcome['refused'])) {
            $refusal = $outcome['refused'];

            return $this->refusal($request, [
                '_form' => (string) ($refusal['message'] ?? 'That time has just gone.')
                    . ' Please pick another time below.',
            ], false);
        }

        $booking = DB::first(
            'SELECT booking_number, event_date, start_time, guest_count, total_cents, deposit_cents
               FROM {bookings} WHERE id = :id',
            ['id' => (int) $outcome['booking_id']]
        );

        Event::fire('booking.enquiry_received', [
            'booking_id' => (int) $outcome['booking_id'],
            'source'     => 'public_form',
        ]);
        Audit::event('booking.public_enquiry', [
            'booking'     => (string) ($booking['booking_number'] ?? ''),
            'event_date'  => $date,
            'guest_count' => $guests,
        ], 'bookings', (int) $outcome['booking_id']);

        return $this->thanks($booking, $package);
    }

    // =================================================================
    // Rendering
    // =================================================================

    /**
     * @param array<string,mixed>       $package
     * @param list<array<string,mixed>> $packages
     * @param array<string,mixed>|null  $availability
     */
    private function page(
        array $package,
        array $packages,
        int $guests,
        string $date,
        ?array $availability,
        ?int $start = null
    ): Response {
        return $this->publicResponse($this->view('public/book', [
            'pageTitle'   => 'Book a party',
            'bareWide'    => true,
            'venueName'   => Settings::venueName(),
            'venuePhone'  => $this->venuePhone(),
            'callSentence' => $this->callSentence(),
            'packages'    => $packages,
            'package'     => $package,
            'guests'      => $guests,
            'date'        => $date,
            'today'       => BookingRepository::today(),
            'maxDate'     => AvailabilityEngine::addDays(BookingRepository::today(), self::MAX_DAYS_AHEAD),
            'availability' => $availability,
            'alternatives' => $this->alternatives($availability, $package, $guests, $date),
            'priced'      => QuoteCalculator::price($package, $guests, [], [
                'tax_rate' => BookingRepository::taxRate(),
            ]),
            'honeypot'    => self::HONEYPOT,
            'optInText'   => self::OPT_IN_TEXT,
            'sent'        => false,
            'booking'     => null,
            'start'       => $start,
            // "14:00", not the TIME column's "14:00:00": this goes into a URL
            // and a hidden field, and the slot links are built the same way, so
            // a chosen time compares equal however the page was reached.
            'startValue'  => $start === null ? '' : substr(AvailabilityEngine::timeValue($start), 0, 5),
            'startLabel'  => $start === null ? '' : AvailabilityEngine::timeLabel($start),
            'duration'    => AvailabilityEngine::durationLabel((int) $package['duration_minutes']),
        ], 'layouts/bare'));
    }

    /**
     * The time the guest has picked, if it is one this page actually offered.
     *
     * Taken from the query string, because the slot list is a set of LINKS
     * rather than a form full of submit buttons: a button named `start_time`
     * sitting inside the same form as a time input is overridden by the input,
     * which is how the office form's slot picker silently did nothing until a
     * browser walk caught it.
     *
     * Validated against the offered slots, so `?start=03:00` on a day that
     * closes at two cannot reach the details form and be refused after the
     * guest has typed everything in.
     *
     * @param array<string,mixed>|null $availability
     */
    private function chosenStart(Request $request, ?array $availability): ?int
    {
        $asked = AvailabilityEngine::minutes($request->str('start'));

        if ($asked === null || $availability === null || ($availability['ok'] ?? false) !== true) {
            return null;
        }

        foreach ($availability['slots'] ?? [] as $slot) {
            if ((int) $slot['start'] === $asked) {
                return $asked;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed>|null $booking
     * @param array<string,mixed>|null $package
     */
    private function thanks(?array $booking, ?array $package): Response
    {
        return $this->publicResponse($this->view('public/book', [
            'pageTitle'   => 'Thank you',
            'bareWide'    => false,
            'venueName'   => Settings::venueName(),
            'venuePhone'  => $this->venuePhone(),
            'callSentence' => $this->callSentence(),
            'packages'    => [],
            'package'     => $package,
            'guests'      => 0,
            'date'        => '',
            'today'       => BookingRepository::today(),
            'maxDate'     => '',
            'availability' => null,
            'alternatives' => [],
            'priced'      => null,
            'honeypot'    => self::HONEYPOT,
            'optInText'   => self::OPT_IN_TEXT,
            'sent'        => true,
            'booking'     => $booking,
            'start'       => null,
            'startValue'  => '',
            'startLabel'  => '',
            'duration'    => '',
        ], 'layouts/bare'));
    }

    /**
     * One answer for every reason this page will not serve.
     *
     * Deliberately identical whether the page is switched off, has no
     * packages, or does not exist: a different message for each would tell
     * anybody poking at the URL something about the venue's configuration.
     */
    private function closed(): Response
    {
        return $this->publicResponse(
            Response::html(\App\Core\View::renderError(404, 'Not found', [
                'message' => 'There is nothing to see here.',
            ]), 404)
        );
    }

    /**
     * Send them back to the form with the message and what they typed.
     *
     * $keepTime is false for exactly one case — the slot went while they were
     * typing — because sending them back to a time that no longer exists
     * would show them the details form again and refuse it again.
     *
     * @param array<string,string> $errors
     */
    private function refusal(Request $request, array $errors, bool $keepTime = true): Response
    {
        Session::put('_errors', array_map(static fn (string $m): array => [$m], $errors));

        $input = $request->all();
        unset($input['_token'], $input[self::HONEYPOT]);

        Session::put('_old_input_next', $input);

        flash('error', (string) reset($errors));

        // A redirect, so the flashed messages are read by the request that
        // renders the form again — Session::put() writes for the NEXT request.
        $start = AvailabilityEngine::minutes($request->str('start_time'));

        $query = array_filter([
            'package_id'  => (string) $request->int('package_id', 0),
            'date'        => $request->str('date'),
            'guest_count' => (string) $request->int('guest_count', 0),
            'start'       => $keepTime && $start !== null ? AvailabilityEngine::timeValue($start) : '',
        ], static fn (string $value): bool => $value !== '' && $value !== '0');

        return $this->publicResponse($this->redirect(
            '/book' . ($query === [] ? '' : '?' . http_build_query($query))
        ));
    }

    private function publicResponse(Response $response): Response
    {
        return $response->header('X-Robots-Tag', 'noindex, nofollow')->noCache();
    }

    // =================================================================
    // Reading
    // =================================================================

    /** @return list<array<string,mixed>> */
    private function packages(): array
    {
        return DB::all(
            'SELECT id, name, slug, description, base_price_cents, included_guests,
                    additional_guest_cents, duration_minutes, min_notice_days,
                    deposit_cents, deposit_percent, main_attraction_id, includes
               FROM {party_packages}
              WHERE is_active = 1 AND is_online = 1
              ORDER BY sort_order, name'
        );
    }

    /**
     * @param list<array<string,mixed>> $packages
     * @return array<string,mixed>
     */
    private function chosenPackage(array $packages, int $id): array
    {
        foreach ($packages as $package) {
            if ((int) $package['id'] === $id) {
                return $package;
            }
        }

        return $packages[0];
    }

    /** @param array<string,mixed> $package */
    private function chosenGuests(Request $request, array $package): int
    {
        $asked = $request->int('guest_count', 0);

        if ($asked <= 0) {
            return (int) $package['included_guests'];
        }

        return max(1, min(QuoteCalculator::MAX_GUESTS, $asked));
    }

    /**
     * @param array<string,mixed> $package
     * @return array<string,mixed>|null
     */
    private function availability(array $package, int $guests, string $date): ?array
    {
        if ($date === '') {
            return null;
        }

        // TOO FAR AHEAD IS REFUSED HERE AS WELL AS AT THE WRITE, and the page
        // is the one that matters to a family. The date input caps itself, but
        // a hand-typed or bookmarked URL does not, and without this the page
        // cheerfully offered times for a party eighteen months out and then
        // refused the enquiry once the form was filled in — the same "show
        // them the form, then say no" the `?start=` validation exists to
        // prevent. The guard in store() stays: it is the one that cannot be
        // walked around.
        if (AvailabilityEngine::daysBetween(BookingRepository::today(), $date) > self::MAX_DAYS_AHEAD) {
            return [
                'ok'      => false,
                'date'    => $date,
                'reason'  => 'too_far',
                'message' => 'We only take bookings up to a year ahead, so for anything further out '
                    . lcfirst($this->callSentence('please give us a')),
                'slots'   => [],
                'taken'   => [],
            ];
        }

        if ($this->pendingOnDate($date) >= self::PUBLIC_MAX_PER_DAY) {
            // The day's allowance is spent. Shaped like the engine's own
            // refusals so the view has one thing to render.
            return [
                'ok'      => false,
                'date'    => $date,
                'reason'  => 'fully_booked',
                'message' => 'We have had a lot of enquiries for that date and would rather talk it '
                    . 'through with you.',
                'slots'   => [],
                'taken'   => [],
            ];
        }

        return AvailabilityEngine::day(BookingRepository::inputFor(
            $date,
            $package,
            $guests,
            null,
            BookingRepository::resourceTypeFor($package, $guests)
        ));
    }

    /**
     * Dates near the one asked for that WOULD work — the useful half of a "no".
     *
     * CLAMPED TO WHAT THIS PAGE WILL ACTUALLY TAKE. The repository searches
     * forward from the requested date, so a fully-booked Saturday eleven
     * months out would otherwise be answered with three dates past the
     * one-year limit — offers the page refuses the moment one is clicked. For
     * a date that is itself too far ahead every candidate is too, which empties
     * the list and leaves the please-call fallback, which is the right answer
     * there.
     *
     * @param array<string,mixed>|null $availability
     * @param array<string,mixed>      $package
     * @return list<array<string,mixed>>
     */
    private function alternatives(?array $availability, array $package, int $guests, string $date): array
    {
        if ($availability === null || ($availability['ok'] ?? false) === true) {
            return [];
        }

        $found = BookingRepository::alternatives(
            $date,
            $package,
            $guests,
            3,
            BookingRepository::resourceTypeFor($package, $guests)
        );

        return array_values(array_filter(
            $found,
            fn (array $row): bool => AvailabilityEngine::daysBetween(
                BookingRepository::today(),
                (string) $row['date']
            ) <= self::MAX_DAYS_AHEAD
        ));
    }

    /**
     * Unanswered public enquiries already on this date.
     *
     * `source` is what distinguishes them: an enquiry the office took by
     * phone is not part of the public allowance, and a party the venue has
     * confirmed is no longer an unanswered enquiry.
     */
    private function pendingOnDate(string $date): int
    {
        return (int) DB::value(
            'SELECT COUNT(*) FROM {bookings}
              WHERE event_date = :date AND deleted_at IS NULL
                AND status = \'inquiry\' AND source = \'public_form\'',
            ['date' => $date]
        );
    }

    /**
     * Hold the rooms this enquiry could land in for the rest of the transaction.
     *
     * A FOR UPDATE on the resource rows, exactly as BookingController does:
     * every allocation write goes through a booking whose rooms were locked
     * here first, so the availability check and the insert that follows it see
     * the same world. Locking the whole TYPE rather than the engine's chosen
     * subset is deliberate — the subset is not known until after the check,
     * which is the thing being protected.
     */
    private function lockRooms(string $type): void
    {
        DB::all(
            'SELECT id FROM {resources}
              WHERE type = :type AND is_active = 1
              ORDER BY id FOR UPDATE',
            ['type' => $type]
        );
    }


    // =================================================================
    // Writing
    // =================================================================

    /**
     * The guest this enquiry belongs to, matched or created.
     *
     * Same identity rules as the web forms: email first, phone only when
     * there is no email, and never as a tie-break — a household landline or a
     * parent's mobile on a child's form would otherwise attach an enquiry to
     * the wrong person. No consent column is touched here; see recordConsent().
     */
    private function resolveContact(string $name, string $email, string $phone): ?int
    {
        $email  = strtolower($email);
        $normal = normalize_phone($phone === '' ? null : $phone);

        if ($email === '' && $normal === null) {
            return null;
        }

        $existing = null;

        if ($email !== '') {
            $existing = DB::first(
                'SELECT id, first_name, last_name, phone FROM {contacts}
                  WHERE email = :e AND deleted_at IS NULL ORDER BY id LIMIT 1',
                ['e' => $email]
            );
        }

        if ($existing === null && $email === '' && $normal !== null) {
            $existing = DB::first(
                'SELECT id, first_name, last_name, phone FROM {contacts}
                  WHERE phone_normalized = :p AND deleted_at IS NULL ORDER BY id LIMIT 1',
                ['p' => $normal]
            );
        }

        [$first, $last] = $this->splitName($name);

        if ($existing !== null) {
            // Only ever FILLS blanks. A guest typing their name differently
            // today does not get their record overwritten.
            $fill = [];

            if (trim((string) ($existing['first_name'] ?? '')) === '' && $first !== '') {
                $fill['first_name'] = $first;
            }

            if (trim((string) ($existing['last_name'] ?? '')) === '' && $last !== '') {
                $fill['last_name'] = $last;
            }

            if (trim((string) ($existing['phone'] ?? '')) === '' && $phone !== '') {
                $fill['phone']            = $phone;
                $fill['phone_normalized'] = $normal;
            }

            if ($fill !== []) {
                $fill['updated_at'] = now_utc();

                DB::update('contacts', $fill, 'id = :id', ['id' => (int) $existing['id']]);
            }

            return (int) $existing['id'];
        }

        $id = DB::insert('contacts', [
            'first_name'       => $first === '' ? null : $first,
            'last_name'        => $last === '' ? null : $last,
            'email'            => $email === '' ? null : $email,
            'phone'            => $phone === '' ? null : $phone,
            'phone_normalized' => $normal,
            'lifecycle_stage'  => 'lead',
            'source'           => 'public_booking',
            'created_at'       => now_utc(),
            'updated_at'       => now_utc(),
        ]);

        Event::fire('contact.created', ['contact_id' => $id, 'source' => 'public_booking']);

        return $id;
    }

    /**
     * @param array<string,mixed> $package
     * @param array<string,mixed> $priced
     * @param array<string,mixed> $details
     */
    private function insert(array $package, array $priced, array $details): ?int
    {
        $start    = (int) $details['start'];
        $duration = (int) $package['duration_minutes'];

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return DB::insert('bookings', [
                    'booking_number'      => $this->nextNumber(),
                    'type'                => 'birthday',
                    // `inquiry` is a LIVE status, so the room is really held.
                    // Nothing here confirms anything: confirming, pricing
                    // changes and the deposit are the office's decisions.
                    'status'              => 'inquiry',
                    'package_id'          => (int) $package['id'],
                    'contact_id'          => $details['contact_id'],
                    'event_date'          => (string) $details['event_date'],
                    'start_time'          => AvailabilityEngine::timeValue($start),
                    'end_time'            => AvailabilityEngine::timeValue($start + $duration),
                    'guest_count'         => (int) $details['guests'],
                    'guest_of_honor_name' => $details['honoree'] === '' ? null : mb_substr($details['honoree'], 0, 120),
                    'guest_of_honor_age'  => (int) $details['honoree_age'] > 0
                        ? min(120, (int) $details['honoree_age'])
                        : null,
                    'allergies'           => $details['allergies'] === ''
                        ? null
                        : mb_substr($details['allergies'], 0, 500),
                    'special_requests'    => $details['requests'] === ''
                        ? null
                        : mb_substr($details['requests'], 0, 5000),
                    // What distinguishes a public enquiry from one the office
                    // took, which is what the daily allowance counts.
                    'source'              => 'public_form',
                    'subtotal_cents'      => (int) $priced['subtotal_cents'],
                    'discount_cents'      => 0,
                    'tax_cents'           => (int) $priced['tax_cents'],
                    'total_cents'         => (int) $priced['total_cents'],
                    'deposit_cents'       => (int) $priced['deposit_cents'],
                    'balance_due_cents'   => (int) $priced['total_cents'],
                    'created_at'          => now_utc(),
                    'updated_at'          => now_utc(),
                ]);
            } catch (PDOException $exception) {
                if ((int) ($exception->errorInfo[1] ?? 0) !== 1062 || $attempt === 4) {
                    throw $exception;
                }
            }
        }

        return null;
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

    /**
     * Record a ticked opt-in.
     *
     * The web forms' rule, and the same guard: consent for a channel with no
     * address behind it is a permission nobody can exercise that still reads
     * as a marketable guest. It never clears a do_not_contact block either.
     */
    private function recordConsent(int $contactId, Request $request): void
    {
        $reach = DB::first('SELECT email FROM {contacts} WHERE id = :id', ['id' => $contactId]) ?? [];

        if (trim((string) ($reach['email'] ?? '')) === '') {
            return;
        }

        DB::update('contacts', [
            'email_consent'    => 1,
            'email_consent_at' => now_utc(),
            'updated_at'       => now_utc(),
        ], 'id = :id', ['id' => $contactId]);

        DB::insert('consent_log', [
            'contact_id' => $contactId,
            'channel'    => 'email',
            'action'     => 'granted',
            'source'     => 'public booking form',
            'ip'         => $request->ip(),
            'user_agent' => mb_substr($request->userAgent(), 0, 500),
            // The wording they were shown, which is what a defence rests on.
            'evidence'   => json_encode([
                'disclosure' => self::OPT_IN_TEXT,
                'method'     => 'public_booking_form',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at' => now_utc(),
        ]);
    }

    /**
     * A typed name split into two columns.
     *
     * Last word is the surname, everything before it the first name. Wrong for
     * plenty of names, which is why the whole string is kept in first_name
     * when there is only one word rather than guessing a surname.
     *
     * @return array{0:string,1:string}
     */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        if (count($parts) < 2) {
            return [mb_substr($name, 0, 80), ''];
        }

        $last = (string) array_pop($parts);

        return [mb_substr(implode(' ', $parts), 0, 80), mb_substr($last, 0, 80)];
    }

    /**
     * The venue's number, or an empty string when nobody has set one.
     *
     * EMPTY, not a placeholder. It used to answer "the venue", which read
     * perfectly in isolation and produced "please give us a ring on the venue"
     * in every sentence that used it — this page's whole fallback. A caller
     * that has to handle the absence is better than a value that hides it; see
     * callSentence().
     */
    private function venuePhone(): string
    {
        return trim((string) Settings::get('venue.phone_display', ''));
    }

    /**
     * "Please give us a ring on (845) 469-2116." — or, with no number stored,
     * "Please give us a call."
     *
     * One place, because this sentence is the end of every refusal on this
     * page and an install that has not filled its phone number in should still
     * read like English. It is a whole sentence rather than a fragment so the
     * grammar cannot come apart at the join.
     */
    private function callSentence(string $lead = 'Please give us a'): string
    {
        $phone = $this->venuePhone();

        return $phone === ''
            ? $lead . ' call.'
            : $lead . ' ring on ' . $phone . '.';
    }
}
