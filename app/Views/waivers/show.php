<?php

declare(strict_types=1);

/**
 * One waiver version, and who has signed it.
 *
 * @var array<string,mixed>       $waiver
 * @var list<array<string,mixed>> $signatures
 * @var int                       $total
 * @var string                    $publicUrl
 * @var bool                      $editable
 */

$id     = (int) $waiver['id'];
$live   = (int) $waiver['is_active'] === 1;
$signed = $total > 0;

?>
<p class="breadcrumbs">
    <a href="<?= e(url('/waivers')) ?>">Waivers</a> / <?= e((string) $waiver['version']) ?>
</p>

<div class="page-actions">
    <p class="page-lede">
        <?php if ($live): ?>
            <span class="badge badge-ok">Live</span> This is what guests are signing now.
        <?php elseif ($waiver['published_at'] !== null): ?>
            <span class="badge badge-muted">Retired</span>
            Published <?= e(local_date((string) $waiver['published_at'])) ?>.
            Signatures on it still stand.
        <?php else: ?>
            <span class="badge badge-warn">Draft</span> Nobody has been shown this yet.
        <?php endif; ?>
    </p>
    <?php if ($editable): ?>
        <a class="btn btn-ghost" href="<?= e(url('/waivers/' . $id . '/edit')) ?>">Edit</a>
    <?php endif; ?>
    <?php if (!$live): ?>
        <form method="post" action="<?= e(url('/waivers/' . $id . '/publish')) ?>" class="inline-form">
            <?= csrf_field() ?>
            <button type="submit" class="btn">Make this the live one</button>
        </form>
    <?php endif; ?>
</div>

<?php if (!$editable && $waiver['published_at'] !== null): ?>
    <div class="alert alert-warning">
        <p>
            This version has been published, so its wording cannot be changed — it is what
            <?= e(number_format($total)) ?> guest<?= $total === 1 ? '' : 's' ?>
            <?= $total === 1 ? 'has' : 'have' ?> already agreed to. To change the wording, create a
            new version and publish that; every signature here stays exactly as it is.
        </p>
    </div>
<?php endif; ?>

<div class="card">
    <h2><?= e((string) $waiver['title']) ?></h2>
    <p class="muted">
        Version <?= e((string) $waiver['version']) ?>.
        <?php if ((int) $waiver['requires_guardian'] === 1): ?>
            A parent or guardian signs for a child.
        <?php endif; ?>
        Guests sign at <a href="<?= e($publicUrl) ?>"><?= e($publicUrl) ?></a>.
    </p>
    <pre class="code-block"><?= e((string) $waiver['body']) ?></pre>
</div>

<div class="card">
    <h2>Signatures (<?= e(number_format($total)) ?>)</h2>
    <?php if ($signatures === []): ?>
        <p class="empty">Nobody has signed this version.</p>
    <?php else: ?>
        <?php if ($total > count($signatures)): ?>
            <p class="muted">
                The <?= e((string) count($signatures)) ?> most recent of
                <?= e(number_format($total)) ?>.
            </p>
        <?php endif; ?>
        <div class="table-wrap">
            <table class="table">
                <caption class="sr-only">Who has signed this version</caption>
                <thead>
                    <tr>
                        <th scope="col">Who</th>
                        <th scope="col">Signed</th>
                        <th scope="col">Runs out</th>
                        <th scope="col">For</th>
                        <th scope="col">Party</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($signatures as $signature): ?>
                        <?php
                        $minors = json_decode((string) ($signature['minors'] ?? '[]'), true);
                        $minors = is_array($minors) ? $minors : [];
                        $expiry = (string) ($signature['expires_at'] ?? '');
                        $expired = $expiry !== '' && $expiry < gmdate('Y-m-d');
                        ?>
                        <tr>
                            <td>
                                <?= e((string) $signature['signer_name']) ?>
                                <?php if (trim((string) ($signature['signer_email'] ?? '')) !== ''): ?>
                                    <span class="td-sub"><?= e((string) $signature['signer_email']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= e(local_time((string) $signature['signed_at'])) ?></td>
                            <td>
                                <?php if ($expiry === ''): ?>
                                    <span class="muted">—</span>
                                <?php elseif ($expired): ?>
                                    <span class="badge badge-bad">Expired</span>
                                <?php else: ?>
                                    <?= e($expiry) ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($minors === []): ?>
                                    Themselves
                                <?php else: ?>
                                    <?= e((string) count($minors)) ?>
                                    child<?= count($minors) === 1 ? '' : 'ren' ?>
                                    <span class="td-sub">
                                        <?= e(implode(', ', array_map(
                                            static fn (array $m): string => (string) ($m['name'] ?? ''),
                                            $minors
                                        ))) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (($signature['booking_id'] ?? null) !== null): ?>
                                    <a href="<?= e(url('/bookings/' . (int) $signature['booking_id'])) ?>">
                                        <?= e((string) $signature['booking_number']) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="muted">Not a party</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if (!$live && !$signed): ?>
    <div class="card">
        <h2>Delete this draft</h2>
        <p class="muted">Nobody has signed it, so there is nothing to lose.</p>
        <form method="post" action="<?= e(url('/waivers/' . $id . '/delete')) ?>" class="form">
            <?= csrf_field() ?>
            <div class="form-actions">
                <button type="submit" class="btn btn-danger">Delete</button>
            </div>
        </form>
    </div>
<?php endif; ?>
