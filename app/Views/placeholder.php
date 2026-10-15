<?php

declare(strict_types=1);

/**
 * An honest "not built yet" page.
 *
 * @var int           $phase
 * @var string        $icon
 * @var list<string>  $features
 * @var string|null   $note
 * @var string        $pageTitle
 */
?>
<section class="card card-wide">
    <div class="placeholder">
        <span class="placeholder-icon" aria-hidden="true"><?= icon($icon, 32) ?></span>
        <h2><?= e($pageTitle) ?></h2>
        <p class="placeholder-phase">Arrives in Phase <?= (int) $phase ?></p>

        <p class="placeholder-lede">
            This module is specified and scheduled, not guessed at. Here is what it will do:
        </p>

        <ul class="feature-list">
            <?php foreach ($features as $feature): ?>
                <li><?= icon('check', 16) ?><span><?= e($feature) ?></span></li>
            <?php endforeach; ?>
        </ul>

        <?php if ($note !== null): ?>
            <p class="placeholder-note"><?= icon('info', 16) ?><span><?= e($note) ?></span></p>
        <?php endif; ?>

        <p class="placeholder-actions">
            <a class="btn btn-ghost" href="<?= e(url('/')) ?>">Back to the dashboard</a>
        </p>
    </div>
</section>
