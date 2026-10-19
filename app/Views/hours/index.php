<?php

declare(strict_types=1);

/**
 * When the venue is open: the weekly pattern by season, closures and special
 * hours, and attractions down for the day.
 *
 * @var list<array{id:int,label:string,from:?string,to:?string,days:array<int,array{opens:string,closes:string}>}> $seasons
 * @var list<string>               $days
 * @var list<array<string,mixed>>  $exceptions
 * @var list<array<string,mixed>>  $outages
 * @var list<array{id:int,name:string}> $attractions
 * @var string                     $today
 */

/**
 * The seven rows of one week's form.
 *
 * @param array<int,array{opens:string,closes:string}> $hours
 */
$week = static function (string $prefix, array $hours, bool $standard) use ($days): void {
    ?>
    <div class="table-wrap">
        <table class="table hours-table">
            <caption class="sr-only">Opening hours by day</caption>
            <thead>
                <tr>
                    <th scope="col">Day</th>
                    <th scope="col">Opens</th>
                    <th scope="col">Closes</th>
                    <?php if ($standard): ?>
                        <th scope="col">Closed</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($days as $dow => $name): ?>
                    <?php
                    $day    = $hours[$dow] ?? null;
                    $closed = $standard && $day === null;
                    $id     = $prefix . '-' . $dow;
                    ?>
                    <tr>
                        <th scope="row"><?= e($name) ?></th>
                        <td class="field">
                            <label for="<?= e($id) ?>-opens" class="sr-only"><?= e($name) ?> opens</label>
                            <input type="time" id="<?= e($id) ?>-opens" name="days[<?= $dow ?>][opens]" step="900"
                                   value="<?= e($day['opens'] ?? '') ?>">
                        </td>
                        <td class="field">
                            <label for="<?= e($id) ?>-closes" class="sr-only"><?= e($name) ?> closes</label>
                            <input type="time" id="<?= e($id) ?>-closes" name="days[<?= $dow ?>][closes]" step="900"
                                   value="<?= e($day['closes'] ?? '') ?>">
                        </td>
                        <?php if ($standard): ?>
                            <td>
                                <label class="hours-closed">
                                    <input type="checkbox" name="days[<?= $dow ?>][closed]" value="1" <?= $closed ? 'checked' : '' ?>>
                                    <span class="sr-only"><?= e($name) ?> </span>Closed
                                </label>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
};
?>
<p class="page-lede">
    What the online booking page and the staff calendar offer. A dated season beats the standard week
    for its dates; a closure or special-hours date beats both. Changes apply to the next booking — a
    party already booked is not moved.
</p>

<?php foreach ($seasons as $i => $season): ?>
    <?php $standard = $season['from'] === null && $season['to'] === null; ?>
    <section class="card">
        <div class="card-head">
            <div>
                <h2><?= e($standard ? 'Standard week' : $season['label']) ?></h2>
                <p class="card-sub">
                    <?php if ($standard): ?>
                        Every day of the year that no season or special date covers. A day ticked closed is closed.
                    <?php else: ?>
                        <?= e(calendar_date($season['from'], 'M j, Y')) ?> to <?= e(calendar_date($season['to'], 'M j, Y')) ?>.
                        A day left blank follows the standard week. To close on a particular date, add it
                        under Closures below.
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <form method="post" class="form"
              action="<?= e(url($season['id'] > 0 ? '/settings/hours/seasons/' . $season['id'] : '/settings/hours/seasons')) ?>">
            <?= csrf_field() ?>
            <?php if ($standard): ?>
                <input type="hidden" name="season_label" value="Standard">
                <input type="hidden" name="standard" value="1">
            <?php else: ?>
                <div class="field-row">
                    <div class="field">
                        <label for="label-<?= $i ?>">Name</label>
                        <input type="text" id="label-<?= $i ?>" name="season_label" maxlength="40" value="<?= e($season['label']) ?>" required>
                    </div>
                    <div class="field">
                        <label for="from-<?= $i ?>">First day</label>
                        <input type="date" id="from-<?= $i ?>" name="effective_from" value="<?= e((string) $season['from']) ?>" required>
                    </div>
                    <div class="field">
                        <label for="to-<?= $i ?>">Last day</label>
                        <input type="date" id="to-<?= $i ?>" name="effective_to" value="<?= e((string) $season['to']) ?>" required>
                    </div>
                </div>
            <?php endif; ?>

            <?php $week('s' . $i, $season['days'], $standard); ?>

            <div class="form-actions">
                <button type="submit" class="btn">Save <?= e($standard ? 'the standard week' : $season['label']) ?></button>
            </div>
        </form>

        <?php if (!$standard && $season['id'] > 0): ?>
            <form method="post" action="<?= e(url('/settings/hours/seasons/' . $season['id'] . '/delete')) ?>" class="inline-form"
                  data-confirm="Delete <?= e($season['label']) ?>? Those dates will follow the standard week.">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-danger-text btn-sm">Delete this season</button>
            </form>
        <?php endif; ?>
    </section>
<?php endforeach; ?>

<section class="card">
    <div class="card-head">
        <div>
            <h2>Add a season</h2>
            <p class="card-sub">Summer hours, school-break hours: a date range with its own week. Leave a day blank to keep
                the standard hours that day; close a particular date under Closures below.</p>
        </div>
    </div>
    <form method="post" action="<?= e(url('/settings/hours/seasons')) ?>" class="form">
        <?= csrf_field() ?>
        <div class="field-row">
            <div class="field">
                <label for="label-new">Name</label>
                <input type="text" id="label-new" name="season_label" maxlength="40" placeholder="Summer" required>
            </div>
            <div class="field">
                <label for="from-new">First day</label>
                <input type="date" id="from-new" name="effective_from" required>
            </div>
            <div class="field">
                <label for="to-new">Last day</label>
                <input type="date" id="to-new" name="effective_to" required>
            </div>
        </div>
        <?php $week('new', [], false); ?>
        <div class="form-actions">
            <button type="submit" class="btn">Add the season</button>
        </div>
    </form>
</section>

<section class="card">
    <div class="card-head">
        <div>
            <h2>Closures and special hours</h2>
            <p class="card-sub">A holiday, a private event, an early close. One date at a time; saving a date again replaces it.</p>
        </div>
    </div>

    <?php if ($exceptions === []): ?>
        <p class="empty">Nothing coming up.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <caption class="sr-only">Upcoming closures and special hours</caption>
                <thead>
                    <tr><th scope="col">Date</th><th scope="col">Hours</th><th scope="col">Note</th><th scope="col"><span class="sr-only">Actions</span></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($exceptions as $row): ?>
                        <tr>
                            <td><strong><?= e(calendar_date((string) $row['date'], 'D, M j, Y')) ?></strong></td>
                            <td>
                                <?php if ((int) $row['is_closed'] === 1): ?>
                                    <span class="badge badge-bad">Closed</span>
                                <?php else: ?>
                                    <?= e(substr((string) $row['opens_at'], 0, 5)) ?>–<?= e(substr((string) $row['closes_at'], 0, 5)) ?>
                                <?php endif; ?>
                            </td>
                            <td><?= e((string) ($row['note'] ?? '')) ?></td>
                            <td class="row-actions">
                                <form method="post" action="<?= e(url('/settings/hours/exceptions/' . (int) $row['id'] . '/delete')) ?>" class="inline-form">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-ghost btn-sm">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/settings/hours/exceptions')) ?>" class="form form-section-divided">
        <?= csrf_field() ?>
        <div class="field-row">
            <div class="field">
                <label for="ex-date">Date</label>
                <input type="date" id="ex-date" name="date" min="<?= e($today) ?>" required>
            </div>
            <div class="field">
                <label for="ex-opens">Opens</label>
                <input type="time" id="ex-opens" name="opens_at" step="900">
            </div>
            <div class="field">
                <label for="ex-closes">Closes</label>
                <input type="time" id="ex-closes" name="closes_at" step="900">
            </div>
            <div class="field">
                <label for="ex-note">Note (optional)</label>
                <input type="text" id="ex-note" name="note" maxlength="191" placeholder="Thanksgiving">
            </div>
        </div>
        <div class="field field-check">
            <label><input type="checkbox" name="is_closed" value="1"> Closed all day</label>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn">Save this date</button>
        </div>
    </form>
</section>

<section class="card">
    <div class="card-head">
        <div>
            <h2>Attractions down for the day</h2>
            <p class="card-sub">A ride closed for maintenance. A party built around it is not offered that day; the rest of the venue is unaffected.</p>
        </div>
    </div>

    <?php if ($outages !== []): ?>
        <div class="table-wrap">
            <table class="table">
                <caption class="sr-only">Upcoming attraction outages</caption>
                <thead>
                    <tr><th scope="col">Date</th><th scope="col">Attraction</th><th scope="col">Note</th><th scope="col"><span class="sr-only">Actions</span></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($outages as $row): ?>
                        <tr>
                            <td><strong><?= e(calendar_date((string) $row['date'], 'D, M j, Y')) ?></strong></td>
                            <td><?= e((string) $row['attraction_name']) ?></td>
                            <td><?= e((string) ($row['note'] ?? '')) ?></td>
                            <td class="row-actions">
                                <form method="post" action="<?= e(url('/settings/hours/outages/' . (int) $row['id'] . '/delete')) ?>" class="inline-form">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-ghost btn-sm">Back in service</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="empty">Everything is running.</p>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/settings/hours/outages')) ?>" class="form form-section-divided">
        <?= csrf_field() ?>
        <div class="field-row">
            <div class="field">
                <label for="out-attraction">Attraction</label>
                <select id="out-attraction" name="attraction_id" required>
                    <option value="">Pick one</option>
                    <?php foreach ($attractions as $attraction): ?>
                        <option value="<?= (int) $attraction['id'] ?>"><?= e((string) $attraction['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="out-date">Date</label>
                <input type="date" id="out-date" name="date" min="<?= e($today) ?>" required>
            </div>
            <div class="field">
                <label for="out-note">Note (optional)</label>
                <input type="text" id="out-note" name="note" maxlength="191" placeholder="Annual inspection">
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn">Mark it down</button>
        </div>
    </form>
</section>
