<?php

declare(strict_types=1);

/**
 * Taking a booking.
 *
 * THIS FORM WORKS WITHOUT JAVASCRIPT, which is the constraint that shapes it.
 * Availability is shown by reloading with a date (the "Check the day" button
 * is a plain GET), and a slot that has gone between the page being drawn and
 * the form being sent comes back as a refusal listing the times that ARE
 * free. The optional script below only saves a round trip; nothing depends on
 * it, and there is no build step anywhere in this application.
 *
 * @var array<string,mixed>|null  $booking
 * @var list<array<string,mixed>> $addons
 * @var list<int>                 $rooms       Resource ids already allocated.
 * @var string                    $date
 * @var array<string,mixed>|null  $availability
 * @var list<string>              $types
 * @var list<array<string,mixed>> $packages
 * @var list<array<string,mixed>> $addonCatalogue
 * @var list<array<string,mixed>> $attractionList
 * @var list<array<string,mixed>> $contacts
 * @var list<array<string,mixed>> $hosts
 * @var array<string,mixed>|null  $package
 * @var int                       $taxRate
 * @var string                    $taxLabel
 * @var array<string,string>      $prefill
 * @var bool                      $showMoney
 * @var callable(string):string   $dateLabel
 * @var callable(string):string   $timeLabel
 */

$editing = $booking !== null;
$id      = $editing ? (int) $booking['id'] : 0;
$action  = $editing ? url('/bookings/' . $id) : url('/bookings');
$words   = static fn (string $value): string => ucfirst(str_replace('_', ' ', $value));

/**
 * What the field should hold.
 *
 * Three sources, in order: what was typed and rejected, the stored row when
 * editing, and what the "check the day" step chose. That last one is not a
 * nicety — without it the availability panel showed one package's slots while
 * the form's own select said "no package", and the booking posted with none.
 */
$value = static function (string $field, mixed $fallback = '') use ($booking, $prefill): string {
    $old = old($field);

    if ($old !== '' && $old !== null) {
        return is_scalar($old) ? (string) $old : '';
    }

    $stored = $booking[$field] ?? ($prefill[$field] ?? null) ?? $fallback;

    return $stored === null ? '' : (string) $stored;
};

$money = static function (string $field) use ($booking): string {
    $old = old($field);

    if ($old !== '' && $old !== null) {
        return is_scalar($old) ? (string) $old : '';
    }

    $cents = $booking[$field . '_cents'] ?? null;

    return $cents === null || (int) $cents === 0 ? '' : number_format((int) $cents / 100, 2, '.', '');
};

$selectedPackage = (int) $value('package_id', '0');
$selectedDate    = $value('event_date', $date);
$startValue      = substr($value('start_time', $chosenStart ?? ''), 0, 5);

?>
<p class="breadcrumbs">
    <a href="<?= e(url('/bookings')) ?>">Bookings</a> /
    <?= $editing ? e((string) $booking['booking_number']) : 'New' ?>
</p>

<?php if (!$editing): ?>
    <form class="card filter-bar" method="get" action="<?= e(url('/bookings/new')) ?>">
        <p class="page-lede">
            Pick the day and the package first and this will show you what is actually free.
        </p>
        <div class="field">
            <label for="check_date">Date</label>
            <input type="date" id="check_date" name="date" value="<?= e($selectedDate) ?>" required>
        </div>
        <div class="field">
            <label for="check_package">Package</label>
            <select id="check_package" name="package_id">
                <option value="">No package</option>
                <?php foreach ($packages as $option): ?>
                    <option value="<?= e((string) $option['id']) ?>"
                        <?= $selectedPackage === (int) $option['id'] ? 'selected' : '' ?>>
                        <?= e((string) $option['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="check_guests">Guests</label>
            <input type="number" id="check_guests" name="guest_count" min="0" max="2000"
                   value="<?= e($value('guest_count', '12')) ?>">
        </div>
        <div class="field field-actions">
            <button type="submit" class="btn btn-sm">Check the day</button>
        </div>
    </form>
<?php endif; ?>

<?php if ($availability !== null): ?>
    <div class="card">
        <h2>What is free on <?= e($dateLabel($selectedDate)) ?></h2>
        <?php if (($availability['ok'] ?? false) === true): ?>
            <p class="muted">
                Open <?= e((string) $availability['opens_label']) ?> to
                <?= e((string) $availability['closes_label']) ?>.
                <?= e((string) $availability['message']) ?>
            </p>
            <p class="page-lede">
                <?php foreach ($availability['slots'] as $slot): ?>
                    <?php
                    // LINKS, not submit buttons sharing the form's own
                    // `start_time` field. A <button name="start_time"> plus the
                    // time input below would both be sent, and the later one in
                    // the document wins — so the button would be ignored and
                    // every slot would look broken. A link reloads the page with
                    // the time chosen, which also leaves it visible in the field.
                    $slotTime = sprintf('%02d:%02d', intdiv((int) $slot['start'], 60), (int) $slot['start'] % 60);
                    $query    = http_build_query(array_filter([
                        'date'        => $selectedDate,
                        'package_id'  => $selectedPackage > 0 ? (string) $selectedPackage : '',
                        'guest_count' => $value('guest_count', '12'),
                        'start'       => $slotTime,
                    ], static fn (string $v): bool => $v !== ''));
                    ?>
                    <a class="btn btn-sm <?= $startValue === $slotTime ? '' : 'btn-ghost' ?>"
                       href="<?= e(url('/bookings/new?' . $query)) ?>"
                        <?= $startValue === $slotTime ? 'aria-current="true"' : '' ?>>
                        <?= e((string) $slot['start_label']) ?>
                        <span class="sr-only">
                            — <?= e((string) $slot['free_count']) ?> free:
                            <?= e(implode(', ', $slot['resource_names'])) ?>
                        </span>
                    </a>
                <?php endforeach; ?>
            </p>
            <p class="muted">
                Choosing a time fills it in below and ticks the rooms that are free at it. The
                rooms behind each time are read out to screen readers.
            </p>
        <?php else: ?>
            <div class="alert alert-warning">
                <p>
                    <?= e((string) $availability['message']) ?>
                    <?php if (($availability['earliest_date'] ?? null) !== null): ?>
                        <a href="<?= e(url('/bookings/new?date=' . (string) $availability['earliest_date']
                            . '&package_id=' . $selectedPackage)) ?>">Try that date</a>.
                    <?php endif; ?>
                </p>
            </div>
            <?php if (($availability['taken'] ?? []) !== []): ?>
                <p class="muted">
                    Times that exist but are taken:
                    <?= e(implode(', ', array_column($availability['taken'], 'start_label'))) ?>.
                </p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<form id="booking-form" method="post" action="<?= e($action) ?>" class="form">
    <?= csrf_field() ?>

    <div class="card">
        <h2>The basics</h2>

        <div class="field">
            <label for="type">Type</label>
            <select id="type" name="type" required>
                <?php foreach ($types as $type): ?>
                    <option value="<?= e($type) ?>" <?= $value('type', 'birthday') === $type ? 'selected' : '' ?>>
                        <?= e($words($type)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="package_id">Package</label>
            <select id="package_id" name="package_id">
                <option value="">No package — price it from add-ons</option>
                <?php foreach ($packages as $option): ?>
                    <option value="<?= e((string) $option['id']) ?>"
                        <?= $selectedPackage === (int) $option['id'] ? 'selected' : '' ?>>
                        <?= e((string) $option['name']) ?>
                        <?php if ($showMoney): ?>
                            — <?= e(money((int) $option['base_price_cents'])) ?>
                            for <?= e((string) $option['included_guests']) ?>,
                            <?= e((string) $option['duration_minutes']) ?> minutes
                        <?php endif; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-hint">
                The package decides how long the party runs and how much notice it needs. With no
                package, give the booking an end time below.
            </p>
        </div>

        <div class="field">
            <label for="event_date">Event date</label>
            <input type="date" id="event_date" name="event_date" value="<?= e($selectedDate) ?>" required>
        </div>

        <div class="field">
            <label for="start_time">Start time</label>
            <input type="time" id="start_time" name="start_time" value="<?= e($startValue) ?>" required>
        </div>

        <div class="field">
            <label for="end_time">End time (only needed with no package)</label>
            <input type="time" id="end_time" name="end_time"
                   value="<?= e(substr($value('end_time'), 0, 5)) ?>">
        </div>

        <div class="field">
            <label for="guest_count">Guests</label>
            <input type="number" id="guest_count" name="guest_count" min="0" max="2000" required
                   value="<?= e($value('guest_count', '12')) ?>">
        </div>

        <div class="field">
            <label for="adult_count">Of those, adults (optional)</label>
            <input type="number" id="adult_count" name="adult_count" min="0" max="2000"
                   value="<?= e($value('adult_count', '0')) ?>">
        </div>

        <div class="field">
            <label for="child_count">Of those, children (optional)</label>
            <input type="number" id="child_count" name="child_count" min="0" max="2000"
                   value="<?= e($value('child_count', '0')) ?>">
        </div>
    </div>

    <div class="card">
        <h2>Who it is for</h2>

        <div class="field">
            <label for="contact_id">Family</label>
            <select id="contact_id" name="contact_id">
                <option value="">Nobody linked yet</option>
                <?php foreach ($contacts as $contact): ?>
                    <option value="<?= e((string) $contact['id']) ?>"
                        <?= (int) $value('contact_id', '0') === (int) $contact['id'] ? 'selected' : '' ?>>
                        <?= e(trim(((string) $contact['first_name']) . ' ' . ((string) $contact['last_name']))) ?>
                        <?php if (trim((string) ($contact['email'] ?? '')) !== ''): ?>
                            — <?= e((string) $contact['email']) ?>
                        <?php endif; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="guest_of_honor_name">Guest of honour</label>
            <input type="text" id="guest_of_honor_name" name="guest_of_honor_name" maxlength="120"
                   value="<?= e($value('guest_of_honor_name')) ?>">
        </div>

        <div class="field">
            <label for="guest_of_honor_age">Turning (optional)</label>
            <input type="number" id="guest_of_honor_age" name="guest_of_honor_age" min="0" max="120"
                   value="<?= e($value('guest_of_honor_age')) ?>">
        </div>

        <div class="field">
            <label for="host_user_id">Party host (optional)</label>
            <select id="host_user_id" name="host_user_id">
                <option value="">Nobody yet</option>
                <?php foreach ($hosts as $host): ?>
                    <option value="<?= e((string) $host['id']) ?>"
                        <?= (int) $value('host_user_id', '0') === (int) $host['id'] ? 'selected' : '' ?>>
                        <?= e((string) $host['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="card">
        <h2>Room</h2>
        <?php
        $options = $availability['resources'] ?? [];

        if ($options === [] && ($availability['ok'] ?? false) === true) {
            $options = [];
        }
        ?>
        <?php if ($options === []): ?>
            <p class="muted">
                Check a date above to see which rooms are free. A booking can be saved without a
                room and have one allocated later.
            </p>
        <?php else: ?>
            <p class="field-hint">
                More than one may be chosen — a large group sometimes takes two rooms.
            </p>
            <?php foreach ($options as $room): ?>
                <div class="field field-check">
                    <input type="checkbox" id="room_<?= e((string) $room['id']) ?>"
                           name="resource_ids[]" value="<?= e((string) $room['id']) ?>"
                        <?= in_array((int) $room['id'], $rooms, true) ? 'checked' : '' ?>>
                    <label for="room_<?= e((string) $room['id']) ?>">
                        <?= e((string) $room['name']) ?>
                        <?php if (($room['capacity'] ?? null) !== null): ?>
                            (holds <?= e((string) $room['capacity']) ?>)
                        <?php endif; ?>
                    </label>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Add-ons</h2>
        <p class="field-hint">
            Put a number against anything the family is having. An empty box means they are not
            having it. A catalogued add-on is always priced from the catalogue, whatever is typed
            here.
        </p>

        <?php foreach ($addonCatalogue as $index => $catalogueItem): ?>
            <?php
            $existing = null;

            foreach ($addons as $row) {
                if ((int) ($row['addon_id'] ?? 0) === (int) $catalogueItem['id']) {
                    $existing = $row;
                }
            }
            ?>
            <div class="field">
                <label for="addon_<?= e((string) $catalogueItem['id']) ?>">
                    <?= e((string) $catalogueItem['name']) ?>
                    <?php if ($showMoney): ?>
                        — <?= e(money((int) $catalogueItem['price_cents'])) ?>
                        <?= e($catalogueItem['unit'] === 'flat'
                            ? 'each'
                            : str_replace('_', ' ', (string) $catalogueItem['unit'])) ?>
                    <?php endif; ?>
                </label>
                <input type="hidden" name="addons[<?= e((string) $index) ?>][addon_id]"
                       value="<?= e((string) $catalogueItem['id']) ?>">
                <input type="text" id="addon_<?= e((string) $catalogueItem['id']) ?>"
                       name="addons[<?= e((string) $index) ?>][qty]" inputmode="decimal"
                       placeholder="none"
                       value="<?= e($existing === null
                           ? ''
                           : rtrim(rtrim((string) $existing['qty'], '0'), '.')) ?>">
            </div>
        <?php endforeach; ?>

        <?php
        // Two free rows for anything the catalogue does not carry. Indexed
        // past the catalogue so the two sets cannot collide.
        $freeBase = count($addonCatalogue);
        ?>
        <?php for ($row = 0; $row < 2; $row++): ?>
            <?php $slot = $freeBase + $row; ?>
            <?php
            // Every control carries its own name: a single <label for> points
            // at one input, and a placeholder is not an accessible name. The
            // static accessibility gate caught these three the first time this
            // form was written, which is what it is for.
            $rowLabel = 'Extra item ' . ($row + 1);
            ?>
            <div class="field">
                <label for="free_addon_<?= e((string) $row) ?>"><?= e($rowLabel) ?> (optional)</label>
                <input type="text" id="free_addon_<?= e((string) $row) ?>"
                       name="addons[<?= e((string) $slot) ?>][description]" maxlength="191"
                       placeholder="What it is"
                       aria-label="<?= e($rowLabel) ?>: what it is">
                <input type="text" name="addons[<?= e((string) $slot) ?>][unit]" inputmode="decimal"
                       placeholder="Price each"
                       aria-label="<?= e($rowLabel) ?>: price each">
                <input type="text" name="addons[<?= e((string) $slot) ?>][qty]" inputmode="decimal"
                       placeholder="How many"
                       aria-label="<?= e($rowLabel) ?>: how many">
                <select name="addons[<?= e((string) $slot) ?>][unit_basis]"
                        aria-label="<?= e($rowLabel) ?>: charged each, per guest or per hour">
                    <option value="flat">Each</option>
                    <option value="per_guest">Per guest</option>
                    <option value="per_hour">Per hour</option>
                </select>
            </div>
        <?php endfor; ?>
    </div>

    <div class="card">
        <h2>What happens when (optional)</h2>
        <p class="field-hint">
            The attractions this party uses, and when. This is what the run sheet prints as the
            timeline, and it is how a waiver requirement reaches the booking.
        </p>
        <?php for ($row = 0; $row < 4; $row++): ?>
            <div class="field">
                <label for="attraction_<?= e((string) $row) ?>">Attraction</label>
                <select id="attraction_<?= e((string) $row) ?>"
                        name="attractions[<?= e((string) $row) ?>][attraction_id]">
                    <option value="">—</option>
                    <?php foreach ($attractionList as $attraction): ?>
                        <option value="<?= e((string) $attraction['id']) ?>">
                            <?= e((string) $attraction['name']) ?>
                            <?= (int) $attraction['requires_waiver'] === 1 ? '(waiver)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="time" name="attractions[<?= e((string) $row) ?>][start_time]"
                       aria-label="Attraction <?= e((string) ($row + 1)) ?> start time">
                <input type="time" name="attractions[<?= e((string) $row) ?>][end_time]"
                       aria-label="Attraction <?= e((string) ($row + 1)) ?> end time">
            </div>
        <?php endfor; ?>
    </div>

    <div class="card">
        <h2>The things that matter on the day</h2>

        <div class="field">
            <label for="allergies">Allergies</label>
            <input type="text" id="allergies" name="allergies" maxlength="500"
                   value="<?= e($value('allergies')) ?>">
            <p class="field-hint">
                Printed in bold at the top of the run sheet, and shown to every member of staff
                who can see the booking — including those who cannot see its figures.
            </p>
        </div>

        <div class="field">
            <label for="dietary_notes">Other dietary notes</label>
            <input type="text" id="dietary_notes" name="dietary_notes" maxlength="500"
                   value="<?= e($value('dietary_notes')) ?>">
        </div>

        <div class="field">
            <label for="cake_notes">Cake</label>
            <input type="text" id="cake_notes" name="cake_notes" maxlength="500"
                   value="<?= e($value('cake_notes')) ?>"
                   placeholder="Bringing their own, out at 3pm">
        </div>

        <div class="field">
            <label for="special_requests">Anything the family has asked for</label>
            <textarea id="special_requests" name="special_requests" rows="3"
                      maxlength="5000"><?= e($value('special_requests')) ?></textarea>
        </div>

        <div class="field">
            <label for="internal_notes">Internal notes (the family never sees these)</label>
            <textarea id="internal_notes" name="internal_notes" rows="3"
                      maxlength="5000"><?= e($value('internal_notes')) ?></textarea>
        </div>
    </div>

    <?php if ($showMoney): ?>
        <div class="card">
            <h2>Money</h2>
            <p class="field-hint">
                The total is worked out from the package, the headcount and the add-ons — it is
                never taken from this form. Tax is added at <?= e($taxLabel) ?>%.
            </p>

            <div class="field">
                <label for="discount">Discount (optional)</label>
                <input type="text" id="discount" name="discount" inputmode="decimal"
                       placeholder="0.00" value="<?= e($money('discount')) ?>">
            </div>

            <div class="field field-check">
                <input type="checkbox" id="tax_exempt" name="tax_exempt" value="1"
                    <?= old('tax_exempt') === '1' ? 'checked' : '' ?>>
                <label for="tax_exempt">Tax exempt (a school, a church, a registered charity)</label>
            </div>

            <div class="field">
                <label for="deposit">Deposit (optional — the package's own is used if blank)</label>
                <input type="text" id="deposit" name="deposit" inputmode="decimal"
                       placeholder="100.00" value="<?= e($money('deposit')) ?>">
            </div>
        </div>
    <?php endif; ?>

    <?php if (!$editing): ?>
        <div class="card">
            <div class="field">
                <label for="status">Take it as</label>
                <select id="status" name="status">
                    <option value="inquiry">An enquiry — nothing promised yet</option>
                    <option value="held">A hold — the room is kept for them</option>
                </select>
                <p class="field-hint">
                    Both hold the room against other bookings. Confirming is a separate step on
                    the booking itself, and it is what adds the checklist.
                </p>
            </div>
        </div>
    <?php endif; ?>

    <div class="form-actions">
        <button type="submit" class="btn"><?= $editing ? 'Save the booking' : 'Take the booking' ?></button>
        <a class="btn btn-ghost"
           href="<?= e(url($editing ? '/bookings/' . $id : '/bookings')) ?>">Cancel</a>
    </div>
</form>
