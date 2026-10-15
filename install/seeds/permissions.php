<?php

declare(strict_types=1);

/**
 * Seeds the permission catalogue and the eight starting roles.
 *
 * The catalogue itself is defined in code (Acl::CATALOG) and mirrored into the
 * database here, so the role editor can join against it. Any permission
 * removed from the code is ignored at check time even if a stale row survives.
 *
 * Returns a summary for the installer to display.
 *
 * @return array{permissions:int,roles:int}
 */

use App\Core\Acl;
use App\Core\DB;

return static function (): array {
    // ------------------------------------------------------------------
    // Permissions
    // ------------------------------------------------------------------

    $existing = DB::pairs('SELECT slug, id FROM {permissions}');

    foreach (Acl::CATALOG as $slug => [$group, $label]) {
        if (isset($existing[$slug])) {
            // Keep labels current when the catalogue's wording changes.
            DB::update('permissions', [
                'group' => $group,
                'label' => $label,
            ], 'id = :id', ['id' => (int) $existing[$slug]]);

            continue;
        }

        DB::insert('permissions', [
            'slug'  => $slug,
            'group' => $group,
            'label' => $label,
        ]);
    }

    $permissionIds = DB::pairs('SELECT slug, id FROM {permissions}');

    // ------------------------------------------------------------------
    // Roles
    // ------------------------------------------------------------------

    // Permission sets are expressed as slug => scope. 'all' is venue-wide,
    // 'own' limits a user to their own records, 'team' to their teams'.
    $everything = array_fill_keys(array_keys(Acl::CATALOG), 'all');

    $visibility = static fn (array $extra = []): array => array_merge([
        'view_dashboard' => 'all',
    ], $extra);

    $roles = [
        [
            'slug'        => 'owner',
            'name'        => 'Owner',
            'description' => 'Full access, including settings, users and data erasure. Cannot be edited or deleted.',
            'is_system'   => 1,
            'sort_order'  => 10,
            // Acl::can() also short-circuits true for this role, so the Owner
            // can always reach the settings that would fix a broken config.
            'permissions' => $everything,
        ],
        [
            'slug'        => 'general_manager',
            'name'        => 'General Manager',
            'description' => 'Everything operational. No user deletion and no destructive settings.',
            'is_system'   => 1,
            'sort_order'  => 20,
            'permissions' => array_merge($everything, [
                // A GM runs the venue but does not reconfigure the install or
                // remove the accounts of the people who administer it.
                'users_manage'        => 'all',
                'roles_manage'        => 'all',
                'contacts_erase'      => 'all',
                'backup_manage'       => 'all',
                'integrations_manage' => 'all',
                'pos_query'           => 'all',
            ]),
            'revoke' => ['roles_manage', 'contacts_erase', 'backup_manage', 'pos_query'],
        ],
        [
            'slug'        => 'sales_manager',
            'name'        => 'Sales Manager',
            'description' => 'All pipelines, bookings and guests, plus campaign sending and team scorecards.',
            'is_system'   => 1,
            'sort_order'  => 30,
            'permissions' => $visibility([
                'view_inbox'       => 'all', 'view_contacts' => 'all', 'view_deals' => 'all',
                'view_bookings'    => 'all', 'view_marketing' => 'all', 'view_automations' => 'all',
                'view_reputation'  => 'all', 'view_memberships' => 'all', 'view_tasks' => 'all',
                'view_reports'     => 'all', 'view_revenue' => 'all',
                'contacts_manage'  => 'all', 'contacts_export' => 'all',
                'deals_manage'     => 'all', 'deals_delete' => 'all', 'deals_reassign' => 'all',
                'quotes_send'      => 'all',
                'bookings_manage'  => 'all', 'bookings_cancel' => 'all', 'payments_record' => 'all',
                'checkin'          => 'all',
                'inbox_reply'      => 'all', 'inbox_assign' => 'all',
                'campaigns_manage' => 'all', 'campaigns_send' => 'all', 'templates_manage' => 'all',
                'forms_manage'     => 'all',
                'automations_manage' => 'all', 'automations_activate' => 'all',
                'reviews_respond'  => 'all', 'feedback_resolve' => 'all',
                'memberships_manage' => 'all',
                'donations_review' => 'all',
                'coupons_manage'   => 'all', 'reports_export' => 'all',
            ]),
        ],
        [
            'slug'        => 'party_host',
            'name'        => 'Party Host / Sales Rep',
            'description' => 'Works their own leads and bookings. No campaign sending, no settings.',
            'is_system'   => 1,
            'sort_order'  => 40,
            'permissions' => $visibility([
                'view_inbox'      => 'all',
                // Scoped to their own records: a host sees their leads, a
                // manager sees the team's.
                'view_contacts'   => 'all',
                'view_deals'      => 'own',
                'view_bookings'   => 'all',
                'view_tasks'      => 'own',
                'contacts_manage' => 'all',
                'deals_manage'    => 'own',
                'quotes_send'     => 'own',
                'bookings_manage' => 'all',
                'payments_record' => 'all',
                'checkin'         => 'all',
                'inbox_reply'     => 'all',
            ]),
        ],
        [
            'slug'        => 'marketing',
            'name'        => 'Marketing',
            'description' => 'Campaigns, segments, templates, forms, automations and reputation. Read-only on deals.',
            'is_system'   => 1,
            'sort_order'  => 50,
            'permissions' => $visibility([
                'view_inbox'      => 'all', 'view_contacts' => 'all', 'view_deals' => 'all',
                'view_marketing'  => 'all', 'view_automations' => 'all', 'view_reputation' => 'all',
                'view_memberships' => 'all', 'view_reports' => 'all', 'view_revenue' => 'all',
                'contacts_manage' => 'all', 'contacts_export' => 'all',
                'campaigns_manage' => 'all', 'campaigns_send' => 'all',
                'templates_manage' => 'all', 'forms_manage' => 'all',
                'automations_manage' => 'all', 'automations_activate' => 'all',
                'playbooks_activate' => 'all',
                'reviews_respond' => 'all',
                'coupons_manage'  => 'all', 'reports_export' => 'all',
            ]),
        ],
        [
            'slug'        => 'front_desk',
            'name'        => 'Front Desk',
            'description' => 'Bookings, check-in, waivers and guest lookup. Cannot see money or edit pipelines.',
            'is_system'   => 1,
            'sort_order'  => 60,
            // Deliberately no view_revenue: floor staff can do their job
            // without seeing guest spend or lifetime value.
            'permissions' => $visibility([
                'view_inbox'      => 'all',
                'view_contacts'   => 'all',
                'view_bookings'   => 'all',
                'view_tasks'      => 'own',
                'contacts_manage' => 'all',
                'bookings_manage' => 'all',
                'checkin'         => 'all',
                'inbox_reply'     => 'all',
            ]),
        ],
        [
            'slug'        => 'read_only',
            'name'        => 'Read Only',
            'description' => 'Sees dashboards and reports. Changes nothing and exports nothing.',
            'is_system'   => 0,
            'sort_order'  => 70,
            'permissions' => $visibility([
                'view_contacts'  => 'all', 'view_deals' => 'all', 'view_bookings' => 'all',
                'view_marketing' => 'all', 'view_reputation' => 'all', 'view_memberships' => 'all',
                'view_reports'   => 'all', 'view_revenue' => 'all',
            ]),
        ],
        [
            'slug'        => 'api',
            'name'        => 'API / Integration',
            'description' => 'Token-scoped programmatic access. Not intended for interactive sign-in.',
            'is_system'   => 1,
            'sort_order'  => 90,
            'permissions' => [
                'view_contacts'   => 'all',
                'view_deals'      => 'all',
                'view_bookings'   => 'all',
                'contacts_manage' => 'all',
                'deals_manage'    => 'all',
                'bookings_manage' => 'all',
            ],
        ],
    ];

    $roleCount = 0;

    foreach ($roles as $role) {
        $permissions = $role['permissions'];

        // A convenience for roles defined as "everything except these".
        foreach ($role['revoke'] ?? [] as $slug) {
            unset($permissions[$slug]);
        }

        $existingRole = DB::first('SELECT id FROM {roles} WHERE slug = :slug', ['slug' => $role['slug']]);

        if ($existingRole !== null) {
            $roleId = (int) $existingRole['id'];
        } else {
            $roleId = DB::insert('roles', [
                'slug'        => $role['slug'],
                'name'        => $role['name'],
                'description' => $role['description'],
                'is_system'   => $role['is_system'],
                'sort_order'  => $role['sort_order'],
                'created_at'  => gmdate('Y-m-d H:i:s'),
                'updated_at'  => gmdate('Y-m-d H:i:s'),
            ]);
            $roleCount++;
        }

        // Rewrite the grants so a re-run repairs a role rather than appending.
        DB::delete('role_permissions', 'role_id = :rid', ['rid' => $roleId]);

        $rows = [];

        foreach ($permissions as $slug => $scope) {
            if (!isset($permissionIds[$slug])) {
                continue;
            }

            $rows[] = [
                'role_id'       => $roleId,
                'permission_id' => (int) $permissionIds[$slug],
                'scope'         => $scope,
            ];
        }

        if ($rows !== []) {
            DB::insertMany('role_permissions', $rows);
        }
    }

    return [
        'permissions' => count(Acl::CATALOG),
        'roles'       => count($roles),
    ];
};
