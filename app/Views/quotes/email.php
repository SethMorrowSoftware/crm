<?php

declare(strict_types=1);

/**
 * The quote rendered into an email body.
 *
 * Rendered through the view layer rather than concatenated in the controller,
 * so there is ONE description of what a quote looks like and the same e()
 * escaping applies. Mailer derives the plain-text alternative from this.
 *
 * Deliberately link-free: this module publishes no tokenised public URL yet, so
 * a "view your quote" link would point at a page the guest cannot open. The
 * figures travel in the message instead.
 *
 * Inline styles and table layout, because that is what mail clients render.
 *
 * @var array<string,mixed>       $quote
 * @var list<array<string,mixed>> $lines
 * @var array<string,string>      $venue
 * @var array{name:string,email:string,contact_id:int|null,address:list<string>} $recipient
 * @var callable(?string):string  $calendarDate
 */

$cell    = 'padding:8px 10px;font-family:Helvetica,Arial,sans-serif;font-size:14px;';
$head    = $cell . 'font-weight:bold;border-bottom:2px solid #333;text-align:left;';
$right   = $cell . 'text-align:right;';
$expires = $quote['valid_until'] === null ? null : (string) $quote['valid_until'];

$quantity = static function (mixed $qty): string {
    $qty = trim((string) $qty);

    if ($qty === '' || !str_contains($qty, '.')) {
        return $qty === '' ? '0' : $qty;
    }

    return rtrim(rtrim($qty, '0'), '.') ?: '0';
};
?>
<div style="font-family:Helvetica,Arial,sans-serif;font-size:14px;line-height:20px;color:#222;">
    <p>
        <?= $recipient['name'] === '' ? 'Hello,' : 'Hello ' . e($recipient['name']) . ',' ?>
    </p>

    <p>
        Here is your quote from <?= e($venue['name']) ?>, reference
        <strong><?= e((string) $quote['number']) ?></strong>.
        <?php if ($expires !== null): ?>
            It is valid until <?= e($calendarDate($expires)) ?>.
        <?php endif; ?>
    </p>

    <?php if ($quote['event_date'] !== null): ?>
        <p>
            For <?= e($calendarDate((string) $quote['event_date'])) ?><?php
                if ($quote['guest_count'] !== null): ?>, <?= (int) $quote['guest_count'] ?> guests<?php
                endif; ?>.
        </p>
    <?php endif; ?>

    <?php /* A real data table, so it keeps its <th scope> rather than role="presentation". */ ?>
    <table cellpadding="0" cellspacing="0" border="0" width="100%"
           style="border-collapse:collapse;margin:16px 0;">
        <tr>
            <th scope="col" style="<?= e($head) ?>">Description</th>
            <th scope="col" style="<?= e($head) ?>">Qty</th>
            <th scope="col" style="<?= e($head) ?>">Unit</th>
            <th scope="col" style="<?= e($head) ?>text-align:right;">Amount</th>
        </tr>
        <?php foreach ($lines as $line): ?>
            <tr>
                <td style="<?= e($cell) ?>border-bottom:1px solid #ddd;">
                    <?= e((string) $line['description']) ?>
                </td>
                <td style="<?= e($cell) ?>border-bottom:1px solid #ddd;">
                    <?= e($quantity($line['qty'])) ?>
                </td>
                <td style="<?= e($cell) ?>border-bottom:1px solid #ddd;">
                    <?= e(money((int) $line['unit_cents'])) ?>
                </td>
                <td style="<?= e($right) ?>border-bottom:1px solid #ddd;">
                    <?= e(money((int) $line['total_cents'])) ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <tr>
            <th scope="row" colspan="3" style="<?= e($right) ?>">Subtotal</th>
            <td style="<?= e($right) ?>"><?= e(money((int) $quote['subtotal_cents'])) ?></td>
        </tr>
        <?php if ((int) $quote['discount_cents'] !== 0): ?>
            <tr>
                <th scope="row" colspan="3" style="<?= e($right) ?>">Discount</th>
                <td style="<?= e($right) ?>">-<?= e(money((int) $quote['discount_cents'])) ?></td>
            </tr>
        <?php endif; ?>
        <?php if ((int) $quote['tax_cents'] !== 0): ?>
            <tr>
                <th scope="row" colspan="3" style="<?= e($right) ?>">Tax</th>
                <td style="<?= e($right) ?>"><?= e(money((int) $quote['tax_cents'])) ?></td>
            </tr>
        <?php endif; ?>
        <tr>
            <th scope="row" colspan="3" style="<?= e($right) ?>font-size:16px;">Total</th>
            <td style="<?= e($right) ?>font-size:16px;font-weight:bold;">
                <?= e(money((int) $quote['total_cents'])) ?>
            </td>
        </tr>
        <?php if ((int) $quote['deposit_cents'] !== 0): ?>
            <tr>
                <th scope="row" colspan="3" style="<?= e($right) ?>">Deposit to hold the date</th>
                <td style="<?= e($right) ?>"><?= e(money((int) $quote['deposit_cents'])) ?></td>
            </tr>
        <?php endif; ?>
    </table>

    <?php if (($quote['terms'] ?? '') !== ''): ?>
        <p style="font-size:13px;color:#555;"><?= nl2br(e((string) $quote['terms']), false) ?></p>
    <?php endif; ?>

    <p>
        Just reply to this message if anything needs changing, or call us
        <?php if ($venue['phone'] !== ''): ?>on <?= e($venue['phone']) ?><?php endif; ?>.
    </p>

    <p>
        <?= e($venue['name']) ?>
        <?php foreach (['line1', 'line2', 'city'] as $part): ?>
            <?php if ($venue[$part] !== ''): ?><br><?= e($venue[$part]) ?><?php endif; ?>
        <?php endforeach; ?>
    </p>
</div>
