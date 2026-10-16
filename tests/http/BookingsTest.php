<?php

declare(strict_types=1);

/**
 * Bookings, end to end over HTTP.
 *
 * Five things here are worth more than the rest, and each one is something a
 * venue would otherwise discover on a Saturday:
 *
 *   1. **THE FIGURES ARE DERIVED, NOT POSTED.** A crafted `total_cents` in the
 *      request body must not become the price. The whole money model rests on
 *      that, and nothing in the UI reveals whether it holds.
 *   2. **THE SLOT IS RE-CHECKED AT THE WRITE.** Two people confirming the last
 *      room in the same second is not hypothetical at a venue with six party
 *      rooms, so the second one has to be refused with the first one's
 *      allocation already in place.
 *   3. **A STATUS CLAIMING MONEY COMES FROM THE MONEY.** `deposit_paid` and
 *      `paid` are not on the status form. Otherwise the front desk marks a
 *      party paid, the run sheet says the balance is clear, and nobody asks
 *      the family for $400 on the day.
 *   4. **A BLANK ADD-ON QUANTITY MEANS NOT WANTED.** Found live: reading blank
 *      as "one" put all fourteen catalogued add-ons on a booking, $5,569 of
 *      extras on a $511 party, with the arithmetic perfectly correct.
 *   5. **ALLERGIES REACH A ROLE WITH NO MONEY PERMISSION.** Front Desk holds
 *      no `view_revenue`; it still has to see the allergy note, and the run
 *      sheet it prints must carry it.
 *
 * Every refusal test here has its positive control beside it. Scoping a write
 * to nothing would satisfy "this role may not" perfectly, and the test that
 * the same role still CAN do its job is what separates a fix from a door
 * welded shut.
 *
 * ONE HONEST QUALIFICATION, because it affects how to read a green run. Each
 * gate exists TWICE — the `can:` middleware on the route and an authorize()
 * call inside the action — and the money rule exists twice as well, as a
 * server-side scrub and a check in the view. Removing either half on its own
 * leaves the other answering, and the test still passes. MEASURED: the
 * single-layer mutations of the money scrub and the checklist gate both
 * survived, and both PAIRS are caught. So these tests pin "this role cannot
 * do this", not "this particular line is what stops them" — which is the
 * right thing to pin, and worth knowing. The same is true of
 * tests/http/ContactsTest.php, for the same reason.
 */

use App\Core\Auth;
use App\Core\DB;
use App\Services\AvailabilityEngine as A;
use App\Services\BookingRepository as R;
use Tests\Assert;
use Tests\Harness;

Harness::suite('http/bookings');

$baseUrl = getenv('CC_TEST_BASE_URL') ?: '';

if ($baseUrl === '') {
    Harness::skip('bookings suite', 'no server; run via tests/run.php');

    return;
}

if (!test_boot()) {
    Harness::skip('bookings suite', 'no database available');

    return;
}

require_once CC_ROOT . '/tests/TestBrowser.php';

// ------------------------------------------------------------------ fixtures

/**
 * A Saturday at least three weeks out.
 *
 * Fixture dates are COMPUTED, never written as literals: availability is
 * answered against the venue's real today and the seeded packages carry a
 * seven-day notice rule, so a literal date works until it goes past. The
 * sibling integration suite was written with July 2026 dates and every
 * availability assertion came back `past` in October.
 */
function bk_saturday(): string
{
    static $date = null;

    if ($date !== null) {
        return $date;
    }

    $date = A::addDays(R::today(), 21);

    for ($i = 0; $i < 7 && A::dayOfWeek($date) !== 6; $i++) {
        $date = A::addDays($date, 1);
    }

    return $date;
}

/**
 * A package of this suite's own, with its own attraction and its own rooms.
 *
 * The first version pointed the SEEDED package at this suite's rooms, and the
 * double-booking tests failed because `resourceTypeFor()` resolves the room
 * pool from the package's main attraction and the seeded Skate Rink resource
 * is attached to the same attraction with a lower id — so bookings went into
 * the rink's pool, not the test pool, and never collided. Mutating seeded rows
 * to steer a test is a bad trade anyway: it leaves the venue's own data
 * changed for every later suite.
 *
 * So everything here is created: an attraction nothing else uses, rooms of
 * type `table` (a real member of the enum that the seed leaves empty) pointed
 * at it, and a package pointed at that. The pool is then exactly these rooms.
 *
 * @return array<string,mixed>
 */
function bk_package(): array
{
    static $package = null;

    if ($package !== null) {
        return $package;
    }

    $attractionId = DB::insert('attractions', [
        'name'              => 'Bookings Test Attraction',
        'slug'              => 'bookings-test-' . bin2hex(random_bytes(4)),
        'is_seasonal'       => 0,
        'is_party_eligible' => 1,
        'is_party_main'     => 1,
        'requires_waiver'   => 0,
        'is_active'         => 1,
        'created_at'        => now_utc(),
        'updated_at'        => now_utc(),
    ]);

    $packageId = DB::insert('party_packages', [
        'name'                   => 'Bookings Test Package',
        'slug'                   => 'bookings-test-package-' . bin2hex(random_bytes(4)),
        'main_attraction_id'     => $attractionId,
        'base_price_cents'       => 34900,
        'included_guests'        => 8,
        'additional_guest_cents' => 2699,
        'duration_minutes'       => 120,
        'min_notice_days'        => 7,
        'deposit_cents'          => 10000,
        'is_active'              => 1,
        'sort_order'             => 900,
        'created_at'             => now_utc(),
        'updated_at'             => now_utc(),
    ]);

    $row = DB::first('SELECT * FROM {party_packages} WHERE id = :id', ['id' => $packageId]);

    Assert::notNull($row);

    /** @var array<string,mixed> $row */
    $package = $row;

    return $package;
}

/** A room in this suite's own pool, so "the only room" means it. */
function bk_room(?int $capacity = 30): int
{
    static $count = 0;
    $count++;

    return DB::insert('resources', [
        'name'          => 'Bookings Test Room ' . $count . ' ' . bin2hex(random_bytes(2)),
        'type'          => 'table',
        'capacity'      => $capacity,
        'attraction_id' => (int) bk_package()['main_attraction_id'],
        'is_active'     => 1,
        'created_at'    => now_utc(),
        'updated_at'    => now_utc(),
    ]);
}

function bk_tag(string $prefix): string
{
    return $prefix . bin2hex(random_bytes(4));
}

/**
 * Create a booking through the real form, and return the id it redirected to.
 *
 * @param array<string,string> $fields
 * @return array{status:int,location:string,body:string,id:int}
 */
function bk_create(TestBrowser $browser, array $fields): array
{
    $defaults = [
        'type'        => 'birthday',
        'package_id'  => (string) bk_package()['id'],
        'event_date'  => bk_saturday(),
        'start_time'  => '14:00',
        'guest_count' => '12',
        'status'      => 'inquiry',
    ];

    $response = $browser->postWithToken('/bookings/new', '/bookings', $fields + $defaults);

    $id = preg_match('#/bookings/(\d+)#', $response['location'], $match) === 1 ? (int) $match[1] : 0;

    return [
        'status'   => $response['status'],
        'location' => $response['location'],
        'body'     => $response['body'],
        'id'       => $id,
    ];
}

/** @return array<string,mixed> */
function bk_row(int $id): array
{
    $row = DB::first('SELECT * FROM {bookings} WHERE id = :id', ['id' => $id]);

    Assert::notNull($row, 'booking ' . $id . ' should exist');

    /** @var array<string,mixed> $row */
    return $row;
}

function bk_cleanup(array $bookingIds): void
{
    foreach ($bookingIds as $id) {
        if ($id > 0) {
            DB::delete('bookings', 'id = :id', ['id' => $id]);
        }
    }
}

function bk_audit(string $action, int $id): bool
{
    return DB::exists(
        "SELECT id FROM {audit_log} WHERE action = :a AND entity_type = 'bookings' AND entity_id = :e",
        ['a' => $action, 'e' => $id]
    );
}

// -------------------------------------------------------------------- actors

$room = bk_room();

bk_package();

$owner = new TestBrowser($baseUrl, 'bookings-owner');
$owner->login('owner@test.invalid', 'owner-test-passphrase-9');

// Front Desk: bookings_manage and checkin, but NO view_revenue, NO
// bookings_cancel and NO payments_record. The whole permission split is
// testable against this one seeded role.
$deskBrowser = new TestBrowser($baseUrl, 'bookings-desk');
$deskBrowser->login('desk@test.invalid', 'desk-test-passphrase-9');

// Read Only: view_bookings and view_revenue, no write permission at all.
$viewerPassword = 'bookings-viewer-passphrase-9';
$viewerId       = test_make_user('read_only', [
    'email'         => 'bookings-viewer@example.test',
    'name'          => 'Bookings Viewer',
    'password_hash' => Auth::hash($viewerPassword),
]);

$viewer = new TestBrowser($baseUrl, 'bookings-viewer');
$viewer->login('bookings-viewer@example.test', $viewerPassword);

Harness::test('the fixtures signed in and the package reaches this suite\'s rooms', function () use (
    $owner,
    $deskBrowser,
    $viewer,
    $room
): void {
    // Without this, a permission test could pass because the actor was never
    // logged in, and an availability test could pass because the package was
    // looking at the wrong room pool.
    foreach (['owner' => $owner, 'desk' => $deskBrowser, 'viewer' => $viewer] as $who => $browser) {
        Assert::same(200, $browser->get('/account')['status'], $who . ' should be signed in');
    }

    Assert::same(6, A::dayOfWeek(bk_saturday()), 'the fixture date is a Saturday');
    Assert::greaterThan(
        13,
        A::daysBetween(R::today(), bk_saturday()),
        'and clears the seeded seven-day notice rule'
    );
    Assert::same(
        (int) bk_package()['main_attraction_id'],
        (int) DB::value('SELECT attraction_id FROM {resources} WHERE id = :id', ['id' => $room]),
        'the test room is attached to the test package\'s attraction'
    );
    Assert::same(
        1,
        (int) DB::value(
            'SELECT COUNT(*) FROM {resources} WHERE attraction_id = :a AND is_active = 1',
            ['a' => (int) bk_package()['main_attraction_id']]
        ),
        'and it is the ONLY room in that pool — the double-booking tests below '
            . 'are meaningless otherwise, which is how they first failed'
    );
});

// ---------------------------------------------------------------- the list

Harness::test('the booking list renders and offers the create action', function () use ($owner): void {
    $response = $owner->get('/bookings');

    Assert::same(200, $response['status']);
    Assert::contains('New booking', $response['body'], 'a role with bookings_manage is offered it');
    Assert::contains(
        'Every party, group and event',
        $response['body'],
        'and the page itself rendered, not just the shell around it'
    );
});

Harness::test('all five list views answer, and a search with a term is not a 500', function () use ($owner): void {
    // THE TEST THAT WAS MISSING FROM THE DEALS AND TASKS MODULES, where the
    // search clause reused one named parameter and every ?q= was a 500 with
    // no message anywhere in the UI. Emulated prepares are off and the native
    // driver answers HY093 to a repeated name. docs/TESTING.md has the rule:
    // a list test that never sets a filter does not test the filter.
    foreach (['upcoming', 'today', 'unassigned', 'past', 'all'] as $view) {
        Assert::same(200, $owner->get('/bookings?view=' . $view)['status'], $view . ' should answer');
    }

    Assert::same(
        200,
        $owner->get('/bookings?view=all&q=' . urlencode(bk_tag('Nomatch')))['status'],
        'a search must not be a server error'
    );
    Assert::same(200, $owner->get('/bookings?view=all&status=confirmed&type=birthday&q=a')['status']);
});

Harness::test('a booking is listed with its date, and found by searching for it', function () use ($owner): void {
    $honoree = bk_tag('Searchable');
    $created = bk_create($owner, ['guest_of_honor_name' => $honoree, 'start_time' => '11:00']);

    Assert::greaterThan(0, $created['id'], 'creating a booking should redirect to it');

    try {
        $list = $owner->get('/bookings?view=all');

        Assert::contains('/bookings/' . $created['id'] . '"', $list['body'], 'the booking is in the list');

        $found = $owner->get('/bookings?view=all&q=' . urlencode($honoree));

        Assert::same(200, $found['status']);
        // The row LINK, not the name: the filter bar echoes the search term
        // back into its own input, so a name assertion passes against an
        // empty list.
        Assert::contains('/bookings/' . $created['id'] . '"', $found['body']);

        $miss = $owner->get('/bookings?view=all&q=' . urlencode(bk_tag('Elsewhere')));

        Assert::notContains(
            '/bookings/' . $created['id'] . '"',
            $miss['body'],
            'a search that returns everybody is not a search'
        );
    } finally {
        bk_cleanup([$created['id']]);
    }
});

// --------------------------------------------------------------- the money

Harness::test('THE TOTAL IS DERIVED FROM THE PACKAGE, NOT FROM THE FORM', function () use ($owner): void {
    // A crafted total in the request body must not become the price. Nothing
    // in the interface shows whether this holds, which is exactly why it needs
    // a test rather than a convention.
    $package = bk_package();
    $created = bk_create($owner, [
        'guest_count'    => '14',
        'start_time'     => '11:00',
        // Every money column, posted directly.
        'subtotal_cents' => '1',
        'total_cents'    => '1',
        'tax_cents'      => '0',
        'deposit_cents'  => '0',
        'balance_due_cents' => '0',
    ]);

    Assert::greaterThan(0, $created['id']);

    try {
        $row = bk_row($created['id']);

        // 14 guests on a package including 8: six extra at the package rate.
        $expectedSubtotal = (int) $package['base_price_cents']
            + 6 * (int) $package['additional_guest_cents'];

        Assert::same($expectedSubtotal, (int) $row['subtotal_cents'], 'worked out, not posted');
        Assert::greaterThan(1, (int) $row['total_cents'], 'the posted total of one cent was ignored');
        Assert::same(
            (int) $row['subtotal_cents'] - (int) $row['discount_cents'] + (int) $row['tax_cents'],
            (int) $row['total_cents'],
            'and the stored figures agree with each other'
        );
        Assert::same(
            (int) $package['deposit_cents'],
            (int) $row['deposit_cents'],
            'the deposit comes from the package too'
        );
    } finally {
        bk_cleanup([$created['id']]);
    }
});

Harness::test('A BLANK ADD-ON QUANTITY ADDS NOTHING', function () use ($owner): void {
    // FOUND LIVE, not by review. The form renders a quantity box for every
    // item in the catalogue, so a blank one is the normal state of almost all
    // of them — and reading blank as "one" put all fourteen seeded add-ons on
    // a 14-guest party: $5,569 of extras on a $511 booking, with the
    // arithmetic perfectly correct and the figure completely wrong.
    $package   = bk_package();
    $catalogue = DB::all('SELECT id FROM {package_addons} WHERE is_active = 1 ORDER BY id LIMIT 5');

    Assert::greaterThan(2, count($catalogue), 'precondition: the seed has add-ons');

    $fields = ['guest_count' => '12', 'start_time' => '11:00'];

    foreach ($catalogue as $index => $addon) {
        $fields['addons[' . $index . '][addon_id]'] = (string) $addon['id'];
        // Every quantity blank, which is what the form posts when nobody
        // touches it.
        $fields['addons[' . $index . '][qty]'] = '';
    }

    $created = bk_create($owner, $fields);

    Assert::greaterThan(0, $created['id']);

    try {
        Assert::same(
            0,
            (int) DB::value(
                'SELECT COUNT(*) FROM {booking_addons} WHERE booking_id = :id',
                ['id' => $created['id']]
            ),
            'no add-on row at all'
        );

        $row = bk_row($created['id']);

        Assert::same(
            (int) $package['base_price_cents'] + 4 * (int) $package['additional_guest_cents'],
            (int) $row['subtotal_cents'],
            'and the subtotal is the package and the extra guests, nothing else'
        );
    } finally {
        bk_cleanup([$created['id']]);
    }
});

Harness::test('an add-on with a quantity IS added, and priced from the catalogue', function () use ($owner): void {
    // The other half: skipping blanks must not mean skipping everything.
    // Measured against the catalogue's own price, and with a posted price
    // that has to be ignored — a named item priced from the form is how a
    // discount gets given without anybody deciding to give one.
    $addon = DB::first(
        "SELECT id, name, price_cents, unit FROM {package_addons} WHERE is_active = 1 AND unit = 'flat' ORDER BY id LIMIT 1"
    ) ?? DB::first('SELECT id, name, price_cents, unit FROM {package_addons} WHERE is_active = 1 ORDER BY id LIMIT 1');

    Assert::notNull($addon);

    $created = bk_create($owner, [
        'guest_count'            => '12',
        'start_time'             => '11:00',
        'addons[0][addon_id]'    => (string) $addon['id'],
        'addons[0][qty]'         => '2',
        'addons[0][unit]'        => '0.01',
        'addons[0][description]' => 'Renamed by the sender',
    ]);

    Assert::greaterThan(0, $created['id']);

    try {
        $rows = DB::all(
            'SELECT description, qty, unit_cents, total_cents FROM {booking_addons} WHERE booking_id = :id',
            ['id' => $created['id']]
        );

        Assert::count(1, $rows);
        Assert::same(
            (int) $addon['price_cents'],
            (int) $rows[0]['unit_cents'],
            'the catalogue price, not the one cent that was posted'
        );
        Assert::same(
            (string) $addon['name'],
            (string) $rows[0]['description'],
            'and the catalogue name, not the posted one'
        );

        $expected = (string) $addon['unit'] === 'per_guest'
            ? 2 * 12 * (int) $addon['price_cents']
            : 2 * (int) $addon['price_cents'];

        Assert::same($expected, (int) $rows[0]['total_cents']);
    } finally {
        bk_cleanup([$created['id']]);
    }
});

// ------------------------------------------------------------ availability

Harness::test('THE SAME SLOT CANNOT BE BOOKED TWICE', function () use ($owner, $room): void {
    // Re-checked inside the transaction with the room locked, so two people
    // confirming the last room in the same second cannot both succeed. Here
    // the first booking's allocation is already committed, which is the
    // weaker but testable half of the same guarantee.
    $first = bk_create($owner, ['start_time' => '14:00', 'guest_of_honor_name' => bk_tag('First')]);

    Assert::greaterThan(0, $first['id']);

    try {
        $before = (int) DB::value('SELECT COUNT(*) FROM {bookings}');

        $second = bk_create($owner, ['start_time' => '14:00', 'guest_of_honor_name' => bk_tag('Second')]);

        Assert::same(0, $second['id'], 'the second booking must not be created');
        Assert::same(
            $before,
            (int) DB::value('SELECT COUNT(*) FROM {bookings}'),
            'and no row may be left behind'
        );

        // The refusal says what is free, so the operator is not left guessing.
        $form = $owner->get('/bookings/new?date=' . bk_saturday() . '&package_id=' . bk_package()['id']);

        Assert::same(200, $form['status']);
    } finally {
        bk_cleanup([$first['id'], $second['id'] ?? 0]);
    }
});

Harness::test('a second room makes the same time bookable again', function () use ($owner, $room): void {
    // The positive control for the test above: refusing everything would
    // satisfy it perfectly.
    $first = bk_create($owner, ['start_time' => '14:00']);

    Assert::greaterThan(0, $first['id']);

    $extra = bk_room();

    try {
        $second = bk_create($owner, ['start_time' => '14:00']);

        Assert::greaterThan(0, $second['id'], 'with two rooms, two parties fit at the same time');
    } finally {
        bk_cleanup([$first['id'], $second['id'] ?? 0]);
        DB::delete('resources', 'id = :id', ['id' => $extra]);
    }
});

Harness::test('a date inside the notice period is refused, and says when is possible', function () use ($owner): void {
    $before = (int) DB::value('SELECT COUNT(*) FROM {bookings}');

    $created = bk_create($owner, ['event_date' => R::today(), 'start_time' => '14:00']);

    Assert::same(0, $created['id'], 'a party today cannot be taken on a seven-day package');
    Assert::same($before, (int) DB::value('SELECT COUNT(*) FROM {bookings}'));
});

Harness::test('a party larger than the room is refused with the room\'s size', function () use ($owner): void {
    $created = bk_create($owner, ['guest_count' => '500', 'start_time' => '14:00']);

    Assert::same(0, $created['id']);
});

Harness::test('the availability endpoint answers JSON for a free day and a closed one', function () use (
    $owner
): void {
    $open = $owner->get('/bookings/availability?date=' . bk_saturday()
        . '&package_id=' . bk_package()['id'] . '&guest_count=12');

    Assert::same(200, $open['status']);

    $payload = json_decode($open['body'], true);

    Assert::true(is_array($payload), 'the endpoint answers JSON');
    Assert::true($payload['ok'], 'the fixture Saturday is open');
    Assert::notEmpty($payload['slots']);
    Assert::hasKey('start_label', $payload['slots'][0]);

    // A refusal carries a reason the public form can branch on, and
    // alternative dates rather than a dead end.
    $past = $owner->get('/bookings/availability?date=' . A::addDays(R::today(), -1)
        . '&package_id=' . bk_package()['id'] . '&guest_count=12');

    $refused = json_decode($past['body'], true);

    Assert::false($refused['ok']);
    Assert::inArray($refused['reason'], A::REASONS);
    Assert::notEmpty($refused['message'], 'and a sentence a person can read');
});

// ------------------------------------------------- status and the money ladder

Harness::test('A STATUS THAT CLAIMS MONEY CANNOT BE CHOSEN BY HAND', function () use ($owner): void {
    // Otherwise the front desk marks a party paid, the run sheet says the
    // balance is clear, and nobody asks the family for $400 on the day.
    $created = bk_create($owner, ['start_time' => '11:00']);

    Assert::greaterThan(0, $created['id']);

    try {
        foreach (['deposit_paid', 'paid'] as $forbidden) {
            $owner->postWithToken(
                '/bookings/' . $created['id'],
                '/bookings/' . $created['id'] . '/status',
                ['status' => $forbidden]
            );

            Assert::notSame(
                $forbidden,
                (string) bk_row($created['id'])['status'],
                $forbidden . ' must come from the payment ledger'
            );
        }

        // And the positive control: a status that IS on the form moves.
        $response = $owner->postWithToken(
            '/bookings/' . $created['id'],
            '/bookings/' . $created['id'] . '/status',
            ['status' => 'confirmed']
        );

        Assert::same(302, $response['status']);
        Assert::same('confirmed', (string) bk_row($created['id'])['status']);
        Assert::notNull(bk_row($created['id'])['confirmed_at'], 'and the confirmation is stamped');
        Assert::true(bk_audit('booking.status', $created['id']), 'and audited');
    } finally {
        bk_cleanup([$created['id']]);
    }
});

Harness::test('confirming a party seeds its checklist, allergies first', function () use ($owner): void {
    $created = bk_create($owner, [
        'start_time' => '11:00',
        'allergies'  => 'Peanuts and sesame',
    ]);

    Assert::greaterThan(0, $created['id']);

    try {
        $owner->postWithToken(
            '/bookings/' . $created['id'],
            '/bookings/' . $created['id'] . '/status',
            ['status' => 'confirmed']
        );

        $items = DB::all(
            'SELECT title FROM {booking_tasks} WHERE booking_id = :id ORDER BY sort_order',
            ['id' => $created['id']]
        );

        Assert::greaterThan(3, count($items));
        Assert::contains('allergies', strtolower((string) $items[0]['title']), 'the allergy briefing leads');

        // Confirming twice must not produce two of everything.
        $owner->postWithToken(
            '/bookings/' . $created['id'],
            '/bookings/' . $created['id'] . '/status',
            ['status' => 'held']
        );
        $owner->postWithToken(
            '/bookings/' . $created['id'],
            '/bookings/' . $created['id'] . '/status',
            ['status' => 'confirmed']
        );

        Assert::same(
            count($items),
            (int) DB::value(
                'SELECT COUNT(*) FROM {booking_tasks} WHERE booking_id = :id',
                ['id' => $created['id']]
            ),
            'the checklist is seeded once'
        );
    } finally {
        bk_cleanup([$created['id']]);
    }
});

Harness::test('recording money advances the status and settles the balance', function () use ($owner): void {
    $created = bk_create($owner, ['start_time' => '11:00', 'guest_count' => '12']);

    Assert::greaterThan(0, $created['id']);

    try {
        $row     = bk_row($created['id']);
        $total   = (int) $row['total_cents'];
        $deposit = (int) $row['deposit_cents'];

        Assert::greaterThan(0, $deposit, 'precondition: the package asks for a deposit');

        $owner->postWithToken('/bookings/' . $created['id'], '/bookings/' . $created['id'] . '/payments', [
            'amount' => number_format($deposit / 100, 2, '.', ''),
            'type'   => 'deposit',
            'method' => 'card',
        ]);

        $afterDeposit = bk_row($created['id']);

        Assert::same('deposit_paid', (string) $afterDeposit['status']);
        Assert::notNull($afterDeposit['deposit_paid_at']);
        Assert::same($total - $deposit, (int) $afterDeposit['balance_due_cents']);
        Assert::null($afterDeposit['paid_in_full_at']);

        $owner->postWithToken('/bookings/' . $created['id'], '/bookings/' . $created['id'] . '/payments', [
            'amount' => number_format(($total - $deposit) / 100, 2, '.', ''),
            'type'   => 'balance',
        ]);

        $afterBalance = bk_row($created['id']);

        Assert::same('paid', (string) $afterBalance['status']);
        Assert::same(0, (int) $afterBalance['balance_due_cents']);
        Assert::notNull($afterBalance['paid_in_full_at']);
        Assert::true(bk_audit('booking.payment', $created['id']));
    } finally {
        bk_cleanup([$created['id']]);
    }
});

Harness::test('a refund takes the status and the balance back', function () use ($owner): void {
    // The money came back out, so the run sheet must stop saying the deposit
    // is held — otherwise nobody asks for it again.
    $created = bk_create($owner, ['start_time' => '11:00']);

    Assert::greaterThan(0, $created['id']);

    try {
        $deposit = (int) bk_row($created['id'])['deposit_cents'];
        $amount  = number_format($deposit / 100, 2, '.', '');

        $owner->postWithToken('/bookings/' . $created['id'], '/bookings/' . $created['id'] . '/payments', [
            'amount' => $amount,
            'type'   => 'deposit',
        ]);

        Assert::same('deposit_paid', (string) bk_row($created['id'])['status']);

        $owner->postWithToken('/bookings/' . $created['id'], '/bookings/' . $created['id'] . '/payments', [
            'amount' => $amount,
            'type'   => 'refund',
        ]);

        $after = bk_row($created['id']);

        Assert::same('confirmed', (string) $after['status'], 'back off the money ladder');
        Assert::null($after['deposit_paid_at'], 'and the deposit is no longer held');
        Assert::same(
            (int) $after['total_cents'],
            (int) $after['balance_due_cents'],
            'with the whole figure owed again'
        );
    } finally {
        bk_cleanup([$created['id']]);
    }
});

Harness::test('deleting a mistyped payment re-derives the balance', function () use ($owner): void {
    // The booking's money columns are derived from the ledger rather than
    // adjusted in place, which is what makes a correction safe.
    $created = bk_create($owner, ['start_time' => '11:00']);

    Assert::greaterThan(0, $created['id']);

    try {
        $total = (int) bk_row($created['id'])['total_cents'];

        $owner->postWithToken('/bookings/' . $created['id'], '/bookings/' . $created['id'] . '/payments', [
            'amount' => '5000.00',
            'type'   => 'balance',
        ]);

        Assert::same('paid', (string) bk_row($created['id'])['status'], 'precondition: overpaid');

        $paymentId = (int) DB::value(
            'SELECT id FROM {booking_payments} WHERE booking_id = :id ORDER BY id DESC LIMIT 1',
            ['id' => $created['id']]
        );

        $owner->postWithToken(
            '/bookings/' . $created['id'],
            '/bookings/' . $created['id'] . '/payments/delete',
            ['payment_id' => (string) $paymentId]
        );

        $after = bk_row($created['id']);

        Assert::same($total, (int) $after['balance_due_cents'], 'the whole figure is owed again');
        Assert::null($after['paid_in_full_at']);
        Assert::same(
            0,
            (int) DB::value(
                'SELECT COUNT(*) FROM {booking_payments} WHERE booking_id = :id',
                ['id' => $created['id']]
            )
        );
    } finally {
        bk_cleanup([$created['id']]);
    }
});

Harness::test('no payment endpoint stores anything that looks like a card', function () use ($owner): void {
    // There is no column in this schema that could hold a card number, and
    // this is the endpoint somebody would be tempted to add one to. The
    // reference is an opaque string from the card machine; a PAN posted into
    // it is stored as the string it is and nothing wider is accepted.
    $created = bk_create($owner, ['start_time' => '11:00']);

    Assert::greaterThan(0, $created['id']);

    try {
        $columns = DB::all('SHOW COLUMNS FROM {booking_payments}');
        $names   = array_map(static fn (array $c): string => strtolower((string) $c['Field']), $columns);

        foreach (['card', 'pan', 'cvv', 'cvc', 'expiry', 'exp_month', 'track'] as $forbidden) {
            foreach ($names as $name) {
                Assert::false(
                    str_contains($name, $forbidden),
                    'cc_booking_payments.' . $name . ' must not exist'
                );
            }
        }
    } finally {
        bk_cleanup([$created['id']]);
    }
});

// --------------------------------------------------------------- cancelling

Harness::test('CANCELLING FREES THE ROOM', function () use ($owner): void {
    // A room given back at four o'clock on Friday has to be bookable at five.
    // This is the single most common reason a venue goes looking for
    // availability at all.
    $first = bk_create($owner, ['start_time' => '14:00']);

    Assert::greaterThan(0, $first['id']);

    try {
        $blocked = bk_create($owner, ['start_time' => '14:00']);

        Assert::same(0, $blocked['id'], 'precondition: the slot is taken');

        $owner->postWithToken('/bookings/' . $first['id'], '/bookings/' . $first['id'] . '/cancel', [
            'cancellation_reason' => 'The family cancelled',
        ]);

        $cancelled = bk_row($first['id']);

        Assert::same('cancelled', (string) $cancelled['status']);
        Assert::notNull($cancelled['cancelled_at']);
        Assert::same('The family cancelled', (string) $cancelled['cancellation_reason']);
        Assert::true(bk_audit('booking.cancelled', $first['id']));

        // The allocation rows are kept on purpose, so a reinstated party goes
        // back into the room it had.
        Assert::greaterThan(
            0,
            (int) DB::value(
                'SELECT COUNT(*) FROM {booking_resources} WHERE booking_id = :id',
                ['id' => $first['id']]
            ),
            'the allocation is kept as history'
        );

        $after = bk_create($owner, ['start_time' => '14:00']);

        Assert::greaterThan(0, $after['id'], 'and the slot is bookable again');

        bk_cleanup([$after['id']]);
    } finally {
        bk_cleanup([$first['id'], $blocked['id'] ?? 0]);
    }
});

Harness::test('REINSTATING A CANCELLED BOOKING RE-CHECKS THE ROOM', function () use ($owner): void {
    // The room was freed the moment it was cancelled — that is the point — so
    // somebody else may be in it. This is the one status change availability
    // can refuse.
    $first = bk_create($owner, ['start_time' => '14:00']);

    Assert::greaterThan(0, $first['id']);

    $owner->postWithToken('/bookings/' . $first['id'], '/bookings/' . $first['id'] . '/cancel', [
        'cancellation_reason' => 'Freeing the room for this test',
    ]);

    $replacement = bk_create($owner, ['start_time' => '14:00']);

    try {
        Assert::greaterThan(0, $replacement['id'], 'precondition: somebody else took the slot');

        $owner->postWithToken('/bookings/' . $first['id'], '/bookings/' . $first['id'] . '/status', [
            'status' => 'inquiry',
        ]);

        Assert::same(
            'cancelled',
            (string) bk_row($first['id'])['status'],
            'it cannot come back into a room that is now somebody else\'s'
        );

        // The positive control: with the replacement gone, it reinstates.
        bk_cleanup([$replacement['id']]);

        $owner->postWithToken('/bookings/' . $first['id'], '/bookings/' . $first['id'] . '/status', [
            'status' => 'inquiry',
        ]);

        $back = bk_row($first['id']);

        Assert::same('inquiry', (string) $back['status']);
        Assert::null($back['cancelled_at'], 'and the cancellation is no longer the current state');
        Assert::null($back['cancellation_reason']);
    } finally {
        bk_cleanup([$first['id'], $replacement['id']]);
    }
});

Harness::test('a cancellation needs a reason', function () use ($owner): void {
    $created = bk_create($owner, ['start_time' => '11:00']);

    Assert::greaterThan(0, $created['id']);

    try {
        $owner->postWithToken('/bookings/' . $created['id'], '/bookings/' . $created['id'] . '/cancel', [
            'cancellation_reason' => '',
        ]);

        Assert::notSame(
            'cancelled',
            (string) bk_row($created['id'])['status'],
            'a cancellation with no reason recorded is a cancellation nobody can explain later'
        );
    } finally {
        bk_cleanup([$created['id']]);
    }
});

// ------------------------------------------------------------- permissions

Harness::test('FRONT DESK CANNOT CANCEL OR TAKE MONEY, BUT CAN STILL TAKE A BOOKING', function () use (
    $owner,
    $deskBrowser
): void {
    // The seeded Front Desk role holds bookings_manage and checkin and
    // neither bookings_cancel nor payments_record. Three gates, one role.
    $created = bk_create($deskBrowser, ['start_time' => '11:00', 'guest_of_honor_name' => bk_tag('Desk')]);

    Assert::greaterThan(0, $created['id'], 'the desk takes bookings — that is the job');

    try {
        $cancel = $deskBrowser->postWithToken(
            '/bookings/' . $created['id'],
            '/bookings/' . $created['id'] . '/cancel',
            ['cancellation_reason' => 'Trying it on']
        );

        Assert::same(403, $cancel['status']);
        Assert::notSame('cancelled', (string) bk_row($created['id'])['status']);

        $payment = $deskBrowser->postWithToken(
            '/bookings/' . $created['id'],
            '/bookings/' . $created['id'] . '/payments',
            ['amount' => '100.00', 'type' => 'deposit']
        );

        Assert::same(403, $payment['status']);
        Assert::same(
            0,
            (int) DB::value(
                'SELECT COUNT(*) FROM {booking_payments} WHERE booking_id = :id',
                ['id' => $created['id']]
            )
        );

        // And the owner, who holds both, can do each of them.
        Assert::same(
            302,
            $owner->postWithToken(
                '/bookings/' . $created['id'],
                '/bookings/' . $created['id'] . '/payments',
                ['amount' => '100.00', 'type' => 'deposit']
            )['status'],
            'the permissions are real, not a blanket refusal'
        );
    } finally {
        bk_cleanup([$created['id']]);
    }
});

Harness::test('A ROLE WITHOUT view_revenue SEES THE ALLERGIES AND NONE OF THE MONEY', function () use (
    $owner,
    $deskBrowser
): void {
    // The separation this whole permission exists for: a nut allergy is not
    // financial information, and the host printing the run sheet has to know
    // about it.
    //
    // Two layers hold this — scrubRow() empties the money columns server-side
    // and each view checks `$showMoney` as well — and it is their pair this
    // test pins. MEASURED: removing either alone leaves the other answering
    // and this still passes; removing both fails it.
    $allergy = bk_tag('Peanutsecret');
    $created = bk_create($owner, ['start_time' => '11:00', 'allergies' => $allergy]);

    Assert::greaterThan(0, $created['id']);

    try {
        $total = money((int) bk_row($created['id'])['total_cents']);

        $ownerPage = $owner->get('/bookings/' . $created['id']);

        Assert::contains($total, $ownerPage['body'], 'precondition: the owner sees the figure');
        Assert::contains($allergy, $ownerPage['body']);

        $deskPage = $deskBrowser->get('/bookings/' . $created['id']);

        Assert::same(200, $deskPage['status']);
        Assert::contains($allergy, $deskPage['body'], 'the desk sees the allergy note');
        Assert::notContains($total, $deskPage['body'], 'and not the total');
        Assert::notContains('Still owed', $deskPage['body'], 'nor the money panel at all');

        $deskSheet = $deskBrowser->get('/bookings/' . $created['id'] . '/run-sheet');

        Assert::same(200, $deskSheet['status']);
        Assert::contains($allergy, $deskSheet['body'], 'and the run sheet it prints carries it');
        Assert::notContains($total, $deskSheet['body']);

        $deskList = $deskBrowser->get('/bookings?view=all');

        Assert::notContains($total, $deskList['body'], 'nor does the list');
        Assert::notContains('Booked value', $deskList['body'], 'nor the money tiles above it');
    } finally {
        bk_cleanup([$created['id']]);
    }
});

Harness::test('a read-only role may look and may not touch', function () use ($owner, $viewer): void {
    $created = bk_create($owner, ['start_time' => '11:00']);

    Assert::greaterThan(0, $created['id']);

    try {
        Assert::same(200, $viewer->get('/bookings')['status'], 'read only may look at the diary');
        Assert::same(200, $viewer->get('/bookings/' . $created['id'])['status']);
        Assert::same(403, $viewer->get('/bookings/new')['status'], 'but not take a booking');

        // With a REAL token minted from a page this role can open, so the CSRF
        // middleware does not answer first and leave the permission gate
        // untested.
        foreach ([
            ''                 => ['type' => 'birthday'],
            '/status'          => ['status' => 'confirmed'],
            '/tasks'           => ['title' => 'Something'],
            '/tasks/toggle'    => ['task_id' => '1'],
            '/guests'          => ['name' => 'Someone'],
            '/host'            => ['host_user_id' => ''],
        ] as $path => $body) {
            $response = $viewer->postWithToken(
                '/bookings/' . $created['id'],
                '/bookings/' . $created['id'] . $path,
                $body
            );

            Assert::same(403, $response['status'], 'POST ' . ($path === '' ? '/' : $path));
        }

        Assert::same('inquiry', (string) bk_row($created['id'])['status'], 'and nothing changed');
        Assert::same(
            0,
            (int) DB::value(
                'SELECT COUNT(*) FROM {booking_tasks} WHERE booking_id = :id',
                ['id' => $created['id']]
            ),
            'including the checklist, which was briefly reachable with view_bookings alone'
        );
    } finally {
        bk_cleanup([$created['id']]);
    }
});

// ------------------------------------------------ the checklist and guests

Harness::test('the checklist can be added to, ticked off and put back', function () use ($owner): void {
    $created = bk_create($owner, ['start_time' => '11:00']);

    Assert::greaterThan(0, $created['id']);

    try {
        $owner->postWithToken('/bookings/' . $created['id'], '/bookings/' . $created['id'] . '/tasks', [
            'title' => 'Order the balloons',
        ]);

        $task = DB::first(
            'SELECT id, completed_at FROM {booking_tasks} WHERE booking_id = :id ORDER BY id DESC LIMIT 1',
            ['id' => $created['id']]
        );

        Assert::notNull($task);

        $owner->postWithToken(
            '/bookings/' . $created['id'],
            '/bookings/' . $created['id'] . '/tasks/toggle',
            ['task_id' => (string) $task['id']]
        );

        Assert::notNull(
            DB::value('SELECT completed_at FROM {booking_tasks} WHERE id = :id', ['id' => $task['id']]),
            'ticked'
        );

        $owner->postWithToken(
            '/bookings/' . $created['id'],
            '/bookings/' . $created['id'] . '/tasks/toggle',
            ['task_id' => (string) $task['id']]
        );

        Assert::null(
            DB::value('SELECT completed_at FROM {booking_tasks} WHERE id = :id', ['id' => $task['id']]),
            'and put back'
        );
    } finally {
        bk_cleanup([$created['id']]);
    }
});

Harness::test('a checklist item on another booking cannot be ticked through this one', function () use (
    $owner
): void {
    $mine   = bk_create($owner, ['start_time' => '11:00']);
    $theirs = bk_create($owner, ['start_time' => '16:00']);

    Assert::greaterThan(0, $mine['id']);
    Assert::greaterThan(0, $theirs['id']);

    try {
        $owner->postWithToken('/bookings/' . $theirs['id'], '/bookings/' . $theirs['id'] . '/tasks', [
            'title' => 'Not yours to tick',
        ]);

        $taskId = (int) DB::value(
            'SELECT id FROM {booking_tasks} WHERE booking_id = :id ORDER BY id DESC LIMIT 1',
            ['id' => $theirs['id']]
        );

        $owner->postWithToken(
            '/bookings/' . $mine['id'],
            '/bookings/' . $mine['id'] . '/tasks/toggle',
            ['task_id' => (string) $taskId]
        );

        Assert::null(
            DB::value('SELECT completed_at FROM {booking_tasks} WHERE id = :id', ['id' => $taskId]),
            'the item belongs to another booking and must be untouched'
        );
    } finally {
        bk_cleanup([$mine['id'], $theirs['id']]);
    }
});

Harness::test('guests can be listed and removed, and the phone is normalised', function () use ($owner): void {
    $created = bk_create($owner, ['start_time' => '11:00']);

    Assert::greaterThan(0, $created['id']);

    try {
        $owner->postWithToken('/bookings/' . $created['id'], '/bookings/' . $created['id'] . '/guests', [
            'name'         => 'Amara',
            'age'          => '7',
            'parent_name'  => 'Joy',
            'parent_phone' => '(845) 555-0199',
            'rsvp_status'  => 'yes',
        ]);

        $guest = DB::first(
            'SELECT * FROM {booking_guests} WHERE booking_id = :id ORDER BY id DESC LIMIT 1',
            ['id' => $created['id']]
        );

        Assert::notNull($guest);
        Assert::same('Amara', (string) $guest['name']);
        Assert::same(
            '+18455550199',
            (string) $guest['parent_phone'],
            'stored E.164, so the same number typed two ways reaches one guest'
        );

        $page = $owner->get('/bookings/' . $created['id']);

        Assert::contains('Amara', $page['body']);

        $owner->postWithToken(
            '/bookings/' . $created['id'],
            '/bookings/' . $created['id'] . '/guests/delete',
            ['guest_id' => (string) $guest['id']]
        );

        Assert::same(
            0,
            (int) DB::value(
                'SELECT COUNT(*) FROM {booking_guests} WHERE booking_id = :id',
                ['id' => $created['id']]
            )
        );
    } finally {
        bk_cleanup([$created['id']]);
    }
});

// ------------------------------------------------- editing and the run sheet

Harness::test('editing re-prices the booking and keeps the money already taken', function () use ($owner): void {
    $created = bk_create($owner, ['start_time' => '11:00', 'guest_count' => '12']);

    Assert::greaterThan(0, $created['id']);

    try {
        $owner->postWithToken('/bookings/' . $created['id'], '/bookings/' . $created['id'] . '/payments', [
            'amount' => '100.00',
            'type'   => 'deposit',
        ]);

        $response = $owner->postWithToken(
            '/bookings/' . $created['id'] . '/edit',
            '/bookings/' . $created['id'],
            [
                'type'        => 'birthday',
                'package_id'  => (string) bk_package()['id'],
                'event_date'  => bk_saturday(),
                'start_time'  => '11:00',
                'guest_count' => '20',
            ]
        );

        Assert::same(302, $response['status']);

        $after    = bk_row($created['id']);
        $package  = bk_package();
        $expected = (int) $package['base_price_cents']
            + 12 * (int) $package['additional_guest_cents'];

        Assert::same(20, (int) $after['guest_count']);
        Assert::same($expected, (int) $after['subtotal_cents'], 're-priced for the new headcount');
        Assert::same(
            (int) $after['total_cents'] - 10000,
            (int) $after['balance_due_cents'],
            'and the $100 already taken is still taken'
        );
        Assert::true(bk_audit('booking.updated', $created['id']));
    } finally {
        bk_cleanup([$created['id']]);
    }
});

Harness::test('a completed booking cannot be re-priced', function () use ($owner): void {
    // The party has happened; its figures are what it was sold at.
    $created = bk_create($owner, ['start_time' => '11:00']);

    Assert::greaterThan(0, $created['id']);

    try {
        foreach (['confirmed', 'completed'] as $step) {
            $owner->postWithToken(
                '/bookings/' . $created['id'],
                '/bookings/' . $created['id'] . '/status',
                ['status' => $step]
            );
        }

        Assert::same('completed', (string) bk_row($created['id'])['status'], 'precondition');

        $before = (int) bk_row($created['id'])['total_cents'];
        $edit   = $owner->get('/bookings/' . $created['id'] . '/edit');

        Assert::same(302, $edit['status'], 'the form is not even offered');

        $owner->postWithToken('/bookings/' . $created['id'], '/bookings/' . $created['id'], [
            'type'        => 'birthday',
            'package_id'  => (string) bk_package()['id'],
            'event_date'  => bk_saturday(),
            'start_time'  => '11:00',
            'guest_count' => '200',
        ]);

        Assert::same($before, (int) bk_row($created['id'])['total_cents'], 'and the figures are untouched');
    } finally {
        bk_cleanup([$created['id']]);
    }
});

Harness::test('the run sheet renders and records that it was printed', function () use ($owner): void {
    $created = bk_create($owner, [
        'start_time'          => '11:00',
        'guest_of_honor_name' => 'Priya',
        'allergies'           => 'Sesame',
        'cake_notes'          => 'Out at 3pm',
    ]);

    Assert::greaterThan(0, $created['id']);

    try {
        $sheet = $owner->get('/bookings/' . $created['id'] . '/run-sheet');

        Assert::same(200, $sheet['status']);
        Assert::contains('ALLERGIES: Sesame', $sheet['body'], 'the allergies lead the sheet');
        Assert::contains('Priya', $sheet['body']);
        Assert::contains('Out at 3pm', $sheet['body']);
        Assert::contains('Timeline', $sheet['body']);
        Assert::notContains(
            'onclick',
            $sheet['body'],
            'and no inline handler: the CSP forbids inline script'
        );

        Assert::notNull(
            bk_row($created['id'])['run_sheet_printed_at'],
            'so the venue can see when the sheet last went out'
        );
    } finally {
        bk_cleanup([$created['id']]);
    }
});

Harness::test('a host can be assigned and cleared', function () use ($owner): void {
    $created = bk_create($owner, ['start_time' => '11:00']);
    $hostId  = (int) DB::value("SELECT id FROM {users} WHERE email = 'desk@test.invalid'");

    Assert::greaterThan(0, $created['id']);
    Assert::greaterThan(0, $hostId);

    try {
        $owner->postWithToken('/bookings/' . $created['id'], '/bookings/' . $created['id'] . '/host', [
            'host_user_id' => (string) $hostId,
        ]);

        Assert::same($hostId, (int) bk_row($created['id'])['host_user_id']);
        Assert::true(bk_audit('booking.host_assigned', $created['id']));

        // And the "no host" view no longer carries it.
        $unassigned = $owner->get('/bookings?view=unassigned');

        Assert::notContains('/bookings/' . $created['id'] . '"', $unassigned['body']);

        $owner->postWithToken('/bookings/' . $created['id'], '/bookings/' . $created['id'] . '/host', [
            'host_user_id' => '',
        ]);

        Assert::null(bk_row($created['id'])['host_user_id']);
    } finally {
        bk_cleanup([$created['id']]);
    }
});

// ----------------------------------------------------------------- cleanup

Harness::test('the suite tidies up after itself', function () use ($viewerId): void {
    // The HTTP suites share one database and nothing resets between them, so
    // a few dozen bookings and a spare room left behind would make a second
    // run read differently from the first.
    DB::delete('bookings', 'booking_number LIKE :p', ['p' => 'B-%']);
    DB::delete('resources', 'type = :t', ['t' => 'table']);
    DB::delete('party_packages', 'id = :id', ['id' => (int) bk_package()['id']]);
    DB::delete('attractions', 'id = :id', ['id' => (int) bk_package()['main_attraction_id']]);
    DB::delete('users', 'id = :id', ['id' => $viewerId]);

    // Scoped to THIS suite's own numbers. The demo seed legitimately plants
    // two bookings of its own (DEMO-0006 and DEMO-0007) and the seed tests
    // count them — asserting an empty table here would either fail or, worse,
    // tempt somebody into deleting them.
    Assert::same(
        0,
        (int) DB::value('SELECT COUNT(*) FROM {bookings} WHERE booking_number LIKE :p', ['p' => 'B-%']),
        'no booking of this suite\'s should survive'
    );
    Assert::same(
        0,
        (int) DB::value(
            'SELECT COUNT(*) FROM {booking_resources} br
               JOIN {bookings} b ON b.id = br.booking_id
              WHERE b.booking_number LIKE :p',
            ['p' => 'B-%']
        ),
        'and their allocations go with them, through the foreign key'
    );
    Assert::same(
        0,
        (int) DB::value("SELECT COUNT(*) FROM {resources} WHERE type = 'table'"),
        'and this suite\'s rooms are gone'
    );
    Assert::same(
        0,
        (int) DB::value(
            'SELECT COUNT(*) FROM {party_packages} WHERE slug LIKE :p',
            ['p' => 'bookings-test-package-%']
        ),
        'with the package and attraction it made for itself'
    );
});
