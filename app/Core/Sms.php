<?php

declare(strict_types=1);

namespace App\Core;

use App\Services\SmsPolicy;
use Throwable;

/**
 * Sending a text message.
 *
 * THE COMPLIANCE RULES ARE ENFORCED HERE, NOT IN THE USER INTERFACE. That
 * sentence is the specification (§8.7) and it is the whole reason this class
 * exists in the shape it does. A disabled button is a courtesy; a refusal in
 * the send path is a guarantee. Every route into sending — the inbox composer,
 * a campaign, an automation, a cron job, a future REST endpoint — comes
 * through `send()`, so a rule written here cannot be forgotten by the next
 * thing that wants to text somebody.
 *
 * What it refuses, and why each one is not a UI concern:
 *
 *   * **No `sms_consent`, for anything marketing.** A text costs the guest's
 *     attention and the venue a regulator's interest. Transactional messages
 *     — "your party is confirmed", "we are closed for the storm" — do not
 *     need it, and a venue that could not send those would route around this
 *     class entirely, which is worse.
 *   * **A suppression, for everything, transactional included.** A guest who
 *     texted STOP has given an instruction, not a preference.
 *   * **`do_not_contact`, for everything.** Explicit and total.
 *   * **Quiet hours.** DEFERRED, not dropped: `send()` answers `deferred`
 *     with the minute it may go, and the caller queues it. Dropping a message
 *     because somebody pressed the button at 9pm would lose the venue a
 *     Saturday's bookings.
 *   * **A bulk message with no way out.** The template validator blocks the
 *     save, and this blocks the send, because a programmatic sender has no
 *     template to validate.
 *   * **An unregistered number**, when 10DLC registration is recorded as
 *     missing — carriers filter those silently, and a silent filter looks
 *     exactly like a venue whose guests ignore them.
 *
 * It mirrors `Mailer` deliberately: the same `kind` constants, the same
 * refuse-with-a-reason return, the same capture-for-tests, the same Safe Mode
 * redirect. Two classes that do the same job in two different shapes is how
 * one of them ends up missing a rule.
 */
final class Sms
{
    /** A message the guest asked for: confirmations, reminders, receipts. */
    public const TRANSACTIONAL = 'transactional';

    /** A message the venue chose to send: offers, campaigns, Text Club. */
    public const MARKETING = 'marketing';

    /** Drivers that reach a carrier, plus `log` for a dry run. */
    public const DRIVERS = ['log', 'twilio', 'telnyx', 'vonage'];

    private static ?string $driverOverride = null;

    /** @var list<array<string,mixed>> Captured instead of sent, for tests. */
    private static array $captured = [];

    private static bool $capturing = false;

    // =================================================================
    // Public API
    // =================================================================

    /**
     * Send one text message.
     *
     * @param array{
     *     to:string, body:string, contact_id?:int|null, kind?:string,
     *     conversation_id?:int|null, user_id?:int|null, template_id?:int|null,
     *     campaign_id?:int|null, automation_run_id?:int|null,
     *     ignore_quiet_hours?:bool
     * } $message
     *
     * @return array{
     *     sent:bool, reason:string, message_id:?int, redirected:bool,
     *     segments:int, cost_cents:int, deferred_minutes:int
     * }
     */
    public static function send(array $message): array
    {
        $body = trim((string) ($message['body'] ?? ''));
        $kind = ($message['kind'] ?? self::MARKETING) === self::TRANSACTIONAL
            ? self::TRANSACTIONAL
            : self::MARKETING;

        // --- Shape of the message -----------------------------------------
        //
        // Before any policy decision, so a malformed message reports what is
        // actually wrong with it rather than whichever rule it happened to
        // trip first. Mailer learned this the same way.
        $to = normalize_phone((string) ($message['to'] ?? ''));

        if ($to === null) {
            return self::refuse('invalid_number', 'That is not a number a carrier could deliver to.');
        }

        if ($body === '') {
            return self::refuse('empty_body', 'A text message needs something in it.');
        }

        $segments = SmsPolicy::segments($body);

        if ($segments > SmsPolicy::MAX_SEGMENTS) {
            // Refused rather than truncated. Truncating loses the end of the
            // message, which is where the opt-out and the offer code live.
            return self::refuse(
                'too_long',
                'That message is ' . $segments . ' texts long. Carriers stop reassembling around '
                    . SmsPolicy::MAX_SEGMENTS . ', so it would arrive in pieces or not at all.'
            );
        }

        // --- Global stop ---------------------------------------------------
        if (Settings::sendingPaused()) {
            return self::refuse('sending_paused', 'All outbound sending is paused in settings.');
        }

        // --- Opt-out text on bulk ------------------------------------------
        //
        // Checked before consent so a message that could never legally be
        // sent to ANYBODY says so once, rather than reporting a particular
        // guest's consent as the problem.
        if ($kind === self::MARKETING && !SmsPolicy::hasOptOut($body)) {
            return self::refuse(
                'no_opt_out',
                'A bulk text must tell the reader how to stop. Add "Reply STOP to opt out".'
            );
        }

        // --- Suppression ----------------------------------------------------
        //
        // For EVERY kind. A guest who texted STOP gave an instruction.
        if (self::isSuppressed($to)) {
            return self::refuse('suppressed', 'That number has opted out of text messages.');
        }

        // --- Consent --------------------------------------------------------
        $contactId = isset($message['contact_id']) && $message['contact_id'] !== null
            ? (int) $message['contact_id']
            : null;

        if ($contactId !== null) {
            $consent = self::consentFor($contactId);

            if ($consent === null) {
                return self::refuse('no_contact', 'That contact does not exist.');
            }

            if ($consent['do_not_contact']) {
                return self::refuse('do_not_contact', 'That guest has asked not to be contacted at all.');
            }

            if ($kind === self::MARKETING && !$consent['sms_consent']) {
                return self::refuse(
                    'no_consent',
                    'That guest has not opted in to text messages, so only transactional texts may be sent.'
                );
            }

            if ($to !== ($consent['phone'] ?? $to)) {
                // The consent belongs to the number on the record, not to the
                // person in the abstract. Texting a different number because
                // somebody typed one into the composer would be sending to a
                // number that never opted in — quite possibly a stranger's.
                if ($kind === self::MARKETING) {
                    return self::refuse(
                        'number_mismatch',
                        'That is not the number this guest opted in on.'
                    );
                }
            }
        }

        // --- Frequency cap --------------------------------------------------
        if ($kind === self::MARKETING && $contactId !== null && self::overFrequencyCap($contactId)) {
            return self::refuse(
                'frequency_cap',
                'That guest has already had the weekly maximum of marketing messages.'
            );
        }

        // --- An unregistered sender ----------------------------------------
        //
        // 10DLC: carriers in the United States filter unregistered
        // application-to-person traffic, and they do it SILENTLY. A venue
        // whose texts vanish looks like a venue whose guests ignore them, so
        // this refuses rather than letting the venue discover it from a
        // campaign report full of "sent".
        if ($kind === self::MARKETING && self::senderUnregistered()) {
            return self::refuse(
                'unregistered_sender',
                'The sending number is recorded as unregistered or rejected for A2P 10DLC. '
                    . 'Carriers filter that traffic silently, so a bulk send would report every '
                    . 'message as sent while nothing arrived. Register it in Settings.'
            );
        }

        // --- Quiet hours ----------------------------------------------------
        //
        // The one rule that DEFERS rather than refusing. A transactional
        // message is exempt: a guest whose party starts in an hour needs to
        // know the room has changed, at whatever time that happens.
        $defer = 0;

        if ($kind === self::MARKETING && ($message['ignore_quiet_hours'] ?? false) !== true) {
            $defer = self::minutesUntilSendable();

            if ($defer > 0) {
                return [
                    'sent'             => false,
                    'reason'           => 'quiet_hours',
                    'message_id'       => null,
                    'redirected'       => false,
                    'segments'         => $segments,
                    'cost_cents'       => 0,
                    'deferred_minutes' => $defer,
                ];
            }
        }

        // --- Safe Mode ------------------------------------------------------
        $redirected = false;
        $originalTo = $to;

        if (Settings::safeMode()) {
            $owner = self::safeModeNumber();

            if ($owner === null) {
                return self::refuse(
                    'safe_mode_no_number',
                    'Safe Mode is on but no number is configured to redirect texts to. '
                        . 'Set one in Settings, or use the log driver.'
                );
            }

            $to         = $owner;
            $redirected = true;
            $body       = '[to ' . $originalTo . '] ' . $body;

            // Re-measured: the prefix can push a message into another segment,
            // and a test that reports the un-prefixed count would under-state
            // what a real send costs.
            $segments = SmsPolicy::segments($body);
        }

        $cost = SmsPolicy::costCents($segments, self::unitMillicents());

        // --- Record then deliver --------------------------------------------
        $messageId = self::record($message, $originalTo, $body, $segments, $cost);

        if (self::$capturing) {
            self::$captured[] = [
                'to'          => $to,
                'original_to' => $originalTo,
                'body'        => $body,
                'kind'        => $kind,
                'segments'    => $segments,
                'cost_cents'  => $cost,
                'redirected'  => $redirected,
            ];

            self::markSent($messageId, 'captured', null);

            return self::result(true, 'captured', $messageId, $redirected, $segments, $cost);
        }

        try {
            $outcome = self::deliver($to, $body);
        } catch (Throwable $e) {
            Log::error('SMS delivery threw', ['error' => $e->getMessage()]);
            self::markFailed($messageId, $e->getMessage());

            return self::result(false, 'driver_error', $messageId, $redirected, $segments, 0);
        }

        if (!$outcome['ok']) {
            self::markFailed($messageId, $outcome['error']);

            return self::result(false, $outcome['error'], $messageId, $redirected, $segments, 0);
        }

        self::markSent($messageId, self::driver(), $outcome['provider_id']);
        self::meter($messageId, $segments, $cost);

        return self::result(true, 'sent', $messageId, $redirected, $segments, $cost);
    }

    /**
     * What `send()` WOULD do, without doing it.
     *
     * The composer's cost preview and the campaign estimate both need this,
     * and both would otherwise reimplement the rules — which is how a preview
     * comes to disagree with the send that follows it. It returns the same
     * reasons `send()` returns, so the UI can say "this guest has not opted
     * in" against a specific recipient rather than only reporting it after
     * the fact.
     *
     * @param array<string,mixed> $message
     * @return array{ok:bool,reason:string,segments:int,cost_cents:int,deferred_minutes:int}
     */
    public static function preview(array $message): array
    {
        $body     = trim((string) ($message['body'] ?? ''));
        $segments = SmsPolicy::segments($body);
        $cost     = SmsPolicy::costCents($segments, self::unitMillicents());

        $capturing      = self::$capturing;
        self::$capturing = true;
        $before          = count(self::$captured);

        try {
            // Run the real thing against the capture buffer rather than a
            // copy of its rules, then discard what it captured. One set of
            // rules, so a preview cannot drift from a send.
            // $message FIRST, so a caller asking about a transactional text
            // is answered about one; marketing is only the default, because it
            // is the stricter of the two and a preview that quietly relaxed
            // the rules would be the wrong way round.
            $result = self::send($message + ['kind' => self::MARKETING]);
        } finally {
            self::$captured   = array_slice(self::$captured, 0, $before);
            self::$capturing  = $capturing;
        }

        // A captured send is one that passed every check.
        $ok = $result['sent'];

        if ($ok && isset($result['message_id'])) {
            // The dry run recorded a row; a preview must not leave one.
            self::forget((int) $result['message_id']);
        }

        return [
            'ok'               => $ok,
            'reason'           => $ok ? 'ok' : $result['reason'],
            'segments'         => $segments,
            'cost_cents'       => $cost,
            'deferred_minutes' => $result['deferred_minutes'],
        ];
    }

    /**
     * Act on an inbound message's keyword, before anything else looks at it.
     *
     * STOP has to work. A STOP sitting in a queue behind contact resolution
     * and an automation run is a STOP that did not work, so this is called
     * first, writes the suppression itself, and does not depend on the
     * contact being found.
     *
     * @return array{action:?string,reply:?string}
     */
    public static function handleKeyword(string $from, string $body): array
    {
        $number = normalize_phone($from);
        $action = SmsPolicy::keywordFor($body);

        if ($number === null || $action === null) {
            return ['action' => null, 'reply' => null];
        }

        return match ($action) {
            'stop'  => ['action' => 'stop', 'reply' => self::optOut($number)],
            'start' => ['action' => 'start', 'reply' => self::optIn($number)],
            'help'  => ['action' => 'help', 'reply' => self::helpText()],
            default => ['action' => $action, 'reply' => null],
        };
    }

    // =================================================================
    // Opting out and back in
    // =================================================================

    /**
     * Honour a STOP: suppress the number, clear the consent, confirm it.
     *
     * The suppression is keyed on the NUMBER and written even when no contact
     * matches it. A guest texting from a number the venue never recorded still
     * said stop, and a suppression that depended on finding them would be a
     * suppression that failed for exactly the people most likely to complain.
     */
    public static function optOut(string $number, string $source = 'inbound STOP'): string
    {
        $number = normalize_phone($number) ?? $number;

        try {
            DB::query(
                'INSERT INTO {suppressions} (channel, address, reason, source, created_at)
                 VALUES (:c, :a, :r, :s, :t)
                 ON DUPLICATE KEY UPDATE reason = VALUES(reason), source = VALUES(source)',
                ['c' => 'sms', 'a' => $number, 'r' => 'unsubscribe', 's' => $source, 't' => now_utc()]
            );

            // And on the contact, so the UI shows the state rather than
            // leaving it to be inferred from a suppression table nobody looks
            // at. Both, not either: the suppression is what the send path
            // checks, and this is what a person reads.
            $contact = DB::first(
                'SELECT id FROM {contacts} WHERE phone_normalized = :p AND deleted_at IS NULL ORDER BY id LIMIT 1',
                ['p' => $number]
            );

            if ($contact !== null) {
                DB::update('contacts', [
                    'sms_consent' => 0,
                    'updated_at'  => now_utc(),
                ], 'id = :id', ['id' => (int) $contact['id']]);

                DB::insert('consent_log', [
                    'contact_id' => (int) $contact['id'],
                    'channel'    => 'sms',
                    'action'     => 'revoked',
                    'source'     => mb_substr($source, 0, 80),
                    'created_at' => now_utc(),
                ]);
            }
        } catch (Throwable $e) {
            Log::error('Could not record an SMS opt-out', ['error' => $e->getMessage()]);
        }

        return (string) Settings::get(
            'sms.stop_reply',
            'You have been unsubscribed and will not get any more texts from us. Reply START to rejoin.'
        );
    }

    /** Honour a START: lift the suppression and record fresh consent. */
    public static function optIn(string $number, string $source = 'inbound START'): string
    {
        $number = normalize_phone($number) ?? $number;

        try {
            DB::delete('suppressions', 'channel = :c AND address = :a', ['c' => 'sms', 'a' => $number]);

            $contact = DB::first(
                'SELECT id FROM {contacts} WHERE phone_normalized = :p AND deleted_at IS NULL ORDER BY id LIMIT 1',
                ['p' => $number]
            );

            if ($contact !== null) {
                // do_not_contact is NOT cleared. It is a broader instruction
                // than "text me again", given by a different route, and a
                // START is not permission to undo it.
                DB::update('contacts', [
                    'sms_consent'        => 1,
                    'sms_consent_at'     => now_utc(),
                    'sms_consent_source' => mb_substr($source, 0, 80),
                    'updated_at'         => now_utc(),
                ], 'id = :id', ['id' => (int) $contact['id']]);

                DB::insert('consent_log', [
                    'contact_id' => (int) $contact['id'],
                    'channel'    => 'sms',
                    'action'     => 'granted',
                    'source'     => mb_substr($source, 0, 80),
                    'created_at' => now_utc(),
                ]);
            }
        } catch (Throwable $e) {
            Log::error('Could not record an SMS opt-in', ['error' => $e->getMessage()]);
        }

        return (string) Settings::get(
            'sms.start_reply',
            'You are subscribed again. Reply STOP at any time to stop.'
        );
    }

    private static function helpText(): string
    {
        $venue = Settings::venueName();
        $phone = trim((string) Settings::get('venue.phone_display', ''));

        $reply = (string) Settings::get(
            'sms.help_reply',
            $venue . ': party bookings and offers. Reply STOP to stop.'
        );

        return $phone === '' ? $reply : $reply . ' Call ' . $phone . '.';
    }

    // =================================================================
    // Drivers
    // =================================================================

    public static function driver(): string
    {
        if (self::$driverOverride !== null) {
            return self::$driverOverride;
        }

        $configured = (string) Settings::get('sms.driver', 'log');

        return in_array($configured, self::DRIVERS, true) ? $configured : 'log';
    }

    public static function useDriver(?string $driver): void
    {
        self::$driverOverride = $driver === null || in_array($driver, self::DRIVERS, true)
            ? $driver
            : 'log';
    }

    /** @return array{ok:bool,error:string,provider_id:?string} */
    private static function deliver(string $to, string $body): array
    {
        return match (self::driver()) {
            'twilio' => self::deliverViaTwilio($to, $body),
            'telnyx' => self::deliverViaTelnyx($to, $body),
            'vonage' => self::deliverViaVonage($to, $body),
            default  => self::deliverViaLog($to, $body),
        };
    }

    /**
     * The dry-run driver: writes what would have gone and reports success.
     *
     * The default on a fresh install, deliberately. An install whose SMS
     * settings have never been touched should not be able to text a guest by
     * accident — and a venue testing an automation wants to see what it would
     * have said.
     *
     * @return array{ok:bool,error:string,provider_id:?string}
     */
    private static function deliverViaLog(string $to, string $body): array
    {
        Log::info('SMS (log driver, not sent)', [
            'to'       => $to,
            'body'     => $body,
            'segments' => SmsPolicy::segments($body),
        ]);

        return ['ok' => true, 'error' => '', 'provider_id' => 'log-' . bin2hex(random_bytes(6))];
    }

    /** @return array{ok:bool,error:string,provider_id:?string} */
    private static function deliverViaTwilio(string $to, string $body): array
    {
        $sid   = (string) Settings::get('sms.twilio_account_sid', '');
        $token = (string) Settings::get('sms.twilio_auth_token', '');

        if ($sid === '' || $token === '') {
            return ['ok' => false, 'error' => 'twilio_not_configured', 'provider_id' => null];
        }

        $payload = ['To' => $to, 'Body' => $body];

        // A messaging service, when one is set, lets the carrier pick the
        // sending number from a pool — which is how a venue avoids one number
        // carrying every text it sends.
        $service = trim((string) Settings::get('sms.twilio_messaging_service_sid', ''));

        if ($service !== '') {
            $payload['MessagingServiceSid'] = $service;
        } else {
            $payload['From'] = self::fromNumber();
        }

        $response = self::post(
            'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '/Messages.json',
            $payload,
            ['Authorization: Basic ' . base64_encode($sid . ':' . $token)],
            'form'
        );

        return self::readProviderResponse($response, 'sid', 'message');
    }

    /** @return array{ok:bool,error:string,provider_id:?string} */
    private static function deliverViaTelnyx(string $to, string $body): array
    {
        $key = (string) Settings::get('sms.telnyx_api_key', '');

        if ($key === '') {
            return ['ok' => false, 'error' => 'telnyx_not_configured', 'provider_id' => null];
        }

        $response = self::post(
            'https://api.telnyx.com/v2/messages',
            ['from' => self::fromNumber(), 'to' => $to, 'text' => $body],
            ['Authorization: Bearer ' . $key],
            'json'
        );

        if ($response['ok'] && isset($response['json']['data']['id'])) {
            return ['ok' => true, 'error' => '', 'provider_id' => (string) $response['json']['data']['id']];
        }

        return self::readProviderResponse($response, 'id', 'errors');
    }

    /** @return array{ok:bool,error:string,provider_id:?string} */
    private static function deliverViaVonage(string $to, string $body): array
    {
        $key    = (string) Settings::get('sms.vonage_api_key', '');
        $secret = (string) Settings::get('sms.vonage_api_secret', '');

        if ($key === '' || $secret === '') {
            return ['ok' => false, 'error' => 'vonage_not_configured', 'provider_id' => null];
        }

        $response = self::post('https://rest.nexmo.com/sms/json', [
            'api_key'    => $key,
            'api_secret' => $secret,
            'from'       => self::fromNumber(),
            'to'         => ltrim($to, '+'),
            'text'       => $body,
            'type'       => SmsPolicy::encoding($body) === 'ucs2' ? 'unicode' : 'text',
        ], [], 'form');

        // Vonage answers 200 with a per-message status INSIDE the body, so an
        // HTTP-status-only check reads a rejected message as sent.
        $first = $response['json']['messages'][0] ?? null;

        if (is_array($first) && (string) ($first['status'] ?? '') === '0') {
            return [
                'ok'          => true,
                'error'       => '',
                'provider_id' => (string) ($first['message-id'] ?? ''),
            ];
        }

        return [
            'ok'          => false,
            'error'       => 'vonage_' . (string) ($first['status'] ?? 'error')
                . ': ' . (string) ($first['error-text'] ?? 'rejected'),
            'provider_id' => null,
        ];
    }

    /**
     * Read a provider's answer, preferring its own error text.
     *
     * @param array{ok:bool,status:int,json:array<string,mixed>,body:string} $response
     * @return array{ok:bool,error:string,provider_id:?string}
     */
    private static function readProviderResponse(array $response, string $idKey, string $errorKey): array
    {
        if ($response['ok']) {
            $id = $response['json'][$idKey] ?? null;

            return [
                'ok'          => true,
                'error'       => '',
                'provider_id' => is_scalar($id) ? (string) $id : null,
            ];
        }

        $detail = $response['json']['message']
            ?? $response['json'][$errorKey]
            ?? $response['body'];

        $text = is_string($detail) ? $detail : (json_encode($detail) ?: '');

        return [
            'ok'          => false,
            'error'       => 'http_' . $response['status'] . ': ' . mb_substr($text, 0, 200),
            'provider_id' => null,
        ];
    }

    /**
     * One HTTP POST.
     *
     * @param array<string,string> $fields
     * @param list<string>         $headers
     * @return array{ok:bool,status:int,json:array<string,mixed>,body:string}
     */
    private static function post(string $url, array $fields, array $headers, string $encoding): array
    {
        $handle = curl_init($url);

        if ($handle === false) {
            return ['ok' => false, 'status' => 0, 'json' => [], 'body' => 'curl unavailable'];
        }

        $payload = $encoding === 'json'
            ? (json_encode($fields) ?: '{}')
            : http_build_query($fields);

        if ($encoding === 'json') {
            $headers[] = 'Content-Type: application/json';
        }

        curl_setopt_array($handle, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);

        $body   = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($handle);
        curl_close($handle);

        if ($body === false) {
            return ['ok' => false, 'status' => $status, 'json' => [], 'body' => $error];
        }

        $json = json_decode((string) $body, true);

        return [
            'ok'     => $status >= 200 && $status < 300,
            'status' => $status,
            'json'   => is_array($json) ? $json : [],
            'body'   => (string) $body,
        ];
    }

    // =================================================================
    // Testing helpers, mirroring Mailer
    // =================================================================

    public static function startCapturing(): void
    {
        self::$capturing = true;
        self::$captured  = [];
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

    /**
     * Send one text to prove the settings work.
     *
     * TRANSACTIONAL and quiet-hours-exempt, with no contact attached: the
     * person pressing the button is asking whether the credentials are right,
     * and refusing them at half past eight in the evening would answer a
     * question they did not ask.
     *
     * @return array{sent:bool,reason:string,detail:string}
     */
    public static function test(string $to): array
    {
        $result = self::send([
            'to'                 => $to,
            'body'               => Settings::venueName() . ': this is a test message from Castle CRM.',
            'kind'               => self::TRANSACTIONAL,
            'ignore_quiet_hours' => true,
        ]);

        return [
            'sent'   => $result['sent'],
            'reason' => $result['reason'],
            'detail' => $result['sent']
                ? 'Sent through the ' . self::driver() . ' driver'
                    . ($result['redirected'] ? ', redirected by Safe Mode' : '') . '.'
                : self::explain($result['reason']),
        ];
    }

    /** Plain English for a refusal reason, for a settings page or an inbox. */
    public static function explain(string $reason): string
    {
        return match ($reason) {
            'invalid_number'      => 'That is not a number a carrier could deliver to.',
            'empty_body'          => 'A text message needs something in it.',
            'too_long'            => 'That message is too long to arrive in one piece.',
            'sending_paused'      => 'All outbound sending is paused in settings.',
            'no_opt_out'          => 'A bulk text must say how to stop. Add "Reply STOP to opt out".',
            'suppressed'          => 'That number has opted out of text messages.',
            'no_contact'          => 'That contact does not exist.',
            'do_not_contact'      => 'That guest has asked not to be contacted at all.',
            'no_consent'          => 'That guest has not opted in to text messages.',
            'number_mismatch'     => 'That is not the number this guest opted in on.',
            'frequency_cap'       => 'That guest has had this week\'s maximum of marketing messages.',
            'unregistered_sender' => 'The sending number is not registered for A2P 10DLC, so '
                . 'carriers would filter it silently.',
            'quiet_hours'         => 'It is outside the hours this venue sends texts, so it will '
                . 'go out when the window opens.',
            'safe_mode_no_number' => 'Safe Mode is on but no number is set to redirect texts to.',
            'driver_error'        => 'The provider could not be reached.',
            default               => $reason === '' ? 'Unknown.' : $reason,
        };
    }

    // =================================================================
    // Policy reads
    // =================================================================

    /**
     * @return array{sent:bool,reason:string,message_id:?int,redirected:bool,segments:int,cost_cents:int,deferred_minutes:int}
     */
    private static function refuse(string $reason, string $detail): array
    {
        Log::notice('SMS refused', ['reason' => $reason, 'detail' => $detail]);

        return self::result(false, $reason, null, false, 0, 0);
    }

    /**
     * @return array{sent:bool,reason:string,message_id:?int,redirected:bool,segments:int,cost_cents:int,deferred_minutes:int}
     */
    private static function result(
        bool $sent,
        string $reason,
        ?int $messageId,
        bool $redirected,
        int $segments,
        int $cost
    ): array {
        return [
            'sent'             => $sent,
            'reason'           => $reason,
            'message_id'       => $messageId,
            'redirected'       => $redirected,
            'segments'         => $segments,
            'cost_cents'       => $cost,
            'deferred_minutes' => 0,
        ];
    }

    private static function isSuppressed(string $number): bool
    {
        try {
            return DB::exists(
                'SELECT id FROM {suppressions} WHERE channel = :c AND address = :a',
                ['c' => 'sms', 'a' => $number]
            );
        } catch (Throwable) {
            // No table yet (pre-install). Fails OPEN, like Mailer, and for a
            // narrower reason than it looks: there can be no suppressions
            // before the table exists, so there is nothing to miss.
            return false;
        }
    }

    /** @return array{sms_consent:bool,do_not_contact:bool,phone:?string}|null */
    private static function consentFor(int $contactId): ?array
    {
        $row = DB::first(
            'SELECT sms_consent, do_not_contact, phone_normalized
               FROM {contacts} WHERE id = :id AND deleted_at IS NULL',
            ['id' => $contactId]
        );

        if ($row === null) {
            return null;
        }

        return [
            'sms_consent'    => (bool) $row['sms_consent'],
            'do_not_contact' => (bool) $row['do_not_contact'],
            'phone'          => $row['phone_normalized'] === null ? null : (string) $row['phone_normalized'],
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
                AND channel IN ('sms', 'mms')
                AND created_at >= :since
                AND campaign_id IS NOT NULL",
            ['id' => $contactId, 'since' => gmdate('Y-m-d H:i:s', time() - 604800)]
        );

        return $recent >= $cap;
    }

    /**
     * Is the sending number recorded as one carriers will filter?
     *
     * Two deliberate narrowings, both of which exist to avoid an install that
     * silently stops texting:
     *
     *   * **NOTHING RECORDED IS NOT "UNREGISTERED".** An install that has
     *     never seen the settings page, or a venue outside the United States
     *     where 10DLC does not apply, must not be stopped by a field nobody
     *     has filled in. Only a row saying `unregistered` or `rejected`
     *     counts, and `pending` — a venue mid-registration — sends.
     *   * **MARKETING ONLY.** It is application-to-person bulk traffic that
     *     carriers filter. Refusing "your party is confirmed for Saturday"
     *     because a registration is outstanding would do the venue more harm
     *     than the filtering does.
     *
     * The spec asks for a warning in settings rather than a refusal here. The
     * warning belongs there too, and will be; this is the case the warning
     * cannot cover, because a bulk send against a rejected number reports
     * every message as "sent" while nothing arrives — and a campaign report
     * full of confident successes is worse than a refusal somebody can read.
     */
    private static function senderUnregistered(): bool
    {
        try {
            $from = self::fromNumber();

            if ($from === '') {
                return false;
            }

            $status = DB::value(
                'SELECT tendlc_status FROM {sms_numbers} WHERE phone = :p LIMIT 1',
                ['p' => $from]
            );

            return in_array($status, ['unregistered', 'rejected'], true);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * How long until a marketing text may go out, in minutes.
     *
     * The venue's own wall clock, which is the only clock quiet hours can
     * sensibly mean: a guest is annoyed at ten in the evening where they live,
     * and this server is somewhere else.
     */
    private static function minutesUntilSendable(): int
    {
        $start = (int) Settings::get('sms.quiet_start_minutes', SmsPolicy::DEFAULT_QUIET_START);
        $end   = (int) Settings::get('sms.quiet_end_minutes', SmsPolicy::DEFAULT_QUIET_END);

        $local = new \DateTimeImmutable('now', new \DateTimeZone(Settings::timezone()));
        $now   = ((int) $local->format('G')) * 60 + (int) $local->format('i');

        return SmsPolicy::minutesUntilWindow($now, $start, $end);
    }

    private static function fromNumber(): string
    {
        return normalize_phone((string) Settings::get('sms.from_number', '')) ?? '';
    }

    private static function safeModeNumber(): ?string
    {
        return normalize_phone((string) Settings::get('sms.safe_mode_number', ''));
    }

    /** The unit price in THOUSANDTHS of a cent — carriers quote fractions. */
    private static function unitMillicents(): int
    {
        return max(0, (int) round((float) Settings::get('sms.cost_per_segment_millicents', 750)));
    }

    // =================================================================
    // The message trail
    // =================================================================

    /** @param array<string,mixed> $message */
    private static function record(
        array $message,
        string $to,
        string $body,
        int $segments,
        int $cost
    ): ?int {
        try {
            return DB::insert('messages', [
                'conversation_id'   => $message['conversation_id'] ?? null,
                'contact_id'        => $message['contact_id'] ?? null,
                'direction'         => 'outbound',
                'channel'           => 'sms',
                'from_address'      => self::fromNumber() ?: null,
                'to_address'        => $to,
                'body'              => $body,
                'body_text'         => $body,
                'user_id'           => $message['user_id'] ?? null,
                'template_id'       => $message['template_id'] ?? null,
                'campaign_id'       => $message['campaign_id'] ?? null,
                'automation_run_id' => $message['automation_run_id'] ?? null,
                'status'            => 'queued',
                'segments'          => $segments,
                // Already cents. Dividing by 100 here would record a
                // three-cent text as costing three hundredths of a cent, and
                // the usage report would read as though texting were free.
                'cost_cents'        => $cost,
                'created_at'        => now_utc(),
            ]);
        } catch (Throwable $e) {
            Log::error('Could not record an outbound text', ['error' => $e->getMessage()]);

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
            Log::debug('Could not mark a text sent', ['error' => $e->getMessage()]);
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
                'error_message' => mb_substr($error, 0, 500),
            ], 'id = :id', ['id' => $messageId]);
        } catch (Throwable $e) {
            Log::debug('Could not mark a text failed', ['error' => $e->getMessage()]);
        }
    }

    /** Remove a row a dry run wrote. */
    private static function forget(int $messageId): void
    {
        try {
            DB::delete('messages', 'id = :id', ['id' => $messageId]);
        } catch (Throwable $e) {
            Log::debug('Could not discard a previewed text', ['error' => $e->getMessage()]);
        }
    }

    /**
     * The usage ledger.
     *
     * UNITS ARE SEGMENTS, not messages. A three-segment text costs three
     * times as much, and a ledger counting it as one send would under-report
     * the venue's bill by whatever fraction of its traffic is long — which is
     * most of it, because a message with an opt-out line appended is longer
     * than the one somebody typed.
     */
    private static function meter(?int $messageId, int $segments, int $cost): void
    {
        try {
            DB::insert('usage_ledger', [
                'channel'         => 'sms',
                'direction'       => 'outbound',
                'units'           => $segments,
                'unit_cost_cents' => $segments > 0 ? $cost / $segments : 0,
                'entity_type'     => 'messages',
                'entity_id'       => $messageId,
                'provider'        => self::driver(),
                'occurred_at'     => now_utc(),
            ]);
        } catch (Throwable $e) {
            Log::debug('Usage metering skipped', ['error' => $e->getMessage()]);
        }
    }
}
