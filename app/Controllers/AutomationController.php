<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\DB;
use App\Core\Http;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Services\AutomationEngine;
use App\Services\AutomationRunner;
use App\Services\AutomationTriggers;
use App\Services\DealStage;

/**
 * The automation builder.
 *
 * A sequence here sends real messages to real families days after anybody was
 * looking at the screen, which makes this the most consequential editor in the
 * application and shapes every decision in it:
 *
 *   * **SWITCHING ONE ON IS THE DANGEROUS ACTION, not saving a step.** So
 *     activation is its own permission, it refuses an automation with no
 *     steps (which would enrol guests and do nothing), and it refuses a
 *     trigger this version cannot deliver rather than leaving somebody
 *     waiting for a thing that will never happen.
 *   * **THE KILL SWITCH IS TWO CLICKS FROM EVERY PAGE** (spec §9.5): the
 *     sidebar reaches this page, and the stop button is on it. The only
 *     reason anybody reaches for it is that something is going out that
 *     should not be, so it is not behind a tab or a confirmation dialog —
 *     and it stops runs already in flight, not just new enrolments.
 *   * **TEST MODE SENDS NOTHING.** A venue has to be able to see what a
 *     sequence would do before it does it to a guest. The preview runs the
 *     real engine against a real contact with the mailer and the SMS driver
 *     CAPTURING, so what it shows is what would have been sent, and then
 *     rolls the run back.
 *
 * Steps are edited one at a time through ordinary forms. There is no drag and
 * drop: this application has no build step, and a sequence that can only be
 * reordered by dragging cannot be reordered on a phone at the front desk.
 */
final class AutomationController extends Controller
{
    /** How many recent runs the detail page shows. */
    private const RECENT_RUNS = 12;

    /** How many step-log rows one run shows. */
    private const LOG_ROWS = 40;

    public function index(Request $request): Response
    {
        $state    = $request->str('state');
        $playbook = $request->int('playbook', 0);
        $search   = mb_substr(trim($request->str('q')), 0, 60);

        $where  = ['1 = 1'];
        $params = [];

        if ($state === 'on') {
            $where[] = 'a.is_active = 1';
        } elseif ($state === 'off') {
            $where[] = 'a.is_active = 0';
        } elseif ($state === 'waiting') {
            // Resolved in PHP below: "waiting on an unbuilt feature" is a
            // property of the trigger catalogue, not of a column.
            $state = 'waiting';
        }

        if ($playbook > 0) {
            $where[]            = 'a.playbook_id = :pid';
            $params['pid']      = $playbook;
        }

        if ($search !== '') {
            $where[]        = '(a.name LIKE :q OR a.description LIKE :q2)';
            $params['q']    = '%' . $search . '%';
            $params['q2']   = '%' . $search . '%';
        }

        $automations = DB::all(
            'SELECT a.id, a.name, a.slug, a.description, a.trigger_type, a.is_active,
                    a.run_count, a.goal_count, a.last_run_at, a.playbook_id,
                    p.name AS playbook_name, p.is_active AS playbook_active,
                    (SELECT COUNT(*) FROM {automation_steps} s WHERE s.automation_id = a.id) AS step_count,
                    (SELECT COUNT(*) FROM {automation_runs} r
                      WHERE r.automation_id = a.id AND r.status IN (\'running\', \'waiting\')) AS in_flight
               FROM {automations} a
               LEFT JOIN {playbooks} p ON p.id = a.playbook_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY a.is_active DESC, a.name
              LIMIT 400',
            $params
        );

        $catalogue = AutomationTriggers::catalogue();

        foreach ($automations as $index => $automation) {
            $trigger = (string) $automation['trigger_type'];
            $entry   = $catalogue[$trigger] ?? null;

            $automations[$index]['trigger_label'] = $entry['label'] ?? $trigger;
            $automations[$index]['pending']       = $entry['pending'] ?? null;
            $automations[$index]['unknown']       = $entry === null;
        }

        if ($state === 'waiting') {
            $automations = array_values(array_filter(
                $automations,
                static fn (array $a): bool => $a['pending'] !== null || $a['unknown']
            ));
        }

        return $this->view('automations/index', [
            'pageTitle'   => 'Automations',
            'automations' => $automations,
            'playbooks'   => DB::all('SELECT id, name FROM {playbooks} ORDER BY sort_order, number'),
            'filters'     => ['state' => $state, 'playbook' => $playbook, 'q' => $search],
            'paused'      => self::paused(),
            'safeMode'    => Settings::safeMode(),
            'counts'      => [
                'total'  => (int) DB::value('SELECT COUNT(*) FROM {automations}'),
                'on'     => (int) DB::value('SELECT COUNT(*) FROM {automations} WHERE is_active = 1'),
                'queued' => (int) DB::value(
                    "SELECT COUNT(*) FROM {automation_runs} WHERE status IN ('running', 'waiting')"
                ),
            ],
        ]);
    }

    public function show(Request $request): Response
    {
        $id         = $request->routeInt('id');
        $automation = $this->find($id);

        if ($automation === null) {
            return $this->notFound('That automation does not exist.');
        }

        $catalogue = AutomationTriggers::catalogue();
        $trigger   = (string) $automation['trigger_type'];

        return $this->view('automations/show', [
            'pageTitle'    => (string) $automation['name'],
            'automation'   => $automation,
            'steps'        => $this->steps($id),
            'triggerEntry' => $catalogue[$trigger] ?? null,
            'stepTypes'    => AutomationEngine::STEP_TYPES,
            'delayTypes'   => AutomationEngine::DELAY_TYPES,
            'templates'    => DB::all(
                'SELECT slug, name, channel FROM {templates} WHERE is_active = 1 ORDER BY channel, name'
            ),
            'users'        => DB::all('SELECT id, name FROM {users} WHERE is_active = 1 ORDER BY name'),
            'runs'         => DB::all(
                'SELECT r.*, c.first_name, c.last_name
                   FROM {automation_runs} r
                   LEFT JOIN {contacts} c ON c.id = r.contact_id
                  WHERE r.automation_id = :id
                  ORDER BY r.id DESC
                  LIMIT ' . self::RECENT_RUNS,
                ['id' => $id]
            ),
            'log'          => $this->logFor($id),
            'paused'       => self::paused(),
            'blockers'     => $this->blockers($automation),
            // Pulled, not read: a preview is about one guest at one moment,
            // and leaving it in the session would have it reappear next week
            // beside a sequence somebody has since edited.
            'preview'      => Session::pull('_automation_preview'),
        ]);
    }

    // =================================================================
    // On and off
    // =================================================================

    public function activate(Request $request): Response
    {
        if ($denied = $this->authorize('automations_activate')) {
            return $denied;
        }

        $id         = $request->routeInt('id');
        $automation = $this->find($id);

        if ($automation === null) {
            return $this->notFound('That automation does not exist.');
        }

        /*
         * THE REFUSALS ARE THE POINT OF THIS ACTION.
         *
         * Each one is a way an automation can read as working and do nothing,
         * which is the failure a venue cannot see: there is no error, no
         * message, and the page says "On".
         */
        $blockers = $this->blockers($automation);

        if ($blockers !== []) {
            return $this->refuse($request, $blockers[0], '/automations/' . $id);
        }

        DB::update('automations', [
            'is_active'  => 1,
            'updated_at' => now_utc(),
        ], 'id = :id', ['id' => $id]);

        Audit::record('automation.activated', 'automations', $id, ['is_active' => 0], ['is_active' => 1]);

        return $this->success(
            $request,
            Settings::safeMode()
                ? (string) $automation['name'] . ' is on. Safe Mode is still redirecting every message to the owner.'
                : (string) $automation['name'] . ' is on. It will start enrolling guests from the next cron run.',
            '/automations/' . $id
        );
    }

    public function deactivate(Request $request): Response
    {
        if ($denied = $this->authorize('automations_activate')) {
            return $denied;
        }

        $id         = $request->routeInt('id');
        $automation = $this->find($id);

        if ($automation === null) {
            return $this->notFound('That automation does not exist.');
        }

        $inFlight = (int) DB::value(
            "SELECT COUNT(*) FROM {automation_runs}
              WHERE automation_id = :id AND status IN ('running', 'waiting')",
            ['id' => $id]
        );

        DB::update('automations', [
            'is_active'  => 0,
            'updated_at' => now_utc(),
        ], 'id = :id', ['id' => $id]);

        Audit::record('automation.deactivated', 'automations', $id, ['is_active' => 1], ['is_active' => 0]);

        /*
         * The runs already in flight are NOT cancelled, and the message says
         * so with the number in it.
         *
         * Switching an automation off stops it advancing — the runner checks
         * `is_active` on every pass — but a guest half way through a sequence
         * stays half way through rather than being dropped, so turning it back
         * on tomorrow continues where it left off. Cancelling them would be
         * irreversible and is a different decision, taken on purpose.
         */
        return $this->success(
            $request,
            $inFlight === 0
                ? (string) $automation['name'] . ' is off.'
                : (string) $automation['name'] . ' is off. ' . $inFlight . ' '
                    . ($inFlight === 1 ? 'guest is' : 'guests are')
                    . ' part way through it; they stay where they are and will continue if you switch it back on.',
            '/automations/' . $id
        );
    }

    /**
     * Stop everything, or start it again.
     *
     * Posted from the automations page and from anywhere the paused pill is
     * shown. It is `system.automations_paused`, deliberately separate from
     * `system.sending_paused`: a venue stopping a sequence that is going wrong
     * usually still wants to be able to answer a guest's text.
     */
    public function pause(Request $request): Response
    {
        return $this->setPaused($request, true);
    }

    public function resume(Request $request): Response
    {
        return $this->setPaused($request, false);
    }

    private function setPaused(Request $request, bool $paused): Response
    {
        if ($denied = $this->authorize('automations_activate')) {
            return $denied;
        }

        Settings::set('system.automations_paused', $paused ? '1' : '0', 'bool');

        Audit::record(
            $paused ? 'automations.paused' : 'automations.resumed',
            'settings',
            null,
            ['automations_paused' => !$paused],
            ['automations_paused' => $paused]
        );

        $queued = (int) DB::value(
            "SELECT COUNT(*) FROM {automation_runs} WHERE status IN ('running', 'waiting')"
        );

        return $this->success(
            $request,
            $paused
                ? 'Every automation is stopped. ' . $queued . ' '
                    . ($queued === 1 ? 'run is' : 'runs are')
                    . ' held where they are, and nothing new will be enrolled until you start them again.'
                : 'Automations are running again.',
            '/automations'
        );
    }

    // =================================================================
    // Steps
    // =================================================================

    public function storeStep(Request $request): Response
    {
        if ($denied = $this->authorize('automations_manage')) {
            return $denied;
        }

        $id         = $request->routeInt('id');
        $automation = $this->find($id);

        if ($automation === null) {
            return $this->notFound('That automation does not exist.');
        }

        $step = $this->stepFromRequest($request);

        if ($step instanceof Response) {
            return $step;
        }

        $problem = AutomationEngine::validateStep($step);

        if ($problem !== null) {
            return $this->refuse($request, $problem, '/automations/' . $id);
        }

        $stepId = (int) DB::insert('automation_steps', $step + [
            'automation_id' => $id,
            'sort_order'    => (int) DB::value(
                'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM {automation_steps} WHERE automation_id = :a',
                ['a' => $id]
            ),
            'is_enabled'    => 1,
            'created_at'    => now_utc(),
        ]);

        Audit::record('automation.step_added', 'automations', $id, null, [
            'step' => $stepId,
            'type' => $step['type'],
        ]);

        return $this->success($request, 'Step added.', '/automations/' . $id);
    }

    public function updateStep(Request $request): Response
    {
        if ($denied = $this->authorize('automations_manage')) {
            return $denied;
        }

        $stepId = $request->routeInt('id');
        $step   = DB::first('SELECT * FROM {automation_steps} WHERE id = :id', ['id' => $stepId]);

        if ($step === null) {
            return $this->notFound('That step does not exist.');
        }

        $automationId = (int) $step['automation_id'];
        $changes      = $this->stepFromRequest($request);

        if ($changes instanceof Response) {
            return $changes;
        }

        $problem = AutomationEngine::validateStep($changes + ['id' => $stepId]);

        if ($problem !== null) {
            return $this->refuse($request, $problem, '/automations/' . $automationId);
        }

        DB::update('automation_steps', $changes, 'id = :id', ['id' => $stepId]);

        Audit::record('automation.step_updated', 'automations', $automationId, $step, $changes);

        return $this->success($request, 'Step saved.', '/automations/' . $automationId);
    }

    public function toggleStep(Request $request): Response
    {
        if ($denied = $this->authorize('automations_manage')) {
            return $denied;
        }

        $stepId = $request->routeInt('id');
        $step   = DB::first('SELECT * FROM {automation_steps} WHERE id = :id', ['id' => $stepId]);

        if ($step === null) {
            return $this->notFound('That step does not exist.');
        }

        $enabled = (int) $step['is_enabled'] === 1 ? 0 : 1;

        DB::update('automation_steps', ['is_enabled' => $enabled], 'id = :id', ['id' => $stepId]);

        Audit::record(
            'automation.step_updated',
            'automations',
            (int) $step['automation_id'],
            ['is_enabled' => (int) $step['is_enabled']],
            ['is_enabled' => $enabled]
        );

        return $this->success(
            $request,
            $enabled === 1 ? 'Step switched on.' : 'Step skipped from now on.',
            '/automations/' . (int) $step['automation_id']
        );
    }

    public function destroyStep(Request $request): Response
    {
        if ($denied = $this->authorize('automations_manage')) {
            return $denied;
        }

        $stepId = $request->routeInt('id');
        $step   = DB::first('SELECT * FROM {automation_steps} WHERE id = :id', ['id' => $stepId]);

        if ($step === null) {
            return $this->notFound('That step does not exist.');
        }

        $automationId = (int) $step['automation_id'];

        /*
         * The step LOG keeps its rows, which is why the log's own join is a
         * LEFT JOIN. "What did this send to Dana in March" has to stay
         * answerable after somebody edits the sequence in April; a cascade
         * would quietly rewrite the history instead.
         */
        DB::delete('automation_steps', 'id = :id', ['id' => $stepId]);

        Audit::record('automation.step_deleted', 'automations', $automationId, $step, null);

        return $this->success($request, 'Step removed.', '/automations/' . $automationId);
    }

    /** Swap a step with its neighbour. */
    public function moveStep(Request $request): Response
    {
        if ($denied = $this->authorize('automations_manage')) {
            return $denied;
        }

        $stepId = $request->routeInt('id');
        $step   = DB::first('SELECT * FROM {automation_steps} WHERE id = :id', ['id' => $stepId]);

        if ($step === null) {
            return $this->notFound('That step does not exist.');
        }

        $automationId = (int) $step['automation_id'];
        $up           = $request->str('direction') !== 'down';

        // The neighbour in the same BRANCH: a step in the "yes" arm of a
        // condition has nothing to do with the order of the main line, and
        // swapping across the two would move it out of its branch.
        $neighbour = DB::first(
            'SELECT id, sort_order FROM {automation_steps}
              WHERE automation_id = :a AND branch = :b
                AND sort_order ' . ($up ? '<' : '>') . ' :order
              ORDER BY sort_order ' . ($up ? 'DESC' : 'ASC') . '
              LIMIT 1',
            ['a' => $automationId, 'b' => (string) $step['branch'], 'order' => (int) $step['sort_order']]
        );

        if ($neighbour === null) {
            return $this->refuse(
                $request,
                'That step is already ' . ($up ? 'first' : 'last') . ' in its branch.',
                '/automations/' . $automationId
            );
        }

        DB::transaction(static function () use ($step, $neighbour): void {
            DB::update(
                'automation_steps',
                ['sort_order' => (int) $neighbour['sort_order']],
                'id = :id',
                ['id' => (int) $step['id']]
            );
            DB::update(
                'automation_steps',
                ['sort_order' => (int) $step['sort_order']],
                'id = :id',
                ['id' => (int) $neighbour['id']]
            );
        });

        return $this->success($request, 'Step moved.', '/automations/' . $automationId);
    }

    // =================================================================
    // Test mode
    // =================================================================

    /**
     * Run the sequence against one guest, sending nothing.
     *
     * THE WHOLE VALUE IS THAT IT USES THE REAL ENGINE. A preview that
     * reimplemented the scheduling would answer a different question from the
     * one the venue is asking — "what will this do?" — and the delays, the
     * conditions and the branches are exactly the parts worth seeing before a
     * real family does.
     *
     * So the mailer and the SMS driver are put into CAPTURE mode, the run is
     * forced through its steps with every delay collapsed, and everything it
     * wrote is rolled back afterwards: the run, its log, and any task or tag
     * the sequence created.
     */
    public function test(Request $request): Response
    {
        if ($denied = $this->authorize('automations_manage')) {
            return $denied;
        }

        $id         = $request->routeInt('id');
        $automation = $this->find($id);

        if ($automation === null) {
            return $this->notFound('That automation does not exist.');
        }

        $contactId = $request->int('contact_id', 0);

        $contact = $contactId > 0
            ? DB::first(
                'SELECT id, first_name, last_name, email, phone FROM {contacts}
                  WHERE id = :id AND deleted_at IS NULL',
                ['id' => $contactId]
            )
            : null;

        if ($contact === null) {
            return $this->refuse(
                $request,
                'Pick a guest to preview against — a sequence reads their details, their visits and '
                . 'their bookings to decide what it would do.',
                '/automations/' . $id
            );
        }

        $steps = $this->steps($id);

        if ($steps === []) {
            return $this->refuse($request, 'This automation has no steps yet.', '/automations/' . $id);
        }

        Session::put('_automation_preview', $this->preview($automation, $steps, $contact));

        Audit::record('automation.tested', 'automations', $id, null, [
            'contact' => (int) $contact['id'],
        ]);

        return $this->redirect('/automations/' . $id);
    }

    /**
     * Start this sequence for one guest, for real.
     *
     * THE DELIVERY MECHANISM FOR A `manual` TRIGGER, which otherwise has
     * none: nothing fires it and no scanner looks for it, so a manual
     * automation with a perfectly good sequence in it could never run. It is
     * also how a venue tests a sequence on themselves — on their own contact
     * record, with Safe Mode redirecting everything to the owner.
     *
     * Behind `automations_activate` rather than `automations_manage`, because
     * unlike the preview beside it THIS SENDS. The engine's own rules still
     * apply: the kill switch, consent, quiet hours, the weekly cap, and
     * enrolment once per guest unless the automation says otherwise.
     */
    public function start(Request $request): Response
    {
        if ($denied = $this->authorize('automations_activate')) {
            return $denied;
        }

        $id         = $request->routeInt('id');
        $automation = $this->find($id);

        if ($automation === null) {
            return $this->notFound('That automation does not exist.');
        }

        if (self::paused()) {
            return $this->refuse(
                $request,
                'Automations are stopped. Start them again first — this would send messages.',
                '/automations/' . $id
            );
        }

        if ($this->steps($id) === []) {
            return $this->refuse($request, 'This automation has no steps yet.', '/automations/' . $id);
        }

        $contactId = $request->int('contact_id', 0);

        $contact = $contactId > 0
            ? DB::first(
                'SELECT id, first_name, last_name FROM {contacts} WHERE id = :id AND deleted_at IS NULL',
                ['id' => $contactId]
            )
            : null;

        if ($contact === null) {
            return $this->refuse($request, 'Pick a guest to start it for.', '/automations/' . $id);
        }

        $runId = AutomationRunner::enrol($automation, ['contact_id' => (int) $contact['id']], 'manual');

        if ($runId === null) {
            return $this->refuse(
                $request,
                trim((string) $contact['first_name'] . ' ' . (string) $contact['last_name'])
                . ' was not enrolled: they do not match the entry conditions, or they have been '
                . 'through this one already.',
                '/automations/' . $id
            );
        }

        $executed = AutomationRunner::advance($runId);

        Audit::record('automation.started', 'automations', $id, null, [
            'contact' => (int) $contact['id'],
            'run'     => $runId,
        ]);

        return $this->success(
            $request,
            'Started for ' . trim((string) $contact['first_name'] . ' ' . (string) $contact['last_name'])
            . '. ' . $executed . ' ' . ($executed === 1 ? 'step has' : 'steps have') . ' run so far'
            . (Settings::safeMode() ? ', with Safe Mode redirecting anything it sent to the owner.' : '.'),
            '/automations/' . $id
        );
    }

    /**
     * What this sequence would do to this guest.
     *
     * NOTHING IS SENT AND NOTHING IS WRITTEN. The plan comes from
     * `AutomationEngine::plan()` — the engine's own scheduling, branching and
     * grace-window decisions — so what a venue sees is what would happen,
     * rather than a second implementation's opinion of it.
     *
     * On top of the plan this adds what only the database can answer: whether
     * the template a step names exists, whether the user it would notify is
     * still here, whether the coupon is real. Those are the mistakes a
     * sequence sits on for months, because every one of them is recorded as a
     * skip or a failure days after anybody was looking.
     *
     * @param array<string,mixed>       $automation
     * @param list<array<string,mixed>> $steps
     * @param array<string,mixed>       $contact
     * @return array<string,mixed>
     */
    private function preview(array $automation, array $steps, array $contact): array
    {
        $subject = AutomationRunner::subjectFor((int) $contact['id']);
        $name    = trim((string) $contact['first_name'] . ' ' . (string) $contact['last_name']);

        $entry = $this->conditions($automation['conditions'] ?? null);
        $exits = $this->conditions($automation['exit_conditions'] ?? null);

        if ($entry !== [] && !AutomationEngine::evaluate($entry, $subject)) {
            return [
                'contact'  => $name,
                'enrolled' => false,
                'headline' => $name . ' would not be enrolled: they do not match the entry conditions.',
                'rows'     => [],
            ];
        }

        $exit = AutomationEngine::shouldExit($exits === [] ? null : $exits, $subject);

        if ($exit['exit']) {
            return [
                'contact'  => $name,
                'enrolled' => false,
                'headline' => $name . ' would be enrolled and then stop immediately: ' . $exit['reason'] . '.',
                'rows'     => [],
            ];
        }

        $plan = AutomationEngine::plan($steps, $subject, now_utc(), Settings::timezone());
        $rows = [];
        $sends = 0;

        foreach ($plan as $entryRow) {
            $step   = $entryRow['step'];
            $type   = (string) $step['type'];
            $config = $this->config($step['config'] ?? null);

            if (!$entryRow['skip'] && in_array($type, ['send_email', 'send_sms'], true)) {
                $sends++;
            }

            $rows[] = [
                'what'    => AutomationEngine::describeStep($step),
                'when'    => $entryRow['at'],
                'skip'    => $entryRow['skip'],
                'reason'  => $entryRow['reason'],
                'branch'  => $entryRow['branch'],
                'stop'    => $entryRow['stop'],
                'problem' => $this->stepProblem($type, $config, $contact, $subject),
            ];
        }

        $problems = count(array_filter($rows, static fn (array $r): bool => $r['problem'] !== null));

        return [
            'contact'  => $name,
            'enrolled' => true,
            'headline' => $sends === 0
                ? $name . ' would be enrolled, and no message would go out.'
                : $name . ' would be sent ' . $sends . ' ' . ($sends === 1 ? 'message' : 'messages') . '.',
            'problems' => $problems,
            'rows'     => $rows,
        ];
    }

    /**
     * What a step would fail or skip on, in words, or null if it is ready.
     *
     * @param array<string,mixed> $config
     * @param array<string,mixed> $contact
     * @param array<string,mixed> $subject
     */
    private function stepProblem(string $type, array $config, array $contact, array $subject = []): ?string
    {
        switch ($type) {
            case 'send_email':
            case 'send_sms':
                $channel = $type === 'send_email' ? 'email' : 'sms';
                $slug    = (string) ($config['template'] ?? '');

                if ($slug === '') {
                    return 'no template is chosen, so this step would fail.';
                }

                $template = DB::first(
                    'SELECT id FROM {templates}
                      WHERE (slug = :slug OR name = :name) AND channel = :channel AND is_active = 1',
                    ['slug' => $slug, 'name' => $slug, 'channel' => $channel]
                );

                if ($template === null) {
                    return 'there is no active ' . $channel . ' template called "' . $slug . '", '
                        . 'so this step would fail.';
                }

                if ($channel === 'email' && trim((string) ($contact['email'] ?? '')) === '') {
                    return 'this guest has no email address, so the step would be skipped.';
                }

                if ($channel === 'sms' && trim((string) ($contact['phone'] ?? '')) === '') {
                    return 'this guest has no phone number, so the step would be skipped.';
                }

                /*
                 * A MERGE TAG THIS RUN CANNOT FILL BECOMES AN EMPTY STRING,
                 * which is right — "{{child_name}}" in a guest's inbox is
                 * worse — and therefore invisible until somebody reads what
                 * was sent. MEASURED on the seeded party reply: "We have your
                 * inquiry about a party for around ." because the template
                 * wanted a child's name and a date the enquiry form never
                 * asked for.
                 */
                $missing = AutomationRunner::missingTags($slug, $subject);

                if ($missing !== []) {
                    return 'the template uses ' . implode(', ', array_map(
                        static fn (string $tag): string => '{{' . $tag . '}}',
                        $missing
                    )) . ' and this run has no value for '
                        . (count($missing) === 1 ? 'it' : 'them')
                        . ' — the message would read with a gap where '
                        . (count($missing) === 1 ? 'it' : 'each') . ' should be.';
                }

                return null;

            case 'notify_user':
            case 'create_task':
                $assignee = (string) ($config['assign_to'] ?? '');

                if ($assignee === '' || $assignee === 'owner') {
                    return null;
                }

                $active = DB::value(
                    'SELECT is_active FROM {users} WHERE id = :id',
                    ['id' => (int) $assignee]
                );

                if ($active === null) {
                    return 'the person it names is not a user any more.';
                }

                return (int) $active === 1 ? null : 'the person it names is no longer active.';

            case 'issue_coupon':
                $code = strtoupper(trim((string) ($config['code'] ?? '')));

                if ($code === '') {
                    return 'no coupon code is filled in, so this step would fail.';
                }

                $coupon = DB::first(
                    'SELECT is_active, expires_at FROM {coupons} WHERE code = :c',
                    ['c' => $code]
                );

                if ($coupon === null) {
                    return 'there is no offer with the code "' . $code . '", so this step would fail.';
                }

                if ((int) $coupon['is_active'] !== 1) {
                    return 'the "' . $code . '" offer is switched off, so this step would be skipped.';
                }

                if ($coupon['expires_at'] !== null && (string) $coupon['expires_at'] < now_utc()) {
                    return 'the "' . $code . '" offer has expired, so this step would be skipped.';
                }

                return null;

            case 'move_deal_stage':
                $wanted = (string) ($config['stage'] ?? '');

                return $wanted !== '' && DealStage::resolve($wanted) === null
                    ? 'there is no stage called "' . $wanted . '", so this step would fail.'
                    : null;

            case 'webhook':
                $url = (string) ($config['url'] ?? '');

                return $url === '' ? 'no URL is filled in.' : Http::refuse($url);

            default:
                return null;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function conditions(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string) ($raw ?? ''), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string,mixed>
     */
    private function config(mixed $raw): array
    {
        return $this->conditions($raw);
    }

    // =================================================================
    // Internals
    // =================================================================

    /** @return array<string,mixed>|null */
    private function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        return DB::first(
            'SELECT a.*, p.name AS playbook_name, p.slug AS playbook_slug, p.is_active AS playbook_active
               FROM {automations} a
               LEFT JOIN {playbooks} p ON p.id = a.playbook_id
              WHERE a.id = :id',
            ['id' => $id]
        );
    }

    /** @return list<array<string,mixed>> */
    private function steps(int $automationId): array
    {
        return DB::all(
            'SELECT * FROM {automation_steps} WHERE automation_id = :a ORDER BY branch, sort_order, id',
            ['a' => $automationId]
        );
    }

    /** @return list<array<string,mixed>> */
    private function logFor(int $automationId, ?int $runId = null): array
    {
        $sql = 'SELECT l.*, s.type AS step_type, s.config AS step_config
                  FROM {automation_step_log} l
                  LEFT JOIN {automation_steps} s ON s.id = l.step_id
                 WHERE l.run_id IN (
                     SELECT id FROM {automation_runs} WHERE automation_id = :a
                 )';

        $params = ['a' => $automationId];

        if ($runId !== null) {
            $sql .= ' AND l.run_id = :r';
            $params['r'] = $runId;
        }

        return DB::all($sql . ' ORDER BY l.id DESC LIMIT ' . self::LOG_ROWS, $params);
    }

    /**
     * Why this automation cannot be switched on, in the order a venue would
     * want to hear them.
     *
     * @param array<string,mixed> $automation
     * @return list<string>
     */
    private function blockers(array $automation): array
    {
        if ((int) ($automation['is_active'] ?? 0) === 1) {
            return [];
        }

        $blockers = [];
        $trigger  = (string) $automation['trigger_type'];
        $entry    = AutomationTriggers::catalogue()[$trigger] ?? null;

        if ($entry === null) {
            $blockers[] = 'Its trigger — "' . $trigger . '" — is not one this version knows about, '
                . 'so nothing would ever start it.';
        } elseif ($entry['pending'] !== null) {
            $blockers[] = 'It waits for ' . $entry['pending'] . ', which this version does not have yet. '
                . 'Switching it on would do nothing at all.';
        }

        $steps = (int) DB::value(
            'SELECT COUNT(*) FROM {automation_steps} WHERE automation_id = :a AND is_enabled = 1',
            ['a' => (int) $automation['id']]
        );

        if ($steps === 0) {
            $blockers[] = 'It has no steps, so it would enrol guests and do nothing with them. '
                . 'Add at least one step first.';
        }

        if ((int) ($automation['playbook_id'] ?? 0) > 0 && (int) ($automation['playbook_active'] ?? 0) !== 1) {
            $blockers[] = 'Its playbook — ' . (string) $automation['playbook_name']
                . ' — is switched off, and nothing inside a switched-off playbook runs.';
        }

        return $blockers;
    }

    /**
     * A step's columns from the form, or a refusal.
     *
     * The config is assembled from named fields rather than taken as JSON: an
     * operator editing raw JSON in a textarea is how a sequence ends up with a
     * key the engine does not read and a step that silently does nothing.
     *
     * @return array<string,mixed>|Response
     */
    private function stepFromRequest(Request $request): array|Response
    {
        $redirect = '/automations/' . max(1, $request->routeInt('id'));

        $data = $this->validate($request, [
            'type'             => 'required|string|max:40',
            'branch'           => 'nullable|in:main,yes,no',
            'delay_minutes'    => 'nullable|int|min:0|max:525600',
            'delay_until_type' => 'nullable|string|max:24',
            'delay_at'         => 'nullable|string|max:5',
            'delay_field'      => 'nullable|string|max:60',
            'delay_days'       => 'nullable|int|min:0|max:730',
            'parent_step_id'   => 'nullable|int|min:0',
        ], [
            'type'          => 'Step',
            'delay_minutes' => 'Wait',
        ]);

        if ($data instanceof Response) {
            return $data;
        }

        $type = (string) $data['type'];

        if (!isset(AutomationEngine::STEP_TYPES[$type])) {
            return $this->refuse($request, 'That is not a kind of step this version knows about.', $redirect);
        }

        $delayType = (string) ($data['delay_until_type'] ?? '');

        if ($delayType !== '' && !in_array($delayType, AutomationEngine::DELAY_TYPES, true)) {
            return $this->refuse($request, 'That is not a kind of delay this version knows about.', $redirect);
        }

        $parent = (int) ($data['parent_step_id'] ?? 0);
        $branch = (string) ($data['branch'] ?? 'main') ?: 'main';

        /*
         * A branch and its parent only mean anything together.
         *
         * A step on the YES side of nothing would be invisible: the walk looks
         * for the "yes" children OF A CONDITION, so a yes-branch step with no
         * parent is in the sequence and can never be reached. Each is
         * therefore corrected to match the other rather than saved as given.
         */
        if ($parent > 0 && $branch === 'main') {
            $branch = 'yes';
        }

        if ($parent === 0 && $branch !== 'main') {
            $branch = 'main';
        }

        /*
         * The delay's own config, as the three fields an operator fills in
         * rather than as JSON. `delay_until_config` is read by
         * `AutomationEngine::scheduleStep()` with the keys `at`, `field` and
         * `days`, and a key it does not read is a step that silently waits
         * for nothing.
         */
        $delayConfig = [];

        if (preg_match('/^\d{1,2}:\d{2}$/', (string) ($data['delay_at'] ?? '')) === 1) {
            $delayConfig['at'] = (string) $data['delay_at'];
        }

        if (($data['delay_field'] ?? '') !== '') {
            $delayConfig['field'] = (string) $data['delay_field'];
            $delayConfig['days']  = (int) ($data['delay_days'] ?? 0);
        }

        return [
            'type'               => $type,
            'branch'             => $branch,
            'parent_step_id'     => $parent > 0 ? $parent : null,
            'delay_minutes'      => (int) ($data['delay_minutes'] ?? 0),
            'delay_until_type'   => $delayType === '' || $delayType === 'minutes' ? null : $delayType,
            'delay_until_config' => $delayConfig === []
                ? null
                : json_encode($delayConfig, JSON_UNESCAPED_SLASHES),
            'config'             => json_encode($this->configFromRequest($request, $type), JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * The config an operator filled in, by step type.
     *
     * @return array<string,mixed>
     */
    private function configFromRequest(Request $request, string $type): array
    {
        $config = [];

        foreach (AutomationEngine::STEP_TYPES[$type]['needs'] as $key) {
            $config[$key] = trim($request->str('config_' . $key));
        }

        // The optional extras, per type. Absent keys are left absent rather
        // than written as empty strings: the engine reads an absent key as
        // "use the default" and an empty one as a value.
        $extras = match ($type) {
            'send_email', 'send_sms' => ['kind'],
            'create_task'            => ['description', 'due_in_hours', 'priority', 'assign_to'],
            'notify_user'            => ['assign_to', 'title'],
            'update_field'           => [],
            'webhook'                => ['secret'],
            'send_survey'            => ['survey'],
            'split_test'             => ['label_a', 'label_b'],
            default                  => [],
        };

        foreach ($extras as $key) {
            $value = trim($request->str('config_' . $key));

            if ($value !== '') {
                $config[$key] = $value;
            }
        }

        if ($type === 'condition') {
            /*
             * One rule, through the same shape the segment editor uses. A
             * fuller rule builder belongs with that editor rather than being
             * reinvented here.
             *
             * `rule_*`, NOT `config_*`: `update_field` has a `config_field`
             * and a `config_value` on the same form, a hidden field still
             * submits, and the last one in the document wins. Sharing the
             * names made "Set a field" save an empty value.
             */
            /*
             * A GROUP, and the operator key is `op`.
             *
             * `branchFor()` hands `config.rules` straight to `evaluate()`,
             * which reads `match` and `rules` off it, and `match()` reads
             * `op`. A bare list with an `operator` key is accepted by every
             * layer and evaluates FALSE for everybody — so the condition
             * saves, reads as "check whether nothing in particular" in the
             * step list, and sends every guest down the NO side. Found by
             * driving the builder.
             */
            $config['rules'] = [
                'match' => 'all',
                'rules' => [[
                    'field' => trim($request->str('rule_field')),
                    'op'    => trim($request->str('rule_operator')) ?: 'is',
                    'value' => trim($request->str('rule_value')),
                ]],
            ];
        }

        return $config;
    }

    private static function paused(): bool
    {
        return (bool) Settings::get('system.automations_paused', false)
            || (bool) Settings::get('system.sending_paused', false);
    }
}
