<?php

declare(strict_types=1);

/**
 * WCAG contrast, measured rather than asserted in a comment.
 *
 * The stylesheet's header claims it targets AA. This computes the ratios from
 * the actual token values, for both themes, so the claim is either true or the
 * suite is red. The alternative — somebody eyeballing a swatch and deciding it
 * looks fine — is how a palette ends up with 3:1 body text, which is exactly
 * what this found the first time it ran.
 *
 * The thresholds come from WCAG 2.1:
 *   1.4.3 Contrast (Minimum)  — 4.5:1 for body text, 3:1 for large text
 *   1.4.11 Non-text Contrast  — 3:1 for the visual boundary of a control
 */

use Tests\Assert;
use Tests\Harness;

Harness::suite('static/contrast');

/** Relative luminance, per the WCAG definition. */
function css_luminance(string $hex): float
{
    $hex = ltrim(trim($hex), '#');

    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }

    $channels = [];

    foreach ([0, 2, 4] as $offset) {
        $value = hexdec(substr($hex, $offset, 2)) / 255;
        $channels[] = $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
    }

    return (0.2126 * $channels[0]) + (0.7152 * $channels[1]) + (0.0722 * $channels[2]);
}

function css_contrast(string $foreground, string $background): float
{
    $a = css_luminance($foreground);
    $b = css_luminance($background);

    return round((max($a, $b) + 0.05) / (min($a, $b) + 0.05), 2);
}

/**
 * Pull the hex-valued custom properties out of one CSS block.
 *
 * @return array<string,string>
 */
function css_tokens(string $css, string $selector): array
{
    $escaped = preg_quote($selector, '/');

    if (preg_match('/' . $escaped . '\s*\{(.*?)\n\s*\}/s', $css, $block) !== 1) {
        Assert::fail('could not find the CSS block for ' . $selector);
    }

    preg_match_all('/--([a-z0-9-]+):\s*(#[0-9a-fA-F]{3,8})\s*;/', $block[1], $matches, PREG_SET_ORDER);

    $tokens = [];

    foreach ($matches as $match) {
        $tokens[$match[1]] = $match[2];
    }

    return $tokens;
}

$css = (string) file_get_contents(CC_ROOT . '/assets/css/app.css');

$light = css_tokens($css, ':root');
$dark  = array_merge($light, css_tokens($css, ':root[data-theme="dark"]'));

/**
 * Every foreground/background pairing the application actually renders, with
 * the threshold that applies to it.
 *
 * @var list<array{0:string,1:string,2:float,3:string}>
 */
$pairs = [
    // Body text on each of the three surfaces.
    ['ink',       'panel',       4.5, 'body text on a card'],
    ['ink',       'bg',          4.5, 'body text on the page'],
    ['ink',       'panel-soft',  4.5, 'body text on a striped row'],
    ['ink-soft',  'panel',       4.5, 'secondary text on a card'],
    ['ink-soft',  'bg',          4.5, 'ghost button label'],
    ['muted',     'panel',       4.5, 'field hints and card subtitles'],
    ['muted',     'bg',          4.5, 'page lede'],
    ['muted',     'panel-soft',  4.5, 'muted text on a striped row'],

    // --faint is used for real text — .muted, .empty, breadcrumbs, card meta,
    // stat labels — so it is held to the body-text threshold, not a decorative
    // one.
    ['faint',     'panel',       4.5, 'meta text on a card'],
    ['faint',     'bg',          4.5, 'breadcrumbs'],
    ['faint',     'panel-soft',  4.5, 'meta text on a striped row'],

    // Links and the accent.
    ['brand',     'panel',       4.5, 'a link on a card'],
    ['brand',     'bg',          4.5, 'a link on the page'],
    ['brand',     'brand-light', 4.5, 'accent text on the accent tint'],

    // Status text, on its own tint and on a plain card.
    ['ok',        'ok-bg',       4.5, 'success alert text'],
    ['warn',      'warn-bg',     4.5, 'warning alert text'],
    ['bad',       'bad-bg',      4.5, 'error alert text'],
    ['info',      'info-bg',     4.5, 'info alert text'],
    ['ok',        'panel',       4.5, 'success text on a card'],
    ['warn',      'panel',       4.5, 'warning text on a card'],
    ['bad',       'panel',       4.5, 'error text on a card'],
    ['info',      'panel',       4.5, 'info text on a card'],

    // Text on a saturated fill.
    ['on-accent', 'brand',       4.5, 'a primary button label'],
    ['on-accent', 'brand-dark',  4.5, 'a primary button label on hover'],
    ['on-accent', 'bad',         4.5, 'a danger button label'],

    // 1.4.11: the boundary of a control must be findable.
    ['field-border', 'panel',      3.0, 'the border of a text input'],
    ['field-border', 'bg',         3.0, 'the border of an input on the page'],
    ['field-border', 'panel-soft', 3.0, 'the border of an input on a striped row'],
];

foreach (['light' => $light, 'dark' => $dark] as $theme => $tokens) {
    Harness::test($theme . ' theme: every text and control pairing meets WCAG AA', function () use (
        $pairs,
        $tokens,
        $theme
    ): void {
        $failures = [];

        foreach ($pairs as [$foreground, $background, $minimum, $where]) {
            Assert::hasKey($foreground, $tokens, 'missing token --' . $foreground . ' in the ' . $theme . ' theme');
            Assert::hasKey($background, $tokens, 'missing token --' . $background . ' in the ' . $theme . ' theme');

            $ratio = css_contrast($tokens[$foreground], $tokens[$background]);

            if ($ratio < $minimum) {
                $failures[] = sprintf(
                    '%s on %s is %.2f:1, needs %.1f:1 (%s)',
                    $foreground,
                    $background,
                    $ratio,
                    $minimum,
                    $where
                );
            }
        }

        Assert::same(
            [],
            $failures,
            $theme . " theme contrast failures:\n  - " . implode("\n  - ", $failures)
        );
    });
}

Harness::test('the dark theme is defined identically in both places', function () use ($css): void {
    // The palette is declared twice — once under prefers-color-scheme for the
    // device preference, once under [data-theme="dark"] for an explicit
    // choice. If they drift, a user who picks dark gets a different, untested
    // palette from one who merely has a dark device.
    $media = css_tokens($css, ':root:not([data-theme="light"])');
    $explicit = css_tokens($css, ':root[data-theme="dark"]');

    Assert::same(
        $media,
        $explicit,
        'the two dark-theme declarations have diverged; copy one over the other'
    );
});

Harness::test('no stylesheet rule hard-codes white on a coloured fill', function () use ($css): void {
    // The failure this prevents: `color: #fff` on var(--brand) reads perfectly
    // in the light theme and fails at 3.3:1 in the dark one, because the dark
    // accent is a pastel. --on-accent flips with the theme; a literal cannot.
    $lines = explode("\n", $css);

    foreach ($lines as $number => $line) {
        if (!preg_match('/background:\s*var\(--(brand|bad|ok|warn|info)[a-z-]*\)/', $line)) {
            continue;
        }

        if (preg_match('/color:\s*(#fff|#ffffff|white)\b/i', $line) === 1) {
            Assert::fail(sprintf(
                'app.css:%d sets a literal white on a themed fill; use var(--on-accent): %s',
                $number + 1,
                trim($line)
            ));
        }
    }
});

Harness::test('the focus ring is not the only cue, and is visible on both themes', function () use ($css): void {
    // Removing the outline without replacing it is the single most common
    // keyboard-accessibility defect on the web.
    Assert::notContains('outline: none', $css, 'a focus style must never simply be removed');
    Assert::notContains('outline: 0', $css);
    Assert::contains(':focus', $css, 'there should be focus styling at all');
});
