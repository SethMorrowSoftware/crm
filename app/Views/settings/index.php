<?php

declare(strict_types=1);

/** @var array<string,int> $counts */

$sections = [
    ['Venue details', '/settings/venue', 'Name, address, phone, timezone and tax rate.', 'cog', 'settings'],
    ['System', '/settings/system', 'Safe Mode, quiet hours, message caps and retention windows.', 'power', 'settings'],
    ['Users', '/settings/users', $counts['users'] . ' accounts.', 'users', 'users_manage'],
    ['Roles & permissions', '/settings/roles', $counts['roles'] . ' roles.', 'lock', 'roles_manage'],
    ['Playbooks', '/playbooks', $counts['playbooks'] . ' of 8 active.', 'book', 'view_automations'],
    ['System health', '/settings/health', 'Cron, the queue, extensions and HTTPS.', 'shield', 'settings'],
    ['Audit log', '/settings/audit', 'Who changed what, and when.', 'list', 'view_logs'],
];
?>
<p class="page-lede">Everything that configures how Castle CRM behaves.</p>

<div class="settings-grid">
    <?php foreach ($sections as [$label, $href, $detail, $iconName, $permission]): ?>
        <?php if (!can($permission)) { continue; } ?>
        <a class="card settings-tile" href="<?= e(url($href)) ?>">
            <span class="settings-icon" aria-hidden="true"><?= icon($iconName, 20) ?></span>
            <span class="settings-body">
                <strong><?= e($label) ?></strong>
                <span><?= e($detail) ?></span>
            </span>
            <?= icon('arrow-right', 16) ?>
        </a>
    <?php endforeach; ?>
</div>
