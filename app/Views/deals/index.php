<?php

declare(strict_types=1);

/**
 * The pipeline board and the same deals as a table.
 *
 * One file renders both, on purpose: the two views share a header, a filter
 * bar and a totals row, and anything that drifts between them is a way for the
 * board and the table to disagree about what is in the pipeline.
 *
 * The board's only styling that is not already in assets/css/app.css is the
 * column grid and each stage's colour stripe — both geometry, declared inline
 * here rather than by editing a shared stylesheet this module does not own.
 *
 * @var string                                  $mode      'board' or 'table'
 * @var list<array<string,mixed>>               $pipelines
 * @var array<string,mixed>|null                $pipeline
 * @var list<array<string,mixed>>               $stages
 * @var array<int,list<array<string,mixed>>>    $cards     stage id => deals
 * @var list<array<string,mixed>>               $deals     table rows
 * @var array<int,array{count:int,value_cents:int}> $totals stage id => totals
 * @var array<int,int>                          $overflow  stage id => not shown
 * @var array{count:int,value_cents:int}        $summary
 * @var array{status:string,stage:int,owner:int,q:string} $filters
 * @var list<array<string,mixed>>               $owners
 * @var int                                     $page
 * @var int                                     $perPage
 * @var int                                     $lastPage
 */

$pipelineId = $pipeline === null ? 0 : (int) $pipeline['id'];
$canManage  = can('deals_manage');

/** A link back to one of these views carrying the current filters. */
$link = static function (string $path, array $extra = []) use ($pipelineId, $filters): string {
    $query = array_filter([
        'pipeline' => $pipelineId > 0 ? (string) $pipelineId : '',
        'status'   => $filters['status'],
        'stage'    => $filters['stage'] > 0 ? (string) $filters['stage'] : '',
        'owner'    => $filters['owner'] > 0 ? (string) $filters['owner'] : '',
        'q'        => $filters['q'],
    ], static fn (string $value): bool => $value !== '');

    foreach ($extra as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
            continue;
        }

        $query[$key] = (string) $value;
    }

    return url($path) . ($query === [] ? '' : '?' . http_build_query($query));
};

/**
 * A plain DATE column, printed as written.
 *
 * Deliberately not local_time(): that converts a UTC timestamp into the venue
 * zone, and an expected close date of 2026-11-02 stored as a DATE would come
 * back as November 1st in Eastern time. A calendar date has no timezone.
 */
$dateText = static function (mixed $date): string {
    $value = trim((string) ($date ?? ''));

    if ($value === '' || str_starts_with($value, '0000')) {
        return '';
    }

    $time = strtotime($value . ' 00:00:00 UTC');

    return $time === false ? '' : gmdate('M j, Y', $time);
};

$guestName = static function (array $deal): string {
    $name = trim((string) ($deal['first_name'] ?? '') . ' ' . (string) ($deal['last_name'] ?? ''));

    return $name === '' ? '' : $name;
};
?>

<div class="page-actions">
    <p class="page-lede">
        Every inquiry, where it has got to, and what it is worth. The board shows the shape of the
        month; the table is the one to use on a phone, with a screen reader, or when you want to
        read values down a column.
    </p>
    <?php if ($canManage): ?>
        <a class="btn" href="<?= e(url('/deals/new' . ($pipelineId > 0 ? '?pipeline=' . $pipelineId : ''))) ?>">
            <?= icon('plus', 16) ?> New deal
        </a>
    <?php endif; ?>
</div>

<?php if ($pipelines === []): ?>
    <section class="card">
        <h2>No pipelines yet</h2>
        <p>
            A deal lives in a stage, and a stage belongs to a pipeline, so there is nothing to show
            until at least one pipeline exists. The installer normally seeds four.
        </p>
        <?php if ($canManage): ?>
            <p><a class="btn" href="<?= e(url('/pipelines/new')) ?>"><?= icon('plus', 16) ?> Build a pipeline</a></p>
        <?php endif; ?>
    </section>
<?php else: ?>

    <form method="get" action="<?= e(url($mode === 'board' ? '/deals' : '/deals/table')) ?>" class="card">
        <div class="filter-bar">
            <div class="field">
                <label for="pipeline">Pipeline</label>
                <select id="pipeline" name="pipeline">
                    <?php foreach ($pipelines as $option): ?>
                        <option value="<?= (int) $option['id'] ?>"
                            <?= (int) $option['id'] === $pipelineId ? 'selected' : '' ?>>
                            <?= e($option['name']) ?><?= (int) $option['is_active'] === 0 ? ' (inactive)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="">Any</option>
                    <?php foreach (['open' => 'Open', 'won' => 'Won', 'lost' => 'Lost', 'abandoned' => 'Abandoned'] as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="stage">Stage</label>
                <select id="stage" name="stage">
                    <option value="">Every stage</option>
                    <?php foreach ($stages as $stage): ?>
                        <option value="<?= (int) $stage['id'] ?>"
                            <?= $filters['stage'] === (int) $stage['id'] ? 'selected' : '' ?>>
                            <?= e($stage['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="owner">Owner</label>
                <select id="owner" name="owner">
                    <option value="">Anyone</option>
                    <?php foreach ($owners as $owner): ?>
                        <option value="<?= (int) $owner['id'] ?>"
                            <?= $filters['owner'] === (int) $owner['id'] ? 'selected' : '' ?>>
                            <?= e($owner['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="q">Search</label>
                <input type="search" id="q" name="q" value="<?= e($filters['q']) ?>"
                       placeholder="Deal or guest name">
            </div>

            <div class="field-actions">
                <button type="submit" class="btn btn-sm"><?= icon('search', 15) ?> Apply</button>
                <a class="btn btn-ghost btn-sm" href="<?= e(url($mode === 'board' ? '/deals' : '/deals/table')
                    . '?pipeline=' . $pipelineId) ?>">Clear</a>
            </div>
        </div>
    </form>

    <div class="stat-row">
        <div class="stat">
            <span class="stat-label">Deals in view</span>
            <span class="stat-value"><?= number_format($summary['count']) ?></span>
            <span class="stat-note"><?= e($pipeline['name'] ?? '') ?></span>
        </div>
        <div class="stat">
            <span class="stat-label">Value in view</span>
            <span class="stat-value"><?= e(money_short((int) $summary['value_cents'])) ?></span>
            <span class="stat-note"><?= e(money((int) $summary['value_cents'])) ?> across every stage shown</span>
        </div>
        <a class="stat" href="<?= e($link($mode === 'board' ? '/deals/table' : '/deals')) ?>">
            <span class="stat-label">Reading this as</span>
            <span class="stat-value"><?= $mode === 'board' ? 'Board' : 'Table' ?></span>
            <span class="stat-note">
                Switch to the <?= $mode === 'board' ? 'table' : 'board' ?> <?= icon('arrow-right', 14) ?>
            </span>
        </a>
        <a class="stat" href="<?= e(url('/pipelines')) ?>">
            <span class="stat-label">Stages</span>
            <span class="stat-value"><?= number_format(count($stages)) ?></span>
            <span class="stat-note">Pipelines &amp; stages <?= icon('arrow-right', 14) ?></span>
        </a>
    </div>

    <?php if ($stages === []): ?>
        <section class="card">
            <h2><?= e($pipeline['name'] ?? 'This pipeline') ?> has no stages</h2>
            <p>Give it at least one stage and it can start holding deals.</p>
            <?php if ($canManage): ?>
                <p>
                    <a class="btn" href="<?= e(url('/pipelines/' . $pipelineId)) ?>">
                        <?= icon('edit', 16) ?> Add its stages
                    </a>
                </p>
            <?php endif; ?>
        </section>

    <?php elseif ($mode === 'board'): ?>

        <!-- The columns: a responsive grid, so a narrow screen stacks them
             rather than forcing a horizontal scroll. -->
        <div style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fill,minmax(15.5rem,1fr));align-items:start">
            <?php foreach ($stages as $stage): ?>
                <?php
                $stageId = (int) $stage['id'];
                $total   = $totals[$stageId] ?? ['count' => 0, 'value_cents' => 0];
                $column  = $cards[$stageId] ?? [];
                $hidden  = $overflow[$stageId] ?? 0;
                $color   = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $stage['color']) === 1
                    ? (string) $stage['color']
                    : '#6b4ea8';
                ?>
                <section class="card" style="border-top:3px solid <?= e($color) ?>">
                    <header>
                        <h2><?= e($stage['name']) ?></h2>
                        <p class="stat-note">
                            <?= number_format($total['count']) ?> deal<?= $total['count'] === 1 ? '' : 's' ?>
                            &middot; <?= e(money((int) $total['value_cents'])) ?>
                            <?php if ((int) $stage['is_won'] === 1): ?>
                                &middot; <span class="badge badge-ok">Won</span>
                            <?php elseif ((int) $stage['is_lost'] === 1): ?>
                                &middot; <span class="badge badge-muted">Lost</span>
                            <?php endif; ?>
                        </p>
                    </header>

                    <?php if ($column === []): ?>
                        <p class="empty">Nothing here.</p>
                    <?php endif; ?>

                    <?php foreach ($column as $deal): ?>
                        <?php $guest = $guestName($deal); ?>
                        <article style="border-top:1px solid var(--line);padding-top:.6rem;margin-top:.6rem">
                            <a href="<?= e(url('/deals/' . (int) $deal['id'])) ?>"><strong><?= e($deal['title']) ?></strong></a>
                            <p class="stat-note">
                                <?= e(money((int) $deal['value_cents'])) ?>
                                <?php if ($guest !== ''): ?>&middot; <?= e($guest) ?><?php endif; ?>
                            </p>
                            <p class="stat-note">
                                <?= $deal['owner_name'] === null
                                    ? '<span class="muted">Unassigned</span>'
                                    : e($deal['owner_name']) ?>
                                <?php if ($dateText($deal['expected_close_date']) !== ''): ?>
                                    &middot; closes <?= e($dateText($deal['expected_close_date'])) ?>
                                <?php endif; ?>
                            </p>

                            <?php if ($canManage && count($stages) > 1): ?>
                                <details>
                                    <summary>Move</summary>
                                    <form method="post" action="<?= e(url('/deals/' . (int) $deal['id'] . '/stage')) ?>"
                                          class="inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="from" value="board">
                                        <select name="stage_id" id="board_stage_<?= (int) $deal['id'] ?>"
                                                aria-label="Move <?= e($deal['title']) ?> to another stage">
                                            <?php foreach ($stages as $target): ?>
                                                <option value="<?= (int) $target['id'] ?>"
                                                    <?= (int) $target['id'] === $stageId ? 'selected' : '' ?>>
                                                    <?= e($target['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-sm">Move</button>
                                    </form>
                                </details>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>

                    <?php if ($hidden > 0): ?>
                        <p class="stat-note">
                            <a href="<?= e($link('/deals/table', ['stage' => $stageId])) ?>">
                                <?= number_format($hidden) ?> more in this stage
                            </a>
                        </p>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        </div>

    <?php else: ?>

        <section class="card">
            <?php if ($deals === []): ?>
                <p class="empty">No deals match those filters.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <caption class="sr-only">Deals in this pipeline</caption>
                        <thead>
                            <tr>
                                <th scope="col">Deal</th>
                                <th scope="col">Stage</th>
                                <th scope="col">Value</th>
                                <th scope="col">Expected close</th>
                                <th scope="col">Owner</th>
                                <th scope="col">Status</th>
                                <?php if ($canManage): ?>
                                    <th scope="col">Move to</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($deals as $deal): ?>
                            <?php $guest = $guestName($deal); ?>
                            <tr>
                                <td>
                                    <a href="<?= e(url('/deals/' . (int) $deal['id'])) ?>">
                                        <strong><?= e($deal['title']) ?></strong>
                                    </a>
                                    <?php if ($guest !== ''): ?>
                                        <span class="td-sub"><?= e($guest) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($deal['stage_name']) ?></td>
                                <td><?= e(money((int) $deal['value_cents'])) ?></td>
                                <td>
                                    <?= $dateText($deal['expected_close_date']) === ''
                                        ? '<span class="muted">—</span>'
                                        : e($dateText($deal['expected_close_date'])) ?>
                                </td>
                                <td>
                                    <?= $deal['owner_name'] === null
                                        ? '<span class="muted">Unassigned</span>'
                                        : e($deal['owner_name']) ?>
                                </td>
                                <td>
                                    <?php $status = (string) $deal['status']; ?>
                                    <span class="badge <?= $status === 'won' ? 'badge-ok' : 'badge-muted' ?>">
                                        <?= e(ucfirst($status)) ?>
                                    </span>
                                </td>
                                <?php if ($canManage): ?>
                                    <td>
                                        <form method="post"
                                              action="<?= e(url('/deals/' . (int) $deal['id'] . '/stage')) ?>"
                                              class="inline-form">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="from" value="table">
                                            <select name="stage_id" id="row_stage_<?= (int) $deal['id'] ?>"
                                                    aria-label="Move <?= e($deal['title']) ?> to another stage">
                                                <?php foreach ($stages as $target): ?>
                                                    <option value="<?= (int) $target['id'] ?>"
                                                        <?= (int) $target['id'] === (int) $deal['stage_id'] ? 'selected' : '' ?>>
                                                        <?= e($target['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn btn-ghost btn-sm">Move</button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($lastPage > 1): ?>
                    <nav class="pagination" aria-label="Pages of deals">
                        <?php if ($page > 1): ?>
                            <a class="btn btn-ghost btn-sm" href="<?= e($link('/deals/table', ['page' => $page - 1])) ?>">
                                Previous
                            </a>
                        <?php endif; ?>
                        <span class="pagination-info">Page <?= (int) $page ?> of <?= (int) $lastPage ?></span>
                        <?php if ($page < $lastPage): ?>
                            <a class="btn btn-ghost btn-sm" href="<?= e($link('/deals/table', ['page' => $page + 1])) ?>">
                                Next
                            </a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </section>

    <?php endif; ?>
<?php endif; ?>
