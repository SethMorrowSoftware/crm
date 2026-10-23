<?php

declare(strict_types=1);

/**
 * The endpoints a guest reaches with no account, over real HTTP.
 *
 * One-click unsubscribe is the whole of it, and it has to work from inside a
 * mail client: no session, no cookie, no CSRF token, and a POST submitted by
 * the recipient's mail provider rather than by the recipient. That makes it
 * the one unauthenticated mutating route in the application, so it gets tested
 * as carefully as the signed-in ones.
 */

use App\Core\DB;
use App\Core\Mailer;
use Tests\Assert;
use Tests\Harness;

Harness::suite('http/public');

$baseUrl = getenv('CC_TEST_BASE_URL') ?: '';

if ($baseUrl === '') {
    Harness::skip('public suite', 'no server; run via tests/run.php');

    return;
}

if (!test_boot()) {
    Harness::skip('public suite', 'no database available');

    return;
}

/**
 * Mint an unsubscribe token the SERVER will accept.
 *
 * The runner writes the server a config file with its own random app_key,
 * which is correct — a fresh key per run proves nothing is hard-coded — but it
 * means a token minted in this process with the test harness's key would never
 * verify over there. So the server's key is read from the config file it is
 * actually running with, used for the one call, and put back.
 */
function public_token(string $address, ?int $contactId): string
{
    /** @var array<string,mixed> $serverConfig */
    $serverConfig = require CC_ROOT . '/config/config.php';

    $ours = \App\Core\Config::get('app_key');

    try {
        \App\Core\Config::set('app_key', $serverConfig['app_key']);

        return Mailer::unsubscribeToken($address, $contactId);
    } finally {
        \App\Core\Config::set('app_key', $ours);
    }
}

/**
 * A request with no cookie jar at all, which is how a mail client arrives.
 *
 * @return array{status:int,body:string,headers:string,location:string}
 */
function public_request(string $path, ?array $post = null): array
{
    $baseUrl = getenv('CC_TEST_BASE_URL') ?: '';
    $handle = curl_init($baseUrl . $path);

    if ($handle === false) {
        throw new RuntimeException('Could not initialise cURL.');
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 20,
    ];

    if ($post !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($post);
    }

    curl_setopt_array($handle, $options);

    $raw = curl_exec($handle);

    if ($raw === false) {
        $error = curl_error($handle);

        throw new RuntimeException('Request to ' . $path . ' failed: ' . $error);
    }

    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);

    return [
        'status'   => $status,
        'body'     => substr((string) $raw, $headerSize),
        'headers'  => substr((string) $raw, 0, $headerSize),
        'location' => '',
    ];
}

// ------------------------------------------------------------- the happy path

Harness::test('a valid unsubscribe link works with no session', function (): void {
    $contactId = test_make_contact([
        'email'         => 'http-unsub@example.test',
        'email_consent' => 1,
    ]);

    $token = public_token('http-unsub@example.test', $contactId);

    $response = public_request('/u/' . $token);

    Assert::same(200, $response['status']);
    Assert::contains('nsubscribe', $response['body']);

    $contact = DB::first('SELECT * FROM {contacts} WHERE id = :id', ['id' => $contactId]);

    Assert::same(0, (int) $contact['email_consent'], 'consent must actually be withdrawn');
    Assert::notNull($contact['marketing_opt_out_at']);

    Assert::true(
        DB::exists(
            'SELECT id FROM {suppressions} WHERE channel = :c AND address = :a',
            ['c' => 'email', 'a' => 'http-unsub@example.test']
        ),
        'the address must also go on the suppression list, or another campaign could re-add it'
    );

    $log = DB::first(
        "SELECT * FROM {consent_log}
          WHERE contact_id = :id AND action = 'revoked' ORDER BY id DESC LIMIT 1",
        ['id' => $contactId]
    );

    Assert::notNull($log, 'withdrawal needs the same evidence trail as consent');
    Assert::same('email', (string) $log['channel']);
    Assert::notEmpty($log['ip'], 'the record should show where the request came from');

    DB::delete('suppressions', 'address = :a', ['a' => 'http-unsub@example.test']);
    DB::delete('contacts', 'id = :id', ['id' => $contactId]);
});

Harness::test('the RFC 8058 one-click POST works without a CSRF token', function (): void {
    // A mail provider submits this on the recipient's behalf. There is no
    // session for a token to live in, so requiring one would break every
    // one-click unsubscribe in Gmail — which is now a bulk-sender
    // requirement, not a nicety.
    $contactId = test_make_contact([
        'email'         => 'http-oneclick@example.test',
        'email_consent' => 1,
    ]);

    $token = public_token('http-oneclick@example.test', $contactId);

    $response = public_request('/u/' . $token, ['List-Unsubscribe' => 'One-Click']);

    Assert::same(200, $response['status'], 'a 419 or 403 here means a broken unsubscribe');

    Assert::same(
        0,
        (int) DB::value('SELECT email_consent FROM {contacts} WHERE id = :id', ['id' => $contactId])
    );

    DB::delete('suppressions', 'address = :a', ['a' => 'http-oneclick@example.test']);
    DB::delete('contacts', 'id = :id', ['id' => $contactId]);
});

Harness::test('unsubscribing twice is harmless', function (): void {
    $contactId = test_make_contact([
        'email'         => 'http-twice@example.test',
        'email_consent' => 1,
    ]);

    $token = public_token('http-twice@example.test', $contactId);

    $first = public_request('/u/' . $token);
    $second = public_request('/u/' . $token);

    Assert::same(200, $first['status']);
    Assert::same(200, $second['status'], 'a mail client may well retry');

    Assert::same(
        1,
        (int) DB::value(
            'SELECT COUNT(*) FROM {suppressions} WHERE channel = :c AND address = :a',
            ['c' => 'email', 'a' => 'http-twice@example.test']
        ),
        'the unique key should mean one row, not an error'
    );

    DB::delete('suppressions', 'address = :a', ['a' => 'http-twice@example.test']);
    DB::delete('contacts', 'id = :id', ['id' => $contactId]);
});

Harness::test('an address with no contact record can still unsubscribe', function (): void {
    // POS imports and forwarded mail both produce addresses with no contact
    // row. "We cannot find you, so you stay subscribed" is not an answer.
    $token = public_token('orphan@example.test', null);

    $response = public_request('/u/' . $token);

    Assert::same(200, $response['status']);
    Assert::true(
        DB::exists(
            'SELECT id FROM {suppressions} WHERE channel = :c AND address = :a',
            ['c' => 'email', 'a' => 'orphan@example.test']
        )
    );

    DB::delete('suppressions', 'address = :a', ['a' => 'orphan@example.test']);
});

// ------------------------------------------------------------- bad tokens

Harness::test('a tampered token does not unsubscribe anybody', function (): void {
    $contactId = test_make_contact([
        'email'         => 'http-safe@example.test',
        'email_consent' => 1,
    ]);

    $token = public_token('http-safe@example.test', $contactId);

    // Flip a bit of the SIGNATURE and re-encode, rather than mutating base64
    // characters: the tail of a base64 string carries bits that no byte uses,
    // so some character mutations decode identically and verify. Doing it that
    // way made this test pass or fail depending on the contact's
    // auto-increment id — green locally, red on one CI job in five.
    $raw = (string) base64_decode(strtr($token, '-_', '+/'), true);
    $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] === 'a' ? 'b' : 'a';
    $tampered = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

    Assert::notSame($token, $tampered, 'the tamper must actually change the token');

    $response = public_request('/u/' . $tampered);

    Assert::inArray($response['status'], [200, 400, 404], 'it should answer, not crash');
    Assert::same(
        1,
        (int) DB::value('SELECT email_consent FROM {contacts} WHERE id = :id', ['id' => $contactId]),
        'a forged link must not be able to unsubscribe a guest'
    );

    DB::delete('contacts', 'id = :id', ['id' => $contactId]);
});

Harness::test('the unsubscribe page never reveals whether an address is on file', function (): void {
    // Otherwise the endpoint is an unauthenticated oracle for "is this parent
    // one of your customers?", which is a privacy leak on its own.
    $known = test_make_contact(['email' => 'known@example.test', 'email_consent' => 1]);

    $knownBody = public_request('/u/' . public_token('known@example.test', $known))['body'];
    $unknownBody = public_request('/u/' . public_token('never-heard-of@example.test', null))['body'];

    // Strip the address itself, which legitimately differs, then compare.
    $normalise = static fn (string $html): string => str_replace(
        ['known@example.test', 'never-heard-of@example.test'],
        'ADDRESS',
        $html
    );

    Assert::same(
        strlen($normalise($unknownBody)),
        strlen($normalise($knownBody)),
        'the two responses should be indistinguishable apart from the address'
    );

    DB::delete('suppressions', "address IN ('known@example.test', 'never-heard-of@example.test')");
    DB::delete('contacts', 'id = :id', ['id' => $known]);
});

Harness::test('a path that is not a token at all 404s', function (): void {
    Assert::same(404, public_request('/u/')['status']);
});

Harness::test('the unsubscribe page is not indexable', function (): void {
    $token = public_token('robots@example.test', null);
    $response = public_request('/u/' . $token);

    Assert::matches(
        '/X-Robots-Tag:\s*noindex/i',
        $response['headers'],
        'an unsubscribe URL in a search index is an unsubscribe anybody can trigger'
    );

    DB::delete('suppressions', 'address = :a', ['a' => 'robots@example.test']);
});
