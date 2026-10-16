<?php

declare(strict_types=1);

/**
 * Reputation: how guests rate us, and what needs somebody now.
 *
 * Ordered by urgency rather than by prettiness: open complaints first,
 * because every hour one sits here is an hour a family is waiting, then the
 * reviews nobody has answered, then the trend.
 *
 * @var int                       $days
 * @var array<int,string>         $ranges
 * @var array<string,mixed>       $window
 * @var array<string,mixed>       $summary
 * @var array<string,mixed>       $priorSummary
 * @var list<array<string,mixed>> $trend
 * @var list<array<string,mixed>> $byHost
 * @var array<string,mixed>       $funnel
 * @var list<array<string,mixed>> $detractors
 * @var list<array<string,mixed>> $needReply
 * @var array<string,mixed>       $reviewStats
 * @var array<string,int>         $uninvited
 * @var array<string,mixed>       $settings
 * @var list<array<string,mixed>> $surveys
 * @var bool                      $canResolve
 * @var bool                      $canReply
 */

$npsDelta = $summary['nps'] !== null && $priorSummary['nps'] !== null
    ? round((float) $summary['nps'] - (float) $priorSummary['nps'], 1)
    : null;

$activeSurveys = array_values(array_filter(
    $surveys,
    static fn (array $s): bool => (int) $s['is_active'] === 1 && ($s['trigger_event'] ?? null) !== null
));

$reasonWords = [
    'no_address'    => 'no email address on file',
    'no_number'     => 'no mobile number on file',
    'no_consent'    => 'no marketing consent',
    'suppressed'    => 'unsubscribed or on the suppression list',
    'do_not_contact' => 'marked do not contact',
    'no_template'   => 'the invitation template is missing',
    'invalid_address' => 'the address is not deliverable',
    'invalid_number' => 'the number is not deliverable',
    'gave_up'       => 'the provider kept refusing it',
    'frequency_cap' => 'they had already had their messages for the week',
];
?>

<?php if ($activeSurveys === []): ?>
    <div class="alert alert-warning">
        <?= icon('alert') ?>
        <div>
            <p><strong>No survey is running, so nobody is being asked.</strong>
                Everything below will stay empty until one is switched on.</p>
            <p><a class="btn btn-ghost" href="<?= e(url('/reputation/surveys')) ?>">Set up feedback</a></p>
        </div>
    </div>
<?php endif; ?>

<section class="card">
    <div class="card-head">
        <div>
            <h2>Reputation</h2>
            <p class="card-sub">
                <?= e(local_date($window['from'], 'M j')) ?> &ndash;
                <?= e(local_date($window['to'], 'M j, Y')) ?>.
                Scores are shown on the day the guest answered.
            </p>
        </div>

        <?php /*
         * A select and a real button, not a select that submits itself: the
         * Content-Security-Policy forbids inline script, so an `onchange`
         * handler here would never run at all — the control would look
         * interactive and do nothing. A button also keeps it operable by
         * keyboard without committing on every arrow key.
         */ ?>
        <form method="get" action="<?= e(url('/reputation')) ?>" class="form-inline">
            <label class="sr-only" for="days">Period</label>
            <select id="days" name="days">
                <?php foreach ($ranges as $value => $label): ?>
                    <option value="<?= (int) $value ?>" <?= $days === (int) $value ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-ghost btn-sm">Show</button>
        </form>
    </div>

    <div class="stat-row">
        <div class="stat">
            <span class="stat-label">Net promoter score</span>
            <strong class="stat-value">
                <?= $summary['nps'] === null ? '&mdash;' : e((string) $summary['nps']) ?>
            </strong>
            <span class="stat-note">
                <?php if ($summary['answered'] === 0): ?>
                    nobody has answered yet
                <?php elseif ($npsDelta === null): ?>
                    from <?= (int) $summary['answered'] ?> answers
                <?php else: ?>
                    <?= $npsDelta >= 0 ? '+' : '' ?><?= e((string) $npsDelta) ?> on the period before
                <?php endif; ?>
            </span>
        </div>

        <div class="stat">
            <span class="stat-label">Answered</span>
            <strong class="stat-value"><?= (int) $summary['answered'] ?></strong>
            <span class="stat-note">
                <?php if ($summary['response_rate'] === null): ?>
                    of <?= (int) $summary['invited'] ?> asked
                <?php else: ?>
                    <?= e((string) $summary['response_rate']) ?>% of <?= (int) $summary['invited'] ?> asked
                <?php endif; ?>
            </span>
        </div>

        <div class="stat">
            <span class="stat-label">Promoters</span>
            <strong class="stat-value"><?= (int) $summary['promoters'] ?></strong>
            <span class="stat-note">
                <?= (int) $summary['passives'] ?> passive,
                <?= (int) $summary['detractors'] ?> unhappy
            </span>
        </div>

        <div class="stat <?= (int) $summary['unresolved'] > 0 ? 'is-warning' : '' ?>">
            <span class="stat-label">Complaints open</span>
            <strong class="stat-value"><?= (int) $summary['unresolved'] ?></strong>
            <span class="stat-note">
                <?= (int) $settings['sla_hours'] ?>-hour deadline each
            </span>
        </div>

        <div class="stat">
            <span class="stat-label">Public reviews</span>
            <strong class="stat-value"><?= (int) $reviewStats['count'] ?></strong>
            <span class="stat-note">
                <?php if ($reviewStats['average'] === null): ?>
                    none in this period
                <?php else: ?>
                    <?= e((string) $reviewStats['average']) ?>&#9733; average
                <?php endif; ?>
            </span>
        </div>

        <div class="stat <?= (int) $reviewStats['unreplied'] > 0 ? 'is-warning' : '' ?>">
            <span class="stat-label">Awaiting a reply</span>
            <strong class="stat-value"><?= (int) $reviewStats['unreplied'] ?></strong>
            <span class="stat-note">
                of <?= (int) $reviewStats['all_time'] ?> reviews held here
            </span>
        </div>
    </div>
</section>

<?php if ($detractors !== []): ?>
    <section class="card">
        <div class="card-head">
            <div>
                <h2>Guests waiting to hear from somebody</h2>
                <p class="card-sub">
                    Nobody is asked for a public review after a low score. They get a
                    person instead &mdash; that is what this list is.
                </p>
            </div>
        </div>

        <table class="table">
            <caption class="sr-only">Unresolved complaints, oldest first</caption>
            <thead>
                <tr>
                    <th scope="col">Guest</th>
                    <th scope="col">Score</th>
                    <th scope="col">What they said</th>
                    <th scope="col">Answered</th>
                    <th scope="col">Due</th>
                    <th scope="col"><span class="sr-only">Open</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($detractors as $row): ?>
                    <tr class="<?= $row['overdue'] ? 'is-overdue' : '' ?>">
                        <td>
                            <strong><?= e(trim((string) $row['first_name'] . ' ' . (string) $row['last_name'])) ?: 'A guest' ?></strong>
                            <?php if (($row['booking_number'] ?? null) !== null): ?>
                                <br><span class="muted"><?= e((string) $row['booking_number']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge badge-bad"><?= (int) $row['score'] ?></span></td>
                        <td><?= e(str_truncate((string) ($row['comment'] ?? ''), 90)) ?: '<span class="muted">no comment</span>' ?></td>
                        <td><?= e(local_date((string) $row['responded_at'], 'M j, g:ia')) ?></td>
                        <td>
                            <?php if ($row['overdue']): ?>
                                <span class="badge badge-bad">overdue</span>
                            <?php else: ?>
                                <?= e(local_date((string) $row['due_at'], 'M j, g:ia')) ?>
                            <?php endif; ?>
                        </td>
                        <td class="td-right">
                            <a class="btn btn-sm" href="<?= e(url('/reputation/feedback/' . (int) $row['id'])) ?>">
                                <?= $canResolve ? 'Resolve' : 'Open' ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php endif; ?>

<?php if ($needReply !== []): ?>
    <section class="card">
        <div class="card-head">
            <div>
                <h2>Reviews nobody has answered</h2>
                <p class="card-sub">Lowest ratings first. A reply is read by everybody who comes after.</p>
            </div>
            <a class="btn btn-ghost" href="<?= e(url('/reputation/reviews')) ?>">All reviews</a>
        </div>

        <ul class="review-list">
            <?php foreach ($needReply as $review): ?>
                <li class="review-item">
                    <div class="review-meta">
                        <span class="rating" aria-label="<?= (int) $review['rating'] ?> out of 5">
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
                    </div>
                    <p><?= e(str_truncate((string) ($review['body'] ?? ''), 220)) ?: '<span class="muted">no text</span>' ?></p>
                    <?php if ($canReply): ?>
                        <a class="btn btn-sm btn-ghost"
                           href="<?= e(url('/reputation/reviews?show=unreplied#review-' . (int) $review['id'])) ?>">
                            Write a reply
                        </a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<section class="card">
    <div class="card-head">
        <div>
            <h2>Score over time</h2>
            <p class="card-sub">
                One <?= $trend !== [] && ($trend[0]['grain'] ?? 'day') === 'week' ? 'week' : 'day' ?>
                per bar, split by how guests answered: happy at the bottom, lukewarm in the
                middle, unhappy on top. The number under each bar is that
                <?= $trend !== [] && ($trend[0]['grain'] ?? 'day') === 'week' ? 'week' : 'day' ?>'s
                score.
            </p>
        </div>
    </div>

    <?php if ($trend === []): ?>
        <p class="empty">No answers in this period yet.</p>
    <?php else: ?>
        <div class="nps-chart">
            <?php foreach ($trend as $point): ?>
                <?php
                $answered = max(1, (int) $point['answered']);
                $pct = static fn (int $n): float => round(($n / $answered) * 100, 1);
                ?>
                <div class="nps-col">
                    <div class="nps-bar" role="img"
                         aria-label="<?= e((string) $point['key']) ?>: <?= (int) $point['promoters'] ?> promoters,
                             <?= (int) $point['passives'] ?> passive,
                             <?= (int) $point['detractors'] ?> unhappy">
                        <span class="nps-seg nps-promoter" style="height:<?= $pct((int) $point['promoters']) ?>%"></span>
                        <span class="nps-seg nps-passive" style="height:<?= $pct((int) $point['passives']) ?>%"></span>
                        <span class="nps-seg nps-detractor" style="height:<?= $pct((int) $point['detractors']) ?>%"></span>
                    </div>
                    <span class="nps-score"><?= $point['nps'] === null ? '&mdash;' : (int) round((float) $point['nps']) ?></span>
                    <?php /* The key is ALREADY a venue-local date, so it is formatted as-is —
                             putting it through local_date() would convert it a second time. */ ?>
                    <span class="nps-label"><?= e(date('M j', (int) strtotime((string) $point['key']))) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<div class="grid-2">
    <section class="card">
        <div class="card-head">
            <div>
                <h2>Review asks</h2>
                <p class="card-sub">Only promoters are asked, so these are all happy guests.</p>
            </div>
            <a class="btn btn-ghost btn-sm" href="<?= e(url('/reputation/requests')) ?>">Every ask</a>
        </div>

        <dl class="definition-list">
            <dt>Asked</dt>
            <dd><?= (int) $funnel['sent'] ?></dd>
            <dt>Clicked through</dt>
            <dd>
                <?= (int) $funnel['clicked'] ?>
                <?= $funnel['click_rate'] === null ? '' : '(' . e((string) $funnel['click_rate']) . '%)' ?>
            </dd>
            <dt>Left a review</dt>
            <dd>
                <?= (int) $funnel['reviewed'] ?>
                <?= $funnel['of_clicks'] === null ? '' : 'of those who clicked (' . e((string) $funnel['of_clicks']) . '%)' ?>
            </dd>
        </dl>

        <?php if (($funnel['by_platform'] ?? []) !== []): ?>
            <table class="table">
                <caption class="sr-only">Asks by platform</caption>
                <thead>
                    <tr><th scope="col">Platform</th><th scope="col">Asked</th><th scope="col">Clicked</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($funnel['by_platform'] as $row): ?>
                        <tr>
                            <td><?= e(App\Services\Reputation::platformName((string) $row['platform'])) ?></td>
                            <td><?= (int) $row['asked'] ?></td>
                            <td><?= (int) $row['clicked'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="card-head">
            <div>
                <h2>By party host</h2>
                <p class="card-sub">
                    Hosts with at least three scores in this period. Fewer than that says
                    nothing about anybody.
                </p>
            </div>
        </div>

        <?php if ($byHost === []): ?>
            <p class="empty">No host has three scores yet in this period.</p>
        <?php else: ?>
            <table class="table">
                <caption class="sr-only">Average score by party host</caption>
                <thead>
                    <tr>
                        <th scope="col">Host</th>
                        <th scope="col">Average</th>
                        <th scope="col">NPS</th>
                        <th scope="col">Answers</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($byHost as $row): ?>
                        <tr>
                            <td><?= e((string) ($row['name'] ?? 'Unassigned')) ?></td>
                            <td><?= $row['avg_score'] === null ? '&mdash;' : e((string) $row['avg_score']) ?></td>
                            <td><?= $row['nps'] === null ? '&mdash;' : e((string) $row['nps']) ?></td>
                            <td><?= (int) $row['answered'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</div>

<?php if ($uninvited !== []): ?>
    <section class="card">
        <div class="card-head">
            <div>
                <h2>Guests we could not ask</h2>
                <p class="card-sub">
                    These are not low response rates &mdash; they are invitations that never
                    went. Worth knowing before reading the percentage above.
                </p>
            </div>
        </div>

        <dl class="definition-list">
            <?php foreach ($uninvited as $reason => $count): ?>
                <dt><?= (int) $count ?></dt>
                <dd><?= e($reasonWords[$reason] ?? str_replace('_', ' ', (string) $reason)) ?></dd>
            <?php endforeach; ?>
        </dl>
    </section>
<?php endif; ?>

<section class="card">
    <div class="card-head">
        <div>
            <h2>Everything else</h2>
        </div>
    </div>
    <p class="card-sub">
        <a href="<?= e(url('/reputation/feedback')) ?>">All feedback</a> &middot;
        <a href="<?= e(url('/reputation/reviews')) ?>">Review inbox</a> &middot;
        <a href="<?= e(url('/reputation/requests')) ?>">Review asks</a> &middot;
        <a href="<?= e(url('/reputation/surveys')) ?>">Settings and surveys</a> &middot;
        <a href="<?= e(url('/reviews')) ?>">The public review wall</a>
    </p>
</section>
