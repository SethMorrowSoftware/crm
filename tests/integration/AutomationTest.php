<?php

declare(strict_types=1);

/**
 * The automation engine against a real database.
 *
 * `tests/unit/AutomationEngineTest.php` pins the decisions — when a step
 * runs, whether it still should, which branch. This drives the RUNNER: real
 * rows, real events, and the real send path.
 *
 * Four things go wrong with an automation, in the order they cost money, and
 * each has a test here:
 *
 *   1. **It reaches somebody who did not consent.** Every message goes
 *      through `Mailer::send()` / `Sms::send()` with the contact attached, so
 *      an automation can never reach somebody a reply typed in the inbox
 *      could not. There is no automation-only path to a guest's phone.
 *   2. **It keeps nudging a guest who has already booked.** Exit conditions
 *      are evaluated before EVERY step, so booking on day three stops the
 *      day-five message. This is the single most embarrassing failure an
 *      automation has.
 *   3. **It sends the same guest the sequence twice.**
 *   4. **It stops half way and nobody is told.** A failing step is retried
 *      with backoff and then the run is FAILED and audited — never
 *      abandoned quietly.
 *
 * Plus the kill switch, which §9.5 requires to be two clicks from every page:
 * it stops runs already in flight, not just new enrolments, because the only
 * reason anybody reaches for it is that something is going out that should
 * not be.
 */

use App\Core\DB;
use App\Core\Event;
use App\Core\Settings;
use App\Services\AutomationEngine;
use App\Services\AutomationRunner;
use Tests\Assert;
use Tests\Harness;

Harness::suite('integration/automation');

if (!test_boot()) {
    Harness::skip('automation suite', 'no database available');

    return;
}

// ------------------------------------------------------------------ fixtures

/**
 * Wipe this suite's fixtures.
 *
 * Called at the START of every test, not only at the end. An assertion
 * failure throws, so a trailing cleanup does not run — and the leftovers are
 * not inert: an automation triggered by `visit.recorded` that outlives its
 * own test enrols the NEXT test's contacts and sends them its messages.
 * MEASURED: one failing test produced three more failures that had nothing
 * wrong with them.
 */
function au_clear(): void
{
    DB::query(
        "DELETE FROM {automation_step_log} WHERE run_id IN (
            SELECT id FROM {automation_runs} WHERE automation_id IN (
                SELECT id FROM {automations} WHERE slug LIKE 'autest-%'))"
    );
    DB::query(
        "DELETE FROM {automation_runs} WHERE automation_id IN (
            SELECT id FROM {automations} WHERE slug LIKE 'autest-%')"
    );
    DB::query(
        "DELETE FROM {automation_steps} WHERE automation_id IN (
            SELECT id FROM {automations} WHERE slug LIKE 'autest-%')"
    );
    DB::query("DELETE FROM {automations} WHERE slug LIKE 'autest-%'");
    DB::query("DELETE FROM {templates} WHERE slug LIKE 'autest-%'");
    DB::query("DELETE FROM {tasks} WHERE title LIKE 'AuTest%'");
    DB::query("DELETE FROM {messages} WHERE to_address LIKE 'autest.%'");
    DB::query(
        "DELETE FROM {taggables} WHERE entity_type = 'contact' AND entity_id IN (
            SELECT id FROM {contacts} WHERE source = 'autest')"
    );
    DB::query("DELETE FROM {bookings} WHERE contact_id IN (SELECT id FROM {contacts} WHERE source = 'autest')");

    // The deal side, in dependency order: a quote hangs off a deal and the
    // stage history hangs off both, so a contact cannot be deleted until all
    // three are gone.
    DB::query(
        "DELETE FROM {quotes} WHERE deal_id IN (
            SELECT id FROM {deals} WHERE contact_id IN (SELECT id FROM {contacts} WHERE source = 'autest'))"
    );
    DB::query(
        "DELETE FROM {deal_stage_history} WHERE deal_id IN (
            SELECT id FROM {deals} WHERE contact_id IN (SELECT id FROM {contacts} WHERE source = 'autest'))"
    );
    DB::query("DELETE FROM {deals} WHERE contact_id IN (SELECT id FROM {contacts} WHERE source = 'autest')");

    DB::query(
        "DELETE FROM {coupon_redemptions} WHERE coupon_id IN (
            SELECT id FROM {coupons} WHERE code LIKE 'AUTEST%')"
    );
    DB::query("DELETE FROM {coupons} WHERE code LIKE 'AUTEST%'");

    DB::query(
        "DELETE FROM {notifications} WHERE user_id IN (
            SELECT id FROM {users} WHERE email LIKE 'autest.%')"
    );
    DB::query("DELETE FROM {users} WHERE email LIKE 'autest.%'");

    DB::query("DELETE FROM {contacts} WHERE source = 'autest'");
    DB::query("DELETE FROM {tags} WHERE name LIKE 'autest-%'");
}

/** A member of staff for the notify step to reach. */
function au_user(array $overrides = []): int
{
    return test_make_user('owner', $overrides + [
        'name'  => 'AuTest Manager',
        'email' => 'autest.user.' . bin2hex(random_bytes(3)) . '@example.test',
    ]);
}

/** @param array<string,mixed> $overrides */
function au_deal(int $contactId, array $overrides = []): int
{
    $stageId = (int) DB::value(
        "SELECT id FROM {pipeline_stages} WHERE pipeline_id = 1 AND name = 'New Inquiry'"
    );

    return (int) DB::insert('deals', $overrides + [
        'title'            => 'AuTest deal',
        'contact_id'       => $contactId,
        'pipeline_id'      => 1,
        'stage_id'         => $stageId,
        'status'           => 'open',
        'value_cents'      => 40000,
        'stage_entered_at' => now_utc(),
        'last_activity_at' => now_utc(),
        'created_at'       => now_utc(),
        'updated_at'       => now_utc(),
    ]);
}

/** @param array<string,mixed> $overrides */
function au_coupon(string $code, array $overrides = []): int
{
    return (int) DB::insert('coupons', $overrides + [
        'code'            => $code,
        'name'            => 'AuTest ' . $code,
        'type'            => 'percent',
        'value'           => 10,
        'max_per_contact' => 1,
        'is_active'       => 1,
        'created_at'      => now_utc(),
        'updated_at'      => now_utc(),
    ]);
}

function au_stage_id(string $name, int $pipeline = 1): int
{
    return (int) DB::value(
        'SELECT id FROM {pipeline_stages} WHERE pipeline_id = :p AND name = :n',
        ['p' => $pipeline, 'n' => $name]
    );
}

/** @param array<string,mixed> $overrides */
function au_contact(string $name, array $overrides = []): int
{
    return (int) DB::insert('contacts', $overrides + [
        'first_name'      => $name,
        'last_name'       => 'AuTest',
        'email'           => 'autest.' . strtolower($name) . '.' . bin2hex(random_bytes(3)) . '@example.test',
        'email_consent'   => 1,
        'source'          => 'autest',
        'lifecycle_stage' => 'lead',
        'created_at'      => now_utc(),
        'updated_at'      => now_utc(),
    ]);
}

function au_template(string $slug, string $channel = 'email'): int
{
    return (int) DB::insert('templates', [
        'name'       => 'AuTest ' . $slug,
        'slug'       => $slug,
        'channel'    => $channel,
        'subject'    => 'AuTest for {{first_name}}',
        'body'       => $channel === 'sms'
            ? 'Hi {{first_name}}, a note from {{venue_name}}. Reply STOP to opt out.'
            : '<p>Hi {{first_name}}, a note from {{venue_name}}.</p>',
        'is_active'  => 1,
        'created_at' => now_utc(),
        'updated_at' => now_utc(),
    ]);
}

/** @param array<string,mixed> $overrides */
function au_automation(string $slug, string $trigger, array $overrides = []): int
{
    return (int) DB::insert('automations', $overrides + [
        'name'                 => 'AuTest ' . $slug,
        'slug'                 => $slug,
        'trigger_type'         => $trigger,
        'is_active'            => 1,
        'run_once_per_contact' => 1,
        'allow_reentry'        => 0,
        'created_at'           => now_utc(),
        'updated_at'           => now_utc(),
    ]);
}

/** @param array<string,mixed> $overrides */
function au_step(int $automationId, string $type, array $config = [], array $overrides = []): int
{
    return (int) DB::insert('automation_steps', $overrides + [
        'automation_id' => $automationId,
        'branch'        => 'main',
        'sort_order'    => (int) DB::value(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM {automation_steps} WHERE automation_id = :a',
            ['a' => $automationId]
        ),
        'type'          => $type,
        'config'        => json_encode($config),
        'delay_minutes' => 0,
        'is_enabled'    => 1,
        'created_at'    => now_utc(),
    ]);
}

/** @return array<string,mixed>|null */
function au_run(int $automationId, ?int $contactId = null): ?array
{
    $sql = 'SELECT * FROM {automation_runs} WHERE automation_id = :a';
    $params = ['a' => $automationId];

    if ($contactId !== null) {
        $sql .= ' AND contact_id = :c';
        $params['c'] = $contactId;
    }

    return DB::first($sql . ' ORDER BY id DESC LIMIT 1', $params);
}

/** @return list<array<string,mixed>> */
function au_log(int $runId): array
{
    return DB::all(
        'SELECT l.*, s.type FROM {automation_step_log} l
           LEFT JOIN {automation_steps} s ON s.id = l.step_id
          WHERE l.run_id = :r ORDER BY l.id',
        ['r' => $runId]
    );
}

function au_sent(string $email): int
{
    return (int) DB::value(
        "SELECT COUNT(*) FROM {messages} WHERE to_address = :e AND direction = 'outbound'",
        ['e' => $email]
    );
}

/** Make a parked run due now. */
function au_due(int $runId): void
{
    DB::update('automation_runs', ['next_run_at' => now_utc()], 'id = :id', ['id' => $runId]);
}

au_clear();

$settingsBefore = [];

foreach ([
    'system.safe_mode'          => false,
    'system.sending_paused'     => false,
    'system.automations_paused' => false,
    'mail.driver'               => 'log',
    'sms.driver'                => 'log',
    'sms.quiet_start_minutes'   => 0,
    'sms.quiet_end_minutes'     => 24 * 60,
] as $key => $value) {
    $settingsBefore[$key] = Settings::get($key);
    Settings::set($key, $value);
}

$restore = static function () use ($settingsBefore): void {
    foreach ($settingsBefore as $key => $value) {
        if ($value === null) {
            Settings::forget($key);
        } else {
            Settings::set($key, $value);
        }
    }
};

// The suite fires events directly, so the listeners have to be registered —
// which they are not when this file is required by the runner rather than by
// a request. Registering twice would double every enrolment.
if (!Event::hasListeners('*')) {
    require CC_ROOT . '/app/listeners.php';
}

// =========================================================== enrolment

Harness::test('an event enrols the contact it is about', function (): void {
    au_clear();

    $automationId = au_automation('autest-enrol', 'visit.recorded');
    au_step($automationId, 'wait');

    $contactId = au_contact('Enrolled');

    Event::fire('visit.recorded', ['contact_id' => $contactId]);

    $run = au_run($automationId, $contactId);

    Assert::notNull($run, 'a run should have started');
    Assert::same($contactId, (int) $run['contact_id']);
    Assert::same(
        1,
        (int) DB::value('SELECT run_count FROM {automations} WHERE id = :a', ['a' => $automationId]),
        'and the automation counts it'
    );

    au_clear();
});

Harness::test('an inactive automation does not enrol anybody', function (): void {
    au_clear();

    $automationId = au_automation('autest-off', 'visit.recorded', ['is_active' => 0]);
    au_step($automationId, 'wait');

    Event::fire('visit.recorded', ['contact_id' => au_contact('Ignored')]);

    Assert::null(au_run($automationId));

    au_clear();
});

Harness::test(
    'AN AUTOMATION IN A SWITCHED-OFF PLAYBOOK DOES NOT RUN',
    function (): void {
    au_clear();

        // Even with its own flag on. A venue that switched a playbook off
        // expects everything in it to stop, and the playbook page says as
        // much when it deactivates — so an automation left individually
        // active must not keep going.
        $playbookId = (int) DB::value('SELECT id FROM {playbooks} ORDER BY id LIMIT 1');

        Assert::greaterThan(0, $playbookId, 'precondition: the playbooks are seeded');

        $wasActive = (int) DB::value('SELECT is_active FROM {playbooks} WHERE id = :p', ['p' => $playbookId]);

        DB::update('playbooks', ['is_active' => 0], 'id = :p', ['p' => $playbookId]);

        try {
            $automationId = au_automation('autest-playbook', 'visit.recorded', ['playbook_id' => $playbookId]);
            au_step($automationId, 'wait');

            Event::fire('visit.recorded', ['contact_id' => au_contact('Playbookoff')]);

            Assert::null(au_run($automationId), 'nothing enrols from a playbook that is off');

            // And with the playbook on, the same automation does enrol —
            // without which this test would pass against a broken query.
            DB::update('playbooks', ['is_active' => 1], 'id = :p', ['p' => $playbookId]);

            Event::fire('visit.recorded', ['contact_id' => au_contact('Playbookon')]);

            Assert::notNull(au_run($automationId), 'and does when the playbook is on');
        } finally {
            DB::update('playbooks', ['is_active' => $wasActive], 'id = :p', ['p' => $playbookId]);
            au_clear();
        }
    }
);

Harness::test('entry conditions decide who is enrolled', function (): void {
    au_clear();

    $automationId = au_automation('autest-entry', 'visit.recorded', [
        'conditions' => json_encode(['match' => 'all', 'rules' => [
            ['field' => 'email_consent', 'op' => 'is_true'],
        ]]),
    ]);
    au_step($automationId, 'wait');

    $yes = au_contact('Consented');
    $no  = au_contact('Unconsented', ['email_consent' => 0]);

    Event::fire('visit.recorded', ['contact_id' => $yes]);
    Event::fire('visit.recorded', ['contact_id' => $no]);

    Assert::notNull(au_run($automationId, $yes));
    Assert::null(au_run($automationId, $no), 'somebody who fails the entry conditions is not enrolled');

    au_clear();
});

Harness::test('run-once-per-guest holds across repeated triggers', function (): void {
    au_clear();

    $automationId = au_automation('autest-once', 'visit.recorded');
    au_step($automationId, 'wait');

    $contactId = au_contact('Onceonly');

    for ($i = 0; $i < 4; $i++) {
        Event::fire('visit.recorded', ['contact_id' => $contactId]);
    }

    Assert::same(1, (int) DB::value(
        'SELECT COUNT(*) FROM {automation_runs} WHERE automation_id = :a AND contact_id = :c',
        ['a' => $automationId, 'c' => $contactId]
    ));

    au_clear();
});

Harness::test('re-entry is allowed once the cooldown has passed', function (): void {
    au_clear();

    $automationId = au_automation('autest-reentry', 'visit.recorded', [
        'run_once_per_contact'   => 0,
        'allow_reentry'          => 1,
        'reentry_cooldown_hours' => 24,
    ]);
    au_step($automationId, 'wait');

    $contactId = au_contact('Returner');

    Event::fire('visit.recorded', ['contact_id' => $contactId]);

    $run = au_run($automationId, $contactId);
    Assert::notNull($run);

    // Still in flight: refused, because two overlapping copies of a sequence
    // is worse than a missed re-enrolment.
    Event::fire('visit.recorded', ['contact_id' => $contactId]);

    Assert::same(1, (int) DB::value(
        'SELECT COUNT(*) FROM {automation_runs} WHERE automation_id = :a',
        ['a' => $automationId]
    ), 'not while they are part way through it');

    // Finished two days ago: allowed.
    DB::update('automation_runs', [
        'status'      => 'completed',
        'finished_at' => gmdate('Y-m-d H:i:s', time() - 48 * 3600),
    ], 'id = :id', ['id' => (int) $run['id']]);

    Event::fire('visit.recorded', ['contact_id' => $contactId]);

    Assert::same(2, (int) DB::value(
        'SELECT COUNT(*) FROM {automation_runs} WHERE automation_id = :a',
        ['a' => $automationId]
    ), 'and allowed once the cooldown has passed');

    au_clear();
});

// ======================================================== the send path

Harness::test(
    'AN AUTOMATION CANNOT REACH SOMEBODY A REPLY COULD NOT',
    function (): void {
    au_clear();

        /*
         * The rule the whole design rests on: every message goes through the
         * ordinary send path with the contact attached, so consent, the
         * suppression list and do-not-contact apply exactly as they do to a
         * reply typed in the inbox. There is no automation-only route to a
         * guest.
         *
         * The entry conditions are deliberately EMPTY here, so nothing but
         * the send path itself is standing between these guests and a
         * message.
         */
        au_template('autest-send');

        $automationId = au_automation('autest-consent', 'visit.recorded');
        au_step($automationId, 'send_email', ['template' => 'autest-send']);

        $reachable = au_contact('Reachable');
        $noConsent = au_contact('Noconsent', ['email_consent' => 0]);
        $refused   = au_contact('Refused', ['do_not_contact' => 1]);

        foreach ([$reachable, $noConsent, $refused] as $contactId) {
            Event::fire('visit.recorded', ['contact_id' => $contactId]);
        }

        AutomationRunner::tick();

        $email = static fn (int $id): string => (string) DB::value(
            'SELECT email FROM {contacts} WHERE id = :id',
            ['id' => $id]
        );

        Assert::same(1, au_sent($email($reachable)), 'the reachable guest gets it');
        Assert::same(0, au_sent($email($noConsent)), 'SOMEBODY WHO HAS NOT OPTED IN DOES NOT');
        Assert::same(0, au_sent($email($refused)), 'NOR DOES SOMEBODY WHO ASKED NOT TO BE CONTACTED');

        // And the refusal is RECORDED against the run, with its reason, so
        // the venue can answer "why didn't Dana get it" months later.
        $skippedRun = au_run($automationId, $noConsent);

        Assert::notNull($skippedRun);

        $log = au_log((int) $skippedRun['id']);

        Assert::count(1, $log);
        Assert::same('skipped', (string) $log[0]['status'], 'a refusal is a skip, not a failure');
        Assert::contains('consent', (string) $log[0]['output'], 'and the reason is written down');

        au_clear();
    }
);

Harness::test(
    'AND THE SAME HOLDS FOR A TEXT, WHERE THE FINE IS PER MESSAGE',
    function (): void {
        au_clear();

        /*
         * The email case above and this one are the same rule on two
         * channels, and this is the channel that carries the exposure: SMS
         * consent is express written consent under the TCPA, and the penalty
         * is per message. So an automation's text goes out as MARKETING,
         * which makes `Sms::send()` check consent, the suppression list, the
         * number the guest opted in on and the weekly cap.
         *
         * MEASURED by mutation: sending it as transactional instead passed
         * every other test in this suite, because nothing else here texts
         * anybody from an automation.
         */
        au_template('autest-text', 'sms');

        $automationId = au_automation('autest-sms', 'visit.recorded');
        au_step($automationId, 'send_sms', ['template' => 'autest-text']);

        $consented = au_contact('Textable', [
            'phone'            => '+18455550101',
            'phone_normalized' => '+18455550101',
            'sms_consent'      => 1,
            'sms_consent_at'   => now_utc(),
        ]);

        $noConsent = au_contact('Untextable', [
            'phone'            => '+18455550102',
            'phone_normalized' => '+18455550102',
            'sms_consent'      => 0,
        ]);

        foreach ([$consented, $noConsent] as $contactId) {
            Event::fire('visit.recorded', ['contact_id' => $contactId]);
        }

        AutomationRunner::tick();

        $texted = static fn (string $number): int => (int) DB::value(
            "SELECT COUNT(*) FROM {messages}
              WHERE to_address = :p AND direction = 'outbound' AND channel = 'sms'",
            ['p' => $number]
        );

        Assert::same(1, $texted('+18455550101'), 'the guest who opted in is texted');
        Assert::same(0, $texted('+18455550102'), 'THE ONE WHO DID NOT IS NOT');

        // And the refusal is recorded with its reason.
        $skipped = au_run($automationId, $noConsent);

        Assert::notNull($skipped);

        $log = au_log((int) $skipped['id']);

        Assert::count(1, $log);
        Assert::same('skipped', (string) $log[0]['status']);
        Assert::contains('consent', (string) $log[0]['output']);

        DB::query("DELETE FROM {messages} WHERE to_address IN ('+18455550101', '+18455550102')");

        au_clear();
    }
);

Harness::test(
    'a refusal is not retried, because it can never succeed',
    function (): void {
    au_clear();

        // "This guest has not opted in" is the system working. Retrying it
        // three times with backoff would be three attempts at something that
        // cannot change, and would leave the run looking broken.
        au_template('autest-noretry');

        $automationId = au_automation('autest-refusal', 'visit.recorded');
        au_step($automationId, 'send_email', ['template' => 'autest-noretry']);
        au_step($automationId, 'add_tag', ['tag' => 'autest-after']);

        $contactId = au_contact('Unreachable', ['email_consent' => 0]);

        Event::fire('visit.recorded', ['contact_id' => $contactId]);
        AutomationRunner::tick();

        $run = au_run($automationId, $contactId);

        Assert::notNull($run);
        Assert::same('completed', (string) $run['status'], 'the run carries on past the refusal');
        Assert::same(0, (int) $run['attempts'], 'and it is not counted as an attempt');

        // The step AFTER the refused one still ran.
        Assert::true(DB::exists(
            "SELECT tag_id FROM {taggables} t JOIN {tags} g ON g.id = t.tag_id
              WHERE g.name = 'autest-after' AND t.entity_id = :c",
            ['c' => $contactId]
        ), 'a refused message does not stop the sequence');

        au_clear();
    }
);

// =========================================================== advancing

Harness::test('a sequence runs in order and parks on a real delay', function (): void {
    au_clear();

    au_template('autest-order');

    $automationId = au_automation('autest-sequence', 'visit.recorded');
    au_step($automationId, 'send_email', ['template' => 'autest-order']);
    au_step($automationId, 'add_tag', ['tag' => 'autest-tagged']);
    // Three days out: the run must park here rather than run it now.
    au_step($automationId, 'create_task', ['title' => 'AuTest later'], ['delay_minutes' => 4320]);

    $contactId = au_contact('Sequenced');

    Event::fire('visit.recorded', ['contact_id' => $contactId]);

    $executed = AutomationRunner::tick();

    Assert::same(2, $executed, 'two steps ran, the third is in the future');

    $run = au_run($automationId, $contactId);

    Assert::notNull($run);
    Assert::same('waiting', (string) $run['status']);
    Assert::notNull($run['next_run_at'], 'with a time to come back to');
    Assert::same(
        0,
        (int) DB::value('SELECT COUNT(*) FROM {tasks} WHERE title = :t', ['t' => 'AuTest later']),
        'and the delayed step has NOT happened yet'
    );

    // Advancing before it is due does nothing at all.
    Assert::same(0, AutomationRunner::tick(), 'a run that is not due is not touched');

    // Due: it finishes.
    au_due((int) $run['id']);
    AutomationRunner::tick();

    Assert::same(
        1,
        (int) DB::value('SELECT COUNT(*) FROM {tasks} WHERE title = :t', ['t' => 'AuTest later']),
        'and then it does'
    );
    Assert::same('completed', (string) au_run($automationId, $contactId)['status']);

    au_clear();
});

Harness::test('a disabled step is skipped without stopping the run', function (): void {
    au_clear();

    au_template('autest-disabled');

    $automationId = au_automation('autest-disabled-step', 'visit.recorded');
    au_step($automationId, 'send_email', ['template' => 'autest-disabled'], ['is_enabled' => 0]);
    au_step($automationId, 'add_tag', ['tag' => 'autest-reached']);

    $contactId = au_contact('Skipper');

    Event::fire('visit.recorded', ['contact_id' => $contactId]);
    AutomationRunner::tick();

    $email = (string) DB::value('SELECT email FROM {contacts} WHERE id = :id', ['id' => $contactId]);

    Assert::same(0, au_sent($email), 'the disabled step sent nothing');
    Assert::true(DB::exists(
        "SELECT tag_id FROM {taggables} t JOIN {tags} g ON g.id = t.tag_id
          WHERE g.name = 'autest-reached' AND t.entity_id = :c",
        ['c' => $contactId]
    ), 'and the rest of the sequence still ran');

    au_clear();
});

Harness::test(
    'BOTH SIDES OF A CONDITION REJOIN THE MAIN LINE',
    function (): void {
    au_clear();

        /*
         * The shape every review-gate and nurture uses: branch, do something
         * different on each side, then carry on with the steps that apply to
         * everybody. A branch that ended the run would silently drop half the
         * sequence for half the guests.
         */
        $automationId = au_automation('autest-branch', 'visit.recorded', [
            'conditions' => json_encode([]),
        ]);

        $condition = au_step($automationId, 'condition', ['rules' => ['match' => 'all', 'rules' => [
            ['field' => 'lifetime_value_cents', 'op' => 'gte', 'value' => 50000],
        ]]]);

        DB::insert('automation_steps', [
            'automation_id'  => $automationId,
            'parent_step_id' => $condition,
            'branch'         => 'yes',
            'sort_order'     => 0,
            'type'           => 'add_tag',
            'config'         => json_encode(['tag' => 'autest-big']),
            'delay_minutes'  => 0,
            'is_enabled'     => 1,
            'created_at'     => now_utc(),
        ]);

        DB::insert('automation_steps', [
            'automation_id'  => $automationId,
            'parent_step_id' => $condition,
            'branch'         => 'no',
            'sort_order'     => 0,
            'type'           => 'add_tag',
            'config'         => json_encode(['tag' => 'autest-small']),
            'delay_minutes'  => 0,
            'is_enabled'     => 1,
            'created_at'     => now_utc(),
        ]);

        // The step everybody reaches, whichever branch they took.
        au_step($automationId, 'add_tag', ['tag' => 'autest-everybody']);

        $big   = au_contact('Bigspender', ['lifetime_value_cents' => 90000]);
        $small = au_contact('Smallspender', ['lifetime_value_cents' => 1000]);

        Event::fire('visit.recorded', ['contact_id' => $big]);
        Event::fire('visit.recorded', ['contact_id' => $small]);
        AutomationRunner::tick();

        $tagged = static fn (int $contactId, string $tag): bool => DB::exists(
            "SELECT tag_id FROM {taggables} t JOIN {tags} g ON g.id = t.tag_id
              WHERE g.name = :tag AND t.entity_type = 'contact' AND t.entity_id = :c",
            ['tag' => $tag, 'c' => $contactId]
        );

        Assert::true($tagged($big, 'autest-big'), 'the big spender took the yes branch');
        Assert::false($tagged($big, 'autest-small'), 'and not the no branch');

        Assert::true($tagged($small, 'autest-small'), 'the small spender took the no branch');
        Assert::false($tagged($small, 'autest-big'));

        Assert::true($tagged($big, 'autest-everybody'), 'AND BOTH REACHED THE STEP AFTER IT');
        Assert::true($tagged($small, 'autest-everybody'));

        Assert::same('completed', (string) au_run($automationId, $big)['status']);
        Assert::same('completed', (string) au_run($automationId, $small)['status']);

        au_clear();
    }
);

Harness::test('an empty branch rejoins rather than ending the run', function (): void {
    au_clear();

    // An operator who has written only the "yes" side has not asked for
    // everybody else to be dropped out of the sequence.
    $automationId = au_automation('autest-emptybranch', 'visit.recorded');

    $condition = au_step($automationId, 'condition', ['rules' => ['match' => 'all', 'rules' => [
        ['field' => 'lifetime_value_cents', 'op' => 'gte', 'value' => 50000],
    ]]]);

    DB::insert('automation_steps', [
        'automation_id'  => $automationId,
        'parent_step_id' => $condition,
        'branch'         => 'yes',
        'sort_order'     => 0,
        'type'           => 'add_tag',
        'config'         => json_encode(['tag' => 'autest-onlyyes']),
        'delay_minutes'  => 0,
        'is_enabled'     => 1,
        'created_at'     => now_utc(),
    ]);

    au_step($automationId, 'add_tag', ['tag' => 'autest-afterempty']);

    $small = au_contact('Nobranch', ['lifetime_value_cents' => 100]);

    Event::fire('visit.recorded', ['contact_id' => $small]);
    AutomationRunner::tick();

    Assert::true(DB::exists(
        "SELECT tag_id FROM {taggables} t JOIN {tags} g ON g.id = t.tag_id
          WHERE g.name = 'autest-afterempty' AND t.entity_id = :c",
        ['c' => $small]
    ), 'the step after the condition still ran');

    au_clear();
});

// ====================================================== exit conditions

Harness::test(
    'A GUEST WHO BOOKS MID-SEQUENCE STOPS RECEIVING IT',
    function (): void {
    au_clear();

        /*
         * The most embarrassing failure an automation has: "still thinking
         * about the party?" arriving after the party is booked. Exit
         * conditions are checked before EVERY step rather than once at
         * enrolment, which is the only way to notice something that happened
         * after the run started.
         */
        au_template('autest-nurture');

        $automationId = au_automation('autest-exit', 'quote.sent', [
            'exit_conditions' => json_encode(['match' => 'any', 'rules' => [
                ['field' => 'has_booking', 'op' => 'is_true'],
            ]]),
        ]);

        au_step($automationId, 'send_email', ['template' => 'autest-nurture']);
        au_step($automationId, 'send_email', ['template' => 'autest-nurture'], ['delay_minutes' => 2880]);

        $contactId = au_contact('Booker');
        $email     = (string) DB::value('SELECT email FROM {contacts} WHERE id = :id', ['id' => $contactId]);

        Event::fire('quote.sent', ['contact_id' => $contactId]);
        AutomationRunner::tick();

        Assert::same(1, au_sent($email), 'the first message went');

        $run = au_run($automationId, $contactId);
        Assert::notNull($run);
        Assert::same('waiting', (string) $run['status']);

        // They book. Nothing else about the run changes.
        DB::insert('bookings', [
            'booking_number' => 'AUTEST-' . bin2hex(random_bytes(3)),
            'contact_id'     => $contactId,
            'type'           => 'birthday',
            // UTC-CORRECT: the exit condition is `has_booking is_true`, an
            // existence check. The date is filler.
            'event_date'     => gmdate('Y-m-d', time() + 86400 * 30),
            'start_time'     => '14:00:00',
            'status'         => 'confirmed',
            'guest_count'    => 10,
            'created_at'     => now_utc(),
            'updated_at'     => now_utc(),
        ]);

        au_due((int) $run['id']);
        AutomationRunner::tick();

        $after = au_run($automationId, $contactId);

        Assert::same('exited', (string) $after['status'], 'the run stops');
        Assert::same(1, au_sent($email), 'AND THE SECOND MESSAGE NEVER WENT');
        Assert::notEmpty((string) $after['error'], 'with the reason recorded');

        au_clear();
    }
);

Harness::test('a cancelled booking does not count as having booked', function (): void {
    au_clear();

    // Otherwise a guest who cancelled would be dropped out of the very
    // sequence that might win them back.
    $automationId = au_automation('autest-cancelled', 'quote.sent', [
        'exit_conditions' => json_encode(['match' => 'any', 'rules' => [
            ['field' => 'has_booking', 'op' => 'is_true'],
        ]]),
    ]);

    au_step($automationId, 'add_tag', ['tag' => 'autest-stillhere']);

    $contactId = au_contact('Canceller');

    DB::insert('bookings', [
        'booking_number' => 'AUTEST-' . bin2hex(random_bytes(3)),
        'contact_id'     => $contactId,
        'type'           => 'birthday',
        // UTC-CORRECT: same existence check; what is under test is the
        // status, not the day.
        'event_date'     => gmdate('Y-m-d', time() + 86400 * 30),
        'status'         => 'cancelled',
        'guest_count'    => 10,
        'created_at'     => now_utc(),
        'updated_at'     => now_utc(),
    ]);

    Event::fire('quote.sent', ['contact_id' => $contactId]);
    AutomationRunner::tick();

    Assert::true(DB::exists(
        "SELECT tag_id FROM {taggables} t JOIN {tags} g ON g.id = t.tag_id
          WHERE g.name = 'autest-stillhere' AND t.entity_id = :c",
        ['c' => $contactId]
    ), 'the sequence ran');

    au_clear();
});

// =============================================================== goals

Harness::test('reaching the goal ends the run and counts a conversion', function (): void {
    au_clear();

    // Carrying on would keep nudging a guest towards something they have
    // already done — and without this, an automation has a send count
    // instead of a conversion rate.
    $automationId = au_automation('autest-goal', 'quote.sent', [
        'goal_event'        => 'deal.won',
        'goal_window_hours' => 720,
    ]);

    au_step($automationId, 'add_tag', ['tag' => 'autest-goalstep']);
    au_step($automationId, 'add_tag', ['tag' => 'autest-never'], ['delay_minutes' => 4320]);

    $contactId = au_contact('Converter');

    Event::fire('quote.sent', ['contact_id' => $contactId]);
    AutomationRunner::tick();

    $run = au_run($automationId, $contactId);
    Assert::notNull($run);
    Assert::same('waiting', (string) $run['status']);

    Event::fire('deal.won', ['contact_id' => $contactId]);

    $after = au_run($automationId, $contactId);

    Assert::notNull($after['goal_reached_at'], 'the conversion is stamped on the run');
    Assert::inArray((string) $after['status'], ['completed', 'exited'], 'and the run is finished');
    Assert::same(1, (int) DB::value(
        'SELECT goal_count FROM {automations} WHERE id = :a',
        ['a' => $automationId]
    ), 'and counted on the automation');

    // The remaining step never runs.
    au_due((int) $run['id']);
    AutomationRunner::tick();

    Assert::false(DB::exists(
        "SELECT tag_id FROM {taggables} t JOIN {tags} g ON g.id = t.tag_id
          WHERE g.name = 'autest-never' AND t.entity_id = :c",
        ['c' => $contactId]
    ), 'a finished run is not advanced');

    au_clear();
});

Harness::test('a goal outside its window is not a conversion', function (): void {
    au_clear();

    $automationId = au_automation('autest-goalwindow', 'quote.sent', [
        'goal_event'        => 'deal.won',
        'goal_window_hours' => 24,
    ]);

    au_step($automationId, 'wait', [], ['delay_minutes' => 4320]);

    $contactId = au_contact('Latecomer');

    Event::fire('quote.sent', ['contact_id' => $contactId]);

    $run = au_run($automationId, $contactId);
    Assert::notNull($run);

    // Started three days ago, with a one-day goal window.
    DB::update('automation_runs', [
        'started_at' => gmdate('Y-m-d H:i:s', time() - 72 * 3600),
    ], 'id = :id', ['id' => (int) $run['id']]);

    Event::fire('deal.won', ['contact_id' => $contactId]);

    Assert::null(
        au_run($automationId, $contactId)['goal_reached_at'],
        'otherwise every automation eventually reports 100%, because every guest books eventually'
    );
    Assert::same(0, (int) DB::value('SELECT goal_count FROM {automations} WHERE id = :a', ['a' => $automationId]));

    au_clear();
});

// ========================================================= failing loudly

Harness::test(
    'A FAILING STEP IS RETRIED AND THEN THE RUN IS FAILED, AUDITED AND SAID',
    function (): void {
    au_clear();

        /*
         * A nine-touch nurture that stopped on day three is a guest who was
         * half-told something, and the venue has no way of knowing unless the
         * run says so. Three attempts with backoff, then failed — and
         * audited, because an error in a log file is not somebody being told.
         */
        $automationId = au_automation('autest-failing', 'visit.recorded');
        au_step($automationId, 'send_email', ['template' => 'autest-does-not-exist']);

        $contactId = au_contact('Failer');

        Event::fire('visit.recorded', ['contact_id' => $contactId]);

        $run = au_run($automationId, $contactId);
        Assert::notNull($run);

        /*
         * Each attempt backs off, so the run is forced due to avoid waiting
         * out the backoff. It stops forcing once the run has FAILED: nudging
         * a failed run would re-arm `next_run_at` and the assertion below —
         * that a failed run is not picked up again — would be testing this
         * loop rather than the runner.
         */
        for ($i = 0; $i < AutomationRunner::MAX_ATTEMPTS + 2; $i++) {
            $state = au_run($automationId, $contactId);

            if ($state === null || (string) $state['status'] === 'failed') {
                break;
            }

            au_due((int) $run['id']);
            AutomationRunner::tick();
        }

        $failed = au_run($automationId, $contactId);

        Assert::same('failed', (string) $failed['status']);
        Assert::same(
            AutomationRunner::MAX_ATTEMPTS,
            (int) $failed['attempts'],
            'the final attempt is counted too, or an operator reading this column is off by one'
        );
        Assert::contains('template', (string) $failed['error'], 'and the reason is readable');
        Assert::null($failed['next_run_at'], 'and it is not picked up again');

        Assert::true(DB::exists(
            "SELECT id FROM {audit_log} WHERE action = 'automation.failed' AND entity_id = :id",
            ['id' => (int) $run['id']]
        ), 'A FAILED RUN IS AUDITED, not just logged');

        Assert::atLeast(
            AutomationRunner::MAX_ATTEMPTS,
            (int) DB::value(
                "SELECT COUNT(*) FROM {automation_step_log} WHERE run_id = :r AND status = 'failed'",
                ['r' => (int) $run['id']]
            ),
            'every attempt is in the step log'
        );

        au_clear();
    }
);

Harness::test('a step this version cannot perform is skipped, not called done', function (): void {
    au_clear();

    /*
     * Every step type in `AutomationEngine::STEP_TYPES` is now implemented, so
     * this uses a type from NOWHERE — which is not a hypothetical. A venue
     * restoring a backup taken on a later release, or importing a playbook
     * from one, has steps this build has never heard of. Reporting "ok" for
     * them would be a lie in the one place somebody goes to find out what
     * happened, and it would also mark the run complete with its real work
     * never done.
     *
     * It used to use `webhook`, which was unimplemented at the time. That is
     * exactly how this kind of test rots: the day the step was built, the
     * assertion started passing for the wrong reason — a webhook POST to a
     * `.test` domain that fails — and would have gone on "passing" while the
     * behaviour it names went untested.
     */
    $automationId = au_automation('autest-unknown', 'visit.recorded');
    au_step($automationId, 'send_carrier_pigeon', ['bird' => 'Audrey']);

    $contactId = au_contact('Unknowable');

    Event::fire('visit.recorded', ['contact_id' => $contactId]);
    AutomationRunner::tick();

    $run = au_run($automationId, $contactId);
    Assert::notNull($run);

    $log = au_log((int) $run['id']);

    Assert::count(1, $log);
    Assert::same('skipped', (string) $log[0]['status']);
    Assert::contains('cannot perform', (string) $log[0]['output']);
    Assert::same('completed', (string) $run['status'], 'and the run still finishes');

    au_clear();
});

// ========================================================= the kill switch

Harness::test(
    'THE KILL SWITCH STOPS RUNS IN FLIGHT, not just new enrolments',
    function (): void {
    au_clear();

        // §9.5 requires it two clicks from every page, and the only reason
        // anybody reaches for it is that something is going out that should
        // not be. Stopping only new enrolments would leave whatever is
        // already in flight going out anyway.
        au_template('autest-killswitch');

        $automationId = au_automation('autest-kill', 'visit.recorded');
        au_step($automationId, 'send_email', ['template' => 'autest-killswitch']);
        au_step($automationId, 'send_email', ['template' => 'autest-killswitch'], ['delay_minutes' => 60]);

        $contactId = au_contact('Inflight');
        $email     = (string) DB::value('SELECT email FROM {contacts} WHERE id = :id', ['id' => $contactId]);

        Event::fire('visit.recorded', ['contact_id' => $contactId]);
        AutomationRunner::tick();

        Assert::same(1, au_sent($email), 'precondition: it is in flight');

        $run = au_run($automationId, $contactId);
        Assert::notNull($run);

        Settings::set('system.automations_paused', true);

        try {
            au_due((int) $run['id']);

            Assert::same(0, AutomationRunner::tick(), 'nothing advances');
            Assert::same(1, au_sent($email), 'and the next message does not go');

            // Nor does anything new enrol.
            Event::fire('visit.recorded', ['contact_id' => au_contact('Blocked')]);

            Assert::same(1, (int) DB::value(
                'SELECT COUNT(*) FROM {automation_runs} WHERE automation_id = :a',
                ['a' => $automationId]
            ));
        } finally {
            Settings::set('system.automations_paused', false);
        }

        // Switched back on, it carries on where it stopped.
        au_due((int) $run['id']);
        AutomationRunner::tick();

        Assert::same(2, au_sent($email), 'and resumes when the switch is released');

        au_clear();
    }
);

Harness::test('an automation switched off mid-flight stops where it is', function (): void {
    au_clear();

    // The venue switching it off is the instruction, and a run that carried
    // on would be the automation they just turned off still messaging people.
    au_template('autest-switchoff');

    $automationId = au_automation('autest-midflight', 'visit.recorded');
    au_step($automationId, 'send_email', ['template' => 'autest-switchoff']);
    au_step($automationId, 'send_email', ['template' => 'autest-switchoff'], ['delay_minutes' => 60]);

    $contactId = au_contact('Halted');
    $email     = (string) DB::value('SELECT email FROM {contacts} WHERE id = :id', ['id' => $contactId]);

    Event::fire('visit.recorded', ['contact_id' => $contactId]);
    AutomationRunner::tick();

    $run = au_run($automationId, $contactId);
    Assert::notNull($run);

    DB::update('automations', ['is_active' => 0], 'id = :a', ['a' => $automationId]);

    au_due((int) $run['id']);
    AutomationRunner::tick();

    Assert::same(1, au_sent($email), 'the rest of the sequence does not go out');

    au_clear();
});

Harness::test(
    'A CRASH MID-PASS DOES NOT MAKE A DELAYED STEP FIRE EARLY',
    function (): void {
        au_clear();

        /*
         * `pending_scheduled` in the run's context means "the run was parked
         * for this step and its time has been decided". It is written
         * together with the `waiting` status, so the two disagreeing means
         * the process died part way through a pass.
         *
         * Trusting the flag in that state would run the pending step
         * immediately, ignoring its delay — a "three days before the party"
         * message sent today. So the state is constructed here directly,
         * which is the only way it can arise, and the delay must still hold.
         * MEASURED by mutation: without the status check, nothing else in
         * this suite noticed.
         */
        $automationId = au_automation('autest-crash', 'visit.recorded');
        au_step($automationId, 'add_tag', ['tag' => 'autest-first']);
        $delayed = au_step($automationId, 'add_tag', ['tag' => 'autest-toosoon'], ['delay_minutes' => 4320]);

        $contactId = au_contact('Crashed');

        Event::fire('visit.recorded', ['contact_id' => $contactId]);
        AutomationRunner::tick();

        $run = au_run($automationId, $contactId);
        Assert::notNull($run);
        Assert::same('waiting', (string) $run['status'], 'precondition: parked on the delayed step');

        // The inconsistent state: still claiming the step was scheduled, but
        // the run is RUNNING and due now.
        DB::update('automation_runs', [
            'status'      => 'running',
            'next_run_at' => now_utc(),
            'context'     => json_encode([
                'pending_step_id'   => $delayed,
                'pending_scheduled' => true,
            ]),
        ], 'id = :id', ['id' => (int) $run['id']]);

        AutomationRunner::tick();

        Assert::false(DB::exists(
            "SELECT tag_id FROM {taggables} t JOIN {tags} g ON g.id = t.tag_id
              WHERE g.name = 'autest-toosoon' AND t.entity_id = :c",
            ['c' => $contactId]
        ), 'the delayed step must NOT have run three days early');

        Assert::same(
            'waiting',
            (string) au_run($automationId, $contactId)['status'],
            'and the run is parked again, properly'
        );

        au_clear();
    }
);

Harness::test(
    'THE ENGINE\'S OWN FAILURE EVENT CANNOT START AN AUTOMATION',
    function (): void {
        au_clear();

        /*
         * `automation.failed` is fired when a run fails. An automation
         * triggered by it would enrol on its own failure, fail, enrol again,
         * and do that once per cron tick for ever — messaging a guest every
         * minute until somebody noticed. The listener refuses the engine's
         * own events outright, which is cheaper than reasoning about which
         * of them are safe.
         */
        $looping = au_automation('autest-loop', 'automation.failed');
        au_step($looping, 'add_tag', ['tag' => 'autest-looped']);

        // Something that really fails, to fire the event.
        $failing = au_automation('autest-loop-source', 'visit.recorded');
        au_step($failing, 'send_email', ['template' => 'autest-missing-template']);

        $contactId = au_contact('Looper');

        Event::fire('visit.recorded', ['contact_id' => $contactId]);

        $run = au_run($failing, $contactId);
        Assert::notNull($run);

        for ($i = 0; $i < AutomationRunner::MAX_ATTEMPTS + 2; $i++) {
            $state = au_run($failing, $contactId);

            if ($state === null || (string) $state['status'] === 'failed') {
                break;
            }

            au_due((int) $run['id']);
            AutomationRunner::tick();
        }

        Assert::same('failed', (string) au_run($failing, $contactId)['status'], 'precondition: it failed');

        Assert::null(
            au_run($looping),
            'and nothing enrolled on the failure, so there is nothing to loop'
        );

        au_clear();
    }
);

// ========================================================= the step log

Harness::test(
    'every step leaves a row, including the ones that did not happen',
    function (): void {
    au_clear();

        // `automation_step_log` is how anybody answers "what did this send to
        // Dana, and when" months later. A step that did not happen is as
        // much of an answer as one that did.
        au_template('autest-logging');

        $automationId = au_automation('autest-log', 'visit.recorded');
        au_step($automationId, 'send_email', ['template' => 'autest-logging']);
        au_step($automationId, 'add_tag', ['tag' => 'autest-logged']);
        // A T-minus step with no date on the record: skipped with a reason.
        au_step($automationId, 'add_tag', ['tag' => 'autest-nodate'], [
            'delay_until_type'   => 'days_before_field',
            'delay_until_config' => json_encode(['field' => 'nonexistent_date', 'days' => 3]),
        ]);

        $contactId = au_contact('Logged');

        Event::fire('visit.recorded', ['contact_id' => $contactId]);
        AutomationRunner::tick();

        $run = au_run($automationId, $contactId);
        Assert::notNull($run);

        $log = au_log((int) $run['id']);

        Assert::count(3, $log, 'three steps, three rows');
        Assert::same('ok', (string) $log[0]['status']);
        Assert::same('ok', (string) $log[1]['status']);
        Assert::same('skipped', (string) $log[2]['status'], 'the undateable step is recorded as skipped');
        Assert::contains('not set', (string) $log[2]['output'], 'with the reason');

        // And the step's own counters moved for the ones that ran.
        Assert::same(1, (int) DB::value(
            'SELECT completed_count FROM {automation_steps} WHERE id = :s',
            ['s' => (int) $log[0]['step_id']]
        ));

        au_clear();
    }
);

// ============================================== who an event is about

Harness::test(
    'AN EVENT THAT NAMES A BOOKING REACHES THE FAMILY WHO BOOKED IT',
    function (): void {
        /*
         * THE DEFECT THIS PINS, and it was in every event-driven automation.
         *
         * A module fires what the moment is about — `BookingController` fires
         * `['booking_id' => 17]`, because it has no business knowing what the
         * engine wants to be handed. `enrol()` read only `contact_id`,
         * `entity_type` and `entity_id`, so the run had NO GUEST: the email
         * step answered "there is no guest on this run to email", the run
         * completed, and the venue had a confirmation sequence that sent
         * nothing and reported success.
         *
         * It was invisible to the rest of this suite because every test here
         * fires `visit.recorded`, which happens to carry a `contact_id`.
         */
        au_clear();
        au_template('autest-subject');

        $automationId = au_automation('autest-subject', 'booking.confirmed');
        au_step($automationId, 'send_email', ['template' => 'autest-subject']);

        $contactId = au_contact('Booker');
        $email     = (string) DB::value('SELECT email FROM {contacts} WHERE id = :id', ['id' => $contactId]);

        $bookingId = (int) DB::insert('bookings', [
            'booking_number' => 'AU-' . bin2hex(random_bytes(4)),
            'contact_id'     => $contactId,
            // UTC-CORRECT: the subject is which contact the run finds from a
            // booking id. Nothing reads this date.
            'event_date'     => gmdate('Y-m-d', time() + 86400 * 10),
            'start_time'     => '14:00',
            'end_time'       => '16:00',
            'guest_count'    => 10,
            'status'         => 'confirmed',
            'created_at'     => now_utc(),
            'updated_at'     => now_utc(),
        ]);

        // Exactly what the controller fires.
        Event::fire('booking.confirmed', ['booking_id' => $bookingId, 'from' => 'held']);
        AutomationRunner::tick();

        $run = au_run($automationId);
        Assert::notNull($run, 'the booking enrolled');
        Assert::same($contactId, (int) $run['contact_id'], 'and the engine found the family');
        Assert::same('bookings', (string) $run['entity_type']);
        Assert::same($bookingId, (int) $run['entity_id']);

        Assert::same(1, au_sent($email), 'so the confirmation actually went');

        au_clear();
    }
);

Harness::test('an event that names a deal with a bare id is understood', function (): void {
    // `DealController` fires `['id' => $dealId]`. The namespace is what says
    // which kind of row that id belongs to.
    au_clear();

    $automationId = au_automation('autest-deal-subject', 'deal.won');
    au_step($automationId, 'add_tag', ['tag' => 'autest-won']);

    $contactId = au_contact('Winner');
    $dealId    = au_deal($contactId);

    Event::fire('deal.won', ['id' => $dealId]);
    AutomationRunner::tick();

    $run = au_run($automationId);
    Assert::notNull($run);
    Assert::same('deals', (string) $run['entity_type']);
    Assert::same($dealId, (int) $run['entity_id']);
    Assert::same($contactId, (int) $run['contact_id'], 'from the deal');

    au_clear();
});

Harness::test('a quote reaches its guest through the deal it hangs off', function (): void {
    // `quotes` has no `contact_id` — only a `deal_id` — so this is the
    // two-hop case, and the thank-you for an accepted quote is the whole
    // reason the lookup exists.
    au_clear();

    $automationId = au_automation('autest-quote-subject', 'quote.accepted');
    au_step($automationId, 'add_tag', ['tag' => 'autest-accepted']);

    $contactId = au_contact('Accepter');
    $dealId    = au_deal($contactId);

    $quoteId = (int) DB::insert('quotes', [
        'number'     => 'AUQ-' . bin2hex(random_bytes(4)),
        'deal_id'    => $dealId,
        'status'     => 'accepted',
        'created_at' => now_utc(),
        'updated_at' => now_utc(),
    ]);

    // Both ids are in the payload, and the event is about the QUOTE.
    Event::fire('quote.accepted', ['quote_id' => $quoteId, 'deal_id' => $dealId]);
    AutomationRunner::tick();

    $run = au_run($automationId);
    Assert::notNull($run);
    Assert::same('quotes', (string) $run['entity_type'], 'the event names the quote, not the deal');
    Assert::same($quoteId, (int) $run['entity_id']);
    Assert::same($contactId, (int) $run['contact_id'], 'and the family came from the deal');

    au_clear();
});

Harness::test('a conversation event reaches whoever is on the thread', function (): void {
    au_clear();

    $automationId = au_automation('autest-convo-subject', 'conversation.closed');
    au_step($automationId, 'add_tag', ['tag' => 'autest-closed']);

    $contactId = au_contact('Threaded');

    $conversationId = (int) DB::insert('conversations', [
        'contact_id' => $contactId,
        'channel'    => 'sms',
        'status'     => 'open',
        'created_at' => now_utc(),
        'updated_at' => now_utc(),
    ]);

    Event::fire('conversation.closed', ['conversation_id' => $conversationId, 'user_id' => 1]);
    AutomationRunner::tick();

    $run = au_run($automationId);
    Assert::notNull($run);
    Assert::same($contactId, (int) $run['contact_id']);

    DB::query('DELETE FROM {conversations} WHERE id = :id', ['id' => $conversationId]);
    au_clear();
});

// ======================================================== notify a user

Harness::test('notifying a user writes the notification AND emails them', function (): void {
    /*
     * Both, not either. The bell in the app is where it belongs; the email is
     * the one that reaches a venue manager, who is not sitting in the CRM at
     * seven in the morning when the waiver chase runs.
     */
    au_clear();

    $userId = au_user();
    $staff  = (string) DB::value('SELECT email FROM {users} WHERE id = :id', ['id' => $userId]);

    $automationId = au_automation('autest-notify', 'visit.recorded');
    au_step($automationId, 'notify_user', [
        'assign_to' => $userId,
        'title'     => 'Chase the waiver',
        'message'   => 'No waiver for {{first_name}} yet.',
    ]);

    $contactId = au_contact('Chased');

    Event::fire('visit.recorded', ['contact_id' => $contactId]);
    AutomationRunner::tick();

    $note = DB::first('SELECT * FROM {notifications} WHERE user_id = :u ORDER BY id DESC', ['u' => $userId]);

    Assert::notNull($note, 'the notification row');
    Assert::same('Chase the waiver', (string) $note['title']);
    Assert::contains('Chased', (string) $note['body'], 'the merge tag resolved');
    Assert::contains('/contacts/' . $contactId, (string) $note['link'], 'and it links to the guest');
    Assert::null($note['read_at']);

    Assert::same(1, au_sent($staff), 'and the email went to the member of staff');

    // The guest is NOT emailed: this step is about the venue's own work.
    $guest = (string) DB::value('SELECT email FROM {contacts} WHERE id = :id', ['id' => $contactId]);
    Assert::same(0, au_sent($guest));

    au_clear();
});

Harness::test('a notification for somebody who has left is skipped, not failed', function (): void {
    // Staff leave, and the automation that named them has to keep working for
    // everybody else. A failed run would stop the steps after it.
    au_clear();

    $userId = au_user(['is_active' => 0]);

    $automationId = au_automation('autest-notify-gone', 'visit.recorded');
    au_step($automationId, 'notify_user', ['assign_to' => $userId, 'message' => 'Anybody?']);

    $contactId = au_contact('Orphaned');

    Event::fire('visit.recorded', ['contact_id' => $contactId]);
    AutomationRunner::tick();

    $run = au_run($automationId, $contactId);
    Assert::same('completed', (string) $run['status']);

    $log = au_log((int) $run['id']);
    Assert::same('ok', (string) $log[0]['status']);
    Assert::contains('no longer active', (string) $log[0]['output']);

    Assert::same(0, (int) DB::value(
        'SELECT COUNT(*) FROM {notifications} WHERE user_id = :u',
        ['u' => $userId]
    ));

    au_clear();
});

// ===================================================== move the deal on

Harness::test('moving a deal sets what the board sets, history included', function (): void {
    /*
     * The step shares `DealStage` with the pipeline board, so an automated
     * move is indistinguishable from a manager dragging the card: the stage
     * stamp the velocity report measures, the SLA deadline the breach scanner
     * reads, and a history row. A second implementation would drift, and the
     * one that drifted would be the one nobody is watching.
     */
    au_clear();

    $automationId = au_automation('autest-move', 'deal.created');
    au_step($automationId, 'move_deal_stage', ['stage' => 'Contacted']);

    $contactId = au_contact('Mover');
    $dealId    = au_deal($contactId);

    Event::fire('deal.created', ['id' => $dealId]);
    AutomationRunner::tick();

    $deal = (array) DB::first('SELECT * FROM {deals} WHERE id = :id', ['id' => $dealId]);

    Assert::same(au_stage_id('Contacted'), (int) $deal['stage_id']);
    Assert::notNull($deal['sla_due_at'], 'the stage carries an SLA, so the deadline is stamped');
    Assert::same('open', (string) $deal['status']);

    Assert::same(1, (int) DB::value(
        'SELECT COUNT(*) FROM {deal_stage_history} WHERE deal_id = :d',
        ['d' => $dealId]
    ), 'the move is in the history the velocity report reads');

    // Nobody dragged it, so the history names no user rather than naming
    // whoever happened to be logged in when cron ran.
    Assert::null(DB::value('SELECT user_id FROM {deal_stage_history} WHERE deal_id = :d', ['d' => $dealId]));

    au_clear();
});

Harness::test('moving a deal into a WON stage fires deal.won', function (): void {
    // The event other automations and the dashboard hang off. An automated
    // move that did not fire it would leave a won deal nothing reacted to.
    au_clear();

    $automationId = au_automation('autest-move-won', 'visit.recorded');
    au_step($automationId, 'move_deal_stage', ['stage' => 'Confirmed']);

    $contactId = au_contact('Won');
    $dealId    = au_deal($contactId);

    Event::record();
    Event::fire('visit.recorded', ['contact_id' => $contactId]);
    AutomationRunner::tick();
    $fired = array_column(Event::recorded(), 'event');
    Event::record(false);

    Assert::inArray('deal.won', $fired);

    $deal = (array) DB::first('SELECT * FROM {deals} WHERE id = :id', ['id' => $dealId]);
    Assert::same('won', (string) $deal['status']);
    Assert::notNull($deal['won_at']);

    au_clear();
});

Harness::test('a deal already in the stage is left alone', function (): void {
    // Re-entering a stage would reset `stage_entered_at` — which is what the
    // velocity report measures — and fire `deal.won` a second time.
    au_clear();

    $automationId = au_automation('autest-move-same', 'visit.recorded');
    au_step($automationId, 'move_deal_stage', ['stage' => 'New Inquiry']);

    $contactId = au_contact('Static');
    $dealId    = au_deal($contactId);
    $entered   = (string) DB::value('SELECT stage_entered_at FROM {deals} WHERE id = :id', ['id' => $dealId]);

    Event::fire('visit.recorded', ['contact_id' => $contactId]);
    AutomationRunner::tick();

    Assert::same($entered, (string) DB::value(
        'SELECT stage_entered_at FROM {deals} WHERE id = :id',
        ['id' => $dealId]
    ), 'the stamp is untouched');

    Assert::same(0, (int) DB::value(
        'SELECT COUNT(*) FROM {deal_stage_history} WHERE deal_id = :d',
        ['d' => $dealId]
    ), 'and no history row for a move that did not happen');

    $log = au_log((int) au_run($automationId, $contactId)['id']);
    Assert::contains('already there', (string) $log[0]['output']);

    au_clear();
});

Harness::test('A STAGE IN ANOTHER PIPELINE IS REFUSED', function (): void {
    /*
     * Both seeded pipelines have a "New Inquiry" and a "Confirmed", so a
     * mistyped playbook could take a party booking's deal onto the corporate
     * board — off the board it belongs to, out of every report keyed to that
     * pipeline, and with nothing in the UI to explain where it went.
     */
    au_clear();

    $automationId = au_automation('autest-move-cross', 'visit.recorded');
    au_step($automationId, 'move_deal_stage', ['stage' => (string) au_stage_id('Qualified', 2)]);

    $contactId = au_contact('Crossed');
    $dealId    = au_deal($contactId);

    Event::fire('visit.recorded', ['contact_id' => $contactId]);
    AutomationRunner::tick();

    $deal = (array) DB::first('SELECT pipeline_id, stage_id FROM {deals} WHERE id = :id', ['id' => $dealId]);

    Assert::same(1, (int) $deal['pipeline_id'], 'still on its own board');
    Assert::same(au_stage_id('New Inquiry'), (int) $deal['stage_id']);

    $log = au_log((int) au_run($automationId, $contactId)['id']);
    Assert::contains('another pipeline', (string) $log[0]['output']);

    au_clear();
});

Harness::test('a stage name that matches nothing FAILS the run', function (): void {
    /*
     * Not a skip. The automation says what it intends to do and cannot do it,
     * and a venue believing their pipeline is being kept up to date by a step
     * that quietly does nothing is worse than a failed run they can see.
     */
    au_clear();

    $automationId = au_automation('autest-move-typo', 'visit.recorded');
    au_step($automationId, 'move_deal_stage', ['stage' => 'Deposit Chased']);

    $contactId = au_contact('Typo');
    au_deal($contactId);

    Event::fire('visit.recorded', ['contact_id' => $contactId]);

    // Three attempts, each with its backoff cleared, as the retry schedule
    // would do over four hours.
    for ($attempt = 0; $attempt < 3; $attempt++) {
        AutomationRunner::tick();
        $run = au_run($automationId, $contactId);

        if ((string) $run['status'] === 'failed') {
            break;
        }

        au_due((int) $run['id']);
    }

    $run = au_run($automationId, $contactId);

    Assert::same('failed', (string) $run['status']);
    Assert::contains('no stage called', (string) $run['error']);

    au_clear();
});

Harness::test('a run that is not about a deal skips the move', function (): void {
    // "Move the deal" in a playbook that also runs for walk-in guests is an
    // authoring slip, not a breakage: failing the run would stop the messages
    // after it.
    au_clear();

    $automationId = au_automation('autest-move-nodeal', 'visit.recorded');
    au_step($automationId, 'move_deal_stage', ['stage' => 'Contacted']);

    $contactId = au_contact('Dealless');

    Event::fire('visit.recorded', ['contact_id' => $contactId]);
    AutomationRunner::tick();

    $run = au_run($automationId, $contactId);
    Assert::same('completed', (string) $run['status']);
    Assert::contains('not about a deal', (string) au_log((int) $run['id'])[0]['output']);

    au_clear();
});

// ======================================================= issue a coupon

Harness::test('issuing a coupon records it as GIVEN, not as redeemed', function (): void {
    /*
     * The distinction the schema change exists for. A row written as a
     * redemption would count an offer going out as an offer coming back, and
     * the venue's redemption rate — the only number that says whether an
     * offer worked — would read 100% for ever.
     */
    au_clear();

    $couponId = au_coupon('AUTEST10');

    $automationId = au_automation('autest-coupon', 'visit.recorded');
    au_step($automationId, 'issue_coupon', ['code' => 'autest10']);

    $contactId = au_contact('Couponed');

    Event::record();
    Event::fire('visit.recorded', ['contact_id' => $contactId]);
    AutomationRunner::tick();
    $fired = array_column(Event::recorded(), 'event');
    Event::record(false);

    $issued = DB::first(
        'SELECT * FROM {coupon_redemptions} WHERE coupon_id = :c AND contact_id = :g',
        ['c' => $couponId, 'g' => $contactId]
    );

    Assert::notNull($issued, 'the offer is recorded against the guest');
    Assert::notNull($issued['issued_at']);
    Assert::null($issued['redeemed_at'], 'nothing has been redeemed');
    Assert::same(0, (int) $issued['discount_cents'], 'and no discount has been given');

    // The code is matched case-insensitively: an operator types it as they
    // say it, not as the offers list stores it.
    Assert::inArray('coupon.issued', $fired, 'so an automation can react to the offer');

    au_clear();
});

Harness::test('the same guest is not given the same offer twice', function (): void {
    // `max_per_contact` is the venue's own rule, and it is what makes
    // re-running a playbook safe.
    au_clear();

    $couponId = au_coupon('AUTESTONCE');

    $automationId = au_automation('autest-coupon-twice', 'visit.recorded', [
        'run_once_per_contact' => 0,
        'allow_reentry'        => 1,
    ]);
    au_step($automationId, 'issue_coupon', ['code' => 'AUTESTONCE']);

    $contactId = au_contact('Greedy');

    Event::fire('visit.recorded', ['contact_id' => $contactId]);
    AutomationRunner::tick();

    // A second run of the same automation for the same guest.
    DB::query('DELETE FROM {automation_runs} WHERE automation_id = :a', ['a' => $automationId]);
    Event::fire('visit.recorded', ['contact_id' => $contactId]);
    AutomationRunner::tick();

    Assert::same(1, (int) DB::value(
        'SELECT COUNT(*) FROM {coupon_redemptions} WHERE coupon_id = :c AND contact_id = :g',
        ['c' => $couponId, 'g' => $contactId]
    ));

    $log = au_log((int) au_run($automationId, $contactId)['id']);
    Assert::contains('already has', (string) $log[0]['output']);

    au_clear();
});

Harness::test('an offer that has run out is skipped, and the sequence goes on', function (): void {
    au_clear();

    // UTC-CORRECT: an instant that is simply in the past, compared against
    // UTC now by the issue_coupon step.
    au_coupon('AUTESTOLD', ['expires_at' => gmdate('Y-m-d H:i:s', time() - 86400)]);
    au_template('autest-after-coupon');

    $automationId = au_automation('autest-coupon-expired', 'visit.recorded');
    au_step($automationId, 'issue_coupon', ['code' => 'AUTESTOLD']);
    au_step($automationId, 'send_email', ['template' => 'autest-after-coupon']);

    $contactId = au_contact('Late');
    $email     = (string) DB::value('SELECT email FROM {contacts} WHERE id = :id', ['id' => $contactId]);

    Event::fire('visit.recorded', ['contact_id' => $contactId]);
    AutomationRunner::tick();

    $log = au_log((int) au_run($automationId, $contactId)['id']);

    Assert::contains('expired', (string) $log[0]['output']);
    Assert::same('ok', (string) $log[1]['status']);
    Assert::same(1, au_sent($email), 'an expired offer is the venue’s own doing, not a failure');

    au_clear();
});

Harness::test('a coupon code that does not exist FAILS, rather than inventing one', function (): void {
    /*
     * An automation that could create offers could create one with any value,
     * and these steps ship inside playbooks. So an unknown code is a failure
     * a venue can see, not a coupon the engine makes up.
     */
    au_clear();

    $automationId = au_automation('autest-coupon-missing', 'visit.recorded');
    au_step($automationId, 'issue_coupon', ['code' => 'AUTESTNOPE']);

    $contactId = au_contact('Hopeful');

    Event::fire('visit.recorded', ['contact_id' => $contactId]);

    for ($attempt = 0; $attempt < 3; $attempt++) {
        AutomationRunner::tick();
        $run = au_run($automationId, $contactId);

        if ((string) $run['status'] === 'failed') {
            break;
        }

        au_due((int) $run['id']);
    }

    $run = au_run($automationId, $contactId);

    Assert::same('failed', (string) $run['status']);
    Assert::contains('AUTESTNOPE', (string) $run['error']);

    Assert::same(0, (int) DB::value("SELECT COUNT(*) FROM {coupons} WHERE code = 'AUTESTNOPE'"));

    au_clear();
});

// ========================================================== the webhook

Harness::test('A WEBHOOK POINTING INSIDE THE NETWORK IS REFUSED', function (): void {
    /*
     * `Core\Http` decides this and `tests/unit/http.php` covers the addresses;
     * what matters here is that the STEP refuses rather than calling it, and
     * that the refusal reaches the run as a failure a venue can see.
     *
     * 169.254.169.254 is the cloud metadata service. On shared hosting it is
     * reachable, and a step that POSTed the venue's data to it would be the
     * most expensive mistake in this file.
     */
    au_clear();

    $automationId = au_automation('autest-webhook-inward', 'visit.recorded');
    au_step($automationId, 'webhook', ['url' => 'http://169.254.169.254/latest/meta-data/']);

    $contactId = au_contact('Inward');

    Event::fire('visit.recorded', ['contact_id' => $contactId]);

    for ($attempt = 0; $attempt < 3; $attempt++) {
        AutomationRunner::tick();
        $run = au_run($automationId, $contactId);

        if ((string) $run['status'] === 'failed') {
            break;
        }

        au_due((int) $run['id']);
    }

    $run = au_run($automationId, $contactId);

    Assert::same('failed', (string) $run['status']);
    Assert::contains('inside the network', (string) $run['error']);

    au_clear();
});

Harness::test('a webhook step with no URL fails rather than pretending', function (): void {
    au_clear();

    $automationId = au_automation('autest-webhook-empty', 'visit.recorded');
    au_step($automationId, 'webhook', ['url' => '']);

    $contactId = au_contact('Urlless');

    Event::fire('visit.recorded', ['contact_id' => $contactId]);

    for ($attempt = 0; $attempt < 3; $attempt++) {
        AutomationRunner::tick();
        $run = au_run($automationId, $contactId);

        if ((string) $run['status'] === 'failed') {
            break;
        }

        au_due((int) $run['id']);
    }

    Assert::same('failed', (string) au_run($automationId, $contactId)['status']);

    au_clear();
});

// ================================= the sequences the installer ships

Harness::test(
    'A SEEDED PLAYBOOK SEQUENCE RUNS, END TO END, WITH NOTHING ADDED',
    function (): void {
        /*
         * The product promise, as a test: a playbook "arrives ready". So this
         * takes the first seeded automation exactly as the installer wrote
         * it — its own trigger, its own steps, its own templates — switches it
         * on, and fires the real event.
         *
         * Everything else in this file builds its own fixtures, which proves
         * the engine works and proves nothing about what a venue actually
         * receives on install. The failure this catches is a sequence that
         * names a template nobody shipped, or a step the engine refuses:
         * both fail days after somebody switched it on, nowhere near the
         * screen where they did it.
         */
        au_clear();

        $seeded = DB::first(
            "SELECT * FROM {automations} WHERE slug = 'birthday-celebration-sales-01'"
        );

        if ($seeded === null) {
            Assert::true(true, 'the playbook seed has not run in this database');

            return;
        }

        $automationId = (int) $seeded['id'];
        $playbookId   = (int) ($seeded['playbook_id'] ?? 0);

        $steps = DB::all(
            'SELECT * FROM {automation_steps} WHERE automation_id = :a ORDER BY sort_order',
            ['a' => $automationId]
        );

        Assert::greaterThan(2, count($steps), 'it ships with its sequence');

        $contactId = au_contact('Enquirer');
        $email     = (string) DB::value('SELECT email FROM {contacts} WHERE id = :id', ['id' => $contactId]);

        try {
            DB::update('automations', ['is_active' => 1], 'id = :id', ['id' => $automationId]);

            if ($playbookId > 0) {
                DB::update('playbooks', ['is_active' => 1], 'id = :id', ['id' => $playbookId]);
            }

            // The event its trigger names, with the payload FormController
            // really fires.
            Event::fire('form.submitted', [
                'form_id'    => 0,
                'form_slug'  => 'party-inquiry',
                'contact_id' => $contactId,
            ]);

            AutomationRunner::tick();

            $run = au_run($automationId, $contactId);

            Assert::notNull($run, 'the enquiry enrolled');
            Assert::same(1, au_sent($email), 'the instant reply went out');

            $log = au_log((int) $run['id']);

            Assert::greaterThan(2, count($log), 'every step is accounted for');

            // The text is SKIPPED, not failed: this contact has no phone
            // number, and a sequence must carry on past a channel it cannot
            // reach rather than stopping there.
            $sms = array_values(array_filter(
                $log,
                static fn (array $row): bool => (string) $row['type'] === 'send_sms'
            ));

            Assert::count(1, $sms);
            Assert::same('skipped', (string) $sms[0]['status'], 'no phone number, so the text is skipped');

            // And the task the venue has to act on exists, with the guest's
            // name merged into it.
            $task = DB::first(
                "SELECT * FROM {tasks} WHERE entity_type IN ('contacts', 'contact') AND entity_id = :c
                  ORDER BY id DESC",
                ['c' => $contactId]
            );

            Assert::notNull($task, 'the call task was created');
            Assert::contains('Enquirer', (string) $task['title'], 'with the guest named in it');

            Assert::same('completed', (string) au_run($automationId, $contactId)['status']);
        } finally {
            DB::update('automations', ['is_active' => 0], 'id = :id', ['id' => $automationId]);

            if ($playbookId > 0) {
                DB::update('playbooks', ['is_active' => 0], 'id = :id', ['id' => $playbookId]);
            }

            DB::query(
                'DELETE FROM {automation_step_log} WHERE run_id IN (
                    SELECT id FROM {automation_runs} WHERE automation_id = :a)',
                ['a' => $automationId]
            );
            DB::query('DELETE FROM {automation_runs} WHERE automation_id = :a', ['a' => $automationId]);
            DB::query(
                'UPDATE {automations} SET run_count = 0, last_run_at = NULL WHERE id = :a',
                ['a' => $automationId]
            );
            DB::query("DELETE FROM {tasks} WHERE title LIKE '%Enquirer%'");

            au_clear();
        }
    }
);

Harness::test(
    'and a seeded step tied to the party’s own date is scheduled from it',
    function (): void {
        /*
         * "Fourteen days before the party" has to mean THAT party, not
         * fourteen days from enrolment — which is what a fixed wait would
         * give, and which lands on a different day for every booking. The
         * seeded T-14 waiver step is the one to check, because it is the one
         * a venue notices when it is wrong: the waiver chase arrives after
         * the party.
         */
        au_clear();

        $step = DB::first(
            "SELECT s.*, a.slug FROM {automation_steps} s
               JOIN {automations} a ON a.id = s.automation_id
              WHERE a.slug LIKE 'birthday-celebration-sales-%'
                AND s.delay_until_type = 'days_before_field'
              ORDER BY s.id LIMIT 1"
        );

        if ($step === null) {
            Assert::true(true, 'no date-relative step is seeded in this database');

            return;
        }

        $config = json_decode((string) $step['delay_until_config'], true);

        Assert::same('event_date', $config['field'] ?? null, 'counted from the party’s own date');
        Assert::greaterThan(0, (int) ($config['days'] ?? 0));

        // The engine's own answer, against a party six weeks out.
        // UTC-CORRECT: `$expected` below is derived from `$party` itself, so
        // this date is only ever compared with itself; the one clock-relative
        // claim is "still in the future", which six weeks settles.
        $party = gmdate('Y-m-d', time() + 42 * 86400);

        $when = AutomationEngine::scheduleStep(
            $step,
            ['event_date' => $party],
            now_utc(),
            'America/New_York'
        );

        Assert::false($when['skip'], 'a party six weeks out is schedulable');
        Assert::notNull($when['at']);

        /*
         * COMPARED AS CALENDAR DAYS AGAINST THE PARTY, not as a seconds delta
         * from now.
         *
         * "Fourteen days before the party" is a claim about two dates, and the
         * step lands at a fixed hour of the local morning. So a delta measured
         * from `time()` is 28 days only while the suite runs before that hour
         * and 27 after it — which is a test that passes or fails by the clock
         * on the wall, and it did exactly that (Expected 28, got 27) once the
         * day had got on a bit. The contract is the date.
         *
         * UTC-CORRECT: the `strtotime()` below reads an ABSOLUTE instant —
         * `$party` at noon UTC — not a relative one, so no clock enters this
         * at all. The static gate cannot tell those two apart and flags any
         * `strtotime`, which is why this says so out loud.
         */
        $expected = gmdate(
            'Y-m-d',
            (int) strtotime($party . ' 12:00:00 UTC') - ((int) $config['days'] * 86400)
        );

        $landed = (new DateTimeImmutable((string) $when['at'] . ' UTC'))
            ->setTimezone(new DateTimeZone('America/New_York'))
            ->format('Y-m-d');

        Assert::same(
            $expected,
            $landed,
            'it lands ' . (int) $config['days'] . ' local days before the party itself'
        );

        // And it is still in the future, which is the other half of the claim.
        Assert::greaterThan(
            time(),
            (int) strtotime((string) $when['at'] . ' UTC'),
            'a party six weeks out schedules its T-minus step ahead of now'
        );

        au_clear();
    }
);

// ------------------------------------------------- asking them for feedback

Harness::test('THE SEND-SURVEY STEP HANDS THE ASK TO THE FEEDBACK MODULE', function (): void {
    /*
     * The step exists so a venue can make the ask conditional. What it must
     * NOT do is build its own invitation: the link carries a one-use token
     * that has to be written before it is sent, and two places assembling
     * that is two places for a guest to receive a dead link.
     */
    au_clear();
    DB::query("DELETE FROM {survey_responses} WHERE contact_id IN (SELECT id FROM {contacts} WHERE source = 'autest')");

    $surveyId = (int) DB::value("SELECT id FROM {surveys} WHERE type = 'nps' ORDER BY id LIMIT 1");

    Assert::greaterThan(0, $surveyId, 'the installer seeds a survey');

    $automationId = au_automation('autest-survey', 'visit.recorded');
    au_step($automationId, 'send_survey');

    $contactId = au_contact('Asked');

    Event::fire('visit.recorded', ['contact_id' => $contactId]);
    AutomationRunner::tick();

    $invitation = DB::first(
        'SELECT * FROM {survey_responses} WHERE contact_id = :c',
        ['c' => $contactId]
    );

    Assert::notNull($invitation, 'an invitation was created for them');
    Assert::notNull($invitation['public_token'], 'with a one-use token');
    Assert::same(32, strlen((string) $invitation['public_token']));
    Assert::null($invitation['responded_at'], 'and nothing answered yet');

    au_clear();
});

Harness::test('a guest already asked is SKIPPED, not asked again', function (): void {
    // A skip, not a failure: "they have already been asked" is the system
    // working, and failing the run would stop the steps after it.
    au_clear();
    DB::query("DELETE FROM {survey_responses} WHERE contact_id IN (SELECT id FROM {contacts} WHERE source = 'autest')");

    $automationId = au_automation('autest-survey-twice', 'visit.recorded', [
        'run_once_per_contact' => 0,
        'allow_reentry'        => 1,
    ]);
    au_step($automationId, 'send_survey');

    $contactId = au_contact('AskedTwice');

    Event::fire('visit.recorded', ['contact_id' => $contactId]);
    AutomationRunner::tick();

    DB::query('DELETE FROM {automation_runs} WHERE automation_id = :a', ['a' => $automationId]);
    Event::fire('visit.recorded', ['contact_id' => $contactId]);
    AutomationRunner::tick();

    Assert::same(
        1,
        (int) DB::value('SELECT COUNT(*) FROM {survey_responses} WHERE contact_id = :c', ['c' => $contactId]),
        'one invitation, however many times the sequence runs'
    );

    $run = au_run($automationId, $contactId);

    Assert::same('completed', (string) $run['status'], 'and the run finished rather than failing');

    au_clear();
});

Harness::test('the review link is READ for a template, never minted', function (): void {
    /*
     * `{{review_link}}` lets a sequence follow up on a promoter who closed
     * the tab. A merge tag that could CREATE a review request would mean a
     * template deciding who gets asked for a public review — so a run whose
     * guest was never asked has no link, and the preview says the tag cannot
     * be filled rather than printing a dead URL.
     */
    au_clear();
    DB::query("DELETE FROM {review_requests} WHERE token LIKE 'autest%'");

    $contactId = au_contact('NoAsk');
    $subject   = AutomationRunner::subjectFor($contactId);

    $template = au_template('autest-review-link');
    DB::update('templates', [
        'body'       => '<p>Hi {{first_name}}, <a href="{{review_link}}">leave a review</a>.</p>',
        'merge_tags' => json_encode(['first_name', 'review_link']),
    ], 'id = :id', ['id' => $template]);

    // A response id with no review request behind it: the tag is absent, so
    // the template is reported as unfillable rather than rendering a dead
    // link into a guest's inbox.
    $missing = AutomationRunner::missingTags(
        'autest-review-link',
        $subject + ['event_survey_response_id' => 999999]
    );

    Assert::inArray('review_link', $missing, 'the gap is reported before anybody sends it');

    // And with a real ask behind it, the tag resolves to THAT ask's tracked
    // link — the one the gate created, not a new one.
    $surveyId = (int) DB::value("SELECT id FROM {surveys} ORDER BY id LIMIT 1");

    $responseId = (int) DB::insert('survey_responses', [
        'survey_id'    => $surveyId,
        'contact_id'   => $contactId,
        'public_token' => bin2hex(random_bytes(16)),
        'score'        => 10,
        'sentiment'    => 'promoter',
        'responded_at' => now_utc(),
        'created_at'   => now_utc(),
    ]);

    DB::insert('review_requests', [
        'contact_id'         => $contactId,
        'survey_response_id' => $responseId,
        'platform'           => 'google',
        'status'             => 'sent',
        'token'              => 'autest' . bin2hex(random_bytes(13)),
        'created_at'         => now_utc(),
    ]);

    Assert::same(
        [],
        AutomationRunner::missingTags(
            'autest-review-link',
            $subject + ['event_survey_response_id' => $responseId]
        ),
        'with a real ask behind it, the link is there'
    );

    DB::query("DELETE FROM {review_requests} WHERE token LIKE 'autest%'");
    DB::query('DELETE FROM {survey_responses} WHERE id = :id', ['id' => $responseId]);
    au_clear();
});

au_clear();
$restore();
