<?php

declare(strict_types=1);

/**
 * One piece of feedback, with the guest's phone number in front of you.
 *
 * The resolve form asks what was DONE, not for a tick. "Resolved" with no
 * record of what was said is the state this page replaces, and the note is
 * what the recovery-offer automation and the resolution-time figure rest on.
 *
 * @var array<string,mixed>       $row
 * @var int                       $slaHours
 * @var string                    $now
 * @var bool                      $overdue
 * @var float|null                $took
 * @var list<array<string,mixed>> $requests
 * @var list<array<string,mixed>> $tasks
 * @var bool                      $canResolve
 */

$name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
$name = $name === '' ? 'A guest' : $name;

$sentiment = (string) ($row['sentiment'] ?? '');
$words = [
    'promoter'  => 'They are happy and were asked for a public review.',
    'passive'   => 'Lukewarm. They were asked what would have made it better, and not for a review.',
    'detractor' => 'Unhappy. They were NOT asked for a public review — somebody here owes them a call.',
];
?>

<section class="card">
    <div class="card-head">
        <div>
            <h2><?= e($name) ?> scored <?= (int) $row['score'] ?></h2>
            <p class="card-sub">
                <?= e($words[$sentiment] ?? '') ?>
            </p>
        </div>
        <a class="btn btn-ghost" href="<?= e(url('/reputation/feedback')) ?>">All feedback</a>
    </div>

    <?php if ($sentiment === 'detractor' && ($row['resolved_at'] ?? null) === null): ?>
        <div class="alert <?= $overdue ? 'alert-error' : 'alert-warning' ?>">
            <?= icon('alert') ?>
            <p>
                <?php if ($overdue): ?>
                    <strong>Past the <?= (int) $slaHours ?>-hour deadline.</strong>
                <?php else: ?>
                    <strong>Open.</strong> The deadline is <?= (int) $slaHours ?> hours from when they answered.
                <?php endif; ?>
                Ring them, then record what you agreed below.
            </p>
        </div>
    <?php endif; ?>

    <dl class="definition-list">
        <dt>What they said</dt>
        <dd><?= ($row['comment'] ?? null) === null
            ? '<span class="muted">They left no comment.</span>'
            : nl2br(e((string) $row['comment'])) ?></dd>

        <dt>Email</dt>
        <dd><?= ($row['email'] ?? null) === null ? '<span class="muted">none on file</span>'
            : '<a href="mailto:' . e((string) $row['email']) . '">' . e((string) $row['email']) . '</a>' ?></dd>

        <dt>Phone</dt>
        <dd><?= ($row['phone'] ?? null) === null ? '<span class="muted">none on file</span>'
            : '<a href="tel:' . e((string) $row['phone']) . '">' . e((string) $row['phone']) . '</a>' ?></dd>

        <dt>Survey</dt>
        <dd><?= e((string) ($row['survey_name'] ?? 'Feedback')) ?>
            (<?= e((string) ($row['survey_type'] ?? 'nps')) ?>)</dd>

        <?php if (($row['booking_number'] ?? null) !== null): ?>
            <dt>Booking</dt>
            <dd>
                <a href="<?= e(url('/bookings/' . (int) $row['booking_id'])) ?>">
                    <?= e((string) $row['booking_number']) ?>
                </a>
                <?php if (($row['event_date'] ?? null) !== null): ?>
                    &middot; <?= e(date('M j, Y', (int) strtotime((string) $row['event_date']))) ?>
                <?php endif; ?>
                <?php if (($row['host_name'] ?? null) !== null): ?>
                    &middot; host <?= e((string) $row['host_name']) ?>
                <?php endif; ?>
            </dd>
        <?php endif; ?>

        <dt>Invited</dt>
        <dd><?= ($row['invited_at'] ?? null) === null ? '<span class="muted">not recorded</span>'
            : e(local_date((string) $row['invited_at'], 'M j, Y g:ia'))
                . ' by ' . e((string) ($row['channel'] ?? 'email')) ?></dd>

        <dt>Answered</dt>
        <dd><?= e(local_date((string) $row['responded_at'], 'M j, Y g:ia')) ?></dd>

        <?php if (($row['resolved_at'] ?? null) !== null): ?>
            <dt>Resolved</dt>
            <dd>
                <?= e(local_date((string) $row['resolved_at'], 'M j, Y g:ia')) ?>
                <?php if (($row['resolver_name'] ?? null) !== null): ?>
                    by <?= e((string) $row['resolver_name']) ?>
                <?php endif; ?>
                <?php if ($took !== null): ?>
                    &middot; took <?= e((string) $took) ?> hours
                <?php endif; ?>
            </dd>

            <dt>What was done</dt>
            <dd><?= nl2br(e((string) ($row['resolution_note'] ?? ''))) ?></dd>
        <?php endif; ?>
    </dl>
</section>

<?php if ($sentiment === 'detractor' && ($row['resolved_at'] ?? null) === null && $canResolve): ?>
    <section class="card">
        <div class="card-head">
            <div>
                <h2>Record what you did</h2>
                <p class="card-sub">
                    Saving this closes the task and fires the "resolved" trigger, so any
                    goodwill-offer automation runs from here.
                </p>
            </div>
        </div>

        <form method="post" action="<?= e(url('/reputation/feedback/' . (int) $row['id'] . '/resolve')) ?>">
            <?= csrf_field() ?>
            <div class="field">
                <label for="resolution_note">What you did</label>
                <textarea id="resolution_note" name="resolution_note" rows="4" required
                          placeholder="Spoke to Dana, apologised for the wait on the karts, comped two wristbands for their next visit."></textarea>
                <p class="field-hint">This is kept with the complaint and shows in the resolution-time figure.</p>
            </div>
            <button type="submit" class="btn">Mark as resolved</button>
        </form>
    </section>
<?php endif; ?>

<?php if ($requests !== []): ?>
    <section class="card">
        <div class="card-head"><div><h2>Review asks from this answer</h2></div></div>
        <table class="table">
            <caption class="sr-only">Review asks</caption>
            <thead><tr><th scope="col">Platform</th><th scope="col">State</th><th scope="col">Asked</th></tr></thead>
            <tbody>
                <?php foreach ($requests as $ask): ?>
                    <tr>
                        <td><?= e(App\Services\Reputation::platformName((string) $ask['platform'])) ?></td>
                        <td><?= e((string) $ask['status']) ?></td>
                        <td><?= e(local_date((string) $ask['created_at'], 'M j, g:ia')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php endif; ?>

<?php if ($tasks !== []): ?>
    <section class="card">
        <div class="card-head"><div><h2>Work raised for this</h2></div></div>
        <ul class="list-plain">
            <?php foreach ($tasks as $task): ?>
                <li>
                    <a href="<?= e(url('/tasks/' . (int) $task['id'])) ?>"><?= e((string) $task['title']) ?></a>
                    &middot; <span class="muted"><?= e((string) $task['status']) ?></span>
                    <?php if (($task['due_at'] ?? null) !== null): ?>
                        &middot; due <?= e(local_date((string) $task['due_at'], 'M j, g:ia')) ?>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>
