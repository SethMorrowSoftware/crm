<?php

declare(strict_types=1);

/**
 * The form builder.
 *
 * Deliberately a plain HTML form with a fixed number of question rows rather
 * than a drag-and-drop builder: there is no build step and no framework here,
 * and a repeater that needs JavaScript would stop working the moment the
 * Content-Security-Policy did its job. Rows with a blank label are ignored on
 * save, so the spare ones cost nothing.
 *
 * @var array<string,mixed>|null        $form
 * @var list<array<string,mixed>>       $fields
 * @var int                             $blankRows
 * @var array<string,string>            $types
 * @var array<string,string>            $mapsTo
 * @var array<string,string>            $consentMap
 * @var int                             $submissions
 * @var array<string,list<string>>      $errors
 */

$isEdit = $form !== null;
$action = $isEdit ? '/forms/' . (int) $form['id'] : '/forms';

/**
 * The first validation message for a field, read from the shared $errors that
 * app/bootstrap.php pulls out of the session for exactly one request.
 */
$errorBag = is_array($errors ?? null) ? $errors : [];

$errorFor = static function (string $field) use ($errorBag): ?string {
    $list = $errorBag[$field] ?? null;

    if (is_array($list) && $list !== []) {
        return (string) $list[0];
    }

    return is_string($list) && $list !== '' ? $list : null;
};

// Existing questions first, then spare rows. `old()` wins over both, so a
// refused save comes back with what was typed rather than with what is stored.
$rows = [];

foreach ($fields as $field) {
    $options = json_decode((string) ($field['options'] ?? ''), true);

    $rows[] = [
        'label'       => (string) $field['label'],
        'type'        => (string) $field['type'],
        'maps_to'     => (string) ($field['maps_to'] ?? ''),
        'help_text'   => (string) ($field['help_text'] ?? ''),
        'options'     => is_array($options) ? implode("\n", array_map('strval', $options)) : '',
        'is_required' => (int) $field['is_required'] === 1,
    ];
}

for ($i = 0; $i < $blankRows; $i++) {
    $rows[] = ['label' => '', 'type' => 'text', 'maps_to' => '', 'help_text' => '', 'options' => '', 'is_required' => false];
}

$oldRows = old('fields');
$oldRows = is_array($oldRows) ? $oldRows : [];

?>
<p class="breadcrumbs"><a href="<?= e(url('/forms')) ?>">Web forms</a> /
    <?= $isEdit ? e($form['name']) : 'New form' ?></p>

<form method="post" action="<?= e(url($action)) ?>" class="card form">
    <?= csrf_field() ?>

    <p class="form-section-title">The form itself</p>

    <div class="field-row">
        <div class="field">
            <label for="name">Name</label>
            <input type="text" id="name" name="name" required maxlength="120"
                   value="<?= e(old('name') ?: ($form['name'] ?? '')) ?>">
            <p class="field-hint">What staff call it. Guests never see this.</p>
            <?php if ($message = $errorFor('name')): ?>
                <p class="field-error"><?= e($message) ?></p>
            <?php endif; ?>
        </div>
        <div class="field">
            <label for="slug">Public address</label>
            <input type="text" id="slug" name="slug" required maxlength="64" pattern="[a-z0-9\-]+"
                   value="<?= e(old('slug') ?: ($form['slug'] ?? '')) ?>">
            <p class="field-hint">
                Lowercase letters, numbers and hyphens. The form is served at
                <code class="code-inline">/f/your-address</code>.
            </p>
            <?php if ($message = $errorFor('slug')): ?>
                <p class="field-error"><?= e($message) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="field">
        <label for="title">Heading shown to the guest</label>
        <input type="text" id="title" name="title" maxlength="191"
               value="<?= e(old('title') ?: ($form['title'] ?? '')) ?>">
        <p class="field-hint">Left blank, the name above is used.</p>
    </div>

    <div class="field">
        <label for="description">Introduction</label>
        <textarea id="description" name="description" rows="3" maxlength="2000"><?= e(old('description') ?: ($form['description'] ?? '')) ?></textarea>
        <p class="field-hint">One or two sentences above the questions.</p>
    </div>

    <div class="field-row">
        <div class="field">
            <label for="submit_label">Button label</label>
            <input type="text" id="submit_label" name="submit_label" maxlength="60"
                   value="<?= e(old('submit_label') ?: ($form['submit_label'] ?? 'Send')) ?>">
        </div>
        <div class="field">
            <label for="redirect_url">Send them to this address afterwards</label>
            <input type="url" id="redirect_url" name="redirect_url" maxlength="255"
                   value="<?= e(old('redirect_url') ?: ($form['redirect_url'] ?? '')) ?>">
            <p class="field-hint">Optional. Blank shows the thank-you message below instead.</p>
            <?php if ($message = $errorFor('redirect_url')): ?>
                <p class="field-error"><?= e($message) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="field">
        <label for="success_message">Thank-you message</label>
        <textarea id="success_message" name="success_message" rows="2" maxlength="2000"><?= e(old('success_message') ?: ($form['success_message'] ?? '')) ?></textarea>
    </div>

    <div class="field field-check">
        <input type="checkbox" id="is_active" name="is_active" value="1"
            <?= (int) ($form['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
        <label for="is_active">Live</label>
        <p class="field-hint">
            Unticked, the public address answers exactly as though the form had never existed. This
            is how you take a form off the website without losing the submissions it already has.
        </p>
    </div>

    <p class="form-section-title">Questions</p>

    <p class="field-hint">
        Leave a label blank to skip the row. One question should map to the guest's email address
        or phone number — without one, a submission is stored but cannot become a contact anybody
        can reply to. A <strong>marketing opt-in</strong> records consent, with the wording below
        it kept as the evidence; every other question records nothing of the kind.
    </p>

    <?php foreach ($rows as $i => $row): ?>
        <?php
        $stored = is_array($oldRows[$i] ?? null) ? $oldRows[$i] : [];

        $value = static function (string $key, mixed $fallback) use ($stored): mixed {
            return array_key_exists($key, $stored) ? $stored[$key] : $fallback;
        };

        $label    = (string) $value('label', $row['label']);
        $type     = (string) $value('type', $row['type']);
        $mapped   = (string) $value('maps_to', $row['maps_to']);
        $help     = (string) $value('help_text', $row['help_text']);
        $options  = (string) $value('options', $row['options']);
        $required = $stored === [] ? $row['is_required'] : isset($stored['is_required']);
        ?>
        <fieldset class="card card-accent">
            <legend>Question <?= (int) $i + 1 ?></legend>

            <div class="field-row">
                <div class="field">
                    <label for="q<?= (int) $i ?>_label">Label</label>
                    <input type="text" id="q<?= (int) $i ?>_label" name="fields[<?= (int) $i ?>][label]"
                           maxlength="191" value="<?= e($label) ?>">
                </div>
                <div class="field">
                    <label for="q<?= (int) $i ?>_type">Type</label>
                    <select id="q<?= (int) $i ?>_type" name="fields[<?= (int) $i ?>][type]">
                        <?php foreach ($types as $key => $description): ?>
                            <option value="<?= e($key) ?>" <?= $type === $key ? 'selected' : '' ?>>
                                <?= e($description) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="field-row">
                <div class="field">
                    <label for="q<?= (int) $i ?>_maps">Writes to</label>
                    <select id="q<?= (int) $i ?>_maps" name="fields[<?= (int) $i ?>][maps_to]">
                        <option value="" <?= $mapped === '' ? 'selected' : '' ?>>
                            Nothing — keep the answer on the submission only
                        </option>
                        <?php foreach ($mapsTo as $key => $description): ?>
                            <option value="<?= e($key) ?>" <?= $mapped === $key ? 'selected' : '' ?>>
                                <?= e($description) ?>
                            </option>
                        <?php endforeach; ?>
                        <?php foreach ($consentMap as $key => $channel): ?>
                            <option value="<?= e($key) ?>" <?= $mapped === $key ? 'selected' : '' ?>>
                                Consent: <?= e($channel) ?> marketing (opt-in only)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="field-hint">
                        An existing contact's details are never overwritten — only blank fields are
                        filled in.
                    </p>
                </div>
                <div class="field">
                    <label for="q<?= (int) $i ?>_help">Hint under the question</label>
                    <input type="text" id="q<?= (int) $i ?>_help" name="fields[<?= (int) $i ?>][help_text]"
                           maxlength="500" value="<?= e($help) ?>">
                    <p class="field-hint">
                        For a marketing opt-in this is the wording kept as consent evidence, so say
                        what you will send and how often.
                    </p>
                </div>
            </div>

            <div class="field">
                <label for="q<?= (int) $i ?>_options">List options</label>
                <textarea id="q<?= (int) $i ?>_options" name="fields[<?= (int) $i ?>][options]"
                          rows="3"><?= e($options) ?></textarea>
                <p class="field-hint">One per line. Only used by "choose one from a list", which needs at least two.</p>
            </div>

            <div class="field field-check">
                <input type="checkbox" id="q<?= (int) $i ?>_required"
                       name="fields[<?= (int) $i ?>][is_required]" value="1" <?= $required ? 'checked' : '' ?>>
                <label for="q<?= (int) $i ?>_required">Required</label>
            </div>
        </fieldset>
    <?php endforeach; ?>

    <div class="form-actions">
        <button type="submit" class="btn"><?= $isEdit ? 'Save the form' : 'Create the form' ?></button>
        <a class="btn btn-ghost" href="<?= e(url('/forms')) ?>">Cancel</a>
    </div>
</form>

<?php if ($isEdit): ?>
    <section class="card">
        <h2>Submissions</h2>
        <p>
            <?= (int) ($submissions ?? 0) ?> submission<?= (int) ($submissions ?? 0) === 1 ? '' : 's' ?> so far.
        </p>
        <p>
            <a class="btn btn-ghost btn-sm"
               href="<?= e(url('/forms/' . (int) $form['id'] . '/submissions')) ?>">
                Read them
            </a>
        </p>
    </section>

    <section class="card card-danger">
        <h2>Delete this form</h2>
        <p>
            Deleting a form deletes its questions and <strong>every submission guests have sent
            through it</strong>, including the evidence behind any consent recorded with them. Once
            a form has been used, this is refused — untick "Live" above instead.
        </p>
        <form method="post" action="<?= e(url('/forms/' . (int) $form['id'] . '/delete')) ?>"
              data-confirm="Delete <?= e($form['name']) ?>? This cannot be undone.">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-danger"><?= icon('trash', 15) ?> Delete form</button>
        </form>
    </section>
<?php endif; ?>
