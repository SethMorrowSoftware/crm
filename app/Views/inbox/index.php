<?php

declare(strict_types=1);

/**
 * The inbox queue.
 *
 * Unread first, then most recent — because the question somebody opens this
 * page to answer is "who is waiting", and a conversation nobody has looked at
 * outranks one that has been read and left. The order comes from the
 * controller's ORDER BY, never from a sort made here, so the badge and the row
 * position cannot disagree.
 *
 * @var list<array<string,mixed>> $conversations
 * @var string                    $filter
 * @var array<string,string>      $filters
 * @var array<string,int>         $counts
 * @var string                    $channel
 * @var list<string>              $channels
 * @var string                    $search
 * @var int                       $page
 * @var int                       $pages
 * @var int                       $total
 * @var callable                  $fmtWhen
 */

$blurb = [
    'mine'       => 'Everything assigned to you that is still in the queue.',
    'unassigned' => 'In the queue and nobody has picked it up.',
    'unread'     => 'Nobody has opened these yet.',
    'open'       => 'Open conversations, whoever they belong to.',
    'snoozed'    => 'Put aside until a time somebody chose. They come back on their own.',
    'closed'     => 'Dealt with. A guest writing again reopens one.',
    'all'        => 'Every conversation you can see.',
];

/** The current query with one key changed, so switching a tab keeps the rest. */
$withFilter = static function (string $target) use ($channel, $search): string {
    $query = array_filter([
        'filter'  => $target,
        'channel' => $channel,
        'q'       => $search,
    ], static fn (string $value): bool => $value !== '');

    return '/inbox' . ($query === [] ? '' : '?' . http_build_query($query));
};

?>
<div class="page-actions">
    <p class="page-lede"><?= e($blurb[$filter] ?? '') ?></p>
</div>

<nav class="row-actions inbox-tabs" aria-label="Inbox views">
    <?php foreach ($filters as $key => $label): ?>
        <?php $count = $counts[$key] ?? 0; ?>
        <a class="btn btn-sm <?= $filter === $key ? '' : 'btn-ghost' ?>"
           href="<?= e(url($withFilter($key))) ?>"
           <?= $filter === $key ? 'aria-current="page"' : '' ?>>
            <?= e($label) ?>
            <?php if ($count > 0): ?>
                <span class="badge badge-muted"><?= e(number_format($count)) ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>

<div class="card filter-bar">
    <form method="get" action="<?= e(url('/inbox')) ?>" class="inline-form">
        <input type="hidden" name="filter" value="<?= e($filter) ?>">

        <div class="field">
            <label for="q">Search</label>
            <input type="search" id="q" name="q" value="<?= e($search) ?>"
                   placeholder="Name, email, phone or subject">
        </div>

        <div class="field">
            <label for="channel">Channel</label>
            <select id="channel" name="channel">
                <option value="">Any channel</option>
                <?php foreach ($channels as $option): ?>
                    <option value="<?= e($option) ?>" <?= $channel === $option ? 'selected' : '' ?>>
                        <?= e(ucfirst($option)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-sm">Filter</button>
            <?php if ($search !== '' || $channel !== ''): ?>
                <a class="btn btn-sm btn-ghost" href="<?= e(url('/inbox?filter=' . $filter)) ?>">Clear</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if ($conversations === []): ?>
    <div class="card">
        <p class="empty">
            <?php if ($search !== '' || $channel !== ''): ?>
                Nothing matches that. Try clearing the filters.
            <?php elseif ($filter === 'mine'): ?>
                Nothing is waiting for you.
            <?php else: ?>
                Nothing here.
            <?php endif; ?>
        </p>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-wrap">
            <table>
                <caption class="sr-only">
                    <?= e(number_format($total)) ?> conversations, unread first
                </caption>
                <thead>
                    <tr>
                        <th scope="col">Guest</th>
                        <th scope="col">Last message</th>
                        <th scope="col">Channel</th>
                        <th scope="col">Assigned</th>
                        <th scope="col">When</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($conversations as $row): ?>
                        <?php
                        $unread  = (int) $row['is_unread'] === 1;
                        $status  = (string) $row['status'];
                        ?>
                        <tr<?= $unread ? ' class="inbox-unread"' : '' ?>>
                            <th scope="row">
                                <a href="<?= e(url('/inbox/' . (int) $row['id'])) ?>">
                                    <?= e((string) $row['display_name']) ?>
                                </a>
                                <?php if ($unread): ?>
                                    <span class="badge">Unread</span>
                                <?php endif; ?>
                                <?php if ($status === 'snoozed'): ?>
                                    <span class="badge badge-muted">Snoozed</span>
                                <?php elseif ($status === 'closed'): ?>
                                    <span class="badge badge-muted">Closed</span>
                                <?php endif; ?>
                                <?php if ((int) ($row['do_not_contact'] ?? 0) === 1): ?>
                                    <span class="badge badge-bad">Do not contact</span>
                                <?php endif; ?>
                            </th>
                            <td class="inbox-preview">
                                <?php if ((string) ($row['last_direction'] ?? '') === 'outbound'): ?>
                                    <span class="muted">You:</span>
                                <?php endif; ?>
                                <?= e((string) $row['preview']) ?>
                            </td>
                            <td>
                                <?php
                                /*
                                 * The icon name is a LITERAL at the call,
                                 * not returned from a helper. `icon()`
                                 * renders nothing for a name it does not
                                 * know — the right failure for a decorative
                                 * glyph, and a silent one for whoever wrote
                                 * the view — so the static gate checks every
                                 * name a view asks for, and it can only see
                                 * the ones written here.
                                 */
                                ?>
                                <?php if ((string) $row['channel'] === 'sms'): ?>
                                    <?= icon('message') ?>
                                <?php elseif ((string) $row['channel'] === 'internal'): ?>
                                    <?= icon('edit') ?>
                                <?php else: ?>
                                    <?= icon('inbox') ?>
                                <?php endif; ?>
                                <?= e(ucfirst((string) $row['channel'])) ?>
                            </td>
                            <td>
                                <?= $row['assignee_name'] === null
                                    ? '<span class="muted">Nobody</span>'
                                    : e((string) $row['assignee_name']) ?>
                            </td>
                            <td><?= e($fmtWhen($row['last_message_at'] ?? null)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($pages > 1): ?>
        <nav class="pagination" aria-label="Pages">
            <?php if ($page > 1): ?>
                <a class="btn btn-ghost btn-sm"
                   href="<?= e(url($withFilter($filter) . (str_contains($withFilter($filter), '?') ? '&' : '?') . 'page=' . ($page - 1))) ?>">
                    Previous
                </a>
            <?php endif; ?>

            <span class="pagination-info">Page <?= e((string) $page) ?> of <?= e((string) $pages) ?></span>

            <?php if ($page < $pages): ?>
                <a class="btn btn-ghost btn-sm"
                   href="<?= e(url($withFilter($filter) . (str_contains($withFilter($filter), '?') ? '&' : '?') . 'page=' . ($page + 1))) ?>">
                    Next
                </a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
