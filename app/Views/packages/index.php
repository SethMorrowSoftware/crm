<?php

declare(strict_types=1);

/**
 * Party packages and add-ons: what the venue sells, and for how much.
 *
 * @var list<array<string,mixed>>           $packages
 * @var list<array<string,mixed>>           $addons
 * @var list<array{id:int,name:string}>     $attractions
 * @var list<string>                        $units
 */

$unitLabel = static fn (string $unit): string => match ($unit) {
    'per_guest' => 'per guest',
    'per_hour'  => 'per hour',
    default     => 'flat',
};
?>
<div class="page-actions">
    <p class="page-lede">
        What a family can book, and for how much. The quote calculator and the online booking page
        read these prices directly, so a change here applies to the next quote.
    </p>
    <a class="btn" href="<?= e(url('/settings/packages/new')) ?>"><?= icon('plus', 16) ?> New package</a>
</div>

<section class="card">
    <div class="card-head">
        <div>
            <h2>Party packages</h2>
            <p class="card-sub">
                "Online" means a family can book it on the website. The rest are booked by phone or at
                the desk — they still appear on the staff booking form.
            </p>
        </div>
    </div>

    <?php if ($packages === []): ?>
        <p class="empty">No packages yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <caption class="sr-only">Party packages</caption>
                <thead>
                    <tr>
                        <th scope="col">Package</th>
                        <th scope="col" class="td-right">Price</th>
                        <th scope="col" class="td-right">Each extra</th>
                        <th scope="col" class="td-right">Deposit</th>
                        <th scope="col">Sold</th>
                        <th scope="col" class="td-right">Booked, 12 mo</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($packages as $package): ?>
                        <?php $active = (int) $package['is_active'] === 1; ?>
                        <tr class="<?= $active ? '' : 'row-muted' ?>">
                            <td>
                                <strong><?= e((string) $package['name']) ?></strong>
                                <span class="td-sub">
                                    <?= (int) $package['included_guests'] ?> guests included
                                    · <?= (int) $package['duration_minutes'] ?> min
                                    <?php if (($package['attraction_name'] ?? null) !== null): ?>
                                        · <?= e((string) $package['attraction_name']) ?>
                                    <?php endif; ?>
                                </span>
                            </td>
                            <td class="td-right"><?= e(money((int) $package['base_price_cents'])) ?></td>
                            <td class="td-right"><?= e(money((int) $package['additional_guest_cents'])) ?></td>
                            <td class="td-right">
                                <?= (int) $package['deposit_cents'] > 0
                                    ? e(money((int) $package['deposit_cents']))
                                    : '<span class="muted">none</span>' ?>
                            </td>
                            <td>
                                <?php if (!$active): ?>
                                    <span class="badge badge-muted">Off sale</span>
                                <?php elseif ((int) $package['is_online'] === 1): ?>
                                    <span class="badge badge-ok">Online &amp; desk</span>
                                <?php else: ?>
                                    <span class="badge">Desk only</span>
                                <?php endif; ?>
                                <?php if ((int) $package['is_seasonal'] === 1): ?>
                                    <span class="badge badge-warn">Seasonal</span>
                                <?php endif; ?>
                            </td>
                            <td class="td-right"><?= (int) $package['bookings_year'] ?></td>
                            <td class="row-actions">
                                <a class="btn btn-ghost btn-sm" href="<?= e(url('/settings/packages/' . (int) $package['id'])) ?>">
                                    Edit<span class="sr-only"> <?= e((string) $package['name']) ?></span>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card-head">
        <div>
            <h2>Add-ons</h2>
            <p class="card-sub">Extras a booking can carry. "Per guest" multiplies by the party's head count.</p>
        </div>
    </div>

    <?php if ($addons === []): ?>
        <p class="empty">No add-ons yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <caption class="sr-only">Add-ons</caption>
                <thead>
                    <tr>
                        <th scope="col">Add-on</th>
                        <th scope="col" class="td-right">Price</th>
                        <th scope="col">Charged</th>
                        <th scope="col" class="td-right">On bookings</th>
                        <th scope="col">State</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($addons as $addon): ?>
                        <?php $active = (int) $addon['is_active'] === 1; ?>
                        <tr class="<?= $active ? '' : 'row-muted' ?>">
                            <td>
                                <strong><?= e((string) $addon['name']) ?></strong>
                                <?php if (($addon['attraction_name'] ?? null) !== null): ?>
                                    <span class="td-sub"><?= e((string) $addon['attraction_name']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="td-right"><?= e(money((int) $addon['price_cents'])) ?></td>
                            <td><?= e($unitLabel((string) $addon['unit'])) ?></td>
                            <td class="td-right"><?= (int) $addon['used'] ?></td>
                            <td>
                                <span class="badge <?= $active ? 'badge-ok' : 'badge-muted' ?>">
                                    <?= $active ? 'Offered' : 'Retired' ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="5">
                                <details>
                                    <summary>Edit <?= e((string) $addon['name']) ?></summary>
                                    <?php
                                    $a = $addon;
                                    $action = url('/settings/packages/addons/' . (int) $addon['id']);
                                    include __DIR__ . '/_addon_form.php';
                                    ?>
                                    <?php if ((int) $addon['used'] === 0): ?>
                                        <form method="post" class="inline-form"
                                              action="<?= e(url('/settings/packages/addons/' . (int) $addon['id'] . '/delete')) ?>"
                                              data-confirm="Delete <?= e((string) $addon['name']) ?>? This cannot be undone.">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-danger-text btn-sm">Delete this add-on</button>
                                        </form>
                                    <?php endif; ?>
                                </details>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card-head"><div><h2>Add an add-on</h2></div></div>
    <?php
    $a = [];
    $action = url('/settings/packages/addons');
    include __DIR__ . '/_addon_form.php';
    ?>
</section>
