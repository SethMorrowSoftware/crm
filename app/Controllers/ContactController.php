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
 * Guests: the contact list, the contact record, notes and consent.
 *
 * Two rules shape this class more than anything else.
 *
 * The first is that **consent is evidence, not a checkbox**. docs/PRIVACY.md §3
 * is explicit: TCPA compliance turns on what the guest was shown and when, and
 * "we have a flag set to 1" is not a defence. So there is no consent checkbox
 * on the contact form at all — email_consent, sms_consent and do_not_contact
 * are not in the editable column list, and the only way to change one is
 * through recordConsent()/blockContact(), which demand a source, stamp the
 * time, and write an append-only consent_log row. A POS-derived contact
 * therefore arrives with no consent and stays that way until somebody records
 * what the guest actually did (§2).
 *
 * The second is that **a scope is a scope everywhere**. Acl::scopeCondition()
 * is applied to the list AND to visible(), so an owner-scoped role cannot
 * reach a contact by typing its id into the URL. Filtering only the list would
 * make the scope decorative.
 */
final class ContactController extends Controller
{
    /** The lifecycle_stage enum, in the order the schema declares it. */
    private const STAGES = ['lead', 'prospect', 'customer', 'member', 'lapsed', 'advocate'];

    /** Channels consent can be recorded for. The schema's enum is wider; these are the two with flags. */
    private const CHANNELS = ['email', 'sms'];

    /** Columns a user may set from the form. Consent and money are deliberately absent. */
    private const EDITABLE = [
        'first_name', 'last_name', 'email', 'phone', 'alt_phone',
        'address_1', 'address_2', 'city', 'state', 'postal_code',
        'lifecycle_stage', 'source', 'source_detail',
        'owner_id', 'household_id', 'birth_date', 'external_pos_id',
    ];

    // -----------------------------------------------------------------
    // List
    // -----------------------------------------------------------------

    public function index(Request $request): Response
    {
        ['page' => $page, 'perPage' => $perPage, 'offset' => $offset] = $this->pagination($request, 25);

        [$where, $params] = $this->filters($request);

        $total = (int) DB::value(
            'SELECT COUNT(*) FROM {contacts} c WHERE ' . $where,
            $params
        );

        // Only the columns the list actually renders. The owner is filterable
        // but not shown — when the filter is set every row has the same owner,
        // and when it is not, the record page is one click away.
        $rows = DB::all(
            'SELECT c.id, c.first_name, c.last_name, c.email, c.phone_normalized,
                    c.lifecycle_stage, c.last_visit_at, c.visit_count, c.lifetime_value_cents,
                    c.email_consent, c.sms_consent, c.do_not_contact,
                    h.name AS household_name
               FROM {contacts} c
               LEFT JOIN {households} h ON h.id = c.household_id
              WHERE ' . $where . '
              ORDER BY c.last_name IS NULL, c.last_name, c.first_name, c.id DESC
              LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
            $params
        );

        return $this->view('contacts/index', [
            'pageTitle' => 'Contacts',
            'rows'      => array_map([$this, 'withMoneyVisibility'], $rows),
            'total'     => $total,
            'page'      => $page,
            'perPage'   => $perPage,
            'lastPage'  => max(1, (int) ceil($total / $perPage)),
            'stages'    => self::STAGES,
            'owners'    => $this->owners(),
            'filters'   => [
                'q'               => $request->str('q'),
                'lifecycle_stage' => $request->str('lifecycle_stage'),
                'owner_id'        => $request->str('owner_id'),
            ],
            'canManage' => Acl::can('contacts_manage'),
            'showMoney' => Acl::can('view_revenue'),
        ]);
    }

    /**
     * The WHERE clause for the list: the ACL scope, then the filters.
     *
     * @return array{0:string,1:array<string,mixed>}
     */
    private function filters(Request $request): array
    {
        [$scope, $params] = Acl::scopeCondition('view_contacts', 'c.owner_id');

        // An own/team scope compares against owner_id, so a contact nobody
        // owns is outside it. That is the ACL's answer, not one invented here:
        // adding "OR owner_id IS NULL" would quietly widen every scoped role's
        // reach to the whole POS import.
        $clauses = ['c.deleted_at IS NULL', $scope];

        $search = trim($request->str('q'));

        if ($search !== '') {
            // One placeholder PER OCCURRENCE, never one reused across the
            // clause. The connection runs with ATTR_EMULATE_PREPARES off, so
            // MySQL prepares the statement itself and each `:name` becomes a
            // separate positional parameter — binding one name for four
            // occurrences is HY093 "Invalid parameter number", i.e. a 500 on
            // every search rather than a wrong result. (MEASURED: that is
            // exactly how this shipped the first time.)
            $ors  = [];
            $term = '%' . $this->escapeLike($search) . '%';

            foreach ([
                'c.first_name',
                'c.last_name',
                "CONCAT_WS(' ', c.first_name, c.last_name)",
                'c.email',
            ] as $index => $column) {
                $key = 'like' . $index;
                // LIKE specials in user input are escaped, so a search for
                // "50% off" looks for that text rather than matching
                // everything.
                $params[$key] = $term;
                $ors[] = $column . ' LIKE :' . $key;
            }

            $params['exact'] = $search;
            $ors[] = 'c.external_pos_id = :exact';

            $digits = (string) preg_replace('/\D+/', '', $search);

            if (strlen($digits) >= 7) {
                // Phone search runs against phone_normalized, the E.164
                // column, so "(845) 555-0143" and "8455550143" find the same
                // guest. `phone` as typed is never searched.
                $params['digits'] = '%' . $digits . '%';
                $ors[] = 'c.phone_normalized LIKE :digits';
            }

            $clauses[] = '(' . implode(' OR ', $ors) . ')';
        }

        $stage = $request->str('lifecycle_stage');

        if (in_array($stage, self::STAGES, true)) {
            $clauses[] = 'c.lifecycle_stage = :stage';
            $params['stage'] = $stage;
        }

        $ownerId = $request->str('owner_id');

        if ($ownerId === 'none') {
            $clauses[] = 'c.owner_id IS NULL';
        } elseif ($ownerId !== '' && ctype_digit($ownerId)) {
            $clauses[] = 'c.owner_id = :owner_id';
            $params['owner_id'] = (int) $ownerId;
        }

        return [implode(' AND ', $clauses), $params];
    }

    /** Escape the LIKE metacharacters MySQL honours, leaving the rest alone. */
    private function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    // -----------------------------------------------------------------
    // Record
    // -----------------------------------------------------------------

    public function show(Request $request): Response
    {
        $contact = $this->visible($request->routeInt('id'));

        if ($contact === null) {
            return $this->notFound('That contact does not exist, or it was deleted.');
        }

        $id = (int) $contact['id'];

        $household = $contact['household_id'] === null ? null : DB::first(
            'SELECT * FROM {households} WHERE id = :id AND deleted_at IS NULL',
            ['id' => (int) $contact['household_id']]
        );

        // Scoped exactly as HouseholdController::members() is, and for the same
        // reason: the household row carries no owner of its own, so a sibling
        // list read without the scope would hand a Party Host the names and
        // addresses of contacts they cannot open — reachable from any one
        // contact of theirs that happens to share a household.
        $householdMembers = [];

        if ($household !== null) {
            [$memberScope, $memberParams] = Acl::scopeCondition('view_contacts', 'c.owner_id');
            $memberParams['hid'] = (int) $household['id'];
            $memberParams['self'] = $id;

            $householdMembers = DB::all(
                'SELECT c.id, c.first_name, c.last_name, c.email
                   FROM {contacts} c
                  WHERE c.household_id = :hid AND c.id <> :self AND c.deleted_at IS NULL
                    AND ' . $memberScope . '
                  ORDER BY c.last_name, c.first_name',
                $memberParams
            );
        }

        return $this->view('contacts/show', [
            'pageTitle'  => $this->displayName($contact),
            'contact'    => $this->withMoneyVisibility($contact),
            'household'  => $household,
            'members'    => $householdMembers,
            'children'   => DB::all(
                'SELECT id, first_name, birth_date, allergies
                   FROM {children}
                  WHERE contact_id = :id AND deleted_at IS NULL
                  ORDER BY birth_date',
                ['id' => $id]
            ),
            'notes'      => DB::all(
                'SELECT n.id, n.body, n.is_pinned, n.created_at, u.name AS author
                   FROM {contact_notes} n
                   LEFT JOIN {users} u ON u.id = n.user_id
                  WHERE n.contact_id = :id
                  ORDER BY n.is_pinned DESC, n.created_at DESC
                  LIMIT 100',
                ['id' => $id]
            ),
            'consent'       => $this->consentSummary($contact),
            'consentLog'    => DB::all(
                'SELECT channel, action, source, evidence, created_at
                   FROM {consent_log}
                  WHERE contact_id = :id
                  ORDER BY created_at DESC, id DESC
                  LIMIT 50',
                ['id' => $id]
            ),
            // The timeline is whatever the activities table actually holds.
            // Nothing is synthesised to fill it: the modules that write these
            // rows land in later phases, and a fabricated history on a guest
            // record is worse than an empty one.
            //
            // It binds the id twice under two names because the statement
            // mentions it twice — see filters() on native prepares.
            'timeline'   => DB::all(
                'SELECT a.type, a.subject, a.body, a.outcome, a.occurred_at, u.name AS author
                   FROM {activities} a
                   LEFT JOIN {users} u ON u.id = a.user_id
                  WHERE (a.entity_type = :etype AND a.entity_id = :entity_id)
                     OR a.contact_id = :contact_id
                  ORDER BY a.occurred_at DESC, a.id DESC
                  LIMIT 50',
                ['etype' => 'contact', 'entity_id' => $id, 'contact_id' => $id]
            ),
            'owner'      => $contact['owner_id'] === null ? null : DB::first(
                'SELECT id, name, email FROM {users} WHERE id = :id',
                ['id' => (int) $contact['owner_id']]
            ),
            'companies'  => DB::all(
                'SELECT co.id, co.name, cc.job_title, cc.is_primary
                   FROM {contact_companies} cc
                   JOIN {companies} co ON co.id = cc.company_id
                  WHERE cc.contact_id = :id
                  ORDER BY cc.is_primary DESC, co.name',
                ['id' => $id]
            ),
            'canManage'  => Acl::can('contacts_manage'),
            'canDelete'  => Acl::can('contacts_delete'),
            'showMoney'  => Acl::can('view_revenue'),
            'channels'   => self::CHANNELS,
        ]);
    }

    public function create(Request $request): Response
    {
        if ($denied = $this->authorize('contacts_manage')) {
            return $denied;
        }

        return $this->view('contacts/form', [
            'pageTitle'  => 'Add a contact',
            'contact'    => null,
            'stages'     => self::STAGES,
            'owners'     => $this->owners(),
            'households' => $this->households(),
        ]);
    }

    public function store(Request $request): Response
    {
        if ($denied = $this->authorize('contacts_manage')) {
            return $denied;
        }

        $data = $this->validateContact($request);

        if ($data instanceof Response) {
            return $data;
        }

        $row = $this->columnsFrom($data);

        if ($guard = $this->guardIdentifiable($row, $request, '/contacts/new')) {
            return $guard;
        }

        $row['country']    = 'US';
        $row['created_at'] = now_utc();
        $row['updated_at'] = now_utc();

        $id = DB::insert('contacts', $row);

        Audit::record('contact.created', 'contacts', $id, null, $row);

        return $this->success($request, 'Contact saved.', '/contacts/' . $id);
    }

    public function edit(Request $request): Response
    {
        if ($denied = $this->authorize('contacts_manage')) {
            return $denied;
        }

        $contact = $this->visible($request->routeInt('id'), 'contacts_manage');

        if ($contact === null) {
            return $this->notFound('That contact does not exist, or it was deleted.');
        }

        return $this->view('contacts/form', [
            'pageTitle'  => 'Edit ' . $this->displayName($contact),
            'contact'    => $contact,
            'stages'     => self::STAGES,
            'owners'     => $this->owners(),
            'households' => $this->households(),
        ]);
    }

    public function update(Request $request): Response
    {
        if ($denied = $this->authorize('contacts_manage')) {
            return $denied;
        }

        $id      = $request->routeInt('id');
        $contact = $this->visible($id, 'contacts_manage');

        if ($contact === null) {
            return $this->notFound('That contact does not exist, or it was deleted.');
        }

        $data = $this->validateContact($request);

        if ($data instanceof Response) {
            return $data;
        }

        $row = $this->columnsFrom($data);

        if ($guard = $this->guardIdentifiable($row, $request, '/contacts/' . $id . '/edit')) {
            return $guard;
        }

        $row['updated_at'] = now_utc();

        DB::transaction(static function () use ($id, $row, $contact): void {
            DB::update('contacts', $row, 'id = :id', ['id' => $id]);

            // Moving somebody out of a household through the CONTACT form is
            // the same event as removing them on the household page, so it has
            // to have the same consequence: a household must not go on naming
            // a main contact who is no longer a member of it. Without this the
            // household list keeps showing them, and a party confirmation is
            // addressed to somebody who moved out.
            $oldHouseholdId = (int) ($contact['household_id'] ?? 0);

            if ($oldHouseholdId > 0 && $oldHouseholdId !== (int) ($row['household_id'] ?? 0)) {
                DB::update('households', [
                    'primary_contact_id' => null,
                    'updated_at'         => now_utc(),
                ], 'id = :hid AND primary_contact_id = :cid', ['hid' => $oldHouseholdId, 'cid' => $id]);
            }

            Audit::record('contact.updated', 'contacts', $id, $contact, $row);
        });

        /*
         * Fired after the commit, and the lifecycle move separately.
         *
         * "A guest becomes a customer" is the moment a venue builds its
         * onboarding on, and it is not the same moment as "somebody corrected
         * a phone number" — an automation on `contact.updated` would run for
         * both. The payload names which columns moved rather than carrying
         * their values: this event is fired on every edit, and the values
         * include a phone number and an email address.
         */
        Event::fire('contact.updated', ['contact_id' => $id, 'changed' => array_keys($row)]);

        $wasStage = (string) ($contact['lifecycle_stage'] ?? '');
        $nowStage = (string) ($row['lifecycle_stage'] ?? $wasStage);

        if ($nowStage !== $wasStage) {
            Event::fire('contact.lifecycle_changed', [
                'contact_id' => $id,
                'from'       => $wasStage,
                'to'         => $nowStage,
            ]);
        }

        return $this->success($request, 'Contact updated.', '/contacts/' . $id);
    }

    public function destroy(Request $request): Response
    {
        if ($denied = $this->authorize('contacts_delete')) {
            return $denied;
        }

        $id      = $request->routeInt('id');
        $contact = $this->visible($id, 'contacts_delete');

        if ($contact === null) {
            return $this->notFound('That contact does not exist, or it was deleted.');
        }

        DB::transaction(static function () use ($id, $contact): void {
            // Soft delete. The contact is referenced by visits, bookings,
            // consent evidence and the audit trail, and a hard delete would
            // take the venue's own history with it. Erasing a guest on request
            // is a different operation behind a different permission
            // (contacts_erase), and it anonymises rather than drops.
            DB::update('contacts', [
                'deleted_at' => now_utc(),
                'updated_at' => now_utc(),
            ], 'id = :id AND deleted_at IS NULL', ['id' => $id]);

            // A deleted contact must not stay listed as a household's primary
            // member: the household page would show a name that no longer
            // resolves to a record anybody can open.
            DB::update('households', [
                'primary_contact_id' => null,
                'updated_at'         => now_utc(),
            ], 'primary_contact_id = :id', ['id' => $id]);

            Audit::record('contact.deleted', 'contacts', $id, $contact, null);
        });

        return $this->success($request, 'Contact deleted.', '/contacts');
    }

    // -----------------------------------------------------------------
    // Notes
    // -----------------------------------------------------------------

    public function storeNote(Request $request): Response
    {
        if ($denied = $this->authorize('contacts_manage')) {
            return $denied;
        }

        $id      = $request->routeInt('id');
        $contact = $this->visible($id, 'contacts_manage');

        if ($contact === null) {
            return $this->notFound('That contact does not exist, or it was deleted.');
        }

        $data = $this->validate($request, [
            'body' => 'required|string|min:2|max:5000',
        ], ['body' => 'Note']);

        if ($data instanceof Response) {
            return $data;
        }

        $noteId = DB::insert('contact_notes', [
            'contact_id' => $id,
            'user_id'    => Auth::id(),
            'body'       => $data['body'],
            'is_pinned'  => $request->bool('is_pinned') ? 1 : 0,
            'created_at' => now_utc(),
            'updated_at' => now_utc(),
        ]);

        Audit::record('contact_note.created', 'contact_notes', $noteId, null, [
            'contact_id' => $id,
            // The note body itself is not copied into the audit diff: it would
            // make the log a second, less protected copy of what staff wrote
            // about a guest. Length is enough to prove the write happened.
            'length'     => strlen((string) $data['body']),
        ]);

        return $this->success($request, 'Note added.', '/contacts/' . $id);
    }

    // -----------------------------------------------------------------
    // Consent
    // -----------------------------------------------------------------

    /**
     * Record a consent event.
     *
     * Deliberately NOT a checkbox on the contact form. A grant needs a source
     * — which form, which keyword, which member of staff heard it — and it
     * writes both the flag with its timestamp and an append-only consent_log
     * row carrying the disclosure wording. That row is the thing that answers
     * "prove they opted in"; the flag on the contact is only a cache of it.
     */
    public function updateConsent(Request $request): Response
    {
        if ($denied = $this->authorize('contacts_manage')) {
            return $denied;
        }

        $id      = $request->routeInt('id');
        $contact = $this->visible($id, 'contacts_manage');

        if ($contact === null) {
            return $this->notFound('That contact does not exist, or it was deleted.');
        }

        $data = $this->validate($request, [
            'channel'  => 'required|in:' . implode(',', self::CHANNELS),
            'action'   => 'required|in:granted,revoked',
            'source'   => 'required|string|min:3|max:80',
            'evidence' => 'nullable|string|max:2000',
        ], [
            'source'   => 'Where this came from',
            'evidence' => 'What the guest was shown',
        ]);

        if ($data instanceof Response) {
            return $data;
        }

        $channel = (string) $data['channel'];
        $granted = $data['action'] === 'granted';
        $back    = '/contacts/' . $id;

        if ($granted && (int) $contact['do_not_contact'] === 1) {
            return $this->refuse(
                $request,
                'This guest is marked do-not-contact. Lift that block first, and only '
                . 'if the guest asked you to.',
                $back
            );
        }

        // There has to be something to consent FOR. Recording email consent on
        // a record with no address stores a permission that can never be
        // exercised and reads, later, as a marketable guest.
        if ($granted) {
            $missing = $channel === 'email'
                ? trim((string) ($contact['email'] ?? '')) === ''
                : trim((string) ($contact['phone_normalized'] ?? '')) === '';

            if ($missing) {
                return $this->refuse(
                    $request,
                    $channel === 'email'
                        ? 'Add an email address before recording email consent.'
                        : 'Add a phone number before recording SMS consent.',
                    $back
                );
            }
        }

        $update = [
            $channel . '_consent'    => $granted ? 1 : 0,
            $channel . '_consent_at' => $granted ? now_utc() : null,
            'updated_at'             => now_utc(),
        ];

        if ($channel === 'sms') {
            $update['sms_consent_source'] = $granted ? $data['source'] : null;
        }

        if (!$granted) {
            // The date they withdrew. Kept on a later grant rather than
            // cleared: it is evidence of something that happened.
            $update['marketing_opt_out_at'] = now_utc();
        }

        DB::transaction(function () use ($id, $contact, $update, $channel, $granted, $data, $request): void {
            DB::update('contacts', $update, 'id = :id', ['id' => $id]);

            $this->logConsent($id, $channel, $granted ? 'granted' : 'revoked', (string) $data['source'], $request, [
                'disclosure' => $data['evidence'] ?? null,
            ]);

            Audit::record(
                $granted ? 'contact.consent_granted' : 'contact.consent_revoked',
                'contacts',
                $id,
                [$channel . '_consent' => (int) $contact[$channel . '_consent']],
                [$channel . '_consent' => $granted ? 1 : 0, 'source' => $data['source']]
            );
        });

        /*
         * Announced as well as audited.
         *
         * This had the audit row and no event, so a withdrawal recorded at the
         * desk — "take me off the texts", said at the counter — reached the
         * consent log and nothing else. The sequence they were half way
         * through carried on, because the only thing that could have stopped
         * it is an automation on `contact.consent_revoked`, and nothing fired
         * it anywhere in the application.
         */
        Event::fire(
            $granted ? 'contact.consent_granted' : 'contact.consent_revoked',
            [
                'contact_id' => $id,
                'channel'    => $channel,
                'source'     => (string) $data['source'],
            ]
        );

        return $this->success(
            $request,
            $granted
                ? ucfirst($channel) . ' consent recorded, with the source and the time.'
                : ucfirst($channel) . ' consent withdrawn.',
            $back
        );
    }

    /**
     * Set or lift the do-not-contact block.
     *
     * Setting it clears both consents, because that is what the guest asked
     * for. Lifting it does NOT restore them: consent that was withdrawn has to
     * be given again, by the guest, with a new source.
     */
    public function setDoNotContact(Request $request): Response
    {
        if ($denied = $this->authorize('contacts_manage')) {
            return $denied;
        }

        $id      = $request->routeInt('id');
        $contact = $this->visible($id, 'contacts_manage');

        if ($contact === null) {
            return $this->notFound('That contact does not exist, or it was deleted.');
        }

        $data = $this->validate($request, [
            'state'  => 'required|in:on,off',
            'source' => 'required|string|min:3|max:80',
        ], ['source' => 'Where this came from']);

        if ($data instanceof Response) {
            return $data;
        }

        $blocking = $data['state'] === 'on';
        $back     = '/contacts/' . $id;

        if ($blocking === ((int) $contact['do_not_contact'] === 1)) {
            return $this->refuse($request, 'That is already the current state.', $back);
        }

        $update = ['do_not_contact' => $blocking ? 1 : 0, 'updated_at' => now_utc()];

        if ($blocking) {
            $update['email_consent']        = 0;
            $update['email_consent_at']     = null;
            $update['sms_consent']          = 0;
            $update['sms_consent_at']       = null;
            $update['sms_consent_source']   = null;
            $update['marketing_opt_out_at'] = now_utc();
        }

        DB::transaction(function () use ($id, $contact, $update, $blocking, $data, $request): void {
            DB::update('contacts', $update, 'id = :id', ['id' => $id]);

            if ($blocking) {
                // One revoked row per channel that was actually held, so the
                // log says what was withdrawn rather than implying consent
                // existed on a channel that never had any.
                foreach (self::CHANNELS as $channel) {
                    if ((int) $contact[$channel . '_consent'] === 1) {
                        $this->logConsent($id, $channel, 'revoked', (string) $data['source'], $request, [
                            'reason' => 'do_not_contact set',
                        ]);
                    }
                }
            }

            Audit::record(
                $blocking ? 'contact.do_not_contact_set' : 'contact.do_not_contact_lifted',
                'contacts',
                $id,
                ['do_not_contact' => (int) $contact['do_not_contact']],
                ['do_not_contact' => $blocking ? 1 : 0, 'source' => $data['source']]
            );
        });

        if ($blocking) {
            // Do-not-contact is broader than a single channel's withdrawal,
            // but it withdraws both, so it announces one revocation per
            // channel that was actually held — the same shape as the consent
            // log above, so an automation that removes somebody from a
            // sequence runs for the channel it is about.
            foreach (self::CHANNELS as $channel) {
                if ((int) $contact[$channel . '_consent'] === 1) {
                    Event::fire('contact.consent_revoked', [
                        'contact_id' => $id,
                        'channel'    => $channel,
                        'source'     => (string) $data['source'],
                        'reason'     => 'do_not_contact',
                    ]);
                }
            }
        }

        return $this->success(
            $request,
            $blocking
                ? 'Marked do-not-contact. Both consents were cleared.'
                : 'Do-not-contact lifted. Consent still has to be given again before anything is sent.',
            $back
        );
    }

    /**
     * Append a consent_log row.
     *
     * @param array<string,mixed> $evidence
     */
    private function logConsent(
        int $contactId,
        string $channel,
        string $action,
        string $source,
        Request $request,
        array $evidence = []
    ): void {
        $user = Auth::user();

        DB::insert('consent_log', [
            'contact_id' => $contactId,
            'channel'    => $channel,
            'action'     => $action,
            'source'     => $source,
            'ip'         => $request->ip(),
            'user_agent' => substr($request->userAgent(), 0, 500),
            'evidence'   => json_encode(array_merge($evidence, [
                'method'            => 'staff_entry',
                'recorded_by'       => $user['name'] ?? null,
                'recorded_by_id'    => Auth::id(),
            ]), JSON_UNESCAPED_UNICODE),
            'created_at' => now_utc(),
        ]);
    }

    /**
     * The consent state in plain words, for the record page.
     *
     * @param array<string,mixed> $contact
     * @return list<array{label:string,state:string,detail:string,tone:string}>
     */
    private function consentSummary(array $contact): array
    {
        $blocked = (int) $contact['do_not_contact'] === 1;
        $rows    = [];

        foreach ([
            'email' => ['Email', 'email', 'an email address'],
            'sms'   => ['Text message', 'phone_normalized', 'a phone number'],
        ] as $channel => [$label, $column, $needs]) {
            $held = (int) $contact[$channel . '_consent'] === 1;
            $when = $contact[$channel . '_consent_at'] ?? null;
            $has  = trim((string) ($contact[$column] ?? '')) !== '';

            if ($blocked) {
                $rows[] = [
                    'label'  => $label,
                    'state'  => 'Blocked',
                    'detail' => 'This guest is marked do-not-contact, which overrides every consent flag.',
                    'tone'   => 'bad',
                ];
                continue;
            }

            if ($held) {
                $rows[] = [
                    'label'  => $label,
                    'state'  => 'Consent on record',
                    'detail' => $when === null
                        ? 'Given, but with no date recorded — an older row, or one written '
                            . 'before consent was timestamped.'
                        : 'Given ' . local_time((string) $when) . '.'
                            . ($channel === 'sms' && !empty($contact['sms_consent_source'])
                                ? ' Source: ' . (string) $contact['sms_consent_source'] . '.'
                                : ''),
                    'tone'   => 'ok',
                ];
                continue;
            }

            $rows[] = [
                'label'  => $label,
                'state'  => 'No consent',
                'detail' => $has
                    ? 'Nothing marketing may be sent on this channel. Transactional messages '
                        . 'about something the guest bought are not affected.'
                    : 'No consent, and no ' . $needs . ' on the record either.',
                'tone'   => 'warn',
            ];
        }

        if (!empty($contact['marketing_opt_out_at'])) {
            $rows[] = [
                'label'  => 'Last opted out',
                'state'  => local_date((string) $contact['marketing_opt_out_at']),
                'detail' => 'The most recent time marketing consent was withdrawn. Kept even if '
                    . 'consent was given again afterwards.',
                'tone'   => 'muted',
            ];
        }

        return $rows;
    }

    // -----------------------------------------------------------------
    // Shared
    // -----------------------------------------------------------------

    /**
     * Fetch a contact the current user is allowed to see.
     *
     * The same scope the list uses, so an own-scoped role cannot read another
     * rep's guest by guessing an id.
     *
     * @return array<string,mixed>|null
     */
    private function visible(int $id, string $permission = 'view_contacts'): ?array
    {
        if ($id <= 0) {
            return null;
        }

        // THE PERMISSION WHOSE SCOPE DECIDES, not always the read one.
        //
        // Scopes are per permission (role_permissions.scope), and the can:
        // middleware only checks that a permission EXISTS — not its scope. So
        // a role holding view_contacts at 'all' and contacts_manage at 'own' —
        // "see everyone, edit your own", a perfectly ordinary setup an operator
        // can build in Settings — passed the middleware and then loaded the
        // contact under the READ scope, which let a crafted POST edit, delete,
        // annotate or record consent for somebody else's contact.
        //
        // DealController::findDeal() took the permission as an argument from
        // the start; this did not, and the two modules disagreeing is what hid
        // it. Found by review.
        [$scope, $params] = Acl::scopeCondition($permission, 'c.owner_id');
        $params['id'] = $id;

        return DB::first(
            'SELECT c.* FROM {contacts} c
              WHERE c.id = :id AND c.deleted_at IS NULL AND ' . $scope,
            $params
        );
    }

    /**
     * Validate the contact form.
     *
     * @return array<string,mixed>|Response
     */
    private function validateContact(Request $request): array|Response
    {
        return $this->validate($request, [
            'first_name'      => 'nullable|string|max:80',
            'last_name'       => 'nullable|string|max:80',
            'email'           => 'nullable|email|max:191',
            'phone'           => 'nullable|phone|max:32',
            'alt_phone'       => 'nullable|phone|max:32',
            'address_1'       => 'nullable|string|max:160',
            'address_2'       => 'nullable|string|max:160',
            'city'            => 'nullable|string|max:80',
            'state'           => 'nullable|string|max:40',
            'postal_code'     => 'nullable|string|max:20',
            'lifecycle_stage' => 'required|in:' . implode(',', self::STAGES),
            'source'          => 'nullable|string|max:40',
            'source_detail'   => 'nullable|string|max:191',
            'owner_id'        => 'nullable|int|exists:users',
            'household_id'    => 'nullable|int|exists:households',
            'birth_date'      => 'nullable|date',
            'external_pos_id' => 'nullable|string|max:64',
        ], [
            'lifecycle_stage' => 'Lifecycle stage',
            'external_pos_id' => 'Card number',
            'owner_id'        => 'Owner',
            'household_id'    => 'Household',
        ]);
    }

    /**
     * Turn validated input into the column set, normalising as we go.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function columnsFrom(array $data): array
    {
        $row = [];

        foreach (self::EDITABLE as $column) {
            $value = $data[$column] ?? null;

            if (is_string($value)) {
                $value = trim($value);

                if ($value === '') {
                    $value = null;
                }
            }

            $row[$column] = $value;
        }

        // E.164 for every write path, so SMS matching and Text Club dedup
        // compare equal across two renderings of one number.
        $row['phone_normalized'] = normalize_phone(
            $row['phone'] === null ? null : (string) $row['phone']
        );

        if ($row['owner_id'] !== null) {
            $row['owner_id'] = (int) $row['owner_id'];
        }

        if ($row['household_id'] !== null) {
            $row['household_id'] = (int) $row['household_id'];
        }

        if ($row['email'] !== null) {
            $row['email'] = strtolower((string) $row['email']);
        }

        if ($row['birth_date'] !== null) {
            $timestamp = strtotime((string) $row['birth_date']);
            $row['birth_date'] = $timestamp === false ? null : gmdate('Y-m-d', $timestamp);
        }

        return $row;
    }

    /**
     * A contact has to be identifiable: a name, or a way to reach them.
     *
     * Without this the form happily writes a row that is all nulls but a
     * lifecycle stage, which cannot be searched for, merged, or recognised as
     * the same guest next time.
     *
     * @param array<string,mixed> $row
     */
    private function guardIdentifiable(array $row, Request $request, string $fallback): ?Response
    {
        foreach (['first_name', 'last_name', 'email', 'phone_normalized', 'external_pos_id'] as $column) {
            if (($row[$column] ?? null) !== null) {
                return null;
            }
        }

        return $this->refuse(
            $request,
            'A contact needs a name, or an email, phone or card number — otherwise there is '
            . 'no way to find this guest again.',
            $fallback
        );
    }

    /**
     * Hide money from roles without view_revenue.
     *
     * Front desk staff can look a guest up and take a booking without seeing
     * what that guest is worth, which is the separation docs/PRIVACY.md §4
     * describes. Scrubbed server-side: a hidden column is not a hidden column
     * if it is in the HTML.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function withMoneyVisibility(array $row): array
    {
        if (Acl::can('view_revenue')) {
            return $row;
        }

        foreach (['lifetime_value_cents', 'avg_spend_cents'] as $column) {
            if (array_key_exists($column, $row)) {
                $row[$column] = null;
            }
        }

        return $row;
    }

    /** @param array<string,mixed> $contact */
    private function displayName(array $contact): string
    {
        $name = trim(((string) ($contact['first_name'] ?? '')) . ' ' . ((string) ($contact['last_name'] ?? '')));

        if ($name !== '') {
            return $name;
        }

        foreach (['email', 'phone_normalized', 'external_pos_id'] as $column) {
            $value = trim((string) ($contact[$column] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return 'Contact #' . (int) ($contact['id'] ?? 0);
    }

    /** @return list<array<string,mixed>> */
    private function owners(): array
    {
        return DB::all(
            'SELECT id, name FROM {users} WHERE deleted_at IS NULL AND is_active = 1 ORDER BY name'
        );
    }

    /** @return list<array<string,mixed>> */
    private function households(): array
    {
        return DB::all(
            'SELECT id, name FROM {households} WHERE deleted_at IS NULL ORDER BY name LIMIT 500'
        );
    }
}
