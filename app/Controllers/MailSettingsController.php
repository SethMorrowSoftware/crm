<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Cache;
use App\Core\Mailer;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;

/**
 * Email provider configuration.
 *
 * Separate from SettingsController because this is the one settings page that
 * holds a credential, and it carries its own permission
 * (`integrations_manage`) accordingly.
 */
final class MailSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $mail = Settings::group('mail');

        return $this->view('settings/mail', [
            'pageTitle'  => 'Email',
            'mail'       => $mail,
            'driver'     => Mailer::driver(),
            // Never render the stored password back into the form. An operator
            // who needs to change it types a new one; a blank field means keep.
            'hasPassword' => Settings::get('mail.smtp_password') !== null
                && Settings::get('mail.smtp_password') !== '',
            'safeMode'   => Settings::safeMode(),
            'dns'        => $this->dnsAdvice(),
            'phpMailAvailable' => function_exists('mail'),
        ]);
    }

    public function save(Request $request): Response
    {
        if ($denied = $this->authorize('integrations_manage')) {
            return $denied;
        }

        $data = $this->validate($request, [
            'driver'          => 'required|in:log,php_mail,smtp',
            'from_email'      => 'required|email|max:160',
            'from_name'       => 'required|string|max:120',
            'reply_to'        => 'nullable|email|max:160',
            'safe_mode_address' => 'nullable|email|max:160',
            'smtp_host'       => 'nullable|string|max:191',
            'smtp_port'       => 'nullable|int|min:1|max:65535',
            'smtp_username'   => 'nullable|string|max:191',
            'smtp_encryption' => 'nullable|in:none,tls,ssl',
            'cost_per_email_cents' => 'nullable|numeric|min:0|max:100',
        ], [
            'from_email'        => 'From address',
            'from_name'         => 'From name',
            'smtp_host'         => 'SMTP host',
            'safe_mode_address' => 'Safe Mode address',
        ]);

        if ($data instanceof Response) {
            return $data;
        }

        // SMTP without a host would fail on every send, so refuse the
        // combination rather than accept a configuration that cannot work.
        if ($data['driver'] === 'smtp' && ($data['smtp_host'] ?? '') === '') {
            return $this->refuse($request, 'SMTP needs a host. Your cPanel host will give you one.', '/settings/mail');
        }

        if ($data['driver'] === 'php_mail' && !function_exists('mail')) {
            return $this->refuse(
                $request,
                "This server has PHP's mail() disabled, so that driver cannot work here. Use SMTP instead.",
                '/settings/mail'
            );
        }

        $before = Settings::group('mail');

        Settings::setMany([
            'mail.driver'          => $data['driver'],
            'mail.from_email'      => $data['from_email'],
            'mail.from_name'       => $data['from_name'],
            'mail.reply_to'        => $data['reply_to'] ?? null,
            'mail.safe_mode_address' => $data['safe_mode_address'] ?? null,
            'mail.smtp_host'       => $data['smtp_host'] ?? null,
            'mail.smtp_port'       => (int) ($data['smtp_port'] ?? 587),
            'mail.smtp_username'   => $data['smtp_username'] ?? null,
            'mail.smtp_encryption' => $data['smtp_encryption'] ?? 'tls',
            'mail.smtp_verify_peer' => $request->bool('smtp_verify_peer', true),
            'mail.cost_per_email_cents' => (float) ($data['cost_per_email_cents'] ?? 0),
        ]);

        // A blank password field means "leave it alone", so an operator can
        // change the host without re-typing the credential.
        $password = (string) $request->post('smtp_password', '');

        if ($password !== '') {
            Settings::set('mail.smtp_password', $password, 'string', true);
        }

        Cache::flush();

        // The diff deliberately excludes the password: Audit redacts it, but
        // not passing it at all is better than relying on that.
        Audit::record('settings.mail_updated', 'settings', null, $before, [
            'driver'     => $data['driver'],
            'from_email' => $data['from_email'],
            'smtp_host'  => $data['smtp_host'] ?? null,
        ]);

        return $this->success($request, 'Email settings saved.', '/settings/mail');
    }

    /**
     * Send a test message through the configured driver.
     */
    public function test(Request $request): Response
    {
        if ($denied = $this->authorize('integrations_manage')) {
            return $denied;
        }

        $to = $request->str('test_to');

        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return $this->refuse($request, 'Enter a valid address to send the test to.', '/settings/mail');
        }

        $result = Mailer::test($to);

        Audit::event('settings.mail_tested', ['to' => $to, 'ok' => $result['ok'], 'driver' => Mailer::driver()]);

        if ($result['ok']) {
            return $this->success($request, $result['detail'], '/settings/mail');
        }

        return $this->refuse($request, $result['detail'], '/settings/mail');
    }

    /**
     * The DNS records that stop this venue's mail being filtered as spam.
     *
     * The advice itself lives in `Mailer::dnsAdvice()`, which is pure and
     * tested: WHICH records are right depends entirely on who actually sends
     * the mail, and this page used to answer that question wrongly in the
     * most damaging direction. See the note on that method.
     *
     * @return array{records:list<array{type:string,name:string,value:string,note:string}>,warning:string,sender:string}
     */
    private function dnsAdvice(): array
    {
        $from = (string) Settings::get('mail.from_email', '');

        $domain = $from !== '' && str_contains($from, '@')
            ? explode('@', $from)[1]
            : (string) preg_replace('/^www\./', '', explode(':', request()->host())[0]);

        return Mailer::dnsAdvice(
            Mailer::driver(),
            (string) Settings::get('mail.smtp_host', ''),
            $domain
        );
    }
}
