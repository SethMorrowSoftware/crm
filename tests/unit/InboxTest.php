<?php

declare(strict_types=1);

/**
 * The parts of the inbox that can be decided without reading a row.
 *
 * SNOOZE ARITHMETIC IS THE REASON THIS FILE EXISTS. "Remind me tomorrow
 * morning" means nine o'clock in the venue's own wall clock, and the gap
 * between now and then is 23, 24 or 25 hours depending on the date. Adding
 * 86400 seconds is right for 363 days a year; on the other two it buries a
 * guest's message for an extra hour or surfaces it an hour early, on a Sunday
 * morning, when nobody is watching the queue. Both 2026 transitions appear
 * below, and the fixture dates are fixed so these hold whenever the suite runs.
 *
 * FIRST RESPONSE is reported per rep, so what counts as one is a judgement
 * about fairness rather than a detail. A newsletter that happened to go out
 * while a guest's question sat unanswered is not an answer, and counting it
 * would make the metric read BEST for the reps who answer least.
 */

use App\Services\Inbox as I;
use Tests\Assert;
use Tests\Harness;

Harness::suite('unit/inbox');

require_once CC_ROOT . '/app/Services/Inbox.php';

const INBOX_TZ = 'America/New_York';

/** A venue-local instant, for readability. */
function inbox_at(string $when): DateTimeImmutable
{
    return new DateTimeImmutable($when, new DateTimeZone(INBOX_TZ));
}

/** What a stored UTC snooze reads as on the venue's own clock. */
function inbox_local(?string $utc): string
{
    if ($utc === null) {
        return '(null)';
    }

    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone(INBOX_TZ))
        ->format('D Y-m-d H:i T');
}

// ------------------------------------------------------- the ordinary cases

Harness::test('a relative snooze is a number of hours from now', function (): void {
    $now = inbox_at('2026-06-10 14:00:00');

    Assert::same('2026-06-10 19:00:00', I::snoozeUntil('1h', $now, INBOX_TZ), 'an hour later, in UTC');
    Assert::same('2026-06-10 21:00:00', I::snoozeUntil('3h', $now, INBOX_TZ));
    Assert::same('Wed 2026-06-10 15:00 EDT', inbox_local(I::snoozeUntil('1h', $now, INBOX_TZ)));
});

Harness::test('a morning snooze lands at nine on the venue\'s clock', function (): void {
    $now = inbox_at('2026-06-10 14:00:00');

    Assert::same('Thu 2026-06-11 09:00 EDT', inbox_local(I::snoozeUntil('tomorrow', $now, INBOX_TZ)));
    Assert::same('Mon 2026-06-15 09:00 EDT', inbox_local(I::snoozeUntil('monday', $now, INBOX_TZ)));
    Assert::same('Wed 2026-06-17 09:00 EDT', inbox_local(I::snoozeUntil('week', $now, INBOX_TZ)));
});

Harness::test('an unrecognised option is refused rather than guessed at', function (): void {
    // A caller that gets null refuses; one that got an arbitrary moment would
    // bury a guest's message at a time nobody chose.
    $now = inbox_at('2026-06-10 14:00:00');

    foreach (['', 'whenever', 'next year', '2h', 'TOMORROW'] as $option) {
        Assert::null(I::snoozeUntil($option, $now, INBOX_TZ), '"' . $option . '" is not an option');
    }
});

// --------------------------------------------------------- the DST cases

Harness::test('SPRING FORWARD: tomorrow morning is still NINE, not ten', function (): void {
    // 8 March 2026, 2am becomes 3am in New York. The day before is EST (-5);
    // the morning after is EDT (-4). Nine o'clock local is 13:00 UTC, not
    // 14:00 — and a snooze computed as "now plus 86400 seconds" from 2pm
    // Saturday would surface at 3pm Sunday, six hours late.
    $now = inbox_at('2026-03-07 14:00:00');

    Assert::same('EST', $now->format('T'), 'precondition: the day before is standard time');

    $utc = I::snoozeUntil('tomorrow', $now, INBOX_TZ);

    Assert::same('2026-03-08 13:00:00', $utc, 'stored as 13:00 UTC');
    Assert::same('Sun 2026-03-08 09:00 EDT', inbox_local($utc), 'which is nine o\'clock local');

    // And the ELAPSED time is 18 hours, where 2pm to 9am the next morning is
    // 19 on an ordinary day — the hour the clocks skip. That one-hour
    // difference is the whole fact a seconds-based implementation cannot
    // represent, in either direction.
    $elapsed = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->getTimestamp()
        - $now->getTimestamp();

    Assert::same(18 * 3600, $elapsed, '18 hours of real time, not the usual 19');
});

Harness::test('FALL BACK: tomorrow morning is still NINE, not eight', function (): void {
    // 1 November 2026, 2am becomes 1am. The day before is EDT (-4); the
    // morning after is EST (-5).
    $now = inbox_at('2026-10-31 14:00:00');

    Assert::same('EDT', $now->format('T'), 'precondition: the day before is daylight time');

    $utc = I::snoozeUntil('tomorrow', $now, INBOX_TZ);

    Assert::same('2026-11-01 14:00:00', $utc);
    Assert::same('Sun 2026-11-01 09:00 EST', inbox_local($utc), 'nine o\'clock local again');

    $elapsed = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->getTimestamp()
        - $now->getTimestamp();

    Assert::same(20 * 3600, $elapsed, '20 hours this time — the day is 25 hours long');
});

Harness::test('a week-long snooze crossing a transition keeps its wall-clock time', function (): void {
    // The case somebody would never think to check, and the one where a
    // seconds-based version is wrong by a whole hour for a whole week.
    $utc = I::snoozeUntil('week', inbox_at('2026-10-29 16:30:00'), INBOX_TZ);

    Assert::same('Thu 2026-11-05 09:00 EST', inbox_local($utc));
});

// ------------------------------------------------ the roll-forward at night

Harness::test('A RELATIVE SNOOZE THAT LANDS AT NIGHT ROLLS TO THE MORNING', function (): void {
    // "In three hours" pressed at nine in the evening is midnight, and a
    // reminder at midnight is a reminder nobody sees. The guest's message
    // deserves to come back when somebody is at a desk.
    Assert::same(
        'Sun 2026-06-14 09:00 EDT',
        inbox_local(I::snoozeUntil('3h', inbox_at('2026-06-13 21:00:00'), INBOX_TZ)),
        'nine in the evening plus three hours is tomorrow morning'
    );

    Assert::same(
        'Sun 2026-06-14 09:00 EDT',
        inbox_local(I::snoozeUntil('1h', inbox_at('2026-06-13 23:30:00'), INBOX_TZ)),
        'and half past eleven plus an hour is too'
    );

    // Already past midnight: the next morning is the SAME calendar day, not
    // the one after. Rolling forward a day here would bury it for 33 hours.
    Assert::same(
        'Sat 2026-06-13 09:00 EDT',
        inbox_local(I::snoozeUntil('1h', inbox_at('2026-06-13 02:00:00'), INBOX_TZ)),
        'two in the morning plus an hour is nine THIS morning'
    );
});

Harness::test('and one that lands inside the working day is left alone', function (): void {
    // The control. A roll-forward that fired on every snooze would quietly
    // turn "in an hour" into "tomorrow" all day long.
    foreach (['09:00:00' => '10:00', '12:00:00' => '13:00', '18:30:00' => '19:30'] as $from => $to) {
        Assert::contains(
            $to,
            inbox_local(I::snoozeUntil('1h', inbox_at('2026-06-10 ' . $from), INBOX_TZ)),
            'an hour after ' . $from . ' is ' . $to
        );
    }
});

Harness::test('a Monday snooze on a Monday means NEXT Monday', function (): void {
    // Not nine o'clock this morning, which is in the past, and which would
    // surface the conversation the instant it was snoozed.
    $monday = inbox_at('2026-06-08 14:00:00');

    Assert::same('Mon', $monday->format('D'), 'precondition: the fixture is a Monday');
    Assert::same('Mon 2026-06-15 09:00 EDT', inbox_local(I::snoozeUntil('monday', $monday, INBOX_TZ)));
});

// --------------------------------------------------------- a custom snooze

Harness::test('a typed snooze is read in the venue\'s own clock', function (): void {
    $now = inbox_at('2026-06-10 14:00:00');

    Assert::same(
        'Thu 2026-12-24 15:30 EST',
        inbox_local(I::snoozeUntilCustom('2026-12-24 15:30', $now, INBOX_TZ)),
        'half past three on Christmas Eve, local'
    );
    Assert::same(
        I::snoozeUntilCustom('2026-12-24 15:30', $now, INBOX_TZ),
        I::snoozeUntilCustom('2026-12-24T15:30', $now, INBOX_TZ),
        'and a datetime-local field\'s T separator reads the same'
    );
});

Harness::test('A TYPED SNOOZE IN THE PAST IS REFUSED', function (): void {
    // A snooze that has already elapsed surfaces instantly, which looks
    // exactly like the button not working — so it is refused and the caller
    // can say why.
    $now = inbox_at('2026-06-10 14:00:00');

    Assert::null(I::snoozeUntilCustom('2026-06-10 13:59', $now, INBOX_TZ), 'a minute ago');
    Assert::null(I::snoozeUntilCustom('2026-06-10 14:00', $now, INBOX_TZ), 'and now is not later');
    Assert::null(I::snoozeUntilCustom('2020-01-01 09:00', $now, INBOX_TZ), 'nor is six years ago');

    Assert::notNull(
        I::snoozeUntilCustom('2026-06-10 14:01', $now, INBOX_TZ),
        'but a minute from now is fine'
    );
});

Harness::test('a malformed typed snooze is refused', function (): void {
    $now = inbox_at('2026-06-10 14:00:00');

    foreach ([
        '', 'tomorrow', '2026-06-11', '11/06/2026 09:00', '2026-06-11 25:00', '2026-06-11 09:70',
    ] as $input) {
        Assert::null(I::snoozeUntilCustom($input, $now, INBOX_TZ), '"' . $input . '" is not a datetime');
    }
});

// ------------------------------------------------------------ first response

Harness::test('A PERSON REPLYING TO A GUEST IS A FIRST RESPONSE', function (): void {
    Assert::true(I::isFirstResponse([
        'direction' => 'outbound',
        'channel'   => 'email',
        'user_id'   => 7,
    ]));

    Assert::true(I::isFirstResponse([
        'direction' => 'outbound',
        'channel'   => 'sms',
        'user_id'   => 7,
    ]), 'on either channel');
});

Harness::test('A CAMPAIGN IS NOT AN ANSWER', function (): void {
    // The unfairness this prevents: a newsletter that went out while a
    // guest's question sat unanswered would stop the clock, and the metric
    // would read best for the reps who answer least.
    Assert::false(I::isFirstResponse([
        'direction'   => 'outbound',
        'channel'     => 'email',
        'user_id'     => 7,
        'campaign_id' => 3,
    ]));
});

Harness::test('AN AUTOMATION IS NOT AN ANSWER EITHER', function (): void {
    // An auto-acknowledgement is worth sending and is not a person replying.
    // The whole point of the measure is how long a guest waited for one.
    Assert::false(I::isFirstResponse([
        'direction'         => 'outbound',
        'channel'           => 'email',
        'user_id'           => 7,
        'automation_run_id' => 11,
    ]));
});

Harness::test('and nor is an internal note, or an inbound message', function (): void {
    Assert::false(I::isFirstResponse([
        'direction' => 'outbound',
        'channel'   => 'internal',
        'user_id'   => 7,
    ]), 'staff talking to each other — the guest has still heard nothing');

    Assert::false(I::isFirstResponse([
        'direction' => 'inbound',
        'channel'   => 'email',
        'user_id'   => null,
    ]), 'the guest writing in is not the venue replying');

    Assert::false(I::isFirstResponse([
        'direction' => 'outbound',
        'channel'   => 'email',
        'user_id'   => null,
    ]), 'and an outbound message with nobody behind it is not a person');

    Assert::false(I::isFirstResponse([]), 'an empty row is not a response');
});

Harness::test('how long a guest waited, and how it reads', function (): void {
    $wait = static fn (string $opened, string $replied): array => [
        'created_at'        => $opened,
        'first_response_at' => $replied,
    ];

    Assert::same(4, I::responseMinutes($wait('2026-06-10 14:00:00', '2026-06-10 14:04:00')));
    Assert::same('4 minutes', I::responseLabel($wait('2026-06-10 14:00:00', '2026-06-10 14:04:00')));
    Assert::same('1 minute', I::responseLabel($wait('2026-06-10 14:00:00', '2026-06-10 14:01:00')));
    Assert::same('2 hours', I::responseLabel($wait('2026-06-10 14:00:00', '2026-06-10 16:00:00')));
    Assert::same('1 hour', I::responseLabel($wait('2026-06-10 14:00:00', '2026-06-10 15:00:00')));
    Assert::same('3 days', I::responseLabel($wait('2026-06-10 14:00:00', '2026-06-13 14:00:00')));
});

Harness::test('an unanswered conversation has NO response time, not a zero', function (): void {
    // Zero would read as "answered instantly" and would drag every average
    // towards nothing — so the worst conversations in the queue would improve
    // the number they ought to be ruining.
    Assert::null(I::responseMinutes(['created_at' => '2026-06-10 14:00:00', 'first_response_at' => null]));
    Assert::null(I::responseLabel(['created_at' => '2026-06-10 14:00:00', 'first_response_at' => null]));
    Assert::null(I::responseMinutes(['created_at' => '2026-06-10 14:00:00', 'first_response_at' => '']));
    Assert::null(I::responseMinutes([]));
    Assert::null(
        I::responseMinutes(['created_at' => '2026-06-10 14:00:00', 'first_response_at' => '2026-06-10 13:00:00']),
        'and a reply stamped before the question is nonsense, not a negative wait'
    );
});

// ---------------------------------------------------------------- composing

Harness::test('a reply goes out on the conversation\'s channel when it can', function (): void {
    Assert::same('email', I::replyChannel('email'));
    Assert::same('sms', I::replyChannel('sms'));
    Assert::same('internal', I::replyChannel('internal'));
});

Harness::test('and falls back to email for a channel with no outbound route', function (): void {
    // Composing into webchat or social would produce a reply the guest never
    // receives, which is worse than sending them an email.
    foreach (['webchat', 'phone', 'social', '', 'carrier-pigeon'] as $channel) {
        Assert::same('email', I::replyChannel($channel), $channel . ' has no outbound route yet');
    }
});

Harness::test('merge tags are an ALLOWLIST, not the contact row', function (): void {
    // A canned reply is typed by staff and rendered into a message to a
    // guest, so what it can reach is a decision. A row passed through whole
    // would let `{{notes}}` or `{{lifetime_value_cents}}` into a reply
    // somebody pasted in a hurry.
    $tags = I::mergeTags([
        'first_name'           => 'Alex',
        'last_name'            => 'Fitzgerald',
        'email'                => 'alex@example.test',
        'phone'                => '845-469-2116',
        'notes'                => 'Difficult parent, watch the deposit',
        'lifetime_value_cents' => 450000,
        'do_not_contact'       => 1,
    ], ['name' => 'The Castle Fun Center', 'phone' => '(845) 469-2116']);

    Assert::same([
        'first_name', 'last_name', 'full_name', 'email', 'phone', 'venue_name', 'venue_phone',
    ], array_keys($tags), 'exactly these tags, and no others');

    Assert::same('Alex', $tags['first_name']);
    Assert::same('Alex Fitzgerald', $tags['full_name']);
    Assert::same('The Castle Fun Center', $tags['venue_name']);

    foreach ($tags as $value) {
        Assert::notContains('Difficult parent', $value, 'the staff note does not reach a guest');
        Assert::notContains('450000', $value, 'and neither does their spend');
    }
});

Harness::test('an unnamed guest is greeted, not addressed as nobody', function (): void {
    // "Hi ," is worse than no name at all, and it is what an empty column
    // produces in the one place a guest reads it.
    $tags = I::mergeTags(['first_name' => '', 'last_name' => ''], []);

    Assert::same('there', $tags['first_name'], 'so "Hi there" rather than "Hi ,"');
    Assert::same('', $tags['full_name'], 'while a full name with nothing in it stays empty');

    Assert::same('there', I::mergeTags([], [])['first_name'], 'and a missing column reads the same');
    Assert::same('there', I::mergeTags(['first_name' => '   '], [])['first_name'], 'whitespace too');
});

// ----------------------------------------------------------------- previews

Harness::test('a queue preview is PLAIN TEXT, stripped before it is cut', function (): void {
    // Cutting an email at 120 characters of HTML can land inside a tag, and
    // the fragment that survives is rendered as markup by the page that shows
    // it — so the tags come out first.
    Assert::same('Hello there', I::preview('<p>Hello <strong>there</strong></p>'));
    Assert::same(
        'Hi',
        I::preview('<div class="a-very-long-class-name-indeed"><span>Hi</span></div>', 20),
        'a short message inside long markup is still short'
    );
    Assert::notContains('<', I::preview(str_repeat('<b>x</b> ', 40)), 'no markup survives at any length');
});

Harness::test('and a script or style block contributes nothing', function (): void {
    // strip_tags alone leaves the CONTENTS of a style block, so an email
    // built by a template designer previews as a wall of CSS.
    Assert::same(
        'Real text',
        I::preview('<style>.x{color:red}</style><p>Real text</p>')
    );
    Assert::same(
        'Real text',
        I::preview('<script>var a = 1;</script><p>Real text</p>')
    );
});

Harness::test('a long preview is truncated with an ellipsis', function (): void {
    $preview = I::preview(str_repeat('word ', 100), 40);

    Assert::same(40, mb_strlen($preview), 'exactly the length asked for');
    Assert::true(str_ends_with($preview, '…'), 'and says it was cut');

    Assert::same('', I::preview(''), 'while nothing previews as nothing');
    Assert::same('', I::preview('   <br>  '), 'and so does markup with no words in it');
});

Harness::test('whitespace in a preview is collapsed', function (): void {
    // An email body is full of newlines and indentation, and a queue row is
    // one line high.
    Assert::same('Hello there friend', I::preview("Hello\n\n  there\t\tfriend  "));
    Assert::notContains("\n", I::preview("a\nb\nc"));
});

// --------------------------------------------------------------- the tabs

Harness::test('the queue tabs lead with the question a rep opens the inbox to answer', function (): void {
    $filters = array_keys(I::FILTERS);

    Assert::same('mine', $filters[0], '"what is waiting for me" comes first');
    Assert::same('all', $filters[count($filters) - 1], 'and everything else comes last');
    Assert::inArray('unassigned', $filters, 'with nobody\'s-yet in between');
    Assert::inArray('snoozed', $filters);
    Assert::inArray('closed', $filters);
});
