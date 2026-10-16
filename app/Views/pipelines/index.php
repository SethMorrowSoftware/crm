<?php

declare(strict_types=1);

/**
 * Every pipeline, with how much is sitting in it.
 *
 * @var list<array<string,mixed>> $pipelines
 * @var array<string,string>      $types
 */

$canManage = can('deals_manage');
?>
<div class="page-actions">
    <p class="page-lede">
        A pipeline is a named list of stages. The stages are the board's columns, and each one
        carries its own response deadline and says whether reaching it counts as won or lost.
    </p>
    <?php if ($canManage): ?>
        <a class="btn" href="<?= e(url('/pipelines/new')) ?>"><?= icon('plus', 16) ?> New pipeline</a>
    <?php endif; ?>
</div>

<section class="card">
    <?php if ($pipelines === []): ?>
        <p class="empty">No pipelines yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Pipeline</th>
                        <th scope="col">Kind</th>
                        <th scope="col">Stages</th>
                        <th scope="col">Deals</th>
                        <th scope="col">Open value</th>
                        <th scope="col">State</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pipelines as $pipeline): ?>
                    <?php $id = (int) $pipeline['id']; ?>
                    <tr<?= (int) $pipeline['is_active'] === 0 ? ' class="row-muted"' : '' ?>>
                        <td>
                            <a href="<?= e(url('/deals?pipeline=' . $id)) ?>">
                                <strong><?= e($pipeline['name']) ?></strong>
                            </a>
                            <span class="td-sub"><?= e($pipeline['slug']) ?></span>
                        </td>
                        <td><?= e($types[(string) $pipeline['type']] ?? ucfirst((string) $pipeline['type'])) ?></td>
                        <td>
                            <?= number_format((int) $pipeline['stage_count']) ?>
                            <?php if ((int) $pipeline['stage_count'] === 0): ?>
                                <span class="td-sub">Cannot hold deals yet</span>
                            <?php endif; ?>
                        </td>
                        <td><?= number_format((int) $pipeline['deal_count']) ?></td>
                        <td><?= e(money((int) $pipeline['open_value_cents'])) ?></td>
                        <td>
                            <?php if ((int) $pipeline['is_active'] === 1): ?>
                                <span class="badge badge-ok">Active</span>
                            <?php else: ?>
                                <span class="badge badge-muted">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td class="td-right">
                            <?php if ($canManage): ?>
                                <a class="btn btn-ghost btn-sm" href="<?= e(url('/pipelines/' . $id)) ?>">Edit</a>
                            <?php else: ?>
                                <a class="btn btn-ghost btn-sm" href="<?= e(url('/deals?pipeline=' . $id)) ?>">Board</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Why there is no delete</h2>
    <p>
        Deleting a pipeline would take every deal in it, and every stage those deals have passed
        through, with it. Switching one to inactive takes it off the board and out of the new-deal
        form while the record stays intact — which is what &ldquo;we don't run that any more&rdquo;
        usually means.
    </p>
</section>
