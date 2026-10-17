<?php

declare(strict_types=1);

/**
 * Backups and data export.
 *
 * @var list<array{filename:string,bytes:int,created_at:int,compressed:bool}> $backups
 * @var int                 $totalBytes
 * @var int                 $keep
 * @var bool                $nightly
 * @var mixed               $lastRun
 * @var mixed               $lastError
 * @var bool                $canCompress
 * @var list<string>        $tables
 * @var array<string,int>   $rowCounts
 * @var array{present:bool,counts:array<string,int>,total:int,untagged_linked:int} $demoData
 */

$bytes = static function (int $size): string {
    if ($size < 1024) {
        return $size . ' B';
    }

    $units = ['KB', 'MB', 'GB'];
    $value = $size / 1024;
    $unit = 0;

    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }

    return round($value, $value < 10 ? 1 : 0) . ' ' . $units[$unit];
};

$stale = $lastRun === null || strtotime((string) $lastRun) < time() - 172800;
?>
<p class="page-lede">
    Castle CRM takes its own database backups and keeps the most recent few. This is deliberately
    not a substitute for your host's backups &mdash; it is the copy you can restore yourself, at
    11pm on a Saturday, without opening a support ticket.
</p>

<?php if ($lastError !== null): ?>
    <div class="alert alert-error">
        <?= icon('alert') ?>
        <p>
            <strong>The last backup failed:</strong> <?= e((string) $lastError) ?>
        </p>
    </div>
<?php endif; ?>

<?php if ($backups === []): ?>
    <div class="alert alert-warning">
        <?= icon('alert') ?>
        <p>
            <strong>There are no backups yet.</strong> Take one now, then check that the file
            downloads and opens. A backup nobody has ever restored is a hope, not a backup.
        </p>
    </div>
<?php elseif ($stale): ?>
    <div class="alert alert-warning">
        <?= icon('clock') ?>
        <p>
            The last backup was
            <?= $lastRun === null ? 'never recorded' : e(time_ago((string) $lastRun)) ?>.
            If nightly backups are on, this usually means cron is not running &mdash; check
            <a href="<?= e(url('/settings/health')) ?>">System health</a>.
        </p>
    </div>
<?php endif; ?>

<!-- ───────────────────────────── Take one now ─────────────────────────── -->
<section class="card">
    <div class="card-head">
        <div>
            <h2>Take a backup now</h2>
            <p class="card-sub">
                Writes a single SQL file<?= $canCompress ? ', gzip-compressed' : '' ?> to
                <code>storage/backups</code>.
            </p>
        </div>
        <?php if ($backups !== []): ?>
            <span class="badge badge-muted"><?= count($backups) ?> kept,
                <?= e($bytes($totalBytes)) ?> total</span>
        <?php endif; ?>
    </div>

    <?php if (!$canCompress): ?>
        <p class="field-hint">
            This server has no zlib extension, so backups are written uncompressed and will be
            several times larger. Nothing is wrong; they just take more disk.
        </p>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/settings/backups')) ?>" class="form">
        <?= csrf_field() ?>

        <div class="field-check">
            <input type="checkbox" id="include_volatile" name="include_volatile" value="1">
            <label for="include_volatile">Include transient tables</label>
            <p class="field-hint">
                Normally sign-in sessions, the job queue and delivery logs are backed up as empty
                structures, because restoring last Tuesday's half-finished jobs helps nobody. Tick
                this only if you are debugging something specific.
            </p>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn"><?= icon('database', 16) ?> Back up now</button>
            <span class="form-hint">
                On a big database this can take a minute. Do not reload the page while it runs.
            </span>
        </div>
    </form>
</section>

<!-- ────────────────────────────── The files ───────────────────────────── -->
<section class="card">
    <div class="card-head">
        <div>
            <h2>Stored backups</h2>
            <p class="card-sub">Newest first. Download one and keep it somewhere that is not this server.</p>
        </div>
    </div>

    <?php if ($backups === []): ?>
        <p class="empty">No backup files yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <caption class="sr-only">Stored backups</caption>
                <thead>
                    <tr>
                        <th scope="col">File</th>
                        <th scope="col">Taken</th>
                        <th scope="col">Size</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($backups as $backup): ?>
                        <tr>
                            <td><code class="wrap"><?= e($backup['filename']) ?></code></td>
                            <td>
                                <?= e(local_time(gmdate('Y-m-d H:i:s', $backup['created_at']))) ?>
                                <span class="muted">&middot;
                                    <?= e(time_ago(gmdate('Y-m-d H:i:s', $backup['created_at']))) ?></span>
                            </td>
                            <td><?= e($bytes($backup['bytes'])) ?></td>
                            <td class="row-actions">
                                <a class="btn btn-sm btn-ghost"
                                   href="<?= e(url('/settings/backups/' . $backup['filename'] . '/download')) ?>">
                                    <?= icon('download', 14) ?> Download
                                </a>
                                <form method="post"
                                      action="<?= e(url('/settings/backups/' . $backup['filename'] . '/delete')) ?>"
                                      class="inline-form">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm btn-ghost btn-danger-text">
                                        <?= icon('trash', 14) ?> Delete
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div class="alert alert-info">
        <?= icon('info') ?>
        <p>
            <strong>A database backup is not a complete backup.</strong>
            <code>config/config.php</code> holds the encryption key, and without it the provider
            passwords stored in the database cannot be read back. Keep a copy of that one file
            somewhere separate, and back up <code>storage/uploads</code> with cPanel's File Manager
            if you keep attachments. Restore instructions are written into the top of every backup
            file, and in <code>docs/INSTALL.md</code>.
        </p>
    </div>
</section>

<!-- ───────────────────────────── Retention ────────────────────────────── -->
<form method="post" action="<?= e(url('/settings/backups/settings')) ?>" class="card form">
    <?= csrf_field() ?>

    <div class="card-head">
        <div>
            <h2>Schedule &amp; retention</h2>
            <p class="card-sub">Nightly backups ride on the same cron line as everything else.</p>
        </div>
    </div>

    <div class="field-check">
        <input type="hidden" name="nightly" value="0">
        <input type="checkbox" id="nightly" name="nightly" value="1" <?= $nightly ? 'checked' : '' ?>>
        <label for="nightly">Take a backup every night</label>
        <p class="field-hint">
            Runs with the other nightly maintenance. Needs the cron line from the installer to be
            in place &mdash; without cron, nothing here happens on its own.
        </p>
    </div>

    <div class="field">
        <label for="keep">Backups to keep</label>
        <input type="number" id="keep" name="keep" min="1" max="60" value="<?= (int) $keep ?>" required>
        <p class="field-hint">
            Older files are deleted when a new one is written. Seven daily backups is about a week of
            room to notice a mistake; a shared-hosting disk quota is the limit on being more generous.
        </p>
        <?php if ($err = error_for('keep')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn">Save schedule</button>
    </div>
</form>

<!-- ────────────────────────── Export one table ────────────────────────── -->
<form method="get" action="<?= e(url('/settings/backups/export')) ?>" class="card form">
    <div class="card-head">
        <div>
            <h2>Export a table as CSV</h2>
            <p class="card-sub">
                For a spreadsheet, for another system, or because a guest asked for a copy of their
                data.
            </p>
        </div>
    </div>

    <div class="field">
        <label for="table">Table</label>
        <select id="table" name="table" required>
            <?php foreach ($tables as $table): ?>
                <option value="<?= e($table) ?>">
                    <?= e(str_replace('_', ' ', $table)) ?>
                    (<?= number_format($rowCounts[$table] ?? 0) ?> rows)
                </option>
            <?php endforeach; ?>
        </select>
        <p class="field-hint">
            Exported with a UTF-8 byte-order mark so Excel reads accented names correctly, and with
            any cell beginning <code>=</code> or <code>+</code> prefixed with an apostrophe, so a
            guest's "name" cannot execute as a formula on a staff machine.
        </p>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-ghost"><?= icon('download', 16) ?> Download CSV</button>
        <span class="form-hint">Every export is recorded in the audit log with your name on it.</span>
    </div>
</form>

<?php if ($demoData['present']): ?>
<!-- ──────────────────────── Remove the example data ───────────────────── -->
<form method="post" action="<?= e(url('/settings/backups/demo-data')) ?>" class="card card-danger form">
    <?= csrf_field() ?>

    <div class="card-head">
        <div>
            <h2>Remove the example data</h2>
            <p class="card-sub">
                The example guests and bookings the installer seeded, so you can judge the reports
                on something that looks like a Saturday. Do this before you import your real list,
                so there is never a question about which rows are which.
            </p>
        </div>
    </div>

    <p class="field-hint">
        <?= number_format($demoData['total']) ?> records &mdash;
        <?= e(App\Services\DemoData::describe($demoData['counts'])) ?>.
        Every one is tagged <code>demo-data</code>, every address is on a reserved domain, and
        none of them can be contacted even with Safe Mode off.
    </p>

    <?php if ($demoData['untagged_linked'] > 0): ?>
        <div class="alert alert-warning">
            <?= icon('alert', 16) ?>
            <p>
                <?= number_format($demoData['untagged_linked']) ?> booking(s) or deal(s) you
                created yourself against an example guest will be <strong>kept</strong>, because
                they are yours rather than the seed's. They will be left with no guest attached.
            </p>
        </div>
    <?php endif; ?>

    <div class="field">
        <label for="confirm-demo">Type DELETE to confirm</label>
        <input type="text" id="confirm-demo" name="confirm" autocomplete="off" required
               placeholder="DELETE" style="max-width: 14rem;">
        <p class="field-hint">
            This cannot be undone from here &mdash; the example data is only seeded by the
            installer.
        </p>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-danger"><?= icon('trash', 16) ?> Remove example data</button>
        <span class="form-hint">Recorded in the audit log with your name on it.</span>
    </div>
</form>
<?php endif; ?>
