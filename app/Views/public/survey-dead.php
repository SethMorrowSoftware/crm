<?php

declare(strict_types=1);

/**
 * A feedback link that did not work.
 *
 * One answer for every reason — expired, mistyped, truncated by a mail
 * program — because saying which would tell somebody poking at URLs whether a
 * token exists.
 *
 * @var string $message
 * @var string $venueName
 */
?>

<h1 class="bare-title">That link did not work</h1>

<p class="bare-sub"><?= e($message) ?></p>

<p class="bare-sub">
    If you have something to tell us, replying to the email it came from reaches a
    person, and so does ringing the front desk.
</p>

<p class="bare-foot muted"><?= e($venueName) ?></p>
