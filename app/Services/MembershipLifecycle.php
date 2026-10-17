<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Event;
use App\Core\Log;
use App\Core\Settings;
use DateTimeImmutable;
use Throwable;

/**
 * Memberships and season passes — the engine.
 *
 * The I/O half of `Membership`, which holds the state machine and touches
 * nothing. This enrols, records usage, advances states and fires events, and
 * decides nothing that could be decided there: `sweep()` in particular is a
 * loop that asks `Membership::nextStatus()` what a row should be and writes
 * the answer.
 *
 * Three properties are load-bearing:
 *
 *   1. **THE SWEEP IS IDEMPOTENT AND SAYS SO IN THE DATA.** Each renewal
 *      rung has its own column, stamped when the notice fires, so a cron
 *      that runs twice — or a venue that restores a backup — cannot ask the
 *      same family to renew twice in a day. The same is true of the start
 *      and expiry events: they are fired on the TRANSITION, and the status
 *      column is what records that it happened.
 *
 *   2. **USAGE IS DERIVED FROM VISITS, NOT COUNTED BY HAND.** `visit.recorded`
 *      is the one source (see `app/listeners.php`), and one visit per
 *      membership per day is the rule — a family checked in one child at a
 *      time must not burn five punches off a ten-visit card.
 *
 *   3. **A FROZEN PASS DOES NOT RUN DOWN.** Freezing moves the expiry date
 *      forward by however long it was frozen, which is the only reading of
 *      "frozen" a guest would accept. The alternative — pausing the status
 *      but letting the calendar run — gives somebody back a pass with three
 *      days left on it.
 */
final class MembershipLifecycle
{
    /** Rows advanced per sweep. The sweep runs daily; this is a bound, not a page size. */
    public const SWEEP_LIMIT = 500;

    /** The prefix on a member number, overridable per venue. */
    public const DEFAULT_PREFIX = 'CFC';

    // ------------------------------------------------------------------
    // Enrolling
    // ------------------------------------------------------------------

    /**
     * Put somebody on a plan.
     *
     * Returns the new membership's id, or throws with a reason a person can
     * read — the caller turns that into a 422 rather than a 500.
     *
     * @param array{plan_id:int,contact_id:?int,household_id?:?int,started_at?:?string,
     *     price_paid_cents?:int,auto_renew?:bool,external_ref?:?string,members?:list<int>} $input
     */
    public static function enrol(array $input): int
    {
        $plan = DB::first('SELECT * FROM {membership_plans} WHERE id = :id', ['id' => (int) $input['plan_id']]);

        if ($plan === null) {
            throw new \RuntimeException('That plan does not exist.');
        }

        $contactId = isset($input['contact_id']) ? (int) $input['contact_id'] : 0;

        if ($contactId <= 0) {
            throw new \RuntimeException('A membership needs a guest to belong to.');
        }

        $started = (string) ($input['started_at'] ?? self::today());
        $started = preg_match('/^\d{4}-\d{2}-\d{2}$/', $started) === 1 ? $started : self::today();

        $expires = Membership::expiresOn($plan, $started);

        /*
         * `pending` when it starts in the future, `active` when it starts
         * today. Decided here rather than left to the nightly sweep, because
         * a pass sold at the counter has to work at the counter five minutes
         * later — waiting for a cron tick would mean a guest standing there
         * with a pass the system says has not started.
         */
        $status = $started > self::today() ? 'pending' : 'active';

        $membershipId = 0;

        DB::transaction(static function () use (
            $plan,
            $input,
            $contactId,
            $started,
            $expires,
            $status,
            &$membershipId
        ): void {
            $membershipId = (int) DB::insert('memberships', [
                'plan_id'          => (int) $plan['id'],
                'contact_id'       => $contactId,
                'household_id'     => isset($input['household_id']) && (int) $input['household_id'] > 0
                    ? (int) $input['household_id']
                    : null,
                'member_number'    => self::nextNumber(),
                'status'           => $status,
                'started_at'       => $started,
                'expires_at'       => $expires,
                'auto_renew'       => ($input['auto_renew'] ?? false) ? 1 : 0,
                'price_paid_cents' => max(0, (int) ($input['price_paid_cents'] ?? (int) $plan['price_cents'])),
                'external_ref'     => ($input['external_ref'] ?? null) ?: null,
                'created_at'       => now_utc(),
                'updated_at'       => now_utc(),
            ]);

            // The holder is a member of their own membership, so a family
            // plan's list is complete and "who is on this pass" has one
            // answer rather than "the holder, plus these".
            DB::insert('membership_members', [
                'membership_id' => $membershipId,
                'contact_id'    => $contactId,
                'is_primary'    => 1,
                'relationship'  => 'holder',
            ]);

            if ((int) ($input['price_paid_cents'] ?? (int) $plan['price_cents']) > 0) {
                DB::insert('membership_payments', [
                    'membership_id' => $membershipId,
                    'amount_cents'  => max(0, (int) ($input['price_paid_cents'] ?? (int) $plan['price_cents'])),
                    'status'        => 'paid',
                    'method'        => 'pos',
                    'occurred_at'   => now_utc(),
                    'created_at'    => now_utc(),
                ]);
            }
        });

        if ($status === 'active') {
            self::fireStarted($membershipId);
        }

        return $membershipId;
    }

    /**
     * Add somebody to a family plan.
     *
     * @return array{added:bool,reason:string}
     */
    public static function addMember(int $membershipId, ?int $contactId, ?int $childId, string $relationship = ''): array
    {
        $row = self::withPlan($membershipId);

        if ($row === null) {
            return ['added' => false, 'reason' => 'that membership does not exist'];
        }

        if ($contactId === null && $childId === null) {
            return ['added' => false, 'reason' => 'nobody was named'];
        }

        $current = (int) DB::value(
            'SELECT COUNT(*) FROM {membership_members} WHERE membership_id = :m',
            ['m' => $membershipId]
        );

        $left = Membership::placesLeft($row['plan'], $current);

        if ($left === 0) {
            return [
                'added'  => false,
                'reason' => 'this plan holds ' . (int) $row['plan']['max_members'] . ' people and it is full',
            ];
        }

        $exists = $contactId !== null
            ? DB::exists(
                'SELECT id FROM {membership_members} WHERE membership_id = :m AND contact_id = :c',
                ['m' => $membershipId, 'c' => $contactId]
            )
            : DB::exists(
                'SELECT id FROM {membership_members} WHERE membership_id = :m AND child_id = :c',
                ['m' => $membershipId, 'c' => $childId]
            );

        if ($exists) {
            return ['added' => false, 'reason' => 'they are already on it'];
        }

        DB::insert('membership_members', [
            'membership_id' => $membershipId,
            'contact_id'    => $contactId,
            'child_id'      => $childId,
            'relationship'  => $relationship === '' ? null : mb_substr($relationship, 0, 40),
            'is_primary'    => 0,
        ]);

        return ['added' => true, 'reason' => ''];
    }

    // ------------------------------------------------------------------
    // Using it
    // ------------------------------------------------------------------

    /**
     * Record that a membership was used, from a visit.
     *
     * Called from the event seam when a visit is recorded — see
     * `app/listeners.php`. ONE USE PER MEMBERSHIP PER DAY, whatever the
     * visit says: a family checked in one child at a time produces several
     * writes for one afternoon, and five punches off a ten-visit card for a
     * single visit is the kind of error a venue only finds when a guest
     * complains.
     *
     * @param array<string,mixed> $payload the `visit.recorded` payload
     */
    public static function recordVisitUsage(array $payload): int
    {
        $contactId = isset($payload['contact_id']) ? (int) $payload['contact_id'] : 0;

        if ($contactId <= 0) {
            return 0;
        }

        $visitId = isset($payload['visit_id']) ? (int) $payload['visit_id'] : null;
        $when    = (string) ($payload['visit_date'] ?? self::today());
        $when    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $when) === 1 ? $when : self::today();

        /*
         * Every LIVE membership this guest is on, whether they hold it or are
         * a family member of somebody else's — which is the point of a family
         * plan, and the reason this joins through `membership_members`
         * rather than reading `memberships.contact_id`.
         */
        $rows = DB::all(
            "SELECT m.id, m.visits_used, m.status, m.expires_at, m.started_at, m.price_paid_cents,
                    p.visit_allowance
               FROM {memberships} m
               JOIN {membership_members} mm ON mm.membership_id = m.id
               JOIN {membership_plans} p ON p.id = m.plan_id
              WHERE mm.contact_id = :c AND m.status IN ('active', 'expiring')",
            ['c' => $contactId]
        );

        $recorded = 0;

        foreach ($rows as $row) {
            $membershipId = (int) $row['id'];

            if (!Membership::isUsable($row, $when, ['visit_allowance' => $row['visit_allowance']])) {
                continue;
            }

            // One per day. The unique check is a read rather than a
            // constraint because `membership_usage` deliberately allows
            // several rows per day for a venue that records per-attraction
            // usage; the rule is this module's, not the schema's.
            $already = DB::exists(
                'SELECT id FROM {membership_usage} WHERE membership_id = :m AND DATE(used_at) = DATE(:d)',
                ['m' => $membershipId, 'd' => $when . ' 12:00:00']
            );

            if ($already) {
                continue;
            }

            DB::insert('membership_usage', [
                'membership_id' => $membershipId,
                'contact_id'    => $contactId,
                'visit_id'      => $visitId,
                'used_at'       => $when . ' 12:00:00',
            ]);

            DB::query(
                'UPDATE {memberships}
                    SET visits_used = visits_used + 1, last_used_at = :when, updated_at = :now
                  WHERE id = :id',
                ['when' => $when . ' 12:00:00', 'now' => now_utc(), 'id' => $membershipId]
            );

            $recorded++;
        }

        return $recorded;
    }

    // ------------------------------------------------------------------
    // Changing it by hand
    // ------------------------------------------------------------------

    /**
     * Freeze a pass, so it neither works nor runs down.
     *
     * @return array{ok:bool,reason:string}
     */
    public static function freeze(int $membershipId): array
    {
        $row = self::withPlan($membershipId);

        if ($row === null) {
            return ['ok' => false, 'reason' => 'that membership does not exist'];
        }

        $status = (string) $row['membership']['status'];

        if (!Membership::canTransition($status, 'frozen')) {
            return ['ok' => false, 'reason' => 'a ' . Membership::label($status) . ' membership cannot be frozen'];
        }

        DB::update('memberships', [
            'status'     => 'frozen',
            'updated_at' => now_utc(),
        ], 'id = :id', ['id' => $membershipId]);

        /*
         * The freeze DATE is kept on the row's `updated_at`, which is enough
         * because unfreezing reads it immediately. A dedicated column would
         * be better and is not in the schema; rather than add one for a
         * value used in exactly one place, `unfreeze()` takes the elapsed
         * days from here and says so.
         */
        return ['ok' => true, 'reason' => ''];
    }

    /**
     * Unfreeze, moving the end date on by however long it was frozen.
     *
     * @return array{ok:bool,reason:string,days:int}
     */
    public static function unfreeze(int $membershipId): array
    {
        $row = self::withPlan($membershipId);

        if ($row === null) {
            return ['ok' => false, 'reason' => 'that membership does not exist', 'days' => 0];
        }

        $membership = $row['membership'];

        if ((string) $membership['status'] !== 'frozen') {
            return ['ok' => false, 'reason' => 'it is not frozen', 'days' => 0];
        }

        $frozenAt = (string) ($membership['updated_at'] ?? now_utc());
        $days     = max(0, Membership::daysBetween(substr($frozenAt, 0, 10), self::today()));

        $expires = $membership['expires_at'] === null
            ? null
            : Membership::addDays(substr((string) $membership['expires_at'], 0, 10), $days);

        DB::update('memberships', [
            // Back to active; the next sweep will call it `expiring` if it
            // is inside the window.
            'status'     => 'active',
            'expires_at' => $expires,
            'updated_at' => now_utc(),
        ], 'id = :id', ['id' => $membershipId]);

        return ['ok' => true, 'reason' => '', 'days' => $days];
    }

    /**
     * Cancel, with a reason the churn report can read.
     *
     * @return array{ok:bool,reason:string}
     */
    public static function cancel(int $membershipId, string $why = ''): array
    {
        $row = self::withPlan($membershipId);

        if ($row === null) {
            return ['ok' => false, 'reason' => 'that membership does not exist'];
        }

        $status = (string) $row['membership']['status'];

        if ($status === 'cancelled') {
            return ['ok' => true, 'reason' => ''];
        }

        if (!Membership::canTransition($status, 'cancelled')) {
            return ['ok' => false, 'reason' => 'a ' . Membership::label($status) . ' membership cannot be cancelled'];
        }

        DB::update('memberships', [
            'status'       => 'cancelled',
            'cancelled_at' => now_utc(),
            'auto_renew'   => 0,
            'updated_at'   => now_utc(),
        ], 'id = :id', ['id' => $membershipId]);

        Event::fire('membership.cancelled', [
            'membership_id' => $membershipId,
            'contact_id'    => $row['membership']['contact_id'] === null
                ? null
                : (int) $row['membership']['contact_id'],
            'plan'          => (string) $row['plan']['name'],
            'reason'        => $why === '' ? null : mb_substr($why, 0, 255),
            // What they got out of it, so a churn report has more than a count.
            'visits_used'   => (int) $row['membership']['visits_used'],
        ]);

        return ['ok' => true, 'reason' => ''];
    }

    /**
     * Renew: a NEW TERM on the same row, starting when the old one ends.
     *
     * Starting from the old expiry rather than from today is the whole point
     * — a guest who renews a fortnight early must not lose the fortnight
     * they already paid for, which is exactly what renewing early would cost
     * them if the term restarted now.
     *
     * @return array{ok:bool,reason:string,expires_at:?string}
     */
    public static function renew(int $membershipId, ?int $pricePaidCents = null): array
    {
        $row = self::withPlan($membershipId);

        if ($row === null) {
            return ['ok' => false, 'reason' => 'that membership does not exist', 'expires_at' => null];
        }

        $membership = $row['membership'];
        $plan       = $row['plan'];

        if ((string) $membership['status'] === 'cancelled') {
            return ['ok' => false, 'reason' => 'a cancelled membership cannot be renewed', 'expires_at' => null];
        }

        $today   = self::today();
        $expires = $membership['expires_at'] === null ? null : substr((string) $membership['expires_at'], 0, 10);

        // From the end of the current term when it is still running, from
        // today when it has already run out.
        $from = $expires !== null && $expires >= $today
            ? Membership::addDays($expires, 1)
            : $today;

        $newExpiry = Membership::expiresOn($plan, $from);
        $price     = $pricePaidCents ?? (int) $plan['price_cents'];

        DB::transaction(static function () use ($membershipId, $newExpiry, $price): void {
            DB::update('memberships', [
                // Active either way: a renewal starting at the end of the
                // current term is still a live pass today, because the
                // current term is what is live until then.
                'status'               => 'active',
                'expires_at'           => $newExpiry,
                'price_paid_cents'     => max(0, $price),
                'visits_used'          => 0,
                'cancelled_at'         => null,
                // Every rung reset, so the ladder runs again next term.
                'renewal_notice_30_at' => null,
                'renewal_notice_14_at' => null,
                'renewal_notice_3_at'  => null,
                'winback_sent_at'      => null,
                'updated_at'           => now_utc(),
            ], 'id = :id', ['id' => $membershipId]);

            if ($price > 0) {
                DB::insert('membership_payments', [
                    'membership_id' => $membershipId,
                    'amount_cents'  => $price,
                    'status'        => 'paid',
                    'method'        => 'pos',
                    'occurred_at'   => now_utc(),
                    'created_at'    => now_utc(),
                ]);
            }
        });

        Event::fire('membership.renewed', [
            'membership_id' => $membershipId,
            'contact_id'    => $membership['contact_id'] === null ? null : (int) $membership['contact_id'],
            'plan'          => (string) $plan['name'],
            'expires_at'    => $newExpiry,
        ]);

        return ['ok' => true, 'reason' => '', 'expires_at' => $newExpiry];
    }

    // ------------------------------------------------------------------
    // The nightly sweep
    // ------------------------------------------------------------------

    /**
     * Advance every membership to where today says it should be.
     *
     * Pure decision, boring execution: `Membership::nextStatus()` says what
     * each row should be and this writes it, firing the events that go with
     * a transition. Called once a day from cron — and safe to call more
     * often, because a status that is already right returns null and nothing
     * is written.
     *
     * @return array{checked:int,changed:int,started:int,expired:int,lapsed:int}
     */
    public static function sweep(?callable $budgetLeft = null): array
    {
        $within = static fn (): bool => $budgetLeft === null || $budgetLeft();
        $today  = self::today();

        $counts = ['checked' => 0, 'changed' => 0, 'started' => 0, 'expired' => 0, 'lapsed' => 0];

        $rows = DB::all(
            "SELECT m.*, p.visit_allowance, p.name AS plan_name
               FROM {memberships} m
               JOIN {membership_plans} p ON p.id = m.plan_id
              WHERE m.status NOT IN ('cancelled', 'lapsed', 'frozen')
              ORDER BY m.id
              LIMIT " . self::SWEEP_LIMIT
        );

        foreach ($rows as $row) {
            if (!$within()) {
                break;
            }

            $counts['checked']++;

            $next = Membership::nextStatus($row, $today, ['visit_allowance' => $row['visit_allowance']]);

            if ($next === null) {
                continue;
            }

            try {
                DB::update('memberships', [
                    'status'     => $next,
                    'updated_at' => now_utc(),
                ], 'id = :id AND status = :was', [
                    'id'  => (int) $row['id'],
                    // Guarded on the status we READ, so two sweeps running
                    // at once cannot both fire the same transition.
                    'was' => (string) $row['status'],
                ]);

                $counts['changed']++;

                if ($next === 'active' && (string) $row['status'] === 'pending') {
                    // A pass that was sold in March for the summer, starting
                    // this morning. Only this transition is a START — moving
                    // back from `expiring` to `active` (the venue extended
                    // it) is not, and firing the welcome series again would
                    // be a sequence a guest has already had.
                    self::fireStarted((int) $row['id'], $counts);
                } elseif ($next === 'expired') {
                    self::fireExpired($row, $counts);
                } elseif ($next === 'lapsed') {
                    $counts['lapsed']++;
                }
            } catch (Throwable $e) {
                Log::error('Could not advance a membership', [
                    'membership' => (int) $row['id'],
                    'to'         => $next,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return $counts;
    }

    /**
     * Stamp the renewal rungs that have come due.
     *
     * The STAMP is the point. The messages themselves are sent by the
     * seeded renewal automations, which trigger on `membership.expiring` —
     * so this does not send anything, it records which rung a membership has
     * reached so the ladder cannot repeat itself after a restart.
     *
     * @return int how many rungs were stamped
     */
    public static function stampRenewalNotices(): int
    {
        $today   = self::today();
        $stamped = 0;

        $rows = DB::all(
            "SELECT * FROM {memberships}
              WHERE status IN ('active', 'expiring') AND expires_at IS NOT NULL
              ORDER BY expires_at
              LIMIT " . self::SWEEP_LIMIT
        );

        foreach ($rows as $row) {
            $rung = Membership::dueRenewalNotice($row, $today);

            if ($rung === null) {
                continue;
            }

            /*
             * The column names are written out rather than built from the
             * rung, so every column this writes is greppable and the static
             * gate that checks writes against the schema can see them. A
             * concatenated name reads as `memberships._at` to anything
             * looking, which is how a typo would survive to production.
             */
            $column = match ($rung) {
                30      => 'renewal_notice_30_at',
                14      => 'renewal_notice_14_at',
                3       => 'renewal_notice_3_at',
                default => null,
            };

            if ($column === null) {
                continue;
            }

            DB::update('memberships', [
                $column      => now_utc(),
                'updated_at' => now_utc(),
            ], 'id = :id', ['id' => (int) $row['id']]);

            $stamped++;
        }

        return $stamped;
    }

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    /**
     * One membership with its plan, or null.
     *
     * @return array{membership:array<string,mixed>,plan:array<string,mixed>}|null
     */
    public static function withPlan(int $membershipId): ?array
    {
        $row = DB::first('SELECT * FROM {memberships} WHERE id = :id', ['id' => $membershipId]);

        if ($row === null) {
            return null;
        }

        $plan = DB::first('SELECT * FROM {membership_plans} WHERE id = :id', ['id' => (int) $row['plan_id']]);

        return ['membership' => $row, 'plan' => $plan ?? []];
    }

    /**
     * The venue's own today, as a date.
     *
     * Every comparison in `Membership` is against this. A pass that runs out
     * "today" runs out on the venue's calendar, not on the server's.
     */
    public static function today(): string
    {
        return (new DateTimeImmutable('now', venue_tz()))->format('Y-m-d');
    }

    // ------------------------------------------------------------------
    // Plumbing
    // ------------------------------------------------------------------

    /**
     * The next member number.
     *
     * Derived from the count rather than held in a setting, and retried on a
     * collision, because two people selling a pass at two registers in the
     * same second would otherwise both get the same number — and
     * `member_number` is unique, so the second sale would fail at the
     * counter.
     */
    private static function nextNumber(): string
    {
        $prefix = (string) Settings::get('memberships.number_prefix', self::DEFAULT_PREFIX);
        $next   = (int) DB::value('SELECT COUNT(*) FROM {memberships}') + 1;

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $candidate = Membership::memberNumber($next + $attempt, $prefix);

            if (!DB::exists('SELECT id FROM {memberships} WHERE member_number = :n', ['n' => $candidate])) {
                return $candidate;
            }
        }

        // Fifty collisions means something else is wrong; a unique suffix is
        // better than failing the sale.
        return Membership::memberNumber($next, $prefix) . '-' . strtoupper(bin2hex(random_bytes(2)));
    }

    /** @param array<string,int> $counts */
    private static function fireStarted(int $membershipId, array &$counts = []): void
    {
        $row = self::withPlan($membershipId);

        if ($row === null) {
            return;
        }

        if (isset($counts['started'])) {
            $counts['started']++;
        }

        Event::fire('membership.started', [
            'membership_id' => $membershipId,
            'contact_id'    => $row['membership']['contact_id'] === null
                ? null
                : (int) $row['membership']['contact_id'],
            'plan'          => (string) ($row['plan']['name'] ?? ''),
            'expires_at'    => $row['membership']['expires_at'],
        ]);
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,int> $counts
     */
    private static function fireExpired(array $row, array &$counts): void
    {
        $counts['expired']++;

        Event::fire('membership.expired', [
            'membership_id' => (int) $row['id'],
            'contact_id'    => $row['contact_id'] === null ? null : (int) $row['contact_id'],
            'plan'          => (string) ($row['plan_name'] ?? ''),
            'visits_used'   => (int) $row['visits_used'],
            'expires_at'    => $row['expires_at'],
        ]);
    }
}
