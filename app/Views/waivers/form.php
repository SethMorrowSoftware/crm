<?php

declare(strict_types=1);

/**
 * Writing a waiver version.
 *
 * Only ever reached for a DRAFT: the controller refuses to open this for a
 * version that has been published, because its wording is what guests have
 * already agreed to.
 *
 * @var array<string,mixed>|null $waiver
 */

$editing = $waiver !== null;
$action  = $editing ? url('/waivers/' . (int) $waiver['id']) : url('/waivers');

$value = static function (string $field, string $fallback = '') use ($waiver): string {
    $old = old($field);

    if ($old !== '' && $old !== null) {
        return is_scalar($old) ? (string) $old : '';
    }

    return (string) ($waiver[$field] ?? $fallback);
};

?>
<p class="breadcrumbs">
    <a href="<?= e(url('/waivers')) ?>">Waivers</a> /
    <?= $editing ? e((string) $waiver['version']) : 'New' ?>
</p>

<form method="post" action="<?= e($action) ?>" class="form">
    <?= csrf_field() ?>

    <div class="card">
        <div class="field">
            <label for="version">Version</label>
            <input type="text" id="version" name="version" maxlength="20" required
                   value="<?= e($value('version', date('Y') . '.1')) ?>">
            <p class="field-hint">
                Your own label for this wording — a date, a number, whatever the venue's lawyer
                refers to. It has to be unique, and it is what a signature records.
            </p>
        </div>

        <div class="field">
            <label for="title">Heading guests see</label>
            <input type="text" id="title" name="title" maxlength="191" required
                   value="<?= e($value('title', 'Participation waiver')) ?>">
        </div>

        <div class="field">
            <label for="body">The waiver text</label>
            <textarea id="body" name="body" rows="18" required><?= e($value('body')) ?></textarea>
            <p class="field-hint">
                Paste the wording the venue's own lawyer has approved. It is shown to guests
                exactly as typed, as plain text — no HTML is interpreted, so nothing in here can
                change the page around it.
            </p>
        </div>

        <div class="field field-check">
            <input type="checkbox" id="requires_guardian" name="requires_guardian" value="1"
                <?= $value('requires_guardian', '1') === '1' ? 'checked' : '' ?>>
            <label for="requires_guardian">
                A child needs a parent or guardian to sign for them
            </label>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn"><?= $editing ? 'Save the draft' : 'Save as a draft' ?></button>
        <a class="btn btn-ghost"
           href="<?= e(url($editing ? '/waivers/' . (int) $waiver['id'] : '/waivers')) ?>">Cancel</a>
    </div>
</form>
