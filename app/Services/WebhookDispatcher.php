<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Http;
use App\Core\Log;
use Throwable;

/**
 * Outbound webhooks — the I/O half.
 *
 * `Webhooks` holds the rules; this queues, sends and records. The split is
 * the usual one and it matters here because the rules are what a receiver
 * has to reimplement to verify a call, so they have to be testable without a
 * network.
 *
 * **NOTHING IS SENT INSIDE THE REQUEST THAT FIRED THE EVENT.** `queue()`
 * writes a row and returns; cron sends it. A booking saved at the front desk
 * must not wait on somebody's Zapier endpoint — and if it did, a dead
 * endpoint would make the save appear to hang, which is how a member of staff
 * ends up clicking Save three times.
 *
 * **A DELIVERY IS RETRIED, NOT RESENT.** The same row, the same body, the
 * same delivery id. A receiver that is idempotent can deduplicate on that id;
 * one that is not would double-count every retry, and generating a fresh id
 * per attempt would take that choice away from them.
 *
 * **A SUBSCRIPTION THAT KEEPS FAILING SWITCHES ITSELF OFF.** Twenty
 * consecutive failed deliveries is an endpoint that went away — usually
 * because somebody deleted the Zap — and retrying it for ever costs a cron
 * tick every minute and buries the real failures in noise.
 */
final class WebhookDispatcher
{
    /** How many deliveries one cron tick will attempt. */
    public const BATCH = 25;

    /**
     * Queue this event for every subscription that wants it.
     *
     * Called from the event listener, so it runs inside the request that
     * fired the event — which is why it does no network I/O and catches
     * everything. A webhook subsystem must never be able to fail a booking.
     *
     * @param array<string,mixed> $payload
     * @return int How many deliveries were queued.
     */
    public static function queue(string $event, array $payload): int
    {
        try {
            $subscriptions = DB::all(
                'SELECT id, event, target_url FROM {webhooks} WHERE is_active = 1'
            );
        } catch (Throwable $e) {
            // An install mid-upgrade may not have the columns yet.
            Log::warning('Could not read webhook subscriptions', ['error' => $e->getMessage()]);

            return 0;
        }

        $queued = 0;
        $now    = time();

        foreach ($subscriptions as $subscription) {
            if (!Webhooks::matches((string) $subscription['event'], $event)) {
                continue;
            }

            try {
                $deliveryId = self::newDeliveryId();

                DB::insert('webhook_deliveries', [
                    'webhook_id'      => (int) $subscription['id'],
                    'event'           => $event,
                    'status'          => 'pending',
                    'payload'         => json_encode(
                        Webhooks::body($event, $payload, $deliveryId, $now),
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    ),
                    'attempts'        => 0,
                    // Due immediately: the next cron tick picks it up.
                    'next_attempt_at' => gmdate('Y-m-d H:i:s'),
                    'created_at'      => gmdate('Y-m-d H:i:s'),
                ]);

                $queued++;
            } catch (Throwable $e) {
                Log::error('Could not queue a webhook delivery', [
                    'webhook' => (int) $subscription['id'],
                    'event'   => $event,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return $queued;
    }

    /**
     * Send what is due. Called once a tick from cron.
     *
     * @param callable|null $budgetLeft Returns false when the tick is out of
     *     time. Checked between deliveries, so the worst overshoot is one
     *     endpoint's timeout rather than the whole batch's.
     * @return array{attempted:int,delivered:int,failed:int,gave_up:int}
     */
    public static function send(?callable $budgetLeft = null, int $batch = self::BATCH): array
    {
        $stats = ['attempted' => 0, 'delivered' => 0, 'failed' => 0, 'gave_up' => 0];

        $due = DB::all(
            "SELECT d.*, w.target_url, w.secret, w.is_active, w.consecutive_failures
               FROM {webhook_deliveries} d
               JOIN {webhooks} w ON w.id = d.webhook_id
              WHERE d.status = 'pending'
                AND d.next_attempt_at IS NOT NULL
                AND d.next_attempt_at <= :now
                AND w.is_active = 1
              ORDER BY d.next_attempt_at, d.id
              LIMIT " . max(1, $batch),
            ['now' => gmdate('Y-m-d H:i:s')]
        );

        foreach ($due as $delivery) {
            if ($budgetLeft !== null && !$budgetLeft()) {
                break;
            }

            $outcome = self::attempt($delivery);
            $stats['attempted']++;
            $stats[$outcome]++;
        }

        return $stats;
    }

    /**
     * One attempt at one delivery.
     *
     * @param array<string,mixed> $delivery
     * @return string delivered|failed|gave_up
     */
    public static function attempt(array $delivery): string
    {
        $body      = (string) ($delivery['payload'] ?? '{}');
        $timestamp = time();
        $secret    = (string) ($delivery['secret'] ?? '');
        $attempts  = (int) $delivery['attempts'] + 1;

        $payload = json_decode($body, true);
        $deliveryId = is_array($payload) ? (string) ($payload['id'] ?? '') : '';

        $result = Http::postRaw(
            (string) $delivery['target_url'],
            $body,
            [
                Webhooks::SIGNATURE_HEADER => Webhooks::sign($body, $timestamp, $secret),
                Webhooks::TIMESTAMP_HEADER => (string) $timestamp,
                Webhooks::EVENT_HEADER     => (string) $delivery['event'],
                Webhooks::DELIVERY_HEADER  => $deliveryId,
                'User-Agent'               => 'CastleCRM-Webhook/' . Webhooks::VERSION,
            ],
            // Resolve and pin: the URL is one an operator typed, and a
            // hostname can point anywhere.
            true
        );

        $now = gmdate('Y-m-d H:i:s');

        $update = [
            'attempts'        => $attempts,
            'last_attempt_at' => $now,
            'response_code'   => $result['status'] > 0 ? $result['status'] : null,
            'error'           => $result['error'] === null ? null : mb_substr((string) $result['error'], 0, 500),
            'response_body'   => mb_substr((string) $result['body'], 0, 500),
            'duration_ms'     => (int) $result['ms'],
        ];

        if ($result['ok']) {
            $update['status']          = 'delivered';
            $update['delivered_at']    = $now;
            $update['next_attempt_at'] = null;

            self::finish($delivery, $update, true);

            return 'delivered';
        }

        $retryable = Webhooks::isRetryable((int) $result['status']);
        $delay     = $retryable ? Webhooks::nextDelaySeconds($attempts) : null;

        if ($delay === null) {
            /*
             * Done trying. `failed` when the receiver rejected it outright
             * (a 4xx it will reject identically for ever) and `gave_up` when
             * we ran out of attempts on something transient — the two send a
             * venue to different places, so they are not the same word.
             */
            $update['status']          = $retryable ? 'gave_up' : 'failed';
            $update['next_attempt_at'] = null;

            self::finish($delivery, $update, false);

            return $retryable ? 'gave_up' : 'failed';
        }

        $update['next_attempt_at'] = gmdate('Y-m-d H:i:s', time() + $delay);

        DB::update('webhook_deliveries', $update, 'id = :id', ['id' => (int) $delivery['id']]);

        return 'failed';
    }

    /**
     * Write the final state, and move the subscription's failure counter.
     *
     * @param array<string,mixed> $delivery
     * @param array<string,mixed> $update
     */
    private static function finish(array $delivery, array $update, bool $succeeded): void
    {
        DB::update('webhook_deliveries', $update, 'id = :id', ['id' => (int) $delivery['id']]);

        $webhookId = (int) $delivery['webhook_id'];

        if ($succeeded) {
            DB::update('webhooks', [
                'consecutive_failures' => 0,
                'last_delivery_at'     => gmdate('Y-m-d H:i:s'),
                'disabled_reason'      => null,
            ], 'id = :id', ['id' => $webhookId]);

            return;
        }

        $failures = (int) ($delivery['consecutive_failures'] ?? 0) + 1;

        $changes = [
            'consecutive_failures' => $failures,
            'last_delivery_at'     => gmdate('Y-m-d H:i:s'),
        ];

        if ($failures >= Webhooks::DISABLE_AFTER) {
            /*
             * Switched off rather than retried for ever. An endpoint that has
             * refused twenty consecutive deliveries is gone, and the reason
             * is RECORDED — a subscription that silently stopped working is
             * the thing a venue discovers months later.
             */
            $changes['is_active']       = 0;
            $changes['disabled_reason'] = 'Switched off automatically after '
                . Webhooks::DISABLE_AFTER . ' deliveries in a row failed. '
                . 'Fix the endpoint and switch it back on.';

            Log::warning('A webhook subscription was disabled after repeated failures', [
                'webhook'  => $webhookId,
                'failures' => $failures,
            ]);
        }

        DB::update('webhooks', $changes, 'id = :id', ['id' => $webhookId]);
    }

    /**
     * Send one delivery again, by hand, from the page.
     *
     * A NEW delivery row rather than a reset of the old one: the log is what
     * a venue reads to understand what happened, and overwriting the failure
     * with a success would erase the evidence of the outage they are
     * investigating.
     */
    public static function replay(int $deliveryId): ?int
    {
        $original = DB::first(
            'SELECT * FROM {webhook_deliveries} WHERE id = :id',
            ['id' => $deliveryId]
        );

        if ($original === null) {
            return null;
        }

        return DB::insert('webhook_deliveries', [
            'webhook_id'      => (int) $original['webhook_id'],
            'event'           => (string) $original['event'],
            'status'          => 'pending',
            // The SAME body, including the same delivery id, so a receiver
            // deduplicating on it recognises the replay for what it is.
            'payload'         => (string) $original['payload'],
            'attempts'        => 0,
            'next_attempt_at' => gmdate('Y-m-d H:i:s'),
            'created_at'      => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Send a sample delivery, now, and report what happened.
     *
     * Synchronous on purpose: this is the Test button, and somebody is
     * watching it. It writes a delivery row like any other so the attempt
     * appears in the log beside the real ones.
     *
     * @return array<string,mixed>
     */
    public static function test(int $webhookId): array
    {
        $webhook = DB::first('SELECT * FROM {webhooks} WHERE id = :id', ['id' => $webhookId]);

        if ($webhook === null) {
            return ['ok' => false, 'error' => 'That subscription is not there any more.'];
        }

        $deliveryId = self::newDeliveryId();
        $now        = time();

        $body = json_encode(
            Webhooks::body('webhook.test', ['id' => 0, 'type' => 'test'], $deliveryId, $now),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        $id = DB::insert('webhook_deliveries', [
            'webhook_id'      => $webhookId,
            'event'           => 'webhook.test',
            'status'          => 'pending',
            'payload'         => $body,
            'attempts'        => 0,
            'next_attempt_at' => gmdate('Y-m-d H:i:s'),
            'created_at'      => gmdate('Y-m-d H:i:s'),
        ]);

        $delivery = DB::first(
            'SELECT d.*, w.target_url, w.secret, w.consecutive_failures
               FROM {webhook_deliveries} d JOIN {webhooks} w ON w.id = d.webhook_id
              WHERE d.id = :id',
            ['id' => $id]
        );

        $outcome = self::attempt($delivery ?? []);

        /*
         * A TEST IS ONE ATTEMPT, NOT SIX.
         *
         * `attempt()` schedules a retry on a transient failure, which is right
         * for a real event and wrong for this: somebody pressed a button, read
         * the answer, and is about to go and fix their endpoint. Leaving the
         * row pending means cron quietly pokes a URL that was just reported
         * broken another five times over nine hours, fills the log with
         * repeats of a failure the operator already knows about, and shows
         * "1 waiting" against a subscription nothing is waiting on.
         *
         * MEASURED on the page: one press of Test left exactly that.
         *
         * So the row is closed here. Pressing Test again is the retry.
         */
        DB::query(
            "UPDATE {webhook_deliveries}
                SET status = CASE WHEN status = 'pending' THEN 'failed' ELSE status END,
                    next_attempt_at = NULL
              WHERE id = :id",
            ['id' => $id]
        );

        $after = DB::first(
            'SELECT status, response_code, error, response_body, duration_ms
               FROM {webhook_deliveries} WHERE id = :id',
            ['id' => $id]
        ) ?? [];

        return [
            'ok'       => $outcome === 'delivered',
            'outcome'  => $outcome,
            'status'   => $after['response_code'] ?? null,
            'error'    => $after['error'] ?? null,
            'body'     => $after['response_body'] ?? null,
            'ms'       => $after['duration_ms'] ?? null,
            'delivery' => $id,
        ];
    }

    /**
     * Drop old deliveries.
     *
     * Only ones that have FINISHED: a pending delivery is work still to do,
     * however old the row is, and pruning it would lose a party notification
     * that an outage delayed.
     */
    public static function prune(int $keepDays = Webhooks::KEEP_DAYS): int
    {
        return DB::delete(
            'webhook_deliveries',
            "status <> 'pending' AND created_at < :cutoff",
            ['cutoff' => gmdate('Y-m-d H:i:s', time() - ($keepDays * 86400))]
        );
    }

    /**
     * A delivery id a receiver can deduplicate on.
     *
     * Not the row id: a replay reuses the body, so the id in it has to stay
     * the same across both rows — and an auto-increment cannot do that.
     */
    private static function newDeliveryId(): string
    {
        return 'whd_' . bin2hex(random_bytes(12));
    }
}
