<?php

declare(strict_types=1);

/**
 * @var array<string,mixed>|null   $user
 * @var list<array<string,mixed>>  $roles
 * @var int                        $sessions
 */

$isEdit = $user !== null;
$action = $isEdit ? '/settings/users/' . (int) $user['id'] : '/settings/users';
$locked = $isEdit && !empty($user['locked_until']) && strtotime((string) $user['locked_until']) > time();
?>
<p class="breadcrumbs"><a href="<?= e(url('/settings/users')) ?>">Users</a> /
    <?= $isEdit ? e($user['name']) : 'New user' ?></p>

<form method="post" action="<?= e(url($action)) ?>" class="card form" autocomplete="off">
    <?= csrf_field() ?>

    <div class="field">
        <label for="name">Name</label>
        <input type="text" id="name" name="name" required
               value="<?= e(old('name') ?: ($user['name'] ?? '')) ?>">
        <?php if ($err = error_for('name')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
    </div>

    <div class="field">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email" required autocomplete="off"
               value="<?= e(old('email') ?: ($user['email'] ?? '')) ?>">
        <p class="field-hint">This is their sign-in name.</p>
        <?php if ($err = error_for('email')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
    </div>

    <div class="field-row">
        <div class="field">
            <label for="phone">Phone</label>
            <input type="text" id="phone" name="phone" value="<?= e(old('phone') ?: ($user['phone'] ?? '')) ?>">
            <p class="field-hint">Used for urgent alerts, like an unresolved complaint.</p>
        </div>
        <div class="field">
            <label for="role_id">Role</label>
            <select id="role_id" name="role_id" required>
                <option value="">Choose a role</option>
                <?php foreach ($roles as $role): ?>
                    <option value="<?= (int) $role['id'] ?>"
                        <?= (string) (old('role_id') ?: ($user['role_id'] ?? '')) === (string) $role['id'] ? 'selected' : '' ?>>
                        <?= e($role['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-hint">You can only grant roles whose permissions you hold yourself.</p>
        </div>
    </div>

    <div class="field">
        <label for="password"><?= $isEdit ? 'New password' : 'Password' ?></label>
        <input type="password" id="password" name="password" autocomplete="new-password"
               minlength="12" <?= $isEdit ? '' : 'required' ?>>
        <p class="field-hint">
            At least 12 characters.
            <?= $isEdit ? 'Leave blank to keep their current password. Changing it signs them out everywhere.' : '' ?>
        </p>
    </div>

    <?php if ($isEdit): ?>
        <div class="field field-check">
            <input type="checkbox" id="is_active" name="is_active" value="1"
                <?= (int) ($user['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
            <label for="is_active">Active</label>
            <p class="field-hint">
                Unticking this blocks sign-in and ends their sessions immediately. Prefer it to
                deleting, which loses the link between them and the deals they worked.
            </p>
        </div>

        <?php if ($locked): ?>
            <div class="field field-check">
                <input type="checkbox" id="clear_lockout" name="clear_lockout" value="1">
                <label for="clear_lockout">Clear the lockout</label>
                <p class="field-hint">
                    This account is locked after too many failed sign-ins until
                    <?= e(local_time((string) $user['locked_until'])) ?>.
                </p>
            </div>
        <?php endif; ?>

        <?php if ($sessions > 0): ?>
            <p class="field-hint">
                <?= (int) $sessions ?> active session<?= $sessions === 1 ? '' : 's' ?>.
            </p>
        <?php endif; ?>
    <?php endif; ?>

    <div class="form-actions">
        <button type="submit" class="btn"><?= $isEdit ? 'Save changes' : 'Create the account' ?></button>
        <a class="btn btn-ghost" href="<?= e(url('/settings/users')) ?>">Cancel</a>
    </div>
</form>

<?php if ($isEdit): ?>
    <section class="card card-danger">
        <h2>Remove this user</h2>
        <p>
            Their audit history, deals and bookings are kept — only their access goes. This cannot be
            undone, and the last active owner can never be removed.
        </p>
        <form method="post" action="<?= e(url('/settings/users/' . (int) $user['id'] . '/delete')) ?>"
              data-confirm="Remove <?= e($user['name']) ?>? Their access ends immediately.">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-danger"><?= icon('trash', 15) ?> Remove user</button>
        </form>
    </section>
<?php endif; ?>
