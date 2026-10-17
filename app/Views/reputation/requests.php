<?php

declare(strict_types=1);

/**
 * Every review ask, and what became of it.
 *
 * @var list<array<string,mixed>> $rows
 * @var int                       $total
 * @var array{page:int,perPage:int,offset:int} $page
 * @var array<string,mixed>       $funnel
 * @var array<string,mixed>       $settings
 */
?>

<section class="card">
    <div class="card-head">
        <div>
            <h2>Review asks</h2>
            <p class="card-sub">
                <?= (int) $total ?> in total.
                <?php if ($settings['ask_everyone']): ?>
                    Happy and lukewarm guests are both asked.
                <?php else: ?>
                    Only guests who scored high enough to be promoters are asked.
                <?php endif; ?>
            </p>
        </div>
        <a class="btn btn-ghost" href="<?= e(url('/reputation')) ?>">Back to reputation</a>
    </div>

    <dl class="definition-list">
        <dt>Asked in the last year</dt>
        <dd><?= (int) $funnel['sent'] ?></dd>
        <dt>Clicked</dt>
        <dd><?= (int) $funnel['clicked'] ?><?= $funnel['click_rate'] === null
            ? '' : ' (' . e((string) $funnel['click_rate']) . '%)' ?></dd>
        <dt>Confirmed as a review</dt>
        <dd>
            <?= (int) $funnel['reviewed'] ?>
            <span class="muted">
                &mdash; a click is recorded automatically; a review is only counted when
                somebody matches it to an imported review.
            </span>
        </dd>
    </dl>

    <?php if ($rows === []): ?>
        <p class="empty">No asks yet.</p>
    <?php else: ?>
        <!-- Wrapped so a wide table scrolls inside its own box rather than
             dragging the whole layout sideways on a phone. MEASURED at 390px. -->
        <div class="table-scroll">
        <table class="table">
            <caption class="sr-only">Review asks</caption>
            <thead>
                <tr>
                    <th scope="col">Guest</th>
                    <th scope="col">Score</th>
                    <th scope="col">Platform</th>
                    <th scope="col">State</th>
                    <th scope="col">Asked</th>
                    <th scope="col">Clicked</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td>
                            <?= e(trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''))) ?: 'A guest' ?>
                            <?php if (($row['booking_number'] ?? null) !== null): ?>
                                <br><span class="muted"><?= e((string) $row['booking_number']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= ($row['score'] ?? null) === null ? '&mdash;' : (int) $row['score'] ?></td>
                        <td><?= e(App\Services\Reputation::platformName((string) $row['platform'])) ?></td>
                        <td><?= e((string) $row['status']) ?></td>
                        <td><?= e(local_date((string) $row['created_at'], 'M j, g:ia')) ?></td>
                        <td><?= ($row['clicked_at'] ?? null) === null
                            ? '<span class="muted">&mdash;</span>'
                            : e(local_date((string) $row['clicked_at'], 'M j, g:ia')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <?php if ($total > $page['perPage']): ?>
            <nav class="pagination" aria-label="Pages">
                <?php if ($page['page'] > 1): ?>
                    <a class="btn btn-ghost btn-sm"
                       href="<?= e(url('/reputation/requests?page=' . ($page['page'] - 1))) ?>">Previous</a>
                <?php endif; ?>
                <span class="muted">Page <?= (int) $page['page'] ?>
                    of <?= (int) ceil($total / $page['perPage']) ?></span>
                <?php if ($page['offset'] + $page['perPage'] < $total): ?>
                    <a class="btn btn-ghost btn-sm"
                       href="<?= e(url('/reputation/requests?page=' . ($page['page'] + 1))) ?>">Next</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
