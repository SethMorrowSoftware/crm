<?php

declare(strict_types=1);

/**
 * Edit one template, with what it looks like beside it.
 *
 * @var array<string,mixed>                       $template
 * @var list<string>                              $tags
 * @var int                                       $usedBy
 * @var int                                       $liveUse
 * @var array{describe:string,optOut:bool}|null   $sms
 */

$id    = (int) $template['id'];
$email = (string) $template['channel'] === 'email';

$value = static function (string $field) use ($template): string {
    $old = old($field);

    if ($old !== '' && $old !== null) {
        return is_scalar($old) ? (string) $old : '';
    }

    return (string) ($template[$field] ?? '');
};

$error = static function (string $field): string {
    $message = error_for($field);

    return $message === null ? '' : '<p class="field-error">' . e($message) . '</p>';
};
?>
<p class="breadcrumbs">
    <a href="<?= e(url('/settings')) ?>">Settings</a> /
    <a href="<?= e(url('/settings/templates')) ?>">Message templates</a> /
    <?= e((string) $template['name']) ?>
</p>

<div class="template-layout">
    <form method="post" action="<?= e(url('/settings/templates/' . $id)) ?>" class="form card" id="template-form">
        <?= csrf_field() ?>

        <div class="card-head">
            <div>
                <h2><?= $email ? 'Email' : 'Text message' ?></h2>
                <p class="card-sub">
                    <?php if ($usedBy > 0): ?>
                        Sent by <?= $usedBy ?> automation step<?= $usedBy === 1 ? '' : 's' ?><?= $liveUse > 0 ? ', ' . $liveUse . ' of them switched on' : '' ?>.
                    <?php else: ?>
                        No automation sends this yet.
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <div class="field">
            <label for="name">Name</label>
            <input type="text" id="name" name="name" maxlength="120" required value="<?= e($value('name')) ?>">
            <p class="field-hint">For staff only. Guests never see it.</p>
            <?= $error('name') ?>
        </div>

        <?php if ($email): ?>
            <div class="field">
                <label for="subject">Subject</label>
                <input type="text" id="subject" name="subject" maxlength="255" required value="<?= e($value('subject')) ?>">
                <?= $error('subject') ?>
            </div>

            <div class="field">
                <label for="preheader">Preview text (optional)</label>
                <input type="text" id="preheader" name="preheader" maxlength="255" value="<?= e($value('preheader')) ?>">
                <p class="field-hint">The grey line an inbox shows after the subject.</p>
            </div>
        <?php endif; ?>

        <div class="field">
            <label for="body"><?= $email ? 'Message (HTML)' : 'Message' ?></label>
            <textarea id="body" name="body" rows="<?= $email ? 22 : 6 ?>" required
                      class="<?= $email ? 'code-input' : '' ?>" spellcheck="<?= $email ? 'false' : 'true' ?>"><?= e($value('body')) ?></textarea>
            <?php if ($sms !== null): ?>
                <p class="field-hint"><?= e($sms['describe']) ?>, with the sample details filled in.</p>
                <?php if (!$sms['optOut']): ?>
                    <p class="field-hint">This text does not tell the guest how to stop. A marketing text must
                        (&ldquo;Reply STOP to opt out&rdquo;); a reply to something they asked for need not.</p>
                <?php endif; ?>
            <?php endif; ?>
            <?= $error('body') ?>
        </div>

        <div class="field field-check">
            <label>
                <input type="checkbox" name="is_active" value="1" <?= (int) $template['is_active'] === 1 ? 'checked' : '' ?>
                    <?= (int) $template['is_system'] === 1 ? 'disabled' : '' ?>>
                In use — automations and the system can send it
            </label>
            <?php if ((int) $template['is_system'] === 1): ?>
                <input type="hidden" name="is_active" value="1">
                <p class="field-hint">One of the system’s own messages, so it is always on.</p>
            <?php endif; ?>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn">Save</button>
            <button type="submit" class="btn btn-ghost" formaction="<?= e(url('/settings/templates/' . $id . '/preview')) ?>"
                    formtarget="_blank">Preview this draft</button>
            <a class="btn btn-ghost" href="<?= e(url('/settings/templates')) ?>">Back</a>
        </div>
    </form>

    <aside class="template-side">
        <section class="card">
            <div class="card-head"><div><h2>As a guest sees it</h2><p class="card-sub">Saved version, with sample details.</p></div></div>
            <iframe class="template-preview<?= $email ? '' : ' template-preview-sms' ?>" title="Preview of <?= e((string) $template['name']) ?>"
                    sandbox src="<?= e(url('/settings/templates/' . $id . '/preview')) ?>"></iframe>
        </section>

        <section class="card">
            <div class="card-head"><div><h2>Details you can use</h2></div></div>
            <p class="field-hint">Type one with double braces, like <code>{{first_name}}</code>. Anything else comes out blank.</p>
            <ul class="tag-list">
                <?php foreach ($tags as $tag): ?>
                    <li><code>{{<?= e($tag) ?>}}</code></li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section class="card">
            <div class="card-head"><div><h2>More</h2></div></div>
            <?php if ($email): ?>
                <form method="post" action="<?= e(url('/settings/templates/' . $id . '/test')) ?>" class="inline-form">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost btn-sm">Email me a test</button>
                </form>
            <?php endif; ?>
            <form method="post" action="<?= e(url('/settings/templates/' . $id . '/duplicate')) ?>" class="inline-form">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-ghost btn-sm">Make a copy to start a new one</button>
            </form>
        </section>
    </aside>
</div>
