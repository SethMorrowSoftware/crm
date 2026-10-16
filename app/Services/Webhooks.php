<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Event;

/**
 * Outbound webhooks — the rules, with no database, no clock and no network.
 *
 * What a receiver has to be able to do, and what this class exists to make
 * possible:
 *
 * **VERIFY THE CALL CAME FROM THIS VENUE.** A webhook endpoint is a URL on
 * the public internet that performs work when something POSTs to it. Without
 * a signature anybody who learns the URL can make the venue's integration
 * think a party was booked — and the URL leaks the first time it is pasted
 * into a support ticket. The signature is an HMAC-SHA256 over the RAW BODY,
 * not over a re-encoded copy: JSON key order and unicode escaping are not
 * stable across languages, so a receiver that re-encodes before verifying
 * gets a different string and every signature fails.
 *
 * **REFUSE A REPLAY.** The timestamp is INSIDE the signed string, so an
 * attacker who captures one delivery cannot resend it tomorrow with a fresh
 * timestamp — changing it invalidates the signature. A receiver rejects
 * anything older than its own tolerance, which is why the header carries the
 * timestamp separately as well.
 *
 * **KNOW WHETHER IT HAS SEEN THIS BEFORE.** Retries mean the same delivery
 * arrives more than once, so every delivery carries a stable `id`. A receiver
 * that is not idempotent will double-count, and this is the only thing that
 * lets it avoid that.
 */
final class Webhooks
{
    /** The header a receiver verifies. */
    public const SIGNATURE_HEADER = 'X-Castle-Signature';

    public const TIMESTAMP_HEADER = 'X-Castle-Timestamp';

    public const EVENT_HEADER = 'X-Castle-Event';

    public const DELIVERY_HEADER = 'X-Castle-Delivery';

    /** Scheme version, so the format can change without breaking receivers. */
    public const VERSION = 'v1';

    /**
     * How long to wait before each retry.
     *
     * **ONE ENTRY PER GAP, NOT PER ATTEMPT**, and that distinction is the
     * whole of a bug this shipped with: six attempts have five gaps between
     * them, so a six-long list indexed by "attempts so far" either skipped its
     * first entry or ran off its end. It skipped — the first retry waited five
     * minutes rather than the documented one, the list's first element was
     * dead code, and the schedule ran to thirteen hours instead of the nine
     * its own comment claimed. A unit test now pins every gap and the count.
     *
     * Front-loaded, because the overwhelming majority of failures are a
     * receiver restarting or a momentary network fault and are over within a
     * minute. The long tail exists for an endpoint whose host is down for an
     * afternoon.
     *
     * Six attempts over about nine hours. Longer than that and a venue is
     * being told about a party that happened yesterday, which is worse than
     * being told it was never delivered.
     *
     * @var list<int>
     */
    public const BACKOFF_MINUTES = [1, 5, 30, 120, 360];

    public const MAX_ATTEMPTS = 6;

    /**
     * Consecutive failed DELIVERIES before a subscription switches itself off.
     *
     * Not attempts — deliveries. A subscription whose endpoint has been gone
     * for twenty consecutive events is pointing at something that no longer
     * exists, and retrying it for ever costs a cron tick every minute and
     * fills the log with noise that hides the real failure.
     */
    public const DISABLE_AFTER = 20;

    /** Deliveries older than this are pruned. Long enough to investigate. */
    public const KEEP_DAYS = 30;

    /**
     * The exact bytes a receiver must verify.
     *
     * `{version}.{timestamp}.{body}` — the three joined by dots, which is a
     * separator none of them can contain (the timestamp is digits, the
     * version is a literal). Concatenating without one is how a signature
     * scheme becomes ambiguous: "1.2" + "34" and "1.23" + "4" are the same
     * string.
     */
    public static function signingString(string $body, int $timestamp): string
    {
        return self::VERSION . '.' . $timestamp . '.' . $body;
    }

    /** The signature, as a receiver computes it. */
    public static function sign(string $body, int $timestamp, string $secret): string
    {
        return self::VERSION . '=' . hash_hmac('sha256', self::signingString($body, $timestamp), $secret);
    }

    /**
     * Verify a signature, in constant time.
     *
     * Here because the test suite verifies what the sender produced — and
     * because it is the reference a receiver's author copies. `hash_equals`
     * rather than `===`: a comparison that returns early on the first wrong
     * byte leaks, over enough requests, how much of a guess was right.
     */
    public static function verify(
        string $body,
        int $timestamp,
        string $signature,
        string $secret,
        int $toleranceSeconds = 300,
        ?int $now = null
    ): bool {
        $now ??= time();

        // The timestamp is checked FIRST and on its own, so a captured
        // delivery cannot be replayed tomorrow even with its original valid
        // signature. Both directions: a clock far in the future is as much a
        // sign of tampering as one far in the past.
        if (abs($now - $timestamp) > $toleranceSeconds) {
            return false;
        }

        return hash_equals(self::sign($body, $timestamp, $secret), $signature);
    }

    /** A new subscription's secret: 32 bytes, hex, shown to the operator. */
    public static function newSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Does this subscription want this event?
     *
     * Three shapes, and the wildcards are what make a subscription worth
     * creating: an integration that wants "anything about a booking" should
     * not have to list nine events and then miss the tenth when it is added.
     *
     *   `*`               — everything
     *   `booking.*`       — every event in that namespace
     *   `booking.created` — exactly that
     */
    public static function matches(string $subscribed, string $event): bool
    {
        $subscribed = trim($subscribed);

        if ($subscribed === '' || $event === '') {
            return false;
        }

        if ($subscribed === '*') {
            return true;
        }

        if (str_ends_with($subscribed, '.*')) {
            $namespace = substr($subscribed, 0, -2);

            // The DOT matters: `booking.*` must not match `bookings.created`.
            return $event === $namespace || str_starts_with($event, $namespace . '.');
        }

        return $subscribed === $event;
    }

    /**
     * How long to wait before the next attempt, in seconds from NOW.
     *
     * `$attemptsSoFar` counts attempts already made, so 1 is "the first try
     * just failed" and the answer is the first gap. Null when there are no
     * attempts left — a caller that reads null as "retry immediately" turns a
     * dead endpoint into an infinite loop, which is why it is null rather
     * than 0.
     */
    public static function nextDelaySeconds(int $attemptsSoFar): ?int
    {
        if ($attemptsSoFar < 1 || $attemptsSoFar >= self::MAX_ATTEMPTS) {
            return null;
        }

        return self::BACKOFF_MINUTES[$attemptsSoFar - 1] * 60;
    }

    /**
     * Should a failure be retried at all?
     *
     * A 4xx means the receiver understood the request and rejected it, so
     * sending the identical body five more times will be rejected five more
     * times — it is a configuration problem at the far end, not a transient
     * one. 408 and 429 are the exceptions: both explicitly mean "try again".
     *
     * A 5xx, a timeout and a connection failure are all transient until
     * proven otherwise, so they retry.
     */
    public static function isRetryable(int $status): bool
    {
        if ($status === 0) {
            // No response at all: timeout, DNS, connection refused.
            return true;
        }

        if ($status === 408 || $status === 429) {
            return true;
        }

        return $status >= 500;
    }

    /**
     * The body a receiver gets.
     *
     * Deliberately thin: the event, when it happened, and the IDS involved —
     * not the records themselves.
     *
     * **IT CARRIES NO GUEST DATA**, and that is the decision this shape
     * rests on. A webhook target is a URL typed into a form, delivered to
     * over the public internet, and logged at the far end by software this
     * venue does not control. Posting a contact's name, email and phone to it
     * would make every subscription a standing export of guest records to
     * somewhere nobody audits. A receiver that needs the record fetches it
     * from `/api/v1`, with a token that can be scoped and revoked — which is
     * the whole reason that API exists.
     *
     * @param array<string,mixed> $payload The event's own payload.
     * @return array<string,mixed>
     */
    public static function body(string $event, array $payload, string $deliveryId, int $timestamp): array
    {
        return [
            'id'         => $deliveryId,
            'event'      => $event,
            'occurred_at' => gmdate('c', $timestamp),
            'data'       => self::idsOnly($payload),
            'version'    => self::VERSION,
        ];
    }

    /**
     * Keep the ids and the scalars that identify a thing; drop everything else.
     *
     * An event payload is whatever the firing code put in it, and over time
     * that has included names and amounts. Rather than auditing every
     * `Event::fire()` call for ever, the webhook takes only keys that are
     * plainly identifiers — and anything new is excluded by default, which is
     * the direction that fails safe.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function idsOnly(array $payload): array
    {
        $out = [];

        foreach ($payload as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (!is_scalar($value) && $value !== null) {
                continue;
            }

            // `*_id`, `id`, and a small list of harmless labels. A `status`
            // or a `type` tells a receiver which branch to take without
            // telling it anything about a person.
            $isIdentifier = $key === 'id'
                || str_ends_with($key, '_id')
                || in_array($key, ['status', 'type', 'channel', 'event', 'source', 'reason'], true);

            if ($isIdentifier) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * The events a subscription can be made for, for the page's picker.
     *
     * Deliverable events only — one for a trigger nothing fires would be a
     * subscription that never does anything, with nothing on the page to say
     * why.
     *
     * @return array<string,string>
     */
    public static function availableEvents(): array
    {
        $events = ['*' => 'Everything'];

        $namespaces = [];

        foreach (Event::CATALOG as $event => $label) {
            if (!Event::isDeliverable($event)) {
                continue;
            }

            $namespace = strstr($event, '.', true);

            if (is_string($namespace) && $namespace !== '') {
                $namespaces[$namespace] = true;
            }

            $events[$event] = (string) $label;
        }

        // The namespace wildcards, listed before the individual events in
        // each group so "anything about a booking" is the easy choice.
        $out = ['*' => 'Everything'];

        foreach (array_keys($namespaces) as $namespace) {
            $out[$namespace . '.*'] = 'Anything about ' . $namespace;
        }

        unset($events['*']);

        return $out + $events;
    }

    /** Is this a real, deliverable event or a wildcard over one? */
    public static function isSubscribable(string $subscribed): bool
    {
        $subscribed = trim($subscribed);

        if ($subscribed === '*') {
            return true;
        }

        if (str_ends_with($subscribed, '.*')) {
            $namespace = substr($subscribed, 0, -2);

            foreach (array_keys(Event::CATALOG) as $event) {
                if (Event::isDeliverable((string) $event)
                    && str_starts_with((string) $event, $namespace . '.')
                ) {
                    return true;
                }
            }

            return false;
        }

        return Event::isDeliverable($subscribed);
    }
}
