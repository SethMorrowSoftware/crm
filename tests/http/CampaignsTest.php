<?php

declare(strict_types=1);

/**
 * The campaign pages, end to end over HTTP.
 *
 * `tests/unit/CampaignPlannerTest.php` pins the arithmetic — what it costs,
 * who it reaches, when it may go. What this file tests is the part of a
 * campaign that cannot be taken back, and the shape of these tests follows
 * from that: a campaign is the only screen in this application where pressing
 * a button spends money and contacts hundreds of real people at once. So the
 * four things pinned here are all about the button:
 *
 *   1. **SENDING IS A SEPARATE PERMISSION FROM BUILDING.** `campaigns_manage`
 *      writes the wording; `campaigns_send` reaches the guests. A venue can
 *      reasonably let somebody draft an offer that a manager presses send on,
 *      and the two are different keys because the damage is different.
 *   2. **A CAMPAIGN THAT CANNOT SEND WELL IS NOT SENT AT ALL.** No segment,
 *      no wording, no subject on an email, nobody reachable: each is refused
 *      with the reason, BEFORE the money goes.
 *   3. **THE AUDIENCE IS FROZEN WHEN SENDING STARTS, WITH A REASON PER
 *      PERSON.** Everybody in the segment gets a row — sendable or skipped —
 *      so months later the venue can answer "why didn't Dana get that".
 *   4. **IT CANNOT GO TWICE.** Pressing send on a campaign that has already
 *      started is refused, and so is editing its wording after the fact.
 *
 * The per-recipient consent checks themselves belong to the send path and are
 * pinned by `tests/integration/SmsTest.php` and `MailerTest.php`. What is
 * pinned HERE is that a campaign goes through them at all rather than round
 * them — tested by putting somebody in the audience whom the PLANNER thinks
 * is reachable and the SEND PATH must refuse.
 */

use App\Core\DB;
use App\Core\Settings;
use App\Controllers\CampaignController;
use App\Services\CampaignPlanner;
use App\Services\SmsPolicy;
use Tests\Assert;
use Tests\Harness;

Harness::suite('http/campaigns');

$baseUrl = getenv('CC_TEST_BASE_URL') ?: '';

if ($baseUrl === '') {
    Harness::skip('campaigns http suite', 'no server; run via tests/run.php');

    return;
}

if (!test_boot()) {
    Harness::skip('campaigns http suite', 'no database available');

    return;
}

require_once CC_ROOT . '/tests/TestBrowser.php';

// ------------------------------------------------------------------ fixtures

function ch_name(string $hint): string
{
    return 'CampHttp ' . $hint . ' ' . bin2hex(random_bytes(3));
}

/** @return array<string,mixed>|null */
function ch_row(string $name): ?array
{
    return DB::first('SELECT * FROM {campaigns} WHERE name = :n', ['n' => $name]);
}

/**
 * The flash messages on a followed response.
 *
 * A refusal redirects and the sentence lands on the destination, so the posts
 * whose WORDING is being tested are followed. The message always sits in a
 * `<p>` inside its alert — after the icon — so that is what is read, rather
 * than the alert element's whole inner HTML: matching to the closing `</div>`
 * breaks on the dismiss button and reports "no message" about a flash that
 * rendered perfectly well.
 */
function ch_flash(array $response): string
{
    preg_match_all('#<div class="alert alert-\w+">.*?<p>(.*?)</p>#s', (string) $response['body'], $found);

    $messages = array_map(
        static fn (string $html): string => trim(html_entity_decode(strip_tags($html))),
        $found[1] ?? []
    );

    return implode(' | ', $messages);
}

/**
 * A segment and a contact tag that belong to ONE test and nothing else.
 *
 * Isolated twice over. Other suites seed contacts and do not all clean up
 * after themselves, so a campaign test counting shared rows would pass or
 * fail depending on what ran before it. But the tag is per TEST, not per
 * suite, for a second reason found by running this file: the tests here
 * accumulate contacts as they go, and with one shared tag the "nobody is
 * reachable" campaign matched the reachable guests seeded two tests earlier
 * and duly sent to them. Every audience figure in the file was really
 * counting the whole file.
 *
 * @return array{source:string, segment:int}
 */
function ch_world(string $hint): array
{
    $source = 'camphttp-' . bin2hex(random_bytes(4));
    $rules  = ['match' => 'all', 'rules' => [['field' => 'source', 'op' => 'is', 'value' => $source]]];

    $segment = (int) DB::insert('segments', [
        'name'       => ch_name($hint . ' segment'),
        'slug'       => $source,
        'rules'      => json_encode($rules),
        'is_dynamic' => 1,
        'created_at' => now_utc(),
        'updated_at' => now_utc(),
    ]);

    return ['source' => $source, 'segment' => $segment];
}

/**
 * One guest of this test's own.
 *
 * @param array{source:string, segment:int} $world
 * @param array<string,mixed>               $overrides
 */
function ch_contact(array $world, string $first, array $overrides = []): int
{
    return (int) DB::insert('contacts', $overrides + [
        'first_name'      => $first,
        'last_name'       => 'CampHttp',
        'email'           => 'camphttp.' . strtolower($first) . '.' . bin2hex(random_bytes(3)) . '@example.test',
        'email_consent'   => 1,
        'source'          => $world['source'],
        'lifecycle_stage' => 'lead',
        'created_at'      => now_utc(),
        'updated_at'      => now_utc(),
    ]);
}

/** @return array<string,string> */
function ch_post(string $name, int $segmentId, array $overrides = []): array
{
    return $overrides + [
        'name'       => $name,
        'channel'    => 'email',
        'segment_id' => (string) $segmentId,
        'subject'    => 'Half price go-karts this Saturday',
        'body'       => 'Hi {{first_name}}, half price karts this Saturday at {{venue_name}}.',
    ];
}

function ch_clear(): void
{
    DB::query("DELETE FROM {messages} WHERE campaign_id IN (SELECT id FROM {campaigns} WHERE name LIKE 'CampHttp%')");
    DB::query("DELETE FROM {campaign_recipients} WHERE campaign_id IN (SELECT id FROM {campaigns} WHERE name LIKE 'CampHttp%')");
    DB::query("DELETE FROM {campaigns} WHERE name LIKE 'CampHttp%'");
    DB::query("DELETE FROM {segments} WHERE name LIKE 'CampHttp%'");
    DB::delete('suppressions', "address LIKE 'camphttp.%'", []);
    DB::query("DELETE FROM {contacts} WHERE source LIKE 'camphttp%'");
}

ch_clear();

/*
 * Sending arranged so a campaign really goes out, and PUT BACK afterwards.
 *
 * Settings are global and shared, and the suites run in one process against
 * one database: the first version of this file turned Safe Mode off and left
 * it off, which broke a quotes test two suites later that asserts an email
 * was redirected. A suite that changes a global has to change it back, and
 * the failure it causes lands somewhere else entirely.
 *
 * Email is priced at nothing here so that no test depends on a cost
 * threshold it did not set itself; the two that care set their own price.
 */
$settingsBefore = [];

foreach ([
    'mail.cost_per_email_cents' => 0,
    'mail.driver'               => 'log',
    'system.safe_mode'          => false,
    'system.sending_paused'     => false,
] as $key => $value) {
    $settingsBefore[$key] = Settings::get($key);
    Settings::set($key, $value);
}

/** Put every global back the way this suite found it. */
$restoreSettings = static function () use ($settingsBefore): void {
    foreach ($settingsBefore as $key => $value) {
        if ($value === null) {
            Settings::forget($key);
        } else {
            Settings::set($key, $value);
        }
    }
};

$owner = new TestBrowser($baseUrl, 'campaigns-owner');
$owner->login('owner@test.invalid', 'owner-test-passphrase-9');

// Front Desk holds no marketing permission at all in the seeds.
$desk = new TestBrowser($baseUrl, 'campaigns-desk');
$desk->login('desk@test.invalid', 'desk-test-passphrase-9');

$marketingPassword = 'campaigns-marketing-passphrase-9';
test_make_user('marketing', [
    'email'         => 'campaigns-marketing@example.test',
    'name'          => 'Campaigns Marketing',
    'password_hash' => App\Core\Auth::hash($marketingPassword),
]);

$marketing = new TestBrowser($baseUrl, 'campaigns-marketing');
$marketing->login('campaigns-marketing@example.test', $marketingPassword);

Harness::test('the fixtures signed in', function () use ($owner, $desk, $marketing): void {
    foreach (['owner' => $owner, 'desk' => $desk, 'marketing' => $marketing] as $who => $browser) {
        Assert::same(200, $browser->get('/account')['status'], $who . ' should be signed in');
    }
});

// --------------------------------------------------------------------- gates

Harness::test('a role with no view_marketing cannot see campaigns at all', function () use ($desk): void {
    Assert::same(403, $desk->get('/campaigns')['status']);
    Assert::same(403, $desk->get('/campaigns/new')['status']);
});

Harness::test('marketing can look and build', function () use ($marketing): void {
    // The positive control. Without it the refusals above prove nothing,
    // because a page broken for everybody would also report 403.
    Assert::same(200, $marketing->get('/campaigns')['status']);
    Assert::same(200, $marketing->get('/campaigns/new')['status']);
});

Harness::test(
    'BUILDING A CAMPAIGN IS NOT PERMISSION TO SEND IT',
    function () use ($baseUrl, $owner): void {
        /*
         * The split that matters most on this page, and no SEEDED role has
         * this shape — Marketing and Owner both hold `campaigns_manage` AND
         * `campaigns_send`, so without building a role here the two keys are
         * never told apart: every other test passes whichever one is removed.
         *
         * Roles are data in this application, so a venue can legitimately
         * make somebody who drafts the offer and a manager who presses send.
         */
        $roleId = (int) DB::insert('roles', [
            'slug'        => 'campaign-drafter-test',
            'name'        => 'Campaign drafter (test)',
            'description' => 'Writes campaigns, sends none.',
            'is_system'   => 0,
            'sort_order'  => 902,
            'created_at'  => now_utc(),
            'updated_at'  => now_utc(),
        ]);

        foreach (['view_marketing', 'campaigns_manage'] as $slug) {
            $permissionId = (int) DB::value('SELECT id FROM {permissions} WHERE slug = :s', ['s' => $slug]);

            Assert::greaterThan(0, $permissionId, 'precondition: ' . $slug . ' exists');

            DB::insert('role_permissions', [
                'role_id'       => $roleId,
                'permission_id' => $permissionId,
                'scope'         => 'all',
            ]);
        }

        test_make_user('campaign-drafter-test', [
            'email'         => 'campaign-drafter@example.test',
            'name'          => 'Campaign Drafter',
            'password_hash' => App\Core\Auth::hash('campaign-drafter-passphrase-9'),
        ]);

        $world = ch_world('drafter');
        ch_contact($world, 'Drafterguest');
        $name = ch_name('drafter');

        try {
            $drafter = new TestBrowser($baseUrl, 'campaigns-drafter');
            $drafter->login('campaign-drafter@example.test', 'campaign-drafter-passphrase-9');

            Assert::same(200, $drafter->get('/account')['status'], 'precondition: signed in');

            // They CAN write one.
            $drafter->postWithToken('/campaigns/new', '/campaigns', ch_post($name, $world['segment']));
            $campaign = ch_row($name);

            Assert::notNull($campaign, 'a drafter should be able to save a campaign');

            $id = (int) $campaign['id'];

            // And they can read it.
            Assert::same(200, $drafter->get('/campaigns/' . $id)['status']);

            // They cannot send it, test it, pause it or resume it.
            foreach (['send', 'test', 'pause', 'resume'] as $action) {
                $response = $drafter->postWithToken(
                    '/campaigns/' . $id,
                    '/campaigns/' . $id . '/' . $action,
                    ['confirm' => CampaignController::CONFIRM_WORD, 'to' => 'drafter@example.test']
                );

                Assert::same(403, $response['status'], $action . ' should need campaigns_send');
            }

            // And nothing went out.
            Assert::same(
                'draft',
                (string) DB::value('SELECT status FROM {campaigns} WHERE id = :id', ['id' => $id]),
                'a refused send must leave the campaign a draft'
            );
            Assert::same(
                0,
                (int) DB::value('SELECT COUNT(*) FROM {messages} WHERE campaign_id = :id', ['id' => $id]),
                'a refused send must send nothing'
            );
        } finally {
            DB::delete('users', 'email = :e', ['e' => 'campaign-drafter@example.test']);
            DB::delete('role_permissions', 'role_id = :r', ['r' => $roleId]);
            DB::delete('roles', 'id = :r', ['r' => $roleId]);
        }
    }
);

// ------------------------------------------------------------- what refuses

Harness::test('a campaign with no name is refused', function () use ($owner): void {
    $world = ch_world('noname');

    $response = $owner->postWithToken('/campaigns/new', '/campaigns', ch_post('', $world['segment']), true);

    Assert::contains('name', strtolower(ch_flash($response)), 'should say the name is missing');
});

Harness::test(
    'A CAMPAIGN WITH NOBODY REACHABLE IS NOT SENT',
    function () use ($owner): void {
        // The whole segment has refused marketing. The planner reports a
        // reach of zero and the send is refused — rather than "succeeding"
        // by sending nothing, which would record a completed campaign that
        // never happened.
        $world = ch_world('unreachable');
        ch_contact($world, 'Nope', ['email_consent' => 0]);
        ch_contact($world, 'Alsonope', ['email_consent' => 0]);

        $name = ch_name('unreachable');
        $owner->postWithToken('/campaigns/new', '/campaigns', ch_post($name, $world['segment']));

        $campaign = ch_row($name);
        Assert::notNull($campaign);

        $id = (int) $campaign['id'];

        $response = $owner->postWithToken(
            '/campaigns/' . $id,
            '/campaigns/' . $id . '/send',
            ['confirm' => CampaignController::CONFIRM_WORD],
            true
        );

        Assert::same(
            'draft',
            (string) DB::value('SELECT status FROM {campaigns} WHERE id = :id', ['id' => $id]),
            'a campaign with no reachable audience must stay a draft'
        );

        $why = strtolower(ch_flash($response));

        Assert::true(
            str_contains($why, 'nobody') || str_contains($why, 'no one') || str_contains($why, 'reach'),
            'the refusal should say there is nobody to send to; got: ' . $why
        );
    }
);

Harness::test(
    'an email campaign with no subject is refused at the send, not at the save',
    function () use ($owner): void {
        // A draft is allowed to be incomplete — that is what a draft is. The
        // check belongs at the send, where the consequence is.
        $world = ch_world('nosubject');
        ch_contact($world, 'Subjectless');

        $name = ch_name('nosubject');
        $owner->postWithToken('/campaigns/new', '/campaigns', ch_post($name, $world['segment'], ['subject' => '']));

        $campaign = ch_row($name);
        Assert::notNull($campaign, 'an incomplete draft should still save');

        $id = (int) $campaign['id'];

        // The page names it as a blocker before anybody presses anything.
        $page = $owner->get('/campaigns/' . $id);
        Assert::same(200, $page['status']);
        Assert::contains('subject', strtolower($page['body']), 'the page should name the missing subject');

        $owner->postWithToken(
            '/campaigns/' . $id,
            '/campaigns/' . $id . '/send',
            ['confirm' => CampaignController::CONFIRM_WORD]
        );

        Assert::same(
            'draft',
            (string) DB::value('SELECT status FROM {campaigns} WHERE id = :id', ['id' => $id]),
            'a subject-less email campaign must not send'
        );
    }
);

Harness::test(
    'A BIG SPEND NEEDS THE WORD TYPED, AND THE WRONG WORD IS NOT IT',
    function () use ($owner): void {
        $world = ch_world('confirm');

        for ($i = 0; $i < 3; $i++) {
            ch_contact($world, 'Costly' . $i);
        }

        // Priced so three recipients clear the threshold. Absurd as a real
        // price, which is the point: the gate exists for a forty-thousand
        // recipient blast and the arithmetic is what is being tested.
        $perEmail = (int) ceil((CampaignPlanner::CONFIRM_ABOVE_CENTS + 100) / 3);
        Settings::set('mail.cost_per_email_cents', $perEmail);

        try {
            $name = ch_name('confirm');
            $owner->postWithToken('/campaigns/new', '/campaigns', ch_post($name, $world['segment']));

            $campaign = ch_row($name);
            Assert::notNull($campaign);

            $id = (int) $campaign['id'];

            // Nothing typed.
            $response = $owner->postWithToken(
                '/campaigns/' . $id,
                '/campaigns/' . $id . '/send',
                [],
                true
            );

            Assert::same(
                'draft',
                (string) DB::value('SELECT status FROM {campaigns} WHERE id = :id', ['id' => $id]),
                'an unconfirmed expensive send must not start'
            );
            Assert::contains(
                CampaignController::CONFIRM_WORD,
                ch_flash($response),
                'the refusal should say which word to type'
            );

            // Something typed, but not the word. A yes/no checkbox is easy to
            // click through without reading; a word has to be copied.
            $owner->postWithToken(
                '/campaigns/' . $id,
                '/campaigns/' . $id . '/send',
                ['confirm' => 'yes']
            );

            Assert::same(
                'draft',
                (string) DB::value('SELECT status FROM {campaigns} WHERE id = :id', ['id' => $id]),
                'the wrong word must not send either'
            );

            // The word itself.
            $owner->postWithToken(
                '/campaigns/' . $id,
                '/campaigns/' . $id . '/send',
                ['confirm' => CampaignController::CONFIRM_WORD]
            );

            Assert::notSame(
                'draft',
                (string) DB::value('SELECT status FROM {campaigns} WHERE id = :id', ['id' => $id]),
                'the right word must send'
            );
        } finally {
            Settings::set('mail.cost_per_email_cents', 0);
        }
    }
);

// ------------------------------------------------------- the send itself

Harness::test(
    'THE AUDIENCE IS FROZEN WITH A REASON FOR EVERY PERSON NOT SENT TO',
    function () use ($owner): void {
        /*
         * The question a venue asks months later is "why didn't Dana get
         * that", and the only way to answer it is to have written the reason
         * down at the time. A skipped recipient is RECORDED, never dropped:
         * a campaign that reached 2 of 6 with no explanation looks like a bug
         * in the sending.
         *
         * The suppressed guest is the important one. The planner's pre-flight
         * does NOT read the suppression list, so if that person comes back
         * `sent` it proves the send path's own checks were bypassed — which
         * is the one way this feature could quietly contact somebody who
         * asked it not to.
         */
        $world = ch_world('frozen');

        $expected = [
            'Reachone'   => 'sent',
            'Reachtwo'   => 'sent',
            'Noconsent'  => 'no_consent',
            'Noaddress'  => 'no_email',
            'Refused'    => 'do_not_contact',
            'Unsubbed'   => 'suppressed',
        ];

        $ids = [
            'Reachone'  => ch_contact($world, 'Reachone'),
            'Reachtwo'  => ch_contact($world, 'Reachtwo'),
            'Noconsent' => ch_contact($world, 'Noconsent', ['email_consent' => 0]),
            'Noaddress' => ch_contact($world, 'Noaddress', ['email' => null]),
            'Refused'   => ch_contact($world, 'Refused', ['do_not_contact' => 1]),
            'Unsubbed'  => ch_contact($world, 'Unsubbed'),
        ];

        DB::insert('suppressions', [
            'channel'    => 'email',
            'address'    => (string) DB::value('SELECT email FROM {contacts} WHERE id = :id', ['id' => $ids['Unsubbed']]),
            'reason'     => 'unsubscribe',
            'source'     => 'campaigns http suite',
            'created_at' => now_utc(),
        ]);

        $name = ch_name('frozen');
        $owner->postWithToken('/campaigns/new', '/campaigns', ch_post($name, $world['segment']));

        $campaign = ch_row($name);
        Assert::notNull($campaign);

        $id = (int) $campaign['id'];

        $owner->postWithToken(
            '/campaigns/' . $id,
            '/campaigns/' . $id . '/send',
            ['confirm' => CampaignController::CONFIRM_WORD]
        );

        Assert::same(
            'sending',
            (string) DB::value('SELECT status FROM {campaigns} WHERE id = :id', ['id' => $id]),
            'the send should have started'
        );

        // Everybody in the segment has a row the moment sending starts —
        // including the ones who will not be sent to.
        Assert::same(
            count($expected),
            (int) DB::value('SELECT COUNT(*) FROM {campaign_recipients} WHERE campaign_id = :id', ['id' => $id]),
            'every segment member should be recorded, reachable or not'
        );

        // Walk it to the end the way cron does.
        $ticks = 0;

        while ($ticks < 10 && CampaignController::sendChunk($id) > 0) {
            $ticks++;
        }

        $outcomes = [];

        foreach (DB::all(
            'SELECT c.first_name, r.status, r.skip_reason
               FROM {campaign_recipients} r JOIN {contacts} c ON c.id = r.contact_id
              WHERE r.campaign_id = :id',
            ['id' => $id]
        ) as $row) {
            $outcomes[(string) $row['first_name']] = $row['skip_reason'] !== null
                ? (string) $row['skip_reason']
                : (string) $row['status'];
        }

        foreach ($expected as $who => $outcome) {
            Assert::same($outcome, $outcomes[$who] ?? '(missing)', $who . ' should be ' . $outcome);
        }

        // Only the two reachable guests were actually messaged.
        Assert::same(
            2,
            (int) DB::value('SELECT COUNT(*) FROM {messages} WHERE campaign_id = :id', ['id' => $id]),
            'exactly the reachable guests should have a message'
        );

        $final = DB::first('SELECT * FROM {campaigns} WHERE id = :id', ['id' => $id]);

        Assert::same('sent', (string) $final['status'], 'it should finish once nothing is pending');
        Assert::same(2, (int) $final['sent_count'], 'the sent count should be the real one');
        Assert::notNull($final['completed_at'], 'a finished campaign should be stamped');
    }
);

Harness::test(
    'a campaign cannot be sent twice, nor edited once it has gone',
    function () use ($owner): void {
        $world = ch_world('once');
        ch_contact($world, 'Onceonly');

        $name = ch_name('once');
        $owner->postWithToken('/campaigns/new', '/campaigns', ch_post($name, $world['segment']));

        $campaign = ch_row($name);
        Assert::notNull($campaign);

        $id = (int) $campaign['id'];

        $owner->postWithToken(
            '/campaigns/' . $id,
            '/campaigns/' . $id . '/send',
            ['confirm' => CampaignController::CONFIRM_WORD]
        );

        while (CampaignController::sendChunk($id) > 0) {
            // Drain it.
        }

        $sentFirst = (int) DB::value('SELECT COUNT(*) FROM {messages} WHERE campaign_id = :id', ['id' => $id]);
        $finished  = DB::first('SELECT status, started_at, completed_at FROM {campaigns} WHERE id = :id', ['id' => $id]);

        Assert::same(1, $sentFirst, 'precondition: it sent once');
        Assert::same('sent', (string) $finished['status'], 'precondition: it finished');

        // Press send again.
        $owner->postWithToken(
            '/campaigns/' . $id,
            '/campaigns/' . $id . '/send',
            ['confirm' => CampaignController::CONFIRM_WORD]
        );

        Assert::same(
            $sentFirst,
            (int) DB::value('SELECT COUNT(*) FROM {messages} WHERE campaign_id = :id', ['id' => $id]),
            'a second send must not send anything again'
        );

        /*
         * And the campaign must still read as FINISHED.
         *
         * The unique key on (campaign, contact) means a second send inserts
         * no new recipients and so messages nobody a second time — which is
         * why the message count alone cannot see this defect. MEASURED by
         * mutation: with the already-sent check removed, every assertion
         * above still passed while the campaign's status was quietly put
         * back to `sending` and its timestamps overwritten, leaving a
         * finished campaign that reads as permanently in flight and a
         * cron job that picks it up every minute for ever.
         */
        $after = DB::first('SELECT status, started_at, completed_at FROM {campaigns} WHERE id = :id', ['id' => $id]);

        Assert::same('sent', (string) $after['status'], 'a second send must not reopen a finished campaign');
        Assert::same(
            (string) $finished['started_at'],
            (string) $after['started_at'],
            'a second send must not overwrite when it really started'
        );
        Assert::same(
            (string) $finished['completed_at'],
            (string) $after['completed_at'],
            'a second send must not unset when it finished'
        );

        // And the wording is now a matter of record.
        $edit = $owner->get('/campaigns/' . $id . '/edit');

        Assert::true(
            $edit['status'] >= 300 && $edit['status'] < 400,
            'editing a sent campaign should be bounced; got ' . $edit['status']
        );

        $before = (string) DB::value('SELECT body FROM {campaigns} WHERE id = :id', ['id' => $id]);

        $owner->postWithToken(
            '/campaigns/' . $id,
            '/campaigns/' . $id,
            ch_post($name, $world['segment'], ['body' => 'Rewritten after the fact.'])
        );

        Assert::same(
            $before,
            (string) DB::value('SELECT body FROM {campaigns} WHERE id = :id', ['id' => $id]),
            'the wording of a sent campaign must not change'
        );
    }
);

Harness::test(
    'a seed test sends to one address and is not counted as the campaign',
    function () use ($owner): void {
        $world = ch_world('seedtest');
        ch_contact($world, 'Seedguest');

        $name = ch_name('seedtest');
        $owner->postWithToken('/campaigns/new', '/campaigns', ch_post($name, $world['segment']));

        $campaign = ch_row($name);
        Assert::notNull($campaign);

        $id = (int) $campaign['id'];

        $owner->postWithToken(
            '/campaigns/' . $id,
            '/campaigns/' . $id . '/test',
            ['to' => 'camphttp.seed@example.test']
        );

        // A test must not touch the campaign's own figures, or the report of
        // what the venue sent includes messages it only sent to itself.
        Assert::same(
            0,
            (int) DB::value('SELECT COUNT(*) FROM {messages} WHERE campaign_id = :id', ['id' => $id]),
            'a test must not be recorded against the campaign'
        );
        Assert::same(
            'draft',
            (string) DB::value('SELECT status FROM {campaigns} WHERE id = :id', ['id' => $id]),
            'a test must not start the campaign'
        );
        Assert::same(
            0,
            (int) DB::value('SELECT COUNT(*) FROM {campaign_recipients} WHERE campaign_id = :id', ['id' => $id]),
            'a test must not freeze the audience'
        );
    }
);

Harness::test(
    'PAUSING STOPS THE SENDING, AND RESUMING PICKS UP WHERE IT STOPPED',
    function () use ($owner): void {
        // The button that exists for the moment somebody notices a typo in a
        // live send. It has to stop the REST of it without re-sending the
        // part that already went.
        $world = ch_world('pause');

        for ($i = 0; $i < 4; $i++) {
            ch_contact($world, 'Pauseguest' . $i);
        }

        $name = ch_name('pause');
        $owner->postWithToken('/campaigns/new', '/campaigns', ch_post($name, $world['segment']));

        $campaign = ch_row($name);
        Assert::notNull($campaign);

        $id = (int) $campaign['id'];

        $owner->postWithToken(
            '/campaigns/' . $id,
            '/campaigns/' . $id . '/send',
            ['confirm' => CampaignController::CONFIRM_WORD]
        );

        // One message goes out, then somebody pauses it.
        CampaignController::sendChunk($id, 1);

        $afterOne = (int) DB::value('SELECT COUNT(*) FROM {messages} WHERE campaign_id = :id', ['id' => $id]);

        Assert::same(1, $afterOne, 'precondition: one message went');

        $owner->postWithToken('/campaigns/' . $id, '/campaigns/' . $id . '/pause', []);

        Assert::same(
            'paused',
            (string) DB::value('SELECT status FROM {campaigns} WHERE id = :id', ['id' => $id])
        );

        // Cron must not carry on with a paused campaign.
        Assert::same(0, CampaignController::sendChunk($id), 'a paused campaign sends nothing');
        Assert::same(
            $afterOne,
            (int) DB::value('SELECT COUNT(*) FROM {messages} WHERE campaign_id = :id', ['id' => $id]),
            'nothing more should have gone out while paused'
        );

        // Resumed, it finishes the rest — and does not re-send the first.
        $owner->postWithToken('/campaigns/' . $id, '/campaigns/' . $id . '/resume', []);

        Assert::same(
            'sending',
            (string) DB::value('SELECT status FROM {campaigns} WHERE id = :id', ['id' => $id])
        );

        while (CampaignController::sendChunk($id) > 0) {
            // Drain it.
        }

        Assert::same(
            4,
            (int) DB::value('SELECT COUNT(*) FROM {messages} WHERE campaign_id = :id', ['id' => $id]),
            'each guest should have exactly one message after a pause and resume'
        );
        Assert::same(
            'sent',
            (string) DB::value('SELECT status FROM {campaigns} WHERE id = :id', ['id' => $id])
        );
    }
);

Harness::test(
    'only a campaign that is really sending can be paused',
    function () use ($owner): void {
        /*
         * Pausing is about stopping a send in flight. A draft has not
         * started and a finished campaign cannot be stopped, so neither is
         * pausable — and the guard matters because the status is what the
         * list and the badge READ: flipping a finished campaign to `paused`
         * leaves a campaign that went out last week showing on the page as
         * still going. MEASURED by mutation; nothing else here noticed.
         */
        $world = ch_world('pausestate');
        ch_contact($world, 'Pausestateguest');

        $name = ch_name('pausestate');
        $owner->postWithToken('/campaigns/new', '/campaigns', ch_post($name, $world['segment']));

        $campaign = ch_row($name);
        Assert::notNull($campaign);

        $id = (int) $campaign['id'];

        // A draft cannot be paused.
        $response = $owner->postWithToken('/campaigns/' . $id, '/campaigns/' . $id . '/pause', [], true);

        Assert::same(
            'draft',
            (string) DB::value('SELECT status FROM {campaigns} WHERE id = :id', ['id' => $id]),
            'a draft must not become paused'
        );
        Assert::contains('not sending', strtolower(ch_flash($response)), 'and it should say why');

        // Nor can a finished one.
        $owner->postWithToken(
            '/campaigns/' . $id,
            '/campaigns/' . $id . '/send',
            ['confirm' => CampaignController::CONFIRM_WORD]
        );

        while (CampaignController::sendChunk($id) > 0) {
            // Drain it.
        }

        Assert::same(
            'sent',
            (string) DB::value('SELECT status FROM {campaigns} WHERE id = :id', ['id' => $id]),
            'precondition: it finished'
        );

        $owner->postWithToken('/campaigns/' . $id, '/campaigns/' . $id . '/pause', []);

        Assert::same(
            'sent',
            (string) DB::value('SELECT status FROM {campaigns} WHERE id = :id', ['id' => $id]),
            'a finished campaign must not read as paused'
        );

        // And resume refuses anything that is not paused, for the same reason.
        $owner->postWithToken('/campaigns/' . $id, '/campaigns/' . $id . '/resume', []);

        Assert::same(
            'sent',
            (string) DB::value('SELECT status FROM {campaigns} WHERE id = :id', ['id' => $id]),
            'a finished campaign must not be put back into sending'
        );
    }
);

Harness::test(
    'the list shows what it really cost, not what it was estimated to cost',
    function () use ($owner): void {
        // A campaign that skipped half its audience did not cost what it was
        // estimated to, and the figure a venue reconciles against an invoice
        // has to be the real one. Priced at a quarter of a cent, which is
        // also the case that would be lost by rounding each message up.
        Settings::set('mail.cost_per_email_cents', 0.25);

        try {
            $world = ch_world('cost');

            // Four in the segment, two of whom can be reached.
            ch_contact($world, 'Costone');
            ch_contact($world, 'Costtwo');
            ch_contact($world, 'Costthree', ['email_consent' => 0]);
            ch_contact($world, 'Costfour', ['email' => null]);

            $name = ch_name('cost');
            $owner->postWithToken('/campaigns/new', '/campaigns', ch_post($name, $world['segment']));

            $campaign = ch_row($name);
            Assert::notNull($campaign);

            $id = (int) $campaign['id'];

            $owner->postWithToken(
                '/campaigns/' . $id,
                '/campaigns/' . $id . '/send',
                ['confirm' => CampaignController::CONFIRM_WORD]
            );

            while (CampaignController::sendChunk($id) > 0) {
                // Drain it.
            }

            $row = DB::first('SELECT * FROM {campaigns} WHERE id = :id', ['id' => $id]);

            // Estimated for 2 reachable at a quarter cent = half a cent,
            // which rounds to 1 whole cent on the campaign's integer column.
            Assert::same(2, (int) $row['sent_count']);

            // The point: the cost came from the MESSAGES, so it exists at all.
            // Recorded only on the usage ledger it read $0.00 for every email
            // campaign however the venue had priced email, which reads either
            // as broken sending or as free postage.
            $onMessages = (float) DB::value(
                'SELECT COALESCE(SUM(cost_cents), 0) FROM {messages} WHERE campaign_id = :id',
                ['id' => $id]
            );

            Assert::same(0.5, round($onMessages, 4), 'two emails at a quarter cent each');

            Assert::greaterThan(
                0,
                (int) $row['actual_cost_cents'],
                'an email campaign on a priced provider must not report costing nothing'
            );
        } finally {
            Settings::set('mail.cost_per_email_cents', 0);
        }
    }
);

Harness::test('a text campaign reports what the wording costs', function () use ($owner): void {
    // One emoji re-encodes the whole message from 160 characters to 70, and
    // the page has to say so BEFORE the send rather than after the invoice.
    $world = ch_world('sms');
    ch_contact($world, 'Smsguest', ['phone' => '8455551234', 'sms_consent' => 1]);

    $name = ch_name('sms');
    $owner->postWithToken('/campaigns/new', '/campaigns', ch_post($name, $world['segment'], [
        'channel' => 'sms',
        'subject' => '',
        'body'    => 'Rain today so it' . "\u{2019}" . 's half price bowling until close. Reply STOP to opt out.',
    ]));

    $campaign = ch_row($name);
    Assert::notNull($campaign);

    $page = $owner->get('/campaigns/' . (int) $campaign['id']);

    Assert::same(200, $page['status']);
    Assert::contains('Unicode', $page['body'], 'the page should warn that the wording costs more');

    /*
     * And it must say how many texts each recipient costs.
     *
     * 73 characters, but one curly apostrophe forces UCS-2, which cuts a
     * text from 160 characters to 70 — so this is TWO texts a head, not one,
     * and on a Text Club blast that is the whole difference. The warning
     * alone does not pin it: MEASURED by mutation, costing every message as
     * a single segment left the Unicode warning rendering perfectly while
     * the bill doubled, because the warning comes from the encoding and the
     * price comes from the count.
     */
    Assert::same(
        2,
        SmsPolicy::segments((string) $campaign['body']),
        'precondition: this wording really is two texts'
    );
    Assert::matches(
        '/2\s*texts\s*each/i',
        preg_replace('/\s+/', ' ', $page['body']) ?? '',
        'the page should say it costs two texts a head'
    );
});

ch_clear();
$restoreSettings();
