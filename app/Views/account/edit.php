<?php

declare(strict_types=1);

/**
 * @var array<string,mixed>       $user
 * @var list<array<string,mixed>> $sessions
 * @var string|null               $currentSession
 * @var array<string,mixed>       $prefs
 */
?>
<p class="page-lede">Your own details, password and preferences.</p>

<form method="post" action="<?= e(url('/account/profile')) ?>" class="card form">
    <?= csrf_field() ?>
    <div class="card-head"><div><h2>Your details</h2></div></div>

    <div class="field">
        <label for="name">Name</label>
        <input type="text" id="name" name="name" required value="<?= e(old('name') ?: $user['name']) ?>">
    </div>

    <div class="field">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email" required value="<?= e(old('email') ?: $user['email']) ?>">
        <p class="field-hint">Also your sign-in name.</p>
    </div>

    <div class="field-row">
        <div class="field">
            <label for="phone">Phone</label>
            <input type="text" id="phone" name="phone" value="<?= e(old('phone') ?: ($user['phone'] ?? '')) ?>">
        </div>
        <div class="field">
            <label for="timezone">Your timezone</label>
            <select id="timezone" name="timezone">
                <option value="">Use the venue's (<?= e(setting('venue.timezone', 'America/New_York')) ?>)</option>
                <?php foreach (DateTimeZone::listIdentifiers(DateTimeZone::AMERICA) as $zone): ?>
                    <option value="<?= e($zone) ?>" <?= ($user['timezone'] ?? '') === $zone ? 'selected' : '' ?>>
                        <?= e($zone) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="form-actions"><button type="submit" class="btn">Save details</button></div>
</form>

<form method="post" action="<?= e(url('/account/password')) ?>" class="card form" autocomplete="off">
    <?= csrf_field() ?>
    <div class="card-head">
        <div>
            <h2>Change your password</h2>
            <p class="card-sub">Signs you out on every other device.</p>
        </div>
    </div>

    <div class="field">
        <label for="current_password">Current password</label>
        <input type="password" id="current_password" name="current_password" required
               autocomplete="current-password">
    </div>

    <div class="field-row">
        <div class="field">
            <label for="password">New password</label>
            <input type="password" id="password" name="password" required minlength="12"
                   autocomplete="new-password">
            <p class="field-hint">At least 12 characters.</p>
        </div>
        <div class="field">
            <label for="password_confirmation">Confirm it</label>
            <input type="password" id="password_confirmation" name="password_confirmation" required
                   minlength="12" autocomplete="new-password">
        </div>
    </div>

    <div class="form-actions"><button type="submit" class="btn">Change password</button></div>
</form>

<form method="post" action="<?= e(url('/account/preferences')) ?>" class="card form">
    <?= csrf_field() ?>
    <div class="card-head"><div><h2>Preferences</h2></div></div>

    <div class="field-row">
        <div class="field">
            <label for="theme">Theme</label>
            <select id="theme" name="theme">
                <?php foreach (['system' => 'Match my device', 'light' => 'Light', 'dark' => 'Dark'] as $value => $label): ?>
                    <option value="<?= e($value) ?>"
                        <?= ($prefs['theme'] ?? 'system') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="density">Table density</label>
            <select id="density" name="density">
                <option value="compact" <?= ($prefs['density'] ?? 'compact') === 'compact' ? 'selected' : '' ?>>Compact</option>
                <option value="comfortable" <?= ($prefs['density'] ?? '') === 'comfortable' ? 'selected' : '' ?>>Comfortable</option>
            </select>
        </div>
    </div>

    <h3 class="form-section-title">Tell me when</h3>
    <?php
    $notifications = [
        'notify_assigned_deal'    => 'A lead is assigned to me',
        'notify_sla_breach'       => 'One of my leads passes its response deadline',
        'notify_task_due'         => 'A task of mine is due',
        'notify_detractor'        => 'A guest leaves poor feedback',
        'notify_daily_digest'     => 'Send me the daily digest',
        'notify_weekly_scorecard' => 'Send me the weekly scorecard',
    ];
    ?>
    <?php foreach ($notifications as $key => $label): ?>
        <div class="field field-check">
            <input type="checkbox" id="<?= e($key) ?>" name="<?= e($key) ?>" value="1"
                <?= !empty($prefs[$key]) ? 'checked' : '' ?>>
            <label for="<?= e($key) ?>"><?= e($label) ?></label>
        </div>
    <?php endforeach; ?>

    <div class="form-actions"><button type="submit" class="btn">Save preferences</button></div>
</form>

<section class="card">
    <div class="card-head">
        <div>
            <h2>Your sessions</h2>
            <p class="card-sub"><?= count($sessions) ?> active.</p>
        </div>
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead><tr><th scope="col">Device</th><th scope="col">IP</th><th scope="col">Last used</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
            <tbody>
            <?php foreach ($sessions as $session): ?>
                <tr>
                    <td><?= e(str_truncate((string) $session['user_agent'], 60)) ?></td>
                    <td class="muted"><?= e($session['ip']) ?></td>
                    <td><?= e(time_ago((string) $session['last_activity'])) ?></td>
                    <td class="td-right">
                        <?php if ($session['id'] === $currentSession): ?>
                            <span class="badge badge-ok">This device</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if (count($sessions) > 1): ?>
        <form method="post" action="<?= e(url('/account/sessions/revoke')) ?>" class="form-actions">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-ghost">Sign out my other devices</button>
        </form>
    <?php endif; ?>
</section>
