<?php

declare(strict_types=1);

/**
 * The segment editor.
 *
 * NO BUILD STEP AND NO JAVASCRIPT FRAMEWORK, so the rule builder is rows in a
 * plain form: a fixed number of slots, each with a field, an operator, a
 * value and an optional group number. That is not a limitation worth hiding —
 * it is why this application installs by uploading a folder.
 *
 * One level of grouping is offered, which expresses "all of these, and any of
 * those". That is the shape nearly every real segment takes: consent and
 * spend as hard conditions, then a set of alternatives. Deeper trees can be
 * stored and run; the editor says so rather than flattening one into
 * something different.
 *
 * @var array<string,mixed>|null                            $segment
 * @var array<string,mixed>                                 $rules
 * @var array{rows:list<array<string,mixed>>,too_deep:bool} $flat
 * @var string                                              $match
 * @var array<string,array<string,string>>                  $fields
 * @var array<string,array<string,mixed>>                   $catalogue
 * @var string                                              $described
 */

$isNew  = $segment === null;
$action = $isNew ? '/segments' : '/segments/' . (int) $segment['id'];

/** Enough slots for the existing rules plus room to add a few. */
$slots = max(6, count($flat['rows']) + 3);

/**
 * The operators each field type offers, in the order a person would look for
 * them. Mirrors SegmentEngine's own tables — it is the single place a
 * mismatch would show, so the field list test in the unit suite compiles
 * every field the editor offers.
 */
$operatorsFor = [
    'string'      => ['is' => 'is', 'is_not' => 'is not', 'contains' => 'contains', 'not_contains' => 'does not contain', 'starts_with' => 'starts with', 'ends_with' => 'ends with', 'is_set' => 'is filled in', 'is_not_set' => 'is empty'],
    'int'         => ['is' => 'is', 'is_not' => 'is not', 'gte' => 'is at least', 'gt' => 'is more than', 'lte' => 'is at most', 'lt' => 'is less than', 'between' => 'is between', 'is_set' => 'is filled in', 'is_not_set' => 'is empty'],
    'money'       => ['gte' => 'is at least ($)', 'gt' => 'is more than ($)', 'lte' => 'is at most ($)', 'lt' => 'is less than ($)', 'between' => 'is between ($)', 'is' => 'is exactly ($)'],
    'date'        => ['on' => 'is on', 'before' => 'is before', 'after' => 'is after', 'between' => 'is between', 'in_last_days' => 'was in the last N days', 'in_next_days' => 'is in the next N days', 'is_set' => 'is filled in', 'is_not_set' => 'is empty'],
    'datetime'    => ['in_last_days' => 'was in the last N days', 'before' => 'is before', 'after' => 'is after', 'on' => 'is on', 'between' => 'is between', 'is_set' => 'is filled in', 'is_not_set' => 'is empty'],
    'bool'        => ['is_true' => 'is yes', 'is_false' => 'is no'],
    'enum'        => ['is' => 'is', 'is_not' => 'is not'],
    'month'       => ['is' => 'is', 'is_not' => 'is not'],
    'child_month' => ['is' => 'is', 'is_not' => 'is not'],
    'tag'         => ['has_tag' => 'includes', 'not_has_tag' => 'does not include'],
    'booking'     => ['ever' => 'has happened', 'never' => 'has never happened', 'in_last_days' => 'was in the last N days', 'in_next_days' => 'is in the next N days', 'count_gte' => 'is at least N parties'],
    'membership'  => ['is' => 'is', 'is_not' => 'is not', 'ever' => 'has ever existed', 'never' => 'has never existed'],
    'engagement'  => ['ever' => 'has happened', 'never' => 'has never happened', 'in_last_days' => 'was in the last N days', 'not_in_last_days' => 'was NOT in the last N days'],
];

$old = static function (int $index, string $key, mixed $default = ''): string {
    $posted = old('rules');

    if (is_array($posted) && isset($posted[$index]) && is_array($posted[$index])) {
        $value = $posted[$index][$key] ?? null;

        if (is_scalar($value)) {
            return (string) $value;
        }
    }

    return is_scalar($default) ? (string) $default : '';
};

?>
<p class="breadcrumbs">
    <a href="<?= e(url('/segments')) ?>">Segments</a> /
    <?= $isNew ? 'New' : e((string) $segment['name']) ?>
</p>

<?php if ($flat['too_deep']): ?>
    <div class="alert alert-warning">
        <?= icon('alert') ?>
        <p>
            This segment has groups inside groups, which this editor cannot show. It is stored
            and it still runs &mdash; but saving from here would replace it with something
            simpler. Leave it alone unless you mean to rebuild it.
        </p>
    </div>
<?php endif; ?>

<?php if (!$isNew && $described !== ''): ?>
    <div class="card">
        <h2>As it stands</h2>
        <p><?= e($described) ?></p>
    </div>
<?php endif; ?>

<form method="post" action="<?= e(url($action)) ?>" class="form">
    <?= csrf_field() ?>

    <div class="card">
        <div class="field">
            <label for="name">Name</label>
            <input type="text" id="name" name="name" maxlength="120" required
                   value="<?= e((string) old('name', $segment['name'] ?? '')) ?>">
            <?php if ($message = error_for('name')): ?>
                <p class="field-error"><?= e($message) ?></p>
            <?php endif; ?>
            <p class="field-hint">
                What question this answers &mdash; &ldquo;July birthday children&rdquo; rather
                than &ldquo;Segment 3&rdquo;.
            </p>
        </div>

        <div class="field">
            <label for="description">Note (optional)</label>
            <input type="text" id="description" name="description" maxlength="500"
                   value="<?= e((string) old('description', $segment['description'] ?? '')) ?>">
        </div>

        <div class="field">
            <label for="match">A guest is in this segment when</label>
            <select id="match" name="match">
                <option value="all" <?= $match === 'all' ? 'selected' : '' ?>>
                    every ungrouped condition below is true
                </option>
                <option value="any" <?= $match === 'any' ? 'selected' : '' ?>>
                    any one of them is true
                </option>
            </select>
        </div>
    </div>

    <div class="card">
        <h2>Conditions</h2>
        <p class="field-hint">
            Leave a row blank to ignore it. Put a number in <strong>Group</strong> to say
            &ldquo;any one of these&rdquo; &mdash; rows sharing a group number are OR-ed
            together, and the group as a whole is then treated like a single condition above.
        </p>

        <?php for ($i = 0; $i < $slots; $i++): ?>
            <?php
            $existing = $flat['rows'][$i] ?? [];
            $field    = $old($i, 'field', $existing['field'] ?? '');
            $op       = $old($i, 'op', $existing['op'] ?? '');
            $value    = $existing['value'] ?? '';
            $value2   = '';

            if (is_array($value)) {
                $value2 = (string) ($value[1] ?? '');
                $value  = (string) ($value[0] ?? '');
            }

            $value  = $old($i, 'value', is_scalar($value) ? $value : '');
            $value2 = $old($i, 'value2', $value2);
            $group  = $old($i, 'group', $existing['group'] ?? '');
            ?>
            <fieldset class="segment-rule">
                <legend class="sr-only">Condition <?= e((string) ($i + 1)) ?></legend>

                <div class="field">
                    <label for="rule-field-<?= $i ?>">Field</label>
                    <select id="rule-field-<?= $i ?>" name="rules[<?= $i ?>][field]">
                        <option value="">&mdash; ignore this row &mdash;</option>
                        <?php foreach ($fields as $groupName => $groupFields): ?>
                            <optgroup label="<?= e($groupName) ?>">
                                <?php foreach ($groupFields as $key => $label): ?>
                                    <option value="<?= e($key) ?>" <?= $field === $key ? 'selected' : '' ?>>
                                        <?= e($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="rule-op-<?= $i ?>">Condition</label>
                    <select id="rule-op-<?= $i ?>" name="rules[<?= $i ?>][op]">
                        <option value="">&mdash;</option>
                        <?php
                        /*
                         * EVERY operator for every type, grouped and labelled
                         * by type. With no JavaScript the list cannot narrow
                         * itself to the chosen field — so rather than offer a
                         * flat list in which "is at least ($)" sits beside
                         * "includes" with no explanation, each group says
                         * which kind of field it applies to, and the engine
                         * refuses a mismatch with a message naming the field.
                         */
                        ?>
                        <?php foreach ($operatorsFor as $type => $operators): ?>
                            <optgroup label="<?= e(ucfirst(str_replace('_', ' ', $type))) ?> fields">
                                <?php foreach ($operators as $key => $label): ?>
                                    <option value="<?= e($key) ?>" <?= $op === $key ? 'selected' : '' ?>>
                                        <?= e($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="rule-value-<?= $i ?>">Value</label>
                    <input type="text" id="rule-value-<?= $i ?>" name="rules[<?= $i ?>][value]"
                           maxlength="191" value="<?= e($value) ?>">
                </div>

                <div class="field">
                    <label for="rule-value2-<?= $i ?>">and (for &ldquo;between&rdquo;)</label>
                    <input type="text" id="rule-value2-<?= $i ?>" name="rules[<?= $i ?>][value2]"
                           maxlength="191" value="<?= e($value2) ?>">
                </div>

                <div class="field">
                    <label for="rule-group-<?= $i ?>">Group</label>
                    <input type="number" id="rule-group-<?= $i ?>" name="rules[<?= $i ?>][group]"
                           min="1" max="9" value="<?= e($group) ?>">
                </div>
            </fieldset>
        <?php endfor; ?>

        <?php if ($message = error_for('_form')): ?>
            <p class="field-error"><?= e($message) ?></p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>A note on how this fails</h2>
        <p class="field-hint">
            A condition this application cannot read is <strong>refused, never skipped</strong>.
            Skipping one would widen the segment &mdash; &ldquo;customers who opted in&rdquo;
            with its consent condition quietly dropped is everybody &mdash; so a segment that
            will not compile cannot be saved, and one that stops compiling later cannot be sent
            to. A segment with no conditions at all matches nobody, for the same reason.
        </p>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn">Save segment</button>
        <a class="btn btn-ghost"
           href="<?= e(url($isNew ? '/segments' : '/segments/' . (int) $segment['id'])) ?>">Cancel</a>
    </div>
</form>
