<?php

declare(strict_types=1);

/**
 * Outbound webhooks.
 *
 * @var list<array<string,mixed>> $webhooks
 * @var array<string,string>      $events
 * @var list<array<string,mixed>> $deliveries
 * @var array{id:int,secret:string}|null $freshSecret
 * @var array<string,mixed>|null  $testResult
 * @var int                       $maxAttempts
 * @var int                       $disableAfter
 */

use App\Services\Webhooks;

/**
 * The words for a delivery's state, and the badge that carries them.
 *
 * `failed` and `gave_up` are deliberately not the same sentence: one means the
 * receiver read the call and rejected it, which is a problem at the far end
 * that resending will not fix, and the other means it never answered. A venue
 * reading this log has to take those two to different places.
 *
 * @return array{0:string,1:string}
 */
$statusBadge = static function (string $status, int $attempts, int $maxAttempts): array {
    switch ($status) {
        case 'delivered':
            return ['badge-ok', 'Delivered'];
        case 'failed':
            return ['badge-bad', 'Rejected'];
        case 'gave_up':
            return ['badge-bad', 'Gave up after ' . $maxAttempts];
        default:
            return [
                'badge-muted',
                $attempts === 0 ? 'Queued' : 'Retrying (' . $attempts . ' of ' . $maxAttempts . ')',
            ];
    }
};
?>

<?php if ($freshSecret !== null): ?>
    <section class="card card-accent">
        <div class="card-head">
            <div>
                <h2>Here is the signing secret. Copy it now.</h2>
                <p class="card-sub">
                    This is the only time it is shown. Your receiver needs it to check that a
                    call really came from Castle CRM &mdash; without that check, anybody who
                    learns the URL can make your integration believe a party was booked.
                </p>
            </div>
        </div>

        <?php /* A readonly input, not a <code> block: it can be selected with one
                 keyboard gesture and copied, which a block cannot. */ ?>
        <div class="field">
            <label for="fresh-secret">Signing secret</label>
            <input type="text" id="fresh-secret" class="token-reveal" readonly
                   value="<?= e((string) $freshSecret['secret']) ?>"
                   aria-describedby="fresh-secret-help">
            <p class="field-hint" id="fresh-secret-help">
                Store it wherever your receiver keeps its credentials. If you lose it, delete
                this subscription and make another &mdash; that takes ten seconds.
            </p>
        </div>
    </section>
<?php endif; ?>

<?php if ($testResult !== null): ?>
    <?php $testOk = (bool) ($testResult['ok'] ?? false); ?>
    <section class="card <?= $testOk ? 'card-accent' : 'card-danger' ?>">
        <div class="card-head">
            <div>
                <h2><?= $testOk ? 'The test call went through.' : 'The test call did not go through.' ?></h2>
                <p class="card-sub">
                    <?php if ($testOk): ?>
                        Your endpoint answered
                        <strong><?= e((string) ($testResult['status'] ?? '200')) ?></strong>
                        in <?= (int) ($testResult['ms'] ?? 0) ?>ms. It is in the log below like
                        any other delivery.
                    <?php else: ?>
                        <?php /* The reason in words, because a status code alone does not
                                 separate "connection refused" from "DNS does not resolve"
                                 from "that URL points inside the network" — and those
                                 three send somebody to three different places. */ ?>
                        <?= $testResult['status'] ?? null
                            ? 'Your endpoint answered <strong>' . e((string) $testResult['status']) . '</strong>.'
                            : 'Nothing answered.' ?>
                        <?= ($testResult['error'] ?? null) !== null
                            ? e((string) $testResult['error'])
                            : '' ?>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <?php if (($testResult['body'] ?? null) !== null && (string) $testResult['body'] !== ''): ?>
            <p class="field-hint">What it sent back:</p>
            <pre class="code-block"><code><?= e((string) $testResult['body']) ?></code></pre>
        <?php endif; ?>
    </section>
<?php endif; ?>

<section class="card">
    <div class="card-head">
        <div>
            <h2>Webhooks</h2>
            <p class="card-sub">
                A webhook tells something outside this application that something happened here
                &mdash; a booking was taken, a lead came in, a membership lapsed &mdash; the
                moment it happens, so nothing has to sit and poll for changes.
            </p>
        </div>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('/settings')) ?>">All settings</a>
    </div>

    <p class="muted">
        <strong>A call carries ids, not records.</strong> It tells your receiver <em>what</em>
        happened and <em>which</em> booking or contact it was about, and nothing else &mdash; no
        name, no email, no phone number. A receiver that needs the record reads it back through
        <a href="<?= e(url('/settings/api-tokens')) ?>"><code>/api/v1</code></a> with a token you
        can scope and revoke. That way a URL typed into this form can never become a standing
        export of guest records to somewhere nobody audits.
    </p>

    <?php if ($webhooks === []): ?>
        <p class="empty">No webhooks yet. Nothing outside this application is being told anything.</p>
    <?php else: ?>
        <div class="table-scroll">
            <table class="table">
                <caption class="sr-only">Webhook subscriptions</caption>
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Sends</th>
                        <th scope="col">To</th>
                        <th scope="col">Last sent</th>
                        <th scope="col">State</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($webhooks as $webhook): ?>
                        <?php
                        $id       = (int) $webhook['id'];
                        $isActive = (int) $webhook['is_active'] === 1;
                        $failures = (int) $webhook['consecutive_failures'];
                        ?>
                        <tr>
                            <th scope="row">
                                <?= e((string) ($webhook['name'] !== '' ? $webhook['name'] : 'Untitled')) ?>
                                <?php if (($webhook['created_by_name'] ?? null) !== null): ?>
                                    <span class="muted">by <?= e((string) $webhook['created_by_name']) ?></span>
                                <?php endif; ?>
                            </th>
                            <td><code><?= e((string) $webhook['event']) ?></code></td>
                            <td class="cell-wrap"><code><?= e((string) $webhook['target_url']) ?></code></td>
                            <td>
                                <?= $webhook['last_delivery_at'] === null
                                    ? '<span class="muted">Never</span>'
                                    : e(local_time((string) $webhook['last_delivery_at'])) ?>
                                <?php if ((int) $webhook['pending'] > 0): ?>
                                    <span class="badge badge-muted">
                                        <?= (int) $webhook['pending'] ?> waiting
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isActive): ?>
                                    <span class="badge badge-ok">On</span>
                                    <?php if ($failures > 0): ?>
                                        <?php /* Shown while it is still on, because this is the
                                                 window in which somebody can fix the endpoint
                                                 before it switches itself off. */ ?>
                                        <span class="badge badge-warn">
                                            <?= $failures ?> failed in a row
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge badge-muted">Off</span>
                                <?php endif; ?>

                                <?php if (($webhook['disabled_reason'] ?? null) !== null): ?>
                                    <p class="field-hint"><?= e((string) $webhook['disabled_reason']) ?></p>
                                <?php endif; ?>
                            </td>
                            <td class="cell-actions">
                                <form method="post" class="inline-form"
                                      action="<?= e(url('/settings/webhooks/' . $id . '/test')) ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-ghost btn-sm">Test</button>
                                </form>

                                <form method="post" class="inline-form"
                                      action="<?= e(url('/settings/webhooks/' . $id . '/toggle')) ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-ghost btn-sm">
                                        <?= $isActive ? 'Switch off' : 'Switch on' ?>
                                    </button>
                                </form>

                                <form method="post" class="inline-form"
                                      action="<?= e(url('/settings/webhooks/' . $id . '/delete')) ?>"
                                      data-confirm="Delete this webhook? Its delivery log goes with it.">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card-head">
        <div>
            <h2>Add a webhook</h2>
            <p class="card-sub">
                Paste the URL your integration gave you &mdash; a Zapier catch hook, a Make
                webhook, or something you have written yourself.
            </p>
        </div>
    </div>

    <form method="post" action="<?= e(url('/settings/webhooks')) ?>">
        <?= csrf_field() ?>

        <div class="field-row">
            <div class="field">
                <label for="name">What is it for?</label>
                <input type="text" id="name" name="name" maxlength="120" required
                       placeholder="Zapier: new bookings to the ops channel">
            </div>

            <div class="field">
                <label for="event">When should it fire?</label>
                <select id="event" name="event" required>
                    <?php foreach ($events as $slug => $label): ?>
                        <option value="<?= e((string) $slug) ?>">
                            <?= e((string) $label) ?> &mdash; <?= e((string) $slug) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="field-hint">
                    The <code>&hellip;.*</code> choices cover everything in that group, including
                    events added later &mdash; which is usually what an integration wants.
                </p>
            </div>
        </div>

        <div class="field">
            <label for="target_url">Where should it be sent?</label>
            <input type="url" id="target_url" name="target_url" maxlength="500" required
                   inputmode="url" placeholder="https://hooks.example.com/abc123"
                   aria-describedby="target-url-help">
            <p class="field-hint" id="target-url-help">
                Must be a public <code>https://</code> address. A URL pointing at this server or
                inside your own network is refused when you save it, not quietly at four in the
                morning.
            </p>
        </div>

        <button type="submit" class="btn">Add it</button>
    </form>
</section>

<section class="card">
    <div class="card-head">
        <div>
            <h2>Recent deliveries</h2>
            <p class="card-sub">
                Every attempt, newest first. A failed delivery retries on its own
                &mdash; <?= $maxAttempts ?> attempts over about nine hours, spaced further apart
                each time. <strong>Replay</strong> sends one again by hand.
            </p>
        </div>
    </div>

    <?php if ($deliveries === []): ?>
        <p class="empty">Nothing has been sent yet.</p>
    <?php else: ?>
        <div class="table-scroll">
            <table class="table">
                <caption class="sr-only">Recent webhook deliveries</caption>
                <thead>
                    <tr>
                        <th scope="col">When</th>
                        <th scope="col">Webhook</th>
                        <th scope="col">Event</th>
                        <th scope="col">Result</th>
                        <th scope="col">Took</th>
                        <th scope="col"><span class="sr-only">Replay</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($deliveries as $delivery): ?>
                        <?php
                        [$badgeClass, $badgeText] = $statusBadge(
                            (string) $delivery['status'],
                            (int) $delivery['attempts'],
                            $maxAttempts
                        );
                        ?>
                        <tr>
                            <th scope="row"><?= e(local_time((string) $delivery['created_at'])) ?></th>
                            <td>
                                <?= e((string) ($delivery['webhook_name'] ?? 'deleted')) ?>
                            </td>
                            <td><code><?= e((string) $delivery['event']) ?></code></td>
                            <td>
                                <span class="badge <?= e($badgeClass) ?>"><?= e($badgeText) ?></span>
                                <?php if (($delivery['response_code'] ?? null) !== null): ?>
                                    <span class="muted">HTTP <?= (int) $delivery['response_code'] ?></span>
                                <?php endif; ?>
                                <?php if (($delivery['error'] ?? null) !== null): ?>
                                    <p class="field-hint"><?= e((string) $delivery['error']) ?></p>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= ($delivery['duration_ms'] ?? null) === null
                                    ? '<span class="muted">&mdash;</span>'
                                    : ((int) $delivery['duration_ms'] . 'ms') ?>
                            </td>
                            <td>
                                <?php /* Only once it has finished: replaying something still in
                                         the queue would send the same body twice for no reason. */ ?>
                                <?php if ((string) $delivery['status'] !== 'pending'): ?>
                                    <form method="post" class="inline-form"
                                          action="<?= e(url(
                                              '/settings/webhooks/deliveries/'
                                              . (int) $delivery['id'] . '/replay'
                                          )) ?>">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-ghost btn-sm">Replay</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card-head"><div><h2>Checking a call really came from here</h2></div></div>

    <p>
        Every call carries three headers. <code><?= e(Webhooks::SIGNATURE_HEADER) ?></code> is an
        HMAC-SHA256 of the <strong>raw request body</strong>, with the timestamp mixed in so a
        captured call cannot be replayed tomorrow:
    </p>

    <pre class="code-block"><code>signed  = "<?= e(Webhooks::VERSION) ?>." + timestamp + "." + raw_body
header  = "<?= e(Webhooks::VERSION) ?>=" + hmac_sha256(signed, your_secret)</code></pre>

    <p class="muted">
        <strong>Verify the bytes you received</strong>, not a re-encoded copy. JSON key order and
        unicode escaping are not stable between languages, so a receiver that parses and
        re-serialises before checking computes a different string and every signature fails.
    </p>

    <ul class="list-plain">
        <li>
            <code><?= e(Webhooks::TIMESTAMP_HEADER) ?></code> &mdash; Unix seconds. Reject anything
            more than about five minutes old.
        </li>
        <li>
            <code><?= e(Webhooks::DELIVERY_HEADER) ?></code> &mdash; a stable id, the same across
            every retry and every replay. <strong>Deduplicate on it</strong>, or a retry will be
            counted twice.
        </li>
        <li>
            <code><?= e(Webhooks::EVENT_HEADER) ?></code> &mdash; the event name, so a receiver can
            branch without parsing the body first.
        </li>
    </ul>

    <p class="muted">
        Answer <code>2xx</code> as soon as you have stored the call and do the work afterwards.
        Anything else is retried; a <code>4xx</code> other than <code>408</code> or
        <code>429</code> is taken as a refusal and not retried at all. After
        <?= $disableAfter ?> deliveries in a row fail, the subscription switches itself off and
        says so here rather than retrying a dead endpoint for ever.
    </p>
</section>
