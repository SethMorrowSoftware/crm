<?php

declare(strict_types=1);

/**
 * The report picker.
 *
 * Reports are listed by FAMILY with the QUESTION each one answers, not by
 * name alone. "Lead time" means nothing to somebody who has not used the
 * system; "How far ahead do people book?" means something to everybody, and
 * it is the sentence a manager is actually holding in their head when they
 * come here.
 *
 * @var array<string,string>                  $families
 * @var array<string,list<array<string,mixed>>> $byFamily
 * @var list<array<string,mixed>>             $saved
 * @var bool                                  $canExport
 * @var array<string,mixed>                   $pulse
 * @var string|null                           $dayNote
 */

$familyIcons = [
    'revenue'    => 'card',
    'sales'      => 'target',
    'marketing'  => 'megaphone',
    'guest'      => 'users',
    'operations' => 'calendar',
    'reputation' => 'star',
];
?>

<?php if ($pulse['ready']): ?>
    <section class="card">
        <div class="card-head">
            <div>
                <h2>The last ninety days</h2>
                <p class="card-sub">
                    <?= e(date('M j', (int) strtotime((string) $pulse['from'] . ' 00:00:00 UTC'))) ?>
                    to
                    <?= e(date('M j, Y', (int) strtotime((string) $pulse['to'] . ' 00:00:00 UTC'))) ?>,
                    from the nightly totals. Today is not in it &mdash; it is not over,
                    so it is not an actual.
                </p>
            </div>
        </div>

        <div class="pulse-row">
            <?php foreach ($pulse['metrics'] as $metric): ?>
                <?php
                $series = $metric['series'];
                $peak   = (float) $metric['peak'];
                ?>
                <div class="pulse">
                    <span class="pulse-label"><?= e((string) $metric['label']) ?></span>
                    <strong class="pulse-total">
                        <?= $metric['money']
                            ? e(money((int) $metric['total']))
                            : e(number_format((float) $metric['total'])) ?>
                    </strong>

                    <?php /* A sparkline of divs, like the NPS chart: no library, no
                             canvas, no third-party request. */ ?>
                    <div class="pulse-spark" role="img"
                         aria-label="<?= e((string) $metric['label']) ?> over
                             <?= count($series) ?> days, highest
                             <?= $metric['money'] ? e(money((int) $peak)) : e(number_format($peak)) ?>">
                        <?php foreach ($series as $value): ?>
                            <span class="pulse-bar"
                                  style="height:<?= $peak > 0 ? max(2, (int) round(((float) $value / $peak) * 100)) : 2 ?>%"></span>
                        <?php endforeach; ?>
                    </div>

                    <span class="pulse-note">
                        <?= count($series) ?> days covered
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php if ($saved !== []): ?>
    <section class="card">
        <div class="card-head">
            <div>
                <h2>Saved</h2>
                <p class="card-sub">
                    A saved report keeps the period you chose by NAME, so
                    &ldquo;Last 30 days&rdquo; still means the last 30 days
                    next month.
                </p>
            </div>
        </div>

        <ul class="saved-reports">
            <?php foreach ($saved as $row): ?>
                <li>
                    <?php if ($row['report_name'] === null): ?>
                        <?php /* The report it points at is gone. Say so — a saved
                                 report that silently vanishes is how a venue finds
                                 out three weeks later. */ ?>
                        <span class="saved-report-dead">
                            <strong><?= e((string) $row['name']) ?></strong>
                            <span class="badge badge-warn">report no longer exists</span>
                        </span>
                    <?php else: ?>
                        <a href="<?= e(url('/reports/saved/' . (int) $row['id'])) ?>">
                            <strong><?= e((string) $row['name']) ?></strong>
                        </a>
                        <span class="muted">
                            <?= e((string) $row['report_name']) ?> &middot;
                            <?= e((string) $row['window']) ?>
                        </span>
                    <?php endif; ?>

                    <form method="post" class="inline-form"
                          action="<?= e(url('/reports/saved/' . (int) $row['id'] . '/delete')) ?>"
                          data-confirm="Delete this saved report?">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn-icon" aria-label="Delete <?= e((string) $row['name']) ?>">
                            <?= icon('trash', 14) ?>
                        </button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<section class="card">
    <div class="card-head">
        <div>
            <h2>Reports</h2>
            <p class="card-sub">
                Every figure here is something that happened &mdash; there are no
                projections, no run rates and no &ldquo;typical day&rdquo;
                baselines anywhere in this application.
                <?php if (!can('view_revenue')): ?>
                    Your role does not include revenue, so money columns will be
                    empty. Every count beside them is complete.
                <?php endif; ?>
            </p>
        </div>
    </div>

    <?php if ($dayNote !== null): ?>
        <?php /*
         * Said on the page rather than left as a surprise. Every report here
         * cuts its days in the venue's timezone, and on a server with no
         * MySQL timezone tables that conversion is a fixed offset rather than
         * the one in force on each date. An approximation nobody is told
         * about is indistinguishable from a bug.
         */ ?>
        <div class="alert alert-info">
            <?= icon('info') ?>
            <div><p><?= e($dayNote) ?></p></div>
        </div>
    <?php endif; ?>
</section>

<?php foreach ($families as $key => $label): ?>
    <?php $reports = $byFamily[$key] ?? []; ?>
    <?php if ($reports === []) { continue; } ?>

    <section class="card">
        <div class="card-head">
            <div>
                <h2><?= icon($familyIcons[$key] ?? 'chart') ?> <?= e($label) ?></h2>
            </div>
        </div>

        <ul class="report-list">
            <?php foreach ($reports as $report): ?>
                <li>
                    <a class="report-link" href="<?= e(url('/reports/' . (string) $report['id'])) ?>">
                        <span class="report-name"><?= e((string) $report['name']) ?></span>
                        <span class="report-question"><?= e((string) $report['question']) ?></span>
                    </a>

                    <?php if ($canExport): ?>
                        <?php /*
                         * The period is NAMED on the button. There is no period
                         * picker on this page, so an unlabelled "CSV" here hands
                         * somebody the last thirty days without saying so — and a
                         * file whose window is a surprise is worse than no
                         * shortcut. Open the report for any other period.
                         */ ?>
                        <a class="btn btn-ghost btn-sm"
                           href="<?= e(url('/reports/' . (string) $report['id'] . '/export?range=last_30')) ?>">
                            <?= icon('download', 14) ?> CSV &middot; 30 days
                        </a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endforeach; ?>
