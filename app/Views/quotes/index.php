<?php

declare(strict_types=1);

/**
 * @var list<array<string,mixed>> $quotes
 * @var int                       $total
 * @var int                       $page
 * @var int                       $lastPage
 * @var string                    $status
 * @var list<string>              $statuses
 * @var callable(?string):string  $calendarDate
 */

/** Status to badge class. A declined or expired quote is not an error state. */
$badge = static function (string $value): string {
    return match ($value) {
        'accepted'         => 'badge-ok',
        'sent', 'viewed'   => 'badge-warn',
        'declined'         => 'badge-bad',
        default            => 'badge-muted',
    };
};
?>
<div class="page-actions">
    <p class="page-lede">
        A quote is the venue's offer in writing. <?= number_format($total) ?>
        quote<?= $total === 1 ? '' : 's' ?><?= $status === '' ? '' : ' that are ' . e($status) ?>.
        Figures are worked out from the lines, so a quote can never disagree with itself.
    </p>
    <?php if (can('deals_manage')): ?>
        <a class="btn" href="<?= e(url('/quotes/new')) ?>"><?= icon('plus', 16) ?> New quote</a>
    <?php endif; ?>
</div>

<form method="get" action="<?= e(url('/quotes')) ?>" class="card filter-bar">
    <div class="field">
        <label for="status">Status</label>
        <select id="status" name="status">
            <option value="">Any</option>
            <?php foreach ($statuses as $option): ?>
                <option value="<?= e($option) ?>" <?= $status === $option ? 'selected' : '' ?>>
                    <?= e(ucfirst($option)) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field field-actions">
        <button type="submit" class="btn btn-sm">Filter</button>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('/quotes')) ?>">Clear</a>
    </div>
</form>

<section class="card">
    <?php if ($quotes === []): ?>
        <p class="empty">
            <?= $status === ''
                ? 'No quotes yet. Build one against a deal and the totals follow from the lines.'
                : 'No quotes are ' . e($status) . '.' ?>
        </p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <caption class="sr-only">Quotes</caption>
                <thead>
                    <tr>
                        <th scope="col">Number</th>
                        <th scope="col">For</th>
                        <th scope="col">Lines</th>
                        <?php /*
                         * Money is NOT gated behind view_revenue here, unlike
                         * guest spend elsewhere. A Party Host holds view_deals
                         * and quotes_send but not view_revenue, and they are
                         * the person who builds and sends these — a quote with
                         * its total hidden from the person sending it would be
                         * unusable. The figure is the venue's own offer, not a
                         * guest's spending history.
                         */ ?>
                        <th scope="col">Total</th>
                        <th scope="col">Status</th>
                        <th scope="col">Expires</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($quotes as $quote): ?>
                    <?php
                    $guest = trim(((string) ($quote['first_name'] ?? '')) . ' ' . ((string) ($quote['last_name'] ?? '')));
                    $expired = $quote['valid_until'] !== null
                        && (string) $quote['valid_until'] < gmdate('Y-m-d');
                    ?>
                    <tr>
                        <td>
                            <strong><?= e($quote['number']) ?></strong>
                            <span class="td-sub">Raised <?= e(local_date((string) $quote['created_at'])) ?></span>
                        </td>
                        <td>
                            <?php if ($quote['deal_title'] !== null): ?>
                                <?= e($quote['deal_title']) ?>
                                <?php if ($guest !== ''): ?>
                                    <span class="td-sub"><?= e($guest) ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="muted">No deal attached</span>
                            <?php endif; ?>
                        </td>
                        <td><?= (int) $quote['line_count'] ?></td>
                        <td><?= e(money((int) $quote['total_cents'])) ?></td>
                        <td>
                            <span class="badge <?= e($badge((string) $quote['status'])) ?>">
                                <?= e(ucfirst((string) $quote['status'])) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($quote['valid_until'] === null): ?>
                                <span class="muted">No expiry</span>
                            <?php else: ?>
                                <?= e($calendarDate((string) $quote['valid_until'])) ?>
                                <?php if ($expired && (string) $quote['status'] !== 'accepted'): ?>
                                    <span class="td-sub">Past its expiry date</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="td-right">
                            <a class="btn btn-ghost btn-sm" href="<?= e(url('/quotes/' . (int) $quote['id'])) ?>">
                                Open
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($lastPage > 1): ?>
        <nav class="pagination" aria-label="Pages">
            <?php $query = static fn (int $target): string => '/quotes?' . http_build_query(
                array_filter(['status' => $status, 'page' => $target], static fn ($v): bool => (string) $v !== '')
            ); ?>
            <?php if ($page > 1): ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(url($query($page - 1))) ?>">Previous</a>
            <?php endif; ?>
            <span class="pagination-info">Page <?= (int) $page ?> of <?= (int) $lastPage ?></span>
            <?php if ($page < $lastPage): ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(url($query($page + 1))) ?>">Next</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</section>
