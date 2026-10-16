<?php

declare(strict_types=1);

/**
 * Create / edit a task.
 *
 * The due date and time are entered and shown in the VENUE's timezone, which
 * the hint under the field names outright. The controller converts them to UTC
 * for storage and splits them back for this form, so what somebody types is
 * what they read back — and a task due at 11pm tonight is not stored as
 * yesterday evening.
 *
 * Leaving the time blank means "by the end of the day", not midnight.
 *
 * @var array<string,mixed>|null             $task
 * @var list<array<string,mixed>>            $assignees
 * @var list<string>                         $priorities
 * @var list<string>                         $statuses
 * @var array<string,array{0:string,1:string}> $entities
 * @var array{date:string,time:string}       $due
 * @var string                               $today
 * @var string                               $timezone
 */

$isEdit = $task !== null;
$id     = $isEdit ? (int) $task['id'] : 0;
$action = $isEdit ? '/tasks/' . $id : '/tasks';

$statusLabels = [
    'open'        => 'Open',
    'in_progress' => 'In progress',
    'done'        => 'Done',
    'cancelled'   => 'Cancelled',
];

$priorityLabels = [
    'urgent' => 'Urgent — today, before anything else',
    'high'   => 'High',
    'normal' => 'Normal',
    'low'    => 'Low — when there is a quiet hour',
];

/** What they just typed, else the stored row, else a default. */
$value = static function (string $field, string $fallback = '') use ($task): string {
    $old = old($field, null);

    if ($old !== null && $old !== '') {
        return (string) $old;
    }

    $stored = $task[$field] ?? null;

    return $stored === null || $stored === '' ? $fallback : (string) $stored;
};

$dueDate = (string) (old('due_date', null) ?? '') !== '' ? (string) old('due_date') : $due['date'];
$dueTime = (string) (old('due_time', null) ?? '') !== '' ? (string) old('due_time') : $due['time'];
?>
<p class="breadcrumbs">
    <a href="<?= e(url('/tasks')) ?>">Tasks</a> /
    <?= $isEdit ? e($task['title']) : 'New task' ?>
</p>

<form method="post" action="<?= e(url($action)) ?>" class="card form">
    <?= csrf_field() ?>

    <div class="field">
        <label for="title">Task</label>
        <input type="text" id="title" name="title" maxlength="191" required
               value="<?= e($value('title')) ?>">
        <p class="field-hint">What needs doing, in the words somebody would use out loud.</p>
        <?php if ($err = error_for('title')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
    </div>

    <div class="field">
        <label for="description">Details</label>
        <textarea id="description" name="description" rows="4"><?= e($value('description')) ?></textarea>
        <?php if ($err = error_for('description')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
    </div>

    <div class="field-row">
        <div class="field">
            <label for="due_date">Due date</label>
            <input type="date" id="due_date" name="due_date" value="<?= e($dueDate) ?>">
            <p class="field-hint">
                Today is <?= e($today) ?> at the venue. Leave blank for a task with no deadline.
            </p>
            <?php if ($err = error_for('due_date')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
        </div>
        <div class="field">
            <label for="due_time">Due time</label>
            <input type="time" id="due_time" name="due_time" value="<?= e($dueTime) ?>">
            <p class="field-hint">
                <?= e($timezone) ?> time. Blank means by the end of that day, not midnight.
            </p>
            <?php if ($err = error_for('due_time')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
        </div>
    </div>

    <div class="field-row">
        <div class="field">
            <label for="assigned_to">Assignee</label>
            <select id="assigned_to" name="assigned_to">
                <option value="">Nobody yet</option>
                <?php foreach ($assignees as $person): ?>
                    <option value="<?= (int) $person['id'] ?>"
                        <?= $value('assigned_to') === (string) $person['id'] ? 'selected' : '' ?>>
                        <?= e($person['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-hint">An unassigned task shows up in the Unassigned view for anyone to pick up.</p>
            <?php if ($err = error_for('assigned_to')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
        </div>
        <div class="field">
            <label for="priority">Priority</label>
            <select id="priority" name="priority">
                <?php $currentPriority = $value('priority', 'normal'); ?>
                <?php foreach ($priorities as $option): ?>
                    <option value="<?= e($option) ?>" <?= $currentPriority === $option ? 'selected' : '' ?>>
                        <?= e($priorityLabels[$option] ?? ucfirst($option)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($err = error_for('priority')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
        </div>
    </div>

    <div class="field-row">
        <div class="field">
            <label for="status">Status</label>
            <select id="status" name="status">
                <?php $currentStatus = $value('status', 'open'); ?>
                <?php foreach ($statuses as $option): ?>
                    <option value="<?= e($option) ?>" <?= $currentStatus === $option ? 'selected' : '' ?>>
                        <?= e($statusLabels[$option] ?? ucfirst($option)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-hint">Marking it done here records you as the person who finished it.</p>
            <?php if ($err = error_for('status')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
        </div>
        <div class="field">
            <label for="entity_type">Linked record</label>
            <select id="entity_type" name="entity_type">
                <option value="">Nothing</option>
                <?php foreach ($entities as $key => $entity): ?>
                    <option value="<?= e($key) ?>" <?= $value('entity_type') === $key ? 'selected' : '' ?>>
                        <?= e($entity[0]) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-hint">Attach the task to the guest, deal, quote or household it is about.</p>
            <?php if ($err = error_for('entity_type')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
        </div>
    </div>

    <div class="field">
        <label for="entity_id">Record number</label>
        <input type="number" id="entity_id" name="entity_id" min="1" step="1"
               value="<?= e($value('entity_id')) ?>">
        <p class="field-hint">
            The number in that record's address bar. Both boxes together or both blank — half a
            link points nowhere.
        </p>
        <?php if ($err = error_for('entity_id')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn"><?= $isEdit ? 'Save changes' : 'Create the task' ?></button>
        <a class="btn btn-ghost" href="<?= e(url($isEdit ? '/tasks/' . $id : '/tasks')) ?>">Cancel</a>
    </div>
</form>
