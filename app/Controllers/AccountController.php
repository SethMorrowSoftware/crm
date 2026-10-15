<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/**
 * A user's own account.
 *
 * Separate from UserController on purpose: these actions need no `users_manage`
 * permission, because everyone may edit themselves. They also must not be able
 * to change a user's role or active flag, which is why none of those fields
 * appear here.
 */
final class AccountController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = Auth::user();

        if ($user === null) {
            return $this->redirect('/login');
        }

        $sessions = DB::all(
            'SELECT id, ip, user_agent, last_activity, created_at
               FROM {sessions}
              WHERE user_id = :uid
              ORDER BY last_activity DESC',
            ['uid' => (int) $user['id']]
        );

        return $this->view('account/edit', [
            'pageTitle'       => 'Your account',
            'user'            => $user,
            'sessions'        => $sessions,
            'currentSession'  => Session::id(),
            'prefs'           => json_decode((string) ($user['notification_prefs'] ?? '{}'), true) ?: [],
        ]);
    }

    public function updateProfile(Request $request): Response
    {
        $userId = Auth::id();

        if ($userId === null) {
            return $this->redirect('/login');
        }

        $data = $this->validate($request, [
            'name'     => 'required|string|min:2|max:120',
            'email'    => 'required|email|max:160|unique:users,email,' . $userId,
            'phone'    => 'nullable|phone',
            'timezone' => 'nullable|timezone',
        ]);

        if ($data instanceof Response) {
            return $data;
        }

        $before = Auth::user();

        DB::update('users', [
            'name'       => $data['name'],
            'email'      => $data['email'],
            'phone'      => normalize_phone($data['phone'] ?? null),
            'timezone'   => $data['timezone'] ?? null,
            'updated_at' => now_utc(),
        ], 'id = :id', ['id' => $userId]);

        Audit::record('account.updated', 'users', $userId, $before, $data);
        Auth::refresh();

        return $this->success($request, 'Your details have been saved.', '/account');
    }

    public function updatePassword(Request $request): Response
    {
        $user = Auth::user();

        if ($user === null) {
            return $this->redirect('/login');
        }

        $current = (string) $request->post('current_password', '');
        $new     = (string) $request->post('password', '');
        $confirm = (string) $request->post('password_confirmation', '');

        // Proving knowledge of the current password is what stops a hijacked
        // session from locking the real owner out of their own account.
        if (!password_verify($current, (string) $user['password_hash'])) {
            return $this->refuse($request, 'Your current password is not correct.', '/account');
        }

        if ($new !== $confirm) {
            return $this->refuse($request, 'The two new passwords do not match.', '/account');
        }

        if ($new === $current) {
            return $this->refuse($request, 'Choose a password you have not used here before.', '/account');
        }

        $problems = Auth::passwordProblems($new, (string) $user['email'], (string) $user['name']);

        if ($problems !== []) {
            return $this->refuse($request, $problems[0], '/account');
        }

        Auth::changePassword((int) $user['id'], $new, $request);

        return $this->success(
            $request,
            'Your password has been changed, and any other devices have been signed out.',
            '/account'
        );
    }

    public function updatePreferences(Request $request): Response
    {
        $userId = Auth::id();

        if ($userId === null) {
            return $this->redirect('/login');
        }

        // An allow-list: only known preference keys are stored, so a crafted
        // POST cannot stuff arbitrary JSON into the column.
        $allowed = [
            'notify_assigned_deal',
            'notify_sla_breach',
            'notify_task_due',
            'notify_detractor',
            'notify_daily_digest',
            'notify_weekly_scorecard',
            'theme',
            'density',
        ];

        $prefs = [];

        foreach ($allowed as $key) {
            if ($key === 'theme') {
                $value = $request->str('theme', 'system');
                $prefs['theme'] = in_array($value, ['system', 'light', 'dark'], true) ? $value : 'system';
                continue;
            }

            if ($key === 'density') {
                $value = $request->str('density', 'compact');
                $prefs['density'] = in_array($value, ['compact', 'comfortable'], true) ? $value : 'compact';
                continue;
            }

            $prefs[$key] = $request->bool($key);
        }

        DB::update('users', [
            'notification_prefs' => json_encode($prefs, JSON_UNESCAPED_UNICODE),
            'updated_at'         => now_utc(),
        ], 'id = :id', ['id' => $userId]);

        Auth::refresh();

        return $this->success($request, 'Preferences saved.', '/account');
    }

    /** Sign out everywhere except here. */
    public function revokeSessions(Request $request): Response
    {
        $userId = Auth::id();

        if ($userId === null) {
            return $this->redirect('/login');
        }

        Session::destroyOthersFor($userId);
        Audit::event('account.sessions_revoked', [], 'user', $userId);

        return $this->success($request, 'Your other devices have been signed out.', '/account');
    }
}
