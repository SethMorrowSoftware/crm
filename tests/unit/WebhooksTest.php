<?php

declare(strict_types=1);

/**
 * The outbound-webhook rules.
 *
 * Everything here is what a RECEIVER has to be able to rely on — the signature
 * it verifies, the id it deduplicates on, the retry schedule it is subjected
 * to, and above all what the body does and does not contain. Those are
 * promises made to code outside this application, so they are tested as
 * promises: pinned exactly, with the reason written down, rather than left to
 * read correct.
 *
 * No database, no clock, no network.
 */

use App\Core\Event;
use App\Services\Webhooks;
use Tests\Assert;
use Tests\Harness;

Harness::suite('unit/webhooks');

// ------------------------------------------------------------- the signature

Harness::test('the signed string joins version, timestamp and body with dots', function (): void {
    Assert::same(
        'v1.1700000000.{"a":1}',
        Webhooks::signingString('{"a":1}', 1700000000)
    );

    /*
     * THE SEPARATOR IS THE POINT. Concatenated without one, a timestamp of
     * 12 and a body of "3..." is the same string as a timestamp of 123 and a
     * body of "..." — two different deliveries with one signature, which is
     * the classic way a signing scheme becomes forgeable.
     */
    Assert::notSame(
        Webhooks::signingString('3x', 12),
        Webhooks::signingString('x', 123)
    );
});

Harness::test('a signature round-trips and nothing else does', function (): void {
    $body   = '{"id":"whd_abc","event":"booking.created"}';
    $secret = 'a-secret';
    $at     = 1700000000;

    $signature = Webhooks::sign($body, $at, $secret);

    Assert::true(str_starts_with($signature, 'v1='), 'the scheme version is on the header');
    Assert::same(3 + 64, strlen($signature), 'v1= plus a hex sha256');

    Assert::true(
        Webhooks::verify($body, $at, $signature, $secret, 300, $at),
        'what we sent verifies'
    );

    // Each of the four inputs, changed one at a time.
    Assert::false(
        Webhooks::verify($body . ' ', $at, $signature, $secret, 300, $at),
        'a body changed by one byte does not'
    );
    Assert::false(
        Webhooks::verify($body, $at + 1, $signature, $secret, 300, $at),
        'a timestamp moved by a second does not — it is inside the signed string'
    );
    Assert::false(
        Webhooks::verify($body, $at, $signature, 'another-secret', 300, $at),
        'somebody else’s secret does not'
    );
    Assert::false(
        Webhooks::verify($body, $at, 'v1=' . str_repeat('0', 64), $secret, 300, $at),
        'nor does a guess'
    );
});

Harness::test('a captured delivery cannot be replayed tomorrow', function (): void {
    /*
     * The reason the timestamp is in the signed string rather than only in a
     * header. An attacker who captures one delivery holds a body and a
     * matching signature for ever; what they cannot do is produce a signature
     * for a FRESH timestamp. So the receiver's own clock closes the window.
     */
    $body   = '{"id":"whd_abc"}';
    $secret = 's';
    $at     = 1700000000;

    $signature = Webhooks::sign($body, $at, $secret);

    Assert::true(Webhooks::verify($body, $at, $signature, $secret, 300, $at + 299));
    Assert::false(
        Webhooks::verify($body, $at, $signature, $secret, 300, $at + 301),
        'five minutes later the same valid signature is refused'
    );

    // Both directions: a timestamp from the future is as much a sign of
    // tampering as one from the past.
    Assert::false(
        Webhooks::verify($body, $at, $signature, $secret, 300, $at - 301),
        'and a timestamp well ahead of the receiver’s clock is refused too'
    );
});

Harness::test('a secret is long enough to be a secret', function (): void {
    $a = Webhooks::newSecret();
    $b = Webhooks::newSecret();

    Assert::same(64, strlen($a), '32 bytes as hex');
    Assert::true((bool) preg_match('/^[0-9a-f]{64}$/', $a), 'hex, so it survives a copy and paste');
    Assert::notSame($a, $b, 'two subscriptions do not share one');
});

// --------------------------------------------------------- which events match

Harness::test('a subscription to one event matches only that event', function (): void {
    Assert::true(Webhooks::matches('booking.created', 'booking.created'));
    Assert::false(Webhooks::matches('booking.created', 'booking.updated'));
    Assert::false(Webhooks::matches('booking.created', 'booking.created.extra'));
});

Harness::test('a namespace wildcard needs the dot', function (): void {
    Assert::true(Webhooks::matches('booking.*', 'booking.created'));
    Assert::true(Webhooks::matches('booking.*', 'booking.cancelled'));

    /*
     * MEASURED while building this: `booking.*` must not match
     * `bookings.created`. A plain prefix test matches it, and the two are
     * different namespaces — one of them a plural somebody might add later.
     * Getting this wrong sends a venue's booking events to a subscription
     * that never asked for them.
     */
    Assert::false(Webhooks::matches('booking.*', 'bookings.created'));
    Assert::false(Webhooks::matches('booking.*', 'bookingsomething'));
    Assert::false(Webhooks::matches('booking.*', 'contact.created'));
});

Harness::test('the everything wildcard matches everything, and nothing matches nothing', function (): void {
    Assert::true(Webhooks::matches('*', 'booking.created'));
    Assert::true(Webhooks::matches('*', 'anything.at.all'));

    // An empty subscription or an empty event is a data problem, and the safe
    // reading of it is "do not send", not "send everything".
    Assert::false(Webhooks::matches('', 'booking.created'));
    Assert::false(Webhooks::matches('*', ''));
    Assert::false(Webhooks::matches('   ', 'booking.created'));

    // Whitespace around a stored value is how it arrives from a form.
    Assert::true(Webhooks::matches(' booking.created ', 'booking.created'));
});

Harness::test('only a real, deliverable event can be subscribed to', function (): void {
    /*
     * A subscription to an event nothing fires is a row that never delivers,
     * with nothing on the page to say why — so it is refused at save time
     * instead.
     */
    Assert::true(Webhooks::isSubscribable('*'));
    Assert::true(Webhooks::isSubscribable('contact.created'), 'a catalogued event');
    Assert::false(Webhooks::isSubscribable('contact.levitated'), 'an invented one');
    Assert::false(Webhooks::isSubscribable('nonsense.*'), 'a wildcard over nothing');
    Assert::false(Webhooks::isSubscribable(''));

    // The wildcard is subscribable exactly when something under it is.
    $deliverable = array_values(array_filter(
        array_keys(Event::CATALOG),
        static fn ($event): bool => Event::isDeliverable((string) $event)
    ));

    Assert::true($deliverable !== [], 'the catalogue has deliverable events');

    $namespace = strstr((string) $deliverable[0], '.', true);

    Assert::true(is_string($namespace) && $namespace !== '', 'events are namespaced');
    Assert::true(Webhooks::isSubscribable($namespace . '.*'));
});

Harness::test('the picker offers the wildcards and every deliverable event', function (): void {
    $events = Webhooks::availableEvents();

    Assert::same('Everything', $events['*'] ?? null, 'the catch-all is first');
    Assert::true(count($events) > 20, 'the catalogue is offered, not a sample');

    foreach (array_keys($events) as $slug) {
        Assert::true(
            Webhooks::isSubscribable((string) $slug),
            'every offered choice can actually be subscribed to: ' . (string) $slug
        );
    }

    // And nothing undeliverable sneaks in: a trigger-only event has no
    // business on this list.
    foreach (array_keys($events) as $slug) {
        if ($slug === '*' || str_ends_with((string) $slug, '.*')) {
            continue;
        }

        Assert::true(Event::isDeliverable((string) $slug), (string) $slug . ' is deliverable');
    }
});

// ------------------------------------------------------------- retry schedule

Harness::test('the retry schedule is front-loaded and finite', function (): void {
    /*
     * ONE GAP PER RETRY — six attempts, five waits.
     *
     * MEASURED: this file found the list being indexed by attempt number, so
     * its first entry was never read. The first retry waited five minutes
     * rather than the documented one, and the schedule ran to thirteen hours
     * instead of nine. Hence a check on the count and on every single gap,
     * not just "it increases".
     */
    Assert::same(
        Webhooks::MAX_ATTEMPTS - 1,
        count(Webhooks::BACKOFF_MINUTES),
        'a list indexed off by one is how the first wait went missing'
    );

    // First retry a minute later: most failures are a receiver restarting.
    Assert::same(60, Webhooks::nextDelaySeconds(1));
    Assert::same(300, Webhooks::nextDelaySeconds(2));

    // Every gap in the list is reachable, and each is the one it looks like.
    foreach (Webhooks::BACKOFF_MINUTES as $index => $minutes) {
        Assert::same(
            $minutes * 60,
            Webhooks::nextDelaySeconds($index + 1),
            'gap ' . ($index + 1) . ' is ' . $minutes . ' minutes'
        );
    }

    // Each wait is longer than the one before it.
    $previous = 0;

    for ($attempt = 1; $attempt < Webhooks::MAX_ATTEMPTS; $attempt++) {
        $delay = Webhooks::nextDelaySeconds($attempt);

        Assert::notNull($delay);
        Assert::greaterThan($previous, (int) $delay, 'attempt ' . $attempt . ' waits longer');

        $previous = (int) $delay;
    }

    /*
     * AND IT STOPS. Null at the last attempt, not another delay: a delivery
     * retried for ever is a party notification arriving a week late, which is
     * worse for a venue than one that is recorded as undelivered.
     */
    Assert::null(Webhooks::nextDelaySeconds(Webhooks::MAX_ATTEMPTS));
    Assert::null(Webhooks::nextDelaySeconds(Webhooks::MAX_ATTEMPTS + 5));
    Assert::null(Webhooks::nextDelaySeconds(0), 'and there is no delay before the first try');

    /*
     * And the whole schedule is under half a day. A venue being told at
     * midnight about a party that was booked at lunchtime is worse than
     * reading in the log that the call never got through.
     */
    Assert::true(
        array_sum(Webhooks::BACKOFF_MINUTES) < 12 * 60,
        'six attempts span under twelve hours, not thirteen'
    );
    Assert::true(
        array_sum(Webhooks::BACKOFF_MINUTES) > 4 * 60,
        'and long enough to outlast an endpoint being down for an afternoon'
    );
});

Harness::test('a refusal is not retried; a failure is', function (): void {
    // No answer at all — timeout, DNS, connection refused.
    Assert::true(Webhooks::isRetryable(0));

    // The receiver broke. Transient until proven otherwise.
    Assert::true(Webhooks::isRetryable(500));
    Assert::true(Webhooks::isRetryable(502));
    Assert::true(Webhooks::isRetryable(503));

    // The two 4xx that explicitly mean "try again".
    Assert::true(Webhooks::isRetryable(408));
    Assert::true(Webhooks::isRetryable(429));

    /*
     * Everything else in the 4xx range means the receiver read the request and
     * rejected it. Sending the identical body five more times gets rejected
     * five more times — it is a configuration problem at the far end, and
     * retrying only hides it behind a queue.
     */
    Assert::false(Webhooks::isRetryable(400));
    Assert::false(Webhooks::isRetryable(401));
    Assert::false(Webhooks::isRetryable(403));
    Assert::false(Webhooks::isRetryable(404));
    Assert::false(Webhooks::isRetryable(410));
    Assert::false(Webhooks::isRetryable(422));

    // A 2xx or 3xx never reaches this function, but it must not claim they
    // need retrying if it ever does.
    Assert::false(Webhooks::isRetryable(200));
    Assert::false(Webhooks::isRetryable(302));
});

// -------------------------------------------------------------- what is sent

Harness::test('the body carries ids and nothing about a person', function (): void {
    /*
     * THE MOST IMPORTANT TEST IN THIS FILE.
     *
     * A webhook target is a URL typed into a form, delivered to over the
     * public internet, and logged at the far end by software this venue does
     * not control. If a name or an email can reach it, then every subscription
     * is a standing export of guest records to somewhere nobody audits — and
     * it would get there not by anybody deciding so, but because some future
     * `Event::fire()` put a name in its payload.
     *
     * So the filter is an ALLOW-list: anything new is excluded by default.
     */
    $body = Webhooks::body(
        'contact.created',
        [
            'id'           => 42,
            'contact_id'   => 42,
            'booking_id'   => 7,
            'status'       => 'confirmed',
            'type'         => 'birthday',
            // Everything below this line is the reason the filter exists.
            'name'         => 'Alex Guest',
            'email'        => 'alex@example.com',
            'phone'        => '8455551234',
            'first_name'   => 'Alex',
            'notes'        => 'allergic to peanuts',
            'amount_cents' => 24900,
            'address'      => '3 Castle Way',
            'date_of_birth' => '1990-04-01',
        ],
        'whd_0123456789ab',
        1700000000
    );

    Assert::same('whd_0123456789ab', $body['id'], 'the delivery id a receiver deduplicates on');
    Assert::same('contact.created', $body['event']);
    Assert::same('v1', $body['version']);
    Assert::same('2023-11-14T22:13:20+00:00', $body['occurred_at'], 'ISO-8601, UTC');

    Assert::same(
        ['id', 'contact_id', 'booking_id', 'status', 'type'],
        array_keys($body['data']),
        'only identifiers and branch labels survive'
    );

    // Said the other way round, against the whole encoded body, because that
    // is what actually goes over the wire.
    $encoded = (string) json_encode($body);

    foreach (['Alex', 'alex@example.com', '8455551234', 'peanuts', 'Castle Way', '1990-04-01'] as $leak) {
        Assert::false(
            str_contains($encoded, $leak),
            'no guest data reaches the wire: ' . $leak
        );
    }

    Assert::false(str_contains($encoded, '24900'), 'nor an amount');
});

Harness::test('the id filter keeps identifiers and drops structure', function (): void {
    Assert::same(
        ['id' => 1, 'deal_id' => 2, 'channel' => 'sms', 'source' => 'kiosk', 'reason' => 'bounced'],
        Webhooks::idsOnly([
            'id'      => 1,
            'deal_id' => 2,
            'channel' => 'sms',
            'source'  => 'kiosk',
            'reason'  => 'bounced',
        ])
    );

    // A nested array or object is dropped whole: a `contact` sub-array is
    // exactly the shape that would carry a whole record through.
    Assert::same(
        ['contact_id' => 9],
        Webhooks::idsOnly([
            'contact_id' => 9,
            'contact'    => ['name' => 'Alex', 'email' => 'alex@example.com'],
            'items'      => [['id' => 1]],
        ])
    );

    // A null id is kept — "this fired with nothing attached" is information,
    // and it is not a person.
    Assert::same(['booking_id' => null], Webhooks::idsOnly(['booking_id' => null]));

    // An `_id` suffix is the rule, not a substring: `identifier` is not an id,
    // and `id_card_number` is not either.
    $filtered = Webhooks::idsOnly([
        'identifier'     => 'x',
        'id_card_number' => 'x',
        'paid'           => true,
    ]);

    Assert::same([], $filtered);

    Assert::same([], Webhooks::idsOnly([]), 'an empty payload stays empty');
});

Harness::test('a subscription switches itself off before it fills the log', function (): void {
    // Not a magic number worth arguing over, but it has to be well above the
    // retries for one delivery — otherwise a single bad endpoint disables the
    // subscription on its first event.
    Assert::greaterThan(Webhooks::MAX_ATTEMPTS, Webhooks::DISABLE_AFTER);
    Assert::true(Webhooks::KEEP_DAYS >= 7, 'the log outlives a long weekend');
});

Harness::test('the headers a receiver reads are named once, here', function (): void {
    // Four distinct header names, all prefixed, so a reverse proxy adding its
    // own cannot collide with them.
    $headers = [
        Webhooks::SIGNATURE_HEADER,
        Webhooks::TIMESTAMP_HEADER,
        Webhooks::EVENT_HEADER,
        Webhooks::DELIVERY_HEADER,
    ];

    Assert::same(4, count(array_unique($headers)));

    foreach ($headers as $header) {
        Assert::true(str_starts_with($header, 'X-Castle-'), $header . ' is namespaced');
    }
});
