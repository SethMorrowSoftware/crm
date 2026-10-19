<?php

declare(strict_types=1);

/**
 * The Text Club: the list, how it is growing, and the year of offers.
 *
 * @var list<array<string,mixed>>        $rows
 * @var array<string,mixed>              $stats
 * @var string                           $filter
 * @var array<int, array<string,mixed>>  $calendar
 * @var int                              $month
 * @var array<string,mixed>|null         $thisOffer
 * @var array{name:string, phone:string} $venue
 * @var bool                             $publicOn
 * @var string                           $confirmText
 * @var string                           $welcomeText
 * @var bool                             $canManage
 * @var bool                             $canSend
 * @var list<string>                     $statuses
 */

use App\Controllers\TextClubController;
use App\Services\SmsPolicy;
use App\Services\TextClub;

$monthNames = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
];

$statusBadge = static fn (string $status): string => match ($status) {
    TextClub::SUBSCRIBED   => 'badge-ok',
    TextClub::PENDING      => 'badge-warn',
    TextClub::UNSUBSCRIBED => 'badge-muted',
    default                => 'badge',
};

/** A rate that may legitimately be unknown. */
$rate = static fn (?float $value): string => $value === null ? '—' : number_format($value, 1) . '%';

?>
<p class="page-lede">
    The opt-in text list. Everybody on it asked twice &mdash; once to join, and again to
    confirm &mdash; which is what makes it a list the venue may legally text.
</p>

<div class="stat-row">
    <div class="stat">
        <span class="stat-label">Subscribed</span>
        <span class="stat-value"><?= e(number_format((int) $stats['subscribed'])) ?></span>
        <span class="stat-note">Confirmed, and may be sent offers.</span>
    </div>
    <div class="stat">
        <span class="stat-label">Waiting to confirm</span>
        <span class="stat-value"><?= e(number_format((int) $stats['pending'])) ?></span>
        <span class="stat-note">
            Invited, not yet replied. They get nothing until they do.
        </span>
    </div>
    <div class="stat">
        <span class="stat-label">Confirm rate</span>
        <span class="stat-value"><?= e($rate($stats['confirm_rate'])) ?></span>
        <span class="stat-note">
            <?php
            /*
             * The figure a bare subscriber count hides. Invites that go
             * unanswered are the normal failure of a double opt-in, and a
             * rate that has fallen is a wording or a timing problem rather
             * than a footfall one.
             */
            ?>
            Of everybody ever invited. Unanswered invites are normal; a falling rate is
            usually the wording.
        </span>
    </div>
    <div class="stat">
        <span class="stat-label">Offers redeemed</span>
        <span class="stat-value"><?= e($rate($stats['redemption_rate'])) ?></span>
        <span class="stat-note">
            <?= e(number_format((int) $stats['redeemed'])) ?> redeemed.
            <?php if ($stats['redemption_rate'] === null): ?>
                Nobody has been sent an offer yet.
            <?php endif; ?>
        </span>
    </div>
</div>

<div class="grid-2">
    <div class="stack">
        <div class="card">
            <h2>This month &mdash; <?= e($monthNames[$month] ?? 'unknown') ?></h2>

            <?php if ($thisOffer === null): ?>
                <p class="empty">
                    No offer is written for <?= e($monthNames[$month] ?? 'this month') ?>.
                    Nothing will be sent, which is a perfectly good thing for a month to do.
                </p>
            <?php else: ?>
                <?php $composed = TextClub::offerMessage($venue, $thisOffer); ?>
                <dl class="definition-list">
                    <div>
                        <dt>Offer</dt>
                        <dd><?= e((string) $thisOffer['name']) ?></dd>
                    </div>
                    <div>
                        <dt>Code</dt>
                        <dd><code><?= e((string) $thisOffer['code']) ?></code></dd>
                    </div>
                </dl>

                <h3>As it will arrive</h3>
                <p class="club-preview"><?= e($composed) ?></p>
                <p class="field-hint">
                    <?= e(SmsPolicy::describe($composed)) ?>
                    <?php if (SmsPolicy::segments($composed) > 1): ?>
                        <strong>That is more than one text per subscriber.</strong>
                    <?php endif; ?>
                </p>

                <?php if ($canSend && (int) $stats['subscribed'] > 0): ?>
                    <form method="post" action="<?= e(url('/text-club/send')) ?>" class="form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="month" value="<?= e((string) $month) ?>">
                        <button type="submit" class="btn">
                            Send to <?= e(number_format((int) $stats['subscribed'])) ?> subscriber<?= (int) $stats['subscribed'] === 1 ? '' : 's' ?>
                        </button>
                        <p class="field-hint">
                            Sent a batch a minute, and never twice to the same person inside
                            <?= e((string) TextClub::MIN_DAYS_BETWEEN_OFFERS) ?> days.
                            Quiet hours still apply &mdash; an evening send waits for the morning
                            rather than waking anybody.
                        </p>
                    </form>
                <?php elseif ($canSend): ?>
                    <?php
                    /*
                     * No button while there is nobody to send to. It read
                     * "Send to 0 subscribers" and could only fail, which is
                     * a worse answer than saying what to do about it.
                     */
                    ?>
                    <p class="empty">
                        Nobody has confirmed yet, so there is nobody to send to. Guests join by
                        texting <strong>JOIN</strong> to the venue's number
                        <?php if ($publicOn): ?>
                            or through the <a href="<?= e(url('/text-club/join')) ?>">sign-up page</a>,
                        <?php endif; ?>
                        and are on the list once they reply <strong>YES</strong>.
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="page-actions">
                <h2>The list</h2>
                <div class="tab-bar">
                    <a class="tab<?= $filter === '' ? ' is-active' : '' ?>"
                       href="<?= e(url('/text-club')) ?>">All</a>
                    <?php foreach ($statuses as $status): ?>
                        <a class="tab<?= $filter === $status ? ' is-active' : '' ?>"
                           href="<?= e(url('/text-club?status=' . $status)) ?>">
                            <?= e(ucfirst($status)) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($rows === []): ?>
                <p class="empty">
                    <?php if ($filter === ''): ?>
                        Nobody has joined yet. Guests join by texting JOIN to the venue's number,
                        <?php if ($publicOn): ?>
                            or through the <a href="<?= e(url('/text-club/join')) ?>">sign-up page</a>.
                        <?php else: ?>
                            and the sign-up page is switched off below.
                        <?php endif; ?>
                    <?php else: ?>
                        Nobody is <?= e($filter) ?>.
                    <?php endif; ?>
                </p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <caption class="sr-only"><?= e((string) count($rows)) ?> Text Club members</caption>
                        <thead>
                            <tr>
                                <th scope="col">Number</th>
                                <th scope="col">Guest</th>
                                <th scope="col">Joined via</th>
                                <th scope="col">Last offer</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $name = trim(
                                    (string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')
                                );
                                ?>
                                <tr>
                                    <th scope="row">
                                        <?= e((string) $row['phone_normalized']) ?>
                                        <span class="badge <?= e($statusBadge((string) $row['status'])) ?>">
                                            <?= e(ucfirst((string) $row['status'])) ?>
                                        </span>
                                    </th>
                                    <td>
                                        <?php if ($row['contact_id'] !== null && $name !== ''): ?>
                                            <a href="<?= e(url('/contacts/' . (int) $row['contact_id'])) ?>">
                                                <?= e($name) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="muted">Not matched to a guest</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e((string) ($row['joined_via'] ?? '—')) ?></td>
                                    <td>
                                        <?php if ($row['last_offer_sent_at'] === null): ?>
                                            <span class="muted">Never</span>
                                        <?php else: ?>
                                            <?= e(local_date((string) $row["last_offer_sent_at"])) ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (count($rows) >= 200): ?>
                    <p class="field-hint">The 200 most recent. The figures above cover everybody.</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <aside class="stack">
        <?php if ($canManage): ?>
            <form method="post" action="<?= e(url('/text-club/settings')) ?>" class="form">
                <?= csrf_field() ?>

                <div class="card">
                    <h2>The sign-up page</h2>
                    <div class="field field-check">
                        <label>
                            <input type="checkbox" name="public_enabled" value="1"
                                <?= $publicOn ? 'checked' : '' ?>>
                            Let guests join from the website
                        </label>
                        <p class="field-hint">
                            Off by default. While it is off the page answers with a plain
                            404 rather than a notice, so a venue that has not set up a
                            sending number is not advertising a form that cannot work.
                            <?php if ($publicOn): ?>
                                It is at <a href="<?= e(url('/text-club/join')) ?>"><?= e(url('/text-club/join')) ?></a>.
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <div class="card">
                    <h2>The wording</h2>

                    <div class="field">
                        <label for="confirm_message">The invite</label>
                        <textarea id="confirm_message" name="confirm_message" rows="4"
                                  maxlength="500" placeholder="<?= e(TextClub::confirmationMessage($venue)) ?>"><?= e($confirmText) ?></textarea>
                        <p class="field-hint">
                            Left empty, the default above is used. Whatever is here must
                            say <strong>YES</strong>, that <strong>message and data rates
                            may apply</strong>, and <strong>how to stop</strong> &mdash;
                            this is the wording a guest's consent rests on, and it is
                            saved alongside their confirmation as the evidence of it.
                        </p>
                    </div>

                    <div class="field">
                        <label for="welcome_message">The welcome</label>
                        <textarea id="welcome_message" name="welcome_message" rows="3"
                                  maxlength="500" placeholder="<?= e(TextClub::welcomeMessage($venue, $thisOffer)) ?>"><?= e($welcomeText) ?></textarea>
                        <p class="field-hint">
                            Sent the moment they confirm, while they are still holding the
                            phone. Must say how to stop.
                        </p>
                    </div>
                </div>

                <div class="card">
                    <h2>The year of offers</h2>
                    <p class="field-hint">
                        One a month. An arcade's quiet months are not its busy ones, and the
                        offer that fills a February Tuesday is not the one to give away in
                        July. Leave a month blank to send nothing that month.
                    </p>

                    <?php foreach ($monthNames as $number => $name): ?>
                        <?php $offer = $calendar[$number] ?? ['name' => '', 'body' => '', 'code' => '', 'type' => 'percent', 'value' => 0]; ?>
                        <details class="club-month"<?= $number === $month ? ' open' : '' ?>>
                            <summary>
                                <?= e($name) ?>
                                <?php if (trim((string) $offer['body']) === ''): ?>
                                    <span class="muted">— nothing</span>
                                <?php else: ?>
                                    <span class="muted">— <?= e((string) $offer['name']) ?></span>
                                <?php endif; ?>
                            </summary>

                            <div class="field">
                                <label for="offer-name-<?= e((string) $number) ?>">What it is called</label>
                                <input type="text" id="offer-name-<?= e((string) $number) ?>"
                                       name="offers[<?= e((string) $number) ?>][name]"
                                       maxlength="120" value="<?= e((string) $offer['name']) ?>">
                            </div>

                            <div class="field">
                                <label for="offer-body-<?= e((string) $number) ?>">The text</label>
                                <textarea id="offer-body-<?= e((string) $number) ?>"
                                          name="offers[<?= e((string) $number) ?>][body]"
                                          rows="3" maxlength="400"><?= e((string) $offer['body']) ?></textarea>
                                <p class="field-hint">
                                    The opt-out line is added for you if you do not write one.
                                    <?php if (trim((string) $offer['body']) !== ''): ?>
                                        <?= e(SmsPolicy::describe(TextClub::offerMessage($venue, $offer))) ?>
                                    <?php endif; ?>
                                </p>
                            </div>

                            <div class="field">
                                <label for="offer-code-<?= e((string) $number) ?>">Code for the till</label>
                                <input type="text" id="offer-code-<?= e((string) $number) ?>"
                                       name="offers[<?= e((string) $number) ?>][code]"
                                       maxlength="40" value="<?= e((string) $offer['code']) ?>">
                            </div>

                            <div class="field">
                                <label for="offer-type-<?= e((string) $number) ?>">Kind</label>
                                <select id="offer-type-<?= e((string) $number) ?>"
                                        name="offers[<?= e((string) $number) ?>][type]">
                                    <?php foreach ([
                                        'percent'   => 'Percentage off',
                                        'fixed'     => 'Amount off',
                                        'bogo'      => 'Buy one get one',
                                        'free_item' => 'Something free',
                                    ] as $value => $label): ?>
                                        <option value="<?= e($value) ?>"
                                            <?= (string) $offer['type'] === $value ? 'selected' : '' ?>>
                                            <?= e($label) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="field">
                                <label for="offer-value-<?= e((string) $number) ?>">How much</label>
                                <input type="number" id="offer-value-<?= e((string) $number) ?>"
                                       name="offers[<?= e((string) $number) ?>][value]"
                                       min="0" step="0.01" value="<?= e((string) $offer['value']) ?>">
                                <p class="field-hint">
                                    Ignored for buy-one-get-one and something-free, which carry no number.
                                </p>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn">Save Text Club settings</button>
                </div>
            </form>
        <?php else: ?>
            <div class="card">
                <h2>The year of offers</h2>
                <ul class="club-calendar">
                    <?php foreach ($monthNames as $number => $name): ?>
                        <?php $offer = $calendar[$number] ?? null; ?>
                        <li>
                            <strong><?= e($name) ?></strong>
                            <?= $offer === null || trim((string) $offer['body']) === ''
                                ? '<span class="muted">nothing</span>'
                                : e((string) $offer['name']) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="field-hint">
                    Changing these needs the campaigns permission.
                </p>
            </div>
        <?php endif; ?>
    </aside>
</div>
