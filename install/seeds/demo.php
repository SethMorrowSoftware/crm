<?php

declare(strict_types=1);

/**
 * Seeds example guests, inquiries and bookings so a fresh install has
 * something in it while the operator learns the system.
 *
 * Four rules, all of which matter more than the data itself:
 *
 * 1. **Nothing here can reach a real person.** Every address is on a
 *    `example.com` domain, which RFC 2606 reserves permanently for exactly
 *    this purpose, and every phone number uses the 555-01xx block the NANP
 *    reserves for fiction. Even with Safe Mode off and a provider configured,
 *    a demo contact cannot be messaged by accident.
 * 2. **Everything is findable and removable.** Every row this seed creates is
 *    either tagged `demo-data` — contacts, households, children, visits,
 *    deals, activities, tasks and bookings — or cascades from a row that is
 *    (consent_log from its contact, booking_payments from its booking), and
 *    the contacts also carry `source = 'demo'`. So "delete the examples" is
 *    one filter rather than an archaeology project. The tasks in particular
 *    were missed at first and left five fake urgent items on the owner's
 *    dashboard after the advertised cleanup, which is why this list is now
 *    explicit and asserted by the seed tests.
 * 3. **No consent is faked.** Demo guests have email consent with a
 *    consent_log row that says, in writing, that it came from this seed and
 *    is not evidence of anything. Two deliberately have no consent, because
 *    the dashboard's "reachable" count is only instructive if it is ever less
 *    than the total.
 * 4. **Dates are relative to install day.** Parties land in the next three
 *    weeks and visits in the last few months, so the calendar, the lapsed
 *    report and the pipeline all have something to show — in 2029 as much as
 *    today. Nothing is random: re-running this on the same day produces the
 *    same data, which is what makes it testable.
 *
 * Depends on the venue seed for pipelines and packages. If that was skipped,
 * the contacts are still created and the deals and bookings are not, rather
 * than the whole seed failing.
 *
 * @return array{contacts:int,children:int,deals:int,bookings:int,visits:int,tasks:int}
 */

use App\Core\DB;

return static function (): array {
    $now   = gmdate('Y-m-d H:i:s');
    $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));

    /** Days from today, as Y-m-d. */
    $day = static fn (int $offset): string => $today->modify(($offset >= 0 ? '+' : '') . $offset . ' days')
        ->format('Y-m-d');

    /** Days from today, as a UTC datetime at a fixed hour so output is stable. */
    $stamp = static fn (int $offset, string $time = '14:30:00'): string =>
        $today->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d') . ' ' . $time;

    // ------------------------------------------------------------------
    // The marker tag
    // ------------------------------------------------------------------

    $tagId = (int) DB::value('SELECT id FROM {tags} WHERE name = :name', ['name' => 'demo-data']);

    if ($tagId === 0) {
        $tagId = DB::insert('tags', [
            'name'       => 'demo-data',
            // Amber, so it reads as a warning wherever it appears.
            'color'      => '#b45309',
            'group'      => 'system',
            'created_at' => $now,
        ]);
    }

    $tag = static function (string $type, int $id) use ($tagId, $now): void {
        // INSERT IGNORE rather than a read-then-write: the composite primary
        // key already enforces one tag per entity, so let the database say so.
        DB::query(
            'INSERT IGNORE INTO {taggables} (tag_id, entity_type, entity_id, created_at)
             VALUES (:tag, :type, :id, :at)',
            ['tag' => $tagId, 'type' => $type, 'id' => $id, 'at' => $now]
        );
    };

    // Already seeded? Leave it alone rather than doubling it.
    //
    // Keyed on the bookings as well as the contacts, because they do not go
    // away together: fk_bookings_contact is ON DELETE SET NULL, so the
    // advertised cleanup ("delete the demo contacts") leaves the bookings
    // behind with a null contact. Checking only the contacts then read as "not
    // seeded", and the re-run died on booking_number's UNIQUE key — inside the
    // installer's transaction, so it took the whole setup down rather than
    // skipping a seed nobody needed. MEASURED.
    $alreadySeeded = DB::exists("SELECT id FROM {contacts} WHERE source = 'demo'")
        || DB::exists("SELECT id FROM {bookings} WHERE booking_number LIKE 'DEMO-%'");

    if ($alreadySeeded) {
        return ['contacts' => 0, 'children' => 0, 'deals' => 0, 'bookings' => 0, 'visits' => 0, 'tasks' => 0];
    }

    $ownerId = (int) DB::value(
        "SELECT u.id FROM {users} u JOIN {roles} r ON r.id = u.role_id
          WHERE r.slug = 'owner' AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1"
    ) ?: null;

    // ------------------------------------------------------------------
    // Households and guests
    // ------------------------------------------------------------------
    //
    // Towns are real places in Orange County within the venue's catchment, so
    // the geography reports look like the venue's own. The people are not.
    //
    // [ household, town, zip, first, last, email, phone, lifecycle, source,
    //   email consent, sms consent, visits, lifetime value (cents),
    //   first visit (days ago), last visit (days ago), children ]

    $families = [
        [
            'household' => 'Alvarez Family', 'city' => 'Chester', 'zip' => '10918',
            'first' => 'Marisol', 'last' => 'Alvarez',
            'email' => 'marisol.alvarez@example.com', 'phone' => '+18455550118',
            'lifecycle' => 'customer', 'source' => 'demo',
            'email_consent' => true, 'sms_consent' => true,
            'visits' => 7, 'ltv' => 184_250, 'first_visit' => -640, 'last_visit' => -24,
            'children' => [
                ['Lucia', -2_920, 'Peanut allergy — EpiPen in her bag.'],
                ['Mateo', -4_380, null],
            ],
        ],
        [
            'household' => 'Brennan Family', 'city' => 'Monroe', 'zip' => '10950',
            'first' => 'Dana', 'last' => 'Brennan',
            'email' => 'dana.brennan@example.com', 'phone' => '+18455550143',
            'lifecycle' => 'customer', 'source' => 'demo',
            'email_consent' => true, 'sms_consent' => false,
            'visits' => 3, 'ltv' => 71_900, 'first_visit' => -310, 'last_visit' => -61,
            'children' => [
                ['Rory', -3_650, null],
            ],
        ],
        [
            'household' => 'Okafor Family', 'city' => 'Goshen', 'zip' => '10924',
            'first' => 'Chidi', 'last' => 'Okafor',
            'email' => 'chidi.okafor@example.com', 'phone' => '+18455550176',
            'lifecycle' => 'prospect', 'source' => 'demo',
            // No consent: the dashboard's reachable count should be lower than
            // the contact count, because in a real list it always is.
            'email_consent' => false, 'sms_consent' => false,
            'visits' => 0, 'ltv' => 0, 'first_visit' => null, 'last_visit' => null,
            'children' => [
                ['Ada', -2_190, 'Dairy free.'],
            ],
        ],
        [
            'household' => 'Whitcombe Family', 'city' => 'Middletown', 'zip' => '10940',
            'first' => 'Gail', 'last' => 'Whitcombe',
            'email' => 'gail.whitcombe@example.com', 'phone' => '+18455550107',
            // Lapsed: last seen outside the default 120-day window, so the
            // win-back report has a row in it.
            'lifecycle' => 'lapsed', 'source' => 'demo',
            'email_consent' => true, 'sms_consent' => true,
            'visits' => 11, 'ltv' => 302_400, 'first_visit' => -1_180, 'last_visit' => -286,
            'children' => [
                ['Eleanor', -3_285, null],
                ['Hugo', -1_825, 'Nut allergy, severe. No cake from outside.'],
            ],
        ],
        [
            'household' => 'Petrosyan Family', 'city' => 'Warwick', 'zip' => '10990',
            'first' => 'Anahit', 'last' => 'Petrosyan',
            'email' => 'anahit.petrosyan@example.com', 'phone' => '+18455550192',
            'lifecycle' => 'member', 'source' => 'demo',
            'email_consent' => true, 'sms_consent' => true,
            'visits' => 19, 'ltv' => 418_600, 'first_visit' => -730, 'last_visit' => -6,
            'children' => [
                ['Narek', -2_555, null],
            ],
        ],
        [
            'household' => 'Delgado Family', 'city' => 'Florida', 'zip' => '10921',
            'first' => 'Rosa', 'last' => 'Delgado',
            'email' => 'rosa.delgado@example.com', 'phone' => '+18455550164',
            'lifecycle' => 'lead', 'source' => 'demo',
            'email_consent' => true, 'sms_consent' => false,
            'visits' => 1, 'ltv' => 8_400, 'first_visit' => -14, 'last_visit' => -14,
            'children' => [
                ['Ximena', -2_555, null],
            ],
        ],
        [
            'household' => 'Nakamura Family', 'city' => 'Highland Mills', 'zip' => '10930',
            'first' => 'Kenji', 'last' => 'Nakamura',
            'email' => 'kenji.nakamura@example.com', 'phone' => '+18455550135',
            'lifecycle' => 'prospect', 'source' => 'demo',
            'email_consent' => false, 'sms_consent' => false,
            'visits' => 2, 'ltv' => 19_750, 'first_visit' => -96, 'last_visit' => -47,
            'children' => [
                ['Sora', -3_650, null],
                ['Yuto', -2_190, null],
            ],
        ],
        [
            'household' => 'Mbeki Family', 'city' => 'Harriman', 'zip' => '10926',
            'first' => 'Thandi', 'last' => 'Mbeki',
            'email' => 'thandi.mbeki@example.com', 'phone' => '+18455550151',
            'lifecycle' => 'customer', 'source' => 'demo',
            'email_consent' => true, 'sms_consent' => true,
            'visits' => 4, 'ltv' => 96_300, 'first_visit' => -400, 'last_visit' => -33,
            'children' => [
                ['Sipho', -2_920, null],
            ],
        ],
    ];

    $contactIds = [];
    $contacts = 0;
    $children = 0;

    foreach ($families as $family) {
        $householdId = DB::insert('households', [
            'name'        => $family['household'],
            'city'        => $family['city'],
            'state'       => 'NY',
            'postal_code' => $family['zip'],
            'notes'       => 'Example data created by the installer. Safe to delete.',
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        $contactId = DB::insert('contacts', [
            'first_name'        => $family['first'],
            'last_name'         => $family['last'],
            'email'             => $family['email'],
            // Rendered and normalised forms both, so the SMS dedup path has
            // something realistic to match on.
            'phone'             => '(845) 555-' . substr($family['phone'], -4),
            'phone_normalized'  => $family['phone'],
            'city'              => $family['city'],
            'state'             => 'NY',
            'postal_code'       => $family['zip'],
            'country'           => 'US',
            'lifecycle_stage'   => $family['lifecycle'],
            'source'            => 'demo',
            'source_detail'     => 'Installer example data',
            'owner_id'          => $ownerId,
            'household_id'      => $householdId,
            'email_consent'     => $family['email_consent'] ? 1 : 0,
            'email_consent_at'  => $family['email_consent'] ? $stamp(-300, '10:00:00') : null,
            'sms_consent'       => $family['sms_consent'] ? 1 : 0,
            'sms_consent_at'    => $family['sms_consent'] ? $stamp(-300, '10:00:00') : null,
            'sms_consent_source' => $family['sms_consent'] ? 'demo seed (not real evidence)' : null,
            'do_not_contact'    => 0,
            'first_visit_at'    => $family['first_visit'] === null ? null : $stamp($family['first_visit'], '11:15:00'),
            'last_visit_at'     => $family['last_visit'] === null ? null : $stamp($family['last_visit'], '15:45:00'),
            'visit_count'       => $family['visits'],
            'lifetime_value_cents' => $family['ltv'],
            'avg_spend_cents'   => $family['visits'] > 0 ? intdiv($family['ltv'], $family['visits']) : 0,
            'notes_summary'     => 'Example guest created by the installer. Not a real person.',
            'created_at'        => $stamp($family['first_visit'] ?? -30, '09:00:00'),
            'updated_at'        => $now,
        ]);

        DB::update('households', ['primary_contact_id' => $contactId], 'id = :id', ['id' => $householdId]);

        $tag('contact', $contactId);
        $tag('household', $householdId);

        $contactIds[$family['last']] = $contactId;
        $contacts++;

        // Consent evidence that is honest about what it is. A real audit needs
        // to be able to tell seeded consent from consent somebody gave.
        foreach (['email' => $family['email_consent'], 'sms' => $family['sms_consent']] as $channel => $given) {
            if (!$given) {
                continue;
            }

            DB::insert('consent_log', [
                'contact_id' => $contactId,
                'channel'    => $channel,
                'action'     => 'granted',
                'source'     => 'installer_demo_seed',
                'ip'         => null,
                'user_agent' => null,
                'evidence'   => json_encode([
                    'note' => 'Seeded example data. This is NOT evidence of consent from a real '
                        . 'person and must not be treated as such.',
                ], JSON_THROW_ON_ERROR),
                'created_at' => $stamp(-300, '10:00:00'),
            ]);
        }

        foreach ($family['children'] as [$childName, $bornDaysAgo, $notes]) {
            $birthDate = $today->modify($bornDaysAgo . ' days');

            $childId = DB::insert('children', [
                'household_id' => $householdId,
                'contact_id'   => $contactId,
                'first_name'   => $childName,
                'birth_date'   => $birthDate->format('Y-m-d'),
                'birth_month'  => (int) $birthDate->format('n'),
                'allergies'    => $notes,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);

            $tag('child', $childId);
            $children++;
        }
    }

    // ------------------------------------------------------------------
    // Visits
    // ------------------------------------------------------------------
    //
    // One row per contact's most recent visit. Enough for the activity feed
    // and the lapsed calculation to be live; not an attempt to fabricate a
    // whole POS history.

    $visits = 0;

    foreach ($families as $family) {
        if ($family['last_visit'] === null || $family['visits'] === 0) {
            continue;
        }

        $visitId = DB::insert('visits', [
            'contact_id'   => $contactIds[$family['last']],
            'visit_date'   => $day($family['last_visit']),
            'source'       => 'manual',
            'spend_cents'  => intdiv($family['ltv'], max(1, $family['visits'])),
            'party_size'   => 2 + count($family['children']),
            'external_ref' => null,
            'created_at'   => $now,
        ]);

        $tag('visit', $visitId);
        $visits++;
    }

    // ------------------------------------------------------------------
    // Deals and bookings
    // ------------------------------------------------------------------
    //
    // Both depend on the venue seed's pipelines and packages. Skipped rather
    // than fatal when that seed was not run.

    $deals = 0;
    $bookings = 0;
    $tasks = 0;

    $pipeline = DB::first("SELECT id FROM {pipelines} WHERE slug = 'birthday'");

    if ($pipeline !== null) {
        $pipelineId = (int) $pipeline['id'];

        /** @var array<string,int> $stages name => id */
        $stages = [];

        foreach (DB::all(
            'SELECT id, name FROM {pipeline_stages} WHERE pipeline_id = :p ORDER BY sort_order',
            ['p' => $pipelineId]
        ) as $stage) {
            $stages[(string) $stage['name']] = (int) $stage['id'];
        }

        $packages = DB::all('SELECT id, name, base_price_cents, deposit_cents FROM {party_packages} ORDER BY sort_order');

        // [ contact surname, title, stage, value cents, event in N days,
        //   guests, status, source, next action, next action in N days ]
        $deck = [
            ['Alvarez', 'Lucia Alvarez — 8th birthday', 'Deposit Paid', 54_900, 12, 14, 'open',
                'website_form', 'Confirm final headcount', 5],
            ['Brennan', 'Rory Brennan — 10th birthday', 'Quote Sent', 61_900, 26, 16, 'open',
                'phone', 'Chase the quote', 1],
            ['Okafor', 'Ada Okafor — 6th birthday', 'New Inquiry', 44_900, 34, 12, 'open',
                'website_form', 'First call — 2 hour response deadline', 0],
            ['Delgado', 'Ximena Delgado — 7th birthday', 'Contacted', 49_900, 41, 15, 'open',
                'referral', 'Send the quote', 2],
            ['Nakamura', 'Sora Nakamura — 10th birthday', 'Follow-Up', 67_900, 19, 18, 'open',
                'walk_in', 'Third follow-up, then let it go', 3],
            ['Mbeki', 'Sipho Mbeki — 8th birthday', 'Confirmed', 58_900, 5, 14, 'won',
                'website_form', null, null],
            ['Petrosyan', 'Narek Petrosyan — 7th birthday', 'Confirmed', 72_400, -9, 20, 'won',
                'repeat', null, null],
            ['Whitcombe', 'Hugo Whitcombe — 5th birthday', 'Lost', 51_900, -40, 12, 'lost',
                'website_form', null, null],
        ];

        $lostReasonId = (int) DB::value('SELECT id FROM {lost_reasons} ORDER BY id LIMIT 1') ?: null;

        foreach ($deck as $i => [$surname, $title, $stageName, $value, $eventIn, $guests,
            $status, $source, $nextAction, $nextIn]) {
            if (!isset($stages[$stageName], $contactIds[$surname])) {
                continue;
            }

            $createdOffset = $eventIn - 45;

            $dealId = DB::insert('deals', [
                'pipeline_id'         => $pipelineId,
                'stage_id'            => $stages[$stageName],
                'title'               => $title,
                'contact_id'          => $contactIds[$surname],
                'owner_id'            => $ownerId,
                'value_cents'         => $value,
                'expected_close_date' => $day(max($createdOffset + 7, $eventIn - 21)),
                'event_date'          => $day($eventIn),
                'guest_count'         => $guests,
                'source'              => $source,
                'source_detail'       => 'Installer example data',
                'status'              => $status,
                'lost_reason_id'      => $status === 'lost' ? $lostReasonId : null,
                'stage_entered_at'    => $stamp($createdOffset + 3, '12:00:00'),
                'last_activity_at'    => $stamp(min(-1, $createdOffset + 5), '12:00:00'),
                'next_action'         => $nextAction,
                'next_action_at'      => $nextIn === null ? null : $stamp($nextIn, '09:30:00'),
                'won_at'              => $status === 'won' ? $stamp($createdOffset + 10, '16:00:00') : null,
                'lost_at'             => $status === 'lost' ? $stamp($createdOffset + 20, '16:00:00') : null,
                'created_at'          => $stamp($createdOffset, '10:20:00'),
                'updated_at'          => $now,
            ]);

            $tag('deal', $dealId);
            $deals++;

            $activityId = DB::insert('activities', [
                'entity_type' => 'deal',
                'entity_id'   => $dealId,
                'type'        => 'note',
                'user_id'     => $ownerId,
                'contact_id'  => $contactIds[$surname],
                'subject'     => 'Example inquiry',
                'body'        => 'Created by the installer as example data. Delete the `demo-data` '
                    . 'tag group to clear it.',
                'occurred_at' => $stamp($createdOffset, '10:21:00'),
                'created_at'  => $stamp($createdOffset, '10:21:00'),
            ]);

            $tag('activity', $activityId);

            if ($nextAction !== null && $nextIn !== null) {
                $taskId = DB::insert('tasks', [
                    'title'       => $nextAction,
                    'description' => 'Example task from the installer.',
                    'entity_type' => 'deal',
                    'entity_id'   => $dealId,
                    'assigned_to' => $ownerId,
                    'created_by'  => $ownerId,
                    'priority'    => $nextIn <= 0 ? 'urgent' : ($nextIn <= 2 ? 'high' : 'normal'),
                    'status'      => 'open',
                    'due_at'      => $stamp($nextIn, '09:30:00'),
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);

                $tag('task', $taskId);
                $tasks++;
            }

            // Won deals get a booking, which is what puts something on the
            // calendar and in the "parties this week" widget.
            if ($status !== 'won' || $packages === []) {
                continue;
            }

            $package = $packages[$i % count($packages)];
            $subtotal = (int) $package['base_price_cents'];
            $tax = (int) round($subtotal * 0.08125);
            $total = $subtotal + $tax;
            $deposit = (int) $package['deposit_cents'];
            $past = $eventIn < 0;

            $bookingId = DB::insert('bookings', [
                'booking_number'      => 'DEMO-' . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
                'deal_id'             => $dealId,
                'contact_id'          => $contactIds[$surname],
                'type'                => 'birthday',
                'package_id'          => (int) $package['id'],
                'status'              => $past ? 'completed' : 'deposit_paid',
                'event_date'          => $day($eventIn),
                'start_time'          => '13:00:00',
                'end_time'            => '15:00:00',
                'guest_count'         => $guests,
                'adult_count'         => (int) round($guests * 0.3),
                'child_count'         => $guests - (int) round($guests * 0.3),
                'guest_of_honor_name' => explode(' ', $title)[0],
                'guest_of_honor_age'  => (int) filter_var($title, FILTER_SANITIZE_NUMBER_INT) ?: null,
                'subtotal_cents'      => $subtotal,
                'tax_cents'           => $tax,
                'total_cents'         => $total,
                'deposit_cents'       => $deposit,
                'deposit_paid_at'     => $stamp($eventIn - 30, '11:00:00'),
                'balance_due_cents'   => $past ? 0 : $total - $deposit,
                'paid_in_full_at'     => $past ? $stamp($eventIn, '15:30:00') : null,
                'host_user_id'        => $ownerId,
                'allergies'           => $surname === 'Whitcombe'
                    ? 'SEVERE NUT ALLERGY — no outside cake.'
                    : null,
                'internal_notes'      => 'Example booking created by the installer.',
                'source'              => $source,
                'confirmed_at'        => $stamp($eventIn - 30, '11:01:00'),
                'created_at'          => $stamp($createdOffset, '10:25:00'),
                'updated_at'          => $now,
            ]);

            $tag('booking', $bookingId);
            $bookings++;

            // The two payment rows carry no tag of their own: they cascade
            // from the booking (fk_booking_payments_booking is ON DELETE
            // CASCADE), so deleting the tagged booking takes them with it.
            DB::insert('booking_payments', [
                'booking_id'   => $bookingId,
                'amount_cents' => $deposit,
                'type'         => 'deposit',
                'method'       => 'card',
                'reference'    => 'DEMO-DEPOSIT-' . ($i + 1),
                'recorded_by'  => $ownerId,
                'occurred_at'  => $stamp($eventIn - 30, '11:00:00'),
                'note'         => 'Example payment. No card data is stored by Castle CRM.',
                'created_at'   => $now,
            ]);

            if ($past) {
                DB::insert('booking_payments', [
                    'booking_id'   => $bookingId,
                    'amount_cents' => $total - $deposit,
                    'type'         => 'balance',
                    'method'       => 'card',
                    'reference'    => 'DEMO-BALANCE-' . ($i + 1),
                    'recorded_by'  => $ownerId,
                    'occurred_at'  => $stamp($eventIn, '15:30:00'),
                    'note'         => 'Example payment.',
                    'created_at'   => $now,
                ]);
            }
        }
    }

    return [
        'contacts' => $contacts,
        'children' => $children,
        'deals'    => $deals,
        'bookings' => $bookings,
        'visits'   => $visits,
        'tasks'    => $tasks,
    ];
};
