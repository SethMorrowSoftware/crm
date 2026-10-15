<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Encryption;
use App\Core\Log;
use App\Core\Mailer;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;

/**
 * Sign in, sign out, and password reset.
 */
final class AuthController extends Controller
{
    /** How long a reset link stays valid. */
    private const RESET_TTL_SECONDS = 3600;

    public function showLogin(Request $request): Response
    {
        return $this->view('auth/login', [
            'pageTitle' => 'Sign in',
        ], 'layouts/bare');
    }

    public function login(Request $request): Response
    {
        $email    = $request->str('email');
        $password = (string) $request->post('password', '');

        if ($email === '' || $password === '') {
            flash('error', 'Enter your email address and password.');
            Session::put('_old_input_next', ['email' => $email]);

            return $this->redirect('/login');
        }

        $result = Auth::attempt($email, $password, $request);

        if (!$result['ok']) {
            flash('error', $result['error'] ?? 'Sign in failed.');
            Session::put('_old_input_next', ['email' => $email]);

            return $this->redirect('/login');
        }

        $destination = $this->intendedUrl('/');

        flash('success', 'Welcome back, ' . Auth::name() . '.');

        return $this->redirect($destination);
    }

    public function logout(Request $request): Response
    {
        Auth::logout($request);

        flash('info', 'You have been signed out.');

        return $this->redirect('/login');
    }

    // -----------------------------------------------------------------
    // Password reset
    // -----------------------------------------------------------------

    public function showForgot(Request $request): Response
    {
        return $this->view('auth/forgot', [
            'pageTitle' => 'Reset your password',
        ], 'layouts/bare');
    }

    /**
     * Start a reset.
     *
     * The response is identical whether or not the address exists, so this
     * cannot be used to enumerate staff accounts.
     */
    public function sendReset(Request $request): Response
    {
        $email = strtolower($request->str('email'));

        $confirmation = 'If that address belongs to an account, a reset link is on its way. '
            . 'Check your inbox, including the spam folder.';

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            flash('info', $confirmation);

            return $this->redirect('/forgot-password');
        }

        $user = DB::first(
            'SELECT id, name, email, is_active FROM {users} WHERE email = :email AND deleted_at IS NULL',
            ['email' => $email]
        );

        if ($user !== null && (int) $user['is_active'] === 1) {
            $token = Encryption::randomToken(32);

            // One live token per address: issuing a new link invalidates the old.
            DB::delete('password_resets', 'email = :email', ['email' => $email]);

            DB::insert('password_resets', [
                'email'      => $email,
                'token_hash' => Encryption::hashToken($token),
                'expires_at' => gmdate('Y-m-d H:i:s', time() + self::RESET_TTL_SECONDS),
                'created_at' => now_utc(),
            ]);

            $link = Mailer::absoluteUrl(url('reset-password/' . $token));

            // Transactional: the person asked for this, so it is sent even
            // without marketing consent. Safe Mode still redirects it to the
            // owner, and the log driver still records it — so on a host with
            // no mail provider configured the link remains recoverable from
            // storage/logs/ rather than being lost.
            $result = Mailer::sendTemplate('password-reset', $email, [
                'name'       => $user['name'],
                'reset_link' => $link,
                'venue_name' => Settings::venueName(),
                'expires_in' => 'one hour',
            ], [
                'kind'       => Mailer::TRANSACTIONAL,
                'to_name'    => (string) $user['name'],
                'contact_id' => null,
            ]);

            if (!$result['sent']) {
                // The operator needs to know the mail did not leave, and the
                // user needs the link to exist somewhere retrievable.
                Log::error('Password reset email could not be sent', [
                    'email'  => $email,
                    'reason' => $result['reason'],
                    'link'   => $link,
                    'note'   => 'Recover the link from this entry, or run tools/reset-password.php instead.',
                ]);
            }

            Audit::event('password.reset_requested', [
                'email'      => $email,
                'mail_sent'  => $result['sent'],
                'redirected' => $result['redirected'],
            ], 'user', (int) $user['id']);
        }

        flash('info', $confirmation);

        return $this->redirect('/forgot-password');
    }

    public function showReset(Request $request): Response
    {
        $token = (string) $request->route('token', '');

        $row = $this->findValidReset($token);

        if ($row === null) {
            flash('error', 'That reset link is invalid or has expired. Request a new one.');

            return $this->redirect('/forgot-password');
        }

        return $this->view('auth/reset', [
            'pageTitle' => 'Choose a new password',
            'token'     => $token,
            'email'     => $row['email'],
        ], 'layouts/bare');
    }

    public function resetPassword(Request $request): Response
    {
        $token    = $request->str('token');
        $password = (string) $request->post('password', '');
        $confirm  = (string) $request->post('password_confirmation', '');

        $row = $this->findValidReset($token);

        if ($row === null) {
            flash('error', 'That reset link is invalid or has expired. Request a new one.');

            return $this->redirect('/forgot-password');
        }

        if ($password !== $confirm) {
            flash('error', 'The two passwords do not match.');

            return $this->redirect('/reset-password/' . $token);
        }

        $user = DB::first(
            'SELECT id, name, email FROM {users} WHERE email = :email AND deleted_at IS NULL',
            ['email' => $row['email']]
        );

        if ($user === null) {
            flash('error', 'That account no longer exists.');

            return $this->redirect('/login');
        }

        $problems = Auth::passwordProblems($password, (string) $user['email'], (string) $user['name']);

        if ($problems !== []) {
            flash('error', $problems[0]);

            return $this->redirect('/reset-password/' . $token);
        }

        DB::transaction(static function () use ($user, $password, $request, $row): void {
            Auth::changePassword((int) $user['id'], $password, $request);

            // Burn the token so a captured link cannot be replayed.
            DB::delete('password_resets', 'email = :email', ['email' => $row['email']]);

            // Clear any lockout, since the person has now proved control of
            // the mailbox — otherwise a locked-out user could not recover.
            DB::update('users', [
                'failed_attempts' => 0,
                'locked_until'    => null,
            ], 'id = :id', ['id' => (int) $user['id']]);
        });

        flash('success', 'Your password has been changed. Sign in with it now.');

        return $this->redirect('/login');
    }

    /**
     * Look up a reset token, verifying both its hash and its expiry.
     *
     * @return array<string,mixed>|null
     */
    private function findValidReset(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        $row = DB::first(
            'SELECT email, token_hash, expires_at FROM {password_resets}
              WHERE token_hash = :hash AND expires_at > :now',
            ['hash' => Encryption::hashToken($token), 'now' => now_utc()]
        );

        return $row;
    }
}
