<?php

declare(strict_types=1);

/**
 * Every automation, and the stop button.
 *
 * @var list<array<string,mixed>> $automations
 * @var list<array<string,mixed>> $playbooks
 * @var array<string,mixed>       $filters
 * @var bool                      $paused
 * @var bool                      $safeMode
 * @var array<string,int>         $counts
 */
?>

<?php if ($paused): ?>
    <div class="alert alert-error">
        <?= icon('pause') ?>
        <div>
            <p><strong>Automations are stopped.</strong> Nothing is being enrolled and nothing in
                flight is advancing. Runs are held where they are, so starting them again continues
                from where each guest got to.</p>
            <?php if (can('automations_activate')): ?>
                <form method="post" action="<?= e(url('/automations/resume')) ?>" class="form-inline">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn">Start automations again</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<section class="card">
    <div class="card-head">
        <div>
            <h2>Automations</h2>
            <p class="card-sub">
                <?= (int) $counts['on'] ?> of <?= (int) $counts['total'] ?> switched on<?php
                ?><?= $counts['queued'] > 0
                    ? ', ' . (int) $counts['queued'] . ' ' . ($counts['queued'] === 1 ? 'guest' : 'guests') . ' part way through'
                    : '' ?>.
                <?php if ($safeMode): ?>
                    Safe Mode is on, so every message is redirected to the owner.
                <?php endif; ?>
            </p>
        </div>

        <?php if (can('automations_activate') && !$paused): ?>
            <form method="post" action="<?= e(url('/automations/pause')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-danger">
                    <?= icon('pause') ?> Stop all automations
                </button>
            </form>
        <?php endif; ?>
    </div>

    <form method="get" action="<?= e(url('/automations')) ?>" class="filter-bar">
        <div class="field">
            <label for="q">Search</label>
            <input type="search" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="Name or description">
        </div>

        <div class="field">
            <label for="state">State</label>
            <select id="state" name="state">
                <option value="">All</option>
                <option value="on" <?= $filters['state'] === 'on' ? 'selected' : '' ?>>Switched on</option>
                <option value="off" <?= $filters['state'] === 'off' ? 'selected' : '' ?>>Off</option>
                <option value="waiting" <?= $filters['state'] === 'waiting' ? 'selected' : '' ?>>
                    Waiting on a feature
                </option>
            </select>
        </div>

        <div class="field">
            <label for="playbook">Playbook</label>
            <select id="playbook" name="playbook">
                <option value="0">All</option>
                <?php foreach ($playbooks as $playbook): ?>
                    <option value="<?= (int) $playbook['id'] ?>"
                        <?= (int) $filters['playbook'] === (int) $playbook['id'] ? 'selected' : '' ?>>
                        <?= e($playbook['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit" class="btn btn-ghost">Filter</button>
    </form>

    <?php if ($automations === []): ?>
        <p class="empty">Nothing matches that.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Automation</th>
                        <th scope="col">Starts when</th>
                        <th scope="col">Steps</th>
                        <th scope="col">Runs</th>
                        <th scope="col">State</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($automations as $automation): ?>
                    <tr>
                        <td>
                            <a href="<?= e(url('/automations/' . (int) $automation['id'])) ?>">
                                <strong><?= e($automation['name']) ?></strong>
                            </a>
                            <span class="td-sub">
                                <?= e(str_truncate((string) $automation['description'], 90)) ?>
                            </span>
                            <?php if ($automation['playbook_name'] !== null): ?>
                                <span class="td-sub">
                                    <?= e($automation['playbook_name']) ?><?php
                                    ?><?= (int) $automation['playbook_active'] === 1 ? '' : ' (playbook off)' ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= e($automation['trigger_label']) ?>
                            <?php if ($automation['pending'] !== null): ?>
                                <span class="td-sub">Waiting on <?= e($automation['pending']) ?></span>
                            <?php elseif ($automation['unknown']): ?>
                                <span class="td-sub">Unknown trigger — nothing would start it</span>
                            <?php endif; ?>
                        </td>
                        <td><?= (int) $automation['step_count'] ?></td>
                        <td>
                            <?= number_format((int) $automation['run_count']) ?>
                            <?php if ((int) $automation['in_flight'] > 0): ?>
                                <span class="td-sub"><?= (int) $automation['in_flight'] ?> in flight</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int) $automation['is_active'] === 1): ?>
                                <span class="badge badge-ok">On</span>
                            <?php elseif ($automation['pending'] !== null || $automation['unknown']): ?>
                                <span class="badge badge-warn">Waiting</span>
                            <?php elseif ((int) $automation['step_count'] === 0): ?>
                                <span class="badge badge-muted">No steps</span>
                            <?php else: ?>
                                <span class="badge badge-muted">Off</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
