<?php

declare(strict_types=1);

/**
 * One automation: what starts it, what it does, and what it has done.
 *
 * @var array<string,mixed>       $automation
 * @var list<array<string,mixed>> $steps
 * @var array{label:string,kind:string,pending:?string}|null $triggerEntry
 * @var array<string,array{label:string,needs:list<string>}> $stepTypes
 * @var list<string>              $delayTypes
 * @var list<array<string,mixed>> $templates
 * @var list<array<string,mixed>> $users
 * @var list<array<string,mixed>> $runs
 * @var list<array<string,mixed>> $log
 * @var bool                      $paused
 * @var list<string>              $blockers
 * @var array<string,mixed>|null  $preview
 */

use App\Services\AutomationEngine;

$isOn    = (int) $automation['is_active'] === 1;
$preview = $preview ?? null;

$statusBadge = static function (string $status): string {
    return match ($status) {
        'completed' => 'badge-ok',
        'failed'    => 'badge-bad',
        'exited'    => 'badge-muted',
        'cancelled' => 'badge-muted',
        default     => 'badge-warn',
    };
};
?>
<p class="breadcrumbs">
    <a href="<?= e(url('/automations')) ?>">Automations</a>
    <?php if ($automation['playbook_slug'] !== null): ?>
        / <a href="<?= e(url('/playbooks/' . (string) $automation['playbook_slug'])) ?>">
            <?= e($automation['playbook_name']) ?>
        </a>
    <?php endif; ?>
    / <?= e($automation['name']) ?>
</p>

<?php if ($paused): ?>
    <div class="alert alert-error">
        <?= icon('pause') ?>
        <p><strong>Automations are stopped.</strong> This one will not run, whatever its own switch
            says, until they are started again from the <a href="<?= e(url('/automations')) ?>">automations
            page</a>.</p>
    </div>
<?php endif; ?>

<section class="card">
    <div class="card-head">
        <div>
            <h2><?= e($automation['name']) ?></h2>
            <p class="card-sub"><?= e($automation['description']) ?></p>
        </div>
        <span class="badge <?= $isOn ? 'badge-ok' : 'badge-muted' ?>"><?= $isOn ? 'On' : 'Off' ?></span>
    </div>

    <dl class="definition-list">
        <div>
            <dt>Starts when</dt>
            <dd>
                <?= e($triggerEntry['label'] ?? (string) $automation['trigger_type']) ?>
                <?php if (($triggerEntry['kind'] ?? '') === 'scanned'): ?>
                    <span class="td-sub">Checked every minute by the scheduler.</span>
                <?php elseif ($triggerEntry !== null): ?>
                    <span class="td-sub">
                        Fired by the application the moment it happens
                        (<code class="code-inline"><?= e($automation['trigger_type']) ?></code>).
                    </span>
                <?php endif; ?>
            </dd>
        </div>
        <div>
            <dt>Enrolled so far</dt>
            <dd><?= number_format((int) $automation['run_count']) ?><?php
                ?><?= (int) $automation['goal_count'] > 0
                    ? ', ' . number_format((int) $automation['goal_count']) . ' reached the goal'
                    : '' ?></dd>
        </div>
        <div>
            <dt>Last started</dt>
            <dd><?= $automation['last_run_at'] === null ? 'Never' : e(local_time((string) $automation['last_run_at'])) ?></dd>
        </div>
    </dl>

    <?php if ($blockers !== []): ?>
        <div class="alert alert-warning">
            <?= icon('alert') ?>
            <div>
                <p><strong>This cannot be switched on yet.</strong></p>
                <ul class="list-plain">
                    <?php foreach ($blockers as $blocker): ?>
                        <li><?= e($blocker) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <?php if (can('automations_activate')): ?>
        <form method="post"
              action="<?= e(url('/automations/' . (int) $automation['id'] . ($isOn ? '/deactivate' : '/activate'))) ?>"
              class="form-actions"
              <?= $isOn ? 'data-confirm="Switch this off? Guests part way through stay where they are."' : '' ?>>
            <?= csrf_field() ?>
            <button type="submit" class="btn <?= $isOn ? 'btn-ghost' : '' ?>" <?= $blockers === [] ? '' : 'disabled' ?>>
                <?= $isOn ? 'Switch this automation off' : 'Switch this automation on' ?>
            </button>
        </form>
    <?php endif; ?>
</section>

<!-- ───────────────────────────────── Steps ─────────────────────────────── -->
<section class="card">
    <div class="card-head">
        <div>
            <h2>What it does</h2>
            <p class="card-sub">
                <?= count($steps) ?> <?= count($steps) === 1 ? 'step' : 'steps' ?>, in order. A step
                with a wait on it holds the guest there until its moment comes.
            </p>
        </div>
    </div>

    <?php if ($steps === []): ?>
        <p class="empty">
            No steps yet. An automation with no steps enrols guests and does nothing with them,
            which is why it cannot be switched on until it has one.
        </p>
    <?php else: ?>
        <ol class="step-list">
            <?php foreach ($steps as $index => $step): ?>
                <?php $disabled = (int) ($step['is_enabled'] ?? 1) !== 1; ?>
                <li class="step-item<?= $disabled ? ' is-off' : '' ?>">
                    <div class="step-head">
                        <span class="step-number"><?= $index + 1 ?></span>
                        <div class="step-text">
                            <strong><?= e(AutomationEngine::describeStep($step)) ?></strong>
                            <span class="td-sub">
                                <?php /* NOT the delay again: describeStep() opens with it. */ ?>
                                <?php if ((string) ($step['branch'] ?? 'main') !== 'main'): ?>
                                    · on the <?= e(strtoupper((string) $step['branch'])) ?> side
                                <?php endif; ?>
                                <?php if ($disabled): ?> · switched off<?php endif; ?>
                            </span>
                            <?php if ((int) ($step['completed_count'] ?? 0) > 0): ?>
                                <span class="td-sub">
                                    Done <?= number_format((int) ($step['completed_count'] ?? 0)) ?> times
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (can('automations_manage')): ?>
                        <div class="step-actions">
                            <form method="post" action="<?= e(url('/automations/steps/' . (int) $step['id'] . '/move')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="direction" value="up">
                                <button type="submit" class="btn-icon" aria-label="Move up">↑</button>
                            </form>
                            <form method="post" action="<?= e(url('/automations/steps/' . (int) $step['id'] . '/move')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="direction" value="down">
                                <button type="submit" class="btn-icon" aria-label="Move down">↓</button>
                            </form>
                            <form method="post" action="<?= e(url('/automations/steps/' . (int) $step['id'] . '/toggle')) ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-ghost btn-sm">
                                    <?= $disabled ? 'Switch on' : 'Skip this step' ?>
                                </button>
                            </form>
                            <form method="post" action="<?= e(url('/automations/steps/' . (int) $step['id'] . '/delete')) ?>"
                                  data-confirm="Remove this step? What it has already sent stays in the log.">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-ghost btn-sm">Remove</button>
                            </form>
                        </div>

                        <details class="step-edit">
                            <summary>Edit this step</summary>
                            <?php
                            $action      = '/automations/steps/' . (int) $step['id'];
                            $submitLabel = 'Save this step';
                            include __DIR__ . '/_step_form.php';
                            ?>
                        </details>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>

    <?php if (can('automations_manage')): ?>
        <details class="step-edit" <?= $steps === [] ? 'open' : '' ?>>
            <summary>Add a step</summary>
            <?php
            $step        = null;
            $action      = '/automations/' . (int) $automation['id'] . '/steps';
            $submitLabel = 'Add the step';
            include __DIR__ . '/_step_form.php';
            ?>
        </details>
    <?php endif; ?>
</section>

<!-- ─────────────────────────────── Test mode ───────────────────────────── -->
<?php if (can('automations_manage')): ?>
    <section class="card">
        <div class="card-head">
            <div>
                <h2>Try it on somebody</h2>
                <p class="card-sub">
                    Shows what this sequence would do to one guest, and when. Nothing is sent and
                    nothing is recorded against them.
                </p>
            </div>
        </div>

        <form method="post" action="<?= e(url('/automations/' . (int) $automation['id'] . '/test')) ?>"
              class="form-inline">
            <?= csrf_field() ?>
            <div class="field">
                <label for="contact_id">Guest’s id</label>
                <input type="number" id="contact_id" name="contact_id" min="1" required
                       placeholder="e.g. 12">
                <p class="form-hint">
                    From the contact’s own page — the number in its address. The preview reads their
                    real details, visits and bookings, because that is what decides the branches.
                </p>
            </div>
            <button type="submit" class="btn btn-ghost">Preview</button>
        </form>

        <?php if ($preview !== null): ?>
            <div class="alert preview-result <?= ($preview['problems'] ?? 0) > 0 ? 'alert-warning' : 'alert-info' ?>">
                <?= icon(($preview['problems'] ?? 0) > 0 ? 'alert' : 'info') ?>
                <div>
                    <p><strong><?= e($preview['headline']) ?></strong></p>

                    <?php if ($preview['rows'] !== []): ?>
                        <ol class="list-plain">
                            <?php foreach ($preview['rows'] as $row): ?>
                                <li>
                                    <?= e($row['what']) ?>
                                    <?php if ($row['skip']): ?>
                                        — <em>skipped: <?= e($row['reason']) ?></em>
                                    <?php elseif ($row['when'] !== null): ?>
                                        — <?= e(local_time((string) $row['when'])) ?>
                                    <?php endif; ?>
                                    <?php if ($row['branch'] !== null): ?>
                                        — this guest goes down the
                                        <strong><?= e(strtoupper((string) $row['branch'])) ?></strong> side
                                    <?php endif; ?>
                                    <?php if ($row['problem'] !== null): ?>
                                        <br><strong>Problem:</strong> <?= e($row['problem']) ?>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<!-- ────────────────────────────── Recent runs ──────────────────────────── -->
<section class="card">
    <div class="card-head">
        <div>
            <h2>Recent runs</h2>
            <p class="card-sub">Who has been through it, and where each of them got to.</p>
        </div>
    </div>

    <?php if ($runs === []): ?>
        <p class="empty">Nobody has been enrolled yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Guest</th>
                        <th scope="col">Started</th>
                        <th scope="col">State</th>
                        <th scope="col">Next</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($runs as $run): ?>
                    <tr>
                        <td>
                            <?php if ($run['contact_id'] !== null): ?>
                                <a href="<?= e(url('/contacts/' . (int) $run['contact_id'])) ?>">
                                    <?= e(trim((string) $run['first_name'] . ' ' . (string) $run['last_name'])) ?: 'Guest' ?>
                                </a>
                            <?php else: ?>
                                <span class="td-sub">
                                    <?= e((string) ($run['entity_type'] ?? 'no guest')) ?>
                                    <?= $run['entity_id'] === null ? '' : '#' . (int) $run['entity_id'] ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td><?= e(local_time((string) $run['started_at'])) ?></td>
                        <td>
                            <span class="badge <?= $statusBadge((string) $run['status']) ?>">
                                <?= e((string) $run['status']) ?>
                            </span>
                            <?php if (($run['error'] ?? null) !== null && (string) $run['error'] !== ''): ?>
                                <span class="td-sub"><?= e(str_truncate((string) $run['error'], 80)) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $run['next_run_at'] === null
                                ? '—'
                                : e(local_time((string) $run['next_run_at'])) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<!-- ─────────────────────────────── The log ─────────────────────────────── -->
<?php if ($log !== []): ?>
    <section class="card">
        <div class="card-head">
            <div>
                <h2>What it has actually done</h2>
                <p class="card-sub">
                    One row per step, skips included with their reason — which is as much of an
                    answer to “what did this send to Dana” as the ones that ran.
                </p>
            </div>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">When</th>
                        <th scope="col">Step</th>
                        <th scope="col">Outcome</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($log as $row): ?>
                    <tr>
                        <td><?= e(local_time((string) $row['executed_at'])) ?></td>
                        <td>
                            <?= e($row['step_type'] === null
                                ? 'a step that has since been removed'
                                : AutomationEngine::describeStep([
                                    'type'   => (string) $row['step_type'],
                                    'config' => $row['step_config'] ?? null,
                                ])) ?>
                        </td>
                        <td>
                            <span class="badge <?= (string) $row['status'] === 'ok' ? 'badge-ok' : ((string) $row['status'] === 'failed' ? 'badge-bad' : 'badge-muted') ?>">
                                <?= e((string) $row['status']) ?>
                            </span>
                            <?php if ((string) ($row['output'] ?? '') !== ''): ?>
                                <span class="td-sub"><?= e(str_truncate((string) $row['output'], 120)) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
