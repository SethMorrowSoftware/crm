<?php

declare(strict_types=1);

/**
 * Build or re-price a quote.
 *
 * The line rows are plain inputs with no JavaScript: a fixed number of blank
 * rows is offered and the blank ones are discarded on save. That is less
 * elegant than an add-a-row button and it works with the keyboard, works
 * without script, and cannot get out of step with what the server parses.
 *
 * @var array<string,mixed>|null  $quote
 * @var list<array<string,mixed>> $lines
 * @var list<array<string,mixed>> $deals
 */

$isEdit = $quote !== null;
$action = $isEdit ? '/quotes/' . (int) $quote['id'] : '/quotes';

/** Cents back to a plain editable decimal — never a float. */
$dollars = static function (?int $cents): string {
    $cents ??= 0;

    return intdiv(abs($cents), 100) . '.' . str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
};

/**
 * A DECIMAL(10,2) quantity as the shortest exact decimal: 3.00 -> 3, 1.50 ->
 * 1.5. Only ever trims past a decimal point, so 100.00 cannot become 1.
 */
$quantity = static function (mixed $qty): string {
    $qty = trim((string) $qty);

    if ($qty === '' || !str_contains($qty, '.')) {
        return $qty;
    }

    return rtrim(rtrim($qty, '0'), '.');
};

// Always offer a few empty rows beyond what exists, so a line can be added
// without a round trip.
$rows = $lines;

for ($i = 0; $i < 4; $i++) {
    $rows[] = ['description' => '', 'qty' => '', 'unit_cents' => null];
}

$selectedDeal = (string) (old('deal_id') ?: ($quote['deal_id'] ?? ''));
?>
<p class="breadcrumbs">
    <a href="<?= e(url('/quotes')) ?>">Quotes</a> /
    <?= $isEdit ? e((string) $quote['number']) : 'New quote' ?>
</p>

<form method="post" action="<?= e(url($action)) ?>" class="card form">
    <?= csrf_field() ?>

    <div class="field-row">
        <div class="field">
            <label for="deal_id">Deal</label>
            <select id="deal_id" name="deal_id">
                <option value="">No deal — a walk-in quote</option>
                <?php foreach ($deals as $deal): ?>
                    <?php $guest = trim(((string) ($deal['first_name'] ?? '')) . ' ' . ((string) ($deal['last_name'] ?? ''))); ?>
                    <option value="<?= (int) $deal['id'] ?>"
                        <?= $selectedDeal === (string) $deal['id'] ? 'selected' : '' ?>>
                        <?= e($deal['title']) ?><?= $guest === '' ? '' : ' — ' . e($guest) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-hint">
                The deal carries the guest this quote is addressed to. Attaching one is what
                gives the quote a name, an address and an email to send to.
            </p>
            <?php if ($err = error_for('deal_id')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
        </div>

        <div class="field">
            <label for="valid_until">Expires on</label>
            <input type="date" id="valid_until" name="valid_until"
                   value="<?= e(old('valid_until') ?: ($quote['valid_until'] ?? '')) ?>">
            <p class="field-hint">Leave blank for a quote with no expiry date.</p>
            <?php if ($err = error_for('valid_until')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
        </div>
    </div>

    <h2 class="form-section-title">Lines</h2>
    <p class="form-hint">
        Enter unit prices in dollars and cents. Every total on the quote is worked out from
        these rows in whole cents, so nothing here is rounded twice. Leave a row blank to skip it.
    </p>

    <div class="table-wrap">
        <table class="table">
            <caption class="sr-only">Lines on this quote</caption>
            <thead>
                <tr>
                    <th scope="col">Description</th>
                    <th scope="col">Quantity</th>
                    <th scope="col">Unit price</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $index => $line): ?>
                <tr>
                    <td>
                        <label class="sr-only" for="line_<?= (int) $index ?>_description">
                            Line <?= (int) $index + 1 ?> description
                        </label>
                        <input type="text" id="line_<?= (int) $index ?>_description"
                               name="lines[<?= (int) $index ?>][description]" maxlength="255"
                               value="<?= e($line['description']) ?>">
                    </td>
                    <td>
                        <label class="sr-only" for="line_<?= (int) $index ?>_qty">
                            Line <?= (int) $index + 1 ?> quantity
                        </label>
                        <input type="text" inputmode="decimal" id="line_<?= (int) $index ?>_qty"
                               name="lines[<?= (int) $index ?>][qty]"
                               value="<?= e($quantity($line['qty'])) ?>">
                    </td>
                    <td>
                        <label class="sr-only" for="line_<?= (int) $index ?>_unit">
                            Line <?= (int) $index + 1 ?> unit price
                        </label>
                        <input type="text" inputmode="decimal" id="line_<?= (int) $index ?>_unit"
                               name="lines[<?= (int) $index ?>][unit]"
                               value="<?= $line['unit_cents'] === null ? '' : e($dollars((int) $line['unit_cents'])) ?>">
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <h2 class="form-section-title">Adjustments</h2>

    <div class="field-row">
        <div class="field">
            <label for="discount_mode">Discount is</label>
            <select id="discount_mode" name="discount_mode">
                <option value="amount" <?= old('discount_mode') === 'percent' ? '' : 'selected' ?>>
                    An amount in dollars
                </option>
                <option value="percent" <?= old('discount_mode') === 'percent' ? 'selected' : '' ?>>
                    A percentage of the lines
                </option>
            </select>
            <p class="field-hint">
                A percentage is converted to a fixed amount when you save, because that
                amount is what the guest is being promised.
            </p>
        </div>

        <div class="field">
            <label for="discount">Discount</label>
            <input type="text" inputmode="decimal" id="discount" name="discount"
                   value="<?= e(old('discount') ?: ($isEdit ? $dollars((int) $quote['discount_cents']) : '')) ?>">
            <p class="field-hint">
                A three-for-two on a $9.99 line is a 9.99 discount. A discount can take a
                quote to zero but never below it.
            </p>
        </div>
    </div>

    <div class="field-row">
        <div class="field">
            <label for="tax">Tax</label>
            <input type="text" inputmode="decimal" id="tax" name="tax"
                   value="<?= e(old('tax') ?: ($isEdit ? $dollars((int) $quote['tax_cents']) : '')) ?>">
            <p class="field-hint">
                An amount, not a rate. A rate has nowhere to be stored on a quote, so saving
                one would mean re-deriving it on every edit and watching the figure drift.
            </p>
        </div>

        <div class="field">
            <label for="deposit">Deposit due</label>
            <input type="text" inputmode="decimal" id="deposit" name="deposit"
                   value="<?= e(old('deposit') ?: ($isEdit ? $dollars((int) $quote['deposit_cents']) : '')) ?>">
            <p class="field-hint">What the guest pays now to hold the date. Capped at the total.</p>
        </div>
    </div>

    <div class="field">
        <label for="terms">Terms</label>
        <textarea id="terms" name="terms" rows="4" maxlength="5000"><?= e(old('terms') ?: ($quote['terms'] ?? '')) ?></textarea>
        <p class="field-hint">Printed on the quote the guest reads.</p>
        <?php if ($err = error_for('terms')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
    </div>

    <div class="field">
        <label for="notes">Internal notes</label>
        <textarea id="notes" name="notes" rows="3" maxlength="5000"><?= e(old('notes') ?: ($quote['notes'] ?? '')) ?></textarea>
        <p class="field-hint">Never shown to the guest and never printed.</p>
        <?php if ($err = error_for('notes')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn"><?= $isEdit ? 'Save the quote' : 'Create the quote' ?></button>
        <a class="btn btn-ghost"
           href="<?= e(url($isEdit ? '/quotes/' . (int) $quote['id'] : '/quotes')) ?>">Cancel</a>
    </div>
</form>
