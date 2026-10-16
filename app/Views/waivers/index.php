<?php

declare(strict_types=1);

/**
 * Waiver versions.
 *
 * Ordered with the live one first, because that is the only one anybody is
 * signing. The signature count is on every row: it is what makes a version
 * undeletable, and seeing it is how an operator understands why.
 *
 * @var list<array<string,mixed>> $waivers
 * @var string                    $publicUrl
 * @var int                       $expiryDays
 */

?>
<div class="page-actions">
    <p class="page-lede">
        The wording guests agree to before they go on anything that needs a waiver. A published
        version can never be edited — its text is what people have already signed — so a change
        means a new version.
    </p>
    <a class="btn" href="<?= e(url('/waivers/new')) ?>">New version</a>
</div>

<?php if ($waivers === []): ?>
    <p class="empty">
        No waiver yet. Nothing on the public signing page until one is published.
        <a href="<?= e(url('/waivers/new')) ?>">Write the first one</a>.
    </p>
<?php else: ?>
    <div class="card">
        <p class="muted">
            Guests sign at <a href="<?= e($publicUrl) ?>"><?= e($publicUrl) ?></a> — give that
            address out, or add the booking number to it so the signature attaches to a party:
            <code><?= e($publicUrl) ?>?booking=B-2026-0001</code>.
            A signature stands for <?= e((string) $expiryDays) ?> days.
        </p>
    </div>

    <div class="table-wrap">
        <table class="table">
            <caption class="sr-only">Waiver versions, the live one first</caption>
            <thead>
                <tr>
                    <th scope="col">Version</th>
                    <th scope="col">Heading</th>
                    <th scope="col">State</th>
                    <th scope="col">Signatures</th>
                    <th scope="col">Published</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($waivers as $waiver): ?>
                    <tr>
                        <td>
                            <a href="<?= e(url('/waivers/' . (int) $waiver['id'])) ?>">
                                <?= e((string) $waiver['version']) ?>
                            </a>
                        </td>
                        <td>
                            <?= e((string) $waiver['title']) ?>
                            <?php if ((int) $waiver['requires_guardian'] === 1): ?>
                                <span class="td-sub">A guardian must sign for a child</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int) $waiver['is_active'] === 1): ?>
                                <span class="badge badge-ok">Live</span>
                            <?php elseif ($waiver['published_at'] !== null): ?>
                                <span class="badge badge-muted">Retired</span>
                            <?php else: ?>
                                <span class="badge badge-warn">Draft</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e(number_format((int) $waiver['signatures'])) ?></td>
                        <td>
                            <?= $waiver['published_at'] === null
                                ? '<span class="muted">—</span>'
                                : e(local_date((string) $waiver['published_at'])) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
