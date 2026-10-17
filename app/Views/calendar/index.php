<?php

declare(strict_types=1);

/**
 * The diary, in three shapes.
 *
 * Month and week are built as TABLES rather than CSS grids, and that is a
 * deliberate accessibility choice rather than a shortcut: a calendar really is
 * tabular data — dates across, weeks down — and a screen reader announces
 * "Saturday 11 July, 3 bookings" from a table with proper headers while it
 * announces nothing useful from a grid of divs. The day view is a table too,
 * with the rooms as row headers.
 *
 * @var string                                     $view
 * @var string                                     $date
 * @var string                                     $today
 * @var string                                     $from
 * @var string                                     $to
 * @var string                                     $title
 * @var string                                     $previous
 * @var string                                     $next
 * @var list<list<array<string,mixed>>>            $grid
 * @var list<array<string,mixed>>                  $days
 * @var list<array<string,mixed>>                  $lanes
 * @var array<string,mixed>|null                   $dayHours
 * @var array<string,list<array<string,mixed>>>    $bookings
 * @var bool                                       $showMoney
 * @var bool                                       $canManage
 * @var string|null                                $feedUrl
 * @var callable(string):string                    $dateLabel
 * @var callable(string):string                    $timeLabel
 */

$words = static fn (string $value): string => ucfirst(str_replace('_', ' ', $value));

$dayNames = App\Controllers\CalendarController::DAY_NAMES;

/** One booking, as it appears in a month cell or a week column. */
$chip = static function (array $booking) use ($timeLabel, $words): string {
    $name = trim((string) ($booking['guest_of_honor_name'] ?? ''));
    $name = $name === '' ? $words((string) $booking['type']) : $name;
    $time = $timeLabel((string) ($booking['start_time'] ?? ''));
    $off  = in_array((string) $booking['status'], ['cancelled', 'no_show'], true);

    $label = ($time === '' ? '' : $time . ' ') . $name;

    return '<a class="cal-chip cal-' . e((string) $booking['status']) . ($off ? ' cal-off' : '')
        . '" href="' . e(url('/bookings/' . (int) $booking['id'])) . '">'
        . ($off ? '<del>' . e($label) . '</del>' : e($label))
        . '<span class="sr-only"> — ' . e($words((string) $booking['status']))
        . ', ' . e((string) (int) $booking['guest_count']) . ' guests'
        . (($booking['rooms'] ?? null) === null ? '' : ', ' . e((string) $booking['rooms']))
        . '</span></a>';
};

$link = static function (string $targetView, string $targetDate) use ($view, $date): string {
    return url('/calendar?view=' . $targetView . '&date=' . $targetDate);
};

?>
<div class="page-actions">
    <p class="page-lede">
        Every party, group and event on the books.
        <a href="<?= e(url('/bookings')) ?>">The list</a> carries the filters and the figures.
    </p>
    <?php if ($canManage): ?>
        <a class="btn" href="<?= e(url('/bookings/new?date=' . $date)) ?>">New booking</a>
    <?php endif; ?>
</div>

<div class="card filter-bar">
    <p class="page-lede">
        <?php foreach (['month' => 'Month', 'week' => 'Week', 'day' => 'Day'] as $key => $label): ?>
            <a class="btn btn-sm <?= $view === $key ? '' : 'btn-ghost' ?>"
               href="<?= e($link($key, $date)) ?>"
                <?= $view === $key ? 'aria-current="page"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
    </p>

    <p class="page-lede">
        <a class="btn btn-ghost btn-sm" href="<?= e($link($view, $previous)) ?>"
           rel="prev">&larr; <span class="sr-only">Previous <?= e($view) ?></span></a>
        <strong><?= e($title) ?></strong>
        <a class="btn btn-ghost btn-sm" href="<?= e($link($view, $next)) ?>"
           rel="next"><span class="sr-only">Next <?= e($view) ?></span> &rarr;</a>
        <a class="btn btn-ghost btn-sm" href="<?= e($link($view, $today)) ?>">Today</a>
    </p>

    <form method="get" action="<?= e(url('/calendar')) ?>" class="field field-actions">
        <input type="hidden" name="view" value="<?= e($view) ?>">
        <label for="jump" class="sr-only">Jump to a date</label>
        <input type="date" id="jump" name="date" value="<?= e($date) ?>">
        <button type="submit" class="btn btn-sm">Go</button>
    </form>
</div>

<?php if ($view === 'month'): ?>
    <div class="table-wrap">
        <table class="table cal-month">
            <caption class="sr-only"><?= e($title) ?>, one week per row</caption>
            <thead>
                <tr>
                    <?php foreach ($dayNames as $name): ?>
                        <th scope="col">
                            <abbr title="<?= e($name) ?>"><?= e(substr($name, 0, 3)) ?></abbr>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($grid as $week): ?>
                    <tr>
                        <?php foreach ($week as $cell): ?>
                            <td class="cal-cell<?= $cell['in_month'] ? '' : ' cal-outside' ?><?=
                                $cell['is_today'] ? ' cal-today' : '' ?>">
                                <a class="cal-date" href="<?= e($link('day', $cell['date'])) ?>">
                                    <?= e((string) $cell['number']) ?>
                                    <span class="sr-only"><?= e($dateLabel($cell['date'])) ?></span>
                                </a>
                                <?php foreach (array_slice($cell['bookings'], 0, 4) as $booking): ?>
                                    <?= $chip($booking) ?>
                                <?php endforeach; ?>
                                <?php if (count($cell['bookings']) > 4): ?>
                                    <a class="cal-more" href="<?= e($link('day', $cell['date'])) ?>">
                                        <?= e((string) (count($cell['bookings']) - 4)) ?> more
                                    </a>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($view === 'week'): ?>
    <div class="table-wrap">
        <table class="table cal-week">
            <caption class="sr-only"><?= e($title) ?></caption>
            <thead>
                <tr>
                    <?php foreach ($days as $day): ?>
                        <th scope="col" class="<?= $day['is_today'] ? 'cal-today' : '' ?>">
                            <a href="<?= e($link('day', $day['date'])) ?>">
                                <?= e((string) $day['name']) ?>
                                <span class="td-sub"><?= e(substr($day['date'], 8, 2)) ?></span>
                            </a>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <?php foreach ($days as $day): ?>
                        <td class="cal-cell<?= $day['is_today'] ? ' cal-today' : '' ?>">
                            <?php if ($day['bookings'] === []): ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                            <?php foreach ($day['bookings'] as $booking): ?>
                                <?= $chip($booking) ?>
                            <?php endforeach; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            </tbody>
        </table>
    </div>

<?php else: ?>
    <?php if ($dayHours === null): ?>
        <p class="empty">
            The venue is closed on <?= e($dateLabel($date)) ?> and nothing is booked.
        </p>
    <?php else: ?>
        <p class="muted">
            Open <?= e((string) $dayHours['opens_label']) ?> to
            <?= e((string) $dayHours['closes_label']) ?>.
        </p>

        <div class="table-wrap">
            <table class="table cal-lanes">
                <caption class="sr-only">
                    Rooms on <?= e($dateLabel($date)) ?>, and what is in each
                </caption>
                <thead>
                    <tr>
                        <th scope="col">Room</th>
                        <th scope="col">What is in it</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lanes as $lane): ?>
                        <tr>
                            <th scope="row">
                                <?= e((string) $lane['room']['name']) ?>
                                <span class="td-sub">
                                    <?= e($words((string) $lane['room']['type'])) ?>
                                    <?php if (($lane['room']['capacity'] ?? null) !== null): ?>
                                        &middot; holds <?= e((string) $lane['room']['capacity']) ?>
                                    <?php endif; ?>
                                </span>
                            </th>
                            <td>
                                <?php if ($lane['bookings'] === []): ?>
                                    <span class="muted">Free all day</span>
                                <?php endif; ?>
                                <?php foreach ($lane['bookings'] as $booking): ?>
                                    <?php
                                    $start = $timeLabel((string) ($booking['start_time'] ?? ''));
                                    $end   = $timeLabel((string) ($booking['end_time'] ?? ''));
                                    $off   = in_array((string) $booking['status'], ['cancelled', 'no_show'], true);
                                    $name  = trim((string) ($booking['guest_of_honor_name'] ?? ''))
                                        ?: $words((string) $booking['type']);
                                    ?>
                                    <a class="cal-chip cal-<?= e((string) $booking['status']) ?><?=
                                        $off ? ' cal-off' : '' ?>"
                                       href="<?= e(url('/bookings/' . (int) $booking['id'])) ?>">
                                        <?php if ($off): ?><del><?php endif; ?>
                                        <?= e($start) ?><?= $end === '' ? '' : '–' . e($end) ?>
                                        <?= e($name) ?>
                                        (<?= e((string) (int) $booking['guest_count']) ?>)
                                        <?php if ($off): ?></del><?php endif; ?>
                                    </a>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php endif; ?>

<div class="card">
    <h2>Subscribe on your phone</h2>
    <?php if ($feedUrl === null): ?>
        <p class="muted">
            A calendar address lets you see the diary in your own calendar application. It
            carries the time, the room, the headcount and the name on each party — and
            deliberately not the allergy notes, the money or the family's details, because the
            address is the only thing protecting it and such a link gets shared.
        </p>
        <form method="post" action="<?= e(url('/calendar/feed')) ?>" class="form">
            <?= csrf_field() ?>
            <div class="form-actions">
                <button type="submit" class="btn">Create my calendar address</button>
            </div>
        </form>
    <?php else: ?>
        <p class="muted">
            You have a calendar address. It is stored hashed, so it cannot be shown again —
            create a new one if you have lost it, which also stops the old one working.
        </p>
        <form method="post" action="<?= e(url('/calendar/feed')) ?>" class="form">
            <?= csrf_field() ?>
            <div class="form-actions">
                <button type="submit" class="btn btn-ghost">Replace it</button>
            </div>
        </form>
        <form method="post" action="<?= e(url('/calendar/feed/revoke')) ?>" class="form">
            <?= csrf_field() ?>
            <div class="form-actions">
                <button type="submit" class="btn btn-ghost btn-danger">Stop it working</button>
            </div>
        </form>
    <?php endif; ?>
</div>
