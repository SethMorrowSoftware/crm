<?php

declare(strict_types=1);

/**
 * Every answer a guest has given.
 *
 * @var list<array<string,mixed>> $rows
 * @var string                    $filter
 * @var int                       $days
 * @var array<int,string>         $ranges
 * @var int                       $total
 * @var array{page:int,perPage:int,offset:int} $page
 * @var int                       $slaHours
 * @var string                    $now
 * @var bool                      $canResolve
 */

$filters = [
    'all'       => 'Everything',
    'open'      => 'Open complaints',
    'detractor' => 'Unhappy',
    'passive'   => 'Passive',
    'promoter'  => 'Happy',
    'comments'  => 'With a comment',
];

$badge = [
    'promoter'  => ['badge-ok', 'happy'],
    'passive'   => ['badge-warn', 'passive'],
    'detractor' => ['badge-bad', 'unhappy'],
];
?>

<section class="card">
    <div class="card-head">
        <div>
            <h2>Guest feedback</h2>
            <p class="card-sub"><?= (int) $total ?> answer<?= $total === 1 ? '' : 's' ?>.</p>
        </div>
        <a class="btn btn-ghost" href="<?= e(url('/reputation')) ?>">Back to reputation</a>
    </div>

    <form method="get" action="<?= e(url('/reputation/feedback')) ?>" class="filter-bar">
        <div class="field">
            <label for="show">Show</label>
            <select id="show" name="show">
                <?php foreach ($filters as $value => $label): ?>
                    <option value="<?= e((string) $value) ?>" <?= $filter === $value ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="days">Period</label>
            <select id="days" name="days">
                <?php foreach ($ranges as $value => $label): ?>
                    <option value="<?= (int) $value ?>" <?= $days === (int) $value ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field field-actions">
            <button type="submit" class="btn btn-sm">Filter</button>
        </div>
    </form>

    <?php if ($filter === 'open'): ?>
        <p class="card-sub">
            Open complaints are shown whatever their date &mdash; one from five weeks ago is
            exactly what this view is for.
        </p>
    <?php endif; ?>

    <?php if ($rows === []): ?>
        <p class="empty">Nothing to show.</p>
    <?php else: ?>
        <!-- Wrapped so a wide table scrolls inside its own box rather than
             dragging the whole layout sideways on a phone. MEASURED at 390px. -->
        <div class="table-scroll">
        <table class="table">
            <caption class="sr-only">Guest feedback</caption>
            <thead>
                <tr>
                    <th scope="col">Guest</th>
                    <th scope="col">Score</th>
                    <th scope="col">Comment</th>
                    <th scope="col">About</th>
                    <th scope="col">Answered</th>
                    <th scope="col">State</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $sentiment = (string) ($row['sentiment'] ?? '');
                    [$class, $word] = $badge[$sentiment] ?? ['badge', $sentiment];
                    $overdue = App\Services\Reputation::isOverdue($row, $slaHours, $now);
                    ?>
                    <tr class="<?= $overdue ? 'is-overdue' : '' ?>">
                        <td>
                            <a href="<?= e(url('/reputation/feedback/' . (int) $row['id'])) ?>">
                                <?= e(trim((string) $row['first_name'] . ' ' . (string) $row['last_name'])) ?: 'A guest' ?>
                            </a>
                        </td>
                        <td>
                            <span class="badge <?= e($class) ?>"><?= (int) $row['score'] ?></span>
                            <span class="sr-only"><?= e($word) ?></span>
                        </td>
                        <td><?= e(str_truncate((string) ($row['comment'] ?? ''), 80)) ?></td>
                        <td>
                            <?php if (($row['booking_number'] ?? null) !== null): ?>
                                <?= e((string) $row['booking_number']) ?>
                                <?php if (($row['host_name'] ?? null) !== null): ?>
                                    <br><span class="muted">host <?= e((string) $row['host_name']) ?></span>
                                <?php endif; ?>
                            <?php elseif (($row['visit_id'] ?? null) !== null): ?>
                                <span class="muted">a visit</span>
                            <?php else: ?>
                                <span class="muted">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e(local_date((string) $row['responded_at'], 'M j, g:ia')) ?></td>
                        <td>
                            <?php if ($sentiment !== 'detractor'): ?>
                                <span class="muted">&mdash;</span>
                            <?php elseif (($row['resolved_at'] ?? null) !== null): ?>
                                <span class="badge badge-ok">resolved</span>
                            <?php elseif ($overdue): ?>
                                <span class="badge badge-bad">overdue</span>
                            <?php else: ?>
                                <span class="badge badge-warn">open</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <?php if ($total > $page['perPage']): ?>
            <nav class="pagination" aria-label="Pages">
                <?php if ($page['page'] > 1): ?>
                    <a class="btn btn-ghost btn-sm"
                       href="<?= e(url('/reputation/feedback?show=' . urlencode($filter) . '&days=' . $days
                           . '&page=' . ($page['page'] - 1))) ?>">Previous</a>
                <?php endif; ?>
                <span class="muted">
                    Page <?= (int) $page['page'] ?> of <?= (int) ceil($total / $page['perPage']) ?>
                </span>
                <?php if ($page['offset'] + $page['perPage'] < $total): ?>
                    <a class="btn btn-ghost btn-sm"
                       href="<?= e(url('/reputation/feedback?show=' . urlencode($filter) . '&days=' . $days
                           . '&page=' . ($page['page'] + 1))) ?>">Next</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
