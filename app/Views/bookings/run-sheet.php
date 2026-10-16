<?php

declare(strict_types=1);

/**
 * The run sheet.
 *
 * The one page in this application somebody reads while holding a tray. It is
 * written to be scanned in four seconds by a seventeen-year-old party host on
 * a loud Saturday, which is why:
 *
 *   - ALLERGIES COME FIRST, in bold, before the name of the party. Everything
 *     else on this sheet can be got wrong and fixed; that one cannot.
 *   - It prints on one page. The stylesheet's @media print block drops the
 *     sidebar, the top bar and every button, so what comes out of the printer
 *     is this document alone.
 *   - It carries no figure a host does not need. The balance is here because
 *     somebody has to ask for it on the day; nothing else about the money is.
 *     A host without `view_revenue` sees the timings, the guests and the
 *     allergies and no money at all — a nut allergy is not financial
 *     information, and that is the whole reason the two are separated.
 *
 * @var array<string,mixed>       $booking
 * @var list<array<string,mixed>> $addons
 * @var list<array<string,mixed>> $rooms
 * @var list<array<string,mixed>> $attractions
 * @var list<array<string,mixed>> $guests
 * @var list<array<string,mixed>> $tasks
 * @var array<string,string>      $venue
 * @var bool                      $showMoney
 * @var callable(string):string   $dateLabel
 * @var callable(string):string   $timeLabel
 */

$allergies = trim((string) ($booking['allergies'] ?? ''));
$dietary   = trim((string) ($booking['dietary_notes'] ?? ''));
$cake      = trim((string) ($booking['cake_notes'] ?? ''));
$words     = static fn (string $value): string => ucfirst(str_replace('_', ' ', $value));
$start     = $timeLabel((string) ($booking['start_time'] ?? ''));
$end       = $timeLabel((string) ($booking['end_time'] ?? ''));

?>
<?php
/**
 * No print button: the Content-Security-Policy forbids inline script and a
 * static gate forbids an inline handler, so window.print() has nowhere to
 * live — and every browser already has Ctrl/Cmd-P. The quote's printable copy
 * made the same call.
 */
?>
<div class="page-actions">
    <p class="page-lede">
        <a href="<?= e(url('/bookings/' . (int) $booking['id'])) ?>">Back to the booking</a>
        &middot; print this page with Ctrl-P (Cmd-P on a Mac)
    </p>
</div>

<article class="card">
    <?php if ($allergies !== '' || $dietary !== ''): ?>
        <div class="alert alert-warning">
            <p>
                <?php if ($allergies !== ''): ?>
                    <strong style="font-size:1.15em">ALLERGIES: <?= e($allergies) ?></strong>
                <?php endif; ?>
                <?php if ($dietary !== ''): ?>
                    <?= $allergies !== '' ? '<br>' : '' ?><strong>Dietary: <?= e($dietary) ?></strong>
                <?php endif; ?>
            </p>
        </div>
    <?php endif; ?>

    <h1><?= e(trim((string) ($booking['guest_of_honor_name'] ?? '')) ?: $words((string) $booking['type'])) ?>
        <?php if (($booking['guest_of_honor_age'] ?? null) !== null): ?>
            &mdash; turning <?= e((string) $booking['guest_of_honor_age']) ?>
        <?php endif; ?>
    </h1>

    <p class="page-lede">
        <?= e($dateLabel((string) $booking['event_date'])) ?>
        <?php if ($start !== ''): ?>
            &middot; <?= e($start) ?><?= $end === '' ? '' : ' to ' . e($end) ?>
        <?php endif; ?>
        &middot; <?= e(number_format((int) $booking['guest_count'])) ?> guests
        <?php if ($rooms !== []): ?>
            &middot; <?= e(implode(', ', array_map(static fn (array $r): string => (string) $r['name'], $rooms))) ?>
        <?php endif; ?>
    </p>

    <dl class="definition-list">
        <div>
            <dt>Booking</dt>
            <dd>
                <?= e((string) $booking['booking_number']) ?>
                &middot; <?= e($words((string) $booking['status'])) ?>
                <?php if (($booking['package_name'] ?? null) !== null): ?>
                    &middot; <?= e((string) $booking['package_name']) ?>
                <?php endif; ?>
            </dd>
        </div>

        <div>
            <dt>Family</dt>
            <dd>
                <?= e(trim(
                    ((string) ($booking['first_name'] ?? '')) . ' ' . ((string) ($booking['last_name'] ?? ''))
                ) ?: 'No contact on the booking') ?>
                <?php if (trim((string) ($booking['phone'] ?? '')) !== ''): ?>
                    &middot; <?= e((string) $booking['phone']) ?>
                <?php endif; ?>
            </dd>
        </div>

        <div>
            <dt>Host</dt>
            <dd><?= e((string) ($booking['host_name'] ?? '') ?: 'Not assigned') ?></dd>
        </div>

        <?php if ($cake !== ''): ?>
            <div>
                <dt>Cake</dt>
                <dd><strong><?= e($cake) ?></strong></dd>
            </div>
        <?php endif; ?>

        <?php if (trim((string) ($booking['special_requests'] ?? '')) !== ''): ?>
            <div>
                <dt>Asked for</dt>
                <dd><?= nl2br(e((string) $booking['special_requests'])) ?></dd>
            </div>
        <?php endif; ?>

        <?php if ($showMoney && (int) $booking['balance_due_cents'] > 0): ?>
            <div>
                <dt>To collect on the day</dt>
                <dd><strong><?= e(money((int) $booking['balance_due_cents'])) ?></strong></dd>
            </div>
        <?php endif; ?>
    </dl>
</article>

<article class="card">
    <h2>Timeline</h2>
    <?php if ($attractions === []): ?>
        <p class="muted">
            Nothing scheduled inside the party.
            <?php if ($start !== ''): ?>
                The room is theirs from <?= e($start) ?><?= $end === '' ? '' : ' until ' . e($end) ?>.
            <?php endif; ?>
        </p>
    <?php else: ?>
        <ul class="checklist">
            <?php foreach ($attractions as $slot): ?>
                <li>
                    <span class="checklist-body">
                        <span class="checklist-label">
                            <?= e($timeLabel((string) ($slot['start_time'] ?? '')) ?: 'Any time') ?>
                            &mdash; <?= e((string) $slot['name']) ?>
                        </span>
                        <span class="checklist-detail">
                            <?php if ($timeLabel((string) ($slot['end_time'] ?? '')) !== ''): ?>
                                until <?= e($timeLabel((string) $slot['end_time'])) ?>
                            <?php endif; ?>
                            <?php if ((int) ($slot['requires_waiver'] ?? 0) === 1): ?>
                                &middot; <strong>waiver needed</strong>
                            <?php endif; ?>
                        </span>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</article>

<?php if ($addons !== []): ?>
    <article class="card">
        <h2>Included</h2>
        <ul class="checklist">
            <?php foreach ($addons as $addon): ?>
                <li>
                    <span class="checklist-body">
                        <span class="checklist-label">
                            <?= e(rtrim(rtrim((string) $addon['qty'], '0'), '.') ?: '1') ?>
                            &times; <?= e((string) ($addon['description'] ?? 'Add-on')) ?>
                        </span>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    </article>
<?php endif; ?>

<?php if ($tasks !== []): ?>
    <article class="card">
        <h2>Checklist</h2>
        <ul class="checklist">
            <?php foreach ($tasks as $task): ?>
                <li class="<?= $task['completed_at'] !== null ? 'is-done' : '' ?>">
                    <span class="checklist-mark" aria-hidden="true">
                        <?= $task['completed_at'] !== null ? '&#10003;' : '&#9744;' ?>
                    </span>
                    <span class="checklist-body">
                        <span class="checklist-label"><?= e((string) $task['title']) ?></span>
                        <span class="checklist-detail">
                            <?= e((string) ($task['assignee'] ?? '') ?: 'Anyone') ?>
                            <?php if ($task['completed_at'] !== null): ?>
                                &middot; done
                            <?php endif; ?>
                        </span>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    </article>
<?php endif; ?>

<?php if ($guests !== []): ?>
    <article class="card">
        <h2>Guests (<?= e((string) count($guests)) ?> on the list)</h2>
        <div class="table-wrap">
            <table class="table">
                <caption class="sr-only">Guests expected, for ticking off at the door</caption>
                <thead>
                    <tr>
                        <th scope="col">In</th>
                        <th scope="col">Name</th>
                        <th scope="col">Age</th>
                        <th scope="col">Parent</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($guests as $guest): ?>
                        <tr>
                            <td><?= $guest['checked_in_at'] !== null ? '&#10003;' : '&#9744;' ?></td>
                            <td><?= e((string) $guest['name']) ?></td>
                            <td><?= e(($guest['age'] ?? null) === null ? '' : (string) $guest['age']) ?></td>
                            <td>
                                <?= e((string) ($guest['parent_name'] ?? '')) ?>
                                <?php if (trim((string) ($guest['parent_phone'] ?? '')) !== ''): ?>
                                    <span class="td-sub"><?= e((string) $guest['parent_phone']) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </article>
<?php endif; ?>

<p class="muted">
    <?= e($venue['name']) ?><?= $venue['phone'] === '' ? '' : ' &middot; ' . e($venue['phone']) ?>
    &middot; printed <?= e(local_time(now_utc())) ?>
</p>
