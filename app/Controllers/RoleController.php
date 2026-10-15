<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Acl;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;

/**
 * Role and permission management.
 *
 * Roles are data, so the venue can shape them around how it actually works
 * rather than around our guesses. The owner role is locked: it is the recovery
 * path if a custom role is misconfigured, so it must always hold everything.
 */
final class RoleController extends Controller
{
    public function index(Request $request): Response
    {
        $roles = DB::all(
            'SELECT r.*,
                    (SELECT COUNT(*) FROM {users} u
                      WHERE u.role_id = r.id AND u.deleted_at IS NULL) AS user_count,
                    (SELECT COUNT(*) FROM {role_permissions} rp
                      WHERE rp.role_id = r.id) AS permission_count
               FROM {roles} r
              ORDER BY r.sort_order, r.name'
        );

        return $this->view('settings/roles/index', [
            'pageTitle'     => 'Roles & permissions',
            'roles'         => $roles,
            'catalogTotal'  => count(Acl::CATALOG),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->view('settings/roles/form', [
            'pageTitle'   => 'Add a role',
            'role'        => null,
            'granted'     => [],
            'catalog'     => Acl::catalogByGroup(),
            'myPermissions' => Auth::isOwner() ? null : Acl::permissions(),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, [
            'name'        => 'required|string|min:2|max:80',
            'description' => 'nullable|string|max:255',
        ]);

        if ($data instanceof Response) {
            return $data;
        }

        $slug = slugify((string) $data['name']);

        if ($slug === '' || DB::exists('SELECT id FROM {roles} WHERE slug = :slug', ['slug' => $slug])) {
            return $this->refuse($request, 'A role with that name already exists. Choose another.', '/settings/roles/new');
        }

        $permissions = $this->submittedPermissions($request);

        if ($refusal = $this->guardEscalation($permissions, $request, '/settings/roles/new')) {
            return $refusal;
        }

        $roleId = DB::transaction(static function () use ($data, $slug, $permissions): int {
            $roleId = DB::insert('roles', [
                'slug'        => $slug,
                'name'        => $data['name'],
                'description' => $data['description'] ?? null,
                'is_system'   => 0,
                'sort_order'  => 100,
                'created_at'  => now_utc(),
                'updated_at'  => now_utc(),
            ]);

            self::writePermissions($roleId, $permissions);

            return $roleId;
        });

        Audit::record('role.created', 'roles', $roleId, null, [
            'name'        => $data['name'],
            'permissions' => array_keys($permissions),
        ]);

        return $this->success($request, 'Role created.', '/settings/roles');
    }

    public function edit(Request $request): Response
    {
        $role = DB::first('SELECT * FROM {roles} WHERE id = :id', ['id' => $request->routeInt('id')]);

        if ($role === null) {
            return $this->notFound('That role does not exist.');
        }

        $granted = DB::pairs(
            'SELECT p.slug, rp.scope
               FROM {role_permissions} rp
               JOIN {permissions} p ON p.id = rp.permission_id
              WHERE rp.role_id = :rid',
            ['rid' => (int) $role['id']]
        );

        return $this->view('settings/roles/form', [
            'pageTitle'     => 'Edit ' . $role['name'],
            'role'          => $role,
            'granted'       => $granted,
            'catalog'       => Acl::catalogByGroup(),
            'myPermissions' => Auth::isOwner() ? null : Acl::permissions(),
            'userCount'     => (int) DB::value(
                'SELECT COUNT(*) FROM {users} WHERE role_id = :rid AND deleted_at IS NULL',
                ['rid' => (int) $role['id']]
            ),
        ]);
    }

    public function update(Request $request): Response
    {
        $id   = $request->routeInt('id');
        $role = DB::first('SELECT * FROM {roles} WHERE id = :id', ['id' => $id]);

        if ($role === null) {
            return $this->notFound('That role does not exist.');
        }

        if ($role['slug'] === 'owner') {
            return $this->refuse(
                $request,
                'The Owner role is locked — it must always hold every permission so there is '
                . 'always a way back into a misconfigured install.',
                '/settings/roles'
            );
        }

        $data = $this->validate($request, [
            'name'        => 'required|string|min:2|max:80',
            'description' => 'nullable|string|max:255',
        ]);

        if ($data instanceof Response) {
            return $data;
        }

        $permissions = $this->submittedPermissions($request);

        if ($refusal = $this->guardEscalation($permissions, $request, '/settings/roles/' . $id)) {
            return $refusal;
        }

        // Editing your own role mid-session would leave you with stale
        // permissions until the next request; refuse rather than surprise.
        if ((int) (Auth::user()['role_id'] ?? 0) === $id) {
            return $this->refuse(
                $request,
                'You cannot edit the role you hold yourself. Ask another administrator.',
                '/settings/roles'
            );
        }

        DB::transaction(static function () use ($id, $data, $permissions, $role): void {
            DB::update('roles', [
                // A system role keeps its slug, so seeded automations and
                // anything referencing it by slug keep working.
                'name'        => $data['name'],
                'description' => $data['description'] ?? null,
                'updated_at'  => now_utc(),
            ], 'id = :id', ['id' => $id]);

            DB::delete('role_permissions', 'role_id = :rid', ['rid' => $id]);
            self::writePermissions($id, $permissions);

            Audit::record('role.updated', 'roles', $id, $role, [
                'name'        => $data['name'],
                'permissions' => array_keys($permissions),
            ]);
        });

        // Anyone holding this role gets the new permissions on their next
        // request; nothing cached survives.
        Acl::forget();

        return $this->success($request, 'Role updated.', '/settings/roles');
    }

    public function destroy(Request $request): Response
    {
        $id   = $request->routeInt('id');
        $role = DB::first('SELECT * FROM {roles} WHERE id = :id', ['id' => $id]);

        if ($role === null) {
            return $this->notFound('That role does not exist.');
        }

        if ((int) $role['is_system'] === 1) {
            return $this->refuse($request, 'Built-in roles cannot be deleted. Edit it instead.', '/settings/roles');
        }

        $inUse = (int) DB::value(
            'SELECT COUNT(*) FROM {users} WHERE role_id = :rid AND deleted_at IS NULL',
            ['rid' => $id]
        );

        if ($inUse > 0) {
            return $this->refuse(
                $request,
                sprintf(
                    '%d user%s still hold%s this role. Move them to another role first.',
                    $inUse,
                    $inUse === 1 ? '' : 's',
                    $inUse === 1 ? 's' : ''
                ),
                '/settings/roles'
            );
        }

        DB::transaction(static function () use ($id, $role): void {
            DB::delete('role_permissions', 'role_id = :rid', ['rid' => $id]);
            DB::delete('roles', 'id = :id', ['id' => $id]);

            Audit::record('role.deleted', 'roles', $id, $role, null);
        });

        return $this->success($request, 'Role deleted.', '/settings/roles');
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Read the submitted permission grid.
     *
     * Only catalogue entries are accepted, and only valid scopes, so a crafted
     * POST cannot grant a permission the code does not know about.
     *
     * @return array<string,string> slug => scope
     */
    private function submittedPermissions(Request $request): array
    {
        $submitted = $request->input('permissions', []);
        $scopes    = $request->input('scopes', []);

        if (!is_array($submitted)) {
            return [];
        }

        $out = [];

        foreach ($submitted as $slug) {
            $slug = (string) $slug;

            if (!isset(Acl::CATALOG[$slug])) {
                continue;
            }

            $scope = is_array($scopes) ? (string) ($scopes[$slug] ?? 'all') : 'all';

            $out[$slug] = in_array($scope, Acl::SCOPES, true) ? $scope : 'all';
        }

        return $out;
    }

    /**
     * Stop a non-owner building a role more powerful than their own.
     *
     * @param array<string,string> $permissions
     */
    private function guardEscalation(array $permissions, Request $request, string $redirectTo): ?Response
    {
        if (Auth::isOwner()) {
            return null;
        }

        $mine = Acl::permissions();

        foreach (array_keys($permissions) as $slug) {
            if (!isset($mine[$slug])) {
                return $this->refuse(
                    $request,
                    'You can only grant permissions you hold yourself. "'
                    . (Acl::CATALOG[$slug][1] ?? $slug) . '" is not one of them.',
                    $redirectTo
                );
            }
        }

        return null;
    }

    /** @param array<string,string> $permissions */
    private static function writePermissions(int $roleId, array $permissions): void
    {
        if ($permissions === []) {
            return;
        }

        $ids = DB::pairs('SELECT slug, id FROM {permissions}');

        $rows = [];

        foreach ($permissions as $slug => $scope) {
            if (!isset($ids[$slug])) {
                continue;
            }

            $rows[] = [
                'role_id'       => $roleId,
                'permission_id' => (int) $ids[$slug],
                'scope'         => $scope,
            ];
        }

        if ($rows !== []) {
            DB::insertMany('role_permissions', $rows);
        }
    }
}
