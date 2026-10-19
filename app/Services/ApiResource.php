<?php

declare(strict_types=1);

namespace App\Services;

/**
 * What the REST API exposes, as data.
 *
 * One entry per resource: its table, the permission that reads it, the
 * permission that writes it, the owner column its scope keys off, and —
 * the important one — **the exact list of fields that leave the building**.
 *
 * THE FIELD LIST IS AN ALLOWLIST AND THAT IS THE WHOLE POINT. The obvious
 * implementation is `SELECT *` and `json_encode($row)`, and it works
 * perfectly until somebody adds a column. Then the API quietly starts
 * publishing it: a password hash, an internal note, a PIN, the reset token
 * that was added for a feature nobody connected to this file. Every column
 * here was chosen; a new one has to be chosen too.
 *
 * The same list decides what can be WRITTEN (`writable`), separately,
 * because the two are not the same set. `visit_count` is readable and
 * derived — an API that let a caller set it would put a number on the page
 * that no visit supports. `email_consent` is neither readable-only nor
 * freely writable: it needs its evidence, which is why it is not in any
 * `writable` list and has its own endpoint.
 *
 * **AND `delete` IS ITS OWN PERMISSION, NOT `write`.** This application
 * separates them everywhere a page does — editing a contact is
 * `contacts_manage`, deleting one is `contacts_delete`; editing a booking is
 * `bookings_manage`, cancelling one is `bookings_cancel` — because the roles
 * an operator actually builds give the front desk the first and not the
 * second. An API that deleted under the write permission would hand every
 * front-desk token a power its own pages refuse it, which is a worse hole
 * than a missing endpoint: it is an endpoint nobody knew was open. `null`
 * means this resource cannot be deleted through the API at all.
 *
 * This class holds no I/O and no request handling, so the shapes can be
 * tested without a database or a server.
 */
final class ApiResource
{
    /** The most rows one page can return, whatever the caller asks for. */
    public const MAX_PER_PAGE = 100;

    public const DEFAULT_PER_PAGE = 25;

    /**
     * @var array<string,array{
     *     table:string, read:string, write:?string, delete:?string, owner:?string,
     *     fields:list<string>, writable:list<string>,
     *     money:list<string>, dates:list<string>,
     *     soft_delete:bool, search:list<string>, sort:string
     * }>
     */
    public const RESOURCES = [
        'contacts' => [
            'table'    => 'contacts',
            'read'     => 'view_contacts',
            'write'    => 'contacts_manage',
            'delete'   => 'contacts_delete',
            'owner'    => 'owner_id',
            'fields'   => [
                'id', 'first_name', 'last_name', 'email', 'phone', 'company_id',
                'household_id', 'lifecycle_stage', 'source', 'owner_id',
                'address_1', 'address_2', 'city', 'state', 'postal_code', 'birth_date',
                'email_consent', 'sms_consent', 'do_not_contact', 'marketing_opt_out_at',
                'alt_phone', 'country', 'source_detail', 'external_pos_id',
                'first_visit_at', 'last_visit_at', 'visit_count',
                'lifetime_value_cents', 'avg_spend_cents',
                'created_at', 'updated_at',
            ],
            // No consent flags: setting one needs the evidence with it, which
            // is a different endpoint. No derived figures either — a caller
            // writing `visit_count` would put a number on the page that no
            // visit supports, and the next recalculation would silently
            // contradict them.
            'writable' => [
                'first_name', 'last_name', 'email', 'phone', 'company_id',
                'household_id', 'lifecycle_stage', 'source', 'owner_id',
                'address_1', 'address_2', 'city', 'state', 'postal_code', 'birth_date',
                'alt_phone', 'country', 'source_detail', 'external_pos_id',
            ],
            // Exact-match filters, `?external_pos_id=4021`. The POS bridge
            // finds a guest by their CenterEdge customer number; without this
            // it had to page the whole guest list to learn one id.
            'exact'    => ['external_pos_id', 'email'],
            'money'       => ['lifetime_value_cents', 'avg_spend_cents'],
            'dates'       => ['birth_date'],
            'soft_delete' => true,
            'search'      => ['first_name', 'last_name', 'email', 'phone'],
            'sort'        => 'id',
        ],

        'households' => [
            'table'       => 'households',
            'read'        => 'view_contacts',
            'write'       => 'contacts_manage',
            // No soft-delete column, so there is nothing to delete through.
            'delete'      => null,
            'owner'       => null,
            'fields'      => ['id', 'name', 'primary_contact_id', 'city', 'state',
                              'postal_code', 'notes', 'created_at', 'updated_at'],
            'writable'    => ['name', 'primary_contact_id', 'city', 'state',
                              'postal_code', 'notes'],
            'money'       => [],
            'dates'       => [],
            'soft_delete' => false,
            'search'      => ['name'],
            'sort'        => 'id',
        ],

        'companies' => [
            'table'       => 'companies',
            'read'        => 'view_contacts',
            'write'       => 'contacts_manage',
            // A school or a corporate client is a contact record of the
            // organisation kind, and the catalogue has one delete permission
            // for that family rather than a separate `companies_delete`
            // nothing would grant.
            'delete'      => 'contacts_delete',
            'owner'       => 'owner_id',
            'fields'      => ['id', 'name', 'type', 'district', 'website', 'phone',
                              'city', 'state', 'postal_code', 'owner_id',
                              'annual_potential_cents', 'is_recurring_group',
                              'last_event_at', 'notes', 'created_at', 'updated_at'],
            'writable'    => ['name', 'type', 'district', 'website', 'phone',
                              'city', 'state', 'postal_code', 'owner_id', 'notes'],
            'money'       => ['annual_potential_cents'],
            'dates'       => [],
            'soft_delete' => true,
            'search'      => ['name'],
            'sort'        => 'id',
        ],

        'deals' => [
            'table'    => 'deals',
            'read'     => 'view_deals',
            'write'    => 'deals_manage',
            'delete'   => 'deals_delete',
            'owner'    => 'owner_id',
            'fields'   => [
                'id', 'pipeline_id', 'stage_id', 'title', 'contact_id', 'company_id',
                'owner_id', 'status', 'value_cents', 'source', 'event_date',
                'guest_count', 'lost_reason_id', 'next_action', 'next_action_at',
                'won_at', 'lost_at', 'created_at', 'updated_at',
            ],
            /*
             * `status` is absent deliberately: won and lost are transitions
             * that stamp their own timestamps and fire events, so they are
             * their own endpoints rather than a column somebody sets.
             *
             * AND SO IS `stage_id`, for exactly the same reason — it was in
             * this list, and that was the bug. A stage is not a column, it is
             * a MOVE: entering one stamps `stage_entered_at` (which is what
             * the velocity report measures), sets or clears `won_at`/`lost_at`
             * and `status`, computes the SLA deadline, writes the
             * stage-history row the pipeline report reads, and fires
             * `deal.stage_changed` plus `deal.won`/`deal.lost` — which is
             * what starts the deposit chase and the thank-you. A PATCH that
             * set the column did none of it: the board showed the deal in
             * "Won" with no won date, no history, and no automation ever
             * running. `POST /api/v1/deals/{id}/stage` goes through
             * `DealStage::move()`, which is the same path the board uses.
             *
             * `pipeline_id` stays writable: moving a deal between boards is a
             * plain reassignment, and `DealStage::move()` refuses a stage
             * from another pipeline, so the two cannot disagree.
             */
            'writable' => [
                'pipeline_id', 'title', 'contact_id', 'company_id',
                'owner_id', 'value_cents', 'source', 'event_date', 'guest_count',
                'next_action', 'next_action_at',
            ],
            'money'       => ['value_cents'],
            'dates'       => ['event_date'],
            'soft_delete' => true,
            'search'      => ['title'],
            'sort'        => 'id',
        ],

        'bookings' => [
            'table'    => 'bookings',
            'read'     => 'view_bookings',
            'write'    => 'bookings_manage',
            // Taking a party off the calendar is `bookings_cancel` on every
            // page that does it, and the front-desk role holds
            // `bookings_manage` without it on purpose.
            'delete'   => 'bookings_cancel',
            'owner'    => 'host_user_id',
            'fields'   => [
                'id', 'booking_number', 'deal_id', 'contact_id', 'company_id',
                'type', 'package_id', 'status', 'event_date', 'start_time', 'end_time',
                'guest_count', 'adult_count', 'child_count',
                'guest_of_honor_name', 'guest_of_honor_age',
                'subtotal_cents', 'discount_cents', 'tax_cents', 'total_cents',
                'deposit_cents', 'balance_due_cents', 'host_user_id',
                'special_requests', 'allergies', 'dietary_notes',
                'source', 'created_at', 'updated_at',
            ],
            // `payment_reference` is readable by nobody through this API and
            // writable by nobody: it is an opaque processor reference, it has
            // no use outside the application, and publishing it is a risk
            // with no matching benefit.
            'writable' => [
                'contact_id', 'company_id', 'type', 'package_id', 'event_date',
                'start_time', 'end_time', 'guest_count', 'adult_count', 'child_count',
                'guest_of_honor_name', 'guest_of_honor_age', 'host_user_id',
                'special_requests', 'allergies', 'dietary_notes', 'source',
            ],
            'money'       => ['subtotal_cents', 'discount_cents', 'tax_cents', 'total_cents',
                              'deposit_cents', 'balance_due_cents'],
            'dates'       => ['event_date'],
            'soft_delete' => true,
            'search'      => ['booking_number', 'guest_of_honor_name'],
            'sort'        => 'id',
        ],

        'visits' => [
            'table'       => 'visits',
            'read'        => 'view_contacts',
            'write'       => 'contacts_manage',
            // A visit is a recorded fact, not a row to tidy up: deleting one
            // would silently change a guest's visit count and lifetime value.
            'delete'      => null,
            'owner'       => null,
            'fields'      => ['id', 'contact_id', 'household_id', 'visit_date', 'source',
                              'spend_cents', 'party_size', 'booking_id', 'external_ref',
                              'created_at'],
            'exact'       => ['contact_id', 'external_ref', 'visit_date'],
            // Writing a visit through the API is deliberately NOT a plain
            // insert — it has to go through VisitRecorder so the one-visit-
            // per-guest-per-day rule, the derived figures and the
            // visit.recorded event all happen. So nothing is writable here
            // and POST /visits has its own handler.
            'writable'    => [],
            'money'       => ['spend_cents'],
            'dates'       => ['visit_date'],
            'soft_delete' => false,
            'search'      => [],
            'sort'        => 'id',
        ],

        'memberships' => [
            'table'       => 'memberships',
            'read'        => 'view_memberships',
            'write'       => null,
            'delete'      => null,
            'owner'       => null,
            'fields'      => ['id', 'member_number', 'plan_id', 'contact_id',
                              'household_id', 'status', 'started_at', 'expires_at',
                              'auto_renew', 'price_paid_cents', 'visits_used',
                              'last_used_at', 'cancelled_at', 'created_at', 'updated_at'],
            // Read-only: every change to a membership is a lifecycle
            // transition with its own rules (freezing gives time back,
            // renewing starts from the current end date), and a PATCH that
            // set `expires_at` directly would route round all of them.
            'writable'    => [],
            'money'       => ['price_paid_cents'],
            'dates'       => ['started_at', 'expires_at'],
            'soft_delete' => false,
            'search'      => ['member_number'],
            'sort'        => 'id',
        ],

        'tasks' => [
            'table'       => 'tasks',
            'read'        => 'view_tasks',
            // `inbox_assign` is what the Tasks pages themselves check — there
            // is no `tasks_manage`, and naming one here would have been an
            // UNLOCKED door rather than a locked one: `Acl::can()` on an
            // unknown permission is false for a role and TRUE for an owner.
            'write'       => 'inbox_assign',
            'delete'      => null,
            'owner'       => 'assigned_to',
            'fields'      => ['id', 'title', 'description', 'assigned_to', 'created_by',
                              'entity_type', 'entity_id', 'due_at', 'completed_at',
                              'priority', 'status', 'created_at', 'updated_at'],
            'writable'    => ['title', 'description', 'assigned_to', 'entity_type',
                              'entity_id', 'due_at', 'priority', 'status'],
            'money'       => [],
            'dates'       => [],
            'soft_delete' => false,
            'search'      => ['title'],
            'sort'        => 'id',
        ],

        'messages' => [
            'table'       => 'messages',
            'read'        => 'view_inbox',
            'write'       => null,
            'delete'      => null,
            'owner'       => null,
            // No `body` and no `body_text`. A listing endpoint that returns
            // the text of every message a venue has ever sent or received is
            // a bulk export of guest correspondence behind a pagination
            // parameter; the single-message endpoint returns it, where it is
            // one deliberate read of one conversation.
            'fields'      => ['id', 'conversation_id', 'contact_id', 'direction', 'channel',
                              'subject', 'status', 'cost_cents', 'created_at'],
            'writable'    => [],
            'money'       => ['cost_cents'],
            'dates'       => [],
            'soft_delete' => false,
            'search'      => [],
            'sort'        => 'id',
        ],

        'conversations' => [
            'table'       => 'conversations',
            'read'        => 'view_inbox',
            'write'       => null,
            'delete'      => null,
            'owner'       => 'assigned_to',
            'fields'      => ['id', 'contact_id', 'channel', 'subject', 'status',
                              'assigned_to', 'last_message_at', 'snoozed_until',
                              'created_at', 'updated_at'],
            'writable'    => [],
            'money'       => [],
            'dates'       => [],
            'soft_delete' => false,
            'search'      => ['subject'],
            'sort'        => 'id',
        ],

        'form-submissions' => [
            'table'       => 'form_submissions',
            'read'        => 'view_marketing',
            'write'       => null,
            'delete'      => null,
            'owner'       => null,
            // No `data`, `ip` or `user_agent`. The answers are the guest's own
            // words and the other two are their network identity — a listing
            // endpoint that returns all three is a bulk export of personal
            // data behind a pagination parameter.
            'fields'      => ['id', 'form_id', 'contact_id', 'deal_id',
                              'is_spam', 'created_at'],
            'writable'    => [],
            'money'       => [],
            'dates'       => [],
            'soft_delete' => false,
            'search'      => [],
            'sort'        => 'id',
        ],

        'reviews' => [
            'table'       => 'reviews',
            'read'        => 'view_reputation',
            'write'       => null,
            'delete'      => null,
            'owner'       => null,
            'fields'      => ['id', 'platform', 'external_id', 'author_name', 'rating',
                              'body', 'review_url', 'replied_at', 'sentiment',
                              'is_featured', 'posted_at', 'imported_at'],
            'writable'    => [],
            'money'       => [],
            'dates'       => [],
            'soft_delete' => false,
            'search'      => ['author_name'],
            'sort'        => 'id',
        ],
    ];

    public static function exists(string $name): bool
    {
        return isset(self::RESOURCES[$name]);
    }

    /** @return array<string,mixed>|null */
    public static function get(string $name): ?array
    {
        return self::RESOURCES[$name] ?? null;
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::RESOURCES);
    }

    /**
     * Shape one row for the wire.
     *
     * Everything is driven by the allowlist: a column the definition does not
     * name cannot appear however it got into the row, which is what makes a
     * `SELECT *` upstream safe rather than a leak waiting for the next
     * migration.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $definition
     * @return array<string,mixed>
     */
    public static function present(array $row, array $definition, bool $withMoney = true): array
    {
        $out = [];

        foreach ($definition['fields'] as $field) {
            if (!array_key_exists($field, $row)) {
                continue;
            }

            $value = $row[$field];

            if (in_array($field, $definition['money'], true)) {
                // Scrubbed to NULL rather than omitted for a caller without
                // `view_revenue`: a key that vanishes reads as "this record
                // has no total", where null reads as "you were not shown
                // one". The two mean different things to an integration.
                $out[$field] = $withMoney ? self::int($value) : null;

                continue;
            }

            $out[$field] = self::scalar($field, $value);
        }

        return $out;
    }

    /**
     * Keep only what a caller may write, and refuse the rest LOUDLY.
     *
     * Unknown and read-only fields are reported rather than dropped. Dropping
     * them silently is how an integration spends a morning wondering why its
     * PATCH has no effect: the request succeeded, the response looked right,
     * and the field it cared about was never written.
     *
     * **AND A FIELD THAT NAMES ANOTHER RECORD HAS TO BE AN ID.** This filtered
     * WHICH keys could be written and never what shape they were, which is a
     * different job and the one that was missing. Three separate bugs were
     * found and fixed one at a time — a stage named by `true` becoming stage
     * 1, a pipeline named by `null` relocating a deal to the first board, a
     * booking named by `true` filing a guest's visit against somebody else's
     * party — before it was clear they were all the same bug: PHP casts
     * `true` to 1 and `"abc"` to 0, so **any** `*_id` field in any resource
     * quietly resolved to "record 1" or "no record" and answered 200.
     *
     * So it is checked here, once, for every resource and every field anybody
     * adds later: `*_id` must be a positive integer (or a digit string, which
     * plenty of JSON clients send), or null where the column allows it. The
     * caller is told which field, not left to find the wrong row later.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $definition
     * @return array{data:array<string,mixed>,refused:list<string>,invalid:array<string,string>}
     */
    public static function writable(array $input, array $definition): array
    {
        $data    = [];
        $refused = [];
        $invalid = [];

        foreach ($input as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (!in_array($key, $definition['writable'], true)) {
                $refused[] = $key;

                continue;
            }

            if (self::namesARecord($key)) {
                $problem = self::idProblem($value);

                if ($problem !== null) {
                    $invalid[$key] = $problem;

                    continue;
                }

                $data[$key] = $value === null ? null : (int) trim((string) $value);

                continue;
            }

            $data[$key] = $value;
        }

        return ['data' => $data, 'refused' => $refused, 'invalid' => $invalid];
    }

    /**
     * Does this field name another record?
     *
     * By name, deliberately: every foreign key in this schema ends in `_id`,
     * and a rule that reads the schema instead would stop protecting a field
     * the moment somebody added one. `external_ref` and `external_pos_id` are
     * NOT record references — they are a foreign system's own string, which
     * may be anything at all.
     */
    public static function namesARecord(string $field): bool
    {
        if (in_array($field, ['external_pos_id', 'external_ref'], true)) {
            return false;
        }

        return str_ends_with($field, '_id');
    }

    /**
     * Why this value is not the id of a record, or null when it is one.
     *
     * A digit string counts: a JSON client that quotes its numbers is common
     * and harmless. `true`, `false`, `0`, a negative, a float, an array and a
     * word do not, and each of them used to mean something.
     */
    public static function idProblem(mixed $value): ?string
    {
        if ($value === null) {
            // Clearing a link is a real thing to want — "this deal is no
            // longer attached to a company". The column decides whether it is
            // allowed, and the database refuses it if not.
            return null;
        }

        if (is_bool($value)) {
            return 'true and false are not record ids';
        }

        if (is_int($value)) {
            return $value > 0 ? null : 'a record id is a positive number';
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed === '') {
                return 'empty — leave the field out, or send null to clear it';
            }

            if (!ctype_digit($trimmed)) {
                return 'not a number';
            }

            return (int) $trimmed > 0 ? null : 'a record id is a positive number';
        }

        return 'not a number';
    }

    /**
     * An opaque cursor from a row id.
     *
     * Base64 of the id, which is NOT secrecy — anybody can decode it and it
     * does not matter. It is a promise about the shape: a caller that treats
     * the cursor as opaque keeps working when this becomes a composite of id
     * and sort key, where one that parsed an integer out of it would break.
     */
    public static function encodeCursor(int $id): string
    {
        return rtrim(strtr(base64_encode((string) $id), '+/', '-_'), '=');
    }

    public static function decodeCursor(?string $cursor): ?int
    {
        if ($cursor === null || trim($cursor) === '') {
            return null;
        }

        $decoded = base64_decode(strtr(trim($cursor), '-_', '+/'), true);

        if ($decoded === false || preg_match('/^\d{1,19}$/', $decoded) !== 1) {
            return null;
        }

        return (int) $decoded;
    }

    /** Bounded, so `?per_page=100000` is a page of 100 rather than an outage. */
    public static function perPage(mixed $requested): int
    {
        $n = is_numeric($requested) ? (int) $requested : self::DEFAULT_PER_PAGE;

        return max(1, min(self::MAX_PER_PAGE, $n));
    }

    private static function int(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    /**
     * One value, typed the way the wire wants it.
     *
     * Integers stay integers and timestamps become ISO 8601 with an explicit
     * zone. "2026-10-16 14:03:11" with no zone is read as local time by most
     * client libraries, which puts every timestamp four or five hours out at
     * this venue — and consistently enough that nobody notices for a while.
     */
    private static function scalar(string $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        // A field naming a record here is an integer. A foreign system's own
        // identifier is NOT, though it ends in `_id` too: cast, a CenterEdge
        // customer number like "CE-4021" came back as 0.
        if ($field === 'id' || self::namesARecord($field)) {
            return (int) $value;
        }

        if (str_ends_with($field, '_count') || str_ends_with($field, '_age')) {
            return (int) $value;
        }

        if (in_array($field, ['email_consent', 'sms_consent', 'do_not_contact', 'is_featured'], true)) {
            return (bool) $value;
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1) {
            return str_replace(' ', 'T', $value) . 'Z';
        }

        return $value;
    }
}
