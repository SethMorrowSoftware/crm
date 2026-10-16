<?php

declare(strict_types=1);

/**
 * One campaign: who it reaches, what it will cost, and the send button.
 *
 * THE COST AND THE REACH ARE SHOWN BEFORE THE BUTTON, not after. A text costs
 * per segment and one emoji can triple that, so the estimate is computed from
 * the real body and a large send asks for a TYPED word rather than a second
 * click — a click is what somebody does by reflex.
 *
 * The gap between the segment's size and the campaign's reach is stated
 * plainly. "400 in the segment, 180 will get this, 220 have not opted in" is
 * the most useful sentence on the page: a venue that only ever saw 400 would
 * conclude the system was broken when 180 arrived.
 *
 * @var array<string,mixed>       $campaign
 * @var array<string,mixed>|null  $segment
 * @var array<string,mixed>       $audience
 * @var string                    $audienceLine
 * @var array<string,mixed>       $estimate
 * @var list<string>              $blockers
 * @var bool                      $started
 * @var bool                      $editable
 * @var string                    $duration
 * @var array<string,int>         $skipped
 * @var list<array<string,mixed>> $preview
 * @var int                       $previewLimit
 * @var string                    $confirmWord
 * @var bool                      $canManage
 * @var bool                      $canSend
 */

use App\Services\CampaignPlanner;

$id      = (int) $campaign['id'];
$status  = (string) $campaign['status'];
$channel = (string) $campaign['channel'];
$isSms   = $channel === 'sms';

?>
<p class="breadcrumbs">
    <a href="<?= e(url('/campaigns')) ?>">Campaigns</a> / <?= e((string) $campaign['name']) ?>
</p>

<div class="page-actions">
    <p class="page-lede">
        <span class="badge <?= $status === 'sent' ? 'badge-ok' : ($status === 'sending' ? 'badge-warn' : 'badge-muted') ?>">
            <?= e(ucfirst($status)) ?>
        </span>
        <?= e($isSms ? 'Text message' : 'Email') ?>
        <?php if ($segment !== null): ?>
            to <a href="<?= e(url('/segments/' . (int) $segment['id'])) ?>"><?= e((string) $segment['name']) ?></a>
        <?php endif; ?>
    </p>
    <?php if ($canManage && $editable): ?>
        <a class="btn btn-ghost" href="<?= e(url('/campaigns/' . $id . '/edit')) ?>">Edit</a>
    <?php endif; ?>
</div>

<?php if ($blockers !== []): ?>
    <div class="alert alert-warning">
        <?= icon('alert') ?>
        <div>
            <p><strong>Not ready to send:</strong></p>
            <ul>
                <?php foreach ($blockers as $blocker): ?>
                    <li><?= e($blocker) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>

<div class="stat-row">
    <div class="stat">
        <span class="stat-label"><?= $started ? 'Sent to' : 'Will reach' ?></span>
        <span class="stat-value"><?= e(number_format((int) $audience['reach'])) ?></span>
        <span class="stat-note">
            <?php if ($started): ?>
                <?= e(number_format((int) $campaign['sent_count'])) ?> sent so far.
            <?php else: ?>
                Of <?= e(number_format((int) $audience['total'])) ?> in the segment.
            <?php endif; ?>
        </span>
    </div>
    <div class="stat">
        <span class="stat-label"><?= $started ? 'Cost' : 'Estimated cost' ?></span>
        <span class="stat-value">
            <?= e(money($started
                ? (int) $campaign['actual_cost_cents']
                : (int) $estimate['total_cents'])) ?>
        </span>
        <span class="stat-note">
            <?php if ($isSms): ?>
                <?= e((string) $estimate['segments']) ?>
                <?= (int) $estimate['segments'] === 1 ? 'text' : 'texts' ?> each,
                <?= e($estimate['encoding'] === 'ucs2' ? 'Unicode' : 'GSM-7') ?>.
            <?php else: ?>
                One message each.
            <?php endif; ?>
        </span>
    </div>
    <?php if (!$started): ?>
        <div class="stat">
            <span class="stat-label">Will take</span>
            <span class="stat-value"><?= e($duration) ?></span>
            <span class="stat-note">
                Sent in batches of <?= e((string) CampaignPlanner::CHUNK) ?> a minute, so it
                does not all land at once.
            </span>
        </div>
    <?php endif; ?>
</div>

<?php if ($isSms && $estimate['encoding'] === 'ucs2'): ?>
    <div class="alert alert-warning">
        <?= icon('alert') ?>
        <p>
            <strong>This message uses Unicode characters</strong>, which cuts a text from 160
            characters to 70. A curly apostrophe or an emoji pasted in from a word processor is
            usually the cause, and it can double or triple what the send costs. Replacing them
            with plain equivalents is worth a minute.
        </p>
    </div>
<?php endif; ?>

<div class="grid-2">
    <div class="stack">
        <div class="card">
            <h2>Who gets this</h2>
            <p><?= e($audienceLine) ?></p>

            <?php if ($skipped !== []): ?>
                <h3>Not sent to</h3>
                <dl class="definition-list">
                    <?php foreach ($skipped as $reason => $count): ?>
                        <div>
                            <dt><?= e(CampaignPlanner::SKIP_REASONS[$reason] ?? ucfirst(str_replace('_', ' ', (string) $reason))) ?></dt>
                            <dd><?= e(number_format($count)) ?></dd>
                        </div>
                    <?php endforeach; ?>
                </dl>
                <p class="field-hint">
                    Each of these is recorded against the guest, so months from now the venue can
                    still answer &ldquo;why didn't I get that&rdquo;.
                </p>
            <?php endif; ?>

            <?php if ($preview !== []): ?>
                <h3>A few of them</h3>
                <ul class="inbox-list">
                    <?php foreach ($preview as $guest): ?>
                        <li>
                            <a href="<?= e(url('/contacts/' . (int) $guest['id'])) ?>">
                                <?= e(trim(
                                    (string) ($guest['first_name'] ?? '') . ' '
                                    . (string) ($guest['last_name'] ?? '')
                                ) ?: 'Unnamed guest') ?>
                            </a>
                            <span class="muted">
                                <?= e((string) ($isSms ? $guest['phone'] : $guest['email'])) ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ((int) $audience['reach'] > $previewLimit): ?>
                    <p class="field-hint">
                        The first <?= e((string) $previewLimit) ?> of
                        <?= e(number_format((int) $audience['reach'])) ?>.
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>The message</h2>
            <?php if (!$isSms && ($campaign['subject'] ?? '') !== ''): ?>
                <dl class="definition-list">
                    <div>
                        <dt>Subject</dt>
                        <dd><?= e((string) $campaign['subject']) ?></dd>
                    </div>
                </dl>
            <?php endif; ?>

            <?php if (trim((string) ($campaign['body'] ?? '')) === ''): ?>
                <p class="empty">Nothing written yet.</p>
            <?php else: ?>
                <div class="inbox-msg-body"><?= e((string) $campaign['body']) ?></div>
                <p class="field-hint">
                    Shown as written. Merge tags like <code>{{first_name}}</code> are filled in
                    per recipient when it sends.
                </p>
            <?php endif; ?>
        </div>
    </div>

    <aside class="stack">
        <?php if ($canSend && !$started): ?>
            <div class="card">
                <h2>Send a test first</h2>
                <p class="field-hint">
                    Goes to one address, carries no campaign id, and does not appear in the
                    figures below &mdash; so you can see the real thing arrive before anybody
                    else does.
                </p>
                <form method="post" action="<?= e(url('/campaigns/' . $id . '/test')) ?>" class="form">
                    <?= csrf_field() ?>
                    <div class="field">
                        <label for="to"><?= $isSms ? 'Mobile number' : 'Email address' ?></label>
                        <input type="text" id="to" name="to" maxlength="191" required
                               value="<?= e((string) old('to')) ?>">
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-ghost btn-sm">Send test</button>
                    </div>
                </form>
            </div>

            <div class="card">
                <h2>Send it</h2>

                <?php if ($blockers !== []): ?>
                    <p class="empty">Fix the points above first.</p>
                <?php else: ?>
                    <p class="field-hint">
                        This will send to <strong><?= e(number_format((int) $audience['reach'])) ?></strong>
                        people and cost about <strong><?= e(money((int) $estimate['total_cents'])) ?></strong>.
                        It cannot be undone, and a campaign can only be sent once.
                    </p>

                    <form method="post" action="<?= e(url('/campaigns/' . $id . '/send')) ?>" class="form">
                        <?= csrf_field() ?>

                        <?php if ($estimate['needs_confirmation']): ?>
                            <div class="field">
                                <label for="confirm">
                                    Type <?= e($confirmWord) ?> to confirm
                                </label>
                                <input type="text" id="confirm" name="confirm" autocomplete="off">
                                <p class="field-hint">
                                    Asked for because this is over
                                    <?= e(money(CampaignPlanner::CONFIRM_ABOVE_CENTS)) ?>. Typing
                                    a word is a decision; a second click is a reflex.
                                </p>
                            </div>
                        <?php endif; ?>

                        <div class="form-actions">
                            <button type="submit" class="btn">
                                Send to <?= e(number_format((int) $audience['reach'])) ?> people
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        <?php elseif (!$canSend && !$started): ?>
            <div class="card">
                <p class="empty">
                    You can build this campaign but not send it. An administrator can grant
                    that in Settings &rarr; Roles.
                </p>
            </div>
        <?php endif; ?>

        <?php if ($started): ?>
            <div class="card">
                <h2>How it is going</h2>
                <dl class="definition-list">
                    <div>
                        <dt>Sent</dt>
                        <dd><?= e(number_format((int) $campaign['sent_count'])) ?></dd>
                    </div>
                    <div>
                        <dt>Opened</dt>
                        <dd><?= e(number_format((int) $campaign['opened_count'])) ?></dd>
                    </div>
                    <div>
                        <dt>Clicked</dt>
                        <dd><?= e(number_format((int) $campaign['clicked_count'])) ?></dd>
                    </div>
                    <div>
                        <dt>Bounced</dt>
                        <dd><?= e(number_format((int) $campaign['bounced_count'])) ?></dd>
                    </div>
                    <div>
                        <dt>Failed</dt>
                        <dd><?= e(number_format((int) $campaign['failed_count'])) ?></dd>
                    </div>
                    <div>
                        <dt>Estimated</dt>
                        <dd><?= e(money((int) $campaign['estimated_cost_cents'])) ?></dd>
                    </div>
                    <div>
                        <dt>Actually cost</dt>
                        <dd><?= e(money((int) $campaign['actual_cost_cents'])) ?></dd>
                    </div>
                </dl>
                <p class="field-hint">
                    The estimate and the real figure differ whenever recipients were skipped.
                    The real one is what reconciles against the provider's invoice.
                </p>
            </div>

            <?php if ($canSend && $status === 'sending'): ?>
                <div class="card">
                    <h2>Pause</h2>
                    <p class="field-hint">
                        Stops after the batch in flight. Nobody already sent to is affected.
                    </p>
                    <form method="post" action="<?= e(url('/campaigns/' . $id . '/pause')) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-ghost btn-sm">Pause sending</button>
                    </form>
                </div>
            <?php elseif ($canSend && $status === 'paused'): ?>
                <div class="card">
                    <h2>Resume</h2>
                    <form method="post" action="<?= e(url('/campaigns/' . $id . '/resume')) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm">Resume sending</button>
                    </form>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="card">
            <h2>What still applies to every recipient</h2>
            <p class="field-hint">
                A campaign uses the same send path as a reply typed in the inbox, one call per
                person. So consent, the unsubscribe list, do-not-contact, the weekly message cap,
                quiet hours and Safe Mode all apply individually &mdash; a campaign cannot reach
                somebody a single message could not, and the segment cannot grant permission the
                send path refuses.
            </p>
        </div>
    </aside>
</div>
