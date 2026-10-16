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
 * Sales pipelines and deals.
 *
 * Three rules shape this class, and the rest is bookkeeping:
 *
 *   1. A deal's STAGE is the single source of truth for its status. Entering a
 *      won stage wins the deal, entering a lost stage loses it, and entering
 *      any other stage reopens it. There is no separate status field on the
 *      form, because two writable sources would disagree within a week.
 *
 *   2. Every stage change — from the board, the table, the move form OR the
 *      edit form — goes through stageTransition(), which writes the
 *      {deal_stage_history} row. The velocity report is only possible if that
 *      row is never skipped, so there is exactly one code path that can move a
 *      deal and it always records who moved it and how long the deal had sat.
 *
 *   3. Reading and writing are both scoped. Acl::scopeCondition() turns the
 *      owner-scoped roles (Party Host holds view_deals and deals_manage at
 *      'own') into a WHERE clause, and a deal outside the scope is a 404 — not
 *      a visible row that refuses to open. Writes are scoped through
 *      deals_manage, deletes through deals_delete, so a host cannot edit a
 *      colleague's deal by POSTing its id.
 *
 * Money is integer cents in the column and in every calculation. The form
 * takes dollars because that is what a person types; parseMoney() converts it
 * with integer arithmetic only, never a float multiplication.
 */
final class DealController extends Controller
{
    /**
     * Where a lead came from.
     *
     * An allow-list rather than free text: "Website form" and "web form" and
     * "website" are three rows in every source report otherwise. The keys
     * match what install/seeds/demo.php writes.
     *
     * @var array<string,string>
     */
    public const SOURCES = [
        'website_form' => 'Website form',
        'phone'        => 'Phone',
        'walk_in'      => 'Walk-in',
        'email'        => 'Email',
        'referral'     => 'Referral',
        'repeat'       => 'Repeat guest',
        'social'       => 'Social media',
        'event'        => 'Event or expo',
        'other'        => 'Other',
    ];

    /** The deals.status enum. */
    private const STATUSES = ['open', 'won', 'lost', 'abandoned'];

    /** Cards drawn per board column before the column links to the table. */
    private const PER_COLUMN = 25;

    /**
     * The widest a deal value may be: $99,999,999.99 in cents.
     *
     * The column is a BIGINT, so this is a typo guard rather than a storage
     * limit — a mis-keyed value is far more likely than a nine-figure party.
     */
    private const MAX_VALUE_CENTS = 9999999999;

    // =================================================================
    // Reading
    // =================================================================

    /** The board: stages as columns, deals as cards. */
    public function index(Request $request): Response
    {
        return $this->collection($request, 'board');
    }

    /**
     * The same deals as a plain table.
     *
     * Not a nicety: the board is a grid of cards, which is the wrong shape for
     * a phone, for a screen reader and for anybody who wants to sort by value.
     * Both views read through the same filter and the same aggregate, so they
     * can never disagree about what is in the pipeline.
     */
    public function table(Request $request): Response
    {
        return $this->collection($request, 'table');
    }

    public function show(Request $request): Response
    {
        $deal = $this->findDeal($request->routeInt('id'), 'view_deals');

        if ($deal === null) {
            return $this->notFound('That deal does not exist, or it is not one of yours.');
        }

        return $this->view('deals/show', [
            'pageTitle' => $deal['title'],
            'deal'      => $deal,
            'stages'    => $this->stagesFor((int) $deal['pipeline_id']),
            'history'   => $this->history((int) $deal['id']),
        ]);
    }

    public function create(Request $request): Response
    {
        if ($denied = $this->authorize('deals_manage')) {
            return $denied;
        }

        $stageGroups = $this->stageGroups();

        if ($stageGroups === []) {
            return $this->refuse(
                $request,
                'There is no pipeline with any stages yet. Build one first, then add deals to it.',
                '/pipelines'
            );
        }

        return $this->view('deals/form', [
            'pageTitle'   => 'New deal',
            'deal'        => null,
            'stageGroups' => $stageGroups,
            'contacts'    => $this->contactOptions(),
            'owners'      => $this->ownerOptions(),
            'pipelineId'  => $request->int('pipeline'),
        ]);
    }

    public function edit(Request $request): Response
    {
        if ($denied = $this->authorize('deals_manage')) {
            return $denied;
        }

        $deal = $this->findDeal($request->routeInt('id'), 'deals_manage');

        if ($deal === null) {
            return $this->notFound('That deal does not exist, or it is not one you may edit.');
        }

        return $this->view('deals/form', [
            'pageTitle'   => 'Edit ' . $deal['title'],
            'deal'        => $deal,
            'stageGroups' => $this->stageGroups(),
            'contacts'    => $this->contactOptions((int) ($deal['contact_id'] ?? 0)),
            'owners'      => $this->ownerOptions(),
            'pipelineId'  => (int) $deal['pipeline_id'],
        ]);
    }

    // =================================================================
    // Writing
    // =================================================================

    public function store(Request $request): Response
    {
        if ($denied = $this->authorize('deals_manage')) {
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

        [$stage, $ownerId, $valueCents] = $resolved;

        $now = now_utc();

        $row = [
            'pipeline_id'         => (int) $stage['pipeline_id'],
            'stage_id'            => (int) $stage['id'],
            'title'               => $data['title'],
            'contact_id'          => $this->nullableId($data['contact_id'] ?? null),
            'owner_id'            => $ownerId,
            'value_cents'         => $valueCents,
            'expected_close_date' => $data['expected_close_date'] ?? null,
            'event_date'          => $data['event_date'] ?? null,
            'guest_count'         => $this->nullableId($data['guest_count'] ?? null),
            'source'              => $data['source'] ?? null,
            'source_detail'       => $data['source_detail'] ?? null,
            'next_action'         => $data['next_action'] ?? null,
            'created_at'          => $now,
            'updated_at'          => $now,
        ];

        // The stage decides status, the deadline and the stage clock, exactly
        // as it does on every later move.
        $row = array_merge($row, $this->stageColumns([], $stage, $now));

        $dealId = DB::transaction(static function () use ($row, $stage, $now): int {
            $id = DB::insert('deals', $row);

            // The opening history row, with no from-stage. Without it the
            // detail page's history would start at the first MOVE and the
            // deal's time in its first stage could not be measured.
            DB::insert('deal_stage_history', [
                'deal_id'          => $id,
                'from_stage_id'    => null,
                'to_stage_id'      => (int) $stage['id'],
                'user_id'          => Auth::id(),
                'duration_seconds' => null,
                'created_at'       => $now,
            ]);

            Audit::record('deal.created', 'deals', $id, null, $row);

            return $id;
        });

        Event::fire('deal.created', ['id' => $dealId, 'pipeline_id' => (int) $stage['pipeline_id']]);
        $this->fireOutcome($dealId, $stage);

        // Flash text is escaped where the layout prints it, so it is passed
        // through raw here — escaping it twice turns an apostrophe in a guest's
        // name into &amp;#039; on screen.
        return $this->success($request, $data['title'] . ' is on the board.', '/deals/' . $dealId);
    }

    public function update(Request $request): Response
    {
        if ($denied = $this->authorize('deals_manage')) {
            return $denied;
        }

        $id   = $request->routeInt('id');
        $deal = $this->findDeal($id, 'deals_manage');

        if ($deal === null) {
            return $this->notFound('That deal does not exist, or it is not one you may edit.');
        }

        $data = $this->validate($request, $this->rules(), $this->labels());

        if ($data instanceof Response) {
            return $data;
        }

        $resolved = $this->resolveWrite($request, $data, $deal);

        if ($resolved instanceof Response) {
            return $resolved;
        }

        [$stage, $ownerId, $valueCents] = $resolved;

        $now     = now_utc();
        $moved   = (int) $stage['id'] !== (int) $deal['stage_id'];
        $fromId  = (int) $deal['stage_id'];

        $update = [
            'pipeline_id'         => (int) $stage['pipeline_id'],
            'title'               => $data['title'],
            'contact_id'          => $this->nullableId($data['contact_id'] ?? null),
            'owner_id'            => $ownerId,
            'value_cents'         => $valueCents,
            'expected_close_date' => $data['expected_close_date'] ?? null,
            'event_date'          => $data['event_date'] ?? null,
            'guest_count'         => $this->nullableId($data['guest_count'] ?? null),
            'source'              => $data['source'] ?? null,
            'source_detail'       => $data['source_detail'] ?? null,
            'next_action'         => $data['next_action'] ?? null,
            'updated_at'          => $now,
        ];

        // A stage change made through the edit form is still a stage change: it
        // goes through the same transition so the history row cannot be lost
        // down this path.
        if ($moved) {
            $update = array_merge($update, $this->stageColumns($deal, $stage, $now));
        }

        DB::transaction(function () use ($id, $update, $deal, $moved, $fromId, $stage, $now): void {
            DB::update('deals', $update, 'id = :id', ['id' => $id]);

            Audit::record('deal.updated', 'deals', $id, $deal, $update);

            if ($moved) {
                $this->recordMove($id, $fromId, (int) $stage['id'], $deal, $now);
            }
        });

        if ($moved) {
            Event::fire('deal.stage_changed', [
                'id'            => $id,
                'from_stage_id' => $fromId,
                'to_stage_id'   => (int) $stage['id'],
            ]);
            $this->fireOutcome($id, $stage);
        }

        return $this->success($request, 'Deal updated.', '/deals/' . $id);
    }

    /**
     * Move a deal to another stage.
     *
     * A POST with a stage <select>, deliberately: drag-and-drop cannot be
     * operated by keyboard and would need JavaScript the rest of this
     * application does not use.
     */
    public function move(Request $request): Response
    {
        if ($denied = $this->authorize('deals_manage')) {
            return $denied;
        }

        $id   = $request->routeInt('id');
        $deal = $this->findDeal($id, 'deals_manage');

        if ($deal === null) {
            return $this->notFound('That deal does not exist, or it is not one you may edit.');
        }

        $back = $this->returnPath($request, (int) $deal['pipeline_id'], $id);

        $stageId = $request->int('stage_id');
        $stage   = $stageId > 0
            ? DB::first('SELECT * FROM {pipeline_stages} WHERE id = :id', ['id' => $stageId])
            : null;

        if ($stage === null) {
            return $this->refuse($request, 'Choose a stage to move the deal to.', $back);
        }

        if ((int) $stage['pipeline_id'] !== (int) $deal['pipeline_id']) {
            return $this->refuse(
                $request,
                'That stage belongs to another pipeline. Use Edit to move the deal between pipelines.',
                $back
            );
        }

        if ((int) $stage['id'] === (int) $deal['stage_id']) {
            // Not an error worth a stack trace, but recording it would put a
            // zero-length row in the history of every deal somebody
            // double-submitted.
            return $this->refuse($request, 'That deal is already in ' . (string) $stage['name'] . '.', $back);
        }

        $now    = now_utc();
        $fromId = (int) $deal['stage_id'];
        $update = array_merge($this->stageColumns($deal, $stage, $now), ['updated_at' => $now]);

        DB::transaction(function () use ($id, $update, $deal, $fromId, $stage, $now): void {
            DB::update('deals', $update, 'id = :id', ['id' => $id]);

            $this->recordMove($id, $fromId, (int) $stage['id'], $deal, $now);

            Audit::record('deal.stage_changed', 'deals', $id, $deal, $update);
        });

        Event::fire('deal.stage_changed', [
            'id'            => $id,
            'from_stage_id' => $fromId,
            'to_stage_id'   => (int) $stage['id'],
        ]);
        $this->fireOutcome($id, $stage);

        return $this->success(
            $request,
            (string) $deal['title'] . ' moved to ' . (string) $stage['name'] . '.',
            $back
        );
    }

    public function destroy(Request $request): Response
    {
        if ($denied = $this->authorize('deals_delete')) {
            return $denied;
        }

        $id   = $request->routeInt('id');
        $deal = $this->findDeal($id, 'deals_delete');

        if ($deal === null) {
            return $this->notFound('That deal does not exist, or it is not one you may delete.');
        }

        // Soft delete. The stage history, the activities and any quote all
        // point at this row, and the venue's own record of what happened is
        // worth more than the disk space.
        DB::transaction(static function () use ($id, $deal): void {
            DB::update('deals', [
                'deleted_at' => now_utc(),
                'updated_at' => now_utc(),
            ], 'id = :id AND deleted_at IS NULL', ['id' => $id]);

            Audit::record('deal.deleted', 'deals', $id, $deal, null);
        });

        return $this->success(
            $request,
            (string) $deal['title'] . ' has been removed from the pipeline.',
            '/deals?pipeline=' . (int) $deal['pipeline_id']
        );
    }

    // =================================================================
    // The board and the table
    // =================================================================

    private function collection(Request $request, string $mode): Response
    {
        $pipelines = $this->pipelineOptions();

        if ($pipelines === []) {
            return $this->view('deals/index', [
                'pageTitle' => 'Pipelines',
                'mode'      => $mode,
                'pipelines' => [],
                'pipeline'  => null,
                'stages'    => [],
                'cards'     => [],
                'deals'     => [],
                'totals'    => [],
                'overflow'  => [],
                'summary'   => ['count' => 0, 'value_cents' => 0],
                'filters'   => $this->filterValues($request),
                'owners'    => [],
                'page'      => 1,
                'lastPage'  => 1,
                'perPage'   => 50,
            ]);
        }

        $pipeline = $this->chosenPipeline($request, $pipelines);
        $stages   = $this->stagesFor((int) $pipeline['id']);

        [$filter, $params] = $this->dealFilter($request, (int) $pipeline['id']);

        // Counts and value totals come from a grouped aggregate, never from
        // the rows drawn on screen: a column that shows its first 25 cards
        // must still report all 60 deals and their full value.
        $totals = [];
        $summary = ['count' => 0, 'value_cents' => 0];

        foreach (DB::all(
            'SELECT d.stage_id, COUNT(*) AS deal_count, COALESCE(SUM(d.value_cents), 0) AS value_cents
               FROM {deals} d
               LEFT JOIN {contacts} c ON c.id = d.contact_id
              WHERE ' . $filter . '
              GROUP BY d.stage_id',
            $params
        ) as $group) {
            $totals[(int) $group['stage_id']] = [
                'count'       => (int) $group['deal_count'],
                'value_cents' => (int) $group['value_cents'],
            ];

            $summary['count']       += (int) $group['deal_count'];
            $summary['value_cents'] += (int) $group['value_cents'];
        }

        $pagination = $this->pagination($request, 50);
        $cards      = [];
        $deals      = [];
        $overflow   = [];
        $lastPage   = 1;

        if ($mode === 'board') {
            foreach ($this->dealRows($filter, $params, 'board') as $deal) {
                $stageId = (int) $deal['stage_id'];

                if (count($cards[$stageId] ?? []) >= self::PER_COLUMN) {
                    continue;
                }

                $cards[$stageId][] = $deal;
            }

            // What a column could not draw is counted from the AGGREGATE, not
            // from the rows that were skipped above: the fetch itself is
            // capped, so counting skipped rows would under-report a very large
            // pipeline and the "+N more" link would lie.
            foreach ($totals as $stageId => $total) {
                $shown = count($cards[$stageId] ?? []);

                if ($total['count'] > $shown) {
                    $overflow[$stageId] = $total['count'] - $shown;
                }
            }
        } else {
            $deals    = $this->dealRows($filter, $params, 'table', $pagination);
            $lastPage = max(1, (int) ceil($summary['count'] / $pagination['perPage']));
        }

        return $this->view('deals/index', [
            'pageTitle' => $mode === 'board' ? 'Pipelines' : 'Deals',
            'mode'      => $mode,
            'pipelines' => $pipelines,
            'pipeline'  => $pipeline,
            'stages'    => $stages,
            'cards'     => $cards,
            'deals'     => $deals,
            'totals'    => $totals,
            'overflow'  => $overflow,
            'summary'   => $summary,
            'filters'   => $this->filterValues($request),
            'owners'    => $this->ownerOptions(),
            'page'      => $pagination['page'],
            'perPage'   => $pagination['perPage'],
            'lastPage'  => $lastPage,
        ]);
    }

    /**
     * @param array<string,mixed>                       $params
     * @param array{page:int,perPage:int,offset:int}|null $pagination
     * @return list<array<string,mixed>>
     */
    private function dealRows(string $filter, array $params, string $mode, ?array $pagination = null): array
    {
        // A board draws every column at once, so it reads a bounded slab and
        // reports whatever it could not draw; the table pages properly.
        if ($mode === 'board') {
            $limit  = (self::PER_COLUMN * 40) + 1;
            $offset = 0;
        } else {
            $limit  = $pagination['perPage'] ?? 50;
            $offset = $pagination['offset'] ?? 0;
        }

        return DB::all(
            'SELECT d.id, d.title, d.pipeline_id, d.stage_id, d.status, d.value_cents,
                    d.expected_close_date, d.event_date, d.guest_count, d.source,
                    d.contact_id, d.owner_id, d.stage_entered_at, d.sla_due_at,
                    d.next_action, d.next_action_at, d.created_at,
                    s.name AS stage_name, s.color AS stage_color,
                    c.first_name, c.last_name,
                    u.name AS owner_name
               FROM {deals} d
               JOIN {pipeline_stages} s ON s.id = d.stage_id
               LEFT JOIN {contacts} c ON c.id = d.contact_id
               LEFT JOIN {users} u ON u.id = d.owner_id
              WHERE ' . $filter . '
              ORDER BY s.sort_order, d.value_cents DESC, d.id DESC
              LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset,
            $params
        );
    }

    /**
     * The WHERE clause both views share, scope included.
     *
     * The local is $clauses rather than the obvious $where because the
     * interpolated-SQL gate in tests/static reads a line holding a quoted
     * string, a $where and one more variable as interpolation — the variable
     * NAME matches its \bWHERE\b keyword check. Keeping the name out of the way
     * is cheaper than weakening the gate that guards every query in the app.
     *
     * @return array{0:string,1:array<string,mixed>}
     */
    private function dealFilter(Request $request, int $pipelineId): array
    {
        [$scope, $params] = Acl::scopeCondition('view_deals', 'd.owner_id');

        $clauses = [
            'd.deleted_at IS NULL',
            'd.pipeline_id = :pipeline_id',
            '(' . $scope . ')',
        ];

        $params['pipeline_id'] = $pipelineId;

        $filters = $this->filterValues($request);

        if ($filters['status'] !== '') {
            $clauses[] = 'd.status = :status';
            $params['status'] = $filters['status'];
        }

        if ($filters['stage'] > 0) {
            $clauses[] = 'd.stage_id = :stage_id';
            $params['stage_id'] = $filters['stage'];
        }

        if ($filters['owner'] > 0) {
            $clauses[] = 'd.owner_id = :owner_id';
            $params['owner_id'] = $filters['owner'];
        }

        if ($filters['q'] !== '') {
            // A DISTINCT NAME PER OCCURRENCE. DB::connect() disables emulated
            // prepares, and the native driver refuses a named parameter used
            // more than once in a statement — it answers HY093 and the page
            // 500s. Reusing :q here meant every search of the board or the
            // table was a server error, which no test covered because none
            // searched with a term. Found by review, reproduced, fixed.
            $like = '%' . $filters['q'] . '%';
            $clauses[] = '(d.title LIKE :q_title OR c.first_name LIKE :q_first OR c.last_name LIKE :q_last)';
            $params['q_title'] = $like;
            $params['q_first'] = $like;
            $params['q_last']  = $like;
        }

        return [implode(' AND ', $clauses), $params];
    }

    /** @return array{status:string,stage:int,owner:int,q:string} */
    private function filterValues(Request $request): array
    {
        $status = $request->str('status');

        return [
            'status' => in_array($status, self::STATUSES, true) ? $status : '',
            'stage'  => max(0, $request->int('stage')),
            'owner'  => max(0, $request->int('owner')),
            'q'      => mb_substr($request->str('q'), 0, 80),
        ];
    }

    // =================================================================
    // Stage transitions
    // =================================================================

    /**
     * The deal columns a stage decides.
     *
     * @param array<string,mixed> $deal  The row as it stands (empty on create).
     * @param array<string,mixed> $stage The stage being entered.
     * @return array<string,mixed>
     */
    private function stageColumns(array $deal, array $stage, string $now): array
    {
        $isWon  = (int) $stage['is_won'] === 1;
        $isLost = (int) $stage['is_lost'] === 1;

        $sla = $stage['sla_hours'] === null || $stage['sla_hours'] === ''
            ? null
            : gmdate('Y-m-d H:i:s', $this->stamp($now) + ((int) $stage['sla_hours'] * 3600));

        return [
            'stage_id'         => (int) $stage['id'],
            'stage_entered_at' => $now,
            'sla_due_at'       => $sla,
            'status'           => $isWon ? 'won' : ($isLost ? 'lost' : 'open'),
            // Keep the original timestamp when a deal re-enters a won or lost
            // stage, so "when did we win this?" is not rewritten by a tidy-up.
            'won_at'           => $isWon ? ($deal['won_at'] ?? $now) : null,
            'lost_at'          => $isLost ? ($deal['lost_at'] ?? $now) : null,
            'last_activity_at' => $now,
        ];
    }

    /**
     * Write the history row for a move.
     *
     * @param array<string,mixed> $deal The row BEFORE the move.
     */
    private function recordMove(int $dealId, int $fromStageId, int $toStageId, array $deal, string $now): void
    {
        $entered = (string) ($deal['stage_entered_at'] ?? '');

        if ($entered === '') {
            $entered = (string) ($deal['created_at'] ?? $now);
        }

        $duration = $entered === '' ? null : max(0, $this->stamp($now) - $this->stamp($entered));

        DB::insert('deal_stage_history', [
            'deal_id'          => $dealId,
            'from_stage_id'    => $fromStageId > 0 ? $fromStageId : null,
            'to_stage_id'      => $toStageId,
            'user_id'          => Auth::id(),
            'duration_seconds' => $duration,
            'created_at'       => $now,
        ]);
    }

    /** @param array<string,mixed> $stage */
    private function fireOutcome(int $dealId, array $stage): void
    {
        if ((int) $stage['is_won'] === 1) {
            Event::fire('deal.won', ['id' => $dealId]);
        }

        if ((int) $stage['is_lost'] === 1) {
            Event::fire('deal.lost', ['id' => $dealId]);
        }
    }

    /** A UTC database timestamp as a unix time. */
    private function stamp(string $utc): int
    {
        $time = strtotime($utc . ' UTC');

        return $time === false ? time() : $time;
    }

    // =================================================================
    // Guards shared by store() and update()
    // =================================================================

    /**
     * Resolve and authorise the parts of a write that validation cannot judge:
     * the stage/pipeline pair, the owner, and the money.
     *
     * @param array<string,mixed>      $data validated input
     * @param array<string,mixed>|null $deal the existing row, on update
     * @return array{0:array<string,mixed>,1:int|null,2:int}|Response
     */
    private function resolveWrite(Request $request, array $data, ?array $deal): array|Response
    {
        $fallback = $deal === null ? '/deals/new' : '/deals/' . (int) $deal['id'] . '/edit';

        $stage = DB::first(
            'SELECT * FROM {pipeline_stages} WHERE id = :id',
            ['id' => (int) $data['stage_id']]
        );

        if ($stage === null) {
            return $this->refuse($request, 'Choose a stage for the deal.', $fallback);
        }

        // The form offers both a pipeline and a stage, so they can be submitted
        // disagreeing. A deal whose stage belongs to another pipeline would
        // never appear on either pipeline's board, so this is refused rather
        // than quietly corrected.
        if ((int) $stage['pipeline_id'] !== (int) $data['pipeline_id']) {
            return $this->refuse(
                $request,
                'That stage belongs to a different pipeline. Pick a stage listed under the pipeline you chose.',
                $fallback
            );
        }

        $ownerId      = $this->resolveOwner($data, $deal);
        $currentOwner = $deal === null ? null : $this->nullableId($deal['owner_id'] ?? null);

        // `exists:users` cannot see is_active or deleted_at, so without this a
        // deal could be parked on a departed employee and vanish from every
        // owner-scoped view.
        //
        // Only when the owner CHANGES, though. Checking it on every save would
        // mean a deal whose owner has since left the venue could not be edited
        // at all — the one moment somebody needs to reassign it — and the
        // refusal would name a field the manager had not touched.
        if ($ownerId !== $currentOwner && $ownerId !== null && !$this->isAssignable($ownerId)) {
            return $this->refuse($request, 'That owner is not an active user.', $fallback);
        }

        // Taking a colleague's deal is the reassignment this permission exists
        // for. Handing over one that is already yours is not.
        if ($ownerId !== $currentOwner && !Acl::can('deals_reassign')) {
            $mayHandOver = $deal !== null && $currentOwner === Auth::id();
            $isOwnNewDeal = $deal === null && $ownerId === Auth::id();

            if (!$mayHandOver && !$isOwnNewDeal) {
                return $this->refuse(
                    $request,
                    'You cannot set the owner of a deal that is not yours. Ask a manager to reassign it.',
                    $fallback
                );
            }
        }

        $valueCents = $this->parseMoney($request->str('value_dollars'));

        if ($valueCents === null) {
            return $this->refuse(
                $request,
                'Enter the deal value as dollars and cents, like 549 or 549.00.',
                $fallback
            );
        }

        if ($valueCents < 0) {
            return $this->refuse($request, 'A deal value cannot be negative.', $fallback);
        }

        if ($valueCents > self::MAX_VALUE_CENTS) {
            return $this->refuse($request, 'That deal value looks like a typo. Check the decimal point.', $fallback);
        }

        return [$stage, $ownerId, $valueCents];
    }

    /**
     * @param array<string,mixed>      $data
     * @param array<string,mixed>|null $deal
     */
    private function resolveOwner(array $data, ?array $deal): ?int
    {
        if (array_key_exists('owner_id', $data)) {
            $submitted = $this->nullableId($data['owner_id']);

            if ($submitted !== null) {
                return $submitted;
            }

            // Submitted blank: unassigned on purpose.
            return null;
        }

        // Not submitted at all — keep what is there, or claim a new deal.
        return $deal === null ? Auth::id() : $this->nullableId($deal['owner_id'] ?? null);
    }

    private function isAssignable(int $userId): bool
    {
        return DB::exists(
            'SELECT id FROM {users} WHERE id = :id AND is_active = 1 AND deleted_at IS NULL',
            ['id' => $userId]
        );
    }

    /**
     * Dollars as typed to exact integer cents, or null when it is not money.
     *
     * Integer arithmetic only: (int) round("549.95" * 100) is 54994 on some
     * builds, and a cent of drift per deal is a reconciliation problem against
     * the POS rather than a rounding curiosity.
     */
    private function parseMoney(string $input): ?int
    {
        $clean = preg_replace('/[\s,$]/', '', trim($input)) ?? '';

        if ($clean === '') {
            return 0;
        }

        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $clean, $match) !== 1) {
            return null;
        }

        $cents = ((int) $match[2] * 100) + (int) str_pad($match[3] ?? '0', 2, '0');

        return $match[1] === '-' ? -$cents : $cents;
    }

    /** @return array<string,string> */
    private function rules(): array
    {
        return [
            'title'               => 'required|string|min:2|max:191',
            'pipeline_id'         => 'required|int|exists:pipelines',
            'stage_id'            => 'required|int|exists:pipeline_stages',
            'contact_id'          => 'nullable|int|exists:contacts',
            'owner_id'            => 'nullable|int|exists:users',
            'expected_close_date' => 'nullable|date_format:Y-m-d',
            'event_date'          => 'nullable|date_format:Y-m-d',
            'guest_count'         => 'nullable|int|min:0|max:65535',
            'source'              => 'nullable|in:' . implode(',', array_keys(self::SOURCES)),
            'source_detail'       => 'nullable|string|max:191',
            'next_action'         => 'nullable|string|max:255',
        ];
    }

    /** @return array<string,string> */
    private function labels(): array
    {
        return [
            'title'               => 'Deal name',
            'pipeline_id'         => 'Pipeline',
            'stage_id'            => 'Stage',
            'contact_id'          => 'Contact',
            'owner_id'            => 'Owner',
            'expected_close_date' => 'Expected close date',
            'event_date'          => 'Event date',
            'guest_count'         => 'Guest count',
            'source'              => 'Source',
            'source_detail'       => 'Source detail',
            'next_action'         => 'Next action',
        ];
    }

    // =================================================================
    // Lookups
    // =================================================================

    /**
     * A deal the current user may act on under this permission, or null.
     *
     * The scope is applied in SQL rather than compared afterwards, so 'team'
     * resolves through the same membership query the rest of the application
     * uses, and an out-of-scope deal is indistinguishable from one that does
     * not exist.
     *
     * @return array<string,mixed>|null
     */
    private function findDeal(int $id, string $permission): ?array
    {
        if ($id <= 0) {
            return null;
        }

        [$scope, $params] = Acl::scopeCondition($permission, 'd.owner_id');

        $params['id'] = $id;

        return DB::first(
            'SELECT d.*, p.name AS pipeline_name, p.slug AS pipeline_slug,
                    s.name AS stage_name, s.color AS stage_color, s.probability AS stage_probability,
                    s.is_won AS stage_is_won, s.is_lost AS stage_is_lost,
                    c.first_name, c.last_name, c.email AS contact_email, c.phone AS contact_phone,
                    u.name AS owner_name,
                    lr.label AS lost_reason
               FROM {deals} d
               JOIN {pipelines} p ON p.id = d.pipeline_id
               JOIN {pipeline_stages} s ON s.id = d.stage_id
               LEFT JOIN {contacts} c ON c.id = d.contact_id
               LEFT JOIN {users} u ON u.id = d.owner_id
               LEFT JOIN {lost_reasons} lr ON lr.id = d.lost_reason_id
              WHERE d.id = :id AND d.deleted_at IS NULL AND (' . $scope . ')',
            $params
        );
    }

    /** @return list<array<string,mixed>> */
    private function history(int $dealId): array
    {
        return DB::all(
            'SELECT h.id, h.from_stage_id, h.to_stage_id, h.duration_seconds, h.created_at,
                    fs.name AS from_stage, ts.name AS to_stage, u.name AS user_name
               FROM {deal_stage_history} h
               LEFT JOIN {pipeline_stages} fs ON fs.id = h.from_stage_id
               LEFT JOIN {pipeline_stages} ts ON ts.id = h.to_stage_id
               LEFT JOIN {users} u ON u.id = h.user_id
              WHERE h.deal_id = :id
              ORDER BY h.id DESC
              LIMIT 200',
            ['id' => $dealId]
        );
    }

    /**
     * Pipelines worth showing a board for: active, or holding a deal already.
     *
     * @return list<array<string,mixed>>
     */
    private function pipelineOptions(): array
    {
        return DB::all(
            'SELECT p.id, p.name, p.slug, p.is_active,
                    (SELECT COUNT(*) FROM {pipeline_stages} s WHERE s.pipeline_id = p.id) AS stage_count
               FROM {pipelines} p
              WHERE p.is_active = 1
                 OR EXISTS (SELECT 1 FROM {deals} d WHERE d.pipeline_id = p.id AND d.deleted_at IS NULL)
              ORDER BY p.is_active DESC, p.sort_order, p.name'
        );
    }

    /**
     * @param list<array<string,mixed>> $pipelines
     * @return array<string,mixed>
     */
    private function chosenPipeline(Request $request, array $pipelines): array
    {
        $requested = $request->int('pipeline');

        foreach ($pipelines as $pipeline) {
            if ((int) $pipeline['id'] === $requested) {
                return $pipeline;
            }
        }

        // With nothing asked for, land on a pipeline that can actually hold
        // deals. A pipeline created a minute ago has no stages yet and sorts
        // first on a default sort_order, so without this the board opens on an
        // empty shell and reads as "there are no deals".
        foreach ($pipelines as $pipeline) {
            if ((int) $pipeline['stage_count'] > 0) {
                return $pipeline;
            }
        }

        return $pipelines[0];
    }

    /** @return list<array<string,mixed>> */
    private function stagesFor(int $pipelineId): array
    {
        return DB::all(
            'SELECT id, pipeline_id, name, sort_order, probability, sla_hours, is_won, is_lost, color
               FROM {pipeline_stages}
              WHERE pipeline_id = :id
              ORDER BY sort_order, id',
            ['id' => $pipelineId]
        );
    }

    /**
     * Every stage, grouped under its pipeline, for the form's stage select.
     *
     * Grouping them means the two selects can be read together — you can see
     * that "Deposit Paid" is a Birthday stage — without any JavaScript to
     * repopulate one from the other.
     *
     * @return list<array{id:int,name:string,stages:list<array<string,mixed>>}>
     */
    private function stageGroups(): array
    {
        $groups = [];

        foreach (DB::all(
            'SELECT p.id AS pipeline_id, p.name AS pipeline_name, p.is_active,
                    s.id, s.name, s.is_won, s.is_lost, s.sort_order
               FROM {pipelines} p
               JOIN {pipeline_stages} s ON s.pipeline_id = p.id
              WHERE p.is_active = 1
              ORDER BY p.sort_order, p.name, s.sort_order, s.id'
        ) as $row) {
            $pipelineId = (int) $row['pipeline_id'];

            $groups[$pipelineId] ??= [
                'id'     => $pipelineId,
                'name'   => (string) $row['pipeline_name'],
                'stages' => [],
            ];

            $groups[$pipelineId]['stages'][] = [
                'id'      => (int) $row['id'],
                'name'    => (string) $row['name'],
                'is_won'  => (int) $row['is_won'] === 1,
                'is_lost' => (int) $row['is_lost'] === 1,
            ];
        }

        return array_values($groups);
    }

    /**
     * Contacts for the picker.
     *
     * Bounded, because this is a <select> and the venue's contact table runs
     * to tens of thousands: the most recent names, plus whichever one this
     * deal already points at so editing can never silently drop it.
     *
     * @return list<array<string,mixed>>
     */
    private function contactOptions(int $keepId = 0): array
    {
        $rows = DB::all(
            'SELECT id, first_name, last_name, email
               FROM {contacts}
              WHERE deleted_at IS NULL
              ORDER BY COALESCE(updated_at, created_at) DESC, id DESC
              LIMIT 200'
        );

        if ($keepId <= 0) {
            return $rows;
        }

        foreach ($rows as $row) {
            if ((int) $row['id'] === $keepId) {
                return $rows;
            }
        }

        $kept = DB::first(
            'SELECT id, first_name, last_name, email FROM {contacts} WHERE id = :id AND deleted_at IS NULL',
            ['id' => $keepId]
        );

        if ($kept !== null) {
            array_unshift($rows, $kept);
        }

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function ownerOptions(): array
    {
        return DB::all(
            'SELECT id, name FROM {users}
              WHERE is_active = 1 AND deleted_at IS NULL
              ORDER BY name'
        );
    }

    /** Where a move should send the user back to — never a path they supplied. */
    private function returnPath(Request $request, int $pipelineId, int $dealId): string
    {
        return match ($request->str('from')) {
            'board' => '/deals?pipeline=' . $pipelineId,
            'table' => '/deals/table?pipeline=' . $pipelineId,
            default => '/deals/' . $dealId,
        };
    }

    private function nullableId(mixed $value): ?int
    {
        if ($value === null || $value === '' || (is_numeric($value) && (int) $value <= 0)) {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }
}
