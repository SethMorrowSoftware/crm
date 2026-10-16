<?php

declare(strict_types=1);

/**
 * The public form page at /f/{slug}.
 *
 * This is the only view in the application rendered to somebody with no
 * account, so two rules apply harder here than anywhere else:
 *
 *   1. It echoes only what FormController::show() hands it. That is the form's
 *      own presentation and its field definitions, each already reduced to the
 *      keys a guest may know (see publicField()). There is no row from
 *      form_submissions, no contact, and no user anywhere in scope.
 *   2. Every value goes through e(), including the operator-written heading,
 *      introduction and hints — those are typed by a member of staff into an
 *      admin form, which makes them untrusted input to this page like any
 *      other.
 *
 * @var array<string,string>      $form
 * @var list<array<string,mixed>> $fields
 * @var bool                      $sent
 * @var string                    $thanks
 * @var string                    $venueName
 * @var array<string,string>      $utm
 * @var array<string,list<string>> $errors
 */

$errorBag = is_array($errors ?? null) ? $errors : [];

$errorFor = static function (string $field) use ($errorBag): ?string {
    $list = $errorBag[$field] ?? null;

    if (is_array($list) && $list !== []) {
        return (string) $list[0];
    }

    return is_string($list) && $list !== '' ? $list : null;
};

$heading = $form['title'] !== '' ? $form['title'] : $form['name'];

?>
<?php if ($sent): ?>
    <h1 class="bare-title">Thank you</h1>
    <p class="bare-sub"><?= e($thanks) ?></p>
    <p class="bare-foot">
        <a href="<?= e(url('/f/' . $form['slug'])) ?>">Send another</a>
    </p>
<?php else: ?>
    <h1 class="bare-title"><?= e($heading) ?></h1>

    <?php if ($form['description'] !== ''): ?>
        <p class="bare-sub"><?= e($form['description']) ?></p>
    <?php endif; ?>

    <?php if ($message = $errorFor('_form')): ?>
        <p class="field-error"><?= e($message) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/f/' . $form['slug'])) ?>" class="form">
        <?= csrf_field() ?>

        <?php foreach ($utm as $utmKey => $utmValue): ?>
            <input type="hidden" name="<?= e($utmKey) ?>" value="<?= e($utmValue) ?>">
        <?php endforeach; ?>

        <?php foreach ($fields as $field): ?>
            <?php
            $id       = 'f_' . $field['key'];
            $message  = $errorFor($field['key']);
            $previous = old($field['key']);
            $previous = is_scalar($previous) ? (string) $previous : '';
            ?>

            <?php if ($field['type'] === 'checkbox' || $field['type'] === 'consent'): ?>
                <div class="field field-check">
                    <input type="checkbox" id="<?= e($id) ?>" name="<?= e($field['key']) ?>" value="1"
                        <?= $previous === '1' ? 'checked' : '' ?>
                        <?= $field['required'] ? 'required' : '' ?>>
                    <label for="<?= e($id) ?>"><?= e($field['label']) ?></label>
                    <?php if ($field['help_text'] !== ''): ?>
                        <p class="field-hint"><?= e($field['help_text']) ?></p>
                    <?php endif; ?>
                    <?php if ($message !== null): ?>
                        <p class="field-error"><?= e($message) ?></p>
                    <?php endif; ?>
                </div>

            <?php elseif ($field['type'] === 'textarea'): ?>
                <div class="field">
                    <label for="<?= e($id) ?>">
                        <?= e($field['label']) ?><?= $field['required'] ? '' : ' (optional)' ?>
                    </label>
                    <textarea id="<?= e($id) ?>" name="<?= e($field['key']) ?>" rows="4" maxlength="2000"
                        <?= $field['required'] ? 'required' : '' ?>><?= e($previous) ?></textarea>
                    <?php if ($field['help_text'] !== ''): ?>
                        <p class="field-hint"><?= e($field['help_text']) ?></p>
                    <?php endif; ?>
                    <?php if ($message !== null): ?>
                        <p class="field-error"><?= e($message) ?></p>
                    <?php endif; ?>
                </div>

            <?php elseif ($field['type'] === 'select'): ?>
                <div class="field">
                    <label for="<?= e($id) ?>">
                        <?= e($field['label']) ?><?= $field['required'] ? '' : ' (optional)' ?>
                    </label>
                    <select id="<?= e($id) ?>" name="<?= e($field['key']) ?>"
                        <?= $field['required'] ? 'required' : '' ?>>
                        <option value="">Choose one</option>
                        <?php foreach ($field['options'] as $option): ?>
                            <option value="<?= e($option) ?>" <?= $previous === $option ? 'selected' : '' ?>>
                                <?= e($option) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($field['help_text'] !== ''): ?>
                        <p class="field-hint"><?= e($field['help_text']) ?></p>
                    <?php endif; ?>
                    <?php if ($message !== null): ?>
                        <p class="field-error"><?= e($message) ?></p>
                    <?php endif; ?>
                </div>

            <?php else: ?>
                <?php
                // The browser's own keyboard and validation for the three types
                // that have one. `tel` is deliberately not given a pattern: the
                // server normalises the number, and a pattern here would reject
                // the perfectly ordinary "(845) 469-2116".
                $inputType = match ($field['type']) {
                    'email' => 'email',
                    'tel'   => 'tel',
                    'date'  => 'date',
                    default => 'text',
                };
                ?>
                <div class="field">
                    <label for="<?= e($id) ?>">
                        <?= e($field['label']) ?><?= $field['required'] ? '' : ' (optional)' ?>
                    </label>
                    <input type="<?= e($inputType) ?>" id="<?= e($id) ?>" name="<?= e($field['key']) ?>"
                           maxlength="191" value="<?= e($previous) ?>"
                           <?= $field['placeholder'] !== '' ? 'placeholder="' . e_attr($field['placeholder']) . '"' : '' ?>
                        <?= $field['required'] ? 'required' : '' ?>>
                    <?php if ($field['help_text'] !== ''): ?>
                        <p class="field-hint"><?= e($field['help_text']) ?></p>
                    <?php endif; ?>
                    <?php if ($message !== null): ?>
                        <p class="field-error"><?= e($message) ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php
        /**
         * The honeypot.
         *
         * Hidden from sight AND from assistive technology, and off the tab
         * order, so no human is ever offered it — which is what makes
         * anything in it evidence of a bot rather than of a confused guest.
         * `aria-hidden` plus `tabindex="-1"` rather than `type="hidden"`: a
         * hidden input is skipped by the naive form-fillers this catches.
         */
        ?>
        <div class="sr-only" aria-hidden="true">
            <label for="<?= e($form['honeypot']) ?>">Leave this empty</label>
            <input type="text" id="<?= e($form['honeypot']) ?>" name="<?= e($form['honeypot']) ?>"
                   value="" tabindex="-1" autocomplete="off">
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-block"><?= e($form['submit_label']) ?></button>
        </div>
    </form>

    <p class="bare-foot">
        <?= e($venueName) ?> will use what you send here to answer your enquiry. We will only add
        you to a mailing list if you tick a box above asking us to.
    </p>
<?php endif; ?>
