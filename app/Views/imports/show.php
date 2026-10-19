<?php

declare(strict_types=1);

/**
 * One import: the mapping screen, the progress, and the report.
 *
 * All three on one page deliberately. The mapping is what the report is about,
 * and a venue reading "188 refused: could not read the date" needs the date
 * column's setting in the same view, not two clicks away.
 *
 * @var array<string,mixed>       $job
 * @var list<string>              $headers
 * @var array<int,string>         $mapping
 * @var array<string,mixed>       $options
 * @var array<string,array{label:string,aliases:list<string>,note?:string}> $fields
 * @var list<array{level:string,message:string}> $problems
 * @var array<string,mixed>       $summary
 * @var list<array<string,mixed>> $reasons
 * @var list<array<string,mixed>> $rows
 * @var int                       $rowTotal
 * @var bool                      $reportCapped
 * @var string                    $outcome
 * @var int                       $page
 * @var int                       $perPage
 * @var array<string,string>      $duplicateModes
 * @var list<string>              $visitSources
 */

use App\Services\Importer;

$status  = (string) $job['status'];
$entity  = (string) $job['entity'];
$id      = (int) $job['id'];
$running = in_array($status, ['dry_run', 'importing'], true);
$checked = $status === 'dry_run_done';
$done    = $status === 'done';
$mapOpen = !$running && !$done;

$blockers = array_values(array_filter(
    $problems,
    static fn (array $problem): bool => $problem['level'] === 'blocker'
));
$advice = array_values(array_filter(
    $problems,
    static fn (array $problem): bool => $problem['level'] === 'advice'
));

$outcomeWords = [
    'created' => 'Added',
    'updated' => 'Updated',
    'skipped' => 'Already here',
    'refused' => 'Refused',
];
?>

<p class="breadcrumbs">
    <a href="<?= e(url('/imports')) ?>">Imports</a> &rsaquo;
    <?= e((string) $job['original_name']) ?>
</p>

<section class="card <?= $running ? 'card-accent' : ($status === 'failed' ? 'card-danger' : '') ?>">
    <div class="card-head">
        <div>
            <h2><?= e((string) $summary['headline']) ?></h2>
            <p class="card-sub">
                <?= e(match ($entity) { 'visits' => 'Visit history', 'children' => 'Children', default => 'Guests' }) ?>
                from <?= e((string) $job['original_name']) ?>
                (<?= e(bytes_human((int) $job['bytes'])) ?>)<?php
                ?><?php if (($job['created_by_name'] ?? null) !== null): ?>,
                    uploaded by <?= e((string) $job['created_by_name']) ?>
                <?php endif; ?>.
            </p>
        </div>

        <?php if ($running): ?>
            <form method="post" action="<?= e(url('/imports/' . $id . '/cancel')) ?>"
                  class="inline-form"
                  data-confirm="Stop this import? Anything already imported stays.">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-danger btn-sm">Stop</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($running): ?>
        <?php /*
               * NO AUTO-REFRESH. The app's CSP forbids inline script, and a
               * meta refresh on a page with a form on it would throw away
               * whatever the operator was half way through typing. A button
               * they press when they want to look is honest and costs nothing.
               */ ?>
        <p>
            <?= $status === 'dry_run' ? 'Checking the file' : 'Importing' ?>.
            This carries on in the background, a batch a minute, so you can leave this page.
        </p>

        <?php if ($summary['percent'] !== null): ?>
            <div class="bar" role="img"
                 aria-label="<?= (int) $summary['percent'] ?>% of the file read">
                <div class="bar-fill" style="width: <?= (int) $summary['percent'] ?>%"></div>
            </div>
        <?php endif; ?>

        <p class="muted">
            <?= number_format((int) $summary['seen']) ?> row<?= (int) $summary['seen'] === 1 ? '' : 's' ?>
            read so far.
            <?php if ($summary['percent'] === null): ?>
                The total is not known until the file has been read to the end.
            <?php endif; ?>
        </p>

        <a class="btn btn-ghost btn-sm" href="<?= e(url('/imports/' . $id)) ?>">Check again</a>
    <?php endif; ?>

    <?php if ($status === 'failed' && ($job['error'] ?? null) !== null): ?>
        <p class="field-error"><?= e((string) $job['error']) ?></p>
    <?php endif; ?>

    <?php if (!$running && (int) $summary['seen'] > 0): ?>
        <div class="stat-row">
            <?php foreach ([
                ['Added', (int) $summary['created'], 'created'],
                ['Updated', (int) $summary['updated'], 'updated'],
                ['Already here', (int) $summary['skipped'], 'skipped'],
                ['Refused', (int) $summary['refused'], 'refused'],
            ] as [$label, $value, $key]): ?>
                <a class="stat<?= $key === 'refused' && $value > 0 ? ' stat-warn' : '' ?>"
                   href="<?= e(url('/imports/' . $id . '?show=' . $key)) ?>">
                    <span class="stat-value"><?= number_format($value) ?></span>
                    <span class="stat-label"><?= e($label) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($checked): ?>
        <p>
            <strong>Nothing has been written yet.</strong> That was the check: it read the whole
            file against the real database and changed nothing. Read the report below, and if it
            says what you expect, import it.
        </p>
    <?php endif; ?>

    <?php if ($done): ?>
        <p class="muted">
            Finished <?= e(local_time((string) $job['finished_at'])) ?>. The report below lists
            every row.
        </p>
    <?php endif; ?>
</section>

<?php if ($mapOpen): ?>
<section class="card">
    <div class="card-head">
        <div>
            <h2>Which column means what</h2>
            <p class="card-sub">
                The guesses come from your column names. Change anything that is wrong, and set
                &ldquo;ignore&rdquo; for columns Castle CRM has no use for.
            </p>
        </div>
    </div>

    <?php foreach ($blockers as $problem): ?>
        <p class="field-error"><?= e($problem['message']) ?></p>
    <?php endforeach; ?>

    <?php foreach ($advice as $problem): ?>
        <?php /* Advice, in the ordinary voice: it does not stop the import and
                 for some venues it is exactly what they want. */ ?>
        <p class="muted"><?= e($problem['message']) ?></p>
    <?php endforeach; ?>

    <form method="post" action="<?= e(url('/imports/' . $id . '/start')) ?>">
        <?= csrf_field() ?>

        <div class="table-scroll">
            <table class="table">
                <caption class="sr-only">Column mapping</caption>
                <thead>
                    <tr>
                        <th scope="col">Column in your file</th>
                        <th scope="col">Means</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($headers as $index => $header): ?>
                        <?php $selected = $mapping[$index] ?? ''; ?>
                        <tr>
                            <th scope="row">
                                <label for="map-<?= (int) $index ?>">
                                    <?= trim($header) === ''
                                        ? '<span class="muted">(column ' . ((int) $index + 1) . ', no heading)</span>'
                                        : e($header) ?>
                                </label>
                            </th>
                            <td>
                                <select id="map-<?= (int) $index ?>" name="map[<?= (int) $index ?>]">
                                    <option value="">&mdash; ignore this column &mdash;</option>
                                    <?php foreach ($fields as $field => $spec): ?>
                                        <option value="<?= e($field) ?>"
                                            <?= $selected === $field ? 'selected' : '' ?>>
                                            <?= e($spec['label']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <h3>Settings for this file</h3>

        <div class="field-row">
            <div class="field">
                <label for="date_order">If a date could be read two ways</label>
                <select id="date_order" name="date_order">
                    <option value="" <?= ($options['date_order'] ?? '') === '' ? 'selected' : '' ?>>
                        Refuse the row and tell me
                    </option>
                    <option value="mdy" <?= ($options['date_order'] ?? '') === 'mdy' ? 'selected' : '' ?>>
                        Month first — 03/04 is 4 March
                    </option>
                    <option value="dmy" <?= ($options['date_order'] ?? '') === 'dmy' ? 'selected' : '' ?>>
                        Day first — 03/04 is 3 April
                    </option>
                </select>
                <p class="field-hint">
                    <?php /* The default REFUSES, and the hint says why. A birthday
                             that is wrong by a month sends the offer to the wrong
                             week every year, silently. */ ?>
                    <code>03/04/2024</code> is two different dates. Unless you tell it which,
                    a row like that is refused rather than guessed &mdash; a birthday wrong by a
                    month sends the offer to the wrong week every year. Dates like
                    <code>2024-03-04</code> or <code>25/03/2024</code> are never ambiguous and
                    are always read.
                </p>
            </div>

            <?php if ($entity === 'contacts'): ?>
                <div class="field">
                    <label for="on_duplicate">When somebody is already here</label>
                    <select id="on_duplicate" name="on_duplicate">
                        <?php foreach ($duplicateModes as $mode => $label): ?>
                            <option value="<?= e($mode) ?>"
                                <?= ($options['on_duplicate'] ?? 'fill') === $mode ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="field-hint">
                        Matched on email first, then phone number. Consent is never changed by
                        an import, whichever of these you pick.
                    </p>
                </div>
            <?php else: ?>
                <div class="field">
                    <label for="visit_source">Record these visits as</label>
                    <select id="visit_source" name="visit_source">
                        <?php foreach ($visitSources as $source): ?>
                            <option value="<?= e($source) ?>"
                                <?= ($options['visit_source'] ?? 'pos_import') === $source ? 'selected' : '' ?>>
                                <?= e(str_replace('_', ' ', $source)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="field-hint">
                        A till export is <code>pos import</code>, which outranks every other
                        source &mdash; so it corrects a visit the front desk recorded by hand,
                        and nothing later undoes it.
                    </p>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($entity === 'contacts'): ?>
            <fieldset class="field">
                <legend>Consent</legend>

                <label class="check">
                    <input type="checkbox" name="consent_claimed" value="1"
                        <?= ($options['consent_claimed'] ?? false) ? 'checked' : '' ?>>
                    <span>
                        <strong>I hold records of these people agreeing to be contacted.</strong>
                        <span class="field-hint">
                            Leave this unticked and every guest arrives signed up for nothing,
                            whatever the file says &mdash; which is the right answer unless you
                            can point to a form they filled in or a text they sent. Ticking it
                            is recorded in the audit log with your name on it, and it still only
                            applies to rows whose consent column actually says yes.
                        </span>
                    </span>
                </label>

                <p class="field-hint">
                    A do-not-contact column is always honoured, ticked or not. Those guests are
                    imported and never messaged.
                </p>
            </fieldset>
        <?php endif; ?>

        <div class="card-actions">
            <?php /* Once a check has run, IMPORTING is the emphasised action and
                     re-checking steps back to a quieter button. Before that there
                     is only one thing to do, so it carries the emphasis. */ ?>
            <button type="submit" class="btn<?= $checked ? ' btn-ghost' : '' ?>">
                <?= $checked ? 'Check it again' : 'Check the file (writes nothing)' ?>
            </button>

            <?php if ($checked): ?>
                <button type="submit" name="commit" value="1" class="btn">
                    Import it for real
                </button>
            <?php endif; ?>
        </div>

        <?php if (!$checked): ?>
            <p class="field-hint">
                <?php /* The button to import is not drawn until a check has run.
                         The dry run is the only thing between a mis-mapped
                         column and a guest database where every town is a
                         postcode, and it costs one minute. */ ?>
                The import button appears once the check has run. It is the only thing between a
                column mapped wrongly and a few thousand records nobody can sort out
                afterwards.
            </p>
        <?php endif; ?>
    </form>
</section>
<?php endif; ?>

<?php if ($reasons !== []): ?>
<section class="card">
    <div class="card-head">
        <div>
            <h2>Why rows were not imported</h2>
            <p class="card-sub">
                Grouped, because the first question about a few hundred refusals is whether it
                is one problem or a hundred &mdash; and it is almost always one.
            </p>
        </div>
    </div>

    <div class="table-scroll">
        <table class="table">
            <caption class="sr-only">Refusals and skips by reason</caption>
            <thead>
                <tr>
                    <th scope="col">Reason</th>
                    <th scope="col" class="td-right">Rows</th>
                    <th scope="col">First at</th>
                    <th scope="col">For example</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reasons as $reason): ?>
                    <tr>
                        <th scope="row">
                            <span class="badge <?= (string) $reason['outcome'] === 'refused'
                                ? 'badge-bad' : 'badge-muted' ?>">
                                <?= e($outcomeWords[(string) $reason['outcome']] ?? (string) $reason['outcome']) ?>
                            </span>
                            <?= e(str_replace('_', ' ', (string) ($reason['reason'] ?? 'unknown'))) ?>
                        </th>
                        <td class="td-right"><?= number_format((int) $reason['rows_count']) ?></td>
                        <td>row <?= (int) $reason['first_line'] ?></td>
                        <td class="cell-wrap"><?= e(str_truncate((string) ($reason['example'] ?? ''), 140)) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<section class="card">
    <div class="card-head">
        <div>
            <h2>Row by row</h2>
            <p class="card-sub">
                Row numbers match what a spreadsheet shows when you open the file, so a refusal
                can be found and fixed.
            </p>
        </div>

        <a class="btn btn-ghost btn-sm"
           href="<?= e(url('/imports/' . $id . '/export?show=' . $outcome)) ?>">
            <?= icon('download', 14) ?>
            Download these as CSV
        </a>
    </div>

    <div class="tab-bar">
        <?php foreach (['refused' => 'Refused', 'skipped' => 'Already here',
                        'created' => 'Added', 'updated' => 'Updated', 'all' => 'Everything'] as $key => $label): ?>
            <a class="tab<?= $outcome === $key ? ' is-active' : '' ?>"
               href="<?= e(url('/imports/' . $id . '?show=' . $key)) ?>"
               <?= $outcome === $key ? 'aria-current="page"' : '' ?>>
                <?= e($label) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($reportCapped): ?>
        <p class="muted">
            <?php /* Said rather than hidden: a wrong mapping refuses every row,
                     and 50,000 identical refusals is a report nobody reads. The
                     COUNTS above are exact. */ ?>
            This list stops at the first <?= number_format(Importer::MAX_REPORTED) ?> rows.
            The counts above cover the whole file.
        </p>
    <?php endif; ?>

    <?php if ($rows === []): ?>
        <p class="empty">
            <?= $outcome === 'refused'
                ? 'No rows were refused.'
                : 'Nothing to show here.' ?>
        </p>
    <?php else: ?>
        <div class="table-scroll">
            <table class="table">
                <caption class="sr-only">Rows in this import</caption>
                <thead>
                    <tr>
                        <th scope="col">Row</th>
                        <th scope="col">What happened</th>
                        <th scope="col">Who</th>
                        <th scope="col">Detail</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <th scope="row"><?= (int) $row['line'] ?></th>
                            <td>
                                <span class="badge <?= match ((string) $row['outcome']) {
                                    'created' => 'badge-ok',
                                    'updated' => 'badge-ok',
                                    'refused' => 'badge-bad',
                                    default   => 'badge-muted',
                                } ?>">
                                    <?= e($outcomeWords[(string) $row['outcome']] ?? (string) $row['outcome']) ?>
                                </span>
                            </td>
                            <td class="cell-wrap">
                                <?php if (($row['entity_id'] ?? null) !== null && $entity === 'contacts'): ?>
                                    <a href="<?= e(url('/contacts/' . (int) $row['entity_id'])) ?>">
                                        <?= e((string) ($row['label'] ?? 'Contact ' . (int) $row['entity_id'])) ?>
                                    </a>
                                <?php else: ?>
                                    <?= e((string) ($row['label'] ?? '—')) ?>
                                <?php endif; ?>
                            </td>
                            <td class="cell-wrap"><?= e((string) ($row['message'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($rowTotal > $perPage): ?>
            <nav class="pagination" aria-label="Report pages">
                <?php if ($page > 1): ?>
                    <a class="btn btn-ghost btn-sm"
                       href="<?= e(url('/imports/' . $id . '?show=' . $outcome . '&page=' . ($page - 1))) ?>">
                        Previous
                    </a>
                <?php endif; ?>

                <span class="pagination-info">
                    <?= number_format((($page - 1) * $perPage) + 1) ?>–<?=
                        number_format(min($page * $perPage, $rowTotal)) ?>
                    of <?= number_format($rowTotal) ?>
                </span>

                <?php if ($page * $perPage < $rowTotal): ?>
                    <a class="btn btn-ghost btn-sm"
                       href="<?= e(url('/imports/' . $id . '?show=' . $outcome . '&page=' . ($page + 1))) ?>">
                        Next
                    </a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php if (!$running): ?>
<section class="card">
    <div class="card-head"><div><h2>Finished with this file?</h2></div></div>

    <p class="muted">
        Deleting the job removes the uploaded file from the server and this report with it.
        <strong>Nothing that was imported is removed</strong> &mdash; those are ordinary
        records now.
    </p>

    <form method="post" action="<?= e(url('/imports/' . $id . '/delete')) ?>"
          data-confirm="Delete this import job and its report? Imported records stay.">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-danger">Delete the job and the file</button>
    </form>
</section>
<?php endif; ?>
