<?php

declare(strict_types=1);

/**
 * Edit one add-on.
 *
 * @var array<string,mixed>             $addon
 * @var list<array{id:int,name:string}> $attractions
 * @var list<string>                    $units
 * @var int                             $used
 */
?>
<p class="breadcrumbs">
    <a href="<?= e(url('/settings')) ?>">Settings</a> /
    <a href="<?= e(url('/settings/packages')) ?>">Party packages</a> /
    <?= e((string) $addon['name']) ?>
</p>

<section class="card">
    <div class="card-head">
        <div>
            <h2><?= e((string) $addon['name']) ?></h2>
            <p class="card-sub">
                A change applies to the next quote. Bookings already made keep the price they were made at.
            </p>
        </div>
    </div>
    <?php
    $a = $addon;
    $action = url('/settings/packages/addons/' . (int) $addon['id']);
    include __DIR__ . '/_addon_form.php';
    ?>
</section>

<section class="card card-danger">
    <div class="card-head"><div><h2>Delete</h2></div></div>
    <?php if ($used > 0): ?>
        <p>
            <?= e((string) $addon['name']) ?> is on <?= $used ?> booking<?= $used === 1 ? '' : 's' ?>,
            so it cannot be deleted. Untick "Offered on new bookings" above to retire it.
        </p>
    <?php else: ?>
        <form method="post" action="<?= e(url('/settings/packages/addons/' . (int) $addon['id'] . '/delete')) ?>"
              data-confirm="Delete <?= e((string) $addon['name']) ?>? This cannot be undone.">
            <?= csrf_field() ?>
            <p>No booking carries this add-on, so it can be deleted outright.</p>
            <button type="submit" class="btn btn-danger">Delete <?= e((string) $addon['name']) ?></button>
        </form>
    <?php endif; ?>
</section>
