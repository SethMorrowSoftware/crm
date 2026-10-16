<?php

declare(strict_types=1);

/**
 * A layout for a page that will live inside an iframe on the venue's website.
 *
 * Deliberately almost nothing: no brand bar, no flash messages, no
 * navigation, and a transparent background so the widget takes the colour of
 * whatever page it is dropped into rather than painting a white box on a dark
 * site. The only chrome is the stylesheet.
 *
 * It posts its own height to the parent window so the iframe can resize to
 * fit — a review wall is as tall as the reviews in it, and a fixed-height
 * iframe either clips the last one or leaves a gap. The script that does that
 * lives in `assets/js/embed.js`, not inline: the Content-Security-Policy
 * forbids inline script, and an embeddable page is the last place to make an
 * exception.
 *
 * @var string $content
 * @var string $pageTitle
 */

$pageTitle ??= 'Reviews';

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="embed">
<?= $content ?>
<script src="<?= e(asset('js/embed.js')) ?>" defer></script>
</body>
</html>
