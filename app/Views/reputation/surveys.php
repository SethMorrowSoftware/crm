<?php

declare(strict_types=1);

/**
 * Feedback settings: what is asked, of whom, and where happy guests are sent.
 *
 * @var list<array<string,mixed>> $surveys
 * @var array<string,mixed>       $settings
 * @var array<string,string>      $triggers
 * @var list<string>              $platforms
 * @var bool                      $canConfig
 * @var int                       $pending
 * @var string                    $surveyUrl
 */

$weights = $settings['weights'];
$live    = App\Services\Reputation::platformWeights($weights, $settings['links']);
$cycle   = App\Services\Reputation::rotation($live);
?>

<section class="card">
    <div class="card-head">
        <div>
            <h2>Surveys</h2>
            <p class="card-sub">
                One question, sent a set time after the visit. A survey with no trigger is
                never sent.
                <?php if ($pending > 0): ?>
                    <?= (int) $pending ?> invitation<?= $pending === 1 ? '' : 's' ?> waiting to go out
                    on the next cron run.
                <?php endif; ?>
            </p>
        </div>
        <a class="btn btn-ghost" href="<?= e(url('/reputation')) ?>">Back to reputation</a>
    </div>

    <?php foreach ($surveys as $survey): ?>
        <div class="setting-block">
            <h3><?= e((string) $survey['name']) ?></h3>
            <p class="muted">
                <?= (int) $survey['invited'] ?> asked, <?= (int) $survey['answered'] ?> answered.
                Scale: <?= (string) $survey['type'] === 'csat' ? '1 to 5 stars' : '0 to 10' ?>.
            </p>

            <?php if ($canConfig): ?>
                <form method="post" action="<?= e(url('/reputation/surveys/' . (int) $survey['id'])) ?>">
                    <?= csrf_field() ?>

                    <div class="field">
                        <label for="name-<?= (int) $survey['id'] ?>">Name</label>
                        <input type="text" id="name-<?= (int) $survey['id'] ?>" name="name"
                               value="<?= e((string) $survey['name']) ?>" maxlength="120" required>
                    </div>

                    <div class="field">
                        <label for="trigger-<?= (int) $survey['id'] ?>">When to ask</label>
                        <select id="trigger-<?= (int) $survey['id'] ?>" name="trigger_event">
                            <option value="">Never — this survey is not sent</option>
                            <?php foreach ($triggers as $value => $label): ?>
                                <option value="<?= e((string) $value) ?>"
                                    <?= (string) ($survey['trigger_event'] ?? '') === $value ? 'selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="delay-<?= (int) $survey['id'] ?>">Hours afterwards</label>
                        <input type="number" id="delay-<?= (int) $survey['id'] ?>" name="delay_hours"
                               value="<?= (int) $survey['delay_hours'] ?>" min="0" max="720" required>
                        <p class="field-hint">
                            Measured from the end of the party or the evening of the visit, so 20
                            hours means the next morning. Quiet hours still apply, so a text never
                            goes out overnight whatever this says.
                        </p>
                    </div>

                    <div class="field field-check">
                        <label>
                            <input type="checkbox" name="is_active" value="1"
                                <?= (int) $survey['is_active'] === 1 ? 'checked' : '' ?>>
                            Running
                        </label>
                    </div>

                    <button type="submit" class="btn btn-sm">Save</button>
                </form>
            <?php else: ?>
                <p class="muted">
                    <?= ($survey['trigger_event'] ?? null) === null
                        ? 'Not sent.'
                        : e((string) ($triggers[(string) $survey['trigger_event']] ?? $survey['trigger_event']))
                            . ', ' . (int) $survey['delay_hours'] . ' hours afterwards.' ?>
                </p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <?php if ($surveys === []): ?>
        <p class="empty">
            No surveys exist. The installer seeds two; if this is empty, the starting data
            was skipped.
        </p>
    <?php endif; ?>
</section>

<?php if ($canConfig): ?>
    <form method="post" action="<?= e(url('/reputation/settings')) ?>">
        <?= csrf_field() ?>

        <section class="card">
            <div class="card-head">
                <div>
                    <h2>Who gets asked for a public review</h2>
                </div>
            </div>

            <div class="field field-check">
                <label>
                    <input type="checkbox" name="ask_everyone" value="1"
                        <?= $settings['ask_everyone'] ? 'checked' : '' ?>>
                    Ask lukewarm guests too, not only the happiest
                </label>
                <p class="field-hint">
                    Off by default: only guests who score 9 or 10 (or 4&ndash;5 stars) are sent
                    to a review site. Worth knowing before you decide &mdash; Google's review
                    policy asks businesses not to solicit reviews selectively from happy
                    customers only, so switching this on is the more conservative choice by
                    that measure, at the cost of some lukewarm reviews.
                    <strong>An unhappy guest is never asked either way.</strong>
                </p>
            </div>

            <div class="field field-check">
                <label>
                    <input type="checkbox" name="auto_escalate" value="1"
                        <?= $settings['auto_escalate'] ? 'checked' : '' ?>>
                    Raise a task and alert a manager when somebody scores low
                </label>
                <p class="field-hint">
                    On by default, and independent of any automation &mdash; a complaint reaches
                    somebody whether or not the reputation playbook is switched on.
                </p>
            </div>

            <div class="field">
                <label for="sla_hours">Hours to deal with a complaint</label>
                <input type="number" id="sla_hours" name="sla_hours" min="1" max="168"
                       value="<?= (int) $settings['sla_hours'] ?>">
            </div>
        </section>

        <section class="card">
            <div class="card-head">
                <div>
                    <h2>Where happy guests are sent</h2>
                    <p class="card-sub">
                        A platform with no link is never used. Weights spread the asks around
                        rather than sending everybody to the same place.
                    </p>
                </div>
            </div>

            <?php foreach ($platforms as $platform): ?>
                <div class="field">
                    <label for="link_<?= e($platform) ?>">
                        <?= e(App\Services\Reputation::platformName($platform)) ?> review link
                    </label>
                    <input type="url" id="link_<?= e($platform) ?>" name="link_<?= e($platform) ?>"
                           value="<?= e((string) $settings['links'][$platform]) ?>"
                           placeholder="https://..." maxlength="500">
                    <label class="inline-weight" for="weight_<?= e($platform) ?>">
                        Share
                        <input type="number" id="weight_<?= e($platform) ?>" name="weight_<?= e($platform) ?>"
                               min="0" max="99" value="<?= (int) $weights[$platform] ?>">
                    </label>
                </div>
            <?php endforeach; ?>

            <?php if ($cycle === []): ?>
                <div class="alert alert-warning">
                    <?= icon('alert') ?>
                    <p>
                        <strong>No review link is set, so no guest will be asked.</strong>
                        They are still thanked, and their score is still recorded &mdash; a link
                        to a listing you do not have would be worse than no ask.
                    </p>
                </div>
            <?php else: ?>
                <p class="field-hint">
                    The next <?= count($cycle) ?> happy guests go to:
                    <?= e(implode(', ', array_map(
                        static fn (string $p): string => App\Services\Reputation::platformName($p),
                        $cycle
                    ))) ?>, then it repeats.
                </p>
            <?php endif; ?>
        </section>

        <section class="card">
            <div class="card-head">
                <div>
                    <h2>How the question is sent</h2>
                </div>
            </div>

            <div class="field">
                <label for="invite_channel">Channel</label>
                <select id="invite_channel" name="invite_channel">
                    <option value="email" <?= $settings['invite_channel'] === 'email' ? 'selected' : '' ?>>Email</option>
                    <option value="sms" <?= $settings['invite_channel'] === 'sms' ? 'selected' : '' ?>>Text message</option>
                </select>
                <p class="field-hint">
                    Either way it goes as marketing, so a guest who has opted out is not
                    asked. The link looks like <code><?= e($surveyUrl) ?></code>.
                </p>
            </div>

            <div class="field">
                <label for="invite_lookback_days">Only ask about visits in the last</label>
                <input type="number" id="invite_lookback_days" name="invite_lookback_days"
                       min="1" max="90" value="<?= (int) $settings['lookback_days'] ?>">
                <p class="field-hint">
                    Days. This is a safety bound, not a preference: without it, switching
                    feedback on would email every family who has ever had a party here. Raise
                    it only deliberately, and watch the first run.
                </p>
            </div>
        </section>

        <section class="card">
            <div class="card-head">
                <div>
                    <h2>The public review wall</h2>
                    <p class="card-sub">
                        An embeddable page of the reviews you have chosen to feature:
                        <a href="<?= e(url('/reviews')) ?>" target="_blank" rel="noopener"><?= e(url('/reviews')) ?></a>
                    </p>
                </div>
            </div>

            <div class="field field-check">
                <label>
                    <input type="checkbox" name="wall_enabled" value="1"
                        <?= $settings['wall_enabled'] ? 'checked' : '' ?>>
                    Serve the review wall
                </label>
            </div>

            <div class="field">
                <label for="wall_min_rating">Lowest rating that may be featured</label>
                <input type="number" id="wall_min_rating" name="wall_min_rating" min="1" max="5"
                       value="<?= (int) $settings['wall_min_rating'] ?>">
                <p class="field-hint">
                    A floor on what you are allowed to feature, not a filter that promotes
                    things on its own &mdash; reviews only appear on the wall when somebody
                    features them.
                </p>
            </div>

            <div class="field">
                <label for="embed">Embed code for the venue's website</label>
                <?php
                /*
                 * The listener is part of the snippet rather than something
                 * the venue has to remember: without it the iframe keeps
                 * whatever height was guessed, which clips the last review or
                 * leaves a gap under it. It checks the origin, because a
                 * message handler that trusts any sender is a habit worth not
                 * teaching.
                 */
                $origin = rtrim(url('/'), '/');
                ?>
                <textarea id="embed" name="embed_code" rows="6" readonly><iframe id="castle-reviews" src="<?= e(url('/reviews?embed=1')) ?>" title="Guest reviews" style="width:100%;border:0" height="600" loading="lazy"></iframe>
<script>
window.addEventListener('message', function (e) {
  if (e.origin !== '<?= e($origin) ?>') return;
  var h = e.data && e.data.castleCrmEmbedHeight;
  if (h) document.getElementById('castle-reviews').height = h;
});
</script></textarea>
                <p class="field-hint">
                    Paste this into any page on the venue's website. It resizes itself to fit
                    the reviews.
                </p>
            </div>
        </section>

        <div class="form-actions">
            <button type="submit" class="btn">Save settings</button>
        </div>
    </form>
<?php endif; ?>
