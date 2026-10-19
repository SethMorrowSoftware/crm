<?php

declare(strict_types=1);

/**
 * Edit one offer. The code is shown, never editable.
 *
 * @var array<string,mixed>          $coupon
 * @var array<string,string>         $types
 * @var array{all:int,live:int}      $uses
 */
?>
<p class="breadcrumbs">
    <a href="<?= e(url('/settings')) ?>">Settings</a> /
    <a href="<?= e(url('/settings/offers')) ?>">Offers</a> /
    <?= e((string) $coupon['code']) ?>
</p>

<section class="card">
    <div class="card-head">
        <div>
            <h2><code><?= e((string) $coupon['code']) ?></code></h2>
            <p class="card-sub">
                <?php if ($uses['all'] > 0): ?>
                    Handed out by <?= (int) $uses['all'] ?> automation step<?= $uses['all'] === 1 ? '' : 's' ?><?= $uses['live'] > 0 ? ', ' . (int) $uses['live'] . ' switched on' : '' ?>.
                <?php else: ?>
                    No automation hands this out. The desk can still redeem it.
                <?php endif; ?>
            </p>
        </div>
    </div>

    <form method="post" action="<?= e(url('/settings/offers/' . (int) $coupon['id'])) ?>" class="form">
        <?= csrf_field() ?>
        <?php $c = $coupon; $f = 'edit'; include __DIR__ . '/_fields.php'; ?>
        <div class="form-actions">
            <button type="submit" class="btn">Save</button>
            <a class="btn btn-ghost" href="<?= e(url('/settings/offers')) ?>">Back</a>
        </div>
    </form>
</section>
