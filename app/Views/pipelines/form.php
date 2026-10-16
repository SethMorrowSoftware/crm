<?php

declare(strict_types=1);

/**
 * Create a pipeline, or edit one and its stages.
 *
 * Two forms, deliberately separate: the pipeline's own details save on their
 * own, and the stage editor saves every row at once. Mixing them would mean a
 * rejected stage row discarding a rename, and the stage editor is the form
 * most likely to be refused — it is validated row by row before anything is
 * written.
 *
 * Every stage control carries an aria-label rather than a visible label: the
 * column heading already names it, and a <label> per cell would read as
 * "Name Name Order Order" down the table.
 *
 * @var array<string,mixed>|null  $pipeline
 * @var list<array<string,mixed>> $stages
 * @var array<int,int>            $stageUsage stage id => live deals
 * @var array<string,string>      $types
 * @var array<string,string>      $strategies
 */

$isEdit = $pipeline !== null;
$action = $isEdit ? '/pipelines/' . (int) $pipeline['id'] : '/pipelines';
$spares = 3;
?>
<p class="breadcrumbs">
    <a href="<?= e(url('/pipelines')) ?>">Pipelines</a> /
    <?= $isEdit ? e($pipeline['name']) : 'New pipeline' ?>
</p>

<form method="post" action="<?= e(url($action)) ?>" class="card form">
    <?= csrf_field() ?>

    <div class="field-row">
        <div class="field">
            <label for="name">Name</label>
            <input type="text" id="name" name="name" required maxlength="80"
                   value="<?= e(old('name') ?: ($pipeline['name'] ?? '')) ?>">
            <?php if ($err = error_for('name')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
        </div>

        <div class="field">
            <label for="type">Kind</label>
            <select id="type" name="type" required>
                <?php foreach ($types as $value => $label): ?>
                    <option value="<?= e($value) ?>"
                        <?= (string) (old('type') ?: ($pipeline['type'] ?? 'custom')) === $value ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($err = error_for('type')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
        </div>
    </div>

    <?php if (!$isEdit): ?>
        <div class="field">
            <label for="slug">Slug</label>
            <input type="text" id="slug" name="slug" maxlength="64" value="<?= e(old('slug')) ?>">
            <p class="field-hint">
                Optional. Derived from the name when left blank, and never changed afterwards —
                playbooks and the seeds find a pipeline by its slug.
            </p>
            <?php if ($err = error_for('slug')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="field-row">
        <div class="field">
            <label for="default_owner_strategy">Owner assignment</label>
            <select id="default_owner_strategy" name="default_owner_strategy">
                <?php foreach ($strategies as $value => $label): ?>
                    <option value="<?= e($value) ?>"
                        <?= (string) (old('default_owner_strategy')
                            ?: ($pipeline['default_owner_strategy'] ?? 'manual')) === $value ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-hint">
                Recorded now, honoured when automatic assignment lands. Today every new deal belongs
                to whoever created it unless somebody with reassign rights changes it.
            </p>
        </div>

        <div class="field">
            <label for="sort_order">Order</label>
            <input type="number" id="sort_order" name="sort_order" min="0" max="32767"
                   value="<?= e(old('sort_order') ?: (string) ($pipeline['sort_order'] ?? 0)) ?>">
            <p class="field-hint">Lower numbers come first in the pipeline picker.</p>
        </div>
    </div>

    <div class="field field-check">
        <!-- An unticked checkbox sends NOTHING, so without this a box the
             operator deliberately cleared would read as "not submitted" and
             the pipeline would stay active. The later value wins. -->
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" id="is_active" name="is_active" value="1"
            <?= (int) ($pipeline['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
        <label for="is_active">Active</label>
        <p class="field-hint">
            An inactive pipeline keeps its deals and its history but disappears from the new-deal
            form. Its board stays reachable while it still holds anything.
        </p>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn"><?= $isEdit ? 'Save pipeline' : 'Create the pipeline' ?></button>
        <a class="btn btn-ghost" href="<?= e(url('/pipelines')) ?>">Cancel</a>
    </div>
</form>

<?php if ($isEdit): ?>
    <form method="post" action="<?= e(url('/pipelines/' . (int) $pipeline['id'] . '/stages')) ?>" class="card">
        <?= csrf_field() ?>

        <div class="card-head">
            <h2>Stages</h2>
            <p class="card-sub">
                In board order. A stage marked won or lost closes the deals that reach it.
            </p>
        </div>

        <?php if ($stages === []): ?>
            <p class="empty">No stages yet — fill in a row below and save.</p>
        <?php endif; ?>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Order</th>
                        <th scope="col">Win&nbsp;%</th>
                        <th scope="col">Deadline (hours)</th>
                        <th scope="col">Colour</th>
                        <th scope="col">Won</th>
                        <th scope="col">Lost</th>
                        <th scope="col">Deals</th>
                        <th scope="col">Remove</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($stages as $stage): ?>
                    <?php
                    $id    = (int) $stage['id'];
                    $inUse = (int) ($stageUsage[$id] ?? 0);
                    ?>
                    <tr>
                        <td>
                            <input type="text" name="stages[<?= $id ?>][name]" maxlength="80"
                                   value="<?= e($stage['name']) ?>" required
                                   aria-label="Name of stage <?= e($stage['name']) ?>">
                        </td>
                        <td>
                            <input type="number" name="stages[<?= $id ?>][sort_order]" min="0" max="32767"
                                   value="<?= (int) $stage['sort_order'] ?>"
                                   aria-label="Board order of <?= e($stage['name']) ?>">
                        </td>
                        <td>
                            <input type="number" name="stages[<?= $id ?>][probability]" min="0" max="100"
                                   value="<?= (int) $stage['probability'] ?>"
                                   aria-label="Win probability at <?= e($stage['name']) ?>">
                        </td>
                        <td>
                            <input type="number" name="stages[<?= $id ?>][sla_hours]" min="1" max="8760"
                                   value="<?= $stage['sla_hours'] === null ? '' : (int) $stage['sla_hours'] ?>"
                                   aria-label="Response deadline for <?= e($stage['name']) ?>, in hours">
                        </td>
                        <td>
                            <input type="text" name="stages[<?= $id ?>][color]" maxlength="7" size="8"
                                   value="<?= e($stage['color']) ?>"
                                   aria-label="Colour of <?= e($stage['name']) ?>">
                        </td>
                        <td>
                            <input type="checkbox" name="stages[<?= $id ?>][is_won]" value="1"
                                <?= (int) $stage['is_won'] === 1 ? 'checked' : '' ?>
                                   aria-label="<?= e($stage['name']) ?> counts as won">
                        </td>
                        <td>
                            <input type="checkbox" name="stages[<?= $id ?>][is_lost]" value="1"
                                <?= (int) $stage['is_lost'] === 1 ? 'checked' : '' ?>
                                   aria-label="<?= e($stage['name']) ?> counts as lost">
                        </td>
                        <td><?= number_format($inUse) ?></td>
                        <td>
                            <?php if ($inUse === 0): ?>
                                <input type="checkbox" name="stages[<?= $id ?>][remove]" value="1"
                                       aria-label="Remove <?= e($stage['name']) ?>">
                            <?php else: ?>
                                <span class="muted" title="Move its deals first">In use</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php for ($row = 0; $row < $spares; $row++): ?>
                    <tr>
                        <td>
                            <input type="text" name="new_stages[<?= $row ?>][name]" maxlength="80"
                                   placeholder="Add a stage"
                                   aria-label="Name of new stage <?= $row + 1 ?>">
                        </td>
                        <td>
                            <input type="number" name="new_stages[<?= $row ?>][sort_order]" min="0" max="32767"
                                   value="<?= (count($stages) + $row + 1) * 10 ?>"
                                   aria-label="Board order of new stage <?= $row + 1 ?>">
                        </td>
                        <td>
                            <input type="number" name="new_stages[<?= $row ?>][probability]" min="0" max="100"
                                   value="0" aria-label="Win probability at new stage <?= $row + 1 ?>">
                        </td>
                        <td>
                            <input type="number" name="new_stages[<?= $row ?>][sla_hours]" min="1" max="8760"
                                   value="" aria-label="Response deadline for new stage <?= $row + 1 ?>, in hours">
                        </td>
                        <td>
                            <input type="text" name="new_stages[<?= $row ?>][color]" maxlength="7" size="8"
                                   value="#6b4ea8" aria-label="Colour of new stage <?= $row + 1 ?>">
                        </td>
                        <td>
                            <input type="checkbox" name="new_stages[<?= $row ?>][is_won]" value="1"
                                   aria-label="New stage <?= $row + 1 ?> counts as won">
                        </td>
                        <td>
                            <input type="checkbox" name="new_stages[<?= $row ?>][is_lost]" value="1"
                                   aria-label="New stage <?= $row + 1 ?> counts as lost">
                        </td>
                        <td><span class="muted">—</span></td>
                        <td><span class="muted">—</span></td>
                    </tr>
                <?php endfor; ?>
                </tbody>
            </table>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn">Save stages</button>
        </div>

        <p class="field-hint">
            A blank new-stage row is ignored, so saving without filling one in changes nothing. A
            stage that still holds deals cannot be removed — move them first.
        </p>
    </form>
<?php endif; ?>
