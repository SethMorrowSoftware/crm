<?php

declare(strict_types=1);

/**
 * Helper functions — no database needed.
 *
 * These are small and heavily used, which is exactly the profile where a bug
 * is both likely and expensive: money() and normalize_phone() are on every
 * financial and messaging path in the application.
 */

use Tests\Assert;
use Tests\Harness;

Harness::suite('unit/helpers');

// ---------------------------------------------------------------- escaping

Harness::test('e() escapes HTML special characters', function (): void {
    Assert::same('&lt;script&gt;', e('<script>'));
    Assert::same('&quot;quoted&quot;', e('"quoted"'));
    Assert::same('&#039;single&#039;', e("'single'"));
    Assert::same('a &amp; b', e('a & b'));
});

Harness::test('e() handles null and booleans without warnings', function (): void {
    Assert::same('', e(null));
    Assert::same('Yes', e(true));
    Assert::same('No', e(false));
});

Harness::test('e() neutralises an attribute-escape payload', function (): void {
    // The classic break-out: a value interpolated into an unquoted or
    // single-quoted attribute.
    $payload = '" onmouseover="alert(1)';
    $escaped = e($payload);

    Assert::notContains('onmouseover="', $escaped);
    Assert::contains('&quot;', $escaped);
});

Harness::test('e_js() cannot break out of a script block', function (): void {
    $encoded = e_js(['body' => '</script><script>alert(1)</script>']);

    // The raw angle brackets must be gone, replaced by their hex escapes, or
    // a value could terminate the enclosing <script> block.
    Assert::notContains('</script>', $encoded);
    Assert::notContains('<', $encoded, 'no raw < may survive');
    Assert::notContains('>', $encoded, 'no raw > may survive');

    // Built at runtime so the needle cannot be mangled by source escaping.
    $hexEscapedLessThan = chr(92) . 'u003C';
    Assert::contains($hexEscapedLessThan, $encoded, '< should be hex-escaped');

    // And it still decodes back to the original value.
    Assert::same(
        '</script><script>alert(1)</script>',
        json_decode($encoded, true)['body'] ?? null
    );
});

Harness::test('clean_html() keeps formatting and strips scripts', function (): void {
    $dirty = '<p>Hello <strong>there</strong></p><script>alert(1)</script>';
    $clean = clean_html($dirty);

    Assert::contains('<strong>there</strong>', $clean);
    Assert::notContains('<script', $clean);
    Assert::notContains('alert(1)', $clean);
});

Harness::test('clean_html() strips event handlers from permitted tags', function (): void {
    $clean = clean_html('<p onclick="steal()">text</p>');

    Assert::notContains('onclick', $clean);
    Assert::contains('text', $clean);
});

Harness::test('clean_html() rejects a javascript: href', function (): void {
    $clean = clean_html('<a href="javascript:alert(1)">click</a>');

    Assert::notContains('javascript:', $clean);
});

Harness::test('clean_html() keeps a safe href and adds rel protection', function (): void {
    $clean = clean_html('<a href="https://example.test/page">link</a>');

    Assert::contains('https://example.test/page', $clean);
    Assert::contains('noopener', $clean);
});

// ------------------------------------------------------------------- money

Harness::test('money() formats integer cents', function (): void {
    Assert::same('$0.00', money(0));
    Assert::same('$1.00', money(100));
    Assert::same('$34,900.00', money(3490000));
    Assert::same('$349.00', money(34900));
    Assert::same('$26.99', money(2699));
});

Harness::test('money() handles negatives and null', function (): void {
    Assert::same('-$10.00', money(-1000));
    Assert::same('$0.00', money(null));
    Assert::same('349.00', money(34900, false));
});

Harness::test('money_short() compacts large figures', function (): void {
    Assert::same('$0', money_short(0));
    Assert::same('$999', money_short(99900));
    Assert::same('$1k', money_short(100000));
    Assert::same('$48.5k', money_short(4850000));
    Assert::same('$1.2M', money_short(120000000));
});

// ------------------------------------------------------------------ phones

Harness::test('normalize_phone() produces E.164 for 10-digit US numbers', function (): void {
    Assert::same('+18454692116', normalize_phone('845-469-2116'));
    Assert::same('+18454692116', normalize_phone('(845) 469-2116'));
    Assert::same('+18454692116', normalize_phone('845.469.2116'));
    Assert::same('+18454692116', normalize_phone('8454692116'));
});

Harness::test('normalize_phone() keeps an already-coded number stable', function (): void {
    // Idempotence matters: this runs on every write, so a second pass over a
    // stored value must not corrupt it.
    $once  = normalize_phone('(845) 469-2116');
    $twice = normalize_phone($once);

    Assert::same($once, $twice);
    Assert::same('+18454692116', $twice);
});

Harness::test('normalize_phone() rejects what cannot be a number', function (): void {
    Assert::null(normalize_phone('12345'), 'too short');
    Assert::null(normalize_phone(''), 'empty');
    Assert::null(normalize_phone(null), 'null');
    Assert::null(normalize_phone('not a phone'), 'no digits');
    Assert::null(normalize_phone('1234567890123456789'), 'too long');
});

Harness::test('two renderings of one number compare equal', function (): void {
    // This is the property SMS delivery and Text Club dedup depend on.
    Assert::same(
        normalize_phone('845 469 2116'),
        normalize_phone('+1 (845) 469-2116')
    );
});

Harness::test('format_phone() renders E.164 readably', function (): void {
    Assert::same('(845) 469-2116', format_phone('+18454692116'));
    Assert::same('—', format_phone(null));
    Assert::same('—', format_phone(''));
});

// ------------------------------------------------------------------- misc

Harness::test('slugify() produces URL-safe slugs', function (): void {
    Assert::same('birthday-celebration-sales', slugify('Birthday & Celebration Sales'));
    Assert::same('go-karts', slugify('Go Karts'));
    Assert::same('event-center', slugify('  Event   Center  '));
});

Harness::test('initials() takes at most two letters', function (): void {
    Assert::same('SM', initials('Seth Morrow'));
    Assert::same('S', initials('Seth'));
    Assert::same('SM', initials('Seth Charles Morrow'));
    Assert::same('?', initials(''));
});

Harness::test('str_truncate() respects the limit', function (): void {
    Assert::same('abc', str_truncate('abc', 10));
    Assert::same('abcde…', str_truncate('abcdefghij', 5));
});

Harness::test('percent() never divides by zero', function (): void {
    Assert::same('—', percent(5, 0));
    Assert::same('50%', percent(5, 10));
    Assert::same('33.3%', percent(1, 3, 1));
});

Harness::test('time_ago() reads naturally in both directions', function (): void {
    Assert::same('just now', time_ago(gmdate('Y-m-d H:i:s')));
    Assert::contains('ago', time_ago(gmdate('Y-m-d H:i:s', time() - 7200)));
    Assert::contains('in ', time_ago(gmdate('Y-m-d H:i:s', time() + 7200)));
    Assert::same('—', time_ago(null));
    Assert::same('—', time_ago(''));
});

Harness::test('array_get() walks a dotted path', function (): void {
    $data = ['db' => ['host' => 'localhost', 'port' => 3306]];

    Assert::same('localhost', array_get($data, 'db.host'));
    Assert::same(3306, array_get($data, 'db.port'));
    Assert::same('fallback', array_get($data, 'db.missing', 'fallback'));
    Assert::same('fallback', array_get($data, 'nothing.here', 'fallback'));
});

Harness::test('icon() returns SVG for a known name and nothing for an unknown one', function (): void {
    Assert::contains('<svg', icon('check'));
    Assert::contains('aria-hidden="true"', icon('check'), 'icons are decorative');
    Assert::same('', icon('no-such-icon'), 'an unknown icon must not break a page');
});
