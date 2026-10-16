<?php

declare(strict_types=1);

/**
 * @var list<array{name:string,status:string,detail:string,fix:?string}> $checks
 * @var string      $cronLine
 * @var string|null $webCron
 * @var array<string,mixed> $phpInfo
 */

$counts = ['ok' => 0, 'warn' => 0, 'fail' => 0];
foreach ($checks as $check) {
    $counts[$check['status']] = ($counts[$check['status']] ?? 0) + 1;
}
?>
<p class="page-lede">
    The things that break quietly. <?= (int) $counts['ok'] ?> fine<?php
    if ($counts['warn'] > 0): ?>, <?= (int) $counts['warn'] ?> worth a look<?php endif;
    if ($counts['fail'] > 0): ?>, <strong><?= (int) $counts['fail'] ?> needing attention</strong><?php endif; ?>.
</p>

<section class="card">
    <ul class="checks">
        <?php foreach ($checks as $check): ?>
            <li>
                <span class="mark mark-<?= e($check['status']) ?>" aria-hidden="true">
                    <?= $check['status'] === 'ok' ? icon('check', 14)
                        : ($check['status'] === 'warn' ? icon('alert', 14) : icon('alert', 14)) ?>
                </span>
                <span class="check-body">
                    <span class="check-label"><?= e($check['name']) ?></span>
                    <span class="check-detail"><?= e($check['detail']) ?></span>
                    <?php if ($check['fix'] !== null): ?>
                        <span class="check-fix"><strong>What to do:</strong> <?= e($check['fix']) ?></span>
                    <?php endif; ?>
                </span>
                <span class="sr-only"><?= e(match ($check['status']) {
                    'ok' => 'Passing', 'warn' => 'Warning', default => 'Failing',
                }) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
</section>

<section class="card">
    <div class="card-head">
        <div>
            <h2>The cron job</h2>
            <p class="card-sub">
                Without this nothing scheduled runs — no reminders, follow-ups, renewal notices or
                digests — and it fails silently.
            </p>
        </div>
    </div>

    <p>In <strong>cPanel &rarr; Cron Jobs</strong>, choose "Once Per Minute" and paste:</p>
    <pre class="code-block"><?= e($cronLine) ?></pre>
    <p class="field-hint">Every five minutes is fine too; timed messages just land a little later.</p>

    <?php if ($webCron !== null): ?>
        <p>No CLI cron on your host? Point an uptime monitor at this URL instead:</p>
        <pre class="code-block"><?= e($webCron) ?></pre>
        <p class="field-hint">Treat it as a secret — anyone with it can trigger your scheduled work.</p>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/settings/health/test-cron')) ?>" class="form-actions">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-ghost">Test cron</button>
        <span class="form-hint">
            Sets a marker the next cron run clears. Reload this page a minute later to confirm.
        </span>
    </form>
</section>

<section class="card">
    <div class="card-head"><div><h2>Environment</h2></div></div>
    <div class="table-wrap">
        <table class="table">
            <caption class="sr-only">System checks</caption>
            <tbody>
            <tr><th scope="col">PHP version</th><td><?= e($phpInfo['version']) ?></td></tr>
            <tr><th scope="col">Server API</th><td><?= e($phpInfo['sapi']) ?></td></tr>
            <tr><th scope="col">Memory limit</th><td><?= e($phpInfo['memory_limit']) ?></td></tr>
            <tr><th scope="col">Max execution time</th><td><?= e($phpInfo['max_execution']) ?>s</td></tr>
            <tr><th scope="col">Max upload size</th><td><?= e($phpInfo['upload_max']) ?></td></tr>
            </tbody>
        </table>
    </div>
</section>
