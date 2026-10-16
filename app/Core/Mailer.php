<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

/**
 * Outbound email.
 *
 * Three decisions shape this class:
 *
 * 1. **Safe Mode is enforced here, not in the UI.** Every path that sends mail
 *    goes through send(), so an operator cannot accidentally reach a guest from
 *    a page that forgot to check. The original recipient is preserved in a
 *    header and banner so the redirected copy is still useful for review.
 *
 * 2. **Consent and suppression are checked in the send path.** A controller
 *    cannot opt out of them. Transactional mail (a booking confirmation, a
 *    password reset) bypasses marketing *consent* — you did ask for it — but
 *    never bypasses the suppression list, because sending to a hard-bounced
 *    address damages the venue's deliverability for everyone else.
 *
 * 3. **No dependency.** The SMTP driver is a socket client written here.
 *    PHPMailer would be the obvious choice elsewhere, but the project's whole
 *    premise is that it uploads to a cPanel host with no Composer step.
 */
final class Mailer
{
    /** A message the guest asked for: reset links, confirmations, receipts. */
    public const TRANSACTIONAL = 'transactional';

    /** A message the venue chose to send: campaigns, offers, nurture steps. */
    public const MARKETING = 'marketing';

    private static ?string $driverOverride = null;

    /** @var list<array<string,mixed>> Captured instead of sent, for tests. */
    private static array $captured = [];

    private static bool $capturing = false;

    // -----------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------

    /**
     * Send one message.
     *
     * @param array{
     *     to:string, subject:string, html?:string, text?:string,
     *     to_name?:string, contact_id?:int|null, kind?:string,
     *     reply_to?:string, headers?:array<string,string>,
     *     template_id?:int|null, campaign_id?:int|null,
     *     automation_run_id?:int|null
     * } $message
     *
     * @return array{sent:bool,reason:string,message_id:?int,redirected:bool}
     */
    public static function send(array $message): array
    {
        $to      = strtolower(trim($message['to'] ?? ''));
        $kind    = $message['kind'] ?? self::MARKETING;
        $subject = trim((string) ($message['subject'] ?? ''));

        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return self::refuse('invalid_address', 'That is not a deliverable email address.');
        }

        if ($subject === '') {
            return self::refuse('no_subject', 'A message with no subject will be filtered as spam.');
        }

        // --- Shape of the message ------------------------------------------
        //
        // Checked here, before any of the policy decisions below, so that a
        // malformed message reports what is actually wrong with it. Validating
        // the body after the Safe Mode branch meant an empty message on a
        // Safe-Mode install was reported as a Safe Mode problem, which sends
        // whoever is debugging it to entirely the wrong place.
        $html = (string) ($message['html'] ?? '');
        $text = (string) ($message['text'] ?? '');

        if ($html === '' && $text === '') {
            return self::refuse('empty_body', 'A message needs a body.');
        }

        // --- Header hygiene -------------------------------------------------
        //
        // A CR or LF in a header value ends the header and starts another, so
        // an unsanitised subject is a Bcc: waiting to happen. Each driver also
        // strips these, but doing it HERE means the stripped form is what gets
        // recorded, captured and logged — so an attempt shows up in the
        // message trail as the harmless string it was reduced to, rather than
        // sitting in the database looking like it worked.
        $subject = self::singleLine($subject);

        $headers = [];

        foreach ($message['headers'] ?? [] as $name => $value) {
            $cleanName = self::singleLine((string) $name);

            // A colon in a header NAME is the same injection by another route.
            if ($cleanName === '' || str_contains($cleanName, ':')) {
                Log::warning('Dropped an unusable mail header name', ['name' => $name]);
                continue;
            }

            $headers[$cleanName] = self::singleLine((string) $value);
        }

        // --- Global stop -------------------------------------------------
        //
        // Distinct from Safe Mode: this halts sending entirely, so a queued
        // message waits rather than failing.
        if (Settings::sendingPaused()) {
            return self::refuse('sending_paused', 'All outbound sending is paused in settings.');
        }

        // --- Suppression --------------------------------------------------
        //
        // Checked for EVERY kind, transactional included. A hard bounce or a
        // spam complaint means this address must not be written to again.
        if (self::isSuppressed($to)) {
            return self::refuse('suppressed', 'That address is on the suppression list.');
        }

        // --- Consent ------------------------------------------------------
        //
        // Marketing needs consent; transactional does not. A do_not_contact
        // flag overrides both, because it is an explicit instruction.
        $contactId = $message['contact_id'] ?? null;

        if ($contactId !== null) {
            $consent = self::consentFor((int) $contactId);

            if ($consent === null) {
                return self::refuse('no_contact', 'That contact does not exist.');
            }

            if ($consent['do_not_contact']) {
                return self::refuse('do_not_contact', 'That guest has asked not to be contacted at all.');
            }

            if ($kind === self::MARKETING && !$consent['email_consent']) {
                return self::refuse(
                    'no_consent',
                    'That guest has not given email consent, so only transactional mail may be sent.'
                );
            }
        }

        // --- Frequency cap -------------------------------------------------
        if ($kind === self::MARKETING && $contactId !== null && self::overFrequencyCap((int) $contactId)) {
            return self::refuse('frequency_cap', 'That guest has already had the weekly maximum of marketing messages.');
        }

        // --- Safe Mode ------------------------------------------------------
        $redirected = false;
        $originalTo = $to;

        if (Settings::safeMode()) {
            $owner = self::ownerAddress();

            if ($owner === null) {
                return self::refuse(
                    'safe_mode_no_owner',
                    'Safe Mode is on but no owner address could be found to redirect to.'
                );
            }

            $to = $owner;
            $redirected = true;
        }

        // --- Compose ---------------------------------------------------------
        if ($text === '') {
            $text = self::htmlToText($html);
        }

        if ($redirected) {
            [$html, $text, $subject] = self::markRedirected($html, $text, $subject, $originalTo, $kind);
        }

        // Bulk mail must carry an unsubscribe route and the venue's postal
        // address. The template validator will enforce this at authoring time
        // too, but a programmatic send must not be able to skip it.
        if ($kind === self::MARKETING) {
            [$html, $text] = self::appendCompliance($html, $text, $originalTo, $contactId);
        }

        if ($redirected) {
            $headers['X-Castle-Original-To'] = $originalTo;
            $headers['X-Castle-Safe-Mode']   = 'redirected';
        }

        if ($kind === self::MARKETING) {
            // RFC 8058 one-click unsubscribe. Gmail and Yahoo both now require
            // this for bulk senders.
            $token = self::unsubscribeToken($originalTo, $contactId);
            $headers['List-Unsubscribe'] = '<' . self::absoluteUrl('/u/' . $token) . '>';
            $headers['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        }

        // --- Record then deliver ---------------------------------------------
        $messageId = self::record($message, $originalTo, $subject, $html, $text, $redirected);

        if (self::$capturing) {
            self::$captured[] = [
                'to'         => $to,
                'original_to' => $originalTo,
                'subject'    => $subject,
                'html'       => $html,
                'text'       => $text,
                'headers'    => $headers,
                'kind'       => $kind,
                'redirected' => $redirected,
            ];

            self::markSent($messageId, 'captured', null);

            return ['sent' => true, 'reason' => 'captured', 'message_id' => $messageId, 'redirected' => $redirected];
        }

        try {
            $result = self::deliver($to, $message['to_name'] ?? '', $subject, $html, $text, $headers, $message);
        } catch (Throwable $e) {
            Log::error('Mail delivery threw', ['error' => $e->getMessage(), 'to' => $to]);
            self::markFailed($messageId, $e->getMessage());

            return ['sent' => false, 'reason' => 'driver_error', 'message_id' => $messageId, 'redirected' => $redirected];
        }

        if (!$result['ok']) {
            self::markFailed($messageId, $result['error']);

            return ['sent' => false, 'reason' => $result['error'], 'message_id' => $messageId, 'redirected' => $redirected];
        }

        self::markSent($messageId, self::driver(), $result['provider_id'] ?? null);
        self::meter($messageId);

        return ['sent' => true, 'reason' => 'sent', 'message_id' => $messageId, 'redirected' => $redirected];
    }

    /**
     * Send a message built from a stored template.
     *
     * @param array<string,mixed> $data  Merge-tag values.
     * @param array<string,mixed> $options
     * @return array{sent:bool,reason:string,message_id:?int,redirected:bool}
     */
    public static function sendTemplate(string $slug, string $to, array $data = [], array $options = []): array
    {
        $template = DB::first(
            'SELECT * FROM {templates} WHERE slug = :slug AND channel = :channel AND is_active = 1',
            ['slug' => $slug, 'channel' => 'email']
        );

        if ($template === null) {
            Log::error('Template not found', ['slug' => $slug]);

            return self::refuse('no_template', 'The template "' . $slug . '" does not exist or is inactive.');
        }

        return self::send(array_merge([
            'to'          => $to,
            // The subject is not HTML; the body is. Merging both with the same
            // escaper put "&amp;" and "&#039;" in guests' inboxes.
            'subject'     => self::mergeText((string) $template['subject'], $data),
            'html'        => self::merge((string) $template['body'], $data),
            'template_id' => (int) $template['id'],
        ], $options));
    }

    /**
     * Substitute {{merge_tags}} into HTML.
     *
     * Values are HTML-escaped: a guest whose last name is "<script>" must not
     * become script when their own confirmation renders in a webmail client.
     * A tag with no value becomes empty rather than leaving the braces visible.
     *
     * For anything that is NOT HTML — a subject line, a plain-text body — use
     * mergeText(). Escaping there is not merely unnecessary, it is visibly
     * wrong: see that method.
     *
     * @param array<string,mixed> $data
     */
    public static function merge(string $body, array $data): string
    {
        return self::substitute($body, $data, true);
    }

    /**
     * Substitute {{merge_tags}} into text that is not HTML.
     *
     * A subject line is not HTML, and escaping it mangles perfectly ordinary
     * names. MEASURED with the real helper before this existed: a venue called
     * "Dave & Buster's" turned the seeded password-reset subject into
     * "Reset your Dave &amp; Buster&#039;s password", and nothing downstream
     * decodes it — singleLine() leaves entities alone and encodeHeaderValue()
     * passes plain ASCII through — so that is what the guest sees in their
     * inbox and what is stored in messages.subject. Apostrophes in a child's
     * name and ampersands in a venue's name are the normal case here, not an
     * edge case.
     *
     * @param array<string,mixed> $data
     */
    public static function mergeText(string $text, array $data): string
    {
        return self::substitute($text, $data, false);
    }

    /** @param array<string,mixed> $data */
    private static function substitute(string $subject, array $data, bool $escape): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-z0-9_.]+)\s*\}\}/i',
            static function (array $m) use ($data, $escape): string {
                $value = array_get($data, strtolower($m[1]));

                if ($value === null) {
                    return '';
                }

                return $escape ? e((string) $value) : (string) $value;
            },
            $subject
        );
    }

    // -----------------------------------------------------------------
    // Drivers
    // -----------------------------------------------------------------

    public static function driver(): string
    {
        if (self::$driverOverride !== null) {
            return self::$driverOverride;
        }

        $driver = (string) Settings::get('mail.driver', 'log');

        return in_array($driver, ['log', 'php_mail', 'smtp'], true) ? $driver : 'log';
    }

    /** Force a driver for the current process. Used by the test suite. */
    public static function useDriver(?string $driver): void
    {
        self::$driverOverride = $driver;
    }

    /**
     * @param array<string,string> $headers
     * @param array<string,mixed>  $message
     * @return array{ok:bool,error:string,provider_id:?string}
     */
    private static function deliver(
        string $to,
        string $toName,
        string $subject,
        string $html,
        string $text,
        array $headers,
        array $message
    ): array {
        $fromEmail = (string) Settings::get('mail.from_email', self::fallbackFrom());
        $fromName  = (string) Settings::get('mail.from_name', Settings::venueName());
        $replyTo   = (string) ($message['reply_to'] ?? Settings::get('mail.reply_to', $fromEmail));

        return match (self::driver()) {
            'php_mail' => self::deliverViaPhpMail($to, $toName, $subject, $html, $text, $headers, $fromEmail, $fromName, $replyTo),
            'smtp'     => self::deliverViaSmtp($to, $toName, $subject, $html, $text, $headers, $fromEmail, $fromName, $replyTo),
            default    => self::deliverViaLog($to, $subject, $html, $text, $headers),
        };
    }

    /**
     * The log driver. Not a stub: on a Phase 1 install with no provider
     * configured this is what makes a password reset recoverable, so it writes
     * the full message where an administrator can retrieve it.
     *
     * @param array<string,string> $headers
     * @return array{ok:bool,error:string,provider_id:?string}
     */
    private static function deliverViaLog(string $to, string $subject, string $html, string $text, array $headers): array
    {
        Log::notice('MAIL (log driver — not actually sent)', [
            'to'      => $to,
            'subject' => $subject,
            'headers' => $headers,
            'text'    => $text,
        ]);

        return ['ok' => true, 'error' => '', 'provider_id' => 'log'];
    }

    /**
     * PHP's mail(). Works on virtually every cPanel host with no
     * configuration, which is why it is the sensible default to offer first,
     * despite worse deliverability than authenticated SMTP.
     *
     * @param array<string,string> $headers
     * @return array{ok:bool,error:string,provider_id:?string}
     */
    private static function deliverViaPhpMail(
        string $to,
        string $toName,
        string $subject,
        string $html,
        string $text,
        array $headers,
        string $fromEmail,
        string $fromName,
        string $replyTo
    ): array {
        if (!function_exists('mail')) {
            return ['ok' => false, 'error' => 'php_mail_unavailable', 'provider_id' => null];
        }

        $boundary = 'cc' . bin2hex(random_bytes(16));

        $allHeaders = array_merge([
            'MIME-Version' => '1.0',
            'Content-Type' => 'multipart/alternative; boundary="' . $boundary . '"',
            'From'         => self::formatAddress($fromEmail, $fromName),
            'Reply-To'     => $replyTo,
            'Date'         => gmdate('r'),
        ], $headers);

        $body = self::multipartBody($boundary, $text, $html);

        // The -f parameter sets the envelope sender, which some hosts need for
        // SPF to pass. Only safe because $fromEmail is validated below.
        $envelope = '';

        if (filter_var($fromEmail, FILTER_VALIDATE_EMAIL) !== false) {
            $envelope = '-f' . $fromEmail;
        }

        $sent = @mail(
            self::formatAddress($to, $toName),
            self::encodeHeaderValue($subject),
            $body,
            self::flattenHeaders($allHeaders),
            $envelope
        );

        return $sent
            ? ['ok' => true, 'error' => '', 'provider_id' => null]
            : ['ok' => false, 'error' => 'php_mail_returned_false', 'provider_id' => null];
    }

    /**
     * Authenticated SMTP over a raw socket.
     *
     * Written here rather than pulled in, per the project's zero-dependency
     * constraint. Supports implicit TLS (465), STARTTLS (587), AUTH LOGIN and
     * AUTH PLAIN — which covers every cPanel mail server and the common
     * third-party relays.
     *
     * @param array<string,string> $headers
     * @return array{ok:bool,error:string,provider_id:?string}
     */
    private static function deliverViaSmtp(
        string $to,
        string $toName,
        string $subject,
        string $html,
        string $text,
        array $headers,
        string $fromEmail,
        string $fromName,
        string $replyTo
    ): array {
        $host       = (string) Settings::get('mail.smtp_host', '');
        $port       = (int) Settings::get('mail.smtp_port', 587);
        $username   = (string) Settings::get('mail.smtp_username', '');
        $password   = (string) Settings::get('mail.smtp_password', '');
        $encryption = (string) Settings::get('mail.smtp_encryption', 'tls');
        $timeout    = (int) Settings::get('mail.smtp_timeout', 15);

        if ($host === '') {
            return ['ok' => false, 'error' => 'smtp_not_configured', 'provider_id' => null];
        }

        $transport = $encryption === 'ssl' ? 'ssl://' : '';

        $context = stream_context_create([
            'ssl' => [
                // Certificate verification stays ON. A mail server with a bad
                // certificate is a mail server that might not be the one you
                // think you are handing a password to.
                'verify_peer'       => (bool) Settings::get('mail.smtp_verify_peer', true),
                'verify_peer_name'  => (bool) Settings::get('mail.smtp_verify_peer', true),
                'SNI_enabled'       => true,
            ],
        ]);

        $socket = @stream_socket_client(
            $transport . $host . ':' . $port,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            Log::error('SMTP connect failed', ['host' => $host, 'port' => $port, 'error' => $errstr]);

            return ['ok' => false, 'error' => 'smtp_connect_failed: ' . $errstr, 'provider_id' => null];
        }

        stream_set_timeout($socket, $timeout);

        $conversation = [];

        try {
            self::smtpExpect($socket, 220, $conversation);

            $helo = (string) Settings::get('mail.smtp_helo', self::heloName());

            self::smtpCommand($socket, 'EHLO ' . $helo, $conversation);
            $capabilities = self::smtpExpect($socket, 250, $conversation);

            if ($encryption === 'tls') {
                self::smtpCommand($socket, 'STARTTLS', $conversation);
                self::smtpExpect($socket, 220, $conversation);

                if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('STARTTLS negotiation failed');
                }

                // RFC 3207: the EHLO must be repeated after the upgrade,
                // because capabilities advertised in the clear are untrusted.
                self::smtpCommand($socket, 'EHLO ' . $helo, $conversation);
                $capabilities = self::smtpExpect($socket, 250, $conversation);
            }

            if ($username !== '') {
                self::smtpAuthenticate($socket, $capabilities, $username, $password, $conversation);
            }

            self::smtpCommand($socket, 'MAIL FROM:<' . $fromEmail . '>', $conversation);
            self::smtpExpect($socket, 250, $conversation);

            self::smtpCommand($socket, 'RCPT TO:<' . $to . '>', $conversation);
            // 251 is "will forward", which is a success.
            self::smtpExpect($socket, [250, 251], $conversation);

            self::smtpCommand($socket, 'DATA', $conversation);
            self::smtpExpect($socket, 354, $conversation);

            $boundary = 'cc' . bin2hex(random_bytes(16));

            $allHeaders = array_merge([
                'Date'         => gmdate('r'),
                'From'         => self::formatAddress($fromEmail, $fromName),
                'To'           => self::formatAddress($to, $toName),
                'Reply-To'     => $replyTo,
                'Subject'      => self::encodeHeaderValue($subject),
                'Message-ID'   => '<' . bin2hex(random_bytes(12)) . '@' . self::heloName() . '>',
                'MIME-Version' => '1.0',
                'Content-Type' => 'multipart/alternative; boundary="' . $boundary . '"',
            ], $headers);

            $data = self::flattenHeaders($allHeaders) . "\r\n"
                . self::multipartBody($boundary, $text, $html);

            // Dot-stuffing: a line that is a single period would otherwise end
            // the DATA section early and truncate the message.
            $data = preg_replace('/^\./m', '..', $data) ?? $data;

            fwrite($socket, $data . "\r\n.\r\n");
            self::smtpExpect($socket, 250, $conversation);

            self::smtpCommand($socket, 'QUIT', $conversation);

            fclose($socket);

            return ['ok' => true, 'error' => '', 'provider_id' => null];
        } catch (Throwable $e) {
            @fclose($socket);

            Log::error('SMTP conversation failed', [
                'host'  => $host,
                'error' => $e->getMessage(),
                // The transcript is the only way to debug a mail server, and
                // smtpCommand already redacts the credential lines.
                'trace' => array_slice($conversation, -8),
            ]);

            return ['ok' => false, 'error' => 'smtp_error: ' . $e->getMessage(), 'provider_id' => null];
        }
    }

    /**
     * @param resource            $socket
     * @param list<string>        $conversation
     */
    private static function smtpAuthenticate(
        mixed $socket,
        string $capabilities,
        string $username,
        string $password,
        array &$conversation
    ): void {
        $upper = strtoupper($capabilities);

        if (str_contains($upper, 'AUTH') && str_contains($upper, 'PLAIN')) {
            self::smtpCommand(
                $socket,
                'AUTH PLAIN ' . base64_encode("\0" . $username . "\0" . $password),
                $conversation,
                true
            );
            self::smtpExpect($socket, 235, $conversation);

            return;
        }

        self::smtpCommand($socket, 'AUTH LOGIN', $conversation);
        self::smtpExpect($socket, 334, $conversation);

        self::smtpCommand($socket, base64_encode($username), $conversation, true);
        self::smtpExpect($socket, 334, $conversation);

        self::smtpCommand($socket, base64_encode($password), $conversation, true);
        self::smtpExpect($socket, 235, $conversation);
    }

    /**
     * @param resource     $socket
     * @param list<string> $conversation
     */
    private static function smtpCommand(mixed $socket, string $command, array &$conversation, bool $secret = false): void
    {
        // A credential must never reach a log file, even a debug one.
        $conversation[] = '> ' . ($secret ? '[redacted]' : $command);

        if (fwrite($socket, $command . "\r\n") === false) {
            throw new RuntimeException('Could not write to the mail server.');
        }
    }

    /**
     * Read a reply and assert its status code.
     *
     * @param resource         $socket
     * @param int|list<int>    $expected
     * @param list<string>     $conversation
     */
    private static function smtpExpect(mixed $socket, int|array $expected, array &$conversation): string
    {
        $expected = is_array($expected) ? $expected : [$expected];
        $lines = [];

        while (true) {
            $line = fgets($socket, 1024);

            if ($line === false) {
                $meta = stream_get_meta_data($socket);

                throw new RuntimeException(
                    ($meta['timed_out'] ?? false) ? 'The mail server timed out.' : 'The mail server closed the connection.'
                );
            }

            $lines[] = rtrim($line, "\r\n");
            $conversation[] = '< ' . rtrim($line, "\r\n");

            // A multi-line reply has a hyphen after the code; the final line
            // has a space.
            if (strlen($line) >= 4 && $line[3] !== '-') {
                break;
            }

            if (count($lines) > 50) {
                throw new RuntimeException('The mail server sent an implausibly long reply.');
            }
        }

        $reply = implode("\n", $lines);
        $code  = (int) substr($lines[count($lines) - 1], 0, 3);

        if (!in_array($code, $expected, true)) {
            throw new RuntimeException(sprintf(
                'Expected %s but the server said: %s',
                implode(' or ', array_map('strval', $expected)),
                $reply
            ));
        }

        return $reply;
    }

    /**
     * Verify the configured driver works, without sending to a guest.
     *
     * @return array{ok:bool,detail:string}
     */
    public static function test(string $to): array
    {
        $driver = self::driver();

        if ($driver === 'log') {
            return [
                'ok' => true,
                'detail' => 'The log driver is selected, so nothing was sent over the network. '
                    . 'The message was written to storage/logs/ instead. Choose PHP mail() or SMTP to send for real.',
            ];
        }

        $result = self::send([
            'to'      => $to,
            'subject' => 'Castle CRM test message',
            'kind'    => self::TRANSACTIONAL,
            'html'    => '<p>This is a test from Castle CRM.</p>'
                . '<p>If you are reading it, the <strong>' . e($driver) . '</strong> driver works.</p>',
        ]);

        if ($result['sent']) {
            return [
                'ok' => true,
                'detail' => $result['redirected']
                    ? 'Sent — but Safe Mode redirected it to the owner address rather than ' . $to . '.'
                    : 'Sent to ' . $to . '. Check the inbox, including spam.',
            ];
        }

        return [
            'ok' => false,
            'detail' => 'Not sent: ' . $result['reason'] . '. See storage/logs/ for the server conversation.',
        ];
    }

    // -----------------------------------------------------------------
    // Test capture
    // -----------------------------------------------------------------

    public static function startCapturing(): void
    {
        self::$capturing = true;
        self::$captured = [];
    }

    public static function stopCapturing(): void
    {
        self::$capturing = false;
    }

    /** @return list<array<string,mixed>> */
    public static function captured(): array
    {
        return self::$captured;
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /** @return array{sent:bool,reason:string,message_id:?int,redirected:bool} */
    private static function refuse(string $reason, string $detail): array
    {
        Log::notice('Mail refused', ['reason' => $reason, 'detail' => $detail]);

        return ['sent' => false, 'reason' => $reason, 'message_id' => null, 'redirected' => false];
    }

    private static function isSuppressed(string $address): bool
    {
        try {
            return DB::exists(
                'SELECT id FROM {suppressions} WHERE channel = :c AND address = :a',
                ['c' => 'email', 'a' => $address]
            );
        } catch (Throwable) {
            // No suppression table yet (pre-install). Fail open only here:
            // refusing all mail would make the installer unable to send.
            return false;
        }
    }

    /** @return array{email_consent:bool,do_not_contact:bool}|null */
    private static function consentFor(int $contactId): ?array
    {
        $row = DB::first(
            'SELECT email_consent, do_not_contact FROM {contacts} WHERE id = :id AND deleted_at IS NULL',
            ['id' => $contactId]
        );

        if ($row === null) {
            return null;
        }

        return [
            'email_consent'  => (bool) $row['email_consent'],
            'do_not_contact' => (bool) $row['do_not_contact'],
        ];
    }

    private static function overFrequencyCap(int $contactId): bool
    {
        $cap = (int) Settings::get('system.message_cap_per_week', 4);

        if ($cap <= 0) {
            return false;
        }

        $recent = (int) DB::value(
            "SELECT COUNT(*) FROM {messages}
              WHERE contact_id = :id
                AND direction = 'outbound'
                AND created_at >= :since
                AND campaign_id IS NOT NULL",
            ['id' => $contactId, 'since' => gmdate('Y-m-d H:i:s', time() - 604800)]
        );

        return $recent >= $cap;
    }

    /** The address Safe Mode redirects to. */
    private static function ownerAddress(): ?string
    {
        $configured = Settings::get('mail.safe_mode_address');

        if (is_string($configured) && filter_var($configured, FILTER_VALIDATE_EMAIL) !== false) {
            return strtolower($configured);
        }

        try {
            $owner = DB::value(
                "SELECT u.email FROM {users} u
                   JOIN {roles} r ON r.id = u.role_id
                  WHERE r.slug = 'owner' AND u.is_active = 1 AND u.deleted_at IS NULL
                  ORDER BY u.id LIMIT 1"
            );

            return is_string($owner) && $owner !== '' ? strtolower($owner) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Make a redirected copy unmistakable, so nobody mistakes it for a real
     * guest message or reads it as though they were the recipient.
     *
     * @return array{0:string,1:string,2:string}
     */
    private static function markRedirected(
        string $html,
        string $text,
        string $subject,
        string $originalTo,
        string $kind
    ): array {
        $banner = '<div style="background:#fff4e5;border-left:4px solid #8a5a00;padding:12px 16px;'
            . 'margin:0 0 20px;font:14px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;color:#8a5a00">'
            . '<strong>Safe Mode — this was not sent to the guest.</strong><br>'
            . 'It would have gone to <strong>' . e($originalTo) . '</strong> (' . e($kind) . ').<br>'
            . 'Turn Safe Mode off in Settings → System when you are ready to message guests.'
            . '</div>';

        $textBanner = "=== SAFE MODE — NOT SENT TO THE GUEST ===\n"
            . 'Intended recipient: ' . $originalTo . ' (' . $kind . ")\n"
            . "Turn Safe Mode off in Settings > System when ready.\n"
            . str_repeat('=', 42) . "\n\n";

        return [
            $banner . $html,
            $textBanner . $text,
            '[Safe Mode → ' . $originalTo . '] ' . $subject,
        ];
    }

    /**
     * CAN-SPAM: bulk mail carries the sender's physical address and a working
     * unsubscribe link. Appended in the send path so a programmatic send
     * cannot omit it.
     *
     * @return array{0:string,1:string}
     */
    private static function appendCompliance(string $html, string $text, string $address, ?int $contactId): array
    {
        $venue = Settings::venueName();

        $postal = implode(', ', array_filter([
            Settings::get('venue.address_1'),
            Settings::get('venue.city'),
            Settings::get('venue.state'),
            Settings::get('venue.postal_code'),
        ]));

        $unsubscribe = self::absoluteUrl('/u/' . self::unsubscribeToken($address, $contactId));

        $html .= '<hr style="border:0;border-top:1px solid #e3e7ec;margin:28px 0 14px">'
            . '<p style="font:12px/1.6 -apple-system,Segoe UI,Roboto,sans-serif;color:#8b95a3">'
            . e($venue) . ($postal !== '' ? '<br>' . e($postal) : '')
            . '<br><a href="' . e($unsubscribe) . '" style="color:#8b95a3">Unsubscribe from these emails</a>'
            . '</p>';

        $text .= "\n\n" . str_repeat('-', 42) . "\n"
            . $venue . ($postal !== '' ? "\n" . $postal : '')
            . "\n\nUnsubscribe: " . $unsubscribe . "\n";

        return [$html, $text];
    }

    /**
     * A stable, unguessable unsubscribe token.
     *
     * Derived by HMAC from the address rather than stored, so a link keeps
     * working without a row per recipient, and cannot be enumerated.
     *
     * Public because a template preview has to render the same link the real
     * send would, and because the round trip against parseUnsubscribeToken()
     * is worth testing directly — a broken unsubscribe link is a compliance
     * failure, not a cosmetic one.
     */
    public static function unsubscribeToken(string $address, ?int $contactId): string
    {
        $key = (string) Config::get('app_key', 'castle');

        $payload = strtolower($address) . '|' . ($contactId ?? 0);

        return self::encodeToken(
            $payload . '|' . substr(hash_hmac('sha256', $payload, $key), 0, 32)
        );
    }

    /** URL-safe base64 without padding. The one place the encoding is defined. */
    private static function encodeToken(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * Verify and decode an unsubscribe token.
     *
     * @return array{address:string,contact_id:?int}|null
     */
    public static function parseUnsubscribeToken(string $token): ?array
    {
        $raw = base64_decode(strtr($token, '-_', '+/'), true);

        if ($raw === false) {
            return null;
        }

        // Accept ONLY the canonical encoding of those bytes.
        //
        // base64 is malleable at the tail: when the payload length is not a
        // multiple of three, the final character carries bits that no byte
        // uses, so several different strings decode to identical bytes. The
        // HMAC is computed over the DECODED payload, so every one of those
        // variants verifies. MEASURED on this token format: for 8 of the first
        // 100 contact ids, the last two characters of a token could be
        // replaced outright and it still parsed to the same address.
        //
        // It is not a forgery route — the signature still pins the address, so
        // a mutated token unsubscribes exactly the guest the real one would.
        // It is fixed because one link having many valid spellings defeats any
        // future attempt to log, revoke or rate-limit a particular token, and
        // because a security property that holds for most inputs is not one
        // you can test or rely on. Re-encoding and comparing costs nothing and
        // every link the application has ever issued is canonical already.
        if (!hash_equals(self::encodeToken($raw), $token)) {
            return null;
        }

        $parts = explode('|', $raw);

        if (count($parts) !== 3) {
            return null;
        }

        [$address, $contactId, $signature] = $parts;

        $expected = substr(
            hash_hmac('sha256', $address . '|' . $contactId, (string) Config::get('app_key', 'castle')),
            0,
            32
        );

        if (!hash_equals($expected, $signature)) {
            return null;
        }

        return [
            'address'    => $address,
            'contact_id' => (int) $contactId > 0 ? (int) $contactId : null,
        ];
    }

    /**
     * Write the message row before attempting delivery, so a crash mid-send
     * leaves a record rather than a silent gap.
     *
     * @param array<string,mixed> $message
     */
    private static function record(
        array $message,
        string $to,
        string $subject,
        string $html,
        string $text,
        bool $redirected
    ): ?int {
        try {
            return DB::insert('messages', [
                'conversation_id'   => $message['conversation_id'] ?? null,
                'contact_id'        => $message['contact_id'] ?? null,
                'direction'         => 'outbound',
                'channel'           => 'email',
                'from_address'      => Settings::get('mail.from_email', self::fallbackFrom()),
                'to_address'        => $to,
                'subject'           => $subject,
                'body'              => $html,
                'body_text'         => $text,
                'user_id'           => Auth::id(),
                'template_id'       => $message['template_id'] ?? null,
                'campaign_id'       => $message['campaign_id'] ?? null,
                'automation_run_id' => $message['automation_run_id'] ?? null,
                'status'            => 'queued',
                'provider'          => self::driver(),
                'error_message'     => $redirected ? 'Safe Mode: redirected to the owner' : null,
                // The price goes on the MESSAGE as well as the usage ledger,
                // because a campaign's actual cost is summed from these rows.
                // Recording it only in the ledger left every email campaign
                // reporting $0.00 beside its estimate however the venue had
                // priced email, which reads either as broken sending or as
                // free postage. The column is DECIMAL(10,4), so a tenth of a
                // cent per email survives to be summed rather than rounding
                // to nothing forty thousand times.
                'cost_cents'        => self::unitCostCents(),
                'created_at'        => now_utc(),
            ]);
        } catch (Throwable $e) {
            Log::error('Could not record an outbound message', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private static function markSent(?int $messageId, string $provider, ?string $providerId): void
    {
        if ($messageId === null) {
            return;
        }

        try {
            DB::update('messages', [
                'status'              => 'sent',
                'provider'            => $provider,
                'provider_message_id' => $providerId,
                'sent_at'             => now_utc(),
            ], 'id = :id', ['id' => $messageId]);
        } catch (Throwable $e) {
            Log::error('Could not mark a message sent', ['error' => $e->getMessage()]);
        }
    }

    private static function markFailed(?int $messageId, string $error): void
    {
        if ($messageId === null) {
            return;
        }

        try {
            DB::update('messages', [
                'status'        => 'failed',
                'error_message' => substr($error, 0, 500),
            ], 'id = :id', ['id' => $messageId]);
        } catch (Throwable $e) {
            Log::error('Could not mark a message failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * What one email costs, in cents.
     *
     * Already cents, unlike the SMS setting's thousandths — so it is used as
     * it stands. Negative is refused: a provider that pays the venue to send
     * mail does not exist, and a negative price would make a campaign's cost
     * fall as it reached more people.
     */
    private static function unitCostCents(): float
    {
        return max(0.0, (float) Settings::get('mail.cost_per_email_cents', 0));
    }

    /** Record the send against the usage ledger, so cost is visible. */
    private static function meter(?int $messageId): void
    {
        try {
            DB::insert('usage_ledger', [
                'channel'         => 'email',
                'direction'       => 'outbound',
                'units'           => 1,
                'unit_cost_cents' => self::unitCostCents(),
                'entity_type'     => 'messages',
                'entity_id'       => $messageId,
                'provider'        => self::driver(),
                'occurred_at'     => now_utc(),
            ]);
        } catch (Throwable $e) {
            Log::debug('Usage metering skipped', ['error' => $e->getMessage()]);
        }
    }

    // -----------------------------------------------------------------
    // MIME helpers
    // -----------------------------------------------------------------

    private static function multipartBody(string $boundary, string $text, string $html): string
    {
        return "--" . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode($text) . "\r\n\r\n"
            . "--" . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode($html) . "\r\n\r\n"
            . "--" . $boundary . "--\r\n";
    }

    /** @param array<string,string> $headers */
    private static function flattenHeaders(array $headers): string
    {
        $lines = [];

        foreach ($headers as $name => $value) {
            // Header injection guard: a newline in either half would let a
            // crafted subject or address add its own headers (an extra Bcc,
            // say). Strip rather than escape — a newline is never legitimate.
            $name  = (string) preg_replace('/[^A-Za-z0-9\-]/', '', $name);
            $value = str_replace(["\r", "\n", "\0"], '', $value);

            if ($name === '' || $value === '') {
                continue;
            }

            $lines[] = $name . ': ' . $value;
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    private static function formatAddress(string $email, string $name = ''): string
    {
        $email = str_replace(["\r", "\n", "\0", '<', '>'], '', $email);

        if ($name === '') {
            return $email;
        }

        return '"' . self::encodeHeaderValue(str_replace('"', '', $name)) . '" <' . $email . '>';
    }

    /**
     * Reduce a value to one line.
     *
     * CR, LF and NUL are removed rather than escaped or rejected: a header
     * that cannot contain them is the only safe header, and silently losing a
     * line break from a subject nobody meant to put there is better than
     * refusing a booking confirmation over it.
     */
    private static function singleLine(string $value): string
    {
        return trim(str_replace(["\r", "\n", "\0"], '', $value));
    }

    /** RFC 2047 encode a header value when it is not plain ASCII. */
    private static function encodeHeaderValue(string $value): string
    {
        $value = str_replace(["\r", "\n", "\0"], '', $value);

        if (preg_match('/[^\x20-\x7E]/', $value) !== 1) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    /** A readable plain-text alternative, for clients that want one. */
    public static function htmlToText(string $html): string
    {
        // Keep link targets, which are the one thing a text version loses.
        //
        // The URL goes in PARENTHESES, not angle brackets. The conventional
        // "label <url>" rendering is destroyed by the strip_tags() below,
        // which sees <https://…> as a tag and deletes it — so every link in
        // every plain-text alternative would silently lose its destination.
        $text = (string) preg_replace_callback(
            '#<a\b[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
            static function (array $m): string {
                $label = trim(strip_tags($m[3]));
                $href  = trim($m[2]);

                return $label === '' || $label === $href ? $href : $label . ' (' . $href . ')';
            },
            $html
        );

        $text = (string) preg_replace('#<(br|/p|/div|/h[1-6]|/li|/tr)\s*/?>#i', "\n", $text);
        $text = (string) preg_replace('#<li\b[^>]*>#i', '- ', $text);
        $text = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[ \t]+/', ' ', $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    private static function fallbackFrom(): string
    {
        $host = (string) Settings::get('venue.website', '');
        $domain = $host !== '' ? (parse_url($host, PHP_URL_HOST) ?: '') : '';

        if ($domain === '') {
            $domain = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $domain = explode(':', $domain)[0];
        }

        return 'no-reply@' . preg_replace('/^www\./', '', $domain);
    }

    private static function heloName(): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

        return explode(':', $host)[0] ?: 'localhost';
    }

    /**
     * An absolute URL, which mail needs — a relative link is useless in an
     * inbox. Falls back to the configured app_url, then the request host.
     */
    public static function absoluteUrl(string $path): string
    {
        return rtrim(self::baseUrl(), '/') . '/' . ltrim($path, '/');
    }

    /**
     * The base URL for a link in an email, from the most trustworthy source
     * available.
     *
     * The Host header is LAST, and that ordering is the point. A link in an
     * email is acted on away from the application, so whoever controls its host
     * controls where the recipient lands — and a password-reset link carries a
     * live token. With `app_url` unset (which the installer, config.sample.php
     * and the test runner all wrote as null), the old code took
     * $_SERVER['HTTP_HOST'] with no validation, so an attacker could POST
     * /forgot-password with `Host: attacker.test` and the owner would be mailed
     * a genuine reset link pointing at the attacker's domain — classic
     * reset-link poisoning, and the owner is used to clicking those.
     *
     * So: the URL the operator configured, else the website they typed into the
     * installer, else the host this install was reached on when it was set up,
     * and only then the request — which is logged when it happens, because at
     * that point the link's host is attacker-controllable input.
     */
    private static function baseUrl(): string
    {
        $configured = trim((string) Config::get('app_url', ''));

        if ($configured !== '') {
            return $configured;
        }

        foreach (['venue.website', 'system.install_url'] as $key) {
            $stored = trim((string) Settings::get($key, ''));

            if ($stored === '') {
                continue;
            }

            $host = self::hostOf($stored);

            if ($host !== '') {
                return (str_starts_with($stored, 'http://') ? 'http://' : 'https://')
                    . $host
                    . (defined('CC_BASE_PATH') ? CC_BASE_PATH : '');
            }
        }

        $scheme = 'https';

        try {
            $scheme = request()->isSecure() ? 'https' : 'http';
        } catch (Throwable) {
            // No request context (cron). https is the safer guess.
        }

        $host = self::hostOf((string) ($_SERVER['HTTP_HOST'] ?? ''));

        if ($host === '') {
            return $scheme . '://localhost' . (defined('CC_BASE_PATH') ? CC_BASE_PATH : '');
        }

        Log::warning('Building an email link from the request Host header', [
            'host' => $host,
            'fix'  => 'Set app_url in config/config.php, or the venue website in Settings, '
                . 'so a link in an email cannot be pointed elsewhere by a crafted request.',
        ]);

        return $scheme . '://' . $host . (defined('CC_BASE_PATH') ? CC_BASE_PATH : '');
    }

    /**
     * The host part of a configured URL or a Host header, or '' if it is not
     * one. Deliberately strict: a host is letters, digits, dots, hyphens and
     * an optional port, so a header carrying a path, a credential, a space or
     * a second host cannot reach a link.
     */
    private static function hostOf(string $value): string
    {
        $value = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', trim($value));
        $value = explode('/', $value)[0];

        // user:pass@host is legal in a URL and lets a crafted value hide the
        // real host behind an @; refuse rather than guess which half is meant.
        if ($value === '' || str_contains($value, '@')) {
            return '';
        }

        return preg_match('/^[A-Za-z0-9.-]+(:\d{1,5})?$/', $value) === 1 ? $value : '';
    }
}
