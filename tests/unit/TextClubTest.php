<?php

declare(strict_types=1);

/**
 * The Text Club's rules, with no database, no network and no clock.
 *
 * THE STATE MACHINE IS THE WHOLE TEST. A guest can text JOIN twice, text YES
 * having never joined, text YES to a prompt from four months ago, text JOIN
 * after a STOP, or do all of it in one evening. Every one of those happens,
 * several of them mean "do nothing", and written as three handlers the
 * awkward combinations resolve to whichever branch was written last. So every
 * cell of the table is a test here, and the ones that matter most are the
 * cells where the answer is to subscribe NOBODY:
 *
 *   - **`YES` with no invite outstanding.** "Yes" is the most ordinary word
 *     in a text message. A guest answering "yes" to *are you still coming
 *     Saturday?* has not joined a marketing list, and the venue's number
 *     receives both kinds of message on the same thread.
 *   - **`YES` to a stale invite.** A confirmation of something nobody
 *     remembers asking for is not consent.
 *   - **`YES` after a STOP.** JOIN is how somebody comes back, and it is
 *     deliberately the more deliberate word.
 *
 * And the one that must NOT be refused: a JOIN after a STOP has to clear the
 * suppression, or the confirmation message is blocked by the send path and
 * the guest can never rejoin however often they ask — while consent itself
 * still waits for the YES. Those two things come apart, and this is where
 * that is pinned.
 *
 * THE WORDING IS ALSO A RULE. Every message this feature sends is a bulk
 * text with a legal shape: an opt-out instruction always, plus a rates
 * disclosure and the word YES on the confirmation. And a single text is 160
 * GSM-7 characters, so the twelve shipped offers and every welcome built from
 * them are measured here rather than discovered on an invoice.
 */

use App\Services\SmsPolicy;
use App\Services\TextClub as TC;
use Tests\Assert;
use Tests\Harness;

Harness::suite('unit/text-club');

/** @var array{name:string, phone:string} */
$venue = ['name' => 'Castle Fun Center', 'phone' => '(845) 469-2116'];

const TC_NOW = '2026-07-15 14:00:00';

/** A subscriber row as the database holds it. */
function tc_row(string $status, ?string $sentAt = null, ?string $confirmedAt = null): array
{
    return [
        'status'               => $status,
        'double_optin_sent_at' => $sentAt,
        'confirmed_at'         => $confirmedAt,
    ];
}

// =========================================================== joining

Harness::test('a JOIN from a new number invites them and subscribes nobody', function (): void {
    $d = TC::decide(null, 'join', TC_NOW);

    Assert::same(TC::PENDING, $d['status'], 'they go on the list as pending');
    Assert::true($d['send_confirmation'], 'and are asked to confirm');
    Assert::false($d['grant_consent'], 'A JOIN IS NOT CONSENT — the YES is');
    Assert::false($d['welcome'], 'and no welcome offer goes out yet');
    Assert::same('confirm_prompt', $d['reply']);
});

Harness::test('a second JOIN before confirming just asks again', function (): void {
    // Somebody who texts JOIN twice gets the same answer twice. Harmless,
    // expected, and the alternative — silence — reads as a broken number.
    $d = TC::decide(tc_row(TC::PENDING, '2026-07-15 13:00:00'), 'join', TC_NOW);

    Assert::same(TC::PENDING, $d['status']);
    Assert::true($d['send_confirmation'], 'the invite is repeated');
    Assert::false($d['grant_consent'], 'still not consent');
});

Harness::test('a JOIN from somebody already subscribed changes nothing', function (): void {
    // The failure this prevents is a second welcome COUPON for a guest who
    // is already a subscriber, which is how a venue ends up giving the same
    // person free go-karts every time they text.
    $d = TC::decide(tc_row(TC::SUBSCRIBED, '2026-01-01 10:00:00', '2026-01-01 10:05:00'), 'join', TC_NOW);

    Assert::null($d['status'], 'their row is left alone');
    Assert::false($d['send_confirmation']);
    Assert::false($d['welcome'], 'NO SECOND WELCOME OFFER');
    Assert::same('already_in', $d['reply'], 'but they are told they are already in');
});

Harness::test(
    'A JOIN AFTER A STOP CLEARS THE SUPPRESSION WITHOUT GRANTING CONSENT',
    function (): void {
        /*
         * The case the two-flag design exists for. Somebody who texted STOP
         * last summer and texts JOIN today has ASKED to be texted, so the
         * suppression has to go — `Sms::send()` refuses a suppressed number
         * for every kind of message, confirmations included, so leaving it in
         * place means the guest can never rejoin however often they ask.
         *
         * But a JOIN is still not consent. Granting it here would turn a
         * single word into a full opt-in and skip the double opt-in entirely
         * for exactly the people who had already said no once.
         */
        $d = TC::decide(tc_row(TC::UNSUBSCRIBED, '2025-06-01 10:00:00'), 'join', TC_NOW);

        Assert::same(TC::PENDING, $d['status'], 'back to pending, not straight to subscribed');
        Assert::true($d['clear_suppression'], 'they asked, so the do-not-text instruction goes');
        Assert::false($d['grant_consent'], 'BUT CONSENT STILL WAITS FOR THE YES');
        Assert::true($d['send_confirmation']);
        Assert::false($d['welcome']);
        Assert::contains('rejoin', $d['reason']);
    }
);

// ======================================================== confirming

Harness::test('a YES to a fresh invite subscribes them and sends the welcome', function (): void {
    $d = TC::decide(tc_row(TC::PENDING, '2026-07-15 13:00:00'), 'confirm', TC_NOW);

    Assert::same(TC::SUBSCRIBED, $d['status']);
    Assert::true($d['grant_consent'], 'NOW consent is granted');
    Assert::true($d['welcome'], 'and the joining offer goes out while they are still holding the phone');
    Assert::true($d['clear_suppression'], 'in case anything suppressed them between the invite and the YES');
    Assert::same('welcome', $d['reply']);
});

Harness::test(
    'A YES WITH NO INVITE OUTSTANDING SUBSCRIBES NOBODY',
    function (): void {
        // The most important test in this file. "Yes" is an ordinary word,
        // and a guest answering "yes" to a question about their party has
        // not joined a marketing list.
        $d = TC::decide(null, 'confirm', TC_NOW);

        Assert::null($d['status'], 'no row is created');
        Assert::false($d['grant_consent'], 'NO CONSENT FROM A BARE YES');
        Assert::false($d['welcome']);
        Assert::null($d['reply'], 'and it is left to be an ordinary message a human answers');
    }
);

Harness::test('a YES to a stale invite does not subscribe them, and says so', function () use ($venue): void {
    // Eight days after the invite, with a seven-day window.
    $d = TC::decide(tc_row(TC::PENDING, '2026-07-07 13:00:00'), 'confirm', TC_NOW);

    Assert::null($d['status'], 'they are not subscribed');
    Assert::false($d['grant_consent']);
    Assert::same('stale_confirm', $d['reply'], 'and not left in silence either');

    // The one thing the reply must not do is tell them they are in.
    $words = TC::replyFor($d, $venue);

    Assert::notNull($words);
    Assert::contains('expired', strtolower((string) $words));
    Assert::contains('JOIN', (string) $words, 'it should say how to start again');
});

Harness::test('the confirmation window is exact at both edges', function (): void {
    // Seven days to the second is still in; one second past is not. Measured
    // on both sides, because an off-by-one here either expires a guest who
    // replied on time or accepts one who did not.
    $inside  = TC::decide(tc_row(TC::PENDING, '2026-07-08 14:00:00'), 'confirm', TC_NOW);
    $outside = TC::decide(tc_row(TC::PENDING, '2026-07-08 13:59:59'), 'confirm', TC_NOW);

    Assert::same(TC::SUBSCRIBED, $inside['status'], 'exactly seven days is still confirmable');
    Assert::null($outside['status'], 'a second later is not');
});

Harness::test('a YES after a STOP is not a rejoin', function (): void {
    $d = TC::decide(tc_row(TC::UNSUBSCRIBED, '2026-07-15 13:00:00'), 'confirm', TC_NOW);

    Assert::null($d['status']);
    Assert::false($d['grant_consent'], 'they opted out; YES does not undo that');
    Assert::null($d['reply'], 'JOIN is the word that brings somebody back');
});

Harness::test('a YES from somebody already subscribed sends no second welcome', function (): void {
    $d = TC::decide(tc_row(TC::SUBSCRIBED, '2026-07-01 10:00:00', '2026-07-01 10:05:00'), 'confirm', TC_NOW);

    Assert::null($d['status']);
    Assert::false($d['welcome'], 'no second coupon');
    Assert::same('already_in', $d['reply']);
});

Harness::test('a pending row that was never actually invited cannot be confirmed', function (): void {
    // A row written by an import or by staff, with no invite ever sent. A YES
    // cannot confirm an invitation that was never made.
    $d = TC::decide(tc_row(TC::PENDING, null), 'confirm', TC_NOW);

    Assert::null($d['status']);
    Assert::false($d['grant_consent']);

    // And they are NOT told an invitation expired, because none was issued.
    // MEASURED by mutation: with the never-invited guard removed this row
    // falls through to the stale branch and still subscribes nobody — the
    // two guards back each other up — but the guest is told something
    // untrue, which the refusal alone did not notice.
    Assert::null($d['reply'], 'nothing expired, so nothing is said about an expiry');
});

Harness::test('an invite dated in the future is not confirmable', function (): void {
    // A clock that jumped, or a row written by a server an hour ahead. Left
    // unguarded, a future stamp is "within the window" for ever.
    $d = TC::decide(tc_row(TC::PENDING, '2026-07-16 14:00:00'), 'confirm', TC_NOW);

    Assert::null($d['status'], 'a future invite confirms nothing');
});

// ============================================================ stopping

Harness::test('a STOP takes a subscriber off the list', function (): void {
    $d = TC::decide(tc_row(TC::SUBSCRIBED, '2026-07-01 10:00:00', '2026-07-01 10:05:00'), 'stop', TC_NOW);

    Assert::same(TC::UNSUBSCRIBED, $d['status']);
    Assert::false($d['clear_suppression'], 'the suppression is being ADDED, not cleared');
    Assert::null($d['reply'], 'the send path words the STOP reply itself');
});

Harness::test('a STOP from somebody not on the list is not an error', function (): void {
    // The suppression is what matters and `Sms::optOut()` writes it without
    // waiting for any lookup. There is simply no list row to settle.
    $d = TC::decide(null, 'stop', TC_NOW);

    Assert::null($d['status']);
    Assert::contains('suppression', $d['reason']);
});

Harness::test('an unknown event changes nothing', function (): void {
    $d = TC::decide(tc_row(TC::SUBSCRIBED), 'maybe', TC_NOW);

    Assert::null($d['status']);
    Assert::false($d['grant_consent']);
});

// ====================================================== the frequency cap

Harness::test('a subscriber who has never had an offer is due one', function (): void {
    // The cap exists to stop a SECOND message, not to delay the first.
    Assert::true(TC::offerDue(null, TC_NOW));
    Assert::true(TC::offerDue('', TC_NOW));
});

Harness::test('the frequency cap is 20 days, not 30, and the reason is the calendar', function (): void {
    /*
     * A thirty-day floor means a month whose offer went out on the 2nd
     * cannot send on the 1st of the next month, so the monthly offer drifts
     * later every month until it falls off the end of a short one. Twenty
     * days still stops a hand-run blast landing on top of the monthly offer.
     */
    Assert::same(20, TC::MIN_DAYS_BETWEEN_OFFERS);

    // Sent on the 2nd of June; the 1st of July is 29 days later.
    Assert::true(
        TC::offerDue('2026-06-02 10:00:00', '2026-07-01 10:00:00'),
        'the monthly offer must not be blocked by last month\'s'
    );

    Assert::false(TC::offerDue('2026-07-01 10:00:00', '2026-07-10 10:00:00'), 'nine days is too soon');
    Assert::true(TC::offerDue('2026-06-20 09:00:00', TC_NOW), '25 days is fine');
});

// ======================================================= the offer calendar

Harness::test('the shipped calendar covers all twelve months and each is valid', function (): void {
    $calendar = TC::defaultCalendar();

    Assert::count(12, $calendar);

    for ($month = 1; $month <= 12; $month++) {
        $offer = TC::offerForMonth($calendar, $month);

        Assert::notNull($offer, 'month ' . $month . ' should have an offer');
        Assert::null(TC::validateOffer($offer), 'month ' . $month . ' should be valid');
    }
});

Harness::test(
    'EVERY SHIPPED OFFER AND WELCOME FITS ONE TEXT',
    function () use ($venue): void {
        /*
         * A single text is 160 GSM-7 characters. The welcome is the one
         * message every single joiner receives, so it is the worst place in
         * the feature to spend a second segment — and MEASURED, the full
         * greeting plus July's offer came to 161 characters and cost two.
         * The preamble now gives way instead.
         */
        foreach (TC::defaultCalendar() as $month => $offer) {
            $sent = TC::offerMessage($venue, $offer);

            Assert::same('gsm7', SmsPolicy::encoding($sent), 'month ' . $month . ' should use no Unicode');
            Assert::same(1, SmsPolicy::segments($sent), 'month ' . $month . '\'s offer should be one text');

            $welcome = TC::welcomeMessage($venue, $offer);

            Assert::same(1, SmsPolicy::segments($welcome), 'month ' . $month . '\'s welcome should be one text');
        }

        Assert::same(1, SmsPolicy::segments(TC::welcomeMessage($venue)), 'and so should one with no offer');
        Assert::same(1, SmsPolicy::segments(TC::confirmationMessage($venue)), 'and the invite');
    }
);

Harness::test(
    'a venue offer too long for one text keeps its own words',
    function () use ($venue): void {
        // Only OUR preamble is shortened. A venue that writes a 300-character
        // offer has chosen a two-text message, and quietly cutting their
        // words to save a segment would be worse than the segment.
        $long  = ['body' => str_repeat('their own carefully chosen wording ', 8), 'code' => 'LONGONE'];
        $built = TC::welcomeMessage($venue, $long);

        Assert::contains('their own carefully chosen wording', $built);
        Assert::contains('Reply STOP', $built, 'and the opt-out survives whatever else does');
        Assert::greaterThan(1, SmsPolicy::segments($built), 'it simply costs what it costs');
    }
);

Harness::test('a month with no wording is no offer, not a broken one', function (): void {
    $calendar = [3 => ['name' => 'Nothing this month', 'body' => '  ', 'code' => 'X']];

    Assert::null(TC::offerForMonth($calendar, 3), 'a blank body means no offer');
    Assert::null(TC::validateOffer($calendar[3]), 'and that is not a validation failure');
});

Harness::test('an offer with wording needs a usable code', function (): void {
    // A MISSING code and a MALFORMED one are different problems, and saying
    // "should be 3 to 40 letters and numbers" about an empty field helps
    // nobody. MEASURED by mutation: dropping the empty check still refuses
    // the save, via the format check, so only the wording distinguishes them.
    Assert::contains(
        'needs a code',
        (string) TC::validateOffer(['body' => 'Half price karts.', 'code' => '']),
        'an empty field is told it is empty'
    );
    Assert::contains(
        'counter',
        (string) TC::validateOffer(['body' => 'Half price karts.', 'code' => 'no spaces allowed!']),
        'a code staff have to read out cannot contain punctuation'
    );
    Assert::null(TC::validateOffer(['body' => 'Half price karts.', 'code' => 'KARTS50', 'type' => 'percent', 'value' => 50]));
});

Harness::test('a nonsensical discount is refused', function (): void {
    Assert::contains('1 and 100', (string) TC::validateOffer(
        ['body' => 'x', 'code' => 'PCT', 'type' => 'percent', 'value' => 120]
    ));
    Assert::contains('1 and 100', (string) TC::validateOffer(
        ['body' => 'x', 'code' => 'PCT', 'type' => 'percent', 'value' => 0]
    ));
    Assert::contains('more than nothing', (string) TC::validateOffer(
        ['body' => 'x', 'code' => 'AMT', 'type' => 'fixed', 'value' => 0]
    ));

    // BOGO and a free item carry no number, so no number is required.
    Assert::null(TC::validateOffer(['body' => 'x', 'code' => 'BOGO', 'type' => 'bogo', 'value' => 0]));
});

Harness::test('a hand-edited calendar cannot smuggle anything into a text', function (): void {
    $calendar = TC::normalizeCalendar([
        '7'  => ['name' => str_repeat('n', 500), 'body' => str_repeat('b', 900), 'code' => 'lower case', 'type' => 'nonsense', 'value' => -5],
        '13' => ['body' => 'there is no month 13', 'code' => 'X'],
        'x'  => ['body' => 'nor a month x', 'code' => 'Y'],
    ]);

    Assert::hasKey(7, $calendar);
    Assert::count(1, $calendar, 'months 13 and x are dropped');
    Assert::same(120, mb_strlen($calendar[7]['name']));
    Assert::same(400, mb_strlen($calendar[7]['body']));
    Assert::same('LOWER CASE', $calendar[7]['code'], 'codes are upper-cased for the counter');
    Assert::same('percent', $calendar[7]['type'], 'an unknown type falls back rather than reaching SQL');
    Assert::same(0.0, $calendar[7]['value'], 'a negative discount is not a discount');
});

Harness::test('normalizing survives junk entirely', function (): void {
    Assert::same([], TC::normalizeCalendar(null));
    Assert::same([], TC::normalizeCalendar('not json'));
    Assert::same([], TC::normalizeCalendar(42));
    Assert::count(1, TC::normalizeCalendar('{"5":{"body":"From a stored string.","code":"STR"}}'));
});

// ============================================================ the wording

Harness::test(
    'A BULK TEXT WITHOUT AN OPT-OUT IS REFUSED BEFORE IT CAN BE SAVED',
    function (): void {
        // The spec's rule is that the VALIDATOR blocks the save. A monthly
        // offer whose opt-out line was edited out is otherwise discovered on
        // the first of the month, at ten in the morning, after it has gone to
        // the whole list.
        Assert::notEmpty(TC::missingDisclosures('Half price karts this Saturday.', 'offer'));
        Assert::empty(TC::missingDisclosures('Half price karts. Reply STOP to opt out.', 'offer'));
    }
);

Harness::test('a confirmation needs the rates notice and the word YES as well', function (): void {
    $missing = TC::missingDisclosures('Thanks for joining! Reply STOP to opt out.', 'confirm');

    Assert::count(2, $missing);
    Assert::contains('rates', implode(' ', $missing));
    Assert::contains('YES', implode(' ', $missing));

    Assert::empty(TC::missingDisclosures(
        'Thanks for joining! Reply YES to confirm. Msg & data rates may apply. Reply STOP to opt out.',
        'confirm'
    ));
});

Harness::test('a custom message that is not compliant is not used', function () use ($venue): void {
    // The venue can word its own invite, but not out of its disclosures: a
    // wording that fails the check falls back to the compliant default
    // rather than going out as written.
    $built = TC::confirmationMessage($venue, 'Yo join the club');

    Assert::empty(TC::missingDisclosures($built, 'confirm'), 'whatever is sent must be compliant');
    Assert::contains('YES', $built);

    $good = 'Reply YES to join ' . $venue['name'] . '. Msg & data rates may apply. Reply STOP to opt out.';

    Assert::same($good, TC::confirmationMessage($venue, $good), 'a compliant one is used as written');
});

Harness::test('the opt-out line is never doubled up', function () use ($venue): void {
    $sent = TC::offerMessage($venue, [
        'body' => 'Half price bowling tonight. Reply STOP to quit.',
        'code' => 'BOWL',
    ]);

    Assert::same(1, preg_match_all('/\bSTOP\b/', $sent), 'one instruction, not two');
});

Harness::test('a code already in the wording is not repeated', function () use ($venue): void {
    $sent = TC::offerMessage($venue, [
        'body' => 'Show KARTS50 for half price karts. Reply STOP to opt out.',
        'code' => 'KARTS50',
    ]);

    Assert::same(1, preg_match_all('/KARTS50/', $sent));
});

Harness::test('merge tags are filled from the venue and the offer', function () use ($venue): void {
    $sent = TC::offerMessage($venue, [
        'body' => 'Call {{venue_phone}} or come to {{venue_name}} with {{code}}. Reply STOP to opt out.',
        'code' => 'ring',
    ]);

    Assert::contains('(845) 469-2116', $sent);
    Assert::contains('Castle Fun Center', $sent);
    Assert::contains('RING', $sent);
    Assert::notContains('{{', $sent, 'no tag should survive into a guest\'s phone');
});

Harness::test('an unnamed venue still produces a sentence', function (): void {
    Assert::contains('Castle Fun Center', TC::confirmationMessage([]));
    Assert::contains('Castle Fun Center', TC::alreadyInMessage(['name' => '   ']));
});

Harness::test('an empty offer produces no message at all', function () use ($venue): void {
    // Rather than a bare "Reply STOP to opt out." sent to the whole list.
    Assert::same('', TC::offerMessage($venue, ['body' => '', 'code' => 'X']));
    Assert::same('', TC::offerMessage($venue, []));
});

// ============================================================= reporting

Harness::test('the list reports the confirm rate, which a subscriber count hides', function (): void {
    $stats = TC::growth([
        ['status' => TC::SUBSCRIBED,   'last_offer_sent_at' => '2026-07-01 10:00:00', 'offers_redeemed' => 2],
        ['status' => TC::SUBSCRIBED,   'last_offer_sent_at' => '2026-07-01 10:00:00', 'offers_redeemed' => 0],
        ['status' => TC::PENDING,      'last_offer_sent_at' => null,                  'offers_redeemed' => 0],
        ['status' => TC::PENDING,      'last_offer_sent_at' => null,                  'offers_redeemed' => 0],
        ['status' => TC::UNSUBSCRIBED, 'last_offer_sent_at' => '2026-06-01 10:00:00', 'offers_redeemed' => 1],
    ]);

    Assert::same(5, $stats['total']);
    Assert::same(2, $stats['subscribed']);
    Assert::same(2, $stats['pending']);
    Assert::same(1, $stats['unsubscribed']);

    // Three of five ever confirmed — the two subscribers and the one who
    // confirmed and later left. Dividing by the subscribed count instead
    // would always report 100%.
    Assert::same(60.0, $stats['confirm_rate']);
    Assert::same(3, $stats['redeemed']);
    Assert::same(100.0, $stats['redemption_rate'], '3 redemptions across 3 subscribers ever sent an offer');
});

Harness::test('an empty list has no rates rather than zero ones', function (): void {
    $stats = TC::growth([]);

    Assert::same(0, $stats['total']);
    Assert::null($stats['confirm_rate'], 'no invites means no rate, not 0%');
    Assert::null($stats['redemption_rate'], 'and an offer nobody was sent was not an offer nobody wanted');
});

Harness::test('a list that has never been offered anything has no redemption rate', function (): void {
    $stats = TC::growth([
        ['status' => TC::SUBSCRIBED, 'last_offer_sent_at' => null, 'offers_redeemed' => 0],
        ['status' => TC::SUBSCRIBED, 'last_offer_sent_at' => null, 'offers_redeemed' => 0],
    ]);

    Assert::same(100.0, $stats['confirm_rate']);
    Assert::null($stats['redemption_rate'], '0% would read as an offer that failed');
});

Harness::test('unknown statuses are not counted as anything', function (): void {
    $stats = TC::growth([['status' => 'nonsense'], ['status' => TC::SUBSCRIBED]]);

    Assert::same(1, $stats['total'], 'only the real one counts');
});

// ============================================================ the calendar clock

Harness::test(
    'WHICH MONTH IT IS, IS DECIDED IN THE VENUE\'S OWN ZONE',
    function (): void {
        /*
         * The monthly offer is a calendar thing. A cron firing at 8pm Eastern
         * on the 31st of July is already the 1st of August in UTC, and would
         * send August's offer a day early — or, read the other way, miss July
         * entirely. Measured on exactly that boundary.
         */
        Assert::same(7, TC::monthIn('2026-08-01 00:30:00', 'America/New_York'), 'still July at 8:30pm Eastern');
        Assert::same(8, TC::monthIn('2026-08-01 00:30:00', 'UTC'), 'and August in UTC');

        Assert::true(TC::isFirstOfMonth('2026-07-01 14:00:00', 'America/New_York'));
        Assert::false(
            TC::isFirstOfMonth('2026-08-01 00:30:00', 'America/New_York'),
            'the 31st locally is not the 1st, whatever UTC thinks'
        );
        Assert::true(TC::isFirstOfMonth('2026-08-01 04:30:00', 'America/New_York'), 'half past midnight locally');
    }
);

Harness::test('an unreadable zone falls back rather than throwing', function (): void {
    Assert::greaterThan(0, TC::monthIn('2026-07-15 14:00:00', 'Not/AZone'));
    Assert::false(TC::isFirstOfMonth('not a date', 'UTC'));
    Assert::same(0, TC::monthIn('not a date', 'UTC'));
});

Harness::test('a month outside the year has no offer', function (): void {
    $calendar = TC::defaultCalendar();

    Assert::null(TC::offerForMonth($calendar, 0), 'month 0 is what a failed clock read returns');
    Assert::null(TC::offerForMonth($calendar, 13));

    /*
     * And a hand-edited setting must not be able to make a failed clock read
     * match something. `monthIn()` returns 0 when it cannot read the date, so
     * a stored calendar carrying a "0" key would turn an unreadable clock
     * into a real send. `normalizeCalendar()` drops such keys, but
     * `offerForMonth()` can be handed raw stored JSON, so it refuses the
     * month itself rather than trusting its caller.
     */
    $tampered = ['0' => ['body' => 'Sent when the clock cannot be read.', 'code' => 'GHOST']];

    Assert::null(TC::offerForMonth($tampered, 0), 'month 0 matches nothing, whatever is stored');
    Assert::null(TC::offerForMonth(['13' => ['body' => 'x', 'code' => 'Y']], 13));
});
