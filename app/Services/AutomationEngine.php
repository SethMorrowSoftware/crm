<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The automation engine's decisions, with no database, no clock and no network.
 *
 * Its I/O half is `AutomationRunner`, which enrols, advances and executes.
 * Everything that decides WHETHER and WHEN lives here, because those are the
 * decisions that send a message to a real family at the wrong moment, and
 * they have to be testable without waiting three days for a delay to elapse.
 *
 * ## Four decisions, and why each is here rather than inline
 *
 * 1. **WHEN a delayed step runs.** A delay is minutes, or a time of day, or a
 *    number of days either side of a date on the booking. The third kind is
 *    where the money is: "fourteen days before the party" is how the waiver
 *    chase works, and computing it by adding seconds to a timestamp lands an
 *    hour out across a daylight-saving change — twice a year, for every run
 *    in flight.
 *
 * 2. **WHETHER a step runs at all.** A delay can resolve into the PAST: an
 *    automation enrolled a booking that is already nine days away cannot send
 *    its fourteen-days-before message, and the naive answer — clamp to now —
 *    sends "your party is in two weeks!" the week of the party. Past-due
 *    steps are SKIPPED beyond a grace window, and the run continues.
 *
 * 3. **WHETHER the run should stop.** Exit conditions are evaluated before
 *    EVERY step, not once at enrolment, so a guest who books on day three
 *    stops receiving "still thinking about it?" on day five. This is the
 *    single most embarrassing failure an automation has, and the only
 *    defence is checking late rather than early.
 *
 * 4. **WHICH branch.** A condition step on any field of the subject, and a
 *    split test by percentage — deterministic from the run, so re-running a
 *    step after a retry cannot put the same guest in the other arm and send
 *    them both versions.
 */
final class AutomationEngine
{
    /**
     * What a step can do.
     *
     * The config each one reads is documented beside it, because a step whose
     * config is wrong fails at 3am inside a cron run rather than in front of
     * whoever wrote it.
     *
     * @var array<string, array{label:string, needs:list<string>}>
     */
    public const STEP_TYPES = [
        'send_email'      => ['label' => 'Send an email',            'needs' => ['template']],
        'send_sms'        => ['label' => 'Send a text',              'needs' => ['template']],
        'create_task'     => ['label' => 'Create a task',            'needs' => ['title']],
        'notify_user'     => ['label' => 'Notify a user',            'needs' => ['message']],
        'update_field'    => ['label' => 'Set a field',              'needs' => ['field', 'value']],
        'add_tag'         => ['label' => 'Add a tag',                'needs' => ['tag']],
        'remove_tag'      => ['label' => 'Remove a tag',             'needs' => ['tag']],
        'move_deal_stage' => ['label' => 'Move the deal',            'needs' => ['stage']],
        'issue_coupon'    => ['label' => 'Issue a coupon',           'needs' => ['code']],
        'webhook'         => ['label' => 'Call a webhook',           'needs' => ['url']],
        'condition'       => ['label' => 'Branch on a condition',    'needs' => ['rules']],
        'split_test'      => ['label' => 'Split the audience',       'needs' => ['percent']],
        'goal'            => ['label' => 'Mark the goal and finish', 'needs' => []],
        'wait'            => ['label' => 'Wait',                     'needs' => []],
        'exit'            => ['label' => 'Stop here',                'needs' => []],
    ];

    /** The kinds of delay a step can carry. */
    public const DELAY_TYPES = ['minutes', 'time_of_day', 'days_before_field', 'days_after_field'];

    /**
     * How late a step may be and still run.
     *
     * Two hours. A cron that was down for an hour should still send the
     * messages it missed; a step whose moment passed last week should not.
     * The grace window is what separates "catching up" from "sending
     * something that is no longer true".
     */
    public const GRACE_MINUTES = 120;

    /** The operators a condition can use. Deliberately small and total. */
    public const OPERATORS = [
        'is', 'is_not', 'contains', 'not_contains', 'starts_with',
        'gt', 'gte', 'lt', 'lte', 'is_set', 'is_empty', 'is_true', 'is_false',
        'in', 'not_in', 'before', 'after', 'within_days', 'older_than_days',
    ];

    /** How many steps one run may execute in a single pass, as a loop bound. */
    public const MAX_STEPS_PER_PASS = 50;

    // =================================================================
    // When a step runs
    // =================================================================

    /**
     * When this step should run, as 'Y-m-d H:i:s' UTC — or null to skip it.
     *
     * Null means "its moment has passed": see decision 2 in the class comment.
     * A caller that treats null as "run now" re-introduces exactly the bug
     * this returns null to prevent.
     *
     * @param array{delay_minutes?:int, delay_until_type?:?string, delay_until_config?:mixed} $step
     * @param array<string,mixed> $subject  The contact/booking/deal the run is about.
     *
     * @return array{at:?string, skip:bool, reason:string}
     */
    public static function scheduleStep(
        array $step,
        array $subject,
        string $nowUtc,
        string $timezone,
        int $graceMinutes = self::GRACE_MINUTES
    ): array {
        $now = self::parse($nowUtc);

        if ($now === null) {
            return ['at' => null, 'skip' => true, 'reason' => 'the clock could not be read'];
        }

        $type   = (string) ($step['delay_until_type'] ?? '') ?: 'minutes';
        $config = self::config($step['delay_until_config'] ?? null);

        $target = match ($type) {
            'minutes'           => self::afterMinutes($now, (int) ($step['delay_minutes'] ?? 0)),
            'time_of_day'       => self::atTimeOfDay($now, $config, $timezone, (int) ($step['delay_minutes'] ?? 0)),
            'days_before_field' => self::relativeToField($subject, $config, $timezone, -1),
            'days_after_field'  => self::relativeToField($subject, $config, $timezone, 1),
            default             => null,
        };

        if ($target === null) {
            return [
                'at'     => null,
                'skip'   => true,
                'reason' => $type === 'minutes'
                    ? 'the delay could not be worked out'
                    : 'the date this step counts from is not set on the record',
            ];
        }

        // Already due, within grace: run it now rather than scheduling the
        // past, which some schedulers read as "immediately, for ever".
        $lateBy = (int) floor(($now->getTimestamp() - $target->getTimestamp()) / 60);

        if ($lateBy <= 0) {
            return ['at' => $target->format('Y-m-d H:i:s'), 'skip' => false, 'reason' => 'scheduled'];
        }

        if ($lateBy <= $graceMinutes) {
            return [
                'at'     => $now->format('Y-m-d H:i:s'),
                'skip'   => false,
                'reason' => 'catching up ' . $lateBy . ' minute(s) late',
            ];
        }

        return [
            'at'     => null,
            'skip'   => true,
            'reason' => 'its moment passed ' . self::humanLate($lateBy) . ' ago',
        ];
    }

    private static function afterMinutes(DateTimeImmutable $now, int $minutes): DateTimeImmutable
    {
        // Negative is treated as immediate rather than as a step into the
        // past: a hand-edited "-30 minutes" means somebody wanted it sooner.
        return $now->modify('+' . max(0, $minutes) . ' minutes');
    }

    /**
     * The next occurrence of a wall-clock time, in the venue's own zone.
     *
     * Built by setting the time IN THE ZONE, never by adding seconds. Adding
     * 86400 to 9am the day before a daylight-saving change gives 8am or 10am,
     * and "the 9am reminder" then arrives at the wrong hour for half the year.
     *
     * @param array<string,mixed> $config
     */
    private static function atTimeOfDay(
        DateTimeImmutable $now,
        array $config,
        string $timezone,
        int $minimumMinutes = 0
    ): ?DateTimeImmutable {
        $at = trim((string) ($config['at'] ?? ''));

        if (preg_match('/^(\d{1,2}):(\d{2})$/', $at, $m) !== 1) {
            return null;
        }

        $hour   = (int) $m[1];
        $minute = (int) $m[2];

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        $zone = self::zone($timezone);

        if ($zone === null) {
            return null;
        }

        $local    = $now->setTimezone($zone);
        $earliest = $local->modify('+' . max(0, $minimumMinutes) . ' minutes');

        // Today if that hour is still ahead of us, otherwise the next day the
        // configuration allows. `setTime` on the local object is what keeps
        // this correct across a changeover.
        $candidate = $local->setTime($hour, $minute);
        $days      = max(0, (int) ($config['days'] ?? 0));

        if ($days > 0) {
            $candidate = $local->modify('+' . $days . ' days')->setTime($hour, $minute);
        }

        $guard = 0;

        while ($candidate < $earliest && $guard < 8) {
            $candidate = $candidate->modify('+1 day')->setTime($hour, $minute);
            $guard++;
        }

        return $candidate->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * N days either side of a date field on the subject.
     *
     * This is how "fourteen days before the party" works, and the hour is
     * part of the configuration rather than inherited from the booking's own
     * time: a reminder for a 9am party should not go out at 9am fourteen days
     * earlier just because that is when the party starts.
     *
     * @param array<string,mixed> $subject
     * @param array<string,mixed> $config
     * @param int $direction -1 for before, 1 for after
     */
    private static function relativeToField(
        array $subject,
        array $config,
        string $timezone,
        int $direction
    ): ?DateTimeImmutable {
        $field = trim((string) ($config['field'] ?? ''));

        if ($field === '') {
            return null;
        }

        $raw = $subject[$field] ?? null;

        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }

        $zone = self::zone($timezone);

        if ($zone === null) {
            return null;
        }

        /*
         * The stored value is a LOCAL date or datetime — a booking's own day
         * and start time, as the venue wrote it. Reading it as UTC would shift
         * every T-minus step by the offset, which for this venue is four or
         * five hours: enough to move a "the day before" reminder onto the
         * wrong day.
         */
        try {
            $base = new DateTimeImmutable((string) $raw, $zone);
        } catch (\Exception) {
            return null;
        }

        $days   = max(0, (int) ($config['days'] ?? 0));
        $offset = $direction * $days;

        $target = $offset === 0
            ? $base
            : $base->modify(($offset > 0 ? '+' : '-') . abs($offset) . ' days');

        // An explicit hour wins; otherwise keep the record's own time.
        $at = trim((string) ($config['at'] ?? ''));

        if (preg_match('/^(\d{1,2}):(\d{2})$/', $at, $m) === 1
            && (int) $m[1] <= 23 && (int) $m[2] <= 59) {
            $target = $target->setTime((int) $m[1], (int) $m[2]);
        }

        return $target->setTimezone(new DateTimeZone('UTC'));
    }

    private static function humanLate(int $minutes): string
    {
        if ($minutes < 120) {
            return $minutes . ' minutes';
        }

        if ($minutes < 2880) {
            return (int) round($minutes / 60) . ' hours';
        }

        return (int) round($minutes / 1440) . ' days';
    }

    // =================================================================
    // Whether a run should stop
    // =================================================================

    /**
     * Should this run stop before the next step?
     *
     * @param array<string,mixed>|null $exitConditions
     * @param array<string,mixed>      $subject
     * @return array{exit:bool, reason:string}
     */
    public static function shouldExit(?array $exitConditions, array $subject): array
    {
        if ($exitConditions === null || $exitConditions === []) {
            return ['exit' => false, 'reason' => ''];
        }

        $match = self::evaluate($exitConditions, $subject);

        return $match
            ? ['exit' => true, 'reason' => self::describe($exitConditions)]
            : ['exit' => false, 'reason' => ''];
    }

    /**
     * Does this subject satisfy a condition group?
     *
     * The same rule shape as a segment, evaluated over an array rather than
     * compiled to SQL — the subject is already loaded by the time a run
     * reaches a step, and a second trip to the database per step would make a
     * nine-touch sequence nine queries deeper for no new information.
     *
     * An unreadable rule is FALSE, never true. Entry conditions decide who is
     * enrolled and exit conditions decide who stops: a rule that cannot be
     * read must not enrol somebody by accident, and must not silently stop a
     * sequence either.
     *
     * @param array{match?:string, rules?:list<array<string,mixed>>} $group
     * @param array<string,mixed> $subject
     */
    public static function evaluate(array $group, array $subject): bool
    {
        $rules = $group['rules'] ?? [];

        if (!is_array($rules) || $rules === []) {
            return false;
        }

        $all     = ($group['match'] ?? 'all') !== 'any';
        $results = [];

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                $results[] = false;

                continue;
            }

            // A nested group.
            if (isset($rule['rules']) && is_array($rule['rules'])) {
                $results[] = self::evaluate($rule, $subject);

                continue;
            }

            $results[] = self::match($rule, $subject);
        }

        return $all ? !in_array(false, $results, true) : in_array(true, $results, true);
    }

    /**
     * @param array<string,mixed> $rule
     * @param array<string,mixed> $subject
     */
    private static function match(array $rule, array $subject): bool
    {
        $field = trim((string) ($rule['field'] ?? ''));
        $op    = trim((string) ($rule['op'] ?? 'is'));

        if ($field === '' || !in_array($op, self::OPERATORS, true)) {
            return false;
        }

        $actual   = $subject[$field] ?? null;
        $expected = $rule['value'] ?? null;

        return match ($op) {
            'is'         => self::looseEquals($actual, $expected),
            'is_not'     => !self::looseEquals($actual, $expected),
            'contains'   => $actual !== null && stripos((string) $actual, (string) $expected) !== false,
            'not_contains' => $actual === null || stripos((string) $actual, (string) $expected) === false,
            'starts_with' => $actual !== null && stripos((string) $actual, (string) $expected) === 0,
            'gt'         => self::numeric($actual) > self::numeric($expected),
            'gte'        => self::numeric($actual) >= self::numeric($expected),
            'lt'         => self::numeric($actual) < self::numeric($expected),
            'lte'        => self::numeric($actual) <= self::numeric($expected),
            // A column that is present and EMPTY is not set. A venue asking
            // "is the email set" means "can I send to it".
            'is_set'     => $actual !== null && trim((string) $actual) !== '',
            'is_empty'   => $actual === null || trim((string) $actual) === '',
            'is_true'    => self::truthy($actual),
            'is_false'   => !self::truthy($actual),
            'in'         => self::inList($actual, $expected),
            'not_in'     => !self::inList($actual, $expected),
            'before'     => self::compareDates($actual, $expected, -1),
            'after'      => self::compareDates($actual, $expected, 1),
            'within_days' => self::withinDays($actual, (int) self::numeric($expected), true),
            'older_than_days' => self::withinDays($actual, (int) self::numeric($expected), false),
            default      => false,
        };
    }

    private static function looseEquals(mixed $actual, mixed $expected): bool
    {
        if ($actual === null) {
            return $expected === null || trim((string) $expected) === '';
        }

        // Compared as strings, case-insensitively, because these values come
        // from a JSON column on one side and a database row on the other:
        // "1" and 1 are the same answer, and so are "Won" and "won".
        return strcasecmp(trim((string) $actual), trim((string) $expected)) === 0;
    }

    private static function numeric(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (float) $value != 0.0;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'y', 'on'], true);
    }

    private static function inList(mixed $actual, mixed $expected): bool
    {
        if ($actual === null) {
            return false;
        }

        $list = is_array($expected)
            ? $expected
            : array_map('trim', explode(',', (string) $expected));

        foreach ($list as $candidate) {
            if (strcasecmp(trim((string) $actual), trim((string) $candidate)) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function compareDates(mixed $actual, mixed $expected, int $direction): bool
    {
        $left  = self::parse((string) $actual);
        $right = self::parse((string) $expected);

        if ($left === null || $right === null) {
            return false;
        }

        return $direction < 0 ? $left < $right : $left > $right;
    }

    private static function withinDays(mixed $actual, int $days, bool $within): bool
    {
        // Relative to the value itself rather than to a clock this class does
        // not own: the caller passes a subject whose dates are already
        // resolved, and `days_ago` is computed by the runner.
        $value = self::numeric($actual);

        return $within ? $value <= $days : $value > $days;
    }

    // =================================================================
    // Which branch
    // =================================================================

    /**
     * Which branch a condition step takes.
     *
     * @param array{config?:mixed} $step
     * @param array<string,mixed>  $subject
     * @return 'yes'|'no'
     */
    public static function branchFor(array $step, array $subject): string
    {
        $config = self::config($step['config'] ?? null);
        $rules  = $config['rules'] ?? null;

        if (!is_array($rules)) {
            return 'no';
        }

        return self::evaluate($rules, $subject) ? 'yes' : 'no';
    }

    /**
     * Which arm of a split test a run takes.
     *
     * DETERMINISTIC from the run's own id. A retry re-executing this step
     * must not put the same guest in the other arm — they would get both
     * versions of the message, which is worse than either.
     *
     * @param array{config?:mixed} $step
     * @return 'yes'|'no'
     */
    public static function splitFor(array $step, int $runId): string
    {
        $config  = self::config($step['config'] ?? null);
        $percent = max(0, min(100, (int) ($config['percent'] ?? 50)));

        if ($percent === 0) {
            return 'no';
        }

        if ($percent === 100) {
            return 'yes';
        }

        // Hashed rather than modulo on the id itself: sequential ids and a
        // 50% split would otherwise alternate perfectly, which correlates the
        // arm with the order people enrolled.
        $bucket = (int) (hexdec(substr(md5('split:' . $runId), 0, 6)) % 100);

        return $bucket < $percent ? 'yes' : 'no';
    }

    /**
     * The next step after this one, within a branch.
     *
     * @param list<array<string,mixed>> $steps     All of the automation's steps.
     * @param int|null                  $afterId   null to start at the beginning.
     * @return array<string,mixed>|null
     */
    public static function nextStep(array $steps, ?int $afterId, ?int $parentId = null, string $branch = 'main'): ?array
    {
        $siblings = [];

        foreach ($steps as $step) {
            if ((int) ($step['parent_step_id'] ?? 0) !== (int) $parentId) {
                continue;
            }

            if ((string) ($step['branch'] ?? 'main') !== $branch) {
                continue;
            }

            // A disabled step is skipped entirely, including its delay: an
            // operator switching one off means "do not do this", not "do it
            // later".
            if (($step['is_enabled'] ?? 1) === 0 || ($step['is_enabled'] ?? '1') === '0') {
                continue;
            }

            $siblings[] = $step;
        }

        usort($siblings, static fn (array $a, array $b): int =>
            [(int) ($a['sort_order'] ?? 0), (int) $a['id']] <=> [(int) ($b['sort_order'] ?? 0), (int) $b['id']]);

        if ($afterId === null) {
            return $siblings[0] ?? null;
        }

        $found = false;

        foreach ($siblings as $step) {
            if ($found) {
                return $step;
            }

            if ((int) $step['id'] === $afterId) {
                $found = true;
            }
        }

        return null;
    }

    /**
     * The first step of a branch under a condition step.
     *
     * @param list<array<string,mixed>> $steps
     * @return array<string,mixed>|null
     */
    public static function firstInBranch(array $steps, int $parentId, string $branch): ?array
    {
        return self::nextStep($steps, null, $parentId, $branch);
    }

    /**
     * The step that follows this one anywhere in the sequence.
     *
     * `nextStep()` answers within one branch; this one knows what to do at the
     * END of a branch, which is to rejoin the line the branch hangs off so the
     * steps after a condition run for both sides. The runner walks with this
     * and so does the preview, which is why it lives here: two copies of
     * "what comes next" would disagree about branches, and the preview would
     * then show a venue a sequence that is not the one that will run.
     *
     * @param list<array<string,mixed>> $steps
     * @return array<string,mixed>|null
     */
    public static function stepAfter(array $steps, ?int $currentId): ?array
    {
        if ($currentId === null) {
            return self::nextStep($steps, null);
        }

        $current = null;

        foreach ($steps as $step) {
            if ((int) $step['id'] === $currentId) {
                $current = $step;

                break;
            }
        }

        if ($current === null) {
            return self::nextStep($steps, null);
        }

        $parent = ($current['parent_step_id'] ?? null) === null ? null : (int) $current['parent_step_id'];
        $branch = (string) ($current['branch'] ?? 'main');

        $next = self::nextStep($steps, $currentId, $parent, $branch);

        if ($next !== null) {
            return $next;
        }

        if ($parent !== null) {
            return self::stepAfter($steps, $parent);
        }

        return null;
    }

    /**
     * WHAT THIS SEQUENCE WOULD DO TO THIS GUEST, AND WHEN.
     *
     * The preview behind the Test button. A venue has to be able to see a
     * sequence before a real family does, and the only preview worth having
     * is one that uses the engine's own decisions: the delays as they are
     * computed in the venue's zone, the branch this guest would actually
     * take, the step whose moment has already passed and would be skipped.
     *
     * PURE, AND IT WRITES NOTHING. An earlier version enrolled the guest for
     * real with sending captured and then deleted the run — which meant
     * zeroing the delays on the step rows while cron was running, where a
     * tick landing mid-preview would have advanced every OTHER guest in that
     * automation with no delay at all. A plan cannot do that to anybody.
     *
     * The clock advances with the plan, so step three's "two days later" is
     * two days after step two rather than two days from now.
     *
     * @param list<array<string,mixed>> $steps
     * @param array<string,mixed>       $subject
     * @return list<array{step:array<string,mixed>,at:?string,skip:bool,reason:string,branch:?string,stop:?string}>
     */
    public static function plan(array $steps, array $subject, string $nowUtc, string $zone, int $runId = 0): array
    {
        $planned = [];
        $reached = $nowUtc;
        $next    = self::stepAfter($steps, null);

        for ($guard = 0; $guard < self::MAX_STEPS_PER_PASS; $guard++) {
            if ($next === null) {
                break;
            }

            $step   = $next;
            $stepId = (int) $step['id'];
            $type   = (string) $step['type'];

            $when   = self::scheduleStep($step, $subject, $reached, $zone);
            $branch = null;
            $stop   = null;

            if (!$when['skip']) {
                // The next step's delay is measured from this one's moment,
                // not from now.
                $reached = $when['at'] ?? $reached;

                $branch = match ($type) {
                    'condition'  => self::branchFor($step, $subject),
                    'split_test' => self::splitFor($step, $runId),
                    default      => null,
                };

                $stop = match ($type) {
                    'exit' => 'exited',
                    'goal' => 'completed',
                    default => null,
                };
            }

            $planned[] = [
                'step'   => $step,
                'at'     => $when['skip'] ? null : $when['at'],
                'skip'   => (bool) $when['skip'],
                'reason' => (string) $when['reason'],
                'branch' => $branch,
                'stop'   => $stop,
            ];

            if ($stop !== null) {
                break;
            }

            if ($branch !== null) {
                // An empty branch rejoins the main line, exactly as the
                // runner does it.
                $next = self::firstInBranch($steps, $stepId, $branch) ?? self::stepAfter($steps, $stepId);

                continue;
            }

            $next = self::stepAfter($steps, $stepId);
        }

        return $planned;
    }

    /**
     * Does this condition group name a field anywhere in it?
     *
     * Recursive, because a group may hold groups. Anything that is not a
     * group with rules in it names nothing — including a bare LIST of rules,
     * which is the shape somebody writes by hand and which `evaluate()` reads
     * as empty.
     */
    private static function namesAField(mixed $group): bool
    {
        if (!is_array($group) || !is_array($group['rules'] ?? null)) {
            return false;
        }

        foreach ($group['rules'] as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            if (is_array($rule['rules'] ?? null)) {
                if (self::namesAField($rule)) {
                    return true;
                }

                continue;
            }

            if (trim((string) ($rule['field'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    // =================================================================
    // Re-entry and goals
    // =================================================================

    /**
     * May this subject be enrolled again?
     *
     * @param array{finished_at?:?string, started_at?:?string}|null $lastRun
     * @return array{allowed:bool, reason:string}
     */
    public static function canEnrol(
        ?array $lastRun,
        string $nowUtc,
        bool $runOncePerContact,
        bool $allowReentry,
        ?int $cooldownHours
    ): array {
        if ($lastRun === null) {
            return ['allowed' => true, 'reason' => 'never enrolled'];
        }

        if ($runOncePerContact && !$allowReentry) {
            return ['allowed' => false, 'reason' => 'this runs once per guest'];
        }

        if (!$allowReentry) {
            return ['allowed' => true, 'reason' => 're-entry is not restricted'];
        }

        $cooldown = max(0, (int) $cooldownHours);

        if ($cooldown === 0) {
            return ['allowed' => true, 'reason' => 'no cooldown'];
        }

        /*
         * Measured from when the last run FINISHED where it has, and from
         * when it started where it has not. A run still in flight is the case
         * that matters: measuring an unfinished run's cooldown from its start
         * lets a nine-day sequence be re-entered on day eight, so the guest
         * receives two overlapping copies of it.
         */
        $from = trim((string) ($lastRun['finished_at'] ?? '')) !== ''
            ? (string) $lastRun['finished_at']
            : (string) ($lastRun['started_at'] ?? '');

        $since = self::parse($from);
        $now   = self::parse($nowUtc);

        if ($since === null || $now === null) {
            return ['allowed' => false, 'reason' => 'the last run\'s dates could not be read'];
        }

        if (trim((string) ($lastRun['finished_at'] ?? '')) === '') {
            return ['allowed' => false, 'reason' => 'they are part way through it already'];
        }

        $hours = ($now->getTimestamp() - $since->getTimestamp()) / 3600;

        return $hours >= $cooldown
            ? ['allowed' => true, 'reason' => 'the cooldown has passed']
            : [
                'allowed' => false,
                'reason'  => 'another ' . (int) ceil($cooldown - $hours) . ' hour(s) of cooldown to go',
            ];
    }

    /**
     * Does this event reach the automation's goal for a run?
     *
     * @param array{goal_event?:?string, goal_window_hours?:?int} $automation
     * @param array{started_at?:?string, goal_reached_at?:?string} $run
     */
    public static function reachesGoal(array $automation, array $run, string $event, string $nowUtc): bool
    {
        $goal = trim((string) ($automation['goal_event'] ?? ''));

        if ($goal === '' || $goal !== $event) {
            return false;
        }

        // Once only. A second conversion is not a second conversion.
        if (trim((string) ($run['goal_reached_at'] ?? '')) !== '') {
            return false;
        }

        $window = (int) ($automation['goal_window_hours'] ?? 0);

        if ($window <= 0) {
            return true;
        }

        $started = self::parse((string) ($run['started_at'] ?? ''));
        $now     = self::parse($nowUtc);

        if ($started === null || $now === null) {
            return false;
        }

        return ($now->getTimestamp() - $started->getTimestamp()) <= $window * 3600;
    }

    // =================================================================
    // Saying what it does
    // =================================================================

    /**
     * What a step does, in a sentence somebody can check.
     *
     * The builder shows this under every card. An automation nobody can read
     * is an automation nobody dares switch on, and this venue's staff are not
     * going to audit a JSON column.
     *
     * @param array<string,mixed> $step
     */
    public static function describeStep(array $step): string
    {
        $type   = (string) ($step['type'] ?? '');
        $config = self::config($step['config'] ?? null);
        $when   = self::describeDelay($step);

        $what = match ($type) {
            'send_email'      => 'email them the "' . (string) ($config['template'] ?? 'untitled') . '" template',
            'send_sms'        => 'text them the "' . (string) ($config['template'] ?? 'untitled') . '" template',
            'create_task'     => 'create the task "' . (string) ($config['title'] ?? 'untitled') . '"',
            'notify_user'     => 'notify ' . ((string) ($config['to'] ?? '') ?: 'the owner'),
            'update_field'    => 'set ' . (string) ($config['field'] ?? 'a field')
                                    . ' to "' . (string) ($config['value'] ?? '') . '"',
            'add_tag'         => 'tag them "' . (string) ($config['tag'] ?? '') . '"',
            'remove_tag'      => 'remove the tag "' . (string) ($config['tag'] ?? '') . '"',
            'move_deal_stage' => 'move the deal to ' . (string) ($config['stage'] ?? 'another stage'),
            'issue_coupon'    => 'issue the coupon ' . (string) ($config['code'] ?? ''),
            'webhook'         => 'POST to ' . (string) ($config['url'] ?? 'a URL'),
            'condition'       => 'check whether ' . self::describe($config['rules'] ?? []),
            'split_test'      => 'send ' . (int) ($config['percent'] ?? 50) . '% down one path',
            'goal'            => 'record the goal and finish',
            'wait'            => 'wait',
            'exit'            => 'stop here',
            default           => 'do something this version does not know about',
        };

        /*
         * "Straight away" is dropped from the control-flow steps. For an
         * ACTION it is information — after a three-day wait, knowing the next
         * message goes immediately matters. For a condition or a split it is
         * noise: the branch is taken when the run reaches it, and "straight
         * away, check whether..." reads as though the checking were the thing
         * being scheduled.
         */
        if (in_array($type, ['condition', 'split_test', 'goal', 'exit'], true)
            && (int) ($step['delay_minutes'] ?? 0) === 0
            && ($step['delay_until_type'] ?? null) === null) {
            return ucfirst($what);
        }

        return trim($when === '' ? ucfirst($what) : ucfirst($when) . ', ' . $what);
    }

    /** @param array<string,mixed> $step */
    public static function describeDelay(array $step): string
    {
        $type   = (string) ($step['delay_until_type'] ?? '') ?: 'minutes';
        $config = self::config($step['delay_until_config'] ?? null);
        $days   = max(0, (int) ($config['days'] ?? 0));
        $at     = trim((string) ($config['at'] ?? ''));

        return match ($type) {
            'minutes' => self::describeMinutes((int) ($step['delay_minutes'] ?? 0)),
            'time_of_day' => $days > 0
                ? 'after ' . self::plural($days, 'day') . ', at ' . ($at === '' ? 'a set time' : $at)
                : 'at ' . ($at === '' ? 'a set time' : $at),
            'days_before_field' => self::plural($days, 'day') . ' before '
                . self::fieldName((string) ($config['field'] ?? ''))
                . ($at === '' ? '' : ' at ' . $at),
            'days_after_field'  => self::plural($days, 'day') . ' after '
                . self::fieldName((string) ($config['field'] ?? ''))
                . ($at === '' ? '' : ' at ' . $at),
            default => '',
        };
    }

    private static function describeMinutes(int $minutes): string
    {
        if ($minutes <= 0) {
            return 'straight away';
        }

        if ($minutes < 60) {
            return 'after ' . self::plural($minutes, 'minute');
        }

        if ($minutes < 1440) {
            return 'after ' . self::plural((int) round($minutes / 60), 'hour');
        }

        return 'after ' . self::plural((int) round($minutes / 1440), 'day');
    }

    private static function plural(int $n, string $word): string
    {
        return $n . ' ' . $word . ($n === 1 ? '' : 's');
    }

    private static function fieldName(string $field): string
    {
        return match ($field) {
            'starts_at', 'event_date' => 'the event',
            'expires_at'              => 'it expires',
            'birth_date'              => 'their birthday',
            ''                        => 'a date on the record',
            default                   => str_replace('_', ' ', $field),
        };
    }

    /**
     * A condition group in words.
     *
     * Never throws and never returns an empty string: this is rendered on a
     * page, and a blank where a rule should be reads as "no rule".
     *
     * @param array<string,mixed> $group
     */
    public static function describe(array $group): string
    {
        $rules = $group['rules'] ?? [];

        if (!is_array($rules) || $rules === []) {
            return 'nothing in particular';
        }

        $joiner = ($group['match'] ?? 'all') === 'any' ? ' or ' : ' and ';
        $parts  = [];

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            if (isset($rule['rules']) && is_array($rule['rules'])) {
                $parts[] = '(' . self::describe($rule) . ')';

                continue;
            }

            $field = str_replace('_', ' ', trim((string) ($rule['field'] ?? '')));
            $value = is_array($rule['value'] ?? null)
                ? implode(', ', array_map('strval', $rule['value']))
                : trim((string) ($rule['value'] ?? ''));

            $parts[] = match (trim((string) ($rule['op'] ?? ''))) {
                'is'              => $field . ' is ' . $value,
                'is_not'          => $field . ' is not ' . $value,
                'contains'        => $field . ' contains ' . $value,
                'not_contains'    => $field . ' does not contain ' . $value,
                'starts_with'     => $field . ' starts with ' . $value,
                'gt'              => $field . ' is more than ' . $value,
                'gte'             => $field . ' is at least ' . $value,
                'lt'              => $field . ' is less than ' . $value,
                'lte'             => $field . ' is at most ' . $value,
                'is_set'          => $field . ' is filled in',
                'is_empty'        => $field . ' is blank',
                'is_true'         => $field . ' is yes',
                'is_false'        => $field . ' is no',
                'in'              => $field . ' is one of ' . $value,
                'not_in'          => $field . ' is none of ' . $value,
                'before'          => $field . ' is before ' . $value,
                'after'           => $field . ' is after ' . $value,
                'within_days'     => $field . ' is within ' . $value . ' days',
                'older_than_days' => $field . ' is more than ' . $value . ' days ago',
                default           => $field . ' matches something unrecognised',
            };
        }

        return $parts === [] ? 'nothing in particular' : implode($joiner, $parts);
    }

    /**
     * What is wrong with a step, or null if nothing is.
     *
     * Checked when a step is SAVED. A step whose config is missing a template
     * fails inside a cron run at three in the morning, logged where nobody is
     * looking, and the automation silently stops working for every guest.
     *
     * @param array<string,mixed> $step
     */
    public static function validateStep(array $step): ?string
    {
        $type = (string) ($step['type'] ?? '');

        if (!isset(self::STEP_TYPES[$type])) {
            return 'That is not a kind of step this version knows about.';
        }

        $config = self::config($step['config'] ?? null);

        foreach (self::STEP_TYPES[$type]['needs'] as $key) {
            $value = $config[$key] ?? null;

            if ($value === null || (!is_array($value) && trim((string) $value) === '')) {
                return self::STEP_TYPES[$type]['label'] . ' needs "' . $key . '" filling in.';
            }
        }

        $delayType = (string) ($step['delay_until_type'] ?? '') ?: 'minutes';

        if (!in_array($delayType, self::DELAY_TYPES, true)) {
            return 'That is not a kind of delay this version knows about.';
        }

        $delayConfig = self::config($step['delay_until_config'] ?? null);

        if ($delayType === 'time_of_day'
            && preg_match('/^\d{1,2}:\d{2}$/', trim((string) ($delayConfig['at'] ?? ''))) !== 1) {
            return 'A step that waits for a time of day needs that time, as HH:MM.';
        }

        if (in_array($delayType, ['days_before_field', 'days_after_field'], true)
            && trim((string) ($delayConfig['field'] ?? '')) === '') {
            return 'A step counted from a date needs to know which date.';
        }

        if ($type === 'condition') {
            /*
             * A CONDITION WITH NO FIELD TO CHECK IS WORSE THAN NO CONDITION.
             *
             * The `needs` loop above accepts it: `rules` is an array, so it is
             * not empty by that test. But a rule naming no field evaluates
             * false for everybody, so every guest goes down the NO side — and
             * the step list reads "check whether nothing in particular", which
             * is honest and still does not say that half the sequence is
             * unreachable. MEASURED by driving the builder: the form saved one
             * happily.
             */
            // A GROUP, not a list: `config.rules` is what `branchFor()`
            // hands to `evaluate()`, which reads `match` and `rules` off it.
            // A list here would evaluate false for everybody.
            if (!self::namesAField($config['rules'] ?? null)) {
                return 'A condition needs a field to check — without one, every guest takes the NO side.';
            }
        }

        if ($type === 'split_test') {
            $percent = $config['percent'] ?? null;

            if (!is_numeric($percent) || (int) $percent < 1 || (int) $percent > 99) {
                return 'A split needs a percentage between 1 and 99 — 0 or 100 is not a split.';
            }
        }

        if ($type === 'webhook' && !self::looksLikeHttps((string) ($config['url'] ?? ''))) {
            // Plain HTTP would put guest data on the wire in clear, and a
            // webhook is the one step that sends it outside the venue.
            return 'A webhook needs an https:// address.';
        }

        return null;
    }

    private static function looksLikeHttps(string $url): bool
    {
        return preg_match('#^https://[a-z0-9.-]+\.[a-z]{2,}(/|$|:)#i', trim($url)) === 1;
    }

    // =================================================================
    // Plumbing
    // =================================================================

    /** @return array<string,mixed> */
    private static function config(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return is_array($raw) ? $raw : [];
    }

    private static function parse(string $stamp): ?DateTimeImmutable
    {
        $stamp = trim($stamp);

        if ($stamp === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($stamp, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    private static function zone(string $timezone): ?DateTimeZone
    {
        try {
            return new DateTimeZone($timezone);
        } catch (\Exception) {
            return null;
        }
    }
}
