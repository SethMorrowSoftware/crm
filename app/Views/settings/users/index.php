<?php

declare(strict_types=1);

/** @var list<array<string,mixed>> $users */
?>
<div class="page-actions">
    <p class="page-lede">
        Give each person the narrowest role that lets them work. Front Desk deliberately cannot see
        guest spend; Party Host sees only their own leads.
    </p>
    <a class="btn" href="<?= e(url('/settings/users/new')) ?>"><?= icon('plus', 16) ?> Add a user</a>
</div>

<section class="card">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Name</th><th>Role</th><th>Last seen</th><th>State</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($users as $user): ?>
                <?php $locked = !empty($user['locked_until']) && strtotime((string) $user['locked_until']) > time(); ?>
                <tr<?= (int) $user['is_active'] === 0 ? ' class="row-muted"' : '' ?>>
                    <td>
                        <strong><?= e($user['name']) ?></strong>
                        <span class="td-sub"><?= e($user['email']) ?></span>
                    </td>
                    <td><?= e($user['role_name'] ?? 'No role') ?></td>
                    <td><?= $user['last_login_at'] ? e(time_ago((string) $user['last_login_at'])) : '<span class="muted">Never</span>' ?></td>
                    <td>
                        <?php if ((int) $user['is_active'] === 0): ?>
                            <span class="badge badge-muted">Inactive</span>
                        <?php elseif ($locked): ?>
                            <span class="badge badge-warn">Locked</span>
                        <?php else: ?>
                            <span class="badge badge-ok">Active</span>
                        <?php endif; ?>
                    </td>
                    <td class="td-right">
                        <a class="btn btn-ghost btn-sm" href="<?= e(url('/settings/users/' . (int) $user['id'])) ?>">Edit</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
