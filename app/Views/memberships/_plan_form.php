<?php

declare(strict_types=1);

/**
 * The plan form, shared by "add" and every "edit".
 *
 * Included with `$p` as the plan row (empty for a new one) and `$action` as
 * where it posts, so the two cannot drift apart — which is what happened to
 * the first version of the automation step form, where the edit path grew a
 * field the create path never got.
 *
 * @var array<string,mixed> $p
 * @var string              $action
 * @var list<string>        $types
 */

$id = (int) ($p['id'] ?? 0);
$f  = static fn (string $name): string => $name . '-' . ($id === 0 ? 'new' : (string) $id);
?>

<form method="post" action="<?= e($action) ?>">
    <?= csrf_field() ?>

    <div class="field-row">
        <div class="field">
            <label for="<?= e($f('name')) ?>">Name</label>
            <input type="text" id="<?= e($f('name')) ?>" name="name" maxlength="120" required
                   value="<?= e((string) ($p['name'] ?? '')) ?>">
        </div>

        <div class="field">
            <label for="<?= e($f('type')) ?>">Kind of pass</label>
            <select id="<?= e($f('type')) ?>" name="type">
                <?php foreach ($types as $type): ?>
                    <option value="<?= e($type) ?>" <?= (string) ($p['type'] ?? '') === $type ? 'selected' : '' ?>>
                        <?= e(match ($type) {
                            'season_pass' => 'Season pass',
                            'membership'  => 'Membership',
                            'multi_visit' => 'Multi-visit card',
                            default       => ucfirst(str_replace('_', ' ', $type)),
                        }) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="<?= e($f('price')) ?>">Price</label>
            <input type="text" id="<?= e($f('price')) ?>" name="price" inputmode="decimal" required
                   value="<?= e(number_format((int) ($p['price_cents'] ?? 0) / 100, 2, '.', '')) ?>">
        </div>
    </div>

    <div class="field-row">
        <div class="field">
            <label for="<?= e($f('duration_days')) ?>">Runs for (days)</label>
            <input type="number" id="<?= e($f('duration_days')) ?>" name="duration_days" min="0" max="3650"
                   value="<?= e((string) ($p['duration_days'] ?? '')) ?>">
            <p class="field-hint">
                Blank means it runs until somebody cancels it — and nothing will ever send a
                renewal notice for it, because there is no date to count back from.
            </p>
        </div>

        <div class="field">
            <label for="<?= e($f('visit_allowance')) ?>">Visits included</label>
            <input type="number" id="<?= e($f('visit_allowance')) ?>" name="visit_allowance" min="0" max="999"
                   value="<?= e((string) ($p['visit_allowance'] ?? '')) ?>">
            <p class="field-hint">Blank means unlimited. A card ends when its visits are used.</p>
        </div>

        <div class="field">
            <label for="<?= e($f('max_members')) ?>">People covered</label>
            <input type="number" id="<?= e($f('max_members')) ?>" name="max_members" min="0" max="50"
                   value="<?= e((string) ($p['max_members'] ?? '')) ?>">
            <p class="field-hint">Blank means one — the holder.</p>
        </div>
    </div>

    <div class="field field-check">
        <label>
            <input type="checkbox" name="is_family_plan" value="1"
                <?= (int) ($p['is_family_plan'] ?? 0) === 1 ? 'checked' : '' ?>>
            A family plan
        </label>
    </div>

    <div class="field field-check">
        <label>
            <input type="checkbox" name="is_active" value="1"
                <?= $id === 0 || (int) ($p['is_active'] ?? 0) === 1 ? 'checked' : '' ?>>
            On sale
        </label>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn"><?= $id === 0 ? 'Create the plan' : 'Save' ?></button>
    </div>
</form>
