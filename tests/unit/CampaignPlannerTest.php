<?php

declare(strict_types=1);

/**
 * What a campaign will cost, who it will reach, and when it may go.
 *
 * Every number here is read immediately before somebody presses a button that
 * cannot be taken back, which is why three of them get most of this file.
 *
 *   * **THE COST.** A text costs per SEGMENT, and the segment count depends on
 *     the characters in the body. One curly apostrophe pasted in from a word
 *     processor can take a 300-character offer from two segments to five — on
 *     forty thousand recipients that is $600 against $1,500, decided by a
 *     character nobody can see.
 *   * **THE REACH.** A segment selects people; it does not grant permission to
 *     contact them. "400 in the segment, 180 will get this, 220 have not
 *     opted in" is the most useful sentence on the page, and a venue that only
 *     saw 400 would conclude the system was broken when 180 arrived.
 *   * **THE BLOCKERS.** All of them at once, because somebody fixing a
 *     campaign should not press send four times to find four problems.
 */

use App\Services\CampaignPlanner as P;
use App\Services\SmsPolicy;
use Tests\Assert;
use Tests\Harness;

Harness::suite('unit/campaign-planner');

require_once CC_ROOT . '/app/Services/SmsPolicy.php';
require_once CC_ROOT . '/app/Services/CampaignPlanner.php';

/** A body that satisfies the bulk opt-out rule. */
const CP_BULK = 'Half price go-karts this Saturday. Reply STOP to opt out.';

// ==================================================================== cost

Harness::test('THE TOTAL IS MULTIPLIED BEFORE IT IS ROUNDED', function (): void {
    // Carriers quote fractions of a cent. Rounding each message up and THEN
    // multiplying over-states a forty-thousand-recipient blast by a third at
    // three quarters of a cent a message — $400 against the real $300, which
    // is the figure somebody is deciding on.
    $estimate = P::estimate('sms', CP_BULK, 40000, 750);

    Assert::same(30000, $estimate['total_cents'], '40,000 at 0.75c is $300, not $400');
    Assert::same(1, $estimate['unit_cost_cents'], 'while ONE message rounds up to a cent');

    // The two really do disagree, which is the point.
    Assert::notSame(
        $estimate['unit_cost_cents'] * 40000,
        $estimate['total_cents'],
        'per-message rounding times the count is a different, wrong number'
    );
});

Harness::test('A LONGER TEXT COSTS PROPORTIONALLY MORE', function (): void {
    $short = P::estimate('sms', CP_BULK, 1000, 750);
    $long  = P::estimate('sms', str_repeat('a', 300) . ' Reply STOP to opt out.', 1000, 750);

    Assert::same(1, $short['segments']);
    Assert::same(3, $long['segments'], 'three texts');
    Assert::same($short['total_cents'] * 3, $long['total_cents'], 'and three times the cost');
});

Harness::test('AND ONE EMOJI CAN TRIPLE THE BILL', function (): void {
    // The case that is invisible on the page and obvious on the invoice.
    $plain = str_repeat('a', 300) . ' Reply STOP to opt out.';
    $withEmoji = $plain . ' 🎉';

    $before = P::estimate('sms', $plain, 40000, 750);
    $after  = P::estimate('sms', $withEmoji, 40000, 750);

    Assert::same('gsm7', $before['encoding']);
    Assert::same('ucs2', $after['encoding'], 'one emoji re-encodes the whole message');

    Assert::same(3, $before['segments']);
    Assert::greaterThan(
        $before['segments'],
        $after['segments'],
        'which costs more segments: ' . $before['segments'] . ' -> ' . $after['segments']
    );
    Assert::greaterThan(
        $before['total_cents'],
        $after['total_cents'],
        'and more money'
    );

    // The page shows the encoding, so this is visible rather than a surprise.
    Assert::same('ucs2', $after['encoding']);
});

Harness::test('an email is one message however long it is', function (): void {
    $short = P::estimate('email', 'Hi', 1000, 750);
    $long  = P::estimate('email', str_repeat('words and words ', 500), 1000, 750);

    Assert::same(1, $short['segments']);
    Assert::same(1, $long['segments'], 'length does not segment an email');
    Assert::same($short['total_cents'], $long['total_cents']);
    Assert::same('n/a', $long['encoding'], 'and encoding is not a thing it reports');
});

Harness::test('A BIG SEND ASKS FOR CONFIRMATION; a test send does not', function (): void {
    // Low enough that the first real blast asks, high enough that testing on
    // a handful of people does not.
    $small = P::estimate('sms', CP_BULK, 50, 750);
    $big   = P::estimate('sms', CP_BULK, 40000, 750);

    Assert::false($small['needs_confirmation'], 'fifty people is 38 cents');
    Assert::true($big['needs_confirmation'], 'forty thousand is $300');
    Assert::greaterThan(0, $big['over_by_cents'], 'and it says by how much');

    // The boundary, on both sides.
    $atThreshold    = P::estimate('sms', CP_BULK, 1, 10 * 1000 * 200);
    $overThreshold  = P::estimate('sms', CP_BULK, 1, 10 * 1000 * 201);

    Assert::same(P::CONFIRM_ABOVE_CENTS, $atThreshold['total_cents'], 'exactly at it');
    Assert::false($atThreshold['needs_confirmation'], 'and exactly at it does not ask');
    Assert::true($overThreshold['needs_confirmation'], 'a cent over does');
});

Harness::test('a free driver costs nothing and asks nothing', function (): void {
    // The log driver, and the default on a fresh install. A confirmation
    // prompt for a dry run would train people to click through it.
    $estimate = P::estimate('sms', CP_BULK, 40000, 0);

    Assert::same(0, $estimate['total_cents']);
    Assert::false($estimate['needs_confirmation']);
});

Harness::test('nonsense inputs cost nothing rather than a negative', function (): void {
    Assert::same(0, P::estimate('sms', CP_BULK, -5, 750)['total_cents']);
    Assert::same(0, P::estimate('sms', CP_BULK, 0, 750)['total_cents']);
    Assert::same(0, P::estimate('sms', CP_BULK, 100, -750)['total_cents']);
});

// ================================================================= audience

Harness::test('A SEGMENT MEMBER NEEDS AN ADDRESS AND CONSENT ON THE CHANNEL', function (): void {
    $fine = ['id' => 1, 'email' => 'a@example.test', 'email_consent' => 1];

    Assert::null(P::skipReason($fine, 'email'), 'an address and a yes is enough');

    Assert::same(
        'no_email',
        P::skipReason(['id' => 2, 'email' => '', 'email_consent' => 1], 'email'),
        'consent with nowhere to send it is not reachable'
    );
    Assert::same(
        'no_consent',
        P::skipReason(['id' => 3, 'email' => 'a@example.test'], 'email'),
        'an address with no yes is not permission'
    );

    // The same contact on the OTHER channel is a different answer, which is
    // the whole reason this takes the channel.
    Assert::same('no_mobile', P::skipReason($fine, 'sms'), 'no mobile number on them');

    $texter = ['id' => 4, 'phone_normalized' => '+18455551234', 'sms_consent' => 1];

    Assert::null(P::skipReason($texter, 'sms'));
    Assert::same('no_email', P::skipReason($texter, 'email'), 'and vice versa');

    // THE SMS MIRROR OF THE EMAIL CASE ABOVE, which the first version of this
    // file left out — so removing the sms consent check entirely passed every
    // test. A number on file is not permission to text it.
    Assert::same(
        'no_consent',
        P::skipReason(['id' => 5, 'phone_normalized' => '+18455551234'], 'sms'),
        'a number with no yes is not permission'
    );
    Assert::same(
        'no_consent',
        P::skipReason(['id' => 6, 'phone_normalized' => '+18455551234', 'sms_consent' => 0], 'sms'),
        'nor is an explicit no'
    );

    // And email consent is not text consent, which is the mistake that ends
    // in a regulator's letter rather than a bug report.
    Assert::same(
        'no_consent',
        P::skipReason([
            'id' => 7, 'phone_normalized' => '+18455551234', 'email_consent' => 1,
        ], 'sms'),
        'saying yes to email is not saying yes to texts'
    );
});

Harness::test('DO-NOT-CONTACT OUTRANKS EVERYTHING, on both channels', function (): void {
    // An absolute instruction, and it must be the reason REPORTED — "has not
    // opted in" would send somebody to fix a consent flag on a guest who
    // asked never to be contacted at all.
    $blocked = [
        'id'               => 1,
        'email'            => 'a@example.test',
        'email_consent'    => 1,
        'phone_normalized' => '+18455551234',
        'sms_consent'      => 1,
        'do_not_contact'   => 1,
    ];

    Assert::same('do_not_contact', P::skipReason($blocked, 'email'));
    Assert::same('do_not_contact', P::skipReason($blocked, 'sms'));

    // Even with nothing else on file, that is still the reason given.
    Assert::same(
        'do_not_contact',
        P::skipReason(['id' => 2, 'do_not_contact' => 1], 'email'),
        'the instruction, not the missing address'
    );
});

Harness::test('THE AUDIENCE SPLIT COUNTS BOTH SIDES AND KEEPS EVERY REASON', function (): void {
    // A skipped recipient is RECORDED, never dropped: a campaign that reached
    // 180 of 400 with no explanation looks like a bug in the sending.
    $contacts = [
        ['id' => 1, 'email' => 'a@example.test', 'email_consent' => 1],
        ['id' => 2, 'email' => 'b@example.test', 'email_consent' => 1],
        ['id' => 3, 'email' => 'c@example.test', 'email_consent' => 0],
        ['id' => 4, 'email' => '', 'email_consent' => 1],
        ['id' => 5, 'email' => 'e@example.test', 'email_consent' => 1, 'do_not_contact' => 1],
    ];

    $audience = P::audience($contacts, 'email');

    Assert::same([1, 2], $audience['sendable']);
    Assert::same(5, $audience['total'], 'everybody is accounted for');
    Assert::same(2, $audience['reach']);
    Assert::same(3, count($audience['skipped']), 'with a reason each');

    Assert::same('no_consent', $audience['skipped'][3]);
    Assert::same('no_email', $audience['skipped'][4]);
    Assert::same('do_not_contact', $audience['skipped'][5]);

    Assert::same(['no_consent' => 1, 'no_email' => 1, 'do_not_contact' => 1], $audience['counts']);

    // Nobody is lost or double-counted.
    Assert::same(
        $audience['total'],
        $audience['reach'] + array_sum($audience['counts']),
        'the two sides add up to the whole segment'
    );
});

Harness::test('a row with no id is ignored rather than counted as unreachable', function (): void {
    // Otherwise a malformed row inflates the "cannot be reached" figure and
    // sends somebody looking for a guest who does not exist.
    $audience = P::audience([
        ['id' => 1, 'email' => 'a@example.test', 'email_consent' => 1],
        ['email' => 'b@example.test', 'email_consent' => 1],
        ['id' => 0, 'email' => 'c@example.test', 'email_consent' => 1],
    ], 'email');

    Assert::same(1, $audience['total']);
    Assert::same(1, $audience['reach']);
});

Harness::test('AN EMPTY SEGMENT REACHES NOBODY, and says that rather than nothing', function (): void {
    $audience = P::audience([], 'email');

    Assert::same(0, $audience['total']);
    Assert::same(0, $audience['reach']);
    Assert::same([], $audience['sendable']);
    Assert::contains('Nobody is in this segment', P::describeAudience($audience));
});

Harness::test('THE AUDIENCE SENTENCE NAMES THE BIGGEST REASON FIRST', function (): void {
    // The largest reason is the one worth acting on, so it leads.
    $contacts = [];

    for ($i = 1; $i <= 10; $i++) {
        $contacts[] = ['id' => $i, 'email' => 'a' . $i . '@example.test', 'email_consent' => 1];
    }

    for ($i = 11; $i <= 40; $i++) {
        $contacts[] = ['id' => $i, 'email' => 'b' . $i . '@example.test', 'email_consent' => 0];
    }

    $contacts[] = ['id' => 41, 'email' => '', 'email_consent' => 1];

    $sentence = P::describeAudience(P::audience($contacts, 'email'));

    Assert::contains('41 in the segment', $sentence);
    Assert::contains('10 will get this', $sentence);
    Assert::contains('30 have not opted in', $sentence);
    Assert::contains('1 has no email address', $sentence);

    Assert::true(
        strpos($sentence, 'have not opted in') < strpos($sentence, 'has no email address'),
        'the thirty come before the one'
    );
});

Harness::test('and it is singular when there is one of something', function (): void {
    $sentence = P::describeAudience(P::audience([
        ['id' => 1, 'email' => 'a@example.test', 'email_consent' => 1],
        ['id' => 2, 'email' => 'b@example.test', 'email_consent' => 0],
    ], 'email'));

    Assert::contains('1 has not opted in', $sentence, 'not "1 have not opted in"');
});

Harness::test('every skip reason has a readable phrase', function (): void {
    // These strings reach a member of staff, so a bare `no_consent` is not
    // good enough — and a reason with no phrase would read as "cannot be
    // reached", which explains nothing.
    foreach (array_keys(P::SKIP_REASONS) as $reason) {
        $sentence = P::describeAudience([
            'total'  => 2,
            'reach'  => 1,
            'counts' => [$reason => 1],
        ]);

        Assert::notContains(
            $reason,
            $sentence,
            $reason . ' is turned into words rather than printed raw'
        );
        Assert::notContains(
            'cannot be reached',
            $sentence,
            $reason . ' has its own phrase rather than the fallback'
        );
    }
});

// ============================================================= send window

Harness::test('no send window means no restriction', function (): void {
    // And HALF a window means none either: half is almost certainly a
    // half-filled form, and guessing the other end would either stop all
    // sending or allow all of it, silently.
    Assert::true(P::windowAllows(3 * 60, null, null), 'neither end set');
    Assert::true(P::windowAllows(3 * 60, 9 * 60, null), 'only a start');
    Assert::true(P::windowAllows(3 * 60, null, 20 * 60), 'only an end');
    Assert::same(0, P::minutesUntilWindow(3 * 60, 9 * 60, null));
});

Harness::test('a send window is the venue\'s own clock, and defers rather than refuses', function (): void {
    $start = 9 * 60;
    $end   = 20 * 60;

    Assert::true(P::windowAllows(12 * 60, $start, $end));
    Assert::false(P::windowAllows(8 * 60 + 59, $start, $end), 'one minute early');
    Assert::false(P::windowAllows(20 * 60, $start, $end), 'the end is exclusive');

    Assert::same(0, P::minutesUntilWindow(12 * 60, $start, $end), 'noon goes now');
    Assert::same(120, P::minutesUntilWindow(7 * 60, $start, $end), 'seven waits two hours');
    Assert::same(
        13 * 60,
        P::minutesUntilWindow(20 * 60, $start, $end),
        'and eight in the evening waits until nine TOMORROW, not until midnight'
    );
});

// ================================================================ blockers

Harness::test('EVERY BLOCKER IS REPORTED AT ONCE, not one at a time', function (): void {
    // Somebody fixing a campaign should not have to press send four times to
    // discover four problems.
    $blockers = P::blockers([
        'channel'    => '',
        'segment_id' => null,
        'body'       => '',
        'subject'    => '',
    ], ['total' => 0, 'reach' => 0]);

    Assert::greaterThan(3, count($blockers), 'all of them: ' . implode(' | ', $blockers));

    $all = implode(' ', $blockers);

    Assert::contains('email or as a text', $all, 'the channel');
    Assert::contains('segment', $all);
    Assert::contains('Write the message', $all);
    Assert::contains('Nobody is in that segment', $all);
});

Harness::test('a ready campaign has no blockers', function (): void {
    // The positive control. A function that always returned a blocker would
    // satisfy every test above.
    Assert::same([], P::blockers([
        'channel'    => 'sms',
        'segment_id' => 3,
        'body'       => CP_BULK,
    ], ['total' => 400, 'reach' => 180]));

    Assert::same([], P::blockers([
        'channel'    => 'email',
        'segment_id' => 3,
        'body'       => '<p>Half price karts.</p>',
        'subject'    => 'This Saturday only',
    ], ['total' => 400, 'reach' => 180]));
});

Harness::test('A BULK EMAIL WITH NO SUBJECT IS BLOCKED', function (): void {
    // A deliverability rule rather than a tidiness one: most providers filter
    // a subjectless bulk mail as spam, so it is money spent on nothing.
    $blockers = P::blockers([
        'channel'    => 'email',
        'segment_id' => 3,
        'body'       => '<p>Hello</p>',
        'subject'    => '   ',
    ], ['total' => 10, 'reach' => 10]);

    Assert::count(1, $blockers);
    Assert::contains('subject', $blockers[0]);

    // And a TEXT needs no subject, so the rule is channel-specific.
    Assert::same([], P::blockers([
        'channel'    => 'sms',
        'segment_id' => 3,
        'body'       => CP_BULK,
    ], ['total' => 10, 'reach' => 10]));
});

Harness::test('A BULK TEXT WITH NO WAY TO STOP IS BLOCKED', function (): void {
    $blockers = P::blockers([
        'channel'    => 'sms',
        'segment_id' => 3,
        'body'       => 'Half price karts this Saturday!',
    ], ['total' => 10, 'reach' => 10]);

    Assert::count(1, $blockers);
    Assert::contains('STOP', $blockers[0]);

    // An EMAIL does not need those words — it carries a List-Unsubscribe
    // header and a footer instead, added by Mailer.
    Assert::same([], P::blockers([
        'channel'    => 'email',
        'segment_id' => 3,
        'subject'    => 'This Saturday',
        'body'       => 'Half price karts this Saturday!',
    ], ['total' => 10, 'reach' => 10]));
});

Harness::test('a text too long to arrive in one piece is blocked', function (): void {
    $blockers = P::blockers([
        'channel'    => 'sms',
        'segment_id' => 3,
        'body'       => str_repeat('a', 153 * (SmsPolicy::MAX_SEGMENTS + 1)) . ' Reply STOP to opt out.',
    ], ['total' => 10, 'reach' => 10]);

    Assert::count(1, $blockers);
    Assert::contains('too long', $blockers[0]);
});

Harness::test('A SEGMENT WITH PEOPLE BUT NO REACH SAYS WHICH IT IS', function (): void {
    // "Nobody is in that segment" and "nobody in it can be reached on this
    // channel" are different problems with different fixes, and a single
    // message for both sends somebody to the wrong page.
    $empty = P::blockers([
        'channel' => 'sms', 'segment_id' => 3, 'body' => CP_BULK,
    ], ['total' => 0, 'reach' => 0]);

    $unreachable = P::blockers([
        'channel' => 'sms', 'segment_id' => 3, 'body' => CP_BULK,
    ], ['total' => 400, 'reach' => 0]);

    Assert::contains('Nobody is in that segment', $empty[0]);
    Assert::contains('can be reached on this channel', $unreachable[0]);
    Assert::notSame($empty[0], $unreachable[0]);
});

// ================================================================== chunks

Harness::test('a send is chunked, and the page can say how long it will take', function (): void {
    // A venue pressing send on forty thousand recipients should be told it
    // will take a while rather than watching a bar they assume is stuck.
    Assert::same(1, P::chunksFor(1));
    Assert::same(1, P::chunksFor(P::CHUNK), 'exactly one chunk');
    Assert::same(2, P::chunksFor(P::CHUNK + 1));
    Assert::same(800, P::chunksFor(40000), 'forty thousand at fifty a minute');
    Assert::same(0, P::chunksFor(0), 'and nothing takes no ticks');

    Assert::same('under a minute', P::durationLabel(10));
    Assert::same('under a minute', P::durationLabel(P::CHUNK));
    Assert::same('about 2 minutes', P::durationLabel(P::CHUNK + 1));
    Assert::contains('hours', P::durationLabel(40000), 'and a big one is honest about it');
    Assert::same('about 1 hour', P::durationLabel(P::CHUNK * 60), 'singular at exactly one');
});
