<?php

declare(strict_types=1);

/**
 * Step 4 — apply the schema.
 *
 * @var string              $token
 * @var array<string,mixed> $state
 */

$esc = static fn (mixed $v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');

$migration = new App\Core\Migration(CC_ROOT . '/install/schema', $state['db']['prefix'] ?? 'cc_');
$pending   = $migration->pending();
$applied   = $state['schema'] ?? null;

?>
<div class="panel">
    <h2>Build the database</h2>

    <?php if ($applied !== null): ?>
        <div class="msg ok">
            <strong>Schema applied.</strong>
            <?= count($applied['applied']) ?> file<?= count($applied['applied']) === 1 ? '' : 's' ?> run<?php
            if ($applied['skipped'] !== []): ?>,
                <?= count($applied['skipped']) ?> already present<?php endif; ?>.
        </div>
    <?php else: ?>
        <p class="lede">
            Castle CRM needs about 99 tables. Each schema file is recorded once it runs, so this is
            safe to retry — nothing is applied twice.
        </p>
    <?php endif; ?>

    <?php if ($pending !== []): ?>
        <h3>To apply</h3>
        <table>
            <thead><tr><th scope="col">File</th><th scope="col">Contents</th></tr></thead>
            <tbody>
            <?php
            $descriptions = [
                '001_core.sql'           => 'Users, roles, permissions, sessions, settings, audit log, job queue',
                '002_contacts.sql'       => 'Guests, households, children, companies, tags, consent log',
                '003_sales.sql'          => 'Pipelines, stages, deals, stage history, quotes',
                '004_operations.sql'     => 'Attractions, operating hours, packages, resources, bookings',
                '005_visits.sql'         => 'Visits, tickets, memberships, passes, waivers',
                '006_communications.sql' => 'Conversations, messages, templates, campaigns, Text Club',
                '007_engagement.sql'     => 'Forms, surveys, reviews, donations, coupons, tasks',
                '008_automation.sql'     => 'Playbooks, automations, dashboards, reporting rollups',
            ];

            foreach ($pending as $file): ?>
                <tr>
                    <td><code><?= $esc($file) ?></code></td>
                    <td><?= $esc($descriptions[$file] ?? 'Schema') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php elseif ($applied !== null): ?>
        <p class="lede">Nothing left to apply.</p>
    <?php endif; ?>

    <?php if (!empty($applied['messages'])): ?>
        <h3>Notes</h3>
        <pre><?php foreach ($applied['messages'] as $message) {
            echo $esc($message) . "\n";
        } ?></pre>
    <?php endif; ?>

    <form method="post" action="index.php?step=<?= $applied !== null && $pending === [] ? 5 : 4 ?>">
        <input type="hidden" name="_token" value="<?= $esc($token) ?>">
        <div class="actions">
            <?php if ($applied !== null && $pending === []): ?>
                <a class="btn" href="index.php?step=5">Continue</a>
            <?php else: ?>
                <button type="submit">Build the database</button>
            <?php endif; ?>
            <a class="btn ghost" href="index.php?step=3">Back</a>
        </div>
    </form>
</div>
