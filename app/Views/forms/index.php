<?php

declare(strict_types=1);

/**
 * @var list<array<string,mixed>> $forms
 * @var bool                      $mayManage
 */
?>
<div class="page-actions">
    <p class="page-lede">
        A form is a page on the open internet. Everything on it is answered by somebody with no
        account, so keep the questions to what the venue will actually act on — and remember that
        only a ticked opt-in box is permission to market to them.
    </p>
    <?php if ($mayManage): ?>
        <a class="btn" href="<?= e(url('/forms/new')) ?>"><?= icon('plus', 16) ?> Build a form</a>
    <?php endif; ?>
</div>

<?php if ($forms === []): ?>
    <section class="card">
        <p class="empty">
            No forms yet.
            <?= $mayManage
                ? 'Build one and it gets its own public address at /f/your-slug.'
                : 'Somebody with form permissions can build one.' ?>
        </p>
    </section>
<?php else: ?>
    <section class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Form</th>
                        <th scope="col">Public address</th>
                        <th scope="col">Questions</th>
                        <th scope="col">Submissions</th>
                        <th scope="col">State</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($forms as $form): ?>
                    <tr<?= (int) $form['is_active'] === 0 ? ' class="row-muted"' : '' ?>>
                        <td>
                            <strong><?= e($form['name']) ?></strong>
                            <?php if ((string) ($form['title'] ?? '') !== ''): ?>
                                <span class="td-sub"><?= e($form['title']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int) $form['is_active'] === 1): ?>
                                <a href="<?= e(url('/f/' . $form['slug'])) ?>">/f/<?= e($form['slug']) ?></a>
                            <?php else: ?>
                                <span class="muted">/f/<?= e($form['slug']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= (int) $form['field_count'] ?></td>
                        <td>
                            <a href="<?= e(url('/forms/' . (int) $form['id'] . '/submissions')) ?>">
                                <?= (int) $form['real_count'] ?>
                            </a>
                            <?php if ((int) $form['spam_count'] > 0): ?>
                                <span class="td-sub"><?= (int) $form['spam_count'] ?> held as spam</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int) $form['is_active'] === 1): ?>
                                <span class="badge badge-ok">Live</span>
                            <?php else: ?>
                                <span class="badge badge-muted">Off</span>
                            <?php endif; ?>
                        </td>
                        <td class="td-right">
                            <?php if ($mayManage): ?>
                                <a class="btn btn-ghost btn-sm"
                                   href="<?= e(url('/forms/' . (int) $form['id'])) ?>">Edit</a>
                            <?php else: ?>
                                <a class="btn btn-ghost btn-sm"
                                   href="<?= e(url('/forms/' . (int) $form['id'] . '/submissions')) ?>">View</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
