<?php

declare(strict_types=1);

/**
 * Every offer, what it is worth, and how it has done.
 *
 * @var list<array<string,mixed>>                     $coupons
 * @var array<string,array{all:int,live:int}>         $uses
 * @var array<string,string>                          $types
 */

$worth = static function (array $c): string {
    return match ((string) $c['type']) {
        'percent'   => rtrim(rtrim(number_format((float) $c['value'], 2), '0'), '.') . '% off',
        'fixed'     => money((int) round((float) $c['value'] * 100)) . ' off',
        'free_item' => 'A free item',
        default     => 'Buy one, get one',
    };
};
?>
<p class="page-lede">
    The codes automations hand out and the desk redeems. A code never changes once it exists — a guest may
    be holding it in a text.
</p>

<section class="card">
    <div class="card-head"><div><h2>Offers</h2></div></div>

    <?php if ($coupons === []): ?>
        <p class="empty">No offers yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <caption class="sr-only">Offers</caption>
                <thead>
                    <tr>
                        <th scope="col">Code</th>
                        <th scope="col">Worth</th>
                        <th scope="col">Good until</th>
                        <th scope="col" class="td-right">Handed out</th>
                        <th scope="col" class="td-right">Redeemed</th>
                        <th scope="col">State</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($coupons as $c): ?>
                        <?php
                        $active  = (int) $c['is_active'] === 1;
                        $expired = $c['expires_at'] !== null && (string) $c['expires_at'] < now_utc();
                        $use     = $uses[(string) $c['code']] ?? ['all' => 0, 'live' => 0];
                        ?>
                        <tr class="<?= $active && !$expired ? '' : 'row-muted' ?>">
                            <td>
                                <strong><code><?= e((string) $c['code']) ?></code></strong>
                                <span class="td-sub"><?= e((string) $c['name']) ?></span>
                            </td>
                            <td><?= e($worth($c)) ?></td>
                            <td><?= $c['expires_at'] === null ? '<span class="muted">No end date</span>' : e(local_date((string) $c['expires_at'])) ?></td>
                            <td class="td-right">
                                <?= (int) $c['issued'] ?>
                                <?php if ($use['live'] > 0): ?>
                                    <span class="td-sub"><?= (int) $use['live'] ?> automation<?= $use['live'] === 1 ? '' : 's' ?> on</span>
                                <?php endif; ?>
                            </td>
                            <td class="td-right"><?= (int) $c['redeemed'] ?></td>
                            <td>
                                <?php if (!$active): ?>
                                    <span class="badge badge-muted">Off</span>
                                <?php elseif ($expired): ?>
                                    <span class="badge badge-warn">Expired</span>
                                <?php else: ?>
                                    <span class="badge badge-ok">Active</span>
                                <?php endif; ?>
                            </td>
                            <td class="row-actions">
                                <a class="btn btn-ghost btn-sm" href="<?= e(url('/settings/offers/' . (int) $c['id'])) ?>">
                                    Edit<span class="sr-only"> <?= e((string) $c['code']) ?></span>
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
    <div class="card-head"><div><h2>Add an offer</h2></div></div>
    <form method="post" action="<?= e(url('/settings/offers')) ?>" class="form">
        <?= csrf_field() ?>
        <div class="field">
            <label for="code-new">Code</label>
            <input type="text" id="code-new" name="code" maxlength="40" required placeholder="FALL20"
                   pattern="[A-Za-z0-9][A-Za-z0-9\-]{2,39}" autocapitalize="characters">
            <p class="field-hint">Letters, digits and dashes. Saved in capitals, and fixed once created.</p>
        </div>
        <?php $c = []; $f = 'new'; include __DIR__ . '/_fields.php'; ?>
        <div class="form-actions">
            <button type="submit" class="btn">Add the offer</button>
        </div>
    </form>
</section>
