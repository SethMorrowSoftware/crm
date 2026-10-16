<?php

declare(strict_types=1);

/**
 * The Text Club over HTTP: the sign-up form, the admin page, and the inbound
 * webhook that makes the double opt-in possible at all.
 *
 * `tests/unit/TextClubTest.php` pins the rules — who may be contacted on what
 * evidence — without a database. What this file tests is the three ways the
 * outside world reaches them, and each one has a different thing that must
 * not go wrong:
 *
 *   1. **THE INBOUND WEBHOOK MUST REFUSE WHAT IT CANNOT VERIFY.** Anybody who
 *      can POST here unverified can forge a JOIN from a stranger's number —
 *      making the venue text somebody who never asked, with the venue's own
 *      records saying they consented — or a STOP from a paying customer's,
 *      quietly cutting them off from their booking reminders. So an unsigned
 *      request is refused, a badly signed one is refused, a replayed one is
 *      refused, and a request that cannot be checked because nothing is
 *      configured is refused too. That last case is the one that matters:
 *      "open until somebody sets a secret" is the default that works
 *      perfectly in testing and is wide open in production.
 *      `tests/static/QualityGatesTest.php` exempts this route from CSRF and
 *      from auth, and names this file as what holds that exemption to
 *      account.
 *   2. **THE PUBLIC FORM GRANTS NO CONSENT.** Handing over a number asks for
 *      an invite. The invite asks for the consent. A form that subscribed
 *      people outright would make the double opt-in decorative.
 *   3. **THE VALIDATOR BLOCKS THE SAVE.** An offer with no opt-out line, or
 *      an invite missing its rates disclosure, is refused when it is written
 *      — not discovered on the first of the month after it has gone to the
 *      whole list.
 */

use App\Controllers\TextClubController as Club;
use App\Core\DB;
use App\Core\Settings;
use App\Services\TextClub;
use Tests\Assert;
use Tests\Harness;

Harness::suite('http/text-club');

$baseUrl = getenv('CC_TEST_BASE_URL') ?: '';

if ($baseUrl === '') {
    Harness::skip('text club http suite', 'no server; run via tests/run.php');

    return;
}

if (!test_boot()) {
    Harness::skip('text club http suite', 'no database available');

    return;
}

require_once CC_ROOT . '/tests/TestBrowser.php';

// ------------------------------------------------------------------ fixtures

/** Numbers this suite owns outright, so nothing it does can touch real rows. */
const TCH_PREFIX = '+1555091';

function tch_number(int $n): string
{
    return TCH_PREFIX . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
}

function tch_clear(): void
{
    DB::query(
        "DELETE FROM {consent_log} WHERE contact_id IN (SELECT id FROM {contacts} WHERE source LIKE 'tchttp%')"
    );
    DB::query("DELETE FROM {messages} WHERE to_address LIKE '" . TCH_PREFIX . "%' OR from_address LIKE '" . TCH_PREFIX . "%'");
    DB::query("DELETE FROM {text_club} WHERE phone_normalized LIKE '" . TCH_PREFIX . "%'");
    DB::delete('suppressions', "address LIKE '" . TCH_PREFIX . "%'", []);
    DB::query(
        "DELETE FROM {conversations} WHERE contact_id IN (SELECT id FROM {contacts} WHERE source LIKE 'tchttp%')"
    );
    DB::query("DELETE FROM {contacts} WHERE source LIKE 'tchttp%' OR phone_normalized LIKE '" . TCH_PREFIX . "%'");
}

/** @return array<string,mixed>|null */
function tch_row(string $number): ?array
{
    return DB::first('SELECT * FROM {text_club} WHERE phone_normalized = :p', ['p' => $number]);
}

function tch_consent(string $number): int
{
    return (int) DB::value(
        'SELECT sms_consent FROM {contacts} WHERE phone_normalized = :p ORDER BY id LIMIT 1',
        ['p' => $number]
    );
}

/** How many texts have gone to this number. */
function tch_sent(string $number): int
{
    return (int) DB::value(
        "SELECT COUNT(*) FROM {messages} WHERE to_address = :p AND direction = 'outbound'",
        ['p' => $number]
    );
}

/** The last thing texted to this number. */
function tch_last(string $number): string
{
    return (string) DB::value(
        "SELECT body FROM {messages} WHERE to_address = :p AND direction = 'outbound' ORDER BY id DESC LIMIT 1",
        ['p' => $number]
    );
}

/** The flash messages on a followed response. */
function tch_flash(array $response): string
{
    preg_match_all('#<div class="alert alert-\w+">.*?<p>(.*?)</p>#s', (string) $response['body'], $found);

    return implode(' | ', array_map(
        static fn (string $html): string => trim(html_entity_decode(strip_tags($html))),
        $found[1] ?? []
    ));
}

/**
 * A setting as it stands NOW, not as this process last cached it.
 *
 * `Settings` caches on first read, and the writes under test happen in the
 * SERVER'S process — so a bare `Settings::get()` here reads a copy loaded
 * before the request that changed it and reports the old value. That read
 * silently "proved" the calendar was never saved when it had been saved
 * perfectly well.
 */
function tch_setting(string $key): mixed
{
    Settings::load(true);

    return Settings::get($key);
}

/** An offer calendar as the editor posts it. */
function tch_offers(array $overrides = []): array
{
    $fields = [];

    for ($month = 1; $month <= 12; $month++) {
        $offer = $overrides[$month] ?? ['name' => '', 'body' => '', 'code' => '', 'type' => 'percent', 'value' => '0'];

        foreach ($offer as $key => $value) {
            $fields['offers[' . $month . '][' . $key . ']'] = (string) $value;
        }
    }

    return $fields;
}

tch_clear();

/*
 * Globals this suite changes, restored at the end. The campaigns suite
 * learned this the expensive way: settings are shared and the failure a leak
 * causes lands in a different file two suites later.
 */
$before = [];

foreach ([
    'system.safe_mode'        => false,
    'system.sending_paused'   => false,
    'sms.driver'              => 'log',
    'sms.webhook_secret'      => 'text-club-suite-secret-99',
    'sms.quiet_start_minutes' => 0,
    'sms.quiet_end_minutes'   => 24 * 60,
    'text_club.public_enabled' => true,
] as $key => $value) {
    $before[$key] = Settings::get($key);
    Settings::set($key, $value);
}

$restore = static function () use ($before): void {
    foreach ($before as $key => $value) {
        if ($value === null) {
            Settings::forget($key);
        } else {
            Settings::set($key, $value);
        }
    }
};

$owner = new TestBrowser($baseUrl, 'club-owner');
$owner->login('owner@test.invalid', 'owner-test-passphrase-9');

$desk = new TestBrowser($baseUrl, 'club-desk');
$desk->login('desk@test.invalid', 'desk-test-passphrase-9');

$anon = new TestBrowser($baseUrl, 'club-anon');

/**
 * POST to the webhook with a correct shared-secret signature.
 *
 * Signed over exactly the body cURL will send, which is why the query string
 * is built here rather than letting the browser do it: a signature over a
 * different serialisation of the same fields is no signature at all.
 *
 * @param array<string,string> $fields
 */
function tch_webhook(TestBrowser $browser, array $fields, array $options = []): array
{
    $body      = http_build_query($fields);
    $timestamp = (string) ($options['timestamp'] ?? time());
    $secret    = (string) ($options['secret'] ?? 'text-club-suite-secret-99');
    $signature = $options['signature'] ?? hash_hmac('sha256', $timestamp . '.' . $body, $secret);

    $headers = [];

    if (($options['omit_signature'] ?? false) !== true) {
        $headers[] = 'X-Castle-Signature: ' . $signature;
    }

    if (($options['omit_timestamp'] ?? false) !== true) {
        $headers[] = 'X-Castle-Timestamp: ' . $timestamp;
    }

    return $browser->request('/webhooks/sms', $fields, false, $headers);
}

Harness::test('the fixtures signed in', function () use ($owner, $desk): void {
    Assert::same(200, $owner->get('/account')['status']);
    Assert::same(200, $desk->get('/account')['status']);
});

// =============================================================== the gates

Harness::test('a role with no view_marketing cannot see the Text Club', function () use ($desk): void {
    Assert::same(403, $desk->get('/text-club')['status']);
});

Harness::test('the owner can', function () use ($owner): void {
    $page = $owner->get('/text-club');

    Assert::same(200, $page['status']);
    Assert::contains('Text Club', $page['body']);
});

// ==================================================== the inbound webhook

Harness::test(
    'THE INBOUND WEBHOOK REFUSES WHAT IT CANNOT VERIFY',
    function () use ($anon): void {
        $number = tch_number(1);

        // No signature at all.
        $bare = $anon->request('/webhooks/sms', ['From' => $number, 'Body' => 'JOIN']);

        Assert::same(403, $bare['status'], 'an unsigned inbound message must be refused');
        Assert::null(tch_row($number), 'and must not reach the list');

        // A signature, but the wrong one.
        $wrong = tch_webhook($anon, ['From' => $number, 'Body' => 'JOIN'], ['signature' => str_repeat('a', 64)]);

        Assert::same(403, $wrong['status'], 'a bad signature must be refused');
        Assert::null(tch_row($number));

        // The right secret, but signed over different content — which is what
        // a tampered body looks like.
        $tampered = $anon->request('/webhooks/sms', ['From' => $number, 'Body' => 'JOIN'], false, [
            'X-Castle-Signature: ' . hash_hmac('sha256', time() . '.From=' . $number . '&Body=STOP', 'text-club-suite-secret-99'),
            'X-Castle-Timestamp: ' . time(),
        ]);

        Assert::same(403, $tampered['status'], 'a signature over different content must be refused');

        // Correctly signed but from twenty minutes ago. A signature with no
        // freshness bound can be replayed for ever, and a replayed STOP is as
        // effective as the original.
        $stale = tch_webhook($anon, ['From' => $number, 'Body' => 'JOIN'], ['timestamp' => time() - 1200]);

        Assert::same(403, $stale['status'], 'a replayed request must be refused');
        Assert::null(tch_row($number), 'none of these may create a subscriber');
    }
);

Harness::test(
    'AN UNCONFIGURED WEBHOOK IS CLOSED, NOT OPEN',
    function () use ($anon): void {
        /*
         * The case that decides whether this endpoint is safe to ship. With
         * no secret set there is nothing to verify against, and the tempting
         * behaviour — accept everything until the venue configures one — is
         * wide open in production while being invisible in testing.
         */
        $number = tch_number(2);

        Settings::forget('sms.webhook_secret');

        try {
            /*
             * Signed with the EMPTY secret, which is what somebody who knows
             * none is configured would send. This is the attack, and it is
             * the only version of this test that proves anything: MEASURED by
             * mutation, a request signed with some OTHER secret is refused by
             * the arithmetic whether or not the guard exists, so the first
             * version of this test passed with the guard deleted.
             */
            $attack = tch_webhook($anon, ['From' => $number, 'Body' => 'JOIN'], ['secret' => '']);

            Assert::same(403, $attack['status'], 'a signature keyed on nothing is not a signature');
            Assert::null(tch_row($number), 'and must not reach the list');
            Assert::same(0, tch_sent($number), 'nor make the venue send anything');

            // And with no signature at all, for completeness.
            $bare = $anon->request('/webhooks/sms', ['From' => $number, 'Body' => 'JOIN']);

            Assert::same(403, $bare['status']);
            Assert::null(tch_row($number));
        } finally {
            Settings::set('sms.webhook_secret', 'text-club-suite-secret-99');
        }
    }
);

Harness::test(
    'a correctly signed JOIN invites them, and the invite is really sent',
    function () use ($anon): void {
        // The whole reason this webhook exists. `InboxController::receive()`
        // only COMPOSES the reply; until this endpoint existed nothing sent
        // it, so a guest could text JOIN, land on the list as pending, and
        // never receive the confirmation they were meant to answer.
        $number = tch_number(3);

        $response = tch_webhook($anon, ['From' => $number, 'Body' => 'JOIN', 'MessageSid' => 'SM' . bin2hex(random_bytes(6))]);

        Assert::same(200, $response['status']);

        $row = tch_row($number);

        Assert::notNull($row, 'they should be on the list');
        Assert::same(TextClub::PENDING, (string) $row['status'], 'as PENDING, not subscribed');
        Assert::same(0, tch_consent($number), 'A JOIN GRANTS NO CONSENT');

        Assert::same(1, tch_sent($number), 'the invite should have gone out');

        $invite = tch_last($number);

        Assert::contains('YES', $invite, 'it should say what to reply');
        Assert::contains('STOP', $invite, 'and how to stop');
        Assert::empty(
            TextClub::missingDisclosures($invite, 'confirm'),
            'whatever is actually sent must carry every disclosure'
        );
    }
);

Harness::test(
    'the YES that follows subscribes them, with the evidence',
    function () use ($anon): void {
        $number = tch_number(4);

        tch_webhook($anon, ['From' => $number, 'Body' => 'JOIN']);
        tch_webhook($anon, ['From' => $number, 'Body' => 'YES']);

        $row = tch_row($number);

        Assert::notNull($row);
        Assert::same(TextClub::SUBSCRIBED, (string) $row['status']);
        Assert::same(1, tch_consent($number), 'NOW they have consented');
        Assert::notNull($row['confirmed_at'], 'and when is recorded');

        $contactId = (int) DB::value(
            'SELECT id FROM {contacts} WHERE phone_normalized = :p ORDER BY id LIMIT 1',
            ['p' => $number]
        );

        $log = DB::first(
            "SELECT * FROM {consent_log} WHERE contact_id = :id AND channel = 'sms' AND action = 'granted'",
            ['id' => $contactId]
        );

        Assert::notNull($log, 'consent is worth nothing without the evidence');

        $evidence = json_decode((string) $log['evidence'], true);

        Assert::true(is_array($evidence));
        Assert::contains(
            'YES',
            (string) ($evidence['disclosure'] ?? ''),
            'the evidence must record the exact wording they agreed to'
        );

        // And the welcome went out while they were still holding the phone.
        Assert::greaterThan(1, tch_sent($number));
        Assert::contains('STOP', tch_last($number), 'even the welcome says how to stop');
    }
);

Harness::test(
    'A BARE YES SUBSCRIBES NOBODY AND STILL REACHES A HUMAN',
    function () use ($anon): void {
        // "Yes" is the most ordinary word in a text message. A guest
        // answering "yes" to *are you still coming Saturday?* has not joined
        // a marketing list — and the venue's number receives both kinds of
        // message on the same thread.
        $number = tch_number(5);

        tch_webhook($anon, ['From' => $number, 'Body' => 'Yes']);

        Assert::null(tch_row($number), 'no list row at all');
        Assert::same(0, tch_consent($number), 'NO CONSENT');
        Assert::same(0, tch_sent($number), 'and nothing is texted back automatically');

        // But it is NOT swallowed: it lands on a thread for somebody to read.
        $contactId = (int) DB::value(
            'SELECT id FROM {contacts} WHERE phone_normalized = :p ORDER BY id LIMIT 1',
            ['p' => $number]
        );

        Assert::greaterThan(0, $contactId, 'an unknown sender is a lead, not an error');
        Assert::true(DB::exists(
            "SELECT id FROM {messages} WHERE contact_id = :id AND direction = 'inbound'",
            ['id' => $contactId]
        ), 'their message must still reach the inbox');
    }
);

Harness::test(
    'A STOP IS CONFIRMED EVEN THOUGH IT SUPPRESSES THE NUMBER',
    function () use ($anon): void {
        /*
         * The order of these two things is a trap. Opting out suppresses the
         * number, and the suppression then blocks every message to it —
         * including the one that tells the guest their opt-out worked, which
         * is the single most likely moment for somebody to conclude they are
         * being ignored and complain to their carrier. Carriers expect that
         * confirmation.
         */
        $number = tch_number(6);

        tch_webhook($anon, ['From' => $number, 'Body' => 'JOIN']);
        tch_webhook($anon, ['From' => $number, 'Body' => 'YES']);

        $sentBefore = tch_sent($number);

        tch_webhook($anon, ['From' => $number, 'Body' => 'STOP']);

        Assert::same(
            TextClub::UNSUBSCRIBED,
            (string) (tch_row($number)['status'] ?? ''),
            'they come off the list'
        );
        Assert::same(0, tch_consent($number), 'and consent is revoked');
        Assert::true(DB::exists(
            "SELECT id FROM {suppressions} WHERE channel = 'sms' AND address = :p",
            ['p' => $number]
        ), 'the number is suppressed');

        Assert::greaterThan($sentBefore, tch_sent($number), 'AND THE CONFIRMATION STILL WENT OUT');
    }
);

Harness::test(
    'a rejoin after a STOP works, and still needs the YES',
    function () use ($anon): void {
        $number = tch_number(7);

        tch_webhook($anon, ['From' => $number, 'Body' => 'JOIN']);
        tch_webhook($anon, ['From' => $number, 'Body' => 'YES']);
        tch_webhook($anon, ['From' => $number, 'Body' => 'STOP']);

        $sentBefore = tch_sent($number);

        tch_webhook($anon, ['From' => $number, 'Body' => 'JOIN']);

        Assert::false(
            DB::exists("SELECT id FROM {suppressions} WHERE channel = 'sms' AND address = :p", ['p' => $number]),
            'they asked, so the do-not-text instruction goes'
        );
        Assert::same(0, tch_consent($number), 'BUT CONSENT STILL WAITS FOR THE YES');
        Assert::greaterThan($sentBefore, tch_sent($number), 'and the invite reaches them');

        tch_webhook($anon, ['From' => $number, 'Body' => 'YES']);

        Assert::same(1, tch_consent($number), 'the YES brings them back');
        Assert::same(2, (int) DB::value(
            "SELECT COUNT(*) FROM {consent_log} cl JOIN {contacts} c ON c.id = cl.contact_id
              WHERE c.phone_normalized = :p AND cl.action = 'granted'",
            ['p' => $number]
        ), 'with a second consent record, not an overwritten one');
    }
);

Harness::test('a delivery receipt is not an inbound message', function () use ($anon): void {
    // Most of this traffic is receipts. One landing on a guest's thread as an
    // empty message would fill the inbox with nothing.
    $number = tch_number(8);

    $response = tch_webhook($anon, [
        'From'          => $number,
        'MessageStatus' => 'delivered',
        'MessageSid'    => 'SM' . bin2hex(random_bytes(6)),
    ]);

    Assert::same(200, $response['status'], 'accepted, so the provider does not retry');
    Assert::contains('"handled":false', $response['body']);
    Assert::same(0, (int) DB::value(
        "SELECT COUNT(*) FROM {messages} WHERE from_address = :p AND direction = 'inbound'",
        ['p' => $number]
    ), 'and nothing is recorded as a message');
});

// ==================================================== the public sign-up

Harness::test('the sign-up page is a plain 404 while it is switched off', function () use ($anon): void {
    Settings::set('text_club.public_enabled', false);

    try {
        Assert::same(404, $anon->get('/text-club/join')['status'], 'off means not there');
        Assert::same(
            404,
            $anon->postWithToken('/login', '/text-club/join', ['phone' => tch_number(9)])['status'],
            'and the POST is shut too, not just the page'
        );
    } finally {
        Settings::set('text_club.public_enabled', true);
    }
});

Harness::test('the sign-up page shows the wording somebody is agreeing to', function () use ($anon): void {
    $page = $anon->get('/text-club/join');

    Assert::same(200, $page['status']);
    Assert::contains('Text Club', $page['body']);
    Assert::contains('STOP', $page['body'], 'how to stop, before they hand over a number');
    Assert::contains('rates', $page['body'], 'and that it may cost them');
    Assert::contains(
        'not a condition',
        $page['body'],
        'and that agreeing is not a condition of buying anything'
    );
    Assert::contains(Club::HONEYPOT, $page['body'], 'the honeypot is on the form');
});

Harness::test(
    'THE FORM ASKS FOR AN INVITE AND GRANTS NO CONSENT',
    function () use ($anon): void {
        $number = tch_number(10);

        $response = $anon->postWithToken('/text-club/join', '/text-club/join', [
            'phone'      => $number,
            'first_name' => 'Formjoiner',
        ], true);

        Assert::same(200, $response['status']);

        $row = tch_row($number);

        Assert::notNull($row, 'they are on the list');
        Assert::same(TextClub::PENDING, (string) $row['status'], 'as pending');
        Assert::same('web', (string) $row['joined_via'], 'and recorded as having come from the web');
        Assert::same(0, tch_consent($number), 'THE FORM ITSELF GRANTS NOTHING');
        Assert::same(1, tch_sent($number), 'the invite is sent');
        Assert::contains('YES', tch_last($number));
    }
);

Harness::test('a filled honeypot is thanked and ignored', function () use ($anon): void {
    $number = tch_number(11);

    $response = $anon->postWithToken('/text-club/join', '/text-club/join', [
        'phone'         => $number,
        Club::HONEYPOT  => 'http://spam.example',
    ], true);

    Assert::same(200, $response['status']);
    Assert::null(tch_row($number), 'nothing is recorded');
    Assert::same(0, tch_sent($number), 'and no text is sent on a bot\'s behalf');

    // The same sentence a person gets. Telling a bot which check it failed is
    // telling it how to pass next time.
    Assert::contains('Thank you', tch_flash($response));
});

Harness::test('an unusable number is refused plainly', function () use ($anon): void {
    $response = $anon->postWithToken('/text-club/join', '/text-club/join', [
        'phone' => 'not a phone number',
    ], true);

    Assert::contains('does not look like', tch_flash($response));
    Assert::same(0, (int) DB::value("SELECT COUNT(*) FROM {text_club} WHERE phone_normalized = ''"));
});

Harness::test(
    'one number cannot be made to receive a flood of invites',
    function () use ($anon): void {
        // Every submission SENDS A TEXT, so an uncapped form is a way to make
        // the venue pay to message a number somebody else chose. Capped on
        // the NUMBER rather than the address: a whole phone network shares an
        // address, and the thing worth limiting is texts to one handset.
        $number = tch_number(12);

        for ($i = 0; $i < Club::MAX_JOINS_PER_DAY + 3; $i++) {
            $anon->postWithToken('/text-club/join', '/text-club/join', ['phone' => $number]);
        }

        Assert::atLeast(1, tch_sent($number), 'the first one goes');
        Assert::true(
            tch_sent($number) <= Club::MAX_JOINS_PER_DAY,
            'but not all ' . (Club::MAX_JOINS_PER_DAY + 3) . ' of them; sent ' . tch_sent($number)
        );
    }
);

// ======================================================== the admin page

Harness::test(
    'THE VALIDATOR BLOCKS A SAVE, RATHER THAN THE FIRST OF THE MONTH FINDING OUT',
    function () use ($owner): void {
        $back = '/text-club';

        // An invite with no rates disclosure.
        $response = $owner->postWithToken($back, '/text-club/settings', tch_offers() + [
            'confirm_message' => 'Reply YES to join. Reply STOP to opt out.',
        ], true);

        Assert::contains('rates', tch_flash($response), 'the missing disclosure is named');

        // A welcome with no way to stop.
        $response = $owner->postWithToken($back, '/text-club/settings', tch_offers() + [
            'welcome_message' => 'You are in! Enjoy.',
        ], true);

        Assert::contains('stop', strtolower(tch_flash($response)));

        // An offer with no code for the till.
        $response = $owner->postWithToken($back, '/text-club/settings', tch_offers([
            4 => ['name' => 'April', 'body' => 'Half price karts.', 'code' => '', 'type' => 'percent', 'value' => '10'],
        ]), true);

        Assert::contains('code', strtolower(tch_flash($response)));
        Assert::contains('Month 4', tch_flash($response), 'and WHICH month is wrong');

        // A nonsensical discount.
        $response = $owner->postWithToken($back, '/text-club/settings', tch_offers([
            5 => ['name' => 'May', 'body' => 'Lots off.', 'code' => 'MAY', 'type' => 'percent', 'value' => '250'],
        ]), true);

        Assert::contains('1 and 100', tch_flash($response));
    }
);

Harness::test(
    'a calendar is saved whole or not at all',
    function () use ($owner): void {
        // A year saved half-way is a year with a hole in it that nobody is
        // told about. Month 2 is good and month 9 is not; neither may land.
        Settings::forget(Club::KEY_CALENDAR);

        $owner->postWithToken('/text-club', '/text-club/settings', tch_offers([
            2 => ['name' => 'February', 'body' => 'BOGO karts.', 'code' => 'FEBOK', 'type' => 'bogo', 'value' => '0'],
            9 => ['name' => 'September', 'body' => 'Bad one.', 'code' => '!!', 'type' => 'percent', 'value' => '10'],
        ]), true);

        Assert::null(tch_setting(Club::KEY_CALENDAR), 'nothing at all should have been written');
    }
);

Harness::test('a good calendar saves, and the page shows the month\'s offer', function () use ($owner): void {
    $month = TextClub::monthIn(now_utc(), Settings::timezone());

    $owner->postWithToken('/text-club', '/text-club/settings', tch_offers([
        $month => [
            'name'  => 'Suite offer',
            'body'  => 'Suite test: half price bowling.',
            'code'  => 'SUITE' . $month,
            'type'  => 'percent',
            'value' => '50',
        ],
    ]) + ['public_enabled' => '1'], true);

    $stored = TextClub::normalizeCalendar(tch_setting(Club::KEY_CALENDAR));

    Assert::hasKey($month, $stored);
    Assert::same('SUITE' . $month, $stored[$month]['code']);

    $page = $owner->get('/text-club');

    Assert::same(200, $page['status']);
    Assert::contains('Suite offer', $page['body']);
    Assert::contains('SUITE' . $month, $page['body']);
    Assert::contains(
        'half price bowling',
        $page['body'],
        'the page should show the offer as it will arrive'
    );
});

Harness::test(
    'writing the calendar is not permission to send it',
    function () use ($baseUrl, $owner): void {
        /*
         * The same split as campaigns, and no SEEDED role has this shape —
         * Owner and Marketing both hold `campaigns_manage` AND
         * `campaigns_send` — so without building a role the two keys are
         * never told apart and every test here passes whichever one is
         * removed.
         */
        $roleId = (int) DB::insert('roles', [
            'slug'        => 'club-writer-test',
            'name'        => 'Club writer (test)',
            'description' => 'Writes offers, sends none.',
            'is_system'   => 0,
            'sort_order'  => 903,
            'created_at'  => now_utc(),
            'updated_at'  => now_utc(),
        ]);

        foreach (['view_marketing', 'campaigns_manage'] as $slug) {
            $permissionId = (int) DB::value('SELECT id FROM {permissions} WHERE slug = :s', ['s' => $slug]);

            Assert::greaterThan(0, $permissionId, 'precondition: ' . $slug . ' exists');

            DB::insert('role_permissions', [
                'role_id'       => $roleId,
                'permission_id' => $permissionId,
                'scope'         => 'all',
            ]);
        }

        test_make_user('club-writer-test', [
            'email'         => 'club-writer@example.test',
            'name'          => 'Club Writer',
            'password_hash' => App\Core\Auth::hash('club-writer-passphrase-9'),
        ]);

        try {
            $writer = new TestBrowser($baseUrl, 'club-writer');
            $writer->login('club-writer@example.test', 'club-writer-passphrase-9');

            Assert::same(200, $writer->get('/account')['status'], 'precondition: signed in');
            Assert::same(200, $writer->get('/text-club')['status'], 'they can see it');

            // They can write the calendar.
            $month = TextClub::monthIn(now_utc(), Settings::timezone());

            $saved = $writer->postWithToken('/text-club', '/text-club/settings', tch_offers([
                $month => [
                    'name'  => 'Writer offer',
                    'body'  => 'Writer test offer.',
                    'code'  => 'WRITER',
                    'type'  => 'bogo',
                    'value' => '0',
                ],
            ]), true);

            Assert::same(200, $saved['status']);
            Assert::contains(
                'WRITER',
                (string) json_encode(tch_setting(Club::KEY_CALENDAR)),
                'a writer can write'
            );

            // They cannot send it.
            $response = $writer->postWithToken('/text-club', '/text-club/send', ['month' => (string) $month]);

            Assert::same(403, $response['status'], 'sending needs campaigns_send');
        } finally {
            DB::delete('users', 'email = :e', ['e' => 'club-writer@example.test']);
            DB::delete('role_permissions', 'role_id = :r', ['r' => $roleId]);
            DB::delete('roles', 'id = :r', ['r' => $roleId]);
        }
    }
);

Harness::test(
    'sending the month\'s offer reaches confirmed subscribers and nobody else',
    function () use ($owner, $anon): void {
        $month = TextClub::monthIn(now_utc(), Settings::timezone());

        $owner->postWithToken('/text-club', '/text-club/settings', tch_offers([
            $month => [
                'name'  => 'Send test',
                'body'  => 'Send test: free game with any load.',
                'code'  => 'SENDTEST',
                'type'  => 'free_item',
                'value' => '0',
            ],
        ]), true);

        // One confirmed, one who never answered, one who left.
        $confirmed   = tch_number(20);
        $neverAnswer = tch_number(21);
        $left        = tch_number(22);

        tch_webhook($anon, ['From' => $confirmed, 'Body' => 'JOIN']);
        tch_webhook($anon, ['From' => $confirmed, 'Body' => 'YES']);

        tch_webhook($anon, ['From' => $neverAnswer, 'Body' => 'JOIN']);

        tch_webhook($anon, ['From' => $left, 'Body' => 'JOIN']);
        tch_webhook($anon, ['From' => $left, 'Body' => 'YES']);
        tch_webhook($anon, ['From' => $left, 'Body' => 'STOP']);

        $before = [
            'confirmed' => tch_sent($confirmed),
            'pending'   => tch_sent($neverAnswer),
            'left'      => tch_sent($left),
        ];

        $owner->postWithToken('/text-club', '/text-club/send', ['month' => (string) $month], true);

        Assert::greaterThan($before['confirmed'], tch_sent($confirmed), 'the subscriber gets it');
        Assert::contains('SENDTEST', tch_last($confirmed), 'with the code for the till');

        Assert::same(
            $before['pending'],
            tch_sent($neverAnswer),
            'SOMEBODY WHO NEVER CONFIRMED GETS NOTHING'
        );
        Assert::same($before['left'], tch_sent($left), 'AND NEITHER DOES SOMEBODY WHO OPTED OUT');

        // And the frequency cap means a second press sends nothing.
        $after = tch_sent($confirmed);

        $owner->postWithToken('/text-club', '/text-club/send', ['month' => (string) $month], true);

        Assert::same($after, tch_sent($confirmed), 'the frequency cap holds on a second press');
    }
);

Harness::test(
    'A SUBSCRIBER THE VENUE LATER MARKED DO-NOT-CONTACT IS NOT SENT THE OFFER',
    function () use ($owner, $anon): void {
        /*
         * The drift this catches, and the reason the offer is sent as
         * MARKETING rather than transactional: the Text Club row says
         * subscribed, and the CONTACT says do not contact. That happens for
         * real — a guest asks at the front desk, staff tick the box on the
         * contact, and nothing updates the list row. The send path is the one
         * place that knows about both, so the offer has to go through its
         * checks rather than round them.
         *
         * MEASURED by mutation: with the kind switched to transactional,
         * every other test here still passed.
         */
        $month = TextClub::monthIn(now_utc(), Settings::timezone());

        $owner->postWithToken('/text-club', '/text-club/settings', tch_offers([
            $month => [
                'name'  => 'Drift test',
                'body'  => 'Drift test: half price karts.',
                'code'  => 'DRIFT',
                'type'  => 'percent',
                'value' => '50',
            ],
        ]), true);

        $number = tch_number(30);

        tch_webhook($anon, ['From' => $number, 'Body' => 'JOIN']);
        tch_webhook($anon, ['From' => $number, 'Body' => 'YES']);

        Assert::same(
            TextClub::SUBSCRIBED,
            (string) (tch_row($number)['status'] ?? ''),
            'precondition: they are a confirmed subscriber'
        );

        // The front desk marks them do-not-contact. The list row is untouched.
        DB::update('contacts', [
            'do_not_contact' => 1,
            'updated_at'     => now_utc(),
        ], 'phone_normalized = :p', ['p' => $number]);

        $before = tch_sent($number);

        $owner->postWithToken('/text-club', '/text-club/send', ['month' => (string) $month], true);

        Assert::same($before, tch_sent($number), 'the send path must refuse them');
        Assert::null(
            tch_row($number)['last_offer_sent_at'] ?? null,
            'and a refusal is not stamped as a delivery'
        );
    }
);

Harness::test(
    'a quiet-hours deferral is not stamped as a delivery',
    function () use ($owner, $anon): void {
        /*
         * `last_offer_sent_at` is what the frequency cap reads. Stamping it
         * on a message that merely WAITED starts the cap running for somebody
         * who was never sent anything — and they are then skipped for the
         * next three weeks as well, so the deferral quietly becomes a
         * cancellation.
         */
        $month = TextClub::monthIn(now_utc(), Settings::timezone());

        $owner->postWithToken('/text-club', '/text-club/settings', tch_offers([
            $month => [
                'name'  => 'Quiet test',
                'body'  => 'Quiet test: free game.',
                'code'  => 'QUIET',
                'type'  => 'free_item',
                'value' => '0',
            ],
        ]), true);

        $number = tch_number(31);

        tch_webhook($anon, ['From' => $number, 'Body' => 'JOIN']);
        tch_webhook($anon, ['From' => $number, 'Body' => 'YES']);

        $before = tch_sent($number);

        /*
         * A window that is genuinely closed right now, whichever hour this
         * suite happens to run in: one hour wide, starting two hours from
         * now. NOT 0 to 0 — `SmsPolicy::withinWindow()` reads an empty
         * window as always open, deliberately, so that configuration means
         * "no restriction" rather than "never send".
         */
        $local  = new DateTimeImmutable('now', new DateTimeZone(Settings::timezone()));
        $nowMin = ((int) $local->format('G')) * 60 + (int) $local->format('i');
        $start  = ($nowMin + 120) % 1440;

        Settings::set('sms.quiet_start_minutes', $start);
        Settings::set('sms.quiet_end_minutes', ($start + 60) % 1440);

        try {
            $owner->postWithToken('/text-club', '/text-club/send', ['month' => (string) $month], true);

            Assert::same($before, tch_sent($number), 'nothing goes out inside quiet hours');
            Assert::null(
                tch_row($number)['last_offer_sent_at'] ?? null,
                'AND THEY STAY DUE, rather than being skipped for three weeks'
            );
        } finally {
            Settings::set('sms.quiet_start_minutes', 0);
            Settings::set('sms.quiet_end_minutes', 24 * 60);
        }

        // With the window open again they really are sent it.
        $owner->postWithToken('/text-club', '/text-club/send', ['month' => (string) $month], true);

        Assert::greaterThan($before, tch_sent($number), 'the deferral was a delay, not a loss');
    }
);

Harness::test('a month with no offer written refuses to send', function () use ($owner): void {
    // Rather than texting the list a bare opt-out line.
    Settings::set(Club::KEY_CALENDAR, []);

    $response = $owner->postWithToken('/text-club', '/text-club/send', ['month' => '6'], true);

    Assert::contains('no offer', strtolower(tch_flash($response)));
});

Harness::test(
    'an empty list is told the list is empty, not that nobody is due',
    function () use ($owner): void {
        /*
         * Two quite different situations behind the same dead end, and one
         * sentence for both reads as a fault. "Nobody is due an offer" to a
         * venue with no subscribers at all sounds like the sending is broken;
         * what they need to know is how somebody gets on the list. Found by
         * driving the page: it also offered a "Send to 0 subscribers" button
         * that could only fail.
         */
        tch_clear();
        DB::query('DELETE FROM {text_club}');

        $month = TextClub::monthIn(now_utc(), Settings::timezone());

        $owner->postWithToken('/text-club', '/text-club/settings', tch_offers([
            $month => [
                'name'  => 'Empty list test',
                'body'  => 'Empty list test offer.',
                'code'  => 'EMPTYLIST',
                'type'  => 'bogo',
                'value' => '0',
            ],
        ]), true);

        $response = $owner->postWithToken('/text-club', '/text-club/send', ['month' => (string) $month], true);
        $why      = strtolower(tch_flash($response));

        Assert::contains('nobody has confirmed', $why, 'it should say the list is empty');
        Assert::contains('join', $why, 'and how somebody gets on it');
        Assert::notContains('20 days', $why, 'the frequency cap is not the reason here');

        // And the page does not offer a button that can only fail.
        $page = $owner->get('/text-club');

        Assert::same(200, $page['status']);
        Assert::notContains(
            'Send to 0 subscriber',
            $page['body'],
            'no button while there is nobody to send to'
        );
    }
);

tch_clear();

foreach ([Club::KEY_CALENDAR, Club::KEY_CONFIRM, Club::KEY_WELCOME, Club::KEY_ENABLED] as $key) {
    Settings::forget($key);
}

$restore();
