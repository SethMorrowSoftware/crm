<?php

declare(strict_types=1);

/**
 * The Text Club sign-up, as a guest sees it on the venue's website.
 *
 * One field. A sign-up form that asks for a name, an email, a birthday and a
 * postcode before it will take a phone number is a sign-up form people close,
 * and every extra field is a reason not to finish. The name is offered and
 * optional because a greeting reads better with one, and that is all.
 *
 * @var array{name:string, phone:string} $venue
 * @var string                           $disclosure
 * @var string                           $honeypot
 */

?>
<h1 class="bare-title">Join the <?= e($venue['name']) ?> Text Club</h1>
<p class="bare-sub">
    One text a month with an offer worth having. No more than that, and you can stop
    any time by replying STOP.
</p>

<form method="post" action="<?= e(url('/text-club/join')) ?>" class="form card">
    <?= csrf_field() ?>

    <div class="field">
        <label for="phone">Mobile number</label>
        <input type="tel" id="phone" name="phone" inputmode="tel" autocomplete="tel"
               required maxlength="24" value="<?= e((string) old('phone')) ?>"
               aria-describedby="phone-help">
        <p class="field-hint" id="phone-help">
            A US mobile that can receive texts. We will send one message asking you to
            confirm &mdash; you are not on the list until you reply to it.
        </p>
    </div>

    <div class="field">
        <label for="first_name">First name <span class="muted">(optional)</span></label>
        <input type="text" id="first_name" name="first_name" maxlength="80"
               autocomplete="given-name" value="<?= e((string) old('first_name')) ?>">
    </div>

    <?php
    /*
     * The honeypot. Hidden from people and from screen readers alike —
     * `aria-hidden` plus `tabindex="-1"` so it is not announced and cannot be
     * tabbed into, which matters because a labelled field a screen reader
     * reads out and asks somebody to fill in is a trap for the wrong person.
     * It is styled off-screen rather than `display:none`, since the simpler
     * bots check for that.
     */
    ?>
    <div class="honeypot" aria-hidden="true">
        <label for="<?= e($honeypot) ?>">Company website</label>
        <input type="text" id="<?= e($honeypot) ?>" name="<?= e($honeypot) ?>"
               tabindex="-1" autocomplete="off">
    </div>

    <div class="club-consent">
        <p>
            <strong>What you are agreeing to.</strong> By giving us your number you agree to
            receive marketing texts from <?= e($venue['name']) ?> at that number, sent
            automatically. Agreeing is not a condition of buying anything.
        </p>
        <p class="club-wording"><?= e($disclosure) ?></p>
        <p>
            Reply STOP to stop, HELP for help.
            <?php if ($venue['phone'] !== ''): ?>
                Or call us on <?= e($venue['phone']) ?>.
            <?php endif; ?>
        </p>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-block">Send me the confirmation text</button>
    </div>
</form>
