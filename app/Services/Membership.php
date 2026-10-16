<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The membership lifecycle — the pure half.
 *
 * No database, no clock: every answer comes from the arguments, and "today"
 * is passed in as a venue-local date. That matters more here than almost
 * anywhere else in this application, because this class decides when
 * somebody's pass STOPS WORKING and when a venue asks them for money again.
 * Both are wrong by a whole day if the comparison happens in UTC: the Castle
 * is four or five hours behind, so from about eight in the evening the UTC
 * date has already rolled over, and a pass that runs out "tomorrow" would be
 * refused at the counter tonight.
 *
 * The state machine from spec §8.10:
 *
 *   pending → active → expiring(T-30) → expired → lapsed(+60d) → [winback]
 *                    ↘ cancelled       ↘ frozen → active
 *
 * `nextStatus()` is the whole of it, and the sweep in `Memberships` does
 * nothing but apply what this returns. So the machine can be exercised over
 * every date in a year without a database, which is the only way anybody
 * would ever find the off-by-one in the lapse window.
 */
final class Membership
{
    /** Every state a membership can be in. Matches the `memberships` enum. */
    public const STATUSES = ['pending', 'active', 'expiring', 'expired', 'cancelled', 'lapsed', 'frozen'];

    /** States where the pass works at the counter today. */
    public const USABLE = ['active', 'expiring'];

    /** States nothing moves out of on its own. */
    public const TERMINAL = ['cancelled', 'lapsed'];

    /** How long before expiry a membership starts being called "expiring". */
    public const EXPIRING_WINDOW_DAYS = 30;

    /** How long after expiry it is written off as lapsed. */
    public const LAPSE_AFTER_DAYS = 60;

    /**
     * The renewal ladder, in days before expiry.
     *
     * Three touches and no more. The stored column per rung
     * (`renewal_notice_30_at` and friends) is what stops a restarted job
     * sending the same notice twice, so adding a rung means adding a column.
     */
    public const RENEWAL_NOTICES = [30, 14, 3];

    /** The win-back ladder, in days AFTER expiry. */
    public const WINBACK_DAYS = [14, 45, 90];

    /** Plan shapes. `memberships.type` on the plan, not on the membership. */
    public const PLAN_TYPES = ['season_pass', 'membership', 'multi_visit'];

    // ------------------------------------------------------------------
    // The machine
    // ------------------------------------------------------------------

    /**
     * What this membership should be TODAY, or null to leave it alone.
     *
     * Deliberately returns null rather than the current status when nothing
     * should change, so the caller writes only when there is something to
     * write — and so "no transition" and "a transition back to where it
     * already was" cannot be confused.
     *
     * @param array<string,mixed> $membership
     * @param array<string,mixed> $plan the plan row, for the visit allowance
     */
    public static function nextStatus(array $membership, string $today, array $plan = []): ?string
    {
        $status  = (string) ($membership['status'] ?? '');
        $starts  = self::date($membership['started_at'] ?? null);
        $expires = self::date($membership['expires_at'] ?? null);

        // Nothing moves a cancelled or lapsed membership. A guest who comes
        // back buys a new one; reviving the old row would lose the fact that
        // they left.
        if (in_array($status, self::TERMINAL, true)) {
            return null;
        }

        // A frozen pass is frozen BY SOMEBODY, and only somebody unfreezes
        // it. Expiry does not run while it is frozen either — that is what
        // freezing is for (an injury, a deployment, a long holiday).
        if ($status === 'frozen') {
            return null;
        }

        if ($status === 'pending') {
            // Not started yet: a pass bought in March for the summer.
            if ($starts === null || $starts > $today) {
                return null;
            }

            return 'active';
        }

        // A punch card is used up rather than timed out, and that is its own
        // kind of ending: the guest has had everything they paid for.
        $allowance = (int) ($plan['visit_allowance'] ?? 0);

        if ($allowance > 0 && (int) ($membership['visits_used'] ?? 0) >= $allowance) {
            return $status === 'expired' ? null : 'expired';
        }

        if ($expires === null) {
            // An open-ended membership (a monthly subscription with no end
            // date) stays active until somebody cancels it.
            return $status === 'active' ? null : 'active';
        }

        if ($expires < $today) {
            $lapsedOn = self::addDays($expires, self::LAPSE_AFTER_DAYS);

            if ($today > $lapsedOn) {
                return $status === 'lapsed' ? null : 'lapsed';
            }

            return $status === 'expired' ? null : 'expired';
        }

        // Still live. Inside the window it is "expiring", which is what the
        // renewal queue and the ladder read.
        $wanted = self::daysBetween($today, $expires) <= self::EXPIRING_WINDOW_DAYS
            ? 'expiring'
            : 'active';

        return $status === $wanted ? null : $wanted;
    }

    /**
     * Is this a change a person is allowed to make by hand?
     *
     * The sweep uses `nextStatus()`; this is for the buttons. Freezing and
     * cancelling are decisions, and so is un-freezing; everything else is the
     * calendar's business.
     */
    public static function canTransition(string $from, string $to): bool
    {
        $allowed = [
            'pending'   => ['active', 'cancelled'],
            'active'    => ['frozen', 'cancelled'],
            'expiring'  => ['frozen', 'cancelled'],
            'frozen'    => ['active', 'cancelled'],
            'expired'   => ['cancelled'],
            // Nothing by hand: see nextStatus().
            'cancelled' => [],
            'lapsed'    => [],
        ];

        return in_array($to, $allowed[$from] ?? [], true);
    }

    /** Does the pass work at the counter today? */
    public static function isUsable(array $membership, string $today, array $plan = []): bool
    {
        if (!in_array((string) ($membership['status'] ?? ''), self::USABLE, true)) {
            return false;
        }

        $expires = self::date($membership['expires_at'] ?? null);

        if ($expires !== null && $expires < $today) {
            return false;
        }

        $allowance = (int) ($plan['visit_allowance'] ?? 0);

        return $allowance <= 0 || (int) ($membership['visits_used'] ?? 0) < $allowance;
    }

    // ------------------------------------------------------------------
    // Dates
    // ------------------------------------------------------------------

    /**
     * When a term that starts on this date runs out.
     *
     * Null for a plan with no duration — a monthly subscription runs until
     * it is cancelled, and inventing an expiry date for it would start the
     * renewal ladder against a date nobody agreed to.
     *
     * The term is INCLUSIVE of its first day: a 365-day pass bought on
     * 1 January runs to 31 December, not to 1 January next year. The
     * off-by-one matters because it is the date printed on the card.
     *
     * @param array<string,mixed> $plan
     */
    public static function expiresOn(array $plan, string $startDate): ?string
    {
        $days = (int) ($plan['duration_days'] ?? 0);

        if ($days <= 0) {
            return null;
        }

        return self::addDays($startDate, $days - 1);
    }

    /** Whole days from one date to another. Negative when `$to` is past. */
    public static function daysBetween(string $from, string $to): int
    {
        $a = strtotime($from . ' 00:00:00 UTC');
        $b = strtotime($to . ' 00:00:00 UTC');

        if ($a === false || $b === false) {
            return 0;
        }

        // Both normalised to UTC midnight first, deliberately: subtracting
        // two local wall-clock times across a daylight-saving boundary is
        // 23 or 25 hours, and integer division then loses or gains a day.
        return (int) round(($b - $a) / 86400);
    }

    public static function addDays(string $date, int $days): string
    {
        $time = strtotime($date . ' 00:00:00 UTC');

        if ($time === false) {
            return $date;
        }

        return gmdate('Y-m-d', $time + ($days * 86400));
    }

    /** Days until this membership runs out. Null when it has no end date. */
    public static function daysLeft(array $membership, string $today): ?int
    {
        $expires = self::date($membership['expires_at'] ?? null);

        return $expires === null ? null : self::daysBetween($today, $expires);
    }

    // ------------------------------------------------------------------
    // The ladders
    // ------------------------------------------------------------------

    /**
     * Which renewal notice is due today, or null.
     *
     * Returns the RUNG (30, 14 or 3), so the caller can stamp the matching
     * column. "Due" means on or past that many days out and not yet sent —
     * past, not exactly on, because a cron that did not run on the day must
     * still send it rather than skipping the rung for ever.
     *
     * Nothing is due for a membership that is not live, has no end date, or
     * has already run out.
     *
     * @param array<string,mixed> $membership
     */
    public static function dueRenewalNotice(array $membership, string $today): ?int
    {
        if (!in_array((string) ($membership['status'] ?? ''), self::USABLE, true)) {
            return null;
        }

        $left = self::daysLeft($membership, $today);

        if ($left === null || $left < 0) {
            return null;
        }

        // Smallest rung first, so a job that has been down for a fortnight
        // sends the one that is actually relevant rather than working
        // through the backlog of notices the guest no longer needs.
        $rungs = self::RENEWAL_NOTICES;
        sort($rungs);

        foreach ($rungs as $rung) {
            if ($left <= $rung && ($membership['renewal_notice_' . $rung . '_at'] ?? null) === null) {
                return $rung;
            }
        }

        return null;
    }

    /**
     * Which win-back touch is due, or null.
     *
     * One column (`winback_sent_at`) covers all three rungs, so this returns
     * the rung whose window the membership is in and the caller decides
     * whether the stamp is old enough — see `winbackIsDue()`.
     *
     * @param array<string,mixed> $membership
     */
    public static function winbackRung(array $membership, string $today): ?int
    {
        if (!in_array((string) ($membership['status'] ?? ''), ['expired', 'lapsed'], true)) {
            return null;
        }

        $expires = self::date($membership['expires_at'] ?? null);

        if ($expires === null) {
            return null;
        }

        $since = self::daysBetween($expires, $today);

        $rung = null;

        foreach (self::WINBACK_DAYS as $day) {
            if ($since >= $day) {
                $rung = $day;
            }
        }

        return $rung;
    }

    /**
     * Is a win-back touch due now?
     *
     * The last touch is 90 days out and then it STOPS. Asking a fourth time
     * costs more goodwill than it recovers, and a venue that keeps emailing
     * somebody who left a year ago is the venue people mark as spam.
     *
     * @param array<string,mixed> $membership
     */
    public static function winbackIsDue(array $membership, string $today): bool
    {
        $rung = self::winbackRung($membership, $today);

        if ($rung === null) {
            return false;
        }

        $sent = self::date($membership['winback_sent_at'] ?? null);

        if ($sent === null) {
            return true;
        }

        $expires = self::date($membership['expires_at'] ?? null);

        if ($expires === null) {
            return false;
        }

        // Due only if the LAST touch was before this rung's own day, so three
        // rungs produce three messages however often the sweep runs.
        return $sent < self::addDays($expires, $rung);
    }

    // ------------------------------------------------------------------
    // What it was worth
    // ------------------------------------------------------------------

    /**
     * What each visit on this pass has cost the guest, in cents.
     *
     * ACTUALS ONLY (the standing product rule): this is two recorded figures
     * divided, not a projection of what the pass "will" be worth. Null until
     * they have used it at all — a cost per visit of "the whole price"
     * because they have been once this week is a true number that tells
     * nobody anything useful.
     */
    public static function costPerVisit(int $pricePaidCents, int $visits): ?int
    {
        if ($visits <= 0 || $pricePaidCents <= 0) {
            return null;
        }

        return intdiv($pricePaidCents, $visits);
    }

    /**
     * How a pass is being used, as facts rather than a forecast.
     *
     * @param array<string,mixed> $membership
     * @param array<string,mixed> $plan
     * @return array{visits:int,days_held:?int,visits_left:?int,cost_per_visit:?int,
     *     visits_per_month:?float,allowance:?int}
     */
    public static function usage(array $membership, array $plan, string $today): array
    {
        $visits  = (int) ($membership['visits_used'] ?? 0);
        $started = self::date($membership['started_at'] ?? null);
        $days    = $started === null ? null : max(0, self::daysBetween($started, $today));

        $allowance = (int) ($plan['visit_allowance'] ?? 0);

        return [
            'visits'           => $visits,
            'days_held'        => $days,
            'allowance'        => $allowance > 0 ? $allowance : null,
            'visits_left'      => $allowance > 0 ? max(0, $allowance - $visits) : null,
            'cost_per_visit'   => self::costPerVisit((int) ($membership['price_paid_cents'] ?? 0), $visits),
            // A rate over the time they have actually held it. Null in the
            // first week, where one visit would read as four a month.
            'visits_per_month' => $days !== null && $days >= 28 && $visits > 0
                ? round($visits / ($days / 30), 1)
                : null,
        ];
    }

    /**
     * How many more people can go on this plan.
     *
     * Zero is a real answer and means the plan is full — the caller must not
     * read it as "no limit", which is null.
     *
     * A BLANK LIMIT MEANS ONE PERSON unless the plan is marked as a family
     * plan. That is the reading the plans page and the plan form both state
     * ("Blank means one — the holder"), and it is the safe one: the common
     * case is a single-person season pass, and treating a blank as unlimited
     * would let a counter quietly add five people to a pass sold for one.
     * A venue that wants an unlimited plan ticks "family plan".
     *
     * @param array<string,mixed> $plan
     */
    public static function placesLeft(array $plan, int $currentMembers): ?int
    {
        $max = (int) ($plan['max_members'] ?? 0);

        if ($max > 0) {
            return max(0, $max - $currentMembers);
        }

        if ((int) ($plan['is_family_plan'] ?? 0) === 1) {
            return null;
        }

        return max(0, 1 - $currentMembers);
    }

    // ------------------------------------------------------------------
    // Words
    // ------------------------------------------------------------------

    public static function label(string $status): string
    {
        return match ($status) {
            'pending'   => 'Not started yet',
            'active'    => 'Active',
            'expiring'  => 'Expiring soon',
            'expired'   => 'Expired',
            'cancelled' => 'Cancelled',
            'lapsed'    => 'Lapsed',
            'frozen'    => 'Frozen',
            default     => ucfirst($status),
        };
    }

    /** The badge class a status renders with, so the pages agree. */
    public static function badge(string $status): string
    {
        return match ($status) {
            'active'            => 'badge-ok',
            'expiring'          => 'badge-warn',
            'expired', 'lapsed' => 'badge-bad',
            default             => 'badge-muted',
        };
    }

    /**
     * One sentence about where this membership stands.
     *
     * In the venue's words rather than the schema's, and specific about
     * dates, because "expiring soon" on its own sends somebody to look up
     * what soon means.
     *
     * @param array<string,mixed> $membership
     */
    public static function describe(array $membership, string $today, array $plan = []): string
    {
        $status = (string) ($membership['status'] ?? '');
        $left   = self::daysLeft($membership, $today);
        $usage  = self::usage($membership, $plan, $today);

        if ($status === 'pending') {
            $starts = self::date($membership['started_at'] ?? null);

            return $starts === null
                ? 'Not started yet, with no start date set.'
                : 'Starts on ' . $starts . '.';
        }

        if ($status === 'frozen') {
            return 'Frozen. It does not run down and it does not work at the counter until '
                . 'somebody unfreezes it.';
        }

        if ($status === 'cancelled') {
            return 'Cancelled.';
        }

        if ($usage['visits_left'] === 0 && $usage['allowance'] !== null) {
            return 'All ' . $usage['allowance'] . ' visits have been used.';
        }

        if ($status === 'lapsed') {
            return 'Lapsed. It ran out more than ' . self::LAPSE_AFTER_DAYS . ' days ago.';
        }

        if ($status === 'expired') {
            return $left === null
                ? 'Expired.'
                : 'Expired ' . abs($left) . ' ' . (abs($left) === 1 ? 'day' : 'days') . ' ago.';
        }

        if ($left === null) {
            return 'Active, with no end date — it runs until somebody cancels it.';
        }

        if ($left === 0) {
            return 'The last day is today.';
        }

        return 'Active, ' . $left . ' ' . ($left === 1 ? 'day' : 'days') . ' left.';
    }

    // ------------------------------------------------------------------
    // Plumbing
    // ------------------------------------------------------------------

    /**
     * A membership number: a readable prefix and a zero-padded sequence.
     *
     * Readable because it is said down a telephone and read off a card:
     * "CFC-000412" survives both, where a bare integer does not.
     */
    public static function memberNumber(int $sequence, string $prefix = 'M'): string
    {
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $prefix) ?? '');
        $prefix = $prefix === '' ? 'M' : substr($prefix, 0, 6);

        return $prefix . '-' . str_pad((string) max(1, $sequence), 6, '0', STR_PAD_LEFT);
    }

    /** A date column as `Y-m-d`, or null. Accepts a datetime and drops its time. */
    private static function date(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return substr(trim($value), 0, 10);
    }
}
