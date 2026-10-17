<?php

declare(strict_types=1);

/**
 * @var list<array<string,mixed>> $rows
 * @var int                       $total
 * @var int                       $page
 * @var int                       $lastPage
 * @var array<string,string>      $filters
 * @var bool                      $canManage
 */
?>
<div class="page-actions">
    <p class="page-lede">
        <?= number_format($total) ?> household<?= $total === 1 ? '' : 's' ?>.
        A household is the unit the venue sells to: one address, one parent paying, several
        children with birthdays.
    </p>
    <div class="row-actions">
        <a class="btn btn-ghost" href="<?= e(url('/contacts')) ?>"><?= icon('users', 16) ?> Contacts</a>
        <?php if ($canManage): ?>
            <a class="btn" href="<?= e(url('/households/new')) ?>"><?= icon('plus', 16) ?> Add a household</a>
        <?php endif; ?>
    </div>
</div>

<form method="get" action="<?= e(url('/households')) ?>" class="card filter-bar">
    <div class="field">
        <label for="q">Search</label>
        <input type="search" id="q" name="q" value="<?= e($filters['q']) ?>"
               placeholder="Name, town or ZIP">
    </div>
    <div class="field field-actions">
        <button type="submit" class="btn btn-sm">Search</button>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('/households')) ?>">Clear</a>
    </div>
</form>

<section class="card">
    <?php if ($rows === []): ?>
        <p class="empty">
            <?= $filters['q'] !== ''
                ? 'No household matches that.'
                : 'No households yet.' ?>
        </p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <caption class="sr-only">Households</caption>
                <thead>
                <tr>
                    <th scope="col">Household</th>
                    <th scope="col">Where</th>
                    <th scope="col">Main contact</th>
                    <th scope="col">Members</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php $primary = trim(((string) $row['primary_first']) . ' ' . ((string) $row['primary_last'])); ?>
                    <tr>
                        <td>
                            <a href="<?= e(url('/households/' . (int) $row['id'])) ?>">
                                <strong><?= e($row['name']) ?></strong>
                            </a>
                        </td>
                        <td>
                            <?php
                            $where = array_filter([
                                (string) ($row['city'] ?? ''),
                                (string) ($row['state'] ?? ''),
                                (string) ($row['postal_code'] ?? ''),
                            ], static fn (string $part): bool => trim($part) !== '');
                            ?>
                            <?= $where === [] ? '<span class="muted">&mdash;</span>' : e(implode(', ', $where)) ?>
                        </td>
                        <td><?= $primary === '' ? '<span class="muted">Not set</span>' : e($primary) ?></td>
                        <td><?= (int) $row['member_count'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($lastPage > 1): ?>
            <nav class="pagination" aria-label="Pages">
                <?php
                $link = static fn (int $target): string => e(url(
                    '/households?' . http_build_query(array_merge($filters, ['page' => $target]))
                ));
                ?>
                <?php if ($page > 1): ?>
                    <a class="btn btn-ghost btn-sm" href="<?= $link($page - 1) ?>">Previous</a>
                <?php endif; ?>
                <span class="pagination-info">Page <?= (int) $page ?> of <?= (int) $lastPage ?></span>
                <?php if ($page < $lastPage): ?>
                    <a class="btn btn-ghost btn-sm" href="<?= $link($page + 1) ?>">Next</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
