<?php

declare(strict_types=1);

/**
 * Step 3 — database connection.
 *
 * @var string              $token
 * @var array<string,mixed> $state
 */

$esc = static fn (mixed $v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');

$db = $state['db'] ?? [
    'host'   => 'localhost',
    'port'   => 3306,
    'name'   => '',
    'user'   => '',
    'prefix' => 'cc_',
    'socket' => '',
];

$needsConfirm = !empty($state['needs_confirm']);

?>
<div class="panel">
    <h2>Connect your database</h2>
    <p class="lede">
        Create the database and user in <strong>cPanel &rarr; MySQL&reg; Databases</strong> first,
        then paste the details here.
    </p>

    <div class="msg warn">
        <strong>cPanel prefixes both names with your account.</strong>
        If your cPanel user is <code class="inline">castlefun</code> and you name the database
        <code class="inline">crm</code>, the real name is <code class="inline">castlefun_crm</code>.
        Use the full prefixed name. Same for the username.
    </div>

    <?php if ($needsConfirm): ?>
        <div class="msg warn">
            <strong>This database is not empty.</strong>
            Read the notes above. If this is the right database, tick the box below to go ahead —
            Castle CRM only touches tables starting with its own prefix.
        </div>
    <?php endif; ?>

    <form method="post" action="index.php?step=3" autocomplete="off">
        <input type="hidden" name="_token" value="<?= $esc($token) ?>">

        <div class="row two">
            <div>
                <label for="db_host">Database host</label>
                <input type="text" id="db_host" name="db_host" value="<?= $esc($db['host']) ?>" required>
                <p class="hint">On cPanel this is almost always <code class="inline">localhost</code>.</p>
            </div>
            <div>
                <label for="db_port">Port</label>
                <input type="number" id="db_port" name="db_port" value="<?= $esc($db['port']) ?>" min="1" max="65535">
                <p class="hint">Leave at 3306 unless your host says otherwise.</p>
            </div>
        </div>

        <label for="db_name">Database name</label>
        <input type="text" id="db_name" name="db_name" value="<?= $esc($db['name']) ?>"
               placeholder="castlefun_crm" required>

        <label for="db_user">Database username</label>
        <input type="text" id="db_user" name="db_user" value="<?= $esc($db['user']) ?>"
               placeholder="castlefun_crmuser" required autocomplete="off">

        <label for="db_pass">Database password</label>
        <input type="password" id="db_pass" name="db_pass" autocomplete="new-password">
        <p class="hint">
            Never shown again once setup finishes. It is written to
            <code class="inline">config/config.php</code>, which is blocked from the web.
        </p>

        <label for="db_prefix">Table prefix <span class="opt">— optional</span></label>
        <input type="text" id="db_prefix" name="db_prefix" value="<?= $esc($db['prefix']) ?>" pattern="[A-Za-z0-9_]*">
        <p class="hint">
            Lets Castle CRM share a database with another application, which matters when your plan
            limits how many databases you get. Leave as <code class="inline">cc_</code> if unsure.
        </p>

        <label for="db_socket">Unix socket <span class="opt">— rarely needed</span></label>
        <input type="text" id="db_socket" name="db_socket" value="<?= $esc($db['socket'] ?? '') ?>"
               placeholder="/var/lib/mysql/mysql.sock">
        <p class="hint">Only if your host requires a socket instead of a host and port.</p>

        <?php if ($needsConfirm): ?>
            <div class="choice" style="margin-top:1.25rem">
                <input type="checkbox" id="confirm_existing" name="confirm_existing" value="1" required>
                <div>
                    <label for="confirm_existing">Yes, use this database</label>
                    <p class="hint">I have read the notes above and this is the right database.</p>
                </div>
            </div>
        <?php endif; ?>

        <div class="actions">
            <button type="submit">Test connection &amp; continue</button>
            <a class="btn ghost" href="index.php?step=2">Back</a>
        </div>
    </form>
</div>
