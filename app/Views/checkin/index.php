<?php

declare(strict_types=1);

/**
 * Today's parties, for the desk.
 *
 * Written for somebody standing at a counter with a queue forming: the time
 * and the room first, the allergy note impossible to miss, and no figures
 * anywhere on the page. A member of the front desk holds `checkin` and no
 * `view_revenue`, and nothing here needs money to be useful.
 *
 * @var string                    $date
 * @var string                    $today
 * @var string                    $previous
 * @var string                    $next
 * @var string                    $label
 * @var list<array<string,mixed>> $bookings
 * @var string                    $waiverUrl
 * @var bool                      $hasWaiver
 * @var callable(string):string   $timeLabel
 */

?>
<div class="page-actions">
    <p class="page-lede">
        <strong><?= e($label) ?></strong>
        <?= $date === $today ? '' : '— not today' ?>
    </p>
    <a class="btn btn-ghost btn-sm" href="<?= e(url('/checkin?date=' . $previous)) ?>" rel="prev">
        &larr; <span class="sr-only">Previous day</span>
    </a>
    <a class="btn btn-ghost btn-sm" href="<?= e(url('/checkin?date=' . $next)) ?>" rel="next">
        <span class="sr-only">Next day</span> &rarr;
    </a>
    <?php if ($date !== $today): ?>
        <a class="btn btn-sm" href="<?= e(url('/checkin')) ?>">Today</a>
    <?php endif; ?>
</div>

<?php if (!$hasWaiver): ?>
    <div class="alert alert-warning">
        <p>
            No waiver has been published, so there is nothing for guests to sign. Somebody with
            the waivers permission can publish one in <a href="<?= e(url('/waivers')) ?>">Waivers</a>.
        </p>
    </div>
<?php endif; ?>

<?php if ($bookings === []): ?>
    <p class="empty">Nothing booked for <?= e($label) ?>.</p>
<?php else: ?>
    <?php foreach ($bookings as $booking): ?>
        <?php
        $id        = (int) $booking['id'];
        $allergies = trim((string) ($booking['allergies'] ?? ''));
        $listed    = (int) $booking['listed'];
        $arrived   = (int) $booking['arrived'];
        $waivered  = (int) $booking['waivered'];
        $start     = $timeLabel((string) ($booking['start_time'] ?? ''));
        ?>
        <div class="card">
            <div class="page-actions">
                <p class="page-lede">
                    <strong><?= e($start === '' ? 'No time set' : $start) ?></strong>
                    &middot;
                    <?= e(trim((string) ($booking['guest_of_honor_name'] ?? ''))
                        ?: ucfirst(str_replace('_', ' ', (string) $booking['type']))) ?>
                    &middot; <?= e((string) (int) $booking['guest_count']) ?> expected
                    <?php if (($booking['rooms'] ?? null) !== null): ?>
                        &middot; <?= e((string) $booking['rooms']) ?>
                    <?php endif; ?>
                </p>
                <a class="btn" href="<?= e(url('/checkin/' . $id)) ?>">Check guests in</a>
            </div>

            <?php if ($allergies !== ''): ?>
                <div class="alert alert-warning">
                    <p><strong>ALLERGIES: <?= e($allergies) ?></strong></p>
                </div>
            <?php endif; ?>

            <p class="muted">
                <?= e((string) $arrived) ?> of <?= e((string) $listed) ?> listed guests in.
                <?php if ($listed === 0): ?>
                    No names on the list — add them as they arrive.
                <?php elseif ($waivered < $listed): ?>
                    <?= e((string) ($listed - $waivered)) ?> without a waiver on file.
                <?php else: ?>
                    Every listed guest has a waiver.
                <?php endif; ?>
                <?php if (($booking['host_name'] ?? null) !== null): ?>
                    Host: <?= e((string) $booking['host_name']) ?>.
                <?php endif; ?>
                <?php if (trim((string) ($booking['phone'] ?? '')) !== ''): ?>
                    Family: <?= e((string) $booking['phone']) ?>.
                <?php endif; ?>
            </p>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
