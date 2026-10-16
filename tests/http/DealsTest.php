<?php

declare(strict_types=1);

/**
 * Sales pipelines and deals, end to end over HTTP.
 *
 * Written against the browser rather than the controller because every rule
 * worth testing here is a rule about a REQUEST: who may see which deals, what
 * a POST writes, and what it refuses. A unit test of DealController would pass
 * with the route unguarded.
 *
 * Each test asserts on an outcome — a row in the database, a redirect, a
 * sentence on the page — never on the absence of an error. Every one of them
 * was broken on purpose and confirmed to fail before being kept; the comment
 * on each says what was broken.
 */

use App\Core\Auth;
use App\Core\DB;
use Tests\Assert;
use Tests\Harness;

Harness::suite('http/deals');

$baseUrl = getenv('CC_TEST_BASE_URL') ?: '';

if ($baseUrl === '') {
    Harness::skip('deals suite', 'no server; run via tests/run.php');

    return;
}

if (!test_boot()) {
    Harness::skip('deals suite', 'no database available');

    return;
}

require_once CC_ROOT . '/tests/TestBrowser.php';

// ---------------------------------------------------------------- fixtures

/** A stage id, by pipeline slug and stage name. */
function deals_stage(string $pipelineSlug, string $stageName): int
{
    $id = (int) DB::value(
        'SELECT s.id
           FROM {pipeline_stages} s
           JOIN {pipelines} p ON p.id = s.pipeline_id
          WHERE p.slug = :slug AND s.name = :name',
        ['slug' => $pipelineSlug, 'name' => $stageName]
    );

    Assert::greaterThan(0, $id, 'the seeded stage "' . $stageName . '" should exist in ' . $pipelineSlug);

    return $id;
}

function deals_pipeline(string $slug): int
{
    $id = (int) DB::value('SELECT id FROM {pipelines} WHERE slug = :slug', ['slug' => $slug]);

    Assert::greaterThan(0, $id, 'the seeded pipeline "' . $slug . '" should exist');

    return $id;
}

function deals_user_id(string $email): int
{
    return (int) DB::value(
        'SELECT id FROM {users} WHERE email = :e AND deleted_at IS NULL',
        ['e' => $email]
    );
}

/** A title no other test or seed will collide with. */
function deals_title(string $what): string
{
    static $counter = 0;
    $counter++;

    return $what . ' ' . $counter . '-' . bin2hex(random_bytes(3));
}

/**
 * Post the new-deal form and hand back the response plus the new id.
 *
 * @param array<string,string> $fields
 * @return array{status:int,location:string,body:string,id:int}
 */
function deals_create(TestBrowser $browser, array $fields): array
{
    $response = $browser->postWithToken('/deals/new', '/deals', $fields);

    $id = preg_match('#/deals/(\d+)$#', $response['location'], $match) === 1 ? (int) $match[1] : 0;

    return [
        'status'   => $response['status'],
        'location' => $response['location'],
        'body'     => $response['body'],
        'id'       => $id,
    ];
}

/** @return array<string,mixed>|null */
function deals_row(int $id): ?array
{
    return DB::first('SELECT * FROM {deals} WHERE id = :id', ['id' => $id]);
}

function deals_history_count(int $dealId): int
{
    return (int) DB::value(
        'SELECT COUNT(*) FROM {deal_stage_history} WHERE deal_id = :id',
        ['id' => $dealId]
    );
}

/** @return array<string,mixed>|null */
function deals_latest_history(int $dealId): ?array
{
    return DB::first(
        'SELECT * FROM {deal_stage_history} WHERE deal_id = :id ORDER BY id DESC LIMIT 1',
        ['id' => $dealId]
    );
}

function deals_audit_count(string $action, int $entityId): int
{
    return (int) DB::value(
        "SELECT COUNT(*) FROM {audit_log}
          WHERE action = :action AND entity_type = 'deals' AND entity_id = :id",
        ['action' => $action, 'id' => $entityId]
    );
}

$birthday      = deals_pipeline('birthday');
$groupPipeline = deals_pipeline('group');
$newInquiry    = deals_stage('birthday', 'New Inquiry');
$quoteSent     = deals_stage('birthday', 'Quote Sent');
$confirmed     = deals_stage('birthday', 'Confirmed');
$groupInquiry  = deals_stage('group', 'New Inquiry');

// A browser per actor: each presents its own client address, so the per-IP
// rate limiter never sees one suite as one hammering client.
$owner = new TestBrowser($baseUrl, 'deals-owner');
$gm    = new TestBrowser($baseUrl, 'deals-gm');
$desk  = new TestBrowser($baseUrl, 'deals-desk');
$host  = new TestBrowser($baseUrl, 'deals-host');
$agent = new TestBrowser($baseUrl, 'deals-agent');

$owner->login('owner@test.invalid', 'owner-test-passphrase-9');
$gm->login('gm@test.invalid', 'gm-test-passphrase-9');
$desk->login('desk@test.invalid', 'desk-test-passphrase-9');

// Party Host holds view_deals and deals_manage at 'own' scope and no
// deals_reassign — the profile the scoping tests need.
$hostEmail = 'deals-host-' . bin2hex(random_bytes(4)) . '@example.test';
$hostId    = test_make_user('party_host', [
    'email'         => $hostEmail,
    'name'          => 'Party Host Pat',
    'password_hash' => Auth::hash('host-test-passphrase-9'),
]);

// The API role is the one seeded profile holding deals_manage at 'all' WITHOUT
// deals_reassign, which is the only way to reach the reassign guard: an
// own-scoped role cannot fetch somebody else's deal to try.
$agentEmail = 'deals-agent-' . bin2hex(random_bytes(4)) . '@example.test';
test_make_user('api', [
    'email'         => $agentEmail,
    'name'          => 'Integration Agent',
    'password_hash' => Auth::hash('agent-test-passphrase-9'),
]);

$host->login($hostEmail, 'host-test-passphrase-9');
$agent->login($agentEmail, 'agent-test-passphrase-9');

$ownerId = deals_user_id('owner@test.invalid');
$gmId    = deals_user_id('gm@test.invalid');

Harness::test('the fixtures signed in', function () use ($owner, $gm, $host, $agent): void {
    // The precondition for everything below. Without it a permission test
    // could pass because the actor was never logged in at all.
    foreach (['owner' => $owner, 'gm' => $gm, 'host' => $host, 'agent' => $agent] as $who => $browser) {
        Assert::same(200, $browser->get('/account')['status'], $who . ' should be signed in');
    }
});

// ------------------------------------------------------------- the board

Harness::test('the board renders its stages as columns', function () use ($owner, $birthday): void {
    // Broken to check: stagesFor() returning [] — the stage names vanish and
    // this fails on the first assertion.
    $response = $owner->get('/deals?pipeline=' . $birthday);

    Assert::same(200, $response['status']);
    Assert::contains('New Inquiry', $response['body'], 'the seeded stages are the columns');
    Assert::contains('Quote Sent', $response['body']);
    Assert::contains('Deposit Paid', $response['body']);
    Assert::contains('Deals in view', $response['body'], 'and the totals row');
});

Harness::test('a deal appears on the board with its value, and in the table too', function () use (
    $owner,
    $birthday,
    $newInquiry
): void {
    // Broken to check: dropping the money column from the board card — the
    // $62.50 assertion fails; and rendering the table from a second query
    // that ignored the pipeline filter, which the shared-filter assertion
    // below catches.
    $title = deals_title('Board visibility');

    $created = deals_create($owner, [
        'title'       => $title,
        'pipeline_id' => (string) $birthday,
        'stage_id'    => (string) $newInquiry,
        'value_dollars' => '62.50',
    ]);

    Assert::greaterThan(0, $created['id'], 'creating a deal should redirect to it');

    $board = $owner->get('/deals?pipeline=' . $birthday);
    Assert::contains($title, $board['body'], 'the new deal should be on the board');
    Assert::contains('$62.50', $board['body'], 'with its value');

    $table = $owner->get('/deals/table?pipeline=' . $birthday);
    Assert::same(200, $table['status']);
    Assert::contains($title, $table['body'], 'and in the table view of the same pipeline');
    Assert::contains('Expected close', $table['body'], 'which is a real table, not the board again');
});

// ------------------------------------------------------------- creating

Harness::test('creating a deal stores exact integer cents', function () use ($owner, $birthday, $newInquiry): void {
    // THE money test, and the value is chosen rather than typed: 540.30 is one
    // of the amounts where (int) ($dollars * 100) — the obvious way to write
    // this — truncates to 54029, because 540.30 has no exact binary
    // representation. MEASURED on this PHP: 549.95 does NOT truncate, so a
    // test using it passes with the float version in place and proves nothing.
    // 2,737 amounts between $300 and $1000 behave like 540.30.
    //
    // Broken to check: parseMoney() computing (int) (((float) $clean) * 100) —
    // this test then reports 54029 and fails. A cent per deal is a
    // reconciliation problem against the POS, not a rounding curiosity.
    $title = deals_title('Exact cents');

    $created = deals_create($owner, [
        'title'         => $title,
        'pipeline_id'   => (string) $birthday,
        'stage_id'      => (string) $newInquiry,
        'value_dollars' => '540.30',
    ]);

    $deal = deals_row($created['id']);

    Assert::notNull($deal);
    Assert::same(54030, (int) $deal['value_cents'], '540.30 is 54030 cents, never 54029');
    Assert::same($title, (string) $deal['title']);
    Assert::same($newInquiry, (int) $deal['stage_id']);
    Assert::same($birthday, (int) $deal['pipeline_id']);

    // A thousands separator and a dollar sign are what a person pastes in from
    // a quote, and both have to survive. Broken to check: dropping the strip in
    // parseMoney() — "$1,250.75" is refused as not-money and the id is 0.
    $pasted = deals_create($owner, [
        'title'         => deals_title('Pasted money'),
        'pipeline_id'   => (string) $birthday,
        'stage_id'      => (string) $newInquiry,
        'value_dollars' => '$1,250.75',
    ]);

    Assert::greaterThan(0, $pasted['id'], 'a pasted figure should be accepted');
    Assert::same(125075, (int) deals_row($pasted['id'])['value_cents']);
});

Harness::test('a new deal starts its stage clock and inherits the stage deadline', function () use (
    $owner,
    $birthday,
    $newInquiry
): void {
    // Broken to check: not merging stageColumns() into the insert — sla_due_at
    // comes back null and the deadline assertion fails. New Inquiry carries a
    // 2-hour response deadline in the seeds.
    $created = deals_create($owner, [
        'title'         => deals_title('Clock start'),
        'pipeline_id'   => (string) $birthday,
        'stage_id'      => (string) $newInquiry,
        'value_dollars' => '100',
    ]);

    $deal = deals_row($created['id']);

    Assert::notNull($deal);
    Assert::same('open', (string) $deal['status']);
    Assert::notEmpty($deal['stage_entered_at'], 'the stage clock has to start somewhere');
    Assert::notEmpty($deal['sla_due_at'], 'New Inquiry has a 2 hour deadline in the seeds');

    $gap = strtotime((string) $deal['sla_due_at'] . ' UTC') - strtotime((string) $deal['stage_entered_at'] . ' UTC');

    Assert::same(7200, $gap, 'two hours after entering the stage');
});

Harness::test('creating a deal records the opening stage-history row', function () use (
    $owner,
    $ownerId,
    $birthday,
    $newInquiry
): void {
    // Broken to check: removing the history insert from store() — the count
    // drops to 0. Without this row the deal's time in its FIRST stage can
    // never be measured, which is half of what the velocity report needs.
    $created = deals_create($owner, [
        'title'         => deals_title('Opening history'),
        'pipeline_id'   => (string) $birthday,
        'stage_id'      => (string) $newInquiry,
        'value_dollars' => '0',
    ]);

    Assert::same(1, deals_history_count($created['id']));

    $entry = deals_latest_history($created['id']);

    Assert::notNull($entry);
    Assert::null($entry['from_stage_id'], 'a deal comes from no stage');
    Assert::same($newInquiry, (int) $entry['to_stage_id']);
    Assert::same($ownerId, (int) $entry['user_id'], 'and it records who created it');
});

Harness::test('creating a deal writes an audit row naming the deal', function () use (
    $owner,
    $birthday,
    $newInquiry
): void {
    // Broken to check: removing Audit::record() from store() — the count is 0.
    $title = deals_title('Audited creation');

    $created = deals_create($owner, [
        'title'         => $title,
        'pipeline_id'   => (string) $birthday,
        'stage_id'      => (string) $newInquiry,
        'value_dollars' => '25',
    ]);

    Assert::same(1, deals_audit_count('deal.created', $created['id']));

    $changes = (string) DB::value(
        "SELECT changes FROM {audit_log}
          WHERE action = 'deal.created' AND entity_type = 'deals' AND entity_id = :id",
        ['id' => $created['id']]
    );

    Assert::contains($title, $changes, 'an audit row that does not say what was created is not evidence');
});

// ------------------------------------------------------------ refusals

Harness::test('a deal with no name is refused and nothing is written', function () use (
    $owner,
    $birthday,
    $newInquiry
): void {
    // Broken to check: dropping |required from the title rule — a nameless
    // deal is created and the count assertion fails.
    $before = (int) DB::value('SELECT COUNT(*) FROM {deals}');

    $response = $owner->postWithToken('/deals/new', '/deals', [
        'title'         => '',
        'pipeline_id'   => (string) $birthday,
        'stage_id'      => (string) $newInquiry,
        'value_dollars' => '100',
    ], true);

    Assert::same($before, (int) DB::value('SELECT COUNT(*) FROM {deals}'), 'no row may be written');
    Assert::contains('Deal name is required', $response['body'], 'and the form says why');
});

Harness::test('a stage belonging to another pipeline is refused', function () use (
    $owner,
    $birthday,
    $groupInquiry
): void {
    // Broken to check: removing the pipeline/stage agreement check in
    // resolveWrite() — the deal is written with a stage from the Groups
    // pipeline, so it appears on NEITHER board, and this fails.
    $before = (int) DB::value('SELECT COUNT(*) FROM {deals}');

    $response = $owner->postWithToken('/deals/new', '/deals', [
        'title'         => deals_title('Mismatched stage'),
        'pipeline_id'   => (string) $birthday,
        'stage_id'      => (string) $groupInquiry,
        'value_dollars' => '100',
    ], true);

    Assert::same($before, (int) DB::value('SELECT COUNT(*) FROM {deals}'));
    Assert::contains('belongs to a different pipeline', $response['body']);
});

Harness::test('a value that is not money is refused', function () use ($owner, $birthday, $newInquiry): void {
    // Broken to check: having parseMoney() fall back to (int) $input instead
    // of returning null — "five hundred" silently becomes 0 and this fails.
    $before = (int) DB::value('SELECT COUNT(*) FROM {deals}');

    $response = $owner->postWithToken('/deals/new', '/deals', [
        'title'         => deals_title('Bad money'),
        'pipeline_id'   => (string) $birthday,
        'stage_id'      => (string) $newInquiry,
        'value_dollars' => 'five hundred',
    ], true);

    Assert::same($before, (int) DB::value('SELECT COUNT(*) FROM {deals}'));
    Assert::contains('dollars and cents', $response['body']);
});

Harness::test('a deal POST with no CSRF token is refused', function () use ($owner, $birthday, $newInquiry): void {
    // Broken to check: dropping 'csrf' from the route — the deal is created
    // and the count assertion fails. A cross-site POST that creates records is
    // the cheapest attack on a logged-in manager.
    $before = (int) DB::value('SELECT COUNT(*) FROM {deals}');

    $response = $owner->post('/deals', [
        'title'         => deals_title('No token'),
        'pipeline_id'   => (string) $birthday,
        'stage_id'      => (string) $newInquiry,
        'value_dollars' => '100',
    ]);

    Assert::same(419, $response['status'], 'the CSRF middleware answers 419');
    Assert::same($before, (int) DB::value('SELECT COUNT(*) FROM {deals}'));
});

// -------------------------------------------------------- the view gate

Harness::test('the front desk cannot see or create deals', function () use ($desk, $birthday, $newInquiry): void {
    // Broken to check: removing can:view_deals from the group — the board
    // renders 200 for a role that deliberately cannot see pipelines, and the
    // first assertion fails.
    Assert::same(403, $desk->get('/deals')['status'], 'front desk holds no view_deals');
    Assert::same(403, $desk->get('/deals/table')['status']);
    Assert::same(403, $desk->get('/deals/new')['status']);
    Assert::same(403, $desk->get('/pipelines')['status']);

    $before = (int) DB::value('SELECT COUNT(*) FROM {deals}');

    $response = $desk->post('/deals', [
        'title'         => deals_title('Desk attempt'),
        'pipeline_id'   => (string) $birthday,
        'stage_id'      => (string) $newInquiry,
        'value_dollars' => '100',
    ]);

    Assert::same(403, $response['status']);
    Assert::same($before, (int) DB::value('SELECT COUNT(*) FROM {deals}'));
});

// ------------------------------------------------------------- moving

Harness::test('moving a deal records who moved it and how long it sat', function () use (
    $gm,
    $gmId,
    $birthday,
    $newInquiry,
    $quoteSent
): void {
    // THE history test. Broken to check: removing the DB::insert from
    // recordMove() — the history count stays at 1; and dropping user_id from
    // that row, which the actor assertion catches. "This sat in Quote Sent for
    // nine days" is only answerable because of these rows.
    $created = deals_create($gm, [
        'title'         => deals_title('Moving on'),
        'pipeline_id'   => (string) $birthday,
        'stage_id'      => (string) $newInquiry,
        'value_dollars' => '400',
    ]);

    $dealId = $created['id'];

    Assert::same(1, deals_history_count($dealId));

    $response = $gm->postWithToken('/deals/' . $dealId, '/deals/' . $dealId . '/stage', [
        'stage_id' => (string) $quoteSent,
        'from'     => 'board',
    ]);

    Assert::same(302, $response['status']);
    Assert::contains('/deals?pipeline=' . $birthday, $response['location'], 'back to the board it came from');

    $deal = deals_row($dealId);

    Assert::notNull($deal);
    Assert::same($quoteSent, (int) $deal['stage_id'], 'the deal is in the new stage');
    Assert::same('open', (string) $deal['status']);

    Assert::same(2, deals_history_count($dealId), 'the move is recorded');

    $entry = deals_latest_history($dealId);

    Assert::notNull($entry);
    Assert::same($newInquiry, (int) $entry['from_stage_id']);
    Assert::same($quoteSent, (int) $entry['to_stage_id']);
    Assert::same($gmId, (int) $entry['user_id'], 'by whom');
    Assert::notNull($entry['duration_seconds'], 'and how long it had been sitting');
    Assert::same(1, deals_audit_count('deal.stage_changed', $dealId));
});

Harness::test('moving into a won stage closes the deal', function () use (
    $gm,
    $birthday,
    $newInquiry,
    $confirmed
): void {
    // Broken to check: hard-coding status 'open' in stageColumns() — the deal
    // reaches Confirmed and still reads as open, so the pipeline value never
    // falls and every won/lost report is wrong.
    $created = deals_create($gm, [
        'title'         => deals_title('Winning'),
        'pipeline_id'   => (string) $birthday,
        'stage_id'      => (string) $newInquiry,
        'value_dollars' => '700',
    ]);

    $gm->postWithToken('/deals/' . $created['id'], '/deals/' . $created['id'] . '/stage', [
        'stage_id' => (string) $confirmed,
    ]);

    $deal = deals_row($created['id']);

    Assert::notNull($deal);
    Assert::same('won', (string) $deal['status']);
    Assert::notEmpty($deal['won_at'], 'and it records when');
    Assert::empty($deal['lost_at']);
});

Harness::test('moving a deal to the stage it is already in is refused', function () use (
    $gm,
    $birthday,
    $newInquiry
): void {
    // Broken to check: removing the same-stage guard — a double submit writes
    // a second, zero-length history row into every such deal's history and
    // this fails.
    $created = deals_create($gm, [
        'title'         => deals_title('Same stage'),
        'pipeline_id'   => (string) $birthday,
        'stage_id'      => (string) $newInquiry,
        'value_dollars' => '10',
    ]);

    $response = $gm->postWithToken(
        '/deals/' . $created['id'],
        '/deals/' . $created['id'] . '/stage',
        ['stage_id' => (string) $newInquiry],
        true
    );

    Assert::same(1, deals_history_count($created['id']), 'no history row for a move that did not happen');
    Assert::contains('already in New Inquiry', $response['body']);
});

Harness::test('a deal cannot be moved into another pipeline\'s stage', function () use (
    $gm,
    $birthday,
    $newInquiry,
    $groupInquiry
): void {
    // Broken to check: removing the pipeline check from move() — the deal ends
    // up in a stage of the Groups pipeline while still belonging to Birthday,
    // which means it vanishes from both boards.
    $created = deals_create($gm, [
        'title'         => deals_title('Cross pipeline'),
        'pipeline_id'   => (string) $birthday,
        'stage_id'      => (string) $newInquiry,
        'value_dollars' => '10',
    ]);

    $response = $gm->postWithToken(
        '/deals/' . $created['id'],
        '/deals/' . $created['id'] . '/stage',
        ['stage_id' => (string) $groupInquiry],
        true
    );

    $deal = deals_row($created['id']);

    Assert::notNull($deal);
    Assert::same($newInquiry, (int) $deal['stage_id'], 'the deal did not move');
    Assert::same(1, deals_history_count($created['id']));
    Assert::contains('belongs to another pipeline', $response['body']);
});

// ------------------------------------------------------- the detail page

Harness::test('the detail page shows the deal and its history', function () use (
    $gm,
    $birthday,
    $newInquiry,
    $quoteSent
): void {
    // Broken to check: returning [] from history() — the "Created in" and
    // arrow entries disappear and this fails.
    $title   = deals_title('Detail page');
    $created = deals_create($gm, [
        'title'         => $title,
        'pipeline_id'   => (string) $birthday,
        'stage_id'      => (string) $newInquiry,
        'value_dollars' => '1234.56',
    ]);

    $gm->postWithToken('/deals/' . $created['id'], '/deals/' . $created['id'] . '/stage', [
        'stage_id' => (string) $quoteSent,
    ]);

    $response = $gm->get('/deals/' . $created['id']);

    Assert::same(200, $response['status']);
    Assert::contains($title, $response['body']);
    Assert::contains('$1,234.56', $response['body'], 'the value, formatted from cents');
    Assert::contains('Stage history', $response['body']);
    Assert::contains('Created in', $response['body'], 'the opening row');
    Assert::contains('Quote Sent', $response['body'], 'and the stage it moved to');
});

// ------------------------------------------------------------- scoping

Harness::test('an owner-scoped role sees only its own deals', function () use (
    $host,
    $gm,
    $birthday,
    $newInquiry
): void {
    // THE scoping test. Broken to check: replacing Acl::scopeCondition() with
    // '1 = 1' in dealFilter() — the host's board shows the manager's deal and
    // the notContains assertion fails. Party Host holds view_deals at 'own'.
    $mine     = deals_title('Host own deal');
    $theirs   = deals_title('Manager deal');

    $hostDeal = deals_create($host, [
        'title'         => $mine,
        'pipeline_id'   => (string) $birthday,
        'stage_id'      => (string) $newInquiry,
        'value_dollars' => '200',
    ]);

    Assert::greaterThan(0, $hostDeal['id'], 'a host may create its own deal');

    deals_create($gm, [
        'title'         => $theirs,
        'pipeline_id'   => (string) $birthday,
        'stage_id'      => (string) $newInquiry,
        'value_dollars' => '300',
    ]);

    $board = $host->get('/deals?pipeline=' . $birthday);

    Assert::same(200, $board['status']);
    Assert::contains($mine, $board['body'], 'its own deal is there');
    Assert::notContains($theirs, $board['body'], 'somebody else\'s is not');

    $table = $host->get('/deals/table?pipeline=' . $birthday);

    Assert::contains($mine, $table['body'], 'and the table view is scoped the same way');
    Assert::notContains($theirs, $table['body']);
});

Harness::test('an owner-scoped role cannot open or move a deal it does not own', function () use (
    $host,
    $gm,
    $birthday,
    $newInquiry,
    $quoteSent
): void {
    // Broken to check: passing '1 = 1' to findDeal() — the host opens the
    // manager's deal (200 instead of 404) and can move it, so the stage
    // assertion fails too.
    $created = deals_create($gm, [
        'title'         => deals_title('Not yours'),
        'pipeline_id'   => (string) $birthday,
        'stage_id'      => (string) $newInquiry,
        'value_dollars' => '500',
    ]);

    $dealId = $created['id'];

    Assert::same(404, $host->get('/deals/' . $dealId)['status'], 'out of scope is indistinguishable from absent');
    Assert::same(404, $host->get('/deals/' . $dealId . '/edit')['status']);

    // The token has to come from a page the host CAN open, which is what an
    // attacker would do: minting it from the deal's own page is impossible.
    $response = $host->postWithToken('/deals', '/deals/' . $dealId . '/stage', [
        'stage_id' => (string) $quoteSent,
    ]);

    Assert::same(404, $response['status']);

    $deal = deals_row($dealId);

    Assert::notNull($deal);
    Assert::same($newInquiry, (int) $deal['stage_id'], 'and the deal did not move');
});

Harness::test('a role without deals_reassign cannot take over somebody else\'s deal', function () use (
    $agent,
    $gm,
    $gmId,
    $birthday,
    $newInquiry
): void {
    // Broken to check: removing the deals_reassign guard from resolveWrite() —
    // the agent becomes the owner and the owner assertion fails. The API role
    // holds deals_manage at 'all', so it can fetch the deal; what it may not
    // do is put its own name on it.
    $title = deals_title('Owned by the manager');

    $created = deals_create($gm, [
        'title'         => $title,
        'pipeline_id'   => (string) $birthday,
        'stage_id'      => (string) $newInquiry,
        'value_dollars' => '900',
    ]);

    $dealId   = $created['id'];
    $agentRow = DB::first("SELECT id FROM {users} WHERE name = 'Integration Agent' AND deleted_at IS NULL");

    Assert::notNull($agentRow, 'the fixture agent should exist');

    $response = $agent->postWithToken('/deals/' . $dealId . '/edit', '/deals/' . $dealId, [
        'title'         => $title . ' (grabbed)',
        'pipeline_id'   => (string) $birthday,
        'stage_id'      => (string) $newInquiry,
        'value_dollars' => '900',
        'owner_id'      => (string) (int) $agentRow['id'],
    ], true);

    $deal = deals_row($dealId);

    Assert::notNull($deal);
    Assert::same($gmId, (int) $deal['owner_id'], 'the manager still owns it');
    Assert::contains('cannot set the owner', $response['body']);
    Assert::same($title, (string) $deal['title'], 'and the refusal wrote nothing at all');
});

Harness::test('editing a deal without changing its owner still works for that role', function () use (
    $agent,
    $gm,
    $gmId,
    $birthday,
    $newInquiry
): void {
    // The other half of the rule: the reassign guard must bite on OWNER
    // changes only. Broken to check: refusing every owner_id submission
    // regardless of whether it changed — this ordinary edit fails.
    $created = deals_create($gm, [
        'title'         => deals_title('Editable by the agent'),
        'pipeline_id'   => (string) $birthday,
        'stage_id'      => (string) $newInquiry,
        'value_dollars' => '120',
    ]);

    $newTitle = deals_title('Edited by the agent');

    $agent->postWithToken('/deals/' . $created['id'] . '/edit', '/deals/' . $created['id'], [
        'title'         => $newTitle,
        'pipeline_id'   => (string) $birthday,
        'stage_id'      => (string) $newInquiry,
        'value_dollars' => '150.25',
        'owner_id'      => (string) $gmId,
    ], true);

    $deal = deals_row($created['id']);

    Assert::notNull($deal);
    Assert::same($newTitle, (string) $deal['title']);
    Assert::same(15025, (int) $deal['value_cents']);
    Assert::same($gmId, (int) $deal['owner_id']);
    Assert::same(1, deals_audit_count('deal.updated', $created['id']));
});

Harness::test('a deal whose owner has left can still be edited and reassigned', function () use (
    $owner,
    $gmId,
    $birthday,
    $newInquiry
): void {
    // The moment a deal most needs editing is when its owner has gone, and the
    // assignable-owner check used to run on every save — so the refusal fired
    // on a field the manager had not touched and the deal was stuck.
    //
    // Broken to check: running isAssignable() regardless of whether the owner
    // changed — the first save is refused and the title assertion fails.
    $leaverId = test_make_user('party_host', [
        'email'         => 'deals-leaver-' . bin2hex(random_bytes(4)) . '@example.test',
        'name'          => 'Departed Dale',
        'password_hash' => Auth::hash('leaver-test-passphrase-9'),
    ]);

    $created = deals_create($owner, [
        'title'         => deals_title('Left behind'),
        'pipeline_id'   => (string) $birthday,
        'stage_id'      => (string) $newInquiry,
        'value_dollars' => '410',
        'owner_id'      => (string) $leaverId,
    ]);

    Assert::greaterThan(0, $created['id']);
    Assert::same($leaverId, (int) deals_row($created['id'])['owner_id']);

    DB::update('users', ['is_active' => 0], 'id = :id', ['id' => $leaverId]);

    $newTitle = deals_title('Still editable');

    $owner->postWithToken('/deals/' . $created['id'] . '/edit', '/deals/' . $created['id'], [
        'title'         => $newTitle,
        'pipeline_id'   => (string) $birthday,
        'stage_id'      => (string) $newInquiry,
        'value_dollars' => '410',
        'owner_id'      => (string) $leaverId,
    ], true);

    Assert::same($newTitle, (string) deals_row($created['id'])['title'], 'an untouched owner blocks nothing');

    // And handing it to somebody who is still here works.
    $owner->postWithToken('/deals/' . $created['id'] . '/edit', '/deals/' . $created['id'], [
        'title'         => $newTitle,
        'pipeline_id'   => (string) $birthday,
        'stage_id'      => (string) $newInquiry,
        'value_dollars' => '410',
        'owner_id'      => (string) $gmId,
    ], true);

    Assert::same($gmId, (int) deals_row($created['id'])['owner_id'], 'reassignment to a live user works');

    // While naming the departed account as a NEW owner is still refused.
    $other = deals_create($owner, [
        'title'         => deals_title('Not to a leaver'),
        'pipeline_id'   => (string) $birthday,
        'stage_id'      => (string) $newInquiry,
        'value_dollars' => '55',
    ]);

    $response = $owner->postWithToken('/deals/' . $other['id'] . '/edit', '/deals/' . $other['id'], [
        'title'         => 'Not to a leaver (attempt)',
        'pipeline_id'   => (string) $birthday,
        'stage_id'      => (string) $newInquiry,
        'value_dollars' => '55',
        'owner_id'      => (string) $leaverId,
    ], true);

    Assert::contains('not an active user', $response['body']);
    Assert::notSame(
        $leaverId,
        (int) deals_row($other['id'])['owner_id'],
        'a deactivated account must not be given new work'
    );
});

// ------------------------------------------------------------ deleting

Harness::test('a role without deals_delete cannot delete its own deal', function () use (
    $host,
    $birthday,
    $newInquiry
): void {
    // Broken to check: removing can:deals_delete from the route AND the
    // authorize() call in destroy() — the host's deal is soft-deleted and the
    // deleted_at assertion fails. Party Host holds deals_manage but not
    // deals_delete, which is the whole point of the split.
    $created = deals_create($host, [
        'title'         => deals_title('Host cannot delete'),
        'pipeline_id'   => (string) $birthday,
        'stage_id'      => (string) $newInquiry,
        'value_dollars' => '75',
    ]);

    $response = $host->postWithToken('/deals/' . $created['id'], '/deals/' . $created['id'] . '/delete', []);

    Assert::same(403, $response['status']);

    $deal = deals_row($created['id']);

    Assert::notNull($deal);
    Assert::null($deal['deleted_at'], 'the deal is still live');
});

Harness::test('deleting a deal takes it off the board and is audited', function () use (
    $owner,
    $birthday,
    $newInquiry
): void {
    // Broken to check: making destroy() a no-op — the board still lists the
    // title; and removing its Audit::record(), which the audit assertion
    // catches.
    $title   = deals_title('To be removed');
    $created = deals_create($owner, [
        'title'         => $title,
        'pipeline_id'   => (string) $birthday,
        'stage_id'      => (string) $newInquiry,
        'value_dollars' => '320',
    ]);

    Assert::contains($title, $owner->get('/deals?pipeline=' . $birthday)['body']);

    $response = $owner->postWithToken(
        '/deals/' . $created['id'],
        '/deals/' . $created['id'] . '/delete',
        [],
        true
    );

    Assert::same(200, $response['status']);

    $deal = deals_row($created['id']);

    Assert::notNull($deal, 'a deleted deal is kept, not dropped');
    Assert::notEmpty($deal['deleted_at'], 'but it is marked deleted');
    Assert::same(1, deals_audit_count('deal.deleted', $created['id']));
    Assert::notContains($title, $owner->get('/deals?pipeline=' . $birthday)['body'], 'and it leaves the board');
    Assert::same(404, $owner->get('/deals/' . $created['id'])['status']);
});

// ------------------------------------------------------------ pipelines

Harness::test('the pipelines page reports each pipeline\'s stages, deals and open value', function () use (
    $owner
): void {
    // Broken to check: dropping the stage_count subquery (the row then reads
    // "0" stages), and dropping the deal_count/open_value derived table (the
    // row reads 0 deals and $0.00). Measured against a pipeline this test
    // builds, so the figures are known exactly rather than being whatever the
    // rest of the suite happens to have left in the seeded pipelines.
    $name = 'Counting Pipeline ' . substr(str_shuffle('abcdefghijklmnopqrstuvwxyz'), 0, 6);

    $owner->postWithToken('/pipelines/new', '/pipelines', ['name' => $name, 'type' => 'custom']);

    $pipelineId = (int) DB::value('SELECT id FROM {pipelines} WHERE name = :n', ['n' => $name]);

    Assert::greaterThan(0, $pipelineId);

    $owner->postWithToken(
        '/pipelines/' . $pipelineId,
        '/pipelines/' . $pipelineId . '/stages',
        [
            'new_stages[0][name]'       => 'Asked',
            'new_stages[0][sort_order]' => '10',
            'new_stages[1][name]'       => 'Quoted',
            'new_stages[1][sort_order]' => '20',
            'new_stages[2][name]'       => 'Closed',
            'new_stages[2][sort_order]' => '30',
        ],
        true
    );

    $stageId = (int) DB::value(
        'SELECT id FROM {pipeline_stages} WHERE pipeline_id = :id ORDER BY sort_order LIMIT 1',
        ['id' => $pipelineId]
    );

    deals_create($owner, [
        'title'         => deals_title('Counted deal'),
        'pipeline_id'   => (string) $pipelineId,
        'stage_id'      => (string) $stageId,
        'value_dollars' => '77.00',
    ]);

    $response = $owner->get('/pipelines');

    Assert::same(200, $response['status']);
    Assert::contains('Birthday Parties', $response['body'], 'the seeded pipelines are listed');
    Assert::contains('Groups &amp; Corporate', $response['body']);

    // Just this pipeline's row, as text, so the assertion cannot be satisfied
    // by a number belonging to another row.
    $matched = preg_match(
        '#<tr[^>]*>(?:(?!</tr>).)*' . preg_quote($name, '#') . '(?:(?!</tr>).)*</tr>#s',
        $response['body'],
        $match
    );

    Assert::same(1, $matched, 'the new pipeline should have a row');

    $row = trim((string) preg_replace('/\s+/', ' ', strip_tags($match[0])));

    Assert::contains(' 3 ', $row, 'three stages');
    Assert::contains(' 1 ', $row, 'one deal');
    Assert::contains('$77.00', $row, 'and its open value');
});

Harness::test('creating a pipeline derives a slug and audits it', function () use ($owner): void {
    // Broken to check: inserting the submitted name as the slug — "Fundraiser
    // Nights 2026" is not a slug and this fails; and removing the audit row.
    $name = 'Fundraiser Nights ' . bin2hex(random_bytes(3));

    $response = $owner->postWithToken('/pipelines/new', '/pipelines', [
        'name'       => $name,
        'type'       => 'custom',
        'sort_order' => '90',
        'is_active'  => '1',
    ]);

    Assert::same(302, $response['status']);

    $row = DB::first('SELECT * FROM {pipelines} WHERE name = :n', ['n' => $name]);

    Assert::notNull($row, 'the pipeline should exist');
    Assert::same(slugify($name), (string) $row['slug']);
    Assert::same('custom', (string) $row['type']);
    Assert::same(1, (int) $row['is_active']);
    Assert::contains('/pipelines/' . (int) $row['id'], $response['location'], 'and it sends you to its stages');

    $audits = (int) DB::value(
        "SELECT COUNT(*) FROM {audit_log}
          WHERE action = 'pipeline.created' AND entity_type = 'pipelines' AND entity_id = :id",
        ['id' => (int) $row['id']]
    );

    Assert::same(1, $audits);
});

Harness::test('a pipeline with no stages says so, and adding one fixes it', function () use ($owner): void {
    // Broken to check: skipping the additions loop in saveStages() — no stage
    // row appears and this fails. Also covers the empty-pipeline state, which
    // is what the operator sees immediately after creating one.
    //
    // The name starts with A deliberately. Pipelines are listed by
    // is_active, then sort_order, then NAME, and a pipeline created here has
    // the default sort_order 0 — so this fixture is only the first in the list,
    // and therefore only exercises the default-landing rule below, if it also
    // sorts first by name. MEASURED: called "Stage Editing …" it sat behind
    // another test's pipeline and the landing assertion passed with the rule
    // removed.
    $name = 'AAA Stage Editing ' . bin2hex(random_bytes(3));

    $owner->postWithToken('/pipelines/new', '/pipelines', [
        'name' => $name,
        'type' => 'custom',
    ]);

    $pipelineId = (int) DB::value('SELECT id FROM {pipelines} WHERE name = :n', ['n' => $name]);

    Assert::greaterThan(0, $pipelineId);

    $empty = $owner->get('/pipelines/' . $pipelineId);

    Assert::same(200, $empty['status']);
    Assert::contains('No stages yet', $empty['body']);

    // Its own board says so rather than looking like an empty pipeline...
    $itsBoard = $owner->get('/deals?pipeline=' . $pipelineId);

    Assert::contains('has no stages', $itsBoard['body']);

    // ...but the board with nothing asked for must not LAND on it. A pipeline
    // made a minute ago has no stages and a default sort_order of 0, so it
    // sorts ahead of the seeded ones; landing there reads as "the venue has no
    // deals". Broken to check: dropping the stage_count preference from
    // chosenPipeline() — MEASURED, the default board opened on this empty
    // shell.
    $landing = $owner->get('/deals');

    Assert::same(200, $landing['status']);
    Assert::notContains('has no stages', $landing['body'], 'the default board must open somewhere usable');

    $response = $owner->postWithToken(
        '/pipelines/' . $pipelineId,
        '/pipelines/' . $pipelineId . '/stages',
        [
            'new_stages[0][name]'        => 'Enquiry',
            'new_stages[0][sort_order]'  => '10',
            'new_stages[0][probability]' => '20',
            'new_stages[0][sla_hours]'   => '6',
            'new_stages[0][color]'       => '#123456',
            'new_stages[1][name]'        => 'Booked',
            'new_stages[1][sort_order]'  => '20',
            'new_stages[1][probability]' => '100',
            'new_stages[1][is_won]'      => '1',
        ],
        true
    );

    Assert::same(200, $response['status']);

    $stages = DB::all(
        'SELECT * FROM {pipeline_stages} WHERE pipeline_id = :id ORDER BY sort_order',
        ['id' => $pipelineId]
    );

    Assert::count(2, $stages);
    Assert::same('Enquiry', (string) $stages[0]['name']);
    Assert::same(6, (int) $stages[0]['sla_hours']);
    Assert::same('#123456', (string) $stages[0]['color']);
    Assert::same(0, (int) $stages[0]['is_won']);
    Assert::same('Booked', (string) $stages[1]['name']);
    Assert::same(1, (int) $stages[1]['is_won'], 'a won stage is how a deal closes');
    Assert::same(100, (int) $stages[1]['probability']);
});

Harness::test('a stage cannot count as both won and lost', function () use ($owner): void {
    // Broken to check: removing the contradiction check in parseStage() — the
    // stage is created and the count assertion fails. A deal reaching it would
    // be won and lost at once, and stageColumns() would quietly pick won.
    $name = 'Contradiction ' . bin2hex(random_bytes(3));

    $owner->postWithToken('/pipelines/new', '/pipelines', ['name' => $name, 'type' => 'custom']);

    $pipelineId = (int) DB::value('SELECT id FROM {pipelines} WHERE name = :n', ['n' => $name]);

    $response = $owner->postWithToken(
        '/pipelines/' . $pipelineId,
        '/pipelines/' . $pipelineId . '/stages',
        [
            'new_stages[0][name]'    => 'Schrodinger',
            'new_stages[0][is_won]'  => '1',
            'new_stages[0][is_lost]' => '1',
        ],
        true
    );

    Assert::same(
        0,
        (int) DB::value('SELECT COUNT(*) FROM {pipeline_stages} WHERE pipeline_id = :id', ['id' => $pipelineId]),
        'nothing may be written'
    );
    Assert::contains('cannot count as both won and lost', $response['body']);
});

Harness::test('a stage holding deals cannot be removed', function () use ($owner, $birthday, $newInquiry): void {
    // Broken to check: removing the in-use guard from saveStages() — MySQL
    // then refuses the DELETE itself (the FK from deals.stage_id is RESTRICT)
    // and the operator gets a 500 instead of a sentence, so the "still holds"
    // assertion fails.
    $created = deals_create($owner, [
        'title'         => deals_title('Holding a stage open'),
        'pipeline_id'   => (string) $birthday,
        'stage_id'      => (string) $newInquiry,
        'value_dollars' => '45',
    ]);

    Assert::greaterThan(0, $created['id']);

    $response = $owner->postWithToken(
        '/pipelines/' . $birthday,
        '/pipelines/' . $birthday . '/stages',
        ['stages[' . $newInquiry . '][remove]' => '1'],
        true
    );

    Assert::same(200, $response['status'], 'a refusal, not a database error');
    Assert::contains('still holds', $response['body']);
    Assert::notNull(
        DB::first('SELECT id FROM {pipeline_stages} WHERE id = :id', ['id' => $newInquiry]),
        'the stage is still there'
    );
});

Harness::test('renaming a stage renames the board column', function () use ($owner): void {
    // Broken to check: skipping the updates loop in saveStages() — the new
    // name never reaches the database and the board still shows the old one.
    $name = 'Renameable ' . bin2hex(random_bytes(3));

    $owner->postWithToken('/pipelines/new', '/pipelines', ['name' => $name, 'type' => 'custom']);

    $pipelineId = (int) DB::value('SELECT id FROM {pipelines} WHERE name = :n', ['n' => $name]);

    $owner->postWithToken(
        '/pipelines/' . $pipelineId,
        '/pipelines/' . $pipelineId . '/stages',
        ['new_stages[0][name]' => 'First Draft', 'new_stages[0][sort_order]' => '10'],
        true
    );

    $stageId = (int) DB::value(
        'SELECT id FROM {pipeline_stages} WHERE pipeline_id = :id',
        ['id' => $pipelineId]
    );

    Assert::greaterThan(0, $stageId);

    $owner->postWithToken(
        '/pipelines/' . $pipelineId,
        '/pipelines/' . $pipelineId . '/stages',
        [
            'stages[' . $stageId . '][name]'        => 'Second Draft',
            'stages[' . $stageId . '][sort_order]'  => '10',
            'stages[' . $stageId . '][probability]' => '0',
            'stages[' . $stageId . '][color]'       => '#6b4ea8',
        ],
        true
    );

    Assert::same(
        'Second Draft',
        (string) DB::value('SELECT name FROM {pipeline_stages} WHERE id = :id', ['id' => $stageId])
    );

    $board = $owner->get('/deals?pipeline=' . $pipelineId);

    Assert::contains('Second Draft', $board['body'], 'the board reads from the same row');
    Assert::notContains('First Draft', $board['body']);
});

Harness::test('an inactive pipeline leaves the new-deal form', function () use ($owner): void {
    // Two things at once, because they are the same rule: unticking Active has
    // to reach the database (the form posts a hidden 0 behind the checkbox,
    // since an unticked box submits nothing at all), and the new-deal form has
    // to offer only live pipelines.
    //
    // Broken to check: hard-coding is_active => 1 in update() (the row stays
    // active and the first assertion fails), and dropping
    // "WHERE p.is_active = 1" from stageGroups() (the retired pipeline's stage
    // is still offered and the last assertion fails).
    $name = 'Retiring Pipeline ' . substr(str_shuffle('abcdefghijklmnopqrstuvwxyz'), 0, 6);

    $owner->postWithToken('/pipelines/new', '/pipelines', ['name' => $name, 'type' => 'custom']);

    $pipelineId = (int) DB::value('SELECT id FROM {pipelines} WHERE name = :n', ['n' => $name]);

    Assert::greaterThan(0, $pipelineId);

    $owner->postWithToken(
        '/pipelines/' . $pipelineId,
        '/pipelines/' . $pipelineId . '/stages',
        ['new_stages[0][name]' => 'Seasonal Hold', 'new_stages[0][sort_order]' => '10'],
        true
    );

    $form = $owner->get('/deals/new');

    Assert::same(200, $form['status']);
    Assert::contains('<optgroup label="Birthday Parties">', $form['body'], 'stages are grouped by pipeline');
    Assert::contains('Seasonal Hold', $form['body'], 'and a live pipeline is offered');

    // Exactly what the form sends when the box is cleared.
    $owner->postWithToken('/pipelines/' . $pipelineId, '/pipelines/' . $pipelineId, [
        'name'      => $name,
        'type'      => 'custom',
        'is_active' => '0',
    ], true);

    Assert::same(
        0,
        (int) DB::value('SELECT is_active FROM {pipelines} WHERE id = :id', ['id' => $pipelineId]),
        'unticking Active has to reach the row'
    );

    $after = $owner->get('/deals/new');

    Assert::same(200, $after['status']);
    Assert::notContains('Seasonal Hold', $after['body'], 'a retired pipeline takes no new deals');
    Assert::contains('<optgroup label="Birthday Parties">', $after['body'], 'the live ones stay');
});

Harness::test('a role that may see pipelines may not edit them', function () use ($baseUrl): void {
    // Party Host holds view_deals but not... it holds deals_manage at 'own'.
    // The read-only role is the one that may look and not touch, so this uses
    // a fresh user of it. Broken to check: removing can:deals_manage from the
    // pipeline write routes — the create form opens to a read-only account.
    $email = 'deals-readonly-' . bin2hex(random_bytes(4)) . '@example.test';

    test_make_user('read_only', [
        'email'         => $email,
        'name'          => 'Read Only Rory',
        'password_hash' => Auth::hash('readonly-test-passphrase-9'),
    ]);

    $viewer = new TestBrowser($baseUrl, 'deals-readonly');
    $viewer->login($email, 'readonly-test-passphrase-9');

    Assert::same(200, $viewer->get('/deals')['status'], 'read only may look at the board');
    Assert::same(200, $viewer->get('/pipelines')['status']);
    Assert::same(403, $viewer->get('/deals/new')['status'], 'but not create a deal');
    Assert::same(403, $viewer->get('/pipelines/new')['status'], 'nor a pipeline');

    $before = (int) DB::value('SELECT COUNT(*) FROM {pipelines}');

    // With a REAL token, minted from a page this role can open — otherwise the
    // CSRF middleware answers 419 first and the permission gate is never
    // reached, so the test would pass without proving anything about it.
    $response = $viewer->postWithToken('/pipelines', '/pipelines', [
        'name' => 'Sneaky Pipeline',
        'type' => 'custom',
    ]);

    Assert::same(403, $response['status'], 'deals_manage is what guards the write');
    Assert::same($before, (int) DB::value('SELECT COUNT(*) FROM {pipelines}'));
});

// ---------------------------------------------------------------- searching

/**
 * The link a rendered row carries, which is the only honest marker that a
 * deal is actually ON the page.
 *
 * Asserting on the deal's TITLE would be vacuous: the filter bar echoes the
 * search term back into `<input name="q" value="...">`, so a page with no
 * results at all still contains the term somebody searched for. The closing
 * quote is part of the needle so that /deals/5 cannot match /deals/51.
 */
function deals_row_link(int $id): string
{
    return '/deals/' . $id . '"';
}

Harness::test('searching the board and the table finds the deal and leaves the rest out', function () use (
    $owner,
    $birthday,
    $newInquiry
): void {
    // THIS IS THE TEST THAT WAS MISSING, and its absence is the whole reason
    // the bug shipped. Every list test here loaded /deals with no `q`, so the
    // search clause was never built and never bound. With a term, the old
    // clause reused one named parameter three times; DB::connect() disables
    // emulated prepares, the native driver answers HY093 to a repeated name,
    // and the page was a 500 for every search anybody ever typed.
    //
    // MEASURED before the fix: HTTP 500 on both views, with no message
    // anywhere in the UI.
    //
    // Broken to check: collapsing :q_title/:q_first/:q_last back to one name
    // — both status assertions go to 500.
    $term     = 'Searchable' . bin2hex(random_bytes(3));
    $wanted   = deals_title($term . ' party');
    $unwanted = deals_title('Unsearchable party');

    $a = deals_create($owner, [
        'title'       => $wanted,
        'pipeline_id' => (string) $birthday,
        'stage_id'    => (string) $newInquiry,
    ]);
    $b = deals_create($owner, [
        'title'       => $unwanted,
        'pipeline_id' => (string) $birthday,
        'stage_id'    => (string) $newInquiry,
    ]);

    try {
        $board = $owner->get('/deals?pipeline=' . $birthday . '&q=' . urlencode($term));

        Assert::same(200, $board['status'], 'a search must not be a server error');
        Assert::contains(
            deals_row_link($a['id']),
            $board['body'],
            'and must put the matching deal on the board'
        );
        Assert::notContains(
            deals_row_link($b['id']),
            $board['body'],
            'a search that returns everybody is not a search'
        );

        $table = $owner->get('/deals/table?pipeline=' . $birthday . '&q=' . urlencode($term));

        Assert::same(200, $table['status'], 'the table view shares the clause, so it shared the bug');
        Assert::contains(deals_row_link($a['id']), $table['body']);
        Assert::notContains(deals_row_link($b['id']), $table['body']);

        // The aggregate row is built from the same WHERE by a DIFFERENT query
        // with its own binding, so it is a second chance to get the parameter
        // names wrong. It reports the filtered count, not the pipeline's.
        Assert::contains('Deals in view', $table['body']);
    } finally {
        DB::delete('deals', 'id IN (:a, :b)', ['a' => $a['id'], 'b' => $b['id']]);
    }
});

Harness::test('searching by the guest\'s name finds their deal', function () use (
    $owner,
    $birthday,
    $newInquiry
): void {
    // The contact's two name halves are separate placeholders now, and each is
    // reached by a different term — so binding first_name's value under
    // last_name's name would show up here rather than in the title test.
    $first   = 'Searchfirst' . bin2hex(random_bytes(3));
    $surname = 'Searchsurname' . bin2hex(random_bytes(3));

    $contactId = test_make_contact(['first_name' => $first, 'last_name' => $surname]);

    $created = deals_create($owner, [
        'title'       => deals_title('Named guest'),
        'pipeline_id' => (string) $birthday,
        'stage_id'    => (string) $newInquiry,
        'contact_id'  => (string) $contactId,
    ]);

    try {
        $bySurname = $owner->get('/deals/table?pipeline=' . $birthday . '&q=' . urlencode($surname));

        Assert::same(200, $bySurname['status']);
        Assert::contains(
            deals_row_link($created['id']),
            $bySurname['body'],
            'a surname search reaches the deal'
        );

        $byFirst = $owner->get('/deals/table?pipeline=' . $birthday . '&q=' . urlencode($first));

        Assert::same(200, $byFirst['status']);
        Assert::contains(
            deals_row_link($created['id']),
            $byFirst['body'],
            'and so does a first-name search'
        );

        $miss = $owner->get(
            '/deals/table?pipeline=' . $birthday . '&q=' . urlencode('Notamatch' . bin2hex(random_bytes(3)))
        );

        Assert::same(200, $miss['status']);
        Assert::notContains(
            deals_row_link($created['id']),
            $miss['body'],
            'while a term matching nothing finds nothing'
        );
    } finally {
        DB::delete('deals', 'id = :id', ['id' => $created['id']]);
        DB::delete('contacts', 'id = :id', ['id' => $contactId]);
    }
});
