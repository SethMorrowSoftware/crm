<?php

declare(strict_types=1);

/**
 * @var list<array<string,mixed>> $roles
 * @var int                       $catalogTotal
 */
?>
<div class="page-actions">
    <p class="page-lede">
        Roles are data, so you can shape them around how the Castle actually works.
        <?= (int) $catalogTotal ?> permissions are available.
    </p>
    <a class="btn" href="<?= e(url('/settings/roles/new')) ?>"><?= icon('plus', 16) ?> Add a role</a>
</div>

<section class="card">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Role</th><th>People</th><th>Permissions</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($roles as $role): ?>
                <tr>
                    <td>
                        <strong><?= e($role['name']) ?></strong>
                        <?php if ($role['slug'] === 'owner'): ?>
                            <span class="badge badge-muted">Locked</span>
                        <?php elseif ((int) $role['is_system'] === 1): ?>
                            <span class="badge badge-muted">Built in</span>
                        <?php endif; ?>
                        <span class="td-sub"><?= e($role['description']) ?></span>
                    </td>
                    <td><?= (int) $role['user_count'] ?></td>
                    <td>
                        <?= $role['slug'] === 'owner'
                            ? 'All ' . (int) $catalogTotal
                            : (int) $role['permission_count'] . ' of ' . (int) $catalogTotal ?>
                    </td>
                    <td class="td-right">
                        <?php if ($role['slug'] !== 'owner'): ?>
                            <a class="btn btn-ghost btn-sm"
                               href="<?= e(url('/settings/roles/' . (int) $role['id'])) ?>">Edit</a>
                        <?php else: ?>
                            <span class="muted">&mdash;</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <h2>How scope works</h2>
    <p>
        Each granted permission carries a reach:
    </p>
    <dl class="definition-list">
        <div><dt>All</dt><dd>Every record in the venue.</dd></div>
        <div><dt>Team</dt><dd>Records owned by anyone sharing a team with them.</dd></div>
        <div><dt>Own</dt><dd>Only records they own. A party host sees their leads, not the team's.</dd></div>
    </dl>
    <p class="field-hint">
        The Owner role is locked and always holds everything — it is the way back in if a custom role
        is misconfigured. You can only grant permissions you hold yourself.
    </p>
</section>
