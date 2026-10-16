<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Role-based authorization.
 *
 * Roles are data, not an enum — an operator creates and edits them in Settings
 * (the convention CEplay established, so Castle staff meet one mental model).
 * Permission keys split in two: `view_*` keys control page visibility, and the
 * rest gate actions. Castle CRM adds a per-permission SCOPE — all / team / own
 * — because a CRM has record ownership that an arcade tool does not.
 *
 * Fail closed: an unknown role, a corrupt slug, or a missing permission all
 * resolve to "no", never to "yes".
 */
final class Acl
{
    /** @var array<string,string>|null permission slug => scope */
    private static ?array $cache = null;

    private static ?int $cachedForUser = null;

    /**
     * The permission catalogue: slug => [group, label].
     *
     * This is the single source of truth. A permission that is not listed here
     * cannot be granted, so a typo in a check fails closed rather than
     * silently passing.
     *
     * @var array<string,array{0:string,1:string}>
     */
    public const CATALOG = [
        // --- Visibility (view_*) -------------------------------------
        'view_dashboard'      => ['Visibility', 'See the dashboard'],
        'view_inbox'          => ['Visibility', 'See the unified inbox'],
        'view_contacts'       => ['Visibility', 'See contacts and households'],
        'view_deals'          => ['Visibility', 'See sales pipelines and deals'],
        'view_bookings'       => ['Visibility', 'See bookings and the calendar'],
        'view_marketing'      => ['Visibility', 'See campaigns, templates and forms'],
        'view_automations'    => ['Visibility', 'See automations and playbooks'],
        'view_reputation'     => ['Visibility', 'See feedback and reviews'],
        'view_memberships'    => ['Visibility', 'See memberships and season passes'],
        'view_tasks'          => ['Visibility', 'See tasks'],
        'view_reports'        => ['Visibility', 'See reports'],
        'view_revenue'        => ['Visibility', 'See money: revenue, spend, lifetime value'],
        'view_logs'           => ['Visibility', 'See the audit log'],

        // --- Contacts ------------------------------------------------
        'contacts_manage'     => ['Contacts', 'Create and edit contacts'],
        'contacts_delete'     => ['Contacts', 'Delete and merge contacts'],
        'contacts_export'     => ['Contacts', 'Export contact data'],
        'contacts_erase'      => ['Contacts', 'Erase a contact on request (privacy)'],

        // --- Sales ---------------------------------------------------
        'deals_manage'        => ['Sales', 'Create and edit deals'],
        'deals_delete'        => ['Sales', 'Delete deals'],
        'deals_reassign'      => ['Sales', 'Reassign deals to another owner'],
        'quotes_send'         => ['Sales', 'Send quotes to guests'],

        // --- Bookings ------------------------------------------------
        'bookings_manage'     => ['Bookings', 'Create and edit bookings'],
        'bookings_cancel'     => ['Bookings', 'Cancel or reschedule bookings'],
        'payments_record'     => ['Bookings', 'Record deposits and payments'],
        'waivers_manage'      => ['Bookings', 'Manage waiver versions'],
        'checkin'             => ['Bookings', 'Check guests in at the desk'],

        // --- Communication -------------------------------------------
        'inbox_reply'         => ['Communication', 'Reply to guest conversations'],
        'inbox_assign'        => ['Communication', 'Assign and close conversations'],
        'campaigns_manage'    => ['Communication', 'Build campaigns and segments'],
        'campaigns_send'      => ['Communication', 'Send a campaign to real guests'],
        'templates_manage'    => ['Communication', 'Edit message templates'],
        'forms_manage'        => ['Communication', 'Build and embed web forms'],

        // --- Automation ----------------------------------------------
        'automations_manage'  => ['Automation', 'Create and edit automations'],
        'automations_activate' => ['Automation', 'Turn automations on and off'],
        'playbooks_activate'  => ['Automation', 'Activate and deactivate playbooks'],

        // --- Reputation ----------------------------------------------
        'reviews_respond'     => ['Reputation', 'Reply to reviews'],
        'feedback_resolve'    => ['Reputation', 'Resolve guest complaints'],

        // --- Memberships ---------------------------------------------
        'memberships_manage'  => ['Memberships', 'Enrol, renew and cancel memberships'],
        'plans_manage'        => ['Memberships', 'Edit membership plans and pricing'],

        // --- Fundraising ---------------------------------------------
        'donations_review'    => ['Fundraising', 'Review donation requests'],
        'donations_approve'   => ['Fundraising', 'Approve donations and issue certificates'],
        'donations_override'  => ['Fundraising', 'Approve beyond the annual budget'],

        // --- Operations ----------------------------------------------
        'attractions_manage'  => ['Operations', 'Edit attractions, packages and resources'],
        'hours_manage'        => ['Operations', 'Edit operating hours and closures'],
        'coupons_manage'      => ['Operations', 'Create and edit coupons'],
        'reports_export'      => ['Operations', 'Export reports'],

        // --- Administration ------------------------------------------
        'settings'            => ['Administration', 'Change system settings'],
        'users_manage'        => ['Administration', 'Manage users'],
        'roles_manage'        => ['Administration', 'Manage roles and permissions'],
        'integrations_manage' => ['Administration', 'Configure POS, email and SMS providers'],
        'pos_query'           => ['Administration', 'Run read-only POS database queries'],
        'sending_override'    => ['Administration', 'Leave Safe Mode and resume sending'],
        'backup_manage'       => ['Administration', 'Run backups and restores'],
    ];

    /** Scopes, narrowest last. */
    public const SCOPES = ['all', 'team', 'own'];

    // -----------------------------------------------------------------
    // Checks
    // -----------------------------------------------------------------

    /**
     * Does the current user hold a permission?
     *
     * The Owner role short-circuits to true so an install can never lock its
     * own administrator out of the settings that would fix it.
     */
    public static function can(string $permission): bool
    {
        if (!Auth::check()) {
            return false;
        }

        /*
         * AN API TOKEN NARROWS, AND IT NARROWS THE OWNER TOO.
         *
         * Checked BEFORE the owner bypass on purpose. An owner minting a
         * read-only token for a reporting integration must get a read-only
         * token — if the bypass ran first, every token an owner created would
         * be a full-access token whatever its abilities said, which is the
         * opposite of what they chose and invisible until something writes.
         *
         * This only ever removes: a token cannot grant a permission the user
         * does not hold, because the role check below still has to pass.
         */
        $token = Auth::apiToken();

        if ($token !== null && !ApiToken::allows($token, $permission)) {
            return false;
        }

        if (Auth::isOwner()) {
            return true;
        }

        return isset(self::permissions()[$permission]);
    }

    /** @param list<string> $permissions */
    public static function canAny(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (self::can($permission)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $permissions */
    public static function canAll(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (!self::can($permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The scope a user holds for a permission: 'all', 'team', 'own', or null
     * when they do not hold it at all.
     */
    public static function scope(string $permission): ?string
    {
        if (!Auth::check()) {
            return null;
        }

        // Narrowed by an API token exactly as `can()` is, and before the
        // owner shortcut for the same reason. Without this, a read-only token
        // would be refused by `can('contacts_manage')` and then handed a
        // scope of 'all' by any query that asked `scope()` instead — the two
        // must never disagree about whether a permission is held.
        $token = Auth::apiToken();

        if ($token !== null && !ApiToken::allows($token, $permission)) {
            return null;
        }

        if (Auth::isOwner()) {
            return 'all';
        }

        return self::permissions()[$permission] ?? null;
    }

    /**
     * An SQL fragment restricting a query to what the user may see.
     *
     * Returns a condition and its bindings, so a list query reads:
     *
     *   [$where, $params] = Acl::scopeCondition('view_deals', 'd.owner_id');
     *   DB::all("SELECT * FROM {deals} d WHERE {$where}", $params);
     *
     * A user with no access gets `1 = 0` rather than an unrestricted query —
     * the failure mode is an empty list, never a data leak.
     *
     * @return array{0:string,1:array<string,mixed>}
     */
    public static function scopeCondition(string $permission, string $ownerColumn): array
    {
        $scope = self::scope($permission);

        if ($scope === null) {
            return ['1 = 0', []];
        }

        if ($scope === 'all') {
            return ['1 = 1', []];
        }

        $userId = Auth::id();

        if ($userId === null) {
            return ['1 = 0', []];
        }

        if ($scope === 'own') {
            return [$ownerColumn . ' = :acl_user_id', ['acl_user_id' => $userId]];
        }

        // team: the user plus everyone sharing a team with them.
        $teamMates = self::teamMateIds($userId);

        if ($teamMates === []) {
            return [$ownerColumn . ' = :acl_user_id', ['acl_user_id' => $userId]];
        }

        $placeholders = [];
        $params       = [];

        foreach ($teamMates as $i => $id) {
            $placeholders[]           = ':acl_team_' . $i;
            $params['acl_team_' . $i] = $id;
        }

        return [
            $ownerColumn . ' IN (' . implode(', ', $placeholders) . ')',
            $params,
        ];
    }

    /** @return list<int> */
    private static function teamMateIds(int $userId): array
    {
        $rows = DB::column(
            'SELECT DISTINCT tm2.user_id
               FROM {team_members} tm1
               JOIN {team_members} tm2 ON tm2.team_id = tm1.team_id
              WHERE tm1.user_id = :uid',
            ['uid' => $userId]
        );

        $ids = array_map('intval', $rows);

        if (!in_array($userId, $ids, true)) {
            $ids[] = $userId;
        }

        return array_values($ids);
    }

    /**
     * The current user's permissions: slug => scope.
     *
     * @return array<string,string>
     */
    public static function permissions(): array
    {
        $userId = Auth::id();

        if ($userId === null) {
            return [];
        }

        if (self::$cache !== null && self::$cachedForUser === $userId) {
            return self::$cache;
        }

        self::$cachedForUser = $userId;

        $roleId = Auth::user()['role_id'] ?? null;

        if ($roleId === null) {
            // A user with no role has no permissions. Fail closed.
            return self::$cache = [];
        }

        $rows = DB::all(
            'SELECT p.slug, rp.scope
               FROM {role_permissions} rp
               JOIN {permissions} p ON p.id = rp.permission_id
              WHERE rp.role_id = :rid',
            ['rid' => (int) $roleId]
        );

        $permissions = [];

        foreach ($rows as $row) {
            $slug = (string) $row['slug'];

            // Ignore anything not in the catalogue: a permission removed from
            // the code must not keep working from a stale database row.
            if (!isset(self::CATALOG[$slug])) {
                continue;
            }

            $scope = (string) ($row['scope'] ?? 'own');
            $permissions[$slug] = in_array($scope, self::SCOPES, true) ? $scope : 'own';
        }

        return self::$cache = $permissions;
    }

    public static function forget(): void
    {
        self::$cache = null;
        self::$cachedForUser = null;
    }

    /**
     * Permissions grouped for the role editor.
     *
     * @return array<string,array<string,string>>
     */
    public static function catalogByGroup(): array
    {
        $grouped = [];

        foreach (self::CATALOG as $slug => [$group, $label]) {
            $grouped[$group][$slug] = $label;
        }

        return $grouped;
    }

    /**
     * May the current user assign this role to someone?
     *
     * A user can only grant a role whose permissions are a subset of their
     * own, which is what stops a manager from minting an owner.
     */
    public static function mayAssignRole(int $roleId): bool
    {
        if (Auth::isOwner()) {
            return true;
        }

        if (!self::can('users_manage')) {
            return false;
        }

        return self::roleIsSubsetOfMine($roleId);
    }

    /**
     * May the current user edit, deactivate or delete THIS account?
     *
     * mayAssignRole() guards the role being granted. On its own that is only
     * half the rule, and the missing half was a privilege-escalation hole: a
     * non-owner holding users_manage could leave the Owner's role untouched —
     * so mayAssignRole() was never consulted — and simply set the Owner's
     * PASSWORD, then sign in as them. Every separation the role system draws
     * collapses at that point, including the backup_manage split that exists
     * so whoever can export every guest's details is not automatically
     * whoever can take over the account. docs/PRIVACY.md describes that split
     * as real, so this is the code that has to make it real.
     *
     * The rule: you may only act on an account whose role is a subset of your
     * own permissions, and only an owner may act on the owner.
     *
     * @param array<string,mixed> $user A users row (needs id and role_id).
     */
    public static function mayManageUser(array $user): bool
    {
        // Your own account. Changing your own role or deactivating yourself is
        // refused elsewhere; what is left is editing your own name and
        // password, which needs no privilege over anybody.
        if ((int) ($user['id'] ?? 0) === Auth::id()) {
            return true;
        }

        if (!self::can('users_manage') && !Auth::isOwner()) {
            return false;
        }

        // Only an owner may touch an owner — checked before the subset rule
        // and regardless of it. A custom role carrying every permission in the
        // catalogue would pass a subset test, and "holds every permission" is
        // still not the same thing as "is the owner": the owner is the account
        // that cannot be locked out, and handing it over must not be a
        // permission anybody can be granted.
        if (self::isOwnerRow($user) && !Auth::isOwner()) {
            return false;
        }

        if (Auth::isOwner()) {
            return true;
        }

        return self::roleIsSubsetOfMine((int) ($user['role_id'] ?? 0));
    }

    /** Does this users row hold the owner role? */
    private static function isOwnerRow(array $user): bool
    {
        $ownerRoleId = (int) DB::value("SELECT id FROM {roles} WHERE slug = 'owner'");

        return $ownerRoleId > 0 && (int) ($user['role_id'] ?? 0) === $ownerRoleId;
    }

    /**
     * Are this role's permissions a subset of the current user's own?
     *
     * Shared by mayAssignRole() and mayManageUser() so the two can never drift
     * apart — the hole above existed because only one side was checked.
     */
    private static function roleIsSubsetOfMine(int $roleId): bool
    {
        $targetSlugs = DB::column(
            'SELECT p.slug
               FROM {role_permissions} rp
               JOIN {permissions} p ON p.id = rp.permission_id
              WHERE rp.role_id = :rid',
            ['rid' => $roleId]
        );

        $mine = self::permissions();

        foreach ($targetSlugs as $slug) {
            if (!isset($mine[(string) $slug])) {
                return false;
            }
        }

        return true;
    }
}
