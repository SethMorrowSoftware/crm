<?php

declare(strict_types=1);

/**
 * One party's door list.
 *
 * Both headcounts are shown and never reconciled: the booking says 14 and the
 * list may hold eight names, because that is all the family sent. The
 * difference is real information — six children arriving unlisted is
 * something the host needs to know, not an error to smooth over.
 *
 * @var array<string,mixed>       $booking
 * @var list<array<string,mixed>> $guests
 * @var list<array<string,mixed>> $loose
 * @var list<array<string,mixed>> $rooms
 * @var list<array<string,mixed>> $needsWaiver
 * @var string                    $waiverUrl
 * @var bool                      $hasWaiver
 * @var bool                      $canManage
 * @var bool                      $arrivable
 * @var string                    $today
 * @var callable(string):string   $timeLabel
 */

$id        = (int) $booking['id'];
$allergies = trim((string) ($booking['allergies'] ?? ''));
$dietary   = trim((string) ($booking['dietary_notes'] ?? ''));
$arrived   = 0;

foreach ($guests as $guest) {
    if ($guest['checked_in_at'] !== null) {
        $arrived++;
    }
}

?>
<p class="breadcrumbs">
    <a href="<?= e(url('/checkin?date=' . (string) $booking['event_date'])) ?>">Check-in</a> /
    <?= e((string) $booking['booking_number']) ?>
</p>

<?php if ($allergies !== '' || $dietary !== ''): ?>
    <div class="alert alert-warning">
        <p>
            <?php if ($allergies !== ''): ?>
                <strong style="font-size:1.1em">ALLERGIES: <?= e($allergies) ?></strong>
            <?php endif; ?>
            <?php if ($dietary !== ''): ?>
                <?= $allergies !== '' ? '<br>' : '' ?>Dietary: <?= e($dietary) ?>
            <?php endif; ?>
        </p>
    </div>
<?php endif; ?>

<div class="page-actions">
    <p class="page-lede">
        <?= e(trim((string) ($booking['guest_of_honor_name'] ?? ''))
            ?: ucfirst(str_replace('_', ' ', (string) $booking['type']))) ?>
        &middot; <?= e($timeLabel((string) ($booking['start_time'] ?? '')) ?: 'no time set') ?>
        &middot; <?= e((string) (int) $booking['guest_count']) ?> expected
        <?php if ($rooms !== []): ?>
            &middot; <?= e(implode(', ', array_map(
                static fn (array $r): string => (string) $r['name'],
                $rooms
            ))) ?>
        <?php endif; ?>
    </p>
    <a class="btn btn-ghost" href="<?= e(url('/bookings/' . $id)) ?>">The booking</a>
    <a class="btn btn-ghost" href="<?= e(url('/bookings/' . $id . '/run-sheet')) ?>">Run sheet</a>
</div>

<?php if (!$arrivable): ?>
    <div class="alert alert-warning">
        <p>
            This booking is
            <?= e(str_replace('_', ' ', (string) $booking['status'])) ?>, not confirmed. Guests can
            still be ticked in, but check with whoever took the booking first.
        </p>
    </div>
<?php endif; ?>

<?php if ($needsWaiver !== []): ?>
    <p class="muted">
        <?= e(implode(', ', array_map(
            static fn (array $a): string => (string) $a['name'],
            $needsWaiver
        ))) ?>
        need<?= count($needsWaiver) === 1 ? 's' : '' ?> a signed waiver.
        <?php if ($hasWaiver): ?>
            Guests can sign at <a href="<?= e($waiverUrl) ?>"><?= e($waiverUrl) ?></a>.
        <?php endif; ?>
    </p>
<?php endif; ?>

<div class="card">
    <h2><?= e((string) $arrived) ?> of <?= e((string) count($guests)) ?> listed guests in</h2>

    <?php if ($guests === []): ?>
        <p class="empty">
            No names on the list. Add each child as they arrive — the booking expects
            <?= e((string) (int) $booking['guest_count']) ?>.
        </p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <caption class="sr-only">Guests expected at this party</caption>
                <thead>
                    <tr>
                        <th scope="col">In</th>
                        <th scope="col">Name</th>
                        <th scope="col">Age</th>
                        <th scope="col">Waiver</th>
                        <th scope="col">Parent</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($guests as $guest): ?>
                        <?php
                        $in      = $guest['checked_in_at'] !== null;
                        $expiry  = (string) ($guest['expires_at'] ?? '');
                        $expired = $expiry !== '' && $expiry < $today;
                        ?>
                        <tr>
                            <td>
                                <form method="post"
                                      action="<?= e(url('/checkin/' . $id . '/guest')) ?>"
                                      class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="guest_id"
                                           value="<?= e((string) $guest['id']) ?>">
                                    <button type="submit" class="btn btn-sm <?= $in ? 'btn-ghost' : '' ?>">
                                        <?= $in ? 'Undo' : 'In' ?>
                                        <span class="sr-only"><?= e((string) $guest['name']) ?></span>
                                    </button>
                                </form>
                            </td>
                            <td>
                                <?= e((string) $guest['name']) ?>
                                <?php if ($in): ?>
                                    <span class="td-sub">
                                        <?= e(local_time((string) $guest['checked_in_at'], 'g:i A')) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><?= e(($guest['age'] ?? null) === null ? '—' : (string) $guest['age']) ?></td>
                            <td>
                                <?php if (($guest['waiver_id'] ?? null) === null): ?>
                                    <span class="badge badge-warn">None</span>
                                <?php elseif ($expired): ?>
                                    <span class="badge badge-bad">Expired</span>
                                <?php else: ?>
                                    <span class="badge badge-ok">Signed</span>
                                    <span class="td-sub"><?= e((string) ($guest['signer_name'] ?? '')) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= e((string) ($guest['parent_name'] ?? '') ?: '—') ?>
                                <?php if (trim((string) ($guest['parent_phone'] ?? '')) !== ''): ?>
                                    <span class="td-sub"><?= e((string) $guest['parent_phone']) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($arrived < count($guests)): ?>
            <form method="post" action="<?= e(url('/checkin/' . $id . '/arrive-all')) ?>" class="form">
                <?= csrf_field() ?>
                <div class="form-actions">
                    <button type="submit" class="btn btn-ghost">
                        Everybody is in (<?= e((string) (count($guests) - $arrived)) ?> left)
                    </button>
                </div>
                <p class="field-hint">
                    For a coach party. This only ever marks people as arrived — undoing one at a
                    time is the row buttons above.
                </p>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Somebody not on the list</h2>
    <p class="muted">The usual case at a children's party. They go straight in as arrived.</p>
    <form method="post" action="<?= e(url('/checkin/' . $id . '/guests')) ?>" class="form">
        <?= csrf_field() ?>
        <div class="field">
            <label for="name">Name</label>
            <input type="text" id="name" name="name" maxlength="120" required>
        </div>
        <div class="field">
            <label for="age">Age (optional)</label>
            <input type="number" id="age" name="age" min="0" max="120">
        </div>
        <div class="field">
            <label for="parent_name">Who brought them (optional)</label>
            <input type="text" id="parent_name" name="parent_name" maxlength="120">
        </div>
        <div class="field">
            <label for="parent_phone">Their phone (optional)</label>
            <input type="tel" id="parent_phone" name="parent_phone" maxlength="32">
        </div>
        <div class="form-actions">
            <button type="submit" class="btn">Add and check in</button>
        </div>
    </form>
</div>

<?php if ($loose !== []): ?>
    <div class="card">
        <h2>Waivers signed for this party but not matched to a name</h2>
        <p class="muted">
            Usually a parent who signed as Elizabeth while the list says Beth. Matching is by
            hand on purpose: a wrong tick here means somebody walks past a waiver nobody signed.
        </p>
        <?php foreach ($loose as $signature): ?>
            <?php
            $minors = json_decode((string) ($signature['minors'] ?? '[]'), true);
            $minors = is_array($minors) ? $minors : [];
            ?>
            <form method="post" action="<?= e(url('/checkin/' . $id . '/waiver')) ?>" class="form">
                <?= csrf_field() ?>
                <input type="hidden" name="signature_id" value="<?= e((string) $signature['id']) ?>">
                <div class="field">
                    <label for="guest_for_<?= e((string) $signature['id']) ?>">
                        <?= e((string) $signature['signer_name']) ?>
                        signed <?= e(local_time((string) $signature['signed_at'])) ?>
                        <?php if ($minors !== []): ?>
                            for <?= e(implode(', ', array_map(
                                static fn (array $m): string => (string) ($m['name'] ?? ''),
                                $minors
                            ))) ?>
                        <?php endif; ?>
                    </label>
                    <select id="guest_for_<?= e((string) $signature['id']) ?>" name="guest_id" required>
                        <option value="">Which guest does this cover?</option>
                        <?php foreach ($guests as $guest): ?>
                            <?php if (($guest['waiver_id'] ?? null) === null): ?>
                                <option value="<?= e((string) $guest['id']) ?>">
                                    <?= e((string) $guest['name']) ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-sm">Match it</button>
                </div>
            </form>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
