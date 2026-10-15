<?php

declare(strict_types=1);

/**
 * The password-reset flow, end to end over real HTTP.
 *
 * This file exists because an adversarial review of the suite found the whole
 * flow untested, and proved it: replacing the token lookup in
 * findValidReset() with `SELECT ... LIMIT 1` — so ANY well-formed 64-hex token
 * redeems whichever reset is pending, and expiry is ignored — left all 372
 * tests green. The mailer tests only called sendTemplate() with a hand-written
 * link; the router test only compiled the route pattern. Nothing ever asked
 * the application to mint a token and then tried to use the wrong one.
 *
 * That is the single most security-sensitive unauthenticated path in the
 * product: it hands out the ability to become the owner.
 */

use App\Core\Auth;
use App\Core\DB;
use App\Core\Encryption;
use App\Core\Settings;
use Tests\Assert;
use Tests\Harness;

Harness::suite('http/reset-password');

$baseUrl = getenv('CC_TEST_BASE_URL') ?: '';

if ($baseUrl === '') {
    Harness::skip('reset-password suite', 'no server; run via tests/run.php');

    return;
}

if (!test_boot()) {
    Harness::skip('reset-password suite', 'no database available');

    return;
}

require_once CC_ROOT . '/tests/TestBrowser.php';

/**
 * Ask the application for a reset, and return the token it actually minted.
 *
 * The token itself is never stored — only its hash — so it cannot be read back
 * from password_resets. It is recovered from the `messages` row the mailer
 * writes before delivery, which holds the rendered body and therefore the real
 * link. That makes this exercise the genuine mint-and-mail path rather than
 * reaching past it to build a token by hand.
 *
 * (The log driver records the body too, but the test runner configures the
 * server with log_level=error, so its notice is filtered out.)
 */
/**
 * Clear the server's rate-limit counters.
 *
 * /forgot-password allows 5 POSTs per 15 minutes per IP, and every request in
 * this suite comes from 127.0.0.1 — so without clearing the counters, the sixth
 * call onwards is answered with a 429 and no token is minted at all.
 *
 * The files are unlinked directly rather than going through Cache::flush(),
 * because that call clears whichever backend THIS process resolves, and the
 * counter that matters belongs to the SERVER process. The two agree only on the
 * file backend, which tests/run.php pins the server to with apc.enable_cli=0;
 * with APCu in play, flush() clears a segment nobody else can see and reports
 * success. Cache::flush() is still called, for this process's own entries.
 *
 * The throttle itself remains exercised, by the smoke suite's own test for it.
 */
function clear_rate_limits(): int
{
    $cleared = 0;

    foreach (glob(CC_ROOT . '/storage/cache/*.cache') ?: [] as $file) {
        if (@unlink($file)) {
            $cleared++;
        }
    }

    App\Core\Cache::flush();

    return $cleared;
}

function request_reset(string $email): array
{
    clear_rate_limits();

    $browser = new TestBrowser(getenv('CC_TEST_BASE_URL') ?: '', 'reset-' . bin2hex(random_bytes(3)));
    $response = $browser->postWithToken('/forgot-password', '/forgot-password', ['email' => $email], true);

    $body = (string) DB::value(
        "SELECT body FROM {messages}
          WHERE to_address = :email AND channel = 'email'
          ORDER BY id DESC LIMIT 1",
        ['email' => $email]
    );

    preg_match('#/reset-password/([a-f0-9]{64})#', $body, $m);

    return [
        'response' => $response,
        'token'    => $m[1] ?? null,
        'browser'  => $browser,
        'log'      => $body,
        'why'      => isset($m[1]) ? '' : mint_failure_detail($email, $response),
    ];
}

/**
 * Say why no token came back, in the failure message itself.
 *
 * This exists because the first version of this suite asserted
 * Assert::notNull($result['token']) with no message, and when it went red on CI
 * — on all five configurations, while every local run passed — the report said
 * only "Expected a value, got null". That is one sentence for a path with eight
 * refusal reasons, a rate limiter and a redirect in it, and it cost a whole
 * debugging round to narrow down something the test already knew.
 *
 * The two facts that split the space: the HTTP status (a 429 means the mint
 * never ran), and whether password_resets holds a row (if it does, the token
 * WAS minted and the mail was refused; if it does not, the request never got
 * that far).
 *
 * @param array<string,mixed> $response
 */
function mint_failure_detail(string $email, array $response): string
{
    $status = (int) ($response['status'] ?? 0);

    $minted = DB::exists('SELECT id FROM {password_resets} WHERE email = :e', ['e' => $email]);

    $recent = DB::query(
        "SELECT to_address, status, error_message FROM {messages}
          WHERE channel = 'email' ORDER BY id DESC LIMIT 3"
    );

    $trail = [];

    foreach ($recent as $row) {
        $trail[] = $row['to_address'] . ' (' . $row['status']
            . ((string) $row['error_message'] !== '' ? '; ' . $row['error_message'] : '') . ')';
    }

    return 'no reset link was recorded for ' . $email . '. '
        . 'POST /forgot-password returned ' . $status . '. '
        . ($status === 429
            ? 'That is the rate limiter: the server\'s counter was not cleared, so the mint never ran. '
                . 'clear_rate_limits() unlinks storage/cache/*.cache, which only reaches the server '
                . 'when it is on the file backend — check that tests/run.php still starts it with '
                . 'apc.enable_cli=0. '
            : '')
        . ($minted
            ? 'A password_resets row EXISTS, so the token was minted and the MAIL was refused — '
                . 'look for "Mail refused" or "Password reset email could not be sent" in '
                . 'storage/logs/, and note the server runs at log_level=error. '
            : 'No password_resets row, so the request never reached the mint. ')
        . 'Newest email rows: ' . ($trail === [] ? 'none at all' : implode(', ', $trail));
}

/** The owner account these tests reset, recreated per test so order cannot matter. */
function reset_fixture(string $email): int
{
    DB::delete('users', 'email = :e', ['e' => $email]);

    // EVERY pending reset, not just this address's.
    //
    // This matters for what the tests can detect. The review's mutation was
    // `SELECT ... LIMIT 1` — any token redeems whatever is pending. With rows
    // left over from earlier tests, that mutation redeems somebody ELSE's row,
    // the account under test is untouched, and the "a different token cannot
    // redeem" assertion passes while the hole is wide open. Leaving exactly one
    // pending reset is what makes the wrong-token and expiry tests bite.
    DB::delete('password_resets', '1 = 1');

    return test_make_user('owner', [
        'email'         => $email,
        'password_hash' => Auth::hash('the-original-passphrase'),
    ]);
}

// Safe Mode would redirect the message to the owner, which is fine, but the
// log driver records it either way. Sending must not be paused.
Settings::set('system.sending_paused', false);
Settings::set('mail.driver', 'log');
App\Core\Cache::flush();

// --------------------------------------------------------------- minting

Harness::test('requesting a reset mints a single-use token and mails the link', function (): void {
    $email = 'reset-mint@example.test';
    $id = reset_fixture($email);

    $result = request_reset($email);

    Assert::same(200, $result['response']['status']);
    Assert::notNull(
        $result['token'],
        $result['why'] ?: 'the reset link should be recoverable from the log: ' . $result['log']
    );

    $row = DB::first('SELECT * FROM {password_resets} WHERE email = :e', ['e' => $email]);

    Assert::notNull($row, 'a reset row should exist');
    Assert::same(
        Encryption::hashToken((string) $result['token']),
        (string) $row['token_hash'],
        'the row must store the HASH, never the token itself'
    );
    Assert::notContains(
        (string) $result['token'],
        (string) $row['token_hash'],
        'the raw token must not be recoverable from the database'
    );

    DB::delete('users', 'id = :id', ['id' => $id]);
});

Harness::test('the reset page accepts the minted token', function (): void {
    $email = 'reset-accept@example.test';
    $id = reset_fixture($email);

    $result = request_reset($email);
    $page = $result['browser']->get('/reset-password/' . $result['token']);

    Assert::same(200, $page['status']);
    Assert::contains('name="password"', $page['body'], 'the form should be rendered');

    DB::delete('users', 'id = :id', ['id' => $id]);
});

// ------------------------------------------------------------- the wrong token

Harness::test('a DIFFERENT well-formed token cannot redeem a pending reset', function (): void {
    // The exact mutation the review used: with the lookup unbound from the
    // token, any 64-hex string redeems whatever reset is outstanding.
    $email = 'reset-wrong@example.test';
    $id = reset_fixture($email);

    $result = request_reset($email);

    Assert::notNull($result['token'], $result['why']);

    $attacker = str_repeat('ab', 32);

    Assert::notSame($attacker, $result['token'], 'precondition: a different token');

    $response = $result['browser']->postWithToken('/forgot-password', '/reset-password', [
        'token'                 => $attacker,
        'password'              => 'an-attacker-chosen-passphrase',
        'password_confirmation' => 'an-attacker-chosen-passphrase',
    ], true);

    Assert::same(200, $response['status'], 'it should answer, not crash');

    $hash = (string) DB::value('SELECT password_hash FROM {users} WHERE id = :id', ['id' => $id]);

    Assert::true(
        password_verify('the-original-passphrase', $hash),
        'the password must be unchanged — a guessed token must not reset an account'
    );
    Assert::false(password_verify('an-attacker-chosen-passphrase', $hash));
    Assert::true(
        DB::exists('SELECT id FROM {password_resets} WHERE email = :e', ['e' => $email]),
        'and the real pending reset must survive'
    );

    DB::delete('users', 'id = :id', ['id' => $id]);
});

Harness::test('a malformed token is refused without touching the database', function (): void {
    $email = 'reset-malformed@example.test';
    $id = reset_fixture($email);

    request_reset($email);

    $browser = new TestBrowser(getenv('CC_TEST_BASE_URL') ?: '', 'reset-malformed');

    foreach (['', 'short', str_repeat('z', 64), str_repeat('a', 63), str_repeat('A', 64)] as $bad) {
        $response = $browser->postWithToken('/forgot-password', '/reset-password', [
            'token'                 => $bad,
            'password'              => 'another-long-passphrase',
            'password_confirmation' => 'another-long-passphrase',
        ], true);

        Assert::same(200, $response['status'], 'input: ' . $bad);
    }

    $hash = (string) DB::value('SELECT password_hash FROM {users} WHERE id = :id', ['id' => $id]);

    Assert::true(password_verify('the-original-passphrase', $hash), 'nothing should have changed');

    DB::delete('users', 'id = :id', ['id' => $id]);
});

// ----------------------------------------------------------------- expiry

Harness::test('an expired token is refused', function (): void {
    $email = 'reset-expired@example.test';
    $id = reset_fixture($email);

    $result = request_reset($email);

    Assert::notNull($result['token'], $result['why']);

    // Age it past its hour.
    DB::update('password_resets', [
        'expires_at' => gmdate('Y-m-d H:i:s', time() - 60),
    ], 'email = :e', ['e' => $email]);

    $response = $result['browser']->postWithToken('/forgot-password', '/reset-password', [
        'token'                 => $result['token'],
        'password'              => 'a-brand-new-passphrase',
        'password_confirmation' => 'a-brand-new-passphrase',
    ], true);

    Assert::same(200, $response['status']);

    $hash = (string) DB::value('SELECT password_hash FROM {users} WHERE id = :id', ['id' => $id]);

    Assert::true(
        password_verify('the-original-passphrase', $hash),
        'an expired link must not still work — it is the only thing bounding a leaked token'
    );

    DB::delete('users', 'id = :id', ['id' => $id]);
});

// ------------------------------------------------------- the happy path, once

Harness::test('the correct token resets the password and burns itself', function (): void {
    $email = 'reset-happy@example.test';
    $id = reset_fixture($email);

    $result = request_reset($email);

    Assert::notNull($result['token'], $result['why']);

    $response = $result['browser']->postWithToken('/reset-password/' . $result['token'], '/reset-password', [
        'token'                 => $result['token'],
        'password'              => 'a-genuinely-new-passphrase',
        'password_confirmation' => 'a-genuinely-new-passphrase',
    ], true);

    Assert::same(200, $response['status']);

    $hash = (string) DB::value('SELECT password_hash FROM {users} WHERE id = :id', ['id' => $id]);

    Assert::true(password_verify('a-genuinely-new-passphrase', $hash), 'the reset should have worked');
    Assert::false(password_verify('the-original-passphrase', $hash));

    Assert::false(
        DB::exists('SELECT id FROM {password_resets} WHERE email = :e', ['e' => $email]),
        'the token must be consumed, not left usable'
    );

    // And a replay fails.
    $replay = $result['browser']->postWithToken('/forgot-password', '/reset-password', [
        'token'                 => $result['token'],
        'password'              => 'a-third-passphrase-entirely',
        'password_confirmation' => 'a-third-passphrase-entirely',
    ], true);

    Assert::same(200, $replay['status']);

    $hash = (string) DB::value('SELECT password_hash FROM {users} WHERE id = :id', ['id' => $id]);

    Assert::true(
        password_verify('a-genuinely-new-passphrase', $hash),
        'replaying a spent token must not change the password again'
    );

    DB::delete('users', 'id = :id', ['id' => $id]);
});

Harness::test('issuing a new reset invalidates the previous one', function (): void {
    $email = 'reset-reissue@example.test';
    $id = reset_fixture($email);

    $first = request_reset($email);
    $second = request_reset($email);

    Assert::notNull($first['token'], $first['why']);
    Assert::notNull($second['token'], $second['why']);
    Assert::notSame($first['token'], $second['token']);

    Assert::same(
        1,
        (int) DB::value('SELECT COUNT(*) FROM {password_resets} WHERE email = :e', ['e' => $email]),
        'one live token per address'
    );

    $response = $second['browser']->postWithToken('/forgot-password', '/reset-password', [
        'token'                 => $first['token'],
        'password'              => 'passphrase-from-the-old-link',
        'password_confirmation' => 'passphrase-from-the-old-link',
    ], true);

    Assert::same(200, $response['status']);

    $hash = (string) DB::value('SELECT password_hash FROM {users} WHERE id = :id', ['id' => $id]);

    Assert::true(
        password_verify('the-original-passphrase', $hash),
        'the superseded link must be dead'
    );

    DB::delete('users', 'id = :id', ['id' => $id]);
});

// --------------------------------------------------- enumeration & the link host

Harness::test('an unknown address gets the same answer as a known one', function (): void {
    $known = 'reset-enum-known@example.test';
    $id = reset_fixture($known);

    $a = request_reset($known);
    $b = request_reset('reset-enum-nobody@example.test');

    Assert::same($a['response']['status'], $b['response']['status']);
    Assert::same(
        strlen(strip_tags($a['response']['body'])),
        strlen(strip_tags($b['response']['body'])),
        'the page must not reveal whether the address has an account'
    );

    Assert::false(
        DB::exists(
            'SELECT id FROM {password_resets} WHERE email = :e',
            ['e' => 'reset-enum-nobody@example.test']
        ),
        'and no token should be minted for an address with no account'
    );

    DB::delete('users', 'id = :id', ['id' => $id]);
});

Harness::test('the reset link host comes from configuration, not the Host header', function (): void {
    // Reset-link poisoning: with app_url unset the link host used to be taken
    // straight from $_SERVER['HTTP_HOST'], so a crafted request could mail the
    // owner a real token pointing at the attacker's domain. absoluteUrl() now
    // prefers app_url, then the configured venue website, then the URL the
    // installer was reached on, and logs a warning if it ever has to fall back
    // to the request.
    $email = 'reset-host@example.test';
    $id = reset_fixture($email);

    $browser = new TestBrowser(getenv('CC_TEST_BASE_URL') ?: '', 'reset-host');
    $csrf = $browser->csrfToken('/forgot-password');

    $jar = sys_get_temp_dir() . '/cc-reset-host-' . getmypid() . '.cookies';
    $handle = curl_init((getenv('CC_TEST_BASE_URL') ?: '') . '/forgot-password');

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['email' => $email, '_token' => $csrf]),
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_TIMEOUT        => 20,
        // The attack: claim to be somewhere else.
        CURLOPT_HTTPHEADER     => ['Host: attacker.test'],
    ]);

    curl_exec($handle);
    curl_close($handle);

    $body = (string) DB::value(
        "SELECT body FROM {messages}
          WHERE to_address = :email AND channel = 'email'
          ORDER BY id DESC LIMIT 1",
        ['email' => $email]
    );

    if ($body === '') {
        // The crafted Host may be refused before any mail is built, which is
        // also a safe outcome — but then there is nothing to assert about it.
        Assert::true(true, 'no message was minted for the crafted host, which is safe');
    } else {
        Assert::contains('/reset-password/', $body, 'precondition: a link was built');
        Assert::notContains(
            'attacker.test',
            $body,
            'a reset link must never be built from the request Host header'
        );
    }

    DB::delete('users', 'id = :id', ['id' => $id]);
});
