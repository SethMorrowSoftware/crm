<?php

declare(strict_types=1);

/**
 * The one question, on a parent's phone, the morning after a party.
 *
 * Designed to be answerable in one tap. No sign-in, no name to confirm, no
 * second page before the score is recorded — every extra step costs responses,
 * and the score is the thing that matters. The comment comes AFTER, on the
 * thank-you page, where it is optional.
 *
 * The buttons are radio inputs in a form, not script: there is no inline
 * script allowed by the Content-Security-Policy, and a feedback page must
 * work on an old phone with a bad signal.
 *
 * @var string               $token
 * @var string               $question
 * @var array{min:int,max:int,step:int} $scale
 * @var array{low:string,high:string}   $labels
 * @var string               $type
 * @var string               $firstName
 * @var string               $venueName
 */
?>

<h1 class="bare-title">
    <?= $firstName === '' ? 'One question' : e($firstName) . ', one question' ?>
</h1>

<p class="bare-sub"><?= e($question) ?></p>

<form method="post" action="<?= e(url('/s/' . $token)) ?>" class="survey-form">
    <?= csrf_field() ?>

    <fieldset class="survey-scale">
        <legend class="sr-only"><?= e($question) ?></legend>

        <div class="survey-buttons <?= $type === 'csat' ? 'is-stars' : '' ?>">
            <?php for ($n = $scale['min']; $n <= $scale['max']; $n += $scale['step']): ?>
                <button type="submit" name="score" value="<?= $n ?>" class="survey-btn">
                    <?= $type === 'csat' ? str_repeat('&#9733;', $n) : $n ?>
                    <?php if ($type === 'csat'): ?>
                        <span class="sr-only"><?= $n ?> out of <?= $scale['max'] ?></span>
                    <?php endif; ?>
                </button>
            <?php endfor; ?>
        </div>

        <div class="survey-ends">
            <span><?= e($labels['low']) ?></span>
            <span><?= e($labels['high']) ?></span>
        </div>
    </fieldset>
</form>

<p class="bare-foot muted">
    Thank you for coming to <?= e($venueName) ?>. Your answer goes to the management
    team &mdash; nobody else sees it.
</p>
