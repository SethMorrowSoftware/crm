<?php

declare(strict_types=1);

/**
 * The segment list.
 *
 * Every count is shown WITH its age, never bare. A segment counted in March
 * and sent to in July is a different list, and a number with no date on it
 * invites somebody to trust it.
 *
 * @var list<array<string,mixed>> $segments
 * @var bool                      $canManage
 */

?>
<div class="page-actions">
    <p class="page-lede">
        A segment is a saved question about the guest list. Nothing here sends anything &mdash;
        a campaign points at a segment, and the rules are re-run when it does.
    </p>
    <?php if ($canManage): ?>
        <a class="btn" href="<?= e(url('/segments/new')) ?>">New segment</a>
    <?php endif; ?>
</div>

<?php if ($segments === []): ?>
    <div class="card">
        <p class="empty">
            No segments yet.
            <?php if ($canManage): ?>
                Build one to answer a question like &ldquo;consented guests with a July birthday
                child who have not been in since the spring&rdquo;.
            <?php endif; ?>
        </p>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <caption class="sr-only"><?= e((string) count($segments)) ?> segments</caption>
                <thead>
                    <tr>
                        <th scope="col">Segment</th>
                        <th scope="col">Conditions</th>
                        <th scope="col">Matched</th>
                        <th scope="col">Counted</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($segments as $segment): ?>
                        <tr>
                            <th scope="row">
                                <a href="<?= e(url('/segments/' . (int) $segment['id'])) ?>">
                                    <?= e((string) $segment['name']) ?>
                                </a>
                                <?php if (!$segment['compiles']): ?>
                                    <span class="badge badge-bad">Will not run</span>
                                <?php endif; ?>
                                <?php if (($segment['description'] ?? '') !== ''): ?>
                                    <span class="td-sub"><?= e((string) $segment['description']) ?></span>
                                <?php endif; ?>
                            </th>
                            <td class="segment-rules">
                                <?php if ($segment['compiles']): ?>
                                    <?= e((string) $segment['described']) ?>
                                <?php else: ?>
                                    <span class="field-error"><?= e((string) $segment['rule_error']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="td-right">
                                <?php if ($segment['cached_count'] === null): ?>
                                    <span class="muted">Not counted</span>
                                <?php else: ?>
                                    <?= e(number_format((int) $segment['cached_count'])) ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($segment['counted_at'] === null): ?>
                                    <span class="muted">&mdash;</span>
                                <?php else: ?>
                                    <?= e(time_ago((string) $segment['counted_at'])) ?>
                                    <?php if ($segment['count_stale']): ?>
                                        <span class="badge badge-warn">Stale</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
