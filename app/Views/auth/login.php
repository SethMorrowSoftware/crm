<?php

declare(strict_types=1);

/** Sign-in form. */
?>
<h1 class="bare-title">Sign in</h1>
<p class="bare-sub">Welcome back.</p>

<form method="post" action="<?= e(url('/login')) ?>" class="stack">
    <?= csrf_field() ?>

    <div class="field">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email" value="<?= e(old('email')) ?>"
               required autocomplete="username" autofocus
               <?= has_error('email') ? 'aria-invalid="true" aria-describedby="email-error"' : '' ?>>
        <?php if ($error = error_for('email')): ?>
            <p class="field-error" id="email-error"><?= e($error) ?></p>
        <?php endif; ?>
    </div>

    <div class="field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required autocomplete="current-password">
    </div>

    <button type="submit" class="btn btn-block">Sign in</button>
</form>

<p class="bare-foot">
    <a href="<?= e(url('/forgot-password')) ?>">Forgotten your password?</a>
</p>
