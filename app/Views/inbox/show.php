<?php

declare(strict_types=1);

/**
 * One conversation.
 *
 * THE COMPOSER IS DRAWN FROM THE SEND PATH'S OWN ANSWER, not from a guess
 * made here. `$composer['can_send']` was decided by asking Core/Sms what it
 * would do, so a guest who texted STOP produces a disabled box with the real
 * reason beside it rather than a reply somebody types and loses on submit.
 * That is §8.6's "an agent physically cannot text a contact who replied STOP",
 * and it is the one thing on this page worth being careful about.
 *
 * @var array<string,mixed>       $conversation
 * @var list<array<string,mixed>> $messages
 * @var bool                      $earlier
 * @var array<string,mixed>|null  $contact
 * @var string                    $replyChannel
 * @var array<string,mixed>       $composer
 * @var list<array<string,mixed>> $canned
 * @var list<array<string,mixed>> $assignees
 * @var array<string,string>      $snoozeOptions
 * @var string|null               $responseLabel
 * @var list<array<string,mixed>> $bookings
 * @var callable                  $fmtWhen
 * @var callable                  $fmtTime
 */

$id     = (int) $conversation['id'];
$status = (string) $conversation['status'];

$canReply  = can('inbox_reply');
$canManage = can('inbox_assign');

?>
<p class="breadcrumbs">
    <a href="<?= e(url('/inbox')) ?>">Inbox</a> / <?= e((string) $conversation['display_name']) ?>
</p>

<div class="page-actions">
    <p class="page-lede">
            <?= e(ucfirst((string) $conversation['channel'])) ?>
            <?php if ($conversation['subject'] !== null && $conversation['subject'] !== ''): ?>
                &middot; <?= e((string) $conversation['subject']) ?>
            <?php endif; ?>
            <?php if ($responseLabel !== null): ?>
                &middot; first answered in <?= e($responseLabel) ?>
            <?php elseif ($status !== 'closed'): ?>
                &middot; <span class="badge badge-warn">No reply yet</span>
            <?php endif; ?>
    </p>

    <?php if ($canManage): ?>
        <div class="row-actions">
            <?php if ($status === 'closed'): ?>
                <form method="post" action="<?= e(url('/inbox/' . $id . '/reopen')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm">Reopen</button>
                </form>
            <?php else: ?>
                <form method="post" action="<?= e(url('/inbox/' . $id . '/close')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm">Close</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<div class="grid-2">
    <div class="stack">
        <?php if ($earlier): ?>
            <p class="empty">
                Only the most recent messages are shown. Earlier ones are on the guest's record.
            </p>
        <?php endif; ?>

        <ol class="inbox-thread">
            <?php foreach ($messages as $message): ?>
                <?php
                $inbound  = (string) $message['direction'] === 'inbound';
                $internal = (string) $message['channel'] === 'internal';
                $body     = (string) ($message['body_text'] ?? $message['body'] ?? '');
                $failed   = in_array((string) $message['status'], ['failed', 'bounced', 'undelivered'], true);
                ?>
                <li class="inbox-msg<?= $inbound ? ' is-inbound' : '' ?><?= $internal ? ' is-note' : '' ?>">
                    <div class="inbox-msg-meta">
                        <strong>
                            <?php if ($internal): ?>
                                Note from <?= e((string) ($message['author_name'] ?? 'staff')) ?>
                            <?php elseif ($inbound): ?>
                                <?= e((string) $conversation['display_name']) ?>
                            <?php else: ?>
                                <?= e((string) ($message['author_name'] ?? 'The venue')) ?>
                            <?php endif; ?>
                        </strong>
                        <span class="muted">
                            <?= e($fmtTime($message['created_at'] ?? null)) ?>
                            <?php if (!$internal): ?>
                                &middot; <?= e(ucfirst((string) $message['channel'])) ?>
                            <?php endif; ?>
                            <?php if ((int) ($message['segments'] ?? 0) > 1): ?>
                                &middot; <?= e((string) (int) $message['segments']) ?> texts
                            <?php endif; ?>
                        </span>
                        <?php if ($failed): ?>
                            <span class="badge badge-bad">
                                <?= e(ucfirst((string) $message['status'])) ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="inbox-msg-body"><?= nl2br(e($body)) ?></div>

                    <?php if ($failed && ($message['error_message'] ?? '') !== ''): ?>
                        <p class="field-error"><?= e((string) $message['error_message']) ?></p>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>

        <?php if (!$canReply): ?>
            <div class="card">
                <p class="empty">
                    You can read this conversation but not reply to it. An administrator can grant
                    that in Settings &rarr; Roles.
                </p>
            </div>
        <?php else: ?>
            <div class="card">
                <h2>Reply</h2>

                <?php if (!$composer['can_send'] && $replyChannel !== 'internal'): ?>
                    <?php
                    /*
                     * THE REFUSAL, IN THE SEND PATH'S OWN WORDS, BEFORE
                     * ANYTHING IS TYPED. The reply box for the guest's channel
                     * is not drawn at all — only the internal note, which
                     * sends nothing and so is always allowed.
                     */
                    ?>
                    <div class="alert alert-warning">
                        <?= icon('alert') ?>
                        <p><?= e((string) $composer['explanation']) ?></p>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?= e(url('/inbox/' . $id . '/reply')) ?>" class="form">
                    <?= csrf_field() ?>

                    <div class="field">
                        <label for="channel">Send as</label>
                        <select id="channel" name="channel">
                            <?php if ($composer['can_send']): ?>
                                <option value="<?= e($replyChannel) ?>" selected>
                                    <?= e($replyChannel === 'sms' ? 'Text message' : 'Email') ?>
                                </option>
                            <?php endif; ?>
                            <option value="internal" <?= $composer['can_send'] ? '' : 'selected' ?>>
                                Internal note (not sent to the guest)
                            </option>
                        </select>
                    </div>

                    <?php if ($canned !== []): ?>
                        <div class="field">
                            <label for="canned">Canned replies</label>
                            <select id="canned" name="canned_preview">
                                <option value="">Choose one to see its wording</option>
                                <?php foreach ($canned as $reply): ?>
                                    <option value="<?= e((string) $reply['id']) ?>">
                                        <?= e((string) $reply['title']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="field-hint">
                                Pick one and its wording is shown below to copy. Merge tags like
                                <code>{{first_name}}</code> are filled in when it is sent.
                            </p>
                        </div>

                        <ul class="inbox-list inbox-canned">
                            <?php foreach ($canned as $reply): ?>
                                <li>
                                    <strong><?= e((string) $reply['title']) ?></strong>
                                    <span class="muted"><?= e((string) $reply['body']) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <div class="field">
                        <label for="body">Message</label>
                        <textarea id="body" name="body" rows="5" required
                                  maxlength="5000"><?= e((string) old('body')) ?></textarea>
                        <?php if ($message = error_for('body')): ?>
                            <p class="field-error"><?= e($message) ?></p>
                        <?php endif; ?>
                        <?php if ($replyChannel === 'sms' && $composer['can_send']): ?>
                            <p class="field-hint">
                                A text is <?= e((string) (int) $composer['limit']) ?> characters per
                                message. Longer replies are sent as several and cost accordingly.
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn">Send</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <aside class="stack">
        <?php if ($contact !== null): ?>
            <div class="card">
                <h2>Guest</h2>
                <dl class="definition-list">
                    <div>
                        <dt>Name</dt>
                        <dd>
                            <a href="<?= e(url('/contacts/' . (int) $contact['id'])) ?>">
                                <?= e(trim(
                                    (string) ($contact['first_name'] ?? '') . ' '
                                    . (string) ($contact['last_name'] ?? '')
                                ) ?: 'Unnamed') ?>
                            </a>
                        </dd>
                    </div>
                    <?php if (($contact['email'] ?? '') !== ''): ?>
                        <div>
                            <dt>Email</dt>
                            <dd><?= e((string) $contact['email']) ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php if (($contact['phone'] ?? '') !== ''): ?>
                        <div>
                            <dt>Phone</dt>
                            <dd><?= e((string) $contact['phone']) ?></dd>
                        </div>
                    <?php endif; ?>
                    <div>
                        <dt>Marketing</dt>
                        <dd>
                            <?php if ((int) ($contact['do_not_contact'] ?? 0) === 1): ?>
                                <span class="badge badge-bad">Do not contact</span>
                            <?php else: ?>
                                <?= (int) ($contact['email_consent'] ?? 0) === 1
                                    ? '<span class="badge badge-ok">Email</span>'
                                    : '' ?>
                                <?= (int) ($contact['sms_consent'] ?? 0) === 1
                                    ? '<span class="badge badge-ok">Text</span>'
                                    : '' ?>
                                <?php if ((int) ($contact['email_consent'] ?? 0) === 0
                                    && (int) ($contact['sms_consent'] ?? 0) === 0): ?>
                                    <span class="muted">No marketing consent</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </dd>
                    </div>
                </dl>
            </div>
        <?php else: ?>
            <div class="card">
                <p class="empty">This conversation is not linked to a guest.</p>
            </div>
        <?php endif; ?>

        <?php if ($canManage): ?>
            <div class="card">
                <h2>Assign</h2>
                <form method="post" action="<?= e(url('/inbox/' . $id . '/assign')) ?>" class="form">
                    <?= csrf_field() ?>
                    <div class="field">
                        <label for="assigned_to">Owner</label>
                        <select id="assigned_to" name="assigned_to">
                            <option value="0">Nobody</option>
                            <?php foreach ($assignees as $user): ?>
                                <option value="<?= e((string) $user['id']) ?>"
                                    <?= (int) ($conversation['assigned_to'] ?? 0) === (int) $user['id'] ? 'selected' : '' ?>>
                                    <?= e((string) $user['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="field-hint">
                            Only people who can reply are listed &mdash; handing a question to
                            somebody who cannot answer it just hides it.
                        </p>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-sm">Assign</button>
                    </div>
                </form>
            </div>

            <div class="card">
                <h2>Snooze</h2>
                <?php if ($status === 'snoozed' && $conversation['snoozed_until'] !== null): ?>
                    <p class="field-hint">
                        Back in the queue at <?= e($fmtTime($conversation['snoozed_until'])) ?>.
                    </p>
                <?php endif; ?>
                <form method="post" action="<?= e(url('/inbox/' . $id . '/snooze')) ?>" class="form">
                    <?= csrf_field() ?>
                    <div class="field">
                        <label for="option">Come back</label>
                        <select id="option" name="option">
                            <?php foreach ($snoozeOptions as $key => $label): ?>
                                <option value="<?= e($key) ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                            <option value="custom">At a time I choose</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="until">If choosing a time</label>
                        <input type="datetime-local" id="until" name="until">
                        <p class="field-hint">The venue's own clock, and it must be in the future.</p>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-sm">Snooze</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($bookings !== []): ?>
            <div class="card">
                <h2>Their parties</h2>
                <ul class="inbox-list">
                    <?php foreach ($bookings as $booking): ?>
                        <li>
                            <a href="<?= e(url('/bookings/' . (int) $booking['id'])) ?>">
                                <?= e((string) $booking['booking_number']) ?>
                            </a>
                            <span class="muted">
                                <?= e(local_date((string) $booking['event_date'])) ?>
                                &middot; <?= e(ucfirst(str_replace('_', ' ', (string) $booking['status']))) ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </aside>
</div>
