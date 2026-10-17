<?php

declare(strict_types=1);

/**
 * The automation engine's decisions, with no database, no clock and no network.
 *
 * An automation sends real messages to real families, days or weeks after
 * anybody was looking. There is no user to notice it going wrong and no
 * request to return an error to, so everything that decides WHETHER and WHEN
 * is pinned here — because the alternative way to find out is a parent
 * receiving "your party is in two weeks!" the day before it.
 *
 * Four groups of test, in order of what they cost when wrong:
 *
 *   1. **WHEN A DELAYED STEP RUNS.** "Fourteen days before the party at 10am"
 *      is how the waiver chase works. Computing it by adding seconds is an
 *      hour out across a daylight-saving change — twice a year, for every run
 *      in flight — and reading a locally-stored date as UTC moves a
 *      day-before reminder onto the wrong day. Both are measured here, on
 *      the real changeover dates.
 *   2. **WHETHER IT RUNS AT ALL.** A delay can resolve into the past, and
 *      the obvious answer (clamp to now) is the one that sends the wrong
 *      thing. Past-due steps are skipped beyond a grace window, and the
 *      grace window is what separates catching up after an outage from
 *      sending something no longer true.
 *   3. **WHETHER THE RUN SHOULD STOP.** Exit conditions are checked before
 *      EVERY step, so a guest who books on day three stops getting "still
 *      thinking about it?" on day five.
 *   4. **WHICH BRANCH.** Including a split test that must be deterministic:
 *      a retry re-running the step cannot put the same guest in the other
 *      arm, or they get both versions.
 */

use App\Services\AutomationEngine as E;
use Tests\Assert;
use Tests\Harness;

Harness::suite('unit/automation-engine');

const AE_TZ = 'America/New_York';

/** The UTC instant a step was scheduled for, read back as a local wall clock. */
function ae_local(?string $utc, string $format = 'Y-m-d H:i T'): string
{
    if ($utc === null) {
        return '(skipped)';
    }

    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone(AE_TZ))
        ->format($format);
}

/** A step with a time-of-day delay. */
function ae_at(string $time, int $days = 0): array
{
    return [
        'delay_until_type'   => 'time_of_day',
        'delay_until_config' => ['at' => $time, 'days' => $days],
    ];
}

/** A step counted from a date on the record. */
function ae_relative(string $type, string $field, int $days, ?string $at = null): array
{
    $config = ['field' => $field, 'days' => $days];

    if ($at !== null) {
        $config['at'] = $at;
    }

    return ['delay_until_type' => $type, 'delay_until_config' => $config];
}

// ============================================================ plain delays

Harness::test('a delay in minutes is just that', function (): void {
    $at = E::scheduleStep(['delay_minutes' => 90], [], '2026-07-01 12:00:00', AE_TZ);

    Assert::same('2026-07-01 13:30:00', $at['at']);
    Assert::false($at['skip']);
});

Harness::test('a step with no delay runs immediately', function (): void {
    $at = E::scheduleStep([], [], '2026-07-01 12:00:00', AE_TZ);

    Assert::same('2026-07-01 12:00:00', $at['at']);
    Assert::false($at['skip']);
});

Harness::test('a negative delay is immediate, not a step into the past', function (): void {
    // A hand-edited "-30" means somebody wanted it sooner, not earlier than
    // the thing that triggered it.
    $at = E::scheduleStep(['delay_minutes' => -30], [], '2026-07-01 12:00:00', AE_TZ);

    Assert::same('2026-07-01 12:00:00', $at['at']);
    Assert::false($at['skip']);
});

// =========================================================== the DST cases

Harness::test(
    'A WALL-CLOCK TIME IS THE SAME CLOCK TIME ON BOTH SIDES OF A DST CHANGE',
    function (): void {
        /*
         * The whole reason these times are built in the zone. "Tomorrow at
         * 9am" has to be 9am to the guest reading it, which is a DIFFERENT
         * UTC instant in winter and summer — 14:00 UTC under EST, 13:00 under
         * EDT. Adding 86400 seconds to yesterday's 9am gives 8am or 10am, and
         * the venue's morning reminder arrives at the wrong hour for half the
         * year.
         *
         * Measured on the real 2026 changeover dates for America/New_York:
         * March 8 and November 1.
         */
        // America/New_York in 2026: forward on March 8, back on November 1.
        $cases = [
            // now (UTC)              expected local wall clock
            ['2026-03-07 12:00:00', '2026-03-08 09:00 EDT'], // ACROSS the spring change
            ['2026-03-08 12:00:00', '2026-03-09 09:00 EDT'], // the morning after
            ['2026-10-31 12:00:00', '2026-11-01 09:00 EST'], // ACROSS the autumn change
            ['2026-11-01 12:00:00', '2026-11-02 09:00 EST'], // the morning after
            ['2026-07-01 12:00:00', '2026-07-02 09:00 EDT'], // an ordinary summer day
        ];

        foreach ($cases as [$now, $expected]) {
            $step = E::scheduleStep(ae_at('09:00', 1), [], $now, AE_TZ);

            Assert::same(
                $expected,
                ae_local($step['at']),
                'from ' . $now . ' UTC, "tomorrow at 9am" should be ' . $expected
            );
        }

        /*
         * And the proof that this is doing real work: the SAME wall-clock
         * time is a DIFFERENT UTC instant on either side of the change. A
         * naive implementation produces the same UTC offset all year and is
         * therefore an hour wrong for half of it.
         */
        $summer = E::scheduleStep(ae_at('09:00'), [], '2026-07-01 06:00:00', AE_TZ);
        $winter = E::scheduleStep(ae_at('09:00'), [], '2026-12-01 06:00:00', AE_TZ);

        Assert::same('13:00:00', substr((string) $summer['at'], 11), '9am EDT is 13:00 UTC');
        Assert::same('14:00:00', substr((string) $winter['at'], 11), '9am EST is 14:00 UTC');
    }
);

Harness::test('a time of day already past today lands tomorrow', function (): void {
    // 2pm UTC on July 1 is 10am local, so "today at 9am" has gone.
    $step = E::scheduleStep(ae_at('09:00'), [], '2026-07-01 14:00:00', AE_TZ);

    Assert::same('2026-07-02 09:00 EDT', ae_local($step['at']));
});

Harness::test('a time of day still ahead today lands today', function (): void {
    // 11am UTC is 7am local; 9am is still to come.
    $step = E::scheduleStep(ae_at('09:00'), [], '2026-07-01 11:00:00', AE_TZ);

    Assert::same('2026-07-01 09:00 EDT', ae_local($step['at']));
});

Harness::test('a malformed time of day skips the step rather than guessing', function (): void {
    foreach (['', '9am', '25:00', '09:70', 'nine'] as $bad) {
        $step = E::scheduleStep(ae_at($bad), [], '2026-07-01 12:00:00', AE_TZ);

        Assert::true($step['skip'], '"' . $bad . '" is not a time');
        Assert::null($step['at']);
    }

    // And the boundary values ARE times.
    Assert::false(E::scheduleStep(ae_at('00:00'), [], '2026-07-01 12:00:00', AE_TZ)['skip']);
    Assert::false(E::scheduleStep(ae_at('23:59'), [], '2026-07-01 12:00:00', AE_TZ)['skip']);
});

// ================================================ counted from a date field

Harness::test(
    'FOURTEEN DAYS BEFORE THE PARTY IS FOURTEEN DAYS BEFORE THE PARTY',
    function (): void {
        // The waiver chase. The booking's date is stored in the venue's own
        // local time; reading it as UTC shifts every T-minus step by four or
        // five hours, which is enough to move a day-before reminder onto the
        // wrong day entirely.
        $step = E::scheduleStep(
            ae_relative('days_before_field', 'starts_at', 14, '10:00'),
            ['starts_at' => '2026-12-20 14:00:00'],
            '2026-10-16 12:00:00',
            AE_TZ
        );

        Assert::same('2026-12-06 10:00 EST', ae_local($step['at']));
        Assert::false($step['skip']);
    }
);

Harness::test('a T-minus step with no hour keeps the record\'s own time', function (): void {
    // A 2pm party gets its T-1 reminder at 2pm the day before, which is a
    // defensible default and visibly different from 10am.
    $step = E::scheduleStep(
        ae_relative('days_before_field', 'starts_at', 1),
        ['starts_at' => '2026-12-20 14:00:00'],
        '2026-12-01 12:00:00',
        AE_TZ
    );

    Assert::same('2026-12-19 14:00 EST', ae_local($step['at']));
});

Harness::test('a T-plus step counts forward', function (): void {
    $step = E::scheduleStep(
        ae_relative('days_after_field', 'completed_at', 1, '09:00'),
        ['completed_at' => '2026-07-04 18:00:00'],
        '2026-07-04 23:00:00',
        AE_TZ
    );

    Assert::same('2026-07-05 09:00 EDT', ae_local($step['at']));
});

Harness::test(
    'a T-minus step crossing a DST boundary still lands on the right clock',
    function (): void {
        // A party on November 7, T-14 at 10am, counted from a date in EDT to
        // a step date in EST. The clock the guest reads must be 10:00 either
        // way; only the UTC instant moves.
        $step = E::scheduleStep(
            ae_relative('days_before_field', 'starts_at', 14, '10:00'),
            ['starts_at' => '2026-11-07 13:00:00'],
            '2026-10-01 12:00:00',
            AE_TZ
        );

        Assert::same('2026-10-24 10:00 EDT', ae_local($step['at']));
    }
);

Harness::test(
    'a step counted from a date the record does not have is skipped, with the reason',
    function (): void {
        // A booking with no start date, or a contact with no birthday. The
        // run carries on; this one step does not happen.
        foreach ([[], ['starts_at' => null], ['starts_at' => '   ']] as $subject) {
            $step = E::scheduleStep(
                ae_relative('days_before_field', 'starts_at', 14),
                $subject,
                '2026-07-01 12:00:00',
                AE_TZ
            );

            Assert::true($step['skip']);
            Assert::contains('not set', $step['reason']);
        }
    }
);

Harness::test('an unparseable date is skipped rather than throwing', function (): void {
    $step = E::scheduleStep(
        ae_relative('days_before_field', 'starts_at', 14),
        ['starts_at' => 'next Thursdayish'],
        '2026-07-01 12:00:00',
        AE_TZ
    );

    Assert::true($step['skip']);
});

Harness::test('a step counted from a field nobody named is skipped', function (): void {
    $step = E::scheduleStep(
        ['delay_until_type' => 'days_before_field', 'delay_until_config' => ['days' => 3]],
        ['starts_at' => '2026-12-20 14:00:00'],
        '2026-07-01 12:00:00',
        AE_TZ
    );

    Assert::true($step['skip']);
});

// ============================================================== past due

Harness::test(
    'A STEP WHOSE MOMENT HAS PASSED IS SKIPPED, NOT SENT LATE',
    function (): void {
        /*
         * The case that makes this function return null instead of clamping.
         * A booking enrolled four days before the party cannot send its
         * fourteen-days-before message, and "clamp to now" sends "your party
         * is in two weeks!" to somebody whose party is on Tuesday.
         */
        $step = E::scheduleStep(
            ae_relative('days_before_field', 'starts_at', 14, '10:00'),
            ['starts_at' => '2026-10-20 14:00:00'],
            '2026-10-16 12:00:00',
            AE_TZ
        );

        Assert::true($step['skip'], 'it must not be sent');
        Assert::null($step['at'], 'and must not be scheduled in the past');
        Assert::contains('passed', $step['reason'], 'with a reason somebody can read');
        Assert::contains('days', $step['reason'], 'naming how late it is');
    }
);

Harness::test(
    'a step that is only a little late is caught up, not dropped',
    function (): void {
        // A cron that was down for an hour should still send what it missed.
        // This is the other half of the decision above, and the grace window
        // is the whole distinction between the two.
        $step = E::scheduleStep(
            ae_relative('days_after_field', 'completed_at', 0, '09:00'),
            ['completed_at' => '2026-07-04 09:00:00'],
            '2026-07-04 14:30:00',   // 10:30 local, 90 minutes past
            AE_TZ
        );

        Assert::false($step['skip'], 'inside the grace window it still runs');
        Assert::same('2026-07-04 14:30:00', $step['at'], 'now, not its original moment');
        Assert::contains('catching up', $step['reason']);
    }
);

Harness::test('the grace window has an exact edge', function (): void {
    $subject = ['completed_at' => '2026-07-04 09:00:00'];
    $step    = ae_relative('days_after_field', 'completed_at', 0, '09:00');

    // 09:00 local is 13:00 UTC. Exactly 120 minutes late, then one more.
    $inside  = E::scheduleStep($step, $subject, '2026-07-04 15:00:00', AE_TZ);
    $outside = E::scheduleStep($step, $subject, '2026-07-04 15:01:00', AE_TZ);

    Assert::false($inside['skip'], 'exactly at the limit still runs');
    Assert::true($outside['skip'], 'a minute later does not');
});

Harness::test('the grace window is configurable per call', function (): void {
    $subject = ['completed_at' => '2026-07-04 09:00:00'];
    $step    = ae_relative('days_after_field', 'completed_at', 0, '09:00');

    // Six hours late. Skipped at the default, allowed with a wider window —
    // which is what a venue recovering from a day of downtime would want.
    Assert::true(E::scheduleStep($step, $subject, '2026-07-04 19:00:00', AE_TZ)['skip']);
    Assert::false(E::scheduleStep($step, $subject, '2026-07-04 19:00:00', AE_TZ, 600)['skip']);
});

Harness::test('an unreadable clock skips rather than guessing', function (): void {
    $step = E::scheduleStep(['delay_minutes' => 10], [], 'not a date', AE_TZ);

    Assert::true($step['skip']);
});

Harness::test('an unreadable timezone skips a step that needs one', function (): void {
    // Rather than silently falling back to UTC, which would send a 9am
    // reminder at 4am.
    $step = E::scheduleStep(ae_at('09:00'), [], '2026-07-01 12:00:00', 'Not/AZone');

    Assert::true($step['skip']);
});

// ===================================================== exit conditions

Harness::test(
    'EXIT CONDITIONS STOP A SEQUENCE MID-FLIGHT',
    function (): void {
        // The single most embarrassing failure an automation has: a guest who
        // booked on day three still getting "still thinking about it?" on day
        // five. The only defence is checking before every step rather than
        // once at enrolment.
        $conditions = ['match' => 'any', 'rules' => [
            ['field' => 'deal_status', 'op' => 'is', 'value' => 'won'],
            ['field' => 'has_booking', 'op' => 'is_true'],
        ]];

        $stillThinking = E::shouldExit($conditions, ['deal_status' => 'open', 'has_booking' => 0]);
        $booked        = E::shouldExit($conditions, ['deal_status' => 'open', 'has_booking' => 1]);
        $won           = E::shouldExit($conditions, ['deal_status' => 'won', 'has_booking' => 0]);

        Assert::false($stillThinking['exit'], 'a guest still deciding keeps getting the sequence');
        Assert::true($booked['exit'], 'one who booked stops');
        Assert::true($won['exit'], 'and so does one whose deal was won');
        Assert::notEmpty($booked['reason'], 'and the reason is recorded');
    }
);

Harness::test('no exit conditions means no exit', function (): void {
    Assert::false(E::shouldExit(null, ['anything' => 1])['exit']);
    Assert::false(E::shouldExit([], ['anything' => 1])['exit']);
    Assert::false(E::shouldExit(['match' => 'all', 'rules' => []], ['anything' => 1])['exit']);
});

// ===================================================== the condition evaluator

Harness::test('every operator does what it says', function (): void {
    $subject = [
        'name'      => 'Casey Morrow',
        'stage'     => 'Proposal',
        'value'     => 450,
        'consent'   => 1,
        'opted_out' => 0,
        'email'     => 'casey@example.test',
        'blank'     => '',
        'party_at'  => '2026-12-20 14:00:00',
        'days_ago'  => 45,
    ];

    $yes = static fn (array $rule): bool => E::evaluate(['rules' => [$rule]], $subject);

    Assert::true($yes(['field' => 'stage', 'op' => 'is', 'value' => 'Proposal']));
    Assert::true($yes(['field' => 'stage', 'op' => 'is', 'value' => 'proposal']), 'case does not matter');
    Assert::false($yes(['field' => 'stage', 'op' => 'is', 'value' => 'Won']));
    Assert::true($yes(['field' => 'stage', 'op' => 'is_not', 'value' => 'Won']));

    Assert::true($yes(['field' => 'name', 'op' => 'contains', 'value' => 'morrow']));
    Assert::true($yes(['field' => 'name', 'op' => 'not_contains', 'value' => 'smith']));
    Assert::true($yes(['field' => 'name', 'op' => 'starts_with', 'value' => 'casey']));

    Assert::true($yes(['field' => 'value', 'op' => 'gt', 'value' => 400]));
    Assert::false($yes(['field' => 'value', 'op' => 'gt', 'value' => 450]));
    Assert::true($yes(['field' => 'value', 'op' => 'gte', 'value' => 450]));
    Assert::true($yes(['field' => 'value', 'op' => 'lt', 'value' => 500]));
    Assert::true($yes(['field' => 'value', 'op' => 'lte', 'value' => 450]));

    Assert::true($yes(['field' => 'email', 'op' => 'is_set']));
    Assert::true($yes(['field' => 'blank', 'op' => 'is_empty']), 'present but empty is not set');
    Assert::false($yes(['field' => 'blank', 'op' => 'is_set']));
    Assert::true($yes(['field' => 'missing', 'op' => 'is_empty']), 'and neither is absent');

    Assert::true($yes(['field' => 'consent', 'op' => 'is_true']));
    Assert::true($yes(['field' => 'opted_out', 'op' => 'is_false']));

    Assert::true($yes(['field' => 'stage', 'op' => 'in', 'value' => 'Proposal,Qualified']));
    Assert::true($yes(['field' => 'stage', 'op' => 'in', 'value' => ['Proposal', 'Qualified']]));
    Assert::true($yes(['field' => 'stage', 'op' => 'not_in', 'value' => 'Won,Lost']));

    Assert::true($yes(['field' => 'party_at', 'op' => 'after', 'value' => '2026-01-01']));
    Assert::true($yes(['field' => 'party_at', 'op' => 'before', 'value' => '2027-01-01']));

    Assert::true($yes(['field' => 'days_ago', 'op' => 'older_than_days', 'value' => 30]));
    Assert::false($yes(['field' => 'days_ago', 'op' => 'within_days', 'value' => 30]));
    Assert::true($yes(['field' => 'days_ago', 'op' => 'within_days', 'value' => 60]));
});

Harness::test(
    'AN UNREADABLE RULE IS FALSE, NEVER TRUE',
    function (): void {
        /*
         * Which way an unreadable rule falls decides what happens when a
         * stored automation outlives the version that wrote it. False means
         * nobody is enrolled by accident and no sequence silently stops.
         * True would mean a rule nobody can read matches everybody, which on
         * an entry condition is a message to the entire contact list.
         */
        $subject = ['stage' => 'Proposal'];

        foreach ([
            ['field' => '', 'op' => 'is', 'value' => 'x'],
            ['field' => 'stage', 'op' => 'sounds_like', 'value' => 'Proposal'],
            ['field' => 'stage', 'op' => '', 'value' => 'Proposal'],
            ['nonsense' => true],
        ] as $rule) {
            Assert::false(
                E::evaluate(['rules' => [$rule]], $subject),
                'an unreadable rule matched: ' . json_encode($rule)
            );
        }

        // And "not" of an unreadable rule is not true either — it is simply
        // not evaluated into existence.
        Assert::false(E::evaluate(['rules' => []], $subject), 'no rules matches nobody');
        Assert::false(E::evaluate(['rules' => 'not an array'], $subject));
    }
);

Harness::test('all versus any', function (): void {
    $subject = ['a' => 1, 'b' => 0];

    $rules = [
        ['field' => 'a', 'op' => 'is_true'],
        ['field' => 'b', 'op' => 'is_true'],
    ];

    Assert::false(E::evaluate(['match' => 'all', 'rules' => $rules], $subject));
    Assert::true(E::evaluate(['match' => 'any', 'rules' => $rules], $subject));
    Assert::false(E::evaluate(['rules' => $rules], $subject), 'all is the default');
});

Harness::test('a nested group is evaluated as a group', function (): void {
    // "Consented AND (a big deal OR a returning guest)".
    $group = ['match' => 'all', 'rules' => [
        ['field' => 'consent', 'op' => 'is_true'],
        ['match' => 'any', 'rules' => [
            ['field' => 'value', 'op' => 'gte', 'value' => 500],
            ['field' => 'visits', 'op' => 'gte', 'value' => 2],
        ]],
    ]];

    Assert::true(E::evaluate($group, ['consent' => 1, 'value' => 100, 'visits' => 3]));
    Assert::true(E::evaluate($group, ['consent' => 1, 'value' => 600, 'visits' => 0]));
    Assert::false(E::evaluate($group, ['consent' => 0, 'value' => 600, 'visits' => 3]), 'consent is required');
    Assert::false(E::evaluate($group, ['consent' => 1, 'value' => 100, 'visits' => 1]), 'neither alternative');
});

// ============================================================== branching

Harness::test('a condition step branches on the subject', function (): void {
    $step = ['type' => 'condition', 'config' => ['rules' => [
        'match' => 'all',
        'rules' => [['field' => 'nps', 'op' => 'gte', 'value' => 9]],
    ]]];

    Assert::same('yes', E::branchFor($step, ['nps' => 10]));
    Assert::same('no', E::branchFor($step, ['nps' => 4]));
});

Harness::test('a condition step with no rules takes the no branch', function (): void {
    // Not the yes branch. A review-gate automation whose condition went
    // missing must not ask a detractor for a public review.
    Assert::same('no', E::branchFor(['type' => 'condition', 'config' => []], ['nps' => 10]));
    Assert::same('no', E::branchFor(['type' => 'condition'], ['nps' => 10]));
});

Harness::test(
    'A SPLIT TEST IS DETERMINISTIC FOR A GIVEN RUN',
    function (): void {
        // A retry re-executing this step must not put the same guest in the
        // other arm: they would receive both versions of the message, which
        // is worse than either of them.
        $step = ['type' => 'split_test', 'config' => ['percent' => 50]];

        foreach ([1, 2, 3, 99, 1000, 123456] as $runId) {
            $first = E::splitFor($step, $runId);

            for ($i = 0; $i < 5; $i++) {
                Assert::same($first, E::splitFor($step, $runId), 'run ' . $runId . ' must not move');
            }
        }
    }
);

Harness::test('a split test actually splits, and not by alternating', function (): void {
    $step = ['type' => 'split_test', 'config' => ['percent' => 50]];
    $arms = ['yes' => 0, 'no' => 0];
    $sequence = [];

    for ($runId = 1; $runId <= 400; $runId++) {
        $arm = E::splitFor($step, $runId);
        $arms[$arm]++;
        $sequence[] = $arm;
    }

    // Roughly even. Not exactly, and a test demanding exactness would be
    // testing the hash rather than the split.
    Assert::greaterThan(150, $arms['yes'], 'got ' . $arms['yes'] . ' in the yes arm');
    Assert::greaterThan(150, $arms['no'], 'got ' . $arms['no'] . ' in the no arm');

    /*
     * And NOT a perfect alternation. Sequential ids with a modulo split
     * correlate the arm with the order people enrolled — every second guest
     * gets version A — which quietly confounds whatever the split was meant
     * to measure.
     */
    $alternations = 0;

    for ($i = 1; $i < count($sequence); $i++) {
        if ($sequence[$i] !== $sequence[$i - 1]) {
            $alternations++;
        }
    }

    $maximum = count($sequence) - 1;

    Assert::true(
        $alternations < $maximum,
        'the arms alternated ' . $alternations . ' times out of ' . $maximum
            . ' — a perfect alternation correlates the arm with enrolment order'
    );
});

Harness::test('0% and 100% splits are honoured rather than split', function (): void {
    Assert::same('no', E::splitFor(['config' => ['percent' => 0]], 7));
    Assert::same('yes', E::splitFor(['config' => ['percent' => 100]], 7));

    // And out-of-range values clamp rather than throwing.
    Assert::same('no', E::splitFor(['config' => ['percent' => -20]], 7));
    Assert::same('yes', E::splitFor(['config' => ['percent' => 500]], 7));
});

// ========================================================= walking the tree

Harness::test('steps run in order within a branch', function (): void {
    $steps = [
        ['id' => 3, 'parent_step_id' => null, 'branch' => 'main', 'sort_order' => 2, 'type' => 'wait'],
        ['id' => 1, 'parent_step_id' => null, 'branch' => 'main', 'sort_order' => 0, 'type' => 'send_email'],
        ['id' => 2, 'parent_step_id' => null, 'branch' => 'main', 'sort_order' => 1, 'type' => 'send_sms'],
    ];

    Assert::same(1, (int) E::nextStep($steps, null)['id'], 'the first by sort order');
    Assert::same(2, (int) E::nextStep($steps, 1)['id']);
    Assert::same(3, (int) E::nextStep($steps, 2)['id']);
    Assert::null(E::nextStep($steps, 3), 'and then the branch ends');
});

Harness::test('two steps sharing a sort order still have a stable order', function (): void {
    // Dragging steps around can leave duplicates. Falling back to the id
    // means the run order does not depend on what the database felt like
    // returning first.
    $steps = [
        ['id' => 9, 'parent_step_id' => null, 'branch' => 'main', 'sort_order' => 1, 'type' => 'wait'],
        ['id' => 4, 'parent_step_id' => null, 'branch' => 'main', 'sort_order' => 1, 'type' => 'wait'],
    ];

    Assert::same(4, (int) E::nextStep($steps, null)['id']);
    Assert::same(9, (int) E::nextStep($steps, 4)['id']);
});

Harness::test('a disabled step is skipped entirely, not deferred', function (): void {
    // Switching a step off means "do not do this", not "do it later".
    $steps = [
        ['id' => 1, 'parent_step_id' => null, 'branch' => 'main', 'sort_order' => 0, 'type' => 'send_email', 'is_enabled' => 1],
        ['id' => 2, 'parent_step_id' => null, 'branch' => 'main', 'sort_order' => 1, 'type' => 'send_sms', 'is_enabled' => 0],
        ['id' => 3, 'parent_step_id' => null, 'branch' => 'main', 'sort_order' => 2, 'type' => 'wait', 'is_enabled' => 1],
    ];

    Assert::same(3, (int) E::nextStep($steps, 1)['id'], 'step 2 is off, so 3 is next');
    Assert::null(E::nextStep($steps, 2), 'and a disabled step is not even a position');
});

Harness::test('branches hang off their condition step', function (): void {
    $steps = [
        ['id' => 1, 'parent_step_id' => null, 'branch' => 'main', 'sort_order' => 0, 'type' => 'condition'],
        ['id' => 2, 'parent_step_id' => 1, 'branch' => 'yes', 'sort_order' => 0, 'type' => 'send_email'],
        ['id' => 3, 'parent_step_id' => 1, 'branch' => 'yes', 'sort_order' => 1, 'type' => 'create_task'],
        ['id' => 4, 'parent_step_id' => 1, 'branch' => 'no',  'sort_order' => 0, 'type' => 'notify_user'],
        ['id' => 5, 'parent_step_id' => null, 'branch' => 'main', 'sort_order' => 1, 'type' => 'goal'],
    ];

    Assert::same(2, (int) E::firstInBranch($steps, 1, 'yes')['id']);
    Assert::same(4, (int) E::firstInBranch($steps, 1, 'no')['id']);
    Assert::same(3, (int) E::nextStep($steps, 2, 1, 'yes')['id']);
    Assert::null(E::nextStep($steps, 4, 1, 'no'), 'the no branch has one step');
    Assert::same(5, (int) E::nextStep($steps, 1)['id'], 'the main line continues past the condition');
});

Harness::test('an empty branch is null rather than an error', function (): void {
    $steps = [['id' => 1, 'parent_step_id' => null, 'branch' => 'main', 'sort_order' => 0, 'type' => 'condition']];

    Assert::null(E::firstInBranch($steps, 1, 'yes'));
    Assert::null(E::firstInBranch($steps, 1, 'no'));
});

// ========================================================= re-entry

Harness::test('somebody never enrolled can always be enrolled', function (): void {
    $can = E::canEnrol(null, '2026-07-01 12:00:00', true, false, null);

    Assert::true($can['allowed']);
});

Harness::test('run-once-per-guest means once', function (): void {
    $last = ['started_at' => '2024-01-01 10:00:00', 'finished_at' => '2024-01-09 10:00:00'];
    $can  = E::canEnrol($last, '2026-07-01 12:00:00', true, false, null);

    Assert::false($can['allowed'], 'even two years later');
    Assert::contains('once', $can['reason']);
});

Harness::test(
    'A SEQUENCE STILL IN FLIGHT CANNOT BE RE-ENTERED',
    function (): void {
        /*
         * The overlap case. A nine-day nurture with a cooldown of 24 hours,
         * measured from the START, can be re-entered on day two — and the
         * guest then receives two copies of the same sequence, offset by a
         * day, for a week. Measured from the FINISH, and refused outright
         * while unfinished.
         */
        $inFlight = ['started_at' => '2026-07-01 10:00:00', 'finished_at' => null];
        $can      = E::canEnrol($inFlight, '2026-07-05 12:00:00', false, true, 24);

        Assert::false($can['allowed'], 'four days in, with a 24-hour cooldown');
        Assert::contains('part way through', $can['reason']);
    }
);

Harness::test('a cooldown is measured from when the last run finished', function (): void {
    $last = ['started_at' => '2026-07-01 10:00:00', 'finished_at' => '2026-07-09 10:00:00'];

    $tooSoon = E::canEnrol($last, '2026-07-10 09:00:00', false, true, 48);
    $ready   = E::canEnrol($last, '2026-07-11 11:00:00', false, true, 48);

    Assert::false($tooSoon['allowed'], '23 hours after finishing, with a 48-hour cooldown');
    Assert::contains('cooldown', $tooSoon['reason']);
    Assert::true($ready['allowed'], 'and allowed once it has passed');
});

Harness::test('a cooldown edge is exact', function (): void {
    $last = ['started_at' => '2026-07-01 10:00:00', 'finished_at' => '2026-07-09 10:00:00'];

    Assert::true(E::canEnrol($last, '2026-07-11 10:00:00', false, true, 48)['allowed'], 'exactly 48 hours');
    Assert::false(E::canEnrol($last, '2026-07-11 09:59:00', false, true, 48)['allowed'], 'a minute short');
});

Harness::test('re-entry with no cooldown is immediate', function (): void {
    $last = ['started_at' => '2026-07-01 10:00:00', 'finished_at' => '2026-07-01 11:00:00'];

    Assert::true(E::canEnrol($last, '2026-07-01 11:01:00', false, true, 0)['allowed']);
    Assert::true(E::canEnrol($last, '2026-07-01 11:01:00', false, true, null)['allowed']);
});

Harness::test('unreadable dates on the last run refuse rather than allow', function (): void {
    // The safe direction: a second copy of a nine-touch sequence is worse
    // than a guest who misses one re-enrolment.
    $can = E::canEnrol(
        ['started_at' => 'nonsense', 'finished_at' => 'also nonsense'],
        '2026-07-01 12:00:00',
        false,
        true,
        24
    );

    Assert::false($can['allowed']);
});

// ============================================================== goals

Harness::test('the goal event records a conversion once', function (): void {
    $automation = ['goal_event' => 'deal.won', 'goal_window_hours' => 720];
    $run        = ['started_at' => '2026-07-01 10:00:00', 'goal_reached_at' => null];

    Assert::true(E::reachesGoal($automation, $run, 'deal.won', '2026-07-05 10:00:00'));
    Assert::false(E::reachesGoal($automation, $run, 'deal.lost', '2026-07-05 10:00:00'), 'a different event');

    $already = ['started_at' => '2026-07-01 10:00:00', 'goal_reached_at' => '2026-07-02 10:00:00'];

    Assert::false(
        E::reachesGoal($automation, $already, 'deal.won', '2026-07-05 10:00:00'),
        'a second conversion is not a second conversion'
    );
});

Harness::test('a goal outside its window is not a conversion', function (): void {
    // Otherwise every automation eventually reports a 100% conversion rate,
    // because every guest books something eventually.
    $automation = ['goal_event' => 'deal.won', 'goal_window_hours' => 24];
    $run        = ['started_at' => '2026-07-01 10:00:00', 'goal_reached_at' => null];

    Assert::true(E::reachesGoal($automation, $run, 'deal.won', '2026-07-02 09:00:00'));
    Assert::false(E::reachesGoal($automation, $run, 'deal.won', '2026-07-03 10:00:00'));
});

Harness::test('no window means any time', function (): void {
    $automation = ['goal_event' => 'deal.won', 'goal_window_hours' => 0];
    $run        = ['started_at' => '2026-07-01 10:00:00'];

    Assert::true(E::reachesGoal($automation, $run, 'deal.won', '2027-07-01 10:00:00'));
});

Harness::test('an automation with no goal has no conversions', function (): void {
    Assert::false(E::reachesGoal(['goal_event' => null], ['started_at' => 'x'], 'deal.won', 'y'));
    Assert::false(E::reachesGoal([], ['started_at' => 'x'], 'deal.won', 'y'));
});

// ========================================================= validation

Harness::test(
    'A STEP WITH A MISSING CONFIG IS REFUSED AT THE SAVE',
    function (): void {
        // Not at three in the morning inside a cron run, logged where nobody
        // is looking, with the automation silently broken for every guest.
        Assert::contains('template', (string) E::validateStep(['type' => 'send_email', 'config' => []]));
        Assert::contains('template', (string) E::validateStep(['type' => 'send_sms', 'config' => ['template' => '  ']]));
        Assert::contains('title', (string) E::validateStep(['type' => 'create_task', 'config' => []]));
        Assert::contains('tag', (string) E::validateStep(['type' => 'add_tag', 'config' => []]));

        Assert::null(E::validateStep(['type' => 'send_email', 'config' => ['template' => 'welcome']]));
        Assert::null(E::validateStep(['type' => 'wait', 'config' => []]), 'a wait needs nothing');
    }
);

Harness::test('an unknown step type is refused', function (): void {
    Assert::contains('not a kind of step', (string) E::validateStep(['type' => 'launch_fireworks']));
    Assert::contains('not a kind of step', (string) E::validateStep([]));
});

Harness::test('a delay that cannot be resolved is refused at the save', function (): void {
    Assert::contains('HH:MM', (string) E::validateStep([
        'type' => 'wait', 'delay_until_type' => 'time_of_day', 'delay_until_config' => [],
    ]));
    Assert::contains('which date', (string) E::validateStep([
        'type' => 'wait', 'delay_until_type' => 'days_before_field', 'delay_until_config' => ['days' => 3],
    ]));
    Assert::contains('kind of delay', (string) E::validateStep([
        'type' => 'wait', 'delay_until_type' => 'when_i_feel_like_it',
    ]));
});

Harness::test('a split that is not a split is refused', function (): void {
    foreach ([0, 100, -1, 101, 'half'] as $percent) {
        Assert::contains(
            'between 1 and 99',
            (string) E::validateStep(['type' => 'split_test', 'config' => ['percent' => $percent]]),
            'percent ' . var_export($percent, true) . ' should be refused'
        );
    }

    Assert::null(E::validateStep(['type' => 'split_test', 'config' => ['percent' => 50]]));
});

Harness::test(
    'a webhook must be https, because it is the one step that leaves the venue',
    function (): void {
        Assert::contains('https', (string) E::validateStep([
            'type' => 'webhook', 'config' => ['url' => 'http://example.test/hook'],
        ]));
        Assert::contains('https', (string) E::validateStep([
            'type' => 'webhook', 'config' => ['url' => 'not a url'],
        ]));
        Assert::null(E::validateStep([
            'type' => 'webhook', 'config' => ['url' => 'https://example.test/hook'],
        ]));
    }
);

// ========================================================= plain English

Harness::test(
    'every step can say what it does, in a sentence somebody can check',
    function (): void {
        // An automation nobody can read is an automation nobody dares switch
        // on, and this venue's staff are not going to audit a JSON column.
        $cases = [
            [['type' => 'send_email', 'config' => ['template' => 'Party enquiry'], 'delay_minutes' => 0],
             'Straight away, email them the "Party enquiry" template'],
            [['type' => 'send_sms', 'config' => ['template' => 'Day before'], 'delay_minutes' => 1440],
             'After 1 day, text them the "Day before" template'],
            [['type' => 'create_task', 'config' => ['title' => 'Ring them'], 'delay_minutes' => 120],
             'After 2 hours, create the task "Ring them"'],
            [['type' => 'add_tag', 'config' => ['tag' => 'nurture'], 'delay_minutes' => 45],
             'After 45 minutes, tag them "nurture"'],
        ];

        foreach ($cases as [$step, $expected]) {
            Assert::same($expected, E::describeStep($step));
        }
    }
);

Harness::test('a T-minus delay reads as a T-minus delay', function (): void {
    $step = [
        'type'               => 'send_email',
        'config'             => ['template' => 'Waiver chase'],
        'delay_until_type'   => 'days_before_field',
        'delay_until_config' => ['field' => 'starts_at', 'days' => 14, 'at' => '10:00'],
    ];

    Assert::same(
        '14 days before the event at 10:00, email them the "Waiver chase" template',
        E::describeStep($step)
    );
});

Harness::test('a condition reads as a question', function (): void {
    $step = ['type' => 'condition', 'config' => ['rules' => [
        'match' => 'all',
        'rules' => [
            ['field' => 'nps_score', 'op' => 'gte', 'value' => 9],
            ['field' => 'email', 'op' => 'is_set'],
        ],
    ]]];

    Assert::same(
        'Check whether nps score is at least 9 and email is filled in',
        E::describeStep($step)
    );
});

Harness::test('a description never comes back blank', function (): void {
    // It is rendered on a page, and a blank where a rule should be reads as
    // "no rule" — which is the opposite of a rule nobody can read.
    Assert::notEmpty(E::describe([]));
    Assert::notEmpty(E::describe(['rules' => []]));
    Assert::notEmpty(E::describe(['rules' => 'nonsense']));
    Assert::notEmpty(E::describeStep([]));
    Assert::notEmpty(E::describeStep(['type' => 'something_new']));
    Assert::contains('nothing in particular', E::describe([]));
});

Harness::test('a config stored as a JSON string reads the same as an array', function (): void {
    // It arrives as a string from the database and as an array from a form,
    // and a reader that only handled one of them would work in exactly one
    // of those two situations.
    $fromDb  = ['type' => 'send_email', 'config' => '{"template":"Welcome"}'];
    $fromApp = ['type' => 'send_email', 'config' => ['template' => 'Welcome']];

    Assert::same(E::describeStep($fromApp), E::describeStep($fromDb));
    Assert::null(E::validateStep($fromDb));
});
