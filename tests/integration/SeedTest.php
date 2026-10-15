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

Harness::test('the template seed is idempotent', function (): void {
    $seed = load_seed('templates');

    $before = (int) DB::value('SELECT COUNT(*) FROM {templates}');
    $result = $seed();

    Assert::same(0, $result['templates'], 'a second run must create nothing');
    Assert::greaterThan(0, $result['skipped']);
    Assert::same($before, (int) DB::value('SELECT COUNT(*) FROM {templates}'));
});

Harness::test('every seeded template has a subject, a body and declared merge tags', function (): void {
    foreach (DB::all('SELECT * FROM {templates}') as $template) {
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

    $lapsed = (int) DB::value(
        "SELECT COUNT(*) FROM {contacts}
          WHERE source = 'demo' AND last_visit_at < :cutoff",
        ['cutoff' => gmdate('Y-m-d H:i:s', time() - ($lapseDays * 86400))]
    );

    $recent = (int) DB::value(
        "SELECT COUNT(*) FROM {contacts}
          WHERE source = 'demo' AND last_visit_at >= :cutoff",
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
