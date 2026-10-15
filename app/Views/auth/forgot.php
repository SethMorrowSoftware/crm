<?php

declare(strict_types=1);

/** Request a password reset. */
?>
<h1 class="bare-title">Reset your password</h1>
<p class="bare-sub">
    Enter the address you sign in with and we will send a link to set a new password.
</p>

<form method="post" action="<?= e(url('/forgot-password')) ?>" class="stack">
    <?= csrf_field() ?>

    <div class="field">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email" value="<?= e(old('email')) ?>"
               required autocomplete="username" autofocus>
    </div>

    <button type="submit" class="btn btn-block">Send the reset link</button>
</form>

<p class="bare-foot"><a href="<?= e(url('/login')) ?>">Back to sign in</a></p>
