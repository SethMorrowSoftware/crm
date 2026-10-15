<?php

declare(strict_types=1);

/**
 * System settings, including the two switches that decide whether Castle CRM
 * can talk to a real guest.
 *
 * @var array<string,mixed> $system
 * @var bool                $safeMode
 * @var bool                $sendingPaused
 * @var string              $ownerEmail
 */

$venueName = setting('venue.name', 'The Castle Fun Center');

?>
<p class="page-lede">How Castle CRM behaves when nobody is watching it.</p>

<!-- ───────────────────────────── Safe Mode ───────────────────────────── -->
<section class="card <?= $safeMode ? 'card-accent' : '' ?>">
    <div class="card-head">
        <div>
            <h2>Safe Mode</h2>
            <p class="card-sub">
                <?= $safeMode
                    ? 'ON — every message is redirected to the owner. No guest can be contacted.'
                    : 'OFF — messages reach real guests.' ?>
            </p>
        </div>
        <span class="badge <?= $safeMode ? 'badge-warn' : 'badge-ok' ?>">
            <?= $safeMode ? 'Protected' : 'Live' ?>
        </span>
    </div>

    <?php if ($safeMode): ?>
        <p>
            While Safe Mode is on, anything Castle CRM would send to a guest goes to
            <strong><?= e($ownerEmail !== '' ? $ownerEmail : 'the owner account') ?></strong> instead,
            with the intended recipient noted at the top. Use it to read your templates as a parent
            would before any of them reach a parent.
        </p>

        <div class="alert alert-warning">
            <?= icon('alert') ?>
            <p>
                Turning Safe Mode off lets Castle CRM email and text real families. Make sure your
                venue details, templates and the automations you have activated all say what you want
                them to say.
            </p>
        </div>

        <form method="post" action="<?= e(url('/settings/safe-mode')) ?>" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="enable" value="0">

            <div class="field">
                <label for="confirm_name">
                    Type <strong><?= e($venueName) ?></strong> to confirm
                </label>
                <input type="text" id="confirm_name" name="confirm_name" required
                       autocomplete="off" placeholder="<?= e($venueName) ?>">
                <p class="field-hint">
                    Deliberately awkward. This is the moment the system starts contacting your guests.
                </p>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-danger">Leave Safe Mode</button>
            </div>
        </form>
    <?php else: ?>
        <p>
            Castle CRM is messaging real guests. Turn Safe Mode back on to redirect everything to the
            owner — useful while testing a new template or sequence.
        </p>

        <form method="post" action="<?= e(url('/settings/safe-mode')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="enable" value="1">
            <button type="submit" class="btn btn-ghost">Turn Safe Mode back on</button>
        </form>
    <?php endif; ?>
</section>

<!-- ─────────────────────────── Kill switch ───────────────────────────── -->
<section class="card">
    <div class="card-head">
        <div>
            <h2>Outbound sending</h2>
            <p class="card-sub">
                The stop button. Different from Safe Mode: this halts sending entirely rather than
                redirecting it.
            </p>
        </div>
        <span class="badge <?= $sendingPaused ? 'badge-bad' : 'badge-ok' ?>">
            <?= $sendingPaused ? 'Paused' : 'Running' ?>
        </span>
    </div>

    <p>
        <?= $sendingPaused
            ? 'Sending is paused. Queued messages are waiting, not failing — they go out when you resume.'
            : 'Use this if something is sending that should not be. Nothing is lost; messages queue up.' ?>
    </p>

    <form method="post" action="<?= e(url('/settings/pause-sending')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="pause" value="<?= $sendingPaused ? '0' : '1' ?>">
        <button type="submit" class="btn <?= $sendingPaused ? '' : 'btn-danger' ?>">
            <?= $sendingPaused ? 'Resume sending' : 'Pause all sending' ?>
        </button>
    </form>
</section>

<!-- ───────────────────────── Messaging guardrails ────────────────────── -->
<form method="post" action="<?= e(url('/settings/system')) ?>" class="card form">
    <?= csrf_field() ?>

    <div class="card-head">
        <div>
            <h2>Guardrails</h2>
            <p class="card-sub">
                Limits that apply to every automation and campaign, so one misconfigured sequence
                cannot overwhelm a guest.
            </p>
        </div>
    </div>

    <div class="field-row">
        <div class="field">
            <label for="quiet_hours_start">Quiet hours start</label>
            <input type="time" id="quiet_hours_start" name="quiet_hours_start"
                   value="<?= e(old('quiet_hours_start') ?: ($system['quiet_hours_start'] ?? '09:00')) ?>">
            <p class="field-hint">The earliest a message may go out, venue local time.</p>
        </div>
        <div class="field">
            <label for="quiet_hours_end">Quiet hours end</label>
            <input type="time" id="quiet_hours_end" name="quiet_hours_end"
                   value="<?= e(old('quiet_hours_end') ?: ($system['quiet_hours_end'] ?? '20:00')) ?>">
            <p class="field-hint">After this, messages wait until tomorrow.</p>
        </div>
    </div>

    <div class="field">
        <label for="message_cap_per_week">Marketing messages per guest per week</label>
        <input type="number" id="message_cap_per_week" name="message_cap_per_week" min="0" max="50"
               value="<?= e(old('message_cap_per_week') ?: ($system['message_cap_per_week'] ?? 4)) ?>">
        <p class="field-hint">
            A hard cap across all campaigns and automations. Transactional messages — confirmations,
            reminders, receipts — do not count against it. Set 0 to remove the cap.
        </p>
    </div>

    <div class="field-row">
        <div class="field">
            <label for="lapse_days">A guest counts as lapsed after</label>
            <input type="number" id="lapse_days" name="lapse_days" min="30" max="730"
                   value="<?= e(old('lapse_days') ?: ($system['lapse_days'] ?? 120)) ?>">
            <p class="field-hint">Days without a visit. Drives the win-back ladders.</p>
        </div>
        <div class="field">
            <label for="attribution_days">Attribution window</label>
            <input type="number" id="attribution_days" name="attribution_days" min="1" max="180"
                   value="<?= e(old('attribution_days') ?: ($system['attribution_days'] ?? 30)) ?>">
            <p class="field-hint">
                Days after a click or open in which a booking is credited to that campaign.
            </p>
        </div>
    </div>

    <!-- ─────────────────────── Security & retention ──────────────────── -->
    <h3 class="form-section-title form-section-divided">Security &amp; retention</h3>

    <!-- An unchecked checkbox posts nothing, so the hidden input carries the
         "off" value. Without it, saving this form could never turn the setting
         back off again. -->
    <input type="hidden" name="force_https" value="0">

    <div class="field-check">
        <input type="checkbox" id="force_https" name="force_https" value="1"
               <?= !empty($system['force_https']) ? 'checked' : '' ?>>
        <label for="force_https">Require HTTPS for every page</label>
        <p class="field-hint">
            Sends any plain-HTTP visitor to the <code>https://</code> address instead, and refuses a
            form submission that arrives unencrypted rather than silently dropping it. Turn this on
            as soon as your certificate works, so a password or a guest's details never travel in
            the clear.
        </p>
    </div>

    <?php if (!request()->isSecure()): ?>
        <div class="alert alert-warning">
            <?= icon('alert') ?>
            <p>
                <strong>You are reading this page over plain HTTP.</strong> Get a certificate working
                first &mdash; in cPanel that is <em>SSL/TLS Status</em> &rarr; <em>Run AutoSSL</em>,
                and it is free. Turning this on before <code>https://</code> works would lock you out
                of your own installation.
            </p>
        </div>
    <?php elseif (empty($system['force_https'])): ?>
        <div class="alert alert-info">
            <?= icon('check-circle') ?>
            <p>HTTPS is working on this request, so it is safe to turn this on.</p>
        </div>
    <?php endif; ?>

    <div class="field">
        <label for="audit_retention_days">Keep audit log entries for</label>
        <input type="number" id="audit_retention_days" name="audit_retention_days" min="0" max="3650"
               value="<?= e(old('audit_retention_days') ?: ($system['audit_retention_days'] ?? 730)) ?>">
        <p class="field-hint">
            Days. The nightly job deletes entries older than this. <strong>0 keeps them forever</strong>,
            which is the safest answer for a dispute and the worst one for a shared-hosting disk quota
            &mdash; two years is a reasonable middle. Consent records are never pruned by this setting:
            they are the evidence that a guest agreed to be contacted, so they are kept for as long as
            the contact exists.
        </p>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn">Save settings</button>
        <a class="btn btn-ghost" href="<?= e(url('/settings')) ?>">Back</a>
    </div>
</form>
