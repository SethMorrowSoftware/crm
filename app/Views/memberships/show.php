<?php

declare(strict_types=1);

/**
 * One membership: who is on it, what it cost, what it has been used for, and
 * the three things a manager does to it.
 *
 * @var array<string,mixed>       $membership
 * @var array<string,mixed>       $plan
 * @var array<string,mixed>|null  $holder
 * @var list<array<string,mixed>> $members
 * @var list<array<string,mixed>> $usage
 * @var list<array<string,mixed>> $payments
 * @var array<string,mixed>       $summary
 * @var string                    $standing
 * @var int|null                  $daysLeft
 * @var int|null                  $placesLeft
 * @var string                    $today
 * @var bool                      $canManage
 */

use App\Services\Membership;

$status = (string) $membership['status'];
?>

<section class="card">
    <div class="card-head">
        <div>
            <h2>
                <?= e(trim((string) ($holder['first_name'] ?? '') . ' ' . (string) ($holder['last_name'] ?? ''))) ?: 'Membership' ?>
                <span class="badge <?= e(Membership::badge($status)) ?>"><?= e(Membership::label($status)) ?></span>
            </h2>
            <p class="card-sub">
                <?= e((string) ($plan['name'] ?? 'No plan')) ?>
                &middot; <?= e((string) $membership['member_number']) ?>
                &middot; <?= e($standing) ?>
            </p>
        </div>
        <a class="btn btn-ghost" href="<?= e(url('/memberships')) ?>">All memberships</a>
    </div>

    <div class="stat-row">
        <div class="stat">
            <span class="stat-label">Visits on it</span>
            <strong class="stat-value"><?= (int) $summary['visits'] ?></strong>
            <span class="stat-note">
                <?php if ($summary['allowance'] !== null): ?>
                    <?= (int) $summary['visits_left'] ?> of <?= (int) $summary['allowance'] ?> left
                <?php elseif ($summary['visits_per_month'] !== null): ?>
                    <?= e((string) $summary['visits_per_month']) ?> a month
                <?php else: ?>
                    since it started
                <?php endif; ?>
            </span>
        </div>

        <?php if (can('view_revenue')): ?>
            <div class="stat">
                <span class="stat-label">Paid</span>
                <strong class="stat-value"><?= e(money((int) $membership['price_paid_cents'])) ?></strong>
                <span class="stat-note">
                    <?= $summary['cost_per_visit'] === null
                        ? 'not used yet'
                        : e(money((int) $summary['cost_per_visit'])) . ' a visit so far' ?>
                </span>
            </div>
        <?php endif; ?>

        <div class="stat">
            <span class="stat-label">Ends</span>
            <?php
            /*
             * WITH THE YEAR when it is not this one. A pass ending in October
             * next year showed as "Oct 15" beside today's date in October —
             * which reads as yesterday. MEASURED by selling one and looking
             * at the page.
             */
            $endsAt   = $membership['expires_at'] === null
                ? null
                : (int) strtotime((string) $membership['expires_at']);
            $thisYear = $endsAt !== null && date('Y', $endsAt) === date('Y');
            ?>
            <strong class="stat-value">
                <?= $endsAt === null ? '&mdash;' : e(date($thisYear ? 'M j' : 'M j, Y', $endsAt)) ?>
            </strong>
            <span class="stat-note">
                <?php if ($membership['expires_at'] === null): ?>
                    runs until cancelled
                <?php elseif ($daysLeft !== null && $daysLeft >= 0): ?>
                    <?= $daysLeft ?> <?= $daysLeft === 1 ? 'day' : 'days' ?> left
                <?php else: ?>
                    <?= abs((int) $daysLeft) ?> days ago
                <?php endif; ?>
            </span>
        </div>

        <div class="stat">
            <span class="stat-label">People on it</span>
            <strong class="stat-value"><?= count($members) ?></strong>
            <span class="stat-note">
                <?php if ($placesLeft === null): ?>
                    as many as they like
                <?php elseif ($placesLeft === 0): ?>
                    <?= count($members) === 1 ? 'just the holder' : 'full' ?>
                <?php else: ?>
                    <?= (int) $placesLeft ?> more allowed
                <?php endif; ?>
            </span>
        </div>
    </div>
</section>

<div class="grid-2">
    <section class="card">
        <div class="card-head"><div><h2>Who is covered</h2></div></div>

        <ul class="list-plain">
            <?php foreach ($members as $member): ?>
                <li>
                    <?php if (($member['child_first'] ?? null) !== null): ?>
                        <?= e((string) $member['child_first']) ?>
                        <span class="muted">(child)</span>
                    <?php else: ?>
                        <?= e(trim((string) ($member['first_name'] ?? '') . ' ' . (string) ($member['last_name'] ?? ''))) ?: 'Unnamed' ?>
                    <?php endif; ?>

                    <?php if ((int) $member['is_primary'] === 1): ?>
                        <span class="badge badge-muted">holder</span>
                    <?php elseif (($member['relationship'] ?? null) !== null): ?>
                        <span class="muted"><?= e((string) $member['relationship']) ?></span>
                    <?php endif; ?>

                    <?php if ($canManage && (int) $member['is_primary'] === 0): ?>
                        <form method="post" class="inline-form"
                              action="<?= e(url('/memberships/' . (int) $membership['id'] . '/members/remove')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="member_id" value="<?= (int) $member['id'] ?>">
                            <button type="submit" class="btn-icon" aria-label="Take them off">&times;</button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($canManage && $placesLeft !== 0): ?>
            <form method="post" action="<?= e(url('/memberships/' . (int) $membership['id'] . '/members')) ?>">
                <?= csrf_field() ?>
                <div class="field-row">
                    <div class="field">
                        <label for="contact_id">Add a contact (id)</label>
                        <input type="number" id="contact_id" name="contact_id" min="1">
                    </div>
                    <div class="field">
                        <label for="relationship">Relationship</label>
                        <input type="text" id="relationship" name="relationship" maxlength="40"
                               placeholder="partner, child, grandparent">
                    </div>
                </div>
                <button type="submit" class="btn btn-sm">Add them</button>
            </form>
        <?php elseif ($placesLeft === 0): ?>
            <?php /* A blank limit means one person — see Membership::placesLeft(). Saying
                     "holds 0 people" (which is what the raw column reads) is how that
                     looked on the page before anybody checked. */ ?>
            <p class="muted">
                <?php if ((int) ($plan['max_members'] ?? 0) > 0): ?>
                    This plan holds <?= (int) $plan['max_members'] ?> people and it is full.
                <?php else: ?>
                    This plan covers the holder only. A plan for a family needs a limit set on it.
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="card-head"><div><h2>What a manager can do</h2></div></div>

        <?php if (!$canManage): ?>
            <p class="muted">Your role can see memberships but not change them.</p>
        <?php else: ?>
            <div class="stack">
                <?php if (Membership::canTransition($status, 'frozen')): ?>
                    <form method="post" action="<?= e(url('/memberships/' . (int) $membership['id'] . '/freeze')) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-ghost">Freeze it</button>
                        <span class="muted">
                            It stops working and stops running down. The end date moves on by
                            however long it is frozen.
                        </span>
                    </form>
                <?php endif; ?>

                <?php if ($status === 'frozen'): ?>
                    <form method="post" action="<?= e(url('/memberships/' . (int) $membership['id'] . '/unfreeze')) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn">Unfreeze it</button>
                    </form>
                <?php endif; ?>

                <?php if ($status !== 'cancelled'): ?>
                    <form method="post" action="<?= e(url('/memberships/' . (int) $membership['id'] . '/renew')) ?>">
                        <?= csrf_field() ?>
                        <div class="field">
                            <label for="price">Renew — price paid</label>
                            <input type="text" id="price" name="price" inputmode="decimal"
                                   placeholder="<?= e(money((int) ($plan['price_cents'] ?? 0), false)) ?>">
                            <p class="field-hint">
                                A new term starting the day the current one ends, so renewing early
                                costs them nothing.
                            </p>
                        </div>
                        <button type="submit" class="btn">Renew it</button>
                    </form>
                <?php endif; ?>

                <?php if (Membership::canTransition($status, 'cancelled')): ?>
                    <form method="post" action="<?= e(url('/memberships/' . (int) $membership['id'] . '/cancel')) ?>"
                          data-confirm="Cancel this membership?">
                        <?= csrf_field() ?>
                        <div class="field">
                            <label for="reason">Cancel — why?</label>
                            <input type="text" id="reason" name="reason" maxlength="255"
                                   placeholder="moved away, too far, not using it">
                            <p class="field-hint">
                                Kept with the cancellation, so the churn report has reasons in it
                                rather than a count.
                            </p>
                        </div>
                        <button type="submit" class="btn btn-danger btn-sm">Cancel it</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<div class="grid-2">
    <section class="card">
        <div class="card-head">
            <div>
                <h2>When it was used</h2>
                <p class="card-sub">
                    One per day, however many people came — a family checked in one child at a time
                    is one visit.
                </p>
            </div>
        </div>

        <?php if ($usage === []): ?>
            <p class="empty">It has not been used yet.</p>
        <?php else: ?>
            <ul class="list-plain">
                <?php foreach (array_slice($usage, 0, 20) as $use): ?>
                    <li><?= e(local_date((string) $use['used_at'])) ?></li>
                <?php endforeach; ?>
            </ul>
            <?php if (count($usage) > 20): ?>
                <p class="muted">and <?= count($usage) - 20 ?> more.</p>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <?php if (can('view_revenue')): ?>
        <section class="card">
            <div class="card-head"><div><h2>What has been paid</h2></div></div>

            <?php if ($payments === []): ?>
                <p class="empty">Nothing recorded.</p>
            <?php else: ?>
                <table class="table">
                    <caption class="sr-only">Payments</caption>
                    <thead><tr><th scope="col">When</th><th scope="col">Amount</th><th scope="col">State</th></tr></thead>
                    <tbody>
                        <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td><?= e(local_date((string) $payment['occurred_at'])) ?></td>
                                <td><?= e(money((int) $payment['amount_cents'])) ?></td>
                                <td><?= e((string) $payment['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>
