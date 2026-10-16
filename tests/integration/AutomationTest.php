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
    DB::query("DELETE FROM {contacts} WHERE source = 'autest'");
    DB::query("DELETE FROM {tags} WHERE name LIKE 'autest-%'");
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

    // A venue whose automation includes a step type this build does not
    // implement needs to know that. Reporting "ok" would be a lie in the
    // one place somebody goes to find out what happened.
    $automationId = au_automation('autest-unknown', 'visit.recorded');
    au_step($automationId, 'webhook', ['url' => 'https://example.test/hook']);

    $contactId = au_contact('Webhooked');

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

au_clear();
$restore();
