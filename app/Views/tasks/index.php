<?php

declare(strict_types=1);

/**
 * The task list.
 *
 * Overdue first, because the only reason to open this page mid-shift is to find
 * out what is already late. The due column prints the venue's local time
 * (local_time() converts from the stored UTC), and the Overdue / Due today
 * badges come from flags the controller computed against the venue's own day —
 * never from a comparison made here, which could disagree with the sort.
 *
 * @var string                    $view
 * @var list<array<string,mixed>> $tasks
 * @var array<string,int>         $counts
 * @var int                       $total
 * @var array<string,string>      $filters
 * @var list<array<string,mixed>> $assignees
 * @var list<string>              $statuses
 * @var bool                      $canManage
 * @var int                       $page
 * @var int                       $lastPage
 */

use App\Controllers\TaskController;

$statusLabels = [
    'open'        => 'Open',
    'in_progress' => 'In progress',
    'done'        => 'Done',
    'cancelled'   => 'Cancelled',
];

$priorityBadge = static fn (string $priority): string => match ($priority) {
    'urgent' => 'badge-bad',
    'high'   => 'badge-warn',
    'low'    => 'badge-muted',
    default  => 'badge',
};

$blurb = [
    'mine'       => 'Everything assigned to you that is still open.',
    'overdue'    => 'Past its due time and still open. The venue\'s clock, not the server\'s.',
    'today'      => 'Still open and due before the end of today in the venue\'s timezone.',
    'unassigned' => 'Open work nobody has picked up.',
    'all'        => 'Every task you can see, overdue first.',
];

/** The current query string with one key changed, so a view keeps the filters. */
$withView = static function (string $target) use ($filters): string {
    $query = array_filter([
        'view'     => $target,
        'status'   => $filters['status'],
        'assignee' => $filters['assignee'],
        'q'        => $filters['q'],
    ], static fn (string $value): bool => $value !== '');

    return '/tasks' . ($query === [] ? '' : '?' . http_build_query($query));
};
?>
<div class="page-actions">
    <p class="page-lede">
        <?= e($blurb[$view] ?? $blurb['all']) ?>
        <?= number_format($total) ?> task<?= $total === 1 ? '' : 's' ?> in this view.
    </p>
    <?php if ($canManage): ?>
        <a class="btn" href="<?= e(url('/tasks/new')) ?>"><?= icon('plus', 16) ?> New task</a>
    <?php endif; ?>
</div>

<nav class="row-actions" aria-label="Task views">
    <?php foreach (TaskController::VIEWS as $key => $label): ?>
        <a class="btn btn-sm <?= $view === $key ? '' : 'btn-ghost' ?>"
           href="<?= e(url($withView($key))) ?>"
           <?= $view === $key ? 'aria-current="page"' : '' ?>>
            <?= e($label) ?>
            <?php if (($counts[$key] ?? 0) > 0): ?>
                <span class="badge <?= $key === 'overdue' ? 'badge-bad' : 'badge-muted' ?>">
                    <?= number_format((int) $counts[$key]) ?>
                </span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>

<form method="get" action="<?= e(url('/tasks')) ?>" class="card filter-bar">
    <input type="hidden" name="view" value="<?= e($view) ?>">

    <div class="field">
        <label for="q">Search</label>
        <input type="search" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="Task or details">
    </div>

    <div class="field">
        <label for="assignee">Assignee</label>
        <select id="assignee" name="assignee">
            <option value="">Anyone</option>
            <option value="none" <?= $filters['assignee'] === 'none' ? 'selected' : '' ?>>Nobody</option>
            <?php foreach ($assignees as $person): ?>
                <option value="<?= (int) $person['id'] ?>"
                    <?= $filters['assignee'] === (string) $person['id'] ? 'selected' : '' ?>>
                    <?= e($person['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="field">
        <label for="status">Status</label>
        <select id="status" name="status">
            <option value="">Open and in progress</option>
            <?php foreach ($statuses as $option): ?>
                <option value="<?= e($option) ?>" <?= $filters['status'] === $option ? 'selected' : '' ?>>
                    <?= e($statusLabels[$option] ?? ucfirst($option)) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="field field-actions">
        <button type="submit" class="btn btn-sm">Filter</button>
        <a class="btn btn-ghost btn-sm" href="<?= e(url($withView($view))) ?>">Clear</a>
    </div>
</form>

<section class="card">
    <?php if ($tasks === []): ?>
        <p class="empty">
            <?php if ($filters['q'] !== '' || $filters['status'] !== '' || $filters['assignee'] !== ''): ?>
                No task matches that.
            <?php elseif ($view === 'overdue'): ?>
                Nothing is overdue.
            <?php elseif ($view === 'today'): ?>
                Nothing is due today.
            <?php elseif ($view === 'unassigned'): ?>
                Every open task has somebody on it.
            <?php elseif ($view === 'mine'): ?>
                Nothing is assigned to you.
            <?php else: ?>
                No tasks yet.
                <?= $canManage ? 'Raise one, and it shows up here and on the dashboard.' : '' ?>
            <?php endif; ?>
        </p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th scope="col">Task</th>
                    <th scope="col">Due</th>
                    <th scope="col">Assignee</th>
                    <th scope="col">Priority</th>
                    <th scope="col">Status</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($tasks as $task): ?>
                    <?php
                    $taskId   = (int) $task['id'];
                    $overdue  = (int) $task['is_overdue'] === 1;
                    $dueToday = (int) $task['is_due_today'] === 1;
                    $status   = (string) $task['status'];
                    ?>
                    <tr>
                        <td>
                            <a href="<?= e(url('/tasks/' . $taskId)) ?>">
                                <strong><?= e($task['title']) ?></strong>
                            </a>
                            <?php if ((int) $task['comment_count'] > 0): ?>
                                <span class="td-sub">
                                    <?= (int) $task['comment_count'] ?>
                                    comment<?= (int) $task['comment_count'] === 1 ? '' : 's' ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($task['due_at'] === null): ?>
                                <span class="muted">No deadline</span>
                            <?php else: ?>
                                <?= e(local_time((string) $task['due_at'])) ?>
                                <?php if ($overdue): ?>
                                    <span class="td-sub"><span class="badge badge-bad">Overdue</span></span>
                                <?php elseif ($dueToday): ?>
                                    <span class="td-sub"><span class="badge badge-warn">Due today</span></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($task['assignee_name'] === null): ?>
                                <span class="muted">Nobody</span>
                            <?php else: ?>
                                <?= e($task['assignee_name']) ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= e($priorityBadge((string) $task['priority'])) ?>">
                                <?= e(ucfirst((string) $task['priority'])) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge <?= $status === 'done' ? 'badge-ok' : 'badge-muted' ?>">
                                <?= e($statusLabels[$status] ?? ucfirst($status)) ?>
                            </span>
                            <?php if ($status === 'done' && $task['completer_name'] !== null): ?>
                                <span class="td-sub">
                                    by <?= e($task['completer_name']) ?>,
                                    <?= e(local_time((string) $task['completed_at'])) ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="td-right">
                            <?php if ($canManage && $status !== 'done'): ?>
                                <form method="post" action="<?= e(url('/tasks/' . $taskId . '/complete')) ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-ghost btn-sm">
                                        <?= icon('check', 14) ?> Done
                                    </button>
                                </form>
                            <?php else: ?>
                                <a class="btn btn-ghost btn-sm" href="<?= e(url('/tasks/' . $taskId)) ?>">Open</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($lastPage > 1): ?>
            <nav class="pagination" aria-label="Pages">
                <?php
                $link = static function (int $target) use ($filters): string {
                    $query = array_filter(array_merge($filters, ['page' => (string) $target]), static fn (string $value): bool => $value !== '');

                    return '/tasks?' . http_build_query($query);
                };
                ?>
                <?php if ($page > 1): ?>
                    <a class="btn btn-ghost btn-sm" href="<?= e(url($link($page - 1))) ?>">Previous</a>
                <?php endif; ?>
                <span class="pagination-info">Page <?= (int) $page ?> of <?= (int) $lastPage ?></span>
                <?php if ($page < $lastPage): ?>
                    <a class="btn btn-ghost btn-sm" href="<?= e(url($link($page + 1))) ?>">Next</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
