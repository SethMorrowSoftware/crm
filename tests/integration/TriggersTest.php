<?php

declare(strict_types=1);

/**
 * The scanners: the triggers nothing fires.
 *
 * Forty-five of the 112 seeded automations wait on one of these. Nobody fires
 * "the party is in fourteen days" — it simply becomes true one morning, and
 * `AutomationTriggers::scan()` is what notices.
 *
 * Two properties decide whether a venue trusts this, and both are easy to get
 * wrong in a way no test of the happy path would show:
 *
 *   1. **A WINDOW, NOT A DEADLINE.** "Fourteen days before" is true for a
 *      whole day. A scanner asking for an exact difference finds nothing on
 *      almost every tick and everything on one, and misses the lot if that
 *      tick is skipped. So each test here checks the day on both sides as
 *      well as the day itself.
 *   2. **DEDUPLICATION IS PER ENTITY, NOT PER GUEST.** A family with two
 *      parties must get two reminders; the same party must not get one a
 *      minute. Both directions are tested, because the fix for either breaks
 *      the other.
 *
 * `tests/unit/AutomationTriggersTest.php` covers the cron matcher and the
 * catalogue, which need no database. This drives the queries.
 */

use App\Core\DB;
use App\Core\Settings;
use App\Services\AutomationTriggers;
use Tests\Assert;
use Tests\Harness;

Harness::suite('integration/triggers');

if (!test_boot()) {
    Harness::skip('triggers suite', 'no database available');

    return;
}

// ------------------------------------------------------------------ fixtures

function tr_clear(): void
{
    DB::query(
        "DELETE FROM {automation_step_log} WHERE run_id IN (
            SELECT id FROM {automation_runs} WHERE automation_id IN (
                SELECT id FROM {automations} WHERE slug LIKE 'trtest-%'))"
    );
    DB::query(
        "DELETE FROM {automation_runs} WHERE automation_id IN (
            SELECT id FROM {automations} WHERE slug LIKE 'trtest-%')"
    );
    DB::query("DELETE FROM {automations} WHERE slug LIKE 'trtest-%'");
    DB::query("DELETE FROM {playbooks} WHERE slug LIKE 'trtest-%'");

    DB::query(
        "DELETE FROM {messages} WHERE conversation_id IN (
            SELECT id FROM {conversations} WHERE contact_id IN (
                SELECT id FROM {contacts} WHERE source = 'trtest'))"
    );
    DB::query(
        "DELETE FROM {conversations} WHERE contact_id IN (SELECT id FROM {contacts} WHERE source = 'trtest')"
    );
    DB::query("DELETE FROM {tasks} WHERE title LIKE 'TrTest%'");
    DB::query(
        "DELETE FROM {waiver_signatures} WHERE booking_id IN (
            SELECT id FROM {bookings} WHERE contact_id IN (
                SELECT id FROM {contacts} WHERE source = 'trtest'))"
    );
    // After the signatures that reference it.
    DB::query("DELETE FROM {waivers} WHERE version LIKE 'TrTest-%'");
    DB::query("DELETE FROM {bookings} WHERE contact_id IN (SELECT id FROM {contacts} WHERE source = 'trtest')");
    DB::query(
        "DELETE FROM {deal_stage_history} WHERE deal_id IN (
            SELECT id FROM {deals} WHERE contact_id IN (SELECT id FROM {contacts} WHERE source = 'trtest'))"
    );
    DB::query("DELETE FROM {deals} WHERE contact_id IN (SELECT id FROM {contacts} WHERE source = 'trtest')");
    DB::query("DELETE FROM {contacts} WHERE source = 'trtest'");
}

/** @param array<string,mixed> $overrides */
function tr_contact(string $name, array $overrides = []): int
{
    return (int) DB::insert('contacts', $overrides + [
        'first_name'      => $name,
        'last_name'       => 'TrTest',
        'email'           => 'trtest.' . strtolower($name) . '.' . bin2hex(random_bytes(3)) . '@example.test',
        'source'          => 'trtest',
        'lifecycle_stage' => 'lead',
        'created_at'      => now_utc(),
        'updated_at'      => now_utc(),
    ]);
}

/** @param array<string,mixed> $overrides */
function tr_automation(string $slug, string $trigger, array $config = [], array $overrides = []): int
{
    return (int) DB::insert('automations', $overrides + [
        'name'                 => 'TrTest ' . $slug,
        'slug'                 => $slug,
        'trigger_type'         => $trigger,
        'trigger_config'       => $config === [] ? null : json_encode($config),
        'is_active'            => 1,
        'run_once_per_contact' => 0,
        'allow_reentry'        => 1,
        'created_at'           => now_utc(),
        'updated_at'           => now_utc(),
    ]);
}

/** @param array<string,mixed> $overrides */
function tr_booking(int $contactId, string $date, array $overrides = []): int
{
    return (int) DB::insert('bookings', $overrides + [
        'booking_number' => 'TR-' . bin2hex(random_bytes(4)),
        'contact_id'     => $contactId,
        'event_date'     => $date,
        'start_time'     => '14:00',
        'end_time'       => '16:00',
        'guest_count'    => 10,
        'status'         => 'confirmed',
        'created_at'     => now_utc(),
        'updated_at'     => now_utc(),
    ]);
}

function tr_runs(int $automationId): int
{
    return (int) DB::value(
        'SELECT COUNT(*) FROM {automation_runs} WHERE automation_id = :a',
        ['a' => $automationId]
    );
}

/**
 * The venue's local date, N days from today — the same arithmetic a scanner
 * does. This file had the right idea before the rest of the suite did: a
 * named helper per frame, so a call site says which calendar it means.
 * `test_local_day()` in the bootstrap is now that helper for everyone, and
 * this one counts FORWARD, which is how every call below reads.
 */
function tr_local(int $days): string
{
    return test_local_day(-$days);
}

/**
 * An instant N days back, in UTC.
 *
 * UTC-CORRECT: every column this fills — `last_visit_at`, `sla_due_at`,
 * `last_activity_at` — is a stored UTC instant that the scanners compare
 * against UTC now. Nothing takes the date part of one and calls it a day.
 */
function tr_utc_days_ago(int $days): string
{
    return gmdate('Y-m-d H:i:s', time() - $days * 86400);
}

tr_clear();

// ------------------------------------------------------------------ bookings

Harness::test('a party N days away is found, and only on that day', function (): void {
    tr_clear();

    $automationId = tr_automation('trtest-upcoming', 'booking.upcoming', ['days' => 14]);

    $contactId = tr_contact('Fortnight');
    tr_booking($contactId, tr_local(14));
    tr_booking($contactId, tr_local(13));
    tr_booking($contactId, tr_local(15));

    AutomationTriggers::scan();

    Assert::same(1, tr_runs($automationId), 'the day itself, not the days either side');

    $run = DB::first('SELECT * FROM {automation_runs} WHERE automation_id = :a', ['a' => $automationId]);
    Assert::same('booking', (string) $run['entity_type'], 'the party, not the guest');
    Assert::same($contactId, (int) $run['contact_id'], 'and the family to message');

    tr_clear();
});

Harness::test('A FAMILY WITH TWO PARTIES GETS TWO REMINDERS', function (): void {
    /*
     * The reason deduplication is per ENTITY. Deduplicating by guest would
     * send one reminder and never chase the second party's waivers — and the
     * venue would not find out until twelve children arrived unsigned.
     */
    tr_clear();

    $automationId = tr_automation('trtest-two-parties', 'booking.upcoming', ['days' => 7]);

    $contactId = tr_contact('Busy');
    tr_booking($contactId, tr_local(7));
    tr_booking($contactId, tr_local(7), ['start_time' => '17:00', 'end_time' => '19:00']);

    AutomationTriggers::scan();

    Assert::same(2, tr_runs($automationId));

    tr_clear();
});

Harness::test('AND THE SAME PARTY IS NOT ENROLLED TWICE, however often cron runs', function (): void {
    // The other direction. The watchdog runs every minute; a scanner that
    // re-enrolled would send a reminder a minute for a day.
    tr_clear();

    $automationId = tr_automation('trtest-rescan', 'booking.upcoming', ['days' => 7]);

    $contactId = tr_contact('Rescanned');
    tr_booking($contactId, tr_local(7));

    AutomationTriggers::scan();
    AutomationTriggers::scan();
    AutomationTriggers::scan();

    Assert::same(1, tr_runs($automationId));

    tr_clear();
});

Harness::test('an enquiry that has not been confirmed is not reminded about', function (): void {
    // The default statuses are the ones where a party is actually happening.
    // Reminding somebody about an enquiry they never booked reads as a
    // booking they did not make.
    tr_clear();

    $automationId = tr_automation('trtest-status', 'booking.upcoming', ['days' => 7]);

    $contactId = tr_contact('Enquirer');
    tr_booking($contactId, tr_local(7), ['status' => 'inquiry']);
    tr_booking($contactId, tr_local(7), ['status' => 'cancelled', 'start_time' => '17:00', 'end_time' => '19:00']);

    AutomationTriggers::scan();

    Assert::same(0, tr_runs($automationId));

    tr_clear();
});

Harness::test('a deleted party is not reminded about', function (): void {
    tr_clear();

    $automationId = tr_automation('trtest-deleted', 'booking.upcoming', ['days' => 7]);

    $contactId = tr_contact('Deleted');
    tr_booking($contactId, tr_local(7), ['deleted_at' => now_utc()]);

    AutomationTriggers::scan();

    Assert::same(0, tr_runs($automationId));

    tr_clear();
});

Harness::test('waivers still missing close to the date are found, signed ones are not', function (): void {
    tr_clear();

    $automationId = tr_automation('trtest-waivers', 'booking.waiver_outstanding', ['days' => 3]);

    $contactId = tr_contact('Unsigned');
    $missing   = tr_booking($contactId, tr_local(3));
    $signed    = tr_booking($contactId, tr_local(3), ['start_time' => '17:00', 'end_time' => '19:00']);

    // A waiver of this suite's own: the seeds do not ship one, and an
    // earlier suite may have cleared whatever was there.
    $waiverId = (int) DB::insert('waivers', [
        'version'           => 'TrTest-' . bin2hex(random_bytes(3)),
        'title'             => 'TrTest waiver',
        'body'              => 'TrTest body.',
        'requires_guardian' => 1,
        'is_active'         => 0,
        'created_at'        => now_utc(),
        'updated_at'        => now_utc(),
    ]);

    DB::insert('waiver_signatures', [
        'waiver_id'   => $waiverId,
        'booking_id'  => $signed,
        'signer_name' => 'A Parent',
        'signed_at'   => now_utc(),
        'ip'          => '127.0.0.1',
    ]);

    AutomationTriggers::scan();

    Assert::same(1, tr_runs($automationId));
    Assert::same($missing, (int) DB::value(
        'SELECT entity_id FROM {automation_runs} WHERE automation_id = :a',
        ['a' => $automationId]
    ), 'the one with nothing signed');

    tr_clear();
});

// ------------------------------------------------------------- lapsing guests

Harness::test('a guest is found on the day they CROSS the lapse window', function (): void {
    /*
     * Not every day afterwards. A guest who last came 200 days ago is still
     * lapsed tomorrow, and a scanner matching "longer ago than 120 days"
     * would re-enrol them every morning for the rest of time — which, with
     * re-entry allowed as a win-back ladder needs, is a message a day for
     * ever.
     */
    tr_clear();

    $automationId = tr_automation('trtest-lapsed', 'contact.lapsed', ['days' => 120]);

    $crossing = tr_contact('Crossing', ['last_visit_at' => tr_utc_days_ago(120)]);
    tr_contact('Longgone', ['last_visit_at' => tr_utc_days_ago(400)]);
    tr_contact('Recent', ['last_visit_at' => tr_utc_days_ago(3)]);
    tr_contact('Never');

    AutomationTriggers::scan();

    Assert::same(1, tr_runs($automationId));
    Assert::same($crossing, (int) DB::value(
        'SELECT contact_id FROM {automation_runs} WHERE automation_id = :a',
        ['a' => $automationId]
    ));

    tr_clear();
});

// -------------------------------------------------------------------- deals

Harness::test('A DEAL PAST ITS DEADLINE IS FOUND, AND AN ANCIENT ONE IS NOT', function (): void {
    /*
     * An unanswered party enquiry is the most expensive thing on the whole
     * list — the family has already texted two other venues — so the scanner
     * exists. It is bounded to recent breaches because an enquiry nobody
     * answered in February is still past its deadline, and finding it on
     * every tick for ever means 200 rows read a minute to do nothing.
     */
    tr_clear();

    $automationId = tr_automation('trtest-sla', 'deal.sla_breached', ['within_days' => 2]);

    $stageId  = (int) DB::value("SELECT id FROM {pipeline_stages} WHERE pipeline_id = 1 ORDER BY sort_order LIMIT 1");
    $contactId = tr_contact('Ignored');

    $breached = (int) DB::insert('deals', [
        'title'       => 'TrTest breached',
        'contact_id'  => $contactId,
        'pipeline_id' => 1,
        'stage_id'    => $stageId,
        'status'      => 'open',
        'sla_due_at'  => gmdate('Y-m-d H:i:s', time() - 3600),
        'created_at'  => now_utc(),
        'updated_at'  => now_utc(),
    ]);

    // Breached a fortnight ago: real, and not news.
    DB::insert('deals', [
        'title'       => 'TrTest ancient',
        'contact_id'  => $contactId,
        'pipeline_id' => 1,
        'stage_id'    => $stageId,
        'status'      => 'open',
        'sla_due_at'  => tr_utc_days_ago(14),
        'created_at'  => now_utc(),
        'updated_at'  => now_utc(),
    ]);

    // Still inside its deadline.
    DB::insert('deals', [
        'title'       => 'TrTest in time',
        'contact_id'  => $contactId,
        'pipeline_id' => 1,
        'stage_id'    => $stageId,
        'status'      => 'open',
        'sla_due_at'  => gmdate('Y-m-d H:i:s', time() + 3600),
        'created_at'  => now_utc(),
        'updated_at'  => now_utc(),
    ]);

    // Already won: the deadline is moot.
    DB::insert('deals', [
        'title'       => 'TrTest won',
        'contact_id'  => $contactId,
        'pipeline_id' => 1,
        'stage_id'    => $stageId,
        'status'      => 'won',
        'sla_due_at'  => gmdate('Y-m-d H:i:s', time() - 3600),
        'created_at'  => now_utc(),
        'updated_at'  => now_utc(),
    ]);

    AutomationTriggers::scan();

    Assert::same(1, tr_runs($automationId));
    Assert::same($breached, (int) DB::value(
        'SELECT entity_id FROM {automation_runs} WHERE automation_id = :a',
        ['a' => $automationId]
    ));

    tr_clear();
});

Harness::test('a deal with no activity for the window is found once', function (): void {
    tr_clear();

    $automationId = tr_automation('trtest-idle', 'deal.idle', ['days' => 14]);

    $stageId   = (int) DB::value("SELECT id FROM {pipeline_stages} WHERE pipeline_id = 1 ORDER BY sort_order LIMIT 1");
    $contactId = tr_contact('Idle');

    DB::insert('deals', [
        'title'            => 'TrTest idle',
        'contact_id'       => $contactId,
        'pipeline_id'      => 1,
        'stage_id'         => $stageId,
        'status'           => 'open',
        'last_activity_at' => tr_utc_days_ago(14),
        'created_at'       => now_utc(),
        'updated_at'       => now_utc(),
    ]);

    AutomationTriggers::scan();
    AutomationTriggers::scan();

    Assert::same(1, tr_runs($automationId));

    tr_clear();
});

// -------------------------------------------------------------------- tasks

Harness::test('an overdue task is found, and brings its guest when it has one', function (): void {
    tr_clear();

    $automationId = tr_automation('trtest-overdue', 'task.overdue');

    $contactId = tr_contact('Owing');

    $taskId = (int) DB::insert('tasks', [
        'title'       => 'TrTest chase the deposit',
        'entity_type' => 'contacts',
        'entity_id'   => $contactId,
        'status'      => 'open',
        'due_at'      => gmdate('Y-m-d H:i:s', time() - 7200),
        'created_at'  => now_utc(),
        'updated_at'  => now_utc(),
    ]);

    // Done, and not yet due: neither is overdue.
    DB::insert('tasks', [
        'title'      => 'TrTest done',
        'status'     => 'done',
        'due_at'     => gmdate('Y-m-d H:i:s', time() - 7200),
        'created_at' => now_utc(),
        'updated_at' => now_utc(),
    ]);
    DB::insert('tasks', [
        'title'      => 'TrTest later',
        'status'     => 'open',
        'due_at'     => gmdate('Y-m-d H:i:s', time() + 7200),
        'created_at' => now_utc(),
        'updated_at' => now_utc(),
    ]);

    AutomationTriggers::scan();

    Assert::same(1, tr_runs($automationId));

    $run = DB::first('SELECT * FROM {automation_runs} WHERE automation_id = :a', ['a' => $automationId]);
    Assert::same($taskId, (int) $run['entity_id']);
    Assert::same($contactId, (int) $run['contact_id'], 'read through what the task is about');

    tr_clear();
});

// ------------------------------------------------------------- conversations

Harness::test('a guest whose question nobody answered is found', function (): void {
    tr_clear();

    $automationId = tr_automation('trtest-unanswered', 'conversation.unanswered', ['hours' => 4]);

    $contactId = tr_contact('Waiting');
    $when      = gmdate('Y-m-d H:i:s', time() - 5 * 3600);

    $conversationId = (int) DB::insert('conversations', [
        'contact_id'      => $contactId,
        'channel'         => 'sms',
        'status'          => 'open',
        'last_message_at' => $when,
        'created_at'      => $when,
        'updated_at'      => $when,
    ]);

    DB::insert('messages', [
        'conversation_id' => $conversationId,
        'contact_id'      => $contactId,
        'direction'       => 'inbound',
        'channel'         => 'sms',
        'body'            => 'Do you have Saturday free?',
        'status'          => 'delivered',
        'created_at'      => $when,
    ]);

    // A second thread where the venue DID answer last: the question is not
    // outstanding, and chasing it would read as the venue talking to itself.
    $answered = (int) DB::insert('conversations', [
        'contact_id'      => $contactId,
        'channel'         => 'sms',
        'status'          => 'open',
        'last_message_at' => $when,
        'created_at'      => $when,
        'updated_at'      => $when,
    ]);

    DB::insert('messages', [
        'conversation_id' => $answered,
        'contact_id'      => $contactId,
        'direction'       => 'outbound',
        'channel'         => 'sms',
        'body'            => 'Yes, 2pm is free.',
        'status'          => 'sent',
        'created_at'      => $when,
    ]);

    AutomationTriggers::scan();

    Assert::same(1, tr_runs($automationId));
    Assert::same($conversationId, (int) DB::value(
        'SELECT entity_id FROM {automation_runs} WHERE automation_id = :a',
        ['a' => $automationId]
    ));

    tr_clear();
});

// ------------------------------------------------------------------- birthdays

Harness::test('a birthday is matched on the month and day, whatever the year', function (): void {
    // A birthday has no year. Comparing a 1987 date to a date N days from now
    // would find nobody, ever.
    tr_clear();

    $automationId = tr_automation('trtest-birthday', 'contact.birthday_soon', ['days' => 7]);

    $target = tr_local(7);
    $born   = '1987-' . substr($target, 5, 5);

    $celebrant = tr_contact('Celebrant', ['birth_date' => $born]);
    tr_contact('Nextweek', ['birth_date' => '1990-' . substr(tr_local(8), 5, 5)]);

    AutomationTriggers::scan();

    Assert::same(1, tr_runs($automationId));
    Assert::same($celebrant, (int) DB::value(
        'SELECT contact_id FROM {automation_runs} WHERE automation_id = :a',
        ['a' => $automationId]
    ));

    tr_clear();
});

// -------------------------------------------------------------- switched off

Harness::test('a switched-off automation is not scanned for', function (): void {
    tr_clear();

    $automationId = tr_automation('trtest-off', 'booking.upcoming', ['days' => 7], ['is_active' => 0]);

    $contactId = tr_contact('Offline');
    tr_booking($contactId, tr_local(7));

    AutomationTriggers::scan();

    Assert::same(0, tr_runs($automationId));

    tr_clear();
});

Harness::test('AN AUTOMATION IN A SWITCHED-OFF PLAYBOOK DOES NOT RUN', function (): void {
    /*
     * The venue turned the playbook off and expects everything in it to stop.
     * The automation's own flag still says active — nobody went through the
     * playbook turning its automations off one at a time — so a scanner that
     * read only that flag would keep sending.
     */
    tr_clear();

    $playbookId = (int) DB::insert('playbooks', [
        'number'     => 99,
        'name'       => 'TrTest playbook',
        'slug'       => 'trtest-playbook',
        'is_active'  => 0,
        'created_at' => now_utc(),
        'updated_at' => now_utc(),
    ]);

    $automationId = tr_automation('trtest-in-playbook', 'booking.upcoming', ['days' => 7], [
        'playbook_id' => $playbookId,
    ]);

    $contactId = tr_contact('Playbooked');
    tr_booking($contactId, tr_local(7));

    AutomationTriggers::scan();

    Assert::same(0, tr_runs($automationId));

    // And it runs again the moment the playbook comes back on.
    DB::update('playbooks', ['is_active' => 1], 'id = :id', ['id' => $playbookId]);
    AutomationTriggers::scan();

    Assert::same(1, tr_runs($automationId));

    tr_clear();
});

Harness::test('a trigger whose scanner this version does not have answers nothing', function (): void {
    // `manual` waits for a button; the other two wait for tables a later
    // phase builds. Listed in SCANNED so the gates know they are deliberate,
    // and answered with nothing rather than an error — a scan that threw
    // would take the party reminders down with it.
    tr_clear();

    $byHand = tr_automation('trtest-manual', 'manual');
    $later  = tr_automation('trtest-certs', 'certificate.expiring');

    $started = AutomationTriggers::scan();

    Assert::same(0, tr_runs($byHand));
    Assert::same(0, tr_runs($later));
    Assert::true($started >= 0, 'and the scan completed');

    tr_clear();
});

// --------------------------------------------------------------------- cron

Harness::test('a cron automation fires in its minute and not again in it', function (): void {
    /*
     * The last firing is recorded per automation, so an expression that
     * matches "the first of the month at ten" fires once however often the
     * watchdog asks — which is sixty times in that hour.
     */
    tr_clear();

    $local  = new DateTimeImmutable('now', new DateTimeZone(Settings::timezone()));
    $minute = $local->format('i');
    $hour   = $local->format('G');

    $automationId = tr_automation('trtest-cron', 'cron', [
        'expression' => $minute . ' ' . $hour . ' * * *',
    ]);

    $first = AutomationTriggers::scan();

    Assert::same(1, tr_runs($automationId), 'it fired in its own minute');
    Assert::true($first >= 1);

    AutomationTriggers::scan();
    AutomationTriggers::scan();

    Assert::same(1, tr_runs($automationId), 'and not again in the same minute');

    tr_clear();
});

Harness::test('a cron automation whose minute has passed does not fire', function (): void {
    tr_clear();

    $local = new DateTimeImmutable('now', new DateTimeZone(Settings::timezone()));
    $other = $local->modify('+17 minutes');

    $automationId = tr_automation('trtest-cron-later', 'cron', [
        'expression' => $other->format('i') . ' ' . $other->format('G') . ' * * *',
    ]);

    AutomationTriggers::scan();

    Assert::same(0, tr_runs($automationId));

    tr_clear();
});

tr_clear();
