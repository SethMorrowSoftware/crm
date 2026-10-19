<?php

declare(strict_types=1);

/**
 * Create or edit one party package.
 *
 * @var array<string,mixed>|null        $package
 * @var list<array{id:int,name:string}> $attractions
 * @var int                             $bookings
 */

$editing = $package !== null;
$id      = $editing ? (int) $package['id'] : 0;
$action  = $editing ? url('/settings/packages/' . $id) : url('/settings/packages');

/** What was typed and rejected, else the stored row, else a default. */
$value = static function (string $field, string $fallback = '') use ($package): string {
    $old = old($field);

    if ($old !== '' && $old !== null) {
        return is_scalar($old) ? (string) $old : '';
    }

    $stored = $package[$field] ?? null;

    return $stored === null ? $fallback : (string) $stored;
};

$money = static function (string $field, string $column, string $fallback = '') use ($package): string {
    $old = old($field);

    if ($old !== '' && $old !== null) {
        return is_scalar($old) ? (string) $old : '';
    }

    return $package === null ? $fallback : number_format((int) $package[$column] / 100, 2, '.', '');
};

$includes = old('includes');

if ($includes === '' || $includes === null) {
    $list = $package !== null && is_string($package['includes'] ?? null)
        ? (json_decode((string) $package['includes'], true) ?: [])
        : [];
    $includes = implode("\n", array_map('strval', is_array($list) ? $list : []));
}

$checked = static fn (string $field, int $default) => $package === null
    ? ($default === 1 ? 'checked' : '')
    : ((int) ($package[$field] ?? 0) === 1 ? 'checked' : '');

$error = static function (string $field): string {
    $message = error_for($field);

    return $message === null ? '' : '<p class="field-error">' . e($message) . '</p>';
};
?>
<p class="breadcrumbs">
    <a href="<?= e(url('/settings')) ?>">Settings</a> /
    <a href="<?= e(url('/settings/packages')) ?>">Party packages</a> /
    <?= $editing ? e((string) $package['name']) : 'New' ?>
</p>

<form method="post" action="<?= e($action) ?>" class="stack-lg">
    <?= csrf_field() ?>

    <section class="card">
        <div class="card-head"><div><h2>The package</h2></div></div>

        <div class="field">
            <label for="name">Name</label>
            <input type="text" id="name" name="name" maxlength="120" required
                   value="<?= e($value('name')) ?>" <?= has_error('name') ? 'aria-invalid="true"' : '' ?>>
            <?= $error('name') ?>
        </div>

        <div class="field">
            <label for="main_attraction_id">Main activity (optional)</label>
            <select id="main_attraction_id" name="main_attraction_id">
                <option value="">None — not built around one attraction</option>
                <?php foreach ($attractions as $attraction): ?>
                    <option value="<?= (int) $attraction['id'] ?>"
                        <?= (int) $value('main_attraction_id', '0') === (int) $attraction['id'] ? 'selected' : '' ?>>
                        <?= e((string) $attraction['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-hint">Party rooms and lanes are offered from this attraction's resources.</p>
        </div>

        <div class="field">
            <label for="description">Description (shown to families booking online)</label>
            <textarea id="description" name="description" rows="3" maxlength="2000"><?= e($value('description')) ?></textarea>
            <?= $error('description') ?>
        </div>

        <div class="field">
            <label for="includes">What is included (one per line)</label>
            <textarea id="includes" name="includes" rows="4" maxlength="2000"><?= e((string) $includes) ?></textarea>
            <p class="field-hint">Shown as a list on the booking page. Up to twelve lines.</p>
        </div>
    </section>

    <section class="card">
        <div class="card-head">
            <div>
                <h2>Price</h2>
                <p class="card-sub">List price, before tax. Quotes already written keep the price they were written at.</p>
            </div>
        </div>

        <div class="field-row">
            <div class="field">
                <label for="base_price">Price</label>
                <input type="text" id="base_price" name="base_price" inputmode="decimal" required
                       value="<?= e($money('base_price', 'base_price_cents')) ?>"
                       <?= has_error('base_price') ? 'aria-invalid="true"' : '' ?>>
                <?= $error('base_price') ?>
            </div>

            <div class="field">
                <label for="included_guests">Guests included</label>
                <input type="number" id="included_guests" name="included_guests" min="1" max="500" required
                       value="<?= e($value('included_guests', '8')) ?>">
                <?= $error('included_guests') ?>
            </div>

            <div class="field">
                <label for="additional_guest_price">Each additional guest</label>
                <input type="text" id="additional_guest_price" name="additional_guest_price" inputmode="decimal" required
                       value="<?= e($money('additional_guest_price', 'additional_guest_cents')) ?>"
                       <?= has_error('additional_guest_price') ? 'aria-invalid="true"' : '' ?>>
                <?= $error('additional_guest_price') ?>
            </div>

            <div class="field">
                <label for="deposit">Deposit</label>
                <input type="text" id="deposit" name="deposit" inputmode="decimal"
                       value="<?= e($money('deposit', 'deposit_cents', '100.00')) ?>">
                <p class="field-hint">0 for none.</p>
                <?= $error('deposit') ?>
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card-head"><div><h2>Scheduling and sale</h2></div></div>

        <div class="field-row">
            <div class="field">
                <label for="duration_minutes">Length, in minutes</label>
                <input type="number" id="duration_minutes" name="duration_minutes" min="15" max="720" required
                       value="<?= e($value('duration_minutes', '120')) ?>">
                <?= $error('duration_minutes') ?>
            </div>

            <div class="field">
                <label for="min_notice_days">Online notice, in days</label>
                <input type="number" id="min_notice_days" name="min_notice_days" min="0" max="365" required
                       value="<?= e($value('min_notice_days', '7')) ?>">
                <p class="field-hint">Inside this window the booking page asks the family to call.</p>
                <?= $error('min_notice_days') ?>
            </div>

            <div class="field">
                <label for="sort_order">Position in lists</label>
                <input type="number" id="sort_order" name="sort_order" min="0" max="9999"
                       value="<?= e($value('sort_order')) ?>">
                <p class="field-hint">Lower comes first. Blank puts a new package last.</p>
            </div>
        </div>

        <div class="field field-check">
            <label>
                <input type="checkbox" name="is_active" value="1" <?= $checked('is_active', 1) ?>>
                Active — staff can book it
            </label>
        </div>

        <div class="field field-check">
            <label>
                <input type="checkbox" name="is_online" value="1" <?= $checked('is_online', 1) ?>>
                Bookable online by families
            </label>
        </div>

        <div class="field field-check">
            <label>
                <input type="checkbox" name="is_seasonal" value="1" <?= $checked('is_seasonal', 0) ?>>
                Seasonal — offered only while its main activity is in season
            </label>
        </div>
    </section>

    <div class="form-actions">
        <button type="submit" class="btn"><?= $editing ? 'Save the package' : 'Create the package' ?></button>
        <a class="btn btn-ghost" href="<?= e(url('/settings/packages')) ?>">Cancel</a>
    </div>
</form>

<?php if ($editing): ?>
    <section class="card card-danger">
        <div class="card-head"><div><h2>Delete</h2></div></div>
        <?php if ($bookings > 0): ?>
            <p>
                <?= e((string) $package['name']) ?> is on <?= $bookings ?> booking<?= $bookings === 1 ? '' : 's' ?>,
                so it cannot be deleted without losing what those parties were. Untick "Active" above to take it
                off sale instead.
            </p>
        <?php else: ?>
            <form method="post" action="<?= e(url('/settings/packages/' . $id . '/delete')) ?>"
                  data-confirm="Delete <?= e((string) $package['name']) ?>? This cannot be undone.">
                <?= csrf_field() ?>
                <p>Nobody has booked this package, so it can be deleted outright.</p>
                <button type="submit" class="btn btn-danger">Delete <?= e((string) $package['name']) ?></button>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>
