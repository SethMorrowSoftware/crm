<?php

declare(strict_types=1);

/**
 * The review inbox.
 *
 * Castle CRM cannot post a reply to Google or Yelp — that needs each
 * platform's own API, and Google's needs a verified listing. So the reply box
 * here RECORDS the reply and the link takes you to the review to paste it.
 * The page says so plainly rather than implying the reply was published.
 *
 * @var list<array<string,mixed>> $rows
 * @var int                       $total
 * @var string                    $filter
 * @var array{page:int,perPage:int,offset:int} $page
 * @var list<string>              $platforms
 * @var array<string,int>         $counts
 * @var list<array<string,mixed>> $drafts
 * @var array{review_id:int,body:string}|null $draft
 * @var bool                      $canReply
 * @var string                    $wallUrl
 */
?>

<section class="card">
    <div class="card-head">
        <div>
            <h2>Reviews</h2>
            <p class="card-sub">
                <?= (int) $counts['all'] ?> held here,
                <?= (int) $counts['unreplied'] ?> with no reply logged,
                <?= (int) $counts['featured'] ?> featured on
                <a href="<?= e($wallUrl) ?>">the review wall</a>.
            </p>
        </div>
        <a class="btn btn-ghost" href="<?= e(url('/reputation')) ?>">Back to reputation</a>
    </div>

    <div class="chip-bar">
        <?php
        $chips = ['all' => 'All', 'unreplied' => 'No reply yet', 'negative' => 'One and two stars', 'featured' => 'Featured'];

        foreach ($platforms as $platform) {
            if (($counts[$platform] ?? 0) > 0) {
                $chips[$platform] = App\Services\Reputation::platformName($platform);
            }
        }
        ?>
        <?php foreach ($chips as $value => $label): ?>
            <a class="chip <?= $filter === $value ? 'is-on' : '' ?>"
               href="<?= e(url('/reputation/reviews?show=' . urlencode((string) $value))) ?>">
                <?= e($label) ?>
                <?php if (isset($counts[$value])): ?>
                    <span class="chip-count"><?= (int) $counts[$value] ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($rows === []): ?>
        <p class="empty">
            Nothing here yet.
            <?php if ($canReply): ?>
                Reviews arrive by import &mdash; see the bottom of this page.
            <?php endif; ?>
        </p>
    <?php else: ?>
        <ul class="review-list">
            <?php foreach ($rows as $review): ?>
                <li class="review-item" id="review-<?= (int) $review['id'] ?>">
                    <div class="review-meta">
                        <span class="rating" aria-label="<?= (int) $review['rating'] ?> out of 5 stars">
                            <?= str_repeat('&#9733;', (int) $review['rating']) ?><?php
                            ?><span class="rating-off"><?= str_repeat('&#9733;', 5 - (int) $review['rating']) ?></span>
                        </span>
                        <strong><?= e((string) ($review['author_name'] ?? 'Anonymous')) ?></strong>
                        <span class="muted">
                            <?= e(App\Services\Reputation::platformName((string) $review['platform'])) ?>
                            <?php if (($review['posted_at'] ?? null) !== null): ?>
                                &middot; <?= e(local_date((string) $review['posted_at'])) ?>
                            <?php endif; ?>
                        </span>
                        <?php if ((int) $review['is_featured'] === 1): ?>
                            <span class="badge badge-ok">featured</span>
                        <?php endif; ?>
                        <?php if (($review['replied_at'] ?? null) !== null): ?>
                            <span class="badge">replied</span>
                        <?php endif; ?>
                    </div>

                    <p><?= nl2br(e((string) ($review['body'] ?? ''))) ?: '<span class="muted">No text.</span>' ?></p>

                    <?php if (($review['replied_at'] ?? null) !== null): ?>
                        <blockquote class="review-reply">
                            <p><?= nl2br(e((string) ($review['reply_body'] ?? ''))) ?></p>
                            <footer class="muted">
                                logged <?= e(local_date((string) $review['replied_at'], 'M j, Y')) ?>
                                <?php if (($review['replier_name'] ?? null) !== null): ?>
                                    by <?= e((string) $review['replier_name']) ?>
                                <?php endif; ?>
                            </footer>
                        </blockquote>
                    <?php endif; ?>

                    <div class="review-actions">
                        <?php if (($review['review_url'] ?? null) !== null): ?>
                            <a class="btn btn-sm btn-ghost" target="_blank" rel="noopener noreferrer"
                               href="<?= e((string) $review['review_url']) ?>">Open on
                                <?= e(App\Services\Reputation::platformName((string) $review['platform'])) ?></a>
                        <?php endif; ?>

                        <?php if ($canReply): ?>
                            <form method="post"
                                  action="<?= e(url('/reputation/reviews/' . (int) $review['id'] . '/feature')) ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-ghost">
                                    <?= (int) $review['is_featured'] === 1 ? 'Take off the wall' : 'Feature on the wall' ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <?php if ($canReply): ?>
                        <?php
                        $isDrafting = $draft !== null && $draft['review_id'] === (int) $review['id'];
                        $value = $isDrafting ? $draft['body'] : (string) ($review['reply_body'] ?? '');
                        ?>
                        <details class="review-write" <?= $isDrafting ? 'open' : '' ?>>
                            <summary><?= ($review['replied_at'] ?? null) === null ? 'Write a reply' : 'Edit the logged reply' ?></summary>

                            <?php if ($drafts !== [] && !$isDrafting): ?>
                                <p class="field-hint">
                                    Start from:
                                    <?php foreach ($drafts as $i => $d): ?>
                                        <?= $i > 0 ? ' &middot; ' : '' ?>
                                        <a href="<?= e(url('/reputation/reviews?show=' . urlencode($filter)
                                            . '&page=' . (int) $page['page']
                                            . '&draft=' . urlencode((string) $d['slug'])
                                            . '&review=' . (int) $review['id']
                                            . '#review-' . (int) $review['id'])) ?>"><?= e((string) $d['name']) ?></a>
                                    <?php endforeach; ?>
                                </p>
                            <?php endif; ?>

                            <form method="post"
                                  action="<?= e(url('/reputation/reviews/' . (int) $review['id'] . '/reply')) ?>">
                                <?= csrf_field() ?>
                                <div class="field">
                                    <label for="reply-<?= (int) $review['id'] ?>">Your reply</label>
                                    <textarea id="reply-<?= (int) $review['id'] ?>" name="reply_body" rows="4"
                                              required><?= e($value) ?></textarea>
                                    <p class="field-hint">
                                        Saving records the reply here. Posting it is still done on
                                        <?= e(App\Services\Reputation::platformName((string) $review['platform'])) ?>
                                        &mdash; use the link above.
                                    </p>
                                </div>
                                <button type="submit" class="btn btn-sm">Log the reply</button>
                            </form>
                        </details>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($total > $page['perPage']): ?>
            <nav class="pagination" aria-label="Pages">
                <?php if ($page['page'] > 1): ?>
                    <a class="btn btn-ghost btn-sm"
                       href="<?= e(url('/reputation/reviews?show=' . urlencode($filter) . '&page=' . ($page['page'] - 1))) ?>">
                        Previous</a>
                <?php endif; ?>
                <span class="muted">Page <?= (int) $page['page'] ?>
                    of <?= (int) ceil($total / $page['perPage']) ?></span>
                <?php if ($page['offset'] + $page['perPage'] < $total): ?>
                    <a class="btn btn-ghost btn-sm"
                       href="<?= e(url('/reputation/reviews?show=' . urlencode($filter) . '&page=' . ($page['page'] + 1))) ?>">
                        Next</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php if ($canReply): ?>
    <section class="card">
        <div class="card-head">
            <div>
                <h2>Import reviews</h2>
                <p class="card-sub">
                    Paste a CSV, or choose a file. One row per review; a header row naming
                    the columns.
                </p>
            </div>
        </div>

        <form method="post" action="<?= e(url('/reputation/reviews/import')) ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>

            <div class="field">
                <label for="platform">Where these came from</label>
                <select id="platform" name="platform">
                    <?php foreach ($platforms as $platform): ?>
                        <option value="<?= e($platform) ?>">
                            <?= e(App\Services\Reputation::platformName($platform)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="field-hint">Used for any row whose own platform column is missing or unrecognised.</p>
            </div>

            <div class="field">
                <label for="csv">Paste the CSV</label>
                <textarea id="csv" name="csv" rows="6"
                          placeholder="author,rating,body,date,url&#10;Dana P.,5,&quot;Best party we have had&quot;,2026-09-14,https://..."></textarea>
                <p class="field-hint">
                    Recognised column names: author, rating (or stars), body (or review),
                    date, url, platform, id. Anything else is ignored, and every row that
                    cannot be used is reported rather than skipped quietly.
                </p>
            </div>

            <div class="field">
                <label for="file">Or choose a file</label>
                <input type="file" id="file" name="file" accept=".csv,text/csv,text/plain">
            </div>

            <button type="submit" class="btn">Import</button>
        </form>
    </section>
<?php endif; ?>
