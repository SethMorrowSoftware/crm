<?php

declare(strict_types=1);

/**
 * @var array<string,mixed>            $quote
 * @var list<array<string,mixed>>      $lines
 * @var array<string,string>           $venue
 * @var array{at:string,by:string}|null $sentBy
 * @var list<string>                   $next
 * @var bool                           $sendable
 * @var bool                           $editable
 * @var array{name:string,email:string,contact_id:int|null,address:list<string>} $recipient
 * @var callable(?string):string       $calendarDate
 */

$id      = (int) $quote['id'];
$status  = (string) $quote['status'];
$expires = $quote['valid_until'] === null ? null : (string) $quote['valid_until'];
$expired = $expires !== null && $expires < gmdate('Y-m-d');
?>
<p class="breadcrumbs"><a href="<?= e(url('/quotes')) ?>">Quotes</a> / <?= e((string) $quote['number']) ?></p>

<div class="page-actions">
    <p class="page-lede">
        <span class="badge <?= e(match ($status) {
            'accepted'       => 'badge-ok',
            'sent', 'viewed' => 'badge-warn',
            'declined'       => 'badge-bad',
            default          => 'badge-muted',
        }) ?>"><?= e(ucfirst($status)) ?></span>
        Raised by <?= e((string) ($quote['created_by_name'] ?? 'a deleted account')) ?>
        on <?= e(local_date((string) $quote['created_at'])) ?>.
        <?php if ($sentBy !== null): ?>
            Sent by <?= e($sentBy['by']) ?> on <?= e(local_time($sentBy['at'])) ?>.
        <?php endif; ?>
    </p>
    <a class="btn btn-ghost" href="<?= e(url('/quotes/' . $id . '/print')) ?>">
        <?= icon('external', 16) ?> Printable copy
    </a>
</div>

<?php if ($expired && $status !== 'accepted' && $status !== 'declined'): ?>
    <div class="alert alert-warning">
        <?= icon('alert') ?>
        <p>
            This quote expired on <?= e($calendarDate($expires)) ?>. Re-price it and move it back
            to draft before sending it again, or the guest is reading figures the venue no
            longer stands behind.
        </p>
    </div>
<?php endif; ?>

<section class="card">
    <div class="card-head">
        <h2>Quote <?= e((string) $quote['number']) ?></h2>
        <?php if ($editable && can('deals_manage')): ?>
            <a class="btn btn-ghost btn-sm" href="<?= e(url('/quotes/' . $id . '/edit')) ?>">
                <?= icon('edit', 15) ?> Edit
            </a>
        <?php endif; ?>
    </div>

    <dl class="definition-list">
        <dt>From</dt>
        <dd>
            <?= e($venue['name']) ?>
            <?php foreach (['line1', 'line2', 'city'] as $part): ?>
                <?php if ($venue[$part] !== ''): ?><br><?= e($venue[$part]) ?><?php endif; ?>
            <?php endforeach; ?>
            <?php if ($venue['phone'] !== ''): ?><br><?= e($venue['phone']) ?><?php endif; ?>
        </dd>

        <dt>For</dt>
        <dd>
            <?php if ($quote['deal_title'] !== null): ?>
                <?= e((string) $quote['deal_title']) ?>
                <?php if ($recipient['name'] !== ''): ?><br><?= e($recipient['name']) ?><?php endif; ?>
                <?php foreach ($recipient['address'] as $addressLine): ?>
                    <br><?= e($addressLine) ?>
                <?php endforeach; ?>
                <?php if ($recipient['email'] !== ''): ?><br><?= e($recipient['email']) ?><?php endif; ?>
            <?php else: ?>
                <span class="muted">
                    No deal is attached, so this quote has no guest on file. It can be printed,
                    or mailed to an address typed below.
                </span>
            <?php endif; ?>
        </dd>

        <?php if ($quote['event_date'] !== null): ?>
            <dt>Event date</dt>
            <dd>
                <?= e($calendarDate((string) $quote['event_date'])) ?>
                <?php if ($quote['guest_count'] !== null): ?>
                    &middot; <?= (int) $quote['guest_count'] ?> guests
                <?php endif; ?>
            </dd>
        <?php endif; ?>

        <dt>Expires</dt>
        <dd><?= $expires === null ? 'No expiry date' : e($calendarDate($expires)) ?></dd>
    </dl>
</section>

<section class="card">
    <div class="card-head"><h2>Lines</h2></div>

    <?php if ($lines === []): ?>
        <p class="empty">No lines yet, so this quote totals nothing. Edit it to add some.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <caption class="sr-only">Lines on this quote</caption>
                <thead>
                    <tr>
                        <th scope="col">Description</th>
                        <th scope="col">Qty</th>
                        <th scope="col">Unit</th>
                        <th scope="col">Line total</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($lines as $line): ?>
                    <tr>
                        <td><?= e((string) $line['description']) ?></td>
                        <td><?= e(rtrim(rtrim((string) $line['qty'], '0'), '.') ?: '0') ?></td>
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
                            <th scope="row" colspan="3">Deposit due now</th>
                            <td><?= e(money((int) $quote['deposit_cents'])) ?></td>
                        </tr>
                    <?php endif; ?>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php if (($quote['terms'] ?? '') !== ''): ?>
    <section class="card">
        <div class="card-head"><h2>Terms</h2></div>
        <p><?= nl2br(e((string) $quote['terms']), false) ?></p>
    </section>
<?php endif; ?>

<?php if (($quote['notes'] ?? '') !== '' && can('deals_manage')): ?>
    <section class="card">
        <div class="card-head"><h2>Internal notes</h2></div>
        <p class="muted">Not printed, never sent.</p>
        <p><?= nl2br(e((string) $quote['notes']), false) ?></p>
    </section>
<?php endif; ?>

<?php if (can('quotes_send')): ?>
    <section class="card">
        <div class="card-head"><h2>Send it</h2></div>

        <?php if (!$sendable): ?>
            <p class="empty">
                A quote that is <?= e($status) ?> cannot be sent. Put it back to draft first.
            </p>
        <?php else: ?>
            <form method="post" action="<?= e(url('/quotes/' . $id . '/send')) ?>" class="form">
                <?= csrf_field() ?>
                <div class="field">
                    <label for="to">Send to</label>
                    <input type="email" id="to" name="to" value="<?= e($recipient['email']) ?>"
                           <?= $recipient['email'] === '' ? 'required' : '' ?>>
                    <p class="field-hint">
                        Goes out through the mailer, so Safe Mode, the suppression list and a
                        guest's do-not-contact flag all still apply. The quote is rendered into
                        the message itself.
                    </p>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn">
                        <?= icon('message', 16) ?>
                        <?= $status === 'draft' ? 'Send the quote' : 'Send it again' ?>
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($next !== [] && can('deals_manage')): ?>
    <section class="card">
        <div class="card-head"><h2>Where it stands</h2></div>
        <p class="muted">
            Only these moves are open from <?= e($status) ?>.
            An accepted quote is final — a booking is built on its figures.
        </p>
        <div class="row-actions">
            <?php foreach ($next as $target): ?>
                <form method="post" action="<?= e(url('/quotes/' . $id . '/status')) ?>" class="inline-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="status" value="<?= e($target) ?>">
                    <button type="submit" class="btn btn-ghost btn-sm">
                        <?= e(match ($target) {
                            'viewed'   => 'Mark as viewed',
                            'accepted' => 'Mark as accepted',
                            'declined' => 'Mark as declined',
                            'expired'  => 'Mark as expired',
                            'draft'    => 'Back to draft',
                            default    => ucfirst($target),
                        }) ?>
                    </button>
                </form>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>
