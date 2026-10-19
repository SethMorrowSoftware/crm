<?php

declare(strict_types=1);

/**
 * Every message the venue sends, by channel.
 *
 * @var list<array<string,mixed>> $templates
 */

$groups = ['email' => 'Email', 'sms' => 'Text messages', 'letter' => 'Letters'];
?>
<p class="page-lede">
    The words guests actually receive. A change applies to the next message sent; nothing already
    sent is touched. Every preview fills the details in with a sample family.
</p>

<?php foreach ($groups as $channel => $heading): ?>
    <?php $rows = array_values(array_filter($templates, static fn (array $t): bool => (string) $t['channel'] === $channel)); ?>
    <?php if ($rows === []) { continue; } ?>
    <section class="card">
        <div class="card-head">
            <div>
                <h2><?= e($heading) ?></h2>
                <p class="card-sub"><?= count($rows) ?> template<?= count($rows) === 1 ? '' : 's' ?>.</p>
            </div>
        </div>

        <div class="table-wrap">
            <table class="table">
                <caption class="sr-only"><?= e($heading) ?> templates</caption>
                <thead>
                    <tr>
                        <th scope="col">Template</th>
                        <th scope="col"><?= $channel === 'sms' ? 'Begins' : 'Subject' ?></th>
                        <th scope="col" class="td-right">Automations</th>
                        <th scope="col">State</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $template): ?>
                        <?php
                        $active = (int) $template['is_active'] === 1;
                        $first  = $channel === 'sms'
                            ? mb_strimwidth(trim((string) preg_replace('/\s+/', ' ', (string) $template['body'])), 0, 70, '…')
                            : (string) ($template['subject'] ?? '');
                        ?>
                        <tr class="<?= $active ? '' : 'row-muted' ?>">
                            <td>
                                <strong><?= e((string) $template['name']) ?></strong>
                                <?php if (($template['category'] ?? '') !== ''): ?>
                                    <span class="td-sub"><?= e(ucfirst(str_replace('_', ' ', (string) $template['category']))) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($first) ?></td>
                            <td class="td-right">
                                <?= (int) $template['used_by'] ?>
                                <?php if ((int) $template['live_use'] > 0): ?>
                                    <span class="td-sub"><?= (int) $template['live_use'] ?> switched on</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int) $template['is_system'] === 1): ?>
                                    <span class="badge">System</span>
                                <?php elseif ($active): ?>
                                    <span class="badge badge-ok">In use</span>
                                <?php else: ?>
                                    <span class="badge badge-muted">Off</span>
                                <?php endif; ?>
                            </td>
                            <td class="row-actions">
                                <a class="btn btn-ghost btn-sm" href="<?= e(url('/settings/templates/' . (int) $template['id'])) ?>">
                                    Edit<span class="sr-only"> <?= e((string) $template['name']) ?></span>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endforeach; ?>
