<?php

declare(strict_types=1);

/**
 * The campaign editor.
 *
 * @var array<string,mixed>|null  $campaign
 * @var list<array<string,mixed>> $segments
 * @var list<string>              $channels
 */

use App\Services\SmsPolicy;

$isNew  = $campaign === null;
$action = $isNew ? '/campaigns' : '/campaigns/' . (int) $campaign['id'];

$value = static fn (string $key, mixed $fallback = ''): string => (string) old(
    $key,
    $campaign[$key] ?? $fallback
);

/** A stored TIME column reads back as "09:00:00"; the input wants "09:00". */
$timeValue = static function (string $key) use ($campaign): string {
    $stored = (string) old($key, $campaign[$key] ?? '');

    return $stored === '' ? '' : substr($stored, 0, 5);
};

?>
<p class="breadcrumbs">
    <a href="<?= e(url('/campaigns')) ?>">Campaigns</a> /
    <?= $isNew ? 'New' : e((string) $campaign['name']) ?>
</p>

<form method="post" action="<?= e(url($action)) ?>" class="form">
    <?= csrf_field() ?>

    <div class="card">
        <div class="field">
            <label for="name">Name</label>
            <input type="text" id="name" name="name" maxlength="120" required
                   value="<?= e($value('name')) ?>">
            <p class="field-hint">For the venue's own records; guests never see it.</p>
        </div>

        <div class="field">
            <label for="channel">Send as</label>
            <select id="channel" name="channel" required>
                <?php foreach ($channels as $option): ?>
                    <option value="<?= e($option) ?>"
                        <?= $value('channel', 'email') === $option ? 'selected' : '' ?>>
                        <?= e($option === 'sms' ? 'Text message' : 'Email') ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-hint">
                A text costs per message of 160 characters &mdash; 70 if it contains a curly
                apostrophe or an emoji. The cost is worked out from the real wording before you
                send.
            </p>
        </div>

        <div class="field">
            <label for="segment_id">Send to</label>
            <select id="segment_id" name="segment_id">
                <option value="0">&mdash; choose a segment &mdash;</option>
                <?php foreach ($segments as $segment): ?>
                    <option value="<?= e((string) $segment['id']) ?>"
                        <?= (int) $value('segment_id', 0) === (int) $segment['id'] ? 'selected' : '' ?>>
                        <?= e((string) $segment['name']) ?>
                        <?php if ($segment['cached_count'] !== null): ?>
                            (<?= e(number_format((int) $segment['cached_count'])) ?> at last count)
                        <?php endif; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-hint">
                <?php if ($segments === []): ?>
                    No segments yet &mdash; <a href="<?= e(url('/segments/new')) ?>">build one</a> first.
                <?php else: ?>
                    A segment selects people; it does not grant permission to contact them. The
                    campaign page shows how many of them can actually be reached.
                <?php endif; ?>
            </p>
        </div>
    </div>

    <div class="card">
        <h2>The message</h2>

        <div class="field">
            <label for="subject">Subject (email only)</label>
            <input type="text" id="subject" name="subject" maxlength="255"
                   value="<?= e($value('subject')) ?>">
            <p class="field-hint">
                A bulk email with no subject is filtered as spam by most providers, so one is
                required before an email campaign can send.
            </p>
        </div>

        <div class="field">
            <label for="body">Message</label>
            <textarea id="body" name="body" rows="10" maxlength="20000"><?= e($value('body')) ?></textarea>
            <p class="field-hint">
                Merge tags: <code>{{first_name}}</code>, <code>{{last_name}}</code>,
                <code>{{full_name}}</code>, <code>{{venue_name}}</code>,
                <code>{{venue_phone}}</code>. An unnamed guest gets &ldquo;there&rdquo;, so
                nobody is greeted as nobody.
            </p>
            <p class="field-hint">
                <strong>A bulk text must say how to stop</strong> &mdash; include
                &ldquo;Reply STOP to opt out&rdquo;. An email does not need those words: one
                carries an unsubscribe header and footer automatically.
                A single text holds <?= e((string) SmsPolicy::GSM_SINGLE) ?> plain characters.
            </p>
        </div>
    </div>

    <div class="card">
        <h2>When it may send</h2>

        <div class="field">
            <label for="send_window_start">Not before</label>
            <input type="time" id="send_window_start" name="send_window_start"
                   value="<?= e($timeValue('send_window_start')) ?>">
        </div>

        <div class="field">
            <label for="send_window_end">Not after</label>
            <input type="time" id="send_window_end" name="send_window_end"
                   value="<?= e($timeValue('send_window_end')) ?>">
        </div>

        <p class="field-hint">
            The venue's own clock. Outside the window the campaign <strong>waits</strong> rather
            than failing &mdash; a message nobody sends is worse than one sent an hour late.
            Set both or neither: half a window would either stop all sending or allow all of it,
            and neither would say so.
        </p>
    </div>

    <div class="card">
        <h2>Who it comes from</h2>

        <div class="field">
            <label for="from_name">From name (optional)</label>
            <input type="text" id="from_name" name="from_name" maxlength="120"
                   value="<?= e($value('from_name')) ?>">
        </div>

        <div class="field">
            <label for="reply_to">Reply-to (optional)</label>
            <input type="email" id="reply_to" name="reply_to" maxlength="191"
                   value="<?= e($value('reply_to')) ?>">
            <p class="field-hint">
                Replies to a campaign are real questions from real guests. Point this at an
                address somebody reads.
            </p>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn">Save campaign</button>
        <a class="btn btn-ghost"
           href="<?= e(url($isNew ? '/campaigns' : '/campaigns/' . (int) $campaign['id'])) ?>">Cancel</a>
    </div>
</form>
