<?php

declare(strict_types=1);

/**
 * The installer's seeds.
 *
 * The demo seed gets the most attention here, because it is the one piece of
 * seeded data that could cause real harm: fictional guests in a real contact
 * list, reachable by a real campaign. The tests assert the properties that
 * make that impossible rather than the row counts, which are allowed to
 * change.
 */

use App\Core\DB;
use Tests\Assert;
use Tests\Harness;

Harness::suite('integration/seeds');

if (!test_boot()) {
    Harness::skip('seed suite', 'no database available');

    return;
}

/** @return callable */
function load_seed(string $name): callable
{
    /** @var callable $seed */
    $seed = require CC_ROOT . '/install/seeds/' . $name . '.php';

    return $seed;
}

// ------------------------------------------------------------ the templates
//
// (The playbook tests are at the foot of this file.)

Harness::test('the template seed is idempotent', function (): void {
    $seed = load_seed('templates');

    $before = (int) DB::value('SELECT COUNT(*) FROM {templates}');
    $result = $seed();

    Assert::same(0, $result['templates'], 'a second run must create nothing');
    Assert::greaterThan(0, $result['skipped']);
    Assert::same($before, (int) DB::value('SELECT COUNT(*) FROM {templates}'));
});

Harness::test('EVERY SEEDED TEXT FITS ONE SEGMENT, AND THE MARKETING ONES CARRY THEIR OPT-OUT', function (): void {
    /*
     * A text costs per SEGMENT, and the limit is 160 characters in GSM-7 but
     * only 70 in UCS-2 — so one curly apostrophe pasted from a word processor
     * re-encodes a whole message and doubles what every send costs. These are
     * the venue's own messages, going out on a sequence nobody watches, so
     * each one is measured WITH ITS MERGE TAGS FILLED: a template that fits
     * in the editor and spills over once somebody's name is in it is the
     * version that actually gets sent.
     *
     * The values below are deliberately long — a full name and the venue's
     * own name, which is 21 characters.
     */
    $values = [
        '{{first_name}}'  => 'Katherine',
        '{{full_name}}'   => 'Katherine Fitzgerald',
        '{{venue_name}}'  => 'The Castle Fun Center',
        '{{venue_phone}}' => '(845) 555-0123',
        '{{child_name}}'  => 'Alexander',
        /*
         * A LINK IS THE EXPENSIVE MERGE TAG, and the one most likely to be
         * underestimated: this is a real-length domain plus the full
         * 32-character token the feedback links carry, 69 characters that are
         * invisible in the template editor. Left unsubstituted it would
         * measure as the 15 characters of "{{survey_link}}" and a text that
         * costs two segments would pass this test.
         */
        '{{survey_link}}' => 'https://www.thecastlefuncenter.com/s/0123456789abcdef0123456789abcdef',
    ];

    $texts = DB::all("SELECT slug, body FROM {templates} WHERE channel = 'sms'");

    Assert::greaterThan(4, count($texts), 'the installer should ship the texts the playbooks send');

    $tooLong = [];
    $noOptOut = [];

    foreach ($texts as $text) {
        $body = strtr((string) $text['body'], $values);

        if (App\Services\SmsPolicy::segments($body) > 1) {
            $tooLong[] = (string) $text['slug'] . ' is ' . App\Services\SmsPolicy::length($body)
                . ' ' . App\Services\SmsPolicy::encoding($body) . ' characters, so it costs '
                . App\Services\SmsPolicy::segments($body) . ' texts';
        }

        // A text about a booking the guest has already made is transactional
        // and needs no opt-out; anything that offers or invites does. The
        // seeded marketing ones are recognisable by their category.
        if (in_array((string) $text['slug'], [
            'winback-sms',
            'quote-nudge-sms',
            // A feedback request is marketing too: it is not something a
            // guest needs in order to use a booking they have already made,
            // so consent applies and an opt-out belongs in it.
            'survey-invitation-sms',
        ], true)) {
            if (!App\Services\SmsPolicy::hasOptOut($body)) {
                $noOptOut[] = (string) $text['slug'];
            }
        }
    }

    Assert::same([], $tooLong, implode("\n   ", $tooLong));
    Assert::same([], $noOptOut, 'marketing texts with no way to stop them: ' . implode(', ', $noOptOut));
});

Harness::test('every seeded EMAIL template has a subject, a body and declared merge tags', function (): void {
    // Email only: a text has no subject line, and storing one would put
    // "Subject:" above a text message somewhere.
    foreach (DB::all("SELECT * FROM {templates} WHERE channel = 'email'") as $template) {
        $slug = (string) $template['slug'];

        Assert::notEmpty($template['subject'], $slug . ' has no subject');
        Assert::notEmpty($template['body'], $slug . ' has no body');
        Assert::notEmpty($template['merge_tags'], $slug . ' declares no merge tags');

        $tags = json_decode((string) $template['merge_tags'], true);

        Assert::true(is_array($tags), $slug . ' has unreadable merge_tags JSON');
    }
});

Harness::test('every merge tag used in a template is declared', function (): void {
    // The point of declaring them is that a template editor can list them. A
    // tag in the body that nobody declared is a tag nobody will ever fill in,
    // which renders as empty text in front of a guest.
    foreach (DB::all('SELECT * FROM {templates}') as $template) {
        $slug = (string) $template['slug'];
        $declared = (array) json_decode((string) $template['merge_tags'], true);

        preg_match_all(
            '/\{\{\s*([a-z0-9_.]+)\s*\}\}/i',
            (string) $template['subject'] . ' ' . (string) $template['body']
                . ' ' . (string) $template['preheader'],
            $matches
        );

        foreach (array_unique($matches[1]) as $used) {
            Assert::inArray(
                strtolower($used),
                array_map('strtolower', $declared),
                $slug . ' uses {{' . $used . '}} but does not declare it'
            );
        }
    }
});

Harness::test('and every seeded TEXT has a body and declared merge tags', function (): void {
    foreach (DB::all("SELECT * FROM {templates} WHERE channel = 'sms'") as $text) {
        $slug = (string) $text['slug'];

        Assert::notEmpty($text['body'], $slug . ' has no body');
        Assert::notEmpty($text['merge_tags'], $slug . ' declares no merge tags');
        Assert::null($text['subject'], $slug . ' has a subject, and a text has no subject line');
    }
});

Harness::test('no template hard-codes a venue phone number or address', function (): void {
    // Everything venue-specific has to come from settings, or the first thing
    // a venue has to do is edit thirteen templates by hand.
    foreach (DB::all('SELECT * FROM {templates}') as $template) {
        $body = (string) $template['body'];

        Assert::false(
            (bool) preg_match('/\(\d{3}\)\s*\d{3}-\d{4}/', $body),
            (string) $template['slug'] . ' hard-codes a phone number'
        );
    }
});

Harness::test('marketing templates do not hard-code an unsubscribe line', function (): void {
    // Mailer::appendCompliance() adds one to every marketing send. A template
    // that also carries its own would produce two, and the one it carries
    // would not be a working link.
    foreach (
        DB::all("SELECT * FROM {templates} WHERE category = 'marketing'") as $template
    ) {
        Assert::notContains(
            'unsubscribe',
            strtolower((string) $template['body']),
            (string) $template['slug'] . ' should leave the unsubscribe line to the send path'
        );
    }
});

// -------------------------------------------------------------- the demo data

Harness::test('the demo seed creates guests, children, deals and bookings', function (): void {
    test_reset_transactional_data();

    $result = load_seed('demo')();

    Assert::greaterThan(0, $result['contacts']);
    Assert::greaterThan(0, $result['children']);
    Assert::greaterThan(0, $result['deals'], 'the pipeline needs rows to be worth looking at');
    Assert::greaterThan(0, $result['bookings'], 'the calendar needs rows too');
    Assert::greaterThan(0, $result['visits']);
});

Harness::test('no demo guest can ever be contacted', function (): void {
    // The single most important property of this seed. example.com is reserved
    // by RFC 2606 and 555-01xx by the NANP, so neither can reach a person even
    // if Safe Mode is off and a campaign goes out by mistake.
    $contacts = DB::all("SELECT * FROM {contacts} WHERE source = 'demo'");

    Assert::greaterThan(0, count($contacts));

    foreach ($contacts as $contact) {
        $email = (string) $contact['email'];
        $phone = (string) $contact['phone_normalized'];

        Assert::true(
            str_ends_with($email, '@example.com'),
            $email . ' is not on a reserved domain'
        );
        Assert::matches(
            '/^\+1\d{3}5550(1\d{2})$/',
            $phone,
            $phone . ' is not in the reserved 555-01xx fiction range'
        );
    }
});

Harness::test('every demo row is tagged so it can be found and deleted', function (): void {
    $tagId = (int) DB::value('SELECT id FROM {tags} WHERE name = :n', ['n' => 'demo-data']);

    Assert::greaterThan(0, $tagId, 'without the marker tag this data is unremovable in practice');

    $untagged = (int) DB::value(
        "SELECT COUNT(*) FROM {contacts} c
          WHERE c.source = 'demo'
            AND NOT EXISTS (
                SELECT 1 FROM {taggables} t
                 WHERE t.tag_id = :tag AND t.entity_type = 'contact' AND t.entity_id = c.id
            )",
        ['tag' => $tagId]
    );

    Assert::same(0, $untagged, 'every example contact must carry the demo-data tag');
});

Harness::test('seeded consent says in writing that it is not real evidence', function (): void {
    $rows = DB::all(
        "SELECT * FROM {consent_log} WHERE source = 'installer_demo_seed'"
    );

    Assert::greaterThan(0, count($rows));

    foreach ($rows as $row) {
        $evidence = strtolower((string) $row['evidence']);

        Assert::contains(
            'not evidence of consent',
            $evidence,
            'an auditor has to be able to tell seeded consent from the real thing'
        );
    }
});

Harness::test('some demo guests have no consent, so the reachable count is instructive', function (): void {
    $total = (int) DB::value("SELECT COUNT(*) FROM {contacts} WHERE source = 'demo'");
    $reachable = (int) DB::value(
        "SELECT COUNT(*) FROM {contacts}
          WHERE source = 'demo' AND email_consent = 1 AND do_not_contact = 0"
    );

    Assert::greaterThan(0, $reachable);
    Assert::greaterThan(
        $reachable,
        $total,
        'a demo list where everyone has consented teaches the operator the wrong lesson'
    );
});

Harness::test('the demo seed produces both a lapsed and a recent guest', function (): void {
    // So the lapsed-guest report and the recent-visit widget each have a row.
    $lapseDays = 120;

    // UTC-CORRECT: this test compares a stored UTC instant against a UTC
    // instant in its own SQL. Nothing here is a calendar day.
    $lapsed = (int) DB::value(
        "SELECT COUNT(*) FROM {contacts}
          WHERE source = 'demo' AND last_visit_at < :cutoff",
        ['cutoff' => gmdate('Y-m-d H:i:s', time() - ($lapseDays * 86400))]
    );

    $recent = (int) DB::value(
        "SELECT COUNT(*) FROM {contacts}
          WHERE source = 'demo' AND last_visit_at >= :cutoff",
        // UTC-CORRECT: the other half of the same comparison.
        ['cutoff' => gmdate('Y-m-d H:i:s', time() - (30 * 86400))]
    );

    Assert::greaterThan(0, $lapsed);
    Assert::greaterThan(0, $recent);
});

Harness::test('demo bookings land in the future as well as the past', function (): void {
    $upcoming = (int) DB::value(
        'SELECT COUNT(*) FROM {bookings} WHERE event_date >= CURDATE()'
    );
    $past = (int) DB::value(
        'SELECT COUNT(*) FROM {bookings} WHERE event_date < CURDATE()'
    );

    Assert::greaterThan(0, $upcoming, 'the calendar should show something');
    Assert::greaterThan(0, $past, 'and the completed-parties report should too');
});

Harness::test('demo money adds up', function (): void {
    foreach (DB::all('SELECT * FROM {bookings}') as $booking) {
        Assert::same(
            (int) $booking['subtotal_cents'] - (int) $booking['discount_cents'] + (int) $booking['tax_cents'],
            (int) $booking['total_cents'],
            'booking ' . $booking['booking_number'] . ' does not total correctly'
        );

        Assert::atLeast(
            0,
            (int) $booking['balance_due_cents'],
            'a negative balance would show as a debt to the guest'
        );
    }
});

Harness::test('the demo seed is idempotent', function (): void {
    $before = (int) DB::value("SELECT COUNT(*) FROM {contacts} WHERE source = 'demo'");

    $result = load_seed('demo')();

    Assert::same(0, $result['contacts'], 'running the installer twice must not double the examples');
    Assert::same($before, (int) DB::value("SELECT COUNT(*) FROM {contacts} WHERE source = 'demo'"));
});

Harness::test('deleting the demo contacts leaves no orphans', function (): void {
    // The promise the installer makes is that this data is removable. Prove
    // the foreign keys actually carry the deletes.
    DB::delete('contacts', "source = 'demo'");

    Assert::same(
        0,
        (int) DB::value('SELECT COUNT(*) FROM {children}'),
        'children cascade from their guardian'
    );
    Assert::same(
        0,
        (int) DB::value("SELECT COUNT(*) FROM {consent_log} WHERE source = 'installer_demo_seed'"),
        'consent rows cascade too'
    );

    test_reset_transactional_data();
});

// ------------------------------------- the advertised cleanup, then a re-run

Harness::test('re-seeding after the advertised cleanup does not fail the installer', function (): void {
    // MEASURED. bookings do not cascade from contacts
    // (fk_bookings_contact is ON DELETE SET NULL) and booking_number is
    // UNIQUE, so the documented cleanup ("delete the demo contacts") left the
    // bookings behind; the guard keyed only on contacts then read as "not
    // seeded" and the re-run died on the unique key — inside the installer's
    // transaction, taking the whole setup down rather than skipping a seed.
    test_reset_transactional_data();

    $first = load_seed('demo')();

    Assert::greaterThan(0, $first['bookings'], 'precondition: bookings were created');

    // Exactly the cleanup the seed header, step 7 and the docs advertise.
    DB::delete('contacts', "source = 'demo'");

    Assert::greaterThan(
        0,
        (int) DB::value("SELECT COUNT(*) FROM {bookings} WHERE booking_number LIKE 'DEMO-%'"),
        'precondition: the bookings survive the contact delete'
    );

    $second = null;

    Assert::doesNotThrow(static function () use (&$second): void {
        $second = load_seed('demo')();
    }, 'a re-run after the cleanup must not throw — it runs inside the installer transaction');

    Assert::same(0, $second['contacts'], 'and it must create nothing rather than duplicate');
    Assert::same(0, $second['bookings']);

    test_reset_transactional_data();
});

Harness::test('every demo row is reachable from the demo-data tag', function (): void {
    // The seed's contract says "delete the examples" is one filter. Tasks were
    // not tagged, so after the cleanup five fake urgent tasks stayed on the
    // owner's dashboard. Anything not tagged must cascade from something that
    // is — this asserts one or the other for every table the seed writes.
    test_reset_transactional_data();
    load_seed('demo')();

    $tagId = (int) DB::value('SELECT id FROM {tags} WHERE name = :n', ['n' => 'demo-data']);

    Assert::greaterThan(0, $tagId);

    $tagged = static fn (string $type): int => (int) DB::value(
        'SELECT COUNT(*) FROM {taggables} WHERE tag_id = :t AND entity_type = :e',
        ['t' => $tagId, 'e' => $type]
    );

    foreach (['contact', 'household', 'child', 'visit', 'deal', 'activity', 'task', 'booking'] as $type) {
        Assert::greaterThan(0, $tagged($type), $type . ' rows must carry the demo-data tag');
    }

    // The two that cascade instead, asserted as cascading rather than tagged.
    Assert::greaterThan(0, (int) DB::value('SELECT COUNT(*) FROM {consent_log}'));
    Assert::greaterThan(0, (int) DB::value('SELECT COUNT(*) FROM {booking_payments}'));

    // Now delete via the tag and prove nothing demo-ish is left behind.
    foreach (['task', 'activity', 'visit', 'child', 'booking', 'deal', 'contact'] as $type) {
        $ids = DB::column(
            'SELECT entity_id FROM {taggables} WHERE tag_id = :t AND entity_type = :e',
            ['t' => $tagId, 'e' => $type]
        );

        if ($ids === []) {
            continue;
        }

        $table = match ($type) {
            'child'    => 'children',
            'activity' => 'activities',
            default    => $type . 's',
        };
        $list = implode(',', array_map('intval', $ids));

        DB::query('DELETE FROM {' . $table . '} WHERE id IN (' . $list . ')');
    }

    Assert::same(
        0,
        (int) DB::value('SELECT COUNT(*) FROM {tasks}'),
        'no fake task may survive the documented cleanup — they land on the owner dashboard'
    );
    Assert::same(0, (int) DB::value('SELECT COUNT(*) FROM {bookings}'));
    Assert::same(
        0,
        (int) DB::value('SELECT COUNT(*) FROM {booking_payments}'),
        'payments should have cascaded with their booking'
    );
    Assert::same(
        0,
        (int) DB::value('SELECT COUNT(*) FROM {consent_log}'),
        'consent rows should have cascaded with their contact'
    );

    test_reset_transactional_data();
});

// ------------------------------------------- the removal the runbook promises
//
// The test above proves the cleanup is POSSIBLE by hand. These prove the
// button does it, because the runbook tells a venue to press one before
// importing a real guest list, and for most of a release that button did not
// exist — the doc described a feature nobody had written.

Harness::test('THE DEMO REMOVAL TAKES EVERYTHING THE SEED CREATED', function (): void {
    test_reset_transactional_data();
    load_seed('demo')();

    $before = App\Services\DemoData::summary();

    Assert::true($before['present'], 'the seed just ran, so there is something to remove');

    foreach (['contact', 'household', 'child', 'visit', 'deal', 'activity', 'task', 'booking'] as $type) {
        Assert::greaterThan(0, $before['counts'][$type] ?? 0, $type . ' should be counted');
    }

    $removed = App\Services\DemoData::remove();

    Assert::same($before['total'], $removed['total'], 'it removes what it said it would');

    // The tagged tables.
    foreach (['contacts', 'households', 'children', 'visits', 'deals', 'activities',
              'tasks', 'bookings'] as $table) {
        Assert::same(
            0,
            (int) DB::value('SELECT COUNT(*) FROM {' . $table . '}'),
            $table . ' must be empty after the removal'
        );
    }

    // And the two that cascade rather than carry the tag. Bookings are the
    // one that matters: their FK to contacts is SET NULL, so a removal that
    // only deleted contacts would leave these behind, looking finished.
    Assert::same(0, (int) DB::value('SELECT COUNT(*) FROM {booking_payments}'));
    Assert::same(0, (int) DB::value('SELECT COUNT(*) FROM {consent_log}'));

    Assert::false(
        DB::exists("SELECT id FROM {tags} WHERE name = 'demo-data'"),
        'the marker tag goes too once nothing wears it'
    );

    test_reset_transactional_data();
});

Harness::test('the removal names what it will delete in words, not type names', function (): void {
    // The first version of the card offered to delete "8 activitys, 11 childs".
    $words = App\Services\DemoData::describe([
        'contact' => 8, 'household' => 1, 'child' => 11, 'visit' => 7,
        'deal' => 8, 'activity' => 8, 'task' => 5, 'booking' => 2,
    ]);

    Assert::same(
        '8 guests, 1 household, 11 children, 7 visits, 8 party enquiries, 8 activities, 5 tasks, 2 bookings',
        $words
    );

    Assert::same('1 guest, 1 child', App\Services\DemoData::describe(['contact' => 1, 'child' => 1]));
    Assert::same('', App\Services\DemoData::describe([]), 'nothing to delete says nothing');
    Assert::same('', App\Services\DemoData::describe(['contact' => 0]), 'and neither does zero');
});

Harness::test('removing the demo data twice is not an error', function (): void {
    test_reset_transactional_data();
    load_seed('demo')();

    App\Services\DemoData::remove();

    $after = App\Services\DemoData::summary();

    Assert::false($after['present'], 'nothing left to find');
    Assert::same(0, $after['total']);

    $second = App\Services\DemoData::remove();

    Assert::same(0, $second['total'], 'a second removal does nothing rather than throwing');

    test_reset_transactional_data();
});

Harness::test('THE REMOVAL KEEPS WHAT THE VENUE MADE ITSELF, AND SAYS SO FIRST', function (): void {
    // A venue kicking the tyres takes a booking against an example guest to
    // see the flow. That booking is theirs, not the seed's, so it survives —
    // but it survives with no guest attached, and the only honest place to
    // say so is BEFORE the removal, not in the flash message afterwards.
    test_reset_transactional_data();
    load_seed('demo')();

    $contactId = (int) DB::value("SELECT id FROM {contacts} WHERE source = 'demo' LIMIT 1");

    Assert::greaterThan(0, $contactId);

    $mine = DB::insert('bookings', [
        'booking_number' => 'TRIAL-0001',
        'contact_id'     => $contactId,
        'status'         => 'confirmed',
        'event_date'     => gmdate('Y-m-d', time() + 86400),
        'start_time'     => '14:00:00',
        'end_time'       => '16:00:00',
        'created_at'     => gmdate('Y-m-d H:i:s'),
        'updated_at'     => gmdate('Y-m-d H:i:s'),
    ]);

    $summary = App\Services\DemoData::summary();

    Assert::same(1, $summary['untagged_linked'], 'the venue own booking is counted and named');

    App\Services\DemoData::remove();

    $survivor = DB::first('SELECT contact_id FROM {bookings} WHERE id = :id', ['id' => $mine]);

    Assert::notNull($survivor, 'a booking the venue made is never deleted by this');
    Assert::null($survivor['contact_id'], 'and its example guest is gone, so it has none');

    test_reset_transactional_data();
});

Harness::test('the removal leaves a tag the venue uses itself alone', function (): void {
    // `demo-data` is an ordinary tag. If somebody has put it on their own
    // rows, deleting the tag would silently untag their work.
    test_reset_transactional_data();
    load_seed('demo')();

    $tagId = (int) DB::value("SELECT id FROM {tags} WHERE name = 'demo-data'");
    $now   = gmdate('Y-m-d H:i:s');

    // A row of theirs, of a type the seed never tags, wearing the same tag.
    DB::query(
        'INSERT IGNORE INTO {taggables} (tag_id, entity_type, entity_id, created_at)
         VALUES (:tag, :type, :id, :at)',
        ['tag' => $tagId, 'type' => 'company', 'id' => 99999, 'at' => $now]
    );

    App\Services\DemoData::remove();

    Assert::true(
        DB::exists('SELECT id FROM {tags} WHERE id = :id', ['id' => $tagId]),
        'the tag stays while anything still wears it'
    );

    DB::delete('taggables', 'tag_id = :tag', ['tag' => $tagId]);
    DB::delete('tags', 'id = :id', ['id' => $tagId]);

    test_reset_transactional_data();
});

// ------------------------------------------------------------- the playbooks

Harness::test('EVERY SEEDED STEP IS ONE THE ENGINE CAN ACTUALLY PERFORM', function (): void {
    /*
     * A step the engine refuses is worse than a missing one: it saves, it
     * shows in the sequence, and then it FAILS a run days later — or, for a
     * condition with nothing to check, quietly sends every guest down the NO
     * side with half the sequence unreachable.
     *
     * The two mistakes this catches are the ones that are invisible to read:
     * a delay tied to a date whose config does not name the date, and a
     * condition saved as a bare list instead of a group. Both were made while
     * writing this seed.
     */
    $offenders = [];

    foreach (DB::all('SELECT s.*, a.slug AS automation FROM {automation_steps} s
                        JOIN {automations} a ON a.id = s.automation_id
                       ORDER BY a.id, s.sort_order') as $step) {
        $problem = App\Services\AutomationEngine::validateStep($step);

        if ($problem !== null) {
            $offenders[] = (string) $step['automation'] . ' step ' . (int) $step['sort_order']
                . ' (' . (string) $step['type'] . '): ' . $problem;
        }
    }

    Assert::same([], $offenders, "seeded steps the engine would refuse:\n   " . implode("\n   ", $offenders));
});

Harness::test('EVERY TEMPLATE A SEEDED STEP NAMES EXISTS, ON THE RIGHT CHANNEL', function (): void {
    /*
     * The failure this prevents is the most ordinary one in the whole
     * product: a sequence that names a template nobody shipped. The run
     * fails, three times with backoff, days after the venue switched it on
     * and nowhere near the screen where they did it. An email step naming a
     * TEXT template fails the same way, which is why the channel is checked
     * too.
     */
    $missing = [];

    foreach (DB::all('SELECT s.type, s.config, a.slug AS automation FROM {automation_steps} s
                        JOIN {automations} a ON a.id = s.automation_id
                       WHERE s.type IN (\'send_email\', \'send_sms\')') as $step) {
        $config = json_decode((string) $step['config'], true);
        $slug   = is_array($config) ? (string) ($config['template'] ?? '') : '';
        $channel = (string) $step['type'] === 'send_sms' ? 'sms' : 'email';

        if ($slug === '') {
            $missing[] = (string) $step['automation'] . ': a ' . $channel . ' step with no template';

            continue;
        }

        if (!DB::exists(
            'SELECT id FROM {templates} WHERE (slug = :slug OR name = :name) AND channel = :channel AND is_active = 1',
            ['slug' => $slug, 'name' => $slug, 'channel' => $channel]
        )) {
            $missing[] = (string) $step['automation'] . ': no active ' . $channel . ' template "' . $slug . '"';
        }
    }

    Assert::same([], $missing, "steps naming a template that is not there:\n   " . implode("\n   ", $missing));
});

Harness::test('EVERY AUTOMATION WHOSE TRIGGER CAN FIRE HAS STEPS', function (): void {
    /*
     * THE RULE THIS WHOLE SEED EXISTS TO SATISFY, and the one a count cannot
     * express: an automation the venue can switch on must DO something.
     *
     * An empty sequence behind a live trigger is the worst shape in the
     * library. It is listed, it is described, it can be activated, and then
     * it runs and performs nothing — and nothing on the page distinguishes
     * it from the eighty that work. The venue finds out when they ask why
     * the thank-you never went.
     *
     * The exception is deliberate and is the other half of the rule: an
     * automation whose trigger is in `Event::PENDING` CANNOT fire, because
     * the module that would fire it is not built. Those ship empty on
     * purpose, the automation page says exactly what each is waiting on, and
     * this test asserts they are empty rather than quietly authored — a
     * sequence behind a dead trigger reads as finished for ever.
     */
    $rows = DB::all(
        'SELECT a.slug, a.name, a.trigger_type,
                (SELECT COUNT(*) FROM {automation_steps} s WHERE s.automation_id = a.id) AS steps
           FROM {automations} a
          ORDER BY a.id'
    );

    Assert::greaterThan(100, count($rows), 'the library is seeded');

    $emptyButLive = [];
    $authoredButDead = [];

    foreach ($rows as $row) {
        $trigger = (string) $row['trigger_type'];
        $steps   = (int) $row['steps'];
        $waiting = App\Core\Event::pendingOn($trigger);

        if ($waiting === null && $steps === 0) {
            $emptyButLive[] = (string) $row['slug'] . ' — ' . (string) $row['name']
                . ' (' . $trigger . ')';
        }

        if ($waiting !== null && $steps > 0) {
            $authoredButDead[] = (string) $row['slug'] . ' — ' . (string) $row['name']
                . ' (' . $trigger . ', waiting on ' . $waiting . ')';
        }
    }

    Assert::same(
        [],
        $emptyButLive,
        "automations a venue can switch on that would do nothing:\n   "
            . implode("\n   ", $emptyButLive)
    );

    Assert::same(
        [],
        $authoredButDead,
        "sequences behind a trigger nothing fires — they would read as finished for ever:\n   "
            . implode("\n   ", $authoredButDead)
    );
});

Harness::test('A SCHEDULED AUTOMATION THAT MESSAGES A GUEST HAS SOMEBODY TO MESSAGE', function (): void {
    /*
     * The trap: a `cron` automation with NO SEGMENT enrols a single run with
     * no contact on it, and `send_email` on a contactless run returns "there
     * is no guest on this run to email" and records the step as DONE.
     *
     * So the run succeeds, the automation reports success, the step shows as
     * performed — and the seasonal announcement went to nobody. For ever,
     * with nothing on any page to say so. It is the quietest failure in this
     * whole application, and three seeded sequences had it before this test
     * existed.
     *
     * A cron automation that only notifies a USER or raises a task is
     * correct with no segment: that is venue-wide work and needs no guest.
     * The rule is about the ones that reach a guest.
     */
    $offenders = [];

    $rows = DB::all(
        "SELECT a.slug, a.name, a.trigger_config
           FROM {automations} a
          WHERE a.trigger_type = 'cron'
            AND EXISTS (
                SELECT 1 FROM {automation_steps} s
                 WHERE s.automation_id = a.id
                   AND s.type IN ('send_email', 'send_sms', 'send_survey', 'issue_coupon')
            )"
    );

    foreach ($rows as $row) {
        $config = json_decode((string) $row['trigger_config'], true);
        $segment = is_array($config) ? (int) ($config['segment_id'] ?? 0) : 0;

        if ($segment <= 0) {
            $offenders[] = (string) $row['slug'] . ' — ' . (string) $row['name'];

            continue;
        }

        // And the segment it names has to be there. A stale id is the same
        // silence by another route.
        if (!DB::exists('SELECT id FROM {segments} WHERE id = :id', ['id' => $segment])) {
            $offenders[] = (string) $row['slug'] . ' — names segment ' . $segment . ', which does not exist';
        }
    }

    Assert::same(
        [],
        $offenders,
        "scheduled sequences that would message a guest but have no audience:\n   "
            . implode("\n   ", $offenders)
    );
});

Harness::test('every seeded segment compiles to SQL and is answerable', function (): void {
    /*
     * A segment whose rules do not compile is not an error anywhere a person
     * would see: the campaign planner reports an audience of nobody, which
     * reads exactly like a segment that genuinely matches nobody.
     *
     * These three also have to be venue-INDEPENDENT — defined on columns
     * every install maintains rather than on this venue's attractions — or
     * they ship empty and the automations that target them send to nobody,
     * which is the failure the test above is about arriving by another road.
     */
    $segments = DB::all('SELECT slug, name, rules FROM {segments} ORDER BY id');

    Assert::greaterThan(2, count($segments), 'the standing segments are seeded');

    foreach ($segments as $segment) {
        $rules = json_decode((string) $segment['rules'], true);

        Assert::true(is_array($rules), (string) $segment['slug'] . ': rules are not readable');

        $check = App\Services\SegmentEngine::validate($rules);

        Assert::true(
            (bool) $check['ok'],
            (string) $segment['slug'] . ': ' . (string) ($check['error'] ?? 'does not compile')
        );

        Assert::greaterThan(
            0,
            (int) $check['rules'],
            (string) $segment['slug'] . ': compiles to no rules at all, so it matches everybody'
        );

        // And the SQL it compiles to really runs. The count is whatever
        // this database holds — what matters is that asking does not throw,
        // because a cron automation whose segment throws enrols NOBODY and
        // says so only in the log.
        Assert::doesNotThrow(
            static function () use ($check, $rules): void {
                $compiled = App\Services\SegmentEngine::compile($rules);
                DB::value(
                    'SELECT COUNT(*) FROM {contacts} c WHERE ' . $compiled['sql'],
                    $compiled['params']
                );
            },
            (string) $segment['slug'] . ': the SQL it compiles to will not run'
        );
    }
});

Harness::test('a seeded automation with steps can be switched on, and one without cannot', function (): void {
    /*
     * The product promise, as an assertion: a playbook whose steps are
     * authored arrives ready to switch on. The pre-condition is dull and
     * worth stating — every step valid, every template present — and the two
     * tests above are what make it true.
     */
    $withSteps = (int) DB::value(
        'SELECT COUNT(DISTINCT automation_id) FROM {automation_steps}'
    );

    Assert::greaterThan(100, $withSteps, 'every playbook ships its sequences');

    // And the ones with no steps are honest about it rather than switched on.
    Assert::same(0, (int) DB::value(
        'SELECT COUNT(*) FROM {automations} a
          WHERE a.is_active = 1
            AND NOT EXISTS (SELECT 1 FROM {automation_steps} s WHERE s.automation_id = a.id)'
    ), 'nothing is seeded switched on with nothing to do');
});

Harness::test('THE REPUTATION PLAYBOOK HANDS THE ASK TO THE FEEDBACK MODULE', function (): void {
    /*
     * Two things could go wrong here and both are invisible until a guest is
     * involved.
     *
     * The first is a sequence that sends its own survey invitation — it
     * cannot, because the link carries a one-use token that has to be written
     * before it is sent, so an email step naming a template with a
     * {{survey_link}} in it would go out with a hole where the link should
     * be. The `send_survey` step exists to hand that to the module.
     *
     * The second is the detractor sequence raising its own task beside the
     * one the module already raises, which means two people ringing one
     * family about one complaint.
     */
    $playbookId = (int) DB::value("SELECT id FROM {playbooks} WHERE slug = 'reputation-protection'");

    Assert::greaterThan(0, $playbookId, 'the reputation playbook is seeded');

    $steps = DB::all(
        'SELECT a.slug, s.type, s.config
           FROM {automation_steps} s
           JOIN {automations} a ON a.id = s.automation_id
          WHERE a.playbook_id = :p
          ORDER BY a.id, s.sort_order',
        ['p' => $playbookId]
    );

    Assert::greaterThan(10, count($steps), 'its sequences are authored, not empty');

    $types = [];
    $tasksOnDetractor = 0;

    foreach ($steps as $step) {
        $types[(string) $step['type']] = true;

        if (str_contains((string) $step['slug'], 'detractor') && (string) $step['type'] === 'create_task') {
            $tasksOnDetractor++;
        }
    }

    Assert::hasKey('send_survey', $types, 'the ask goes through the module');

    Assert::same(
        0,
        $tasksOnDetractor,
        'the detractor sequence must not raise a second task beside the module\'s own'
    );
});

Harness::test('the playbook seed is idempotent, steps included', function (): void {
    $seed = load_seed('playbooks');

    $before = (int) DB::value('SELECT COUNT(*) FROM {automation_steps}');
    $result = $seed();

    Assert::same(0, $result['playbooks'], 'a second run creates no playbook');
    Assert::same(0, $result['automations'], 'and no automation');
    Assert::same(0, $result['steps'], 'and no step');
    Assert::same($before, (int) DB::value('SELECT COUNT(*) FROM {automation_steps}'));
});

Harness::test('an automation somebody has emptied on purpose stays empty', function (): void {
    /*
     * The top-up on re-seed is for the install that predates the steps
     * shipping. A venue that deleted the steps of a seeded automation has
     * said what they want, and a later seed run putting them back — on an
     * automation that may be switched ON — would start sending the venue's
     * own sequence again.
     */
    $id = (int) DB::value(
        "SELECT a.id FROM {automations} a
          WHERE a.slug LIKE 'birthday-celebration-sales-%'
            AND EXISTS (SELECT 1 FROM {automation_steps} s WHERE s.automation_id = a.id)
          ORDER BY a.id LIMIT 1"
    );

    Assert::greaterThan(0, $id, 'precondition: a seeded automation with steps');

    $steps = DB::all('SELECT * FROM {automation_steps} WHERE automation_id = :a ORDER BY sort_order', ['a' => $id]);

    DB::query('DELETE FROM {automation_steps} WHERE automation_id = :a', ['a' => $id]);

    try {
        $seed = load_seed('playbooks');
        $seed();

        Assert::same(0, (int) DB::value(
            'SELECT COUNT(*) FROM {automation_steps} WHERE automation_id = :a',
            ['a' => $id]
        ), 'the seed must not refill an automation somebody emptied');
    } finally {
        // Put them back, in their own order, so the rest of the suite sees
        // the install the seed describes.
        foreach ($steps as $step) {
            unset($step['id']);
            DB::insert('automation_steps', $step);
        }
    }
});

Harness::test('A MILESTONE CONGRATULATES A GUEST ONCE, NOT ON EVERY LATER VISIT', function (): void {
    /*
     * MEASURED, and it is the subtlest trap in the whole seeded library.
     *
     * `run_once_per_contact` is deliberately bypassed when the trigger names
     * an ENTITY, and `visit.recorded` names a visit — so every visit enrols a
     * fresh run whatever that flag is set to. The "tenth visit" condition
     * stays true from the tenth visit onwards, so the obvious sequence
     * (condition → email) emails a regular EVERY TIME THEY COME, for ever.
     * Twelve visits produced twelve runs and three sends before the guard
     * existed, and a venue finds out when a regular asks why they are
     * congratulated on their tenth visit every week.
     *
     * The guard is the tag the sequence writes itself. This test runs the
     * real engine over a real guest's twelve visits and counts SENDS, not
     * runs — runs are expected to be one per visit.
     */
    $playbook = (int) DB::value(
        "SELECT id FROM {playbooks} WHERE slug = 'visit-ticketing-automation'"
    );
    $automation = (int) DB::value(
        "SELECT id FROM {automations} WHERE slug = 'visit-ticketing-automation-08'"
    );

    Assert::greaterThan(0, $automation, 'the milestone automation is seeded');

    $wasPlaybook   = (int) DB::value('SELECT is_active FROM {playbooks} WHERE id = :id', ['id' => $playbook]);
    $wasAutomation = (int) DB::value('SELECT is_active FROM {automations} WHERE id = :id', ['id' => $automation]);

    // An automation inside a switched-off playbook does not run however
    // active its own flag is, so both go on.
    DB::update('playbooks', ['is_active' => 1], 'id = :id', ['id' => $playbook]);
    DB::update('automations', ['is_active' => 1], 'id = :id', ['id' => $automation]);

    $contactId = DB::insert('contacts', [
        'first_name'    => 'Milestone',
        'last_name'     => 'Tester',
        'email'         => 'milestone.' . bin2hex(random_bytes(4)) . '@example.test',
        'email_consent' => 1,
        'source'        => 'seedtest',
        'created_at'    => now_utc(),
        'updated_at'    => now_utc(),
    ]);

    try {
        for ($day = 0; $day < 12; $day++) {
            App\Services\VisitRecorder::record([
                'contact_id'  => $contactId,
                // Venue days, because that is what a visit_date IS here —
                // "the day a guest would say they came", which is the same
                // thing VisitRecorder fills in when a caller omits it. Only
                // distinctness matters to the count below, but a UTC "today"
                // files the first of these on a day the venue has not
                // reached yet.
                'visit_date'  => test_local_day($day),
                'source'      => 'check_in',
                'spend_cents' => 2500,
            ]);

            // Drain, so each visit's run finishes before the next is recorded
            // — which is what gives the tag time to become the guard.
            for ($i = 0; $i < 3; $i++) {
                App\Services\AutomationRunner::tick(100);
            }
        }

        $sends = (int) DB::value(
            "SELECT COUNT(*) FROM {automation_step_log} l
               JOIN {automation_steps} s ON s.id = l.step_id
               JOIN {automation_runs} r ON r.id = l.run_id
              WHERE r.contact_id = :c AND r.automation_id = :a AND s.type = 'send_email'",
            ['c' => $contactId, 'a' => $automation]
        );

        Assert::same(1, $sends, 'the milestone email is attempted exactly once across twelve visits');

        // And it really did reach the threshold rather than never firing at
        // all — a guard that blocks everything would pass the line above.
        Assert::true(
            DB::exists(
                "SELECT tg.tag_id FROM {taggables} tg
                   JOIN {tags} t ON t.id = tg.tag_id
                  WHERE tg.entity_type = 'contact' AND tg.entity_id = :c AND t.name = :n",
                ['c' => $contactId, 'n' => 'milestone 10']
            ),
            'the guard tag is written, so the condition really did pass once'
        );

        Assert::same(
            12,
            (int) DB::value('SELECT visit_count FROM {contacts} WHERE id = :c', ['c' => $contactId]),
            'precondition: all twelve visits were recorded on separate days'
        );
    } finally {
        DB::update('playbooks', ['is_active' => $wasPlaybook], 'id = :id', ['id' => $playbook]);
        DB::update('automations', ['is_active' => $wasAutomation], 'id = :id', ['id' => $automation]);
        test_reset_transactional_data();
    }
});
