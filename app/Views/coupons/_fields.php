<?php

declare(strict_types=1);

/**
 * The offer fields, shared by "add" and "edit" so the two cannot drift.
 *
 * @var array<string,mixed>    $c       The offer row; empty for a new one.
 * @var array<string,string>   $types
 * @var string                 $f       Id suffix, so two forms on a page do not collide.
 */

$type = (string) ($c['type'] ?? 'percent');
?>
<div class="field-row">
    <div class="field">
        <label for="name-<?= e($f) ?>">Name</label>
        <input type="text" id="name-<?= e($f) ?>" name="name" maxlength="120" required
               value="<?= e((string) ($c['name'] ?? '')) ?>" placeholder="Fall weekend 20% off">
        <p class="field-hint">For staff. The guest sees the code and whatever the message says.</p>
    </div>

    <div class="field">
        <label for="type-<?= e($f) ?>">Kind of offer</label>
        <select id="type-<?= e($f) ?>" name="type">
            <?php foreach ($types as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $type === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="field">
        <label for="value-<?= e($f) ?>">Value</label>
        <input type="text" id="value-<?= e($f) ?>" name="value" inputmode="decimal"
               value="<?= e(isset($c['value']) && (float) $c['value'] > 0 ? rtrim(rtrim(number_format((float) $c['value'], 2, '.', ''), '0'), '.') : '') ?>">
        <p class="field-hint">A percentage for percent off, dollars for dollars off. Ignored for the other two.</p>
    </div>
</div>

<div class="field-row">
    <div class="field">
        <label for="min-<?= e($f) ?>">Minimum spend (optional)</label>
        <input type="text" id="min-<?= e($f) ?>" name="min_spend" inputmode="decimal"
               value="<?= e(isset($c['min_spend_cents']) && (int) $c['min_spend_cents'] > 0 ? number_format((int) $c['min_spend_cents'] / 100, 2, '.', '') : '') ?>">
    </div>

    <div class="field">
        <label for="per-<?= e($f) ?>">Uses per guest</label>
        <input type="number" id="per-<?= e($f) ?>" name="max_per_contact" min="1" max="1000" required
               value="<?= e((string) ($c['max_per_contact'] ?? 1)) ?>">
    </div>

    <div class="field">
        <label for="total-<?= e($f) ?>">Total uses (optional)</label>
        <input type="number" id="total-<?= e($f) ?>" name="max_redemptions" min="1"
               value="<?= e((string) ($c['max_redemptions'] ?? '')) ?>">
        <p class="field-hint">Blank for no limit.</p>
    </div>
</div>

<div class="field-row">
    <div class="field">
        <label for="starts-<?= e($f) ?>">First day (optional)</label>
        <input type="date" id="starts-<?= e($f) ?>" name="starts_on"
               value="<?= e(($c['starts_at'] ?? null) ? local_date((string) $c['starts_at'], 'Y-m-d') : '') ?>">
    </div>

    <div class="field">
        <label for="expires-<?= e($f) ?>">Last day (optional)</label>
        <input type="date" id="expires-<?= e($f) ?>" name="expires_on"
               value="<?= e(($c['expires_at'] ?? null) ? local_date((string) $c['expires_at'], 'Y-m-d') : '') ?>">
        <p class="field-hint">Good until the end of that day, here.</p>
    </div>
</div>

<div class="field field-check">
    <label>
        <input type="checkbox" name="is_active" value="1" <?= ($c === [] || (int) ($c['is_active'] ?? 0) === 1) ? 'checked' : '' ?>>
        Active — automations can hand it out and the desk can redeem it
    </label>
</div>
