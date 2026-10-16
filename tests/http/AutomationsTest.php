<?php

declare(strict_types=1);

/**
 * The automation builder over HTTP.
 *
 * The engine's own behaviour is pinned by `integration/automation` and
 * `unit/automation-engine`. What this file is about is the SCREEN: a venue
 * switches a sequence on here, and from then on it messages real families
 * days later with nobody watching. So the tests are mostly about the things
 * the page refuses to do.
 *
 *   1. **AN AUTOMATION THAT CANNOT WORK CANNOT BE SWITCHED ON.** No steps, a
 *      trigger this version cannot deliver, a switched-off playbook — each is
 *      refused with the reason, because each one otherwise reads as "On" and
 *      does nothing at all. That is the failure a venue cannot see: no error,
 *      no message, no sign anywhere that the sequence they are relying on is
 *      inert.
 *   2. **THE KILL SWITCH STOPS EVERYTHING, AND SAYS SO EVERYWHERE.** Spec
 *      §9.5 wants it two clicks from every page; the only reason anybody
 *      reaches for it is that something is going out that should not be.
 *   3. **EDITING AND ACTIVATING ARE DIFFERENT PERMISSIONS.** Writing a
 *      sequence is a different act from starting it, and both are tested
 *      against a role that holds only the view key.
 *   4. **THE PREVIEW SENDS NOTHING.** It is the one thing standing between a
 *      venue and finding out what a sequence does by doing it to a guest.
 */

use App\Core\DB;
use App\Core\Settings;
use Tests\Assert;
use Tests\Harness;

Harness::suite('http/automations');

$baseUrl = getenv('CC_TEST_BASE_URL') ?: '';

if ($baseUrl === '') {
    Harness::skip('automations http suite', 'no server; run via tests/run.php');

    return;
}

if (!test_boot()) {
    Harness::skip('automations http suite', 'no database available');

    return;
}

require_once CC_ROOT . '/tests/TestBrowser.php';

// ------------------------------------------------------------------ fixtures

function ah_clear(): void
{
    DB::query(
        "DELETE FROM {automation_step_log} WHERE run_id IN (
            SELECT id FROM {automation_runs} WHERE automation_id IN (
                SELECT id FROM {automations} WHERE slug LIKE 'ahttp-%'))"
    );
    DB::query(
        "DELETE FROM {automation_runs} WHERE automation_id IN (
            SELECT id FROM {automations} WHERE slug LIKE 'ahttp-%')"
    );
    DB::query(
        "DELETE FROM {automation_steps} WHERE automation_id IN (
            SELECT id FROM {automations} WHERE slug LIKE 'ahttp-%')"
    );
    DB::query("DELETE FROM {automations} WHERE slug LIKE 'ahttp-%'");
    DB::query("DELETE FROM {playbooks} WHERE slug LIKE 'ahttp-%'");
    DB::query("DELETE FROM {templates} WHERE slug LIKE 'ahttp-%'");
    DB::query("DELETE FROM {contacts} WHERE source = 'ahttp'");
    DB::query("DELETE FROM {tags} WHERE name LIKE 'ahttp-%'");
}

/** @param array<string,mixed> $overrides */
function ah_automation(string $slug, string $trigger = 'visit.recorded', array $overrides = []): int
{
    return (int) DB::insert('automations', $overrides + [
        'name'                 => 'AHttp ' . $slug,
        'slug'                 => $slug,
        'description'          => 'Built by the automations http suite.',
        'trigger_type'         => $trigger,
        'is_active'            => 0,
        'run_once_per_contact' => 1,
        'created_at'           => now_utc(),
        'updated_at'           => now_utc(),
    ]);
}

function ah_template(string $slug): int
{
    return (int) DB::insert('templates', [
        'name'       => 'AHttp ' . $slug,
        'slug'       => $slug,
        'channel'    => 'email',
        'subject'    => 'Hello {{first_name}}',
        'body'       => '<p>Hello {{first_name}}.</p>',
        'is_active'  => 1,
        'created_at' => now_utc(),
        'updated_at' => now_utc(),
    ]);
}

function ah_contact(string $name): int
{
    return (int) DB::insert('contacts', [
        'first_name'      => $name,
        'last_name'       => 'AHttp',
        'email'           => 'ahttp.' . strtolower($name) . '.' . bin2hex(random_bytes(3)) . '@example.test',
        'email_consent'   => 1,
        'source'          => 'ahttp',
        'lifecycle_stage' => 'lead',
        'created_at'      => now_utc(),
        'updated_at'      => now_utc(),
    ]);
}

/** @return list<array<string,mixed>> */
function ah_steps(int $automationId): array
{
    return DB::all(
        'SELECT * FROM {automation_steps} WHERE automation_id = :a ORDER BY sort_order, id',
        ['a' => $automationId]
    );
}

function ah_active(int $automationId): int
{
    return (int) DB::value('SELECT is_active FROM {automations} WHERE id = :id', ['id' => $automationId]);
}

/** The flash message a redirect left behind, read off the page it lands on. */
function ah_flash(TestBrowser $browser, string $path): string
{
    $body = (string) ($browser->get($path)['body'] ?? '');

    if (preg_match('/<div class="alert alert-[a-z]+">(.*?)<\/div>/s', $body, $m) !== 1) {
        return '';
    }

    return trim(preg_replace('/\s+/', ' ', strip_tags($m[1])) ?? '');
}

ah_clear();

$pausedBefore = Settings::get('system.automations_paused');

$restore = static function () use ($pausedBefore): void {
    // Settings are global and the suites share one database: a kill switch
    // left on would make every later automation test read as "nothing ran".
    if ($pausedBefore === null) {
        Settings::forget('system.automations_paused');
    } else {
        Settings::set('system.automations_paused', $pausedBefore);
    }
};

$owner = new TestBrowser($baseUrl, 'automations-owner');
$owner->login('owner@test.invalid', 'owner-test-passphrase-9');

$desk = new TestBrowser($baseUrl, 'automations-desk');
$desk->login('desk@test.invalid', 'desk-test-passphrase-9');

// ------------------------------------------------------------------- reading

Harness::test('the list shows every automation, with what starts it', function () use ($owner): void {
    ah_clear();
    $id = ah_automation('ahttp-list');

    $page = $owner->get('/automations');

    Assert::same(200, $page['status']);
    Assert::contains('AHttp ahttp-list', (string) $page['body']);
    Assert::contains('A visit is recorded', (string) $page['body'], 'the trigger in words, not as an event name');
    Assert::contains('Stop all automations', (string) $page['body'], 'the kill switch is on the page');

    ah_clear();
});

Harness::test('an automation waiting on an unbuilt feature says what it waits for', function () use ($owner): void {
    // `review.received` is a real moment a venue will want. The reputation
    // module is what will fire it, and until then the page says so rather
    // than offering a switch that does nothing.
    ah_clear();
    $id = ah_automation('ahttp-pending', 'review.received');

    $page = $owner->get('/automations/' . $id);

    Assert::same(200, $page['status']);
    Assert::contains('reputation module', (string) $page['body']);
    Assert::contains('would do nothing at all', (string) $page['body']);

    ah_clear();
});

Harness::test('the front desk cannot see the automations at all', function () use ($desk): void {
    ah_clear();
    $id = ah_automation('ahttp-hidden');

    Assert::same(403, $desk->get('/automations')['status']);
    Assert::same(403, $desk->get('/automations/' . $id)['status']);

    ah_clear();
});

// ---------------------------------------------------------------- the steps

Harness::test('a step is added, read back, and described in plain words', function () use ($owner): void {
    ah_clear();
    ah_template('ahttp-welcome');
    $id = ah_automation('ahttp-steps');

    $owner->postWithToken('/automations/' . $id, '/automations/' . $id . '/steps', [
        'type'            => 'send_email',
        'config_template' => 'ahttp-welcome',
        'delay_minutes'   => '1440',
        'branch'          => 'main',
    ]);

    $steps = ah_steps($id);

    Assert::count(1, $steps);
    Assert::same('send_email', (string) $steps[0]['type']);
    Assert::same(1440, (int) $steps[0]['delay_minutes']);

    $config = json_decode((string) $steps[0]['config'], true);
    Assert::same('ahttp-welcome', $config['template'] ?? null);

    // And the page says what it will do, in words a venue manager can check.
    $body = (string) $owner->get('/automations/' . $id)['body'];
    Assert::contains('After 1 day', $body);
    Assert::contains('ahttp-welcome', $body);

    ah_clear();
});

Harness::test('A CONDITION IS SAVED IN THE SHAPE THE ENGINE READS', function () use ($owner): void {
    /*
     * The defect this pins, found by driving the builder: the rule was saved
     * as a bare LIST with an `operator` key, while `branchFor()` hands
     * `config.rules` to `evaluate()` — which reads `match` and `rules` off it
     * — and `match()` reads `op`. Every layer accepted it, the step list read
     * "check whether nothing in particular", and every guest went down the NO
     * side. A condition that silently always answers no is worse than no
     * condition at all, because half the sequence is unreachable and nothing
     * says so.
     */
    ah_clear();
    $id = ah_automation('ahttp-condition');

    $owner->postWithToken('/automations/' . $id, '/automations/' . $id . '/steps', [
        'type'          => 'condition',
        'rule_field'    => 'lifecycle_stage',
        'rule_operator' => 'is',
        'rule_value'    => 'customer',
    ]);

    $steps = ah_steps($id);
    Assert::count(1, $steps);

    $config = json_decode((string) $steps[0]['config'], true);
    $group  = $config['rules'] ?? null;

    Assert::true(is_array($group), 'the rules are stored');
    Assert::same('all', $group['match'] ?? null, 'as a GROUP, with its match mode');
    Assert::same('lifecycle_stage', $group['rules'][0]['field'] ?? null);
    Assert::same('is', $group['rules'][0]['op'] ?? null, 'the operator key is "op"');
    Assert::same('customer', $group['rules'][0]['value'] ?? null);

    // Which means the engine can read it: the description is the quickest
    // proof, because it comes from the same group the branch is decided on.
    Assert::contains(
        'lifecycle stage is customer',
        (string) $owner->get('/automations/' . $id)['body'],
        'the saved condition reads back as what was typed'
    );

    ah_clear();
});

Harness::test('a condition with no field to check is refused', function () use ($owner): void {
    // It would evaluate false for everybody, so the YES side could never run.
    ah_clear();
    $id = ah_automation('ahttp-empty-condition');

    $owner->postWithToken('/automations/' . $id, '/automations/' . $id . '/steps', [
        'type'       => 'condition',
        'rule_field' => '',
        'rule_value' => '',
    ]);

    Assert::count(0, ah_steps($id), 'nothing was saved');
    Assert::contains('field to check', ah_flash($owner, '/automations/' . $id));

    ah_clear();
});

Harness::test('a webhook step must be https', function () use ($owner): void {
    // The one step that sends anything outside the venue.
    ah_clear();
    $id = ah_automation('ahttp-webhook');

    $owner->postWithToken('/automations/' . $id, '/automations/' . $id . '/steps', [
        'type'       => 'webhook',
        'config_url' => 'http://hooks.example.com/castle',
    ]);

    Assert::count(0, ah_steps($id));
    Assert::contains('https', ah_flash($owner, '/automations/' . $id));

    ah_clear();
});

Harness::test('a step is reordered, skipped and removed', function () use ($owner): void {
    ah_clear();
    ah_template('ahttp-one');
    ah_template('ahttp-two');
    $id = ah_automation('ahttp-order');

    foreach (['ahttp-one', 'ahttp-two'] as $template) {
        $owner->postWithToken('/automations/' . $id, '/automations/' . $id . '/steps', [
            'type'            => 'send_email',
            'config_template' => $template,
        ]);
    }

    $steps = ah_steps($id);
    Assert::count(2, $steps);

    $second = (int) $steps[1]['id'];

    $owner->postWithToken(
        '/automations/' . $id,
        '/automations/steps/' . $second . '/move',
        ['direction' => 'up']
    );

    $moved = ah_steps($id);
    Assert::same($second, (int) $moved[0]['id'], 'the second step is now first');

    // Skipped, not deleted: an operator switching a step off means "do not do
    // this", and the run skips it including its delay.
    $owner->postWithToken('/automations/' . $id, '/automations/steps/' . $second . '/toggle', []);
    Assert::same(0, (int) DB::value(
        'SELECT is_enabled FROM {automation_steps} WHERE id = :id',
        ['id' => $second]
    ));

    $owner->postWithToken('/automations/' . $id, '/automations/steps/' . $second . '/delete', []);
    Assert::count(1, ah_steps($id));

    ah_clear();
});

Harness::test('a step inside a branch cannot be moved out of it', function () use ($owner): void {
    /*
     * The neighbour is found WITHIN the branch. A step in the "yes" arm of a
     * condition has nothing to do with the order of the main line, and
     * swapping across the two would move it out of its branch — where it
     * would run for everybody, including the guests the condition was there
     * to keep it away from.
     */
    ah_clear();
    ah_template('ahttp-branch');
    $id = ah_automation('ahttp-branch');

    $owner->postWithToken('/automations/' . $id, '/automations/' . $id . '/steps', [
        'type'            => 'send_email',
        'config_template' => 'ahttp-branch',
    ]);

    $owner->postWithToken('/automations/' . $id, '/automations/' . $id . '/steps', [
        'type'          => 'condition',
        'rule_field'    => 'lifecycle_stage',
        'rule_operator' => 'is',
        'rule_value'    => 'customer',
    ]);

    $condition = (int) ah_steps($id)[1]['id'];

    $owner->postWithToken('/automations/' . $id, '/automations/' . $id . '/steps', [
        'type'           => 'add_tag',
        'config_tag'     => 'ahttp-yes',
        'branch'         => 'yes',
        'parent_step_id' => (string) $condition,
    ]);

    $child = DB::first(
        "SELECT * FROM {automation_steps} WHERE automation_id = :a AND branch = 'yes'",
        ['a' => $id]
    );

    Assert::notNull($child, 'precondition: the child is in the yes branch');
    Assert::same($condition, (int) $child['parent_step_id']);

    $owner->postWithToken(
        '/automations/' . $id,
        '/automations/steps/' . (int) $child['id'] . '/move',
        ['direction' => 'up']
    );

    $after = (array) DB::first('SELECT * FROM {automation_steps} WHERE id = :id', ['id' => (int) $child['id']]);

    Assert::same('yes', (string) $after['branch'], 'still in its branch');
    Assert::same($condition, (int) $after['parent_step_id'], 'still inside the condition');
    Assert::contains('already first in its branch', ah_flash($owner, '/automations/' . $id));

    // And the main line is untouched: nothing was swapped with it.
    $first = ah_steps($id)[0];
    Assert::same('send_email', (string) $first['type']);
    Assert::same('main', (string) $first['branch']);

    ah_clear();
});

// ------------------------------------------------------------ switching on

Harness::test('AN AUTOMATION WITH NO STEPS CANNOT BE SWITCHED ON', function () use ($owner): void {
    // It would enrol guests and do nothing with them — and the page would say
    // "On", which is the whole problem.
    ah_clear();
    $id = ah_automation('ahttp-nosteps');

    $owner->postWithToken('/automations/' . $id, '/automations/' . $id . '/activate', []);

    Assert::same(0, ah_active($id), 'still off');
    Assert::contains('no steps', ah_flash($owner, '/automations/' . $id));

    ah_clear();
});

Harness::test('AN AUTOMATION WHOSE TRIGGER IS NOT BUILT YET CANNOT BE SWITCHED ON', function () use ($owner): void {
    ah_clear();
    ah_template('ahttp-pend');
    $id = ah_automation('ahttp-pending-on', 'ticket.purchased');

    $owner->postWithToken('/automations/' . $id, '/automations/' . $id . '/steps', [
        'type'            => 'send_email',
        'config_template' => 'ahttp-pend',
    ]);

    $owner->postWithToken('/automations/' . $id, '/automations/' . $id . '/activate', []);

    Assert::same(0, ah_active($id));
    Assert::contains('online ticketing', ah_flash($owner, '/automations/' . $id));

    ah_clear();
});

Harness::test('an automation in a switched-off playbook cannot be switched on', function () use ($owner): void {
    ah_clear();
    ah_template('ahttp-pb');

    $playbookId = (int) DB::insert('playbooks', [
        'number'     => 98,
        'name'       => 'AHttp playbook',
        'slug'       => 'ahttp-playbook',
        'is_active'  => 0,
        'created_at' => now_utc(),
        'updated_at' => now_utc(),
    ]);

    $id = ah_automation('ahttp-in-playbook', 'visit.recorded', ['playbook_id' => $playbookId]);

    $owner->postWithToken('/automations/' . $id, '/automations/' . $id . '/steps', [
        'type'            => 'send_email',
        'config_template' => 'ahttp-pb',
    ]);

    $owner->postWithToken('/automations/' . $id, '/automations/' . $id . '/activate', []);

    Assert::same(0, ah_active($id));
    Assert::contains('playbook', ah_flash($owner, '/automations/' . $id));

    // And it goes on once the playbook is on.
    DB::update('playbooks', ['is_active' => 1], 'id = :id', ['id' => $playbookId]);

    $owner->postWithToken('/automations/' . $id, '/automations/' . $id . '/activate', []);
    Assert::same(1, ah_active($id));

    ah_clear();
});

Harness::test('switching one off leaves the guests part way through where they are', function () use ($owner): void {
    /*
     * Not cancelled. A guest half way through a sequence stays half way
     * through, so switching it back on tomorrow continues from where they got
     * to — and the message says how many people that is, because "off" with
     * eleven people mid-sequence is a different situation from "off" with
     * none.
     */
    ah_clear();
    ah_template('ahttp-inflight');
    $id = ah_automation('ahttp-offswitch', 'visit.recorded', ['is_active' => 1]);

    $owner->postWithToken('/automations/' . $id, '/automations/' . $id . '/steps', [
        'type'            => 'send_email',
        'config_template' => 'ahttp-inflight',
    ]);

    $contactId = ah_contact('Midway');

    $runId = (int) DB::insert('automation_runs', [
        'automation_id' => $id,
        'contact_id'    => $contactId,
        'status'        => 'waiting',
        'next_run_at'   => gmdate('Y-m-d H:i:s', time() + 3600),
        'started_at'    => now_utc(),
    ]);

    $owner->postWithToken('/automations/' . $id, '/automations/' . $id . '/deactivate', []);

    Assert::same(0, ah_active($id));
    Assert::same('waiting', (string) DB::value(
        'SELECT status FROM {automation_runs} WHERE id = :id',
        ['id' => $runId]
    ), 'the run is untouched');

    Assert::contains('part way through', ah_flash($owner, '/automations/' . $id));

    ah_clear();
});

// ---------------------------------------------------------- the kill switch

Harness::test('THE KILL SWITCH STOPS EVERYTHING AND SAYS SO ON EVERY PAGE', function () use ($owner, $restore): void {
    ah_clear();
    $id = ah_automation('ahttp-kill');

    try {
        $owner->postWithToken('/automations', '/automations/pause', []);

        // Settings are READ THROUGH A CACHE, and this process is not the one
        // that wrote the row — so without the reload this reads whatever the
        // test process loaded at boot, which is the state before the POST.
        Settings::load(true);
        Assert::true((bool) Settings::get('system.automations_paused'), 'the setting is set');

        $list = (string) $owner->get('/automations')['body'];
        Assert::contains('Automations are stopped', $list);
        Assert::contains('Start automations again', $list);

        // On the automation's own page, because that is where somebody
        // wondering why nothing has happened will be looking.
        Assert::contains('Automations are stopped', (string) $owner->get('/automations/' . $id)['body']);

        // And in the topbar of a page that has nothing to do with
        // automations: two clicks from everywhere, per §9.5.
        Assert::contains('Automations stopped', (string) $owner->get('/contacts')['body']);

        $owner->postWithToken('/automations', '/automations/resume', []);

        Settings::load(true);
        Assert::false((bool) Settings::get('system.automations_paused'));
        Assert::notContains('Automations are stopped', (string) $owner->get('/automations')['body']);
    } finally {
        $restore();
        ah_clear();
    }
});

// ---------------------------------------------------------------- test mode

Harness::test('THE PREVIEW SENDS NOTHING AND WRITES NOTHING', function () use ($owner): void {
    ah_clear();
    ah_template('ahttp-preview');
    $id = ah_automation('ahttp-preview');

    $owner->postWithToken('/automations/' . $id, '/automations/' . $id . '/steps', [
        'type'            => 'send_email',
        'config_template' => 'ahttp-preview',
        'delay_minutes'   => '2880',
    ]);

    $contactId = ah_contact('Previewed');
    $email     = (string) DB::value('SELECT email FROM {contacts} WHERE id = :id', ['id' => $contactId]);

    $owner->postWithToken('/automations/' . $id, '/automations/' . $id . '/test', [
        'contact_id' => (string) $contactId,
    ]);

    $body = (string) $owner->get('/automations/' . $id)['body'];

    Assert::contains('would be sent 1 message', $body);
    Assert::contains('Previewed', $body, 'it names the guest it was run against');

    // NOTHING happened to them.
    Assert::same(0, (int) DB::value(
        "SELECT COUNT(*) FROM {messages} WHERE to_address = :e",
        ['e' => $email]
    ), 'no message, not even a captured one');

    Assert::same(0, (int) DB::value(
        'SELECT COUNT(*) FROM {automation_runs} WHERE automation_id = :a',
        ['a' => $id]
    ), 'and no run was recorded against them');

    Assert::same(0, (int) DB::value(
        'SELECT run_count FROM {automations} WHERE id = :a',
        ['a' => $id]
    ), 'nor counted as an enrolment');

    // The delay is still on the step: an earlier version collapsed the delays
    // in the database to resolve the preview in one pass, which would have
    // advanced every OTHER guest in that automation with no delay at all if
    // cron had landed mid-preview.
    Assert::same(2880, (int) ah_steps($id)[0]['delay_minutes']);

    ah_clear();
});

Harness::test('the preview says what a step would fail on before it fails', function () use ($owner): void {
    /*
     * The mistakes a sequence sits on for months: a template that was
     * renamed, an offer that does not exist, a person who has left. Every one
     * of them is recorded as a skip or a failure days after anybody was
     * looking, so the preview names them while somebody is still on the page.
     */
    ah_clear();
    $id = ah_automation('ahttp-preview-problem');

    // A template that does not exist. Added directly, because the form's own
    // select would not offer it — the case is a template that was renamed or
    // retired after the step was written.
    DB::insert('automation_steps', [
        'automation_id' => $id,
        'branch'        => 'main',
        'sort_order'    => 0,
        'type'          => 'send_email',
        'config'        => json_encode(['template' => 'ahttp-was-renamed']),
        'delay_minutes' => 0,
        'is_enabled'    => 1,
        'created_at'    => now_utc(),
    ]);

    $contactId = ah_contact('Problem');

    $owner->postWithToken('/automations/' . $id, '/automations/' . $id . '/test', [
        'contact_id' => (string) $contactId,
    ]);

    $body = (string) $owner->get('/automations/' . $id)['body'];

    Assert::contains('no active email template', $body);
    Assert::contains('ahttp-was-renamed', $body);

    ah_clear();
});

Harness::test('the preview needs a guest to run against', function () use ($owner): void {
    ah_clear();
    $id = ah_automation('ahttp-preview-nobody');

    $owner->postWithToken('/automations/' . $id, '/automations/' . $id . '/test', ['contact_id' => '0']);

    Assert::contains('Pick a guest', ah_flash($owner, '/automations/' . $id));

    ah_clear();
});

// ------------------------------------------------------------- the two gates

Harness::test('SEEING AN AUTOMATION IS NOT EDITING IT, AND EDITING IS NOT STARTING IT', function () use ($baseUrl, $owner): void {
    /*
     * Three permissions, and no seeded role holds the view key alone — so the
     * split was never exercised and a test would pass with either gate
     * removed. Roles are DATA in this application, so a venue can
     * legitimately make somebody who reads the automations and builds none.
     */
    ah_clear();
    DB::query("DELETE FROM {role_permissions} WHERE role_id IN (SELECT id FROM {roles} WHERE slug = 'ahttp-viewer')");
    DB::query("DELETE FROM {users} WHERE email = 'ahttp-viewer@example.test'");
    DB::query("DELETE FROM {roles} WHERE slug = 'ahttp-viewer'");

    ah_template('ahttp-gated');
    $id = ah_automation('ahttp-gates');

    $owner->postWithToken('/automations/' . $id, '/automations/' . $id . '/steps', [
        'type'            => 'send_email',
        'config_template' => 'ahttp-gated',
    ]);

    $stepId = (int) ah_steps($id)[0]['id'];

    $roleId = (int) DB::insert('roles', [
        'slug'        => 'ahttp-viewer',
        'name'        => 'Automation viewer (test)',
        'description' => 'Reads the automations, builds none.',
        'is_system'   => 0,
        'sort_order'  => 902,
        'created_at'  => now_utc(),
        'updated_at'  => now_utc(),
    ]);

    $viewKey = (int) DB::value("SELECT id FROM {permissions} WHERE slug = 'view_automations'");
    Assert::greaterThan(0, $viewKey, 'precondition: view_automations exists');

    DB::insert('role_permissions', [
        'role_id'       => $roleId,
        'permission_id' => $viewKey,
        'scope'         => 'all',
    ]);

    test_make_user('ahttp-viewer', [
        'email'         => 'ahttp-viewer@example.test',
        'name'          => 'Automation Viewer',
        'password_hash' => App\Core\Auth::hash('ahttp-viewer-passphrase-9'),
    ]);

    try {
        $viewer = new TestBrowser($baseUrl, 'automations-viewer');
        $viewer->login('ahttp-viewer@example.test', 'ahttp-viewer-passphrase-9');

        Assert::same(200, $viewer->get('/automations/' . $id)['status'], 'they can look');

        // The buttons are not even drawn for them.
        $body = (string) $viewer->get('/automations/' . $id)['body'];
        Assert::notContains('Add a step', $body);
        Assert::notContains('Switch this automation on', $body);
        Assert::notContains('Stop all automations', (string) $viewer->get('/automations')['body']);

        // And the routes refuse them.
        Assert::same(403, $viewer->postWithToken('/automations/' . $id, '/automations/' . $id . '/steps', [
            'type'            => 'send_email',
            'config_template' => 'ahttp-gated',
        ])['status']);

        Assert::same(403, $viewer->postWithToken(
            '/automations/' . $id,
            '/automations/steps/' . $stepId . '/delete',
            []
        )['status']);

        Assert::same(403, $viewer->postWithToken(
            '/automations/' . $id,
            '/automations/' . $id . '/activate',
            []
        )['status']);

        Assert::same(403, $viewer->postWithToken('/automations', '/automations/pause', [])['status']);

        Assert::same(403, $viewer->postWithToken(
            '/automations/' . $id,
            '/automations/' . $id . '/test',
            ['contact_id' => '1']
        )['status'], 'the preview reads a guest’s record, so it is a manage action');

        // Nothing changed.
        Assert::count(1, ah_steps($id));
        Assert::same(0, ah_active($id));
    } finally {
        DB::query("DELETE FROM {users} WHERE email = 'ahttp-viewer@example.test'");
        DB::query("DELETE FROM {role_permissions} WHERE role_id = " . $roleId);
        DB::query("DELETE FROM {roles} WHERE id = " . $roleId);
        ah_clear();
    }
});

Harness::test('every write needs its CSRF token', function () use ($owner): void {
    ah_clear();
    $id = ah_automation('ahttp-csrf');

    foreach ([
        '/automations/' . $id . '/activate',
        '/automations/' . $id . '/deactivate',
        '/automations/' . $id . '/steps',
        '/automations/pause',
    ] as $path) {
        Assert::same(419, $owner->post($path, ['type' => 'send_email'])['status'], $path);
    }

    ah_clear();
});

ah_clear();
$restore();
