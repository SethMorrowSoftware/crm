<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\DB;
use App\Core\Event;
use App\Core\Http;
use App\Core\Log;
use App\Core\Mailer;
use App\Core\Settings;
use App\Core\Sms;
use Throwable;

/**
 * The automation engine's I/O half: enrol, advance, execute.
 *
 * `AutomationEngine` decides; this does. The decisions it defers to are the
 * ones that would otherwise need a three-day wait to test — when a step runs,
 * whether it still should, which branch, whether to stop.
 *
 * ## The four rules this class exists to keep
 *
 * 1. **AN AUTOMATION SENDS NOTHING THAT A PERSON COULD NOT SEND.** Every
 *    message goes through `Mailer::send()` / `Sms::send()` with the contact
 *    attached, so consent, the suppression list, do-not-contact, the weekly
 *    frequency cap and quiet hours apply exactly as they do to a reply typed
 *    in the inbox. There is no automation-only path to a guest's phone, and
 *    adding one would put the compliance rules in two places.
 *
 * 2. **A STEP THAT FAILS IS RETRIED, AND THEN SAID TO HAVE FAILED.** Never
 *    silently abandoned. A nine-touch nurture that stopped on day three
 *    because a template was missing is a guest who was half-told something,
 *    and the venue has no way of knowing unless the run says so.
 *
 * 3. **ONE STEP, ONE LOG ROW.** `automation_step_log` is how anybody answers
 *    "what did this send to Dana, and when" months later. Including the
 *    skips, with their reason — a step that did not happen is as much of an
 *    answer as one that did.
 *
 * 4. **A RUN NEVER LOOPS.** Every pass is bounded, and a step that would
 *    schedule itself is advanced past rather than re-entered. An automation
 *    that re-enters itself on a cron that fires every minute is a thousand
 *    messages before anybody notices.
 */
final class AutomationRunner
{
    /** How many runs one cron tick will advance. */
    public const BATCH = 25;

    /** How many times a failing step is retried before the run is failed. */
    public const MAX_ATTEMPTS = 3;

    /** Minutes to wait before each retry: 5, then 30, then two hours. */
    public const BACKOFF_MINUTES = [5, 30, 120];

    /**
     * The payload keys that name the thing an event is about.
     *
     * Read in this order, except that the kind matching the event's own
     * namespace is tried first — see `locate()`.
     *
     * @var array<string,string>
     */
    private const ENTITY_KEYS = [
        'booking_id' => 'bookings',
        'deal_id'    => 'deals',
        'quote_id'   => 'quotes',
        'visit_id'   => 'visits',
        'task_id'    => 'tasks',
    ];

    /**
     * The kind of row each event namespace is about, for the events that fire
     * a bare `id`.
     *
     * @var array<string,string>
     */
    private const NAMESPACE_ENTITIES = [
        'booking' => 'bookings',
        'deal'    => 'deals',
        'quote'   => 'quotes',
        'visit'   => 'visits',
        'task'    => 'tasks',
    ];

    // =================================================================
    // Enrolment
    // =================================================================

    /**
     * Enrol a subject in every active automation listening for this event.
     *
     * Called from one listener per event rather than from the modules
     * themselves — `Event` is the seam, and a module that called this
     * directly would be a module that has to be edited to add an automation.
     *
     * @param array<string,mixed> $payload
     * @return list<int> The run ids started.
     */
    public static function onEvent(string $event, array $payload): array
    {
        if (self::paused()) {
            return [];
        }

        $automations = DB::all(
            'SELECT a.* FROM {automations} a
               LEFT JOIN {playbooks} p ON p.id = a.playbook_id
              WHERE a.is_active = 1
                AND a.trigger_type = :event
                -- An automation in a switched-off playbook does not run, even
                -- if its own flag says active: the venue turned the playbook
                -- off and expects everything in it to stop.
                AND (a.playbook_id IS NULL OR p.is_active = 1)
              ORDER BY a.priority, a.id',
            ['event' => $event]
        );

        $started = [];

        foreach ($automations as $automation) {
            try {
                $runId = self::enrol($automation, $payload, $event);

                if ($runId !== null) {
                    $started[] = $runId;
                }
            } catch (Throwable $e) {
                // One broken automation must not stop the others, and must
                // not stop whatever fired the event.
                Log::error('Could not enrol in an automation', [
                    'automation' => (int) $automation['id'],
                    'event'      => $event,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return $started;
    }

    /**
     * Start one run, if this subject qualifies.
     *
     * @param array<string,mixed> $automation
     * @param array<string,mixed> $payload
     */
    public static function enrol(array $automation, array $payload, string $event = 'manual'): ?int
    {
        $automationId = (int) $automation['id'];

        ['contact_id' => $contactId, 'entity_type' => $entityType, 'entity_id' => $entityId]
            = self::locate($event, $payload);

        $subject = self::subject($contactId, $entityType, $entityId, $payload);

        // Entry conditions, evaluated ONCE, here. A guest who stops matching
        // tomorrow is handled by the exit conditions instead, which is a
        // different question with a different answer.
        $conditions = self::json($automation['conditions'] ?? null);

        if ($conditions !== [] && !AutomationEngine::evaluate($conditions, $subject)) {
            return null;
        }

        /*
         * ONCE PER ENTITY WHERE THERE IS ONE, once per guest where there is
         * not. The two are different questions and getting it wrong breaks
         * in both directions:
         *
         *   - Deduplicating a "fourteen days before the party" trigger by
         *     GUEST means a family booking two parties gets one reminder, and
         *     the second party's waivers are never chased.
         *   - Not deduplicating by entity at all means the same party is
         *     enrolled on every scan — one reminder a minute for a day.
         *
         * So an entity, when the trigger names one, is enrolled at most once
         * per automation, whatever the run-once-per-guest flag says. That
         * flag is about the GUEST, and a scanner that names a booking is not
         * asking about the guest.
         */
        if ($entityType !== null && $entityId !== null) {
            if (DB::exists(
                'SELECT id FROM {automation_runs}
                  WHERE automation_id = :a AND entity_type = :t AND entity_id = :e
                  LIMIT 1',
                ['a' => $automationId, 't' => $entityType, 'e' => $entityId]
            )) {
                return null;
            }
        }

        $last = $contactId === null || ($entityType !== null && $entityId !== null)
            ? null
            : DB::first(
                'SELECT started_at, finished_at FROM {automation_runs}
                  WHERE automation_id = :a AND contact_id = :c
                  ORDER BY id DESC LIMIT 1',
                ['a' => $automationId, 'c' => $contactId]
            );

        $allowed = AutomationEngine::canEnrol(
            $last,
            now_utc(),
            (bool) ($automation['run_once_per_contact'] ?? 1),
            (bool) ($automation['allow_reentry'] ?? 0),
            isset($automation['reentry_cooldown_hours']) ? (int) $automation['reentry_cooldown_hours'] : null
        );

        if (!$allowed['allowed']) {
            return null;
        }

        $runId = (int) DB::insert('automation_runs', [
            'automation_id' => $automationId,
            'contact_id'    => $contactId,
            'entity_type'   => $entityType,
            'entity_id'     => $entityId,
            'status'        => 'running',
            'next_run_at'   => now_utc(),
            // The payload is kept so a step can merge the event's own values
            // into a message — "your party on Saturday" needs the booking the
            // event was about, not whatever the booking says next week.
            'context'       => json_encode(['event' => $event, 'payload' => $payload]),
            'started_at'    => now_utc(),
        ]);

        DB::query(
            'UPDATE {automations} SET run_count = run_count + 1, last_run_at = :now WHERE id = :id',
            ['now' => now_utc(), 'id' => $automationId]
        );

        return $runId;
    }

    // =================================================================
    // Advancing
    // =================================================================

    /**
     * Advance every run that is due. Returns how many steps were executed.
     *
     * Called from cron. Bounded by BATCH rather than draining, so one busy
     * automation cannot starve the rest of a cron tick.
     */
    public static function tick(int $batch = self::BATCH): int
    {
        if (self::paused()) {
            return 0;
        }

        $due = DB::all(
            "SELECT r.* FROM {automation_runs} r
               JOIN {automations} a ON a.id = r.automation_id
              WHERE r.status IN ('running', 'waiting')
                AND r.next_run_at IS NOT NULL
                AND r.next_run_at <= :now
                -- A run belonging to an automation that has since been
                -- switched off stops where it is. The venue switching it off
                -- is the instruction.
                AND a.is_active = 1
              ORDER BY r.next_run_at
              LIMIT " . max(1, $batch),
            ['now' => now_utc()]
        );

        $executed = 0;

        foreach ($due as $run) {
            try {
                $executed += self::advance((int) $run['id']);
            } catch (Throwable $e) {
                Log::error('Could not advance an automation run', [
                    'run'   => (int) $run['id'],
                    'error' => $e->getMessage(),
                ]);

                self::fail((int) $run['id'], $e->getMessage());
            }
        }

        return $executed;
    }

    /**
     * Advance one run as far as it will go without waiting.
     *
     * Returns how many steps it executed. A step with a real delay schedules
     * itself and the loop stops — so a nine-day sequence costs one step per
     * day rather than nine in one pass.
     */
    public static function advance(int $runId): int
    {
        $run = DB::first('SELECT * FROM {automation_runs} WHERE id = :id', ['id' => $runId]);

        if ($run === null || !in_array((string) $run['status'], ['running', 'waiting'], true)) {
            return 0;
        }

        $automation = DB::first(
            'SELECT * FROM {automations} WHERE id = :id',
            ['id' => (int) $run['automation_id']]
        );

        if ($automation === null) {
            self::finish($runId, 'cancelled', 'the automation no longer exists');

            return 0;
        }

        $steps   = self::steps((int) $automation['id']);
        $context = self::json($run['context'] ?? null);
        $payload = is_array($context['payload'] ?? null) ? $context['payload'] : [];

        $subject = self::subject(
            $run['contact_id'] === null ? null : (int) $run['contact_id'],
            $run['entity_type'] === null ? null : (string) $run['entity_type'],
            $run['entity_id'] === null ? null : (int) $run['entity_id'],
            $payload
        );

        $executed = 0;
        $current  = $run['current_step_id'] === null ? null : (int) $run['current_step_id'];
        $zone     = Settings::timezone();

        /*
         * THE STEP TO CONSIDER NEXT, carried explicitly rather than derived
         * from `current_step_id` each time round.
         *
         * Deriving it does not work once branches exist: after a condition
         * step picks "yes", the next step is the first inside that branch,
         * and `nextFrom()` would return the condition's next MAIN-line
         * sibling instead — so the branch would never run. It is also
         * recorded in `context.pending_step_id`, so a run interrupted
         * part-way through a branch resumes inside it rather than rejoining
         * the main line.
         */
        $pending = isset($context['pending_step_id']) ? (int) $context['pending_step_id'] : null;
        $next    = $pending !== null
            ? self::byId($steps, $pending)
            : self::nextFrom($steps, $current);

        /*
         * A STEP WHOSE TIME WAS ALREADY DECIDED MUST NOT BE RE-SCHEDULED.
         *
         * A delay is relative to the moment the step was REACHED. Parking a
         * run for three days and then, on waking, asking "when should this
         * step run?" again answers "three days from now" — so the run parks
         * again, and again, and the step never happens. MEASURED: every
         * automation with any delay in it never completed.
         *
         * The authority for WHEN is the run's own `next_run_at`, not a copy
         * of it in the context: one timestamp, so forcing a run due — which
         * an operator does to test a sequence, and which this suite does to
         * avoid waiting three days — works as it reads. The context only
         * records THAT the pending step was parked for, which `next_run_at`
         * cannot say on its own.
         */
        $dueStepId = ($context['pending_scheduled'] ?? false) === true
            // And only on a run that really is WAITING. The flag and the
            // status are written together, so disagreeing means the process
            // died part way through a pass — and trusting the flag then would
            // run a "three days before the party" step immediately. Checked
            // here as well as set carefully in `park()`, so the invariant
            // holds locally rather than depending on every writer.
            && (string) ($run['status'] ?? '') === 'waiting'
            && $pending !== null
            && (string) ($run['next_run_at'] ?? '') !== ''
            && (string) $run['next_run_at'] <= now_utc()
            ? $pending
            : null;

        for ($pass = 0; $pass < AutomationEngine::MAX_STEPS_PER_PASS; $pass++) {
            /*
             * EXIT CONDITIONS BEFORE EVERY STEP, not once at enrolment. A
             * guest who booked on day three has to stop receiving "still
             * thinking about it?" on day five, and the only way to know is to
             * ask again each time.
             */
            $exit = AutomationEngine::shouldExit(self::json($automation['exit_conditions'] ?? null), $subject);

            if ($exit['exit']) {
                self::finish($runId, 'exited', $exit['reason']);

                return $executed;
            }

            if ($next === null) {
                self::finish($runId, 'completed', 'reached the end');

                return $executed;
            }

            $step   = $next;
            $stepId = (int) $step['id'];

            // When does it run? Already settled, if this is the step the run
            // was parked for and its moment has come.
            $when = $stepId === $dueStepId
                ? ['at' => now_utc(), 'skip' => false, 'reason' => 'its scheduled time has come']
                : AutomationEngine::scheduleStep($step, $subject, now_utc(), $zone);

            // Settled once: a later step in the same pass is scheduled
            // normally, or a sequence of delayed steps would all fire at once.
            $dueStepId = null;

            if ($when['skip']) {
                // Skipped, RECORDED, and the run carries on. A step whose
                // moment has passed is not a failure of the sequence.
                self::log($runId, $stepId, 'skipped', ['reason' => $when['reason']]);

                $current = $stepId;
                $next    = self::nextFrom($steps, $stepId);

                self::park($runId, $current, $next, now_utc());

                continue;
            }

            if ($when['at'] !== null && $when['at'] > now_utc()) {
                // Waiting. The step itself has NOT run, so it stays pending.
                self::park($runId, $current, $step, $when['at'], 'waiting');

                return $executed;
            }

            $outcome = self::execute($step, $run, $subject, $automation);
            $executed++;

            if ($outcome['status'] === 'failed') {
                self::retryOrFail($runId, $stepId, $run, (string) $outcome['error']);

                return $executed;
            }

            self::log($runId, $stepId, (string) $outcome['status'], $outcome['output']);

            $current = $stepId;

            // A step can end the run outright.
            if ($outcome['stop'] !== null) {
                self::finish($runId, (string) $outcome['stop'], (string) ($outcome['reason'] ?? ''));

                return $executed;
            }

            if ($outcome['branch'] !== null) {
                $next = AutomationEngine::firstInBranch($steps, $stepId, (string) $outcome['branch']);

                /*
                 * An EMPTY branch rejoins the main line rather than ending
                 * the run: an operator who has written only the "yes" side
                 * has not asked for everybody else to be dropped.
                 */
                if ($next === null) {
                    $next = self::nextFrom($steps, $stepId);
                }
            } else {
                $next = self::nextFrom($steps, $stepId);
            }

            self::park($runId, $current, $next, now_utc());
        }

        // The loop bound. Reaching it means something is wrong with the
        // automation's shape rather than with this run, so it is said out
        // loud rather than left to happen again every minute.
        Log::warning('An automation run hit its step limit in one pass', ['run' => $runId]);

        return $executed;
    }

    // =================================================================
    // Executing one step
    // =================================================================

    /**
     * @param array<string,mixed> $step
     * @param array<string,mixed> $run
     * @param array<string,mixed> $subject
     * @param array<string,mixed> $automation
     * @return array{status:string, output:array<string,mixed>, error:?string, branch:?string, stop:?string, reason:?string}
     */
    private static function execute(array $step, array $run, array $subject, array $automation): array
    {
        $type   = (string) $step['type'];
        $config = self::json($step['config'] ?? null);
        $runId  = (int) $run['id'];

        $ok = static fn (array $output = [], ?string $branch = null, ?string $stop = null, ?string $reason = null): array => [
            'status' => 'ok', 'output' => $output, 'error' => null,
            'branch' => $branch, 'stop' => $stop, 'reason' => $reason,
        ];

        $failed = static fn (string $error): array => [
            'status' => 'failed', 'output' => [], 'error' => $error,
            'branch' => null, 'stop' => null, 'reason' => null,
        ];

        try {
            return match ($type) {
                'wait' => $ok(),

                'exit' => $ok([], null, 'exited', 'a step ended the sequence'),

                'goal' => self::markGoal($runId, $ok()),

                'condition' => $ok(
                    ['branch' => AutomationEngine::branchFor($step, $subject)],
                    AutomationEngine::branchFor($step, $subject)
                ),

                'split_test' => $ok(
                    ['arm' => AutomationEngine::splitFor($step, $runId)],
                    AutomationEngine::splitFor($step, $runId)
                ),

                'send_email' => self::sendEmail($config, $run, $subject, $automation, $ok, $failed),
                'send_sms'   => self::sendSms($config, $run, $subject, $automation, $ok, $failed),

                'create_task' => $ok(self::createTask($config, $run, $subject)),
                'add_tag'     => $ok(self::tag($config, $run, true)),
                'remove_tag'  => $ok(self::tag($config, $run, false)),
                'update_field' => $ok(self::updateField($config, $run)),
                'notify_user' => $ok(self::notifyUser($config, $run, $subject)),
                'move_deal_stage' => self::moveDeal($config, $run, $ok, $failed),
                'issue_coupon'    => self::issueCoupon($config, $run, $ok, $failed),
                'webhook'         => self::callWebhook($config, $run, $subject, $automation, $ok, $failed),

                // Not yet built. Recorded as SKIPPED with the reason rather
                // than silently treated as done: a venue whose automation
                // includes a step this version cannot perform needs to know
                // that, and a run that reports "ok" for it is lying.
                default => [
                    'status' => 'skipped',
                    'output' => ['reason' => 'this version cannot perform a "' . $type . '" step yet'],
                    'error'  => null, 'branch' => null, 'stop' => null, 'reason' => null,
                ],
            };
        } catch (Throwable $e) {
            return $failed($e->getMessage());
        }
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $run
     * @param array<string,mixed> $subject
     * @param array<string,mixed> $automation
     * @return array{status:string, output:array<string,mixed>, error:?string, branch:?string, stop:?string, reason:?string}
     */
    private static function sendEmail(
        array $config,
        array $run,
        array $subject,
        array $automation,
        callable $ok,
        callable $failed
    ): array {
        $contactId = $run['contact_id'] === null ? null : (int) $run['contact_id'];

        if ($contactId === null) {
            return $ok(['skipped' => 'there is no guest on this run to email']);
        }

        $template = (string) ($config['template'] ?? '');
        $built    = self::template($template, 'email');

        if ($built === null) {
            return $failed('there is no email template called "' . $template . '"');
        }

        $tags = self::mergeTags($subject);

        /*
         * Through the ordinary send path, with the contact attached and the
         * MARKETING kind unless the step says otherwise — so consent, the
         * suppression list, do-not-contact, the weekly cap and quiet hours
         * all apply. An automation must not be able to reach somebody a
         * reply typed in the inbox could not.
         */
        $result = Mailer::send([
            'to'                => (string) ($subject['email'] ?? ''),
            // The subject is NOT HTML and the body is, so they take
            // different escapers — merging both with the HTML one puts
            // "&amp;" and "&#039;" in a guest's inbox.
            'subject'           => Mailer::mergeText((string) $built['subject'], $tags),
            'html'              => Mailer::merge((string) $built['body'], $tags),
            'contact_id'        => $contactId,
            'template_id'       => (int) $built['id'],
            'automation_run_id' => (int) $run['id'],
            'kind'              => ($config['kind'] ?? '') === 'transactional'
                ? Mailer::TRANSACTIONAL
                : Mailer::MARKETING,
        ]);

        if ($result['sent'] !== true) {
            // A refusal is not a FAILURE. "This guest has not opted in" is
            // the system working, and retrying it three times with backoff
            // would be three attempts at something that can never succeed.
            return [
                'status' => 'skipped',
                'output' => ['refused' => (string) $result['reason']],
                'error'  => null, 'branch' => null, 'stop' => null, 'reason' => null,
            ];
        }

        return $ok(['message_id' => $result['message_id'], 'to' => $subject['email'] ?? null]);
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $run
     * @param array<string,mixed> $subject
     * @param array<string,mixed> $automation
     * @return array{status:string, output:array<string,mixed>, error:?string, branch:?string, stop:?string, reason:?string}
     */
    private static function sendSms(
        array $config,
        array $run,
        array $subject,
        array $automation,
        callable $ok,
        callable $failed
    ): array {
        $contactId = $run['contact_id'] === null ? null : (int) $run['contact_id'];

        if ($contactId === null) {
            return $ok(['skipped' => 'there is no guest on this run to text']);
        }

        $template = (string) ($config['template'] ?? '');
        $built    = self::template($template, 'sms');

        if ($built === null) {
            return $failed('there is no text template called "' . $template . '"');
        }

        $result = Sms::send([
            'to'                => (string) ($subject['phone_normalized'] ?? $subject['phone'] ?? ''),
            'body'              => Mailer::mergeText((string) $built['body'], self::mergeTags($subject)),
            'contact_id'        => $contactId,
            'template_id'       => (int) $built['id'],
            'automation_run_id' => (int) $run['id'],
            'kind'              => ($config['kind'] ?? '') === 'transactional'
                ? Sms::TRANSACTIONAL
                : Sms::MARKETING,
        ]);

        if ($result['sent'] !== true) {
            /*
             * Quiet hours are the one refusal worth WAITING for rather than
             * skipping: the message is still wanted, just not at eleven at
             * night. The step reschedules itself for the start of the window
             * instead of being recorded as skipped.
             */
            if ((string) $result['reason'] === 'quiet_hours') {
                return [
                    'status' => 'skipped',
                    'output' => [
                        'deferred_minutes' => (int) ($result['deferred_minutes'] ?? 0),
                        'note'             => 'waiting for the venue\'s sending hours',
                    ],
                    'error' => null, 'branch' => null, 'stop' => null, 'reason' => null,
                ];
            }

            return [
                'status' => 'skipped',
                'output' => ['refused' => (string) $result['reason']],
                'error'  => null, 'branch' => null, 'stop' => null, 'reason' => null,
            ];
        }

        return $ok(['message_id' => $result['message_id'], 'segments' => $result['segments'] ?? null]);
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $run
     * @param array<string,mixed> $subject
     * @return array<string,mixed>
     */
    private static function createTask(array $config, array $run, array $subject): array
    {
        $tags  = self::mergeTags($subject);
        $title = Mailer::mergeText((string) ($config['title'] ?? 'Follow up'), $tags);

        $dueAt = null;
        $hours = (int) ($config['due_in_hours'] ?? 0);

        if ($hours > 0) {
            $dueAt = gmdate('Y-m-d H:i:s', time() + $hours * 3600);
        }

        $taskId = (int) DB::insert('tasks', [
            'title'       => mb_substr($title, 0, 191),
            'description' => isset($config['description'])
                ? Mailer::mergeText((string) $config['description'], $tags)
                : null,
            'entity_type' => $run['entity_type'] ?? 'contacts',
            'entity_id'   => $run['entity_id'] ?? $run['contact_id'],
            'assigned_to' => self::assignee($config),
            'due_at'      => $dueAt,
            'priority'    => in_array((string) ($config['priority'] ?? ''), ['low', 'normal', 'high'], true)
                ? (string) $config['priority']
                : 'normal',
            'status'      => 'open',
            'created_at'  => now_utc(),
            'updated_at'  => now_utc(),
        ]);

        Event::fire('task.created', ['task_id' => $taskId, 'from_automation' => true]);

        return ['task_id' => $taskId];
    }

    /** @param array<string,mixed> $config */
    private static function assignee(array $config): ?int
    {
        $to = $config['assign_to'] ?? null;

        if (is_numeric($to)) {
            return (int) $to;
        }

        // "the owner" is the only strategy this version resolves. A
        // round-robin needs a team table to rotate over, and guessing would
        // put the venue's work in front of whoever happens to sort first.
        if ($to === 'owner' || $to === null) {
            $owner = DB::value(
                "SELECT u.id FROM {users} u
                   JOIN {roles} r ON r.id = u.role_id
                  WHERE r.slug = 'owner' AND u.is_active = 1
                  ORDER BY u.id LIMIT 1"
            );

            return $owner === null ? null : (int) $owner;
        }

        return null;
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $run
     * @return array<string,mixed>
     */
    private static function tag(array $config, array $run, bool $add): array
    {
        $name      = trim((string) ($config['tag'] ?? ''));
        $contactId = $run['contact_id'] === null ? null : (int) $run['contact_id'];

        if ($name === '' || $contactId === null) {
            return ['skipped' => 'no tag or no guest'];
        }

        $tagId = DB::value('SELECT id FROM {tags} WHERE name = :n', ['n' => $name]);

        if ($tagId === null) {
            if (!$add) {
                return ['skipped' => 'there is no such tag'];
            }

            $tagId = DB::insert('tags', [
                'name'       => mb_substr($name, 0, 80),
                'created_at' => now_utc(),
            ]);
        }

        if (!$add) {
            DB::delete(
                'taggables',
                "tag_id = :t AND entity_type = 'contact' AND entity_id = :c",
                ['t' => (int) $tagId, 'c' => $contactId]
            );

            Event::fire('contact.untagged', ['contact_id' => $contactId, 'tag' => $name]);

            return ['removed' => $name];
        }

        // INSERT IGNORE, because tagging somebody who is already tagged is
        // not an error and must not fail the run.
        DB::query(
            "INSERT IGNORE INTO {taggables} (tag_id, entity_type, entity_id, created_at)
             VALUES (:t, 'contact', :c, :now)",
            ['t' => (int) $tagId, 'c' => $contactId, 'now' => now_utc()]
        );

        Event::fire('contact.tagged', ['contact_id' => $contactId, 'tag' => $name]);

        return ['added' => $name];
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $run
     * @return array<string,mixed>
     */
    private static function updateField(array $config, array $run): array
    {
        $field     = trim((string) ($config['field'] ?? ''));
        $contactId = $run['contact_id'] === null ? null : (int) $run['contact_id'];

        /*
         * An ALLOWLIST, not the contact's whole row. The field name comes
         * from a JSON column an operator edits, and this writes it into a
         * column name — so anything outside this list is refused rather than
         * interpolated. Consent columns are deliberately absent: an
         * automation must not be able to grant somebody consent.
         */
        $allowed = ['lifecycle_stage', 'source', 'notes', 'owner_id', 'do_not_contact'];

        if ($contactId === null || !in_array($field, $allowed, true)) {
            return ['skipped' => 'that field cannot be set by an automation'];
        }

        DB::update('contacts', [
            $field       => $config['value'] ?? null,
            'updated_at' => now_utc(),
        ], 'id = :id', ['id' => $contactId]);

        return ['set' => $field];
    }

    /**
     * Tell somebody at the venue.
     *
     * TWO deliveries, because one is not enough on its own: a row in
     * `notifications` for the bell in the app, and a transactional email,
     * because a venue manager is not sitting in the CRM at 7am when the
     * waiver chase runs. The email is the one that actually reaches them
     * today, and it is TRANSACTIONAL — this is staff mail about their own
     * work, not marketing, and it must not be filtered by a consent flag on
     * some guest record that happens to share the address.
     *
     * The notification is written even when the email fails: the venue should
     * be able to see what the automation decided to tell them, whatever the
     * mail server did.
     *
     * @param array<string,mixed> $config
     * @param array<string,mixed> $run
     * @param array<string,mixed> $subject
     * @return array<string,mixed>
     */
    private static function notifyUser(array $config, array $run, array $subject): array
    {
        $userId = self::assignee($config);

        if ($userId === null) {
            return ['skipped' => 'there is nobody to notify'];
        }

        $user = DB::first(
            'SELECT id, name, email, is_active FROM {users} WHERE id = :id',
            ['id' => $userId]
        );

        if ($user === null || (int) $user['is_active'] !== 1) {
            // Deliberately not an error. Staff leave, and an automation that
            // named them must keep working for everybody else.
            return ['skipped' => 'that user is no longer active'];
        }

        $tags    = self::mergeTags($subject);
        $message = Mailer::mergeText((string) ($config['message'] ?? ''), $tags);
        $title   = trim((string) ($config['title'] ?? ''));
        $title   = $title === ''
            ? mb_substr($message, 0, 120)
            : Mailer::mergeText($title, $tags);

        $link = self::link($run);

        try {
            DB::insert('notifications', [
                'user_id'    => $userId,
                'type'       => 'automation',
                'title'      => mb_substr($title === '' ? 'An automation needs you' : $title, 0, 200),
                'body'       => $message === '' ? null : $message,
                'link'       => $link,
                'created_at' => now_utc(),
            ]);
        } catch (Throwable $e) {
            Log::error('Could not write a notification', ['user' => $userId, 'error' => $e->getMessage()]);
        }

        $email = trim((string) ($user['email'] ?? ''));
        $sent  = false;

        if ($email !== '' && ($config['email'] ?? true) !== false) {
            $result = Mailer::send([
                'to'                => $email,
                'to_name'           => (string) ($user['name'] ?? ''),
                'subject'           => $title === '' ? 'Castle CRM' : $title,
                'html'              => '<p>' . nl2br(e($message)) . '</p>'
                    . ($link === null ? '' : '<p><a href="' . e(Mailer::absoluteUrl($link)) . '">Open it in Castle CRM</a></p>'),
                'automation_run_id' => (int) $run['id'],
                'kind'              => Mailer::TRANSACTIONAL,
            ]);

            $sent = ($result['sent'] ?? false) === true;
        }

        return ['user_id' => $userId, 'emailed' => $sent];
    }

    /**
     * Move the deal this run is about.
     *
     * Shares `DealStage` with the pipeline board, so an automated move sets
     * the same columns, writes the same history row and fires the same won or
     * lost event as a manager dragging the card. A run that is not about a
     * deal is SKIPPED rather than failed: "move the deal" in a playbook that
     * also runs for walk-in guests is an authoring slip, not a breakage, and
     * failing the run would stop the messages after it.
     *
     * @param array<string,mixed> $config
     * @param array<string,mixed> $run
     * @return array{status:string, output:array<string,mixed>, error:?string, branch:?string, stop:?string, reason:?string}
     */
    private static function moveDeal(array $config, array $run, callable $ok, callable $failed): array
    {
        $dealId = (string) ($run['entity_type'] ?? '') === 'deals'
            ? (int) ($run['entity_id'] ?? 0)
            : 0;

        if ($dealId <= 0 && $run['contact_id'] !== null) {
            // The guest's own live deal, newest first. A contact with no open
            // deal gives nothing, which is the skip below.
            $dealId = (int) (DB::value(
                "SELECT id FROM {deals}
                  WHERE contact_id = :c AND deleted_at IS NULL AND status = 'open'
                  ORDER BY id DESC LIMIT 1",
                ['c' => (int) $run['contact_id']]
            ) ?? 0);
        }

        if ($dealId <= 0) {
            return $ok(['skipped' => 'this run is not about a deal']);
        }

        $moved = DealStage::move($dealId, (string) ($config['stage'] ?? ''));

        if (!$moved['moved'] && $moved['reason'] !== null && str_starts_with($moved['reason'], 'there is no stage')) {
            // A stage name that matches nothing is a FAILURE, not a skip: the
            // automation says what it intends to do and cannot do it, and a
            // silent skip would leave a venue believing their pipeline was
            // being kept up to date.
            return $failed($moved['reason']);
        }

        return $ok(array_filter($moved, static fn ($value): bool => $value !== null));
    }

    /**
     * Give this guest a coupon.
     *
     * The coupon itself is a venue-wide offer with a code, a value and its own
     * limits; what an automation does is RECORD that this guest has been given
     * it, which is why this writes a redemption row with no redemption. The
     * alternative — a coupon row per guest — would fill the offers list with
     * thousands of single-use codes and break the venue's own reporting on how
     * an offer performed.
     *
     * A coupon the venue has not created is NOT invented here. An automation
     * that could create offers could create one with any value, and the step
     * is authored in a playbook that ships with the product.
     *
     * @param array<string,mixed> $config
     * @param array<string,mixed> $run
     * @return array{status:string, output:array<string,mixed>, error:?string, branch:?string, stop:?string, reason:?string}
     */
    private static function issueCoupon(array $config, array $run, callable $ok, callable $failed): array
    {
        $code      = strtoupper(trim((string) ($config['code'] ?? '')));
        $contactId = $run['contact_id'] === null ? null : (int) $run['contact_id'];

        if ($code === '') {
            return $failed('this step has no coupon code in it');
        }

        if ($contactId === null) {
            return $ok(['skipped' => 'there is no guest on this run to give a coupon to']);
        }

        $coupon = DB::first(
            'SELECT id, code, name, type, value, max_per_contact, max_redemptions,
                    redemption_count, is_active, starts_at, expires_at
               FROM {coupons} WHERE code = :c',
            ['c' => $code]
        );

        if ($coupon === null) {
            return $failed('there is no coupon with the code "' . $code . '"');
        }

        if ((int) $coupon['is_active'] !== 1) {
            return $ok(['skipped' => 'the "' . $code . '" offer is switched off']);
        }

        $now = now_utc();

        if ($coupon['expires_at'] !== null && (string) $coupon['expires_at'] < $now) {
            // Not a failure: an offer running out is the venue's own doing,
            // and the rest of the sequence should still run.
            return $ok(['skipped' => 'the "' . $code . '" offer has expired']);
        }

        $already = (int) DB::value(
            'SELECT COUNT(*) FROM {coupon_redemptions} WHERE coupon_id = :id AND contact_id = :c',
            ['id' => (int) $coupon['id'], 'c' => $contactId]
        );

        // `max_per_contact` is the venue's own rule about how many times one
        // family may have an offer, and it is the reason re-running a
        // playbook does not hand out a second one.
        if ($already >= max(1, (int) $coupon['max_per_contact'])) {
            return $ok(['skipped' => 'this guest already has the "' . $code . '" offer']);
        }

        $issuedId = DB::insert('coupon_redemptions', [
            'coupon_id'        => (int) $coupon['id'],
            'contact_id'       => $contactId,
            // ISSUED, not redeemed. `redeemed_at` stays NULL and
            // `discount_cents` stays 0 until the code is used at the till, so
            // nothing counts an offer going out as an offer coming back —
            // see 009_automation.sql.
            'discount_cents'   => 0,
            'issued_at'        => $now,
            'issued_by_run_id' => (int) $run['id'],
            'redeemed_at'      => null,
        ]);

        Event::fire('coupon.issued', [
            'coupon_id'  => (int) $coupon['id'],
            'code'       => (string) $coupon['code'],
            'contact_id' => $contactId,
            'issue_id'   => $issuedId,
            'run_id'     => (int) $run['id'],
        ]);

        return $ok([
            'coupon_id' => (int) $coupon['id'],
            'code'      => (string) $coupon['code'],
            'issued'    => true,
        ]);
    }

    /**
     * Call a URL.
     *
     * The escape hatch that makes the engine useful beyond what ships: a
     * venue wires an automation to their own kitchen printer, a Slack
     * channel, a spreadsheet. Bounded by `Core\Http` — short timeouts, no
     * redirects, no inward addresses, and only the first few hundred bytes of
     * the reply kept.
     *
     * The payload names IDs, not personal data. An operator can add fields by
     * hand if they want them, but a webhook step that shipped sending a
     * guest's phone number and email to a third party by default would be a
     * privacy decision made for the venue rather than by it.
     *
     * @param array<string,mixed> $config
     * @param array<string,mixed> $run
     * @param array<string,mixed> $subject
     * @param array<string,mixed> $automation
     * @return array{status:string, output:array<string,mixed>, error:?string, branch:?string, stop:?string, reason:?string}
     */
    private static function callWebhook(
        array $config,
        array $run,
        array $subject,
        array $automation,
        callable $ok,
        callable $failed
    ): array {
        $url = trim((string) ($config['url'] ?? ''));

        if ($url === '') {
            return $failed('this step has no URL in it');
        }

        $refusal = Http::refuse($url);

        if ($refusal !== null) {
            return $failed($refusal);
        }

        $payload = [
            'event'      => 'automation.step',
            'automation' => [
                'id'   => (int) $automation['id'],
                'name' => (string) ($automation['name'] ?? ''),
            ],
            'run_id'     => (int) $run['id'],
            'contact_id' => $run['contact_id'] === null ? null : (int) $run['contact_id'],
            'entity'     => [
                'type' => $run['entity_type'] ?? null,
                'id'   => $run['entity_id'] === null ? null : (int) $run['entity_id'],
            ],
            'sent_at'    => now_utc(),
        ];

        if (is_array($config['payload'] ?? null)) {
            $tags = self::mergeTags($subject);

            foreach ($config['payload'] as $key => $value) {
                $payload['data'][(string) $key] = is_string($value)
                    ? Mailer::mergeText($value, $tags)
                    : $value;
            }
        }

        $headers = [];

        if (($config['secret'] ?? '') !== '') {
            // A shared secret the receiver can check, as a header rather than
            // in the body or the URL: a URL with a secret in it ends up in
            // the step log, in a proxy log and in a bug report.
            $headers['X-Castle-Signature'] = hash_hmac(
                'sha256',
                json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '',
                (string) $config['secret']
            );
        }

        $result = Http::postJson($url, $payload, $headers);

        if (!$result['ok']) {
            /*
             * FAILED, so the run retries with backoff.
             *
             * A webhook is usually the step the rest of the sequence depends
             * on — the kitchen ticket, the door code — and an endpoint that
             * was down for a minute is exactly what the retry schedule is
             * for. The URL is not repeated in the error: it is on the step.
             */
            return $failed(
                'the webhook answered ' . ($result['status'] === 0 ? 'nothing' : (string) $result['status'])
                . ($result['error'] === null ? '' : ': ' . $result['error'])
            );
        }

        return $ok(['status' => $result['status'], 'ms' => $result['ms']]);
    }

    /** Where in the app a run's subject can be looked at. */
    private static function link(array $run): ?string
    {
        $id = $run['entity_id'] === null ? 0 : (int) $run['entity_id'];

        $path = match ((string) ($run['entity_type'] ?? '')) {
            'deals'    => '/deals/',
            'bookings' => '/bookings/',
            'contacts' => '/contacts/',
            default    => null,
        };

        if ($path !== null && $id > 0) {
            return $path . $id;
        }

        return $run['contact_id'] === null ? null : '/contacts/' . (int) $run['contact_id'];
    }

    // =================================================================
    // Goals
    // =================================================================

    /**
     * An event reached a goal: record the conversion and exit those runs.
     *
     * @param array<string,mixed> $payload
     */
    public static function onGoalEvent(string $event, array $payload): int
    {
        $contactId = isset($payload['contact_id']) ? (int) $payload['contact_id'] : null;

        if ($contactId === null) {
            return 0;
        }

        $runs = DB::all(
            "SELECT r.*, a.goal_event, a.goal_window_hours
               FROM {automation_runs} r
               JOIN {automations} a ON a.id = r.automation_id
              WHERE r.contact_id = :c
                AND r.status IN ('running', 'waiting')
                AND a.goal_event = :event",
            ['c' => $contactId, 'event' => $event]
        );

        $counted = 0;

        foreach ($runs as $run) {
            if (!AutomationEngine::reachesGoal($run, $run, $event, now_utc())) {
                continue;
            }

            DB::update('automation_runs', [
                'goal_reached_at' => now_utc(),
            ], 'id = :id', ['id' => (int) $run['id']]);

            DB::query(
                'UPDATE {automations} SET goal_count = goal_count + 1 WHERE id = :id',
                ['id' => (int) $run['automation_id']]
            );

            // Reaching the goal ENDS the run. Carrying on would keep nudging
            // a guest towards something they have already done.
            self::finish((int) $run['id'], 'completed', 'reached the goal: ' . $event);

            $counted++;
        }

        return $counted;
    }

    private static function markGoal(int $runId, array $ok): array
    {
        DB::update('automation_runs', ['goal_reached_at' => now_utc()], 'id = :id AND goal_reached_at IS NULL', ['id' => $runId]);

        $automationId = (int) DB::value('SELECT automation_id FROM {automation_runs} WHERE id = :id', ['id' => $runId]);

        DB::query('UPDATE {automations} SET goal_count = goal_count + 1 WHERE id = :id', ['id' => $automationId]);

        return array_merge($ok, ['stop' => 'completed', 'reason' => 'a goal step ended the sequence']);
    }

    // =================================================================
    // Bookkeeping
    // =================================================================

    /** @return list<array<string,mixed>> */
    private static function steps(int $automationId): array
    {
        return DB::all(
            'SELECT * FROM {automation_steps} WHERE automation_id = :a ORDER BY sort_order, id',
            ['a' => $automationId]
        );
    }

    /**
     * One step by id.
     *
     * @param list<array<string,mixed>> $steps
     * @return array<string,mixed>|null
     */
    private static function byId(array $steps, int $stepId): ?array
    {
        foreach ($steps as $step) {
            if ((int) $step['id'] === $stepId) {
                return $step;
            }
        }

        return null;
    }

    /**
     * Write down where the run has got to.
     *
     * `current_step_id` is the last step EXECUTED and `pending_step_id` (in
     * the context) is the next one to consider. Both, because they are
     * different questions: the first is what the log and the stats are about,
     * and the second is the only thing that lets a run interrupted inside a
     * branch resume inside it rather than silently rejoining the main line.
     *
     * @param array<string,mixed>|null $next
     */
    private static function park(
        int $runId,
        ?int $currentId,
        ?array $next,
        string $nextRunAt,
        string $status = 'running'
    ): void {
        $run = DB::first('SELECT context FROM {automation_runs} WHERE id = :id', ['id' => $runId]);
        $context = self::json($run['context'] ?? null);

        if ($next === null) {
            unset($context['pending_step_id'], $context['pending_scheduled']);
        } else {
            $context['pending_step_id'] = (int) $next['id'];

            /*
             * Marked only when the run is being PARKED for this step. A run
             * that is simply carrying on has not had the step's time decided
             * yet, and claiming otherwise would make the next pass run it
             * without honouring its delay at all.
             */
            if ($status === 'waiting') {
                $context['pending_scheduled'] = true;
            } else {
                unset($context['pending_scheduled']);
            }
        }

        DB::update('automation_runs', [
            'status'          => $status,
            'current_step_id' => $currentId,
            'next_run_at'     => $nextRunAt,
            'context'         => json_encode($context),
            // Reset on any step that got somewhere: the attempt counter is
            // about ONE step failing repeatedly, not about the run's history.
            'attempts'        => 0,
        ], 'id = :id', ['id' => $runId]);
    }

    /**
     * The next step, wherever in the tree the run currently sits.
     *
     * @param list<array<string,mixed>> $steps
     * @return array<string,mixed>|null
     */
    /**
     * The step after this one, branches and rejoins included.
     *
     * The walk itself lives in `AutomationEngine::stepAfter()` because the
     * preview needs the same answer, and two copies of "what comes next"
     * would eventually disagree about branches — leaving a venue looking at a
     * preview of a sequence that is not the one that will run.
     *
     * @param list<array<string,mixed>> $steps
     * @return array<string,mixed>|null
     */
    private static function nextFrom(array $steps, ?int $currentId): ?array
    {
        return AutomationEngine::stepAfter($steps, $currentId);
    }

    /**
     * The subject for one guest, for the preview.
     *
     * Public because the builder's Test button needs exactly what a run would
     * see, and building a second version of it in the controller is how a
     * preview starts disagreeing with the thing it previews.
     *
     * @return array<string,mixed>
     */
    public static function subjectFor(int $contactId): array
    {
        return self::subject($contactId, null, null, []);
    }

    /** @param array<string,mixed> $output */
    private static function log(int $runId, ?int $stepId, string $status, array $output = []): void
    {
        try {
            DB::insert('automation_step_log', [
                'run_id'      => $runId,
                'step_id'     => $stepId,
                'status'      => in_array($status, ['ok', 'skipped', 'failed'], true) ? $status : 'ok',
                'output'      => $output === [] ? null : json_encode($output),
                'executed_at' => now_utc(),
            ]);

            if ($stepId !== null && $status === 'ok') {
                DB::query(
                    'UPDATE {automation_steps}
                        SET entered_count = entered_count + 1, completed_count = completed_count + 1
                      WHERE id = :id',
                    ['id' => $stepId]
                );
            }
        } catch (Throwable $e) {
            Log::error('Could not log an automation step', ['run' => $runId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * A step failed: back off and retry, or give up and SAY SO.
     *
     * @param array<string,mixed> $run
     */
    private static function retryOrFail(int $runId, int $stepId, array $run, string $error): void
    {
        $attempts = (int) ($run['attempts'] ?? 0) + 1;

        self::log($runId, $stepId, 'failed', ['error' => $error, 'attempt' => $attempts]);

        if ($attempts >= self::MAX_ATTEMPTS) {
            self::fail($runId, $error, $attempts);

            return;
        }

        $minutes = self::BACKOFF_MINUTES[$attempts - 1] ?? end(self::BACKOFF_MINUTES);

        DB::update('automation_runs', [
            'status'      => 'waiting',
            'attempts'    => $attempts,
            'next_run_at' => gmdate('Y-m-d H:i:s', time() + $minutes * 60),
            'error'       => mb_substr($error, 0, 2000),
        ], 'id = :id', ['id' => $runId]);
    }

    private static function fail(int $runId, string $error, ?int $attempts = null): void
    {
        $columns = [
            'status'      => 'failed',
            'error'       => mb_substr($error, 0, 2000),
            'next_run_at' => null,
            'finished_at' => now_utc(),
        ];

        /*
         * The final attempt is recorded too. Without it the row reads
         * "attempts: 2" on a run that made three — the last one is what
         * failed it, and an operator counting retries off this column would
         * be off by one every time.
         */
        if ($attempts !== null) {
            $columns['attempts'] = $attempts;
        }

        DB::update('automation_runs', $columns, 'id = :id', ['id' => $runId]);

        /*
         * A failed run is AUDITED, not just logged. A nine-touch nurture that
         * stopped on day three is a guest who was half-told something, and
         * the venue has no way of knowing unless somebody is told.
         */
        Audit::event('automation.failed', [
            'run'   => $runId,
            'error' => mb_substr($error, 0, 300),
        ], 'automation_runs', $runId);

        Event::fire('automation.failed', ['run_id' => $runId, 'error' => $error]);
    }

    private static function finish(int $runId, string $status, string $reason): void
    {
        DB::update('automation_runs', [
            'status'      => in_array($status, ['completed', 'exited', 'cancelled', 'failed'], true)
                ? $status
                : 'completed',
            'next_run_at' => null,
            'finished_at' => now_utc(),
            'error'       => $reason === '' ? null : mb_substr($reason, 0, 2000),
        ], 'id = :id', ['id' => $runId]);
    }

    // =================================================================
    // The subject
    // =================================================================

    /**
     * Everything a condition or a message might need about this run.
     *
     * One array, loaded once per pass. Conditions are evaluated in PHP
     * against this rather than compiled to SQL per step: a nine-touch
     * sequence would otherwise be nine round trips deeper for information
     * that has not changed within the pass.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    /**
     * WHO AND WHAT AN EVENT IS ABOUT.
     *
     * The scanners name their subject outright (`entity_type` + `entity_id`,
     * and the guest when they know them), but a module firing an event does
     * not: `BookingController` fires `['booking_id' => 17]` and
     * `DealController` fires `['id' => 4]`, because that is what the event is
     * about and the module has no business knowing what the automation engine
     * wants to be handed.
     *
     * Without this, `enrol()` read only `contact_id`/`entity_type`/
     * `entity_id`, so EVERY event-driven automation that sends a message was
     * broken: the run had no guest, and `send_email` answered "there is no
     * guest on this run to email" and completed. Silently, because a run that
     * skips every step still finishes. The engine's own tests did not see it —
     * they fire `visit.recorded`, which happens to carry a `contact_id`.
     *
     * So the conventions are read here, once:
     *
     *   * an explicit `entity_type` + `entity_id` wins, since a scanner knows
     *     better than any inference;
     *   * then a `<thing>_id` key, preferring the one matching the EVENT's own
     *     namespace — `quote.accepted` carries both `quote_id` and `deal_id`,
     *     and it is about the quote;
     *   * then a bare `id`, when the namespace names a kind of row;
     *   * and the guest is looked up FROM that row when the event did not name
     *     them, including the two-hop case (a quote hangs off a deal, and only
     *     the deal names the family).
     *
     * @param array<string,mixed> $payload
     * @return array{contact_id:?int,entity_type:?string,entity_id:?int}
     */
    private static function locate(string $event, array $payload): array
    {
        $contactId = isset($payload['contact_id']) && (int) $payload['contact_id'] > 0
            ? (int) $payload['contact_id']
            : null;

        $entityType = isset($payload['entity_type']) && (string) $payload['entity_type'] !== ''
            ? (string) $payload['entity_type']
            : null;

        $entityId = isset($payload['entity_id']) && (int) $payload['entity_id'] > 0
            ? (int) $payload['entity_id']
            : null;

        $namespace = (string) (strtok($event, '.') ?: '');

        if ($entityType === null || $entityId === null) {
            // The event's own kind first, then the rest in a fixed order so
            // the answer does not depend on the order of the array literal
            // whoever fired it happened to write.
            $keys      = self::ENTITY_KEYS;
            $preferred = self::NAMESPACE_ENTITIES[$namespace] ?? null;

            if ($preferred !== null) {
                $keys = array_merge(
                    array_filter($keys, static fn (string $type): bool => $type === $preferred),
                    $keys
                );
            }

            foreach ($keys as $key => $type) {
                if (isset($payload[$key]) && (int) $payload[$key] > 0) {
                    $entityType = $type;
                    $entityId   = (int) $payload[$key];

                    break;
                }
            }
        }

        // `Event::fire('deal.created', ['id' => $dealId])` — the id of the
        // thing the namespace names.
        if (($entityType === null || $entityId === null)
            && isset($payload['id'])
            && (int) $payload['id'] > 0
            && isset(self::NAMESPACE_ENTITIES[$namespace])) {
            $entityType = self::NAMESPACE_ENTITIES[$namespace];
            $entityId   = (int) $payload['id'];
        }

        if ($contactId === null && $entityType !== null && $entityId !== null) {
            $contactId = self::contactFor($entityType, $entityId);
        }

        // A conversation and a message name neither a contact nor a kind of
        // row the engine models, but both know whose thread it is.
        if ($contactId === null) {
            foreach (['conversation_id' => 'conversations', 'message_id' => 'messages'] as $key => $table) {
                if (isset($payload[$key]) && (int) $payload[$key] > 0) {
                    $found = DB::value(
                        'SELECT contact_id FROM {' . $table . '} WHERE id = :id',
                        ['id' => (int) $payload[$key]]
                    );

                    if ($found !== null && (int) $found > 0) {
                        $contactId = (int) $found;

                        break;
                    }
                }
            }
        }

        return ['contact_id' => $contactId, 'entity_type' => $entityType, 'entity_id' => $entityId];
    }

    /**
     * The guest a row belongs to.
     *
     * A quote does not carry one — it hangs off a deal, and the deal names the
     * family — and a task carries whatever it is about, which may be a
     * contact and may be a booking. Both are looked up rather than assumed,
     * because an automation on `quote.accepted` with no guest attached cannot
     * send the thank-you that is its entire point.
     */
    private static function contactFor(string $entityType, int $entityId): ?int
    {
        $table = self::tableFor($entityType);

        if ($table === null) {
            return null;
        }

        $found = match ($table) {
            // The entity IS the guest.
            'contacts' => $entityId,

            // A child's row names the guardian, who is the person messaged —
            // a child is not a marketing recipient.
            'bookings', 'deals', 'visits', 'children', 'conversations', 'memberships' => DB::value(
                'SELECT contact_id FROM {' . $table . '} WHERE id = :id',
                ['id' => $entityId]
            ),
            'quotes' => DB::value(
                'SELECT d.contact_id FROM {quotes} q
                   JOIN {deals} d ON d.id = q.deal_id
                  WHERE q.id = :id',
                ['id' => $entityId]
            ),
            'tasks' => DB::value(
                "SELECT entity_id FROM {tasks}
                  WHERE id = :id AND entity_type IN ('contact', 'contacts')",
                ['id' => $entityId]
            ),
            default => null,
        };

        return $found === null || (int) $found <= 0 ? null : (int) $found;
    }

    private static function subject(?int $contactId, ?string $entityType, ?int $entityId, array $payload): array
    {
        $subject = [];

        if ($contactId !== null) {
            $contact = DB::first(
                'SELECT * FROM {contacts} WHERE id = :id AND deleted_at IS NULL',
                ['id' => $contactId]
            );

            if (is_array($contact)) {
                $subject = $contact;

                // Derived values a condition is likely to want, computed here
                // rather than expected of whoever writes the rule.
                $subject['days_since_last_visit'] = self::daysSince($contact['last_visit_at'] ?? null);
                $subject['days_ago']              = $subject['days_since_last_visit'];
                $subject['has_booking']           = DB::exists(
                    "SELECT id FROM {bookings}
                      WHERE contact_id = :c AND deleted_at IS NULL
                        AND status NOT IN ('cancelled', 'no_show')",
                    ['c' => $contactId]
                ) ? 1 : 0;
            }
        }

        // The entity the event was about, prefixed so its columns cannot
        // shadow the contact's — a booking's `status` and a contact's
        // `lifecycle_stage` are different questions and a rule naming
        // `status` should not get whichever was loaded last.
        if ($entityType !== null && $entityId !== null) {
            $table = self::tableFor($entityType);

            if ($table !== null) {
                $row = DB::first('SELECT * FROM {' . $table . '} WHERE id = :id', ['id' => $entityId]);

                if (is_array($row)) {
                    foreach ($row as $column => $value) {
                        $subject[$entityType . '_' . $column] = $value;

                        // And unprefixed when the contact has no such column,
                        // so "starts_at" works without anybody having to know
                        // which table it came from.
                        if (!array_key_exists($column, $subject)) {
                            $subject[$column] = $value;
                        }
                    }
                }
            }
        }

        // The event's own payload wins: it is the most specific thing known.
        foreach ($payload as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $subject['event_' . $key] = $value;
            }
        }

        return $subject;
    }

    private static function tableFor(string $entityType): ?string
    {
        // An allowlist, because this becomes a table name.
        return match ($entityType) {
            'booking', 'bookings'   => 'bookings',
            'deal', 'deals'         => 'deals',
            'quote', 'quotes'       => 'quotes',
            'visit', 'visits'       => 'visits',
            'membership', 'memberships' => 'memberships',
            'contact', 'contacts'   => 'contacts',
            'child', 'children'     => 'children',
            'task', 'tasks'         => 'tasks',
            'conversation', 'conversations' => 'conversations',
            default                 => null,
        };
    }

    private static function daysSince(mixed $stamp): ?int
    {
        if ($stamp === null || trim((string) $stamp) === '') {
            return null;
        }

        $then = strtotime((string) $stamp . ' UTC');

        return $then === false ? null : (int) floor((time() - $then) / 86400);
    }

    /**
     * @param array<string,mixed> $subject
     * @return array<string,string>
     */
    private static function mergeTags(array $subject): array
    {
        $first = trim((string) ($subject['first_name'] ?? ''));

        return [
            // An unnamed guest is greeted as "there", never as nobody.
            'first_name'  => $first === '' ? 'there' : $first,
            'last_name'   => trim((string) ($subject['last_name'] ?? '')),
            'full_name'   => trim($first . ' ' . (string) ($subject['last_name'] ?? '')) ?: 'there',
            'venue_name'  => Settings::venueName(),
            'venue_phone' => (string) Settings::get('venue.phone_display', ''),
        ];
    }

    /**
     * A template by slug or name, for one channel.
     *
     * @return array<string,mixed>|null
     */
    private static function template(string $key, string $channel): ?array
    {
        if (trim($key) === '') {
            return null;
        }

        /*
         * By slug OR name, and only while ACTIVE — the same condition
         * `Mailer::sendTemplate()` uses. A template the venue has retired
         * must not keep going out from an automation nobody is watching.
         *
         * Two placeholders for one value, not one named twice: emulated
         * prepares are off, so a repeated named parameter is an HY093 that
         * surfaces as a bare 500.
         */
        $row = DB::first(
            'SELECT * FROM {templates}
              WHERE (slug = :key OR name = :name) AND channel = :channel AND is_active = 1
              ORDER BY id LIMIT 1',
            ['key' => $key, 'name' => $key, 'channel' => $channel]
        );

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed> */
    private static function json(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return is_array($raw) ? $raw : [];
    }

    /**
     * The global kill switch.
     *
     * Checked at enrolment AND on every tick, so turning it on stops runs
     * already in flight rather than only preventing new ones. §9.5 calls for
     * it to be two clicks from every page, and the only reason anybody
     * reaches for it is that something is going out that should not be.
     */
    private static function paused(): bool
    {
        return (bool) Settings::get('system.automations_paused', false)
            || (bool) Settings::get('system.sending_paused', false);
    }
}
