<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Acl;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;

/**
 * User management.
 *
 * The safety rails here are the point of the class:
 *   - the last active owner can never be demoted, deactivated or deleted
 *   - nobody can assign a role carrying permissions they do not themselves hold
 *   - nobody can delete or demote themselves
 *
 * Without these, one wrong click locks the venue out of its own CRM.
 */
final class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $users = DB::all(
            'SELECT u.id, u.name, u.email, u.phone, u.is_active, u.last_login_at,
                    u.locked_until, u.created_at, r.name AS role_name, r.slug AS role_slug
               FROM {users} u
               LEFT JOIN {roles} r ON r.id = u.role_id
              WHERE u.deleted_at IS NULL
              ORDER BY u.is_active DESC, u.name'
        );

        return $this->view('settings/users/index', [
            'pageTitle' => 'Users',
            'users'     => $users,
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->view('settings/users/form', [
            'pageTitle' => 'Add a user',
            'user'      => null,
            'roles'     => $this->assignableRoles(),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, [
            'name'     => 'required|string|min:2|max:120',
            'email'    => 'required|email|max:160|unique:users,email',
            'phone'    => 'nullable|phone',
            'role_id'  => 'required|int|exists:roles',
            'timezone' => 'nullable|timezone',
        ], ['role_id' => 'Role']);

        if ($data instanceof Response) {
            return $data;
        }

        if (!Acl::mayAssignRole((int) $data['role_id'])) {
            return $this->refuse(
                $request,
                'You cannot grant a role that includes permissions you do not have yourself.',
                '/settings/users/new'
            );
        }

        $password = (string) $request->post('password', '');
        $problems = Auth::passwordProblems($password, (string) $data['email'], (string) $data['name']);

        if ($problems !== []) {
            return $this->refuse($request, $problems[0], '/settings/users/new');
        }

        $id = DB::insert('users', [
            'name'          => $data['name'],
            'email'         => $data['email'],
            'phone'         => normalize_phone($data['phone'] ?? null),
            'password_hash' => Auth::hash($password),
            'role_id'       => (int) $data['role_id'],
            'timezone'      => $data['timezone'] ?? Settings::timezone(),
            'is_active'     => 1,
            'created_at'    => now_utc(),
            'updated_at'    => now_utc(),
        ]);

        Audit::record('user.created', 'users', $id, null, [
            'name' => $data['name'], 'email' => $data['email'], 'role_id' => $data['role_id'],
        ]);

        return $this->success($request, e($data['name']) . ' can now sign in.', '/settings/users');
    }

    public function edit(Request $request): Response
    {
        $user = DB::first(
            'SELECT * FROM {users} WHERE id = :id AND deleted_at IS NULL',
            ['id' => $request->routeInt('id')]
        );

        if ($user === null) {
            return $this->notFound('That user does not exist.');
        }

        return $this->view('settings/users/form', [
            'pageTitle' => 'Edit ' . $user['name'],
            'user'      => $user,
            'roles'     => $this->assignableRoles(),
            'sessions'  => (int) DB::value(
                'SELECT COUNT(*) FROM {sessions} WHERE user_id = :uid',
                ['uid' => (int) $user['id']]
            ),
        ]);
    }

    public function update(Request $request): Response
    {
        $id   = $request->routeInt('id');
        $user = DB::first('SELECT * FROM {users} WHERE id = :id AND deleted_at IS NULL', ['id' => $id]);

        if ($user === null) {
            return $this->notFound('That user does not exist.');
        }

        $data = $this->validate($request, [
            'name'     => 'required|string|min:2|max:120',
            'email'    => 'required|email|max:160|unique:users,email,' . $id,
            'phone'    => 'nullable|phone',
            'role_id'  => 'required|int|exists:roles',
            'timezone' => 'nullable|timezone',
        ], ['role_id' => 'Role']);

        if ($data instanceof Response) {
            return $data;
        }

        $newRoleId = (int) $data['role_id'];
        $isActive  = $request->bool('is_active', true);

        // Guard the last owner before anything is written.
        if ($guard = $this->guardLastOwner($user, $newRoleId, $isActive, $request)) {
            return $guard;
        }

        if ($newRoleId !== (int) $user['role_id'] && !Acl::mayAssignRole($newRoleId)) {
            return $this->refuse(
                $request,
                'You cannot grant a role that includes permissions you do not have yourself.',
                '/settings/users/' . $id
            );
        }

        // Changing your own role mid-session would leave stale permissions in
        // memory; refuse rather than half-apply it.
        if ($id === Auth::id() && $newRoleId !== (int) $user['role_id']) {
            return $this->refuse(
                $request,
                'You cannot change your own role. Ask another administrator.',
                '/settings/users/' . $id
            );
        }

        if ($id === Auth::id() && !$isActive) {
            return $this->refuse($request, 'You cannot deactivate your own account.', '/settings/users/' . $id);
        }

        $update = [
            'name'       => $data['name'],
            'email'      => $data['email'],
            'phone'      => normalize_phone($data['phone'] ?? null),
            'role_id'    => $newRoleId,
            'timezone'   => $data['timezone'] ?? Settings::timezone(),
            'is_active'  => $isActive ? 1 : 0,
            'updated_at' => now_utc(),
        ];

        // An administrator clearing a lockout is a normal support action.
        if ($request->bool('clear_lockout')) {
            $update['locked_until']    = null;
            $update['failed_attempts'] = 0;
        }

        $password = (string) $request->post('password', '');

        if ($password !== '') {
            $problems = Auth::passwordProblems($password, (string) $data['email'], (string) $data['name']);

            if ($problems !== []) {
                return $this->refuse($request, $problems[0], '/settings/users/' . $id);
            }

            $update['password_hash'] = Auth::hash($password);
        }

        DB::transaction(static function () use ($id, $update, $user, $isActive, $password): void {
            DB::update('users', $update, 'id = :id', ['id' => $id]);

            Audit::record('user.updated', 'users', $id, $user, $update);

            // A deactivated or password-changed account must not keep a live
            // session: that is the whole point of deactivating it.
            if (!$isActive || $password !== '') {
                DB::delete('sessions', 'user_id = :uid', ['uid' => $id]);
            }
        });

        return $this->success($request, 'User updated.', '/settings/users');
    }

    public function destroy(Request $request): Response
    {
        $id   = $request->routeInt('id');
        $user = DB::first('SELECT * FROM {users} WHERE id = :id AND deleted_at IS NULL', ['id' => $id]);

        if ($user === null) {
            return $this->notFound('That user does not exist.');
        }

        if ($id === Auth::id()) {
            return $this->refuse($request, 'You cannot delete your own account.', '/settings/users');
        }

        if ($guard = $this->guardLastOwner($user, null, false, $request)) {
            return $guard;
        }

        DB::transaction(static function () use ($id, $user): void {
            // Soft delete: audit rows, deals and bookings reference this user,
            // and a hard delete would orphan the venue's own history.
            DB::update('users', [
                'deleted_at' => now_utc(),
                'is_active'  => 0,
                // Free the address for reuse while keeping the record readable.
                'email'      => 'deleted+' . $id . '@castle-crm.invalid',
            ], 'id = :id', ['id' => $id]);

            DB::delete('sessions', 'user_id = :uid', ['uid' => $id]);

            Audit::record('user.deleted', 'users', $id, $user, null);
        });

        return $this->success($request, e((string) $user['name']) . ' has been removed.', '/settings/users');
    }

    public function reactivate(Request $request): Response
    {
        $id = $request->routeInt('id');

        $affected = DB::update('users', [
            'is_active'       => 1,
            'locked_until'    => null,
            'failed_attempts' => 0,
            'updated_at'      => now_utc(),
        ], 'id = :id AND deleted_at IS NULL', ['id' => $id]);

        if ($affected === 0) {
            return $this->notFound('That user does not exist.');
        }

        Audit::record('user.reactivated', 'users', $id, null, ['is_active' => 1]);

        return $this->success($request, 'Account reactivated.', '/settings/users');
    }

    // -----------------------------------------------------------------
    // Guards
    // -----------------------------------------------------------------

    /**
     * Refuse any change that would leave the install with no active owner.
     *
     * @param array<string,mixed> $user
     */
    private function guardLastOwner(array $user, ?int $newRoleId, bool $isActive, Request $request): ?Response
    {
        $ownerRoleId = (int) DB::value("SELECT id FROM {roles} WHERE slug = 'owner'");

        if ((int) $user['role_id'] !== $ownerRoleId) {
            return null;
        }

        $losingOwner = ($newRoleId !== null && $newRoleId !== $ownerRoleId) || !$isActive;

        if (!$losingOwner) {
            return null;
        }

        $otherActiveOwners = (int) DB::value(
            'SELECT COUNT(*) FROM {users}
              WHERE role_id = :rid AND is_active = 1 AND deleted_at IS NULL AND id <> :id',
            ['rid' => $ownerRoleId, 'id' => (int) $user['id']]
        );

        if ($otherActiveOwners > 0) {
            return null;
        }

        return $this->refuse(
            $request,
            'This is the only active owner. Promote another user to Owner first, '
            . 'or nobody will be able to administer Castle CRM.',
            '/settings/users'
        );
    }

    /**
     * Roles the current user may actually assign, so the dropdown cannot offer
     * an escalation the save would then reject.
     *
     * @return list<array<string,mixed>>
     */
    private function assignableRoles(): array
    {
        $roles = DB::all('SELECT id, name, slug, description FROM {roles} ORDER BY sort_order, name');

        return array_values(array_filter(
            $roles,
            static fn (array $role): bool => Acl::mayAssignRole((int) $role['id'])
        ));
    }
}
