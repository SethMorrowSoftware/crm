<?php

declare(strict_types=1);

/**
 * @var array<string,mixed>       $household
 * @var list<array<string,mixed>> $members
 * @var list<array<string,mixed>> $children
 * @var list<array<string,mixed>> $candidates
 * @var bool                      $canManage
 */

$id      = (int) $household['id'];
$primary = (int) ($household['primary_contact_id'] ?? 0);

$memberName = static function (array $row): string {
    $full = trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['last_name'] ?? '')));

    return $full === '' ? 'Contact #' . (int) $row['id'] : $full;
};

$address = array_filter([
    (string) ($household['address_1'] ?? ''),
    (string) ($household['address_2'] ?? ''),
    trim(((string) ($household['city'] ?? '')) . ' ' . ((string) ($household['state'] ?? ''))
        . ' ' . ((string) ($household['postal_code'] ?? ''))),
], static fn (string $part): bool => trim($part) !== '');
?>
<p class="breadcrumbs">
    <a href="<?= e(url('/households')) ?>">Households</a> / <?= e($household['name']) ?>
</p>

<div class="page-actions">
    <p class="page-lede">
        <?= $address === []
            ? 'No address recorded.'
            : e(implode(', ', $address)) ?>
    </p>
    <?php if ($canManage): ?>
        <a class="btn" href="<?= e(url('/households/' . $id . '/edit')) ?>"><?= icon('edit', 16) ?> Edit</a>
    <?php endif; ?>
</div>

<?php if (!empty($household['notes'])): ?>
    <section class="card">
        <div class="card-head"><h2>Notes</h2></div>
        <p><?= nl2br(e($household['notes'])) ?></p>
    </section>
<?php endif; ?>

<section class="card">
    <div class="card-head">
        <h2>Members</h2>
        <p class="card-sub"><?= count($members) ?> <?= count($members) === 1 ? 'person' : 'people' ?></p>
    </div>

    <?php if ($members === []): ?>
        <p class="empty">Nobody in this household yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">How to reach them</th>
                    <th scope="col">Stage</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($members as $member): ?>
                    <tr>
                        <td>
                            <a href="<?= e(url('/contacts/' . (int) $member['id'])) ?>">
                                <strong><?= e($memberName($member)) ?></strong>
                            </a>
                            <?php if ((int) $member['id'] === $primary): ?>
                                <span class="badge badge-ok">Main contact</span>
                            <?php endif; ?>
                            <?php if ((int) $member['do_not_contact'] === 1): ?>
                                <span class="badge badge-bad">Do not contact</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($member['email'])): ?>
                                <span><?= e($member['email']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($member['phone_normalized'])): ?>
                                <span class="td-sub"><?= e(format_phone((string) $member['phone_normalized'])) ?></span>
                            <?php elseif (empty($member['email'])): ?>
                                <span class="muted">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge"><?= e(ucfirst((string) $member['lifecycle_stage'])) ?></span></td>
                        <td class="td-right">
                            <?php if ($canManage): ?>
                                <form method="post" class="inline-form"
                                      action="<?= e(url('/households/' . $id . '/members/remove')) ?>"
                                      data-confirm="Remove <?= e($memberName($member)) ?> from this household?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="contact_id" value="<?= (int) $member['id'] ?>">
                                    <button type="submit" class="btn btn-ghost btn-sm">Remove</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="field-hint">
            Removing somebody leaves their contact record alone — it only unlinks them from
            this household.
        </p>
    <?php endif; ?>

    <?php if ($canManage): ?>
        <form method="post" action="<?= e(url('/households/' . $id . '/members')) ?>" class="form">
            <?= csrf_field() ?>
            <div class="field">
                <label for="contact_id">Add somebody</label>
                <select id="contact_id" name="contact_id" required>
                    <option value="">Choose a contact</option>
                    <?php foreach ($candidates as $candidate): ?>
                        <option value="<?= (int) $candidate['id'] ?>">
                            <?= e($memberName($candidate)) ?>
                            <?= !empty($candidate['email']) ? ' — ' . e($candidate['email']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="field-hint">
                    <?php if ($candidates === []): ?>
                        No contact is available to add. Everybody visible to you is already in a
                        household — <a href="<?= e(url('/contacts/new')) ?>">add a contact</a> first.
                    <?php else: ?>
                        The newest 200 contacts not already in this household. Somebody further
                        down the list is moved from their own record instead.
                    <?php endif; ?>
                </p>
                <?php if ($err = error_for('contact_id')): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-sm">Add to household</button>
            </div>
        </form>
    <?php endif; ?>
</section>

<?php if ($children !== []): ?>
    <section class="card">
        <div class="card-head">
            <h2>Children</h2>
            <p class="card-sub">Party operations only. Every message goes to the guardian.</p>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Birthday</th>
                    <th scope="col">Allergies</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($children as $child): ?>
                    <tr>
                        <td><strong><?= e($child['first_name']) ?></strong></td>
                        <td>
                            <?= !empty($child['birth_date'])
                                ? e(local_date((string) $child['birth_date']))
                                : '<span class="muted">Not known</span>' ?>
                        </td>
                        <td>
                            <?php if (!empty($child['allergies'])): ?>
                                <strong class="badge badge-warn"><?= e($child['allergies']) ?></strong>
                            <?php else: ?>
                                <span class="muted">None recorded</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
