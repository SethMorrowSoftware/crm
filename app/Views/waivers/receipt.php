<?php

declare(strict_types=1);

/**
 * A signer's own copy, reached by the token in their link.
 *
 * The token is the authentication, so this carries only what the signer
 * already knows: their own name, the text they agreed to, and when it runs
 * out. No booking, no other signature, nothing about the venue's diary.
 *
 * @var array<string,mixed> $signature
 * @var string              $venueName
 */

$minors = json_decode((string) ($signature['minors'] ?? '[]'), true);
$minors = is_array($minors) ? $minors : [];

?>
<h1 class="bare-title">Your signed waiver</h1>
<p class="bare-sub">
    <?= e((string) $signature['signer_name']) ?> signed version
    <?= e((string) $signature['version']) ?> on
    <?= e(local_time((string) $signature['signed_at'])) ?>.
    <?php if (($signature['expires_at'] ?? null) !== null): ?>
        It stands until <?= e((string) $signature['expires_at']) ?>.
    <?php endif; ?>
</p>

<?php if ($minors !== []): ?>
    <p class="bare-sub">
        Signed on behalf of
        <?= e(implode(', ', array_map(
            static fn (array $m): string => (string) ($m['name'] ?? ''),
            $minors
        ))) ?>.
    </p>
<?php endif; ?>

<h2><?= e((string) $signature['title']) ?></h2>
<pre class="code-block"><?= e((string) $signature['body']) ?></pre>

<p class="bare-foot"><?= e($venueName) ?></p>
