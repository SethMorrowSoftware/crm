<?php

declare(strict_types=1);

/**
 * Seeds The Castle Fun Center's own operating shape: attractions, party
 * packages, add-ons, resources, operating hours, pipelines and lost reasons.
 *
 * IMPORTANT: prices and hours here were gathered from the venue's public
 * website and may be stale (spec §3.3 / §3.5). They are seeded as EDITABLE
 * DEFAULTS and the installer asks an operator to confirm them. No business
 * logic reads a hard-coded price — the quote calculator reads these rows.
 *
 * @return array{attractions:int,packages:int,addons:int,resources:int,pipelines:int}
 */

use App\Core\DB;

return static function (): array {
    $now = gmdate('Y-m-d H:i:s');

    // ------------------------------------------------------------------
    // Attractions (spec §3.1)
    // ------------------------------------------------------------------
    //
    // season_start_md / season_end_md are MM-DD so a season survives a year
    // rollover. is_party_main marks what a party can be built AROUND, which is
    // what the package picker offers.

    $attractions = [
        // name, slug, category, indoor, seasonal, season start, season end,
        // min_age, min_height, capacity, slot mins, party_eligible, party_main, waiver
        ['Bowling',          'bowling',       'lanes',     1, 0, null,    null,    null, null, 36, 60, 1, 1, 0],
        ['Roller Skating',   'roller-skating', 'rink',     1, 0, null,    null,    null, null, 120, 120, 1, 1, 1],
        ['Laser Tag',        'laser-tag',     'arena',     1, 0, null,    null,    5,    null, 30, 20, 1, 1, 0],
        ['Ballocity',        'ballocity',     'play',      1, 0, null,    null,    null, null, 60, 60, 1, 1, 1],
        ['InflataPark',      'inflatapark',   'play',      1, 0, null,    null,    3,    null, 40, 60, 1, 1, 1],
        ['Arcade',           'arcade',        'arcade',    1, 0, null,    null,    null, null, null, null, 1, 0, 0],
        ['Rock Climbing Wall', 'rock-wall',   'adventure', 1, 0, null,    null,    5,    null, 8,  15, 1, 0, 1],
        ['Axe Throwing',     'axe-throwing',  'adventure', 1, 0, null,    null,    18,   null, 12, 60, 1, 1, 1],
        ['Free Fall',        'free-fall',     'adventure', 1, 0, null,    null,    7,    48,   6,  10, 1, 0, 1],
        ['Event Center',     'event-center',  'event',     1, 0, null,    null,    null, null, 200, 240, 1, 1, 0],
        // Outdoor and seasonal. The availability engine refuses to quote these
        // out of season, which is the whole point of modelling it as data.
        ['Go Karts',         'go-karts',      'rides',     0, 1, '04-01', '10-31', 8,    58,   20, 10, 1, 1, 1],
        ['Zip Line',         'zip-line',      'adventure', 0, 1, '04-01', '10-31', 7,    48,   8,  15, 1, 0, 1],
        ['Mini Golf',        'mini-golf',     'rides',     0, 1, '04-01', '10-31', null, null, 72, 90, 1, 1, 0],
        ['Swing Ride',       'swing-ride',    'rides',     0, 1, '04-01', '10-31', 5,    42,   16, 5,  1, 0, 0],
        ['Dragon Coaster',   'dragon-coaster', 'rides',    0, 1, '04-01', '10-31', 3,    36,   20, 5,  1, 0, 0],
        ['Bumper Boats',     'bumper-boats',  'rides',     0, 1, '05-01', '09-30', 6,    44,   12, 10, 1, 0, 1],
    ];

    $attractionIds = [];
    $attractionCount = 0;
    $sort = 0;

    foreach ($attractions as $row) {
        [$name, $slug, $category, $indoor, $seasonal, $seasonStart, $seasonEnd,
         $minAge, $minHeight, $capacity, $slotMinutes, $partyEligible, $partyMain, $waiver] = $row;

        $existing = DB::first('SELECT id FROM {attractions} WHERE slug = :slug', ['slug' => $slug]);

        if ($existing !== null) {
            $attractionIds[$slug] = (int) $existing['id'];
            continue;
        }

        $attractionIds[$slug] = DB::insert('attractions', [
            'name'              => $name,
            'slug'              => $slug,
            'category'          => $category,
            'is_indoor'         => $indoor,
            'is_seasonal'       => $seasonal,
            'season_start_md'   => $seasonStart,
            'season_end_md'     => $seasonEnd,
            'min_age'           => $minAge,
            'min_height_in'     => $minHeight,
            'capacity_per_slot' => $capacity,
            'slot_minutes'      => $slotMinutes,
            'price_cents'       => 0, // Per-activity pricing lives in the POS.
            'is_party_eligible' => $partyEligible,
            'is_party_main'     => $partyMain,
            'requires_waiver'   => $waiver,
            'is_active'         => 1,
            'sort_order'        => $sort += 10,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        $attractionCount++;
    }

    // ------------------------------------------------------------------
    // Party packages (spec §3.3)
    // ------------------------------------------------------------------

    $packages = [
        // name, slug, main attraction, base cents, included, extra cents,
        // duration, min notice days, seasonal, deposit cents
        ['Roller Skating Birthday', 'roller-skating-birthday', 'roller-skating', 34900, 8, 2699, 120, 7, 0, 10000],
        ['Laser Tag Birthday',      'laser-tag-birthday',      'laser-tag',      34900, 8, 2699, 120, 7, 0, 10000],
        ['Bowling Birthday',        'bowling-birthday',        'bowling',        39900, 8, 2999, 120, 7, 0, 10000],
        // Seasonal: the availability engine will not offer this in winter.
        ['Mini Golf Birthday',      'mini-golf-birthday',      'mini-golf',      34900, 8, 2699, 120, 7, 1, 10000],
    ];

    $packageCount = 0;
    $sort = 0;

    foreach ($packages as $row) {
        [$name, $slug, $mainSlug, $base, $included, $extra, $duration, $notice, $seasonal, $deposit] = $row;

        if (DB::exists('SELECT id FROM {party_packages} WHERE slug = :slug', ['slug' => $slug])) {
            continue;
        }

        DB::insert('party_packages', [
            'name'                   => $name,
            'slug'                   => $slug,
            'main_attraction_id'     => $attractionIds[$mainSlug] ?? null,
            'base_price_cents'       => $base,
            'included_guests'        => $included,
            'additional_guest_cents' => $extra,
            'duration_minutes'       => $duration,
            'min_notice_days'        => $notice,
            'is_seasonal'            => $seasonal,
            'deposit_cents'          => $deposit,
            'description'            => 'Approximately two hours. Starts with the main activity; '
                . 'add further attractions as add-ons. Online booking requires '
                . $notice . ' or more days notice — inside that window the family must call.',
            'is_active'              => 1,
            'sort_order'             => $sort += 10,
            'created_at'             => $now,
            'updated_at'             => $now,
        ]);

        $packageCount++;
    }

    // ------------------------------------------------------------------
    // Add-ons
    // ------------------------------------------------------------------

    $addons = [
        // name, price cents, unit, attraction slug
        ['Extra Attraction — Laser Tag',   1899, 'per_guest', 'laser-tag'],
        ['Extra Attraction — Ballocity',   1499, 'per_guest', 'ballocity'],
        ['Extra Attraction — Rock Wall',   1299, 'per_guest', 'rock-wall'],
        ['Extra Attraction — Mini Golf',   1299, 'per_guest', 'mini-golf'],
        ['Extra Attraction — Go Karts',    1999, 'per_guest', 'go-karts'],
        ['Arcade Card — $10 value',        1000, 'per_guest', 'arcade'],
        ['Arcade Card — $20 value',        2000, 'per_guest', 'arcade'],
        ['Pizza — additional pie',          2200, 'flat',      null],
        ['Ice Cream Cake',                  4500, 'flat',      null],
        ['Goody Bags',                       799, 'per_guest', null],
        ['Dedicated Party Host',            5000, 'flat',      null],
        ['Extra Half Hour in Party Room',   5000, 'per_hour',  null],
        ['Table Decorations',               3500, 'flat',      null],
        ['Adult Meal',                      1499, 'per_guest', null],
    ];

    $addonCount = 0;
    $sort = 0;

    foreach ($addons as [$name, $price, $unit, $attractionSlug]) {
        if (DB::exists('SELECT id FROM {package_addons} WHERE name = :name', ['name' => $name])) {
            continue;
        }

        DB::insert('package_addons', [
            'name'          => $name,
            'price_cents'   => $price,
            'unit'          => $unit,
            'attraction_id' => $attractionSlug === null ? null : ($attractionIds[$attractionSlug] ?? null),
            'is_active'     => 1,
            'sort_order'    => $sort += 10,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        $addonCount++;
    }

    // ------------------------------------------------------------------
    // Resources — the physical things a booking consumes
    // ------------------------------------------------------------------

    $resources = [];

    for ($i = 1; $i <= 6; $i++) {
        $resources[] = ['Party Room ' . $i, 'party_room', 30, null];
    }

    for ($i = 1; $i <= 24; $i++) {
        $resources[] = ['Lane ' . $i, 'bowling_lane', 6, 'bowling'];
    }

    $resources[] = ['Laser Tag Arena',  'laser_arena', 30, 'laser-tag'];
    $resources[] = ['Skate Rink',       'skate_rink', 120, 'roller-skating'];
    $resources[] = ['Event Center Hall', 'event_space', 200, 'event-center'];

    $resourceCount = 0;
    $sort = 0;

    foreach ($resources as [$name, $type, $capacity, $attractionSlug]) {
        if (DB::exists('SELECT id FROM {resources} WHERE name = :name', ['name' => $name])) {
            continue;
        }

        DB::insert('resources', [
            'name'          => $name,
            'type'          => $type,
            'capacity'      => $capacity,
            'attraction_id' => $attractionSlug === null ? null : ($attractionIds[$attractionSlug] ?? null),
            'is_active'     => 1,
            'sort_order'    => $sort += 10,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        $resourceCount++;
    }

    // ------------------------------------------------------------------
    // Operating hours
    // ------------------------------------------------------------------
    //
    // Two seasons, because the Castle's shoulder-season weekdays are much
    // shorter than summer. Seeded as a starting point for an operator to
    // correct — hours change and the installer says so.

    if (!DB::exists('SELECT id FROM {operating_hours} LIMIT 1')) {
        $hours = [
            // day_of_week (0=Sun), opens, closes, label
            [0, '11:00:00', '20:00:00', 'Standard'],
            [1, '15:00:00', '20:00:00', 'Standard'],
            [2, '15:00:00', '20:00:00', 'Standard'],
            [3, '15:00:00', '20:00:00', 'Standard'],
            [4, '15:00:00', '21:00:00', 'Standard'],
            [5, '11:00:00', '23:00:00', 'Standard'],
            [6, '11:00:00', '23:00:00', 'Standard'],
        ];

        foreach ($hours as [$day, $opens, $closes, $label]) {
            DB::insert('operating_hours', [
                'day_of_week'  => $day,
                'opens_at'     => $opens,
                'closes_at'    => $closes,
                'season_label' => $label,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Pipelines (spec §8.4)
    // ------------------------------------------------------------------

    $pipelines = [
        [
            'name' => 'Birthday Parties',
            'slug' => 'birthday',
            'type' => 'birthday',
            'stages' => [
                // name, probability, sla_hours, is_won, is_lost, colour
                ['New Inquiry',  10, 2,    0, 0, '#3b82f6'],
                ['Contacted',    25, 24,   0, 0, '#6366f1'],
                ['Quote Sent',   40, 48,   0, 0, '#8b5cf6'],
                ['Follow-Up',    50, 72,   0, 0, '#a855f7'],
                ['Date Held',    70, 48,   0, 0, '#d946ef'],
                ['Deposit Paid', 90, null, 0, 0, '#10b981'],
                ['Confirmed',   100, null, 1, 0, '#059669'],
                ['Lost',          0, null, 0, 1, '#6b7280'],
            ],
        ],
        [
            'name' => 'Groups & Corporate',
            'slug' => 'group',
            'type' => 'group',
            'stages' => [
                ['New Inquiry',          10, 4,    0, 0, '#3b82f6'],
                ['Qualified',            20, 48,   0, 0, '#6366f1'],
                ['Info Sent / Site Visit', 35, 72, 0, 0, '#8b5cf6'],
                ['Proposal Sent',        50, 120,  0, 0, '#a855f7'],
                ['Negotiating',          65, 120,  0, 0, '#d946ef'],
                ['Verbal Commit',        80, 72,   0, 0, '#ec4899'],
                ['Contract / PO Received', 95, null, 0, 0, '#10b981'],
                ['Confirmed',           100, null, 1, 0, '#059669'],
                ['Lost',                  0, null, 0, 1, '#6b7280'],
            ],
        ],
        [
            'name' => 'Event Center',
            'slug' => 'event-center',
            'type' => 'event_center',
            'stages' => [
                ['Inquiry',       10, 4,    0, 0, '#3b82f6'],
                ['Tour Booked',   30, 72,   0, 0, '#8b5cf6'],
                ['Proposal Sent', 50, 120,  0, 0, '#a855f7'],
                ['Contract Out',  75, 168,  0, 0, '#d946ef'],
                ['Deposit Paid',  90, null, 0, 0, '#10b981'],
                ['Confirmed',    100, null, 1, 0, '#059669'],
                ['Lost',           0, null, 0, 1, '#6b7280'],
            ],
        ],
        [
            'name' => 'Donations & Fundraising',
            'slug' => 'fundraising',
            'type' => 'fundraising',
            'stages' => [
                ['Request Received', 20, 24,   0, 0, '#3b82f6'],
                ['Under Review',     40, 72,   0, 0, '#8b5cf6'],
                ['Approved',         90, null, 0, 0, '#10b981'],
                ['Fulfilled',       100, null, 1, 0, '#059669'],
                ['Declined',          0, null, 0, 1, '#6b7280'],
            ],
        ],
    ];

    $pipelineCount = 0;
    $pipelineSort = 0;

    foreach ($pipelines as $pipeline) {
        if (DB::exists('SELECT id FROM {pipelines} WHERE slug = :slug', ['slug' => $pipeline['slug']])) {
            continue;
        }

        $pipelineId = DB::insert('pipelines', [
            'name'                   => $pipeline['name'],
            'slug'                   => $pipeline['slug'],
            'type'                   => $pipeline['type'],
            'is_active'              => 1,
            'sort_order'             => $pipelineSort += 10,
            'default_owner_strategy' => 'round_robin',
            'created_at'             => $now,
            'updated_at'             => $now,
        ]);

        $stageSort = 0;

        foreach ($pipeline['stages'] as [$stageName, $probability, $sla, $isWon, $isLost, $color]) {
            DB::insert('pipeline_stages', [
                'pipeline_id' => $pipelineId,
                'name'        => $stageName,
                'sort_order'  => $stageSort += 10,
                'probability' => $probability,
                'sla_hours'   => $sla,
                'is_won'      => $isWon,
                'is_lost'     => $isLost,
                'color'       => $color,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        $pipelineCount++;
    }

    // ------------------------------------------------------------------
    // Loss reasons — required on a lost deal, so the win/loss report has
    // something real to aggregate.
    // ------------------------------------------------------------------

    $lostReasons = [
        'Price', 'Date unavailable', 'Chose a competitor', 'Went no-contact',
        'Party cancelled', 'Too far to travel', 'Group size too small',
        'Group size too large', 'Wanted an attraction we do not offer',
        'Booked directly instead', 'Other',
    ];

    $sort = 0;

    foreach ($lostReasons as $label) {
        if (DB::exists('SELECT id FROM {lost_reasons} WHERE label = :label', ['label' => $label])) {
            continue;
        }

        DB::insert('lost_reasons', [
            'label'      => $label,
            'sort_order' => $sort += 10,
            'is_active'  => 1,
        ]);
    }

    // ------------------------------------------------------------------
    // SMS keywords — STOP must work from the very first install, before
    // anyone configures anything.
    // ------------------------------------------------------------------

    $keywords = [
        ['STOP',   'unsubscribe', 'You have been unsubscribed and will receive no further texts from us. Reply START to rejoin.'],
        ['CANCEL', 'unsubscribe', 'You have been unsubscribed and will receive no further texts from us. Reply START to rejoin.'],
        ['QUIT',   'unsubscribe', 'You have been unsubscribed and will receive no further texts from us. Reply START to rejoin.'],
        ['UNSUBSCRIBE', 'unsubscribe', 'You have been unsubscribed and will receive no further texts from us.'],
        ['START',  'subscribe',   'Welcome back! You are subscribed to Castle Fun Center texts. Reply STOP to opt out.'],
        ['JOIN',   'subscribe',   'Thanks for joining the Castle Text Club! Reply YES to confirm. Msg & data rates may apply. Reply STOP to opt out.'],
        ['HELP',   'help',        'Castle Fun Center: call (845) 469-2116 for help. Reply STOP to opt out. Msg & data rates may apply.'],
        ['INFO',   'help',        'Castle Fun Center, Chester NY. Call (845) 469-2116. Reply STOP to opt out.'],
    ];

    foreach ($keywords as [$keyword, $action, $reply]) {
        if (DB::exists('SELECT id FROM {sms_keywords} WHERE keyword = :k', ['k' => $keyword])) {
            continue;
        }

        DB::insert('sms_keywords', [
            'keyword'    => $keyword,
            'action'     => $action,
            'reply_body' => $reply,
            'is_active'  => 1,
            'created_at' => $now,
        ]);
    }

    return [
        'attractions' => $attractionCount,
        'packages'    => $packageCount,
        'addons'      => $addonCount,
        'resources'   => $resourceCount,
        'pipelines'   => $pipelineCount,
    ];
};
