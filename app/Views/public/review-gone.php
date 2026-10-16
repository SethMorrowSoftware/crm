<?php

declare(strict_types=1);

/**
 * A review ask whose destination has since been removed.
 *
 * The venue took the platform link out of their settings after this ask went
 * out. Thanking them and stopping is better than redirecting to nowhere, and
 * the click is still recorded, so the figures do not lose it.
 *
 * @var string $platform
 * @var string $venueName
 */
?>

<h1 class="bare-title">Thank you</h1>

<p class="bare-sub">
    We are not able to send you to <?= e($platform) ?> at the moment &mdash; but thank you
    for being willing to, and thank you for the score you left. It reached us.
</p>

<p class="bare-foot muted"><?= e($venueName) ?></p>
