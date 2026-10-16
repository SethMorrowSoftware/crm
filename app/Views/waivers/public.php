<?php

declare(strict_types=1);

/**
 * The public waiver page at /waiver.
 *
 * One of the two views rendered to somebody with no account, so the same two
 * rules as the public web form apply harder here than anywhere else:
 *
 *   1. It echoes only what WaiverController hands it — the waiver reduced to
 *      the keys a guest may know, and a booking reduced to its number and
 *      date. There is no row from `bookings` in scope, no contact, no user.
 *   2. Every value goes through e(), the waiver body included. That text is
 *      typed by a member of staff into an admin form, which makes it
 *      untrusted input to this page like a guest's own typing — and it is
 *      rendered inside <pre> as plain text, so nothing pasted into it can
 *      change the page around the agreement.
 *
 * @var array<string,string>|null $waiver
 * @var array<string,mixed>|null  $booking
 * @var bool                      $sent
 * @var array<string,mixed>|null  $signature
 * @var string                    $venueName
 * @var string                    $agreement
 * @var int                       $maxMinors
 */

/**
 * The per-field message, from the session.
 *
 * `error_for()` rather than a view variable: the controller flashes the
 * errors and redirects, which is the only way a message survives to the
 * request that renders the form again. The public web form declares an
 * `$errors` variable that nothing ever passes, so its per-field messages have
 * never rendered — fixed there at the same time as this was written.
 */
$errorFor = static fn (string $field): ?string => error_for($field);

$oldText = static function (string $field): string {
    $value = old($field);

    return is_scalar($value) ? (string) $value : '';
};

?>
<?php if ($sent && $signature !== null): ?>
    <h1 class="bare-title">Thank you</h1>
    <p class="bare-sub">
        Signed by <?= e((string) $signature['signer_name']) ?>.
        <?php if (($signature['expires_at'] ?? null) !== null): ?>
            It stands until <?= e((string) $signature['expires_at']) ?>.
        <?php endif; ?>
    </p>
    <p class="bare-foot">
        <a href="<?= e(url('/waiver/' . (string) $signature['public_token'])) ?>">
            Keep a copy of what you signed
        </a>
    </p>

<?php elseif ($waiver === null): ?>
    <h1 class="bare-title">Nothing to sign just yet</h1>
    <p class="bare-sub">
        <?= e($venueName) ?> has not published a waiver. Please ask a member of staff — there is
        nothing for you to do here.
    </p>

<?php else: ?>
    <h1 class="bare-title"><?= e($waiver['title']) ?></h1>

    <?php if ($booking !== null): ?>
        <p class="bare-sub">
            For <?= e(trim((string) ($booking['guest_of_honor_name'] ?? '')) ?: 'the party') ?>
            on <?= e((string) $booking['event_date']) ?>
            (<?= e((string) $booking['booking_number']) ?>).
        </p>
    <?php endif; ?>

    <?php if ($message = $errorFor('_form')): ?>
        <p class="field-error"><?= e($message) ?></p>
    <?php endif; ?>

    <pre class="code-block"><?= e($waiver['body']) ?></pre>

    <form method="post" action="<?= e(url('/waiver')) ?>" class="form">
        <?= csrf_field() ?>
        <?php if ($booking !== null): ?>
            <input type="hidden" name="booking" value="<?= e((string) $booking['booking_number']) ?>">
        <?php endif; ?>

        <div class="field">
            <label for="signer_name">Your full name</label>
            <input type="text" id="signer_name" name="signer_name" maxlength="120" required
                   autocomplete="name" value="<?= e($oldText('signer_name')) ?>">
            <?php if ($message = $errorFor('signer_name')): ?>
                <p class="field-error"><?= e($message) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="signer_email">Email address</label>
            <input type="email" id="signer_email" name="signer_email" maxlength="191"
                   autocomplete="email" value="<?= e($oldText('signer_email')) ?>">
            <?php if ($message = $errorFor('signer_email')): ?>
                <p class="field-error"><?= e($message) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="signer_phone">Or a phone number</label>
            <input type="tel" id="signer_phone" name="signer_phone" maxlength="32"
                   autocomplete="tel" value="<?= e($oldText('signer_phone')) ?>">
            <p class="field-hint">One or the other, so we can reach you if we need to.</p>
        </div>

        <div class="field field-check">
            <input type="checkbox" id="for_self" name="for_self" value="1"
                <?= $oldText('for_self') === '1' ? 'checked' : '' ?>>
            <label for="for_self">I am signing for myself</label>
        </div>

        <fieldset class="field">
            <legend>Children you are signing for</legend>
            <p class="field-hint">
                Leave these empty if there are none. A parent or guardian signs for anybody
                under 18.
            </p>
            <?php for ($row = 0; $row < 6; $row++): ?>
                <div class="field">
                    <label for="minor_<?= e((string) $row) ?>">
                        Child <?= e((string) ($row + 1)) ?>
                    </label>
                    <input type="text" id="minor_<?= e((string) $row) ?>"
                           name="minors[<?= e((string) $row) ?>][name]" maxlength="120"
                           placeholder="Their name"
                           aria-label="Child <?= e((string) ($row + 1)) ?>: name">
                    <input type="number" name="minors[<?= e((string) $row) ?>][age]"
                           min="0" max="120" placeholder="Age"
                           aria-label="Child <?= e((string) ($row + 1)) ?>: age">
                </div>
            <?php endfor; ?>
            <?php if ($message = $errorFor('minors')): ?>
                <p class="field-error"><?= e($message) ?></p>
            <?php endif; ?>
        </fieldset>

        <div class="field field-check">
            <input type="checkbox" id="agree" name="agree" value="1" required>
            <label for="agree"><?= e($agreement) ?></label>
            <?php if ($message = $errorFor('agree')): ?>
                <p class="field-error"><?= e($message) ?></p>
            <?php endif; ?>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-block">Sign it</button>
        </div>
    </form>

    <p class="bare-foot">
        <?= e($venueName) ?> records your name, the version of this waiver, the time, and the
        address you signed from. That is what a signature is for. Nothing here is used to market
        to you.
    </p>
<?php endif; ?>
