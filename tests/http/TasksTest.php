<?php

declare(strict_types=1);

/**
 * Tasks, end to end over HTTP.
 *
 * Written against the browser rather than the controller because every rule
 * worth testing here is a rule about a REQUEST: who may see which tasks, what a
 * POST writes, and what it refuses. A unit test of TaskController would pass
 * with the route unguarded.
 *
 * The timezone tests are the point of this file. All internal time is UTC and
 * the venue is not, so "due today" and "overdue" are claims about a clock the
 * database does not keep. Three of them set the venue timezone explicitly and
 * pin exact stored values; the rest pick a real zone whose local clock is
 * currently mid-day, so a task due at 11pm venue-local is reliably in the
 * future and one due at 00:30 venue-local is reliably in the past whatever hour
 * the suite happens to run at. That zone always has a non-trivial offset, which
 * is what makes the venue's day and UTC's day genuinely different windows — the
 * difference a UTC-based suite cannot see and production gets wrong every
 * evening.
 *
 * Each test asserts on an outcome — a row in the database, a redirect, a
 * sentence on the page — never on the absence of an error. Every one of them
 * was broken on purpose and confirmed to fail before being kept; the comment on
 * each says what was broken.
 */

use App\Core\Auth;
use App\Core\DB;
use App\Core\Settings;
use Tests\Assert;
use Tests\Harness;

Harness::suite('http/tasks');

$baseUrl = getenv('CC_TEST_BASE_URL') ?: '';

if ($baseUrl === '') {
    Harness::skip('tasks suite', 'no server; run via tests/run.php');

    return;
}

if (!test_boot()) {
    Harness::skip('tasks suite', 'no database available');

    return;
}

require_once CC_ROOT . '/tests/TestBrowser.php';

// ---------------------------------------------------------------- fixtures

function tasks_user_id(string $email): int
{
    return (int) DB::value(
        'SELECT id FROM {users} WHERE email = :e AND deleted_at IS NULL',
        ['e' => $email]
    );
}

/** A title no other test or seed will collide with. */
function tasks_title(string $what): string
{
    static $counter = 0;
    $counter++;

    return $what . ' ' . $counter . '-' . bin2hex(random_bytes(3));
}

/**
 * Post the new-task form and hand back the response plus the new id.
 *
 * The redirect is FOLLOWED, which is not a detail: success() flashes "… is on
 * the list" into the session, and a flash that is never rendered is rendered on
 * the next page this browser loads instead — carrying the new task's title with
 * it. Several tests below assert that a title is NOT on a page, and a stale
 * flash would put it there and fail them for the wrong reason.
 *
 * @param array<string,string> $fields
 * @return array{status:int,location:string,body:string,id:int}
 */
function tasks_create(TestBrowser $browser, array $fields, string $tokenFrom = '/tasks/new'): array
{
    $response = $browser->postWithToken($tokenFrom, '/tasks', $fields, true);

    // The first Location header in the exchange is the redirect the POST
    // answered with, which is the one carrying the new id.
    $id = preg_match('#/tasks/(\d+)#', $response['location'], $match) === 1 ? (int) $match[1] : 0;

    return [
        'status'   => $response['status'],
        'location' => $response['location'],
        'body'     => $response['body'],
        'id'       => $id,
    ];
}

/** @return array<string,mixed>|null */
function tasks_row(int $id): ?array
{
    return DB::first('SELECT * FROM {tasks} WHERE id = :id', ['id' => $id]);
}

function tasks_count(): int
{
    return (int) DB::value('SELECT COUNT(*) FROM {tasks}');
}

function tasks_audit_count(string $action, int $entityId): int
{
    return (int) DB::value(
        "SELECT COUNT(*) FROM {audit_log}
          WHERE action = :action AND entity_type = 'tasks' AND entity_id = :id",
        ['action' => $action, 'id' => $entityId]
    );
}

/**
 * Run a body with the venue timezone set to $zone, then put it back.
 *
 * Settings are read from the database once per request, so a write here is
 * visible to the very next request the server handles. Restoring matters: the
 * suites that run after this one would otherwise inherit a venue on the far
 * side of the world.
 */
function tasks_with_timezone(string $zone, callable $body): void
{
    $had      = Settings::has('venue.timezone');
    $previous = $had ? (string) Settings::get('venue.timezone') : null;

    Settings::set('venue.timezone', $zone);

    try {
        $body();
    } finally {
        if ($previous === null) {
            Settings::forget('venue.timezone');
        } else {
            Settings::set('venue.timezone', $previous);
        }

        Settings::load(true);
    }
}

/** A venue-local date and time as the UTC string the database should hold. */
function tasks_expected_utc(string $zone, string $localDate, string $localTime): string
{
    return (new DateTimeImmutable($localDate . ' ' . $localTime, new DateTimeZone($zone)))
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d H:i:s');
}

function tasks_local_now(string $zone): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone($zone));
}

/**
 * A real timezone whose clock currently reads between 02:00 and 21:00, with an
 * offset of at least two hours from UTC.
 *
 * Both halves matter. The hour window makes "23:00 today" reliably in the
 * future and "00:30 today" reliably in the past, so the bucket tests below are
 * deterministic at any time of day. The offset makes the venue's local day and
 * the UTC day two genuinely different 24-hour windows — without that, a
 * day-boundary computed in UTC would give the same answer as one computed
 * locally, and the tests could not tell a correct implementation from a broken
 * one.
 *
 * The offsets below span -12 to +14, so for any UTC hour at least one of them
 * lands in the window. If none does, this throws rather than skipping: a
 * timezone test that quietly does not run is exactly the kind of green this
 * file exists to avoid.
 */
function tasks_pick_zone(): string
{
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    $candidates = [
        'Pacific/Kiritimati', 'Pacific/Auckland', 'Australia/Brisbane', 'Asia/Tokyo',
        'Asia/Shanghai', 'Asia/Dhaka', 'Asia/Kolkata', 'Asia/Dubai', 'Europe/Moscow',
        'Africa/Cairo', 'America/Sao_Paulo', 'America/New_York', 'America/Chicago',
        'America/Denver', 'America/Los_Angeles', 'America/Anchorage', 'Pacific/Honolulu',
        'Pacific/Pago_Pago', 'Etc/GMT+12',
    ];

    foreach ($candidates as $zone) {
        $tz = new DateTimeZone($zone);

        if (abs($tz->getOffset($now)) < 7200) {
            continue;
        }

        $hour = (int) $now->setTimezone($tz)->format('G');

        if ($hour >= 2 && $hour <= 21) {
            return $zone;
        }
    }

    Assert::fail('No candidate timezone is currently mid-day; the zone list needs widening.');
}

// A browser per actor: each presents its own client address, so the per-IP rate
// limiter never sees one suite as one hammering client.
$owner = new TestBrowser($baseUrl, 'tasks-owner');
$gm    = new TestBrowser($baseUrl, 'tasks-gm');
$desk  = new TestBrowser($baseUrl, 'tasks-desk');

$owner->login('owner@test.invalid', 'owner-test-passphrase-9');
$gm->login('gm@test.invalid', 'gm-test-passphrase-9');
$desk->login('desk@test.invalid', 'desk-test-passphrase-9');

$ownerId = tasks_user_id('owner@test.invalid');
$gmId    = tasks_user_id('gm@test.invalid');
$deskId  = tasks_user_id('desk@test.invalid');

// Chosen once, so every timezone test in this file agrees about where the venue
// is even if the suite happens to cross an hour boundary part way through.
$venueZone = tasks_pick_zone();

// A user who exists only to be an assignee, so a view can be narrowed to
// exactly the tasks a test created and nothing the rest of the suite left behind.
$cleanerEmail = 'tasks-cleaner-' . bin2hex(random_bytes(4)) . '@example.test';
$cleanerId    = test_make_user('front_desk', [
    'email'         => $cleanerEmail,
    'name'          => 'Closing Casey',
    'password_hash' => Auth::hash('cleaner-test-passphrase-9'),
]);

Harness::test('the fixtures signed in', function () use ($owner, $gm, $desk): void {
    // The precondition for everything below. Without it a permission test could
    // pass because the actor was never logged in at all.
    foreach (['owner' => $owner, 'gm' => $gm, 'desk' => $desk] as $who => $browser) {
        Assert::same(200, $browser->get('/account')['status'], $who . ' should be signed in');
    }
});

// ------------------------------------------------------------- the list

Harness::test('the task list offers the five views a venue needs', function () use ($owner): void {
    // Broken to check: dropping an entry from TaskController::VIEWS — the tab
    // disappears and this fails. "Mine / overdue / due today / unassigned /
    // all" is the whole navigation of the page.
    $response = $owner->get('/tasks');

    Assert::same(200, $response['status']);
    Assert::contains('Mine', $response['body']);
    Assert::contains('Overdue', $response['body']);
    Assert::contains('Due today', $response['body']);
    Assert::contains('Unassigned', $response['body']);
    Assert::contains('All', $response['body']);
    Assert::contains('Task views', $response['body'], 'and the strip is labelled for a screen reader');
});

// ------------------------------------------------------------- creating

Harness::test('creating a task stores it, stamps the author and audits it', function () use (
    $owner,
    $ownerId,
    $gmId
): void {
    // Broken to check: dropping created_by from the insert (the author
    // assertion fails), and removing Audit::record() from store() (the audit
    // count drops to 0).
    $title = tasks_title('Chase the deposit');

    $created = tasks_create($owner, [
        'title'       => $title,
        'description' => 'Ring them before close.',
        'assigned_to' => (string) $gmId,
        'priority'    => 'high',
    ]);

    Assert::greaterThan(0, $created['id'], 'creating a task should redirect to it');

    $task = tasks_row($created['id']);

    Assert::notNull($task);
    Assert::same($title, (string) $task['title']);
    Assert::same('Ring them before close.', (string) $task['description']);
    Assert::same($gmId, (int) $task['assigned_to']);
    Assert::same($ownerId, (int) $task['created_by'], 'who raised it is part of the record');
    Assert::same('high', (string) $task['priority']);
    Assert::same('open', (string) $task['status']);
    Assert::null($task['due_at'], 'a task with no date typed has no deadline');
    Assert::null($task['completed_at']);

    Assert::same(1, tasks_audit_count('task.created', $created['id']));

    $changes = (string) DB::value(
        "SELECT changes FROM {audit_log}
          WHERE action = 'task.created' AND entity_type = 'tasks' AND entity_id = :id",
        ['id' => $created['id']]
    );

    Assert::contains($title, $changes, 'an audit row that does not say what was created is not evidence');

    $detail = $owner->get('/tasks/' . $created['id']);

    Assert::same(200, $detail['status']);
    Assert::contains($title, $detail['body']);
    Assert::contains('Ring them before close.', $detail['body']);
});

Harness::test('a task can be attached to a contact, and the page links to it', function () use ($owner): void {
    // Broken to check: returning null from entityLink() — the page reads "Not
    // attached to a record" and both link assertions fail. A task about a guest
    // that cannot reach the guest is half a feature.
    $contactId = test_make_contact(['first_name' => 'Marla', 'last_name' => 'Linkworth']);

    $created = tasks_create($owner, [
        'title'       => tasks_title('Call the guest back'),
        'entity_type' => 'contact',
        'entity_id'   => (string) $contactId,
    ]);

    $task = tasks_row($created['id']);

    Assert::notNull($task);
    Assert::same('contact', (string) $task['entity_type']);
    Assert::same($contactId, (int) $task['entity_id']);

    $detail = $owner->get('/tasks/' . $created['id']);

    Assert::contains('Marla Linkworth', $detail['body'], 'the linked record is named');
    Assert::contains('/contacts/' . $contactId, $detail['body'], 'and reachable');
});

// ------------------------------------------------------------ refusals

Harness::test('a task with no title is refused and nothing is written', function () use ($owner): void {
    // Broken to check: dropping |required from the title rule — a nameless task
    // is created and the count assertion fails.
    $before = tasks_count();

    $response = $owner->postWithToken('/tasks/new', '/tasks', [
        'title'    => '',
        'priority' => 'normal',
    ], true);

    Assert::same($before, tasks_count(), 'no row may be written');
    Assert::contains('Task is required', $response['body'], 'and the form says why');
});

Harness::test('half a record link is refused, and so is one that names nothing', function () use ($owner): void {
    // Broken to check: returning [$type, $id] from resolveEntity() without the
    // both-or-neither check (the first case is written and the count assertion
    // fails), and removing the entityExists() lookup (the second case is
    // written, pointing at a contact that does not exist).
    $before = tasks_count();

    $halfLink = $owner->postWithToken('/tasks/new', '/tasks', [
        'title'       => tasks_title('Half a link'),
        'entity_type' => 'deal',
    ], true);

    Assert::same($before, tasks_count(), 'a type with no number writes nothing');
    Assert::contains('linked record does not exist', $halfLink['body']);

    $ghost = $owner->postWithToken('/tasks/new', '/tasks', [
        'title'       => tasks_title('Ghost link'),
        'entity_type' => 'contact',
        'entity_id'   => '98765432',
    ], true);

    Assert::same($before, tasks_count(), 'a number that names nothing writes nothing either');
    Assert::contains('linked record does not exist', $ghost['body']);
});

Harness::test('a task cannot be assigned to a deactivated account', function () use ($owner): void {
    // Broken to check: removing the isAssignable() guard from resolveWrite() —
    // the task is parked on somebody who has left and then sits in nobody's
    // list, which is how work quietly disappears at a venue with seasonal staff.
    $leaverId = test_make_user('front_desk', [
        'email'         => 'tasks-leaver-' . bin2hex(random_bytes(4)) . '@example.test',
        'name'          => 'Departed Dale',
        'password_hash' => Auth::hash('leaver-test-passphrase-9'),
    ]);

    DB::update('users', ['is_active' => 0], 'id = :id', ['id' => $leaverId]);

    $before = tasks_count();

    $response = $owner->postWithToken('/tasks/new', '/tasks', [
        'title'       => tasks_title('To a leaver'),
        'assigned_to' => (string) $leaverId,
    ], true);

    Assert::same($before, tasks_count());
    Assert::contains('not an active user', $response['body']);
});

Harness::test('a task POST with no CSRF token is refused', function () use ($owner, $gmId): void {
    // Broken to check: dropping 'csrf' from the route — the task is created and
    // the count assertion fails. A cross-site POST that creates records is the
    // cheapest attack on a logged-in manager.
    $before = tasks_count();

    $response = $owner->post('/tasks', [
        'title'       => tasks_title('No token'),
        'assigned_to' => (string) $gmId,
    ]);

    Assert::same(419, $response['status'], 'the CSRF middleware answers 419');
    Assert::same($before, tasks_count());
});

// ---------------------------------------------------- the permission gate

Harness::test('a role that may see tasks may not raise or close them', function () use (
    $owner,
    $desk,
    $deskId
): void {
    // THE permission test. Front Desk holds view_tasks at 'own' and does NOT
    // hold inbox_assign, which is what guards RAISING and REASSIGNING here.
    //
    // It no longer guards CLOSING. The module first shipped gating complete,
    // reopen and comment on the same permission as raising, so the desk could
    // see the job assigned to them and not tick it off — see
    // TaskController::mayCloseOwn() and the two tests below this one. Finishing
    // your own work is now allowed; finishing somebody else's is not, which is
    // what this test checks.
    //
    // Broken to check: removing can:inbox_assign from the write routes AND the
    // authorize() call in store() — the desk account creates a task, so the 403
    // assertions and the count assertion all fail.
    Assert::same(200, $desk->get('/tasks')['status'], 'the desk may look at its list');
    Assert::same(403, $desk->get('/tasks/new')['status'], 'but not open the form');

    $before = tasks_count();

    // A REAL token, minted from a page this role can open — otherwise the CSRF
    // middleware answers 419 first, the permission gate is never reached, and
    // the test would pass without proving anything about it.
    $response = $desk->postWithToken('/tasks', '/tasks', [
        'title' => tasks_title('Desk attempt'),
    ]);

    Assert::same(403, $response['status'], 'inbox_assign is what guards the write');
    Assert::same($before, tasks_count());

    // It CAN see and close a task of its own — covered by the two tests that
    // follow. What it still cannot do is edit one, because editing is where
    // reassignment lives.
    $mine = tasks_create($owner, [
        'title'       => tasks_title('Desk may look only'),
        'assigned_to' => (string) $deskId,
    ]);

    Assert::same(200, $desk->get('/tasks/' . $mine['id'])['status'], 'it is their own task');
    Assert::same(403, $desk->get('/tasks/' . $mine['id'] . '/edit')['status'], 'but not to edit');

    $edit = $desk->postWithToken('/tasks', '/tasks/' . $mine['id'], [
        'title'       => tasks_title('Desk rewrite'),
        'assigned_to' => (string) $deskId,
    ]);

    Assert::same(403, $edit['status'], 'editing still needs the permission that hands work out');
});

// -------------------------------------------------- complete and reopen

Harness::test('completing a task records who finished it and when', function () use ($gm, $gmId): void {
    // Broken to check: dropping completed_by from complete() (the "who"
    // assertion fails) and dropping completed_at (the "when" assertion fails).
    // Those two columns are the whole evidence that the work happened, and the
    // weekly scorecard is built from them.
    $title   = tasks_title('Restock the prize counter');
    $created = tasks_create($gm, ['title' => $title, 'assigned_to' => (string) $gmId]);

    Assert::same('open', (string) tasks_row($created['id'])['status']);

    $response = $gm->postWithToken(
        '/tasks/' . $created['id'],
        '/tasks/' . $created['id'] . '/complete',
        []
    );

    Assert::same(302, $response['status']);

    $task = tasks_row($created['id']);

    Assert::notNull($task);
    Assert::same('done', (string) $task['status']);
    Assert::notEmpty($task['completed_at'], 'when it was finished');
    Assert::same($gmId, (int) $task['completed_by'], 'and by whom');
    Assert::same(1, tasks_audit_count('task.completed', $created['id']));

    $detail = $gm->get('/tasks/' . $created['id']);

    Assert::contains('General Manager Gail', $detail['body'], 'the page names the person who closed it');
    Assert::contains('Reopen', $detail['body'], 'and offers to reopen it');
});

Harness::test('completing an already-done task is refused, not re-stamped', function () use ($gm, $gmId): void {
    // Broken to check: removing the already-done guard from complete() — the
    // second submit rewrites completed_at and completed_by, so the record of
    // who actually finished the work is lost to whoever clicked last. This is
    // the one thing the route exists to record.
    $created = tasks_create($gm, [
        'title'       => tasks_title('Double click'),
        'assigned_to' => (string) $gmId,
    ]);

    $gm->postWithToken('/tasks/' . $created['id'], '/tasks/' . $created['id'] . '/complete', []);

    $first = (string) tasks_row($created['id'])['completed_at'];

    Assert::notEmpty($first);

    // Move the stamp back an hour so a re-stamp would be visible even inside
    // the same second.
    DB::update(
        'tasks',
        ['completed_at' => gmdate('Y-m-d H:i:s', time() - 3600)],
        'id = :id',
        ['id' => $created['id']]
    );

    $was = (string) tasks_row($created['id'])['completed_at'];

    $response = $gm->postWithToken(
        '/tasks/' . $created['id'],
        '/tasks/' . $created['id'] . '/complete',
        [],
        true
    );

    Assert::contains('already done', $response['body']);
    Assert::same($was, (string) tasks_row($created['id'])['completed_at'], 'the first stamp stands');
});

Harness::test('reopening a task clears the completion record', function () use ($gm, $gmId): void {
    // Broken to check: leaving completed_at/completed_by in place in reopen() —
    // the task reads as open while still naming somebody who finished it, so
    // every report that reads those columns disagrees with the status beside
    // them.
    $created = tasks_create($gm, [
        'title'       => tasks_title('Back to the list'),
        'assigned_to' => (string) $gmId,
    ]);

    $gm->postWithToken('/tasks/' . $created['id'], '/tasks/' . $created['id'] . '/complete', []);

    Assert::same('done', (string) tasks_row($created['id'])['status']);

    $response = $gm->postWithToken(
        '/tasks/' . $created['id'],
        '/tasks/' . $created['id'] . '/reopen',
        []
    );

    Assert::same(302, $response['status']);

    $task = tasks_row($created['id']);

    Assert::notNull($task);
    Assert::same('open', (string) $task['status']);
    Assert::null($task['completed_at'], 'nobody has finished it now');
    Assert::null($task['completed_by']);
    Assert::same(1, tasks_audit_count('task.reopened', $created['id']));
});

Harness::test('reopening a task that is already open is refused', function () use ($gm, $gmId): void {
    // Broken to check: removing the status guard from reopen() — an open task
    // is "reopened", which writes an audit row claiming something happened that
    // did not, so the audit assertion fails.
    $created = tasks_create($gm, [
        'title'       => tasks_title('Already open'),
        'assigned_to' => (string) $gmId,
    ]);

    $response = $gm->postWithToken(
        '/tasks/' . $created['id'],
        '/tasks/' . $created['id'] . '/reopen',
        [],
        true
    );

    Assert::contains('already open', $response['body']);
    Assert::same(0, tasks_audit_count('task.reopened', $created['id']), 'nothing happened, so nothing is logged');
    Assert::same('open', (string) tasks_row($created['id'])['status']);
});

Harness::test('marking a task done on the edit form stamps it too', function () use ($gm, $gmId): void {
    // The status field and the Done button are two ways to the same place, and
    // the stamps have to be kept in step down both. Broken to check: removing
    // the completionColumns() merge from update() — the task reads done with
    // nobody's name against it, and reopening it on the form leaves a stale
    // completion time.
    $created = tasks_create($gm, [
        'title'       => tasks_title('Done on the form'),
        'assigned_to' => (string) $gmId,
    ]);

    $gm->postWithToken('/tasks/' . $created['id'] . '/edit', '/tasks/' . $created['id'], [
        'title'       => 'Done on the form, edited',
        'assigned_to' => (string) $gmId,
        'status'      => 'done',
        'priority'    => 'normal',
    ], true);

    $task = tasks_row($created['id']);

    Assert::notNull($task);
    Assert::same('done', (string) $task['status']);
    Assert::notEmpty($task['completed_at']);
    Assert::same($gmId, (int) $task['completed_by']);
    Assert::same(1, tasks_audit_count('task.updated', $created['id']));

    // And back the other way.
    $gm->postWithToken('/tasks/' . $created['id'] . '/edit', '/tasks/' . $created['id'], [
        'title'       => 'Done on the form, edited',
        'assigned_to' => (string) $gmId,
        'status'      => 'in_progress',
        'priority'    => 'normal',
    ], true);

    $task = tasks_row($created['id']);

    Assert::same('in_progress', (string) $task['status']);
    Assert::null($task['completed_at'], 'a task in progress has not been finished by anybody');
    Assert::null($task['completed_by']);
});

// ------------------------------------------------------------ comments

Harness::test('a comment is stored against the task and shown on it', function () use ($gm, $gmId): void {
    // Broken to check: making storeComment() a no-op (the row count is 0), and
    // returning [] from comments() (the body never appears on the page).
    $created = tasks_create($gm, [
        'title'       => tasks_title('Talk it over'),
        'assigned_to' => (string) $gmId,
    ]);

    $body = 'Parents want the 2pm slot instead, waiting on the lane.';

    $response = $gm->postWithToken(
        '/tasks/' . $created['id'],
        '/tasks/' . $created['id'] . '/comments',
        ['body' => $body],
        true
    );

    Assert::same(200, $response['status']);

    $comments = DB::all('SELECT * FROM {task_comments} WHERE task_id = :id', ['id' => $created['id']]);

    Assert::count(1, $comments);
    Assert::same($body, (string) $comments[0]['body']);
    Assert::same($gmId, (int) $comments[0]['user_id'], 'and who said it');

    $detail = $gm->get('/tasks/' . $created['id']);

    Assert::contains($body, $detail['body']);
    Assert::contains('General Manager Gail', $detail['body']);

    // The comment body is deliberately NOT copied into the audit diff: it would
    // make the log a second, less guarded copy of what staff wrote about a
    // colleague or a guest. Broken to check: passing the body through to
    // Audit::record() — this fails.
    $logged = (string) DB::value(
        "SELECT changes FROM {audit_log}
          WHERE action = 'task_comment.created' AND entity_id = :id",
        ['id' => (int) $comments[0]['id']]
    );

    Assert::notEmpty($logged, 'the write itself is still recorded');
    Assert::notContains($body, $logged, 'but not what was written');
});

Harness::test('an empty comment is refused and nothing is written', function () use ($gm, $gmId): void {
    // Broken to check: dropping |required from the body rule — a blank comment
    // row is written and the count assertion fails.
    $created = tasks_create($gm, [
        'title'       => tasks_title('No empty comments'),
        'assigned_to' => (string) $gmId,
    ]);

    $response = $gm->postWithToken(
        '/tasks/' . $created['id'],
        '/tasks/' . $created['id'] . '/comments',
        ['body' => ''],
        true
    );

    Assert::same(
        0,
        (int) DB::value('SELECT COUNT(*) FROM {task_comments} WHERE task_id = :id', ['id' => $created['id']]),
        'no comment row may be written'
    );
    Assert::contains('Comment is required', $response['body']);
});

// ------------------------------------------------------------- the views

Harness::test('mine, unassigned and the assignee filter select the right tasks', function () use (
    $gm,
    $gmId,
    $cleanerId
): void {
    // Broken to check: dropping the 'mine' case from taskFilter() (the view
    // falls back to everything and the notContains assertion fails), and
    // dropping the 'unassigned' case (an assigned task shows up as unassigned).
    $mine       = tasks_title('Mine to do');
    $theirs     = tasks_title('Somebody else to do');
    $nobodys    = tasks_title('Nobody has this yet');

    tasks_create($gm, ['title' => $mine, 'assigned_to' => (string) $gmId]);
    tasks_create($gm, ['title' => $theirs, 'assigned_to' => (string) $cleanerId]);
    tasks_create($gm, ['title' => $nobodys]);

    $mineView = $gm->get('/tasks?view=mine');

    Assert::same(200, $mineView['status']);
    Assert::contains($mine, $mineView['body'], 'my own task is there');
    Assert::notContains($theirs, $mineView['body'], 'somebody else\'s is not');
    Assert::notContains($nobodys, $mineView['body'], 'nor is an unassigned one');

    $unassigned = $gm->get('/tasks?view=unassigned');

    Assert::contains($nobodys, $unassigned['body']);
    Assert::notContains($mine, $unassigned['body']);

    $filtered = $gm->get('/tasks?view=all&assignee=' . $cleanerId);

    Assert::contains($theirs, $filtered['body'], 'the assignee filter narrows to that person');
    Assert::notContains($mine, $filtered['body']);
});

Harness::test('a completed task leaves the working views but stays findable', function () use ($gm): void {
    // Broken to check: dropping the live-status default from taskFilter() —
    // done work stays in the Unassigned view for ever and the page stops
    // answering "what still needs doing".
    $title = tasks_title('Will be finished');

    $created = tasks_create($gm, ['title' => $title]);

    Assert::contains($title, $gm->get('/tasks?view=unassigned')['body'], 'it starts out outstanding');

    $gm->postWithToken(
        '/tasks/' . $created['id'],
        '/tasks/' . $created['id'] . '/complete',
        [],
        true
    );

    Assert::notContains(
        $title,
        $gm->get('/tasks?view=unassigned')['body'],
        'done work is not outstanding work'
    );

    // The explicit status filter is what reaches it again — the view's default
    // is a default, not a cage.
    Assert::contains(
        $title,
        $gm->get('/tasks?view=unassigned&status=done')['body'],
        'asking for done tasks finds it'
    );
});

Harness::test('overdue work sorts above everything else, done work does not', function () use (
    $owner,
    $cleanerId,
    $venueZone
): void {
    // Overdue first is the whole reason to open this page mid-shift.
    //
    // The DONE task is what makes this test bite. It is the oldest due date, so
    // ordering by due date alone puts it at the top — broken to check: removing
    // the status-aware CASE from taskRows()' ORDER BY, which does exactly that
    // and fails the first assertion. A finished task from last week is not the
    // most urgent thing on the floor.
    $local = tasks_local_now($venueZone);

    tasks_with_timezone($venueZone, function () use ($owner, $cleanerId, $local): void {
        $late   = tasks_title('Late and open');
        $closed = tasks_title('Old but finished');
        $soon   = tasks_title('Due tomorrow');

        tasks_create($owner, [
            'title'       => $closed,
            'assigned_to' => (string) $cleanerId,
            'due_date'    => $local->modify('-3 days')->format('Y-m-d'),
            'due_time'    => '09:00',
            'status'      => 'done',
        ]);

        tasks_create($owner, [
            'title'       => $late,
            'assigned_to' => (string) $cleanerId,
            'due_date'    => $local->modify('-1 day')->format('Y-m-d'),
            'due_time'    => '09:00',
        ]);

        tasks_create($owner, [
            'title'       => $soon,
            'assigned_to' => (string) $cleanerId,
            'due_date'    => $local->modify('+1 day')->format('Y-m-d'),
            'due_time'    => '09:00',
        ]);

        $body = $owner->get('/tasks?view=all&assignee=' . $cleanerId)['body'];

        $positions = [];

        foreach (['late' => $late, 'closed' => $closed, 'soon' => $soon] as $key => $title) {
            $at = strpos($body, $title);

            Assert::notSame(false, $at, $key . ' should be listed');
            $positions[$key] = (int) $at;
        }

        Assert::true(
            $positions['late'] < $positions['closed'],
            'the overdue open task comes before the older finished one'
        );
        Assert::true(
            $positions['closed'] < $positions['soon'],
            'and the rest fall in due-date order'
        );
    });
});

// --------------------------------------------------------------- scoping

Harness::test('an owner-scoped role sees only the tasks assigned to it', function () use (
    $owner,
    $desk,
    $deskId,
    $gmId
): void {
    // THE scoping test. Broken to check: replacing Acl::scopeCondition() with
    // '1 = 1' in taskFilter() — the desk's list shows the manager's task and
    // the notContains assertion fails. Front Desk holds view_tasks at 'own'.
    $theirs = tasks_title('Desk task');
    $other  = tasks_title('Manager task');

    $deskTask = tasks_create($owner, [
        'title'       => $theirs,
        'assigned_to' => (string) $deskId,
    ]);

    $gmTask = tasks_create($owner, [
        'title'       => $other,
        'assigned_to' => (string) $gmId,
    ]);

    $list = $desk->get('/tasks?view=all');

    Assert::same(200, $list['status']);
    Assert::contains($theirs, $list['body'], 'its own task is there');
    Assert::notContains($other, $list['body'], 'somebody else\'s is not');

    // Broken to check: passing '1 = 1' to findTask() — the desk opens the
    // manager's task (200 instead of 404).
    Assert::same(200, $desk->get('/tasks/' . $deskTask['id'])['status']);
    Assert::same(
        404,
        $desk->get('/tasks/' . $gmTask['id'])['status'],
        'out of scope is indistinguishable from absent'
    );
});

// ======================================================================
// Timezones — the venue's day, not the server's
// ======================================================================

Harness::test('a due date typed in venue time is stored as the right UTC instant', function () use ($owner): void {
    // THE storage test, and the one that is wrong every evening in production
    // if it is missing.
    //
    // 11pm on a June evening at The Castle is 03:00 UTC on the FOLLOWING day:
    // the venue-local date and the stored UTC date are not the same date. Store
    // the typed string as UTC instead and the task is already four hours in the
    // past before anybody looks at it.
    //
    // Both a summer and a winter date are checked, so the conversion has to be
    // zone-aware rather than a fixed offset: America/New_York is UTC-4 in June
    // and UTC-5 in January.
    //
    // Broken to check: using new DateTimeZone('UTC') in place of venue_tz() in
    // localToUtc() — both assertions fail, each by the zone's offset.
    tasks_with_timezone('America/New_York', function () use ($owner): void {
        $summer = tasks_create($owner, [
            'title'    => tasks_title('Late on a summer night'),
            'due_date' => '2030-06-15',
            'due_time' => '23:00',
        ]);

        Assert::same(
            '2030-06-16 03:00:00',
            (string) tasks_row($summer['id'])['due_at'],
            '11pm on June 15th in New York is 03:00 UTC on the 16th'
        );

        $winter = tasks_create($owner, [
            'title'    => tasks_title('Late on a winter night'),
            'due_date' => '2030-01-15',
            'due_time' => '23:00',
        ]);

        Assert::same(
            '2030-01-16 04:00:00',
            (string) tasks_row($winter['id'])['due_at'],
            'and 04:00 UTC in January, because the offset is not a constant'
        );

        // A date with no time means "by the end of the venue's day". Storing
        // midnight instead would make every task due today read as overdue from
        // one minute past twelve. Broken to check: defaulting
        // DEFAULT_DUE_TIME to '00:00'.
        $dateOnly = tasks_create($owner, [
            'title'    => tasks_title('Some time that day'),
            'due_date' => '2030-06-15',
        ]);

        Assert::same(
            '2030-06-16 03:59:00',
            (string) tasks_row($dateOnly['id'])['due_at'],
            'end of the venue day, not the start of it'
        );
    });
});

Harness::test('the edit form reads back the venue-local time that was typed', function () use ($owner): void {
    // The other half of the conversion. Broken to check: dropping
    // setTimezone(venue_tz()) from localParts() — the form offers 03:00 on the
    // 16th, so simply opening a task and saving it moves its deadline by four
    // hours.
    tasks_with_timezone('America/New_York', function () use ($owner): void {
        $created = tasks_create($owner, [
            'title'    => tasks_title('Round trip'),
            'due_date' => '2030-06-15',
            'due_time' => '23:00',
        ]);

        $form = $owner->get('/tasks/' . $created['id'] . '/edit');

        Assert::same(200, $form['status']);
        Assert::contains('name="due_date" value="2030-06-15"', $form['body'], 'the date as typed');
        Assert::contains('name="due_time" value="23:00"', $form['body'], 'and the time as typed');

        // Saving it unchanged must not move it.
        $owner->postWithToken('/tasks/' . $created['id'] . '/edit', '/tasks/' . $created['id'], [
            'title'    => 'Round trip, saved again',
            'due_date' => '2030-06-15',
            'due_time' => '23:00',
            'priority' => 'normal',
            'status'   => 'open',
        ], true);

        Assert::same('2030-06-16 03:00:00', (string) tasks_row($created['id'])['due_at']);
    });
});

Harness::test('a task due at 11pm venue time is not overdue earlier that evening', function () use (
    $owner,
    $cleanerId,
    $venueZone
): void {
    // The behaviour the operator asked for, stated as plainly as it can be:
    // at 6pm, something due at 11pm tonight is not late.
    //
    // The venue timezone is chosen so its clock currently reads between 02:00
    // and 21:00 and its offset from UTC is at least two hours — so "23:00
    // today" is genuinely in the future, "00:30 today" is genuinely in the
    // past, and the two are on DIFFERENT UTC dates from each other. That last
    // part is what a UTC-only suite cannot see.
    //
    // Broken to check: comparing days instead of instants in the overdue case
    // of taskFilter() (DATE(t.due_at) <= CURDATE() puts tonight's task in the
    // overdue list), and reading the typed time as UTC in localToUtc() (the
    // stored-instant assertion fails outright).
    $today = tasks_local_now($venueZone)->format('Y-m-d');

    tasks_with_timezone($venueZone, function () use ($owner, $cleanerId, $venueZone, $today): void {
        $tonight = tasks_title('Due at eleven tonight');
        $earlier = tasks_title('Was due at half past midnight');

        $late = tasks_create($owner, [
            'title'       => $tonight,
            'assigned_to' => (string) $cleanerId,
            'due_date'    => $today,
            'due_time'    => '23:00',
        ]);

        $early = tasks_create($owner, [
            'title'       => $earlier,
            'assigned_to' => (string) $cleanerId,
            'due_date'    => $today,
            'due_time'    => '00:30',
        ]);

        Assert::greaterThan(0, $late['id'], 'the late task should have been created');
        Assert::greaterThan(0, $early['id']);

        // The stored instants are the venue's wall clock converted, nothing else.
        Assert::same(
            tasks_expected_utc($venueZone, $today, '23:00'),
            (string) tasks_row($late['id'])['due_at'],
            '23:00 at the venue, in UTC'
        );
        Assert::same(
            tasks_expected_utc($venueZone, $today, '00:30'),
            (string) tasks_row($early['id'])['due_at']
        );

        $overdue = $owner->get('/tasks?view=overdue&assignee=' . $cleanerId);

        Assert::same(200, $overdue['status']);
        Assert::notContains($tonight, $overdue['body'], 'later today is not late');
        Assert::contains($earlier, $overdue['body'], 'earlier today is');

        // And the task's own page agrees with the list it was drawn from.
        Assert::notContains(
            'Overdue',
            $owner->get('/tasks/' . $late['id'])['body'],
            'no overdue badge on a task that is not'
        );
        Assert::contains('Overdue', $owner->get('/tasks/' . $early['id'])['body']);
    });
});

Harness::test('due today is the venue\'s day at both ends, not UTC\'s', function () use (
    $owner,
    $cleanerId,
    $venueZone
): void {
    // The companion to the test above, and the one that catches a day boundary
    // computed in the wrong zone.
    //
    // The venue's local day and the UTC day are two different 24-hour windows
    // whenever the offset is not zero, and the chosen zone guarantees at least
    // two hours of difference. So one of these two tasks — 00:30 and 23:00 on
    // the venue's today — necessarily falls OUTSIDE the UTC calendar day the
    // request happens in, whichever hour the suite runs at. Both must still be
    // "due today", because both are due today at the venue.
    //
    // Broken to check: computing the window with gmdate('Y-m-d') bounds instead
    // of venueDayBounds() — MEASURED, one of the two tasks drops out of the
    // view and this fails. Also broken: using venueDayBounds() but adding
    // '+1 day' to the UTC instant rather than to the local date, which gets
    // the DST days wrong by an hour.
    $local = tasks_local_now($venueZone);
    $today = $local->format('Y-m-d');

    tasks_with_timezone($venueZone, function () use ($owner, $cleanerId, $today, $local): void {
        $first = tasks_title('First thing today');
        $last  = tasks_title('Last thing today');
        $next  = tasks_title('First thing tomorrow');

        tasks_create($owner, [
            'title'       => $first,
            'assigned_to' => (string) $cleanerId,
            'due_date'    => $today,
            'due_time'    => '00:30',
        ]);

        tasks_create($owner, [
            'title'       => $last,
            'assigned_to' => (string) $cleanerId,
            'due_date'    => $today,
            'due_time'    => '23:00',
        ]);

        tasks_create($owner, [
            'title'       => $next,
            'assigned_to' => (string) $cleanerId,
            'due_date'    => $local->modify('+1 day')->format('Y-m-d'),
            'due_time'    => '00:30',
        ]);

        $view = $owner->get('/tasks?view=today&assignee=' . $cleanerId);

        Assert::same(200, $view['status']);
        Assert::contains($first, $view['body'], 'half past midnight is today at the venue');
        Assert::contains($last, $view['body'], 'and so is eleven at night');
        Assert::notContains($next, $view['body'], 'tomorrow is not');
    });
});

Harness::test('the Due today count on the tab strip counts the venue\'s day, scoped', function () use (
    $baseUrl,
    $owner,
    $venueZone
): void {
    // The counts are a second, separate reading of the same window, and a page
    // whose tab says 2 while its list shows 1 is worse than either being wrong
    // on its own. This pins them to each other.
    //
    // A brand-new own-scoped account is the actor, so the expected figure is
    // known EXACTLY rather than being whatever the rest of the suite happened
    // to leave behind. That makes the assertion do double duty: by this point
    // the venue has several other tasks due today, so an unscoped count could
    // not read 2 either.
    //
    // Broken to check: using gmdate() bounds in viewCounts() while leaving
    // taskFilter() correct (the strip and the list disagree — one of the two
    // tasks is not counted), and dropping Acl::scopeCondition() from
    // viewCounts() (the figure jumps past 2).
    $today = tasks_local_now($venueZone)->format('Y-m-d');

    $email = 'tasks-counter-' . bin2hex(random_bytes(4)) . '@example.test';
    $id    = test_make_user('front_desk', [
        'email'         => $email,
        'name'          => 'Counting Cora',
        'password_hash' => Auth::hash('counter-test-passphrase-9'),
    ]);

    $counter = new TestBrowser($baseUrl, 'tasks-counter');
    $counter->login($email, 'counter-test-passphrase-9');

    Assert::same(200, $counter->get('/account')['status'], 'the counting account should be signed in');

    tasks_with_timezone($venueZone, function () use ($counter, $owner, $id, $today): void {
        foreach (['00:30', '23:00'] as $time) {
            // A task with no due date as well, so the Due today figure cannot
            // be passing by counting everything assigned to this person.
            tasks_create($owner, [
                'title'       => tasks_title('Floor work at ' . $time),
                'assigned_to' => (string) $id,
                'due_date'    => $today,
                'due_time'    => $time,
            ]);
        }

        tasks_create($owner, [
            'title'       => tasks_title('Floor work with no deadline'),
            'assigned_to' => (string) $id,
        ]);

        $body = $counter->get('/tasks?view=today')['body'];

        // The strip's Due today figure, read out of the markup rather than
        // trusted: the anchor carries the label and then its badge.
        $matched = preg_match(
            '#Due today\s*<span class="badge[^"]*">\s*([\d,]+)\s*</span>#s',
            $body,
            $match
        );

        Assert::same(1, $matched, 'the Due today tab should carry a count');
        Assert::same(
            2,
            (int) str_replace(',', '', $match[1]),
            'both of today\'s tasks are counted, in the venue\'s day, and nothing else is'
        );

        // The All tab counts every live task of theirs, deadline or not, which
        // is how we know the 2 above is a window and not just the scope.
        $allMatched = preg_match(
            '#>\s*All\s*<span class="badge[^"]*">\s*([\d,]+)\s*</span>#s',
            $body,
            $allMatch
        );

        Assert::same(1, $allMatched, 'the All tab should carry a count too');
        Assert::same(3, (int) str_replace(',', '', $allMatch[1]), 'three live tasks, two of them due today');
    });
});

// ------------------------------------------- the assignee may finish their own

Harness::test('the person a task is assigned to can complete it without the write permission', function () use ($gm, $desk, $deskId): void {
    // The module as first built gated complete/reopen/comment on the same
    // permission as RAISING a task, so a Front Desk user could see the job
    // assigned to them and not tick it off. A task board where the assignee
    // cannot close their own work is not a task board, and the spec section
    // this implements is called "Tasks & team accountability" — which runs
    // through the person doing the work.
    //
    // Raising and reassigning stay privileged. This is only about finishing
    // what you were already given.
    $created = tasks_create($gm, [
        'title'       => tasks_title('desk closes own'),
        'assigned_to' => (string) $deskId,
    ]);

    Assert::notNull($created['id'], 'precondition: the manager raised a task for the desk');

    $response = $desk->postWithToken(
        '/tasks/' . $created['id'],
        '/tasks/' . $created['id'] . '/complete',
        [],
        true
    );

    Assert::same(200, $response['status']);

    $row = tasks_row($created['id']);

    Assert::notNull($row);
    Assert::same('done', (string) $row['status'], 'the assignee must be able to finish their own work');
    Assert::same($deskId, (int) $row['completed_by'], 'and it is recorded as theirs');
    Assert::notNull($row['completed_at']);
});

Harness::test('a role that sees every task still cannot close somebody else\'s', function () use ($gm, $gmId, $baseUrl): void {
    // mayCloseOwn()'s assignee check is unreachable through the SEEDED roles,
    // which is why the obvious version of this test proved nothing. The two
    // roles without inbox_assign (Front Desk, Party Host) hold view_tasks at
    // 'own', so they can only ever SEE their own work — a task belonging to
    // somebody else is invisible, the scope refuses first, and the check never
    // runs. MEASURED: written against the desk account, making mayCloseOwn()
    // return true unconditionally still passed.
    //
    // Roles are DATA in this app, editable in Settings, so a venue can
    // legitimately make a watcher who sees everything and changes nothing.
    // That is the case where this check is the only thing standing between a
    // viewer and closing other people's work, so that is what this covers.
    $roleId = (int) DB::insert('roles', [
        'slug'        => 'tasks-watcher-test',
        'name'        => 'Task watcher (test)',
        'description' => 'Sees every task, holds no write permission.',
        'is_system'   => 0,
        'sort_order'  => 900,
        'created_at'  => now_utc(),
        'updated_at'  => now_utc(),
    ]);

    $viewTasks = (int) DB::value("SELECT id FROM {permissions} WHERE slug = 'view_tasks'");

    Assert::true($viewTasks > 0, 'precondition: view_tasks exists');

    DB::insert('role_permissions', [
        'role_id'       => $roleId,
        'permission_id' => $viewTasks,
        'scope'         => 'all',
    ]);

    $watcherId = test_make_user('tasks-watcher-test', [
        'email'         => 'tasks-watcher@example.test',
        'password_hash' => Auth::hash('watcher-test-passphrase-9'),
    ]);

    try {
        $watcher = new TestBrowser($baseUrl, 'tasks-watcher');
        $watcher->login('tasks-watcher@example.test', 'watcher-test-passphrase-9');

        $created = tasks_create($gm, [
            'title'       => tasks_title('watcher may not close'),
            'assigned_to' => (string) $gmId,
        ]);

        Assert::notNull($created['id']);

        // The precondition the desk account could not give us: this role really
        // can SEE a task that is not its own.
        Assert::same(
            200,
            $watcher->get('/tasks/' . $created['id'])['status'],
            'precondition: an all-scoped role can see somebody else\'s task'
        );

        $watcher->postWithToken(
            '/tasks/' . $created['id'],
            '/tasks/' . $created['id'] . '/complete',
            [],
            true
        );

        Assert::notSame(
            'done',
            (string) tasks_row($created['id'])['status'],
            'seeing every task is not permission to close other people\'s work'
        );
    } finally {
        DB::delete('users', 'id = :id', ['id' => $watcherId]);
        DB::delete('role_permissions', 'role_id = :r', ['r' => $roleId]);
        DB::delete('roles', 'id = :r', ['r' => $roleId]);
    }
});
