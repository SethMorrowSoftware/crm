<?php

declare(strict_types=1);

/**
 * @var array<string,mixed>|null            $role
 * @var array<string,string>                $granted      slug => scope
 * @var array<string,array<string,string>>  $catalog      group => [slug => label]
 * @var array<string,string>|null           $myPermissions null when owner
 * @var int                                 $userCount
 */

$isEdit = $role !== null;
$action = $isEdit ? '/settings/roles/' . (int) $role['id'] : '/settings/roles';
?>
<p class="breadcrumbs"><a href="<?= e(url('/settings/roles')) ?>">Roles</a> /
    <?= $isEdit ? e($role['name']) : 'New role' ?></p>

<form method="post" action="<?= e(url($action)) ?>" class="card form">
    <?= csrf_field() ?>

    <div class="field">
        <label for="name">Role name</label>
        <input type="text" id="name" name="name" required
               value="<?= e(old('name') ?: ($role['name'] ?? '')) ?>">
    </div>

    <div class="field">
        <label for="description">What this role is for</label>
        <input type="text" id="description" name="description" maxlength="255"
               value="<?= e(old('description') ?: ($role['description'] ?? '')) ?>">
        <p class="field-hint">Shown wherever the role is offered, so whoever assigns it picks correctly.</p>
    </div>

    <?php if ($isEdit && $userCount > 0): ?>
        <div class="alert alert-info">
            <?= icon('info') ?>
            <p>
                <?= (int) $userCount ?> <?= $userCount === 1 ? 'person holds' : 'people hold' ?>
                this role. Changes apply to them on their next request — no re-login needed.
            </p>
        </div>
    <?php endif; ?>

    <h2 class="form-section-title">Permissions</h2>
    <p class="field-hint">
        A <code class="code-inline">view</code> permission reveals a page and opens its read API;
        unticking it hides the nav item and bounces the route. The rest gate actions.
    </p>

    <?php foreach ($catalog as $group => $permissions): ?>
        <fieldset class="permission-group">
            <legend><?= e($group) ?></legend>
            <?php foreach ($permissions as $slug => $label): ?>
                <?php
                // A non-owner cannot grant what they do not hold, so those rows
                // are shown disabled rather than hidden — clearer than a gap.
                $blocked = $myPermissions !== null && !isset($myPermissions[$slug]);
                $isGranted = isset($granted[$slug]);
                $scope = $granted[$slug] ?? 'all';
                ?>
                <div class="permission-row<?= $blocked ? ' is-blocked' : '' ?>">
                    <input type="checkbox" id="perm_<?= e($slug) ?>" name="permissions[]"
                           value="<?= e($slug) ?>"
                        <?= $isGranted ? 'checked' : '' ?> <?= $blocked ? 'disabled' : '' ?>>
                    <label for="perm_<?= e($slug) ?>">
                        <?= e($label) ?>
                        <code class="code-inline"><?= e($slug) ?></code>
                    </label>

                    <select name="scopes[<?= e($slug) ?>]" aria-label="Reach for <?= e($label) ?>"
                        <?= $blocked ? 'disabled' : '' ?>>
                        <option value="all"  <?= $scope === 'all'  ? 'selected' : '' ?>>All records</option>
                        <option value="team" <?= $scope === 'team' ? 'selected' : '' ?>>Their team</option>
                        <option value="own"  <?= $scope === 'own'  ? 'selected' : '' ?>>Their own</option>
                    </select>

                    <?php if ($blocked): ?>
                        <span class="permission-blocked" title="You do not hold this permission yourself">
                            <?= icon('lock', 14) ?>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </fieldset>
    <?php endforeach; ?>

    <div class="form-actions">
        <button type="submit" class="btn"><?= $isEdit ? 'Save role' : 'Create role' ?></button>
        <a class="btn btn-ghost" href="<?= e(url('/settings/roles')) ?>">Cancel</a>
    </div>
</form>

<?php if ($isEdit && (int) ($role['is_system'] ?? 0) === 0): ?>
    <section class="card card-danger">
        <h2>Delete this role</h2>
        <p>Only possible while nobody holds it. Move those people to another role first.</p>
        <form method="post" action="<?= e(url('/settings/roles/' . (int) $role['id'] . '/delete')) ?>"
              data-confirm="Delete the <?= e($role['name']) ?> role?">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-danger"><?= icon('trash', 15) ?> Delete role</button>
        </form>
    </section>
<?php endif; ?>
