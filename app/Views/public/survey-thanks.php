<?php

declare(strict_types=1);

/**
 * What a guest sees after answering — a different page per band.
 *
 * A promoter gets one link. A passive gets an open question. A detractor gets
 * a promise that a person will be in touch, which the module keeps by raising
 * a task with a deadline rather than by sending an automatic apology.
 *
 * The review link is NOT shown to anybody the gate did not pick, and there is
 * no way to reach it from this page by guessing — the token belongs to a
 * `review_requests` row that only `Feedback::record()` creates.
 *
 * @var string                   $sentiment
 * @var array{heading:string,body:string,ask_comment:bool,comment_prompt:?string} $followUp
 * @var array{platform:string,url:string}|null $review
 * @var string|null              $platformName
 * @var string                   $token
 * @var string                   $firstName
 * @var string                   $venueName
 * @var string|null              $comment
 * @var bool                     $commentJustLeft
 */
?>

<h1 class="bare-title"><?= e($followUp['heading']) ?></h1>

<?php if ($commentJustLeft): ?>
    <p class="bare-sub">
        Thank you &mdash; that is with the management team now.
        <?php if ($sentiment === 'detractor'): ?>
            Somebody will be in touch today.
        <?php endif; ?>
    </p>
<?php else: ?>
    <p class="bare-sub"><?= e($followUp['body']) ?></p>
<?php endif; ?>

<?php if ($review !== null && !$commentJustLeft): ?>
    <p class="survey-cta">
        <a class="btn btn-lg" href="<?= e($review['url']) ?>">
            Leave a review on <?= e((string) $platformName) ?>
        </a>
    </p>
<?php endif; ?>

<?php if ($followUp['ask_comment'] && !$commentJustLeft): ?>
    <form method="post" action="<?= e(url('/s/' . $token)) ?>" class="survey-comment">
        <?= csrf_field() ?>
        <div class="field">
            <label for="comment"><?= e((string) $followUp['comment_prompt']) ?></label>
            <textarea id="comment" name="comment" rows="4"
                      maxlength="4000"><?= e((string) ($comment ?? '')) ?></textarea>
        </div>
        <button type="submit" class="btn">Send it</button>
    </form>
<?php endif; ?>

<p class="bare-foot muted">
    <?= e($venueName) ?>
    <?php if ($sentiment === 'detractor'): ?>
        &mdash; if it is urgent, please ring us rather than waiting for us to call.
    <?php endif; ?>
</p>
