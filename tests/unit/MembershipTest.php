<?php

declare(strict_types=1);

/**
 * The membership lifecycle, with no database and no clock.
 *
 * THE STATE MACHINE IS THE TEST, and the dates are where it goes wrong.
 * Two things ride on this being right, and both are the kind of wrong a
 * venue finds out about from a guest:
 *
 *   - a pass that stops working a day early, because "expired" was decided
 *     in UTC while the venue is five hours behind — from eight in the
 *     evening the UTC date has already rolled over, so the last day of
 *     somebody's season pass would be refused at the counter that evening;
 *   - a renewal ladder that fires its first rung and then goes quiet,
 *     because the rung's own stamp was not checked.
 *
 * So every boundary is pinned on both sides: the day before, the day itself
 * and the day after.
 */

use App\Services\Membership as M;
use Tests\Assert;
use Tests\Harness;

Harness::suite('unit/membership');

/** A live season pass, as the table holds one. */
function mb_pass(array $overrides = []): array
{
    return $overrides + [
        'status'               => 'active',
        'started_at'           => '2026-01-01',
        'expires_at'           => '2026-12-31',
        'visits_used'          => 0,
        'price_paid_cents'     => 9900,
        'renewal_notice_30_at' => null,
        'renewal_notice_14_at' => null,
        'renewal_notice_3_at'  => null,
        'winback_sent_at'      => null,
    ];
}

// ------------------------------------------------------------- the machine

Harness::test('a pass that has not started yet starts on its day, not before', function (): void {
    $pending = mb_pass(['status' => 'pending', 'started_at' => '2026-06-01']);

    Assert::null(M::nextStatus($pending, '2026-05-31'), 'not the day before');
    Assert::same('active', M::nextStatus($pending, '2026-06-01'), 'on the day');
    Assert::same('active', M::nextStatus($pending, '2026-06-02'), 'or later, if cron was down');
});

Harness::test('a pending pass with no start date waits rather than guessing', function (): void {
    Assert::null(M::nextStatus(mb_pass(['status' => 'pending', 'started_at' => null]), '2026-06-01'));
});

Harness::test('THE EXPIRY BOUNDARY, ON BOTH SIDES', function (): void {
    $pass = mb_pass(['status' => 'expiring', 'expires_at' => '2026-12-31']);

    // The last day is a day the pass WORKS. Getting this wrong by one is
    // the difference between a guest using what they paid for and being
    // turned away at the door.
    Assert::null(M::nextStatus($pass, '2026-12-31'), 'the last day is still live');
    Assert::same('expired', M::nextStatus($pass, '2027-01-01'), 'the next day is not');
});

Harness::test('a pass becomes "expiring" exactly thirty days out', function (): void {
    $pass = mb_pass(['expires_at' => '2026-12-31']);

    Assert::null(M::nextStatus($pass, '2026-11-30'), '31 days out is still just active');
    Assert::same('expiring', M::nextStatus($pass, '2026-12-01'), '30 days out it is expiring');
    Assert::same('expiring', M::nextStatus($pass, '2026-12-30'));
});

Harness::test('an expired pass lapses sixty days later, and not before', function (): void {
    $pass = mb_pass(['status' => 'expired', 'expires_at' => '2026-12-31']);

    Assert::null(M::nextStatus($pass, '2027-02-28'), '59 days after is still expired');
    Assert::null(M::nextStatus($pass, '2027-03-01'), 'and so is the sixtieth');
    Assert::same('lapsed', M::nextStatus($pass, '2027-03-02'), 'past the window it lapses');
});

Harness::test('NOTHING MOVES A CANCELLED OR LAPSED MEMBERSHIP', function (): void {
    // A guest who comes back buys a new one. Reviving the old row would lose
    // the fact that they left, which is the only thing the churn report has.
    foreach (['cancelled', 'lapsed'] as $terminal) {
        $pass = mb_pass(['status' => $terminal, 'expires_at' => '2020-01-01']);

        Assert::null(M::nextStatus($pass, '2030-01-01'), $terminal . ' stays ' . $terminal);
    }
});

Harness::test('a frozen pass does not run down', function (): void {
    // The whole point of freezing. A pass frozen in March and unfrozen in
    // June must not have spent those months expiring.
    $pass = mb_pass(['status' => 'frozen', 'expires_at' => '2026-03-01']);

    Assert::null(M::nextStatus($pass, '2026-09-01'));
});

Harness::test('a punch card ends when its visits are used, whatever the date', function (): void {
    $card = mb_pass(['expires_at' => null, 'visits_used' => 10]);

    Assert::same('expired', M::nextStatus($card, '2026-06-01', ['visit_allowance' => 10]));
    Assert::null(M::nextStatus($card, '2026-06-01', ['visit_allowance' => 0]), 'unlimited is unlimited');

    $partUsed = mb_pass(['expires_at' => null, 'visits_used' => 9]);

    Assert::null(M::nextStatus($partUsed, '2026-06-01', ['visit_allowance' => 10]), 'one left');
});

Harness::test('an open-ended membership stays active until somebody cancels it', function (): void {
    $rolling = mb_pass(['expires_at' => null]);

    Assert::null(M::nextStatus($rolling, '2030-01-01'));
});

Harness::test('only a person freezes or cancels', function (): void {
    Assert::true(M::canTransition('active', 'frozen'));
    Assert::true(M::canTransition('expiring', 'cancelled'));
    Assert::true(M::canTransition('frozen', 'active'));

    // Not by hand: the calendar's business.
    Assert::false(M::canTransition('active', 'expired'));
    Assert::false(M::canTransition('expired', 'active'), 'that is a renewal, not a status change');
    Assert::false(M::canTransition('cancelled', 'active'));
    Assert::false(M::canTransition('lapsed', 'active'));
});

// --------------------------------------------------------------- usability

Harness::test('A PASS WORKS ON ITS LAST DAY', function (): void {
    $pass = mb_pass(['status' => 'expiring', 'expires_at' => '2026-12-31']);

    Assert::true(M::isUsable($pass, '2026-12-31'));
    Assert::false(M::isUsable($pass, '2027-01-01'));
});

Harness::test('a frozen or used-up pass does not work', function (): void {
    Assert::false(M::isUsable(mb_pass(['status' => 'frozen']), '2026-06-01'));
    Assert::false(
        M::isUsable(mb_pass(['visits_used' => 10]), '2026-06-01', ['visit_allowance' => 10]),
        'no visits left'
    );
    Assert::true(
        M::isUsable(mb_pass(['visits_used' => 9]), '2026-06-01', ['visit_allowance' => 10])
    );
});

// ------------------------------------------------------------------- dates

Harness::test('A TERM INCLUDES ITS FIRST DAY', function (): void {
    // A 365-day pass bought on 1 January runs to 31 December, not to
    // 1 January next year. This is the date printed on the card.
    Assert::same('2026-12-31', M::expiresOn(['duration_days' => 365], '2026-01-01'));
    Assert::same('2026-01-01', M::expiresOn(['duration_days' => 1], '2026-01-01'));
    Assert::same('2026-01-31', M::expiresOn(['duration_days' => 31], '2026-01-01'));
});

Harness::test('a plan with no length has no end date, rather than a guessed one', function (): void {
    // Inventing one would start the renewal ladder against a date nobody
    // agreed to.
    Assert::null(M::expiresOn(['duration_days' => 0], '2026-01-01'));
    Assert::null(M::expiresOn([], '2026-01-01'));
});

Harness::test('day arithmetic survives a daylight-saving boundary', function (): void {
    // In America/New_York the clocks go forward on 8 March 2026 and back on
    // 1 November. Both of those "days" are 23 or 25 hours long locally, and
    // an hours-based subtraction loses or gains a day across them.
    Assert::same(1, M::daysBetween('2026-03-07', '2026-03-08'));
    Assert::same(1, M::daysBetween('2026-03-08', '2026-03-09'));
    Assert::same(1, M::daysBetween('2026-11-01', '2026-11-02'));
    Assert::same(365, M::daysBetween('2026-01-01', '2026-12-31') + 1);
    Assert::same(-1, M::daysBetween('2026-03-08', '2026-03-07'), 'and backwards');
});

Harness::test('adding days crosses a month, a year and a leap day', function (): void {
    Assert::same('2026-03-01', M::addDays('2026-02-28', 1), '2026 is not a leap year');
    Assert::same('2024-02-29', M::addDays('2024-02-28', 1), '2024 is');
    Assert::same('2027-01-01', M::addDays('2026-12-31', 1));
    Assert::same('2026-12-31', M::addDays('2027-01-01', -1));
});

// ----------------------------------------------------------- the renewal ladder

Harness::test('THE RENEWAL LADDER FIRES THREE TIMES AND STAMPS EACH RUNG', function (): void {
    $pass = mb_pass(['status' => 'expiring', 'expires_at' => '2026-12-31']);

    // 31 days out: nothing yet.
    Assert::null(M::dueRenewalNotice($pass, '2026-11-30'));

    // 30 days out: the first rung.
    Assert::same(30, M::dueRenewalNotice($pass, '2026-12-01'));

    // Stamped, so the same day does not fire it again.
    $pass['renewal_notice_30_at'] = '2026-12-01 09:00:00';
    Assert::null(M::dueRenewalNotice($pass, '2026-12-01'));
    Assert::null(M::dueRenewalNotice($pass, '2026-12-10'), 'and nor does the day after');

    // 14 days out: the second.
    Assert::same(14, M::dueRenewalNotice($pass, '2026-12-17'));
    $pass['renewal_notice_14_at'] = '2026-12-17 09:00:00';

    // 3 days out: the last.
    Assert::same(3, M::dueRenewalNotice($pass, '2026-12-28'));
    $pass['renewal_notice_3_at'] = '2026-12-28 09:00:00';

    Assert::null(M::dueRenewalNotice($pass, '2026-12-30'), 'three and then it stops');
});

Harness::test('a cron that was down sends the rung that is relevant, not the backlog', function (): void {
    // Down for a fortnight, and the guest now has four days left. Sending
    // the 30-day notice would read as nonsense; the smallest rung that
    // applies is the one that is true today.
    $pass = mb_pass(['status' => 'expiring', 'expires_at' => '2026-12-31']);

    Assert::same(3, M::dueRenewalNotice($pass, '2026-12-28'));
});

Harness::test('nothing renews a pass that has no end date or has already gone', function (): void {
    Assert::null(M::dueRenewalNotice(mb_pass(['expires_at' => null]), '2026-12-01'));
    Assert::null(M::dueRenewalNotice(mb_pass(['status' => 'cancelled']), '2026-12-01'));
    Assert::null(
        M::dueRenewalNotice(mb_pass(['status' => 'expired', 'expires_at' => '2026-01-01']), '2026-12-01')
    );
    Assert::null(
        M::dueRenewalNotice(mb_pass(['expires_at' => '2026-11-30']), '2026-12-01'),
        'already past'
    );
});

// --------------------------------------------------------- the win-back ladder

Harness::test('THE WIN-BACK LADDER IS THREE TOUCHES AND THEN SILENCE', function (): void {
    $pass = mb_pass(['status' => 'expired', 'expires_at' => '2026-06-30']);

    Assert::null(M::winbackRung($pass, '2026-07-10'), 'ten days out, too soon');
    Assert::same(14, M::winbackRung($pass, '2026-07-14'));
    Assert::same(45, M::winbackRung($pass, '2026-08-20'));
    Assert::same(90, M::winbackRung($pass, '2026-10-01'));
    Assert::same(90, M::winbackRung($pass, '2027-06-01'), 'and it never goes past the last rung');
});

Harness::test('each win-back touch goes once', function (): void {
    $pass = mb_pass(['status' => 'expired', 'expires_at' => '2026-06-30']);

    Assert::true(M::winbackIsDue($pass, '2026-07-14'), 'the first');

    $pass['winback_sent_at'] = '2026-07-14 09:00:00';
    Assert::false(M::winbackIsDue($pass, '2026-07-20'), 'not twice');

    Assert::true(M::winbackIsDue($pass, '2026-08-20'), 'the second');

    $pass['winback_sent_at'] = '2026-08-20 09:00:00';
    Assert::false(M::winbackIsDue($pass, '2026-09-01'));

    Assert::true(M::winbackIsDue($pass, '2026-10-01'), 'the third');

    $pass['winback_sent_at'] = '2026-10-01 09:00:00';

    // And THEN IT STOPS. A venue still emailing somebody a year after they
    // left is the venue people mark as spam.
    Assert::false(M::winbackIsDue($pass, '2027-06-01'));
});

Harness::test('nobody is won back who has not left', function (): void {
    Assert::false(M::winbackIsDue(mb_pass(), '2026-06-01'), 'an active pass');
    Assert::false(M::winbackIsDue(mb_pass(['status' => 'cancelled']), '2026-06-01'), 'they cancelled');
});

// ---------------------------------------------------------- what it was worth

Harness::test('cost per visit is two recorded figures divided, or nothing', function (): void {
    Assert::same(990, M::costPerVisit(9900, 10));
    Assert::same(1650, M::costPerVisit(9900, 6));

    // Not "the whole price because they have been once this week" — that is
    // a true number that tells nobody anything.
    Assert::null(M::costPerVisit(9900, 0));
    Assert::null(M::costPerVisit(0, 10), 'a comped pass has no cost per visit');
});

Harness::test('usage reports facts, never a forecast', function (): void {
    $pass = mb_pass(['started_at' => '2026-01-01', 'visits_used' => 12, 'price_paid_cents' => 9900]);

    $usage = M::usage($pass, ['visit_allowance' => null], '2026-07-01');

    Assert::same(12, $usage['visits']);
    Assert::same(181, $usage['days_held']);
    Assert::null($usage['allowance']);
    Assert::null($usage['visits_left']);
    Assert::same(825, $usage['cost_per_visit']);
    Assert::same(2.0, $usage['visits_per_month']);
});

Harness::test('a rate is not reported in the first few weeks', function (): void {
    // One visit in week one is not "four a month".
    $usage = M::usage(
        mb_pass(['started_at' => '2026-01-01', 'visits_used' => 1]),
        [],
        '2026-01-08'
    );

    Assert::null($usage['visits_per_month']);
    Assert::same(7, $usage['days_held']);
});

Harness::test('a punch card reports what is left', function (): void {
    $usage = M::usage(mb_pass(['visits_used' => 7]), ['visit_allowance' => 10], '2026-06-01');

    Assert::same(10, $usage['allowance']);
    Assert::same(3, $usage['visits_left']);
});

Harness::test('a full family plan says nought places, not "no limit"', function (): void {
    // Zero and null are different answers, and reading one as the other
    // either blocks a family or lets a plan hold nine people.
    Assert::same(0, M::placesLeft(['max_members' => 5], 5));
    Assert::same(2, M::placesLeft(['max_members' => 5], 3));
    Assert::same(0, M::placesLeft(['max_members' => 5], 9), 'never negative');
});

Harness::test('A BLANK LIMIT MEANS ONE PERSON, NOT UNLIMITED', function (): void {
    /*
     * The plans page and the plan form both say "blank means one — the
     * holder", and this is what makes that true. Reading a blank as
     * unlimited would let a counter add a whole family to a pass sold for
     * one person, which is a revenue leak nobody would see.
     */
    Assert::same(1, M::placesLeft([], 0), 'room for the holder');
    Assert::same(0, M::placesLeft([], 1), 'and nobody else');
    Assert::same(0, M::placesLeft(['max_members' => 0], 3));

    // Unless it is a family plan with no stated limit, which is the venue
    // saying "as many as they like".
    Assert::null(M::placesLeft(['is_family_plan' => 1], 4));
});

// ------------------------------------------------------------------- words

Harness::test('a member number is readable down a telephone', function (): void {
    Assert::same('CFC-000412', M::memberNumber(412, 'CFC'));
    Assert::same('M-000001', M::memberNumber(1, ''));
    Assert::same('ABC-000007', M::memberNumber(7, 'a-b/c'), 'punctuation is dropped');
    Assert::same('CFC-000001', M::memberNumber(0, 'CFC'), 'never zero');
});

Harness::test('the standing of a pass is said in dates, not in jargon', function (): void {
    Assert::contains('30 days left', M::describe(mb_pass(['expires_at' => '2026-12-31']), '2026-12-01'));
    Assert::contains('today', M::describe(mb_pass(['expires_at' => '2026-12-31']), '2026-12-31'));
    Assert::contains(
        'Starts on 2026-06-01',
        M::describe(mb_pass(['status' => 'pending', 'started_at' => '2026-06-01']), '2026-05-01')
    );
    Assert::contains(
        'Frozen',
        M::describe(mb_pass(['status' => 'frozen']), '2026-06-01')
    );
    Assert::contains(
        'All 10 visits have been used',
        M::describe(mb_pass(['visits_used' => 10]), '2026-06-01', ['visit_allowance' => 10])
    );
    Assert::contains(
        'runs until somebody cancels it',
        M::describe(mb_pass(['expires_at' => null]), '2026-06-01')
    );
});

Harness::test('every status has a label and a badge', function (): void {
    foreach (M::STATUSES as $status) {
        Assert::notEmpty(M::label($status), $status . ' needs words');
        Assert::notEmpty(M::badge($status), $status . ' needs a badge');
    }
});
