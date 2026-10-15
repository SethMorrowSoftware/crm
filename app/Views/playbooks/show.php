<?php

declare(strict_types=1);

/**
 * @var array<string,mixed>       $playbook
 * @var list<array<string,mixed>> $automations
 * @var array<string,int>         $assets
 */
?>
<p class="breadcrumbs"><a href="<?= e(url('/playbooks')) ?>">Playbooks</a> / <?= e($playbook['name']) ?></p>

<section class="card">
    <div class="card-head">
        <div>
            <h2><?= e($playbook['name']) ?></h2>
            <p class="card-sub"><?= e($playbook['tagline']) ?></p>
        </div>
        <span class="badge <?= $playbook['is_active'] ? 'badge-ok' : 'badge-muted' ?>">
            <?= $playbook['is_active'] ? 'Active' : 'Off' ?>
        </span>
    </div>

    <dl class="definition-list">
        <div><dt>The revenue moment</dt><dd><?= e($playbook['revenue_moment']) ?></dd></div>
        <div><dt>What arrives ready</dt><dd><?= e($playbook['arrives_ready']) ?></dd></div>
        <div><dt>What you tailor</dt><dd><?= e($playbook['you_tailor']) ?></dd></div>
    </dl>

    <?php if (can('playbooks_activate')): ?>
        <form method="post"
              action="<?= e(url('/playbooks/' . (int) $playbook['id'] . ($playbook['is_active'] ? '/deactivate' : '/activate'))) ?>"
              class="form-actions">
            <?= csrf_field() ?>
            <button type="submit" class="btn <?= $playbook['is_active'] ? 'btn-ghost' : '' ?>">
                <?= $playbook['is_active'] ? 'Turn this playbook off' : 'Activate this playbook' ?>
            </button>
            <?php if ($playbook['is_active']): ?>
                <span class="form-hint">Turning it off also stops every automation inside it.</span>
            <?php endif; ?>
        </form>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card-head">
        <div>
            <h2>Automations</h2>
            <p class="card-sub">
                <?= count($automations) ?> in this playbook. Each is switched on individually, so you
                can start with one and add more.
            </p>
        </div>
    </div>

    <?php if ($automations === []): ?>
        <p class="empty">No automations are registered for this playbook.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Automation</th><th>Trigger</th><th>State</th></tr></thead>
                <tbody>
                <?php foreach ($automations as $automation): ?>
                    <tr>
                        <td>
                            <strong><?= e($automation['name']) ?></strong>
                            <span class="td-sub"><?= e($automation['description']) ?></span>
                        </td>
                        <td><code class="code-inline"><?= e($automation['trigger_type']) ?></code></td>
                        <td>
                            <span class="badge <?= $automation['is_active'] ? 'badge-ok' : 'badge-muted' ?>">
                                <?= $automation['is_active'] ? 'On' : 'Off' ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="form-hint">
            The automation builder arrives in Phase 5. Until then this is the registry of what the
            playbook will run.
        </p>
    <?php endif; ?>
</section>
