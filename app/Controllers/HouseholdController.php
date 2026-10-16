<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Acl;
use App\Core\Audit;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;

/**
 * Households — the family a guest belongs to.
 *
 * A household is the unit the venue actually sells to: one address, one parent
 * paying, several children with birthdays. Keeping it as its own record is
 * what lets a birthday campaign address the guardian rather than the child
 * (docs/PRIVACY.md §1), and what stops four siblings reading as four
 * unconnected guests.
 *
 * Membership lives on contacts.household_id rather than a join table — a
 * person belongs to one household — so adding and removing a member is an
 * update to the contact, and both directions are audited against the contact
 * as well as the household.
 */
final class HouseholdController extends Controller
{
    public function index(Request $request): Response
    {
        ['page' => $page, 'perPage' => $perPage, 'offset' => $offset] = $this->pagination($request, 25);

        $search  = trim($request->str('q'));
        $clauses = ['h.deleted_at IS NULL'];
        $params  = [];

        if ($search !== '') {
            // One placeholder per occurrence: with ATTR_EMULATE_PREPARES off,
            // MySQL prepares the statement and a name reused three times is
            // HY093, not a bound value. See ContactController::filters().
            $ors  = [];
            $term = '%' . addcslashes($search, '%_\\') . '%';

            foreach (['h.name', 'h.city', 'h.postal_code'] as $index => $column) {
                $key = 'like' . $index;
                $params[$key] = $term;
                $ors[] = $column . ' LIKE :' . $key;
            }

            $clauses[] = '(' . implode(' OR ', $ors) . ')';
        }

        $where = implode(' AND ', $clauses);

        $total = (int) DB::value(
            'SELECT COUNT(*) FROM {households} h WHERE ' . $where,
            $params
        );

        // The member count is scoped exactly as the member LIST is, so this
        // page can never say "4 members" above a detail page showing one.
        //
        // Its bindings are merged into the ROW query alone: a parameter bound
        // against a statement that does not mention it is an HY093 error, not
        // a harmless extra, and the count query above has no member subquery.
        [$memberScope, $memberParams] = Acl::scopeCondition('view_contacts', 'c.owner_id');

        // A correlated subquery rather than a GROUP BY, so a household with no
        // members still appears — which is the state every household is in for
        // the minute between creating it and filling it.
        $rows = DB::all(
            'SELECT h.id, h.name, h.city, h.state, h.postal_code,
                    (SELECT COUNT(*) FROM {contacts} c
                      WHERE c.household_id = h.id AND c.deleted_at IS NULL
                        AND ' . $memberScope . ') AS member_count,
                    p.first_name AS primary_first, p.last_name AS primary_last
               FROM {households} h
               LEFT JOIN {contacts} p ON p.id = h.primary_contact_id AND p.deleted_at IS NULL
              WHERE ' . $where . '
              ORDER BY h.name, h.id DESC
              LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
            array_merge($params, $memberParams)
        );

        return $this->view('households/index', [
            'pageTitle' => 'Households',
            'rows'      => $rows,
            'total'     => $total,
            'page'      => $page,
            'perPage'   => $perPage,
            'lastPage'  => max(1, (int) ceil($total / $perPage)),
            'filters'   => ['q' => $search],
            'canManage' => Acl::can('contacts_manage'),
        ]);
    }

    public function show(Request $request): Response
    {
        $household = $this->find($request->routeInt('id'));

        if ($household === null) {
            return $this->notFound('That household does not exist, or it was deleted.');
        }

        $id = (int) $household['id'];

        return $this->view('households/show', [
            'pageTitle' => $household['name'],
            'household' => $household,
            'members'   => $this->members($id),
            'children'  => DB::all(
                'SELECT id, first_name, birth_date, allergies, contact_id
                   FROM {children}
                  WHERE household_id = :id AND deleted_at IS NULL
                  ORDER BY birth_date',
                ['id' => $id]
            ),
            // Only contacts the viewer may see are offered as candidates, so
            // the picker cannot be used to enumerate guests outside the
            // viewer's scope.
            'candidates' => $this->candidates($id),
            'canManage'  => Acl::can('contacts_manage'),
        ]);
    }

    public function create(Request $request): Response
    {
        if ($denied = $this->authorize('contacts_manage')) {
            return $denied;
        }

        return $this->view('households/form', [
            'pageTitle' => 'Add a household',
            'household' => null,
            'members'   => [],
        ]);
    }

    public function store(Request $request): Response
    {
        if ($denied = $this->authorize('contacts_manage')) {
            return $denied;
        }

        $data = $this->validateHousehold($request);

        if ($data instanceof Response) {
            return $data;
        }

        $row = $this->columnsFrom($data);

        if ($guard = $this->guardName($row, $request, '/households/new')) {
            return $guard;
        }

        $row['created_at'] = now_utc();
        $row['updated_at'] = now_utc();

        $id = DB::insert('households', $row);

        Audit::record('household.created', 'households', $id, null, $row);

        return $this->success($request, 'Household saved.', '/households/' . $id);
    }

    public function edit(Request $request): Response
    {
        if ($denied = $this->authorize('contacts_manage')) {
            return $denied;
        }

        $household = $this->find($request->routeInt('id'));

        if ($household === null) {
            return $this->notFound('That household does not exist, or it was deleted.');
        }

        return $this->view('households/form', [
            'pageTitle' => 'Edit ' . $household['name'],
            'household' => $household,
            'members'   => $this->members((int) $household['id']),
        ]);
    }

    public function update(Request $request): Response
    {
        if ($denied = $this->authorize('contacts_manage')) {
            return $denied;
        }

        $id        = $request->routeInt('id');
        $household = $this->find($id);

        if ($household === null) {
            return $this->notFound('That household does not exist, or it was deleted.');
        }

        $data = $this->validateHousehold($request);

        if ($data instanceof Response) {
            return $data;
        }

        $row = $this->columnsFrom($data);

        if ($guard = $this->guardName($row, $request, '/households/' . $id . '/edit')) {
            return $guard;
        }

        // A primary contact who is not in the household would render as a
        // member who is not listed among the members.
        if ($row['primary_contact_id'] !== null) {
            $isMember = DB::exists(
                'SELECT id FROM {contacts}
                  WHERE id = :cid AND household_id = :hid AND deleted_at IS NULL',
                ['cid' => $row['primary_contact_id'], 'hid' => $id]
            );

            if (!$isMember) {
                return $this->refuse(
                    $request,
                    'The main contact has to be a member of this household. Add them first.',
                    '/households/' . $id . '/edit'
                );
            }
        }

        $row['updated_at'] = now_utc();

        DB::update('households', $row, 'id = :id', ['id' => $id]);

        Audit::record('household.updated', 'households', $id, $household, $row);

        return $this->success($request, 'Household updated.', '/households/' . $id);
    }

    // -----------------------------------------------------------------
    // Membership
    // -----------------------------------------------------------------

    public function addMember(Request $request): Response
    {
        if ($denied = $this->authorize('contacts_manage')) {
            return $denied;
        }

        $id        = $request->routeInt('id');
        $household = $this->find($id);

        if ($household === null) {
            return $this->notFound('That household does not exist, or it was deleted.');
        }

        $data = $this->validate($request, [
            'contact_id' => 'required|int|exists:contacts',
        ], ['contact_id' => 'Contact']);

        if ($data instanceof Response) {
            return $data;
        }

        $contact = $this->visibleContact((int) $data['contact_id']);

        if ($contact === null) {
            return $this->notFound('That contact does not exist, or it was deleted.');
        }

        if ((int) ($contact['household_id'] ?? 0) === $id) {
            return $this->refuse($request, 'They are already in this household.', '/households/' . $id);
        }

        $contactId = (int) $contact['id'];
        $previous  = (int) ($contact['household_id'] ?? 0);

        DB::transaction(static function () use ($id, $contactId, $previous, $contact): void {
            DB::update('contacts', [
                'household_id' => $id,
                'updated_at'   => now_utc(),
            ], 'id = :id', ['id' => $contactId]);

            // Joining this household means leaving the last one, so the same
            // rule applies as in removeMember(): a household must not go on
            // naming a main contact who has moved out. This is the third of
            // the three paths that change household_id — the others are
            // removeMember() below and ContactController::update().
            if ($previous > 0) {
                DB::update('households', [
                    'primary_contact_id' => null,
                    'updated_at'         => now_utc(),
                ], 'id = :hid AND primary_contact_id = :cid', ['hid' => $previous, 'cid' => $contactId]);
            }

            // Audited against the CONTACT, because that is the row that
            // changed — "who moved this guest into that household" is the
            // question the log has to answer.
            Audit::record('contact.household_set', 'contacts', $contactId, [
                'household_id' => $contact['household_id'],
            ], ['household_id' => $id]);
        });

        return $this->success($request, 'Added to the household.', '/households/' . $id);
    }

    public function removeMember(Request $request): Response
    {
        if ($denied = $this->authorize('contacts_manage')) {
            return $denied;
        }

        $id        = $request->routeInt('id');
        $household = $this->find($id);

        if ($household === null) {
            return $this->notFound('That household does not exist, or it was deleted.');
        }

        $data = $this->validate($request, [
            'contact_id' => 'required|int',
        ], ['contact_id' => 'Contact']);

        if ($data instanceof Response) {
            return $data;
        }

        $contactId = (int) $data['contact_id'];
        $contact   = $this->visibleContact($contactId);

        // Only a current member can be removed. Without this check the action
        // would "succeed" against any id at all and report that it had done
        // something.
        if ($contact === null || (int) ($contact['household_id'] ?? 0) !== $id) {
            return $this->refuse($request, 'They are not in this household.', '/households/' . $id);
        }

        DB::transaction(static function () use ($id, $contact, $contactId, $household): void {
            DB::update('contacts', [
                'household_id' => null,
                'updated_at'   => now_utc(),
            ], 'id = :id', ['id' => $contactId]);

            // Leaving a departed member as the household's main contact is the
            // bug this prevents: the household page would keep showing them as
            // its primary while they are no longer a member of it.
            if ((int) ($household['primary_contact_id'] ?? 0) === $contactId) {
                DB::update('households', [
                    'primary_contact_id' => null,
                    'updated_at'         => now_utc(),
                ], 'id = :id', ['id' => $id]);

                Audit::record('household.updated', 'households', $id, $household, [
                    'primary_contact_id' => null,
                ]);
            }

            Audit::record('contact.household_cleared', 'contacts', $contactId, [
                'household_id' => $contact['household_id'],
            ], ['household_id' => null]);
        });

        return $this->success($request, 'Removed from the household.', '/households/' . $id);
    }

    // -----------------------------------------------------------------
    // Shared
    // -----------------------------------------------------------------

    /** @return array<string,mixed>|null */
    private function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        return DB::first(
            'SELECT * FROM {households} WHERE id = :id AND deleted_at IS NULL',
            ['id' => $id]
        );
    }

    /**
     * A contact the current user may see, under the same scope the contact
     * list uses.
     *
     * @return array<string,mixed>|null
     */
    private function visibleContact(int $id): ?array
    {
        [$scope, $params] = Acl::scopeCondition('view_contacts', 'c.owner_id');
        $params['id'] = $id;

        return DB::first(
            'SELECT c.id, c.household_id, c.first_name, c.last_name
               FROM {contacts} c
              WHERE c.id = :id AND c.deleted_at IS NULL AND ' . $scope,
            $params
        );
    }

    /** @return list<array<string,mixed>> */
    private function members(int $householdId): array
    {
        [$scope, $params] = Acl::scopeCondition('view_contacts', 'c.owner_id');
        $params['hid'] = $householdId;

        return DB::all(
            'SELECT c.id, c.first_name, c.last_name, c.email, c.phone_normalized,
                    c.lifecycle_stage, c.birth_date, c.do_not_contact
               FROM {contacts} c
              WHERE c.household_id = :hid AND c.deleted_at IS NULL AND ' . $scope . '
              ORDER BY c.last_name, c.first_name',
            $params
        );
    }

    /**
     * Contacts that could be added: visible, and not already in a household.
     *
     * Bounded, because this fills a <select> — a venue with 40,000 guests must
     * not render all of them. Somebody not in the list is moved from their own
     * record instead.
     *
     * @return list<array<string,mixed>>
     */
    private function candidates(int $householdId): array
    {
        [$scope, $params] = Acl::scopeCondition('view_contacts', 'c.owner_id');
        $params['hid'] = $householdId;

        return DB::all(
            'SELECT c.id, c.first_name, c.last_name, c.email
               FROM {contacts} c
              WHERE c.deleted_at IS NULL
                AND (c.household_id IS NULL OR c.household_id <> :hid)
                AND ' . $scope . '
              ORDER BY c.last_name, c.first_name
              LIMIT 200',
            $params
        );
    }

    /**
     * @return array<string,mixed>|Response
     */
    private function validateHousehold(Request $request): array|Response
    {
        return $this->validate($request, [
            'name'               => 'required|string|min:2|max:160',
            'address_1'          => 'nullable|string|max:160',
            'address_2'          => 'nullable|string|max:160',
            'city'               => 'nullable|string|max:80',
            'state'              => 'nullable|string|max:40',
            'postal_code'        => 'nullable|string|max:20',
            'notes'              => 'nullable|string|max:5000',
            'primary_contact_id' => 'nullable|int|exists:contacts',
        ], [
            'name'               => 'Household name',
            'primary_contact_id' => 'Main contact',
        ]);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function columnsFrom(array $data): array
    {
        $row = [];

        foreach ([
            'name', 'address_1', 'address_2', 'city', 'state', 'postal_code',
            'notes', 'primary_contact_id',
        ] as $column) {
            $value = $data[$column] ?? null;

            if (is_string($value)) {
                $value = trim($value);

                if ($value === '') {
                    $value = null;
                }
            }

            $row[$column] = $value;
        }

        if ($row['primary_contact_id'] !== null) {
            $row['primary_contact_id'] = (int) $row['primary_contact_id'];
        }

        return $row;
    }

    /**
     * A household needs a name staff can recognise.
     *
     * `required|min:2` is not quite enough on its own: the validator measures
     * the length BEFORE trimming, so "   " passes it and then trims to
     * nothing. Falling back to a generic name here was the first version, and
     * it is worse than refusing — it creates a record called "Household" that
     * nobody can find or tell apart from the next one.
     *
     * @param array<string,mixed> $row
     */
    private function guardName(array $row, Request $request, string $fallback): ?Response
    {
        if (($row['name'] ?? null) !== null) {
            return null;
        }

        return $this->refuse($request, 'Give the household a name staff will recognise.', $fallback);
    }
}
