<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Acl;
use App\Core\Audit;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;

/**
 * Pipelines and their stages.
 *
 * A pipeline is a named list of stages, and the stages are what every deal
 * hangs off: the board's columns, the response deadline a deal inherits when
 * it arrives, and whether reaching that column counts as won or lost. The
 * installer seeds four (Birthday, Groups & Corporate, Event Center,
 * Fundraising); this is how an operator adds a fifth, renames a column, or
 * reorders one for how they actually sell.
 *
 * Two things are deliberately NOT here:
 *
 *   - There is no delete. {deals}.pipeline_id cascades, so deleting a pipeline
 *     would silently take every deal and its stage history with it. Switching
 *     a pipeline to inactive hides it from the board and from the new-deal
 *     form while leaving the record intact, which is what "we don't run that
 *     any more" actually means.
 *
 *   - A stage in use cannot be removed. The FK from {deals}.stage_id is
 *     RESTRICT, so the database would refuse anyway; refusing here means the
 *     operator gets a sentence naming the count instead of a 500.
 */
final class PipelineController extends Controller
{
    /** The pipelines.type enum. */
    private const TYPES = [
        'birthday'     => 'Birthday parties',
        'group'        => 'Groups & corporate',
        'corporate'    => 'Corporate',
        'event_center' => 'Event center',
        'membership'   => 'Memberships',
        'fundraising'  => 'Fundraising',
        'custom'       => 'Something else',
    ];

    /** How a new deal picks an owner. Only 'manual' is honoured in Phase 2. */
    private const STRATEGIES = [
        'manual'        => 'Whoever creates the deal',
        'round_robin'   => 'Round robin (Phase 3)',
        'load_balanced' => 'Load balanced (Phase 3)',
        'by_source'     => 'By source (Phase 3)',
    ];

    /** A board with more columns than this is unreadable, not expressive. */
    private const MAX_STAGES = 30;

    private const DEFAULT_COLOR = '#6b4ea8';

    public function index(Request $request): Response
    {
        // Deal counts are scoped the same way the board is, so an owner-scoped
        // role is not told about 40 deals it cannot open.
        //
        // The scope goes into a derived table rather than into two correlated
        // subqueries, because its bindings would then appear twice: PDO with
        // emulated prepares OFF — which DB::connect() insists on — cannot bind
        // one named placeholder to two markers.
        [$scope, $params] = Acl::scopeCondition('view_deals', 'd.owner_id');

        $pipelines = DB::all(
            'SELECT p.id, p.name, p.slug, p.type, p.is_active, p.sort_order,
                    (SELECT COUNT(*) FROM {pipeline_stages} s WHERE s.pipeline_id = p.id) AS stage_count,
                    COALESCE(agg.deal_count, 0) AS deal_count,
                    COALESCE(agg.open_value_cents, 0) AS open_value_cents
               FROM {pipelines} p
               LEFT JOIN (
                    SELECT d.pipeline_id,
                           COUNT(*) AS deal_count,
                           COALESCE(SUM(CASE WHEN d.status = \'open\' THEN d.value_cents ELSE 0 END), 0)
                               AS open_value_cents
                      FROM {deals} d
                     WHERE d.deleted_at IS NULL AND (' . $scope . ')
                     GROUP BY d.pipeline_id
               ) agg ON agg.pipeline_id = p.id
              ORDER BY p.is_active DESC, p.sort_order, p.name',
            $params
        );

        return $this->view('pipelines/index', [
            'pageTitle' => 'Pipelines & stages',
            'pipelines' => $pipelines,
            'types'     => self::TYPES,
        ]);
    }

    public function create(Request $request): Response
    {
        if ($denied = $this->authorize('deals_manage')) {
            return $denied;
        }

        return $this->view('pipelines/form', [
            'pageTitle'  => 'New pipeline',
            'pipeline'   => null,
            'stages'     => [],
            'stageUsage' => [],
            'types'      => self::TYPES,
            'strategies' => self::STRATEGIES,
        ]);
    }

    public function store(Request $request): Response
    {
        if ($denied = $this->authorize('deals_manage')) {
            return $denied;
        }

        $data = $this->validate($request, [
            'name'                   => 'required|string|min:2|max:80',
            'slug'                   => 'nullable|slug|max:64',
            'type'                   => 'required|in:' . implode(',', array_keys(self::TYPES)),
            'default_owner_strategy' => 'nullable|in:' . implode(',', array_keys(self::STRATEGIES)),
            'sort_order'             => 'nullable|int|min:0|max:32767',
        ], ['default_owner_strategy' => 'Owner assignment']);

        if ($data instanceof Response) {
            return $data;
        }

        $slug = $this->uniqueSlug((string) ($data['slug'] ?? '') ?: slugify((string) $data['name']));

        if ($slug === null) {
            return $this->refuse(
                $request,
                'That name is too close to an existing pipeline. Give it a distinct name or set the slug yourself.',
                '/pipelines/new'
            );
        }

        $now = now_utc();

        $row = [
            'name'                   => $data['name'],
            'slug'                   => $slug,
            'type'                   => $data['type'],
            // The form always submits this (it carries a hidden 0 behind the
            // checkbox), so the default only applies to a POST that omits the
            // field entirely — where "a pipeline somebody just created is
            // live" is the useful reading.
            'is_active'              => $request->bool('is_active', true) ? 1 : 0,
            'sort_order'             => (int) ($data['sort_order'] ?? 0),
            'default_owner_strategy' => $data['default_owner_strategy'] ?? 'manual',
            'created_at'             => $now,
            'updated_at'             => $now,
        ];

        $id = DB::transaction(static function () use ($row): int {
            $id = DB::insert('pipelines', $row);

            Audit::record('pipeline.created', 'pipelines', $id, null, $row);

            return $id;
        });

        // Straight to the stage editor: a pipeline with no stages cannot hold
        // a deal, so leaving the operator on a list that looks finished would
        // be the wrong place to stop.
        return $this->success(
            $request,
            $data['name'] . ' created. Now give it some stages.',
            '/pipelines/' . $id
        );
    }

    public function edit(Request $request): Response
    {
        if ($denied = $this->authorize('deals_manage')) {
            return $denied;
        }

        $pipeline = DB::first('SELECT * FROM {pipelines} WHERE id = :id', ['id' => $request->routeInt('id')]);

        if ($pipeline === null) {
            return $this->notFound('That pipeline does not exist.');
        }

        return $this->view('pipelines/form', [
            'pageTitle'  => 'Edit ' . $pipeline['name'],
            'pipeline'   => $pipeline,
            'stages'     => $this->stages((int) $pipeline['id']),
            'stageUsage' => $this->stageUsage((int) $pipeline['id']),
            'types'      => self::TYPES,
            'strategies' => self::STRATEGIES,
        ]);
    }

    public function update(Request $request): Response
    {
        if ($denied = $this->authorize('deals_manage')) {
            return $denied;
        }

        $id       = $request->routeInt('id');
        $pipeline = DB::first('SELECT * FROM {pipelines} WHERE id = :id', ['id' => $id]);

        if ($pipeline === null) {
            return $this->notFound('That pipeline does not exist.');
        }

        $data = $this->validate($request, [
            'name'                   => 'required|string|min:2|max:80',
            'type'                   => 'required|in:' . implode(',', array_keys(self::TYPES)),
            'default_owner_strategy' => 'nullable|in:' . implode(',', array_keys(self::STRATEGIES)),
            'sort_order'             => 'nullable|int|min:0|max:32767',
        ], ['default_owner_strategy' => 'Owner assignment']);

        if ($data instanceof Response) {
            return $data;
        }

        $update = [
            'name'                   => $data['name'],
            'type'                   => $data['type'],
            'is_active'              => $request->bool('is_active', false) ? 1 : 0,
            'sort_order'             => (int) ($data['sort_order'] ?? 0),
            'default_owner_strategy' => $data['default_owner_strategy'] ?? 'manual',
            'updated_at'             => now_utc(),
        ];

        // The slug is left alone on purpose: the seeds and the playbook asset
        // links resolve pipelines by slug, so renaming one would quietly break
        // the link between a playbook and the pipeline it ships with.
        DB::transaction(static function () use ($id, $update, $pipeline): void {
            DB::update('pipelines', $update, 'id = :id', ['id' => $id]);

            Audit::record('pipeline.updated', 'pipelines', $id, $pipeline, $update);
        });

        return $this->success($request, 'Pipeline updated.', '/pipelines/' . $id);
    }

    /**
     * Save every stage of a pipeline in one submission.
     *
     * Validated completely before anything is written: a board half-reordered
     * because row 6 had a bad colour is worse than a refusal naming row 6.
     */
    public function saveStages(Request $request): Response
    {
        if ($denied = $this->authorize('deals_manage')) {
            return $denied;
        }

        $id       = $request->routeInt('id');
        $pipeline = DB::first('SELECT * FROM {pipelines} WHERE id = :id', ['id' => $id]);

        if ($pipeline === null) {
            return $this->notFound('That pipeline does not exist.');
        }

        $back     = '/pipelines/' . $id;
        $existing = $this->stages($id);
        $usage    = $this->stageUsage($id);

        $submitted = $request->input('stages');
        $submitted = is_array($submitted) ? $submitted : [];

        $updates = [];
        $removals = [];

        foreach ($existing as $position => $stage) {
            $stageId = (int) $stage['id'];
            $fields  = $submitted[(string) $stageId] ?? $submitted[$stageId] ?? null;

            if (!is_array($fields)) {
                // Not in the submission at all: leave it exactly as it is.
                continue;
            }

            if (!empty($fields['remove'])) {
                $inUse = (int) ($usage[$stageId] ?? 0);

                if ($inUse > 0) {
                    return $this->refuse(
                        $request,
                        '"' . $stage['name'] . '" still holds ' . $inUse . ' deal'
                        . ($inUse === 1 ? '' : 's') . '. Move them to another stage first.',
                        $back
                    );
                }

                $removals[] = $stageId;
                continue;
            }

            $parsed = $this->parseStage($fields, 'Stage ' . ($position + 1));

            if (is_string($parsed)) {
                return $this->refuse($request, $parsed, $back);
            }

            $updates[$stageId] = $parsed;
        }

        $additions = [];

        foreach ($this->newStageRows($request) as $index => $fields) {
            $parsed = $this->parseStage($fields, 'New stage ' . ($index + 1));

            if (is_string($parsed)) {
                return $this->refuse($request, $parsed, $back);
            }

            $additions[] = $parsed;
        }

        $finalCount = count($existing) - count($removals) + count($additions);

        if ($finalCount < 1) {
            return $this->refuse($request, 'A pipeline needs at least one stage.', $back);
        }

        if ($finalCount > self::MAX_STAGES) {
            return $this->refuse(
                $request,
                'A pipeline can have at most ' . self::MAX_STAGES . ' stages. That would make ' . $finalCount . '.',
                $back
            );
        }

        $now = now_utc();

        DB::transaction(static function () use ($id, $updates, $removals, $additions, $now): void {
            foreach ($updates as $stageId => $fields) {
                DB::update(
                    'pipeline_stages',
                    array_merge($fields, ['updated_at' => $now]),
                    'id = :id AND pipeline_id = :pipeline_id',
                    ['id' => $stageId, 'pipeline_id' => $id]
                );
            }

            foreach ($removals as $stageId) {
                DB::delete(
                    'pipeline_stages',
                    'id = :id AND pipeline_id = :pipeline_id',
                    ['id' => $stageId, 'pipeline_id' => $id]
                );
            }

            foreach ($additions as $fields) {
                DB::insert('pipeline_stages', array_merge($fields, [
                    'pipeline_id' => $id,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]));
            }

            Audit::record('pipeline.stages_saved', 'pipelines', $id, null, [
                'updated' => count($updates),
                'removed' => count($removals),
                'added'   => count($additions),
            ]);
        });

        return $this->success($request, 'Stages saved.', $back);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * One submitted stage row, or a sentence explaining why it was refused.
     *
     * @param array<string,mixed> $fields
     * @return array<string,mixed>|string
     */
    private function parseStage(array $fields, string $label): array|string
    {
        $name = trim((string) ($fields['name'] ?? ''));

        if ($name === '') {
            return $label . ' needs a name.';
        }

        if (mb_strlen($name) > 80) {
            return $label . ': the name must be 80 characters or fewer.';
        }

        $isWon  = !empty($fields['is_won']);
        $isLost = !empty($fields['is_lost']);

        if ($isWon && $isLost) {
            return $label . ' cannot count as both won and lost.';
        }

        $probabilityRaw = trim((string) ($fields['probability'] ?? ''));

        // Checked as typed, not cast: (int) "ninety" is 0, which would store a
        // silent wrong answer in the forecast weighting.
        if ($probabilityRaw !== ''
            && (preg_match('/^\d{1,3}$/', $probabilityRaw) !== 1 || (int) $probabilityRaw > 100)
        ) {
            return $label . ': the probability must be a whole number between 0 and 100.';
        }

        $probability = $probabilityRaw === '' ? 0 : (int) $probabilityRaw;

        $slaRaw = trim((string) ($fields['sla_hours'] ?? ''));

        if ($slaRaw !== '' && (preg_match('/^\d{1,5}$/', $slaRaw) !== 1 || (int) $slaRaw < 1 || (int) $slaRaw > 8760)) {
            return $label . ': the response deadline must be a whole number of hours, from 1 to 8760.';
        }

        $sortRaw = trim((string) ($fields['sort_order'] ?? ''));

        if ($sortRaw !== '' && (preg_match('/^\d{1,5}$/', $sortRaw) !== 1 || (int) $sortRaw > 32767)) {
            return $label . ': the order must be a whole number from 0 to 32767.';
        }

        $color = strtolower(trim((string) ($fields['color'] ?? '')));

        if ($color !== '' && preg_match('/^#[0-9a-f]{6}$/', $color) !== 1) {
            return $label . ': the colour must be a hex value like ' . self::DEFAULT_COLOR . '.';
        }

        return [
            'name'        => $name,
            'sort_order'  => $sortRaw === '' ? 0 : (int) $sortRaw,
            'probability' => $probability,
            'sla_hours'   => $slaRaw === '' ? null : (int) $slaRaw,
            'is_won'      => $isWon ? 1 : 0,
            'is_lost'     => $isLost ? 1 : 0,
            'color'       => $color === '' ? self::DEFAULT_COLOR : $color,
        ];
    }

    /**
     * The blank rows at the foot of the editor that were actually filled in.
     *
     * @return list<array<string,mixed>>
     */
    private function newStageRows(Request $request): array
    {
        $rows = $request->input('new_stages');
        $rows = is_array($rows) ? $rows : [];

        $filled = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            // An untouched blank row is not an error — it is the three spares
            // the form always offers.
            if (trim((string) ($row['name'] ?? '')) === '') {
                continue;
            }

            $filled[] = $row;
        }

        return $filled;
    }

    private function uniqueSlug(string $base): ?string
    {
        $base = slugify($base);

        if ($base === '') {
            $base = 'pipeline';
        }

        $base = mb_substr($base, 0, 56);

        for ($suffix = 0; $suffix <= 20; $suffix++) {
            $candidate = $suffix === 0 ? $base : $base . '-' . $suffix;

            if (!DB::exists('SELECT id FROM {pipelines} WHERE slug = :slug', ['slug' => $candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return list<array<string,mixed>> */
    private function stages(int $pipelineId): array
    {
        return DB::all(
            'SELECT * FROM {pipeline_stages} WHERE pipeline_id = :id ORDER BY sort_order, id',
            ['id' => $pipelineId]
        );
    }

    /**
     * How many live deals sit in each stage — the figure that decides whether
     * a stage may be removed.
     *
     * Deliberately NOT scoped: this is a structural question about the
     * pipeline, and a stage holding somebody else's deals must not look empty
     * to an owner-scoped manager about to delete it.
     *
     * @return array<int,int>
     */
    private function stageUsage(int $pipelineId): array
    {
        $usage = [];

        foreach (DB::all(
            'SELECT d.stage_id, COUNT(*) AS deal_count
               FROM {deals} d
               JOIN {pipeline_stages} s ON s.id = d.stage_id
              WHERE s.pipeline_id = :id AND d.deleted_at IS NULL
              GROUP BY d.stage_id',
            ['id' => $pipelineId]
        ) as $row) {
            $usage[(int) $row['stage_id']] = (int) $row['deal_count'];
        }

        return $usage;
    }
}
