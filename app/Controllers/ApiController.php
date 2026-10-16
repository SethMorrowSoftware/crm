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
                'fields'   => $definition['fields'],
            ];
        }

        return $this->json([
            'version'   => 'v1',
            'resources' => $resources,
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

        $input = $request->json();

        if (!is_array($input)) {
            return Response::json(['error' => 'Send a JSON object.'], 400);
        }

        ['data' => $data, 'refused' => $refused] = ApiResource::writable($input, $definition);

        if ($refused !== []) {
            return $this->refusedFields($refused, $definition);
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

        // Found through the READ scope first: a 404 for a record outside the
        // caller's scope, never a 403, so the API does not confirm that a
        // record they may not see exists.
        $existing = $this->findScoped($definition, $request->routeInt('id'));

        if ($existing === null) {
            return $this->notFoundJson('No ' . rtrim($name, 's') . ' with that id.');
        }

        $input = $request->json();

        if (!is_array($input)) {
            return Response::json(['error' => 'Send a JSON object.'], 400);
        }

        ['data' => $data, 'refused' => $refused] = ApiResource::writable($input, $definition);

        if ($refused !== []) {
            return $this->refusedFields($refused, $definition);
        }

        if ($data === []) {
            return Response::json(['error' => 'Nothing to change — send at least one field.'], 422);
        }

        if (DB::columnExists((string) $definition['table'], 'updated_at')) {
            $data['updated_at'] = gmdate('Y-m-d H:i:s');
        }

        try {
            DB::update($definition['table'], $data, 'id = :id', ['id' => (int) $existing['id']]);
        } catch (Throwable $e) {
            return $this->writeFailed($e, 'update', $name);
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

        if ($definition['write'] === null || !$definition['soft_delete']) {
            return Response::json([
                'error' => ucfirst($name) . ' cannot be deleted through the API.',
            ], 405);
        }

        if (!Acl::can((string) $definition['write'])) {
            return $this->forbidden($definition['write']);
        }

        $existing = $this->findScoped($definition, $request->routeInt('id'));

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

        $input = $request->json();

        if (!is_array($input)) {
            return Response::json(['error' => 'Send a JSON object.'], 400);
        }

        $contactId = (int) ($input['contact_id'] ?? 0);

        if ($contactId <= 0) {
            return Response::json(['error' => 'A visit needs a contact_id.'], 422);
        }

        $result = VisitRecorder::record([
            'contact_id'  => $contactId,
            'visit_date'  => $input['visit_date'] ?? null,
            'source'      => (string) ($input['source'] ?? 'manual'),
            'spend_cents' => (int) ($input['spend_cents'] ?? 0),
            'party_size'  => isset($input['party_size']) ? (int) $input['party_size'] : null,
            'booking_id'  => isset($input['booking_id']) ? (int) $input['booking_id'] : null,
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

        $input = $request->json();

        if (!is_array($input)) {
            return Response::json(['error' => 'Send a JSON object.'], 400);
        }

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

        $contact = $this->findScoped(ApiResource::get('contacts') ?? [], $request->routeInt('id'));

        if ($contact === null) {
            return $this->notFoundJson('No contact with that id.');
        }

        $channel = (string) $input['channel'];
        $granted = filter_var($input['granted'], FILTER_VALIDATE_BOOLEAN);
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

        DB::update('contacts', $update, 'id = :id', ['id' => (int) $contact['id']]);

        DB::insert('consent_log', [
            'contact_id' => (int) $contact['id'],
            'channel'    => $channel,
            // The enum is granted|revoked. "withdrawn" is not one of them and
            // MySQL would have truncated it to an empty string in a
            // non-strict mode — a consent record with no action on it.
            'action'     => $granted ? 'granted' : 'revoked',
            'source'     => (string) $input['source'],
            'ip'         => $request->ip(),
            'user_agent' => 'API token #' . (int) (Auth::apiToken()['id'] ?? 0),
            // The disclosure goes in `evidence`, which is where this schema
            // keeps the words the guest agreed to.
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
     * Find one row, THROUGH THE CALLER'S READ SCOPE.
     *
     * Every single-record endpoint goes through this. Looking a row up by id
     * and then checking the scope is the same thing written in two steps, and
     * the second step is the one somebody forgets.
     *
     * @param array<string,mixed> $definition
     * @return array<string,mixed>|null
     */
    private function findScoped(array $definition, int $id): ?array
    {
        if ($id <= 0 || $definition === []) {
            return null;
        }

        $alias = $this->alias($definition);

        [$scopeWhere, $params] = Acl::scopeCondition(
            (string) $definition['read'],
            $definition['owner'] === null ? '' : $alias . '.' . $definition['owner']
        );

        if ($definition['owner'] === null && Acl::scope((string) $definition['read']) !== 'all') {
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

            // A pipeline and a stage are NOT NULL. Defaulted to the first
            // stage of the first pipeline rather than refused, so the common
            // case — a website form posting a lead — needs no knowledge of
            // this venue's board.
            if ((int) ($data['pipeline_id'] ?? 0) <= 0 || (int) ($data['stage_id'] ?? 0) <= 0) {
                $stage = DB::first(
                    'SELECT pipeline_id, id FROM {pipeline_stages} ORDER BY pipeline_id, sort_order LIMIT 1'
                );

                if ($stage === null) {
                    throw new RuntimeException('This install has no pipeline to put a deal on.');
                }

                $data['pipeline_id'] = (int) $stage['pipeline_id'];
                $data['stage_id']    = (int) $stage['id'];
            }

            $data['status'] ??= 'open';
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

    /** A short, stable alias so a column reference is never ambiguous. */
    private function alias(array $definition): string
    {
        return 't';
    }

    private function notFoundJson(string $message): Response
    {
        return Response::json(['error' => $message], 404);
    }

    private function forbidden(?string $permission): Response
    {
        return Response::json([
            'error'      => 'This token does not carry the permission this endpoint needs.',
            'permission' => $permission,
        ], 403);
    }

    /** @param list<string> $refused */
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
