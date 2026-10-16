<?php

declare(strict_types=1);

/**
 * The half of the automation engine that nothing fires.
 *
 * Most of this venue's automations hang off a trigger that becomes true as
 * time passes — "the party is in fourteen days", "the first of the month at
 * ten" — and the two pieces of that which can be tested without a database
 * are the cron matcher and the trigger catalogue.
 *
 * THE CRON MATCHER IS WHERE A MONTHLY OFFER GOES WRONG. It is asked once a
 * minute and must answer true in exactly one of those minutes, so every
 * boundary below is a real one: a `0 10 1 * *` that also matched 10:01 would
 * send the monthly offer twice, and one that matched no minute at all would
 * send it never, with nothing in the log either way.
 *
 * The day-of-month / day-of-week rule is the one everybody gets wrong: when
 * BOTH are restricted, cron ORs them. `0 12 13 * 5` means the 13th and every
 * Friday, not Friday the 13th. A venue writing "the 13th or any Friday" and
 * getting four firings a year instead of sixty would have no way to tell from
 * the UI.
 *
 * No database, no network, no clock — every moment is passed in.
 */

use App\Core\Event;
use App\Services\AutomationTriggers as T;
use Tests\Assert;
use Tests\Harness;

Harness::suite('unit/automation-triggers');

function at_moment(string $local): DateTimeImmutable
{
    // A fixed zone, because the matcher is handed a moment already converted
    // into the venue's own — see the zone() call in cron().
    return new DateTimeImmutable($local, new DateTimeZone('America/New_York'));
}

// ------------------------------------------------------------------ shape

Harness::test('an expression that is not five fields matches nothing', function (): void {
    // Refused rather than guessed at. A four-field expression is somebody
    // writing cron from memory, and treating it as five with a missing field
    // would fire at a time they did not choose.
    foreach (['', '*', '* * * *', '* * * * * *', '0 10 1 *'] as $bad) {
        Assert::false(T::cronMatches($bad, at_moment('2026-05-01 10:00')), 'should refuse: "' . $bad . '"');
    }
});

Harness::test('every-minute matches every minute', function (): void {
    Assert::true(T::cronMatches('* * * * *', at_moment('2026-05-01 10:00')));
    Assert::true(T::cronMatches('* * * * *', at_moment('2026-12-31 23:59')));

    // Whitespace is how an expression arrives from a text field.
    Assert::true(T::cronMatches('  *  *  *  *  *  ', at_moment('2026-05-01 10:00')));
});

// ----------------------------------------------------------------- minutes

Harness::test('an exact minute matches that minute and no other', function (): void {
    Assert::true(T::cronMatches('0 10 * * *', at_moment('2026-05-01 10:00')));
    Assert::false(T::cronMatches('0 10 * * *', at_moment('2026-05-01 10:01')), 'the minute after');
    Assert::false(T::cronMatches('0 10 * * *', at_moment('2026-05-01 09:59')), 'the minute before');
    Assert::false(T::cronMatches('0 10 * * *', at_moment('2026-05-01 11:00')), 'the hour after');

    // 09:00 is not 9 minutes past: the fields are minute first.
    Assert::false(T::cronMatches('9 10 * * *', at_moment('2026-05-01 09:10')));
    Assert::true(T::cronMatches('9 10 * * *', at_moment('2026-05-01 10:09')));
});

Harness::test('a step matches every nth minute from the start of the hour', function (): void {
    foreach (['00', '15', '30', '45'] as $minute) {
        Assert::true(T::cronMatches('*/15 * * * *', at_moment('2026-05-01 10:' . $minute)), ':' . $minute);
    }

    foreach (['01', '07', '14', '16', '59'] as $minute) {
        Assert::false(T::cronMatches('*/15 * * * *', at_moment('2026-05-01 10:' . $minute)), ':' . $minute);
    }
});

Harness::test('a list matches each of its values', function (): void {
    Assert::true(T::cronMatches('0,30 10 * * *', at_moment('2026-05-01 10:00')));
    Assert::true(T::cronMatches('0,30 10 * * *', at_moment('2026-05-01 10:30')));
    Assert::false(T::cronMatches('0,30 10 * * *', at_moment('2026-05-01 10:15')));

    // Spaces inside a list would split the expression into more than five
    // fields, so this is the shape that has to work.
    Assert::true(T::cronMatches('0,15,30,45 * * * *', at_moment('2026-05-01 10:45')));
});

// ------------------------------------------------------------------- hours

Harness::test('a range matches its ends and everything between', function (): void {
    Assert::true(T::cronMatches('0 9-11 * * *', at_moment('2026-05-01 09:00')), 'the lower end');
    Assert::true(T::cronMatches('0 9-11 * * *', at_moment('2026-05-01 10:00')));
    Assert::true(T::cronMatches('0 9-11 * * *', at_moment('2026-05-01 11:00')), 'the upper end');
    Assert::false(T::cronMatches('0 9-11 * * *', at_moment('2026-05-01 08:00')));
    Assert::false(T::cronMatches('0 9-11 * * *', at_moment('2026-05-01 12:00')), 'one past the end');
});

Harness::test('a step over a range starts at the range, not at zero', function (): void {
    // Every four hours from nine: 9, 13, 17. NOT 8 or 12, which is what a
    // matcher that stepped from midnight would give.
    Assert::true(T::cronMatches('0 9-17/4 * * *', at_moment('2026-05-01 09:00')));
    Assert::true(T::cronMatches('0 9-17/4 * * *', at_moment('2026-05-01 13:00')));
    Assert::true(T::cronMatches('0 9-17/4 * * *', at_moment('2026-05-01 17:00')));
    Assert::false(T::cronMatches('0 9-17/4 * * *', at_moment('2026-05-01 12:00')));
    Assert::false(T::cronMatches('0 9-17/4 * * *', at_moment('2026-05-01 21:00')), 'past the range');
});

// -------------------------------------------------------- days and months

Harness::test('the first of the month at ten means that one minute', function (): void {
    Assert::true(T::cronMatches('0 10 1 * *', at_moment('2026-05-01 10:00')));
    Assert::false(T::cronMatches('0 10 1 * *', at_moment('2026-05-02 10:00')), 'the second');
    Assert::false(T::cronMatches('0 10 1 * *', at_moment('2026-05-01 10:01')), 'a minute late');
});

Harness::test('a month restricts to that month', function (): void {
    // The seasonal case: a summer-camp offer that must not go out in January.
    Assert::true(T::cronMatches('0 10 1 5 *', at_moment('2026-05-01 10:00')));
    Assert::false(T::cronMatches('0 10 1 5 *', at_moment('2026-06-01 10:00')));
    Assert::true(T::cronMatches('0 10 1 5-8 *', at_moment('2026-08-01 10:00')), 'a range of months');
});

Harness::test('a weekday matches that weekday', function (): void {
    // 2026-05-04 is a Monday, 2026-05-08 a Friday.
    Assert::true(T::cronMatches('0 9 * * 1', at_moment('2026-05-04 09:00')), 'Monday');
    Assert::false(T::cronMatches('0 9 * * 1', at_moment('2026-05-08 09:00')), 'not Friday');
    Assert::true(T::cronMatches('0 9 * * 1-5', at_moment('2026-05-08 09:00')), 'weekdays include Friday');
    Assert::false(T::cronMatches('0 9 * * 1-5', at_moment('2026-05-03 09:00')), 'and not Sunday');
});

Harness::test('Sunday is both 0 and 7', function (): void {
    // Real crontabs use either, and a venue copying a line off the internet
    // must not get a Sunday automation that never fires.
    Assert::true(T::cronMatches('0 9 * * 0', at_moment('2026-05-03 09:00')));
    Assert::true(T::cronMatches('0 9 * * 7', at_moment('2026-05-03 09:00')));
    Assert::false(T::cronMatches('0 9 * * 0', at_moment('2026-05-04 09:00')), 'Monday is neither');
});

Harness::test('THE DAY-OF-MONTH AND WEEKDAY FIELDS ARE OR-ED, NOT AND-ED', function (): void {
    /*
     * The rule real cron follows, and the one worth a test of its own: when
     * both fields are restricted, EITHER matching is enough.
     *
     * 2026-05-13 is a Wednesday and 2026-05-08 a Friday, so `13 * 5` fires on
     * both. An AND would have fired on neither, and a venue would be waiting
     * for Friday the 13th — which happens once or twice a year.
     */
    Assert::true(T::cronMatches('0 12 13 * 5', at_moment('2026-05-13 12:00')), 'the 13th, a Wednesday');
    Assert::true(T::cronMatches('0 12 13 * 5', at_moment('2026-05-08 12:00')), 'a Friday, not the 13th');
    Assert::false(T::cronMatches('0 12 13 * 5', at_moment('2026-05-06 12:00')), 'neither');

    // And when only ONE is restricted the other must not veto it: this is
    // where a naive OR sends every single day.
    Assert::true(T::cronMatches('0 12 13 * *', at_moment('2026-05-13 12:00')));
    Assert::false(T::cronMatches('0 12 13 * *', at_moment('2026-05-14 12:00')), 'a bare * must not match');
    Assert::true(T::cronMatches('0 12 * * 3', at_moment('2026-05-13 12:00')));
    Assert::false(T::cronMatches('0 12 * * 3', at_moment('2026-05-14 12:00')));
});

// --------------------------------------------------------------- catalogue

Harness::test('the trigger catalogue is the union of both halves of the engine', function (): void {
    $catalogue = T::catalogue();

    Assert::greaterThan(60, count($catalogue), 'both halves should be in it');

    foreach (array_keys(Event::CATALOG) as $event) {
        Assert::hasKey($event, $catalogue, 'event missing from the catalogue: ' . $event);
    }

    foreach (array_keys(T::SCANNED) as $scanned) {
        Assert::hasKey($scanned, $catalogue, 'scanner missing from the catalogue: ' . $scanned);
        Assert::same('scanned', $catalogue[$scanned]['kind'], $scanned . ' is answered by a scanner');
    }

    foreach ($catalogue as $trigger => $entry) {
        Assert::notEmpty($entry['label'], 'a label for ' . $trigger);
        Assert::inArray($entry['kind'], ['event', 'scanned']);
    }
});

Harness::test('a scanner wins over an event of the same name', function (): void {
    // Both halves name `contact.lapsed`-shaped triggers, and only one of them
    // runs. Reporting it as an event would send somebody looking for a
    // `fire()` call that is not there.
    $catalogue = T::catalogue();

    Assert::same('scanned', $catalogue['contact.lapsed']['kind']);
    Assert::same('scanned', $catalogue['booking.upcoming']['kind']);
    Assert::same('scanned', $catalogue['task.overdue']['kind']);
});

Harness::test('a trigger waiting on an unbuilt feature says so, and is not deliverable', function (): void {
    $catalogue = T::catalogue();

    // The honest placeholders. `ticket.purchased` is a real moment a venue
    // will want; online ticketing is what will fire it.
    Assert::notNull($catalogue['ticket.purchased']['pending']);
    Assert::false(T::isDeliverable('ticket.purchased'));

    /*
     * And the ones that work today — including the four the reputation
     * module took off the pending list when it shipped. They are named here
     * deliberately: a trigger moving from "waiting on a feature" to "works"
     * is exactly the kind of thing that gets built and then never wired up,
     * and this is the assertion that would catch a module shipping without
     * firing what it promised.
     */
    foreach ([
        'contact.created', 'booking.deposit_paid', 'visit.recorded', 'cron', 'manual',
        'survey.responded', 'survey.detractor', 'review.received', 'feedback.resolved',
    ] as $live) {
        Assert::null($catalogue[$live]['pending'], $live . ' works in this version');
        Assert::true(T::isDeliverable($live), $live);
    }

    // A name nobody declared is not deliverable either.
    Assert::false(T::isDeliverable('contact.teleported'));
    Assert::false(T::isDeliverable(''));
});

Harness::test('every scanner named in SCANNED is one scan() looks for', function (): void {
    /*
     * The list and the dispatch have to agree. A trigger in SCANNED with no
     * arm in `runScanner()` is the silent failure this whole area is about:
     * `scan()` would load the automation, find no scanner, and answer zero —
     * for ever, with the automation reading as active.
     *
     * Asserted against the SOURCE, because the dispatch is a private match
     * and the alternative is a database, a clock and fourteen fixtures.
     */
    $source = (string) file_get_contents(CC_ROOT . '/app/Services/AutomationTriggers.php');

    $start = strpos($source, 'private static function runScanner');
    Assert::true($start !== false, 'the dispatch should be findable');

    $dispatch = substr($source, (int) $start, 2200);

    // These wait for something other than a query: a button, or a table a
    // later phase builds. They are in SCANNED so the gate knows they are
    // deliberate, and answered with nothing rather than an error.
    $byHand = ['manual', 'certificate.expiring', 'company.recurring_due'];

    $missing = [];

    foreach (array_keys(T::SCANNED) as $trigger) {
        if (in_array($trigger, $byHand, true)) {
            continue;
        }

        if (!str_contains($dispatch, "'" . $trigger . "'")) {
            $missing[] = $trigger;
        }
    }

    Assert::same([], $missing, "in SCANNED but nothing scans for them:\n   " . implode("\n   ", $missing));
});
