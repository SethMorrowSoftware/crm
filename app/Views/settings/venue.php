<?php

declare(strict_types=1);

/**
 * @var array<string,mixed> $venue
 * @var list<string>        $timezones
 */

$v = static fn (string $key, string $default = ''): string => (string) (old($key) ?: ($venue[$key] ?? $default));
?>
<p class="page-lede">
    These appear on quotes, in message footers, and in the legal text every bulk email must carry.
</p>

<form method="post" action="<?= e(url('/settings/venue')) ?>" class="card form">
    <?= csrf_field() ?>

    <div class="field">
        <label for="name">Venue name</label>
        <input type="text" id="name" name="name" value="<?= e($v('name', 'The Castle Fun Center')) ?>" required>
        <?php if ($err = error_for('name')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
    </div>

    <div class="field-row">
        <div class="field">
            <label for="phone">Main phone</label>
            <input type="text" id="phone" name="phone" value="<?= e($v('phone_display') ?: $v('phone')) ?>">
            <p class="field-hint">Shown when a booking falls inside the call-us window.</p>
        </div>
        <div class="field">
            <label for="email">Main email</label>
            <input type="email" id="email" name="email" value="<?= e($v('email')) ?>">
        </div>
    </div>

    <div class="field">
        <label for="address_1">Street address</label>
        <input type="text" id="address_1" name="address_1" value="<?= e($v('address_1')) ?>">
        <p class="field-hint">Required by law in bulk email, so it goes in the footer automatically.</p>
    </div>

    <div class="field">
        <label for="address_2">Address line 2</label>
        <input type="text" id="address_2" name="address_2" value="<?= e($v('address_2')) ?>">
    </div>

    <div class="field-row">
        <div class="field">
            <label for="city">City</label>
            <input type="text" id="city" name="city" value="<?= e($v('city')) ?>">
        </div>
        <div class="field">
            <label for="state">State</label>
            <input type="text" id="state" name="state" value="<?= e($v('state')) ?>" maxlength="40">
        </div>
        <div class="field">
            <label for="postal_code">ZIP</label>
            <input type="text" id="postal_code" name="postal_code" value="<?= e($v('postal_code')) ?>">
        </div>
    </div>

    <div class="field">
        <label for="website">Website</label>
        <input type="text" id="website" name="website" value="<?= e($v('website')) ?>">
    </div>

    <div class="field-row">
        <div class="field">
            <label for="timezone">Timezone</label>
            <select id="timezone" name="timezone" required>
                <?php foreach ($timezones as $zone): ?>
                    <option value="<?= e($zone) ?>"
                        <?= $zone === ($venue['timezone'] ?? 'America/New_York') ? 'selected' : '' ?>>
                        <?= e($zone) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-hint">Decides when quiet hours apply, so nothing goes out at 3am.</p>
        </div>
        <div class="field">
            <label for="tax_rate">Sales tax (%)</label>
            <input type="number" id="tax_rate" name="tax_rate" step="0.001" min="0" max="30"
                   value="<?= e($v('tax_rate', '0')) ?>">
        </div>
        <div class="field">
            <label for="brand_color">Brand colour</label>
            <input type="text" id="brand_color" name="brand_color" value="<?= e($v('brand_color', '#6b4ea8')) ?>"
                   pattern="#[0-9a-fA-F]{6}">
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn">Save venue details</button>
        <a class="btn btn-ghost" href="<?= e(url('/settings')) ?>">Cancel</a>
    </div>
</form>
