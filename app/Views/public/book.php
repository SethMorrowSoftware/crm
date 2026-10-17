<?php

declare(strict_types=1);

/**
 * The public party-enquiry page at /book.
 *
 * The third and last view rendered to somebody with no account, so the two
 * rules the public web form and the waiver page state apply here as well:
 *
 *   1. It echoes only what PublicBookingController hands it — the active
 *      packages, one availability answer, and a price worked out from them.
 *      There is no contact, no user, and no other family's booking in scope.
 *      The only `bookings` row it ever sees is the six-column read of the
 *      enquiry just taken, shown back to the person who typed it.
 *   2. Every value goes through e(), package names and descriptions included:
 *      those are typed by staff into an admin form, which makes them
 *      untrusted input to this page like a guest's own typing.
 *
 * THE SHAPE OF THE PAGE IS THE POINT. A family answers three questions in
 * order — which party, which day, which time — and only then is asked for
 * their name. Each of the first two is a GET, so a half-finished enquiry is a
 * URL that can be shared, bookmarked or reloaded, and nothing is written
 * until the final POST.
 *
 * The time is chosen by following a LINK, not by pressing one of a row of
 * submit buttons. A button named `start_time` inside a form that also carries
 * a `start_time` input loses to the input — which is how the office form's
 * slot picker silently did nothing until a browser walk caught it.
 *
 * @var list<array<string,mixed>>  $packages
 * @var array<string,mixed>|null   $package
 * @var int                        $guests
 * @var string                     $date
 * @var string                     $today
 * @var string                     $maxDate
 * @var array<string,mixed>|null   $availability
 * @var list<array<string,mixed>>  $alternatives
 * @var array<string,mixed>|null   $priced
 * @var string                     $honeypot
 * @var string                     $optInText
 * @var bool                       $sent
 * @var array<string,mixed>|null   $booking
 * @var int|null                   $start
 * @var string                     $startValue
 * @var string                     $startLabel
 * @var string                     $duration
 * @var string                     $venueName
 * @var string                     $venuePhone
 * @var string                     $callSentence
 */

$errorFor = static fn (string $field): ?string => error_for($field);

/**
 * Minutes since midnight as the "14:30" a link carries.
 *
 * Not the slot's own `start_label`: that is "2:30 PM", for a person, and the
 * engine's time parser reads 24-hour clock only — so a link built from the
 * label comes back unreadable and the chosen time is silently dropped. The
 * office booking form formats it the same way, inline, for the same reason.
 */
$timeParam = static fn (int $minutes): string => sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);

$oldText = static function (string $field): string {
    $value = old($field);

    return is_scalar($value) ? (string) $value : '';
};

/** The query string for a link that changes one thing about the current answer. */
$link = static function (array $changes) use ($package, $guests, $date, $startValue): string {
    $query = [
        'package_id'  => (string) (int) ($package['id'] ?? 0),
        'guest_count' => (string) $guests,
        'date'        => $date,
        'start'       => $startValue,
    ];

    foreach ($changes as $key => $value) {
        $query[$key] = (string) $value;
    }

    $query = array_filter($query, static fn (string $value): bool => $value !== '' && $value !== '0');

    return url('/book' . ($query === [] ? '' : '?' . http_build_query($query)));
};

?>
<?php if ($sent): ?>
    <h1 class="bare-title">Thank you</h1>

    <?php if ($booking !== null): ?>
        <p class="bare-sub">
            We have your request and your room is held while we look at it.
        </p>

        <dl class="definition-list">
            <div>
                <dt>Reference</dt>
                <dd><?= e((string) $booking['booking_number']) ?></dd>
            </div>
            <div>
                <dt>Day</dt>
                <dd><?= e(local_date((string) $booking['event_date'])) ?></dd>
            </div>
            <div>
                <dt>Time</dt>
                <dd><?= e(substr((string) $booking['start_time'], 0, 5)) ?></dd>
            </div>
            <div>
                <dt>Guests</dt>
                <dd><?= e((string) (int) $booking['guest_count']) ?></dd>
            </div>
            <div>
                <dt>Estimate</dt>
                <dd><?= e(money((int) $booking['total_cents'])) ?></dd>
            </div>
        </dl>

        <p class="bare-sub">
            Nothing has been charged. Somebody from <?= e($venueName) ?> will be in touch to confirm
            the details and take the deposit of <?= e(money((int) $booking['deposit_cents'])) ?>.
            If you would rather talk it through now, <?= e(lcfirst($callSentence)) ?>
        </p>
    <?php else: ?>
        <p class="bare-sub">We have your request and will be in touch.</p>
    <?php endif; ?>

    <p class="bare-foot">
        <a href="<?= e(url('/book')) ?>">Ask about another party</a>
    </p>
<?php else: ?>
    <?php
    $packageId = (int) ($package['id'] ?? 0);
    $minNotice = (int) ($package['min_notice_days'] ?? 0);
    $includes  = is_string($package['includes'] ?? null)
        ? (json_decode((string) $package['includes'], true) ?: [])
        : (is_array($package['includes'] ?? null) ? $package['includes'] : []);
    ?>

    <h1 class="bare-title">Book a party at <?= e($venueName) ?></h1>
    <p class="bare-sub">
        Pick a package, a day and a time, and tell us how to reach you. We will hold the room while
        we confirm it with you &mdash; nothing is charged on this page.
    </p>

    <?php if ($message = $errorFor('_form')): ?>
        <div class="alert alert-error">
            <?= icon('alert') ?>
            <p><?= e($message) ?></p>
        </div>
    <?php endif; ?>

    <!-- ----------------------------------------------- 1. which party -->
    <section class="book-step" aria-labelledby="step-package">
        <h2 class="book-step-title" id="step-package">1. Which party?</h2>

        <ul class="book-packages">
            <?php foreach ($packages as $option): ?>
                <?php $chosen = (int) $option['id'] === $packageId; ?>
                <li class="book-package<?= $chosen ? ' is-chosen' : '' ?>">
                    <a href="<?= e($link(['package_id' => (int) $option['id'], 'start' => ''])) ?>"
                       <?= $chosen ? 'aria-current="true"' : '' ?>>
                        <strong><?= e((string) $option['name']) ?></strong>
                        <span class="book-package-price">
                            <?= e(money((int) $option['base_price_cents'])) ?>
                            <small>for <?= e((string) (int) $option['included_guests']) ?> guests</small>
                        </span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if (trim((string) ($package['description'] ?? '')) !== ''): ?>
            <p class="book-package-note"><?= e((string) $package['description']) ?></p>
        <?php endif; ?>

        <?php if ($includes !== []): ?>
            <ul class="book-includes">
                <?php foreach (array_slice($includes, 0, 12) as $item): ?>
                    <?php if (is_scalar($item)): ?>
                        <li><?= e((string) $item) ?></li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <!-- ------------------------------------------- 2. how many, which day -->
    <section class="book-step" aria-labelledby="step-day">
        <h2 class="book-step-title" id="step-day">2. How many guests, and which day?</h2>

        <?php
        /*
         * A GET. Choosing a day asks a question; it does not commit anything,
         * so it must not be a POST and must not need a CSRF token to answer.
         */
        ?>
        <form method="get" action="<?= e(url('/book')) ?>" class="form book-day-form">
            <input type="hidden" name="package_id" value="<?= e((string) $packageId) ?>">

            <div class="field">
                <label for="guest_count">Guests</label>
                <input type="number" id="guest_count" name="guest_count" min="1" max="200"
                       value="<?= e((string) $guests) ?>" inputmode="numeric">
                <p class="field-hint">
                    <?= e((string) (int) ($package['included_guests'] ?? 0)) ?> are included;
                    extra guests are <?= e(money((int) ($package['additional_guest_cents'] ?? 0))) ?> each.
                </p>
            </div>

            <div class="field">
                <label for="date">Day</label>
                <input type="date" id="date" name="date" value="<?= e($date) ?>"
                       min="<?= e($today) ?>" max="<?= e($maxDate) ?>">
                <?php if ($message = $errorFor('date')): ?>
                    <p class="field-error"><?= e($message) ?></p>
                <?php endif; ?>
                <?php if ($minNotice > 0): ?>
                    <p class="field-hint">
                        This package needs <?= e((string) $minNotice) ?>
                        <?= $minNotice === 1 ? 'day' : 'days' ?> notice. For anything sooner,
                        <?= e(lcfirst($callSentence)) ?>
                    </p>
                <?php endif; ?>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn">See what is free</button>
            </div>
        </form>
    </section>

    <!-- -------------------------------------------------- 3. which time -->
    <section class="book-step" aria-labelledby="step-time">
        <h2 class="book-step-title" id="step-time">3. Which time?</h2>

        <?php if ($availability === null): ?>
            <p class="empty">Choose a day above and we will show you the times that are free.</p>

        <?php elseif (($availability['ok'] ?? false) === true): ?>
            <p class="book-avail-note"><?= e((string) $availability['message']) ?></p>

            <ul class="book-slots">
                <?php foreach ($availability['slots'] as $slot): ?>
                    <?php $isChosen = $start !== null && (int) $slot['start'] === $start; ?>
                    <li>
                        <a class="book-slot<?= $isChosen ? ' is-chosen' : '' ?>"
                           href="<?= e($link(['start' => $timeParam((int) $slot['start'])])) ?>"
                           <?= $isChosen ? 'aria-current="true"' : '' ?>>
                            <?= e((string) $slot['start_label']) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php if ($duration !== ''): ?>
                <p class="field-hint">Each party runs for <?= e($duration) ?>.</p>
            <?php endif; ?>

        <?php else: ?>
            <p class="book-avail-note is-refused"><?= e((string) $availability['message']) ?></p>

            <?php if ($alternatives !== []): ?>
                <p class="field-hint">These days would work:</p>
                <ul class="book-slots">
                    <?php foreach ($alternatives as $alternative): ?>
                        <li>
                            <a class="book-slot"
                               href="<?= e($link(['date' => (string) $alternative['date'], 'start' => ''])) ?>">
                                <?= e((string) $alternative['label']) ?>
                                <small>from <?= e((string) $alternative['first']) ?></small>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <?php
                /*
                 * The please-call fallback. A refusal with no alternative is
                 * the one case where this page cannot help, and the spec is
                 * explicit that it must then hand the family a phone number
                 * rather than a dead end.
                 */
                ?>
                <?php if (($availability['reason'] ?? '') !== 'too_far'): ?>
                    <?php
                    /*
                     * Not repeated for a date beyond the year this page takes:
                     * that refusal already ends in the phone number, and
                     * "we could not find another day nearby" after it reads as
                     * though the venue searched, when the truth is simply that
                     * it does not book that far out.
                     */
                    ?>
                    <p class="field-hint">
                        We could not find another day nearby for that party.
                        <?= e($callSentence) ?> We will sort something out.
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <!-- --------------------------------------------------- 4. your details -->
    <?php if ($start !== null): ?>
        <section class="book-step" aria-labelledby="step-you">
            <h2 class="book-step-title" id="step-you">4. Your details</h2>

            <?php if ($priced !== null): ?>
                <dl class="definition-list book-quote">
                    <div>
                        <dt><?= e((string) $package['name']) ?></dt>
                        <dd><?= e(money((int) $priced['base_cents'])) ?></dd>
                    </div>
                    <?php if ((int) $priced['extra_guests'] > 0): ?>
                        <div>
                            <dt><?= e((string) (int) $priced['extra_guests']) ?> extra guests</dt>
                            <dd><?= e(money((int) $priced['guests_cents'])) ?></dd>
                        </div>
                    <?php endif; ?>
                    <div>
                        <dt>Tax</dt>
                        <dd><?= e(money((int) $priced['tax_cents'])) ?></dd>
                    </div>
                    <div>
                        <dt><strong>Estimate</strong></dt>
                        <dd><strong><?= e(money((int) $priced['total_cents'])) ?></strong></dd>
                    </div>
                    <?php if ((int) $priced['deposit_cents'] > 0): ?>
                        <div>
                            <dt>Deposit to confirm</dt>
                            <dd><?= e(money((int) $priced['deposit_cents'])) ?></dd>
                        </div>
                    <?php endif; ?>
                </dl>

                <p class="field-hint">
                    An estimate for <?= e($startLabel) ?> on <?= e(local_date($date)) ?>, before
                    anything you add on the day. We will confirm the figure with you; nothing is
                    charged here and we do not ask for card details on this page.
                </p>
            <?php endif; ?>

            <form method="post" action="<?= e(url('/book')) ?>" class="form">
                <?= csrf_field() ?>

                <?php
                /*
                 * The three answers from the steps above, carried as hidden
                 * fields. They come from the controller's own resolved values
                 * rather than from the step-2 form's inputs — the office form
                 * read them from a second form that the browser never
                 * submitted together with this one, so a headcount chosen in
                 * step 2 never reached the booking.
                 */
                ?>
                <input type="hidden" name="package_id" value="<?= e((string) $packageId) ?>">
                <input type="hidden" name="guest_count" value="<?= e((string) $guests) ?>">
                <input type="hidden" name="date" value="<?= e($date) ?>">
                <input type="hidden" name="start_time" value="<?= e($startValue) ?>">

                <div class="field">
                    <label for="name">Your name</label>
                    <input type="text" id="name" name="name" maxlength="120" required
                           autocomplete="name" value="<?= e($oldText('name')) ?>">
                    <?php if ($message = $errorFor('name')): ?>
                        <p class="field-error"><?= e($message) ?></p>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" maxlength="191"
                           autocomplete="email" value="<?= e($oldText('email')) ?>">
                    <?php if ($message = $errorFor('email')): ?>
                        <p class="field-error"><?= e($message) ?></p>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label for="phone">Phone</label>
                    <input type="tel" id="phone" name="phone" maxlength="40"
                           autocomplete="tel" value="<?= e($oldText('phone')) ?>">
                    <p class="field-hint">An email address or a phone number &mdash; either is enough.</p>
                </div>

                <div class="field">
                    <label for="guest_of_honor_name">Who is the party for? (optional)</label>
                    <input type="text" id="guest_of_honor_name" name="guest_of_honor_name"
                           maxlength="120" value="<?= e($oldText('guest_of_honor_name')) ?>">
                </div>

                <div class="field">
                    <label for="guest_of_honor_age">How old are they turning? (optional)</label>
                    <input type="number" id="guest_of_honor_age" name="guest_of_honor_age"
                           min="1" max="120" inputmode="numeric"
                           value="<?= e($oldText('guest_of_honor_age')) ?>">
                </div>

                <div class="field">
                    <label for="allergies">Allergies we should know about (optional)</label>
                    <textarea id="allergies" name="allergies" rows="2"
                              maxlength="500"><?= e($oldText('allergies')) ?></textarea>
                </div>

                <div class="field">
                    <label for="special_requests">Anything else? (optional)</label>
                    <textarea id="special_requests" name="special_requests" rows="3"
                              maxlength="2000"><?= e($oldText('special_requests')) ?></textarea>
                </div>

                <div class="field field-check">
                    <input type="checkbox" id="opt_in" name="opt_in" value="1"
                        <?= $oldText('opt_in') === '1' ? 'checked' : '' ?>>
                    <label for="opt_in"><?= e($optInText) ?></label>
                </div>

                <?php
                /*
                 * The honeypot, exactly as the public web form does it: hidden
                 * from sight AND from assistive technology, and off the tab
                 * order, so no human is ever offered it — which is what makes
                 * anything in it evidence of a bot rather than of a confused
                 * guest. Not `type="hidden"`, because the naive form-fillers
                 * this catches skip those.
                 */
                ?>
                <div class="sr-only" aria-hidden="true">
                    <label for="<?= e($honeypot) ?>">Leave this empty</label>
                    <input type="text" id="<?= e($honeypot) ?>" name="<?= e($honeypot) ?>"
                           value="" tabindex="-1" autocomplete="off">
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-block">Send the request</button>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <p class="bare-foot">
        <?= e($venueName) ?> will use what you send here to answer your request. We will only add you
        to a mailing list if you tick the box asking us to.
        <?php if ($venuePhone !== ''): ?>
            Prefer to talk? Ring <?= e($venuePhone) ?>.
        <?php endif; ?>
    </p>
<?php endif; ?>
