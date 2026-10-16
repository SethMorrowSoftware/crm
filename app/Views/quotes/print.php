<?php

declare(strict_types=1);

/**
 * The copy a venue sends or hands over.
 *
 * Rendered in the normal layout on purpose: the stylesheet's @media print block
 * already drops the sidebar, the top bar and every button, so what prints is
 * this document alone. A second layout would be a second thing to keep in step
 * with the theme.
 *
 * @var array<string,mixed>       $quote
 * @var list<array<string,mixed>> $lines
 * @var array<string,string>      $venue
 * @var array{name:string,email:string,contact_id:int|null,address:list<string>} $recipient
 * @var callable(?string):string  $calendarDate
 */

$expires = $quote['valid_until'] === null ? null : (string) $quote['valid_until'];

/** A quantity as the shortest exact decimal — only ever trimmed past a point. */
$quantity = static function (mixed $qty): string {
    $qty = trim((string) $qty);

    if ($qty === '' || !str_contains($qty, '.')) {
        return $qty === '' ? '0' : $qty;
    }

    return rtrim(rtrim($qty, '0'), '.') ?: '0';
};
?>
<p class="breadcrumbs">
    <a href="<?= e(url('/quotes')) ?>">Quotes</a> /
    <a href="<?= e(url('/quotes/' . (int) $quote['id'])) ?>"><?= e((string) $quote['number']) ?></a> /
    Printable copy
</p>

<section class="card">
    <div class="grid-2">
        <div>
            <h2><?= e($venue['name']) ?></h2>
            <p>
                <?php foreach (['line1', 'line2', 'city'] as $part): ?>
                    <?php if ($venue[$part] !== ''): ?><?= e($venue[$part]) ?><br><?php endif; ?>
                <?php endforeach; ?>
                <?php if ($venue['phone'] !== ''): ?><?= e($venue['phone']) ?><br><?php endif; ?>
                <?php if ($venue['email'] !== ''): ?><?= e($venue['email']) ?><br><?php endif; ?>
                <?php if ($venue['website'] !== ''): ?><?= e($venue['website']) ?><?php endif; ?>
            </p>
        </div>
        <div>
            <h2>Quote <?= e((string) $quote['number']) ?></h2>
            <p>
                Dated <?= e(local_date((string) $quote['created_at'])) ?><br>
                <?php if ($expires !== null): ?>
                    Valid until <?= e($calendarDate($expires)) ?><br>
                <?php else: ?>
                    No expiry date<br>
                <?php endif; ?>
                <?php if ($recipient['name'] !== ''): ?>
                    Prepared for <?= e($recipient['name']) ?>
                <?php endif; ?>
            </p>
            <?php if ($recipient['address'] !== []): ?>
                <p>
                    <?php foreach ($recipient['address'] as $addressLine): ?>
                        <?= e($addressLine) ?><br>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($quote['event_date'] !== null): ?>
        <p>
            For <?= e($calendarDate((string) $quote['event_date'])) ?><?php
                if ($quote['guest_count'] !== null): ?>, <?= (int) $quote['guest_count'] ?> guests<?php
                endif; ?>.
        </p>
    <?php endif; ?>

    <div class="table-wrap">
        <table class="table">
            <caption class="sr-only">Quote <?= e((string) $quote['number']) ?> line items</caption>
            <thead>
                <tr>
                    <th scope="col">Description</th>
                    <th scope="col">Qty</th>
                    <th scope="col">Unit</th>
                    <th scope="col">Amount</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($lines as $line): ?>
                <tr>
                    <td><?= e((string) $line['description']) ?></td>
                    <td><?= e($quantity($line['qty'])) ?></td>
                    <td><?= e(money((int) $line['unit_cents'])) ?></td>
                    <td><?= e(money((int) $line['total_cents'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th scope="row" colspan="3">Subtotal</th>
                    <td><?= e(money((int) $quote['subtotal_cents'])) ?></td>
                </tr>
                <?php if ((int) $quote['discount_cents'] !== 0): ?>
                    <tr>
                        <th scope="row" colspan="3">Discount</th>
                        <td>-<?= e(money((int) $quote['discount_cents'])) ?></td>
                    </tr>
                <?php endif; ?>
                <?php if ((int) $quote['tax_cents'] !== 0): ?>
                    <tr>
                        <th scope="row" colspan="3">Tax</th>
                        <td><?= e(money((int) $quote['tax_cents'])) ?></td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <th scope="row" colspan="3">Total</th>
                    <td><strong><?= e(money((int) $quote['total_cents'])) ?></strong></td>
                </tr>
                <?php if ((int) $quote['deposit_cents'] !== 0): ?>
                    <tr>
                        <th scope="row" colspan="3">Deposit to hold the date</th>
                        <td><?= e(money((int) $quote['deposit_cents'])) ?></td>
                    </tr>
                <?php endif; ?>
            </tfoot>
        </table>
    </div>

    <?php if (($quote['terms'] ?? '') !== ''): ?>
        <h2>Terms</h2>
        <p><?= nl2br(e((string) $quote['terms']), false) ?></p>
    <?php endif; ?>
</section>
