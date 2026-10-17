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
use App\Core\Validator;
use App\Services\ApiResource;
use App\Services\DealStage;
use App\Services\VisitRecorder;
use RuntimeException;
use Throwable;

/**
 * The REST API, `/api/v1/*`.
 *
 * One controller for every resource, driven by `ApiResource::RESOURCES` —
 * because twelve near-identical controllers is twelve places to forget the
 * scope condition, and the one that forgets it is a data leak nobody reviews
 * twice.
 *
 * Four rules, and each exists because the obvious API gets it wrong:
 *
 * **IT IS THE SAME PERMISSION SYSTEM.** Every read goes through
 * `Acl::scopeCondition()` and every write through `Acl::can()`, so a
 * front-desk token sees exactly what a front-desk person sees. An API that
 * does its own authorization is a second implementation of the rules, and
 * the second one is always the one that is wrong.
 *
 * **MONEY FOLLOWS `view_revenue`.** Same as every page: the fields stay and
 * their values are null, so an integration's parser does not break when the
 * token's role changes.
 *
 * **IT NEVER BULK-EXPORTS WHAT A PAGE WOULD NOT SHOW.** Message bodies, form
 * answers and payment references are absent from the listings on purpose —
 * see the notes in `ApiResource`.
 *
 * **ITS CURSOR IS OPAQUE AND ITS PAGE IS BOUNDED.** `?per_page=100000` is a
 * page of 100. Cursors rather than offsets, because a venue's contact list
 * changes while you page through it and an offset silently skips rows.
 */
final class ApiController extends Controller
{
    /** Writes are audited under this source so they are separable from the UI. */
    private const AUDIT_SOURCE = 'api';

    // =================================================================
    // Discovery
    // =================================================================

    /**
     * What this install exposes, and what THIS TOKEN may do with it.
     *
     * The abilities are resolved against the caller's own role, so the list
     * is what they can really do rather than what they were granted on
     * paper — a token with `deals_manage` held by a user whose role lost it
     * reads correctly here as read-only.
     */
    public function index(Request $request): Response
    {
        $resources = [];

        foreach (ApiResource::RESOURCES as $name => $definition) {
            $canRead = Acl::can((string) $definition['read']);

            if (!$canRead) {
                continue;
            }

            $resources[$name] = [
                'path'     => '/api/v1/' . $name,
                'readable' => true,
                'writable' => $definition['write'] !== null
                    && $definition['writable'] !== []
                    && Acl::can((string) $definition['write']),
                // Reported separately because it IS separate: deleting is its
                // own permission, and a token that may edit a contact
                // commonly may not delete one. An integration can see that
                // here rather than discovering it from a 403.
                'deletable' => $definition['delete'] !== null
                    && $definition['soft_delete']
                    && Acl::can((string) $definition['delete']),
                'fields'   => $definition['fields'],
            ];
        }

        return $this->json([
            'version'   => 'v1',
            /*
             * AN OBJECT EVEN WHEN IT IS EMPTY. A PHP array with no keys
             * encodes as `[]`, so a token that can read nothing — which is
             * what a narrowly scoped token legitimately looks like — would
             * hand a client a list where every other response hands it a map,
             * and a typed client parses that as a failure rather than as "no
             * resources". Non-empty, the cast changes nothing.
             */
            'resources' => (object) $resources,
            'you'       => [
                'user_id'  => Auth::id(),
                'name'     => (string) (Auth::user()['name'] ?? ''),
                'role'     => Auth::roleSlug(),
                'revenue'  => Acl::can('view_revenue'),
            ],
        ]);
    }

    // =================================================================
    // Reading
    // =================================================================

    public function list(Request $request): Response
    {
        $name       = (string) $request->route('resource', '');
        $definition = ApiResource::get($name);

        if ($definition === null) {
            return $this->notFoundJson('There is no resource called "' . $name . '".');
        }

        [$scopeWhere, $scopeParams] = Acl::scopeCondition(
            (string) $definition['read'],
            $definition['owner'] === null
                ? ''
                : $this->alias($definition) . '.' . $definition['owner']
        );

        // An owner-less resource cannot be scoped to an owner, so a role
        // holding it at 'own' sees nothing rather than everything. That is
        // the safe direction and it is stated rather than assumed.
        if ($definition['owner'] === null && Acl::scope((string) $definition['read']) !== 'all') {
            if (Acl::scope((string) $definition['read']) === null) {
                return $this->forbidden($definition['read']);
            }

            $scopeWhere  = '1 = 0';
            $scopeParams = [];
        }

        if (!Acl::can((string) $definition['read'])) {
            return $this->forbidden($definition['read']);
        }

        $alias   = $this->alias($definition);
        $clauses = [$scopeWhere];
        $params  = $scopeParams;

        if ($definition['soft_delete']) {
            $clauses[] = $alias . '.deleted_at IS NULL';
        }

        // The cursor. Descending by id, so "newest first" is the default and
        // the cursor means "older than this".
        $after = ApiResource::decodeCursor($request->str('cursor', '') ?: null);

        if ($after !== null) {
            $clauses[]        = $alias . '.id < :cursor_id';
            $params['cursor_id'] = $after;
        }

        // Search, over the fields the definition allows and no others.
        $term = trim($request->str('q', ''));

        if ($term !== '' && $definition['search'] !== []) {
            $ors = [];

            foreach ($definition['search'] as $i => $field) {
                $ors[]                     = $alias . '.' . $field . ' LIKE :q' . $i;
                $params['q' . $i]          = '%' . $term . '%';
            }

            $clauses[] = '(' . implode(' OR ', $ors) . ')';
        }

        // `updated_since`, which is what an integration syncing incrementally
        // actually needs — without it every poll is a full table read.
        $since = trim($request->str('updated_since', ''));

        if ($since !== '') {
            $stamp = strtotime($since);

            if ($stamp === false) {
                return Response::json(
                    ['error' => 'updated_since must be a date or timestamp, like 2026-10-16 or 2026-10-16T14:03:11Z.'],
                    422
                );
            }

            $column = DB::columnExists((string) $definition['table'], 'updated_at')
                ? 'updated_at'
                : 'created_at';

            $clauses[]      = $alias . '.' . $column . ' >= :since';
            $params['since'] = gmdate('Y-m-d H:i:s', $stamp);
        }

        $perPage = ApiResource::perPage($request->str('per_page', ''));

        // One more than asked for, so "is there another page" is answered
        // without a second COUNT over a table that may be large.
        $rows = DB::all(
            'SELECT ' . $alias . '.* FROM {' . $definition['table'] . '} ' . $alias
                . ' WHERE ' . implode(' AND ', $clauses)
                . ' ORDER BY ' . $alias . '.id DESC'
                . ' LIMIT ' . ($perPage + 1),
            $params
        );

        $hasMore = count($rows) > $perPage;
        $rows    = array_slice($rows, 0, $perPage);

        $withMoney = Acl::can('view_revenue');
        $data      = [];

        foreach ($rows as $row) {
            $data[] = ApiResource::present($row, $definition, $withMoney);
        }

        $lastId = $rows === [] ? null : (int) $rows[count($rows) - 1]['id'];

        return $this->json([
            'data' => $data,
            'meta' => [
                'has_more'    => $hasMore,
                'next_cursor' => $hasMore && $lastId !== null
                    ? ApiResource::encodeCursor($lastId)
                    : null,
                'per_page'    => $perPage,
                // Said out loud, so an integration knows the money columns
                // are empty by policy rather than because the records have
                // no money on them.
                'money_hidden' => !$withMoney,
            ],
        ]);
    }

    public function show(Request $request): Response
    {
        $name       = (string) $request->route('resource', '');
        $definition = ApiResource::get($name);

        if ($definition === null) {
            return $this->notFoundJson('There is no resource called "' . $name . '".');
        }

        if (!Acl::can((string) $definition['read'])) {
            return $this->forbidden($definition['read']);
        }

        $row = $this->findScoped($definition, $request->routeInt('id'));

        if ($row === null) {
            return $this->notFoundJson('No ' . rtrim($name, 's') . ' with that id.');
        }

        $body = ApiResource::present($row, $definition, Acl::can('view_revenue'));

        /*
         * The ONE place a message body is returned: a single, deliberate read
         * of a single message. The listing leaves it out so that paging
         * cannot become a bulk export of a venue's correspondence.
         */
        if ($name === 'messages' && array_key_exists('body_text', $row)) {
            $body['body'] = (string) ($row['body_text'] ?? '') !== ''
                ? (string) $row['body_text']
                : strip_tags((string) ($row['body'] ?? ''));
        }

        return $this->json(['data' => $body]);
    }

    // =================================================================
    // Writing
    // =================================================================

    public function store(Request $request): Response
    {
        $name       = (string) $request->route('resource', '');
        $definition = ApiResource::get($name);

        if ($definition === null) {
            return $this->notFoundJson('There is no resource called "' . $name . '".');
        }

        // A visit is not an insert — see below.
        if ($name === 'visits') {
            return $this->storeVisit($request);
        }

        if ($definition['write'] === null || $definition['writable'] === []) {
            return Response::json([
                'error' => ucfirst($name) . ' are read-only through the API. '
                    . 'Changing one goes through its own endpoint so its rules still apply.',
            ], 405);
        }

        if (!Acl::can((string) $definition['write'])) {
            return $this->forbidden($definition['write']);
        }

        $notAnObject = $this->bodyNotAnObject($request);

        if ($notAnObject !== null) {
            return $notAnObject;
        }

        $input = $request->json();

        ['data' => $data, 'refused' => $refused, 'invalid' => $invalid] = ApiResource::writable($input, $definition);

        if ($refused !== []) {
            return $this->refusedFields($refused, $definition);
        }

        // A field that names another record but is not an id. Reported by
        // name and reason rather than cast — see ApiResource::writable().
        if ($invalid !== []) {
            return $this->invalidFields($invalid);
        }

        if ($data === []) {
            return Response::json(['error' => 'Nothing to create — send at least one field.'], 422);
        }

        try {
            $id = $this->insert($name, $definition, $data);
        } catch (RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return $this->writeFailed($e, 'create', $name);
        }

        Audit::event('api_created', ['resource' => $name, 'id' => $id], $name, $id);

        $row = DB::first(
            'SELECT * FROM {' . $definition['table'] . '} WHERE id = :id',
            ['id' => $id]
        );

        return $this->json(
            ['data' => ApiResource::present($row ?? [], $definition, Acl::can('view_revenue'))],
            201
        );
    }

    public function update(Request $request): Response
    {
        $name       = (string) $request->route('resource', '');
        $definition = ApiResource::get($name);

        if ($definition === null) {
            return $this->notFoundJson('There is no resource called "' . $name . '".');
        }

        if ($definition['write'] === null || $definition['writable'] === []) {
            return Response::json([
                'error' => ucfirst($name) . ' are read-only through the API.',
            ], 405);
        }

        if (!Acl::can((string) $definition['write'])) {
            return $this->forbidden($definition['write']);
        }

        /*
         * Found THROUGH THE WRITE PERMISSION'S OWN SCOPE, and a 404 for a
         * record outside it rather than a 403 — the API must not confirm that
         * a record the caller may not touch exists.
         *
         * The write permission, not the read one: "see everyone, edit your
         * own" is a scope pairing an operator can build in Settings, and
         * loading the row under `view_*` would honour the first half and
         * ignore the second.
         */
        $existing = $this->findScoped(
            $definition,
            $request->routeInt('id'),
            (string) $definition['write']
        );

        if ($existing === null) {
            return $this->notFoundJson('No ' . rtrim($name, 's') . ' with that id.');
        }

        $notAnObject = $this->bodyNotAnObject($request);

        if ($notAnObject !== null) {
            return $notAnObject;
        }

        $input = $request->json();

        ['data' => $data, 'refused' => $refused, 'invalid' => $invalid] = ApiResource::writable($input, $definition);

        if ($refused !== []) {
            return $this->refusedFields($refused, $definition);
        }

        // A field that names another record but is not an id. Reported by
        // name and reason rather than cast — see ApiResource::writable().
        if ($invalid !== []) {
            return $this->invalidFields($invalid);
        }

        if ($data === []) {
            return Response::json(['error' => 'Nothing to change — send at least one field.'], 422);
        }

        if (DB::columnExists((string) $definition['table'], 'updated_at')) {
            $data['updated_at'] = gmdate('Y-m-d H:i:s');
        }

        /*
         * MOVING A DEAL TO ANOTHER BOARD IS A MOVE, NOT A FIELD.
         *
         * `pipeline_id` is writable and `stage_id` deliberately is not, and
         * those two facts together left a hole this diff opened: a PATCH
         * naming only `pipeline_id` put the deal on a new board while its
         * `stage_id` still pointed at a stage of the OLD one. MEASURED: a deal
         * came back `pipeline_id: 2, stage_id: 1` with stage 1 belonging to
         * pipeline 1 — a deal on a board it has no stage on, which no page
         * renders sensibly and which every report keyed to a stage
         * mis-attributes.
         *
         * The web's edit form cannot produce that, because there the pipeline
         * follows the chosen STAGE (`DealController::update()` sets
         * `pipeline_id` from `$stage['pipeline_id']`). Here the caller names
         * the board, so the stage follows it: the deal enters the first stage
         * of the pipeline it is being moved to, through `DealStage` — the
         * clock, the SLA, the status, the history row and the events — because
         * that is what has actually happened to it.
         */
        $boardMove = null;

        if ($name === 'deals' && array_key_exists('pipeline_id', $data)) {
            /*
             * THE ID OF A BOARD, NOT WHATEVER CASTS TO ONE.
             *
             * This read `(int) $data['pipeline_id']` — and review caught that
             * as the same mistake, in the code written to fix the same
             * mistake. MEASURED: `null`, `0`, `""`, `false`, `true`, `-3`,
             * `"abc"` and `[]` each cast to 0 or 1, fell through to "no
             * pipeline named", and SILENTLY RELOCATED THE DEAL to the first
             * board's first stage — with a 200 saying so, and a history row
             * to match. Worse than the stranding it replaced, because a
             * stranded deal is at least still on the board somebody chose.
             */
            $askedFor = $data['pipeline_id'];

            $validId = (is_int($askedFor) && $askedFor > 0)
                || (is_string($askedFor) && ctype_digit(trim($askedFor)) && (int) trim($askedFor) > 0);

            if (!$validId) {
                return Response::json([
                    'error' => 'pipeline_id has to be the id of a pipeline. To take a deal off '
                        . 'its board entirely, there is nowhere for it to go — a deal lives on one.',
                ], 422);
            }

            $wantedPipeline      = (int) trim((string) $askedFor);
            $data['pipeline_id'] = $wantedPipeline;
            $currentStage        = (int) ($existing['stage_id'] ?? 0);

            $stillFits = $currentStage > 0 && DB::exists(
                'SELECT id FROM {pipeline_stages} WHERE id = :id AND pipeline_id = :p',
                ['id' => $currentStage, 'p' => $wantedPipeline]
            );

            if (!$stillFits) {
                /*
                 * A PATCH MAY NOT OPEN OR CLOSE A DEAL.
                 *
                 * Review caught two ways this did. MEASURED: a deal won two
                 * minutes earlier — `status: won`, `won_at` stamped — came
                 * back `status: open, won_at: NULL` from a PATCH that named
                 * nothing but a board, because the new board's first stage is
                 * an open one and the stage columns follow the stage. That
                 * erases the date every revenue report keys on, with a 200
                 * and no event to say a won deal stopped being won.
                 *
                 * And the mirror image: a board whose first stage an operator
                 * has marked won or lost (the stage editor allows it) would
                 * have CLOSED the deal from the same PATCH, firing `deal.won`
                 * or `deal.lost` — and `deal.lost` starts a guest-facing
                 * email.
                 *
                 * Both are refused. Winning and losing are transitions with
                 * their own endpoint, which is the rule this whole module is
                 * built on; a board change is a reassignment and has no
                 * business being either.
                 */
                $status = strtolower(trim((string) ($existing['status'] ?? 'open')));

                if (in_array($status, ['won', 'lost'], true)) {
                    return Response::json([
                        'error' => 'That deal is ' . $status . ', and moving it to another board would '
                            . 'reopen it and erase the date it was ' . $status . '. Move it through '
                            . 'POST /api/v1/deals/{id}/stage, where reopening is the thing you asked for.',
                    ], 422);
                }

                $stage = $this->firstStageOf($wantedPipeline);

                if ($stage === null) {
                    return Response::json([
                        'error' => 'There is no pipeline with that id, or it has no stages.',
                    ], 422);
                }

                if ((int) $stage['is_won'] === 1 || (int) $stage['is_lost'] === 1) {
                    return Response::json([
                        'error' => 'The first stage of that board closes a deal, so moving one there '
                            . 'is a win or a loss rather than a reassignment. Use '
                            . 'POST /api/v1/deals/{id}/stage, which records it as the outcome it is.',
                    ], 422);
                }

                $now  = gmdate('Y-m-d H:i:s');
                $data = array_merge($data, DealStage::columns($existing, $stage, $now));
                $data['pipeline_id'] = (int) $stage['pipeline_id'];

                $boardMove = ['stage' => $stage, 'from' => $currentStage, 'at' => $now];
            }
        }

        try {
            if ($boardMove === null) {
                DB::update($definition['table'], $data, 'id = :id', ['id' => (int) $existing['id']]);
            } else {
                // The row and its history row together: a move recorded half
                // way is a deal whose stage clock no report can read.
                DB::transaction(static function () use ($definition, $data, $existing, $boardMove): void {
                    DB::update($definition['table'], $data, 'id = :id', ['id' => (int) $existing['id']]);

                    DealStage::recordMove(
                        (int) $existing['id'],
                        (int) $boardMove['from'],
                        (int) $boardMove['stage']['id'],
                        $existing,
                        (string) $boardMove['at']
                    );
                });
            }
        } catch (Throwable $e) {
            return $this->writeFailed($e, 'update', $name);
        }

        if ($boardMove !== null) {
            Event::fire('deal.stage_changed', [
                'id'            => (int) $existing['id'],
                'from_stage_id' => $boardMove['from'] > 0 ? (int) $boardMove['from'] : null,
                'to_stage_id'   => (int) $boardMove['stage']['id'],
            ]);

            /*
             * NO `DealStage::fireOutcome()` HERE, deliberately, and it is not
             * an omission: the guard above refuses a board whose first stage
             * closes a deal, so a board move cannot be a win or a loss and
             * the call could only ever be a no-op. Leaving it in would read
             * as though a PATCH can close a deal, which is exactly what this
             * must not do.
             */
        }

        Audit::record(
            'api_updated',
            $name,
            (int) $existing['id'],
            $existing,
            array_merge($existing, $data)
        );

        $row = DB::first(
            'SELECT * FROM {' . $definition['table'] . '} WHERE id = :id',
            ['id' => (int) $existing['id']]
        );

        return $this->json(['data' => ApiResource::present($row ?? [], $definition, Acl::can('view_revenue'))]);
    }

    public function destroy(Request $request): Response
    {
        $name       = (string) $request->route('resource', '');
        $definition = ApiResource::get($name);

        if ($definition === null) {
            return $this->notFoundJson('There is no resource called "' . $name . '".');
        }

        /*
         * DELETING IS ITS OWN PERMISSION.
         *
         * This used to check `write`, and that was a real hole rather than an
         * untidy one: this application separates the two everywhere a page
         * does — `contacts_manage` edits and `contacts_delete` deletes,
         * `bookings_manage` edits and `bookings_cancel` cancels — precisely
         * because the front-desk role an operator builds holds the first and
         * not the second. So a front-desk token could DELETE through the API
         * what the same person's own pages refuse them, and nothing on any
         * page would have shown it.
         */
        if ($definition['delete'] === null || !$definition['soft_delete']) {
            return Response::json([
                'error' => ucfirst($name) . ' cannot be deleted through the API.',
            ], 405);
        }

        if (!Acl::can((string) $definition['delete'])) {
            return $this->forbidden($definition['delete']);
        }

        // And found through THAT permission's scope, for the reason spelled
        // out on `findScoped()`.
        $existing = $this->findScoped(
            $definition,
            $request->routeInt('id'),
            (string) $definition['delete']
        );

        if ($existing === null) {
            return $this->notFoundJson('No ' . rtrim($name, 's') . ' with that id.');
        }

        /*
         * ALWAYS A SOFT DELETE, and only for resources that have the column.
         * A hard delete through an API is irreversible by a caller who may
         * have a bug in a loop, and every one of these rows is referenced by
         * something else — a booking by its payments, a contact by its
         * visits and its consent evidence.
         */
        DB::update(
            $definition['table'],
            ['deleted_at' => gmdate('Y-m-d H:i:s')],
            'id = :id',
            ['id' => (int) $existing['id']]
        );

        Audit::event('api_deleted', ['resource' => $name, 'id' => (int) $existing['id']], $name, (int) $existing['id']);

        return $this->json(['data' => ['id' => (int) $existing['id'], 'deleted' => true]]);
    }

    // =================================================================
    // The endpoints that are not CRUD
    // =================================================================

    /**
     * Record a visit.
     *
     * NOT an insert into `visits`, which is why it is its own handler: a
     * visit has to go through `VisitRecorder` so that the one-visit-per-
     * guest-per-day rule holds, the derived figures are refreshed, and
     * `visit.recorded` / `visit.first` fire. A POST that wrote the row
     * directly would give a family eleven visits for one afternoon and fire
     * nothing, so the welcome series would never start.
     */
    private function storeVisit(Request $request): Response
    {
        if (!Acl::can('contacts_manage')) {
            return $this->forbidden('contacts_manage');
        }

        $notAnObject = $this->bodyNotAnObject($request);

        if ($notAnObject !== null) {
            return $notAnObject;
        }

        $input = $request->json();

        /*
         * The same id rule the writable fields get, through the same helper —
         * this endpoint takes its body raw rather than through
         * `ApiResource::writable()`, so it has to ask. `(int)` alone turned
         * `true` into 1, which recorded the visit against whichever guest is
         * contact 1.
         */
        if (!array_key_exists('contact_id', $input) || $input['contact_id'] === null) {
            return Response::json(['error' => 'A visit needs a contact_id.'], 422);
        }

        $problem = ApiResource::idProblem($input['contact_id']);

        if ($problem !== null) {
            return Response::json([
                'error' => 'contact_id has to be the id of a contact: ' . $problem . '.',
            ], 422);
        }

        $contactId = (int) trim((string) $input['contact_id']);

        /*
         * THE CONTACT HAS TO BE ONE THIS TOKEN MAY WRITE TO.
         *
         * Any positive id used to be passed straight to the recorder, which
         * made this the one write in the API that ignored scope entirely: a
         * role holding `contacts_manage` at 'own' could add visits — and so
         * move the visit count, the lifetime value and the last-visit date,
         * and start the welcome series — on a guest belonging to somebody
         * else, or walk the id space doing it. A soft-deleted contact was
         * reachable too, which would have quietly revived them in every
         * report that counts visits.
         *
         * 404 rather than 403, same as everywhere else here: a caller who may
         * not touch a record does not get told it exists.
         */
        $contact = $this->findScoped(
            ApiResource::get('contacts') ?? [],
            $contactId,
            'contacts_manage'
        );

        if ($contact === null) {
            return $this->notFoundJson('No contact with that id.');
        }

        /*
         * AND A `booking_id` IS A RECORD SELECTOR, SO IT IS VALIDATED TOO.
         *
         * Found by sweeping this file for the mistake that had already
         * appeared three times: `(int) $input['booking_id']` turns `true`
         * into 1, so a visit would be filed against whichever party happens
         * to be booking 1 — somebody else's. `"abc"` became 0, which
         * `VisitRecorder` reads as "no booking", so a caller who named a
         * booking silently got a visit without one. Neither is a number
         * anybody typed.
         *
         * Scoped as well as validated: a booking the caller may not see is
         * not a booking they may attach a guest's visit to.
         */
        $bookingId = null;

        if (array_key_exists('booking_id', $input) && $input['booking_id'] !== null) {
            $problem = ApiResource::idProblem($input['booking_id']);

            if ($problem !== null) {
                return Response::json([
                    'error' => 'booking_id has to be the id of a booking (' . $problem . '). '
                        . 'Leave it out if the visit was not part of a booked party.',
                ], 422);
            }

            $bookingId = (int) trim((string) $input['booking_id']);

            if ($this->findScoped(ApiResource::get('bookings') ?? [], $bookingId, 'view_bookings') === null) {
                return $this->notFoundJson('No booking with that id.');
            }
        }

        $result = VisitRecorder::record([
            'contact_id'  => $contactId,
            'visit_date'  => $input['visit_date'] ?? null,
            'source'      => (string) ($input['source'] ?? 'manual'),
            'spend_cents' => (int) ($input['spend_cents'] ?? 0),
            'party_size'  => isset($input['party_size']) ? (int) $input['party_size'] : null,
            'booking_id'  => $bookingId,
            'external_ref' => $input['external_ref'] ?? null,
        ]);

        if ($result['visit_id'] === null) {
            return Response::json([
                'error'  => 'That visit was not recorded.',
                'reason' => $result['skipped'],
            ], 422);
        }

        Audit::event('api_visit_recorded', [
            'contact_id' => $contactId,
            'visit_id'   => $result['visit_id'],
            'recorded'   => $result['recorded'],
        ], 'visits', (int) $result['visit_id']);

        return $this->json([
            'data' => [
                'id'       => (int) $result['visit_id'],
                // `recorded` false with an id means it was already there: a
                // repeat of the same day, which is the correct answer and not
                // an error. An integration retrying a failed batch depends on
                // this being idempotent.
                'recorded' => (bool) $result['recorded'],
                'first'    => (bool) $result['first'],
                'note'     => $result['recorded']
                    ? null
                    : 'That guest already had a visit on that date, so this did not add another.',
            ],
        ], $result['recorded'] ? 201 : 200);
    }

    /**
     * Move a deal to another stage.
     *
     * NOT a PATCH on `stage_id`, which is why it is its own endpoint — and
     * `stage_id` was in the writable list until review caught it. A stage is
     * not a column, it is a move: entering one stamps `stage_entered_at`
     * (what the velocity report measures), sets `status` and `won_at`/
     * `lost_at`, computes the SLA deadline, writes the stage-history row the
     * pipeline report reads, and fires `deal.stage_changed` plus
     * `deal.won`/`deal.lost` — the events that start the deposit chase and
     * the thank-you. A PATCH that set the column did none of it: the board
     * showed the deal in "Won" with no won date, no history and no
     * automation ever running, and nothing anywhere said so.
     *
     * `DealStage::move()` is the same path the board posts to, so the two
     * cannot drift.
     */
    public function moveDealStage(Request $request): Response
    {
        if (!Acl::can('deals_manage')) {
            return $this->forbidden('deals_manage');
        }

        $definition = ApiResource::get('deals') ?? [];
        $deal       = $this->findScoped($definition, $request->routeInt('id'), 'deals_manage');

        if ($deal === null) {
            return $this->notFoundJson('No deal with that id.');
        }

        $notAnObject = $this->bodyNotAnObject($request);

        if ($notAnObject !== null) {
            return $notAnObject;
        }

        $input = $request->json();

        /*
         * By NAME or by id: an integration written against this venue's board
         * knows "Deposit chased", and one written against the product knows
         * neither, so both are accepted exactly as an automation step is.
         *
         * A STRING OR AN INTEGER, AND NOTHING ELSE IS COERCED INTO ONE. This
         * read `trim((string) ($input['stage'] ?? ...))`, and `(string) true`
         * is `"1"` — which `DealStage::resolve()` reads as stage id 1, the top
         * of the first board. So a client whose stage field carried a flag
         * rather than a name moved the deal to the top of its pipeline and got
         * a 200 saying so: a real transition, a history row, the stage clock
         * reset. An array or object cannot be cast at all, and the warning
         * that raises is promoted to an exception in development, which made
         * this the one write in this controller that could answer 500.
         */
        $named = $input['stage'] ?? $input['stage_id'] ?? null;

        if (!is_string($named) && !is_int($named)) {
            return Response::json([
                'error' => 'Name the stage to move to, as "stage" (its name) or "stage_id" '
                    . '(a number). Anything else is refused rather than guessed at.',
            ], 422);
        }

        $wanted = trim((string) $named);

        if ($wanted === '') {
            return Response::json([
                'error' => 'Name the stage to move to, as "stage" (its name) or "stage_id".',
            ], 422);
        }

        $before = $deal;

        try {
            $result = DealStage::move((int) $deal['id'], $wanted);
        } catch (Throwable $e) {
            // The same shape store() and update() use, so no input can make
            // this endpoint the only one that answers 500.
            return $this->writeFailed($e, 'move', 'deals');
        }

        /*
         * Already in that stage is NOT an error: it is the right answer, and
         * an integration retrying a failed batch depends on it. Told apart
         * from a refusal by its shape rather than its words — a move that was
         * refused names no from-stage.
         */
        $alreadyThere = !$result['moved']
            && $result['from'] !== null
            && $result['from'] === $result['to'];

        if (!$result['moved'] && !$alreadyThere) {
            return Response::json([
                'error' => 'That deal was not moved: ' . (string) ($result['reason'] ?? 'the stage was refused') . '.',
                'stage' => $result['to'],
            ], 422);
        }

        $row = DB::first('SELECT * FROM {deals} WHERE id = :id', ['id' => (int) $deal['id']]);

        if ($result['moved']) {
            Audit::record('api_deal_stage_changed', 'deals', (int) $deal['id'], $before, $row ?? []);
        }

        return $this->json([
            'data'  => ApiResource::present($row ?? [], $definition, Acl::can('view_revenue')),
            'moved' => (bool) $result['moved'],
            'from'  => $result['from'],
            'to'    => $result['to'],
            'note'  => $result['moved']
                ? null
                : 'That deal was already in ' . (string) $result['to'] . ', so nothing changed.',
        ]);
    }

    /**
     * Set a consent flag, WITH its evidence.
     *
     * The one write in this API that cannot be a PATCH on a field, because a
     * consent flag without a record of what the guest agreed to, when, and
     * through what, is a liability rather than a feature — it is the thing a
     * venue would have to produce if a complaint ever went anywhere.
     */
    public function consent(Request $request): Response
    {
        if (!Acl::can('contacts_manage')) {
            return $this->forbidden('contacts_manage');
        }

        $notAnObject = $this->bodyNotAnObject($request);

        if ($notAnObject !== null) {
            return $notAnObject;
        }

        $input = $request->json();

        $validator = Validator::make($input, [
            'channel'    => 'required|in:email,sms',
            'granted'    => 'required',
            'source'     => 'required|max:80',
            'disclosure' => 'max:2000',
        ]);

        if (!$validator->passes()) {
            return Response::json([
                'error'  => $validator->firstError() ?? 'Check the fields.',
                'fields' => $validator->errors(),
            ], 422);
        }

        // Under `contacts_manage`'s scope, not `view_contacts`'s: recording
        // consent is a write on somebody's guest, and a role that may only
        // edit its own must not be able to set a flag on anybody else's.
        $contact = $this->findScoped(
            ApiResource::get('contacts') ?? [],
            $request->routeInt('id'),
            'contacts_manage'
        );

        if ($contact === null) {
            return $this->notFoundJson('No contact with that id.');
        }

        /*
         * `granted` HAS TO BE A YES OR A NO, AND NOTHING ELSE IS READ AS "NO".
         *
         * This was `filter_var($input['granted'], FILTER_VALIDATE_BOOLEAN)`,
         * which returns **false** for anything it does not recognise. So
         * `"maybe"`, `"2"`, `"unknown"` — and `"Y"`, which is what a venue's
         * own spreadsheets and plenty of integrations send — each recorded a
         * WITHDRAWAL of consent the guest never made: the flag cleared,
         * `marketing_opt_out_at` stamped, and a row in an append-only
         * compliance log saying they revoked. Losing a reachable guest to a
         * typo is bad; writing a false entry in the log that exists to be
         * evidence is worse. `FILTER_NULL_ON_FAILURE` separates "no" from
         * "not a yes or a no", and the second one is refused.
         */
        $granted = filter_var($input['granted'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($granted === null) {
            return Response::json([
                'error' => 'granted has to say yes or no: true, false, 1, 0, "true", "false", '
                    . '"yes", "no", "on" or "off". Anything else is refused rather than read as a '
                    . 'withdrawal of consent.',
            ], 422);
        }

        /*
         * And the text fields have to be text. `max:80` does not check a
         * type, so an array passed validation and then died on `(string)` —
         * AFTER the consent flag had been written and before its evidence
         * row, which is the one state this endpoint exists to prevent.
         */
        foreach (['channel', 'source', 'disclosure'] as $field) {
            if (array_key_exists($field, $input) && $input[$field] !== null && !is_scalar($input[$field])) {
                return Response::json([
                    'error' => $field . ' has to be text.',
                ], 422);
            }
        }

        $channel = (string) $input['channel'];
        $now     = gmdate('Y-m-d H:i:s');

        $disclosure = trim((string) ($input['disclosure'] ?? ''));

        // GRANTING needs the words. Withdrawing does not: a guest saying stop
        // is honoured immediately and unconditionally, and refusing to record
        // a withdrawal because a field was missing would be the worst
        // possible reading of a compliance rule.
        if ($granted && $disclosure === '') {
            return Response::json([
                'error' => 'Granting consent needs the disclosure the guest agreed to, '
                    . 'word for word. Withdrawing it does not.',
            ], 422);
        }

        $column = $channel === 'sms' ? 'sms_consent' : 'email_consent';

        $update = [
            $column            => $granted ? 1 : 0,
            $column . '_at'    => $granted ? $now : null,
            'updated_at'       => $now,
        ];

        if (!$granted) {
            $update['marketing_opt_out_at'] = $now;
        }

        if ($channel === 'sms') {
            $update['sms_consent_source'] = (string) $input['source'];
        }

        /*
         * THE FLAG AND ITS EVIDENCE, OR NEITHER.
         *
         * These were two unwrapped writes. A consent flag with no record of
         * what the guest agreed to, when, and through what is the thing this
         * endpoint's whole docblock is about — it is what a venue would have
         * to produce if a complaint ever went anywhere — and anything failing
         * between the two left exactly that. Review found a reachable way in
         * (an array in a text field, which passed `max:80` and then died on
         * the cast); a full disk or a lock timeout would do it too.
         */
        DB::transaction(function () use ($update, $contact, $channel, $granted, $input, $request, $disclosure, $now): void {
            DB::update('contacts', $update, 'id = :id', ['id' => (int) $contact['id']]);

            DB::insert('consent_log', [
                'contact_id' => (int) $contact['id'],
                'channel'    => $channel,
                // The enum is granted|revoked. "withdrawn" is not one of them
                // and MySQL would have truncated it to an empty string in a
                // non-strict mode — a consent record with no action on it.
                'action'     => $granted ? 'granted' : 'revoked',
                'source'     => (string) $input['source'],
                'ip'         => $request->ip(),
                'user_agent' => 'API token #' . (int) (Auth::apiToken()['id'] ?? 0),
                // The disclosure goes in `evidence`, which is where this
                // schema keeps the words the guest agreed to.
                'evidence'   => json_encode(
                    array_filter([
                        'disclosure' => $disclosure === '' ? null : $disclosure,
                        'via'        => 'rest api v1',
                        'token_id'   => (int) (Auth::apiToken()['id'] ?? 0),
                    ], static fn ($v): bool => $v !== null),
                    JSON_UNESCAPED_SLASHES
                ),
                'created_at' => $now,
            ]);
        });

        Audit::event('api_consent_recorded', [
            'contact_id' => (int) $contact['id'],
            'channel'    => $channel,
            'granted'    => $granted,
            'source'     => (string) $input['source'],
        ], 'contacts', (int) $contact['id']);

        Event::fire($granted ? 'contact.consent_granted' : 'contact.consent_revoked', [
            'contact_id' => (int) $contact['id'],
            'channel'    => $channel,
        ]);

        return $this->json([
            'data' => [
                'contact_id' => (int) $contact['id'],
                'channel'    => $channel,
                'granted'    => $granted,
                'recorded_at' => str_replace(' ', 'T', $now) . 'Z',
            ],
        ]);
    }

    // =================================================================
    // Plumbing
    // =================================================================

    /**
     * Find one row, THROUGH THE SCOPE OF THE PERMISSION THAT GOVERNS THE CALL.
     *
     * Every single-record endpoint goes through this. Looking a row up by id
     * and then checking the scope is the same thing written in two steps, and
     * the second step is the one somebody forgets.
     *
     * `$permission` DEFAULTS TO THE READ ONE AND A WRITE MUST PASS ITS OWN.
     * Scopes are per permission (`role_permissions.scope`) and `Acl::can()`
     * only answers whether a permission is held AT ALL — not how widely. So a
     * role holding `view_contacts` at 'all' and `contacts_manage` at 'own' —
     * "see everyone, edit your own", an ordinary setup an operator can build
     * in Settings — passed `can()` and then loaded the record under the READ
     * scope, which let a token PATCH, delete or record consent for somebody
     * else's guest. The same mistake, in the same shape, as the one
     * `ContactController::visible()` carries a comment about: the fix there
     * was to take the governing permission as an argument, and this is that
     * fix on the API's side of the house.
     *
     * @param array<string,mixed> $definition
     * @return array<string,mixed>|null
     */
    private function findScoped(array $definition, int $id, ?string $permission = null): ?array
    {
        if ($id <= 0 || $definition === []) {
            return null;
        }

        $alias      = $this->alias($definition);
        $permission = $permission ?? (string) $definition['read'];

        [$scopeWhere, $params] = Acl::scopeCondition(
            $permission,
            $definition['owner'] === null ? '' : $alias . '.' . $definition['owner']
        );

        if ($definition['owner'] === null && Acl::scope($permission) !== 'all') {
            return null;
        }

        $params['id'] = $id;
        $clauses      = [$alias . '.id = :id', $scopeWhere];

        if ($definition['soft_delete']) {
            $clauses[] = $alias . '.deleted_at IS NULL';
        }

        return DB::first(
            'SELECT ' . $alias . '.* FROM {' . $definition['table'] . '} ' . $alias
                . ' WHERE ' . implode(' AND ', $clauses),
            $params
        );
    }

    /**
     * Insert, with the columns the table insists on.
     *
     * A few tables have NOT NULL columns with no default that a caller has no
     * business supplying — a booking's reference number, a membership's
     * member number. They are filled here rather than demanded of the caller.
     *
     * @param array<string,mixed> $definition
     * @param array<string,mixed> $data
     */
    private function insert(string $name, array $definition, array $data): int
    {
        $now = gmdate('Y-m-d H:i:s');

        /** @var array<string,mixed>|null The stage a new deal opens in. */
        $openingStage = null;

        if (DB::columnExists((string) $definition['table'], 'created_at')) {
            $data['created_at'] = $now;
        }

        if (DB::columnExists((string) $definition['table'], 'updated_at')) {
            $data['updated_at'] = $now;
        }

        if ($name === 'bookings') {
            $data['booking_number'] ??= 'API-' . strtoupper(bin2hex(random_bytes(4)));
            $data['status']         ??= 'inquiry';

            if (trim((string) ($data['event_date'] ?? '')) === '') {
                throw new RuntimeException('A booking needs an event_date.');
            }
        }

        if ($name === 'deals') {
            if (trim((string) ($data['title'] ?? '')) === '') {
                throw new RuntimeException('A deal needs a title.');
            }

            /*
             * A new deal OPENS AT THE TOP OF ITS BOARD, and the stage decides
             * the rest of the row.
             *
             * `stage_id` is not writable — a stage is a move, not a field (see
             * `ApiResource`) — so there is nothing to validate here and
             * nothing for a caller to get wrong: the first stage of the
             * pipeline they named, or of the first pipeline if they named
             * none, so the common case (a website form posting a lead) needs
             * no knowledge of this venue's board.
             *
             * `DealStage::columns()` then fills the stage clock, the SLA
             * deadline and the won/lost status exactly as it does on the
             * board, which is what makes an API-created deal measurable by
             * the velocity report instead of a row with no `stage_entered_at`.
             */
            /*
             * Same rule as a board move on PATCH: a pipeline the caller NAMES
             * has to be an id, and is refused rather than cast. Absent is
             * different and fine — that is the documented default, the first
             * board, which is what lets a website form post a lead knowing
             * nothing about this venue's pipelines.
             */
            if (array_key_exists('pipeline_id', $data)) {
                $askedFor = $data['pipeline_id'];

                $validId = (is_int($askedFor) && $askedFor > 0)
                    || (is_string($askedFor) && ctype_digit(trim($askedFor)) && (int) trim($askedFor) > 0);

                if (!$validId) {
                    throw new RuntimeException(
                        'pipeline_id has to be the id of a pipeline. Leave it out to use the first one.'
                    );
                }

                $data['pipeline_id'] = (int) trim((string) $askedFor);
            }

            $pipelineId = (int) ($data['pipeline_id'] ?? 0);
            $stage      = $this->firstStageOf($pipelineId);

            if ($stage === null) {
                throw new RuntimeException($pipelineId > 0
                    ? 'There is no pipeline with that id, or it has no stages.'
                    : 'This install has no pipeline to put a deal on.');
            }

            $data['pipeline_id'] = (int) $stage['pipeline_id'];
            $data                = array_merge($data, DealStage::columns([], $stage, $now));
            $openingStage        = $stage;
        }

        if ($name === 'contacts' && trim((string) ($data['first_name'] ?? '')) === ''
            && trim((string) ($data['last_name'] ?? '')) === ''
            && trim((string) ($data['email'] ?? '')) === ''
        ) {
            throw new RuntimeException('A contact needs at least a name or an email address.');
        }

        if ($name === 'tasks') {
            if (trim((string) ($data['title'] ?? '')) === '') {
                throw new RuntimeException('A task needs a title.');
            }

            $data['created_by'] ??= Auth::id();
            $data['status']     ??= 'open';
        }

        $id = DB::insert($definition['table'], $data);

        if ($openingStage !== null) {
            /*
             * The opening history row, with no from-stage — the same one
             * `DealController::store()` writes. Without it the deal's history
             * would start at its first MOVE and the time it spent in its
             * first stage could not be measured, which is the figure the
             * pipeline report opens with.
             */
            DB::insert('deal_stage_history', [
                'deal_id'          => $id,
                'from_stage_id'    => null,
                'to_stage_id'      => (int) $openingStage['id'],
                'user_id'          => Auth::id(),
                'duration_seconds' => null,
                'created_at'       => $now,
            ]);

            DealStage::fireOutcome($id, $openingStage);
        }

        // The same events the UI fires, so an automation does not care which
        // door a record came in through.
        match ($name) {
            'contacts' => Event::fire('contact.created', ['contact_id' => $id]),
            'deals'    => Event::fire('deal.created', ['deal_id' => $id, 'contact_id' => (int) ($data['contact_id'] ?? 0)]),
            'bookings' => Event::fire('booking.created', ['booking_id' => $id, 'contact_id' => (int) ($data['contact_id'] ?? 0)]),
            default    => null,
        };

        return $id;
    }

    /**
     * The stage a deal opens in, or lands in when it moves to another board.
     *
     * The top of the named pipeline, or of the first pipeline when none is
     * named — so the common case, a website form posting a lead, needs no
     * knowledge of this venue's board. Shared by create and by a board move
     * so the two cannot pick differently.
     *
     * **0 MEANS "THE CALLER NAMED NONE", AND ONLY A CALLER WHO REALLY NAMED
     * NONE MAY PASS IT.** Both call sites validate the id first, because
     * reaching here with a 0 that came from casting `null`, `""` or `"abc"`
     * turns a typo into a silent relocation to a board nobody chose. That was
     * a measured bug, not a hypothetical one.
     *
     * @return array<string,mixed>|null
     */
    private function firstStageOf(int $pipelineId): ?array
    {
        if ($pipelineId > 0) {
            return DB::first(
                'SELECT * FROM {pipeline_stages} WHERE pipeline_id = :p ORDER BY sort_order, id LIMIT 1',
                ['p' => $pipelineId]
            );
        }

        return DB::first('SELECT * FROM {pipeline_stages} ORDER BY pipeline_id, sort_order, id LIMIT 1');
    }

    /** A short, stable alias so a column reference is never ambiguous. */
    private function alias(array $definition): string
    {
        return 't';
    }

    private function notFoundJson(string $message): Response
    {
        return Response::json(['error' => $message], 404);
    }

    /**
     * A body that could never carry fields, named for what it actually is.
     *
     * Every write here reads `$request->json()`, which is typed `: array` and
     * so hands a list, a scalar and unparseable text over as `[]`. That made
     * the `if (!is_array($input))` guard this replaces unreachable — MEASURED:
     * a POST of `[1,2,3]` to the stage endpoint was answered "Name the stage
     * to move to", which describes a problem the caller does not have. The
     * body is the problem, so the body is what the error names.
     *
     * AN ABSENT BODY IS NOT REFUSED HERE. "Nothing to create — send at least
     * one field", "Nothing to change" and "Name the stage to move to" already
     * say precisely what is missing, and they say it about the request the
     * caller actually made.
     *
     * A form-encoded body reads as 'invalid', which is what it is: this API
     * takes JSON bodies and nothing else, and "not valid JSON" is a truer
     * answer than telling a caller who sent three fields that they sent none.
     */
    private function bodyNotAnObject(Request $request): ?Response
    {
        $problem = match ($request->jsonShape()) {
            'list'    => 'this body is a JSON list, which names no fields',
            'scalar'  => 'this body is a single JSON value, which names no fields',
            'invalid' => 'this body is not valid JSON',
            default   => null,
        };

        if ($problem === null) {
            return null;
        }

        return Response::json([
            'error' => 'Send a JSON object: ' . $problem . '.',
        ], 400);
    }

    private function forbidden(?string $permission): Response
    {
        return Response::json([
            'error'      => 'This token does not carry the permission this endpoint needs.',
            'permission' => $permission,
        ], 403);
    }

    /** @param list<string> $refused */
    /**
     * A field whose value cannot be what it claims to be.
     *
     * Separate from `refusedFields()` because it is a different answer to a
     * different mistake: there the field may not be written at all, here it
     * may be written but not with that. Both are 422, and both name the
     * field — a write that silently resolved `true` to record 1 is the bug
     * this exists to make impossible.
     *
     * @param array<string,string> $invalid field => why
     */
    private function invalidFields(array $invalid): Response
    {
        $parts = [];

        foreach ($invalid as $field => $why) {
            $parts[] = $field . ' (' . $why . ')';
        }

        return Response::json([
            'error'   => 'These fields name another record, so each has to be its id: '
                . implode(', ', $parts) . '.',
            'invalid' => $invalid,
        ], 422);
    }

    private function refusedFields(array $refused, array $definition): Response
    {
        return Response::json([
            'error'    => 'These fields cannot be written: ' . implode(', ', $refused) . '.',
            'refused'  => $refused,
            'writable' => $definition['writable'],
        ], 422);
    }

    private function writeFailed(Throwable $e, string $verb, string $name): Response
    {
        \App\Core\Log::error('API write failed', [
            'verb'     => $verb,
            'resource' => $name,
            'error'    => $e->getMessage(),
        ]);

        /*
         * The driver's message is NOT returned. "Cannot add or update a child
         * row: a foreign key constraint fails (`castle_crm`.`cc_deals`, ...)"
         * names the database, the prefix and the constraint — a free schema
         * map for anybody holding a low-privilege token.
         */
        return Response::json([
            'error' => 'That ' . $verb . ' was refused. Check that any ids you sent exist.',
        ], 422);
    }
}
