<?php

declare(strict_types=1);

/**
 * Step 2 — requirements.
 *
 * Hard failures block; optional items are shown so the operator understands
 * what degrades rather than discovering it later.
 *
 * @var string                    $token
 * @var list<array<string,mixed>> $checks
 */

$esc = static fn (mixed $v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');

$blocked = installer_hasHardFailure($checks);

$failedRequired = array_values(array_filter(
    $checks,
    static fn (array $c): bool => $c['required'] && !$c['ok']
));

?>
<div class="panel">
    <h2>Server requirements</h2>
    <p class="lede">
        Everything marked <span class="tag">Required</span> has to pass. The rest are optional —
        Castle CRM works without them, with the matching feature switched off.
    </p>

    <?php if ($blocked): ?>
        <div class="msg bad">
            <strong><?= count($failedRequired) ?> required item<?= count($failedRequired) === 1 ? '' : 's' ?>
            still need<?= count($failedRequired) === 1 ? 's' : '' ?> attention.</strong>
            Make the changes below in cPanel, then press Re-check.
        </div>
    <?php else: ?>
        <div class="msg ok">
            <strong>This server can run Castle CRM.</strong>
            Anything below marked Optional is a feature you can add later.
        </div>
    <?php endif; ?>

    <ul class="checks">
        <?php foreach ($checks as $check): ?>
            <?php
            $markClass = $check['ok'] ? 'ok' : ($check['required'] ? 'bad' : 'warn');
            $markGlyph = $check['ok'] ? '&check;' : ($check['required'] ? '&times;' : '!');
            ?>
            <li>
                <span class="mark <?= $markClass ?>" aria-hidden="true"><?= $markGlyph ?></span>
                <span class="check-body">
                    <span class="check-label"><?= $esc($check['label']) ?></span>
                    <span class="check-detail"><?= $esc($check['detail']) ?></span>
                    <?php if (!$check['ok'] && $check['fix'] !== null): ?>
                        <span class="check-fix"><strong>Fix:</strong> <?= $esc($check['fix']) ?></span>
                    <?php endif; ?>
                </span>
                <span class="tag"><?= $check['required'] ? 'Required' : 'Optional' ?></span>
                <span class="sr-only">
                    <?= $check['ok'] ? 'Passed' : ($check['required'] ? 'Failed' : 'Warning') ?>
                </span>
            </li>
        <?php endforeach; ?>
    </ul>

    <form method="post" action="index.php?step=2">
        <input type="hidden" name="_token" value="<?= $esc($token) ?>">
        <div class="actions">
            <button type="submit"><?= $blocked ? 'Re-check' : 'Continue' ?></button>
            <a class="btn ghost" href="index.php?step=1">Back</a>
        </div>
    </form>
</div>

<style>
    .sr-only {
        position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
        overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0;
    }
</style>
