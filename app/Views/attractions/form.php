<?php

declare(strict_types=1);

/**
 * Create or edit one attraction.
 *
 * @var array<string,mixed>|null $attraction
 */

$a       = $attraction ?? [];
$editing = $attraction !== null;
$id      = $editing ? (int) $a['id'] : 0;

$value = static function (string $field, string $fallback = '') use ($a): string {
    $old = old($field);

    if ($old !== '' && $old !== null) {
        return is_scalar($old) ? (string) $old : '';
    }

    return isset($a[$field]) && $a[$field] !== null ? (string) $a[$field] : $fallback;
};

$checked = static fn (string $field, int $default) => ($a === [] ? $default : (int) ($a[$field] ?? 0)) === 1 ? 'checked' : '';

$months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

$md = static function (?string $value): array {
    return $value !== null && preg_match('/^(\d{2})-(\d{2})$/', $value, $m) === 1 ? [(int) $m[1], (int) $m[2]] : [0, 0];
};

[$startMonth, $startDay] = $md($a['season_start_md'] ?? null);
[$endMonth, $endDay]     = $md($a['season_end_md'] ?? null);
?>
<p class="breadcrumbs">
    <a href="<?= e(url('/settings')) ?>">Settings</a> /
    <a href="<?= e(url('/settings/attractions')) ?>">Attractions</a> /
    <?= $editing ? e((string) $a['name']) : 'New' ?>
</p>

<form method="post" action="<?= e(url($editing ? '/settings/attractions/' . $id : '/settings/attractions')) ?>" class="form">
    <?= csrf_field() ?>

    <section class="card">
        <div class="card-head"><div><h2>The attraction</h2></div></div>

        <div class="field-row">
            <div class="field">
                <label for="name">Name</label>
                <input type="text" id="name" name="name" maxlength="120" required value="<?= e($value('name')) ?>">
            </div>
            <div class="field">
                <label for="category">Category (optional)</label>
                <input type="text" id="category" name="category" maxlength="40" value="<?= e($value('category')) ?>" placeholder="rides">
            </div>
        </div>

        <div class="field">
            <label for="description">Description (optional)</label>
            <textarea id="description" name="description" rows="3" maxlength="2000"><?= e($value('description')) ?></textarea>
        </div>

        <div class="field field-check">
            <label><input type="checkbox" name="is_indoor" value="1" <?= $checked('is_indoor', 1) ?>> Indoor</label>
        </div>
        <div class="field field-check">
            <label><input type="checkbox" name="requires_waiver" value="1" <?= $checked('requires_waiver', 0) ?>> Guests need a signed waiver</label>
        </div>
        <div class="field field-check">
            <label><input type="checkbox" name="is_active" value="1" <?= $checked('is_active', 1) ?>> Active — offered on new bookings</label>
        </div>
    </section>

    <section class="card">
        <div class="card-head">
            <div>
                <h2>Season</h2>
                <p class="card-sub">The same dates every year. A season may run over New Year, like November to March.</p>
            </div>
        </div>

        <div class="field field-check">
            <label><input type="checkbox" name="is_seasonal" value="1" <?= $checked('is_seasonal', 0) ?>> Only open for part of the year</label>
        </div>

        <div class="field-row">
            <div class="field">
                <label for="season_start_month">Opens</label>
                <select id="season_start_month" name="season_start_month">
                    <option value="">Month</option>
                    <?php foreach ($months as $i => $month): ?>
                        <option value="<?= $i + 1 ?>" <?= $startMonth === $i + 1 ? 'selected' : '' ?>><?= e($month) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="season_start_day">Day</label>
                <input type="number" id="season_start_day" name="season_start_day" min="1" max="31" value="<?= $startDay > 0 ? $startDay : '' ?>">
            </div>
            <div class="field">
                <label for="season_end_month">Closes after</label>
                <select id="season_end_month" name="season_end_month">
                    <option value="">Month</option>
                    <?php foreach ($months as $i => $month): ?>
                        <option value="<?= $i + 1 ?>" <?= $endMonth === $i + 1 ? 'selected' : '' ?>><?= e($month) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="season_end_day">Day</label>
                <input type="number" id="season_end_day" name="season_end_day" min="1" max="31" value="<?= $endDay > 0 ? $endDay : '' ?>">
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card-head"><div><h2>Who can go, and how many</h2></div></div>

        <div class="field-row">
            <div class="field">
                <label for="min_age">Minimum age (optional)</label>
                <input type="number" id="min_age" name="min_age" min="0" max="99" value="<?= e($value('min_age')) ?>">
            </div>
            <div class="field">
                <label for="min_height_in">Minimum height, inches (optional)</label>
                <input type="number" id="min_height_in" name="min_height_in" min="0" max="96" value="<?= e($value('min_height_in')) ?>">
            </div>
            <div class="field">
                <label for="max_height_in">Maximum height, inches (optional)</label>
                <input type="number" id="max_height_in" name="max_height_in" min="0" max="96" value="<?= e($value('max_height_in')) ?>">
            </div>
        </div>

        <div class="field-row">
            <div class="field">
                <label for="capacity_per_slot">People at a time (optional)</label>
                <input type="number" id="capacity_per_slot" name="capacity_per_slot" min="1" max="5000" value="<?= e($value('capacity_per_slot')) ?>">
            </div>
            <div class="field">
                <label for="slot_minutes">Slot length, minutes (optional)</label>
                <input type="number" id="slot_minutes" name="slot_minutes" min="1" max="720" value="<?= e($value('slot_minutes')) ?>">
            </div>
            <div class="field">
                <label for="sort_order">Position in lists</label>
                <input type="number" id="sort_order" name="sort_order" min="0" max="9999" value="<?= e($value('sort_order')) ?>">
                <p class="field-hint">Lower comes first. Blank puts a new one last.</p>
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card-head"><div><h2>Parties</h2></div></div>
        <div class="field field-check">
            <label><input type="checkbox" name="is_party_main" value="1" <?= $checked('is_party_main', 0) ?>> A party can be built around it (bowling, laser tag)</label>
        </div>
        <div class="field field-check">
            <label><input type="checkbox" name="is_party_eligible" value="1" <?= $checked('is_party_eligible', 1) ?>> A party can include it as an extra (the arcade)</label>
        </div>
    </section>

    <div class="form-actions">
        <button type="submit" class="btn"><?= $editing ? 'Save' : 'Add the attraction' ?></button>
        <a class="btn btn-ghost" href="<?= e(url('/settings/attractions')) ?>">Back</a>
    </div>
</form>
