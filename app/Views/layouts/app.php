<?php

declare(strict_types=1);

/**
 * The application shell: sidebar, header, flash messages, content.
 *
 * @var string                        $content
 * @var string                        $pageTitle
 * @var array<string,mixed>|null      $currentUser
 * @var string                        $venueName
 * @var bool                          $safeMode
 * @var bool                          $sendingPaused
 * @var list<array{type:string,message:string}> $flashMessages
 */

$pageTitle ??= 'Castle CRM';
$flashMessages = is_array($flashMessages ?? null) ? $flashMessages : [];

$prefs = json_decode((string) ($currentUser['notification_prefs'] ?? '{}'), true) ?: [];
$theme = in_array($prefs['theme'] ?? 'system', ['light', 'dark'], true) ? $prefs['theme'] : null;

$path = request()->path();

/**
 * Nav sections. Each item names the permission that reveals it, so the sidebar
 * and the router agree by construction rather than by remembering to update
 * both.
 */
$nav = [
    [
        'label' => null,
        'items' => [
            ['Dashboard', '/', 'view_dashboard', 'grid'],
            ['Inbox', '/inbox', 'view_inbox', 'inbox'],
        ],
    ],
    [
        'label' => 'Guests & sales',
        'items' => [
            ['Contacts', '/contacts', 'view_contacts', 'users'],
            ['Pipelines', '/deals', 'view_deals', 'trending'],
            ['Bookings', '/bookings', 'view_bookings', 'calendar'],
            ['Calendar', '/calendar', 'view_bookings', 'calendar'],
            ['Check-in', '/checkin', 'checkin', 'check'],
            ['Memberships', '/memberships', 'view_memberships', 'card'],
        ],
    ],
    [
        'label' => 'Growth',
        'items' => [
            ['Marketing', '/marketing', 'view_marketing', 'megaphone'],
            ['Campaigns', '/campaigns', 'view_marketing', 'megaphone'],
            ['Segments', '/segments', 'view_marketing', 'target'],
            ['Playbooks', '/playbooks', 'view_automations', 'book'],
            ['Reputation', '/reputation', 'view_reputation', 'star'],
        ],
    ],
    [
        'label' => 'Work',
        'items' => [
            ['Tasks', '/tasks', 'view_tasks', 'check'],
            ['Reports', '/reports', 'view_reports', 'chart'],
        ],
    ],
];

$isCurrent = static function (string $href) use ($path): bool {
    if ($href === '/') {
        return $path === '/';
    }

    return $path === $href || str_starts_with($path, $href . '/');
};

?><!doctype html>
<html lang="en"<?= $theme !== null ? ' data-theme="' . e($theme) . '"' : '' ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle) ?> &middot; <?= e($venueName ?? 'Castle CRM') ?></title>
    <?= csrf_token() !== '' ? '<meta name="csrf-token" content="' . e(csrf_token()) . '">' : '' ?>
    <meta name="base-path" content="<?= e(url('/')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
</head>
<body data-density="<?= e($prefs['density'] ?? 'compact') ?>">

<a class="skip-link" href="#main">Skip to content</a>

<div class="shell">

    <!-- ───────────────────────────── Sidebar ──────────────────────────── -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <a href="<?= e(url('/')) ?>" class="brand-link">
                <span class="brand-mark" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor">
                        <path d="M3 21V9l3-2v3l3-2V5l3-2 3 2v3l3-2v3l3 2v12H3zm6-2h2v-5H9v5zm4 0h2v-5h-2v5z"/>
                    </svg>
                </span>
                <span class="brand-text">
                    <strong>Castle CRM</strong>
                    <small><?= e(str_truncate($venueName ?? '', 28)) ?></small>
                </span>
            </a>
        </div>

        <nav class="sidebar-nav" aria-label="Main">
            <?php foreach ($nav as $section): ?>
                <?php
                $visible = array_values(array_filter(
                    $section['items'],
                    static fn (array $item): bool => can($item[2])
                ));

                if ($visible === []) {
                    continue;
                }
                ?>
                <?php if ($section['label'] !== null): ?>
                    <p class="nav-heading"><?= e($section['label']) ?></p>
                <?php endif; ?>
                <ul class="nav-list">
                    <?php foreach ($visible as [$label, $href, $permission, $icon]): ?>
                        <li>
                            <a href="<?= e(url($href)) ?>"
                               class="nav-link<?= $isCurrent($href) ? ' is-current' : '' ?>"
                                <?= $isCurrent($href) ? 'aria-current="page"' : '' ?>>
                                <?= icon($icon) ?>
                                <span><?= e($label) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>

            <?php if (can_any(['settings', 'users_manage', 'roles_manage', 'view_logs', 'waivers_manage'])): ?>
                <p class="nav-heading">Admin</p>
                <ul class="nav-list">
                    <?php if (can('waivers_manage')): ?>
                        <li>
                            <a href="<?= e(url('/waivers')) ?>"
                               class="nav-link<?= $isCurrent('/waivers') ? ' is-current' : '' ?>">
                                <?= icon('shield') ?>
                                <span>Waivers</span>
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if (can('settings')): ?>
                        <li><a href="<?= e(url('/settings')) ?>"
                               class="nav-link<?= $isCurrent('/settings') ? ' is-current' : '' ?>">
                            <?= icon('cog') ?><span>Settings</span></a></li>
                    <?php endif; ?>
                    <?php if (can('users_manage')): ?>
                        <li><a href="<?= e(url('/settings/users')) ?>"
                               class="nav-link<?= $isCurrent('/settings/users') ? ' is-current' : '' ?>">
                            <?= icon('users') ?><span>Users</span></a></li>
                    <?php endif; ?>
                    <?php if (can('view_logs')): ?>
                        <li><a href="<?= e(url('/settings/audit')) ?>"
                               class="nav-link<?= $isCurrent('/settings/audit') ? ' is-current' : '' ?>">
                            <?= icon('list') ?><span>Audit log</span></a></li>
                    <?php endif; ?>
                </ul>
            <?php endif; ?>
        </nav>

        <div class="sidebar-foot">
            <a href="<?= e(url('/account')) ?>" class="user-chip">
                <span class="avatar" aria-hidden="true"><?= e(initials((string) ($currentUser['name'] ?? '?'))) ?></span>
                <span class="user-text">
                    <strong><?= e(str_truncate((string) ($currentUser['name'] ?? ''), 20)) ?></strong>
                    <small><?= e($currentUser['role_name'] ?? 'No role') ?></small>
                </span>
            </a>
            <form method="post" action="<?= e(url('/logout')) ?>" class="logout-form">
                <?= csrf_field() ?>
                <button type="submit" class="btn-icon" title="Sign out" aria-label="Sign out">
                    <?= icon('exit') ?>
                </button>
            </form>
        </div>
    </aside>

    <!-- ──────────────────────────── Main area ─────────────────────────── -->
    <div class="main-area">

        <header class="topbar">
            <button type="button" class="btn-icon nav-toggle" data-nav-toggle
                    aria-label="Toggle navigation" aria-expanded="false" aria-controls="sidebar">
                <?= icon('menu') ?>
            </button>

            <h1 class="page-title"><?= e($pageTitle) ?></h1>

            <div class="topbar-right">
                <?php if ($safeMode ?? true): ?>
                    <?php $safeHref = can('sending_override') ? url('/settings/system') : null; ?>
                    <?php if ($safeHref !== null): ?><a href="<?= e($safeHref) ?>" class="pill pill-warn"
                        title="All outbound messages are redirected to the owner. Nothing reaches a guest.">
                    <?php else: ?><span class="pill pill-warn"
                        title="All outbound messages are redirected to the owner. Nothing reaches a guest.">
                    <?php endif; ?>
                        <?= icon('shield') ?> Safe Mode
                    <?= $safeHref !== null ? '</a>' : '</span>' ?>
                <?php endif; ?>

                <?php if ($sendingPaused ?? false): ?>
                    <span class="pill pill-bad" title="All outbound sending is paused.">
                        <?= icon('pause') ?> Sending paused
                    </span>
                <?php endif; ?>
            </div>
        </header>

        <main id="main" class="content" tabindex="-1">
            <?php if ($flashMessages !== []): ?>
                <div class="flash-stack" role="status" aria-live="polite">
                    <?php foreach ($flashMessages as $flash): ?>
                        <?php
                        $type = in_array($flash['type'] ?? '', ['success', 'error', 'warning', 'info'], true)
                            ? $flash['type']
                            : 'info';
                        ?>
                        <div class="alert alert-<?= e($type) ?>">
                            <?= icon(match ($type) {
                                'success' => 'check-circle',
                                'error'   => 'alert',
                                'warning' => 'alert',
                                default   => 'info',
                            }) ?>
                            <p><?= e($flash['message'] ?? '') ?></p>
                            <button type="button" class="alert-close" data-dismiss aria-label="Dismiss">&times;</button>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?= $content ?>
        </main>
    </div>
</div>

<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
