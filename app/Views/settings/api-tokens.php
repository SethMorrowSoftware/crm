<?php

declare(strict_types=1);

/**
 * API tokens.
 *
 * @var list<array<string,mixed>>        $tokens
 * @var string|null                      $fresh
 * @var list<array<string,mixed>>        $users
 * @var array<string,array<string,string>> $catalogue  Already grouped by resource.
 * @var int                              $maxAbilities
 */

use App\Core\ApiToken;

$grouped = $catalogue;
?>

<?php if ($fresh !== null): ?>
    <section class="card card-accent">
        <div class="card-head">
            <div>
                <h2>Here is the token. Copy it now.</h2>
                <p class="card-sub">
                    This is the only time it is shown. Castle CRM stores a one-way hash of it
                    and cannot show it to you again &mdash; which is the point: a credential
                    this page could re-display would be a credential sitting in the database
                    for anybody who ever gets a copy of it.
                </p>
            </div>
        </div>

        <?php /* A readonly input rather than a <code> block: it can be selected
                 with one keyboard gesture and copied, which a block cannot. */ ?>
        <div class="field">
            <label for="fresh-token">Your new token</label>
            <input type="text" id="fresh-token" class="token-reveal" readonly
                   value="<?= e($fresh) ?>"
                   aria-describedby="fresh-token-help">
            <p class="field-hint" id="fresh-token-help">
                Send it as <code>Authorization: Bearer &lt;token&gt;</code>. If you lose it,
                revoke it below and make another &mdash; that takes ten seconds.
            </p>
        </div>
    </section>
<?php endif; ?>

<section class="card">
    <div class="card-head">
        <div>
            <h2>API tokens</h2>
            <p class="card-sub">
                A token lets something outside this application read and write through
                <code>/api/v1</code>. It acts <strong>as the person it belongs to</strong> and can
                never do more than they can &mdash; so a token for a front-desk account looks up
                one guest and cannot export the list, and a person who leaves takes their
                tokens' access with them the moment their account is switched off.
            </p>
        </div>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('/settings')) ?>">All settings</a>
    </div>

    <?php if ($tokens === []): ?>
        <p class="empty">No tokens yet. Nothing outside this application can reach it.</p>
    <?php else: ?>
        <div class="table-scroll">
            <table class="table">
                <caption class="sr-only">API tokens</caption>
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Acts as</th>
                        <th scope="col">Can do</th>
                        <th scope="col">Last used</th>
                        <th scope="col">Expires</th>
                        <th scope="col"><span class="sr-only">Revoke</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tokens as $token): ?>
                        <tr>
                            <th scope="row"><?= e((string) $token['name']) ?></th>
                            <td>
                                <?= e((string) $token['user_name']) ?>
                                <span class="muted"><?= e((string) $token['user_email']) ?></span>
                            </td>
                            <td>
                                <?php if (in_array(ApiToken::ALL, $token['ability_list'], true)): ?>
                                    <span class="muted">Everything that account can</span>
                                <?php else: ?>
                                    <?php foreach ($token['ability_list'] as $ability): ?>
                                        <span class="badge badge-muted"><?= e($ability) ?></span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php /* "Never" is the useful reading of null here: an
                                         integration that was set up and never ran is the
                                         thing somebody wants to notice. */ ?>
                                <?= $token['last_used_at'] === null
                                    ? '<span class="muted">Never</span>'
                                    : e(local_time((string) $token['last_used_at'])) ?>
                            </td>
                            <td>
                                <?php if ($token['expires_at'] === null): ?>
                                    <span class="muted">No expiry</span>
                                <?php elseif ($token['expired']): ?>
                                    <span class="badge badge-warning">Expired</span>
                                <?php else: ?>
                                    <?= e(local_date((string) $token['expires_at'])) ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="post" class="inline-form"
                                      action="<?= e(url('/settings/api-tokens/' . (int) $token['id'] . '/revoke')) ?>"
                                      data-confirm="Revoke this token? Anything using it stops working immediately.">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-danger btn-sm">Revoke</button>
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
            <h2>Make a token</h2>
            <p class="card-sub">
                Give it a name you will recognise in six months &mdash; "Zapier: new leads to
                Sheets" rather than "token 3".
            </p>
        </div>
    </div>

    <form method="post" action="<?= e(url('/settings/api-tokens')) ?>">
        <?= csrf_field() ?>

        <div class="field-row">
            <div class="field">
                <label for="name">What is it for?</label>
                <input type="text" id="name" name="name" maxlength="80" required
                       placeholder="Zapier: new leads to a spreadsheet">
            </div>

            <div class="field">
                <label for="user_id">Act as</label>
                <select id="user_id" name="user_id" required>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= (int) $user['id'] ?>">
                            <?= e((string) $user['name']) ?>
                            (<?= e((string) ($user['role_name'] ?? 'no role')) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="field-hint">
                    The token can never do more than this account can.
                </p>
            </div>

            <div class="field">
                <label for="expires_on">Expires on</label>
                <input type="date" id="expires_on" name="expires_on">
                <p class="field-hint">Leave empty for no expiry.</p>
            </div>
        </div>

        <fieldset class="field">
            <legend>What may it do?</legend>
            <p class="field-hint">
                Leave everything unticked for &ldquo;anything that account can do&rdquo;. Ticking
                some narrows it further &mdash; a token that only reads is the right default for
                anything that only needs to read. These are the only permissions
                <code>/api/v1</code> consults; the rest of the catalogue has no effect on a
                token, so it is not offered here.
            </p>

            <div class="ability-groups">
                <?php foreach ($grouped as $group => $permissions): ?>
                    <div class="ability-group">
                        <h3><?= e((string) $group) ?></h3>
                        <?php foreach ($permissions as $slug => $label): ?>
                            <label class="check">
                                <input type="checkbox" name="abilities[]" value="<?= e((string) $slug) ?>">
                                <span><?= e((string) $label) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <button type="submit" class="btn">Create the token</button>
    </form>
</section>

<section class="card">
    <div class="card-head"><div><h2>Using it</h2></div></div>

    <p>Every request carries the token in a header:</p>

    <?php
    /*
     * ABSOLUTE, or it is not a command anybody can paste. `url()` returns a
     * path when `app_url` is unset, and "curl /api/v1" does nothing useful in
     * a terminal — the one place this snippet is ever used.
     */
    $apiUrl = \App\Core\Mailer::absoluteUrl('/api/v1');
    ?>
    <pre class="code-block"><code>curl <?= e($apiUrl) ?> \
  -H "Authorization: Bearer cc_live_..."</code></pre>

    <p class="muted">
        That first call lists every resource this token can reach and what it may do with
        each, so an integration can check itself rather than guessing. The full reference,
        with a worked example per endpoint, is in <code>docs/API.md</code>.
    </p>

    <p class="muted">
        <strong>On cPanel:</strong> Apache does not pass the <code>Authorization</code> header
        to PHP unless it is told to, and the shipped <code>.htaccess</code> tells it to. If
        every token comes back &ldquo;not valid&rdquo; while you can see you sent one, that
        file is the first place to look &mdash; a host that overrides it is the usual cause.
    </p>
</section>
