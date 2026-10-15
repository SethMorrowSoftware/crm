<?php

declare(strict_types=1);

/**
 * The dashboard.
 *
 * Phase 1 shows what genuinely exists plus a setup checklist. Stat tiles whose
 * module has not shipped say so rather than showing a zero a manager would
 * misread as "no bookings today".
 *
 * @var string                    $greeting
 * @var array<string,mixed>       $setup
 * @var array<string,mixed>       $playbooks
 * @var list<array<string,mixed>> $stats
 * @var array<string,mixed>       $health
 * @var list<array<string,mixed>> $recentAudit
 */

$setupIncomplete = $setup['complete'] < $setup['total'];

?>

<p class="greeting"><?= e($greeting) ?>.</p>

<?php if ($setupIncomplete): ?>
    <section class="card card-accent" aria-labelledby="setup-heading">
        <div class="card-head">
            <div>
                <h2 id="setup-heading">Finish setting up</h2>
                <p class="card-sub">
                    <?= (int) $setup['complete'] ?> of <?= (int) $setup['total'] ?> done.
                    This card disappears once everything is ticked.
                </p>
            </div>
            <div class="progress-ring" role="img"
                 aria-label="<?= (int) $setup['complete'] ?> of <?= (int) $setup['total'] ?> setup steps complete">
                <strong><?= (int) $setup['complete'] ?>/<?= (int) $setup['total'] ?></strong>
            </div>
        </div>

        <ul class="checklist">
            <?php foreach ($setup['items'] as $item): ?>
                <li class="<?= $item['done'] ? 'is-done' : '' ?>">
                    <span class="checklist-mark" aria-hidden="true">
                        <?= $item['done'] ? icon('check-circle', 17) : icon('clock', 17) ?>
                    </span>
                    <span class="checklist-body">
                        <span class="checklist-label">
                            <?php if (!$item['done'] && $item['url'] !== null): ?>
                                <a href="<?= e(url($item['url'])) ?>"><?= e($item['label']) ?></a>
                            <?php else: ?>
                                <?= e($item['label']) ?>
                            <?php endif; ?>
                        </span>
                        <span class="checklist-detail"><?= e($item['detail']) ?></span>
                    </span>
                    <span class="sr-only"><?= $item['done'] ? 'Complete' : 'Outstanding' ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<section class="stat-row" aria-label="Key figures">
    <?php foreach ($stats as $stat): ?>
        <?php $tag = $stat['url'] !== null ? 'a' : 'div'; ?>
        <<?= $tag ?> class="stat<?= $stat['ready'] ? '' : ' stat-pending' ?>"
            <?= $stat['url'] !== null ? 'href="' . e(url($stat['url'])) . '"' : '' ?>>
            <span class="stat-label"><?= e($stat['label']) ?></span>
            <span class="stat-value"><?= $stat['ready'] ? e($stat['value']) : '&mdash;' ?></span>
            <span class="stat-note"><?= e($stat['note']) ?></span>
        </<?= $tag ?>>
    <?php endforeach; ?>
</section>

<div class="grid-2">

    <section class="card" aria-labelledby="playbooks-heading">
        <div class="card-head">
            <div>
                <h2 id="playbooks-heading">Revenue playbooks</h2>
                <p class="card-sub">
                    <?= (int) $playbooks['active'] ?> of <?= (int) $playbooks['total'] ?> active.
                    Each one is a bundle of pipelines, forms, templates and automations.
                </p>
            </div>
            <?php if (can('view_automations')): ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('/playbooks')) ?>">
                    Manage <?= icon('arrow-right', 15) ?>
                </a>
            <?php endif; ?>
        </div>

        <?php if ($playbooks['rows'] === []): ?>
            <p class="empty">
                No playbooks are installed. Re-run the installer's seeding step to add them.
            </p>
        <?php else: ?>
            <ul class="playbook-list">
                <?php foreach ($playbooks['rows'] as $playbook): ?>
                    <li>
                        <span class="playbook-number"><?= e(str_pad((string) $playbook['number'], 2, '0', STR_PAD_LEFT)) ?></span>
                        <span class="playbook-body">
                            <?php if (can('view_automations')): ?>
                                <a href="<?= e(url('/playbooks/' . $playbook['slug'])) ?>"
                                   class="playbook-name"><?= e($playbook['name']) ?></a>
                            <?php else: ?>
                                <span class="playbook-name"><?= e($playbook['name']) ?></span>
                            <?php endif; ?>
                            <span class="playbook-tagline"><?= e($playbook['tagline']) ?></span>
                        </span>
                        <span class="badge <?= $playbook['is_active'] ? 'badge-ok' : 'badge-muted' ?>">
                            <?= $playbook['is_active'] ? 'Active' : 'Off' ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <div class="stack-lg">
        <section class="card" aria-labelledby="health-heading">
            <div class="card-head">
                <div>
                    <h2 id="health-heading">System</h2>
                    <p class="card-sub">The things that break quietly.</p>
                </div>
                <?php if (can('settings')): ?>
                    <a class="btn btn-ghost btn-sm" href="<?= e(url('/settings/health')) ?>">
                        Details <?= icon('arrow-right', 15) ?>
                    </a>
                <?php endif; ?>
            </div>

            <dl class="health-list">
                <?php
                $cronAge = $health['cron_age'];

                $cronState = match (true) {
                    $cronAge === null    => ['bad', 'Never run'],
                    $cronAge < 900       => ['ok', time_ago(gmdate('Y-m-d H:i:s', time() - $cronAge))],
                    default              => ['warn', 'Stale — ' . time_ago(gmdate('Y-m-d H:i:s', time() - $cronAge))],
                };
                ?>
                <div class="health-row">
                    <dt>Scheduled work</dt>
                    <dd><span class="dot dot-<?= e($cronState[0]) ?>"></span><?= e($cronState[1]) ?></dd>
                </div>

                <?php if ($cronAge === null): ?>
                    <p class="health-warning">
                        Cron has never run, so no reminder, follow-up or digest can fire.
                        <?php if (can('settings')): ?>
                            <a href="<?= e(url('/settings/health')) ?>">Get the cron line</a>.
                        <?php endif; ?>
                    </p>
                <?php endif; ?>

                <div class="health-row">
                    <dt>Queued jobs</dt>
                    <dd>
                        <span class="dot dot-<?= (int) $health['queue_depth'] > 5000 ? 'warn' : 'ok' ?>"></span>
                        <?= number_format((int) $health['queue_depth']) ?>
                    </dd>
                </div>

                <div class="health-row">
                    <dt>Failed jobs</dt>
                    <dd>
                        <span class="dot dot-<?= (int) $health['failed_jobs'] > 0 ? 'warn' : 'ok' ?>"></span>
                        <?= number_format((int) $health['failed_jobs']) ?>
                    </dd>
                </div>

                <div class="health-row">
                    <dt>Outbound messaging</dt>
                    <dd>
                        <?php if ($health['sending_paused']): ?>
                            <span class="dot dot-bad"></span>Paused
                        <?php elseif ($health['safe_mode']): ?>
                            <span class="dot dot-warn"></span>Safe Mode — redirected to the owner
                        <?php else: ?>
                            <span class="dot dot-ok"></span>Live
                        <?php endif; ?>
                    </dd>
                </div>
            </dl>
        </section>

        <?php if ($recentAudit !== []): ?>
            <section class="card" aria-labelledby="activity-heading">
                <div class="card-head">
                    <div>
                        <h2 id="activity-heading">Recent activity</h2>
                        <p class="card-sub">Who changed what.</p>
                    </div>
                    <?php if (can('view_logs')): ?>
                        <a class="btn btn-ghost btn-sm" href="<?= e(url('/settings/audit')) ?>">
                            All <?= icon('arrow-right', 15) ?>
                        </a>
                    <?php endif; ?>
                </div>

                <ul class="timeline">
                    <?php foreach ($recentAudit as $entry): ?>
                        <li>
                            <span class="timeline-when"><?= e(time_ago((string) $entry['created_at'])) ?></span>
                            <span class="timeline-what">
                                <strong><?= e($entry['user_name'] ?? 'System') ?></strong>
                                <?= e(str_replace(['.', '_'], [' ', ' '], (string) $entry['action'])) ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
    </div>
</div>
