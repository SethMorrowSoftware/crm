<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\DB;
use App\Core\Log;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Core\Sms;
use Throwable;

/**
 * Inbound texts, as the provider delivers them.
 *
 * This is the other half of the SMS feature, and without it a guest who
 * texts the venue is shouting into a void: `InboxController::receive()`
 * composes a reply — the STOP confirmation, the Text Club invite — and
 * RETURNS it for the caller to deliver. This is that caller, and the only
 * one. (MEASURED while driving the Text Club: with no webhook, a guest could
 * text JOIN, be put on the list as pending, and never receive the
 * confirmation they were supposed to answer. The whole double opt-in dead
 * ended there, silently, with the invite composed and thrown away.)
 *
 * ## Why this endpoint is signature-verified and REFUSES when it cannot be
 *
 * A forged inbound message is not a nuisance, it is a liability. Anybody who
 * can POST here unverified can:
 *
 *   - **send a JOIN from somebody else's number**, which makes the venue text
 *     a stranger who never asked — the exact thing the double opt-in exists
 *     to prevent, now with the venue's own records saying they consented;
 *   - **send a STOP from a paying customer's number**, quietly cutting them
 *     off from their own booking reminders;
 *   - put words on a guest's conversation thread that the guest never wrote.
 *
 * So an unsigned request is refused, and — this is the part worth being
 * deliberate about — a request that CANNOT be verified because nothing is
 * configured is refused too. Accepting everything until a secret is set is
 * the default that ships broken: it works perfectly in testing and is wide
 * open in production, and nothing about the behaviour tells anybody which
 * state they are in.
 */
final class SmsWebhookController extends Controller
{
    /** Providers whose signature we know how to check. */
    public const VERIFIABLE = ['twilio', 'telnyx', 'secret'];

    /**
     * How far out of date a signed timestamp may be, in seconds.
     *
     * Five minutes. A signature with no freshness bound can be replayed for
     * ever — and a replayed STOP is as effective as the original.
     */
    public const MAX_SKEW = 300;

    public function receive(Request $request): Response
    {
        $driver = strtolower((string) Settings::get('sms.driver', 'log'));
        $check  = $this->verify($request, $driver);

        if ($check !== null) {
            // 403 and nothing else. The response says which check failed only
            // in the log: telling the sender whether the signature or the
            // timestamp was wrong is telling them how to get it right.
            Log::warning('Refused an inbound SMS webhook', [
                'reason' => $check,
                'driver' => $driver,
                'ip'     => $request->ip(),
            ]);

            return Response::json(['error' => 'Not accepted.'], 403);
        }

        $payload = $this->normalize($request, $driver);

        if ($payload === null) {
            // 200, deliberately. A payload we cannot read is not something
            // the provider should retry for ever — and a delivery receipt,
            // which is most of this traffic, legitimately carries no message.
            return Response::json(['ok' => true, 'handled' => false]);
        }

        try {
            $outcome = InboxController::receive([
                'channel'             => 'sms',
                'from'                => $payload['from'],
                'body'                => $payload['body'],
                'provider_message_id' => $payload['provider_message_id'],
            ]);
        } catch (Throwable $e) {
            Log::error('Could not take an inbound text', ['error' => $e->getMessage()]);

            // 500 so the provider retries: an inbound message dropped on the
            // floor is a guest whose question nobody ever sees.
            return Response::json(['error' => 'Could not record that.'], 500);
        }

        /*
         * THE REPLY IS SENT HERE, and as TRANSACTIONAL.
         *
         * Every one of these is an answer to a message the guest just sent:
         * a STOP confirmation, a HELP text, a Text Club invite. Sent as
         * marketing they would be refused for want of consent — and the
         * invite's entire purpose is to obtain that consent, while the STOP
         * confirmation has to reach somebody who has just withdrawn it.
         *
         * `ignore_quiet_hours` for the same reason: somebody who texts the
         * venue at eleven at night is awake, holding their phone, and waiting
         * for an answer. Deferring the confirmation of their own STOP until
         * nine in the morning is the one deferral that reads as the opt-out
         * having failed.
         */
        if (($outcome['reply'] ?? null) !== null) {
            Sms::send([
                'to'                 => $payload['from'],
                'body'               => (string) $outcome['reply'],
                'conversation_id'    => $outcome['conversation_id'],
                'kind'               => Sms::TRANSACTIONAL,
                'ignore_quiet_hours' => true,
                // A STOP suppresses the number, and the suppression would
                // then block the very message that confirms it. This is the
                // one place that has to override it, and it is why the flag
                // exists rather than being a general escape hatch.
                'ignore_suppression' => $outcome['keyword'] === 'stop',
            ]);
        }

        return Response::json([
            'ok'      => true,
            'handled' => true,
            'keyword' => $outcome['keyword'],
        ]);
    }

    // =================================================================
    // Verification
    // =================================================================

    /** The reason to refuse, or null to accept. */
    private function verify(Request $request, string $driver): ?string
    {
        return match ($driver) {
            'twilio' => $this->verifyTwilio($request),
            'telnyx' => $this->verifyTelnyx($request),
            default  => $this->verifySharedSecret($request),
        };
    }

    /**
     * Twilio's documented scheme.
     *
     * HMAC-SHA1 of the full request URL with every POST parameter appended in
     * key order, keyed on the account's auth token — which the venue has
     * already configured in order to SEND, so there is nothing extra to set
     * up and no second secret to get out of step.
     */
    private function verifyTwilio(Request $request): ?string
    {
        $token = (string) Settings::get('sms.twilio_auth_token', '');

        if ($token === '') {
            return 'no Twilio auth token is configured, so nothing can be verified';
        }

        $given = $request->header('X-Twilio-Signature');

        if ($given === '') {
            return 'no signature header';
        }

        $params = $request->postAll();
        ksort($params);

        $data = $this->publicUrl($request);

        foreach ($params as $key => $value) {
            // Only scalars take part, exactly as Twilio specifies. An array
            // parameter is not something this endpoint receives, and
            // stringifying one would make the signature unreproducible.
            if (is_array($value)) {
                continue;
            }

            $data .= $key . (string) $value;
        }

        $expected = base64_encode(hash_hmac('sha1', $data, $token, true));

        return hash_equals($expected, $given) ? null : 'the signature did not match';
    }

    /**
     * Telnyx signs with Ed25519 over "timestamp|body".
     *
     * Verified with `sodium_crypto_sign_verify_detached`, which is in PHP
     * core, so this needs no extension a cPanel host might not have.
     */
    private function verifyTelnyx(Request $request): ?string
    {
        $publicKey = trim((string) Settings::get('sms.telnyx_public_key', ''));

        if ($publicKey === '') {
            return 'no Telnyx public key is configured, so nothing can be verified';
        }

        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return 'this PHP build cannot check an Ed25519 signature';
        }

        $signature = $request->header('Telnyx-Signature-Ed25519');
        $timestamp = $request->header('Telnyx-Timestamp');

        if ($signature === '' || $timestamp === '') {
            return 'no signature or timestamp header';
        }

        if ($this->tooOld($timestamp)) {
            return 'the timestamp is outside the permitted window';
        }

        try {
            $ok = sodium_crypto_sign_verify_detached(
                (string) base64_decode($signature, true),
                $timestamp . '|' . $request->rawBody(),
                (string) base64_decode($publicKey, true)
            );
        } catch (Throwable $e) {
            return 'the signature could not be read: ' . $e->getMessage();
        }

        return $ok ? null : 'the signature did not match';
    }

    /**
     * The fallback: a shared secret the venue sets and its provider or
     * middleware sends back.
     *
     * For a provider this does not know, or a small script in front of one.
     * Signed over the raw body with a timestamp, so it cannot be replayed,
     * and compared in constant time. A plain `X-Webhook-Secret: value` header
     * would have been simpler and is a bearer token in a log file.
     */
    private function verifySharedSecret(Request $request): ?string
    {
        $secret = trim((string) Settings::get('sms.webhook_secret', ''));

        if ($secret === '') {
            return 'no inbound webhook secret is configured, so nothing can be verified';
        }

        $given     = $request->header('X-Castle-Signature');
        $timestamp = $request->header('X-Castle-Timestamp');

        if ($given === '' || $timestamp === '') {
            return 'no signature or timestamp header';
        }

        if ($this->tooOld($timestamp)) {
            return 'the timestamp is outside the permitted window';
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $request->rawBody(), $secret);

        return hash_equals($expected, $given) ? null : 'the signature did not match';
    }

    private function tooOld(string $timestamp): bool
    {
        $sent = (int) $timestamp;

        if ($sent <= 0) {
            return true;
        }

        return abs(time() - $sent) > self::MAX_SKEW;
    }

    /**
     * The URL the provider signed, which is the PUBLIC one.
     *
     * Behind a proxy or a load balancer the server sees `http` on some
     * internal hostname while the provider signed `https` and the real
     * domain, so a signature built from `$_SERVER` alone never matches. The
     * configured `app_url` is authoritative when it is set, exactly as the
     * `url()` helper treats it.
     */
    private function publicUrl(Request $request): string
    {
        $query      = (string) ($_SERVER['QUERY_STRING'] ?? '');
        $configured = Config::get('app_url');

        if (is_string($configured) && $configured !== '') {
            $base = rtrim($configured, '/') . $request->path();
        } else {
            $scheme = ($_SERVER['HTTPS'] ?? '') !== '' ? 'https' : 'http';
            $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $base   = $scheme . '://' . $host . $request->path();
        }

        return $query === '' ? $base : $base . '?' . $query;
    }

    // =================================================================
    // Reading the payload
    // =================================================================

    /**
     * One inbound message, however this provider words it.
     *
     * Returns null when there is no message to take — which is most of this
     * traffic. A delivery receipt is not an inbound text and must not land on
     * a guest's conversation as an empty one.
     *
     * @return array{from:string, body:string, provider_message_id:?string}|null
     */
    private function normalize(Request $request, string $driver): ?array
    {
        $all = $request->all();

        $from = trim((string) (
            $all['From']           // Twilio
            ?? $all['from']        // Telnyx (nested below), generic
            ?? $all['msisdn']      // Vonage
            ?? ''
        ));

        $body = (string) (
            $all['Body']           // Twilio
            ?? $all['text']        // Vonage, generic
            ?? $all['body']
            ?? ''
        );

        $id = $all['MessageSid'] ?? $all['SmsMessageSid'] ?? $all['messageId'] ?? $all['id'] ?? null;

        // Telnyx nests the message under data.payload, with `from` an object.
        if (isset($all['data']) && is_array($all['data'])) {
            $inner = $all['data']['payload'] ?? [];

            if (is_array($inner)) {
                $nestedFrom = $inner['from'] ?? null;
                $from = trim((string) (
                    is_array($nestedFrom) ? ($nestedFrom['phone_number'] ?? '') : (string) $nestedFrom
                )) ?: $from;
                $body = (string) ($inner['text'] ?? $body);
                $id   = $inner['id'] ?? $id;
            }

            // An event that is not an inbound message — a delivery receipt,
            // a failure — carries no text and belongs nowhere near a thread.
            $event = (string) ($all['data']['event_type'] ?? '');

            if ($event !== '' && $event !== 'message.received') {
                return null;
            }
        }

        if ($from === '') {
            return null;
        }

        // A Twilio delivery receipt posts a MessageStatus and no Body.
        if ($body === '' && ($all['MessageStatus'] ?? $all['SmsStatus'] ?? '') !== '') {
            return null;
        }

        return [
            'from'                => $from,
            'body'                => $body,
            'provider_message_id' => $id === null ? null : mb_substr((string) $id, 0, 191),
        ];
    }

    /**
     * Is inbound texting set up at all?
     *
     * Read by the settings page so an operator can see that the number is
     * configured to SEND but cannot RECEIVE — which is the state a venue
     * ends up in, and the one where STOP silently does not reply.
     */
    public static function configured(): bool
    {
        $driver = strtolower((string) Settings::get('sms.driver', 'log'));

        return match ($driver) {
            'twilio' => trim((string) Settings::get('sms.twilio_auth_token', '')) !== '',
            'telnyx' => trim((string) Settings::get('sms.telnyx_public_key', '')) !== '',
            default  => trim((string) Settings::get('sms.webhook_secret', '')) !== '',
        };
    }
}
