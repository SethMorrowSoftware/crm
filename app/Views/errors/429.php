<?php

declare(strict_types=1);

/**
 * The shared error page.
 *
 * @var int         $status
 * @var string      $title
 * @var string|null $message
 * @var string|null $detail  Stack trace; only ever set in debug mode.
 */
?>
<div class="error-page">
    <p class="error-status"><?= e((string) $status) ?></p>
    <h1><?= e($title) ?></h1>
    <?php if (!empty($message)): ?>
        <p class="error-message"><?= e($message) ?></p>
    <?php endif; ?>

    <?php if (!empty($detail)): ?>
        <details class="error-detail">
            <summary>Technical detail (shown because debug mode is on)</summary>
            <pre><?= e($detail) ?></pre>
        </details>
    <?php endif; ?>

    <p class="error-actions">
        <a class="btn" href="<?= e(url('/')) ?>">Back to the dashboard</a>
    </p>
</div>
