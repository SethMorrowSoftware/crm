<?php

declare(strict_types=1);

/**
 * Step 6 — the owner account.
 *
 * @var string              $token
 * @var array<string,mixed> $state
 */

$esc = static fn (mixed $v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');

$owner = $state['owner'] ?? ['name' => '', 'email' => ''];

?>
<div class="panel">
    <h2>Your owner account</h2>
    <p class="lede">
        This account holds every permission and cannot be locked out — it is the way back in if a
        custom role is ever misconfigured. You can add the rest of your team afterwards.
    </p>

    <form method="post" action="index.php?step=6" autocomplete="off">
        <input type="hidden" name="_token" value="<?= $esc($token) ?>">

        <label for="owner_name">Your name</label>
        <input type="text" id="owner_name" name="owner_name" value="<?= $esc($owner['name']) ?>" required>

        <label for="owner_email">Your email address</label>
        <input type="email" id="owner_email" name="owner_email" value="<?= $esc($owner['email']) ?>"
               required autocomplete="username">
        <p class="hint">
            This is your sign-in name. While Safe Mode is on, every message Castle CRM would have
            sent to a guest comes here instead — so use an address you actually read.
        </p>

        <label for="owner_password">Password</label>
        <input type="password" id="owner_password" name="owner_password" required
               autocomplete="new-password" minlength="12">
        <p class="hint">
            At least 12 characters. Length beats punctuation: three or four unrelated words are both
            stronger and easier to type than <code class="inline">P@ssw0rd!</code>.
        </p>

        <label for="owner_password_confirmation">Confirm password</label>
        <input type="password" id="owner_password_confirmation" name="owner_password_confirmation"
               required autocomplete="new-password" minlength="12">

        <div class="msg warn" style="margin-top:1.5rem">
            <strong>Castle CRM holds guest contact details and consent records.</strong>
            Use a password you do not use anywhere else, and store it in a password manager. There is
            no "forgot password" shortcut that bypasses your email.
        </div>

        <div class="actions">
            <button type="submit">Continue</button>
            <a class="btn ghost" href="index.php?step=5">Back</a>
        </div>
    </form>
</div>
