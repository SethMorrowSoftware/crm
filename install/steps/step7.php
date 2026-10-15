<?php

declare(strict_types=1);

/**
 * Step 7 — starting data.
 *
 * @var string              $token
 * @var array<string,mixed> $state
 */

$esc = static fn (mixed $v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');

$seeds = $state['seeds'] ?? ['venue' => true, 'playbooks' => true, 'demo' => false];

?>
<div class="panel">
    <h2>Starting data</h2>
    <p class="lede">
        Castle CRM can arrive already shaped around the Castle, so your first look is a working
        system rather than a blank one. Everything seeded here is editable and deletable.
    </p>

    <form method="post" action="index.php?step=7">
        <input type="hidden" name="_token" value="<?= $esc($token) ?>">

        <div class="choice">
            <input type="checkbox" id="seed_venue" name="seed_venue" value="1"
                <?= !empty($seeds['venue']) ? 'checked' : '' ?>>
            <div>
                <label for="seed_venue">The Castle's operating shape</label>
                <p class="hint">
                    16 attractions with their seasons, age and height limits — Bowling, Roller Skating,
                    Laser Tag, Ballocity, InflataPark, Axe Throwing, Go Karts, Zip Line, Mini Golf and
                    the rest. 4 party packages. 14 add-ons. 33 resources (6 party rooms, 24 lanes, the
                    rink, the arena, the Event Center). Weekly operating hours. 4 sales pipelines with
                    response deadlines. 11 loss reasons. The STOP/HELP text keywords.
                </p>
            </div>
        </div>

        <div class="choice">
            <input type="checkbox" id="seed_playbooks" name="seed_playbooks" value="1"
                <?= !empty($seeds['playbooks']) ? 'checked' : '' ?>>
            <div>
                <label for="seed_playbooks">The 8 revenue playbooks and 111 automations</label>
                <p class="hint">
                    Birthday &amp; Celebration Sales, Group &amp; Corporate Events, Visit &amp;
                    Ticketing, Reputation Protection, Membership &amp; Season Pass, Text Club &amp;
                    Repeat Visits, Sales Accountability, and Fundraising &amp; Referrals.
                    <strong>Every playbook arrives switched off and every automation arrives
                    inactive</strong> — they are a library to review, not a machine that starts running.
                </p>
            </div>
        </div>

        <div class="choice">
            <input type="checkbox" id="seed_demo" name="seed_demo" value="1"
                <?= !empty($seeds['demo']) ? 'checked' : '' ?>>
            <div>
                <label for="seed_demo">Example guests and bookings <span class="opt">— off by default</span></label>
                <p class="hint">
                    A handful of fictional families and parties, so the dashboard and pipelines have
                    something in them while you learn the system. Leave this OFF for a real install:
                    fake guests in a real contact list are a nuisance to unpick later.
                    <em>Not yet implemented — this checkbox is recorded but seeds nothing in Phase 1.</em>
                </p>
            </div>
        </div>

        <div class="msg ok">
            <strong>Recommended:</strong> leave the first two ticked and the third clear.
            Skipping the first two gives you an empty system you would have to fill by hand.
        </div>

        <div class="actions">
            <button type="submit">Continue</button>
            <a class="btn ghost" href="index.php?step=6">Back</a>
        </div>
    </form>
</div>
