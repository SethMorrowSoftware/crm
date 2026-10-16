<?php

declare(strict_types=1);

/**
 * Create / edit a contact.
 *
 * Note what is NOT on this form: email_consent, sms_consent and
 * do_not_contact. Consent is recorded on the contact's own page, where it
 * demands a source and writes a dated, append-only consent_log row — see
 * docs/PRIVACY.md §3. A checkbox here would let somebody tick "they're happy
 * to get texts" with no evidence of anything the guest actually did.
 *
 * @var array<string,mixed>|null   $contact
 * @var list<string>               $stages
 * @var list<array<string,mixed>>  $owners
 * @var list<array<string,mixed>>  $households
 */

$isEdit = $contact !== null;
$id     = $isEdit ? (int) $contact['id'] : 0;
$action = $isEdit ? '/contacts/' . $id : '/contacts';

$name = $isEdit
    ? trim(((string) ($contact['first_name'] ?? '')) . ' ' . ((string) ($contact['last_name'] ?? '')))
    : '';

if ($isEdit && $name === '') {
    $name = 'Contact #' . $id;
}

/** Current value for a field: what they just typed, else the stored row. */
$value = static function (string $field) use ($contact): string {
    $old = old($field, null);

    if ($old !== null && $old !== '') {
        return (string) $old;
    }

    return (string) ($contact[$field] ?? '');
};

$stageLabels = [
    'lead'     => 'Lead — enquired, not booked',
    'prospect' => 'Prospect — quoted or in conversation',
    'customer' => 'Customer — has visited or booked',
    'member'   => 'Member — holds a pass or membership',
    'lapsed'   => 'Lapsed — has not been back',
    'advocate' => 'Advocate — refers other guests',
];
?>
<p class="breadcrumbs">
    <a href="<?= e(url('/contacts')) ?>">Contacts</a> /
    <?= $isEdit ? e($name) : 'New contact' ?>
</p>

<form method="post" action="<?= e(url($action)) ?>" class="card form">
    <?= csrf_field() ?>

    <div class="field-row">
        <div class="field">
            <label for="first_name">First name</label>
            <input type="text" id="first_name" name="first_name" maxlength="80"
                   value="<?= e($value('first_name')) ?>">
            <?php if ($err = error_for('first_name')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
        </div>
        <div class="field">
            <label for="last_name">Last name</label>
            <input type="text" id="last_name" name="last_name" maxlength="80"
                   value="<?= e($value('last_name')) ?>">
            <?php if ($err = error_for('last_name')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
        </div>
    </div>

    <div class="field-row">
        <div class="field">
            <label for="email">Email address</label>
            <input type="email" id="email" name="email" maxlength="191" value="<?= e($value('email')) ?>">
            <?php if ($err = error_for('email')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
        </div>
        <div class="field">
            <label for="phone">Phone</label>
            <input type="tel" id="phone" name="phone" maxlength="32" value="<?= e($value('phone')) ?>">
            <p class="field-hint">Stored in international form as well, so texts match whichever way it is typed.</p>
            <?php if ($err = error_for('phone')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
        </div>
    </div>

    <div class="field-row">
        <div class="field">
            <label for="alt_phone">Second phone</label>
            <input type="tel" id="alt_phone" name="alt_phone" maxlength="32" value="<?= e($value('alt_phone')) ?>">
            <?php if ($err = error_for('alt_phone')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
        </div>
        <div class="field">
            <label for="birth_date">Date of birth</label>
            <input type="date" id="birth_date" name="birth_date"
                   value="<?= e($isEdit && !empty($contact['birth_date'])
                        ? substr((string) $contact['birth_date'], 0, 10)
                        : (string) old('birth_date', '')) ?>">
            <p class="field-hint">Drives the birthday campaign. Optional.</p>
            <?php if ($err = error_for('birth_date')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
        </div>
    </div>

    <p class="form-section-title">Where they are</p>

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

    <p class="form-section-title">How we know them</p>

    <div class="field-row">
        <div class="field">
            <label for="lifecycle_stage">Lifecycle stage</label>
            <select id="lifecycle_stage" name="lifecycle_stage" required>
                <?php $current = $value('lifecycle_stage') ?: 'lead'; ?>
                <?php foreach ($stages as $stage): ?>
                    <option value="<?= e($stage) ?>" <?= $current === $stage ? 'selected' : '' ?>>
                        <?= e($stageLabels[$stage] ?? ucfirst($stage)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($err = error_for('lifecycle_stage')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
        </div>
        <div class="field">
            <label for="owner_id">Owner</label>
            <select id="owner_id" name="owner_id">
                <option value="">Nobody yet</option>
                <?php foreach ($owners as $owner): ?>
                    <option value="<?= (int) $owner['id'] ?>"
                        <?= $value('owner_id') === (string) $owner['id'] ? 'selected' : '' ?>>
                        <?= e($owner['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-hint">Who is looking after this guest.</p>
        </div>
    </div>

    <div class="field-row">
        <div class="field">
            <label for="household_id">Household</label>
            <select id="household_id" name="household_id">
                <option value="">None</option>
                <?php foreach ($households as $household): ?>
                    <option value="<?= (int) $household['id'] ?>"
                        <?= $value('household_id') === (string) $household['id'] ? 'selected' : '' ?>>
                        <?= e($household['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-hint">
                <a href="<?= e(url('/households')) ?>">Households</a> group a family under one address.
            </p>
        </div>
        <div class="field">
            <label for="external_pos_id">CenterEdge card number</label>
            <input type="text" id="external_pos_id" name="external_pos_id" maxlength="64"
                   value="<?= e($value('external_pos_id')) ?>">
            <p class="field-hint">The key that joins this guest to their POS visit history.</p>
            <?php if ($err = error_for('external_pos_id')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
        </div>
    </div>

    <div class="field-row">
        <div class="field">
            <label for="source">Source</label>
            <input type="text" id="source" name="source" maxlength="40" value="<?= e($value('source')) ?>">
            <p class="field-hint">Where they came from: website, walk-in, pos_import, referral.</p>
        </div>
        <div class="field">
            <label for="source_detail">Source detail</label>
            <input type="text" id="source_detail" name="source_detail" maxlength="191"
                   value="<?= e($value('source_detail')) ?>">
        </div>
    </div>

    <p class="form-hint">
        Consent is not set here. Email and text permission are recorded on the contact's own
        page, with the date and where it came from, because that record — not a tick — is what
        shows the guest agreed.
    </p>

    <div class="form-actions">
        <button type="submit" class="btn"><?= $isEdit ? 'Save changes' : 'Create the contact' ?></button>
        <a class="btn btn-ghost"
           href="<?= e(url($isEdit ? '/contacts/' . $id : '/contacts')) ?>">Cancel</a>
    </div>
</form>
