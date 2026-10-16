<?php

declare(strict_types=1);

/**
 * The campaign list, live ones first.
 *
 * @var list<array<string,mixed>> $campaigns
 * @var bool                      $canManage
 * @var bool                      $canSend
 */

$badge = static fn (string $status): string => match ($status) {
    'sending'   => 'badge-warn',
    'scheduled' => 'badge',
    'sent'      => 'badge-ok',
    'cancelled' => 'badge-bad',
    default     => 'badge-muted',
};

?>
<div class="page-actions">
    <p class="page-lede">
        One message to a lot of people. Every recipient still goes through the ordinary
        consent checks, so a campaign can never reach somebody a single reply could not.
    </p>
    <?php if ($canManage): ?>
        <a class="btn" href="<?= e(url('/campaigns/new')) ?>">New campaign</a>
    <?php endif; ?>
</div>

<?php if ($campaigns === []): ?>
    <div class="card">
        <p class="empty">
            No campaigns yet.
            <?php if ($canManage): ?>
                You will need a <a href="<?= e(url('/segments')) ?>">segment</a> first &mdash;
                that is who it goes to.
            <?php endif; ?>
        </p>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-wrap">
            <table>
                <caption class="sr-only"><?= e((string) count($campaigns)) ?> campaigns</caption>
                <thead>
                    <tr>
                        <th scope="col">Campaign</th>
                        <th scope="col">Channel</th>
                        <th scope="col">Segment</th>
                        <th scope="col">Sent</th>
                        <th scope="col">Cost</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($campaigns as $campaign): ?>
                        <?php
                        $status = (string) $campaign['status'];
                        $total  = (int) $campaign['recipients_total'];
                        $sent   = (int) $campaign['sent_count'];
                        ?>
                        <tr>
                            <th scope="row">
                                <a href="<?= e(url('/campaigns/' . (int) $campaign['id'])) ?>">
                                    <?= e((string) $campaign['name']) ?>
                                </a>
                                <span class="badge <?= e($badge($status)) ?>">
                                    <?= e(ucfirst($status)) ?>
                                </span>
                            </th>
                            <td><?= e((string) $campaign['channel'] === 'sms' ? 'Text' : 'Email') ?></td>
                            <td>
                                <?= $campaign['segment_name'] === null
                                    ? '<span class="muted">None chosen</span>'
                                    : e((string) $campaign['segment_name']) ?>
                            </td>
                            <td class="td-right">
                                <?php if ($campaign['started_at'] === null): ?>
                                    <span class="muted">&mdash;</span>
                                <?php else: ?>
                                    <?= e(number_format($sent)) ?>
                                    <?php if ($total > 0 && $sent < $total): ?>
                                        <span class="td-sub">of <?= e(number_format($total)) ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="td-right">
                                <?php
                                /*
                                 * The ACTUAL cost once it has sent, the
                                 * estimate before. A campaign that skipped
                                 * half its audience did not cost what it was
                                 * estimated to, and the figure a venue
                                 * reconciles against an invoice has to be the
                                 * real one.
                                 */
                                ?>
                                <?php if ($campaign['started_at'] === null): ?>
                                    <span class="muted">&mdash;</span>
                                <?php else: ?>
                                    <?= e(money((int) $campaign['actual_cost_cents'])) ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
