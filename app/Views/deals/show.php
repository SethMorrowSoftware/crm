<?php

declare(strict_types=1);

/**
 * One deal: where it is, what it is worth, who the guest is, and every stage
 * it has been through.
 *
 * The history is the reason the move form is a POST that writes a row rather
 * than a quiet UPDATE — "this sat in Quote Sent for nine days" is only
 * answerable because each move was recorded.
 *
 * @var array<string,mixed>       $deal
 * @var list<array<string,mixed>> $stages
 * @var list<array<string,mixed>> $history
 */

$dealId     = (int) $deal['id'];
$canManage  = can('deals_manage');
$guestName  = trim((string) ($deal['first_name'] ?? '') . ' ' . (string) ($deal['last_name'] ?? ''));
$stageColor = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $deal['stage_color']) === 1
    ? (string) $deal['stage_color']
    : '#6b4ea8';

/** A plain DATE column, printed without timezone conversion. */
$dateText = static function (mixed $date): string {
    $value = trim((string) ($date ?? ''));

    if ($value === '' || str_starts_with($value, '0000')) {
        return '';
    }

    $time = strtotime($value . ' 00:00:00 UTC');

    return $time === false ? '' : gmdate('M j, Y', $time);
};

/** Seconds in a stage, in the units a person would say out loud. */
$spent = static function (mixed $seconds): string {
    if ($seconds === null || $seconds === '') {
        return '';
    }

    $seconds = max(0, (int) $seconds);

    if ($seconds < 90) {
        return $seconds . ' seconds';
    }

    if ($seconds < 5400) {
        $minutes = (int) round($seconds / 60);

        return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
    }

    if ($seconds < 172800) {
        $hours = (int) round($seconds / 3600);

        return $hours . ' hour' . ($hours === 1 ? '' : 's');
    }

    $days = (int) round($seconds / 86400);

    return $days . ' day' . ($days === 1 ? '' : 's');
};
?>
<p class="breadcrumbs">
    <a href="<?= e(url('/deals?pipeline=' . (int) $deal['pipeline_id'])) ?>"><?= e($deal['pipeline_name']) ?></a>
    / <?= e($deal['title']) ?>
</p>

<div class="page-actions">
    <p class="page-lede">
        <span class="badge" style="border-left:3px solid <?= e($stageColor) ?>">
            <?= e($deal['stage_name']) ?>
        </span>
        <?php if ((string) $deal['status'] === 'won'): ?>
            <span class="badge badge-ok">Won</span>
        <?php elseif ((string) $deal['status'] === 'lost'): ?>
            <span class="badge badge-muted">Lost</span>
        <?php elseif ((string) $deal['status'] === 'abandoned'): ?>
            <span class="badge badge-muted">Abandoned</span>
        <?php endif; ?>
        &middot; <?= e(money((int) $deal['value_cents'])) ?>
        <?php if ($deal['stage_probability'] !== null): ?>
            &middot; <?= (int) $deal['stage_probability'] ?>% at this stage
        <?php endif; ?>
    </p>
    <?php if ($canManage): ?>
        <a class="btn" href="<?= e(url('/deals/' . $dealId . '/edit')) ?>"><?= icon('edit', 16) ?> Edit</a>
    <?php endif; ?>
</div>

<div class="grid-2">
    <div>
        <section class="card">
            <div class="card-head">
                <h2>The deal</h2>
            </div>

            <dl class="definition-list">
                <dt>Guest</dt>
                <dd>
                    <?php /*
                     * /contacts/{id} is the contacts module's own URL and
                     * lands when that module ships; until then /contacts is
                     * still the Phase 2 placeholder, so the guest's email and
                     * phone are printed here as well and nothing on this page
                     * depends on following the link.
                     */ ?>
                    <?php if ((int) ($deal['contact_id'] ?? 0) > 0): ?>
                        <a href="<?= e(url('/contacts/' . (int) $deal['contact_id'])) ?>">
                            <?= e($guestName === '' ? 'Contact #' . (int) $deal['contact_id'] : $guestName) ?>
                        </a>
                        <?php if (!empty($deal['contact_email'])): ?>
                            <span class="td-sub"><?= e($deal['contact_email']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($deal['contact_phone'])): ?>
                            <span class="td-sub"><?= e(format_phone((string) $deal['contact_phone'])) ?></span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="muted">No contact linked yet</span>
                    <?php endif; ?>
                </dd>

                <dt>Owner</dt>
                <dd><?= $deal['owner_name'] === null
                        ? '<span class="muted">Unassigned</span>'
                        : e($deal['owner_name']) ?></dd>

                <dt>Value</dt>
                <dd><?= e(money((int) $deal['value_cents'])) ?></dd>

                <dt>Expected close</dt>
                <dd><?= $dateText($deal['expected_close_date']) === ''
                        ? '<span class="muted">—</span>'
                        : e($dateText($deal['expected_close_date'])) ?></dd>

                <dt>Event date</dt>
                <dd><?= $dateText($deal['event_date']) === ''
                        ? '<span class="muted">—</span>'
                        : e($dateText($deal['event_date'])) ?></dd>

                <dt>Guests</dt>
                <dd><?= $deal['guest_count'] === null
                        ? '<span class="muted">—</span>'
                        : number_format((int) $deal['guest_count']) ?></dd>

                <dt>Source</dt>
                <dd>
                    <?php if (!empty($deal['source'])): ?>
                        <?= e(ucfirst(str_replace('_', ' ', (string) $deal['source']))) ?>
                        <?php if (!empty($deal['source_detail'])): ?>
                            <span class="td-sub"><?= e($deal['source_detail']) ?></span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="muted">Not recorded</span>
                    <?php endif; ?>
                </dd>

                <dt>Next action</dt>
                <dd><?= empty($deal['next_action'])
                        ? '<span class="muted">Nothing planned</span>'
                        : e($deal['next_action']) ?></dd>

                <dt>In this stage since</dt>
                <dd>
                    <?= empty($deal['stage_entered_at'])
                        ? '<span class="muted">—</span>'
                        : e(local_time((string) $deal['stage_entered_at'])) ?>
                    <?php if (!empty($deal['sla_due_at'])): ?>
                        <span class="td-sub">Response deadline <?= e(local_time((string) $deal['sla_due_at'])) ?></span>
                    <?php endif; ?>
                </dd>

                <?php if (!empty($deal['lost_reason'])): ?>
                    <dt>Lost because</dt>
                    <dd><?= e($deal['lost_reason']) ?></dd>
                <?php endif; ?>
            </dl>
        </section>

        <?php if ($canManage && count($stages) > 1): ?>
            <section class="card">
                <div class="card-head">
                    <h2>Move it on</h2>
                </div>
                <form method="post" action="<?= e(url('/deals/' . $dealId . '/stage')) ?>" class="form">
                    <?= csrf_field() ?>
                    <div class="field">
                        <label for="stage_id">Stage</label>
                        <select id="stage_id" name="stage_id">
                            <?php foreach ($stages as $stage): ?>
                                <option value="<?= (int) $stage['id'] ?>"
                                    <?= (int) $stage['id'] === (int) $deal['stage_id'] ? 'selected' : '' ?>>
                                    <?= e($stage['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="field-hint">
                            Moving it records who moved it and how long it had been sitting. A won or
                            lost stage closes the deal.
                        </p>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn">Move the deal</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>
    </div>

    <div>
        <section class="card">
            <div class="card-head">
                <h2>Stage history</h2>
            </div>

            <?php if ($history === []): ?>
                <p class="empty">Nothing recorded yet.</p>
            <?php else: ?>
                <ul class="timeline">
                    <?php foreach ($history as $entry): ?>
                        <li>
                            <p class="timeline-what">
                                <?php if ($entry['from_stage'] === null): ?>
                                    Created in <strong><?= e($entry['to_stage'] ?? 'a stage') ?></strong>
                                <?php else: ?>
                                    <?= e($entry['from_stage']) ?> &rarr;
                                    <strong><?= e($entry['to_stage'] ?? 'a stage') ?></strong>
                                <?php endif; ?>
                                <?php if ($spent($entry['duration_seconds']) !== ''): ?>
                                    <span class="td-sub">
                                        after <?= e($spent($entry['duration_seconds'])) ?> in the previous stage
                                    </span>
                                <?php endif; ?>
                            </p>
                            <p class="timeline-when">
                                <?= e(local_time((string) $entry['created_at'])) ?>
                                &middot; <?= $entry['user_name'] === null
                                    ? 'automatically'
                                    : e($entry['user_name']) ?>
                            </p>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <?php if (can('deals_delete')): ?>
            <section class="card card-danger">
                <h2>Remove this deal</h2>
                <p>
                    It leaves the board and every report. The stage history is kept, so if this was a
                    mistake an administrator can still see what happened.
                </p>
                <form method="post" action="<?= e(url('/deals/' . $dealId . '/delete')) ?>"
                      data-confirm="Remove <?= e($deal['title']) ?> from the pipeline?">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-danger"><?= icon('trash', 15) ?> Remove deal</button>
                </form>
            </section>
        <?php endif; ?>
    </div>
</div>
