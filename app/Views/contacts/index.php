<?php

declare(strict_types=1);

/**
 * The guest list.
 *
 * @var list<array<string,mixed>> $rows
 * @var int                       $total
 * @var int                       $page
 * @var int                       $lastPage
 * @var list<string>              $stages
 * @var list<array<string,mixed>> $owners
 * @var array<string,string>      $filters
 * @var bool                      $canManage
 * @var bool                      $showMoney
 */

$stageLabels = [
    'lead'     => 'Lead',
    'prospect' => 'Prospect',
    'customer' => 'Customer',
    'member'   => 'Member',
    'lapsed'   => 'Lapsed',
    'advocate' => 'Advocate',
];
?>
<div class="page-actions">
    <p class="page-lede">
        <?= number_format($total) ?> contact<?= $total === 1 ? '' : 's' ?>.
        Search by name, email address or phone number — a phone search matches however the
        number was typed.
    </p>
    <div class="row-actions">
        <a class="btn btn-ghost" href="<?= e(url('/households')) ?>"><?= icon('users', 16) ?> Households</a>
        <?php if ($canManage): ?>
            <a class="btn" href="<?= e(url('/contacts/new')) ?>"><?= icon('plus', 16) ?> Add a contact</a>
        <?php endif; ?>
    </div>
</div>

<form method="get" action="<?= e(url('/contacts')) ?>" class="card filter-bar">
    <div class="field">
        <label for="q">Search</label>
        <input type="search" id="q" name="q" value="<?= e($filters['q']) ?>"
               placeholder="Name, email or phone">
    </div>

    <div class="field">
        <label for="lifecycle_stage">Stage</label>
        <select id="lifecycle_stage" name="lifecycle_stage">
            <option value="">Any stage</option>
            <?php foreach ($stages as $stage): ?>
                <option value="<?= e($stage) ?>" <?= $filters['lifecycle_stage'] === $stage ? 'selected' : '' ?>>
                    <?= e($stageLabels[$stage] ?? ucfirst($stage)) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="field">
        <label for="owner_id">Owner</label>
        <select id="owner_id" name="owner_id">
            <option value="">Anyone</option>
            <option value="none" <?= $filters['owner_id'] === 'none' ? 'selected' : '' ?>>Nobody</option>
            <?php foreach ($owners as $owner): ?>
                <option value="<?= (int) $owner['id'] ?>"
                    <?= $filters['owner_id'] === (string) $owner['id'] ? 'selected' : '' ?>>
                    <?= e($owner['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="field field-actions">
        <button type="submit" class="btn btn-sm">Search</button>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('/contacts')) ?>">Clear</a>
    </div>
</form>

<section class="card">
    <?php if ($rows === []): ?>
        <p class="empty">
            <?php if ($filters['q'] !== '' || $filters['lifecycle_stage'] !== '' || $filters['owner_id'] !== ''): ?>
                No contact matches that.
            <?php else: ?>
                No contacts yet.
                <?= $canManage ? 'Add one, or import a guest list from the POS once that is connected.' : '' ?>
            <?php endif; ?>
        </p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <caption class="sr-only">Contacts</caption>
                <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">How to reach them</th>
                    <th scope="col">Stage</th>
                    <th scope="col">Household</th>
                    <th scope="col">Last visit</th>
                    <?php if ($showMoney): ?><th scope="col">Lifetime value</th><?php endif; ?>
                    <th scope="col">Marketing</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $name = trim(((string) $row['first_name']) . ' ' . ((string) $row['last_name']));
                    $stage = (string) $row['lifecycle_stage'];
                    ?>
                    <tr>
                        <td>
                            <a href="<?= e(url('/contacts/' . (int) $row['id'])) ?>">
                                <strong><?= $name === '' ? 'Contact #' . (int) $row['id'] : e($name) ?></strong>
                            </a>
                        </td>
                        <td>
                            <?php if (!empty($row['email'])): ?>
                                <span><?= e($row['email']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($row['phone_normalized'])): ?>
                                <span class="td-sub"><?= e(format_phone((string) $row['phone_normalized'])) ?></span>
                            <?php elseif (empty($row['email'])): ?>
                                <span class="muted">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge"><?= e($stageLabels[$stage] ?? ucfirst($stage)) ?></span></td>
                        <td>
                            <?php if (!empty($row['household_name'])): ?>
                                <?= e($row['household_name']) ?>
                            <?php else: ?>
                                <span class="muted">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($row['last_visit_at'])): ?>
                                <?= e(local_date((string) $row['last_visit_at'])) ?>
                                <span class="td-sub"><?= (int) $row['visit_count'] ?> visit<?= (int) $row['visit_count'] === 1 ? '' : 's' ?></span>
                            <?php else: ?>
                                <span class="muted">No visit recorded</span>
                            <?php endif; ?>
                        </td>
                        <?php if ($showMoney): ?>
                            <td><?= e(money((int) $row['lifetime_value_cents'])) ?></td>
                        <?php endif; ?>
                        <td>
                            <?php if ((int) $row['do_not_contact'] === 1): ?>
                                <span class="badge badge-bad">Do not contact</span>
                            <?php else: ?>
                                <?php if ((int) $row['email_consent'] === 1): ?>
                                    <span class="badge badge-ok">Email</span>
                                <?php endif; ?>
                                <?php if ((int) $row['sms_consent'] === 1): ?>
                                    <span class="badge badge-ok">SMS</span>
                                <?php endif; ?>
                                <?php if ((int) $row['email_consent'] === 0 && (int) $row['sms_consent'] === 0): ?>
                                    <span class="badge badge-muted">No consent</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($lastPage > 1): ?>
            <nav class="pagination" aria-label="Pages">
                <?php
                $link = static fn (int $target): string => e(url(
                    '/contacts?' . http_build_query(array_merge($filters, ['page' => $target]))
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
