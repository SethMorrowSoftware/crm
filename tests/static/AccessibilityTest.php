<?php

declare(strict_types=1);

/**
 * Structural accessibility, checked across every view.
 *
 * Contrast is measured in static/contrast; this covers the parts a colour
 * calculation cannot see — whether a control has a name, whether a table says
 * which cells are headers, whether a page can be navigated without a mouse.
 *
 * The audience here is not hypothetical. A front-desk screen gets used with
 * one hand while the other holds a phone, by somebody squinting at a monitor
 * in a brightly lit arcade, with a keyboard because the till queue is five
 * families deep. Every one of those is the same accessibility work.
 *
 * These are static checks over the markup, so they prove a label EXISTS, not
 * that it reads well. They catch the mechanical failures, which are the ones
 * that actually ship.
 */

use Tests\Assert;
use Tests\Harness;

Harness::suite('static/accessibility');

/** @return list<string> Every view and layout file. */
function a11y_view_files(): array
{
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(CC_ROOT . '/app/Views', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    // The installer renders its own pages, outside the view layer.
    foreach ((array) glob(CC_ROOT . '/install/steps/*.php') as $step) {
        $files[] = (string) $step;
    }

    sort($files);

    return $files;
}

function a11y_relative(string $path): string
{
    return str_replace(CC_ROOT . '/', '', $path);
}

/**
 * Replace each PHP block with a stable placeholder.
 *
 * Without this, a tag cannot be matched at all: the `?>` inside
 * `id="field_<?= e($slug) ?>"` closes the tag as far as any HTML regex is
 * concerned, so the attributes after it — including the aria-label that makes
 * the control accessible — are invisible to the check, and it reports a
 * failure that is not there.
 *
 * Identical expressions get identical placeholders, so a `for` and an `id`
 * built the same way still match each other, while two different expressions
 * stay distinguishable.
 */
function a11y_strip_php(string $html): string
{
    $seen = [];

    return (string) preg_replace_callback(
        '/<\?(?:php|=)?.*?\?>/s',
        static function (array $match) use (&$seen): string {
            $key = preg_replace('/\s+/', ' ', trim($match[0])) ?? $match[0];

            if (!isset($seen[$key])) {
                $seen[$key] = 'php' . count($seen);
            }

            return '@' . $seen[$key] . '@';
        },
        $html
    );
}

// ---------------------------------------------------------- form controls

/**
 * Form controls in one document that have no accessible name.
 *
 * A <label for>, a wrapping <label>, an aria-label or an aria-labelledby all
 * count. Hidden and submit inputs are exempt: they take their name from their
 * value, or have none to take.
 *
 * @return list<string>
 */
function a11y_unnamed_controls(string $markup): array
{
    $html = a11y_strip_php($markup);

    preg_match_all('/<label[^>]*\bfor="([^"]+)"/i', $html, $labelled);
    $labelledIds = $labelled[1];

    preg_match_all('/<(input|select|textarea)\b([^>]*?)\/?>/is', $html, $controls, PREG_SET_ORDER);

    $unnamed = [];

    foreach ($controls as $control) {
        $attributes = $control[2];

        if (preg_match('/type="(hidden|submit|image)"/i', $attributes) === 1) {
            continue;
        }

        if (
            preg_match('/\baria-label(?:ledby)?="/i', $attributes) === 1
            || preg_match('/\btitle="/i', $attributes) === 1
        ) {
            continue;
        }

        if (
            preg_match('/\bid="([^"]+)"/i', $attributes, $id) === 1
            && in_array($id[1], $labelledIds, true)
        ) {
            continue;
        }

        // A control nested inside its own <label> needs no `for`.
        if (
            preg_match(
                '/<label\b[^>]*>(?:(?!<\/label>).)*' . preg_quote($control[0], '/') . '/s',
                $html
            ) === 1
        ) {
            continue;
        }

        $unnamed[] = '<' . strtolower($control[1])
            . rtrim(preg_replace('/\s+/', ' ', substr($attributes, 0, 70)) ?? '') . '>';
    }

    return $unnamed;
}

Harness::test('the unnamed-control detector works', function (): void {
    // A static gate that cannot fail is worse than no gate, because it reads
    // as coverage. These fixtures prove it finds what it claims to find — and
    // that it does not flag the four shapes the views legitimately use.
    Assert::count(
        1,
        a11y_unnamed_controls('<input type="text" name="orphan">'),
        'a bare input should be reported'
    );

    Assert::count(
        1,
        a11y_unnamed_controls('<label for="elsewhere">X</label><input type="text" id="here">'),
        'a label pointing at another id does not name this control'
    );

    Assert::same([], a11y_unnamed_controls('<label for="a">A</label><input id="a" type="text">'));
    Assert::same([], a11y_unnamed_controls('<label>A <input type="checkbox" name="a"></label>'));
    Assert::same([], a11y_unnamed_controls('<input type="text" aria-label="Search">'));
    Assert::same([], a11y_unnamed_controls('<input type="hidden" name="_token" value="x">'));

    // And the shape that broke the first version of this check: PHP inside the
    // tag, with the aria-label after it.
    Assert::same([], a11y_unnamed_controls(
        '<select name="scopes[<?= e($slug) ?>]" aria-label="Reach for <?= e($label) ?>">'
        . '<option value="all">All</option></select>'
    ));
});

Harness::test('every form control has a programmatic name', function (): void {
    $problems = [];

    foreach (a11y_view_files() as $file) {
        foreach (a11y_unnamed_controls((string) file_get_contents($file)) as $control) {
            $problems[] = a11y_relative($file) . ': ' . $control;
        }
    }

    Assert::same(
        [],
        $problems,
        "form controls with no accessible name:\n  - " . implode("\n  - ", $problems)
    );
});

Harness::test('no label points at an id that does not exist in the same file', function (): void {
    // A `for` that matches nothing is worse than no label: it looks correct in
    // review and does nothing in a browser.
    $problems = [];

    foreach (a11y_view_files() as $file) {
        $html = a11y_strip_php((string) file_get_contents($file));

        preg_match_all('/<label[^>]*\bfor="([^"]+)"/i', $html, $labels);

        foreach ($labels[1] as $target) {
            // Skip anything built with PHP, which cannot be resolved here.
            if (str_contains($target, '<?')) {
                continue;
            }

            if (!preg_match('/\bid="' . preg_quote($target, '/') . '"/i', $html)) {
                $problems[] = a11y_relative($file) . ': label for="' . $target . '" matches no id';
            }
        }
    }

    Assert::same([], $problems, implode("\n  - ", $problems));
});

// ------------------------------------------------------------- icons & images

Harness::test('every image has alt text or is marked decorative', function (): void {
    $problems = [];

    foreach (a11y_view_files() as $file) {
        $html = a11y_strip_php((string) file_get_contents($file));

        preg_match_all('/<img\b([^>]*)>/i', $html, $images, PREG_SET_ORDER);

        foreach ($images as $image) {
            if (preg_match('/\balt=/i', $image[1]) !== 1) {
                $problems[] = a11y_relative($file) . ': <img' . $image[1] . '>';
            }
        }
    }

    Assert::same([], $problems, "images with no alt attribute:\n  - " . implode("\n  - ", $problems));
});

Harness::test('decorative icons are hidden from assistive technology', function (): void {
    // An icon beside a text label is read twice without this, which turns
    // "Settings" into "image Settings".
    $icons = (string) file_get_contents(CC_ROOT . '/app/Views/partials/icons.php');

    Assert::contains(
        'aria-hidden="true"',
        $icons,
        'the icon helper should mark its output decorative by default'
    );
    Assert::contains('focusable="false"', $icons, 'IE/Edge put SVGs in the tab order without this');
});

// ------------------------------------------------------------------ tables

Harness::test('every table header cell declares a scope', function (): void {
    // Without scope, a screen reader cannot tell which header belongs to which
    // cell, and a data table becomes an unordered list of values.
    $problems = [];

    foreach (a11y_view_files() as $file) {
        $html = a11y_strip_php((string) file_get_contents($file));

        preg_match_all('/<th\b([^>]*)>/i', $html, $headers, PREG_SET_ORDER);

        foreach ($headers as $header) {
            if (preg_match('/\bscope="(col|row|colgroup|rowgroup)"/i', $header[1]) !== 1) {
                $problems[] = a11y_relative($file) . ': <th' . rtrim($header[1]) . '>';
            }
        }
    }

    Assert::same([], $problems, "table headers with no scope:\n  - " . implode("\n  - ", $problems));
});

// ------------------------------------------------------- keyboard and structure

Harness::test('the layouts set a language and offer a skip link', function (): void {
    foreach (['app', 'bare'] as $layout) {
        $html = (string) file_get_contents(CC_ROOT . '/app/Views/layouts/' . $layout . '.php');

        Assert::matches(
            '/<html[^>]*\blang="[a-z]{2}/i',
            $html,
            $layout . ': without lang, a screen reader guesses the pronunciation'
        );
    }

    $app = (string) file_get_contents(CC_ROOT . '/app/Views/layouts/app.php');

    Assert::contains('skip-link', $app, 'a keyboard user should not have to tab through the whole nav');
    Assert::contains('id="main"', $app, 'and the skip link needs somewhere to land');
});

Harness::test('no view uses an inline event handler', function (): void {
    // Two reasons, and they point the same way: the Content-Security-Policy
    // forbids inline script, and an onclick on a <div> is not reachable by
    // keyboard. Anything interactive has to be a button or a link.
    $problems = [];

    foreach (a11y_view_files() as $file) {
        $html = (string) file_get_contents($file);

        if (preg_match('/\bon(click|change|submit|input|load|mouseover|keydown)\s*=/i', $html, $match) === 1) {
            $problems[] = a11y_relative($file) . ': ' . $match[0];
        }
    }

    Assert::same([], $problems, implode("\n  - ", $problems));
});

Harness::test('no element is given a positive tabindex', function (): void {
    // A positive tabindex jumps the focus order out of document order, and one
    // of them breaks the sequence for the whole page.
    $problems = [];

    foreach (a11y_view_files() as $file) {
        $html = (string) file_get_contents($file);

        if (preg_match('/tabindex="[1-9]/', $html, $match) === 1) {
            $problems[] = a11y_relative($file) . ': ' . $match[0];
        }
    }

    Assert::same([], $problems, implode("\n  - ", $problems));
});

Harness::test('a destructive action is a form, never a link', function (): void {
    // A GET link that deletes something is triggered by a browser prefetch, a
    // link checker or a mail client. Deletes are POSTs with a CSRF token.
    $problems = [];

    foreach (a11y_view_files() as $file) {
        $html = a11y_strip_php((string) file_get_contents($file));

        preg_match_all('/<a\b[^>]*href="([^"]*)"/i', $html, $links, PREG_SET_ORDER);

        foreach ($links as $link) {
            if (preg_match('#/(delete|destroy|remove|deactivate|revoke)(/|\?|"|$)#i', $link[1]) === 1) {
                $problems[] = a11y_relative($file) . ': ' . $link[1];
            }
        }
    }

    Assert::same([], $problems, implode("\n  - ", $problems));
});

// ------------------------------------------------------------ error reporting

Harness::test('validation errors are announced, not only coloured', function (): void {
    // Colour alone fails 1.4.1 Use of Colour; a field error also needs text,
    // and the summary needs a live region so it is read when it appears.
    $layout = (string) file_get_contents(CC_ROOT . '/app/Views/layouts/app.php');

    Assert::matches(
        '/role="(alert|status)"|aria-live=/',
        $layout,
        'flash messages should be in a live region so they are announced'
    );

    $css = (string) file_get_contents(CC_ROOT . '/assets/css/app.css');

    Assert::contains('.field-error', $css, 'there should be a textual error style, not just a red border');
});

Harness::test('the stylesheet respects a reduced-motion preference', function (): void {
    $css = (string) file_get_contents(CC_ROOT . '/assets/css/app.css');

    Assert::contains(
        'prefers-reduced-motion',
        $css,
        'animation can cause real nausea; the preference is there to be honoured'
    );
});

Harness::test('font sizes are relative, so a browser zoom still works', function (): void {
    // A px font size does not respond to the browser's own text-size setting
    // in some engines, which is the first accommodation most people with low
    // vision ever use.
    $css = (string) file_get_contents(CC_ROOT . '/assets/css/app.css');

    preg_match_all('/font-size:\s*(\d+)px/i', $css, $matches, PREG_SET_ORDER);

    $offenders = [];

    foreach ($matches as $match) {
        // A declaration inside the print block is acceptable: pt and px are
        // meaningful on paper.
        $offenders[] = $match[0];
    }

    Assert::same(
        [],
        $offenders,
        "px font sizes in the screen stylesheet:\n  - " . implode("\n  - ", $offenders)
    );
});
