<?php

declare(strict_types=1);

/**
 * The public review wall.
 *
 * Two uses, one template: a page a venue can link to, and the same markup
 * inside an iframe on their own website (`?embed=1`, which swaps the layout).
 * Writing it twice would mean two things to keep in step, and the embedded
 * copy is the one nobody would notice had gone stale.
 *
 * FEATURED REVIEWS ONLY. Nothing appears here because it is five stars; it
 * appears because somebody at the venue chose it. That is a publishing
 * decision, and the controller does not make it automatically.
 *
 * @var list<array<string,mixed>> $reviews
 * @var float|null                $average
 * @var int                       $count
 * @var bool                      $embed
 * @var string                    $venueName
 */
?>

<div class="review-wall" data-embed="<?= $embed ? '1' : '0' ?>">
    <?php if (!$embed): ?>
        <h1 class="bare-title">What our guests say</h1>
    <?php endif; ?>

    <?php if ($reviews === []): ?>
        <p class="muted">No reviews are featured here at the moment.</p>
    <?php else: ?>
        <?php if ($average !== null): ?>
            <p class="review-wall-head">
                <span class="rating rating-lg" aria-hidden="true">
                    <?= str_repeat('&#9733;', (int) round($average)) ?><?php
                    ?><span class="rating-off"><?= str_repeat('&#9733;', 5 - (int) round($average)) ?></span>
                </span>
                <strong><?= e((string) $average) ?></strong>
                from <?= (int) $count ?> featured review<?= $count === 1 ? '' : 's' ?>
            </p>
        <?php endif; ?>

        <ul class="review-wall-list">
            <?php foreach ($reviews as $review): ?>
                <li class="review-wall-item">
                    <span class="rating" aria-label="<?= (int) $review['rating'] ?> out of 5 stars">
                        <?= str_repeat('&#9733;', (int) $review['rating']) ?><?php
                        ?><span class="rating-off"><?= str_repeat('&#9733;', 5 - (int) $review['rating']) ?></span>
                    </span>

                    <blockquote><p><?= nl2br(e((string) ($review['body'] ?? ''))) ?></p></blockquote>

                    <footer>
                        <strong><?= e((string) ($review['author_name'] ?? 'A guest')) ?></strong>
                        <span class="muted">
                            <?= e(App\Services\Reputation::platformName((string) $review['platform'])) ?>
                            <?php if (($review['posted_at'] ?? null) !== null): ?>
                                &middot; <?= e(date('F Y', (int) strtotime((string) $review['posted_at']))) ?>
                            <?php endif; ?>
                        </span>
                    </footer>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
