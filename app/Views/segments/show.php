<?php

declare(strict_types=1);

/**
 * One segment: what it asks, how many it found, and a sample of who.
 *
 * The sample is the point of this page. A count cannot answer "why is this
 * full of staff addresses", and that is the question somebody needs to ask
 * before using a segment for anything.
 *
 * @var array<string,mixed>       $segment
 * @var array<string,mixed>       $rules
 * @var string                    $described
 * @var list<array<string,mixed>> $preview
 * @var string                    $error
 * @var bool                      $canManage
 * @var int                       $previewLimit
 */

$id = (int) $segment['id'];

?>
<p class="breadcrumbs">
    <a href="<?= e(url('/segments')) ?>">Segments</a> / <?= e((string) $segment['name']) ?>
</p>

<div class="page-actions">
    <p class="page-lede">
        <?php if (($segment['description'] ?? '') !== ''): ?>
            <?= e((string) $segment['description']) ?>
        <?php else: ?>
            A saved question about the guest list.
        <?php endif; ?>
    </p>
    <?php if ($canManage): ?>
        <form method="post" action="<?= e(url('/segments/' . $id . '/refresh')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-ghost">Count now</button>
        </form>
        <a class="btn" href="<?= e(url('/segments/' . $id . '/edit')) ?>">Edit</a>
    <?php endif; ?>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-error">
        <?= icon('alert') ?>
        <p>
            <strong>This segment will not run.</strong>
            <?= e($error) ?>
            Nothing can be sent to it until that is fixed &mdash; which is deliberate: a
            condition that cannot be read would otherwise be skipped, and skipping one
            <em>widens</em> the segment.
        </p>
    </div>
<?php endif; ?>

<div class="stat-row">
    <div class="stat">
        <span class="stat-label">Matched</span>
        <span class="stat-value">
            <?= $segment['cached_count'] === null
                ? 'Not counted'
                : e(number_format((int) $segment['cached_count'])) ?>
        </span>
        <span class="stat-note">
            <?php if ($segment['counted_at'] === null): ?>
                Press <strong>Count now</strong> to measure it.
            <?php else: ?>
                Counted <?= e(time_ago((string) $segment['counted_at'])) ?>.
                <?php if ($segment['count_stale']): ?>
                    This figure is old enough to have moved.
                <?php endif; ?>
            <?php endif; ?>
        </span>
    </div>
    <div class="stat">
        <span class="stat-label">Conditions</span>
        <span class="stat-value"><?= e((string) $segment['rule_count']) ?></span>
        <span class="stat-note">Re-run every time a campaign uses this segment.</span>
    </div>
</div>

<div class="card">
    <h2>What it asks</h2>
    <p><?= e($described) ?></p>
</div>

<div class="card">
    <h2>A sample of who matches</h2>

    <?php if ($error !== ''): ?>
        <p class="empty">Nothing to show while the conditions cannot be read.</p>
    <?php elseif ($preview === []): ?>
        <p class="empty">
            Nobody matches these conditions at the moment.
        </p>
    <?php else: ?>
        <p class="field-hint">
            The first <?= e((string) count($preview)) ?>
            <?php if (count($preview) === $previewLimit): ?>
                of however many &mdash; this is a sample, not the list.
            <?php else: ?>
                &mdash; which is all of them.
            <?php endif; ?>
        </p>

        <div class="table-wrap">
            <table class="table">
                <caption class="sr-only">A sample of the guests this segment matches</caption>
                <thead>
                    <tr>
                        <th scope="col">Guest</th>
                        <th scope="col">Stage</th>
                        <th scope="col">Reachable</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($preview as $guest): ?>
                        <tr>
                            <th scope="row">
                                <a href="<?= e(url('/contacts/' . (int) $guest['id'])) ?>">
                                    <?= e(trim(
                                        (string) ($guest['first_name'] ?? '') . ' '
                                        . (string) ($guest['last_name'] ?? '')
                                    ) ?: 'Unnamed guest') ?>
                                </a>
                                <?php if (($guest['email'] ?? '') !== ''): ?>
                                    <span class="td-sub"><?= e((string) $guest['email']) ?></span>
                                <?php endif; ?>
                            </th>
                            <td><?= e(ucfirst((string) $guest['lifecycle_stage'])) ?></td>
                            <td>
                                <?php
                                /*
                                 * Shown because a segment can legitimately
                                 * contain people nobody may write to — the
                                 * consent check is in the send path, not
                                 * here, so a segment is allowed to be wider
                                 * than the audience it produces. Seeing that
                                 * on the sample is how somebody understands
                                 * why a count of 400 sent 180 messages.
                                 */
                                ?>
                                <?= (int) ($guest['email_consent'] ?? 0) === 1
                                    ? '<span class="badge badge-ok">Email</span>' : '' ?>
                                <?= (int) ($guest['sms_consent'] ?? 0) === 1
                                    ? '<span class="badge badge-ok">Text</span>' : '' ?>
                                <?php if ((int) ($guest['email_consent'] ?? 0) === 0
                                    && (int) ($guest['sms_consent'] ?? 0) === 0): ?>
                                    <span class="muted">No marketing consent</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p class="field-hint">
            A segment selects people; it does not grant permission to contact them. Consent is
            checked again for every recipient when a campaign sends, so a segment can hold more
            people than a campaign will reach.
        </p>
    <?php endif; ?>
</div>

<?php if ($canManage): ?>
    <div class="card">
        <h2>Delete</h2>
        <p class="field-hint">
            A segment a campaign points at cannot be deleted &mdash; that campaign's record of
            who it went to is how the venue answers &ldquo;why did I get this&rdquo;.
        </p>
        <form method="post" action="<?= e(url('/segments/' . $id . '/delete')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-danger btn-sm">Delete this segment</button>
        </form>
    </div>
<?php endif; ?>
