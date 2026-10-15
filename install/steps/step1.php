<?php

declare(strict_types=1);

/**
 * Step 1 — welcome and what setup will do.
 *
 * @var string $token
 */
?>
<div class="panel">
    <h2>Set up Castle CRM</h2>
    <p class="lede">
        This takes about five minutes. You need your database details from cPanel and nothing else —
        no shell access, no command line.
    </p>

    <h3>What these eight steps do</h3>
    <ul>
        <li><strong>Check the server</strong> can run Castle CRM, and tell you exactly what to change in cPanel if not.</li>
        <li><strong>Connect your database</strong> and verify the user can actually create tables.</li>
        <li><strong>Build the schema</strong> — around 99 tables.</li>
        <li><strong>Record your venue</strong>: name, address, timezone, tax rate.</li>
        <li><strong>Create your owner account.</strong></li>
        <li><strong>Seed your starting data</strong>: the Castle's attractions, party packages, pipelines
            and the eight revenue playbooks.</li>
        <li><strong>Finish</strong> with the one cron line to paste into cPanel and the DNS records for email.</li>
    </ul>

    <h3>Two things to know up front</h3>

    <div class="msg ok">
        <strong>Nothing will message a guest.</strong>
        Castle CRM finishes setup in <strong>Safe Mode</strong>: every email and text is redirected to
        your own address, and every seeded automation starts switched off. You turn that off deliberately,
        later, when you have read what the messages say.
    </div>

    <div class="msg warn">
        <strong>Your point-of-sale stays in charge.</strong>
        Castle CRM runs the work <em>around</em> a visit — the inquiry, the quote, the follow-up, the
        review request. CenterEdge keeps the cash drawer, the Castle Card and the confirmed party
        reservation. Castle CRM reads from it; it never overwrites it.
    </div>

    <form method="post" action="index.php?step=1">
        <input type="hidden" name="_token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
        <div class="actions">
            <button type="submit">Start setup</button>
        </div>
    </form>
</div>
