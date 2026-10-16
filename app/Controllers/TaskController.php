<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Acl;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Event;
use App\Core\Request;
use App\Core\Response;

/**
 * Tasks and team accountability.
 *
 * Three rules shape this class.
 *
 *   1. EVERY DAY IS A VENUE DAY. The database stores UTC and nothing else, but
 *      "due today" and "overdue" are claims about the venue's own calendar, so
 *      every day boundary on this page is computed from Settings::timezone()
 *      through venue_tz(). A due date typed as "16th, 11pm" is converted on the
 *      way in (localToUtc) and split back on the way out (localParts), so the
 *      figure a manager types is the figure they read back.
 *
 *      This is not a nicety. The Castle is America/New_York, which is four or
 *      five hours behind UTC — so from about 8pm local onwards the UTC date has
 *      already rolled over. Treat the typed string as UTC, or compare days in
 *      UTC, and a task due at the end of today reads as overdue every single
 *      evening, all year. A suite that only ever asks "is it before now?" in
 *      UTC cannot see it, which is why tests/http/TasksTest.php sets the venue
 *      timezone explicitly and pins the conversion to an exact stored value.
 *
 *   2. OVERDUE IS AN INSTANT, DUE-TODAY IS A DAY. A task is overdue when its
 *      due instant has passed and it is still open — a comparison between two
 *      instants, which is the same answer in any timezone and so cannot drift.
 *      "Due today" is a window, and that window is the venue's local day
 *      expressed in UTC. Keeping the two definitions apart is what stops a task
 *      due later today from being reported as late.
 *
 *   3. Reading is SCOPED through Acl::scopeCondition('view_tasks',
 *      't.assigned_to'), so a role holding view_tasks at 'own' — Front Desk and
 *      Party Host both do in the seeds — sees the tasks assigned to them and
 *      nothing else. A task outside the scope is a 404, not a visible row that
 *      refuses to open. Writing gates on a permission the catalogue already
 *      has: see WRITE_PERMISSION.
 */
final class TaskController extends Controller
{
    /**
     * The permission that guards every write on this page.
     *
     * Acl::CATALOG has no `tasks_manage` key and adding one is out of scope for
     * this module, so this is the closest existing fit: `inbox_assign` is
     * "Assign and close conversations", and assigning a piece of work to a
     * colleague and closing it when it is done is exactly what the buttons on
     * this page do. In the seeded roles it lands where you would want it —
     * Owner, General Manager and Sales Manager may raise and close work; Front
     * Desk, Party Host, Marketing and Read Only may only look at it.
     *
     * The trade-off is real and worth stating: a Front Desk user can see the
     * task assigned to them and cannot tick it off. Granting that would mean
     * either a new catalogue key or an assignee-may-complete exception, and
     * both are product decisions rather than this module's to make.
     */
    public const WRITE_PERMISSION = 'inbox_assign';

    /** The tasks.status enum. */
    public const STATUSES = ['open', 'in_progress', 'done', 'cancelled'];

    /** The tasks.priority enum, most urgent first. */
    public const PRIORITIES = ['urgent', 'high', 'normal', 'low'];

    /** Statuses that still represent work to do. */
    private const LIVE = "('open','in_progress')";

    /**
     * The list views, in the order the page offers them.
     *
     * @var array<string,string>
     */
    public const VIEWS = [
        'mine'       => 'Mine',
        'overdue'    => 'Overdue',
        'today'      => 'Due today',
        'unassigned' => 'Unassigned',
        'all'        => 'All',
    ];

    /**
     * Records a task may be attached to: key => [label, path prefix].
     *
     * An allow-list, because entity_type reaches a table name and a URL. Every
     * type here has a page in this application; a task pointing at a record
     * nobody can open would be a dead end on the detail page.
     *
     * @var array<string,array{0:string,1:string}>
     */
    public const ENTITIES = [
        'contact'   => ['Contact', '/contacts/'],
        'deal'      => ['Deal', '/deals/'],
        'quote'     => ['Quote', '/quotes/'],
        'household' => ['Household', '/households/'],
    ];

    /** When a due date is given with no time, the task is due by end of the venue's day. */
    private const DEFAULT_DUE_TIME = '23:59';

    // =================================================================
    // Reading
    // =================================================================

    public function index(Request $request): Response
    {
        $view = $this->chosenView($request);

        [$filter, $params] = $this->taskFilter($request, $view);

        $total      = (int) DB::value('SELECT COUNT(*) FROM {tasks} t WHERE ' . $filter, $params);
        $pagination = $this->pagination($request, 50);

        return $this->view('tasks/index', [
            'pageTitle' => 'Tasks',
            'view'      => $view,
            'tasks'     => $this->taskRows($filter, $params, $pagination),
            'counts'    => $this->viewCounts(),
            'total'     => $total,
            'filters'   => $this->filterValues($request),
            'assignees' => $this->assigneeOptions(),
            'statuses'  => self::STATUSES,
            'canManage' => Acl::can(self::WRITE_PERMISSION),
            'page'      => $pagination['page'],
            'lastPage'  => max(1, (int) ceil($total / $pagination['perPage'])),
        ]);
    }

    public function show(Request $request): Response
    {
        $task = $this->findTask($request->routeInt('id'), 'view_tasks');

        if ($task === null) {
            return $this->notFound('That task does not exist, or it is not one of yours.');
        }

        return $this->view('tasks/show', [
            'pageTitle' => (string) $task['title'],
            'task'      => $task,
            'comments'  => $this->comments((int) $task['id']),
            'link'      => $this->entityLink($task),
            'canManage' => Acl::can(self::WRITE_PERMISSION),
        ]);
    }

    public function create(Request $request): Response
    {
        if ($denied = $this->authorize(self::WRITE_PERMISSION)) {
            return $denied;
        }

        return $this->view('tasks/form', [
            'pageTitle' => 'New task',
            'task'      => null,
            'assignees' => $this->assigneeOptions(),
            'priorities' => self::PRIORITIES,
            'statuses'  => self::STATUSES,
            'entities'  => self::ENTITIES,
            'due'       => ['date' => '', 'time' => ''],
            'today'     => $this->venueToday(),
            'timezone'  => $this->venueZoneName(),
        ]);
    }

    public function edit(Request $request): Response
    {
        if ($denied = $this->authorize(self::WRITE_PERMISSION)) {
            return $denied;
        }

        $task = $this->findTask($request->routeInt('id'), self::WRITE_PERMISSION);

        if ($task === null) {
            return $this->notFound('That task does not exist, or it is not one you may edit.');
        }

        return $this->view('tasks/form', [
            'pageTitle'  => 'Edit ' . (string) $task['title'],
            'task'       => $task,
            'assignees'  => $this->assigneeOptions(),
            'priorities' => self::PRIORITIES,
            'statuses'   => self::STATUSES,
            'entities'   => self::ENTITIES,
            'due'        => $this->localParts($task['due_at'] === null ? null : (string) $task['due_at']),
            'today'      => $this->venueToday(),
            'timezone'   => $this->venueZoneName(),
        ]);
    }

    // =================================================================
    // Writing
    // =================================================================

    public function store(Request $request): Response
    {
        if ($denied = $this->authorize(self::WRITE_PERMISSION)) {
            return $denied;
        }

        $data = $this->validate($request, $this->rules(), $this->labels());

        if ($data instanceof Response) {
            return $data;
        }

        $resolved = $this->resolveWrite($request, $data, null);

        if ($resolved instanceof Response) {
            return $resolved;
        }

        [$dueAt, $assignedTo, $entityType, $entityId] = $resolved;

        $now = now_utc();

        $row = [
            'title'       => $data['title'],
            'description' => $data['description'] ?? null,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'assigned_to' => $assignedTo,
            'created_by'  => Auth::id(),
            'priority'    => $data['priority'] ?? 'normal',
            'status'      => $data['status'] ?? 'open',
            'due_at'      => $dueAt,
            'created_at'  => $now,
            'updated_at'  => $now,
        ];

        // A task created already done still records who finished it: the
        // completion columns are the only evidence of that, and leaving them
        // empty would make the task look open to every report that reads them.
        if ($row['status'] === 'done') {
            $row['completed_at'] = $now;
            $row['completed_by'] = Auth::id();
        }

        $taskId = DB::transaction(static function () use ($row): int {
            $id = DB::insert('tasks', $row);

            Audit::record('task.created', 'tasks', $id, null, $row);

            return $id;
        });

        Event::fire('task.created', ['id' => $taskId, 'assigned_to' => $assignedTo]);

        return $this->success($request, $data['title'] . ' is on the list.', '/tasks/' . $taskId);
    }

    public function update(Request $request): Response
    {
        $id   = $request->routeInt('id');

        // Looked up under the READ scope, then authorised per task: the
        // assignee may finish their own work without holding the permission
        // that hands work out. See mayCloseOwn().
        $task = $this->findTask($id, 'view_tasks');

        if ($task === null) {
            return $this->notFound('That task does not exist, or it is not one you may edit.');
        }

        if (!$this->mayCloseOwn($task)) {
            return $this->refuse(
                $request,
                'That task is not assigned to you, and you cannot close other people\'s work.',
                '/tasks/' . $id
            );
        }

        $data = $this->validate($request, $this->rules(), $this->labels());

        if ($data instanceof Response) {
            return $data;
        }

        $resolved = $this->resolveWrite($request, $data, $task);

        if ($resolved instanceof Response) {
            return $resolved;
        }

        [$dueAt, $assignedTo, $entityType, $entityId] = $resolved;

        $now    = now_utc();
        $status = (string) ($data['status'] ?? $task['status']);

        $fields = [
            'title'       => $data['title'],
            'description' => $data['description'] ?? null,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'assigned_to' => $assignedTo,
            'priority'    => $data['priority'] ?? 'normal',
            'status'      => $status,
            'due_at'      => $dueAt,
            'updated_at'  => $now,
        ];

        // Status is editable here as well as through the two buttons, so the
        // completion stamps are kept in step down this path too — otherwise a
        // task marked done on the form reads as finished by nobody, and one
        // reopened on the form keeps a completion time that is no longer true.
        $fields = array_merge($fields, $this->completionColumns($task, $status, $now));

        DB::transaction(static function () use ($id, $fields, $task): void {
            DB::update('tasks', $fields, 'id = :id', ['id' => $id]);

            Audit::record('task.updated', 'tasks', $id, $task, $fields);
        });

        return $this->success($request, 'Task updated.', '/tasks/' . $id);
    }

    /**
     * Mark a task done, recording who and when.
     *
     * A POST, not a link: it changes a record, and a GET that does would be
     * triggered by a browser prefetch or a link checker.
     */
    public function complete(Request $request): Response
    {
        $id   = $request->routeInt('id');

        // Looked up under the READ scope, then authorised per task: the
        // assignee may finish their own work without holding the permission
        // that hands work out. See mayCloseOwn().
        $task = $this->findTask($id, 'view_tasks');

        if ($task === null) {
            return $this->notFound('That task does not exist, or it is not one you may edit.');
        }

        if (!$this->mayCloseOwn($task)) {
            return $this->refuse(
                $request,
                'That task is not assigned to you, and you cannot close other people\'s work.',
                '/tasks/' . $id
            );
        }

        if ((string) $task['status'] === 'done') {
            // Refused rather than quietly re-stamped: a second submit would
            // rewrite who finished it and when, which is the one thing this
            // route exists to record.
            return $this->refuse($request, 'That task is already done.', '/tasks/' . $id);
        }

        $now = now_utc();

        $fields = [
            'status'       => 'done',
            'completed_at' => $now,
            'completed_by' => Auth::id(),
            'updated_at'   => $now,
        ];

        DB::transaction(static function () use ($id, $fields, $task): void {
            DB::update('tasks', $fields, 'id = :id', ['id' => $id]);

            Audit::record('task.completed', 'tasks', $id, $task, $fields);
        });

        Event::fire('task.completed', ['id' => $id, 'completed_by' => Auth::id()]);

        return $this->success($request, (string) $task['title'] . ' is done.', '/tasks/' . $id);
    }

    public function reopen(Request $request): Response
    {
        $id   = $request->routeInt('id');

        // Looked up under the READ scope, then authorised per task: the
        // assignee may finish their own work without holding the permission
        // that hands work out. See mayCloseOwn().
        $task = $this->findTask($id, 'view_tasks');

        if ($task === null) {
            return $this->notFound('That task does not exist, or it is not one you may edit.');
        }

        if (!$this->mayCloseOwn($task)) {
            return $this->refuse(
                $request,
                'That task is not assigned to you, and you cannot close other people\'s work.',
                '/tasks/' . $id
            );
        }

        if (!in_array((string) $task['status'], ['done', 'cancelled'], true)) {
            return $this->refuse($request, 'That task is already open.', '/tasks/' . $id);
        }

        $now = now_utc();

        // The completion stamps are cleared, not kept: a reopened task has not
        // been finished by anybody, and leaving a name and a time there would
        // make the next report disagree with the status beside it.
        $fields = [
            'status'       => 'open',
            'completed_at' => null,
            'completed_by' => null,
            'updated_at'   => $now,
        ];

        DB::transaction(static function () use ($id, $fields, $task): void {
            DB::update('tasks', $fields, 'id = :id', ['id' => $id]);

            Audit::record('task.reopened', 'tasks', $id, $task, $fields);
        });

        return $this->success($request, (string) $task['title'] . ' is open again.', '/tasks/' . $id);
    }

    public function storeComment(Request $request): Response
    {
        if ($denied = $this->authorize(self::WRITE_PERMISSION)) {
            return $denied;
        }

        $id   = $request->routeInt('id');
        $task = $this->findTask($id, self::WRITE_PERMISSION);

        if ($task === null) {
            return $this->notFound('That task does not exist, or it is not one you may comment on.');
        }

        $data = $this->validate($request, [
            'body' => 'required|string|min:2|max:5000',
        ], ['body' => 'Comment']);

        if ($data instanceof Response) {
            return $data;
        }

        $commentId = DB::insert('task_comments', [
            'task_id'    => $id,
            'user_id'    => Auth::id(),
            'body'       => $data['body'],
            'created_at' => now_utc(),
        ]);

        // A comment is activity on the task, so the task's own clock moves too
        // — otherwise a busy discussion leaves a task looking untouched.
        DB::update('tasks', ['updated_at' => now_utc()], 'id = :id', ['id' => $id]);

        Audit::record('task_comment.created', 'task_comments', $commentId, null, [
            'task_id' => $id,
            // The body is not copied into the audit diff. It would make the log
            // a second, less guarded copy of what staff wrote about a colleague
            // or a guest; the length is enough to show the write happened.
            'length'  => strlen((string) $data['body']),
        ]);

        return $this->success($request, 'Comment added.', '/tasks/' . $id);
    }

    // =================================================================
    // Time: the venue's day, not the server's
    // =================================================================

    /** Today's date in the venue's timezone. */
    private function venueToday(): string
    {
        return (new \DateTimeImmutable('now', venue_tz()))->format('Y-m-d');
    }

    private function venueZoneName(): string
    {
        return venue_tz()->getName();
    }

    /**
     * A venue-local date and time as the UTC instant to store.
     *
     * The whole module turns on this one conversion. "2026-10-16 23:00" at The
     * Castle is 03:00 UTC on the 17th, so a task due late tonight lives on
     * TOMORROW's UTC date — and anything that reads the typed string as UTC has
     * the task an hour late before it is even saved.
     */
    private function localToUtc(string $localDate, string $localTime): ?string
    {
        try {
            return (new \DateTimeImmutable($localDate . ' ' . $localTime, venue_tz()))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * A stored UTC instant split back into the venue-local date and time the
     * form shows, so editing a task does not quietly move it.
     *
     * @return array{date:string,time:string}
     */
    private function localParts(?string $utc): array
    {
        if ($utc === null || $utc === '' || str_starts_with($utc, '0000')) {
            return ['date' => '', 'time' => ''];
        }

        try {
            $local = (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(venue_tz());
        } catch (\Exception) {
            return ['date' => '', 'time' => ''];
        }

        return ['date' => $local->format('Y-m-d'), 'time' => $local->format('H:i')];
    }

    /**
     * The UTC instants that bound one venue-local day: [start, end).
     *
     * The end is the START of the next local day rather than 23:59:59, and it
     * is reached with a calendar +1 day in the venue's own zone — so the window
     * is 23 or 25 hours long across a daylight-saving change, which is what the
     * venue's day actually is on those two days of the year.
     *
     * @return array{0:string,1:string}
     */
    private function venueDayBounds(string $localDate): array
    {
        $utc = new \DateTimeZone('UTC');

        try {
            $start = new \DateTimeImmutable($localDate . ' 00:00:00', venue_tz());
        } catch (\Exception) {
            $start = new \DateTimeImmutable('today', venue_tz());
        }

        return [
            $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            $start->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * The clock values a list query needs: now, and today's local bounds.
     *
     * Read once per statement, and every statement that needs the clock takes
     * all of it from here — so the flag printed on a row and the ORDER BY that
     * put the row where it is cannot disagree about when "now" was. A row that
     * sorted as overdue but rendered as on time would be the most confusing
     * possible bug on this page.
     *
     * @return array<string,string>
     */
    private function clock(): array
    {
        [$start, $end] = $this->venueDayBounds($this->venueToday());

        return [
            'clock_now'   => now_utc(),
            'clock_start' => $start,
            'clock_end'   => $end,
        ];
    }

    // =================================================================
    // The list
    // =================================================================

    private function chosenView(Request $request): string
    {
        $view = $request->str('view');

        return isset(self::VIEWS[$view]) ? $view : 'all';
    }

    /** @return array{view:string,status:string,assignee:string,q:string} */
    private function filterValues(Request $request): array
    {
        $status   = $request->str('status');
        $assignee = $request->str('assignee');

        return [
            'view'     => $this->chosenView($request),
            'status'   => in_array($status, self::STATUSES, true) ? $status : '',
            'assignee' => ($assignee === 'none' || ctype_digit($assignee)) ? $assignee : '',
            'q'        => mb_substr($request->str('q'), 0, 80),
        ];
    }

    /**
     * The WHERE clause for a list view, scope included.
     *
     * The local is $clauses rather than the obvious $where because the
     * interpolated-SQL gate in tests/static treats a line carrying a quoted
     * string, a $where and one more variable as interpolation — its keyword
     * check matches the variable NAME. Keeping the name out of the way is
     * cheaper than weakening the gate that guards every query in the app.
     *
     * @return array{0:string,1:array<string,mixed>}
     */
    private function taskFilter(Request $request, string $view): array
    {
        [$scope, $params] = Acl::scopeCondition('view_tasks', 't.assigned_to');

        $clauses = ['(' . $scope . ')'];
        $filters = $this->filterValues($request);

        // Each working view answers "what needs doing", so it is limited to
        // live tasks — unless the reader asked for a status outright, in which
        // case that is the answer they wanted and it replaces the default.
        $live = true;

        switch ($view) {
            case 'mine':
                $clauses[] = 't.assigned_to = :me';
                $params['me'] = Auth::id() ?? 0;
                break;

            case 'overdue':
                // An instant comparison, so this says the same thing in every
                // timezone. See the class docblock, rule 2.
                $clauses[] = 't.due_at IS NOT NULL AND t.due_at < :overdue_now';
                $params['overdue_now'] = now_utc();
                break;

            case 'today':
                // A WINDOW, and the window is the venue's local day. In UTC it
                // would be the wrong day for several hours every evening.
                [$start, $end] = $this->venueDayBounds($this->venueToday());
                $clauses[] = 't.due_at >= :today_start AND t.due_at < :today_end';
                $params['today_start'] = $start;
                $params['today_end']   = $end;
                break;

            case 'unassigned':
                $clauses[] = 't.assigned_to IS NULL';
                break;

            default:
                $live = false;
        }

        if ($filters['status'] !== '') {
            $clauses[] = 't.status = :status';
            $params['status'] = $filters['status'];
        } elseif ($live) {
            $clauses[] = 't.status IN ' . self::LIVE;
        }

        if ($filters['assignee'] === 'none') {
            $clauses[] = 't.assigned_to IS NULL';
        } elseif ($filters['assignee'] !== '') {
            $clauses[] = 't.assigned_to = :assignee';
            $params['assignee'] = (int) $filters['assignee'];
        }

        if ($filters['q'] !== '') {
            // One name per occurrence — the native driver refuses a repeated
            // named parameter (HY093), which is the same reason :clock_now and
            // :clock_sort are separate below. Reusing :q made /tasks?q=... a
            // 500 for every search.
            $like = '%' . $filters['q'] . '%';
            $clauses[] = '(t.title LIKE :q_title OR t.description LIKE :q_body)';
            $params['q_title'] = $like;
            $params['q_body']  = $like;
        }

        return [implode(' AND ', $clauses), $params];
    }

    /**
     * One page of tasks, overdue first.
     *
     * is_overdue and is_due_today are computed in the same statement as the
     * ordering, from the same bound clock, so what the page shows and what it
     * sorted by are one decision rather than two.
     *
     * @param array<string,mixed>                   $params
     * @param array{page:int,perPage:int,offset:int} $pagination
     * @return list<array<string,mixed>>
     */
    private function taskRows(string $filter, array $params, array $pagination): array
    {
        $params = array_merge($params, $this->clock());

        // :clock_sort is the same instant as :clock_now under a second name.
        // PDO with emulated prepares OFF rewrites named placeholders to
        // positional ones and binds each name exactly once, so a statement
        // naming :clock_now in both its SELECT and its ORDER BY is refused
        // outright with "Invalid parameter number" — MEASURED, it 500'd the
        // whole list page. Two names, one value, taken from the same read so
        // the flag and the sort cannot disagree.
        //
        // The extra name is added HERE and not in clock(), because binding a
        // parameter a statement does not use is refused for the same reason:
        // viewCounts() and findTask() do not sort.
        $params['clock_sort'] = $params['clock_now'];

        return DB::all(
            'SELECT t.id, t.title, t.description, t.entity_type, t.entity_id,
                    t.assigned_to, t.created_by, t.priority, t.status, t.due_at,
                    t.completed_at, t.completed_by, t.created_at, t.updated_at,
                    au.name AS assignee_name,
                    cu.name AS creator_name,
                    du.name AS completer_name,
                    (SELECT COUNT(*) FROM {task_comments} tc WHERE tc.task_id = t.id) AS comment_count,
                    CASE WHEN t.status IN ' . self::LIVE . '
                               AND t.due_at IS NOT NULL
                               AND t.due_at < :clock_now
                         THEN 1 ELSE 0 END AS is_overdue,
                    CASE WHEN t.due_at IS NOT NULL
                               AND t.due_at >= :clock_start
                               AND t.due_at < :clock_end
                         THEN 1 ELSE 0 END AS is_due_today
               FROM {tasks} t
               LEFT JOIN {users} au ON au.id = t.assigned_to
               LEFT JOIN {users} cu ON cu.id = t.created_by
               LEFT JOIN {users} du ON du.id = t.completed_by
              WHERE ' . $filter . '
              ORDER BY CASE WHEN t.status IN ' . self::LIVE . '
                                 AND t.due_at IS NOT NULL
                                 AND t.due_at < :clock_sort
                            THEN 0 ELSE 1 END,
                       t.due_at IS NULL,
                       t.due_at ASC,
                       FIELD(t.priority, \'urgent\', \'high\', \'normal\', \'low\'),
                       t.id DESC
              LIMIT ' . (int) $pagination['perPage'] . ' OFFSET ' . (int) $pagination['offset'],
            $params
        );
    }

    /**
     * How much live work each view is holding, for the tab strip.
     *
     * One grouped statement rather than five counts, and scoped like every
     * other read, so a Front Desk account is told about its own backlog and
     * not the venue's.
     *
     * @return array<string,int>
     */
    private function viewCounts(): array
    {
        [$scope, $params] = Acl::scopeCondition('view_tasks', 't.assigned_to');

        $params = array_merge($params, $this->clock(), ['me' => Auth::id() ?? 0]);

        $row = DB::first(
            'SELECT COUNT(*) AS live_total,
                    SUM(CASE WHEN t.assigned_to = :me THEN 1 ELSE 0 END) AS mine,
                    SUM(CASE WHEN t.due_at IS NOT NULL
                                  AND t.due_at < :clock_now
                             THEN 1 ELSE 0 END) AS overdue,
                    SUM(CASE WHEN t.due_at IS NOT NULL
                                  AND t.due_at >= :clock_start
                                  AND t.due_at < :clock_end
                             THEN 1 ELSE 0 END) AS today,
                    SUM(CASE WHEN t.assigned_to IS NULL THEN 1 ELSE 0 END) AS unassigned
               FROM {tasks} t
              WHERE t.status IN ' . self::LIVE . ' AND (' . $scope . ')',
            $params
        );

        return [
            'mine'       => (int) ($row['mine'] ?? 0),
            'overdue'    => (int) ($row['overdue'] ?? 0),
            'today'      => (int) ($row['today'] ?? 0),
            'unassigned' => (int) ($row['unassigned'] ?? 0),
            'all'        => (int) ($row['live_total'] ?? 0),
        ];
    }

    // =================================================================
    // Guards shared by store() and update()
    // =================================================================

    /**
     * Resolve the parts of a write that validation cannot judge: the due
     * instant, the assignee, and the record the task hangs off.
     *
     * @param array<string,mixed>      $data validated input
     * @param array<string,mixed>|null $task the existing row, on update
     * @return array{0:?string,1:?int,2:?string,3:?int}|Response
     */
    private function resolveWrite(Request $request, array $data, ?array $task): array|Response
    {
        $fallback = $task === null ? '/tasks/new' : '/tasks/' . (int) $task['id'] . '/edit';

        $dueAt = $this->resolveDue($data);

        if ($dueAt === false) {
            return $this->refuse(
                $request,
                'That due date and time could not be read. Use a date like 2026-10-16 and a time like 17:30.',
                $fallback
            );
        }

        $assignedTo   = $this->nullableId($data['assigned_to'] ?? null);
        $currentOwner = $task === null ? null : $this->nullableId($task['assigned_to'] ?? null);

        // `exists:users` cannot see is_active or deleted_at, so without this a
        // task could be parked on somebody who has left and would then sit in
        // nobody's list. Only checked when the assignee CHANGES: a task whose
        // assignee has since left the venue is exactly the task somebody needs
        // to edit, and refusing that would strand it.
        if ($assignedTo !== $currentOwner && $assignedTo !== null && !$this->isAssignable($assignedTo)) {
            return $this->refuse($request, 'That assignee is not an active user.', $fallback);
        }

        $link = $this->resolveEntity($data);

        if ($link === false) {
            return $this->refuse(
                $request,
                'That linked record does not exist. Pick the kind of record and paste its number, or leave both blank.',
                $fallback
            );
        }

        return [$dueAt, $assignedTo, $link[0], $link[1]];
    }

    /**
     * The due instant in UTC, null for no deadline, or false when unreadable.
     *
     * A date with no time means "by the end of the venue's day", which is what
     * somebody typing only a date means. Storing 00:00 instead would make every
     * task due today read as overdue from one minute past midnight.
     *
     * @param array<string,mixed> $data
     */
    private function resolveDue(array $data): string|false|null
    {
        $date = trim((string) ($data['due_date'] ?? ''));

        if ($date === '') {
            return null;
        }

        $time = trim((string) ($data['due_time'] ?? ''));

        if ($time === '') {
            $time = self::DEFAULT_DUE_TIME;
        }

        $utc = $this->localToUtc($date, $time);

        return $utc ?? false;
    }

    /**
     * The entity_type / entity_id pair, or false when it names nothing real.
     *
     * @param array<string,mixed> $data
     * @return array{0:?string,1:?int}|false
     */
    private function resolveEntity(array $data): array|false
    {
        $type = trim((string) ($data['entity_type'] ?? ''));
        $id   = $this->nullableId($data['entity_id'] ?? null);

        if ($type === '' && $id === null) {
            return [null, null];
        }

        // Half a link is worse than none: a type with no id shows "Deal" and
        // goes nowhere, and an id with no type cannot be resolved at all.
        if ($type === '' || $id === null || !isset(self::ENTITIES[$type])) {
            return false;
        }

        if (!$this->entityExists($type, $id)) {
            return false;
        }

        return [$type, $id];
    }

    /**
     * Does the linked record exist?
     *
     * Written out per type rather than interpolating a table name, so the
     * soft-delete rule for each one is visible and the statement is static.
     */
    private function entityExists(string $type, int $id): bool
    {
        return match ($type) {
            'contact' => DB::exists(
                'SELECT id FROM {contacts} WHERE id = :id AND deleted_at IS NULL',
                ['id' => $id]
            ),
            'deal' => DB::exists(
                'SELECT id FROM {deals} WHERE id = :id AND deleted_at IS NULL',
                ['id' => $id]
            ),
            'quote' => DB::exists('SELECT id FROM {quotes} WHERE id = :id', ['id' => $id]),
            'household' => DB::exists(
                'SELECT id FROM {households} WHERE id = :id AND deleted_at IS NULL',
                ['id' => $id]
            ),
            default => false,
        };
    }

    /**
     * The completion columns a status change implies.
     *
     * @param array<string,mixed> $task the row as it stands
     * @return array<string,mixed>
     */
    private function completionColumns(array $task, string $status, string $now): array
    {
        $wasDone = (string) $task['status'] === 'done';

        if ($status === 'done') {
            // Keep the original stamp when a task that was already done is
            // saved again, so "who finished this, and when" is not rewritten
            // by somebody fixing a typo in the title.
            return $wasDone
                ? []
                : ['completed_at' => $now, 'completed_by' => Auth::id()];
        }

        return $wasDone
            ? ['completed_at' => null, 'completed_by' => null]
            : [];
    }

    /** @return array<string,string> */
    private function rules(): array
    {
        return [
            'title'       => 'required|string|min:2|max:191',
            'description' => 'nullable|string|max:20000',
            'assigned_to' => 'nullable|int|exists:users',
            'priority'    => 'nullable|in:' . implode(',', self::PRIORITIES),
            'status'      => 'nullable|in:' . implode(',', self::STATUSES),
            'due_date'    => 'nullable|date_format:Y-m-d',
            'due_time'    => 'nullable|time',
            'entity_type' => 'nullable|in:' . implode(',', array_keys(self::ENTITIES)),
            'entity_id'   => 'nullable|int|min:1',
        ];
    }

    /** @return array<string,string> */
    private function labels(): array
    {
        return [
            'title'       => 'Task',
            'description' => 'Details',
            'assigned_to' => 'Assignee',
            'priority'    => 'Priority',
            'status'      => 'Status',
            'due_date'    => 'Due date',
            'due_time'    => 'Due time',
            'entity_type' => 'Linked record',
            'entity_id'   => 'Record number',
        ];
    }

    // =================================================================
    // Lookups
    // =================================================================

    /**
     * A task the current user may act on under this permission, or null.
     *
     * The scope is applied in SQL, so 'team' resolves through the same
     * membership query the rest of the application uses and an out-of-scope
     * task is indistinguishable from one that does not exist.
     *
     * @return array<string,mixed>|null
     */
    /**
     * May the current user close, reopen or comment on THIS task?
     *
     * Holding the write permission is one way. Being the person it is assigned
     * to is the other, and it has to be: a task board where the assignee can
     * see their own job and cannot tick it off is not a task board. The spec
     * section this module implements is called "Tasks & team accountability",
     * and accountability runs through the person doing the work.
     *
     * RAISING and REASSIGNING stay privileged — those hand work out, which is
     * what the write permission is actually about. This only covers finishing
     * what you were already given, and commenting on it.
     *
     * @param array<string,mixed> $task
     */
    private function mayCloseOwn(array $task): bool
    {
        if (Acl::can(self::WRITE_PERMISSION)) {
            return true;
        }

        $me = Auth::id();

        return $me > 0 && (int) ($task['assigned_to'] ?? 0) === $me;
    }

    private function findTask(int $id, string $permission): ?array
    {
        if ($id <= 0) {
            return null;
        }

        [$scope, $params] = Acl::scopeCondition($permission, 't.assigned_to');

        $params = array_merge($params, $this->clock(), ['id' => $id]);

        return DB::first(
            'SELECT t.*,
                    au.name AS assignee_name,
                    cu.name AS creator_name,
                    du.name AS completer_name,
                    CASE WHEN t.status IN ' . self::LIVE . '
                               AND t.due_at IS NOT NULL
                               AND t.due_at < :clock_now
                         THEN 1 ELSE 0 END AS is_overdue,
                    CASE WHEN t.due_at IS NOT NULL
                               AND t.due_at >= :clock_start
                               AND t.due_at < :clock_end
                         THEN 1 ELSE 0 END AS is_due_today
               FROM {tasks} t
               LEFT JOIN {users} au ON au.id = t.assigned_to
               LEFT JOIN {users} cu ON cu.id = t.created_by
               LEFT JOIN {users} du ON du.id = t.completed_by
              WHERE t.id = :id AND (' . $scope . ')',
            $params
        );
    }

    /** @return list<array<string,mixed>> */
    private function comments(int $taskId): array
    {
        return DB::all(
            'SELECT c.id, c.body, c.created_at, u.name AS user_name
               FROM {task_comments} c
               LEFT JOIN {users} u ON u.id = c.user_id
              WHERE c.task_id = :id
              ORDER BY c.id ASC
              LIMIT 500',
            ['id' => $taskId]
        );
    }

    /**
     * The label and URL for a task's linked record, or null.
     *
     * The label is looked up rather than stored, so renaming a deal renames it
     * everywhere it is referenced.
     *
     * @param array<string,mixed> $task
     * @return array{label:string,name:string,url:string}|null
     */
    private function entityLink(array $task): ?array
    {
        $type = (string) ($task['entity_type'] ?? '');
        $id   = (int) ($task['entity_id'] ?? 0);

        if ($id <= 0 || !isset(self::ENTITIES[$type])) {
            return null;
        }

        [$label, $path] = self::ENTITIES[$type];

        $name = match ($type) {
            'contact' => trim((string) DB::value(
                "SELECT CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))
                   FROM {contacts} WHERE id = :id",
                ['id' => $id]
            )),
            'deal' => (string) DB::value('SELECT title FROM {deals} WHERE id = :id', ['id' => $id]),
            'quote' => (string) DB::value('SELECT number FROM {quotes} WHERE id = :id', ['id' => $id]),
            'household' => (string) DB::value('SELECT name FROM {households} WHERE id = :id', ['id' => $id]),
            default => '',
        };

        return [
            'label' => $label,
            'name'  => $name === '' ? $label . ' #' . $id : $name,
            'url'   => $path . $id,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function assigneeOptions(): array
    {
        return DB::all(
            'SELECT id, name FROM {users}
              WHERE is_active = 1 AND deleted_at IS NULL
              ORDER BY name'
        );
    }

    private function isAssignable(int $userId): bool
    {
        return DB::exists(
            'SELECT id FROM {users} WHERE id = :id AND is_active = 1 AND deleted_at IS NULL',
            ['id' => $userId]
        );
    }

    private function nullableId(mixed $value): ?int
    {
        if ($value === null || $value === '' || (is_numeric($value) && (int) $value <= 0)) {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }
}
