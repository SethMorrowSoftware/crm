<?php

declare(strict_types=1);

/**
 * One submission, in full.
 *
 * The answers are rendered from the copy stored WITH the submission, not from
 * the form's current field list, so an enquiry from last season still shows
 * the questions it was actually answering.
 *
 * @var array<string,mixed>       $form
 * @var array<string,mixed>       $row
 * @var list<array<string,mixed>> $consentLog
 */

$utm = json_decode((string) ($row['utm'] ?? ''), true);
$utm = is_array($utm) ? $utm : [];

?>
<p class="breadcrumbs"><a href="<?= e(url('/forms')) ?>">Web forms</a> /
    <a href="<?= e(url('/forms/' . (int) $form['id'] . '/submissions')) ?>"><?= e($form['name']) ?></a> /
    Submission #<?= (int) $row['id'] ?></p>

<?php if ((int) $row['is_spam'] === 1): ?>
    <div class="alert alert-warning">
        <?= icon('alert') ?>
        <p>
            The honeypot caught this one, so no contact was created and no consent was recorded.
            It is kept here in case it turns out to be a real enquiry.
        </p>
    </div>
<?php endif; ?>

<section class="card">
    <div class="card-head">
        <h2>Answers</h2>
        <p class="card-sub">Received <?= e(local_time((string) $row['created_at'])) ?></p>
    </div>

    <?php if ($row['answers'] === []): ?>
        <p class="empty">This submission carries no answers.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <caption class="sr-only">Answers</caption>
                <thead>
                    <tr>
                        <th scope="col">Question</th>
                        <th scope="col">Answer</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($row['answers'] as $answer): ?>
                    <tr>
                        <th scope="row"><?= e($answer['label']) ?></th>
                        <td>
                            <?= $answer['value'] === ''
                                ? '<span class="muted">Left blank</span>'
                                : nl2br(e($answer['value'])) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card-head">
        <h2>The contact behind it</h2>
    </div>

    <?php if ($row['contact_id'] === null): ?>
        <p class="empty">
            No contact. The submission carried neither an email address nor a phone number, so
            there was nothing to identify or reach a guest by — every answer above is still kept.
        </p>
    <?php else: ?>
        <dl class="definition-list">
            <dt>Name</dt>
            <dd><?= e(trim(
                (string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')
            ) ?: '—') ?></dd>
            <dt>Email</dt>
            <dd><?= e((string) ($row['contact_email'] ?? '') ?: '—') ?></dd>
            <dt>Contact record</dt>
            <dd>#<?= (int) $row['contact_id'] ?></dd>
        </dl>
        <p class="field-hint">
            A submission fills in blank contact fields only. Anything the guest typed that
            disagreed with what the record already held was kept out of it — the audit log entry
            for this submission names which fields those were.
        </p>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card-head">
        <h2>Consent</h2>
        <p class="card-sub">The last 20 consent events for this contact, newest first.</p>
    </div>

    <?php if ($consentLog === []): ?>
        <p class="empty">
            No consent has ever been recorded for this contact. An enquiry is not permission to
            market — only a ticked opt-in box is.
        </p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <caption class="sr-only">What happened to this submission</caption>
                <thead>
                    <tr>
                        <th scope="col">When</th>
                        <th scope="col">Channel</th>
                        <th scope="col">What</th>
                        <th scope="col">Source</th>
                        <th scope="col">Wording they were shown</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($consentLog as $event): ?>
                    <?php
                    $evidence   = json_decode((string) ($event['evidence'] ?? ''), true);
                    $disclosure = is_array($evidence) ? (string) ($evidence['disclosure'] ?? '') : '';
                    ?>
                    <tr>
                        <td><?= e(local_time((string) $event['created_at'])) ?></td>
                        <td><?= e($event['channel']) ?></td>
                        <td>
                            <?php if ((string) $event['action'] === 'granted'): ?>
                                <span class="badge badge-ok">Granted</span>
                            <?php else: ?>
                                <span class="badge badge-bad">Revoked</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($event['source']) ?></td>
                        <td><?= $disclosure === '' ? '<span class="muted">—</span>' : e($disclosure) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card-head">
        <h2>Where it came from</h2>
    </div>
    <dl class="definition-list">
        <dt>IP address</dt>
        <dd><?= e((string) ($row['ip'] ?? '') ?: '—') ?></dd>
        <dt>Referring page</dt>
        <dd><?= e((string) ($row['referrer'] ?? '') ?: '—') ?></dd>
        <dt>Browser</dt>
        <dd><?= e(str_truncate((string) ($row['user_agent'] ?? ''), 120) ?: '—') ?></dd>
        <dt>Campaign</dt>
        <dd>
            <?php if ($utm === []): ?>
                —
            <?php else: ?>
                <?php foreach ($utm as $utmKey => $utmValue): ?>
                    <?= e((string) $utmKey) ?>: <?= e((string) $utmValue) ?><br>
                <?php endforeach; ?>
            <?php endif; ?>
        </dd>
    </dl>
</section>
