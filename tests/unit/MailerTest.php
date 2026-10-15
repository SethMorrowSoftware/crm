<?php

declare(strict_types=1);

/**
 * The parts of the mailer that need no database: merge-tag substitution, the
 * HTML-to-text fallback, and the unsubscribe token.
 *
 * The send path itself is tested in integration/mailer, because refusing to
 * message a guest without consent is only meaningful against real rows.
 */

use App\Core\Config;
use App\Core\Mailer;
use App\Core\Settings;
use Tests\Assert;
use Tests\Harness;

Harness::suite('unit/mailer');

// Config only — no database connection is attempted by any test here.
test_boot();

// absoluteUrl() reads the request host, which a CLI process does not have.
$_SERVER['HTTP_HOST'] = 'crm.example.test';
$_SERVER['REQUEST_URI'] = '/';

// ------------------------------------------------------------------ merging

Harness::test('merge substitutes a tag', function (): void {
    Assert::same(
        'Hello Marisol,',
        Mailer::merge('Hello {{name}},', ['name' => 'Marisol'])
    );
});

Harness::test('merge is whitespace and case tolerant', function (): void {
    Assert::same('Hi Dana', Mailer::merge('Hi {{  NAME  }}', ['name' => 'Dana']));
});

Harness::test('merge supports dotted paths', function (): void {
    Assert::same(
        'Castle Fun Center',
        Mailer::merge('{{venue.name}}', ['venue' => ['name' => 'Castle Fun Center']])
    );
});

Harness::test('a tag with no value becomes empty rather than showing braces', function (): void {
    $result = Mailer::merge('Hello {{name}}, your party is {{when}}.', ['name' => 'Rory']);

    Assert::notContains('{{', $result, 'an unresolved tag must not reach a guest');
    Assert::same('Hello Rory, your party is .', $result);
});

Harness::test('merge escapes a value that looks like markup', function (): void {
    // A guest can type whatever they like into a booking form. Their own
    // confirmation email is rendered in a webmail client, so an unescaped
    // value is a stored XSS with the venue's own domain on it.
    $result = Mailer::merge('Hello {{name}}', ['name' => '<script>alert(1)</script>']);

    Assert::notContains('<script', $result);
    Assert::contains('&lt;script&gt;', $result);
});

Harness::test('merge escapes a quote so an attribute cannot be broken out of', function (): void {
    $result = Mailer::merge('<a href="{{link}}">Go</a>', ['link' => '" onmouseover="x']);

    Assert::notContains('" onmouseover="x', $result);
    Assert::contains('&quot;', $result);
});

Harness::test('an ampersand in a URL is entity-encoded, which is correct in HTML', function (): void {
    $result = Mailer::merge('<a href="{{link}}">x</a>', ['link' => 'https://e.test/a?b=1&c=2']);

    Assert::contains('b=1&amp;c=2', $result, 'HTML attributes need &amp;, not a bare ampersand');
});

// -------------------------------------------------------------- html to text

Harness::test('the text fallback strips tags and keeps the words', function (): void {
    $text = Mailer::htmlToText('<p>Hello <strong>Dana</strong>,</p><p>Your party is booked.</p>');

    Assert::notContains('<', $text);
    Assert::contains('Hello Dana', $text);
    Assert::contains('Your party is booked.', $text);
});

Harness::test('the text fallback does not leak script or style content', function (): void {
    $text = Mailer::htmlToText(
        '<style>body{color:red}</style><script>alert(1)</script><p>Real words.</p>'
    );

    Assert::contains('Real words.', $text);
    Assert::notContains('alert(1)', $text, 'script bodies must not appear as visible text');
    Assert::notContains('color:red', $text);
});

Harness::test('a link keeps its destination in the text version', function (): void {
    $text = Mailer::htmlToText('<p>See <a href="https://example.test/quote">your quote</a>.</p>');

    Assert::contains('https://example.test/quote', $text, 'a text-only reader still needs the URL');
});

// ------------------------------------------------------- unsubscribe tokens

Harness::test('an unsubscribe token round-trips', function (): void {
    $token = Mailer::unsubscribeToken('Parent@Example.Test', 42);
    $parsed = Mailer::parseUnsubscribeToken($token);

    Assert::notNull($parsed);
    Assert::same('parent@example.test', $parsed['address'], 'the address is normalised to lower case');
    Assert::same(42, $parsed['contact_id']);
});

Harness::test('a token with no contact parses to a null contact id', function (): void {
    $parsed = Mailer::parseUnsubscribeToken(Mailer::unsubscribeToken('nobody@example.test', null));

    Assert::notNull($parsed);
    Assert::null($parsed['contact_id']);
});

Harness::test('a token is URL safe', function (): void {
    // It travels in a path segment and through a mail client's link rewriter,
    // so + / and = would all be mangled somewhere along the way.
    $token = Mailer::unsubscribeToken('someone+tagged@example.test', 7);

    Assert::matches('/^[A-Za-z0-9_-]+$/', $token);
});

/**
 * Tamper with a token in a way that is GUARANTEED to change its meaning.
 *
 * Mutating base64 characters is not: the final character of a token carries
 * bits that no byte uses, so some mutations decode to identical bytes and the
 * signature still verifies. A test that tampered that way passed or failed
 * depending on the length of the payload — which, for a real token, depends on
 * the contact's auto-increment id. It passed locally on every id I happened to
 * use and failed in CI on one of five jobs.
 *
 * So: decode, flip a bit in the signature, and re-encode canonically.
 */
function tampered_token(string $token): string
{
    $raw = (string) base64_decode(strtr($token, '-_', '+/'), true);

    // The signature is the last of the three pipe-separated fields.
    $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] === 'a' ? 'b' : 'a';

    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

Harness::test('a tampered token is rejected', function (): void {
    $token = Mailer::unsubscribeToken('victim@example.test', 1);
    $tampered = tampered_token($token);

    Assert::notSame($token, $tampered, 'the tamper must actually change the token');
    Assert::null(
        Mailer::parseUnsubscribeToken($tampered),
        'anyone could otherwise unsubscribe any address they could guess'
    );
});

Harness::test('a tampered token is rejected for every payload length', function (): void {
    // The bug this covers was length-dependent and so appeared only for some
    // contact ids. Sweeping a few hundred covers every residue of the payload
    // length mod 3, which is what decides whether the tail has spare bits.
    $accepted = [];

    for ($id = 1; $id <= 300; $id++) {
        $token = Mailer::unsubscribeToken('victim@example.test', $id);

        if (Mailer::parseUnsubscribeToken(tampered_token($token)) !== null) {
            $accepted[] = $id;
        }
    }

    Assert::same([], $accepted, 'a signature flip must be refused at every length');
});

Harness::test('a non-canonical spelling of a real token is refused', function (): void {
    // base64 tail malleability: replacing the last characters can decode to
    // the same bytes, so the HMAC still matches. MEASURED before the fix: 8 of
    // the first 100 contact ids had a token whose last two characters could be
    // swapped outright and still parsed. It was never a forgery route — the
    // signature still pins the address — but one link with many valid
    // spellings cannot be logged, revoked or rate-limited as one thing.
    $variants = 0;

    for ($id = 1; $id <= 300; $id++) {
        $token = Mailer::unsubscribeToken('victim@example.test', $id);

        foreach (['ZZ', 'AA', 'zz', '00', '--', '__'] as $tail) {
            $variant = substr($token, 0, -2) . $tail;

            if ($variant === $token) {
                continue;
            }

            if (Mailer::parseUnsubscribeToken($variant) !== null) {
                $variants++;
            }
        }
    }

    Assert::same(0, $variants, 'only the canonical encoding may be accepted');
});

Harness::test('every real token still round-trips at every length', function (): void {
    // The other half of the contract: canonicalisation must not have made any
    // link the application issues unreadable.
    $broken = [];

    for ($id = 1; $id <= 300; $id++) {
        $parsed = Mailer::parseUnsubscribeToken(
            Mailer::unsubscribeToken('guest@example.test', $id)
        );

        if ($parsed === null || $parsed['address'] !== 'guest@example.test' || $parsed['contact_id'] !== $id) {
            $broken[] = $id;
        }
    }

    Assert::same([], $broken, 'a real unsubscribe link must work for every contact');
});

Harness::test('a token forged for another address is rejected', function (): void {
    // The signature covers the address, so swapping the address in the payload
    // must invalidate it. Rebuild the payload by hand to prove it.
    $forged = rtrim(strtr(base64_encode('target@example.test|0|' . str_repeat('0', 32)), '+/', '-_'), '=');

    Assert::null(Mailer::parseUnsubscribeToken($forged));
});

Harness::test('rubbish is rejected rather than throwing', function (): void {
    foreach (['', 'x', '!!!!', str_repeat('A', 400), 'a|b|c'] as $junk) {
        Assert::null(Mailer::parseUnsubscribeToken($junk), 'input: ' . $junk);
    }
});

Harness::test('a token signed with a different app key does not verify', function (): void {
    $token = Mailer::unsubscribeToken('key-test@example.test', 3);
    $original = Config::get('app_key');

    try {
        Config::set('app_key', base64_encode(str_repeat("\x11", 32)));

        Assert::null(
            Mailer::parseUnsubscribeToken($token),
            'the HMAC must actually depend on the installation key'
        );
    } finally {
        Config::set('app_key', $original);
    }
});

// --------------------------------------------------------------- absolute URL

Harness::test('absoluteUrl produces an absolute address', function (): void {
    Assert::matches('#^https?://#', Mailer::absoluteUrl('/u/abc'));
});

Harness::test('absoluteUrl does not double a slash', function (): void {
    Assert::notContains('//u/', Mailer::absoluteUrl('/u/abc'));
});

// ------------------------------------------- subject lines are not HTML

Harness::test('mergeText does not HTML-escape, and merge still does', function (): void {
    // A subject line is not HTML. Merging it with the body's escaper put
    // entities in guests' inboxes: MEASURED, a venue called "Dave & Buster's"
    // turned "Reset your {{venue_name}} password" into
    // "Reset your Dave &amp; Buster&#039;s password", and nothing downstream
    // decodes it — singleLine() leaves entities alone and encodeHeaderValue()
    // passes plain ASCII through unchanged.
    $data = ['venue_name' => "Dave & Buster's"];

    Assert::same(
        "Reset your Dave & Buster's password",
        Mailer::mergeText('Reset your {{venue_name}} password', $data)
    );

    Assert::same(
        'Reset your Dave &amp; Buster&#039;s password',
        Mailer::merge('Reset your {{venue_name}} password', $data),
        'the HTML path must still escape'
    );
});

Harness::test('a name with an apostrophe survives into the subject intact', function (): void {
    foreach (["Mia O'Brien", 'Smith & Sons', 'Jack "JJ" Nolan', 'Zoë Ruiz'] as $name) {
        $subject = Mailer::mergeText("{{child_name}}'s party", ['child_name' => $name]);

        Assert::contains($name, $subject, 'the name must appear as typed');
        Assert::notContains('&amp;', $subject);
        Assert::notContains('&#039;', $subject);
        Assert::notContains('&quot;', $subject);
    }
});

// -------------------------------------------- where an email link points

Harness::test('a link prefers configured app_url over the request host', function (): void {
    // Reset-link poisoning: a link in an email is acted on away from the
    // application, so whoever controls its host controls where the recipient
    // lands — and a reset link carries a live token. app_url was null on every
    // install (installer, config.sample.php and the test runner all wrote
    // null), so the unvalidated Host header was the normal path.
    $originalUrl = Config::get('app_url');
    $originalHost = $_SERVER['HTTP_HOST'] ?? null;

    try {
        Config::set('app_url', 'https://real.example.test');
        $_SERVER['HTTP_HOST'] = 'attacker.test';

        $url = Mailer::absoluteUrl('/reset-password/' . str_repeat('a', 64));

        Assert::contains('real.example.test', $url);
        Assert::notContains('attacker.test', $url, 'the Host header must not win');
    } finally {
        Config::set('app_url', $originalUrl);
        $_SERVER['HTTP_HOST'] = $originalHost;
    }
});

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
