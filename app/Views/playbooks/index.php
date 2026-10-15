<?php

declare(strict_types=1);

/**
 * @var list<array<string,mixed>> $playbooks
 * @var int                       $activeCount
 * @var bool                      $safeMode
 */
?>
<p class="page-lede">
    A playbook is a ready-built system for one revenue moment — its pipeline, forms, templates and
    automations in one bundle. <strong><?= (int) $activeCount ?> of <?= count($playbooks) ?></strong>
    are active.
</p>

<?php if ($safeMode): ?>
    <div class="alert alert-info">
        <?= icon('shield') ?>
        <p>
            Safe Mode is on, so activating a playbook cannot message a guest. Turning a playbook on
            makes its assets available; each automation inside it still has to be switched on
            individually.
        </p>
    </div>
<?php endif; ?>

<div class="playbook-grid">
    <?php foreach ($playbooks as $playbook): ?>
        <article class="card playbook-card<?= $playbook['is_active'] ? ' is-active' : '' ?>">
            <header class="playbook-card-head">
                <span class="playbook-number-lg">
                    <?= e(str_pad((string) $playbook['number'], 2, '0', STR_PAD_LEFT)) ?>
                </span>
                <span class="badge <?= $playbook['is_active'] ? 'badge-ok' : 'badge-muted' ?>">
                    <?= $playbook['is_active'] ? 'Active' : 'Off' ?>
                </span>
            </header>

            <h2 class="playbook-card-title">
                <a href="<?= e(url('/playbooks/' . $playbook['slug'])) ?>"><?= e($playbook['name']) ?></a>
            </h2>
            <p class="playbook-card-tagline"><?= e($playbook['tagline']) ?></p>

            <p class="playbook-card-meta">
                <?= (int) $playbook['automation_count'] ?> automations
                <?php if ((int) $playbook['active_automation_count'] > 0): ?>
                    &middot; <strong><?= (int) $playbook['active_automation_count'] ?> running</strong>
                <?php endif; ?>
            </p>

            <?php if (can('playbooks_activate')): ?>
                <form method="post"
                      action="<?= e(url('/playbooks/' . (int) $playbook['id'] . ($playbook['is_active'] ? '/deactivate' : '/activate'))) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm <?= $playbook['is_active'] ? 'btn-ghost' : '' ?>">
                        <?= $playbook['is_active'] ? 'Turn off' : 'Activate' ?>
                    </button>
                </form>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</div>
