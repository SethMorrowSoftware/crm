<?php

declare(strict_types=1);

/**
 * Which URLs the application will call, and which it refuses.
 *
 * The automation engine's webhook step calls a URL an operator typed, from a
 * cron process, on a shared host — where `http://127.0.0.1` and
 * `http://169.254.169.254` are both reachable and both interesting. A venue
 * manager pasting a URL from a vendor's documentation is not an attacker, but
 * the step is also the obvious thing to point at an internal address if you
 * can get somebody to paste one.
 *
 * `Http::refuse()` is the whole of that decision and it is pure, so every case
 * below is a real address rather than a mock. It is defence in depth, not a
 * boundary: it cannot stop a hostname that resolves inwards, and the test for
 * that says so rather than pretending otherwise.
 *
 * No network: nothing here opens a socket.
 */

use App\Core\Http;
use Tests\Assert;
use Tests\Harness;

Harness::suite('unit/http');

Harness::test('an ordinary https URL is allowed', function (): void {
    Assert::null(Http::refuse('https://hooks.example.com/castle'));
    Assert::null(Http::refuse('https://example.com'));
    Assert::null(Http::refuse('https://example.com:8443/path?a=1#frag'));

    // Plain http is allowed too. A kitchen printer or a till on the venue's
    // own network is reached over http, and refusing it would make the step
    // useless for the integrations a venue actually wants.
    Assert::null(Http::refuse('http://hooks.example.com/castle'));

    // Surrounding whitespace is how a URL arrives from a textarea.
    Assert::null(Http::refuse('  https://example.com/hook  '));
});

Harness::test('anything that is not a URL is refused', function (): void {
    foreach (['', '   ', 'not a url', 'example.com/hook', '/hook', '//example.com'] as $bad) {
        Assert::notNull(Http::refuse($bad), 'should refuse: "' . $bad . '"');
    }
});

Harness::test('only http and https are callable', function (): void {
    // `file://` would read a file off the server and POST nothing; `gopher://`
    // and `dict://` are the classic request-smuggling schemes. curl is told
    // the same thing by CURLOPT_PROTOCOLS — this is the readable half.
    foreach ([
        'file:///etc/passwd',
        'ftp://example.com/x',
        'gopher://example.com:70/_x',
        'dict://example.com:11211/stat',
    ] as $bad) {
        $why = Http::refuse($bad);

        Assert::notNull($why, 'should refuse: ' . $bad);
        Assert::contains('http', (string) $why, 'and should say why: ' . $bad);
    }
});

Harness::test('THIS SERVER IS NOT CALLABLE BY NAME', function (): void {
    foreach (['http://localhost/hook', 'https://localhost:8080/x', 'http://api.localhost/x'] as $bad) {
        Assert::notNull(Http::refuse($bad), 'should refuse: ' . $bad);
    }
});

Harness::test('AN ADDRESS INSIDE THE NETWORK IS NOT CALLABLE', function (): void {
    $inward = [
        'http://127.0.0.1/hook'        => 'loopback',
        'http://127.1.2.3/hook'        => 'the rest of loopback',
        'http://10.0.0.5/hook'         => 'private class A',
        'http://172.16.4.1/hook'       => 'private class B',
        'http://192.168.1.1/hook'      => 'private class C',
        'http://169.254.169.254/latest/meta-data/' => 'the cloud metadata service',
        'http://[::1]/hook'            => 'IPv6 loopback',
        'http://[fd00::1]/hook'        => 'IPv6 private',
    ];

    foreach ($inward as $url => $what) {
        Assert::notNull(Http::refuse($url), 'should refuse ' . $what . ': ' . $url);
    }

    // A public address in the same shape is fine, so the guard is about the
    // range and not about digits.
    Assert::null(Http::refuse('http://8.8.8.8/hook'));
    Assert::null(Http::refuse('https://[2001:4860:4860::8888]/hook'));
});

Harness::test('a hostname is NOT resolved, and the guard does not pretend to', function (): void {
    /*
     * `localtest.me` and friends resolve to 127.0.0.1. This guard lets them
     * through, deliberately: a lookup on every send costs a round trip, and a
     * name that resolves inwards would still have to be caught at connect
     * time to be caught at all — DNS can answer differently the second time.
     *
     * The test exists so the limit is written down. If this ever starts
     * refusing, something resolves at refuse() time and the cost and the
     * rebinding hole both need thinking about again.
     */
    Assert::null(Http::refuse('http://anything.example.invalid/hook'));
});

Harness::test('the timeouts are short and the reply is clipped', function (): void {
    // Not configurable, and the values matter: a cron tick that waits ninety
    // seconds on a dead endpoint is a tick that did not send the party
    // reminders behind it.
    Assert::true(Http::TIMEOUT <= 15, 'a webhook must not hold up the queue');
    Assert::true(Http::CONNECT_TIMEOUT <= 8);
    Assert::true(Http::MAX_BODY <= 2000, 'a step log is read in a browser');
});

Harness::test('a refused URL is never called', function (): void {
    // The refusal happens before curl is touched, so this returns rather than
    // opening a socket — which is what makes it safe to assert in a unit
    // suite with no network.
    $result = Http::postJson('http://169.254.169.254/latest/meta-data/', ['a' => 1]);

    Assert::false($result['ok']);
    Assert::same(0, $result['status'], 'nothing answered, because nothing was asked');
    Assert::same(0, $result['ms']);
    Assert::notNull($result['error']);
});
