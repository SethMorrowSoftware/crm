<?php

declare(strict_types=1);

/**
 * One booking, as the front desk reads it.
 *
 * Laid out in the order the questions get asked on the phone: when and where,
 * who, what they are getting, what is owed, and what still has to be done
 * before the day. The allergy note sits above all of it, because that is the
 * one piece of information on this page that can hurt somebody.
 *
 * @var array<string,mixed>       $booking
 * @var list<array<string,mixed>> $addons
 * @var list<array<string,mixed>> $rooms
 * @var list<array<string,mixed>> $attractions
 * @var list<array<string,mixed>> $guests
 * @var list<array<string,mixed>> $tasks
 * @var list<array<string,mixed>> $payments
 * @var array{required:bool,signed:int,guests:int,attractions:list<string>} $waivers
 * @var list<string>              $next
 * @var bool                      $canManage
 * @var bool                      $canCancel
 * @var bool                      $canPay
 * @var bool                      $canCheckIn
 * @var bool                      $showMoney
 * @var bool                      $priceable
 * @var bool                      $liveStatus
 * @var string                    $taxLabel
 * @var list<array<string,mixed>> $hosts
 * @var callable(string):string   $dateLabel
 * @var callable(string):string   $timeLabel
 */

$id        = (int) $booking['id'];
$status    = (string) $booking['status'];
$words     = static fn (string $value): string => ucfirst(str_replace('_', ' ', $value));
$allergies = trim((string) ($booking['allergies'] ?? ''));
$dietary   = trim((string) ($booking['dietary_notes'] ?? ''));
$paid      = 0;

foreach ($payments as $payment) {
    $paid += (int) $payment['amount_cents'];
}

$badge = match ($status) {
    'paid', 'completed', 'deposit_paid', 'confirmed' => 'badge-ok',
    'held', 'inquiry'                                => 'badge-warn',
    'cancelled', 'no_show'                           => 'badge-bad',
    default                                          => 'badge-muted',
};

?>
<p class="breadcrumbs">
    <a href="<?= e(url('/bookings')) ?>">Bookings</a> / <?= e((string) $booking['booking_number']) ?>
</p>

<div class="page-actions">
    <p class="page-lede">
        <span class="badge <?= e($badge) ?>"><?= e($words($status)) ?></span>
        <?= e($words((string) $booking['type'])) ?>
        on <?= e($dateLabel((string) $booking['event_date'])) ?>
        <?php if (($start = $timeLabel((string) ($booking['start_time'] ?? ''))) !== ''): ?>
            at <?= e($start) ?><?php
            $end = $timeLabel((string) ($booking['end_time'] ?? ''));
            echo $end === '' ? '' : ' until ' . e($end);
        ?>
        <?php endif; ?>
        for <?= e(number_format((int) $booking['guest_count'])) ?>
        <?= e((int) $booking['guest_count'] === 1 ? 'guest' : 'guests') ?>.
    </p>
    <a class="btn btn-ghost" href="<?= e(url('/bookings/' . $id . '/run-sheet')) ?>">Run sheet</a>
    <?php if ($canManage && $priceable): ?>
        <a class="btn" href="<?= e(url('/bookings/' . $id . '/edit')) ?>">Edit</a>
    <?php endif; ?>
</div>

<?php if ($allergies !== '' || $dietary !== ''): ?>
    <div class="alert alert-warning">
        <p>
            <?php if ($allergies !== ''): ?>
                <strong>Allergies: <?= e($allergies) ?></strong>
            <?php endif; ?>
            <?php if ($dietary !== ''): ?>
                <?= $allergies !== '' ? '<br>' : '' ?>Dietary: <?= e($dietary) ?>
            <?php endif; ?>
        </p>
    </div>
<?php endif; ?>

<?php if ($status === 'cancelled'): ?>
    <div class="alert alert-warning">
        <p>
            Cancelled <?= e(local_time((string) $booking['cancelled_at'])) ?>.
            <?php if (trim((string) ($booking['cancellation_reason'] ?? '')) !== ''): ?>
                Reason: <?= e((string) $booking['cancellation_reason']) ?>.
            <?php endif; ?>
            The room is free again, so putting this booking back needs the slot to still be
            available.
        </p>
    </div>
<?php endif; ?>

<div class="card">
    <h2>The party</h2>
    <dl class="definition-list">
        <div>
            <dt>Guest of honour</dt>
            <dd>
                <?php if (trim((string) ($booking['guest_of_honor_name'] ?? '')) !== ''): ?>
                    <?= e((string) $booking['guest_of_honor_name']) ?>
                    <?php if (($booking['guest_of_honor_age'] ?? null) !== null): ?>
                        (turning <?= e((string) $booking['guest_of_honor_age']) ?>)
                    <?php endif; ?>
                <?php else: ?>
                    <span class="muted">Not recorded</span>
                <?php endif; ?>
            </dd>
        </div>

        <div>
            <dt>Family</dt>
            <dd>
                <?php if (($booking['contact_id'] ?? null) !== null): ?>
                    <a href="<?= e(url('/contacts/' . (int) $booking['contact_id'])) ?>">
                        <?= e(trim(((string) $booking['first_name']) . ' ' . ((string) $booking['last_name']))) ?>
                    </a>
                    <?php if (trim((string) ($booking['phone'] ?? '')) !== ''): ?>
                        &middot; <?= e((string) $booking['phone']) ?>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="muted">No contact linked</span>
                <?php endif; ?>
            </dd>
        </div>

        <div>
            <dt>Package</dt>
            <dd><?= e((string) ($booking['package_name'] ?? '') ?: 'No package — priced from add-ons') ?></dd>
        </div>

        <div>
            <dt>Room</dt>
            <dd>
                <?php if ($rooms === []): ?>
                    <span class="muted">No room allocated</span>
                <?php else: ?>
                    <?= e(implode(', ', array_map(static fn (array $r): string => (string) $r['name'], $rooms))) ?>
                <?php endif; ?>
            </dd>
        </div>

        <div>
            <dt>Headcount</dt>
            <dd>
                <?= e(number_format((int) $booking['guest_count'])) ?> total
                <?php if ((int) $booking['adult_count'] > 0 || (int) $booking['child_count'] > 0): ?>
                    (<?= e((string) (int) $booking['adult_count']) ?> adults,
                    <?= e((string) (int) $booking['child_count']) ?> children)
                <?php endif; ?>
            </dd>
        </div>

        <div>
            <dt>Cake</dt>
            <dd><?= e(trim((string) ($booking['cake_notes'] ?? '')) ?: '—') ?></dd>
        </div>

        <?php if (trim((string) ($booking['special_requests'] ?? '')) !== ''): ?>
            <div>
                <dt>Asked for</dt>
                <dd><?= nl2br(e((string) $booking['special_requests'])) ?></dd>
            </div>
        <?php endif; ?>

        <?php if (trim((string) ($booking['internal_notes'] ?? '')) !== ''): ?>
            <div>
                <dt>Internal notes</dt>
                <dd><?= nl2br(e((string) $booking['internal_notes'])) ?></dd>
            </div>
        <?php endif; ?>
    </dl>
</div>

<?php if ($attractions !== []): ?>
    <div class="card">
        <h2>What happens when</h2>
        <div class="table-wrap">
            <table class="table">
                <caption class="sr-only">The attraction schedule inside the party</caption>
                <thead>
                    <tr>
                        <th scope="col">Attraction</th>
                        <th scope="col">From</th>
                        <th scope="col">Until</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($attractions as $slot): ?>
                        <tr>
                            <td>
                                <?= e((string) $slot['name']) ?>
                                <?php if ((int) $slot['requires_waiver'] === 1): ?>
                                    <span class="badge badge-warn">Waiver</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($timeLabel((string) ($slot['start_time'] ?? '')) ?: '—') ?></td>
                            <td><?= e($timeLabel((string) ($slot['end_time'] ?? '')) ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php if ($showMoney): ?>
    <div class="card">
        <h2>Money</h2>
        <div class="stat-row">
            <div class="stat">
                <span class="stat-label">Total</span>
                <span class="stat-value"><?= e(money((int) $booking['total_cents'])) ?></span>
                <span class="stat-note">Including <?= e($taxLabel) ?>% tax</span>
            </div>
            <div class="stat">
                <span class="stat-label">Taken</span>
                <span class="stat-value"><?= e(money($paid)) ?></span>
                <span class="stat-note">
                    <?php if ($booking['deposit_paid_at'] !== null): ?>
                        Deposit held
                    <?php else: ?>
                        Deposit <?= e(money((int) $booking['deposit_cents'])) ?> due
                    <?php endif; ?>
                </span>
            </div>
            <div class="stat">
                <span class="stat-label">Still owed</span>
                <span class="stat-value"><?= e(money((int) $booking['balance_due_cents'])) ?></span>
                <span class="stat-note">
                    <?= (int) $booking['balance_due_cents'] === 0 ? 'Nothing outstanding' : 'On or before the day' ?>
                </span>
            </div>
        </div>

        <div class="table-wrap">
            <table class="table">
                <caption class="sr-only">How the total is made up</caption>
                <tbody>
                    <tr>
                        <th scope="row">Package and extra guests</th>
                        <td><?= e(money((int) $booking['subtotal_cents'] - array_sum(
                            array_map(static fn (array $a): int => (int) $a['total_cents'], $addons)
                        ))) ?></td>
                    </tr>
                    <?php foreach ($addons as $addon): ?>
                        <tr>
                            <th scope="row">
                                <?= e((string) ($addon['description'] ?? 'Add-on')) ?>
                                <span class="td-sub">
                                    <?= e(rtrim(rtrim((string) $addon['qty'], '0'), '.') ?: '0') ?>
                                    &times; <?= e(money((int) $addon['unit_cents'])) ?>
                                    <?php if (($addon['unit_basis'] ?? null) !== null
                                        && $addon['unit_basis'] !== 'flat'): ?>
                                        <?= e(str_replace('_', ' ', (string) $addon['unit_basis'])) ?>
                                    <?php endif; ?>
                                </span>
                            </th>
                            <td><?= e(money((int) $addon['total_cents'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ((int) $booking['discount_cents'] > 0): ?>
                        <tr>
                            <th scope="row">Discount</th>
                            <td>-<?= e(money((int) $booking['discount_cents'])) ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <th scope="row">Tax at <?= e($taxLabel) ?>%</th>
                        <td><?= e(money((int) $booking['tax_cents'])) ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><strong>Total</strong></th>
                        <td><strong><?= e(money((int) $booking['total_cents'])) ?></strong></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <?php if ($payments !== []): ?>
            <h3>Payments</h3>
            <div class="table-wrap">
                <table class="table">
                    <caption class="sr-only">Money taken on this booking</caption>
                    <thead>
                        <tr>
                            <th scope="col">When</th>
                            <th scope="col">Amount</th>
                            <th scope="col">Type</th>
                            <th scope="col">How</th>
                            <th scope="col">Recorded by</th>
                            <?php if ($canPay): ?>
                                <th scope="col"><span class="sr-only">Actions</span></th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td><?= e(local_date((string) $payment['occurred_at'])) ?></td>
                                <td><?= e(money((int) $payment['amount_cents'])) ?></td>
                                <td><?= e($words((string) $payment['type'])) ?></td>
                                <td>
                                    <?= e((string) ($payment['method'] ?? '') ?: '—') ?>
                                    <?php if (trim((string) ($payment['reference'] ?? '')) !== ''): ?>
                                        <span class="td-sub"><?= e((string) $payment['reference']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e((string) ($payment['recorded_by_name'] ?? 'a deleted account')) ?></td>
                                <?php if ($canPay): ?>
                                    <td class="row-actions">
                                        <form method="post"
                                              action="<?= e(url('/bookings/' . $id . '/payments/delete')) ?>"
                                              class="inline-form">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="payment_id"
                                                   value="<?= e((string) $payment['id']) ?>">
                                            <button type="submit" class="btn btn-ghost btn-sm btn-danger">
                                                Remove
                                            </button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($canPay): ?>
            <h3>Record a payment</h3>
            <p class="muted">
                No card details are stored anywhere in this application. The reference is
                whatever the card machine or the hosted payment page calls the transaction.
            </p>
            <form method="post" action="<?= e(url('/bookings/' . $id . '/payments')) ?>" class="form">
                <?= csrf_field() ?>
                <div class="field">
                    <label for="amount">Amount</label>
                    <input type="text" id="amount" name="amount" inputmode="decimal"
                           placeholder="100.00" value="<?= e((string) old('amount')) ?>" required>
                </div>
                <div class="field">
                    <label for="payment_type">Type</label>
                    <select id="payment_type" name="type" required>
                        <option value="deposit">Deposit</option>
                        <option value="balance">Balance</option>
                        <option value="refund">Refund</option>
                        <option value="adjustment">Adjustment</option>
                    </select>
                </div>
                <div class="field">
                    <label for="method">How it was paid (optional)</label>
                    <input type="text" id="method" name="method" maxlength="32"
                           placeholder="Card, cash, transfer">
                </div>
                <div class="field">
                    <label for="reference">Reference (optional)</label>
                    <input type="text" id="reference" name="reference" maxlength="80">
                </div>
                <div class="field">
                    <label for="occurred_on">Date taken (optional)</label>
                    <input type="date" id="occurred_on" name="occurred_on">
                </div>
                <div class="field">
                    <label for="payment_note">Note (optional)</label>
                    <input type="text" id="payment_note" name="note" maxlength="255">
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn">Record it</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="card">
    <h2>Before the day</h2>
    <?php if ($waivers['required']): ?>
        <p class="muted">
            <?= e(implode(', ', $waivers['attractions'])) ?>
            <?= count($waivers['attractions']) === 1 ? 'needs' : 'need' ?> a signed waiver.
            <?= e((string) $waivers['signed']) ?> signed
            <?php if ($waivers['guests'] > 0): ?>
                of <?= e((string) $waivers['guests']) ?> guests listed
            <?php endif; ?>.
        </p>
    <?php endif; ?>

    <?php if ($tasks === []): ?>
        <p class="empty">Nothing on the checklist yet. Confirming the booking adds the usual items.</p>
    <?php else: ?>
        <ul class="checklist">
            <?php foreach ($tasks as $task): ?>
                <li class="<?= $task['completed_at'] !== null ? 'is-done' : '' ?>">
                    <form method="post" action="<?= e(url('/bookings/' . $id . '/tasks/toggle')) ?>"
                          class="inline-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="task_id" value="<?= e((string) $task['id']) ?>">
                        <button type="submit" class="btn btn-ghost btn-sm">
                            <?= $task['completed_at'] !== null ? 'Undo' : 'Tick off' ?>
                        </button>
                    </form>
                    <span class="checklist-body">
                        <span class="checklist-label"><?= e((string) $task['title']) ?></span>
                        <span class="checklist-detail">
                            <?= e((string) ($task['assignee'] ?? '') ?: 'Anyone') ?><?php
                            if ($task['completed_at'] !== null) {
                                echo ' &middot; done ' . e(local_date((string) $task['completed_at']));
                            }
                            ?>
                        </span>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($canManage): ?>
        <form method="post" action="<?= e(url('/bookings/' . $id . '/tasks')) ?>" class="form">
            <?= csrf_field() ?>
            <div class="field">
                <label for="task_title">Add to the checklist</label>
                <input type="text" id="task_title" name="title" maxlength="191" required
                       placeholder="Order the balloons">
            </div>
            <div class="field">
                <label for="task_assigned">Who (optional)</label>
                <select id="task_assigned" name="assigned_to">
                    <option value="">Nobody in particular</option>
                    <?php foreach ($hosts as $host): ?>
                        <option value="<?= e((string) $host['id']) ?>"><?= e((string) $host['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-sm">Add</button>
            </div>
        </form>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Guest list</h2>
    <?php if ($guests === []): ?>
        <p class="empty">No names yet. The family usually sends these a few days before.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <caption class="sr-only">Guests on this booking</caption>
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Age</th>
                        <th scope="col">Parent</th>
                        <th scope="col">RSVP</th>
                        <th scope="col">Checked in</th>
                        <?php if ($canManage): ?>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($guests as $guest): ?>
                        <tr>
                            <td><?= e((string) $guest['name']) ?></td>
                            <td><?= e(($guest['age'] ?? null) === null ? '—' : (string) $guest['age']) ?></td>
                            <td>
                                <?= e((string) ($guest['parent_name'] ?? '') ?: '—') ?>
                                <?php if (trim((string) ($guest['parent_phone'] ?? '')) !== ''): ?>
                                    <span class="td-sub"><?= e((string) $guest['parent_phone']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($words((string) $guest['rsvp_status'])) ?></td>
                            <td>
                                <?php if ($guest['checked_in_at'] !== null): ?>
                                    <?= e(local_time((string) $guest['checked_in_at'], 'g:i A')) ?>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($canManage): ?>
                                <td class="row-actions">
                                    <form method="post"
                                          action="<?= e(url('/bookings/' . $id . '/guests/delete')) ?>"
                                          class="inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="guest_id" value="<?= e((string) $guest['id']) ?>">
                                        <button type="submit" class="btn btn-ghost btn-sm btn-danger">Remove</button>
                                    </form>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($canManage): ?>
        <form method="post" action="<?= e(url('/bookings/' . $id . '/guests')) ?>" class="form">
            <?= csrf_field() ?>
            <div class="field">
                <label for="guest_name">Name</label>
                <input type="text" id="guest_name" name="name" maxlength="120" required>
            </div>
            <div class="field">
                <label for="guest_age">Age (optional)</label>
                <input type="number" id="guest_age" name="age" min="0" max="120">
            </div>
            <div class="field">
                <label for="guest_parent">Parent (optional)</label>
                <input type="text" id="guest_parent" name="parent_name" maxlength="120">
            </div>
            <div class="field">
                <label for="guest_phone">Parent's phone (optional)</label>
                <input type="tel" id="guest_phone" name="parent_phone" maxlength="32">
            </div>
            <div class="field">
                <label for="guest_rsvp">RSVP</label>
                <select id="guest_rsvp" name="rsvp_status">
                    <option value="unknown">Not heard</option>
                    <option value="yes">Coming</option>
                    <option value="maybe">Maybe</option>
                    <option value="no">Not coming</option>
                </select>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-sm">Add guest</button>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php if ($canManage || $canCancel): ?>
    <div class="card">
        <h2>Where this booking stands</h2>

        <?php if ($canManage && $next !== []): ?>
            <form method="post" action="<?= e(url('/bookings/' . $id . '/status')) ?>" class="form">
                <?= csrf_field() ?>
                <div class="field">
                    <label for="status">Move it to</label>
                    <select id="status" name="status" required>
                        <?php foreach ($next as $option): ?>
                            <option value="<?= e($option) ?>"><?= e($words($option)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn">Move it</button>
                </div>
            </form>
        <?php elseif ($canManage): ?>
            <p class="muted">
                <?php if ($status === 'completed'): ?>
                    This party has happened. Nothing moves from here.
                <?php else: ?>
                    There is nowhere to move this booking by hand from
                    <?= e($words($status)) ?>.
                <?php endif; ?>
            </p>
        <?php endif; ?>

        <?php if ($canManage && in_array($status, ['confirmed', 'deposit_paid', 'paid'], true)): ?>
            <p class="muted">
                Deposit paid and paid in full are set by recording the money, not by choosing
                them here — otherwise the run sheet can say a balance is clear when nobody has
                taken it.
            </p>
        <?php endif; ?>

        <?php if ($canCancel && $status !== 'cancelled'): ?>
            <h3>Cancel</h3>
            <form method="post" action="<?= e(url('/bookings/' . $id . '/cancel')) ?>" class="form">
                <?= csrf_field() ?>
                <div class="field">
                    <label for="cancellation_reason">Why</label>
                    <input type="text" id="cancellation_reason" name="cancellation_reason"
                           maxlength="255" required placeholder="Family cancelled, double booked, weather">
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-danger">Cancel this booking</button>
                </div>
            </form>
        <?php endif; ?>

        <?php if ($canManage): ?>
            <h3>Party host</h3>
            <form method="post" action="<?= e(url('/bookings/' . $id . '/host')) ?>" class="form">
                <?= csrf_field() ?>
                <div class="field">
                    <label for="host_user_id">Who is running it</label>
                    <select id="host_user_id" name="host_user_id">
                        <option value="">Nobody yet</option>
                        <?php foreach ($hosts as $host): ?>
                            <option value="<?= e((string) $host['id']) ?>"
                                <?= (int) ($booking['host_user_id'] ?? 0) === (int) $host['id'] ? 'selected' : '' ?>>
                                <?= e((string) $host['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-sm">Assign</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
<?php endif; ?>

<p class="muted">
    Booking <?= e((string) $booking['booking_number']) ?>, raised
    <?= e(local_date((string) $booking['created_at'])) ?>.
    <?php if ($booking['run_sheet_printed_at'] !== null): ?>
        Run sheet last printed <?= e(local_time((string) $booking['run_sheet_printed_at'])) ?>.
    <?php endif; ?>
</p>
