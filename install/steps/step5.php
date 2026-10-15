<?php

declare(strict_types=1);

/**
 * Step 5 — venue profile.
 *
 * Pre-filled with The Castle Fun Center's public details so the common case is
 * a glance and a click, but every field is editable and the page says plainly
 * that the defaults need checking.
 *
 * @var string              $token
 * @var array<string,mixed> $state
 */

$esc = static fn (mixed $v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');

$venue = $state['venue'] ?? [
    'name'        => 'The Castle Fun Center',
    'email'       => '',
    'phone'       => '(845) 469-2116',
    'address_1'   => '109 Brookside Ave',
    'city'        => 'Chester',
    'state'       => 'NY',
    'postal_code' => '10918',
    'website'     => 'https://thecastlefuncenter.com',
    'timezone'    => 'America/New_York',
    'tax_rate'    => 8.125,
];

// US zones first, since this is a single-venue install in New York.
$zones = array_merge(
    DateTimeZone::listIdentifiers(DateTimeZone::AMERICA),
    ['UTC']
);

?>
<div class="panel">
    <h2>Your venue</h2>
    <p class="lede">
        These appear on quotes, in message footers, and in the legal text that every bulk email must
        carry. The phone number is what a family sees when a booking falls inside the call-us window.
    </p>

    <div class="msg warn">
        <strong>Check the pre-filled values.</strong>
        They came from the venue's public website and may be out of date — particularly the tax rate.
        Nothing here is hard-coded anywhere; these are just the starting values.
    </div>

    <form method="post" action="index.php?step=5">
        <input type="hidden" name="_token" value="<?= $esc($token) ?>">

        <label for="venue_name">Venue name</label>
        <input type="text" id="venue_name" name="venue_name" value="<?= $esc($venue['name']) ?>" required>

        <div class="row two">
            <div>
                <label for="venue_phone">Main phone</label>
                <input type="text" id="venue_phone" name="venue_phone" value="<?= $esc($venue['phone']) ?>">
                <p class="hint">Shown to families who need to call instead of booking online.</p>
            </div>
            <div>
                <label for="venue_email">Main email <span class="opt">— optional</span></label>
                <input type="email" id="venue_email" name="venue_email" value="<?= $esc($venue['email']) ?>">
            </div>
        </div>

        <label for="address_1">Street address</label>
        <input type="text" id="address_1" name="address_1" value="<?= $esc($venue['address_1']) ?>">
        <p class="hint">
            Required by law in every bulk email you send, so Castle CRM puts it in the footer
            automatically.
        </p>

        <div class="row three">
            <div>
                <label for="city">City</label>
                <input type="text" id="city" name="city" value="<?= $esc($venue['city']) ?>">
            </div>
            <div>
                <label for="state">State</label>
                <input type="text" id="state" name="state" value="<?= $esc($venue['state']) ?>" maxlength="40">
            </div>
            <div>
                <label for="postal_code">ZIP</label>
                <input type="text" id="postal_code" name="postal_code" value="<?= $esc($venue['postal_code']) ?>">
            </div>
        </div>

        <label for="website">Website <span class="opt">— optional</span></label>
        <input type="text" id="website" name="website" value="<?= $esc($venue['website']) ?>">

        <div class="row two">
            <div>
                <label for="timezone">Timezone</label>
                <select id="timezone" name="timezone" required>
                    <?php foreach ($zones as $zone): ?>
                        <option value="<?= $esc($zone) ?>"
                            <?= $zone === $venue['timezone'] ? 'selected' : '' ?>><?= $esc($zone) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="hint">
                    Everything is stored in UTC and shown in this zone. It also decides when quiet
                    hours start, so a reminder never goes out at 3am.
                </p>
            </div>
            <div>
                <label for="tax_rate">Sales tax rate (%)</label>
                <input type="number" id="tax_rate" name="tax_rate" value="<?= $esc($venue['tax_rate']) ?>"
                       step="0.001" min="0" max="30">
                <p class="hint">Applied to quotes. Orange County NY is 8.125% at the time of writing — confirm it.</p>
            </div>
        </div>

        <div class="actions">
            <button type="submit">Continue</button>
            <a class="btn ghost" href="index.php?step=4">Back</a>
        </div>
    </form>
</div>
