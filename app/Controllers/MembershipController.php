<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Acl;
use App\Core\Audit;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\Membership;
use App\Services\MembershipLifecycle;
use RuntimeException;

/**
 * Memberships and season passes — the venue's pages.
 *
 * The rules are `Services\Membership` (pure: the state machine, the ladders,
 * the dates) and the engine is `Services\MembershipLifecycle` (enrol, use,
 * freeze, renew, sweep). This renders and validates.
 *
 * Two permissions, and the split is the point: `memberships_manage` sells a
 * pass and cancels one, which is counter work; `plans_manage` decides what a
 * pass COSTS and what it includes, which is not. A front-desk role that can
 * enrol a family must not be able to invent a twelve-pound season pass.
 */
final class MembershipController extends Controller
{
    public const MANAGE = 'memberships_manage';

    public const PLANS = 'plans_manage';

    /** The lists the page offers, in the order it offers them. */
    public const VIEWS = [
        'live'     => 'Live',
        'renewals' => 'Up for renewal',
        'idle'     => 'Not being used',
        'lapsed'   => 'Lapsed',
        'all'      => 'All',
    ];

    /** A pass unused for this long is worth a nudge. */
    private const IDLE_DAYS = 35;

    // =================================================================
    // Reading
    // =================================================================

    public function index(Request $request): Response
    {
        $view  = $request->str('show', 'live');
        $view  = isset(self::VIEWS[$view]) ? $view : 'live';
        $page  = $this->pagination($request, 50, 200);
        $today = MembershipLifecycle::today();

        [$clauses, $params] = $this->filter($request, $view, $today);

        /*
         * THE SAME JOINS AS THE ROWS QUERY BELOW, including the contact —
         * the search clause names `c.first_name`, and a count query without
         * that join is an "unknown column" 500 the moment anybody types in
         * the search box. (It was: the page rendered fine until the first
         * search.)
         */
        $total = (int) DB::value(
            'SELECT COUNT(*) FROM {memberships} m
               JOIN {membership_plans} p ON p.id = m.plan_id
               LEFT JOIN {contacts} c ON c.id = m.contact_id
              WHERE ' . $clauses,
            $params
        );

        $rows = DB::all(
            "SELECT m.*, p.name AS plan_name, p.type AS plan_type, p.visit_allowance,
                    p.max_members, p.is_family_plan,
                    c.first_name, c.last_name, c.email, c.phone,
                    (SELECT COUNT(*) FROM {membership_members} mm WHERE mm.membership_id = m.id) AS member_count
               FROM {memberships} m
               JOIN {membership_plans} p ON p.id = m.plan_id
               LEFT JOIN {contacts} c ON c.id = m.contact_id
              WHERE " . $clauses . '
              ORDER BY ' . $this->order($view) . '
              LIMIT ' . $page['perPage'] . ' OFFSET ' . $page['offset'],
            $params
        );

        return $this->view('memberships/index', [
            'pageTitle' => 'Memberships',
            'rows'      => $rows,
            'total'     => $total,
            'page'      => $page,
            'view'      => $view,
            'views'     => self::VIEWS,
            'counts'    => $this->counts($today),
            'today'     => $today,
            'plans'     => $this->planRows(),
            'canManage' => Acl::can(self::MANAGE),
            'canPlans'  => Acl::can(self::PLANS),
        ]);
    }

    public function show(Request $request): Response
    {
        $id  = $request->routeInt('id');
        $row = MembershipLifecycle::withPlan($id);

        if ($row === null) {
            return $this->notFound('That membership does not exist.');
        }

        $today = MembershipLifecycle::today();

        return $this->view('memberships/show', [
            'pageTitle'  => 'Membership',
            'membership' => $row['membership'],
            'plan'       => $row['plan'],
            'holder'     => DB::first(
                'SELECT id, first_name, last_name, email, phone FROM {contacts} WHERE id = :id',
                ['id' => (int) ($row['membership']['contact_id'] ?? 0)]
            ),
            'members'    => DB::all(
                // `children` carries a first name only — a child's surname is
                // their guardian's, and storing it twice is how the two
                // drift apart.
                "SELECT mm.*, c.first_name, c.last_name, ch.first_name AS child_first
                   FROM {membership_members} mm
                   LEFT JOIN {contacts} c ON c.id = mm.contact_id
                   LEFT JOIN {children} ch ON ch.id = mm.child_id
                  WHERE mm.membership_id = :m
                  ORDER BY mm.is_primary DESC, mm.id",
                ['m' => $id]
            ),
            'usage'      => DB::all(
                'SELECT * FROM {membership_usage} WHERE membership_id = :m ORDER BY used_at DESC LIMIT 60',
                ['m' => $id]
            ),
            'payments'   => DB::all(
                'SELECT * FROM {membership_payments} WHERE membership_id = :m ORDER BY occurred_at DESC',
                ['m' => $id]
            ),
            'summary'    => Membership::usage($row['membership'], $row['plan'], $today),
            'standing'   => Membership::describe($row['membership'], $today, $row['plan']),
            'daysLeft'   => Membership::daysLeft($row['membership'], $today),
            'placesLeft' => Membership::placesLeft($row['plan'], (int) DB::value(
                'SELECT COUNT(*) FROM {membership_members} WHERE membership_id = :m',
                ['m' => $id]
            )),
            'today'      => $today,
            'canManage'  => Acl::can(self::MANAGE),
        ]);
    }

    /** The plan catalogue: what a venue sells. */
    public function plans(Request $request): Response
    {
        return $this->view('memberships/plans', [
            'pageTitle' => 'Membership plans',
            'plans'     => $this->planRows(true),
            'types'     => Membership::PLAN_TYPES,
            'canPlans'  => Acl::can(self::PLANS),
        ]);
    }

    // =================================================================
    // Writing
    // =================================================================

    public function store(Request $request): Response
    {
        if ($denied = $this->authorize(self::MANAGE)) {
            return $denied;
        }

        $result = $this->validate($request, [
            'plan_id'    => 'required|int|min:1',
            'contact_id' => 'required|int|min:1',
            'started_at' => 'nullable|date_format:Y-m-d',
            'price'      => 'nullable|numeric',
        ], [
            'plan_id'    => 'Plan',
            'contact_id' => 'Guest',
            'started_at' => 'Start date',
            'price'      => 'Price paid',
        ]);

        if ($result instanceof Response) {
            return $result;
        }

        try {
            $membershipId = MembershipLifecycle::enrol([
                'plan_id'          => (int) $result['plan_id'],
                'contact_id'       => (int) $result['contact_id'],
                'started_at'       => ($result['started_at'] ?? '') === ''
                    ? null
                    : (string) $result['started_at'],
                'price_paid_cents' => $request->has('price') && trim($request->str('price')) !== ''
                    ? (int) round((float) $request->str('price') * 100)
                    : null,
                'auto_renew'       => $request->bool('auto_renew'),
            ]);
        } catch (RuntimeException $e) {
            return $this->refuse($request, $e->getMessage(), '/memberships');
        }

        Audit::record('membership_created', 'membership', $membershipId);

        return $this->success($request, 'Membership created.', '/memberships/' . $membershipId);
    }

    public function addMember(Request $request): Response
    {
        if ($denied = $this->authorize(self::MANAGE)) {
            return $denied;
        }

        $id = $request->routeInt('id');

        $result = MembershipLifecycle::addMember(
            $id,
            $request->int('contact_id') > 0 ? $request->int('contact_id') : null,
            $request->int('child_id') > 0 ? $request->int('child_id') : null,
            $request->str('relationship')
        );

        if (!$result['added']) {
            return $this->refuse($request, 'Not added: ' . $result['reason'] . '.', '/memberships/' . $id);
        }

        Audit::record('membership_member_added', 'membership', $id);

        return $this->success($request, 'Added to the membership.', '/memberships/' . $id);
    }

    public function removeMember(Request $request): Response
    {
        if ($denied = $this->authorize(self::MANAGE)) {
            return $denied;
        }

        $id       = $request->routeInt('id');
        $memberId = $request->int('member_id');

        // The holder stays: a membership with nobody on it belongs to nobody,
        // and the holder is who the renewal notice goes to.
        $removed = DB::delete(
            'membership_members',
            'id = :id AND membership_id = :m AND is_primary = 0',
            ['id' => $memberId, 'm' => $id]
        );

        if ($removed === 0) {
            return $this->refuse($request, 'The holder cannot be taken off their own membership.', '/memberships/' . $id);
        }

        Audit::record('membership_member_removed', 'membership', $id);

        return $this->success($request, 'Taken off the membership.', '/memberships/' . $id);
    }

    public function freeze(Request $request): Response
    {
        if ($denied = $this->authorize(self::MANAGE)) {
            return $denied;
        }

        $id     = $request->routeInt('id');
        $result = MembershipLifecycle::freeze($id);

        if (!$result['ok']) {
            return $this->refuse($request, ucfirst($result['reason']) . '.', '/memberships/' . $id);
        }

        Audit::record('membership_frozen', 'membership', $id);

        return $this->success(
            $request,
            'Frozen. It does not run down while it is frozen, and the end date moves on when you '
                . 'unfreeze it.',
            '/memberships/' . $id
        );
    }

    public function unfreeze(Request $request): Response
    {
        if ($denied = $this->authorize(self::MANAGE)) {
            return $denied;
        }

        $id     = $request->routeInt('id');
        $result = MembershipLifecycle::unfreeze($id);

        if (!$result['ok']) {
            return $this->refuse($request, ucfirst($result['reason']) . '.', '/memberships/' . $id);
        }

        Audit::record('membership_unfrozen', 'membership', $id, null, ['days' => $result['days']]);

        return $this->success(
            $request,
            $result['days'] > 0
                ? 'Unfrozen, and the end date moved on by ' . $result['days']
                    . ' ' . ($result['days'] === 1 ? 'day' : 'days') . '.'
                : 'Unfrozen.',
            '/memberships/' . $id
        );
    }

    public function cancel(Request $request): Response
    {
        if ($denied = $this->authorize(self::MANAGE)) {
            return $denied;
        }

        $id     = $request->routeInt('id');
        $result = MembershipLifecycle::cancel($id, $request->str('reason'));

        if (!$result['ok']) {
            return $this->refuse($request, ucfirst($result['reason']) . '.', '/memberships/' . $id);
        }

        Audit::record('membership_cancelled', 'membership', $id, null, [
            'reason' => $request->str('reason'),
        ]);

        return $this->success($request, 'Cancelled.', '/memberships/' . $id);
    }

    public function renew(Request $request): Response
    {
        if ($denied = $this->authorize(self::MANAGE)) {
            return $denied;
        }

        $id = $request->routeInt('id');

        $price = trim($request->str('price')) === ''
            ? null
            : (int) round((float) $request->str('price') * 100);

        $result = MembershipLifecycle::renew($id, $price);

        if (!$result['ok']) {
            return $this->refuse($request, ucfirst($result['reason']) . '.', '/memberships/' . $id);
        }

        Audit::record('membership_renewed', 'membership', $id, null, [
            'expires_at' => $result['expires_at'],
        ]);

        return $this->success(
            $request,
            $result['expires_at'] === null
                ? 'Renewed. It has no end date, so it runs until somebody cancels it.'
                : 'Renewed to ' . date('M j, Y', (int) strtotime($result['expires_at'])) . '.',
            '/memberships/' . $id
        );
    }

    /** Create or edit a plan. */
    public function savePlan(Request $request): Response
    {
        if ($denied = $this->authorize(self::PLANS)) {
            return $denied;
        }

        $result = $this->validate($request, [
            'name'            => 'required|string|max:120',
            'type'            => 'required|in:' . implode(',', Membership::PLAN_TYPES),
            'price'           => 'required|numeric',
            'duration_days'   => 'nullable|int|min:0|max:3650',
            'visit_allowance' => 'nullable|int|min:0|max:999',
            'max_members'     => 'nullable|int|min:0|max:50',
        ], [
            'name'            => 'Name',
            'type'            => 'Kind of pass',
            'price'           => 'Price',
            'duration_days'   => 'Length in days',
            'visit_allowance' => 'Visits included',
            'max_members'     => 'People covered',
        ]);

        if ($result instanceof Response) {
            return $result;
        }

        $id   = $request->routeInt('id');
        $slug = $this->planSlug((string) $result['name'], $id);

        $columns = [
            'name'            => (string) $result['name'],
            'slug'            => $slug,
            'type'            => (string) $result['type'],
            'price_cents'     => max(0, (int) round((float) $result['price'] * 100)),
            'duration_days'   => ($result['duration_days'] ?? '') === ''
                ? null
                : max(0, (int) $result['duration_days']),
            'visit_allowance' => ($result['visit_allowance'] ?? '') === ''
                ? null
                : max(0, (int) $result['visit_allowance']),
            'max_members'     => ($result['max_members'] ?? '') === ''
                ? null
                : max(0, (int) $result['max_members']),
            'is_family_plan'  => $request->bool('is_family_plan') ? 1 : 0,
            'is_active'       => $request->bool('is_active') ? 1 : 0,
            'updated_at'      => now_utc(),
        ];

        if ($id > 0) {
            $before = DB::first('SELECT * FROM {membership_plans} WHERE id = :id', ['id' => $id]);

            if ($before === null) {
                return $this->notFound('That plan does not exist.');
            }

            DB::update('membership_plans', $columns, 'id = :id', ['id' => $id]);
            Audit::record('membership_plan_updated', 'membership_plan', $id, $before, $columns);

            return $this->success($request, 'Plan saved.', '/memberships/plans');
        }

        $columns['created_at'] = now_utc();
        $planId = (int) DB::insert('membership_plans', $columns);

        Audit::record('membership_plan_created', 'membership_plan', $planId, null, $columns);

        return $this->success($request, 'Plan created.', '/memberships/plans');
    }

    // =================================================================
    // Plumbing
    // =================================================================

    /**
     * @return array{0:string,1:array<string,mixed>}
     */
    private function filter(Request $request, string $view, string $today): array
    {
        $clauses = ['1 = 1'];
        $params = [];

        $search = trim($request->str('q'));

        if ($search !== '') {
            $clauses[] = "(m.member_number LIKE :q OR c.first_name LIKE :q2 OR c.last_name LIKE :q3
                         OR c.email LIKE :q4)";
            $like = '%' . $search . '%';
            $params += ['q' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like];
        }

        $planId = $request->int('plan');

        if ($planId > 0) {
            $clauses[]          = 'm.plan_id = :plan';
            $params['plan']   = $planId;
        }

        switch ($view) {
            case 'renewals':
                // Everything inside the renewal window, soonest first. Not
                // only `expiring`: a pass that ran out last week is still
                // somebody to ring.
                $clauses[]            = "m.status IN ('expiring', 'expired')";
                break;

            case 'idle':
                $clauses[]            = "m.status IN ('active', 'expiring')";
                $clauses[]            = 'COALESCE(m.last_used_at, m.started_at) <= :idle';
                $params['idle']     = Membership::addDays($today, -self::IDLE_DAYS) . ' 23:59:59';
                break;

            case 'lapsed':
                $clauses[]            = "m.status IN ('lapsed', 'cancelled')";
                break;

            case 'all':
                break;

            case 'live':
            default:
                $clauses[]            = "m.status IN ('pending', 'active', 'expiring', 'frozen')";
                break;
        }

        return [implode(' AND ', $clauses), $params];
    }

    private function order(string $view): string
    {
        return match ($view) {
            'renewals' => 'm.expires_at IS NULL, m.expires_at, m.id',
            'idle'     => 'COALESCE(m.last_used_at, m.started_at), m.id',
            default    => 'm.id DESC',
        };
    }

    /** @return array<string,int> */
    private function counts(string $today): array
    {
        $idle = Membership::addDays($today, -self::IDLE_DAYS) . ' 23:59:59';

        return [
            'live'     => (int) DB::value(
                "SELECT COUNT(*) FROM {memberships}
                  WHERE status IN ('pending', 'active', 'expiring', 'frozen')"
            ),
            'renewals' => (int) DB::value(
                "SELECT COUNT(*) FROM {memberships} WHERE status IN ('expiring', 'expired')"
            ),
            'idle'     => (int) DB::value(
                "SELECT COUNT(*) FROM {memberships}
                  WHERE status IN ('active', 'expiring')
                    AND COALESCE(last_used_at, started_at) <= :idle",
                ['idle' => $idle]
            ),
            'lapsed'   => (int) DB::value(
                "SELECT COUNT(*) FROM {memberships} WHERE status IN ('lapsed', 'cancelled')"
            ),
            'all'      => (int) DB::value('SELECT COUNT(*) FROM {memberships}'),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function planRows(bool $withCounts = false): array
    {
        if (!$withCounts) {
            return DB::all(
                'SELECT * FROM {membership_plans} WHERE is_active = 1 ORDER BY sort_order, name'
            );
        }

        return DB::all(
            "SELECT p.*,
                    (SELECT COUNT(*) FROM {memberships} m WHERE m.plan_id = p.id) AS sold,
                    (SELECT COUNT(*) FROM {memberships} m
                      WHERE m.plan_id = p.id AND m.status IN ('active', 'expiring')) AS live
               FROM {membership_plans} p
              ORDER BY p.sort_order, p.name"
        );
    }

    private function planSlug(string $name, int $excludeId): string
    {
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? '', '-');
        $base = $base === '' ? 'plan' : substr($base, 0, 50);
        $slug = $base;

        for ($n = 2; $n < 60; $n++) {
            $taken = DB::exists(
                'SELECT id FROM {membership_plans} WHERE slug = :s AND id <> :id',
                ['s' => $slug, 'id' => $excludeId]
            );

            if (!$taken) {
                return $slug;
            }

            $slug = $base . '-' . $n;
        }

        return $base . '-' . bin2hex(random_bytes(2));
    }
}
