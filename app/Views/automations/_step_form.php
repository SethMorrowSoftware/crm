<?php

declare(strict_types=1);

/**
 * One step's fields, for adding and for editing.
 *
 * Every field a step type could want is here, each labelled with the steps it
 * belongs to and hidden by `initStepFields()` when another type is chosen.
 * Without JavaScript they are all visible and the form still saves correctly:
 * the server reads only the keys the chosen type declares.
 *
 * @var array<string,array{label:string,needs:list<string>}> $stepTypes
 * @var list<string>              $delayTypes
 * @var list<array<string,mixed>> $templates
 * @var list<array<string,mixed>> $users
 * @var list<array<string,mixed>> $steps
 * @var array<string,mixed>|null  $step      The step being edited, or null to add.
 * @var string                    $action
 * @var string                    $submitLabel
 */

$step   ??= null;
$config = [];

if ($step !== null) {
    $decoded = json_decode((string) ($step['config'] ?? '{}'), true);
    $config  = is_array($decoded) ? $decoded : [];
}

$value = static function (string $key, string $default = '') use ($config): string {
    $found = $config[$key] ?? null;

    if (is_array($found)) {
        return '';
    }

    return $found === null ? $default : (string) $found;
};

// `config.rules` is a GROUP — see the note in AutomationController — so the
// one rule this form edits is the first inside it.
$rule = is_array($config['rules']['rules'][0] ?? null) ? $config['rules']['rules'][0] : [];

$delayConfig = [];

if ($step !== null) {
    $decodedDelay = json_decode((string) ($step['delay_until_config'] ?? '{}'), true);
    $delayConfig  = is_array($decodedDelay) ? $decodedDelay : [];
}

$delay = static function (string $key) use ($delayConfig): string {
    $found = $delayConfig[$key] ?? null;

    return $found === null || is_array($found) ? '' : (string) $found;
};

// A condition's children hang off it, so only condition steps can be parents.
$parents = array_values(array_filter(
    $steps,
    static fn (array $candidate): bool => in_array((string) $candidate['type'], ['condition', 'split_test'], true)
        && ($step === null || (int) $candidate['id'] !== (int) $step['id'])
));
?>
<form method="post" action="<?= e(url($action)) ?>" class="stacked-form" data-step-form>
    <?= csrf_field() ?>

    <div class="field">
        <label for="type-<?= e((string) ($step['id'] ?? 'new')) ?>">What the step does</label>
        <select id="type-<?= e((string) ($step['id'] ?? 'new')) ?>" name="type" data-step-type required>
            <?php foreach ($stepTypes as $key => $meta): ?>
                <option value="<?= e($key) ?>"
                    <?= (string) ($step['type'] ?? 'send_email') === $key ? 'selected' : '' ?>>
                    <?= e($meta['label']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- ── send_email / send_sms ─────────────────────────────────────── -->
    <div class="field" data-for="send_email send_sms">
        <label for="template-<?= e((string) ($step['id'] ?? 'new')) ?>">Template</label>
        <select id="template-<?= e((string) ($step['id'] ?? 'new')) ?>" name="config_template" data-required>
            <option value="">Choose a template…</option>
            <?php foreach ($templates as $template): ?>
                <option value="<?= e($template['slug']) ?>"
                    <?= $value('template') === (string) $template['slug'] ? 'selected' : '' ?>>
                    <?= e($template['name']) ?> (<?= e($template['channel']) ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <p class="form-hint">
            Only active templates are listed. A message goes out through the ordinary send path, so
            consent, quiet hours and the weekly cap all apply.
        </p>
    </div>

    <div class="field" data-for="send_email send_sms">
        <label for="kind-<?= e((string) ($step['id'] ?? 'new')) ?>">Kind</label>
        <select id="kind-<?= e((string) ($step['id'] ?? 'new')) ?>" name="config_kind">
            <option value="">Marketing (needs their opt-in)</option>
            <option value="transactional" <?= $value('kind') === 'transactional' ? 'selected' : '' ?>>
                Transactional (about a booking they made)
            </option>
        </select>
        <p class="form-hint">
            Transactional means a message about something the guest has already done — a party
            confirmation, a waiver reminder. Anything that offers, promotes or invites is marketing,
            whatever it is about.
        </p>
    </div>

    <!-- ── create_task / notify_user ─────────────────────────────────── -->
    <div class="field" data-for="create_task">
        <label for="title-<?= e((string) ($step['id'] ?? 'new')) ?>">Task title</label>
        <input type="text" id="title-<?= e((string) ($step['id'] ?? 'new')) ?>" name="config_title"
               value="<?= e($value('title')) ?>" maxlength="191" data-required>
        <p class="form-hint">Merge tags work here: {{first_name}}, {{venue_name}}.</p>
    </div>

    <div class="field" data-for="create_task">
        <label for="due-<?= e((string) ($step['id'] ?? 'new')) ?>">Due in (hours)</label>
        <input type="number" id="due-<?= e((string) ($step['id'] ?? 'new')) ?>" name="config_due_in_hours"
               value="<?= e($value('due_in_hours')) ?>" min="0" max="8760">
    </div>

    <div class="field" data-for="notify_user">
        <label for="message-<?= e((string) ($step['id'] ?? 'new')) ?>">What to tell them</label>
        <textarea id="message-<?= e((string) ($step['id'] ?? 'new')) ?>" name="config_message"
                  rows="3" maxlength="2000" data-required><?= e($value('message')) ?></textarea>
    </div>

    <div class="field" data-for="create_task notify_user">
        <label for="assign-<?= e((string) ($step['id'] ?? 'new')) ?>">Who</label>
        <select id="assign-<?= e((string) ($step['id'] ?? 'new')) ?>" name="config_assign_to">
            <option value="owner">The owner</option>
            <?php foreach ($users as $user): ?>
                <option value="<?= (int) $user['id'] ?>"
                    <?= $value('assign_to') === (string) $user['id'] ? 'selected' : '' ?>>
                    <?= e($user['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- ── tags and fields ──────────────────────────────────────────── -->
    <div class="field" data-for="add_tag remove_tag">
        <label for="tag-<?= e((string) ($step['id'] ?? 'new')) ?>">Tag</label>
        <input type="text" id="tag-<?= e((string) ($step['id'] ?? 'new')) ?>" name="config_tag"
               value="<?= e($value('tag')) ?>" maxlength="80" data-required>
    </div>

    <div class="field" data-for="update_field">
        <label for="field-<?= e((string) ($step['id'] ?? 'new')) ?>">Field</label>
        <select id="field-<?= e((string) ($step['id'] ?? 'new')) ?>" name="config_field" data-required>
            <?php foreach (['lifecycle_stage', 'source', 'notes', 'owner_id', 'do_not_contact'] as $field): ?>
                <option value="<?= e($field) ?>" <?= $value('field') === $field ? 'selected' : '' ?>>
                    <?= e($field) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="form-hint">
            Consent columns are deliberately missing: an automation must not be able to grant
            somebody consent on their behalf.
        </p>
    </div>

    <div class="field" data-for="update_field">
        <label for="fvalue-<?= e((string) ($step['id'] ?? 'new')) ?>">Set it to</label>
        <input type="text" id="fvalue-<?= e((string) ($step['id'] ?? 'new')) ?>" name="config_value"
               value="<?= e($value('value')) ?>" maxlength="191" data-required>
    </div>

    <!-- ── deals, coupons, webhooks ─────────────────────────────────── -->
    <div class="field" data-for="move_deal_stage">
        <label for="stage-<?= e((string) ($step['id'] ?? 'new')) ?>">Stage</label>
        <input type="text" id="stage-<?= e((string) ($step['id'] ?? 'new')) ?>" name="config_stage"
               value="<?= e($value('stage')) ?>" maxlength="120" data-required>
        <p class="form-hint">
            By name, matched inside the deal's own pipeline. A stage in another pipeline is refused:
            moving a party's deal onto the corporate board takes it out of every report that names
            the pipeline it belongs to.
        </p>
    </div>

    <div class="field" data-for="issue_coupon">
        <label for="code-<?= e((string) ($step['id'] ?? 'new')) ?>">Offer code</label>
        <input type="text" id="code-<?= e((string) ($step['id'] ?? 'new')) ?>" name="config_code"
               value="<?= e($value('code')) ?>" maxlength="40" data-required>
        <p class="form-hint">
            An offer the venue has already created. This records that the guest has been GIVEN it —
            the redemption is recorded when they use it — and it honours the offer's own
            once-per-guest rule, so re-running a playbook cannot hand out a second one.
        </p>
    </div>

    <div class="field" data-for="send_survey">
        <label for="survey-<?= e((string) ($step['id'] ?? 'new')) ?>">Survey (optional)</label>
        <input type="text" id="survey-<?= e((string) ($step['id'] ?? 'new')) ?>" name="config_survey"
               value="<?= e($value('survey')) ?>" maxlength="120">
        <p class="form-hint">
            By name, as it appears on the Reputation page. Leave it blank and the survey whose own
            trigger matches this sequence is used. The invitation carries a one-use link and is
            never sent twice: if the survey is also switched on there, whichever gets there first
            wins and this step reports that they had already been asked.
        </p>
    </div>

    <div class="field" data-for="webhook">
        <label for="url-<?= e((string) ($step['id'] ?? 'new')) ?>">URL</label>
        <input type="url" id="url-<?= e((string) ($step['id'] ?? 'new')) ?>" name="config_url"
               value="<?= e($value('url')) ?>" maxlength="255" data-required>
        <p class="form-hint">
            Must be <code>https://</code>: this is the one step that sends anything outside the
            venue, and plain http would put it on the wire in clear. Called with the automation, the
            run and the guest's id — not their details. Addresses inside this network are refused,
            and the call is given ten seconds.
        </p>
    </div>

    <div class="field" data-for="webhook">
        <label for="secret-<?= e((string) ($step['id'] ?? 'new')) ?>">Shared secret (optional)</label>
        <input type="text" id="secret-<?= e((string) ($step['id'] ?? 'new')) ?>" name="config_secret"
               value="<?= e($value('secret')) ?>" maxlength="120">
        <p class="form-hint">Sent as a signature header so the receiver can check the call came from here.</p>
    </div>

    <!--
        ── branching ──────────────────────────────────────────────────

        THE CONDITION'S THREE FIELDS ARE NAMED `rule_*`, NOT `config_*`.

        A hidden field still submits, and `update_field` already has a
        `config_field` and a `config_value` on this same form. Sharing the
        names means the LAST one in the document wins — so choosing "Set a
        field" and filling it in saved an empty value, from the condition row
        nobody had touched. Found by driving the form; it cost a save that
        reported success and set the field to nothing.
    -->
    <div class="field" data-for="condition">
        <label for="cfield-<?= e((string) ($step['id'] ?? 'new')) ?>">Branch when</label>
        <div class="field-row">
            <input type="text" id="cfield-<?= e((string) ($step['id'] ?? 'new')) ?>" name="rule_field"
                   value="<?= e((string) ($rule['field'] ?? '')) ?>" placeholder="lifecycle_stage" maxlength="60">
            <select name="rule_operator" aria-label="Comparison">
                <?php foreach (App\Services\AutomationEngine::OPERATORS as $operator): ?>
                    <option value="<?= e($operator) ?>"
                        <?= (string) ($rule['op'] ?? 'is') === $operator ? 'selected' : '' ?>>
                        <?= e(str_replace('_', ' ', $operator)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="rule_value" value="<?= e((string) ($rule['value'] ?? '')) ?>"
                   placeholder="customer" maxlength="191" aria-label="Value to compare with">
        </div>
        <p class="form-hint">
            Steps you then add inside the YES or NO branch run for that side only, and both sides
            rejoin whatever follows this step.
        </p>
    </div>

    <div class="field" data-for="split_test">
        <label for="percent-<?= e((string) ($step['id'] ?? 'new')) ?>">Percent down the A side</label>
        <input type="number" id="percent-<?= e((string) ($step['id'] ?? 'new')) ?>" name="config_percent"
               value="<?= e($value('percent', '50')) ?>" min="1" max="99" data-required>
    </div>

    <!-- ── every step ───────────────────────────────────────────────── -->
    <fieldset class="fieldset">
        <legend>When it runs</legend>

        <div class="field">
            <label for="delay-<?= e((string) ($step['id'] ?? 'new')) ?>">Wait first (minutes)</label>
            <input type="number" id="delay-<?= e((string) ($step['id'] ?? 'new')) ?>" name="delay_minutes"
                   value="<?= e((string) ($step['delay_minutes'] ?? 0)) ?>" min="0" max="525600">
            <p class="form-hint">1440 is a day. 10080 is a week.</p>
        </div>

        <div class="field">
            <label for="dtype-<?= e((string) ($step['id'] ?? 'new')) ?>">Or tie it to</label>
            <select id="dtype-<?= e((string) ($step['id'] ?? 'new')) ?>" name="delay_until_type">
                <option value="">Nothing — just the wait above</option>
                <?php foreach ($delayTypes as $delayType): ?>
                    <?php if ($delayType === 'minutes') { continue; } ?>
                    <option value="<?= e($delayType) ?>"
                        <?= (string) ($step['delay_until_type'] ?? '') === $delayType ? 'selected' : '' ?>>
                        <?= e(match ($delayType) {
                            'time_of_day'       => 'A time of day',
                            'days_before_field' => 'N days before a date on the record',
                            'days_after_field'  => 'N days after a date on the record',
                            default             => $delayType,
                        }) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="dat-<?= e((string) ($step['id'] ?? 'new')) ?>">At (time of day)</label>
            <input type="time" id="dat-<?= e((string) ($step['id'] ?? 'new')) ?>" name="delay_at"
                   value="<?= e($delay('at')) ?>">
            <p class="form-hint">
                The venue's own clock, not the server's — so a step set for 10am stays at 10am
                through a daylight-saving change rather than landing an hour out twice a year.
            </p>
        </div>

        <div class="field">
            <label for="dfield-<?= e((string) ($step['id'] ?? 'new')) ?>">Counting from which date</label>
            <input type="text" id="dfield-<?= e((string) ($step['id'] ?? 'new')) ?>" name="delay_field"
                   value="<?= e($delay('field')) ?>" maxlength="60" placeholder="event_date">
            <p class="form-hint">
                A date on the record the run is about: <code>event_date</code> is the party's own
                day, <code>birth_date</code> the guest's birthday.
            </p>
        </div>

        <div class="field">
            <label for="ddays-<?= e((string) ($step['id'] ?? 'new')) ?>">How many days</label>
            <input type="number" id="ddays-<?= e((string) ($step['id'] ?? 'new')) ?>" name="delay_days"
                   value="<?= e($delay('days')) ?>" min="0" max="730">
        </div>
    </fieldset>

    <fieldset class="fieldset">
        <legend>Where it sits</legend>

        <div class="field">
            <label for="branch-<?= e((string) ($step['id'] ?? 'new')) ?>">Branch</label>
            <select id="branch-<?= e((string) ($step['id'] ?? 'new')) ?>" name="branch">
                <option value="main" <?= (string) ($step['branch'] ?? 'main') === 'main' ? 'selected' : '' ?>>
                    The main line
                </option>
                <option value="yes" <?= (string) ($step['branch'] ?? '') === 'yes' ? 'selected' : '' ?>>
                    The YES side of a condition
                </option>
                <option value="no" <?= (string) ($step['branch'] ?? '') === 'no' ? 'selected' : '' ?>>
                    The NO side of a condition
                </option>
            </select>
        </div>

        <?php if ($parents !== []): ?>
            <div class="field">
                <label for="parent-<?= e((string) ($step['id'] ?? 'new')) ?>">Inside which condition</label>
                <select id="parent-<?= e((string) ($step['id'] ?? 'new')) ?>" name="parent_step_id">
                    <option value="0">Not inside one</option>
                    <?php foreach ($parents as $parent): ?>
                        <option value="<?= (int) $parent['id'] ?>"
                            <?= (int) ($step['parent_step_id'] ?? 0) === (int) $parent['id'] ? 'selected' : '' ?>>
                            <?= e(App\Services\AutomationEngine::describeStep($parent)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

    </fieldset>

    <div class="form-actions">
        <button type="submit" class="btn"><?= e($submitLabel) ?></button>
    </div>
</form>
