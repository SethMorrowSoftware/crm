<?php

declare(strict_types=1);

/**
 * @var array<string,mixed>|null   $household
 * @var list<array<string,mixed>>  $members
 */

$isEdit = $household !== null;
$id     = $isEdit ? (int) $household['id'] : 0;
$action = $isEdit ? '/households/' . $id : '/households';

$value = static function (string $field) use ($household): string {
    $old = old($field, null);

    if ($old !== null && $old !== '') {
        return (string) $old;
    }

    return (string) ($household[$field] ?? '');
};

$memberName = static function (array $row): string {
    $full = trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['last_name'] ?? '')));

    return $full === '' ? 'Contact #' . (int) $row['id'] : $full;
};
?>
<p class="breadcrumbs">
    <a href="<?= e(url('/households')) ?>">Households</a> /
    <?= $isEdit ? e($household['name']) : 'New household' ?>
</p>

<form method="post" action="<?= e(url($action)) ?>" class="card form">
    <?= csrf_field() ?>

    <div class="field">
        <label for="name">Household name</label>
        <input type="text" id="name" name="name" required minlength="2" maxlength="160"
               value="<?= e($value('name')) ?>" placeholder="The Alvarez family">
        <p class="field-hint">How staff will recognise it — usually the family surname.</p>
        <?php if ($err = error_for('name')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
    </div>

    <div class="field">
        <label for="address_1">Address</label>
        <input type="text" id="address_1" name="address_1" maxlength="160" value="<?= e($value('address_1')) ?>">
    </div>

    <div class="field">
        <label for="address_2">Address line 2</label>
        <input type="text" id="address_2" name="address_2" maxlength="160" value="<?= e($value('address_2')) ?>">
    </div>

    <div class="field-row">
        <div class="field">
            <label for="city">Town or city</label>
            <input type="text" id="city" name="city" maxlength="80" value="<?= e($value('city')) ?>">
        </div>
        <div class="field">
            <label for="state">State</label>
            <input type="text" id="state" name="state" maxlength="40" value="<?= e($value('state')) ?>">
        </div>
        <div class="field">
            <label for="postal_code">ZIP</label>
            <input type="text" id="postal_code" name="postal_code" maxlength="20"
                   value="<?= e($value('postal_code')) ?>">
            <?php if ($err = error_for('postal_code')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
        </div>
    </div>

    <?php if ($isEdit): ?>
        <div class="field">
            <label for="primary_contact_id">Main contact</label>
            <select id="primary_contact_id" name="primary_contact_id">
                <option value="">Not set</option>
                <?php foreach ($members as $member): ?>
                    <option value="<?= (int) $member['id'] ?>"
                        <?= $value('primary_contact_id') === (string) $member['id'] ? 'selected' : '' ?>>
                        <?= e($memberName($member)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-hint">
                The guardian a party confirmation or a birthday message is addressed to. Only
                members of this household can be chosen — add somebody on the household page
                first.
            </p>
            <?php if ($err = error_for('primary_contact_id')): ?>
                <p class="field-error"><?= e($err) ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="field">
        <label for="notes">Notes</label>
        <textarea id="notes" name="notes" rows="3" maxlength="5000"><?= e($value('notes')) ?></textarea>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn"><?= $isEdit ? 'Save changes' : 'Create the household' ?></button>
        <a class="btn btn-ghost"
           href="<?= e(url($isEdit ? '/households/' . $id : '/households')) ?>">Cancel</a>
    </div>
</form>

<?php if (!$isEdit): ?>
    <p class="form-hint">
        Members are added once the household exists — create it, then add the people from its
        own page.
    </p>
<?php endif; ?>
