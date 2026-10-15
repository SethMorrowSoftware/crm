<?php

declare(strict_types=1);

/**
 * Choose a new password.
 *
 * @var string $token
 * @var string $email
 */
?>
<h1 class="bare-title">Choose a new password</h1>
<p class="bare-sub">Setting a new password for <strong><?= e($email) ?></strong>.</p>

<form method="post" action="<?= e(url('/reset-password')) ?>" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">

    <div class="field">
        <label for="password">New password</label>
        <input type="password" id="password" name="password" required
               autocomplete="new-password" minlength="12" autofocus>
        <p class="field-hint">
            At least 12 characters. Three or four unrelated words beat a short password with
            punctuation in it.
        </p>
    </div>

    <div class="field">
        <label for="password_confirmation">Confirm new password</label>
        <input type="password" id="password_confirmation" name="password_confirmation"
               required autocomplete="new-password" minlength="12">
    </div>

    <button type="submit" class="btn btn-block">Change my password</button>
</form>

<p class="bare-foot">
    Changing your password signs you out everywhere else.
</p>
