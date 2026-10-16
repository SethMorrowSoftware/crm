<?php

declare(strict_types=1);

/**
 * Import a file.
 *
 * @var list<array<string,mixed>> $jobs
 * @var int                       $maxBytes
 * @var int                       $uploadMax
 */

$statusWords = [
    'mapping'      => ['badge-muted', 'Waiting to be mapped'],
    'dry_run'      => ['badge-warn', 'Checking…'],
    'dry_run_done' => ['badge-warn', 'Checked, not imported'],
    'importing'    => ['badge-warn', 'Importing…'],
    'done'         => ['badge-ok', 'Imported'],
    'failed'       => ['badge-bad', 'Stopped on an error'],
    'cancelled'    => ['badge-muted', 'Cancelled'],
];
?>

<section class="card">
    <div class="card-head">
        <div>
            <h2>Import a file</h2>
            <p class="card-sub">
                Bring guests and their visit history across from whatever you used before.
                A spreadsheet exported as CSV is all this needs.
            </p>
        </div>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('/settings/backups')) ?>">Backups &amp; export</a>
    </div>

    <p class="muted">
        <strong>Nothing is written until you have seen what would happen.</strong> You pick which
        column means what, then Castle CRM reads the whole file without changing anything and
        tells you exactly what it would add, what is already here, and which rows it cannot
        read &mdash; by row number, so you can fix them in the file.
    </p>

    <form method="post" action="<?= e(url('/imports')) ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>

        <div class="field-row">
            <fieldset class="field">
                <legend>What is in this file?</legend>

                <?php /* Radios, not a select: the two choices behave completely
                         differently and both are worth reading before choosing. */ ?>
                <label class="check">
                    <input type="radio" name="entity" value="contacts" checked>
                    <span>
                        <strong>Guests</strong>
                        <span class="field-hint">
                            Names, emails, phone numbers, addresses. One row per person.
                        </span>
                    </span>
                </label>

                <label class="check">
                    <input type="radio" name="entity" value="visits">
                    <span>
                        <strong>Visit history</strong>
                        <span class="field-hint">
                            One row per guest per day they came, with what they spent.
                            Import your guests first &mdash; this file matches them, it does
                            not create them.
                        </span>
                    </span>
                </label>
            </fieldset>

            <div class="field">
                <label for="file">The file</label>
                <input type="file" id="file" name="file" accept=".csv,text/csv,text/plain" required
                       aria-describedby="file-help">
                <p class="field-hint" id="file-help">
                    CSV, with the column names in the first row.
                    <?php
                    /*
                     * BOTH LIMITS, and the host's one first when it is smaller.
                     * `upload_max_filesize` is set by the host, not by us, and a
                     * file over it arrives at PHP as "no file was uploaded" with
                     * no number anywhere — so an operator whose 12MB file
                     * vanishes needs to be told the real figure.
                     */
                    $effective = min($uploadMax, $maxBytes);
                    ?>
                    Up to <?= e(bytes_human($effective)) ?>.
                    <?php if ($uploadMax < $maxBytes): ?>
                        That is this server&rsquo;s own upload limit; Castle CRM itself would
                        take <?= e(bytes_human($maxBytes)) ?>. A bigger file has to be split.
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <button type="submit" class="btn">Upload and choose the columns</button>
    </form>
</section>

<section class="card">
    <div class="card-head">
        <div>
            <h2>What to export from your old system</h2>
        </div>
    </div>

    <div class="grid-2">
        <div>
            <h3>For guests</h3>
            <p class="muted">
                At minimum, an <strong>email address or a phone number</strong> &mdash; that is
                what identifies a person. A name on its own is not enough: two different
                families called Smith are not one guest, and an import cannot undo merging
                them.
            </p>
            <p class="muted">
                Useful if you have them: first and last name, address, ZIP, date of birth
                (for birthday offers), and the card or account number you used before &mdash;
                that last one is what lets a visit-history file find the right person later.
            </p>
        </div>

        <div>
            <h3>About consent</h3>
            <p class="muted">
                <strong>Imported guests are not signed up for anything.</strong> A file from
                another system cannot show that somebody agreed to be emailed, and the column
                that looks like it does is usually a default somebody set once for everybody.
            </p>
            <p class="muted">
                If you genuinely hold records of people opting in &mdash; a form they filled in,
                a text they sent &mdash; you can map a consent column and tick the box that says
                so on the next screen. It is recorded in the audit log as your decision, with
                your name on it.
            </p>
        </div>
    </div>
</section>

<section class="card">
    <div class="card-head">
        <div>
            <h2>Recent imports</h2>
            <p class="card-sub">
                Every file, what it did, and the row-by-row report. Kept so that
                &ldquo;where did this contact come from?&rdquo; has an answer.
            </p>
        </div>
    </div>

    <?php if ($jobs === []): ?>
        <p class="empty">Nothing has been imported yet.</p>
    <?php else: ?>
        <div class="table-scroll">
            <table class="table">
                <caption class="sr-only">Recent imports</caption>
                <thead>
                    <tr>
                        <th scope="col">File</th>
                        <th scope="col">What</th>
                        <th scope="col">State</th>
                        <th scope="col" class="td-right">Added</th>
                        <th scope="col" class="td-right">Updated</th>
                        <th scope="col" class="td-right">Refused</th>
                        <th scope="col">When</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($jobs as $job): ?>
                        <?php [$badge, $words] = $statusWords[(string) $job['status']]
                            ?? ['badge-muted', (string) $job['status']]; ?>
                        <tr>
                            <th scope="row">
                                <a href="<?= e(url('/imports/' . (int) $job['id'])) ?>">
                                    <?= e((string) $job['original_name']) ?>
                                </a>
                                <span class="td-sub">
                                    <?= e(bytes_human((int) $job['bytes'])) ?>
                                    <?php if (($job['created_by_name'] ?? null) !== null): ?>
                                        · <?= e((string) $job['created_by_name']) ?>
                                    <?php endif; ?>
                                </span>
                            </th>
                            <td><?= (string) $job['entity'] === 'visits' ? 'Visits' : 'Guests' ?></td>
                            <td>
                                <span class="badge <?= e($badge) ?>"><?= e($words) ?></span>
                                <?php if (($job['error'] ?? null) !== null): ?>
                                    <p class="field-hint"><?= e((string) $job['error']) ?></p>
                                <?php endif; ?>
                            </td>
                            <td class="td-right"><?= number_format((int) $job['created']) ?></td>
                            <td class="td-right"><?= number_format((int) $job['updated']) ?></td>
                            <td class="td-right">
                                <?php if ((int) $job['refused'] > 0): ?>
                                    <strong><?= number_format((int) $job['refused']) ?></strong>
                                <?php else: ?>
                                    0
                                <?php endif; ?>
                            </td>
                            <td><?= e(local_time((string) $job['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
