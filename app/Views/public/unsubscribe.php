<?php

declare(strict_types=1);

/**
 * The page a guest lands on after clicking unsubscribe.
 *
 * No login, no form, no "are you sure" — the click already said so. It tells
 * them plainly what happened and what still applies.
 *
 * @var string      $state   'done' | 'already' | 'invalid'
 * @var string|null $address
 * @var string      $venueName
 */
?>
<?php if ($state === 'invalid'): ?>
    <h1 class="bare-title">That link did not work</h1>
    <p class="bare-sub">
        The unsubscribe link was not valid. It may have been truncated by your email
        program when you copied it.
    </p>
    <p class="bare-sub">
        Reply <strong>STOP</strong> to any text from us, or reply to the email itself and
        a person will take you off the list.
    </p>
<?php elseif ($state === 'already'): ?>
    <h1 class="bare-title">You were already unsubscribed</h1>
    <p class="bare-sub">
        <strong><?= e($address) ?></strong> is already off our marketing list, so nothing
        changed. You will not receive promotional emails from us.
    </p>
<?php else: ?>
    <h1 class="bare-title">You have been unsubscribed</h1>
    <p class="bare-sub">
        <strong><?= e($address) ?></strong> will no longer receive marketing emails from
        <?= e($venueName ?? 'us') ?>. That takes effect immediately.
    </p>
<?php endif; ?>

<?php if ($state !== 'invalid'): ?>
    <div class="alert alert-info" style="margin-top:1.25rem">
        <?= icon('info') ?>
        <p>
            If you have a party booked, you will still get the confirmations and reminders
            for it — those are not marketing, and you need them.
        </p>
    </div>
<?php endif; ?>

<p class="bare-foot">
    <?= e($venueName ?? 'The Castle Fun Center') ?>
    <?php if ($phone = setting('venue.phone_display') ?: setting('venue.phone')): ?>
        &middot; <?= e($phone) ?>
    <?php endif; ?>
</p>
