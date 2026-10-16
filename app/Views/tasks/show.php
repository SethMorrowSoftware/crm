<?php

declare(strict_types=1);

/**
 * One task: what it is, when it is due in the venue's own time, who owns it,
 * and the conversation on it.
 *
 * Completing and reopening are POST forms, not links. They change a record, and
 * a GET that does is triggered by a browser prefetch or a link checker.
 *
 * @var array<string,mixed>                                $task
 * @var list<array<string,mixed>>                          $comments
 * @var array{label:string,name:string,url:string}|null    $link
 * @var bool                                               $canManage
 */

$taskId   = (int) $task['id'];
$status   = (string) $task['status'];
$overdue  = (int) $task['is_overdue'] === 1;
$dueToday = (int) $task['is_due_today'] === 1;

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
?>
<p class="breadcrumbs">
    <a href="<?= e(url('/tasks')) ?>">Tasks</a> / <?= e($task['title']) ?>
</p>

<div class="page-actions">
    <p class="page-lede">
        <span class="badge <?= $status === 'done' ? 'badge-ok' : 'badge-muted' ?>">
            <?= e($statusLabels[$status] ?? ucfirst($status)) ?>
        </span>
        <span class="badge <?= e($priorityBadge((string) $task['priority'])) ?>">
            <?= e(ucfirst((string) $task['priority'])) ?>
        </span>
        <?php if ($overdue): ?>
            <span class="badge badge-bad">Overdue</span>
        <?php elseif ($dueToday): ?>
            <span class="badge badge-warn">Due today</span>
        <?php endif; ?>
    </p>
    <?php if ($canManage): ?>
        <div class="row-actions">
            <a class="btn btn-ghost" href="<?= e(url('/tasks/' . $taskId . '/edit')) ?>">
                <?= icon('edit', 16) ?> Edit
            </a>
            <?php if ($status === 'done' || $status === 'cancelled'): ?>
                <form method="post" action="<?= e(url('/tasks/' . $taskId . '/reopen')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn">Reopen</button>
                </form>
            <?php else: ?>
                <form method="post" action="<?= e(url('/tasks/' . $taskId . '/complete')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn"><?= icon('check', 16) ?> Mark it done</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<div class="grid-2">
    <div>
        <section class="card">
            <div class="card-head">
                <h2><?= e($task['title']) ?></h2>
            </div>

            <?php if (trim((string) ($task['description'] ?? '')) !== ''): ?>
                <p><?= nl2br(e($task['description'])) ?></p>
            <?php else: ?>
                <p class="empty">No details were written down.</p>
            <?php endif; ?>

            <dl class="definition-list">
                <dt>Due</dt>
                <dd>
                    <?php if ($task['due_at'] === null): ?>
                        <span class="muted">No deadline</span>
                    <?php else: ?>
                        <?= e(local_time((string) $task['due_at'])) ?>
                        <span class="td-sub">Venue time. Stored as <?= e($task['due_at']) ?> UTC.</span>
                    <?php endif; ?>
                </dd>

                <dt>Assignee</dt>
                <dd><?= $task['assignee_name'] === null
                        ? '<span class="muted">Nobody yet</span>'
                        : e($task['assignee_name']) ?></dd>

                <dt>Raised by</dt>
                <dd>
                    <?= $task['creator_name'] === null
                        ? '<span class="muted">An automation</span>'
                        : e($task['creator_name']) ?>
                    <span class="td-sub"><?= e(local_time((string) $task['created_at'])) ?></span>
                </dd>

                <dt>About</dt>
                <dd>
                    <?php if ($link === null): ?>
                        <span class="muted">Not attached to a record</span>
                    <?php else: ?>
                        <a href="<?= e(url($link['url'])) ?>"><?= e($link['name']) ?></a>
                        <span class="td-sub"><?= e($link['label']) ?></span>
                    <?php endif; ?>
                </dd>

                <dt>Completed</dt>
                <dd>
                    <?php if ($task['completed_at'] === null): ?>
                        <span class="muted">Not yet</span>
                    <?php else: ?>
                        <?= e(local_time((string) $task['completed_at'])) ?>
                        <span class="td-sub">
                            by <?= $task['completer_name'] === null
                                ? 'somebody since removed'
                                : e($task['completer_name']) ?>
                        </span>
                    <?php endif; ?>
                </dd>
            </dl>
        </section>
    </div>

    <div>
        <section class="card">
            <div class="card-head">
                <h2>Comments</h2>
            </div>

            <?php if ($comments === []): ?>
                <p class="empty">Nothing said yet.</p>
            <?php else: ?>
                <ul class="timeline">
                    <?php foreach ($comments as $comment): ?>
                        <li>
                            <p class="timeline-what"><?= nl2br(e($comment['body'])) ?></p>
                            <p class="timeline-when">
                                <?= e(local_time((string) $comment['created_at'])) ?>
                                &middot; <?= $comment['user_name'] === null
                                    ? 'automatically'
                                    : e($comment['user_name']) ?>
                            </p>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($canManage): ?>
                <form method="post" action="<?= e(url('/tasks/' . $taskId . '/comments')) ?>" class="form">
                    <?= csrf_field() ?>
                    <div class="field">
                        <label for="body">Add a comment</label>
                        <textarea id="body" name="body" rows="3"
                                  placeholder="What happened, or what is holding this up"></textarea>
                        <?php if ($err = error_for('body')): ?>
                            <p class="field-error"><?= e($err) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-sm">Post comment</button>
                    </div>
                </form>
            <?php endif; ?>
        </section>
    </div>
</div>
