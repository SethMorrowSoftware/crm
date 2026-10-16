<?php

declare(strict_types=1);

/**
 * The public party-enquiry page, end to end over HTTP.
 *
 * This is the only page in the application where a stranger on the internet
 * WRITES a row that holds a physical room, so the things worth testing are not
 * the happy path — they are the six ways that privilege could be abused or go
 * quietly wrong:
 *
 *   1. **IT IS OFF UNTIL THE VENUE TURNS IT ON**, and off means the same 404
 *      as "there is no such page". A fresh install must not be taking
 *      commitments against packages and prices nobody has checked.
 *   2. **THE FIGURES ARE DERIVED, NOT POSTED.** A crafted `total_cents` in the
 *      body must not become the price — the same rule as the office form, and
 *      far more exposed here.
 *   3. **THE SLOT IS RE-CHECKED AT THE WRITE**, inside the transaction, with
 *      the rooms locked. The times this page offered were the times when it
 *      was drawn, and two families can click the same one.
 *   4. **AN ENQUIRY REALLY HOLDS THE ROOM.** `inquiry` is a live status on
 *      purpose: a family told yes and then phoned back with a no is worse than
 *      a family told no. The cost is that a stranger can tie a room up, which
 *      is what the daily allowance bounds.
 *   5. **AN ENQUIRY IS NOT AN OPT-IN.** Consent comes from a ticked box, for a
 *      channel the guest can actually be reached on, with the wording they
 *      saw recorded as evidence. Phase 2's rule, tested again here because
 *      this page writes contacts by a different path.
 *   6. **A REFUSAL IS USEFUL.** The spec's own sentence for this page is that
 *      a "no" becomes either alternative dates or the please-call fallback.
 *
 * Every refusal test has its positive control beside it: a page that answered
 * 404 to everything, or a controller that wrote nothing at all, would satisfy
 * half of these perfectly.
 *
 * ONE THING HERE IS NOT TESTED, and it is better to say so than to imply
 * otherwise: the `FOR UPDATE` lock the write takes over the candidate rooms.
 * A sequential suite cannot produce two writes interleaved inside one
 * transaction, so removing the lock leaves every test below green — the
 * re-check still refuses the second enquiry because the first one has already
 * committed. What the tests pin is that the re-check happens AT THE WRITE
 * rather than only when the page was drawn; the lock is what makes that
 * re-check sound under real concurrency, and it is reviewed, not measured.
 * BookingController carries the same lock for the same reason.
 */

use App\Controllers\PublicBookingController as P;
use App\Core\DB;
use App\Core\Settings;
use App\Services\AvailabilityEngine as A;
use App\Services\BookingRepository as R;
use Tests\Assert;
use Tests\Harness;

Harness::suite('http/public-booking');

/**
 * The resource type this suite owns outright.
 *
 * A real member of the resources enum that neither the venue's seed nor any
 * other suite uses, so `resourcesOfType()` returns this suite's rooms and
 * nothing else — which is what "the only room" has to mean for the
 * double-booking assertions to say anything.
 */
const PB_ROOM_TYPE = 'kart';

$baseUrl = getenv('CC_TEST_BASE_URL') ?: '';

if ($baseUrl === '') {
    Harness::skip('public booking suite', 'no server; run via tests/run.php');

    return;
}

if (!test_boot()) {
    Harness::skip('public booking suite', 'no database available');

    return;
}

require_once CC_ROOT . '/tests/TestBrowser.php';

// ------------------------------------------------------------------ fixtures

/**
 * The nth Saturday at least three weeks out.
 *
 * A DATE OF ITS OWN PER TEST, because the daily allowance counts unanswered
 * public enquiries per date — so two tests sharing a Saturday would have the
 * second one refused by the first one's leftovers, and the failure would read
 * as a broken allowance rather than as a dirty fixture.
 *
 * Saturdays, because the venue's seeded hours are longest then and a package
 * needs a window it fits in. Three weeks clears the seeded seven-day notice.
 */
function pb_saturday(int $nth = 0): string
{
    $date = A::addDays(R::today(), 21);

    for ($i = 0; $i < 7 && A::dayOfWeek($date) !== 6; $i++) {
        $date = A::addDays($date, 1);
    }

    return A::addDays($date, 7 * $nth);
}

/**
 * A package of this suite's own, with its own attraction and its own rooms.
 *
 * Exactly the reasoning in BookingsTest: `resourceTypeFor()` resolves the room
 * pool from the package's main attraction, so pointing a SEEDED package at
 * test rooms puts bookings in the seeded pool and every "the room is now held"
 * assertion below becomes meaningless.
 *
 * The rooms are type PB_ROOM_TYPE and not `table`, which BookingsTest and the
 * availability integration suite both use and neither deletes: the room POOL
 * is every active resource of a type (`resourcesOfType`), not every resource
 * of an attraction, so leftovers from an earlier suite join this one's pool.
 * That is what made "the only room is now taken" false — the page went on
 * offering the time because another suite's abandoned rooms were free. Stale
 * rows of this type are cleared below before anything is created.
 *
 * @return array<string,mixed>
 */
function pb_package(): array
{
    static $package = null;

    if ($package !== null) {
        return $package;
    }

    $attractionId = DB::insert('attractions', [
        'name'              => 'Public Booking Test Attraction',
        'slug'              => 'public-booking-test-' . bin2hex(random_bytes(4)),
        'is_seasonal'       => 0,
        'is_party_eligible' => 1,
        'is_party_main'     => 1,
        'requires_waiver'   => 0,
        'is_active'         => 1,
        'created_at'        => now_utc(),
        'updated_at'        => now_utc(),
    ]);

    $packageId = DB::insert('party_packages', [
        'name'                   => 'Public Booking Test Package',
        'slug'                   => 'public-booking-test-package-' . bin2hex(random_bytes(4)),
        'main_attraction_id'     => $attractionId,
        'base_price_cents'       => 34900,
        'included_guests'        => 8,
        'additional_guest_cents' => 2699,
        'duration_minutes'       => 120,
        'min_notice_days'        => 7,
        'deposit_cents'          => 10000,
        'is_active'              => 1,
        'sort_order'             => 950,
        'created_at'             => now_utc(),
        'updated_at'             => now_utc(),
    ]);

    $row = DB::first('SELECT * FROM {party_packages} WHERE id = :id', ['id' => $packageId]);

    Assert::notNull($row);

    /** @var array<string,mixed> $row */
    $package = $row;

    return $package;
}

/** Rooms in this suite's own pool, created once, so "the only room" means it. */
function pb_rooms(int $count = 1, int $capacity = 30): array
{
    static $rooms = [];

    while (count($rooms) < $count) {
        $rooms[] = DB::insert('resources', [
            'name'          => 'Public Booking Test Room ' . (count($rooms) + 1)
                . ' ' . bin2hex(random_bytes(2)),
            'type'          => PB_ROOM_TYPE,
            'capacity'      => $capacity,
            'attraction_id' => (int) pb_package()['main_attraction_id'],
            'is_active'     => 1,
            'created_at'    => now_utc(),
            'updated_at'    => now_utc(),
        ]);
    }

    return $rooms;
}

/** A visitor with no account, on an IP of their own so the POST throttle is per-test. */
function pb_visitor(string $baseUrl, string $name): TestBrowser
{
    static $n = 0;
    $n++;

    // 198.51.100.0/24 is TEST-NET-2, reserved for documentation — it can never
    // be a real client, which is what makes it safe to fabricate.
    return new TestBrowser($baseUrl, 'public-booking-' . $name, '198.51.100.' . (($n % 250) + 2));
}

/** The times the page is offering, as "HH:MM". */
function pb_slots(string $body): array
{
    // The links are HTML-escaped, so the separator reads "&amp;start=" and the
    // colon is percent-encoded.
    preg_match_all('/start=(\d{2})(?:%3A|:)(\d{2})/', $body, $m, PREG_SET_ORDER);

    $times = [];

    foreach ($m as $match) {
        $times[] = $match[1] . ':' . $match[2];
    }

    return array_values(array_unique($times));
}

/**
 * Walk the page the way a family does, and send the enquiry.
 *
 * The two GETs are not ceremony: the details form only exists once a time has
 * been chosen, so the CSRF token can only be minted from the page that offers
 * it — which also means this helper fails loudly if the flow stops working,
 * rather than posting a tokenless request and reporting a CSRF refusal.
 *
 * @param array<string,string> $fields
 * @return array{status:int,body:string,slot:string,offered:list<string>,token:string}
 */
function pb_enquire(TestBrowser $visitor, string $date, array $fields = [], ?string $slot = null): array
{
    $packageId = (string) pb_package()['id'];
    $guests    = $fields['guest_count'] ?? '12';

    $browse  = $visitor->get('/book?package_id=' . $packageId . '&guest_count=' . $guests . '&date=' . $date);
    $offered = pb_slots($browse['body']);
    $slot  ??= $offered[0] ?? '';

    $form  = $visitor->get(
        '/book?package_id=' . $packageId . '&guest_count=' . $guests
        . '&date=' . $date . '&start=' . $slot
    );
    $token = preg_match('/name="_token" value="([^"]+)"/', $form['body'], $m) === 1 ? $m[1] : '';

    // $fields FIRST: PHP's `+` keeps the left operand when a key appears in
    // both, so writing the defaults first silently discarded every override a
    // test passed — and a test whose "name" => "" never reached the server
    // reads as "the server accepted a nameless enquiry".
    $response = $visitor->post('/book', $fields + [
        '_token'      => $token,
        'package_id'  => $packageId,
        'guest_count' => $guests,
        'date'        => $date,
        'start_time'  => $slot,
        'name'        => 'PB Parent',
        'email'       => 'pb.' . bin2hex(random_bytes(4)) . '@example.test',
    ]);

    return [
        'status'  => $response['status'],
        'body'    => $response['body'],
        'slot'    => $slot,
        'offered' => $offered,
        'token'   => $token,
    ];
}

/** @return array<string,mixed>|null the enquiry on this date, if there is one */
function pb_row(string $date): ?array
{
    return DB::first(
        "SELECT * FROM {bookings}
          WHERE event_date = :d AND source = 'public_form' AND deleted_at IS NULL
          ORDER BY id DESC LIMIT 1",
        ['d' => $date]
    );
}

function pb_pending(string $date): int
{
    return (int) DB::value(
        "SELECT COUNT(*) FROM {bookings}
          WHERE event_date = :d AND status = 'inquiry'
            AND source = 'public_form' AND deleted_at IS NULL",
        ['d' => $date]
    );
}

function pb_clear(string $date): void
{
    foreach (DB::all('SELECT id FROM {bookings} WHERE event_date = :d', ['d' => $date]) as $row) {
        DB::delete('booking_resources', 'booking_id = :id', ['id' => (int) $row['id']]);
        DB::delete('bookings', 'id = :id', ['id' => (int) $row['id']]);
    }

    DB::delete('contacts', "email LIKE 'pb.%@example.test'", []);
}

// --------------------------------------------------------------- preconditions

// Anything left of this type by an interrupted run of THIS suite. It owns the
// type outright, so this can never touch the venue's own resources.
DB::delete('resources', 'type = :t', ['t' => PB_ROOM_TYPE]);

pb_package();
pb_rooms(1);

Harness::test('the fixture date really is the weekday these tests rely on', function (): void {
    // Every availability assertion below is about a day the venue is open for
    // long enough to fit a two-hour party. If this search were wrong they
    // would pass or fail for reasons that have nothing to do with the page.
    Assert::same(6, A::dayOfWeek(pb_saturday()), 'the fixture Saturday is a Saturday');
    Assert::greaterThan(
        13,
        A::daysBetween(R::today(), pb_saturday()),
        'and is far enough out to clear the package\'s seven-day notice'
    );
});

// --------------------------------------------------------------- the off switch

Harness::test('THE PAGE IS OFF UNTIL THE VENUE TURNS IT ON', function () use ($baseUrl): void {
    // A fresh install does not take bookings from the internet. The setting
    // row is removed rather than set to 0, because what is being tested is
    // the DEFAULT — an install that has never been asked.
    $stored = Settings::get(R::PUBLIC_SETTING);

    try {
        Settings::forget(R::PUBLIC_SETTING);

        Assert::false(R::publicEnabled(), 'with nothing stored, the page is off');

        $visitor = pb_visitor($baseUrl, 'off-default');
        $page    = $visitor->get('/book');

        Assert::same(404, $page['status'], 'and answers 404');
        Assert::notContains('Book a party', $page['body'], 'with no form behind it');
    } finally {
        if ($stored === null) {
            Settings::forget(R::PUBLIC_SETTING);
        } else {
            Settings::set(R::PUBLIC_SETTING, $stored);
        }
    }
});

// From here on the page is on. Restored at the end of the file.
$storedPublic = Settings::get(R::PUBLIC_SETTING);
Settings::set(R::PUBLIC_SETTING, '1');

Harness::test('switching it on makes the page answer', function () use ($baseUrl): void {
    // The positive control for every 404 in this file. Without it, a
    // controller that answered 404 unconditionally would pass them all.
    $page = pb_visitor($baseUrl, 'on')->get('/book');

    Assert::same(200, $page['status'], 'a visitor with no account reaches the page');
    Assert::contains('Book a party', $page['body']);
});

Harness::test('switched ON but with no package to sell, it answers the SAME 404', function () use ($baseUrl): void {
    // Offering an empty form would take an enquiry nobody can price, and a
    // DIFFERENT message for each reason the page will not serve would tell
    // anybody poking at the URL about the venue's configuration.
    $active = array_map(
        static fn (array $row): int => (int) $row['id'],
        DB::all('SELECT id FROM {party_packages} WHERE is_active = 1')
    );

    Assert::greaterThan(0, count($active), 'precondition: something is on sale to begin with');

    try {
        DB::query('UPDATE {party_packages} SET is_active = 0 WHERE is_active = 1');

        $visitor = pb_visitor($baseUrl, 'no-packages');
        $page    = $visitor->get('/book');

        Assert::same(404, $page['status']);
        Assert::notContains('package', strtolower($page['body']), 'and says nothing about packages');
    } finally {
        foreach ($active as $id) {
            DB::update('party_packages', ['is_active' => 1], 'id = :id', ['id' => $id]);
        }
    }
});

// ------------------------------------------------------------------- the flow

Harness::test('the details form is not offered until a time is chosen', function () use ($baseUrl): void {
    // A family answers which party, which day and which time before being
    // asked for their name. Asking first would collect contact details for an
    // enquiry that cannot be taken.
    $visitor   = pb_visitor($baseUrl, 'flow');
    $packageId = (string) pb_package()['id'];
    $date      = pb_saturday();

    $bare = $visitor->get('/book');
    Assert::notContains('name="name"', $bare['body'], 'nothing is asked before a day is chosen');

    $day = $visitor->get('/book?package_id=' . $packageId . '&guest_count=12&date=' . $date);
    Assert::notContains('name="name"', $day['body'], 'nor after a day but before a time');

    $slots = pb_slots($day['body']);
    Assert::greaterThan(0, count($slots), 'and real times are offered on ' . $date);

    $withTime = $visitor->get(
        '/book?package_id=' . $packageId . '&guest_count=12&date=' . $date . '&start=' . $slots[0]
    );
    Assert::contains('name="name"', $withTime['body'], 'choosing a time opens the details form');
    Assert::contains('name="_token"', $withTime['body'], 'which carries a CSRF token');
});

Harness::test('the details form carries the three answers it was given', function () use ($baseUrl): void {
    // The office form read these from a SECOND form that the browser never
    // submitted alongside the first, so a headcount chosen in step two never
    // reached the booking — found by walking it in a browser, invisible to a
    // test that builds its own request body. These hidden fields are what
    // carry the answers, so their presence is worth asserting outright.
    $visitor   = pb_visitor($baseUrl, 'hidden-fields');
    $packageId = (string) pb_package()['id'];
    $date      = pb_saturday();

    $day   = $visitor->get('/book?package_id=' . $packageId . '&guest_count=17&date=' . $date);
    $slots = pb_slots($day['body']);

    Assert::greaterThan(0, count($slots));

    $form = $visitor->get(
        '/book?package_id=' . $packageId . '&guest_count=17&date=' . $date . '&start=' . $slots[0]
    );

    foreach ([
        'package_id'  => $packageId,
        'guest_count' => '17',
        'date'        => $date,
        'start_time'  => $slots[0],
    ] as $field => $value) {
        Assert::matches(
            '/<input type="hidden" name="' . $field . '" value="' . preg_quote($value, '/') . '"/',
            $form['body'],
            $field . ' is carried into the enquiry as ' . $value
        );
    }
});

Harness::test('a time the page never offered does not open the details form', function () use ($baseUrl): void {
    // `?start=` is a URL parameter, so it is whatever anybody types. Accepting
    // one the engine did not offer would show a family the form, let them fill
    // it in, and refuse it at the end.
    $visitor = pb_visitor($baseUrl, 'bad-start');
    $date    = pb_saturday();

    $response = $visitor->get(
        '/book?package_id=' . pb_package()['id'] . '&guest_count=12&date=' . $date . '&start=03:00'
    );

    Assert::same(200, $response['status'], 'the page still renders');
    Assert::notContains('name="name"', $response['body'], 'but three in the morning is not on offer');
});

// -------------------------------------------------------------- the enquiry

Harness::test('AN ENQUIRY IS WRITTEN, LIVE, AND HOLDS A ROOM', function () use ($baseUrl): void {
    $date = pb_saturday(1);

    pb_clear($date);

    try {
        $result = pb_enquire(pb_visitor($baseUrl, 'write'), $date, [
            'guest_of_honor_name' => 'PB Honoree',
            'guest_of_honor_age'  => '8',
            'allergies'           => 'Peanuts',
            'special_requests'    => 'A quiet corner if there is one',
        ]);

        Assert::same(200, $result['status']);
        Assert::contains('Thank you', $result['body'], 'the family is thanked');
        Assert::contains('B-', $result['body'], 'and given a reference');

        $row = pb_row($date);

        Assert::notNull($row, 'and the enquiry is stored');
        /** @var array<string,mixed> $row */
        Assert::same('inquiry', (string) $row['status'], 'at a LIVE status, so the room is really held');
        Assert::same('public_form', (string) $row['source'], 'marked as having come from the public page');
        Assert::same('PB Honoree', (string) $row['guest_of_honor_name']);
        Assert::same('Peanuts', (string) $row['allergies'], 'and the allergy note the desk needs');

        // A LIVE STATUS IS NOT ENOUGH ON ITS OWN. Without an allocation the
        // slot stays "free", six roomless enquiries fit at two o'clock, and
        // the calendar shows every room available while the diary says
        // otherwise — the bug the office form had, measured.
        $held = DB::all(
            'SELECT resource_id, start_time, end_time FROM {booking_resources} WHERE booking_id = :id',
            ['id' => (int) $row['id']]
        );

        Assert::same(1, count($held), 'and a room is actually allocated to it');

        $start = A::minutes($result['slot']);
        Assert::notNull($start);
        Assert::same(
            R::localToUtc($date, (int) $start),
            (string) $held[0]['start_time'],
            'at the local time asked for, stored as UTC'
        );
    } finally {
        pb_clear($date);
    }
});

Harness::test('THE FIGURES ARE DERIVED, NOT POSTED', function () use ($baseUrl): void {
    // The whole money model rests on this, and nothing on the page reveals
    // whether it holds. A page that trusted the body would let anybody book a
    // $350 party for a dollar — and the stored row would look perfectly
    // ordinary afterwards.
    $date    = pb_saturday(2);
    $package = pb_package();

    pb_clear($date);

    try {
        $result = pb_enquire(pb_visitor($baseUrl, 'derived'), $date, [
            'guest_count'       => '12',
            'subtotal_cents'    => '1',
            'tax_cents'         => '0',
            'total_cents'       => '1',
            'deposit_cents'     => '0',
            'balance_due_cents' => '0',
            'status'            => 'paid',
            'type'              => 'corporate',
        ]);

        Assert::same(200, $result['status']);

        $row = pb_row($date);
        Assert::notNull($row);
        /** @var array<string,mixed> $row */

        $extra    = 12 - (int) $package['included_guests'];
        $subtotal = (int) $package['base_price_cents'] + $extra * (int) $package['additional_guest_cents'];
        $tax      = (int) round($subtotal * R::taxRate() / 100000);

        Assert::same($subtotal, (int) $row['subtotal_cents'], 'the subtotal comes from the package');
        Assert::same($tax, (int) $row['tax_cents'], 'the tax from the venue\'s own rate');
        Assert::same($subtotal + $tax, (int) $row['total_cents']);
        Assert::same(
            (int) $package['deposit_cents'],
            (int) $row['deposit_cents'],
            'and the deposit from the package, not the request'
        );

        // Nothing has been paid, so the whole total is still owed.
        Assert::same((int) $row['total_cents'], (int) $row['balance_due_cents']);
        Assert::same(
            0,
            (int) DB::value(
                'SELECT COUNT(*) FROM {booking_payments} WHERE booking_id = :id',
                ['id' => (int) $row['id']]
            ),
            'and no payment is invented'
        );

        // A status claiming money, and a type, are not the public page's to set.
        Assert::same('inquiry', (string) $row['status'], 'a posted status is ignored');
        Assert::same('birthday', (string) $row['type'], 'and so is a posted type');
    } finally {
        pb_clear($date);
    }
});

Harness::test('THE SLOT IS RE-CHECKED AT THE WRITE, with the room already taken', function () use ($baseUrl): void {
    // Two families clicking the same two o'clock. The page offered it to both,
    // honestly, and the second write has to be refused with the first one's
    // allocation already in place.
    $date = pb_saturday(3);

    pb_clear($date);

    try {
        $first = pb_enquire(pb_visitor($baseUrl, 'race-first'), $date);

        Assert::contains('Thank you', $first['body'], 'the first enquiry is taken');
        Assert::same(1, pb_pending($date));

        // The same time, posted directly — which is exactly what the second
        // family's browser does, because their page was drawn before the
        // first one's write.
        $visitor   = pb_visitor($baseUrl, 'race-second');
        $packageId = (string) pb_package()['id'];

        $form  = $visitor->get(
            '/book?package_id=' . $packageId . '&guest_count=12&date=' . $date
            . '&start=' . $first['slot']
        );
        $token = preg_match('/name="_token" value="([^"]+)"/', $form['body'], $m) === 1 ? $m[1] : '';

        // The page already knows the time has gone, so it offers no form —
        // which is itself the first line of defence and worth asserting.
        Assert::same('', $token, 'the page no longer offers that time');

        // Post it anyway, with a token minted from the page that does render.
        $fresh = $visitor->get('/book?package_id=' . $packageId . '&guest_count=12&date=' . $date);
        $slots = pb_slots($fresh['body']);

        Assert::notContains(
            $first['slot'],
            implode(',', $slots),
            'and the taken time is gone from the offered list'
        );

        $other = $visitor->get(
            '/book?package_id=' . $packageId . '&guest_count=12&date=' . $date . '&start=' . $slots[0]
        );
        $token = preg_match('/name="_token" value="([^"]+)"/', $other['body'], $m) === 1 ? $m[1] : '';

        Assert::notSame('', $token, 'precondition: some other time is still bookable');

        $response = $visitor->post('/book', [
            '_token'      => $token,
            'package_id'  => $packageId,
            'guest_count' => '12',
            'date'        => $date,
            'start_time'  => $first['slot'],
            'name'        => 'PB Second',
            'email'       => 'pb.' . bin2hex(random_bytes(4)) . '@example.test',
        ]);

        Assert::same(302, $response['status'], 'the second write is refused');
        Assert::same(1, pb_pending($date), 'and nothing is stored for it');
    } finally {
        pb_clear($date);
    }
});

// -------------------------------------------------------------------- spam

Harness::test('the honeypot is answered as if it worked, and writes nothing', function () use ($baseUrl): void {
    // Telling a bot it was caught teaches it how to pass. The page has
    // nothing to store for one either way.
    $date = pb_saturday(4);

    pb_clear($date);

    try {
        $result = pb_enquire(pb_visitor($baseUrl, 'honeypot'), $date, [
            P::HONEYPOT => 'https://spam.example',
        ]);

        Assert::same(200, $result['status']);
        Assert::contains('Thank you', $result['body'], 'it is thanked like anybody else');
        Assert::same(0, pb_pending($date), 'and nothing is written');

        // The positive control: the same walk WITHOUT the honeypot does write.
        $real = pb_enquire(pb_visitor($baseUrl, 'honeypot-control'), $date);

        Assert::contains('Thank you', $real['body']);
        Assert::same(1, pb_pending($date), 'so the refusal above was the honeypot, not a broken page');
    } finally {
        pb_clear($date);
    }
});

Harness::test('the page is never indexed and never cached', function () use ($baseUrl): void {
    // A search engine listing a venue's booking form with somebody's
    // half-finished enquiry in the query string is a privacy problem, and a
    // cached availability page is a wrong one.
    $response = pb_visitor($baseUrl, 'headers')->get('/book');

    // TestBrowser hands back the raw header block, not an array.
    $headers = strtolower((string) ($response['headers'] ?? ''));

    Assert::contains('noindex', $headers, 'X-Robots-Tag says noindex');
    Assert::contains('no-store', $headers, 'and the page is not cached');
});

// ----------------------------------------------------------------- refusals

Harness::test('an enquiry with no way to reach anybody is refused', function () use ($baseUrl): void {
    // A held room and nobody to ring about it is worse than no enquiry: the
    // venue cannot confirm it and cannot release it either.
    $date = pb_saturday(5);

    pb_clear($date);

    try {
        $result = pb_enquire(pb_visitor($baseUrl, 'unreachable'), $date, [
            'email' => '',
            'phone' => '',
        ]);

        Assert::same(302, $result['status'], 'it is sent back to the form');
        Assert::same(0, pb_pending($date), 'and nothing is held');

        // A phone number alone IS enough — the control that proves the refusal
        // above was about reachability and not about the email field.
        $byPhone = pb_enquire(pb_visitor($baseUrl, 'by-phone'), $date, [
            'email' => '',
            'phone' => '845-469-2116',
        ]);

        Assert::contains('Thank you', $byPhone['body'], 'a phone number alone is enough');
        Assert::same(1, pb_pending($date));
    } finally {
        pb_clear($date);
    }
});

Harness::test('a nameless enquiry is refused by the server, not only by the browser', function () use ($baseUrl): void {
    // The `required` attribute is a courtesy to a person using a browser. It
    // is not a check.
    $date = pb_saturday(6);

    pb_clear($date);

    try {
        $result = pb_enquire(pb_visitor($baseUrl, 'nameless'), $date, ['name' => '']);

        Assert::same(302, $result['status']);
        Assert::same(0, pb_pending($date));
    } finally {
        pb_clear($date);
    }
});

Harness::test('a date beyond a year out is never OFFERED a time', function () use ($baseUrl): void {
    // The date input caps itself, but a hand-typed or bookmarked URL does not.
    // Offering times for a party eighteen months out and then refusing the
    // enquiry once the form is filled in is the same "show them the form, then
    // say no" the ?start= validation exists to prevent.
    //
    // The far date is a SATURDAY, like every other fixture date here, so the
    // venue's hours are not what refuses it — the limit is.
    $far = pb_saturday(53);

    Assert::greaterThan(
        P::MAX_DAYS_AHEAD,
        A::daysBetween(R::today(), $far),
        'precondition: the fixture date really is beyond the limit'
    );
    Assert::same(6, A::dayOfWeek($far), 'and is a Saturday the venue would otherwise be open on');

    $page = pb_visitor($baseUrl, 'too-far-page')->get(
        '/book?package_id=' . pb_package()['id'] . '&guest_count=12&date=' . $far
    );

    Assert::same(200, $page['status'], 'the page still answers');
    Assert::same([], pb_slots($page['body']), 'but offers no times');
    Assert::notContains('name="name"', $page['body'], 'and no form to fill in');
    Assert::contains('up to a year ahead', $page['body'], 'it says why');
});

Harness::test('the please-call fallback reads like English with or without a number', function () use ($baseUrl): void {
    // THE FALLBACK IS THE POINT OF THIS PAGE'S REFUSALS, so the sentence has
    // to survive an install that has not filled its phone number in. It used
    // to resolve the missing number to the words "the venue", which read
    // perfectly on its own and produced "please give us a ring on the venue"
    // in every refusal — measured on a venue with no number stored.
    $far    = pb_saturday(53);
    $stored = Settings::get('venue.phone_display');
    $url    = '/book?package_id=' . pb_package()['id'] . '&guest_count=12&date=' . $far;

    try {
        Settings::set('venue.phone_display', '(845) 469-2116');

        $withNumber = pb_visitor($baseUrl, 'call-number')->get($url)['body'];

        Assert::contains('ring on (845) 469-2116', $withNumber, 'the number is offered when there is one');

        Settings::forget('venue.phone_display');

        $without = pb_visitor($baseUrl, 'call-none')->get($url)['body'];

        Assert::contains('give us a call', $without, 'and the sentence still reads properly without one');
        Assert::notContains('ring on the venue', $without, 'never "ring on the venue"');
        Assert::notContains('Ring .', $without, 'and no dangling fragment where the number was');
    } finally {
        if ($stored === null) {
            Settings::forget('venue.phone_display');
        } else {
            Settings::set('venue.phone_display', $stored);
        }
    }
});

Harness::test('and a POST for one is refused even with a token in hand', function () use ($baseUrl): void {
    // Defence in depth: the page above is what a family sees, and this is the
    // guard that cannot be walked around. The token is minted on a date the
    // page WILL serve, so what refuses this is the limit and not CSRF.
    $far  = pb_saturday(53);
    $near = pb_saturday(7);

    pb_clear($near);

    try {
        $visitor   = pb_visitor($baseUrl, 'too-far-post');
        $packageId = (string) pb_package()['id'];

        $slots = pb_slots($visitor->get(
            '/book?package_id=' . $packageId . '&guest_count=12&date=' . $near
        )['body']);

        Assert::greaterThan(0, count($slots), 'precondition: the near date is bookable');

        $form  = $visitor->get(
            '/book?package_id=' . $packageId . '&guest_count=12&date=' . $near . '&start=' . $slots[0]
        );
        $token = preg_match('/name="_token" value="([^"]+)"/', $form['body'], $m) === 1 ? $m[1] : '';

        Assert::notSame('', $token, 'precondition: a token was minted');

        $response = $visitor->post('/book', [
            '_token'      => $token,
            'package_id'  => $packageId,
            'guest_count' => '12',
            'date'        => $far,
            'start_time'  => $slots[0],
            'name'        => 'PB Far Future',
            'email'       => 'pb.' . bin2hex(random_bytes(4)) . '@example.test',
        ]);

        Assert::same(302, $response['status'], 'it is refused');
        Assert::same(
            0,
            (int) DB::value(
                "SELECT COUNT(*) FROM {bookings} WHERE event_date = :d AND source = 'public_form'",
                ['d' => $far]
            ),
            'and nothing is held eighteen months out'
        );

        // The positive control: the SAME post against the near date works, so
        // the refusal above was the date and not the shape of the request.
        $control = $visitor->post('/book', [
            '_token'      => $visitor->csrfToken(
                '/book?package_id=' . $packageId . '&guest_count=12&date=' . $near . '&start=' . $slots[0]
            ),
            'package_id'  => $packageId,
            'guest_count' => '12',
            'date'        => $near,
            'start_time'  => $slots[0],
            'name'        => 'PB Near Future',
            'email'       => 'pb.' . bin2hex(random_bytes(4)) . '@example.test',
        ]);

        Assert::contains('Thank you', $control['body'], 'the same request works on a date within the limit');
    } finally {
        pb_clear($near);
    }
});

Harness::test('an alternative date is never one the page would refuse', function () use ($baseUrl): void {
    // The useful half of a "no". BookingRepository::alternatives() searches
    // FORWARD from the date asked for, so a refusal close to the one-year
    // limit would otherwise be answered with dates past it — offers that fail
    // the moment one is clicked.
    $page = pb_visitor($baseUrl, 'alternatives')->get(
        '/book?package_id=' . pb_package()['id'] . '&guest_count=12&date=' . pb_saturday(53)
    );

    // Every date the page links to, alternatives included.
    preg_match_all('/date=(\d{4}-\d{2}-\d{2})/', $page['body'], $m);

    $offered = array_values(array_unique($m[1]));

    Assert::greaterThan(0, count($offered), 'the page links to at least its own date');

    foreach ($offered as $date) {
        if ($date === pb_saturday(53)) {
            continue;
        }

        Assert::true(
            A::daysBetween(R::today(), $date) <= P::MAX_DAYS_AHEAD,
            $date . ' is within the year this page takes bookings for'
        );
    }
});

Harness::test('A DATE CLOSES ONCE ITS ALLOWANCE OF UNANSWERED ENQUIRIES IS SPENT', function () use ($baseUrl): void {
    // The bound on the privilege this page grants. A stranger can tie a room
    // up, so at worst they fill one day's allowance and the venue gets a phone
    // call — which is where the fallback leads anyway.
    //
    // Enough rooms that each enquiry gets its own, and a DIFFERENT time each
    // time, so what refuses the last one is the allowance and not a collision.
    // Those are two separate refusals and a test that cannot tell them apart
    // proves neither.
    $date  = pb_saturday(8);
    $rooms = pb_rooms(P::PUBLIC_MAX_PER_DAY + 1);

    Assert::greaterThan(P::PUBLIC_MAX_PER_DAY, count($rooms), 'precondition: rooms to spare');

    pb_clear($date);

    try {
        $used = [];

        for ($i = 0; $i < P::PUBLIC_MAX_PER_DAY; $i++) {
            $visitor = pb_visitor($baseUrl, 'allowance-' . $i);
            $browse  = $visitor->get(
                '/book?package_id=' . pb_package()['id'] . '&guest_count=12&date=' . $date
            );

            $free = array_values(array_diff(pb_slots($browse['body']), $used));

            Assert::greaterThan(0, count($free), 'a free time for enquiry ' . ($i + 1));

            $used[] = $free[0];

            $result = pb_enquire($visitor, $date, [], $free[0]);

            Assert::contains(
                'Thank you',
                $result['body'],
                'enquiry ' . ($i + 1) . ' of the allowance is taken'
            );
        }

        Assert::same(P::PUBLIC_MAX_PER_DAY, pb_pending($date), 'the allowance is now spent');

        // The page closes the date BEFORE offering times, so nobody fills a
        // form in against an allowance that has already gone.
        $closed = pb_visitor($baseUrl, 'allowance-closed')->get(
            '/book?package_id=' . pb_package()['id'] . '&guest_count=12&date=' . $date
        );

        Assert::same(200, $closed['status'], 'the page still answers');
        Assert::same([], pb_slots($closed['body']), 'but offers no times on that date');
        Assert::contains(
            'lot of enquiries',
            $closed['body'],
            'and says why, rather than reading as fully booked'
        );
        Assert::notContains('name="name"', $closed['body'], 'with no form to fill in');

        // AND IT REFUSES A DIRECT POST, not only the page. A family's browser
        // is stopped by the page above; a script is not, and the write guard
        // is the one that actually bounds the privilege this page grants. The
        // token is minted on a date with room, so what refuses this is the
        // allowance and not CSRF.
        $other   = pb_saturday(9);
        $scripted = pb_visitor($baseUrl, 'allowance-post');

        $elsewhere = pb_slots($scripted->get(
            '/book?package_id=' . pb_package()['id'] . '&guest_count=12&date=' . $other
        )['body']);

        Assert::greaterThan(0, count($elsewhere), 'precondition: another date is bookable');

        $token = $scripted->csrfToken(
            '/book?package_id=' . pb_package()['id'] . '&guest_count=12&date=' . $other
            . '&start=' . $elsewhere[0]
        );

        Assert::notSame('', $token, 'precondition: a token was minted');

        $posted = $scripted->post('/book', [
            '_token'      => $token,
            'package_id'  => (string) pb_package()['id'],
            'guest_count' => '12',
            'date'        => $date,
            'start_time'  => $used[0],
            'name'        => 'PB Scripted',
            'email'       => 'pb.' . bin2hex(random_bytes(4)) . '@example.test',
        ]);

        Assert::same(302, $posted['status'], 'a direct POST against a spent date is refused');
        Assert::same(
            P::PUBLIC_MAX_PER_DAY,
            pb_pending($date),
            'and the allowance is not exceeded'
        );

        // Another date is untouched: the allowance is per date, not a global
        // switch that one busy Saturday can throw.
        $other = pb_visitor($baseUrl, 'allowance-other')->get(
            '/book?package_id=' . pb_package()['id'] . '&guest_count=12&date=' . pb_saturday(9)
        );

        Assert::greaterThan(0, count(pb_slots($other['body'])), 'and other dates still take enquiries');
    } finally {
        pb_clear($date);
    }
});

Harness::test('an ANSWERED enquiry does not count against the allowance', function () use ($baseUrl): void {
    // The allowance counts what the venue has not dealt with. A date whose
    // enquiries have all been confirmed is a date doing business, and closing
    // it would punish the venue for being busy.
    $date = pb_saturday(10);

    pb_clear($date);

    try {
        $used = [];

        for ($i = 0; $i < P::PUBLIC_MAX_PER_DAY; $i++) {
            $visitor = pb_visitor($baseUrl, 'answered-' . $i);
            $browse  = $visitor->get(
                '/book?package_id=' . pb_package()['id'] . '&guest_count=12&date=' . $date
            );

            $free = array_values(array_diff(pb_slots($browse['body']), $used));

            Assert::greaterThan(0, count($free), 'a free time for enquiry ' . ($i + 1));
            $used[] = $free[0];

            pb_enquire($visitor, $date, [], $free[0]);
        }

        Assert::same(P::PUBLIC_MAX_PER_DAY, pb_pending($date), 'precondition: the allowance is spent');

        // The office answers them.
        DB::query(
            "UPDATE {bookings} SET status = 'confirmed'
              WHERE event_date = :d AND source = 'public_form'",
            ['d' => $date]
        );

        Assert::same(0, pb_pending($date), 'none is unanswered now');

        $reopened = pb_visitor($baseUrl, 'answered-after')->get(
            '/book?package_id=' . pb_package()['id'] . '&guest_count=12&date=' . $date
        );

        Assert::notContains(
            'lot of enquiries',
            $reopened['body'],
            'so the date is open again'
        );
    } finally {
        pb_clear($date);
    }
});

// ------------------------------------------------------------------ consent

Harness::test('AN ENQUIRY IS NOT AN OPT-IN', function () use ($baseUrl): void {
    // Phase 2's rule, tested again because this page writes contacts by its
    // own path. Sending a party enquiry is not permission to market.
    $date  = pb_saturday(11);
    $email = 'pb.' . bin2hex(random_bytes(4)) . '@example.test';

    pb_clear($date);

    try {
        $result = pb_enquire(pb_visitor($baseUrl, 'no-consent'), $date, ['email' => $email]);

        Assert::contains('Thank you', $result['body']);

        $contact = DB::first('SELECT * FROM {contacts} WHERE email = :e', ['e' => $email]);

        Assert::notNull($contact, 'the guest is recorded');
        /** @var array<string,mixed> $contact */
        Assert::same(0, (int) $contact['email_consent'], 'but not as having agreed to marketing');
        Assert::null($contact['email_consent_at']);
        Assert::same(
            0,
            (int) DB::value(
                'SELECT COUNT(*) FROM {consent_log} WHERE contact_id = :id',
                ['id' => (int) $contact['id']]
            ),
            'and nothing is logged as consent'
        );
        Assert::same('lead', (string) $contact['lifecycle_stage']);
    } finally {
        pb_clear($date);
    }
});

Harness::test('a TICKED box grants consent, with the wording the guest saw', function () use ($baseUrl): void {
    // The positive control for the test above, and the evidence a complaint
    // is answered with months later.
    $date  = pb_saturday(12);
    $email = 'pb.' . bin2hex(random_bytes(4)) . '@example.test';

    pb_clear($date);

    try {
        pb_enquire(pb_visitor($baseUrl, 'consent'), $date, [
            'email'  => $email,
            'opt_in' => '1',
        ]);

        $contact = DB::first('SELECT * FROM {contacts} WHERE email = :e', ['e' => $email]);

        Assert::notNull($contact);
        /** @var array<string,mixed> $contact */
        Assert::same(1, (int) $contact['email_consent'], 'consent is recorded');
        Assert::notNull($contact['email_consent_at'], 'with the time it was given');

        $log = DB::first(
            'SELECT * FROM {consent_log} WHERE contact_id = :id ORDER BY id DESC LIMIT 1',
            ['id' => (int) $contact['id']]
        );

        Assert::notNull($log, 'and logged');
        /** @var array<string,mixed> $log */
        Assert::same('email', (string) $log['channel']);
        Assert::same('granted', (string) $log['action']);
        Assert::contains(
            P::OPT_IN_TEXT,
            (string) $log['evidence'],
            'with the exact wording shown beside the box'
        );

        // An email opt-in is not an SMS one, even with a phone number on file.
        Assert::same(0, (int) $contact['sms_consent'], 'and it grants nothing for text messages');
    } finally {
        pb_clear($date);
    }
});

Harness::test('a ticked box grants NOTHING for a channel with no address', function () use ($baseUrl): void {
    // Consent for a channel nobody can be reached on is a permission that
    // cannot be exercised and still reads as a marketable guest.
    $date  = pb_saturday(13);
    $phone = '845-469-' . random_int(1000, 9999);

    pb_clear($date);

    try {
        pb_enquire(pb_visitor($baseUrl, 'consent-unreachable'), $date, [
            'email'  => '',
            'phone'  => $phone,
            'opt_in' => '1',
        ]);

        $contact = DB::first(
            'SELECT * FROM {contacts} WHERE phone_normalized = :p ORDER BY id DESC LIMIT 1',
            ['p' => normalize_phone($phone)]
        );

        Assert::notNull($contact, 'the guest is still recorded');
        /** @var array<string,mixed> $contact */
        Assert::same(
            0,
            (int) $contact['email_consent'],
            'but an email opt-in with no email address grants nothing'
        );
        Assert::same(
            0,
            (int) DB::value(
                'SELECT COUNT(*) FROM {consent_log} WHERE contact_id = :id',
                ['id' => (int) $contact['id']]
            )
        );

        DB::delete('contacts', 'id = :id', ['id' => (int) $contact['id']]);
    } finally {
        pb_clear($date);
    }
});

// ------------------------------------------------------------------ identity

Harness::test('a returning family is matched, not duplicated, and never overwritten', function () use ($baseUrl): void {
    // The same parent books three birthdays over four years. Three contact
    // rows means the venue cannot see that, and an overwritten name means a
    // typo today loses what was right yesterday.
    $date  = pb_saturday(14);
    $email = 'pb.' . bin2hex(random_bytes(4)) . '@example.test';

    pb_clear($date);

    $existing = DB::insert('contacts', [
        'first_name'      => 'Alexandra',
        'last_name'       => 'Fitzgerald',
        'email'           => $email,
        'lifecycle_stage' => 'customer',
        'created_at'      => now_utc(),
        'updated_at'      => now_utc(),
    ]);

    try {
        pb_enquire(pb_visitor($baseUrl, 'returning'), $date, [
            'email' => $email,
            'name'  => 'alex f',
            'phone' => '845-469-2116',
        ]);

        Assert::same(
            1,
            (int) DB::value('SELECT COUNT(*) FROM {contacts} WHERE email = :e', ['e' => $email]),
            'one contact, not two'
        );

        $contact = DB::first('SELECT * FROM {contacts} WHERE id = :id', ['id' => $existing]);

        Assert::notNull($contact);
        /** @var array<string,mixed> $contact */
        Assert::same('Alexandra', (string) $contact['first_name'], 'the name on file is kept');
        Assert::same('Fitzgerald', (string) $contact['last_name']);
        Assert::same(
            'customer',
            (string) $contact['lifecycle_stage'],
            'and a customer is not demoted to a lead'
        );

        // A BLANK is filled, though: that is new information, not a conflict.
        Assert::same('845-469-2116', (string) $contact['phone'], 'a blank phone number is filled in');

        $row = pb_row($date);
        Assert::notNull($row);
        /** @var array<string,mixed> $row */
        Assert::same($existing, (int) $row['contact_id'], 'and the enquiry is attached to them');
    } finally {
        pb_clear($date);
        DB::delete('contacts', 'id = :id', ['id' => $existing]);
    }
});

// --------------------------------------------------------------------- audit

Harness::test('the enquiry is audited, with no user behind it', function () use ($baseUrl): void {
    // Somebody will ask where a booking came from. The row has to say "the
    // public page", and it has to say it without inventing a member of staff.
    $date = pb_saturday(15);

    pb_clear($date);

    try {
        pb_enquire(pb_visitor($baseUrl, 'audit'), $date);

        $row = pb_row($date);
        Assert::notNull($row);
        /** @var array<string,mixed> $row */

        $audit = DB::first(
            "SELECT * FROM {audit_log}
              WHERE action = 'booking.public_enquiry' AND entity_type = 'bookings' AND entity_id = :id",
            ['id' => (int) $row['id']]
        );

        Assert::notNull($audit, 'the enquiry is audited');
        /** @var array<string,mixed> $audit */
        Assert::null($audit['user_id'], 'with nobody signed in');
        Assert::contains((string) $row['booking_number'], (string) $audit['changes']);
    } finally {
        pb_clear($date);
    }
});

Harness::test('the POST needs a CSRF token', function () use ($baseUrl): void {
    // The page mints a token into the visitor's own session, which is what
    // stops another site aiming a form at a visitor's browser and filling the
    // venue's diary from it.
    $date = pb_saturday(16);

    pb_clear($date);

    try {
        $visitor  = pb_visitor($baseUrl, 'csrf');
        $response = $visitor->post('/book', [
            'package_id'  => (string) pb_package()['id'],
            'guest_count' => '12',
            'date'        => $date,
            'start_time'  => '14:00',
            'name'        => 'PB No Token',
            'email'       => 'pb.' . bin2hex(random_bytes(4)) . '@example.test',
        ]);

        Assert::same(419, $response['status'], 'a tokenless enquiry is refused');
        Assert::same(0, pb_pending($date), 'and nothing is held');
    } finally {
        pb_clear($date);
    }
});

// ------------------------------------------------------------------- cleanup

Harness::test('the suite leaves the venue as it found it', function () use ($storedPublic): void {
    if ($storedPublic === null) {
        Settings::forget(R::PUBLIC_SETTING);
    } else {
        Settings::set(R::PUBLIC_SETTING, $storedPublic);
    }

    // The fixture attraction, package and rooms, and every contact this suite
    // created. A suite that leaves a package on sale changes what the NEXT
    // one sees on the public page.
    $package = pb_package();

    DB::query(
        "DELETE FROM {bookings} WHERE package_id = :id",
        ['id' => (int) $package['id']]
    );
    DB::delete('contacts', "email LIKE 'pb.%@example.test'", []);
    DB::delete('resources', 'attraction_id = :id', ['id' => (int) $package['main_attraction_id']]);
    DB::delete('resources', 'type = :t', ['t' => PB_ROOM_TYPE]);
    DB::delete('party_packages', 'id = :id', ['id' => (int) $package['id']]);
    DB::delete('attractions', 'id = :id', ['id' => (int) $package['main_attraction_id']]);

    Assert::same(
        0,
        (int) DB::value(
            'SELECT COUNT(*) FROM {party_packages} WHERE id = :id',
            ['id' => (int) $package['id']]
        ),
        'the fixture package is gone'
    );
    Assert::same(
        0,
        (int) DB::value("SELECT COUNT(*) FROM {contacts} WHERE email LIKE 'pb.%@example.test'"),
        'and so are the contacts it created'
    );
});
