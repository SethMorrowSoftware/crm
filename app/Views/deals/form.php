<?php

declare(strict_types=1);

/**
 * Create or edit a deal.
 *
 * The stage select lists every stage GROUPED BY its pipeline, and the pipeline
 * select sits beside it. Two selects could be submitted disagreeing, and the
 * controller refuses that rather than guessing — but the optgroup labels mean
 * you can see which pipeline a stage belongs to before you pick it, which is
 * the part a dependent dropdown would otherwise need JavaScript for. This
 * application ships no page-level JavaScript, and a form that cannot be
 * completed without it would be worse than one extra select.
 *
 * @var array<string,mixed>|null $deal
 * @var list<array{id:int,name:string,stages:list<array<string,mixed>>}> $stageGroups
 * @var list<array<string,mixed>> $contacts
 * @var list<array<string,mixed>> $owners
 * @var int $pipelineId
 */

use App\Controllers\DealController;

$isEdit = $deal !== null;
$action = $isEdit ? '/deals/' . (int) $deal['id'] : '/deals';

$selectedPipeline = (int) (old('pipeline_id') ?: ($deal['pipeline_id'] ?? $pipelineId));
$selectedStage    = (int) (old('stage_id') ?: ($deal['stage_id'] ?? 0));
$selectedContact  = (int) (old('contact_id') ?: ($deal['contact_id'] ?? 0));
$selectedSource   = (string) (old('source') ?: ($deal['source'] ?? ''));

/**
 * The owner this form would submit if nobody touches the control.
 *
 * On a new deal that is the person creating it; on an edit it is whoever owns
 * it ALREADY, including nobody. Defaulting an edit to the current user would
 * make a host who merely corrected a typo submit an owner change, which
 * deals_reassign then refuses — a confusing refusal for something they did not
 * ask for.
 */
$currentOwner  = $isEdit ? (int) ($deal['owner_id'] ?? 0) : (int) (auth_user()['id'] ?? 0);
$selectedOwner = (int) (old('owner_id') ?: $currentOwner);

$valueDollars = old('value_dollars');

if ($valueDollars === '' || $valueDollars === null) {
    $valueDollars = $isEdit ? money((int) $deal['value_cents'], false) : '';
}

/** A DATE column as the date input wants it, with no timezone shifting. */
$dateValue = static function (mixed $date): string {
    $value = trim((string) ($date ?? ''));

    if ($value === '' || str_starts_with($value, '0000')) {
        return '';
    }

    $time = strtotime($value . ' 00:00:00 UTC');

    return $time === false ? '' : gmdate('Y-m-d', $time);
};
?>
<p class="breadcrumbs">
    <a href="<?= e(url('/deals')) ?>">Pipelines</a> /
    <?php if ($isEdit): ?>
        <a href="<?= e(url('/deals/' . (int) $deal['id'])) ?>"><?= e($deal['title']) ?></a> / Edit
    <?php else: ?>
        New deal
    <?php endif; ?>
</p>

<form method="post" action="<?= e(url($action)) ?>" class="card form">
    <?= csrf_field() ?>

    <div class="field">
        <label for="title">Deal name</label>
        <input type="text" id="title" name="title" required maxlength="191"
               value="<?= e(old('title') ?: ($deal['title'] ?? '')) ?>">
        <p class="field-hint">
            What this is, in the words you would use on the phone — &ldquo;Lucia Alvarez — 8th
            birthday&rdquo;, &ldquo;Monroe-Woodbury 5th grade field trip&rdquo;.
        </p>
        <?php if ($err = error_for('title')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
    </div>

    <div class="field-row">
        <div class="field">
            <label for="pipeline_id">Pipeline</label>
            <select id="pipeline_id" name="pipeline_id" required>
                <?php foreach ($stageGroups as $group): ?>
                    <option value="<?= (int) $group['id'] ?>"
                        <?= (int) $group['id'] === $selectedPipeline ? 'selected' : '' ?>>
                        <?= e($group['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($err = error_for('pipeline_id')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
        </div>

        <div class="field">
            <label for="stage_id">Stage</label>
            <select id="stage_id" name="stage_id" required>
                <?php foreach ($stageGroups as $group): ?>
                    <optgroup label="<?= e($group['name']) ?>">
                        <?php foreach ($group['stages'] as $stage): ?>
                            <option value="<?= (int) $stage['id'] ?>"
                                <?= (int) $stage['id'] === $selectedStage ? 'selected' : '' ?>>
                                <?= e($stage['name']) ?><?php
                                    if ($stage['is_won']) {
                                        echo ' (won)';
                                    } elseif ($stage['is_lost']) {
                                        echo ' (lost)';
                                    }
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
            <p class="field-hint">
                Pick a stage listed under the pipeline you chose. The stage decides whether the deal
                counts as open, won or lost, and sets its response deadline.
            </p>
            <?php if ($err = error_for('stage_id')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
        </div>
    </div>

    <div class="field-row">
        <div class="field">
            <label for="contact_id">Contact</label>
            <select id="contact_id" name="contact_id">
                <option value="">Nobody yet</option>
                <?php foreach ($contacts as $contact): ?>
                    <?php
                    $name = trim((string) ($contact['first_name'] ?? '') . ' ' . (string) ($contact['last_name'] ?? ''));
                    $name = $name === '' ? (string) ($contact['email'] ?? 'Contact ' . (int) $contact['id']) : $name;
                    ?>
                    <option value="<?= (int) $contact['id'] ?>"
                        <?= (int) $contact['id'] === $selectedContact ? 'selected' : '' ?>>
                        <?= e($name) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-hint">The 200 most recently touched guests. A deal can start without one.</p>
            <?php if ($err = error_for('contact_id')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
        </div>

        <div class="field">
            <label for="owner_id">Owner</label>
            <select id="owner_id" name="owner_id" <?= can('deals_reassign') ? '' : 'disabled' ?>>
                <option value="">Unassigned</option>
                <?php foreach ($owners as $owner): ?>
                    <option value="<?= (int) $owner['id'] ?>"
                        <?= (int) $owner['id'] === $selectedOwner ? 'selected' : '' ?>>
                        <?= e($owner['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (!can('deals_reassign')): ?>
                <input type="hidden" name="owner_id" value="<?= $currentOwner > 0 ? (int) $currentOwner : '' ?>">
                <p class="field-hint">Your deals stay with you. A manager can reassign them.</p>
            <?php else: ?>
                <p class="field-hint">Who is working it. Nudges and scorecards follow this.</p>
            <?php endif; ?>
            <?php if ($err = error_for('owner_id')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
        </div>
    </div>

    <div class="field-row">
        <div class="field">
            <label for="value_dollars">Value</label>
            <input type="text" id="value_dollars" name="value_dollars" inputmode="decimal"
                   value="<?= e($valueDollars) ?>" placeholder="549.00">
            <p class="field-hint">
                Dollars and cents. Stored to the cent, so 549.95 stays 549.95.
            </p>
        </div>

        <div class="field">
            <label for="guest_count">Guests</label>
            <input type="number" id="guest_count" name="guest_count" min="0" max="65535"
                   value="<?= e(old('guest_count') ?: ($deal['guest_count'] ?? '')) ?>">
            <?php if ($err = error_for('guest_count')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
        </div>
    </div>

    <div class="field-row">
        <div class="field">
            <label for="expected_close_date">Expected close date</label>
            <input type="date" id="expected_close_date" name="expected_close_date"
                   value="<?= e(old('expected_close_date') ?: $dateValue($deal['expected_close_date'] ?? null)) ?>">
            <p class="field-hint">When you expect them to commit — not the party itself.</p>
            <?php if ($err = error_for('expected_close_date')): ?>
                <p class="field-error"><?= e($err) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="event_date">Event date</label>
            <input type="date" id="event_date" name="event_date"
                   value="<?= e(old('event_date') ?: $dateValue($deal['event_date'] ?? null)) ?>">
            <p class="field-hint">A school books in March for June, so this is usually much later.</p>
            <?php if ($err = error_for('event_date')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
        </div>
    </div>

    <div class="field-row">
        <div class="field">
            <label for="source">Source</label>
            <select id="source" name="source">
                <option value="">Not recorded</option>
                <?php foreach (DealController::SOURCES as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $selectedSource === $value ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($err = error_for('source')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
        </div>

        <div class="field">
            <label for="source_detail">Source detail</label>
            <input type="text" id="source_detail" name="source_detail" maxlength="191"
                   value="<?= e(old('source_detail') ?: ($deal['source_detail'] ?? '')) ?>">
            <p class="field-hint">Which form, which referrer, which event.</p>
        </div>
    </div>

    <div class="field">
        <label for="next_action">Next action</label>
        <input type="text" id="next_action" name="next_action" maxlength="255"
               value="<?= e(old('next_action') ?: ($deal['next_action'] ?? '')) ?>">
        <p class="field-hint">The one thing that moves this forward. &ldquo;Chase the quote&rdquo;.</p>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn"><?= $isEdit ? 'Save changes' : 'Create the deal' ?></button>
        <a class="btn btn-ghost"
           href="<?= e(url($isEdit ? '/deals/' . (int) $deal['id'] : '/deals')) ?>">Cancel</a>
    </div>
</form>
