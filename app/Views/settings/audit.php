<?php

declare(strict_types=1);

/**
 * @var list<array<string,mixed>> $rows
 * @var int                       $total
 * @var int                       $page
 * @var int                       $lastPage
 * @var list<string>              $actions
 * @var list<array<string,mixed>> $users
 * @var array<string,string>      $filters
 */
?>
<p class="page-lede">
    Every change to guest data, every sign-in, every export.
    <?= number_format($total) ?> entr<?= $total === 1 ? 'y' : 'ies' ?>.
    Read-only by design — nothing in Castle CRM edits or deletes these rows.
</p>

<form method="get" action="<?= e(url('/settings/audit')) ?>" class="card filter-bar">
    <div class="field">
        <label for="action">Action</label>
        <select id="action" name="action">
            <option value="">Any</option>
            <?php foreach ($actions as $action): ?>
                <option value="<?= e($action) ?>" <?= $filters['action'] === $action ? 'selected' : '' ?>>
                    <?= e($action) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="field">
        <label for="user_id">Who</label>
        <select id="user_id" name="user_id">
            <option value="">Anyone</option>
            <?php foreach ($users as $user): ?>
                <option value="<?= (int) $user['id'] ?>"
                    <?= $filters['user_id'] === (string) $user['id'] ? 'selected' : '' ?>>
                    <?= e($user['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="field">
        <label for="from">From</label>
        <input type="date" id="from" name="from" value="<?= e($filters['from']) ?>">
    </div>

    <div class="field">
        <label for="to">To</label>
        <input type="date" id="to" name="to" value="<?= e($filters['to']) ?>">
    </div>

    <div class="field field-actions">
        <button type="submit" class="btn btn-sm">Filter</button>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('/settings/audit')) ?>">Clear</a>
        <?php if (can('reports_export')): ?>
            <a class="btn btn-ghost btn-sm"
               href="<?= e(url('/settings/audit/export?' . http_build_query($filters))) ?>">
                <?= icon('download', 15) ?> CSV
            </a>
        <?php endif; ?>
    </div>
</form>

<section class="card">
    <?php if ($rows === []): ?>
        <p class="empty">Nothing matches those filters.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <caption class="sr-only">Audit log</caption>
                <thead>
                <tr><th scope="col">When</th><th scope="col">Who</th><th scope="col">Action</th><th scope="col">Record</th><th scope="col">Changes</th><th scope="col">IP</th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php $changes = json_decode((string) ($row['changes'] ?? ''), true); ?>
                    <tr>
                        <td title="<?= e(local_time((string) $row['created_at'], 'Y-m-d H:i:s')) ?>">
                            <?= e(local_time((string) $row['created_at'], 'M j, g:i A')) ?>
                        </td>
                        <td><?= e($row['user_name'] ?? 'System') ?></td>
                        <td><code class="code-inline"><?= e($row['action']) ?></code></td>
                        <td>
                            <?php if (!empty($row['entity_type'])): ?>
                                <?= e($row['entity_type']) ?><?= $row['entity_id'] !== null
                                    ? ' #' . (int) $row['entity_id'] : '' ?>
                            <?php else: ?>
                                <span class="muted">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (is_array($changes) && $changes !== []): ?>
                                <details>
                                    <summary><?= count($changes) ?> field<?= count($changes) === 1 ? '' : 's' ?></summary>
                                    <ul class="change-list">
                                        <?php foreach ($changes as $field => $change): ?>
                                            <li>
                                                <strong><?= e($field) ?></strong>
                                                <?php if (is_array($change) && array_key_exists('from', $change)): ?>
                                                    <?php
                                                    /*
                                                     * A VALUE MAY BE STRUCTURED, and casting one
                                                     * to a string is a fatal — "Array to string
                                                     * conversion" took the whole audit page to a
                                                     * 500 the first time a settings save recorded
                                                     * a nested value. The audit viewer must never
                                                     * be the thing that breaks because something
                                                     * was logged: it is what you go to when
                                                     * everything else is already confusing.
                                                     */
                                                    $readable = static function (mixed $value): string {
                                                        if ($value === null || is_scalar($value)) {
                                                            return (string) $value;
                                                        }

                                                        return (string) json_encode(
                                                            $value,
                                                            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                                                        );
                                                    };
                                                    ?>
                                                    <span class="muted"><?= e(str_truncate($readable($change['from'] ?? ''), 40)) ?></span>
                                                    &rarr;
                                                    <span><?= e(str_truncate($readable($change['to'] ?? ''), 40)) ?></span>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </details>
                            <?php else: ?>
                                <span class="muted">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td class="muted"><?= e($row['ip'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($lastPage > 1): ?>
            <nav class="pagination" aria-label="Pages">
                <?php
                $query = static fn (int $p): string =>
                    e(url('/settings/audit?' . http_build_query(array_merge($filters, ['page' => $p]))));
                ?>
                <?php if ($page > 1): ?>
                    <a class="btn btn-ghost btn-sm" href="<?= $query($page - 1) ?>">Previous</a>
                <?php endif; ?>
                <span class="pagination-info">Page <?= (int) $page ?> of <?= (int) $lastPage ?></span>
                <?php if ($page < $lastPage): ?>
                    <a class="btn btn-ghost btn-sm" href="<?= $query($page + 1) ?>">Next</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
