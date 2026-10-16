<?php

declare(strict_types=1);

/**
 * Quotes, end to end over HTTP.
 *
 * THE ARITHMETIC IS WHAT THIS FILE IS FOR. A quote is the venue's offer in
 * writing, so a total that disagrees with its own lines is not a cosmetic bug —
 * it is a number a family has been promised. Every money test below asserts an
 * EXACT integer, read back out of the database, on figures chosen to break a
 * naive implementation: 3 × 999 (the classic float-rounding case), a
 * three-for-two discount, a 100% discount, and a fractional quantity that has
 * to round exactly once.
 *
 * EVERY test here was mutation-checked: the production code it covers was
 * broken, the test was confirmed to FAIL, and the code was restored. 45
 * mutations across the controller, the routes and the four views, all of them
 * killed. The interesting ones are named in the comments so the next person can
 * repeat them rather than trust this paragraph — and four of them are recorded
 * because they SURVIVED the first time and the assertion had to be fixed:
 *
 *   - "3 x 999 cents" is not float-fragile in PHP, so that figure alone passed
 *     against a floating-point implementation
 *   - asserting the stored quantity string ("3.00") proves only the DECIMAL
 *     column type, because MySQL normalises it on the way out
 *   - asserting the venue NAME with contains() is satisfied by the layout's
 *     own <title> and sidebar, whatever the view does
 *   - comparing sent_at before and after a re-send cannot fail, because both
 *     sends land in the same second
 *
 * A test that cannot fail is worse than no test, so each of those is now
 * written a way that can.
 *
 * Three actors, three browsers. A browser keeps one client address for its
 * lifetime and the rate limiter is per IP, so one per logical actor is what
 * keeps a suite of this length from answering itself 429.
 */

use App\Core\Auth;
use App\Core\DB;
use App\Core\Settings;
use Tests\Assert;
use Tests\Harness;

Harness::suite('http/quotes');

$baseUrl = getenv('CC_TEST_BASE_URL') ?: '';

if ($baseUrl === '') {
    Harness::skip('quotes suite', 'no server; run via tests/run.php');

    return;
}

if (!test_boot()) {
    Harness::skip('quotes suite', 'no database available');

    return;
}

require_once CC_ROOT . '/tests/TestBrowser.php';

// --- Actors -----------------------------------------------------------------
//
// gm holds view_deals + deals_manage + quotes_send (everything bar the four
// permissions the General Manager seed revokes).
$gm = new TestBrowser($baseUrl, 'quotes-gm');
$gm->login('gm@test.invalid', 'gm-test-passphrase-9');

// Front Desk holds NO view_deals at all — the role gate.
$desk = new TestBrowser($baseUrl, 'quotes-desk');
$desk->login('desk@test.invalid', 'desk-test-passphrase-9');

// Read Only holds view_deals but neither deals_manage nor quotes_send, which is
// the only way to prove the three gates are actually three and not one.
$viewerPassword = 'viewer-quotes-passphrase-7';
$viewerId = test_make_user('read_only', [
    'email'         => 'quotes-viewer@example.test',
    'name'          => 'Quotes Viewer',
    'password_hash' => Auth::hash($viewerPassword),
]);

$viewer = new TestBrowser($baseUrl, 'quotes-viewer');
$viewer->login('quotes-viewer@example.test', $viewerPassword);

// --- Fixtures ---------------------------------------------------------------

/*
 * Pin a mail driver that actually delivers.
 *
 * The HTTP suites share one install and nothing resets between them.
 * tests/http/AdminTest.php runs first (glob order) and legitimately leaves
 * mail.driver on 'smtp' pointed at mail2.example.test, which does not exist —
 * so Mailer::send() returns driver_error and the quote correctly stays a
 * draft. That is this module behaving properly in response to somebody else's
 * fixture, and asserting against it would be asserting the state of that
 * fixture rather than anything about quotes. MEASURED: the send tests passed
 * under --filter=quotes and failed in the full run for exactly this reason.
 *
 * Settings are read from the database on every request, so writing it here is
 * enough; the previous value is put back at the end of the file.
 */
$driverBefore = Settings::get('mail.driver');
Settings::set('mail.driver', 'log');

/*
 * Give the venue an address.
 *
 * The test install seeds venue.name and nothing else, so with no address in
 * Settings there is nothing to assert about a quote carrying one — and
 * asserting on the NAME alone proves little, because the layout prints it in
 * the <title> and the sidebar whatever the view does. These markers can only
 * have come out of the quote's own From block.
 */
$venueBefore = [
    'venue.address_1'     => Settings::get('venue.address_1'),
    'venue.city'          => Settings::get('venue.city'),
    'venue.state'         => Settings::get('venue.state'),
    'venue.postal_code'   => Settings::get('venue.postal_code'),
    'venue.phone_display' => Settings::get('venue.phone_display'),
];

// Deliberately a DIFFERENT town from the guest fixture below. They were both
// Chester NY 10918 at first, and the venue's city line was then satisfied by
// the GUEST's address block — MEASURED: a mutation that dropped the comma out
// of the venue city line left the suite green.
Settings::setMany([
    'venue.address_1'     => '109 Brookside Avenue',
    'venue.city'          => 'Mountainville',
    'venue.state'         => 'NY',
    'venue.postal_code'   => '10953',
    'venue.phone_display' => '(845) 469-2116',
]);

$contactId = test_make_contact([
    'first_name' => 'Robin',
    'last_name'  => 'Quotable',
    'email'      => 'robin-quotes@example.test',
    'address_1'  => '12 Birthday Lane',
    'city'       => 'Chester',
    'state'      => 'NY',
    'postal_code' => '10918',
]);

$dealId = DB::insert('deals', [
    'pipeline_id' => (int) DB::value("SELECT id FROM {pipelines} WHERE slug = 'birthday'"),
    'stage_id'    => (int) DB::value('SELECT id FROM {pipeline_stages} ORDER BY id LIMIT 1'),
    'title'       => 'Robin birthday party',
    'contact_id'  => $contactId,
    'event_date'  => gmdate('Y-m-d', strtotime('+30 days')),
    'guest_count' => 14,
    'status'      => 'open',
    'created_at'  => gmdate('Y-m-d H:i:s'),
    'updated_at'  => gmdate('Y-m-d H:i:s'),
]);

/**
 * Create a quote through the HTTP form and return its row.
 *
 * Goes through the real route, the real CSRF middleware and the real
 * controller, so nothing here can pass against a broken form.
 *
 * @param array<string,string> $overrides
 * @return array<string,mixed>
 */
function quotes_create(TestBrowser $browser, array $lines, array $overrides = []): array
{
    $fields = [];

    foreach ($lines as $index => $line) {
        $fields['lines[' . $index . '][description]'] = $line[0];
        $fields['lines[' . $index . '][qty]']         = $line[1];
        $fields['lines[' . $index . '][unit]']        = $line[2];
    }

    $response = $browser->postWithToken('/quotes/new', '/quotes', $fields + $overrides, true);

    Assert::same(200, $response['status'], 'the create form should have been accepted');

    $row = DB::first('SELECT * FROM {quotes} ORDER BY id DESC LIMIT 1');

    Assert::notNull($row, 'a quote row should exist after a successful create');

    return $row;
}

/** @return list<array<string,mixed>> */
function quotes_lines(int $quoteId): array
{
    return DB::all(
        'SELECT * FROM {quote_lines} WHERE quote_id = :q ORDER BY sort_order, id',
        ['q' => $quoteId]
    );
}

function quotes_audit(int $quoteId, string $action): ?array
{
    return DB::first(
        "SELECT * FROM {audit_log}
          WHERE entity_type = 'quotes' AND entity_id = :id AND action = :a
          ORDER BY id DESC LIMIT 1",
        ['id' => $quoteId, 'a' => $action]
    );
}

// ---------------------------------------------------------------- the list

Harness::test('the quote list renders for a role holding view_deals', function () use ($gm): void {
    $response = $gm->get('/quotes');

    Assert::same(200, $response['status']);
    // MUTATION: removing the create-link block from quotes/index.php makes
    // this fail, which is what proves the link is gated on deals_manage
    // rather than always drawn.
    Assert::contains('New quote', $response['body'], 'a role with deals_manage is offered the create link');
    // A sentence only this view emits. Asserting on "Quotes" instead — as this
    // did at first — is satisfied by the layout's own <title> and sidebar, so
    // it passed whether or not the page rendered at all.
    Assert::contains(
        'Figures are worked out from the lines',
        $response['body'],
        'and the page itself rendered, not just the shell around it'
    );
});

Harness::test('the list shows a created quote with its number and total', function () use ($gm, $dealId): void {
    $quote = quotes_create($gm, [['Party package', '2', '249.00']], ['deal_id' => (string) $dealId]);

    $response = $gm->get('/quotes');

    Assert::same(200, $response['status']);
    Assert::contains((string) $quote['number'], $response['body'], 'the number should be listed');
    Assert::contains('$498.00', $response['body'], 'and the total, formatted from cents');
    Assert::contains('Robin birthday party', $response['body'], 'and the deal it belongs to');
});

Harness::test('the status filter narrows the list to that status', function () use ($gm, $dealId): void {
    $draft = quotes_create($gm, [['Filter draft', '1', '11.00']], ['deal_id' => (string) $dealId]);
    $sent  = quotes_create($gm, [['Filter sent', '1', '12.00']], ['deal_id' => (string) $dealId]);

    $gm->postWithToken('/quotes/' . (int) $sent['id'], '/quotes/' . (int) $sent['id'] . '/send', [], true);

    $sentOnly = $gm->get('/quotes?status=sent');

    Assert::same(200, $sentOnly['status']);
    Assert::contains((string) $sent['number'], $sentOnly['body'], 'the sent quote is listed');
    // MUTATION: dropping the `AND q.status = :status` clause from index() lists
    // the draft here too, and this fails.
    Assert::notContains((string) $draft['number'], $sentOnly['body'], 'the draft is not');

    // An unknown status is ignored rather than producing an empty list or an
    // error — a crafted ?status= must not be able to reach the enum.
    $bogus = $gm->get('/quotes?status=not-a-status');

    Assert::same(200, $bogus['status']);
    Assert::contains((string) $draft['number'], $bogus['body'], 'an unusable filter falls back to everything');
    Assert::contains((string) $sent['number'], $bogus['body']);
});

Harness::test('the list pages, and a later page holds different quotes', function () use ($gm, $dealId): void {
    // Inserted directly: the subject here is the LIST's paging, and twelve
    // round trips through the create form would only make it slower, not
    // better. per_page is clamped to a floor of 10 by Controller::pagination(),
    // so twelve rows is the smallest fixture that spans two pages.
    $made = [];

    for ($i = 1; $i <= 12; $i++) {
        $number = 'Q-PAGE-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT);
        $made[] = $number;

        DB::insert('quotes', [
            'deal_id'        => $dealId,
            'number'         => $number,
            'status'         => 'draft',
            'subtotal_cents' => 100 * $i,
            'total_cents'    => 100 * $i,
            'created_at'     => gmdate('Y-m-d H:i:s'),
            'updated_at'     => gmdate('Y-m-d H:i:s'),
        ]);
    }

    try {
        // Newest first, so the last one inserted leads page 1.
        $newest = $made[11];
        $page1  = $gm->get('/quotes?per_page=10&page=1');
        $page2  = $gm->get('/quotes?per_page=10&page=2');

        Assert::same(200, $page1['status']);
        Assert::same(200, $page2['status']);
        Assert::contains($newest, $page1['body'], 'the newest quote leads the first page');
        // MUTATION: dropping OFFSET from the list query serves page 1 twice,
        // and this fails.
        Assert::notContains($newest, $page2['body'], 'and does not appear again on the second');
        Assert::contains('Page 2 of', $page2['body'], 'and the pager says where you are');
    } finally {
        DB::delete('quotes', 'number LIKE :n', ['n' => 'Q-PAGE-%']);
    }
});

// ------------------------------------------------------------------ create

Harness::test('creating a quote stores the lines and a draft status', function () use ($gm, $dealId): void {
    $quote = quotes_create($gm, [
        ['Laser tag for 10', '1', '180.00'],
        ['Extra pizza',      '3', '12.50'],
    ], ['deal_id' => (string) $dealId, 'valid_until' => gmdate('Y-m-d', strtotime('+14 days'))]);

    Assert::same('draft', (string) $quote['status'], 'a new quote is a draft, never sent');
    Assert::same($dealId, (int) $quote['deal_id']);
    Assert::notEmpty((string) $quote['number'], 'a quote number must be allocated');

    $lines = quotes_lines((int) $quote['id']);

    Assert::count(2, $lines, 'both lines should be stored');
    Assert::same('Laser tag for 10', (string) $lines[0]['description']);
    Assert::same(18000, (int) $lines[0]['unit_cents'], 'the unit price is stored in cents');
    Assert::same(18000, (int) $lines[0]['total_cents']);
    Assert::same(1250, (int) $lines[1]['unit_cents']);
    Assert::same(3750, (int) $lines[1]['total_cents'], '3 x 1250 is exactly 3750');

    // 18000 + 3750. MUTATION: making totals() sum line UNIT prices instead of
    // line totals gives 19250 and this fails.
    Assert::same(21750, (int) $quote['subtotal_cents']);
    Assert::same(21750, (int) $quote['total_cents']);
});

Harness::test('the stored total always equals the stored lines', function () use ($gm, $dealId): void {
    // The invariant the whole module exists to keep. Asserted against the
    // database rather than against the controller's own arithmetic, so a bug
    // that computes one figure and writes another is caught.
    $quote = quotes_create($gm, [
        ['Mini golf',  '4',    '14.75'],
        ['Go karts',   '2',    '9.99'],
        ['Party host', '1.50', '40.00'],
    ], ['deal_id' => (string) $dealId]);

    $lines = quotes_lines((int) $quote['id']);
    $sum   = 0;

    foreach ($lines as $index => $line) {
        $sum += (int) $line['total_cents'];

        // Each stored line must multiply out on its own, or the printed quote
        // shows a quantity and a unit price that do not make the amount beside
        // them. MySQL normalises DECIMAL(10,2) on the way out, so asserting
        // the stored string ("3.00") proves only the column type — this reads
        // the quantity back and does the sum.
        // MUTATION: having the controller write the quantity as a whole number
        // (1 instead of 1.50) leaves the total at 6000 against a stored 1.00,
        // and this fails on the third line.
        $parts = explode('.', (string) $line['qty']);
        $qtyHundredths = ((int) $parts[0]) * 100 + (int) str_pad($parts[1] ?? '0', 2, '0');

        Assert::same(
            (int) $line['total_cents'],
            intdiv($qtyHundredths * (int) $line['unit_cents'] + 50, 100),
            'line ' . ($index + 1) . ': the stored quantity times the stored unit price'
        );
    }

    Assert::same($sum, (int) $quote['subtotal_cents'], 'the subtotal is the sum of the lines');
    Assert::same(
        (int) $quote['subtotal_cents'] - (int) $quote['discount_cents'] + (int) $quote['tax_cents'],
        (int) $quote['total_cents'],
        'and the total follows from the subtotal, the discount and the tax'
    );

    // 4 x 1475 = 5900; 2 x 999 = 1998; 1.5 x 4000 = 6000.
    Assert::same(5900, (int) $lines[0]['total_cents']);
    Assert::same(1998, (int) $lines[1]['total_cents']);
    Assert::same(6000, (int) $lines[2]['total_cents'], 'a fractional quantity rounds exactly once');
    Assert::same(13898, (int) $quote['subtotal_cents']);
});

Harness::test('a quantity of 3 at 999 cents is exactly 2997, and no line drifts a cent', function () use ($gm, $dealId): void {
    // MEASURED, and the measurement changed this test: 3 x 9.99 is NOT a
    // float-fragile case in PHP. (int) (3 * 9.99 * 100) is 2997 on this
    // platform, so that figure alone would pass against a floating-point
    // implementation and the test would prove nothing. The two lines beside it
    // are fragile, found by sweeping the pairs where the float path and the
    // integer path disagree:
    //   3    x $1.40  →  420 exact, 419 via float
    //   1.5  x $1.07  →  161 exact, 160 via float
    // MUTATION: computing the line in dollars as a float and scaling back —
    // (int) ((($qty / 100) * ($unit / 100)) * 100) — yields 419 and 160, and
    // this fails. (Verified: with that mutation in place the suite went red
    // here; with the real integer path it is green.)
    $quote = quotes_create($gm, [
        ['Arcade card', '3',   '9.99'],
        ['Token pack',  '3',   '1.40'],
        ['Half hour',   '1.5', '1.07'],
    ], ['deal_id' => (string) $dealId]);

    $lines = quotes_lines((int) $quote['id']);

    Assert::same(2997, (int) $lines[0]['total_cents'], '3 x 999');
    Assert::same(420, (int) $lines[1]['total_cents'], '3 x 140, which a float path renders as 419');
    Assert::same(161, (int) $lines[2]['total_cents'], '1.5 x 107, which a float path renders as 160');
    Assert::same(3578, (int) $quote['subtotal_cents']);
    Assert::same(3578, (int) $quote['total_cents']);
});

Harness::test('a three-for-two discount leaves exactly two units payable', function () use ($gm, $dealId): void {
    // Three $9.99 arcade cards, one of them free. 2997 - 999 = 1998, which is
    // 2 x 999 to the cent.
    $quote = quotes_create($gm, [['Arcade card', '3', '9.99']], [
        'deal_id'       => (string) $dealId,
        'discount_mode' => 'amount',
        'discount'      => '9.99',
    ]);

    Assert::same(2997, (int) $quote['subtotal_cents']);
    Assert::same(999, (int) $quote['discount_cents']);
    // MUTATION: computing the total as subtotal + discount, or forgetting the
    // discount entirely, gives 3996 or 2997 and this fails.
    Assert::same(1998, (int) $quote['total_cents'], 'three for the price of two');
});

Harness::test('a 100% discount takes the total to exactly zero, never below', function () use ($gm, $dealId): void {
    $quote = quotes_create($gm, [
        ['Comped party package', '1', '399.00'],
        ['Comped cake',          '2', '24.50'],
    ], [
        'deal_id'       => (string) $dealId,
        'discount_mode' => 'percent',
        'discount'      => '100',
    ]);

    Assert::same(44800, (int) $quote['subtotal_cents'], '39900 + 4900');
    Assert::same(44800, (int) $quote['discount_cents'], '100% of the lines, to the cent');
    Assert::same(0, (int) $quote['total_cents'], 'a fully comped quote is zero');
    Assert::same(0, (int) $quote['deposit_cents'], 'and nothing can be due on it');
});

Harness::test('a discount larger than the lines cannot invert the total', function () use ($gm, $dealId): void {
    // A negative total would read as the venue owing the guest money, which is
    // not a thing a quote can say.
    // MUTATION: dropping the max(0, min($subtotal, $discount)) clamp gives
    // -50100 and this fails.
    $quote = quotes_create($gm, [['One ride band', '1', '99.00']], [
        'deal_id'       => (string) $dealId,
        'discount_mode' => 'amount',
        'discount'      => '600.00',
    ]);

    Assert::same(9900, (int) $quote['subtotal_cents']);
    Assert::same(9900, (int) $quote['discount_cents'], 'the discount is capped at the subtotal');
    Assert::same(0, (int) $quote['total_cents']);
});

Harness::test('a percentage discount rounds half away from zero, in cents', function () use ($gm, $dealId): void {
    // 12.5% of 2997 is 374.625 cents. Truncating gives 374 and under-charges
    // the venue on every such line; rounding half away from zero gives 375.
    // MUTATION: replacing divideRound() with intdiv() alone gives 374, a
    // discount of $3.74 and a total of 2623, and this fails.
    $quote = quotes_create($gm, [['Arcade card', '3', '9.99']], [
        'deal_id'       => (string) $dealId,
        'discount_mode' => 'percent',
        'discount'      => '12.5',
    ]);

    Assert::same(2997, (int) $quote['subtotal_cents']);
    Assert::same(375, (int) $quote['discount_cents']);
    Assert::same(2622, (int) $quote['total_cents']);
});

Harness::test('tax and a deposit are stored as cents and the deposit is capped at the total', function () use ($gm, $dealId): void {
    $quote = quotes_create($gm, [['Event centre hire', '1', '1200.00']], [
        'deal_id' => (string) $dealId,
        'tax'     => '96.00',
        'deposit' => '5000.00',
    ]);

    Assert::same(120000, (int) $quote['subtotal_cents']);
    Assert::same(9600, (int) $quote['tax_cents']);
    Assert::same(129600, (int) $quote['total_cents'], 'tax is added after the discount');
    // MUTATION: dropping the min($total, $depositCents) clamp stores 500000,
    // a deposit larger than the quote, and this fails.
    Assert::same(129600, (int) $quote['deposit_cents'], 'a deposit can never exceed the total');
});

Harness::test('creating a quote writes an audit row naming the actor', function () use ($gm, $dealId): void {
    $quote = quotes_create($gm, [['Audited line', '1', '10.00']], ['deal_id' => (string) $dealId]);

    $row = quotes_audit((int) $quote['id'], 'quote.created');

    Assert::notNull($row, 'a created quote must leave an audit row');

    $gmId = (int) DB::value("SELECT id FROM {users} WHERE email = 'gm@test.invalid'");

    Assert::same($gmId, (int) $row['user_id'], 'and the row must name who did it');
    Assert::contains('total_cents', (string) $row['changes'], 'with the figures it was created with');
});

// -------------------------------------------------------------- validation

Harness::test('a quote with no usable line is refused and nothing is written', function () use ($gm, $dealId): void {
    $before = (int) DB::value('SELECT COUNT(*) FROM {quotes}');

    // Every field blank: the form always offers empty template rows, so this
    // is the shape a mis-click actually produces.
    $response = $gm->postWithToken('/quotes/new', '/quotes', [
        'deal_id'                 => (string) $dealId,
        'lines[0][description]'   => '',
        'lines[0][qty]'           => '',
        'lines[0][unit]'          => '',
    ], true);

    Assert::same(200, $response['status']);
    Assert::contains('at least one line', $response['body'], 'and it should say why');
    // MUTATION: removing the `if ($parsed === []) return '...'` guard inserts a
    // quote with no lines and a zero total, and this fails.
    Assert::same($before, (int) DB::value('SELECT COUNT(*) FROM {quotes}'), 'no quote may be written');
});

Harness::test('a line with a price but no description is refused', function () use ($gm, $dealId): void {
    $before = (int) DB::value('SELECT COUNT(*) FROM {quotes}');

    $response = $gm->postWithToken('/quotes/new', '/quotes', [
        'deal_id'               => (string) $dealId,
        'lines[0][description]' => '',
        'lines[0][qty]'         => '2',
        'lines[0][unit]'        => '30.00',
    ], true);

    Assert::same(200, $response['status']);
    Assert::contains('needs a description', $response['body']);
    Assert::same($before, (int) DB::value('SELECT COUNT(*) FROM {quotes}'));
});

Harness::test('a unit price that is not money is refused rather than silently zeroed', function () use ($gm, $dealId): void {
    // The dangerous failure here is not a 500 — it is a quote that quietly
    // prices a line at $0 because the input could not be parsed.
    $before = (int) DB::value('SELECT COUNT(*) FROM {quotes}');

    $response = $gm->postWithToken('/quotes/new', '/quotes', [
        'deal_id'               => (string) $dealId,
        'lines[0][description]' => 'Mystery item',
        'lines[0][qty]'         => '1',
        'lines[0][unit]'        => 'two hundred',
    ], true);

    Assert::same(200, $response['status']);
    Assert::contains('unit price', $response['body']);
    Assert::same($before, (int) DB::value('SELECT COUNT(*) FROM {quotes}'));
});

Harness::test('a third decimal place on a quantity is refused, not rounded away', function () use ($gm, $dealId): void {
    // qty is DECIMAL(10,2). Accepting 1.005 would have MySQL round the stored
    // quantity while the line total was computed from the unrounded figure, so
    // the stored line would not multiply out.
    $before = (int) DB::value('SELECT COUNT(*) FROM {quotes}');

    $response = $gm->postWithToken('/quotes/new', '/quotes', [
        'deal_id'               => (string) $dealId,
        'lines[0][description]' => 'Half an hour of something',
        'lines[0][qty]'         => '1.005',
        'lines[0][unit]'        => '100.00',
    ], true);

    Assert::same(200, $response['status']);
    Assert::contains('two decimal places', $response['body']);
    Assert::same($before, (int) DB::value('SELECT COUNT(*) FROM {quotes}'));
});

Harness::test('a zero quantity is refused', function () use ($gm, $dealId): void {
    $before = (int) DB::value('SELECT COUNT(*) FROM {quotes}');

    $response = $gm->postWithToken('/quotes/new', '/quotes', [
        'deal_id'               => (string) $dealId,
        'lines[0][description]' => 'Nothing at all',
        'lines[0][qty]'         => '0',
        'lines[0][unit]'        => '50.00',
    ], true);

    Assert::same(200, $response['status']);
    Assert::contains('above zero', $response['body']);
    Assert::same($before, (int) DB::value('SELECT COUNT(*) FROM {quotes}'));
});

Harness::test('a discount over 100 per cent is refused', function () use ($gm, $dealId): void {
    $before = (int) DB::value('SELECT COUNT(*) FROM {quotes}');

    $response = $gm->postWithToken('/quotes/new', '/quotes', [
        'deal_id'               => (string) $dealId,
        'lines[0][description]' => 'A line',
        'lines[0][qty]'         => '1',
        'lines[0][unit]'        => '100.00',
        'discount_mode'         => 'percent',
        'discount'              => '150',
    ], true);

    Assert::same(200, $response['status']);
    Assert::contains('between 0 and 100', $response['body']);
    Assert::same($before, (int) DB::value('SELECT COUNT(*) FROM {quotes}'));
});

// -------------------------------------------------------------- the detail

Harness::test('the detail view carries the venue address, the lines and the total', function () use ($gm, $dealId): void {
    $quote = quotes_create($gm, [['Bowling for 12', '12', '15.00']], [
        'deal_id'     => (string) $dealId,
        'valid_until' => '2030-06-01',
        'terms'       => 'A deposit holds the date.',
    ]);

    $response = $gm->get('/quotes/' . (int) $quote['id']);

    Assert::same(200, $response['status']);

    // The NAME has to be asserted in position, not merely present: the layout
    // prints it in the <title> and the sidebar, so a bare contains() passes
    // against a view that never renders it at all. MEASURED — that is exactly
    // what this assertion did until the From block was checked instead.
    Assert::matches(
        '#<dt>From</dt>\s*<dd>\s*The Castle Fun Center#',
        $response['body'],
        'a quote must say who it is from, in its own From block'
    );
    // These can only have come from Settings via the quote view.
    Assert::contains('109 Brookside Avenue', $response['body'], 'and the venue street address');
    Assert::contains('Mountainville, NY 10953', $response['body'], 'and the rest of it, on one line');
    Assert::contains('(845) 469-2116', $response['body'], 'and a number to call');
    Assert::contains('Bowling for 12', $response['body'], 'the lines');
    Assert::contains('$180.00', $response['body'], 'the total');
    Assert::contains('Robin Quotable', $response['body'], 'who it is for');
    Assert::contains('12 Birthday Lane', $response['body'], 'and their address');
    Assert::contains('A deposit holds the date.', $response['body'], 'and the terms');
    Assert::contains('Jun 1, 2030', $response['body'], 'and the expiry date the table supports');
});

Harness::test('an expiry date is not shifted by the venue timezone', function () use ($gm, $dealId): void {
    // valid_until and event_date are DATE columns — a calendar day, with no
    // time and no zone. The app's local_date() helper reads its argument as a
    // UTC instant and converts it to the venue's timezone, which is right for
    // a timestamp and wrong for a date: midnight UTC on 2030-06-01 is 8pm on
    // May 31 in New York, and the quote would tell the family it expired the
    // day before it does.
    //
    // MUTATION: swapping QuoteController::calendarDate() back to local_date()
    // in any of the three views renders "May 31, 2030" and this fails. It
    // failed when first written, which is how the bug was found.
    $quote = quotes_create($gm, [['Dated line', '1', '25.00']], [
        'deal_id'     => (string) $dealId,
        'valid_until' => '2030-06-01',
    ]);

    Assert::same('America/New_York', Settings::timezone(), 'the precondition: a venue behind UTC');
    Assert::same(
        '2030-06-01',
        substr((string) $quote['valid_until'], 0, 10),
        'the date is stored as given'
    );

    foreach (['', '/print'] as $suffix) {
        $response = $gm->get('/quotes/' . (int) $quote['id'] . $suffix);

        Assert::contains('Jun 1, 2030', $response['body'], 'the day it really expires' . $suffix);
        Assert::notContains('May 31, 2030', $response['body'], 'never the evening before' . $suffix);
    }
});

Harness::test('the printable copy renders the same figures', function () use ($gm, $dealId): void {
    $quote = quotes_create($gm, [['Rock wall session', '6', '11.25']], ['deal_id' => (string) $dealId]);

    $response = $gm->get('/quotes/' . (int) $quote['id'] . '/print');

    Assert::same(200, $response['status']);
    Assert::contains('Rock wall session', $response['body']);
    Assert::contains('$67.50', $response['body'], '6 x 1125 is 6750 cents');
    // In position again, for the same reason as the detail view.
    Assert::matches(
        '#<h2>The Castle Fun Center</h2>#',
        $response['body'],
        'the letterhead a venue would actually send'
    );
    Assert::contains('109 Brookside Avenue', $response['body'], 'with its address under it');
    Assert::contains((string) $quote['number'], $response['body']);
});

Harness::test('internal notes are on the detail view but never on the printable copy', function () use ($gm, $dealId): void {
    // The one place these two views must differ. A note saying "they will pay
    // more, push the premium package" reaching a guest is a real incident.
    $quote = quotes_create($gm, [['A line', '1', '50.00']], [
        'deal_id' => (string) $dealId,
        'notes'   => 'INTERNAL-ONLY-MARKER push the premium package',
    ]);

    $detail = $gm->get('/quotes/' . (int) $quote['id']);
    $print  = $gm->get('/quotes/' . (int) $quote['id'] . '/print');

    Assert::contains('INTERNAL-ONLY-MARKER', $detail['body'], 'staff should see their own note');
    // MUTATION: adding $quote['notes'] to print.php makes this fail.
    Assert::notContains('INTERNAL-ONLY-MARKER', $print['body'], 'the guest must never see it');
});

// ---------------------------------------------------------------- editing

Harness::test('editing a draft re-prices it and leaves no orphan lines', function () use ($gm, $dealId): void {
    $quote = quotes_create($gm, [
        ['First go',  '1', '100.00'],
        ['Second go', '1', '50.00'],
    ], ['deal_id' => (string) $dealId]);

    $id = (int) $quote['id'];

    Assert::same(15000, (int) $quote['total_cents']);

    $response = $gm->postWithToken('/quotes/' . $id . '/edit', '/quotes/' . $id, [
        'deal_id'               => (string) $dealId,
        'lines[0][description]' => 'Revised single line',
        'lines[0][qty]'         => '3',
        'lines[0][unit]'        => '9.99',
    ], true);

    Assert::same(200, $response['status']);

    $after = DB::first('SELECT * FROM {quotes} WHERE id = :id', ['id' => $id]);
    $lines = quotes_lines($id);

    Assert::count(1, $lines, 'the replaced lines must not leave the old ones behind');
    Assert::same('Revised single line', (string) $lines[0]['description']);
    // MUTATION: deleting the old lines but recomputing the total from the OLD
    // set — or not recomputing at all — leaves 15000 here and this fails.
    Assert::same(2997, (int) $after['subtotal_cents']);
    Assert::same(2997, (int) $after['total_cents']);
    Assert::notNull(quotes_audit($id, 'quote.updated'), 'an edit must be audited');
});

// --------------------------------------------------------------- the gates

Harness::test('a role without view_deals cannot reach quotes at all', function () use ($desk, $gm, $dealId): void {
    // Front Desk is deliberately seeded without view_deals.
    $quote = quotes_create($gm, [['Gated line', '1', '10.00']], ['deal_id' => (string) $dealId]);

    foreach (['/quotes', '/quotes/' . (int) $quote['id'], '/quotes/' . (int) $quote['id'] . '/print'] as $path) {
        $response = $desk->get($path, true);

        Assert::same(403, $response['status'], $path . ' must be refused');
        Assert::notContains('Gated line', $response['body'], 'and must not leak the quote either');
    }
});

Harness::test('view_deals alone cannot create a quote', function () use ($viewer, $gm, $dealId): void {
    // Read Only holds view_deals but not deals_manage. This separation is the
    // point of having two keys.
    $readable = $viewer->get('/quotes');

    Assert::same(200, $readable['status'], 'the precondition: this role CAN see quotes');
    Assert::notContains('New quote', $readable['body'], 'but is not offered the create link');

    $before = (int) DB::value('SELECT COUNT(*) FROM {quotes}');

    Assert::same(403, $viewer->get('/quotes/new', true)['status'], 'the form is closed');

    // The token has to come from a page this role CAN open, which is what an
    // attacker would do and what keeps the refusal from being a CSRF rejection
    // dressed up as a permission check.
    $response = $viewer->postWithToken('/quotes', '/quotes', [
        'deal_id'               => (string) $dealId,
        'lines[0][description]' => 'Should never exist',
        'lines[0][qty]'         => '1',
        'lines[0][unit]'        => '10.00',
    ], true);

    Assert::same(403, $response['status'], 'and so is the write');

    // And again with NO deal, which matters: a quote attached to a deal is
    // refused a second time by the record-scope check in resolveDeal(), so
    // this case is the one that tests the deals_manage gate on its own.
    // MEASURED — without it, removing 'can:deals_manage' from the store route
    // left this whole test green.
    $dealless = $viewer->postWithToken('/quotes', '/quotes', [
        'lines[0][description]' => 'Should never exist',
        'lines[0][qty]'         => '1',
        'lines[0][unit]'        => '10.00',
    ], true);

    Assert::same(403, $dealless['status'], 'a quote with no deal is gated too');
    Assert::same($before, (int) DB::value('SELECT COUNT(*) FROM {quotes}'), 'nothing may be written');
    Assert::false(
        DB::exists('SELECT 1 FROM {quote_lines} WHERE description = :d', ['d' => 'Should never exist']),
        'and no line either'
    );
});

Harness::test('view_deals alone cannot edit or re-status a quote', function () use ($viewer, $gm, $dealId): void {
    $quote = quotes_create($gm, [['Untouchable', '1', '77.00']], ['deal_id' => (string) $dealId]);
    $id    = (int) $quote['id'];

    Assert::same(403, $viewer->get('/quotes/' . $id . '/edit', true)['status']);

    $response = $viewer->postWithToken('/quotes', '/quotes/' . $id, [
        'deal_id'               => (string) $dealId,
        'lines[0][description]' => 'Tampered',
        'lines[0][qty]'         => '1',
        'lines[0][unit]'        => '1.00',
    ], true);

    Assert::same(403, $response['status']);

    $after = DB::first('SELECT * FROM {quotes} WHERE id = :id', ['id' => $id]);

    Assert::same(7700, (int) $after['total_cents'], 'the figures must be untouched');
    Assert::same('Untouchable', (string) quotes_lines($id)[0]['description']);
});

Harness::test('sending is gated on quotes_send, separately from editing', function () use ($viewer, $gm, $dealId): void {
    $quote = quotes_create($gm, [['Unsendable', '1', '42.00']], ['deal_id' => (string) $dealId]);
    $id    = (int) $quote['id'];

    $response = $viewer->postWithToken('/quotes', '/quotes/' . $id . '/send', [
        'to' => 'robin-quotes@example.test',
    ], true);

    Assert::same(403, $response['status'], 'a role without quotes_send is refused');

    $after = DB::first('SELECT status, sent_at FROM {quotes} WHERE id = :id', ['id' => $id]);

    // MUTATION: weakening both layers of the send gate — the route's
    // 'can:quotes_send' and send()'s own authorize('quotes_send') — to
    // 'view_deals' leaves this quote 'sent', and this fails.
    Assert::same('draft', (string) $after['status'], 'and the quote stays a draft');
    Assert::null($after['sent_at'], 'with no send recorded');
    Assert::null(quotes_audit($id, 'quote.sent'), 'and no send in the audit log');
});

Harness::test('a scoped role sees only quotes for its own deals', function () use ($baseUrl, $gm, $dealId): void {
    // view_deals is scoped: the Party Host role holds it as 'own'. A host must
    // see the quotes for their own leads and not the rest of the team's, and
    // the refusal has to be a 404 rather than a 403 — whether a record exists
    // is itself something somebody outside its scope should not learn.
    $password = 'host-quotes-passphrase-7';
    $hostId   = test_make_user('party_host', [
        'email'         => 'quotes-host@example.test',
        'name'          => 'Party Host Pat',
        'password_hash' => Auth::hash($password),
    ]);

    // $dealId belongs to nobody; give it an owner who is NOT the host.
    DB::update('deals', ['owner_id' => (int) DB::value(
        "SELECT id FROM {users} WHERE email = 'gm@test.invalid'"
    )], 'id = :id', ['id' => $dealId]);

    $ownDealId = DB::insert('deals', [
        'pipeline_id' => (int) DB::value("SELECT id FROM {pipelines} WHERE slug = 'birthday'"),
        'stage_id'    => (int) DB::value('SELECT id FROM {pipeline_stages} ORDER BY id LIMIT 1'),
        'title'       => 'Pat own lead',
        'owner_id'    => $hostId,
        'status'      => 'open',
        'created_at'  => gmdate('Y-m-d H:i:s'),
        'updated_at'  => gmdate('Y-m-d H:i:s'),
    ]);

    try {
        $theirs = quotes_create($gm, [['Someone elses party', '1', '199.00']], [
            'deal_id' => (string) $dealId,
        ]);

        $host = new TestBrowser($baseUrl, 'quotes-host');
        $host->login('quotes-host@example.test', $password);

        $mine = quotes_create($host, [['Pat own party', '1', '150.00']], [
            'deal_id' => (string) $ownDealId,
        ]);

        $list = $host->get('/quotes');

        Assert::same(200, $list['status']);
        // By quote NUMBER, not by line description: the list shows the deal it
        // belongs to, so asserting on a line would have been asserting on text
        // the page never renders.
        Assert::contains((string) $mine['number'], $list['body'], 'their own quote is listed');
        Assert::contains('Pat own lead', $list['body'], 'with the deal it belongs to');
        // MUTATION: replacing the scopeCondition() in index() with '1 = 1'
        // lists the other host's quote and this fails.
        Assert::notContains(
            (string) $theirs['number'],
            $list['body'],
            'another owner\'s quote must not be'
        );
        Assert::notContains('Robin birthday party', $list['body'], 'nor their deal');

        Assert::same(
            404,
            $host->get('/quotes/' . (int) $theirs['id'], true)['status'],
            'and opening it directly must not work either'
        );
        Assert::same(
            200,
            $host->get('/quotes/' . (int) $mine['id'], true)['status'],
            'while their own still opens'
        );

        // And they cannot attach a NEW quote to a deal that is not theirs,
        // which the exists: validation rule alone would happily allow.
        $before   = (int) DB::value('SELECT COUNT(*) FROM {quotes}');
        $attempt  = $host->postWithToken('/quotes/new', '/quotes', [
            'deal_id'               => (string) $dealId,
            'lines[0][description]' => 'Poached lead',
            'lines[0][qty]'         => '1',
            'lines[0][unit]'        => '10.00',
        ], true);

        Assert::same(403, $attempt['status'], 'quoting on somebody else\'s deal is refused');
        Assert::same($before, (int) DB::value('SELECT COUNT(*) FROM {quotes}'), 'and nothing is written');
    } finally {
        DB::delete('quotes', 'deal_id = :d', ['d' => $ownDealId]);
        DB::delete('deals', 'id = :id', ['id' => $ownDealId]);
        DB::delete('users', 'id = :id', ['id' => $hostId]);
        DB::update('deals', ['owner_id' => null], 'id = :id', ['id' => $dealId]);
    }
});

// ---------------------------------------------------------------- sending

Harness::test('sending records when, by whom, and the message itself', function () use ($gm, $dealId): void {
    $quote = quotes_create($gm, [['Party package', '1', '329.00']], ['deal_id' => (string) $dealId]);
    $id    = (int) $quote['id'];

    $response = $gm->postWithToken('/quotes/' . $id, '/quotes/' . $id . '/send', [], true);

    Assert::same(200, $response['status']);

    $after = DB::first('SELECT * FROM {quotes} WHERE id = :id', ['id' => $id]);

    Assert::same('sent', (string) $after['status']);
    Assert::notNull($after['sent_at'], 'sent_at records WHEN');

    // WHOM: cc_quotes has no sent_by column, so the actor lives in the audit
    // row the send writes — which is what the page reads back.
    $audit = quotes_audit($id, 'quote.sent');

    Assert::notNull($audit, 'a send must be audited');
    Assert::same(
        (int) DB::value("SELECT id FROM {users} WHERE email = 'gm@test.invalid'"),
        (int) $audit['user_id'],
        'and the audit row records WHO sent it'
    );

    // The mail went through Mailer::send(), which records every outbound
    // message. Safe Mode is on in this suite, so the delivery is redirected to
    // the owner while the row keeps the address it was ADDRESSED to — that is
    // the protected posture working, not a failure.
    $message = DB::first(
        "SELECT * FROM {messages} WHERE channel = 'email' ORDER BY id DESC LIMIT 1"
    );

    Assert::notNull($message, 'the send must go through the mailer, which records it');
    Assert::same('outbound', (string) $message['direction']);
    Assert::contains('329.00', (string) $message['body'], 'the quote travels in the message body');
    Assert::contains((string) $quote['number'], (string) $message['subject']);
    Assert::same(
        'robin-quotes@example.test',
        (string) $message['to_address'],
        'addressed to the deal contact, resolved through the deal'
    );
    Assert::contains(
        'Safe Mode',
        (string) $message['error_message'],
        'and Safe Mode is recorded as having redirected the delivery'
    );

    // And the page now says who sent it.
    $page = $gm->get('/quotes/' . $id);

    Assert::contains('Sent by General Manager Gail', $page['body']);
});

Harness::test('a quote with no lines cannot be sent', function () use ($gm, $dealId): void {
    // Reachable only by emptying a draft, but a quote of $0.00 with no lines
    // reaching a guest would be worse than an error message.
    $quote = quotes_create($gm, [['Will be emptied', '1', '10.00']], ['deal_id' => (string) $dealId]);
    $id    = (int) $quote['id'];

    DB::delete('quote_lines', 'quote_id = :q', ['q' => $id]);

    $response = $gm->postWithToken('/quotes/' . $id, '/quotes/' . $id . '/send', [], true);

    Assert::same(200, $response['status']);
    Assert::contains('at least one line', $response['body']);
    Assert::same(
        'draft',
        (string) DB::value('SELECT status FROM {quotes} WHERE id = :id', ['id' => $id]),
        'and it stays a draft'
    );
});

Harness::test('a send is refused when there is no address to send to', function () use ($gm): void {
    // A deal-less quote has no guest on file, because cc_quotes has no contact
    // column. Sending it with no address typed must refuse rather than mail
    // the empty string.
    $quote = quotes_create($gm, [['Walk-in quote', '1', '60.00']]);
    $id    = (int) $quote['id'];

    Assert::null($quote['deal_id'], 'the fixture really is deal-less');

    $response = $gm->postWithToken('/quotes/' . $id, '/quotes/' . $id . '/send', ['to' => ''], true);

    Assert::same(200, $response['status']);
    Assert::contains('no email address', $response['body']);
    Assert::same(
        'draft',
        (string) DB::value('SELECT status FROM {quotes} WHERE id = :id', ['id' => $id])
    );
});

Harness::test('a suppressed address is refused by the mailer, and the quote stays a draft', function () use ($gm, $dealId): void {
    // Proves the send really goes through Mailer::send() rather than building
    // mail by hand: suppression lives inside the mailer, so a hand-rolled send
    // would sail straight past it.
    $quote = quotes_create($gm, [['Suppressed send', '1', '80.00']], ['deal_id' => (string) $dealId]);
    $id    = (int) $quote['id'];

    DB::insert('suppressions', [
        'address'    => 'bounced-quote@example.test',
        'channel'    => 'email',
        'reason'     => 'bounce',
        'created_at' => gmdate('Y-m-d H:i:s'),
    ]);

    try {
        $response = $gm->postWithToken('/quotes/' . $id, '/quotes/' . $id . '/send', [
            'to' => 'bounced-quote@example.test',
        ], true);

        Assert::same(200, $response['status']);
        Assert::contains('suppressed', $response['body'], 'the operator should be told why');
        // MUTATION: having send() ignore the mailer's verdict (`if (false)` on
        // the !$result['sent'] branch) — which is what hand-rolling the mail
        // would amount to, since suppression lives inside Mailer — marks this
        // quote 'sent', and this fails.
        Assert::same(
            'draft',
            (string) DB::value('SELECT status FROM {quotes} WHERE id = :id', ['id' => $id]),
            'a refused send must not mark the quote sent'
        );
        Assert::null(quotes_audit($id, 'quote.sent'), 'and must not look like a send in the log');
    } finally {
        DB::delete('suppressions', 'address = :a', ['a' => 'bounced-quote@example.test']);
    }
});

// --------------------------------------------------------- status transitions

Harness::test('a draft cannot be moved straight to sent through the status form', function () use ($gm, $dealId): void {
    // The status route is gated on deals_manage, which is CHEAPER than
    // quotes_send. If it could set 'sent', the send permission would be
    // decorative.
    $quote = quotes_create($gm, [['Status line', '1', '20.00']], ['deal_id' => (string) $dealId]);
    $id    = (int) $quote['id'];

    $response = $gm->postWithToken('/quotes/' . $id, '/quotes/' . $id . '/status', [
        'status' => 'sent',
    ], true);

    Assert::same(200, $response['status']);
    // MUTATION: adding 'sent' to TRANSITIONS['draft'] makes this fail.
    Assert::same(
        'draft',
        (string) DB::value('SELECT status FROM {quotes} WHERE id = :id', ['id' => $id]),
        'only the send route may set sent'
    );
    Assert::null(
        DB::value('SELECT sent_at FROM {quotes} WHERE id = :id', ['id' => $id]),
        'and nothing may be stamped as sent'
    );
});

Harness::test('a sent quote can be accepted, and acceptance is stamped and final', function () use ($gm, $dealId): void {
    $quote = quotes_create($gm, [['Accepted package', '1', '275.00']], ['deal_id' => (string) $dealId]);
    $id    = (int) $quote['id'];

    $gm->postWithToken('/quotes/' . $id, '/quotes/' . $id . '/send', [], true);

    $accept = $gm->postWithToken('/quotes/' . $id, '/quotes/' . $id . '/status', [
        'status' => 'accepted',
    ], true);

    Assert::same(200, $accept['status']);

    $after = DB::first('SELECT * FROM {quotes} WHERE id = :id', ['id' => $id]);

    Assert::same('accepted', (string) $after['status']);
    Assert::notNull($after['accepted_at'], 'acceptance is stamped');
    Assert::notNull(quotes_audit($id, 'quote.accepted'), 'and audited');

    // Terminal: a booking is built on these figures.
    $reopen = $gm->postWithToken('/quotes/' . $id, '/quotes/' . $id . '/status', [
        'status' => 'draft',
    ], true);

    Assert::same(200, $reopen['status']);
    // MUTATION: adding 'draft' to TRANSITIONS['accepted'] makes this fail.
    Assert::same(
        'accepted',
        (string) DB::value('SELECT status FROM {quotes} WHERE id = :id', ['id' => $id]),
        'an accepted quote cannot be quietly reopened'
    );
});

Harness::test('a sent quote cannot be re-priced in place', function () use ($gm, $dealId): void {
    // The guest is holding a copy. Re-pricing it silently would leave the
    // venue and the guest reading different documents with the same number.
    $quote = quotes_create($gm, [['Original price', '1', '500.00']], ['deal_id' => (string) $dealId]);
    $id    = (int) $quote['id'];

    $gm->postWithToken('/quotes/' . $id, '/quotes/' . $id . '/send', [], true);

    Assert::same(
        'sent',
        (string) DB::value('SELECT status FROM {quotes} WHERE id = :id', ['id' => $id]),
        'the precondition'
    );

    $response = $gm->postWithToken('/quotes/' . $id, '/quotes/' . $id, [
        'deal_id'               => (string) $dealId,
        'lines[0][description]' => 'Secretly cheaper',
        'lines[0][qty]'         => '1',
        'lines[0][unit]'        => '1.00',
    ], true);

    Assert::same(200, $response['status']);
    Assert::contains('back to draft', $response['body'], 'and it should say what to do instead');
    // MUTATION: removing the EDITABLE guard from update() stores 100 here and
    // this fails.
    Assert::same(
        50000,
        (int) DB::value('SELECT total_cents FROM {quotes} WHERE id = :id', ['id' => $id]),
        'the figures the guest is holding must not change'
    );
    Assert::same('Original price', (string) quotes_lines($id)[0]['description']);
});

Harness::test('a declined quote can be revised: back to draft, re-priced, and re-sent', function () use ($gm, $dealId): void {
    // The guard above has to let the normal next move through, or it is worse
    // than the hole it closes.
    $quote = quotes_create($gm, [['First offer', '1', '400.00']], ['deal_id' => (string) $dealId]);
    $id    = (int) $quote['id'];

    $gm->postWithToken('/quotes/' . $id, '/quotes/' . $id . '/send', [], true);
    $gm->postWithToken('/quotes/' . $id, '/quotes/' . $id . '/status', ['status' => 'declined'], true);

    Assert::same(
        'declined',
        (string) DB::value('SELECT status FROM {quotes} WHERE id = :id', ['id' => $id])
    );

    $gm->postWithToken('/quotes/' . $id, '/quotes/' . $id . '/status', ['status' => 'draft'], true);

    Assert::same(
        'draft',
        (string) DB::value('SELECT status FROM {quotes} WHERE id = :id', ['id' => $id]),
        'a declined quote may be taken back to draft'
    );

    $response = $gm->postWithToken('/quotes/' . $id . '/edit', '/quotes/' . $id, [
        'deal_id'               => (string) $dealId,
        'lines[0][description]' => 'Sharper offer',
        'lines[0][qty]'         => '1',
        'lines[0][unit]'        => '349.00',
    ], true);

    Assert::same(200, $response['status']);
    Assert::same(
        34900,
        (int) DB::value('SELECT total_cents FROM {quotes} WHERE id = :id', ['id' => $id]),
        'and then re-priced'
    );

    $gm->postWithToken('/quotes/' . $id, '/quotes/' . $id . '/send', [], true);

    Assert::same(
        'sent',
        (string) DB::value('SELECT status FROM {quotes} WHERE id = :id', ['id' => $id]),
        'and sent again'
    );
});

Harness::test('a re-send does not rewrite when the quote was first sent', function () use ($gm, $dealId): void {
    // "How long has the guest been sitting on this?" has to keep its answer.
    //
    // The first version of this read sent_at, re-sent, and compared — which
    // could never fail, because both sends land in the same second and
    // now_utc() is second-resolution. MEASURED: a mutation writing the current
    // time on every send left it green. Backdating the stamp makes the
    // assertion real without a sleep.
    $quote = quotes_create($gm, [['Resend line', '1', '70.00']], ['deal_id' => (string) $dealId]);
    $id    = (int) $quote['id'];

    $gm->postWithToken('/quotes/' . $id, '/quotes/' . $id . '/send', [], true);

    $backdated = '2026-01-02 03:04:05';
    DB::update('quotes', ['sent_at' => $backdated], 'id = :id', ['id' => $id]);

    $gm->postWithToken('/quotes/' . $id, '/quotes/' . $id . '/send', [], true);

    Assert::same(
        $backdated,
        (string) DB::value('SELECT sent_at FROM {quotes} WHERE id = :id', ['id' => $id]),
        'the first send time must survive a re-send'
    );
    Assert::notNull(
        quotes_audit($id, 'quote.sent'),
        'while the re-send itself is still recorded in the audit log'
    );
});

Harness::test('an unknown status is refused', function () use ($gm, $dealId): void {
    $quote = quotes_create($gm, [['A line', '1', '20.00']], ['deal_id' => (string) $dealId]);
    $id    = (int) $quote['id'];

    $response = $gm->postWithToken('/quotes/' . $id, '/quotes/' . $id . '/status', [
        'status' => 'paid-in-full',
    ], true);

    Assert::same(200, $response['status']);
    Assert::same(
        'draft',
        (string) DB::value('SELECT status FROM {quotes} WHERE id = :id', ['id' => $id])
    );
});

// ------------------------------------------------------------------ numbers

Harness::test('quote numbers are unique and sequential', function () use ($gm, $dealId): void {
    $first  = quotes_create($gm, [['Seq one', '1', '10.00']], ['deal_id' => (string) $dealId]);
    $second = quotes_create($gm, [['Seq two', '1', '10.00']], ['deal_id' => (string) $dealId]);

    Assert::notSame((string) $first['number'], (string) $second['number'], 'numbers must not repeat');

    $prefix = 'Q-' . gmdate('Y') . '-';

    Assert::true(str_starts_with((string) $second['number'], $prefix), 'numbered within the year');
    Assert::same(
        (int) substr((string) $first['number'], strlen($prefix)) + 1,
        (int) substr((string) $second['number'], strlen($prefix)),
        'and the sequence advances by one'
    );

    $distinct = (int) DB::value('SELECT COUNT(DISTINCT number) FROM {quotes}');
    $rows     = (int) DB::value('SELECT COUNT(*) FROM {quotes}');

    Assert::same($rows, $distinct, 'every quote in the table has its own number');
});

// ----------------------------------------------------------------- cleanup

Harness::test('the suite tidies up after itself', function () use ($viewerId, $dealId, $contactId, $driverBefore, $venueBefore): void {
    // The HTTP suites share one database and nothing resets between them, so
    // leaving a few dozen quotes and a spare user behind would make a second
    // run read differently from the first. The message delete is narrowed to
    // this module's own subject line: the reset-password suite runs AFTER this
    // one and reads cc_messages, so a broad delete here would be a test
    // breaking a test.
    DB::delete('quotes', 'deal_id = :d OR deal_id IS NULL', ['d' => $dealId]);
    DB::delete('deals', 'id = :id', ['id' => $dealId]);
    // Not anchored: Safe Mode prefixes the stored subject with
    // "[Safe Mode → …]", so an anchored pattern would match nothing and the
    // cleanup would quietly do half its job.
    DB::delete('messages', 'subject LIKE :s', ['s' => '%Your quote from%']);
    DB::delete('contacts', 'id = :id', ['id' => $contactId]);
    DB::delete('users', 'id = :id', ['id' => $viewerId]);

    // Put the settings back exactly as they were found, so this suite cannot
    // change what a later one sees.
    if ($driverBefore === null) {
        Settings::forget('mail.driver');
    } else {
        Settings::set('mail.driver', $driverBefore);
    }

    foreach ($venueBefore as $key => $value) {
        if ($value === null) {
            Settings::forget($key);
        } else {
            Settings::set($key, $value);
        }
    }

    Assert::same(
        0,
        (int) DB::value('SELECT COUNT(*) FROM {quotes}'),
        'no quote should survive the run'
    );
    Assert::same(
        0,
        (int) DB::value('SELECT COUNT(*) FROM {quote_lines}'),
        'and the lines go with them, through the foreign key'
    );
});
