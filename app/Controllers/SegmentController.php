<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\SegmentEngine;
use RuntimeException;
use Throwable;

/**
 * Saved questions about the guest list.
 *
 * The engine is in `Services\SegmentEngine` and is pure; this is the half that
 * stores, counts and shows. Four things decide how it behaves.
 *
 * 1. **A SEGMENT THAT WILL NOT COMPILE IS NEVER SAVED.** The engine throws
 *    rather than dropping a rule it cannot read, and this reports that as a
 *    validation error. The alternative — storing it and finding out at send
 *    time — puts the discovery at the worst possible moment.
 *
 * 2. **THE COUNT IS A MEASUREMENT, WITH A TIMESTAMP.** `cached_count` is
 *    shown WITH when it was taken, never as a bare number: a segment counted
 *    in March and sent to in July is a different list, and a figure with no
 *    date on it invites somebody to trust it. Counting is its own button.
 *
 * 3. **THE PREVIEW IS A SAMPLE, AND SAYS SO.** Twenty names out of however
 *    many, so somebody can sanity-check a segment before using it — "why is
 *    this full of staff addresses" is a question worth being able to ask.
 *
 * 4. **COUNTING IS NOT SENDING.** This module needs `campaigns_manage` to
 *    build a segment and nothing more. Permission to CONTACT the people in
 *    one is `campaigns_send`, and it is checked where the sending happens.
 */
final class SegmentController extends Controller
{
    /** How many names the preview shows. */
    public const PREVIEW_LIMIT = 20;

    /**
     * How long a count may go unrefreshed before the page calls it stale.
     *
     * Six hours, which is long enough that a morning's work does not re-count
     * constantly and short enough that nobody sends against yesterday's
     * figure without being told.
     */
    public const STALE_AFTER_SECONDS = 21600;

    public function index(Request $request): Response
    {
        if ($refusal = $this->authorize('view_marketing')) {
            return $refusal;
        }

        $rows = DB::all(
            'SELECT s.*, u.name AS author
               FROM {segments} s
               LEFT JOIN {users} u ON u.id = s.created_by
              ORDER BY s.name'
        );

        return $this->view('segments/index', [
            'pageTitle' => 'Segments',
            'segments'  => array_map([$this, 'decorate'], $rows),
            'canManage' => can('campaigns_manage'),
        ]);
    }

    public function create(Request $request): Response
    {
        if ($refusal = $this->authorize('campaigns_manage')) {
            return $refusal;
        }

        return $this->form(null);
    }

    public function edit(Request $request): Response
    {
        if ($refusal = $this->authorize('campaigns_manage')) {
            return $refusal;
        }

        $segment = $this->find((int) $request->route('id', '0'));

        if ($segment === null) {
            return $this->notFound('That segment is not here.');
        }

        return $this->form($segment);
    }

    public function show(Request $request): Response
    {
        if ($refusal = $this->authorize('view_marketing')) {
            return $refusal;
        }

        $segment = $this->find((int) $request->route('id', '0'));

        if ($segment === null) {
            return $this->notFound('That segment is not here.');
        }

        $rules = $this->rulesOf($segment);

        // A SAMPLE, AND IT SAYS SO. Somebody checking a segment before using
        // it needs to be able to ask "why is this full of staff addresses",
        // and a count alone cannot answer that.
        $preview = [];
        $error   = '';

        try {
            $compiled = SegmentEngine::compile($rules);

            $preview = DB::all(
                'SELECT c.id, c.first_name, c.last_name, c.email, c.phone,
                        c.lifecycle_stage, c.email_consent, c.sms_consent
                   FROM {contacts} c
                  WHERE ' . $compiled['sql'] . '
                  ORDER BY c.last_name, c.first_name
                  LIMIT ' . self::PREVIEW_LIMIT,
                $compiled['params']
            );
        } catch (RuntimeException $e) {
            // A stored segment can stop compiling — a field renamed in a
            // later version, say. Saying so is the point; silently showing an
            // empty list would read as "nobody matches".
            $error = $e->getMessage();
        } catch (Throwable $e) {
            $error = 'That segment could not be run: ' . $e->getMessage();
        }

        return $this->view('segments/show', [
            'pageTitle'  => (string) $segment['name'],
            'segment'    => $this->decorate($segment),
            'rules'      => $rules,
            'described'  => SegmentEngine::describe($rules),
            'preview'    => $preview,
            'error'      => $error,
            'canManage'  => can('campaigns_manage'),
            'previewLimit' => self::PREVIEW_LIMIT,
        ]);
    }

    public function store(Request $request): Response
    {
        if ($refusal = $this->authorize('campaigns_manage')) {
            return $refusal;
        }

        return $this->save($request, null);
    }

    public function update(Request $request): Response
    {
        if ($refusal = $this->authorize('campaigns_manage')) {
            return $refusal;
        }

        $segment = $this->find((int) $request->route('id', '0'));

        if ($segment === null) {
            return $this->notFound('That segment is not here.');
        }

        return $this->save($request, $segment);
    }

    /**
     * Count the segment, and record when.
     *
     * Its own action and its own button, because a count is a measurement
     * rather than a property: re-counting on every page load would scan the
     * contact table for each of a dozen segments, and caching it silently
     * would leave a stale number looking authoritative.
     */
    public function refresh(Request $request): Response
    {
        if ($refusal = $this->authorize('campaigns_manage')) {
            return $refusal;
        }

        $segment = $this->find((int) $request->route('id', '0'));

        if ($segment === null) {
            return $this->notFound('That segment is not here.');
        }

        $id = (int) $segment['id'];

        try {
            $count = $this->count($this->rulesOf($segment));
        } catch (RuntimeException $e) {
            return $this->refuse($request, $e->getMessage(), '/segments/' . $id);
        }

        DB::update('segments', [
            'cached_count' => $count,
            'cached_at'    => now_utc(),
            'updated_at'   => now_utc(),
        ], 'id = :id', ['id' => $id]);

        return $this->success(
            $request,
            number_format($count) . ' ' . ($count === 1 ? 'guest' : 'guests') . ' match right now.',
            '/segments/' . $id
        );
    }

    public function destroy(Request $request): Response
    {
        if ($refusal = $this->authorize('campaigns_manage')) {
            return $refusal;
        }

        $segment = $this->find((int) $request->route('id', '0'));

        if ($segment === null) {
            return $this->notFound('That segment is not here.');
        }

        $id = (int) $segment['id'];

        // A SEGMENT A CAMPAIGN POINTS AT IS NOT DELETED. The campaign's own
        // record of who it went to would be left pointing at nothing, and
        // that record is how a venue answers "why did I get this".
        $used = (int) DB::value(
            'SELECT COUNT(*) FROM {campaigns} WHERE segment_id = :id',
            ['id' => $id]
        );

        if ($used > 0) {
            return $this->refuse(
                $request,
                'That segment is used by ' . $used . ' ' . ($used === 1 ? 'campaign' : 'campaigns')
                    . '. Delete or re-point those first.',
                '/segments/' . $id
            );
        }

        DB::delete('segments', 'id = :id', ['id' => $id]);

        Audit::event('segment.deleted', ['name' => (string) $segment['name']], 'segments', $id);

        return $this->success($request, 'Segment deleted.', '/segments');
    }

    // =================================================================
    // Saving
    // =================================================================

    /** @param array<string,mixed>|null $segment */
    private function save(Request $request, ?array $segment): Response
    {
        $isNew = $segment === null;
        $back  = $isNew ? '/segments/new' : '/segments/' . (int) $segment['id'] . '/edit';

        $name = trim($request->str('name'));

        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            return $this->refuse($request, 'Give the segment a name.', $back);
        }

        $rules = $this->rulesFromRequest($request);

        if (is_string($rules)) {
            return $this->refuse($request, $rules, $back);
        }

        // THE ENGINE DECIDES WHETHER THIS IS SAVEABLE. Not a lighter check
        // here that might disagree with it — a segment that saved and then
        // would not run is a failure discovered at send time.
        $validation = SegmentEngine::validate($rules);

        if (!$validation['ok']) {
            return $this->refuse($request, $validation['error'], $back);
        }

        $slug = $this->uniqueSlug($name, $isNew ? 0 : (int) $segment['id']);

        $columns = [
            'name'        => $name,
            'slug'        => $slug,
            'description' => mb_substr(trim($request->str('description')), 0, 500) ?: null,
            'rules'       => json_encode($rules, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'is_dynamic'  => 1,
            'updated_at'  => now_utc(),
        ];

        if ($isNew) {
            $columns['created_by'] = Auth::id();
            $columns['created_at'] = now_utc();

            $id = DB::insert('segments', $columns);

            Audit::event('segment.created', ['name' => $name, 'rules' => $validation['rules']], 'segments', $id);
        } else {
            $id = (int) $segment['id'];

            // THE COUNT IS CLEARED, NOT KEPT. The rules just changed, so the
            // old figure is about a different question — and a number beside
            // the new rules would be read as being about them.
            $columns['cached_count'] = null;
            $columns['cached_at']    = null;

            DB::update('segments', $columns, 'id = :id', ['id' => $id]);

            Audit::event('segment.updated', ['name' => $name, 'rules' => $validation['rules']], 'segments', $id);
        }

        return $this->success($request, 'Segment saved.', '/segments/' . $id);
    }

    /**
     * Read a rule tree out of the request.
     *
     * The editor posts flat arrays — `rules[0][field]` and so on — plus a
     * `group` index so a rule can be put in a nested OR group. That is as
     * much nesting as a form can express without JavaScript, and this
     * application has no build step: one level of grouping covers "all of
     * these, and any of those", which is the shape nearly every real segment
     * takes.
     *
     * Returns a string when the POST itself is unusable, as distinct from a
     * rule the engine refuses — the caller reports either the same way.
     *
     * @return array<string,mixed>|string
     */
    private function rulesFromRequest(Request $request): array|string
    {
        $posted = $request->array('rules');

        if ($posted === []) {
            // NOT an empty tree, which the engine reads as "nobody". A form
            // submitted with no rules is almost always somebody who has not
            // finished, and saving a segment that matches nobody would look
            // like the editor losing their work.
            return 'Add at least one condition.';
        }

        $top    = [];
        $groups = [];

        foreach ($posted as $row) {
            if (!is_array($row)) {
                continue;
            }

            $field = trim((string) ($row['field'] ?? ''));
            $op    = trim((string) ($row['op'] ?? ''));

            if ($field === '' || $op === '') {
                // A blank row is the editor's empty slot, not a mistake.
                continue;
            }

            $rule = ['field' => $field, 'op' => $op];

            // A paired operator takes two inputs; everything else takes one.
            if ($op === 'between') {
                $rule['value'] = [
                    (string) ($row['value'] ?? ''),
                    (string) ($row['value2'] ?? ''),
                ];
            } elseif (isset($row['value'])) {
                $rule['value'] = is_scalar($row['value']) ? (string) $row['value'] : '';
            }

            $group = trim((string) ($row['group'] ?? ''));

            if ($group === '' || $group === '0') {
                $top[] = $rule;
            } else {
                $groups[$group][] = $rule;
            }
        }

        foreach ($groups as $rules) {
            $top[] = ['match' => 'any', 'rules' => $rules];
        }

        if ($top === []) {
            return 'Add at least one condition.';
        }

        return [
            'match' => $request->str('match') === 'any' ? 'any' : 'all',
            'rules' => $top,
        ];
    }

    // =================================================================
    // Reading
    // =================================================================

    /** @return array<string,mixed>|null */
    private function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        return DB::first('SELECT * FROM {segments} WHERE id = :id', ['id' => $id]);
    }

    /**
     * The stored rules as an array.
     *
     * A segment whose JSON is unreadable returns an EMPTY tree, which the
     * engine compiles to "nobody" — the safe direction. The page shows the
     * count as unknown rather than as zero.
     *
     * @param array<string,mixed> $segment
     * @return array<string,mixed>
     */
    private function rulesOf(array $segment): array
    {
        $decoded = json_decode((string) ($segment['rules'] ?? ''), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $rules */
    private function count(array $rules): int
    {
        $compiled = SegmentEngine::compile($rules);

        return (int) DB::value(
            'SELECT COUNT(*) FROM {contacts} c WHERE ' . $compiled['sql'],
            $compiled['params']
        );
    }

    /**
     * Add what a row needs that is not a column.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decorate(array $row): array
    {
        $rules = $this->rulesOf($row);

        $row['described'] = SegmentEngine::describe($rules);

        $validation        = SegmentEngine::validate($rules);
        $row['compiles']   = $validation['ok'];
        $row['rule_error'] = $validation['error'];
        $row['rule_count'] = $validation['rules'];

        // THE AGE OF THE COUNT, so the number is never shown bare. A segment
        // counted in March and sent to in July is a different list.
        $countedAt = $row['cached_at'] ?? null;

        $row['counted_at'] = $countedAt;
        $row['count_stale'] = $countedAt === null
            || (time() - (int) strtotime((string) $countedAt . ' UTC')) > self::STALE_AFTER_SECONDS;

        return $row;
    }

    /** @param array<string,mixed>|null $segment */
    private function form(?array $segment): Response
    {
        $rules = $segment === null ? [] : $this->rulesOf($segment);

        return $this->view('segments/form', [
            'pageTitle' => $segment === null ? 'New segment' : 'Edit ' . (string) $segment['name'],
            'segment'   => $segment,
            'rules'     => $rules,
            'flat'      => $this->flatten($rules),
            'match'     => strtolower((string) ($rules['match'] ?? 'all')) === 'any' ? 'any' : 'all',
            'fields'    => SegmentEngine::fieldsByGroup(),
            'catalogue' => SegmentEngine::FIELDS,
            'described' => SegmentEngine::describe($rules),
        ]);
    }

    /**
     * A rule tree flattened back into the editor's rows.
     *
     * The inverse of rulesFromRequest(). Only one level of grouping survives,
     * which is all the form can express — a tree built through the API with
     * deeper nesting is still STORED and still RUNS, and the editor says it
     * cannot show it rather than quietly flattening and re-saving it as
     * something different.
     *
     * @param array<string,mixed> $rules
     * @return array{rows:list<array<string,mixed>>,too_deep:bool}
     */
    private function flatten(array $rules): array
    {
        $rows    = [];
        $tooDeep = false;
        $group   = 0;

        foreach ($rules['rules'] ?? [] as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            if (!isset($rule['rules']) && !isset($rule['match'])) {
                $rows[] = $rule + ['group' => ''];

                continue;
            }

            $group++;

            foreach ($rule['rules'] ?? [] as $inner) {
                if (!is_array($inner)) {
                    continue;
                }

                if (isset($inner['rules']) || isset($inner['match'])) {
                    // A group inside a group. Storable, runnable, not
                    // editable here.
                    $tooDeep = true;

                    continue;
                }

                $rows[] = $inner + ['group' => (string) $group];
            }
        }

        return ['rows' => $rows, 'too_deep' => $tooDeep];
    }

    private function uniqueSlug(string $name, int $exceptId): string
    {
        $base = slugify($name) ?: 'segment';
        $slug = mb_substr($base, 0, 56);
        $n    = 1;

        while (DB::exists(
            'SELECT id FROM {segments} WHERE slug = :s AND id <> :id',
            ['s' => $slug, 'id' => $exceptId]
        )) {
            $n++;
            $slug = mb_substr($base, 0, 56) . '-' . $n;
        }

        return $slug;
    }
}
