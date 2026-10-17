<?php

declare(strict_types=1);

/**
 * One report.
 *
 * The table is generic: every report returns `columns` (each with a type) and
 * `rows`, and this file knows how to draw a date, a count, money and a
 * percentage. That is deliberate — twenty-three bespoke templates is
 * twenty-three places for a money column to be formatted as a count, and the
 * reports that need a chart get one from the same column metadata.
 *
 * @var array<string,mixed>      $report
 * @var array<string,mixed>      $window
 * @var array<string,string>     $presets
 * @var array<string,mixed>|null $result
 * @var string|null              $failure
 * @var bool                     $canExport
 * @var bool                     $scrubbed
 * @var list<array<string,mixed>> $saved
 */

$query = ['range' => (string) $window['preset']];

if ($window['preset'] === 'custom') {
    $query['from'] = (string) $window['from'];
    $query['to']   = (string) $window['to'];
}

$queryString = http_build_query($query);

/**
 * One cell, by its column's declared type.
 *
 * NULL is rendered as an em dash, never as 0 or 0%. A report that printed
 * "0%" for a rate it cannot compute is making a claim; a dash is the absence
 * of one, which is what a reader needs in order to go and look.
 */
$cell = static function (mixed $value, string $type): string {
    if ($value === null) {
        return '<span class="muted">&mdash;</span>';
    }

    return match ($type) {
        'money'   => '<span class="num">' . e(money((int) $value)) . '</span>',
        'int'     => '<span class="num">' . e(number_format((int) $value)) . '</span>',
        'percent' => '<span class="num">' . e(rtrim(rtrim(number_format((float) $value, 1), '0'), '.')) . '%</span>',
        'date'    => e(date('D M j', (int) strtotime((string) $value . ' 00:00:00 UTC'))),
        default   => e((string) $value),
    };
};

// A chart is drawn only when the first column is a date and there is exactly
// one obvious measure to plot. Guessing which of six numeric columns a reader
// wants is how a chart ends up showing "still owed" as if it were takings.
$chartKey   = null;
$chartLabel = '';
$chartType  = 'int';

if ($result !== null && ($result['columns'][0]['type'] ?? '') === 'date') {
    foreach ($result['columns'] as $column) {
        if (($column['type'] ?? '') === 'money' && !$scrubbed) {
            $chartKey   = (string) $column['key'];
            $chartLabel = (string) $column['label'];
            $chartType  = 'money';
            break;
        }
    }

    if ($chartKey === null) {
        foreach ($result['columns'] as $column) {
            if (($column['type'] ?? '') === 'int') {
                $chartKey   = (string) $column['key'];
                $chartLabel = (string) $column['label'];
                $chartType  = 'int';
                break;
            }
        }
    }
}

$chartMax = 0;

if ($chartKey !== null) {
    foreach ($result['rows'] as $row) {
        $chartMax = max($chartMax, (int) ($row[$chartKey] ?? 0));
    }
}

/*
 * How often to print a date under the bars.
 *
 * MEASURED on a phone: ninety bars with a label each renders as
 * "18 Sep19 Sep20 Sep25 Sep" — the labels run into one another and the last
 * is cut off, which is worse than no labels at all. CSS cannot count bars,
 * so the thinning is done here: every bar is still drawn and every bar still
 * carries its full date in the chart's accessible description, only the
 * printed labels are thinned.
 */
$labelEvery = 1;

if ($chartKey !== null) {
    $barCount   = count($result['rows']);
    $labelEvery = $barCount > 60 ? 10 : ($barCount > 30 ? 5 : ($barCount > 14 ? 3 : 1));
}
?>

<section class="card">
    <div class="card-head">
        <div>
            <h2><?= e((string) $report['name']) ?></h2>
            <p class="card-sub"><?= e((string) $report['question']) ?></p>
        </div>
        <div class="card-actions">
            <?php if ($canExport && $result !== null): ?>
                <a class="btn btn-ghost btn-sm"
                   href="<?= e(url('/reports/' . (string) $report['id'] . '/export?' . $queryString)) ?>">
                    <?= icon('download', 14) ?> Download CSV
                </a>
            <?php endif; ?>
            <a class="btn btn-ghost btn-sm" href="<?= e(url('/reports')) ?>">All reports</a>
        </div>
    </div>

    <?php /*
     * The period picker is a plain GET form with a submit button. No inline
     * handler and no auto-submitting select: the Content-Security-Policy on
     * this application forbids inline script outright, and a select that
     * submits on change is unusable with a keyboard.
     */ ?>
    <form method="get" class="report-window" action="<?= e(url('/reports/' . (string) $report['id'])) ?>">
        <div class="field">
            <label for="range">Period</label>
            <select id="range" name="range">
                <?php foreach ($presets as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $window['preset'] === $key ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="from">From</label>
            <input type="date" id="from" name="from" value="<?= e((string) $window['from']) ?>">
        </div>

        <div class="field">
            <label for="to">To</label>
            <input type="date" id="to" name="to" value="<?= e((string) $window['to']) ?>">
        </div>

        <div class="field field-actions">
            <button type="submit" class="btn btn-sm">Show it</button>
            <p class="field-hint">The two dates apply when the period is set to Custom.</p>
        </div>
    </form>

    <p class="report-period">
        <?= icon('calendar', 14) ?>
        <strong><?= e((string) $window['label']) ?></strong>
        &mdash; <?= e(date('M j, Y', (int) strtotime((string) $window['from'] . ' 00:00:00 UTC'))) ?>
        to <?= e(date('M j, Y', (int) strtotime((string) $window['to'] . ' 00:00:00 UTC'))) ?>,
        <?= (int) $window['days'] ?> <?= (int) $window['days'] === 1 ? 'day' : 'days' ?>.
    </p>

    <?php if ($window['clamped']): ?>
        <div class="alert alert-warning">
            <?= icon('alert') ?>
            <div>
                <p>That period was longer than a report can cover, so it was
                    shortened to the most recent <?= (int) $window['days'] ?> days.
                    For anything longer, use the CSV export a year at a time.</p>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($scrubbed): ?>
        <div class="alert alert-info">
            <?= icon('info') ?>
            <div>
                <p>Your role does not include revenue, so the money columns below are
                    empty rather than hidden &mdash; every count beside them is
                    complete, and the rows line up with what a manager sees.</p>
            </div>
        </div>
    <?php endif; ?>
</section>

<?php if ($failure !== null): ?>
    <section class="card">
        <div class="alert alert-error">
            <?= icon('alert') ?>
            <div>
                <p><strong>This report could not run.</strong></p>
                <p><?= e($failure) ?></p>
            </div>
        </div>
    </section>
<?php else: ?>

    <?php if (($result['comparison'] ?? null) !== null): ?>
        <?php
        $comparison = $result['comparison'];
        $delta      = $comparison['delta'] ?? null;
        $format     = static fn (mixed $v): string => match ($comparison['type'] ?? 'int') {
            'money'  => money((int) $v),
            // A score is not a count: rounding 42.3 to 42 loses the movement
            // the report exists to show.
            'number' => rtrim(rtrim(number_format((float) $v, 1), '0'), '.'),
            default  => number_format((float) $v, 0),
        };

        /*
         * WHICH DIRECTION IS GOOD IS THE REPORT'S CALL, NOT THE VIEW'S.
         * More cancellations is worse and more revenue is better, and a view
         * that paints every rise green says "well done" under a column of
         * parties the venue lost. Where neither direction is good — message
         * volume — the figure stays neutral rather than being given a verdict
         * it does not have.
         */
        $good      = $comparison['good'] ?? null;
        $direction = $delta === null ? 'flat' : (string) $delta['direction'];
        $tone      = 'flat';

        if ($good !== null && $direction !== 'flat') {
            $tone = $direction === $good ? 'up' : 'down';
        }
        ?>
        <section class="card">
            <div class="card-head">
                <div>
                    <h2>Against <?= e((string) $comparison['label']) ?></h2>
                    <p class="card-sub">
                        Both sides happened. This is not a forecast &mdash; nothing in
                        this application projects.
                    </p>
                </div>
            </div>

            <div class="stat-row">
                <div class="stat">
                    <span class="stat-label">This period</span>
                    <strong class="stat-value"><?= e($format($comparison['now'])) ?></strong>
                </div>
                <div class="stat">
                    <span class="stat-label"><?= e(ucfirst((string) $comparison['label'])) ?></span>
                    <strong class="stat-value"><?= e($format($comparison['before'])) ?></strong>
                    <span class="stat-note">
                        <?= e(date('M j', (int) strtotime((string) $window['prior_from'] . ' 00:00:00 UTC'))) ?>
                        &ndash;
                        <?= e(date('M j, Y', (int) strtotime((string) $window['prior_to'] . ' 00:00:00 UTC'))) ?>
                    </span>
                </div>
                <div class="stat">
                    <span class="stat-label">Change</span>
                    <?php if ($delta === null): ?>
                        <?php /* No prior period at all. "No comparison" and "+100%" are
                                 different statements and only one of them is true. */ ?>
                        <strong class="stat-value">&mdash;</strong>
                        <span class="stat-note">nothing to compare with</span>
                    <?php else: ?>
                        <strong class="stat-value delta-<?= e($tone) ?>">
                            <?= $delta['change'] > 0 ? '+' : '' ?><?= e($format($delta['change'])) ?>
                        </strong>
                        <span class="stat-note">
                            <?php if ($delta['percent'] === null): ?>
                                up from nothing, so there is no percentage
                            <?php else: ?>
                                <?= $delta['percent'] > 0 ? '+' : '' ?><?= e((string) $delta['percent']) ?>%
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($chartKey !== null && $result['rows'] !== []): ?>
        <section class="card">
            <div class="card-head">
                <div>
                    <h2><?= e($chartLabel) ?> by day</h2>
                    <p class="card-sub">One bar per day in the table below.</p>
                </div>
            </div>

            <div class="report-chart">
                <?php foreach ($result['rows'] as $index => $row): ?>
                    <?php
                    $value  = (int) ($row[$chartKey] ?? 0);
                    $height = $chartMax > 0 ? max(1, (int) round(($value / $chartMax) * 100)) : 0;
                    $day    = (string) ($row[(string) $result['columns'][0]['key']] ?? '');
                    $stamp  = (int) strtotime($day . ' 00:00:00 UTC');
                    ?>
                    <div class="report-col">
                        <div class="report-bar" role="img"
                             aria-label="<?= e(date('D j M', $stamp)) ?>: <?= e($chartType === 'money' ? money($value) : number_format($value)) ?>"
                             title="<?= e(date('D j M', $stamp)) ?> &middot; <?= e($chartType === 'money' ? money($value) : number_format($value)) ?>">
                            <span class="report-fill" style="height:<?= $height ?>%"></span>
                        </div>
                        <span class="report-bar-label">
                            <?= $index % $labelEvery === 0 ? e(date('j M', $stamp)) : '&nbsp;' ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($labelEvery > 1): ?>
                <p class="muted">
                    <?php /* "5rd" is what a hard-coded suffix produces here. */ ?>
                    Every <?= $labelEvery ?><?= match ($labelEvery % 10) {
                        1 => $labelEvery % 100 === 11 ? 'th' : 'st',
                        2 => $labelEvery % 100 === 12 ? 'th' : 'nd',
                        3 => $labelEvery % 100 === 13 ? 'th' : 'rd',
                        default => 'th',
                    } ?>
                    date is printed to keep them legible; every bar is in the table below, and
                    hovering one names its day.
                </p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="card">
        <div class="card-head">
            <div>
                <h2>The figures</h2>
                <?php if (($result['note'] ?? null) !== null): ?>
                    <p class="card-sub"><?= e((string) $result['note']) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($result['rows'] === []): ?>
            <p class="empty">
                Nothing in this period. That is a real answer, not a missing one &mdash;
                try a longer period if you expected rows.
            </p>
        <?php else: ?>
            <div class="table-scroll">
                <table class="table table-report">
                    <caption class="sr-only"><?= e((string) $report['name']) ?></caption>
                    <thead>
                        <tr>
                            <?php foreach ($result['columns'] as $column): ?>
                                <th scope="col"
                                    class="<?= in_array($column['type'] ?? 'text', ['int', 'money', 'percent'], true) ? 'num-col' : '' ?>">
                                    <?= e((string) $column['label']) ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($result['rows'] as $row): ?>
                            <tr>
                                <?php foreach ($result['columns'] as $index => $column): ?>
                                    <?php
                                    $type    = (string) ($column['type'] ?? 'text');
                                    $classes = in_array($type, ['int', 'money', 'percent'], true) ? 'num-col' : '';
                                    ?>
                                    <?php if ($index === 0): ?>
                                        <th scope="row" class="<?= e($classes) ?>">
                                            <?= $cell($row[(string) $column['key']] ?? null, $type) ?>
                                        </th>
                                    <?php else: ?>
                                        <td class="<?= e($classes) ?>">
                                            <?= $cell($row[(string) $column['key']] ?? null, $type) ?>
                                        </td>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>

                    <?php if (($result['totals'] ?? []) !== []): ?>
                        <tfoot>
                            <tr>
                                <?php foreach ($result['columns'] as $index => $column): ?>
                                    <?php
                                    $key     = (string) $column['key'];
                                    $type    = (string) ($column['type'] ?? 'text');
                                    $classes = in_array($type, ['int', 'money', 'percent'], true) ? 'num-col' : '';
                                    ?>
                                    <?php if ($index === 0): ?>
                                        <th scope="row">Total</th>
                                    <?php elseif (array_key_exists($key, $result['totals'])): ?>
                                        <td class="<?= e($classes) ?>">
                                            <?= $cell($result['totals'][$key], $type) ?>
                                        </td>
                                    <?php else: ?>
                                        <?php /* A column with no meaningful total (an average, a
                                                 rate, a name) is left BLANK rather than summed —
                                                 a footer reading "412%" under a rate column is
                                                 worse than no footer. */ ?>
                                        <td></td>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>

            <?php if (count($result['rows']) >= \App\Services\ReportRunner::ROW_LIMIT): ?>
                <p class="muted">
                    Showing the first <?= \App\Services\ReportRunner::ROW_LIMIT ?> rows. Narrow the
                    period to see the rest &mdash; the totals above cover only what is shown.
                </p>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="card-head">
            <div>
                <h2>Keep this</h2>
                <p class="card-sub">
                    Saves the report and the period by name, so a saved
                    &ldquo;Last 30 days&rdquo; still means the last 30 days next month.
                </p>
            </div>
        </div>

        <form method="post" action="<?= e(url('/reports/saved')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="report" value="<?= e((string) $report['id']) ?>">
            <input type="hidden" name="range" value="<?= e((string) $window['preset']) ?>">
            <input type="hidden" name="from" value="<?= e((string) $window['from']) ?>">
            <input type="hidden" name="to" value="<?= e((string) $window['to']) ?>">

            <div class="field">
                <label for="name">Call it</label>
                <?php /*
                 * A real VALUE, not a placeholder. A placeholder that reads like
                 * a filled-in name means pressing Save it does nothing but
                 * raise "this field is required", which is a dead end a person
                 * hits once and does not come back from.
                 */ ?>
                <input type="text" id="name" name="name" maxlength="120" required
                       value="<?= e((string) $report['name'] . ' — ' . (string) $window['label']) ?>">
            </div>

            <button type="submit" class="btn btn-sm">Save it</button>
        </form>
    </section>
<?php endif; ?>
