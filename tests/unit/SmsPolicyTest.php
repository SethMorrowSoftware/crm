<?php

declare(strict_types=1);

/**
 * The three questions about a text message that can be answered without a
 * database, a network or a clock.
 *
 * SEGMENT COUNTING IS WHERE THE MONEY IS. A text is not "160 characters": it
 * is 160 *in GSM-7* and 70 in UCS-2, and one curly apostrophe pasted in from
 * a word processor re-encodes the whole message and can take a 300-character
 * offer from two segments to five. On a 40,000-recipient Text Club blast that
 * is the difference between $600 and $1,500, decided by a character nobody can
 * see. Every boundary below is a real one, and each is tested on both sides.
 *
 * QUIET HOURS defer, they do not drop — and the case worth getting right is
 * the one after the window has closed, because "wait until the window opens"
 * naively computed sends a nine-o'clock evening blast at one minute past
 * midnight.
 *
 * KEYWORDS are matched on the WHOLE message. A substring test accepts
 * "STOPWATCH" as an opt-out; treating "please stop sending me these" as a
 * keyword closes the conversation and loses a complaint a human needed to read.
 */

use App\Services\SmsPolicy as S;
use Tests\Assert;
use Tests\Harness;

Harness::suite('unit/sms-policy');

require_once CC_ROOT . '/app/Services/SmsPolicy.php';

// ------------------------------------------------------------------ encoding

Harness::test('ordinary English is GSM-7', function (): void {
    Assert::same('gsm7', S::encoding('Your party is confirmed for Saturday at 2pm.'));
    Assert::same('gsm7', S::encoding(''), 'and so is nothing at all');
});

Harness::test('the GSM alphabet includes the characters people assume it does not', function (): void {
    // Each of these is in the basic 128 and costs ONE slot, so a composer
    // that warned about them would be crying wolf.
    foreach (['£', '¥', 'é', 'è', 'ù', 'ì', 'ò', 'Ç', 'Ø', 'Å', 'Æ', 'ß', 'É', '¤', '¡', '¿', 'Ä', 'Ö', 'Ñ', 'Ü', '§', 'ä', 'ö', 'ñ', 'ü', 'à'] as $char) {
        Assert::same('gsm7', S::encoding($char), $char . ' is in the GSM alphabet');
        Assert::same(1, S::length($char), 'and costs one slot');
    }
});

Harness::test('ONE character outside the alphabet re-encodes the WHOLE message', function (): void {
    // There is no mixing: the message is GSM-7 or it is not. This is the
    // single most expensive fact about SMS and the least obvious.
    $plain = str_repeat('a', 300);

    Assert::same('gsm7', S::encoding($plain));
    Assert::same(2, S::segments($plain), '300 plain characters is two messages');

    Assert::same('ucs2', S::encoding($plain . '🎉'));
    Assert::same(
        5,
        S::segments($plain . '🎉'),
        'and the same text with one emoji is FIVE — a single character more than doubled the bill'
    );
});

Harness::test('the characters a word processor produces are the usual culprits', function (): void {
    // A curly apostrophe is invisible at a glance and is what actually
    // happens when somebody drafts an offer in Word and pastes it in.
    foreach (['’' => 'curly apostrophe', '“' => 'curly quote', '—' => 'em dash', '…' => 'ellipsis', '°' => 'degree sign'] as $char => $name) {
        Assert::same('ucs2', S::encoding('it' . $char . 's here'), $name . ' forces Unicode');
    }

    // And the composer must be able to NAME them, because "replace ’ with '"
    // is actionable where "your message is Unicode" is not.
    Assert::same(['’'], S::offendingCharacters('it’s a party'));
    Assert::same(
        ['’', '—'],
        S::offendingCharacters('it’s a party — really'),
        'reported once each, in the order they appear'
    );
    Assert::same([], S::offendingCharacters('plain text'), 'and nothing to report when there is nothing');
    Assert::count(3, S::offendingCharacters('a’b—c…d°e', 3), 'bounded, so a pasted novel does not fill the page');
});

// -------------------------------------------------------------------- length

Harness::test('a GSM extension character costs TWO slots', function (): void {
    // ^ { } \ [ ~ ] | € are sent as an escape plus the character. A message of
    // 160 square brackets is two messages, not one, and nothing about it looks
    // long.
    foreach (['^', '{', '}', '\\', '[', '~', ']', '|', '€'] as $char) {
        Assert::same('gsm7', S::encoding($char), $char . ' is still GSM-7');
        Assert::same(2, S::length($char), 'but costs two slots');
    }

    Assert::same(160, S::length(str_repeat('[', 80)), 'eighty brackets fill a single message exactly');
    Assert::same(1, S::segments(str_repeat('[', 80)));
    Assert::same(2, S::segments(str_repeat('[', 81)), 'and eighty-one does not fit');
});

Harness::test('an emoji outside the basic plane costs TWO UCS-2 units', function (): void {
    // Counting code points under-reports the bill: 🎉 is U+1F389, a surrogate
    // pair on the wire. Every emoji anybody actually uses is above U+FFFF.
    Assert::same(2, S::length('🎉'));
    Assert::same(4, S::length('🎉🎉'), 'two of them, four units');
    Assert::same(1, S::segments(str_repeat('🎉', 35)), 'so thirty-five fill a single Unicode message');
    Assert::same(2, S::segments(str_repeat('🎉', 36)), 'and thirty-six do not');

    // A mixed message measures each character for what it is: four letters
    // at one unit each, two emoji at two.
    Assert::same(8, S::length('ab🎉cd🎉'));
});

Harness::test('a BMP character outside GSM costs one UCS-2 unit', function (): void {
    // ’ is U+2019: not in the GSM alphabet, so the message is UCS-2, and
    // inside the basic plane, so it is one unit rather than two.
    Assert::same('ucs2', S::encoding('’'));
    Assert::same(1, S::length('’'));
    Assert::same(70, S::length(str_repeat('’', 70)), 'seventy of them fill a single Unicode message');
    Assert::same(1, S::segments(str_repeat('’', 70)));
    Assert::same(2, S::segments(str_repeat('’', 71)));
});

// ------------------------------------------------------------------ segments

Harness::test('THE GSM BOUNDARIES, on both sides of each', function (): void {
    // 160 for one message, then 153 each — the concatenation header costs
    // seven characters per segment, so the limit DROPS when a message grows.
    Assert::same(1, S::segments(str_repeat('a', 1)));
    Assert::same(1, S::segments(str_repeat('a', 160)), '160 is one message');
    Assert::same(2, S::segments(str_repeat('a', 161)), '161 is two');
    Assert::same(2, S::segments(str_repeat('a', 306)), '2 × 153 still fits in two');
    Assert::same(3, S::segments(str_repeat('a', 307)), 'and one more makes three');
    Assert::same(3, S::segments(str_repeat('a', 459)));
    Assert::same(4, S::segments(str_repeat('a', 460)));
});

Harness::test('THE UCS-2 BOUNDARIES, on both sides of each', function (): void {
    // 70, then 67 each.
    $u = static fn (int $n): string => str_repeat('’', $n);

    Assert::same(1, S::segments($u(70)));
    Assert::same(2, S::segments($u(71)));
    Assert::same(2, S::segments($u(134)), '2 × 67');
    Assert::same(3, S::segments($u(135)));
    Assert::same(3, S::segments($u(201)));
    Assert::same(4, S::segments($u(202)));
});

Harness::test('an empty message is reported as one, not free', function (): void {
    // The provider would accept it and charge for it. Refusing an empty body
    // is the send path's job; reporting it as costing nothing is a different
    // kind of wrong, and it is the kind that shows up on an invoice.
    Assert::same(1, S::segments(''));
    Assert::same(0, S::length(''));
});

// ----------------------------------------------------------------- remaining

Harness::test('characters remaining is measured against the CURRENT segment count', function (): void {
    // Not "limit minus length". Crossing from one segment to two re-measures
    // the whole message against the smaller limit, so a composer doing the
    // naive subtraction shows a number that jumps backwards.
    Assert::same(155, S::remaining('hello'));
    Assert::same(0, S::remaining(str_repeat('a', 160)), 'full at 160');
    Assert::same(
        145,
        S::remaining(str_repeat('a', 161)),
        'and 306 minus 161 at 161 — the second segment brought 153, not 160'
    );
    Assert::same(0, S::remaining(str_repeat('a', 306)));
    Assert::same(152, S::remaining(str_repeat('a', 307)));
});

// ---------------------------------------------------------------------- cost

Harness::test('cost rounds half AWAY from zero, in integer cents', function (): void {
    // intdiv() truncates, which under-reports — and the figure being
    // under-reported is the one somebody is about to approve for a blast to
    // the whole list.
    Assert::same(1, S::costCents(1, 750), 'three quarters of a cent rounds up to one');
    Assert::same(2, S::costCents(3, 750), '2.25 cents rounds to 2');
    Assert::same(3, S::costCents(4, 750), '3 cents exactly');
    Assert::same(30000, S::costCents(1, 750, 40000), 'and forty thousand recipients is $300');
    Assert::same(90000, S::costCents(3, 750, 40000), 'tripled by a single emoji: $900');
    Assert::same(0, S::costCents(1, 0), 'a free driver costs nothing');
    Assert::same(0, S::costCents(-5, 750), 'and nonsense inputs cost nothing rather than a negative');
    Assert::same(0, S::costCents(1, 750, 0));
});

Harness::test('the composer summary names the count, the encoding and the room left', function (): void {
    Assert::same('1 message · GSM-7 · 155 characters left', S::describe('hello'));
    Assert::same('2 messages · GSM-7 · 145 characters left', S::describe(str_repeat('a', 161)));
    Assert::contains('Unicode', S::describe('it’s a party'), 'and says Unicode in plain words');
});

// -------------------------------------------------------------- quiet hours

Harness::test('THE PERMITTED WINDOW, on both sides of each edge', function (): void {
    // 9am to 8pm. Half-open at the end: 8pm is outside, the way every other
    // window in this application works.
    $start = 9 * 60;
    $end   = 20 * 60;

    Assert::false(S::withinWindow(8 * 60 + 59, $start, $end), 'one minute before nine is quiet');
    Assert::true(S::withinWindow(9 * 60, $start, $end), 'nine exactly is permitted');
    Assert::true(S::withinWindow(19 * 60 + 59, $start, $end));
    Assert::false(S::withinWindow(20 * 60, $start, $end), 'eight exactly is quiet');
    Assert::false(S::withinWindow(3 * 60, $start, $end), 'and three in the morning certainly is');
});

Harness::test('a window whose start equals its end means NO RESTRICTION', function (): void {
    // The alternative reading — "no sending at all" — gives a venue that
    // saves 0 and 0 an install that silently never sends another text, which
    // nobody would diagnose from the settings page.
    Assert::true(S::withinWindow(3 * 60, 0, 0), 'unset settings do not switch texting off');
    Assert::true(S::withinWindow(23 * 60, 540, 540));
    Assert::same(0, S::minutesUntilWindow(3 * 60, 0, 0), 'and nothing waits');
});

Harness::test('a window that wraps midnight is defined rather than arbitrary', function (): void {
    // Unlikely for a family entertainment centre, but a setting that can be
    // saved has to mean something.
    $start = 22 * 60;
    $end   = 6 * 60;

    Assert::true(S::withinWindow(23 * 60, $start, $end), 'eleven at night is inside 22:00–06:00');
    Assert::true(S::withinWindow(2 * 60, $start, $end), 'and so is two in the morning');
    Assert::false(S::withinWindow(12 * 60, $start, $end), 'noon is not');
    Assert::same(10 * 60, S::minutesUntilWindow(12 * 60, $start, $end), 'which waits until ten tonight');
});

Harness::test('A MESSAGE AFTER THE WINDOW CLOSES WAITS FOR TOMORROW, not for midnight', function (): void {
    // The case the naive version gets wrong. "Wait until the window opens"
    // computed as start minus now is NEGATIVE in the evening, and a negative
    // wait clamped to zero sends a nine-o'clock blast immediately — or, if
    // the queue runs on the clock, at 00:01.
    $start = 9 * 60;
    $end   = 20 * 60;

    Assert::same(0, S::minutesUntilWindow(12 * 60, $start, $end), 'noon goes now');
    Assert::same(120, S::minutesUntilWindow(7 * 60, $start, $end), 'seven in the morning waits two hours');
    Assert::same(
        12 * 60,
        S::minutesUntilWindow(21 * 60, $start, $end),
        'and nine in the evening waits TWELVE hours, to nine tomorrow'
    );
    Assert::same(
        6 * 60,
        S::minutesUntilWindow(3 * 60, $start, $end),
        'three in the morning waits six hours, to nine the same day'
    );
    Assert::same(1, S::minutesUntilWindow(8 * 60 + 59, $start, $end), 'and 08:59 waits one minute');
    Assert::same(
        13 * 60,
        S::minutesUntilWindow(20 * 60, $start, $end),
        'the first quiet minute waits the longest'
    );
});

Harness::test('minutes outside a day are wrapped, not rejected', function (): void {
    // 1440 is midnight tomorrow, and a caller doing arithmetic on a local
    // minute can land there. Answering as though it were midnight is right;
    // throwing, or treating it as hour 24 of a 25-hour day, is not.
    Assert::same(S::withinWindow(0, 540, 1200), S::withinWindow(1440, 540, 1200));
    Assert::same(S::withinWindow(60, 540, 1200), S::withinWindow(1500, 540, 1200));
    Assert::true(S::withinWindow(-180, 1200, 540), 'and a negative minute wraps backwards');
});

// ------------------------------------------------------------------- opt-out

Harness::test('A BULK MESSAGE MUST SAY HOW TO STOP, and any sensible wording counts', function (): void {
    foreach ([
        'Reply STOP to opt out',
        'Txt STOP to unsubscribe',
        'STOP to quit',
        'Reply stop to stop',
        'Text QUIT anytime',
        'Reply CANCEL to end these',
        'Reply END to stop',
        'Msg&data rates may apply. Reply UNSUBSCRIBE to opt out.',
    ] as $body) {
        Assert::true(S::hasOptOut($body), '"' . $body . '" offers a way out');
    }
});

Harness::test('and a SUBSTRING is not an opt-out', function (): void {
    // The bug a naive str_contains() ships with. Each of these would pass a
    // substring test and tells the reader nothing.
    foreach ([
        'Buy a STOPWATCH today',
        'Non-stopping fun at the Castle',
        'Our ENDLESS summer pass is here',
        'Quito is lovely in spring',
        'Save 20% on all CANCELLATION cover',
    ] as $body) {
        Assert::false(S::hasOptOut($body), '"' . $body . '" is not an opt-out');
    }

    Assert::false(S::hasOptOut('Party time at the Castle!'), 'and neither is an ordinary message');
    Assert::false(S::hasOptOut(''));
});

// ------------------------------------------------------------------ keywords

Harness::test('an inbound keyword is the WHOLE message, punctuation and case aside', function (): void {
    foreach (['STOP', 'stop', 'Stop', ' stop ', 'STOP.', 'STOP!', '  Stop!!  '] as $body) {
        Assert::same('stop', S::keywordFor($body), '"' . $body . '" stops');
    }

    Assert::same('stop', S::keywordFor('unsubscribe'));
    Assert::same('stop', S::keywordFor('CANCEL'));
    Assert::same('start', S::keywordFor('START'));
    Assert::same('start', S::keywordFor('unstop'));
    Assert::same('confirm', S::keywordFor('YES'), 'the double opt-in confirmation');
    Assert::same('help', S::keywordFor('help'));
    Assert::same('join', S::keywordFor('JOIN'), 'the Text Club opt-in');
});

Harness::test('A SENTENCE CONTAINING A KEYWORD IS NOT A KEYWORD', function (): void {
    // This one matters in both directions. Treating it as a keyword closes
    // the conversation and loses a complaint a person needed to read; the
    // venue should still honour it, but by a human reading it rather than a
    // regex guessing.
    Assert::null(S::keywordFor('please stop sending me these'));
    Assert::null(S::keywordFor('stop by on Saturday?'));
    Assert::null(S::keywordFor('can you help me rebook'));
    Assert::null(S::keywordFor('yes please, 2pm works'));
    Assert::null(S::keywordFor('Is the party still on?'));
    Assert::null(S::keywordFor(''));
    Assert::null(S::keywordFor('   '));
});

Harness::test('every stop keyword also satisfies the opt-out requirement', function (): void {
    // The two lists are derived from one table on purpose: a keyword a guest
    // can text to stop, which a bulk message is not allowed to cite as the
    // way to stop, would be a contradiction nobody notices until a regulator
    // does.
    foreach (S::KEYWORDS as $word => $action) {
        if ($action !== 'stop') {
            continue;
        }

        Assert::true(
            S::hasOptOut('Reply ' . $word . ' to opt out'),
            $word . ' is accepted as an opt-out instruction'
        );
        Assert::same('stop', S::keywordFor($word), 'and acted on when a guest texts it');
    }
});
