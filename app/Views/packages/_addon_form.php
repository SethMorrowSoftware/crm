<?php

declare(strict_types=1);

/**
 * The add-on form, shared by "add" and every "edit", so the two cannot drift.
 *
 * @var array<string,mixed>             $a
 * @var string                          $action
 * @var list<array{id:int,name:string}> $attractions
 * @var list<string>                    $units
 */

$id = (int) ($a['id'] ?? 0);
$f  = static fn (string $name): string => 'addon-' . $name . '-' . ($id === 0 ? 'new' : (string) $id);
?>
<form method="post" action="<?= e($action) ?>">
    <?= csrf_field() ?>

    <div class="field-row">
        <div class="field">
            <label for="<?= e($f('name')) ?>">Name</label>
            <input type="text" id="<?= e($f('name')) ?>" name="name" maxlength="120" required
                   value="<?= e((string) ($a['name'] ?? '')) ?>">
        </div>

        <div class="field">
            <label for="<?= e($f('price')) ?>">Price</label>
            <input type="text" id="<?= e($f('price')) ?>" name="price" inputmode="decimal" required
                   value="<?= e(number_format((int) ($a['price_cents'] ?? 0) / 100, 2, '.', '')) ?>">
        </div>

        <div class="field">
            <label for="<?= e($f('unit')) ?>">Charged</label>
            <select id="<?= e($f('unit')) ?>" name="unit">
                <?php foreach ($units as $unit): ?>
                    <option value="<?= e($unit) ?>" <?= (string) ($a['unit'] ?? 'flat') === $unit ? 'selected' : '' ?>>
                        <?= e(match ($unit) {
                            'per_guest' => 'Per guest',
                            'per_hour'  => 'Per hour',
                            default     => 'Once, flat',
                        }) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="<?= e($f('attraction')) ?>">Attraction (optional)</label>
            <select id="<?= e($f('attraction')) ?>" name="attraction_id">
                <option value="">None</option>
                <?php foreach ($attractions as $attraction): ?>
                    <option value="<?= (int) $attraction['id'] ?>"
                        <?= (int) ($a['attraction_id'] ?? 0) === (int) $attraction['id'] ? 'selected' : '' ?>>
                        <?= e((string) $attraction['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="field">
        <label for="<?= e($f('description')) ?>">Description (optional)</label>
        <input type="text" id="<?= e($f('description')) ?>" name="description" maxlength="500"
               value="<?= e((string) ($a['description'] ?? '')) ?>">
    </div>

    <div class="field field-check">
        <label>
            <input type="checkbox" name="is_active" value="1"
                <?= $id === 0 || (int) ($a['is_active'] ?? 0) === 1 ? 'checked' : '' ?>>
            Offered on new bookings
        </label>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn"><?= $id === 0 ? 'Add the add-on' : 'Save' ?></button>
    </div>
</form>
