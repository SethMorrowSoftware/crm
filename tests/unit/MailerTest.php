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

Harness::test('a tampered token is rejected', function (): void {
    $token = Mailer::unsubscribeToken('victim@example.test', 1);

    // Flip the last character, whatever it is.
    $tampered = substr($token, 0, -1) . (str_ends_with($token, 'A') ? 'B' : 'A');

    Assert::null(
        Mailer::parseUnsubscribeToken($tampered),
        'anyone could otherwise unsubscribe any address they could guess'
    );
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
