<?php

declare(strict_types=1);

/**
 * What the venue sells, and what each one includes.
 *
 * Editing these is `plans_manage` rather than `memberships_manage`: a front
 * desk that can sell a pass must not be able to decide what a pass costs.
 *
 * @var list<array<string,mixed>> $plans
 * @var list<string>              $types
 * @var bool                      $canPlans
 */

$typeLabel = static fn (string $type): string => match ($type) {
    'season_pass' => 'Season pass',
    'membership'  => 'Membership',
    'multi_visit' => 'Multi-visit card',
    default       => ucfirst(str_replace('_', ' ', $type)),
};
?>

<section class="card">
    <div class="card-head">
        <div>
            <h2>Membership plans</h2>
            <p class="card-sub">
                A plan with no length runs until somebody cancels it; one with a visit allowance
                ends when the visits are used.
            </p>
        </div>
        <a class="btn btn-ghost" href="<?= e(url('/memberships')) ?>">Memberships</a>
    </div>

    <?php if ($plans === []): ?>
        <p class="empty">No plans yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <caption class="sr-only">Membership plans</caption>
                <thead>
                    <tr>
                        <th scope="col">Plan</th>
                        <th scope="col">Kind</th>
                        <th scope="col">Price</th>
                        <th scope="col">Runs for</th>
                        <th scope="col">Visits</th>
                        <th scope="col">People</th>
                        <th scope="col">Sold</th>
                        <th scope="col">State</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($plans as $plan): ?>
                        <tr>
                            <td><strong><?= e((string) $plan['name']) ?></strong></td>
                            <td><?= e($typeLabel((string) $plan['type'])) ?></td>
                            <td><?= e(money((int) $plan['price_cents'])) ?></td>
                            <td>
                                <?= ($plan['duration_days'] ?? null) === null
                                    ? '<span class="muted">until cancelled</span>'
                                    : (int) $plan['duration_days'] . ' days' ?>
                            </td>
                            <td>
                                <?= ($plan['visit_allowance'] ?? null) === null
                                    ? '<span class="muted">unlimited</span>'
                                    : (int) $plan['visit_allowance'] ?>
                            </td>
                            <td>
                                <?= ($plan['max_members'] ?? null) === null
                                    ? '1'
                                    : 'up to ' . (int) $plan['max_members'] ?>
                            </td>
                            <td><?= (int) $plan['sold'] ?> <span class="td-sub"><?= (int) $plan['live'] ?> live</span></td>
                            <td>
                                <span class="badge <?= (int) $plan['is_active'] === 1 ? 'badge-ok' : 'badge-muted' ?>">
                                    <?= (int) $plan['is_active'] === 1 ? 'On sale' : 'Off sale' ?>
                                </span>
                            </td>
                        </tr>

                        <?php if ($canPlans): ?>
                            <tr>
                                <td colspan="8">
                                    <details>
                                        <summary>Edit <?= e((string) $plan['name']) ?></summary>
                                        <?php
                                        $p = $plan;
                                        $action = url('/memberships/plans/' . (int) $plan['id']);
                                        include __DIR__ . '/_plan_form.php';
                                        ?>
                                    </details>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php if ($canPlans): ?>
    <section class="card">
        <div class="card-head"><div><h2>Add a plan</h2></div></div>
        <?php
        $p = [];
        $action = url('/memberships/plans');
        include __DIR__ . '/_plan_form.php';
        ?>
    </section>
<?php endif; ?>
