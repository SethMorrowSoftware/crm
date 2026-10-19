<?php

declare(strict_types=1);

/**
 * Every pass the venue has sold, and the four questions a manager asks of
 * them: who is live, who is up for renewal, who is not using it, and who has
 * gone.
 *
 * @var list<array<string,mixed>> $rows
 * @var int                       $total
 * @var array{page:int,perPage:int,offset:int} $page
 * @var string                    $view
 * @var array<string,string>      $views
 * @var array<string,int>         $counts
 * @var string                    $today
 * @var list<array<string,mixed>> $plans
 * @var bool                      $canManage
 * @var bool                      $canPlans
 */

use App\Services\Membership;

$query = static fn (array $extra = []): string => url('/memberships?' . http_build_query(array_merge([
    'show' => $view,
    'q'    => request()->str('q'),
    'plan' => request()->int('plan') ?: '',
], $extra)));
?>

<section class="card">
    <div class="card-head">
        <div>
            <h2>Memberships</h2>
            <p class="card-sub">
                <?= (int) $counts['live'] ?> live,
                <?= (int) $counts['renewals'] ?> up for renewal,
                <?= (int) $counts['idle'] ?> not being used.
            </p>
        </div>
        <div class="row-actions">
            <?php if ($canPlans): ?>
                <a class="btn btn-ghost" href="<?= e(url('/memberships/plans')) ?>">Plans</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="chip-bar">
        <?php foreach ($views as $key => $label): ?>
            <a class="chip <?= $view === $key ? 'is-on' : '' ?>" href="<?= e($query(['show' => $key])) ?>">
                <?= e($label) ?>
                <span class="chip-count"><?= (int) ($counts[$key] ?? 0) ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <form method="get" action="<?= e(url('/memberships')) ?>" class="filter-bar">
        <input type="hidden" name="show" value="<?= e($view) ?>">

        <div class="field">
            <label for="q">Search</label>
            <input type="search" id="q" name="q" value="<?= e(request()->str('q')) ?>"
                   placeholder="Name, email or member number">
        </div>

        <div class="field">
            <label for="plan">Plan</label>
            <select id="plan" name="plan">
                <option value="">All</option>
                <?php foreach ($plans as $plan): ?>
                    <option value="<?= (int) $plan['id'] ?>"
                        <?= request()->int('plan') === (int) $plan['id'] ? 'selected' : '' ?>>
                        <?= e((string) $plan['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field field-actions">
            <button type="submit" class="btn btn-sm">Filter</button>
            <?php if (request()->str('q') !== '' || request()->int('plan') > 0): ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('/memberships?show=' . $view)) ?>">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($rows === []): ?>
        <p class="empty">
            <?php if ($view === 'live' && (int) $counts['all'] === 0): ?>
                Nobody holds a pass yet. Sell one below.
            <?php else: ?>
                Nothing matches.
            <?php endif; ?>
        </p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <caption class="sr-only">Memberships</caption>
                <thead>
                    <tr>
                        <th scope="col">Member</th>
                        <th scope="col">Plan</th>
                        <th scope="col">State</th>
                        <th scope="col">Ends</th>
                        <th scope="col">Visits</th>
                        <th scope="col">Last used</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $status = (string) $row['status'];
                        $left   = Membership::daysLeft($row, $today);
                        ?>
                        <tr>
                            <td>
                                <a href="<?= e(url('/memberships/' . (int) $row['id'])) ?>">
                                    <?= e(trim((string) $row['first_name'] . ' ' . (string) $row['last_name'])) ?: 'Unnamed' ?>
                                </a>
                                <span class="td-sub"><?= e((string) $row['member_number']) ?></span>
                            </td>
                            <td>
                                <?= e((string) $row['plan_name']) ?>
                                <?php if ((int) $row['member_count'] > 1): ?>
                                    <span class="td-sub"><?= (int) $row['member_count'] ?> people</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= e(Membership::badge($status)) ?>">
                                    <?= e(Membership::label($status)) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($row['expires_at'] === null): ?>
                                    <span class="muted">no end date</span>
                                <?php else: ?>
                                    <?= e(date('M j, Y', (int) strtotime((string) $row['expires_at']))) ?>
                                    <?php if ($left !== null && $left >= 0 && $left <= 30): ?>
                                        <span class="td-sub"><?= $left === 0 ? 'today' : $left . ' days' ?></span>
                                    <?php elseif ($left !== null && $left < 0): ?>
                                        <span class="td-sub"><?= abs($left) ?> days ago</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= (int) $row['visits_used'] ?><?php
                                ?><?= ($row['visit_allowance'] ?? null) === null
                                    ? ''
                                    : ' of ' . (int) $row['visit_allowance'] ?>
                            </td>
                            <td>
                                <?= $row['last_used_at'] === null
                                    ? '<span class="muted">never</span>'
                                    : e(local_date((string) $row['last_used_at'])) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total > $page['perPage']): ?>
            <nav class="pagination" aria-label="Pages">
                <?php if ($page['page'] > 1): ?>
                    <a class="btn btn-ghost btn-sm" href="<?= e($query(['page' => $page['page'] - 1])) ?>">Previous</a>
                <?php endif; ?>
                <span class="pagination-info">
                    Page <?= (int) $page['page'] ?> of <?= (int) ceil($total / $page['perPage']) ?>
                </span>
                <?php if ($page['offset'] + $page['perPage'] < $total): ?>
                    <a class="btn btn-ghost btn-sm" href="<?= e($query(['page' => $page['page'] + 1])) ?>">Next</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php if ($canManage): ?>
    <section class="card">
        <div class="card-head">
            <div>
                <h2>Sell a pass</h2>
                <p class="card-sub">
                    It works at the counter straight away unless you give it a start date in the
                    future.
                </p>
            </div>
        </div>

        <?php if ($plans === []): ?>
            <p class="empty">
                There are no active plans to sell.
                <?php if ($canPlans): ?>
                    <a href="<?= e(url('/memberships/plans')) ?>">Create one first.</a>
                <?php endif; ?>
            </p>
        <?php else: ?>
            <form method="post" action="<?= e(url('/memberships')) ?>">
                <?= csrf_field() ?>

                <div class="field-row">
                    <div class="field">
                        <label for="contact_id">Guest</label>
                        <input type="number" id="contact_id" name="contact_id" min="1" required
                               value="<?= e(old('contact_id')) ?>">
                        <p class="field-hint">
                            The contact's id, from their page. (A picker lands with the contacts
                            rewrite; this is deliberately the plain thing that works.)
                        </p>
                    </div>

                    <div class="field">
                        <label for="plan_id">Plan</label>
                        <select id="plan_id" name="plan_id" required>
                            <?php foreach ($plans as $plan): ?>
                                <option value="<?= (int) $plan['id'] ?>">
                                    <?= e((string) $plan['name']) ?>
                                    — <?= e(money((int) $plan['price_cents'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label for="started_at">Starts</label>
                        <input type="date" id="started_at" name="started_at" value="<?= e($today) ?>">
                    </div>

                    <div class="field">
                        <label for="price">Price paid</label>
                        <input type="text" id="price" name="price" inputmode="decimal"
                               placeholder="the plan's price">
                        <p class="field-hint">Leave it blank to charge what the plan says.</p>
                    </div>
                </div>

                <div class="field field-check">
                    <label>
                        <input type="checkbox" name="auto_renew" value="1"> Renews automatically
                    </label>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn">Sell it</button>
                </div>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>
