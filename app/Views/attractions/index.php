<?php

declare(strict_types=1);

/**
 * Every attraction, its season and its rules, at a glance.
 *
 * @var list<array<string,mixed>> $attractions
 */

$season = static function (array $a): string {
    if ((int) $a['is_seasonal'] !== 1) {
        return 'All year';
    }

    $fmt = static fn (?string $md): string => $md === null ? '?' : date('M j', (int) strtotime('2000-' . $md));

    return $fmt($a['season_start_md'] ?? null) . ' – ' . $fmt($a['season_end_md'] ?? null);
};

$rules = static function (array $a): string {
    $parts = [];

    if ($a['min_age'] !== null) {
        $parts[] = (int) $a['min_age'] . '+ years';
    }

    if ($a['min_height_in'] !== null) {
        $parts[] = (int) $a['min_height_in'] . '"+';
    }

    if ($a['max_height_in'] !== null) {
        $parts[] = 'under ' . (int) $a['max_height_in'] . '"';
    }

    return $parts === [] ? '—' : implode(' · ', $parts);
};
?>
<div class="page-actions">
    <p class="page-lede">
        What the venue has. The booking page reads these: an attraction out of season is not offered, and a party
        can only be built around one marked as a party's main activity.
    </p>
    <a class="btn" href="<?= e(url('/settings/attractions/new')) ?>"><?= icon('plus', 16) ?> New attraction</a>
</div>

<section class="card">
    <div class="table-wrap">
        <table class="table">
            <caption class="sr-only">Attractions</caption>
            <thead>
                <tr>
                    <th scope="col">Attraction</th>
                    <th scope="col">Season</th>
                    <th scope="col">Who can go</th>
                    <th scope="col" class="td-right">At a time</th>
                    <th scope="col">Parties</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($attractions as $a): ?>
                    <tr class="<?= (int) $a['is_active'] === 1 ? '' : 'row-muted' ?>">
                        <td>
                            <strong><?= e((string) $a['name']) ?></strong>
                            <span class="td-sub">
                                <?= (int) $a['is_indoor'] === 1 ? 'Indoor' : 'Outdoor' ?>
                                <?= (int) $a['requires_waiver'] === 1 ? ' · waiver' : '' ?>
                                <?= (int) $a['is_active'] === 1 ? '' : ' · switched off' ?>
                            </span>
                        </td>
                        <td><?= e($season($a)) ?></td>
                        <td><?= e($rules($a)) ?></td>
                        <td class="td-right"><?= $a['capacity_per_slot'] === null ? '—' : (int) $a['capacity_per_slot'] ?></td>
                        <td>
                            <?php if ((int) $a['is_party_main'] === 1): ?>
                                <span class="badge badge-ok">Main activity</span>
                            <?php elseif ((int) $a['is_party_eligible'] === 1): ?>
                                <span class="badge">Add-on</span>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="row-actions">
                            <a class="btn btn-ghost btn-sm" href="<?= e(url('/settings/attractions/' . (int) $a['id'])) ?>">
                                Edit<span class="sr-only"> <?= e((string) $a['name']) ?></span>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
