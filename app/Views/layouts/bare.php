<?php

declare(strict_types=1);

/**
 * A chrome-free layout for pages reached without a session: sign in, password
 * reset, and error pages.
 *
 * @var string      $content
 * @var string      $pageTitle
 * @var list<array{type:string,message:string}> $flashMessages
 * @var bool        $bareWide
 * @var string|null $venueName
 */

$pageTitle ??= 'Castle CRM';
$flashMessages = is_array($flashMessages ?? null) ? $flashMessages : [];

/*
 * WHOSE NAME IS ON THE PAGE.
 *
 * This layout serves two different audiences. Sign-in, password reset and the
 * error pages are staff looking at their CRM, and "Castle CRM" is the right
 * name there. A waiver, a party enquiry, a Text Club sign-up, an unsubscribe
 * confirmation or a feedback question is a GUEST, usually a parent on a phone
 * at the door — and they have never heard of Castle CRM. Branding their page
 * with the name of the venue's back-office software reads, at best, as the
 * wrong company; at worst as a phishing page, which is exactly the reaction
 * you do not want from somebody being asked to sign something.
 *
 * So the venue's name wins whenever a page supplies it, and every public page
 * already does. The fallback is for the staff pages, which supply nothing.
 */
$brandName = trim((string) ($venueName ?? ''));
$brandName = $brandName === '' ? 'Castle CRM' : $brandName;

// Opt-in, so sign-in and the error pages keep the narrow card they were
// designed around. Only a page that genuinely needs two columns asks.
$bareClass = ($bareWide ?? false) === true ? 'bare-main is-wide' : 'bare-main';

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle) ?> &middot; <?= e($brandName) ?></title>
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
</head>
<body class="bare">
<main class="<?= e($bareClass) ?>">
    <div class="bare-card">
        <div class="bare-brand">
            <span class="brand-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="26" height="26" fill="currentColor">
                    <path d="M3 21V9l3-2v3l3-2V5l3-2 3 2v3l3-2v3l3 2v12H3zm6-2h2v-5H9v5zm4 0h2v-5h-2v5z"/>
                </svg>
            </span>
            <strong><?= e($brandName) ?></strong>
        </div>

        <?php foreach ($flashMessages as $flash): ?>
            <?php
            $type = in_array($flash['type'] ?? '', ['success', 'error', 'warning', 'info'], true)
                ? $flash['type']
                : 'info';
            ?>
            <div class="alert alert-<?= e($type) ?>">
                <?= icon($type === 'success' ? 'check-circle' : ($type === 'info' ? 'info' : 'alert')) ?>
                <p><?= e($flash['message'] ?? '') ?></p>
            </div>
        <?php endforeach; ?>

        <?= $content ?>
    </div>
</main>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
