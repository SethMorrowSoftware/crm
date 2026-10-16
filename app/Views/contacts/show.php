<?php

declare(strict_types=1);

/**
 * One guest.
 *
 * @var array<string,mixed>       $contact
 * @var array<string,mixed>|null  $household
 * @var list<array<string,mixed>> $members
 * @var list<array<string,mixed>> $children
 * @var list<array<string,mixed>> $notes
 * @var list<array{label:string,state:string,detail:string,tone:string}> $consent
 * @var list<array<string,mixed>> $consentLog
 * @var list<array<string,mixed>> $timeline
 * @var array<string,mixed>|null  $owner
 * @var list<array<string,mixed>> $companies
 * @var bool                      $canManage
 * @var bool                      $canDelete
 * @var bool                      $showMoney
 * @var list<string>              $channels
 */

$id   = (int) $contact['id'];
$name = trim(((string) ($contact['first_name'] ?? '')) . ' ' . ((string) ($contact['last_name'] ?? '')));

if ($name === '') {
    $name = (string) ($contact['email'] ?? $contact['phone_normalized'] ?? 'Contact #' . $id);
}

$blocked = (int) $contact['do_not_contact'] === 1;

$stageLabels = [
    'lead'     => 'Lead',
    'prospect' => 'Prospect',
    'customer' => 'Customer',
    'member'   => 'Member',
    'lapsed'   => 'Lapsed',
    'advocate' => 'Advocate',
];

$toneClass = static fn (string $tone): string => match ($tone) {
    'ok'   => 'badge badge-ok',
    'warn' => 'badge badge-warn',
    'bad'  => 'badge badge-bad',
    default => 'badge badge-muted',
};

$memberName = static function (array $row): string {
    $full = trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['last_name'] ?? '')));

    return $full === '' ? 'Contact #' . (int) $row['id'] : $full;
};
?>
<p class="breadcrumbs"><a href="<?= e(url('/contacts')) ?>">Contacts</a> / <?= e($name) ?></p>

<div class="page-actions">
    <p class="page-lede">
        <span class="badge"><?= e($stageLabels[(string) $contact['lifecycle_stage']]
            ?? ucfirst((string) $contact['lifecycle_stage'])) ?></span>
        <?php if ($blocked): ?>
            <span class="badge badge-bad">Do not contact</span>
        <?php endif; ?>
        Added <?= e(local_date((string) $contact['created_at'])) ?>.
    </p>
    <?php if ($canManage): ?>
        <a class="btn" href="<?= e(url('/contacts/' . $id . '/edit')) ?>"><?= icon('edit', 16) ?> Edit</a>
    <?php endif; ?>
</div>

<section class="stat-row" aria-label="Guest history">
    <div class="stat">
        <span class="stat-label">Visits</span>
        <span class="stat-value"><?= number_format((int) $contact['visit_count']) ?></span>
        <span class="stat-note">
            <?= !empty($contact['last_visit_at'])
                ? 'Last ' . e(local_date((string) $contact['last_visit_at']))
                : 'None recorded yet' ?>
        </span>
    </div>
    <div class="stat">
        <span class="stat-label">First visit</span>
        <span class="stat-value">
            <?= !empty($contact['first_visit_at'])
                ? e(local_date((string) $contact['first_visit_at']))
                : '&mdash;' ?>
        </span>
        <span class="stat-note">From the POS card ledger, once connected</span>
    </div>
    <?php if ($showMoney): ?>
        <div class="stat">
            <span class="stat-label">Lifetime value</span>
            <span class="stat-value"><?= e(money((int) $contact['lifetime_value_cents'])) ?></span>
            <span class="stat-note">Average <?= e(money((int) $contact['avg_spend_cents'])) ?> a visit</span>
        </div>
    <?php endif; ?>
    <div class="stat">
        <span class="stat-label">Last contacted</span>
        <span class="stat-value">
            <?= !empty($contact['last_contacted_at'])
                ? e(time_ago((string) $contact['last_contacted_at']))
                : '&mdash;' ?>
        </span>
        <span class="stat-note">By anything Castle CRM sent</span>
    </div>
</section>

<div class="grid-2">
    <section class="card">
        <div class="card-head"><h2>Details</h2></div>
        <dl class="definition-list">
            <div>
                <dt>Email</dt>
                <dd><?= !empty($contact['email']) ? e($contact['email']) : '<span class="muted">None</span>' ?></dd>
            </div>
            <div>
                <dt>Phone</dt>
                <dd>
                    <?php if (!empty($contact['phone_normalized'])): ?>
                        <?= e(format_phone((string) $contact['phone_normalized'])) ?>
                        <span class="muted">(<?= e($contact['phone_normalized']) ?>)</span>
                    <?php elseif (!empty($contact['phone'])): ?>
                        <?= e($contact['phone']) ?>
                        <span class="muted">— not a number we can text</span>
                    <?php else: ?>
                        <span class="muted">None</span>
                    <?php endif; ?>
                </dd>
            </div>
            <?php if (!empty($contact['alt_phone'])): ?>
                <div><dt>Second phone</dt><dd><?= e($contact['alt_phone']) ?></dd></div>
            <?php endif; ?>
            <div>
                <dt>Address</dt>
                <dd>
                    <?php
                    $address = array_filter([
                        (string) ($contact['address_1'] ?? ''),
                        (string) ($contact['address_2'] ?? ''),
                        trim(((string) ($contact['city'] ?? '')) . ' ' . ((string) ($contact['state'] ?? ''))
                            . ' ' . ((string) ($contact['postal_code'] ?? ''))),
                    ], static fn (string $part): bool => trim($part) !== '');
                    ?>
                    <?= $address === []
                        ? '<span class="muted">None</span>'
                        : e(implode(', ', $address)) ?>
                </dd>
            </div>
            <div>
                <dt>Date of birth</dt>
                <dd><?= !empty($contact['birth_date'])
                        ? e(local_date((string) $contact['birth_date']))
                        : '<span class="muted">Not known</span>' ?></dd>
            </div>
            <div>
                <dt>Card number</dt>
                <dd><?= !empty($contact['external_pos_id'])
                        ? e($contact['external_pos_id'])
                        : '<span class="muted">Not linked to a POS card</span>' ?></dd>
            </div>
            <div>
                <dt>Owner</dt>
                <dd><?= $owner !== null ? e($owner['name']) : '<span class="muted">Nobody</span>' ?></dd>
            </div>
            <div>
                <dt>Source</dt>
                <dd>
                    <?php if (!empty($contact['source'])): ?>
                        <?= e($contact['source']) ?>
                        <?= !empty($contact['source_detail']) ? ' — ' . e($contact['source_detail']) : '' ?>
                    <?php else: ?>
                        <span class="muted">Not recorded</span>
                    <?php endif; ?>
                </dd>
            </div>
        </dl>

        <?php if ($companies !== []): ?>
            <p class="form-section-title">Organisations</p>
            <ul class="feature-list">
                <?php foreach ($companies as $company): ?>
                    <li>
                        <?= e($company['name']) ?>
                        <?= !empty($company['job_title']) ? ' — ' . e($company['job_title']) : '' ?>
                        <?= (int) $company['is_primary'] === 1 ? ' <span class="badge badge-muted">Primary</span>' : '' ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="card-head">
            <h2>Household</h2>
            <?php if ($household !== null): ?>
                <a class="btn btn-ghost btn-sm"
                   href="<?= e(url('/households/' . (int) $household['id'])) ?>">Open household</a>
            <?php endif; ?>
        </div>

        <?php if ($household === null): ?>
            <p class="empty">
                Not in a household.
                <?php if ($canManage): ?>
                    A household groups a family under one address, so a party reaches the
                    guardian rather than four separate guests —
                    <a href="<?= e(url('/contacts/' . $id . '/edit')) ?>">set one on the contact</a>.
                <?php endif; ?>
            </p>
        <?php else: ?>
            <p><strong><?= e($household['name']) ?></strong></p>
            <?php
            $householdAddress = array_filter([
                (string) ($household['address_1'] ?? ''),
                trim(((string) ($household['city'] ?? '')) . ' ' . ((string) ($household['state'] ?? ''))
                    . ' ' . ((string) ($household['postal_code'] ?? ''))),
            ], static fn (string $part): bool => trim($part) !== '');
            ?>
            <?php if ($householdAddress !== []): ?>
                <p class="muted"><?= e(implode(', ', $householdAddress)) ?></p>
            <?php endif; ?>

            <p class="form-section-title">Other members</p>
            <?php if ($members === []): ?>
                <p class="empty">Nobody else is in this household yet.</p>
            <?php else: ?>
                <ul class="feature-list">
                    <?php foreach ($members as $member): ?>
                        <li>
                            <a href="<?= e(url('/contacts/' . (int) $member['id'])) ?>">
                                <?= e($memberName($member)) ?>
                            </a>
                            <?php if (!empty($member['email'])): ?>
                                <span class="muted"><?= e($member['email']) ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($children !== []): ?>
            <p class="form-section-title">Children</p>
            <ul class="feature-list">
                <?php foreach ($children as $child): ?>
                    <li>
                        <?= e($child['first_name']) ?>
                        <?php if (!empty($child['birth_date'])): ?>
                            <span class="muted"><?= e(local_date((string) $child['birth_date'])) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($child['allergies'])): ?>
                            <strong class="badge badge-warn">Allergies: <?= e($child['allergies']) ?></strong>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="field-hint">
                Every message about a child goes to the guardian. Castle CRM stores no way to
                reach a child directly.
            </p>
        <?php endif; ?>
    </section>
</div>

<section class="card">
    <div class="card-head">
        <h2>Consent</h2>
        <p class="card-sub">What this guest may be sent, and the evidence for it.</p>
    </div>

    <dl class="definition-list">
        <?php foreach ($consent as $row): ?>
            <div>
                <dt><?= e($row['label']) ?></dt>
                <dd>
                    <span class="<?= e($toneClass($row['tone'])) ?>"><?= e($row['state']) ?></span>
                    <span class="td-sub"><?= e($row['detail']) ?></span>
                </dd>
            </div>
        <?php endforeach; ?>
    </dl>

    <?php if ($canManage): ?>
        <form method="post" action="<?= e(url('/contacts/' . $id . '/consent')) ?>" class="form">
            <?= csrf_field() ?>
            <p class="form-section-title">Record what the guest did</p>

            <div class="field-row">
                <div class="field">
                    <label for="consent_channel">Channel</label>
                    <select id="consent_channel" name="channel" required>
                        <?php foreach ($channels as $channel): ?>
                            <option value="<?= e($channel) ?>">
                                <?= $channel === 'email' ? 'Email' : 'Text message' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="consent_action">What happened</label>
                    <select id="consent_action" name="action" required>
                        <option value="granted">They agreed to be contacted</option>
                        <option value="revoked">They asked us to stop</option>
                    </select>
                </div>
            </div>

            <div class="field">
                <label for="consent_source">Where this came from</label>
                <input type="text" id="consent_source" name="source" required minlength="3" maxlength="80"
                       value="<?= e((string) old('source', '')) ?>"
                       placeholder="Party booking form, text club keyword CASTLE, asked at the desk">
                <p class="field-hint">
                    Required. Which form, which keyword, or which member of staff heard it.
                </p>
                <?php if ($err = error_for('source')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label for="consent_evidence">What the guest was shown</label>
                <textarea id="consent_evidence" name="evidence" rows="2" maxlength="2000"><?= e((string) old('evidence', '')) ?></textarea>
                <p class="field-hint">
                    The wording they agreed to, if there was any. Compliance turns on what was
                    shown, not on the tick — so this is the part worth keeping.
                </p>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn">Record it</button>
            </div>
        </form>

        <form method="post" action="<?= e(url('/contacts/' . $id . '/do-not-contact')) ?>" class="form"
              data-confirm="<?= $blocked
                  ? 'Lift the do-not-contact block? Consent still has to be given again.'
                  : 'Mark as do-not-contact? Both consents will be cleared.' ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="state" value="<?= $blocked ? 'off' : 'on' ?>">
            <p class="form-section-title">Do not contact</p>
            <p class="field-hint">
                <?= $blocked
                    ? 'This guest is blocked from everything. Lifting the block does not restore '
                        . 'their consent — that has to be given again.'
                    : 'A hard block that overrides both consents. Use it when a guest asks not to '
                        . 'be contacted at all.' ?>
            </p>
            <div class="field">
                <label for="dnc_source">Where this came from</label>
                <input type="text" id="dnc_source" name="source" required minlength="3" maxlength="80"
                       placeholder="Phone call, reply to a text, asked at the desk">
            </div>
            <div class="form-actions">
                <button type="submit" class="btn <?= $blocked ? 'btn-ghost' : 'btn-danger' ?>">
                    <?= $blocked ? 'Lift the block' : 'Mark do not contact' ?>
                </button>
            </div>
        </form>
    <?php endif; ?>

    <p class="form-section-title">Consent history</p>
    <?php if ($consentLog === []): ?>
        <p class="empty">
            Nothing recorded. A contact from the POS arrives with no consent — that is a visit
            history, not permission to market to them.
        </p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th scope="col">When</th>
                    <th scope="col">Channel</th>
                    <th scope="col">What</th>
                    <th scope="col">Source</th>
                    <th scope="col">Evidence</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($consentLog as $entry): ?>
                    <?php $evidence = json_decode((string) ($entry['evidence'] ?? ''), true); ?>
                    <tr>
                        <td><?= e(local_time((string) $entry['created_at'])) ?></td>
                        <td><?= e($entry['channel']) ?></td>
                        <td>
                            <span class="badge <?= $entry['action'] === 'granted' ? 'badge-ok' : 'badge-muted' ?>">
                                <?= $entry['action'] === 'granted' ? 'Agreed' : 'Withdrawn' ?>
                            </span>
                        </td>
                        <td><?= e($entry['source']) ?></td>
                        <td>
                            <?php if (is_array($evidence) && $evidence !== []): ?>
                                <?php if (!empty($evidence['disclosure'])): ?>
                                    <span><?= e(str_truncate((string) $evidence['disclosure'], 120)) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($evidence['recorded_by'])): ?>
                                    <span class="td-sub">Recorded by <?= e($evidence['recorded_by']) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($evidence['reason'])): ?>
                                    <span class="td-sub"><?= e($evidence['reason']) ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="muted">&mdash;</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="field-hint">These rows are append-only. Nothing in Castle CRM edits or deletes them.</p>
    <?php endif; ?>
</section>

<div class="grid-2">
    <section class="card">
        <div class="card-head"><h2>Notes</h2></div>

        <?php if ($canManage): ?>
            <form method="post" action="<?= e(url('/contacts/' . $id . '/notes')) ?>" class="form">
                <?= csrf_field() ?>
                <div class="field">
                    <label for="note_body">Add a note</label>
                    <textarea id="note_body" name="body" rows="3" required minlength="2" maxlength="5000"
                              placeholder="What happened, what they asked for, what to do next"></textarea>
                    <?php if ($err = error_for('body')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
                </div>
                <div class="field field-check">
                    <input type="checkbox" id="is_pinned" name="is_pinned" value="1">
                    <label for="is_pinned">Pin this to the top</label>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-sm">Save note</button>
                </div>
            </form>
        <?php endif; ?>

        <?php if ($notes === []): ?>
            <p class="empty">No notes yet.</p>
        <?php else: ?>
            <ul class="timeline">
                <?php foreach ($notes as $note): ?>
                    <li>
                        <span class="timeline-when">
                            <?= e(local_time((string) $note['created_at'])) ?>
                            <?= (int) $note['is_pinned'] === 1 ? ' <span class="badge badge-muted">Pinned</span>' : '' ?>
                        </span>
                        <span class="timeline-what">
                            <strong><?= e($note['author'] ?? 'System') ?></strong>
                            <?= nl2br(e($note['body'])) ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="card-head">
            <h2>Timeline</h2>
            <p class="card-sub">Recorded activity on this guest.</p>
        </div>

        <?php if ($timeline === []): ?>
            <p class="empty">
                Nothing recorded yet. Messages, bookings, visits and payments appear here as
                each of those modules lands — this panel shows only what the activity table
                actually holds, so an empty timeline means nothing has happened, not that
                something is missing.
            </p>
        <?php else: ?>
            <ul class="timeline">
                <?php foreach ($timeline as $entry): ?>
                    <li>
                        <span class="timeline-when"><?= e(local_time((string) $entry['occurred_at'])) ?></span>
                        <span class="timeline-what">
                            <strong><?= e(ucfirst(str_replace('_', ' ', (string) $entry['type']))) ?></strong>
                            <?php if (!empty($entry['subject'])): ?>
                                <?= e($entry['subject']) ?>
                            <?php endif; ?>
                            <?php if (!empty($entry['outcome'])): ?>
                                <span class="muted"><?= e($entry['outcome']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($entry['author'])): ?>
                                <span class="td-sub"><?= e($entry['author']) ?></span>
                            <?php endif; ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<?php if ($canDelete): ?>
    <section class="card card-danger">
        <h2>Delete this contact</h2>
        <p>
            The record is hidden rather than destroyed, so visits, bookings and the consent
            trail stay intact and an administrator can undo it. Erasing a guest on request —
            anonymising the record and keeping only the financial totals — is a different
            operation behind its own permission.
        </p>
        <form method="post" action="<?= e(url('/contacts/' . $id . '/delete')) ?>"
              data-confirm="Delete <?= e($name) ?>?">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-danger"><?= icon('trash', 15) ?> Delete contact</button>
        </form>
    </section>
<?php endif; ?>
