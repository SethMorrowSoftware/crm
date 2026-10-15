<?php

declare(strict_types=1);

/**
 * The mail send path, against real rows.
 *
 * These are the tests that matter most in the whole suite. Everything they
 * cover is a promise made to a guest or to the law: that Safe Mode actually
 * prevents contact, that a suppressed address is never written to again, that
 * marketing without consent does not go out, and that bulk mail carries an
 * unsubscribe route. A regression in any of them is not a bug report, it is a
 * complaint to a regulator.
 */

use App\Core\Config;
use App\Core\DB;
use App\Core\Mailer;
use App\Core\Settings;
use Tests\Assert;
use Tests\Harness;

Harness::suite('integration/mailer');

if (!test_boot()) {
    Harness::skip('mailer suite', 'no database available');

    return;
}

test_reset_transactional_data();

$_SERVER['HTTP_HOST'] = 'crm.example.test';
$_SERVER['REQUEST_URI'] = '/';

/**
 * Run a closure with a known set of settings, restoring them afterwards so
 * tests cannot leak state into each other.
 *
 * @param array<string,mixed> $settings
 */
function with_mail_settings(array $settings, callable $callback): mixed
{
    $previous = [];

    foreach ($settings as $key => $value) {
        $previous[$key] = Settings::get($key);
        Settings::set($key, $value);
    }

    try {
        return $callback();
    } finally {
        foreach ($previous as $key => $value) {
            Settings::set($key, $value);
        }
    }
}

/**
 * Capture what would have been sent instead of sending it.
 *
 * @return array{result:array<string,mixed>,sent:list<array<string,mixed>>}
 */
function capture_send(array $message): array
{
    Mailer::useDriver('log');
    Mailer::startCapturing();

    try {
        $result = Mailer::send($message);
        $sent = Mailer::captured();
    } finally {
        Mailer::stopCapturing();
        Mailer::useDriver(null);
    }

    return ['result' => $result, 'sent' => $sent];
}

/** The baseline: Safe Mode off, sending on, no cap. Each test opts back in. */
function live_settings(array $overrides = []): array
{
    return array_merge([
        'system.safe_mode'            => false,
        'system.sending_paused'       => false,
        'system.message_cap_per_week' => 0,
        'mail.safe_mode_address'      => null,
    ], $overrides);
}

// ------------------------------------------------------------- basic refusals

Harness::test('an invalid address is refused before anything is recorded', function (): void {
    $before = (int) DB::value('SELECT COUNT(*) FROM {messages}');

    $result = Mailer::send(['to' => 'not-an-address', 'subject' => 'Hi', 'html' => '<p>x</p>']);

    Assert::false($result['sent']);
    Assert::same('invalid_address', $result['reason']);
    Assert::same($before, (int) DB::value('SELECT COUNT(*) FROM {messages}'));
});

Harness::test('a message with no subject is refused', function (): void {
    $result = Mailer::send(['to' => 'a@example.test', 'subject' => '  ', 'html' => '<p>x</p>']);

    Assert::same('no_subject', $result['reason']);
});

Harness::test('a message with no body is refused', function (): void {
    $result = Mailer::send(['to' => 'a@example.test', 'subject' => 'Hello']);

    Assert::same('empty_body', $result['reason']);
});

// ------------------------------------------------------------- the kill switch

Harness::test('the global pause stops even transactional mail', function (): void {
    $result = with_mail_settings(live_settings(['system.sending_paused' => true]), static fn (): array =>
        Mailer::send([
            'to'      => 'parent@example.test',
            'subject' => 'Your booking',
            'html'    => '<p>Confirmed.</p>',
            'kind'    => Mailer::TRANSACTIONAL,
        ]));

    Assert::false($result['sent']);
    Assert::same('sending_paused', $result['reason'], 'the stop button must stop everything');
});

// ----------------------------------------------------------------- Safe Mode

Harness::test('Safe Mode redirects to the owner and never to the guest', function (): void {
    $ownerId = test_make_user('owner', ['email' => 'owner-safe@example.test']);

    $captured = with_mail_settings(
        live_settings(['system.safe_mode' => true]),
        static fn (): array => capture_send([
            'to'      => 'real.parent@example.test',
            'subject' => 'Your party is confirmed',
            'html'    => '<p>See you Saturday.</p>',
            'kind'    => Mailer::TRANSACTIONAL,
        ])
    );

    Assert::true($captured['result']['sent']);
    Assert::true($captured['result']['redirected']);
    Assert::count(1, $captured['sent']);

    $message = $captured['sent'][0];

    Assert::same('owner-safe@example.test', $message['to'], 'Safe Mode must redirect to the owner');
    Assert::same('real.parent@example.test', $message['original_to']);
    Assert::contains(
        'real.parent@example.test',
        $message['html'],
        'the redirected copy must say who it was for, or it is useless for review'
    );
    Assert::contains('real.parent@example.test', $message['headers']['X-Castle-Original-To'] ?? '');

    DB::delete('users', 'id = :id', ['id' => $ownerId]);
});

Harness::test('Safe Mode marks the subject so a redirected copy cannot be mistaken', function (): void {
    $ownerId = test_make_user('owner', ['email' => 'owner-subject@example.test']);

    $captured = with_mail_settings(
        live_settings(['system.safe_mode' => true]),
        static fn (): array => capture_send([
            'to'      => 'parent@example.test',
            'subject' => 'Your party is confirmed',
            'html'    => '<p>x</p>',
            'kind'    => Mailer::TRANSACTIONAL,
        ])
    );

    Assert::notSame(
        'Your party is confirmed',
        $captured['sent'][0]['subject'],
        'a redirected copy landing in the owner inbox must look different from a real one'
    );

    DB::delete('users', 'id = :id', ['id' => $ownerId]);
});

Harness::test('Safe Mode with no owner refuses rather than falling through to the guest', function (): void {
    // The dangerous failure mode: if the redirect target cannot be resolved,
    // "send it to the original recipient after all" would be catastrophic.
    DB::pdo()->exec('DELETE FROM `cc_users`');

    $result = with_mail_settings(
        live_settings(['system.safe_mode' => true]),
        static fn (): array => Mailer::send([
            'to'      => 'parent@example.test',
            'subject' => 'Hello',
            'html'    => '<p>x</p>',
            'kind'    => Mailer::TRANSACTIONAL,
        ])
    );

    Assert::false($result['sent']);
    Assert::same('safe_mode_no_owner', $result['reason']);
});

Harness::test('the configured Safe Mode address wins over the owner lookup', function (): void {
    $ownerId = test_make_user('owner', ['email' => 'owner-ignored@example.test']);

    $captured = with_mail_settings(
        live_settings([
            'system.safe_mode'       => true,
            'mail.safe_mode_address' => 'testing@example.test',
        ]),
        static fn (): array => capture_send([
            'to'      => 'parent@example.test',
            'subject' => 'Hello',
            'html'    => '<p>x</p>',
            'kind'    => Mailer::TRANSACTIONAL,
        ])
    );

    Assert::same('testing@example.test', $captured['sent'][0]['to']);

    DB::delete('users', 'id = :id', ['id' => $ownerId]);
});

// --------------------------------------------------------------- suppression

Harness::test('a suppressed address is refused even for transactional mail', function (): void {
    DB::insert('suppressions', [
        'channel'    => 'email',
        'address'    => 'bounced@example.test',
        'reason'     => 'bounce',
        'created_at' => gmdate('Y-m-d H:i:s'),
    ]);

    $result = with_mail_settings(live_settings(), static fn (): array => Mailer::send([
        'to'      => 'bounced@example.test',
        'subject' => 'Your booking',
        'html'    => '<p>x</p>',
        'kind'    => Mailer::TRANSACTIONAL,
    ]));

    Assert::false($result['sent']);
    Assert::same(
        'suppressed',
        $result['reason'],
        'sending to a hard bounce damages deliverability for every other guest'
    );

    DB::delete('suppressions', 'address = :a', ['a' => 'bounced@example.test']);
});

Harness::test('suppression matching is case insensitive', function (): void {
    DB::insert('suppressions', [
        'channel'    => 'email',
        'address'    => 'mixed@example.test',
        'reason'     => 'complaint',
        'created_at' => gmdate('Y-m-d H:i:s'),
    ]);

    $result = with_mail_settings(live_settings(), static fn (): array => Mailer::send([
        'to'      => 'MIXED@Example.Test',
        'subject' => 'Hello',
        'html'    => '<p>x</p>',
        'kind'    => Mailer::TRANSACTIONAL,
    ]));

    Assert::same('suppressed', $result['reason']);

    DB::delete('suppressions', 'address = :a', ['a' => 'mixed@example.test']);
});

// ------------------------------------------------------------------- consent

Harness::test('marketing to a contact without email consent is refused', function (): void {
    $contactId = test_make_contact(['email' => 'no-consent@example.test', 'email_consent' => 0]);

    $result = with_mail_settings(live_settings(), static fn (): array => Mailer::send([
        'to'         => 'no-consent@example.test',
        'subject'    => 'Half price Tuesdays',
        'html'       => '<p>Come in.</p>',
        'kind'       => Mailer::MARKETING,
        'contact_id' => $contactId,
    ]));

    Assert::false($result['sent']);
    Assert::same('no_consent', $result['reason']);
});

Harness::test('transactional mail to the same contact is allowed', function (): void {
    $contactId = test_make_contact(['email' => 'no-consent-2@example.test', 'email_consent' => 0]);

    $captured = with_mail_settings(live_settings(), static fn (): array => capture_send([
        'to'         => 'no-consent-2@example.test',
        'subject'    => 'Your booking is confirmed',
        'html'       => '<p>Saturday at 1pm.</p>',
        'kind'       => Mailer::TRANSACTIONAL,
        'contact_id' => $contactId,
    ]));

    Assert::true(
        $captured['result']['sent'],
        'a guest who booked a party is owed their confirmation regardless of marketing consent'
    );
});

Harness::test('do_not_contact blocks transactional mail too', function (): void {
    $contactId = test_make_contact([
        'email'          => 'dnc@example.test',
        'email_consent'  => 1,
        'do_not_contact' => 1,
    ]);

    $result = with_mail_settings(live_settings(), static fn (): array => Mailer::send([
        'to'         => 'dnc@example.test',
        'subject'    => 'Your booking',
        'html'       => '<p>x</p>',
        'kind'       => Mailer::TRANSACTIONAL,
        'contact_id' => $contactId,
    ]));

    Assert::false($result['sent']);
    Assert::same(
        'do_not_contact',
        $result['reason'],
        'an explicit instruction not to be contacted overrides every other flag'
    );
});

Harness::test('a contact id that does not exist is refused, not ignored', function (): void {
    $result = with_mail_settings(live_settings(), static fn (): array => Mailer::send([
        'to'         => 'ghost@example.test',
        'subject'    => 'Hello',
        'html'       => '<p>x</p>',
        'kind'       => Mailer::MARKETING,
        'contact_id' => 999_999_999,
    ]));

    Assert::same('no_contact', $result['reason']);
});

// ------------------------------------------------------------ marketing rules

Harness::test('marketing mail carries an unsubscribe link and the postal address', function (): void {
    $contactId = test_make_contact(['email' => 'consented@example.test', 'email_consent' => 1]);

    $captured = with_mail_settings(live_settings(), static fn (): array => capture_send([
        'to'         => 'consented@example.test',
        'subject'    => 'This month at the Castle',
        'html'       => '<p>News.</p>',
        'kind'       => Mailer::MARKETING,
        'contact_id' => $contactId,
    ]));

    Assert::true($captured['result']['sent']);

    $message = $captured['sent'][0];

    Assert::contains('/u/', $message['html'], 'CAN-SPAM requires a working unsubscribe route');
    Assert::contains('Unsubscribe', $message['text']);
    Assert::hasKey('List-Unsubscribe', $message['headers']);
    Assert::same('List-Unsubscribe=One-Click', $message['headers']['List-Unsubscribe-Post'] ?? '');

    // The venue seed sets a street address; it has to appear in the footer.
    $street = (string) Settings::get('venue.address_1', '');

    if ($street !== '') {
        Assert::contains($street, $message['text'], 'a physical address is required in bulk mail');
    }
});

Harness::test('the unsubscribe link in a marketing email actually parses', function (): void {
    $contactId = test_make_contact(['email' => 'linkcheck@example.test', 'email_consent' => 1]);

    $captured = with_mail_settings(live_settings(), static fn (): array => capture_send([
        'to'         => 'linkcheck@example.test',
        'subject'    => 'Hello',
        'html'       => '<p>x</p>',
        'kind'       => Mailer::MARKETING,
        'contact_id' => $contactId,
    ]));

    $header = $captured['sent'][0]['headers']['List-Unsubscribe'] ?? '';

    Assert::matches('#^<https?://[^>]+/u/[A-Za-z0-9_-]+>$#', $header);

    preg_match('#/u/([A-Za-z0-9_-]+)#', $header, $m);
    $parsed = Mailer::parseUnsubscribeToken($m[1] ?? '');

    Assert::notNull($parsed, 'a link in a real email must verify against the real key');
    Assert::same('linkcheck@example.test', $parsed['address']);
    Assert::same($contactId, $parsed['contact_id']);
});

Harness::test('transactional mail carries no unsubscribe header', function (): void {
    $captured = with_mail_settings(live_settings(), static fn (): array => capture_send([
        'to'      => 'parent@example.test',
        'subject' => 'Your booking is confirmed',
        'html'    => '<p>x</p>',
        'kind'    => Mailer::TRANSACTIONAL,
    ]));

    Assert::false(
        isset($captured['sent'][0]['headers']['List-Unsubscribe']),
        'offering to unsubscribe from a booking confirmation would be misleading'
    );
});

Harness::test('the weekly cap counts campaign messages only', function (): void {
    $contactId = test_make_contact(['email' => 'capped@example.test', 'email_consent' => 1]);

    // Two prior campaign sends this week.
    for ($i = 0; $i < 2; $i++) {
        DB::insert('messages', [
            'contact_id'  => $contactId,
            'channel'     => 'email',
            'direction'   => 'outbound',
            'status'      => 'sent',
            'to_address'  => 'capped@example.test',
            'subject'     => 'Earlier campaign ' . $i,
            'body'        => 'x',
            'campaign_id' => null,
            'created_at'  => gmdate('Y-m-d H:i:s'),
        ]);
    }

    // campaign_id is NULL on those rows, so they do not count: the cap is
    // about campaigns, not about every message a guest has ever had.
    $result = with_mail_settings(
        live_settings(['system.message_cap_per_week' => 2]),
        static fn (): array => capture_send([
            'to'         => 'capped@example.test',
            'subject'    => 'Another one',
            'html'       => '<p>x</p>',
            'kind'       => Mailer::MARKETING,
            'contact_id' => $contactId,
        ])['result']
    );

    Assert::true($result['sent'], 'messages with no campaign must not consume the campaign cap');
});

// ------------------------------------------------------------ header hygiene

Harness::test('a newline in a header value cannot inject a header', function (): void {
    $captured = with_mail_settings(live_settings(), static fn (): array => capture_send([
        'to'      => 'parent@example.test',
        'subject' => "Your booking\r\nBcc: attacker@example.test",
        'html'    => '<p>x</p>',
        'kind'    => Mailer::TRANSACTIONAL,
        'headers' => ['X-Test' => "clean\r\nX-Injected: yes"],
    ]));

    $message = $captured['sent'][0];

    Assert::notContains("\r", $message['subject'], 'a CR in a subject is a header injection');
    Assert::notContains("\n", $message['subject']);
    Assert::notContains("\r", $message['headers']['X-Test'] ?? '');
    Assert::notContains("\n", $message['headers']['X-Test'] ?? '');
    Assert::false(
        isset($message['headers']['X-Injected']),
        'an injected header must not become a real header'
    );
});

// ----------------------------------------------------------------- templates

Harness::test('the password-reset template exists and is marked as a system template', function (): void {
    $template = DB::first("SELECT * FROM {templates} WHERE slug = 'password-reset'");

    Assert::notNull($template, 'without this row nobody can recover an account');
    Assert::same(1, (int) $template['is_system']);
    Assert::same(1, (int) $template['is_active']);
    Assert::same('email', (string) $template['channel']);
    Assert::contains('{{reset_link}}', (string) $template['body'], 'the link tag must be in the body');
});

Harness::test('sendTemplate merges the data into the real template', function (): void {
    $ownerId = test_make_user('owner', ['email' => 'owner-template@example.test']);

    $captured = with_mail_settings(live_settings(), static fn (): array => capture_send([
        // sendTemplate is the path under test; send() is reached through it.
        'to' => 'ignored@example.test', 'subject' => 'ignored', 'html' => 'ignored',
    ]));

    // Run the template path directly so the stored body is exercised.
    Mailer::useDriver('log');
    Mailer::startCapturing();

    try {
        $result = with_mail_settings(live_settings(), static fn (): array => Mailer::sendTemplate(
            'password-reset',
            'locked.out@example.test',
            [
                'name'       => 'Marisol',
                'reset_link' => 'https://crm.example.test/reset-password/' . str_repeat('a', 64),
                'venue_name' => 'The Castle Fun Center',
                'expires_in' => 'one hour',
            ],
            ['kind' => Mailer::TRANSACTIONAL]
        ));

        $sent = Mailer::captured();
    } finally {
        Mailer::stopCapturing();
        Mailer::useDriver(null);
    }

    Assert::true($result['sent'], 'reason: ' . $result['reason']);
    Assert::count(1, $sent);
    Assert::contains('Marisol', $sent[0]['html']);
    Assert::contains('reset-password/' . str_repeat('a', 64), $sent[0]['html']);
    Assert::contains('The Castle Fun Center', $sent[0]['subject']);
    Assert::notContains('{{', $sent[0]['html'], 'no merge tag may survive into a sent message');

    unset($captured);
    DB::delete('users', 'id = :id', ['id' => $ownerId]);
});

Harness::test('an unknown template slug refuses rather than sending an empty message', function (): void {
    $result = with_mail_settings(live_settings(), static fn (): array =>
        Mailer::sendTemplate('no-such-template', 'a@example.test', []));

    Assert::false($result['sent']);
    Assert::same('no_template', $result['reason']);
});

// -------------------------------------------------------------- the log trail

Harness::test('every send writes a message row with the original recipient', function (): void {
    $ownerId = test_make_user('owner', ['email' => 'owner-trail@example.test']);
    $before = (int) DB::value('SELECT COUNT(*) FROM {messages}');

    with_mail_settings(
        live_settings(['system.safe_mode' => true]),
        static fn (): array => capture_send([
            'to'      => 'audit.me@example.test',
            'subject' => 'Recorded',
            'html'    => '<p>x</p>',
            'kind'    => Mailer::TRANSACTIONAL,
        ])
    );

    Assert::same($before + 1, (int) DB::value('SELECT COUNT(*) FROM {messages}'));

    $row = DB::first("SELECT * FROM {messages} WHERE subject LIKE '%Recorded%' ORDER BY id DESC LIMIT 1");

    Assert::notNull($row);
    Assert::same(
        'audit.me@example.test',
        (string) $row['to_address'],
        'the record must show who it was FOR, not where Safe Mode sent it'
    );

    DB::delete('users', 'id = :id', ['id' => $ownerId]);
});

Harness::test('a seeded subject renders a name with an apostrophe as typed', function (): void {
    // End to end through the real stored template, because the escaping bug
    // was in sendTemplate()'s use of the body's escaper for the subject, and a
    // unit test of merge() alone would not have seen it.
    $ownerId = test_make_user('owner', ['email' => 'owner-subject-escape@example.test']);

    Mailer::useDriver('log');
    Mailer::startCapturing();

    try {
        $result = with_mail_settings(live_settings(), static fn (): array => Mailer::sendTemplate(
            'password-reset',
            'apostrophe@example.test',
            [
                'name'       => "Mia O'Brien",
                'reset_link' => 'https://crm.example.test/reset-password/' . str_repeat('a', 64),
                'venue_name' => "Dave & Buster's",
                'expires_in' => 'one hour',
            ],
            ['kind' => Mailer::TRANSACTIONAL]
        ));

        $sent = Mailer::captured();
    } finally {
        Mailer::stopCapturing();
        Mailer::useDriver(null);
    }

    Assert::true($result['sent'], 'reason: ' . $result['reason']);
    Assert::count(1, $sent);

    Assert::contains("Dave & Buster's", $sent[0]['subject'], 'the subject must read as typed');
    Assert::notContains('&amp;', $sent[0]['subject'], 'a subject line is not HTML');
    Assert::notContains('&#039;', $sent[0]['subject']);

    // The body is HTML and must still be escaped.
    Assert::contains('&#039;', $sent[0]['html'], 'the HTML body must still escape the apostrophe');
    Assert::notContains("Mia O'Brien", $sent[0]['html'], 'raw apostrophe must not reach the markup');

    // And the stored record carries the readable subject, not entities.
    $row = DB::first(
        "SELECT subject FROM {messages} WHERE to_address = 'apostrophe@example.test' ORDER BY id DESC LIMIT 1"
    );

    Assert::notNull($row);
    Assert::notContains('&amp;', (string) $row['subject'], 'nor may the message trail store entities');

    DB::delete('users', 'id = :id', ['id' => $ownerId]);
});

// -------------------------------------------- where an email link points
//
// absoluteUrl() falls back through stored settings, so every case below the
// first needs real rows. The app_url-only case stays in unit/mailer, which
// runs with no database at all.

Harness::test('with no app_url a link uses the configured venue website', function (): void {
    $originalUrl = Config::get('app_url');
    $originalHost = $_SERVER['HTTP_HOST'] ?? null;

    try {
        Config::set('app_url', null);
        Settings::set('venue.website', 'https://castlefuncenter.example');
        $_SERVER['HTTP_HOST'] = 'attacker.test';

        $url = Mailer::absoluteUrl('/u/abc');

        Assert::contains('castlefuncenter.example', $url);
        Assert::notContains('attacker.test', $url);
    } finally {
        Config::set('app_url', $originalUrl);
        Settings::forget('venue.website');
        $_SERVER['HTTP_HOST'] = $originalHost;
    }
});

Harness::test('the installer-recorded URL beats a crafted Host header', function (): void {
    // The normal state after setup: system.install_url holds the address the
    // operator actually reached the installer on, so even with app_url and the
    // venue website unset, a crafted request cannot redirect a reset link.
    $originalUrl = Config::get('app_url');
    $originalHost = $_SERVER['HTTP_HOST'] ?? null;

    try {
        Config::set('app_url', null);
        Settings::forget('venue.website');
        Settings::set('system.install_url', 'https://crm.realvenue.test');
        $_SERVER['HTTP_HOST'] = 'attacker.test';

        $url = Mailer::absoluteUrl('/reset-password/' . str_repeat('a', 64));

        Assert::contains('crm.realvenue.test', $url);
        Assert::notContains('attacker.test', $url);
    } finally {
        Config::set('app_url', $originalUrl);
        Settings::forget('system.install_url');
        $_SERVER['HTTP_HOST'] = $originalHost;
    }
});

Harness::test('an unconfigured install sanitises the host it falls back to', function (): void {
    // With nothing configured at all the request host IS the documented
    // fallback — a fresh install has to be able to mail a working link, and
    // Mailer logs a warning when it comes to this. What must NOT happen is a
    // crafted header smuggling a path, a credential, a second host or a header
    // break into the URL.
    $originalUrl = Config::get('app_url');
    $originalHost = $_SERVER['HTTP_HOST'] ?? null;

    try {
        Config::set('app_url', null);
        Settings::forget('venue.website');
        Settings::forget('system.install_url');

        $cases = [
            // crafted header            => must not appear in the URL
            'attacker.test/evil'         => '/evil',
            'user:pass@attacker.test'    => '@',
            "real.test\nX-Evil: 1"       => "\n",
            'real.test attacker.test'    => ' ',
        ];

        foreach ($cases as $crafted => $forbidden) {
            $_SERVER['HTTP_HOST'] = $crafted;

            $url = Mailer::absoluteUrl('/u/abc');

            Assert::notContains(
                $forbidden,
                $url,
                'input: ' . addcslashes((string) $crafted, "\n")
            );
            Assert::matches(
                '#^https?://[A-Za-z0-9.-]+(:\d+)?/u/abc$#',
                $url,
                'the URL must have exactly one clean host: ' . $url
            );
        }
    } finally {
        Config::set('app_url', $originalUrl);
        $_SERVER['HTTP_HOST'] = $originalHost;
    }
});

Harness::test('a plain host is still usable when nothing is configured', function (): void {
    // The fallback must keep working, or a fresh install's links break.
    $originalUrl = Config::get('app_url');
    $originalHost = $_SERVER['HTTP_HOST'] ?? null;

    try {
        Config::set('app_url', null);
        Settings::forget('venue.website');
        Settings::forget('system.install_url');
        $_SERVER['HTTP_HOST'] = 'crm.example.test';

        Assert::contains('crm.example.test', Mailer::absoluteUrl('/u/abc'));
    } finally {
        Config::set('app_url', $originalUrl);
        $_SERVER['HTTP_HOST'] = $originalHost;
    }
});
