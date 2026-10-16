<?php

declare(strict_types=1);

/**
 * Email provider configuration.
 *
 * The page is ordered the way an operator actually proceeds: pick how mail
 * leaves the server, say who it comes from, then prove it works. The DNS
 * records come last because they are the part people put off, and they matter
 * only once something is actually sending.
 *
 * @var array<string,mixed> $mail
 * @var string              $driver
 * @var bool                $hasPassword
 * @var bool                $safeMode
 * @var bool                $phpMailAvailable
 * @var list<array{type:string,name:string,value:string,note:string}> $dns
 */

// old()'s DEFAULT, not `?:`. With `?:`, a submitted empty value is falsy and
// falls through to the stored one — so after a validation error the form
// silently restored a field the operator had just cleared. Clearing the Safe
// Mode address and mistyping the From address in the same submit put the old
// address back, looking saved, and redirected mail kept going to a mailbox
// nobody reads.
$v = static fn (string $key, string $default = ''): string
    => (string) old($key, $mail[$key] ?? $default);

// Whether this render follows a rejected submit. `driver` is always posted by
// the form, so its presence in the old input distinguishes "the operator
// cleared this" from "nothing was submitted" — which a checkbox cannot tell
// from its own value, since an unticked box posts nothing at all.
$resubmitted = old('driver', null) !== null;

$drivers = [
    'log' => [
        'label'  => 'Write to the log only',
        'detail' => 'Nothing leaves the server. Every message is written in full to '
            . 'storage/logs/ instead, including password-reset links. This is the safe default '
            . 'and it is genuinely useful: you can read exactly what would have been sent.',
    ],
    'php_mail' => [
        'label'  => "This server's own mail (PHP mail())",
        'detail' => 'The simplest thing that works on cPanel: hands the message to the server\'s '
            . 'own mail queue. Fine for a handful of transactional messages a day. Deliverability '
            . 'depends entirely on your DNS records being right.',
    ],
    'smtp' => [
        'label'  => 'SMTP',
        'detail' => 'Connects to a mail server with a username and password — your own cPanel '
            . 'mailbox, or a sending service. The right answer for anything resembling volume, '
            . 'because a service manages reputation and bounces for you.',
    ],
];

$currentDriver = old('driver') ?: $driver;
?>
<p class="page-lede">
    How email leaves Castle CRM, and who it appears to come from. Nothing on this page can make a
    message reach a guest while Safe Mode is on &mdash; it decides the route, not the permission.
</p>

<?php if ($safeMode): ?>
    <div class="alert alert-info">
        <?= icon('shield') ?>
        <p>
            <strong>Safe Mode is on</strong>, so every message is redirected to the owner's address
            whatever you configure here. That is exactly what you want while you set this up: send
            yourself the test below, read it, then decide about Safe Mode on
            <a href="<?= e(url('/settings/system')) ?>">the System page</a>.
        </p>
    </div>
<?php endif; ?>

<form method="post" action="<?= e(url('/settings/mail')) ?>" class="card form">
    <?= csrf_field() ?>

    <div class="card-head">
        <div>
            <h2>How mail is sent</h2>
            <p class="card-sub">Start with the log driver. Move up when you need to.</p>
        </div>
    </div>

    <?php foreach ($drivers as $key => $info): ?>
        <div class="field-check">
            <input type="radio" id="driver_<?= e($key) ?>" name="driver" value="<?= e($key) ?>"
                   <?= $currentDriver === $key ? 'checked' : '' ?>
                   <?= $key === 'php_mail' && !$phpMailAvailable ? 'disabled' : '' ?>>
            <label for="driver_<?= e($key) ?>"><?= e($info['label']) ?></label>
            <p class="field-hint">
                <?= e($info['detail']) ?>
                <?php if ($key === 'php_mail' && !$phpMailAvailable): ?>
                    <br><strong>Unavailable:</strong> this server has PHP's <code>mail()</code>
                    function disabled, so this option cannot work here. Use SMTP.
                <?php endif; ?>
            </p>
        </div>
    <?php endforeach; ?>

    <?php if ($err = error_for('driver')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>

    <h3 class="form-section-title form-section-divided">Who it comes from</h3>

    <div class="field-row">
        <div class="field">
            <label for="from_email">From address</label>
            <input type="email" id="from_email" name="from_email" required
                   value="<?= e($v('from_email')) ?>">
            <p class="field-hint">
                Use an address at your own domain. A Gmail or Yahoo address here will be rejected
                outright by most providers, because their DMARC policy forbids anyone else sending
                as them.
            </p>
            <?php if ($err = error_for('from_email')): ?>
                <p class="field-error"><?= e($err) ?></p>
            <?php endif; ?>
        </div>
        <div class="field">
            <label for="from_name">From name</label>
            <input type="text" id="from_name" name="from_name" required
                   value="<?= e($v('from_name', (string) setting('venue.name', 'The Castle Fun Center'))) ?>">
            <p class="field-hint">What a parent sees in their inbox list.</p>
            <?php if ($err = error_for('from_name')): ?>
                <p class="field-error"><?= e($err) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="field-row">
        <div class="field">
            <label for="reply_to">Reply-to address</label>
            <input type="email" id="reply_to" name="reply_to" value="<?= e($v('reply_to')) ?>">
            <p class="field-hint">
                Where a reply lands. Leave blank to use the from address. Point it at a mailbox
                somebody actually reads &mdash; parents reply to confirmations.
            </p>
        </div>
        <div class="field">
            <label for="safe_mode_address">Safe Mode address</label>
            <input type="email" id="safe_mode_address" name="safe_mode_address"
                   value="<?= e($v('safe_mode_address')) ?>">
            <p class="field-hint">
                Where redirected mail goes while Safe Mode is on. Blank means the owner account's
                own address.
            </p>
        </div>
    </div>

    <!-- SMTP. Shown always rather than toggled by script: the app ships no
         inline JavaScript, and a settings page that works with scripting off
         is worth more than a page that hides four fields. -->
    <h3 class="form-section-title form-section-divided">SMTP details</h3>
    <p class="field-hint">
        Only used by the SMTP driver. In cPanel, <em>Email Accounts</em> &rarr; <em>Connect
        Devices</em> shows these values for any mailbox you have created.
    </p>

    <div class="field-row">
        <div class="field">
            <label for="smtp_host">Host</label>
            <input type="text" id="smtp_host" name="smtp_host" value="<?= e($v('smtp_host')) ?>"
                   placeholder="mail.your-domain.com" autocomplete="off">
            <?php if ($err = error_for('smtp_host')): ?>
                <p class="field-error"><?= e($err) ?></p>
            <?php endif; ?>
        </div>
        <div class="field">
            <label for="smtp_port">Port</label>
            <input type="number" id="smtp_port" name="smtp_port" min="1" max="65535"
                   value="<?= e($v('smtp_port', '587')) ?>">
            <p class="field-hint">587 with TLS, or 465 with SSL.</p>
        </div>
        <div class="field">
            <label for="smtp_encryption">Encryption</label>
            <select id="smtp_encryption" name="smtp_encryption">
                <?php foreach ([
                    'tls'  => 'STARTTLS (port 587)',
                    'ssl'  => 'SSL/TLS (port 465)',
                    'none' => 'None — not recommended',
                ] as $key => $label): ?>
                    <option value="<?= e($key) ?>"
                        <?= (string) old('smtp_encryption', $mail['smtp_encryption'] ?? 'tls') === $key
                            ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="field-row">
        <div class="field">
            <label for="smtp_username">Username</label>
            <input type="text" id="smtp_username" name="smtp_username"
                   value="<?= e($v('smtp_username')) ?>" autocomplete="off">
            <p class="field-hint">Usually the full email address of the mailbox.</p>
        </div>
        <div class="field">
            <label for="smtp_password">Password</label>
            <input type="password" id="smtp_password" name="smtp_password" autocomplete="new-password"
                   placeholder="<?= $hasPassword ? 'Saved — leave blank to keep it' : '' ?>">
            <p class="field-hint">
                <?php if ($hasPassword): ?>
                    A password is saved and encrypted. Leave this blank to keep it; type a new one to
                    replace it. It is never displayed back, not even to you.
                <?php else: ?>
                    Encrypted with the key in <code>config/config.php</code> before it is stored, and
                    redacted from the audit log and from error output.
                <?php endif; ?>
            </p>
        </div>
    </div>

    <div class="field-check">
        <input type="hidden" name="smtp_verify_peer" value="0">
        <input type="checkbox" id="smtp_verify_peer" name="smtp_verify_peer" value="1"
               <?= ($resubmitted ? old('smtp_verify_peer') === '1' : ($mail['smtp_verify_peer'] ?? true))
                   ? 'checked' : '' ?>>
        <label for="smtp_verify_peer">Verify the mail server's certificate</label>
        <p class="field-hint">
            Leave this on. Turning it off means an attacker between this server and your mail host
            could read every message and capture the password above. The only real reason to disable
            it is a host using a self-signed certificate, and the better fix is to ask them to stop.
        </p>
    </div>

    <h3 class="form-section-title form-section-divided">Cost tracking</h3>

    <div class="field">
        <label for="cost_per_email_cents">Cost per email (cents)</label>
        <input type="number" id="cost_per_email_cents" name="cost_per_email_cents"
               step="0.001" min="0" max="100"
               value="<?= e($v('cost_per_email_cents', '0')) ?>">
        <p class="field-hint">
            What your provider charges per message, if anything. Used only to put a cost next to the
            revenue on campaign reports, so a campaign's return is a real number rather than a
            flattering one. Leave at 0 if sending is included in your hosting.
        </p>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn">Save email settings</button>
        <a class="btn btn-ghost" href="<?= e(url('/settings')) ?>">Back</a>
    </div>
</form>

<!-- ────────────────────────────── The test ────────────────────────────── -->
<section class="card">
    <div class="card-head">
        <div>
            <h2>Send a test</h2>
            <p class="card-sub">Save first &mdash; the test uses the settings as stored, not as typed.</p>
        </div>
        <span class="badge badge-muted">Driver: <?= e($drivers[$driver]['label'] ?? $driver) ?></span>
    </div>

    <form method="post" action="<?= e(url('/settings/mail/test')) ?>" class="form">
        <?= csrf_field() ?>
        <div class="field">
            <label for="test_to">Send a test message to</label>
            <input type="email" id="test_to" name="test_to" required
                   value="<?= e((string) (auth_user()['email'] ?? '')) ?>">
            <p class="field-hint">
                Your own address. If it does not arrive, check the spam folder before anything else
                &mdash; a first message from a new sending domain very often lands there, and the DNS
                records below are the fix.
            </p>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-ghost"><?= icon('megaphone', 16) ?> Send test</button>
        </div>
    </form>
</section>

<!-- ──────────────────────────── Deliverability ───────────────────────── -->
<section class="card">
    <div class="card-head">
        <div>
            <h2>Deliverability (DNS)</h2>
            <p class="card-sub">
                Three records that decide whether your mail reaches an inbox or a spam folder.
            </p>
        </div>
    </div>

    <p>
        Without these, mail sent as your domain is unauthenticated, and Gmail and Outlook treat
        unauthenticated mail from an unfamiliar domain exactly as you would expect. The shortcut on
        cPanel is <strong>Email Deliverability &rarr; Repair</strong>, which writes SPF and DKIM for
        you. If you are editing DNS by hand, these are the values.
    </p>

    <div class="table-wrap">
        <table class="table">
            <caption class="sr-only">DNS records to add for your domain</caption>
            <thead>
                <tr><th scope="col">Type</th><th scope="col">Name</th>
                    <th scope="col">Value</th><th scope="col">Why</th></tr>
            </thead>
            <tbody>
                <?php foreach ($dns as $record): ?>
                    <tr>
                        <td><code><?= e($record['type']) ?></code></td>
                        <td><code><?= e($record['name']) ?></code></td>
                        <td><code class="wrap"><?= e($record['value']) ?></code></td>
                        <td><?= e($record['note']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <p class="field-hint">
        DNS changes can take a few hours to propagate. Test again tomorrow before concluding that
        something is broken.
    </p>
</section>
