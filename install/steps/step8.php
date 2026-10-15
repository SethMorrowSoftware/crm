<?php

declare(strict_types=1);

/**
 * Step 8 — finish, then the handover instructions.
 *
 * The three things on this page that actually matter after a successful
 * install: the cron line, the DNS records, and deleting install/.
 *
 * @var string              $token
 * @var array<string,mixed> $state
 */

$esc = static fn (mixed $v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');

$complete = !empty($state['complete']);
$summary  = $state['summary'] ?? [];

$appBase = rtrim(str_replace('\\', '/', dirname(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')))), '/');
$appUrl  = ($appBase === '' ? '/' : $appBase . '/');

if (!$complete):
    ?>
    <div class="panel">
        <h2>Ready to finish</h2>
        <p class="lede">This last step writes your configuration, creates your account and seeds your data.</p>

        <table>
            <tbody>
            <tr>
                <th>Database</th>
                <td><code><?= $esc($state['db']['name'] ?? '') ?></code>
                    on <code><?= $esc($state['db']['host'] ?? '') ?></code>
                    <?php if (!empty($state['db_version'])): ?>
                        &middot; MySQL <?= $esc($state['db_version']) ?>
                    <?php endif; ?>
                </td>
            </tr>
            <tr><th>Table prefix</th><td><code><?= $esc($state['db']['prefix'] ?? 'cc_') ?></code></td></tr>
            <tr><th>Venue</th><td><?= $esc($state['venue']['name'] ?? '') ?>
                &middot; <?= $esc($state['venue']['timezone'] ?? '') ?></td></tr>
            <tr><th>Owner</th><td><?= $esc($state['owner']['name'] ?? '') ?>
                &lt;<?= $esc($state['owner']['email'] ?? '') ?>&gt;</td></tr>
            <tr>
                <th>Seeding</th>
                <td>
                    <?php
                    $chosen = array_keys(array_filter($state['seeds'] ?? []));
                    echo $chosen === [] ? 'Nothing — roles and permissions only' : $esc(implode(', ', $chosen));
                    ?>
                </td>
            </tr>
            </tbody>
        </table>

        <form method="post" action="index.php?step=8">
            <input type="hidden" name="_token" value="<?= $esc($token) ?>">
            <div class="actions">
                <button type="submit">Finish setup</button>
                <a class="btn ghost" href="index.php?step=7">Back</a>
            </div>
        </form>
    </div>
    <?php
    return;
endif;
?>

<div class="panel">
    <h2>Castle CRM is installed</h2>

    <div class="msg ok">
        <strong>Your account is ready.</strong>
        Sign in at <a href="<?= $esc($appUrl) ?>"><?= $esc($appUrl) ?></a>
        with <code><?= $esc($state['owner']['email'] ?? '') ?></code>.
    </div>

    <?php if (!empty($summary['seeds'])): ?>
        <h3>What was created</h3>
        <ul>
            <?php if (isset($summary['seeds']['permissions'])): ?>
                <li><?= (int) $summary['seeds']['permissions']['permissions'] ?> permissions across
                    <?= (int) $summary['seeds']['permissions']['roles'] ?> roles</li>
            <?php endif; ?>
            <?php if (isset($summary['seeds']['venue'])): ?>
                <li><?= (int) $summary['seeds']['venue']['attractions'] ?> attractions,
                    <?= (int) $summary['seeds']['venue']['packages'] ?> party packages,
                    <?= (int) $summary['seeds']['venue']['addons'] ?> add-ons,
                    <?= (int) $summary['seeds']['venue']['resources'] ?> resources,
                    <?= (int) $summary['seeds']['venue']['pipelines'] ?> pipelines</li>
            <?php endif; ?>
            <?php if (isset($summary['seeds']['playbooks'])): ?>
                <li><?= (int) $summary['seeds']['playbooks']['playbooks'] ?> revenue playbooks with
                    <?= (int) $summary['seeds']['playbooks']['automations'] ?> automations
                    &mdash; all inactive until you switch them on</li>
            <?php endif; ?>
        </ul>
    <?php endif; ?>
</div>

<div class="panel">
    <h2>Three things left to do</h2>

    <h3>1. Delete the install folder</h3>
    <p>
        In <strong>cPanel &rarr; File Manager</strong>, select the <code>install</code> folder and
        choose Delete. The installer has locked itself, but removing it entirely is better.
    </p>

    <h3>2. Add the cron job</h3>
    <p>
        Without this, <strong>nothing scheduled ever runs</strong> — no reminders, no follow-ups, no
        renewal notices, no digests. It is the single most common thing to get missed, and the failure
        is silent.
    </p>
    <p>In <strong>cPanel &rarr; Cron Jobs</strong>, choose "Once Per Minute" and paste:</p>
    <pre><?= $esc($summary['cron_line'] ?? '') ?></pre>
    <p class="hint">
        Every five minutes is fine too if your host restricts cron frequency; timed messages will
        just land up to five minutes late.
    </p>

    <?php if (!empty($summary['cron_token'])): ?>
        <p class="hint">
            No CLI cron available? Point an external uptime service at this URL once a minute instead:
        </p>
        <pre><?= $esc(rtrim($appUrl, '/')) ?>/cron.php?token=<?= $esc($summary['cron_token']) ?></pre>
        <p class="hint">Treat that URL as a secret — anyone holding it can trigger your scheduled work.</p>
    <?php endif; ?>

    <h3>3. Set up email authentication</h3>
    <p>
        Without these records your mail lands in spam. In
        <strong>cPanel &rarr; Email Deliverability</strong>, click <strong>Repair</strong> — that
        handles SPF and DKIM for you. If you would rather add them by hand, in
        <strong>Zone Editor</strong>:
    </p>

    <table>
        <thead><tr><th>Type</th><th>Name</th><th>Value</th></tr></thead>
        <tbody>
        <?php foreach ($summary['dns'] ?? [] as $record): ?>
            <tr>
                <td><?= $esc($record['type']) ?></td>
                <td><code><?= $esc($record['name']) ?></code></td>
                <td>
                    <code><?= $esc($record['value']) ?></code>
                    <p class="hint"><?= $esc($record['note']) ?></p>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="panel">
    <h2>Before you go live</h2>

    <div class="msg warn">
        <strong>Safe Mode is ON.</strong>
        Every email and text Castle CRM would send to a guest is being redirected to
        <code><?= $esc($state['owner']['email'] ?? '') ?></code> instead. Nothing can reach a real
        family yet. That is deliberate.
    </div>

    <p>A sensible order from here:</p>
    <ol>
        <li><strong>Confirm your venue details</strong> — the seeded prices and hours came from your
            public website and need checking against reality.</li>
        <li><strong>Add your team</strong> and give each person the narrowest role that lets them work.
            Front Desk deliberately cannot see guest spend.</li>
        <li><strong>Connect email and SMS</strong>. SMS needs a registered 10DLC brand before US
            carriers will deliver it, and that takes two to four weeks — start it early.</li>
        <li><strong>Activate one playbook</strong>, read its automations, and turn on the one or two
            that address your worst leak. Birthday follow-up is the usual answer.</li>
        <li><strong>Send yourself test messages</strong> while Safe Mode is on. Read them as a parent
            would.</li>
        <li><strong>Then leave Safe Mode</strong>, in Settings &rarr; System. You will be asked to type
            the venue name to confirm.</li>
    </ol>

    <div class="actions">
        <a class="btn" href="<?= $esc($appUrl) ?>">Sign in to Castle CRM</a>
    </div>
</div>
