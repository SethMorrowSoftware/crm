<?php

declare(strict_types=1);

/**
 * Installer shell.
 *
 * Self-contained styling — the installer must render correctly before any
 * configuration exists, and before the application's own assets are proven
 * reachable.
 *
 * @var int                                   $step
 * @var string                                $token
 * @var list<string>                          $errors
 * @var list<string>                          $notices
 * @var array<string,mixed>                   $state
 * @var list<array<string,mixed>>             $checks
 */

$esc = static fn (mixed $v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');

$steps = [
    1 => 'Welcome',
    2 => 'Requirements',
    3 => 'Database',
    4 => 'Schema',
    5 => 'Venue',
    6 => 'Owner account',
    7 => 'Starting data',
    8 => 'Finish',
];

$complete = !empty($state['complete']);

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Castle CRM setup — step <?= $step ?> of 8</title>
    <style>
        :root {
            --brand: #6b4ea8;
            --brand-dark: #553d87;
            --ink: #1b1f26;
            --muted: #5b6472;
            --faint: #8b95a3;
            --line: #e3e7ec;
            --bg: #f6f7f9;
            --panel: #ffffff;
            --ok: #0f7b4f;
            --ok-bg: #e8f6ef;
            --warn: #8a5a00;
            --warn-bg: #fff4e5;
            --bad: #b42318;
            --bad-bg: #fdeceb;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --ink: #e8eaed;
                --muted: #a8b0bb;
                --faint: #7d8694;
                --line: #2d333d;
                --bg: #14171c;
                --panel: #1c2027;
                --ok-bg: #11291f;
                --warn-bg: #2a2113;
                --bad-bg: #2a1716;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font: 16px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", sans-serif;
            background: var(--bg);
            color: var(--ink);
            padding: 1rem;
        }

        .wrap { max-width: 50rem; margin: 2rem auto 4rem; }

        header.brand { text-align: center; margin-bottom: 2rem; }
        header.brand h1 { margin: 0 0 .25rem; font-size: 1.6rem; letter-spacing: -.02em; }
        header.brand p { margin: 0; color: var(--faint); font-size: .95rem; }

        /* Progress rail */
        ol.rail {
            display: flex; flex-wrap: wrap; gap: .4rem;
            list-style: none; margin: 0 0 1.5rem; padding: 0;
            font-size: .78rem;
        }
        ol.rail li {
            flex: 1 1 auto; min-width: 5.5rem;
            padding: .45rem .6rem; border-radius: 6px;
            background: var(--panel); border: 1px solid var(--line);
            color: var(--faint); text-align: center;
        }
        ol.rail li.done { background: var(--ok-bg); border-color: transparent; color: var(--ok); }
        ol.rail li.now  { background: var(--brand); border-color: var(--brand); color: #fff; font-weight: 600; }

        .panel {
            background: var(--panel); border: 1px solid var(--line);
            border-radius: 10px; padding: 1.75rem;
        }
        .panel + .panel { margin-top: 1rem; }

        h2 { margin: 0 0 .5rem; font-size: 1.3rem; letter-spacing: -.015em; }
        h3 { margin: 1.75rem 0 .5rem; font-size: 1rem; }
        p.lede { margin: 0 0 1.5rem; color: var(--muted); }

        label { display: block; margin: 1rem 0 .3rem; font-weight: 600; font-size: .9rem; }
        label .opt { font-weight: 400; color: var(--faint); }

        input[type=text], input[type=email], input[type=password],
        input[type=number], select {
            width: 100%; padding: .6rem .7rem;
            border: 1px solid var(--line); border-radius: 6px;
            background: var(--bg); color: var(--ink); font: inherit; font-size: .95rem;
        }
        input:focus, select:focus {
            outline: 2px solid var(--brand); outline-offset: 1px; border-color: var(--brand);
        }

        .hint { font-size: .82rem; color: var(--faint); margin-top: .3rem; }

        .row { display: grid; gap: 1rem; }
        @media (min-width: 34rem) { .row.two { grid-template-columns: 1fr 1fr; } }
        @media (min-width: 34rem) { .row.three { grid-template-columns: 2fr 1fr 1fr; } }

        .actions { margin-top: 2rem; display: flex; gap: .75rem; align-items: center; flex-wrap: wrap; }

        button, .btn {
            display: inline-block; padding: .65rem 1.3rem;
            background: var(--brand); color: #fff; border: 0; border-radius: 6px;
            font: inherit; font-weight: 600; cursor: pointer; text-decoration: none;
        }
        button:hover, .btn:hover { background: var(--brand-dark); }
        button:focus-visible, .btn:focus-visible { outline: 2px solid var(--ink); outline-offset: 2px; }
        .btn.ghost { background: transparent; color: var(--muted); border: 1px solid var(--line); }

        .msg { padding: .85rem 1rem; border-radius: 6px; margin-bottom: 1rem; font-size: .9rem; }
        .msg.bad  { background: var(--bad-bg);  border-left: 3px solid var(--bad);  color: var(--bad); }
        .msg.warn { background: var(--warn-bg); border-left: 3px solid var(--warn); color: var(--warn); }
        .msg.ok   { background: var(--ok-bg);   border-left: 3px solid var(--ok);   color: var(--ok); }
        .msg ul { margin: .4rem 0 0; padding-left: 1.2rem; }

        /* Requirement list */
        ul.checks { list-style: none; margin: 0; padding: 0; }
        ul.checks li {
            display: flex; gap: .75rem; align-items: flex-start;
            padding: .7rem 0; border-bottom: 1px solid var(--line);
        }
        ul.checks li:last-child { border-bottom: 0; }
        .mark {
            flex: 0 0 1.4rem; height: 1.4rem; border-radius: 50%;
            display: grid; place-items: center; font-size: .8rem; font-weight: 700; margin-top: .1rem;
        }
        .mark.ok   { background: var(--ok-bg);   color: var(--ok); }
        .mark.warn { background: var(--warn-bg); color: var(--warn); }
        .mark.bad  { background: var(--bad-bg);  color: var(--bad); }
        .check-body { flex: 1 1 auto; min-width: 0; }
        .check-label { font-weight: 600; font-size: .92rem; }
        .check-detail { font-size: .85rem; color: var(--muted); }
        .check-fix { font-size: .85rem; color: var(--warn); margin-top: .25rem; }
        .tag {
            font-size: .7rem; text-transform: uppercase; letter-spacing: .04em;
            padding: .1rem .4rem; border-radius: 3px; background: var(--bg);
            color: var(--faint); border: 1px solid var(--line); white-space: nowrap;
        }

        code, pre {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: .85rem;
        }
        pre {
            background: var(--bg); border: 1px solid var(--line); border-radius: 6px;
            padding: .85rem; overflow-x: auto; margin: .5rem 0;
        }
        code.inline { background: var(--bg); padding: .1rem .35rem; border-radius: 3px; }

        .choice {
            display: flex; gap: .7rem; align-items: flex-start;
            padding: .8rem; border: 1px solid var(--line); border-radius: 6px; margin-bottom: .6rem;
        }
        .choice input { margin-top: .3rem; flex: 0 0 auto; }
        .choice label { margin: 0; }
        .choice .hint { margin-top: .2rem; }

        table { width: 100%; border-collapse: collapse; font-size: .85rem; }
        th, td { text-align: left; padding: .5rem .4rem; border-bottom: 1px solid var(--line); vertical-align: top; }
        th { color: var(--faint); font-size: .78rem; text-transform: uppercase; letter-spacing: .04em; }
        td code { word-break: break-all; }

        footer.note { margin-top: 1.5rem; text-align: center; font-size: .8rem; color: var(--faint); }
    </style>
</head>
<body>
<div class="wrap">
    <header class="brand">
        <h1>Castle CRM</h1>
        <p>Setup &middot; step <?= $step ?> of 8</p>
    </header>

    <ol class="rail">
        <?php foreach ($steps as $number => $label): ?>
            <li class="<?= $number < $step || $complete ? 'done' : ($number === $step ? 'now' : '') ?>">
                <?= $esc($label) ?>
            </li>
        <?php endforeach; ?>
    </ol>

    <?php if ($errors !== []): ?>
        <div class="msg bad">
            <strong>That did not work.</strong>
            <ul><?php foreach ($errors as $error): ?><li><?= $esc($error) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <?php if ($notices !== []): ?>
        <div class="msg warn">
            <strong>Worth knowing:</strong>
            <ul><?php foreach ($notices as $notice): ?><li><?= $esc($notice) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <?php
    $stepFile = __DIR__ . '/step' . $step . '.php';

    if (is_file($stepFile)) {
        require $stepFile;
    } else {
        echo '<div class="panel"><h2>Step not found</h2><p class="lede">Start again from '
            . '<a href="index.php?step=1">step 1</a>.</p></div>';
    }
    ?>

    <footer class="note">
        Castle CRM &middot; The Castle Fun Center, Chester NY
    </footer>
</div>
</body>
</html>
