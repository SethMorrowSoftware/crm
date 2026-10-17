<?php

declare(strict_types=1);

/**
 * The booking list.
 *
 * Ordered by event date ASCENDING, unlike every other list in the
 * application: a booking list is a diary, and the thing a venue needs at the
 * top of it is the next party, not the most recently typed one. The past view
 * reverses it for the same reason.
 *
 * @var list<array<string,mixed>> $bookings
 * @var int                       $total
 * @var int                       $page
 * @var int                       $lastPage
 * @var array<string,mixed>       $filters
 * @var list<string>              $types
 * @var list<string>              $statuses
 * @var list<array<string,mixed>> $hosts
 * @var bool                      $canManage
 * @var bool                      $showMoney
 * @var array<string,int>         $summary
 * @var callable(string):string   $dateLabel
 * @var callable(string):string   $timeLabel
 */

$badge = static fn (string $status): string => match ($status) {
    'paid', 'completed'       => 'badge-ok',
    'deposit_paid', 'confirmed' => 'badge-ok',
    'held', 'inquiry'         => 'badge-warn',
    'cancelled', 'no_show'    => 'badge-bad',
    default                   => 'badge-muted',
};

$words = static fn (string $value): string => ucfirst(str_replace('_', ' ', $value));

$views = [
    'upcoming'   => 'Upcoming',
    'today'      => 'Today',
    'unassigned' => 'No host',
    'past'       => 'Past',
    'all'        => 'All',
];

?>
<div class="page-actions">
    <p class="page-lede">
        Every party, group and event the venue has on the books. The run sheet for each one
        is on its page.
    </p>
    <?php if ($canManage): ?>
        <a class="btn" href="<?= e(url('/bookings/new')) ?>">New booking</a>
    <?php endif; ?>
</div>

<div class="stat-row">
    <div class="stat">
        <span class="stat-label">Bookings</span>
        <span class="stat-value"><?= e(number_format($summary['bookings'])) ?></span>
        <span class="stat-note"><?= e($views[$filters['view']] ?? 'All') ?></span>
    </div>
    <div class="stat">
        <span class="stat-label">Guests expected</span>
        <span class="stat-value"><?= e(number_format($summary['guests'])) ?></span>
        <span class="stat-note">Across those bookings</span>
    </div>
    <?php if ($showMoney): ?>
        <div class="stat">
            <span class="stat-label">Booked value</span>
            <span class="stat-value"><?= e(money((int) ($summary['total_cents'] ?? 0))) ?></span>
            <span class="stat-note">Including tax</span>
        </div>
        <div class="stat">
            <span class="stat-label">Still owed</span>
            <span class="stat-value"><?= e(money((int) ($summary['balance_cents'] ?? 0))) ?></span>
            <span class="stat-note">Balance due on the day</span>
        </div>
    <?php endif; ?>
</div>

<nav class="page-actions" aria-label="Which bookings">
    <p class="page-lede">
        <?php foreach ($views as $key => $label): ?>
            <?php
            $query = array_filter([
                'view'   => $key,
                'status' => $filters['status'],
                'type'   => $filters['type'],
                'host'   => $filters['host'] > 0 ? (string) $filters['host'] : '',
                'q'      => $filters['q'],
            ], static fn (string $value): bool => $value !== '');
            ?>
            <a class="btn btn-sm <?= $filters['view'] === $key ? '' : 'btn-ghost' ?>"
               href="<?= e(url('/bookings' . ($query === [] ? '' : '?' . http_build_query($query)))) ?>"
                <?= $filters['view'] === $key ? 'aria-current="page"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
    </p>
</nav>

<form class="card filter-bar" method="get" action="<?= e(url('/bookings')) ?>">
    <input type="hidden" name="view" value="<?= e((string) $filters['view']) ?>">

    <div class="field">
        <label for="q">Search</label>
        <input type="search" id="q" name="q" value="<?= e((string) $filters['q']) ?>"
               placeholder="Booking number, guest of honour or family name">
    </div>

    <div class="field">
        <label for="status">Status</label>
        <select id="status" name="status">
            <option value="">Any status</option>
            <?php foreach ($statuses as $status): ?>
                <option value="<?= e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>>
                    <?= e($words($status)) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="field">
        <label for="type">Type</label>
        <select id="type" name="type">
            <option value="">Any type</option>
            <?php foreach ($types as $type): ?>
                <option value="<?= e($type) ?>" <?= $filters['type'] === $type ? 'selected' : '' ?>>
                    <?= e($words($type)) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="field">
        <label for="host">Host</label>
        <select id="host" name="host">
            <option value="">Anyone</option>
            <?php foreach ($hosts as $host): ?>
                <option value="<?= e((string) $host['id']) ?>"
                    <?= $filters['host'] === (int) $host['id'] ? 'selected' : '' ?>>
                    <?= e((string) $host['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="field-actions">
        <button type="submit" class="btn btn-sm">Filter</button>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('/bookings?view=' . $filters['view'])) ?>">Clear</a>
    </div>
</form>

<?php if ($bookings === []): ?>
    <p class="empty">
        <?php if ($filters['q'] !== '' || $filters['status'] !== '' || $filters['type'] !== ''): ?>
            Nothing matches those filters.
        <?php elseif ($filters['view'] === 'today'): ?>
            Nothing booked for today.
        <?php else: ?>
            No bookings yet.
            <?php if ($canManage): ?>
                <a href="<?= e(url('/bookings/new')) ?>">Take the first one</a>.
            <?php endif; ?>
        <?php endif; ?>
    </p>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <caption class="sr-only">Bookings, soonest first</caption>
            <thead>
                <tr>
                    <th scope="col">When</th>
                    <th scope="col">Booking</th>
                    <th scope="col">Family</th>
                    <th scope="col">Guests</th>
                    <th scope="col">Room</th>
                    <?php if ($showMoney): ?>
                        <th scope="col">Total</th>
                        <th scope="col">Owed</th>
                    <?php endif; ?>
                    <th scope="col">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bookings as $booking): ?>
                    <?php
                    $id        = (int) $booking['id'];
                    $start     = (string) ($booking['start_time'] ?? '');
                    $startLabel = $timeLabel($start);
                    $allergies = trim((string) ($booking['allergies'] ?? ''));
                    ?>
                    <tr>
                        <td>
                            <a href="<?= e(url('/bookings/' . $id)) ?>">
                                <?= e($dateLabel((string) $booking['event_date'])) ?>
                            </a>
                            <?php if ($startLabel !== ''): ?>
                                <span class="td-sub"><?= e($startLabel) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= e((string) $booking['booking_number']) ?>
                            <span class="td-sub"><?= e($words((string) $booking['type'])) ?></span>
                        </td>
                        <td>
                            <?php if (trim((string) ($booking['guest_of_honor_name'] ?? '')) !== ''): ?>
                                <?= e((string) $booking['guest_of_honor_name']) ?>
                            <?php endif; ?>
                            <span class="td-sub">
                                <?= e(trim(
                                    ((string) ($booking['first_name'] ?? '')) . ' '
                                    . ((string) ($booking['last_name'] ?? ''))
                                ) ?: 'No contact linked') ?>
                            </span>
                        </td>
                        <td>
                            <?= e(number_format((int) $booking['guest_count'])) ?>
                            <?php if ($allergies !== ''): ?>
                                <span class="td-sub">
                                    <strong>Allergies</strong>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= e((string) ($booking['rooms'] ?? '') ?: '—') ?>
                            <?php if (($booking['host_name'] ?? null) !== null): ?>
                                <span class="td-sub"><?= e((string) $booking['host_name']) ?></span>
                            <?php endif; ?>
                        </td>
                        <?php if ($showMoney): ?>
                            <td><?= e(money((int) $booking['total_cents'])) ?></td>
                            <td>
                                <?php if ((int) $booking['balance_due_cents'] > 0): ?>
                                    <?= e(money((int) $booking['balance_due_cents'])) ?>
                                <?php else: ?>
                                    <span class="muted">Clear</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <td>
                            <span class="badge <?= e($badge((string) $booking['status'])) ?>">
                                <?= e($words((string) $booking['status'])) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($lastPage > 1): ?>
        <nav class="pagination" aria-label="Pages">
            <?php
            $pageQuery = static function (int $target) use ($filters): string {
                $query = array_filter([
                    'view'   => $filters['view'],
                    'status' => $filters['status'],
                    'type'   => $filters['type'],
                    'host'   => $filters['host'] > 0 ? (string) $filters['host'] : '',
                    'q'      => $filters['q'],
                    'page'   => (string) $target,
                ], static fn (string $value): bool => $value !== '');

                return url('/bookings?' . http_build_query($query));
            };
            ?>
            <?php if ($page > 1): ?>
                <a class="btn btn-ghost btn-sm" href="<?= e($pageQuery($page - 1)) ?>">Previous</a>
            <?php endif; ?>
            <span class="pagination-info">Page <?= e((string) $page) ?> of <?= e((string) $lastPage) ?></span>
            <?php if ($page < $lastPage): ?>
                <a class="btn btn-ghost btn-sm" href="<?= e($pageQuery($page + 1)) ?>">Next</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
