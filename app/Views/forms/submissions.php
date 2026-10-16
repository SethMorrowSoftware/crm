<?php

declare(strict_types=1);

/**
 * @var array<string,mixed>       $form
 * @var list<array<string,mixed>> $rows
 * @var int                       $total
 * @var int                       $page
 * @var int                       $perPage
 * @var int                       $lastPage
 */
?>
<p class="breadcrumbs"><a href="<?= e(url('/forms')) ?>">Web forms</a> /
    <?= e($form['name']) ?> / Submissions</p>

<div class="page-actions">
    <p class="page-lede">
        <?= (int) $total ?> submission<?= (int) $total === 1 ? '' : 's' ?> through
        <code class="code-inline">/f/<?= e($form['slug']) ?></code>.
        Anything the honeypot caught is listed too, marked as spam — kept rather than discarded so
        a real enquiry wrongly caught can still be read.
    </p>
    <?php if (can('forms_manage')): ?>
        <a class="btn btn-ghost" href="<?= e(url('/forms/' . (int) $form['id'])) ?>">
            <?= icon('edit', 16) ?> Edit the form
        </a>
    <?php endif; ?>
</div>

<?php if ($rows === []): ?>
    <section class="card">
        <p class="empty">Nothing has been submitted yet.</p>
    </section>
<?php else: ?>
    <section class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Received</th>
                        <th scope="col">Contact</th>
                        <th scope="col">First answers</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php $isSpam = (int) $row['is_spam'] === 1; ?>
                    <tr<?= $isSpam ? ' class="row-muted"' : '' ?>>
                        <td>
                            <?= e(local_time((string) $row['created_at'])) ?>
                            <?php if ($isSpam): ?>
                                <span class="td-sub"><span class="badge badge-warn">Spam</span></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['contact_id'] !== null): ?>
                                <strong><?= e(trim(
                                    (string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')
                                ) ?: 'Contact #' . (int) $row['contact_id']) ?></strong>
                                <?php if ((string) ($row['email'] ?? '') !== ''): ?>
                                    <span class="td-sub"><?= e($row['email']) ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="muted">No contact</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e(str_truncate($row['summary'], 70)) ?></td>
                        <td class="td-right">
                            <a class="btn btn-ghost btn-sm"
                               href="<?= e(url('/forms/' . (int) $form['id'] . '/submissions/' . (int) $row['id'])) ?>">
                                Read
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if ($lastPage > 1): ?>
        <nav class="pagination" aria-label="Submission pages">
            <?php if ($page > 1): ?>
                <a class="btn btn-ghost btn-sm"
                   href="<?= e(url('/forms/' . (int) $form['id'] . '/submissions?page=' . ($page - 1))) ?>">
                    Newer
                </a>
            <?php endif; ?>
            <span class="pagination-info">Page <?= (int) $page ?> of <?= (int) $lastPage ?></span>
            <?php if ($page < $lastPage): ?>
                <a class="btn btn-ghost btn-sm"
                   href="<?= e(url('/forms/' . (int) $form['id'] . '/submissions?page=' . ($page + 1))) ?>">
                    Older
                </a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
