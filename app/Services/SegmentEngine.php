<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Rules in, parameterised SQL out.
 *
 * A segment is a saved question about the guest list — "customers who came in
 * the last ninety days, spent over two hundred dollars, and said yes to
 * email" — stored as JSON and compiled here into a WHERE clause. That makes
 * this class two things at once, and both are the reason it is pure and
 * separately tested.
 *
 * ## It is a security boundary
 *
 * Operator and field names come from an operator typing into an admin form.
 * Every one of them is checked against an ALLOWLIST and turned into a fixed
 * SQL fragment; every VALUE is bound. Nothing an operator types reaches the
 * statement text. `tests/unit/SegmentEngineTest.php` asserts that directly —
 * it compiles rules whose values are SQL and checks the generated statement
 * does not contain them.
 *
 * ## It decides who gets a message
 *
 * Which makes the failure mode asymmetric, and the asymmetry is the single
 * most important decision in this file:
 *
 * **A RULE THAT CANNOT BE COMPILED THROWS. IT IS NEVER DROPPED.**
 *
 * Dropping an unknown rule WIDENS the segment. "Customers who opted in" with
 * its consent rule quietly skipped is "everybody", and the venue finds out
 * when forty thousand people get an offer meant for two hundred. Refusing to
 * compile means a campaign cannot be sent against a segment nobody can
 * evaluate — which is an error message, not an apology to a mailing list.
 *
 * For the same reason **an empty rule set matches NOBODY.** "No conditions"
 * is not "no filter": a segment somebody created and has not finished
 * defining must not be the whole database. A venue that genuinely wants
 * everybody has a rule for it (`lifecycle_stage` is one of several).
 *
 * The consent rules here are for SELECTING people, not for permitting
 * contact. Permission is enforced in `Core\Mailer` and `Core\Sms`, every
 * time, for every recipient — a segment cannot grant it and a mis-built
 * segment cannot bypass it.
 */
final class SegmentEngine
{
    /** How deep a rule tree may nest. Beyond this it is a denial of service. */
    public const MAX_DEPTH = 5;

    /** How many rules one segment may carry, at all levels together. */
    public const MAX_RULES = 100;

    /**
     * The fields a rule may name, and how each is compiled.
     *
     * An allowlist, so a column that should never be a marketing filter —
     * `password_hash` on a joined table, `notes`, an internal id — cannot be
     * reached by typing its name. Each entry says:
     *
     *   type   — which operators apply and how the value is cast
     *   column — the SQL expression, already qualified. Fixed text, never
     *            built from input.
     *   label  — what the segment editor calls it
     *   values — for `enum`, the only values accepted
     *
     * @var array<string,array<string,mixed>>
     */
    public const FIELDS = [
        // --- who they are ------------------------------------------------
        'first_name'      => ['type' => 'string', 'column' => 'c.first_name', 'label' => 'First name'],
        'last_name'       => ['type' => 'string', 'column' => 'c.last_name', 'label' => 'Last name'],
        'email'           => ['type' => 'string', 'column' => 'c.email', 'label' => 'Email address'],
        'phone'           => ['type' => 'string', 'column' => 'c.phone_normalized', 'label' => 'Mobile number'],
        'city'            => ['type' => 'string', 'column' => 'c.city', 'label' => 'Town or city'],
        'state'           => ['type' => 'string', 'column' => 'c.state', 'label' => 'State'],
        'postal_code'     => ['type' => 'string', 'column' => 'c.postal_code', 'label' => 'Postcode'],
        'lifecycle_stage' => [
            'type'   => 'enum',
            'column' => 'c.lifecycle_stage',
            'label'  => 'Lifecycle stage',
            'values' => ['lead', 'prospect', 'customer', 'member', 'lapsed', 'archived'],
        ],
        'source'          => ['type' => 'string', 'column' => 'c.source', 'label' => 'How they arrived'],
        'owner_id'        => ['type' => 'int', 'column' => 'c.owner_id', 'label' => 'Owner'],

        // --- how they behave ---------------------------------------------
        'visit_count'          => ['type' => 'int', 'column' => 'c.visit_count', 'label' => 'Number of visits'],
        'lifetime_value_cents' => [
            'type'   => 'money',
            'column' => 'c.lifetime_value_cents',
            'label'  => 'Lifetime spend',
        ],
        'avg_spend_cents'      => ['type' => 'money', 'column' => 'c.avg_spend_cents', 'label' => 'Average spend'],
        'first_visit_at'       => ['type' => 'datetime', 'column' => 'c.first_visit_at', 'label' => 'First visit'],
        'last_visit_at'        => ['type' => 'datetime', 'column' => 'c.last_visit_at', 'label' => 'Last visit'],
        'last_contacted_at'    => ['type' => 'datetime', 'column' => 'c.last_contacted_at', 'label' => 'Last contacted'],
        'created_at'           => ['type' => 'datetime', 'column' => 'c.created_at', 'label' => 'Added to the CRM'],

        // --- dates that matter to a party venue ---------------------------
        'birth_date'  => ['type' => 'date', 'column' => 'c.birth_date', 'label' => 'Date of birth'],
        'birth_month' => ['type' => 'month', 'column' => 'MONTH(c.birth_date)', 'label' => 'Birth month'],

        // --- consent, for SELECTING on, never for permitting --------------
        'email_consent'  => ['type' => 'bool', 'column' => 'c.email_consent', 'label' => 'Said yes to email'],
        'sms_consent'    => ['type' => 'bool', 'column' => 'c.sms_consent', 'label' => 'Said yes to texts'],
        'do_not_contact' => ['type' => 'bool', 'column' => 'c.do_not_contact', 'label' => 'Do not contact'],

        // --- things that live in other tables -----------------------------
        'tag'               => ['type' => 'tag', 'column' => '', 'label' => 'Tag'],
        'has_booking'       => ['type' => 'booking', 'column' => '', 'label' => 'Party bookings'],
        'child_birth_month' => [
            'type'   => 'child_month',
            'column' => '',
            'label'  => 'A child\'s birth month',
        ],
        'membership_status' => [
            'type'   => 'membership',
            'column' => '',
            'label'  => 'Membership',
            // The memberships.status enum, verbatim. A value outside it
            // matches nothing, so getting this wrong produces an empty
            // segment that looks like a working one.
            'values' => ['pending', 'active', 'expiring', 'expired', 'cancelled', 'lapsed', 'frozen'],
        ],
        'campaign_opened'  => ['type' => 'engagement', 'column' => 'opened_at', 'label' => 'Opened a campaign'],
        'campaign_clicked' => ['type' => 'engagement', 'column' => 'clicked_at', 'label' => 'Clicked a campaign'],
    ];

    /**
     * Which operators each type accepts, and the SQL each becomes.
     *
     * `%s` is the field's own column expression — fixed text from FIELDS —
     * and `%p` is a bound placeholder. Nothing else is substituted.
     *
     * @var array<string,array<string,string>>
     */
    private const OPERATORS = [
        'string' => [
            'is'           => '%s = %p',
            'is_not'       => '(%s IS NULL OR %s <> %p)',
            'contains'     => '%s LIKE %p',
            'not_contains' => '(%s IS NULL OR %s NOT LIKE %p)',
            'starts_with'  => '%s LIKE %p',
            'ends_with'    => '%s LIKE %p',
            'is_set'       => "(%s IS NOT NULL AND %s <> '')",
            'is_not_set'   => "(%s IS NULL OR %s = '')",
        ],
        'int' => [
            'is'         => '%s = %p',
            'is_not'     => '(%s IS NULL OR %s <> %p)',
            'gt'         => '%s > %p',
            'gte'        => '%s >= %p',
            'lt'         => '%s < %p',
            'lte'        => '%s <= %p',
            'between'    => '%s BETWEEN %p AND %p',
            'is_set'     => '%s IS NOT NULL',
            'is_not_set' => '%s IS NULL',
        ],
        'date' => [
            'on'           => 'DATE(%s) = %p',
            'before'       => '%s < %p',
            'after'        => '%s > %p',
            'between'      => 'DATE(%s) BETWEEN %p AND %p',
            'in_last_days' => '%s >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %p DAY)',
            'in_next_days' => '%s BETWEEN UTC_TIMESTAMP() AND DATE_ADD(UTC_TIMESTAMP(), INTERVAL %p DAY)',
            'is_set'       => '%s IS NOT NULL',
            'is_not_set'   => '%s IS NULL',
        ],
        'bool' => [
            'is_true'  => '%s = 1',
            'is_false' => '(%s = 0 OR %s IS NULL)',
        ],
        'enum' => [
            'is'     => '%s = %p',
            'is_not' => '(%s IS NULL OR %s <> %p)',
        ],
        'month' => [
            'is'     => '%s = %p',
            'is_not' => '(%s IS NULL OR %s <> %p)',
        ],
    ];

    /** Operators that take no value at all. */
    private const VALUELESS = ['is_set', 'is_not_set', 'is_true', 'is_false'];

    /** Operators that take two. */
    private const PAIRED = ['between'];

    // =================================================================
    // Compiling
    // =================================================================

    /**
     * Compile a rule tree into a WHERE clause over `{contacts} c`.
     *
     * @param array<string,mixed> $rules
     * @return array{sql:string,params:array<string,mixed>,rules:int}
     *
     * @throws RuntimeException on anything it cannot compile — see the class
     *         docblock. A dropped rule widens the segment, and that is the
     *         direction that sends a message to the wrong forty thousand
     *         people.
     */
    public static function compile(array $rules): array
    {
        $state = ['n' => 0, 'params' => [], 'rules' => 0];
        $sql   = self::group($rules, $state, 1);

        // ALWAYS, regardless of what the operator asked for. A deleted guest
        // is not a recipient, and leaving this to the rules means one segment
        // somewhere eventually forgets it.
        $sql = 'c.deleted_at IS NULL AND (' . $sql . ')';

        return ['sql' => $sql, 'params' => $state['params'], 'rules' => $state['rules']];
    }

    /**
     * Would this compile? The editor's question, asked without throwing.
     *
     * @param array<string,mixed> $rules
     * @return array{ok:bool,error:string,rules:int}
     */
    public static function validate(array $rules): array
    {
        try {
            $compiled = self::compile($rules);

            return ['ok' => true, 'error' => '', 'rules' => $compiled['rules']];
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'rules' => 0];
        }
    }

    /**
     * One group: a match mode and a list of rules or nested groups.
     *
     * @param array<string,mixed>                               $group
     * @param array{n:int,params:array<string,mixed>,rules:int}  $state
     */
    private static function group(array $group, array &$state, int $depth): string
    {
        if ($depth > self::MAX_DEPTH) {
            throw new RuntimeException(
                'Those conditions are nested more than ' . self::MAX_DEPTH . ' deep. '
                    . 'Flatten some of the groups.'
            );
        }

        $mode = strtolower((string) ($group['match'] ?? 'all'));

        if (!in_array($mode, ['all', 'any'], true)) {
            throw new RuntimeException('A group must match "all" or "any", not "' . $mode . '".');
        }

        $rules = $group['rules'] ?? [];

        if (!is_array($rules) || $rules === []) {
            // AN EMPTY GROUP MATCHES NOBODY. "No conditions" is not "no
            // filter": a half-finished segment must not be the whole guest
            // list. See the class docblock.
            return '1 = 0';
        }

        $parts = [];

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                throw new RuntimeException('A condition must be an object.');
            }

            $parts[] = isset($rule['rules']) || isset($rule['match'])
                ? '(' . self::group($rule, $state, $depth + 1) . ')'
                : self::rule($rule, $state);
        }

        return implode($mode === 'all' ? ' AND ' : ' OR ', $parts);
    }

    /**
     * One rule.
     *
     * @param array<string,mixed>                              $rule
     * @param array{n:int,params:array<string,mixed>,rules:int} $state
     */
    private static function rule(array $rule, array &$state): string
    {
        $state['rules']++;

        if ($state['rules'] > self::MAX_RULES) {
            throw new RuntimeException(
                'That segment has more than ' . self::MAX_RULES . ' conditions. '
                    . 'Split it into several.'
            );
        }

        $field = (string) ($rule['field'] ?? '');

        if (!isset(self::FIELDS[$field])) {
            // NOT SKIPPED. An unknown field is usually a renamed one, and
            // skipping its rule turns "customers who opted in" into
            // "everybody".
            throw new RuntimeException('There is no field called "' . $field . '" to filter on.');
        }

        $definition = self::FIELDS[$field];
        $type       = (string) $definition['type'];
        $operator   = (string) ($rule['op'] ?? '');

        return match ($type) {
            'tag'         => self::tagRule($operator, $rule, $state),
            'booking'     => self::bookingRule($operator, $rule, $state),
            'child_month' => self::childMonthRule($operator, $rule, $state),
            'membership'  => self::membershipRule($operator, $rule, $state, $definition),
            'engagement'  => self::engagementRule($operator, $rule, $state, $definition),
            default       => self::columnRule($field, $definition, $operator, $rule, $state),
        };
    }

    /**
     * A rule over a column on `contacts`.
     *
     * @param array<string,mixed>                              $definition
     * @param array<string,mixed>                              $rule
     * @param array{n:int,params:array<string,mixed>,rules:int} $state
     */
    private static function columnRule(
        string $field,
        array $definition,
        string $operator,
        array $rule,
        array &$state
    ): string {
        $type = (string) $definition['type'];

        // `money` and `datetime` share their operator tables with `int` and
        // `date` — the difference is how the VALUE is read, not what can be
        // asked of it.
        $family = match ($type) {
            'money'    => 'int',
            'datetime' => 'date',
            default    => $type,
        };

        $table = self::OPERATORS[$family] ?? null;

        if ($table === null) {
            throw new RuntimeException('Nothing can be asked of "' . $field . '" yet.');
        }

        if (!isset($table[$operator])) {
            throw new RuntimeException(
                '"' . $operator . '" is not something you can ask of ' . $definition['label'] . '.'
            );
        }

        $column   = (string) $definition['column'];
        $template = $table[$operator];

        if (in_array($operator, self::VALUELESS, true)) {
            return self::fill($template, $column, []);
        }

        if (in_array($operator, self::PAIRED, true)) {
            $values = $rule['value'] ?? [];

            if (!is_array($values) || count($values) !== 2) {
                throw new RuntimeException(
                    $definition['label'] . ' between two values needs both of them.'
                );
            }

            return self::fill($template, $column, [
                self::bind($state, self::cast($type, $values[0], $definition)),
                self::bind($state, self::cast($type, $values[1], $definition)),
            ]);
        }

        // THE OPERATOR DECIDES THE VALUE'S TYPE TOO, not the field alone.
        // `last_visit_at` is a datetime, but "in the last 90 days" takes the
        // number 90 — reading it as a date refused every relative window on
        // every date field, which is most of what a segment is for. MEASURED
        // against the real database, where it threw "that condition needs a
        // date like 2026-07-04" for a perfectly ordinary rule.
        $value = in_array($operator, ['in_last_days', 'in_next_days'], true)
            ? self::positiveInt($rule['value'] ?? null, 'a number of days')
            : self::cast($type, $rule['value'] ?? null, $definition);

        // The three LIKE operators are the only place a value is decorated,
        // and the decoration goes on the BOUND value rather than into the
        // SQL — so a guest searching for 100% of something cannot turn their
        // own surname into a wildcard clause.
        $value = match ($operator) {
            'contains', 'not_contains' => '%' . self::escapeLike((string) $value) . '%',
            'starts_with'              => self::escapeLike((string) $value) . '%',
            'ends_with'                => '%' . self::escapeLike((string) $value),
            default                    => $value,
        };

        return self::fill($template, $column, [self::bind($state, $value)]);
    }

    /**
     * Put the column and the placeholders into a template.
     *
     * The column goes into every `%s` — several operators mention it twice,
     * because a NULL must not quietly satisfy a "is not" test. The
     * placeholders fill the `%p`s in order.
     *
     * @param list<string> $placeholders
     */
    private static function fill(string $template, string $column, array $placeholders): string
    {
        $out   = '';
        $index = 0;
        $length = strlen($template);

        for ($i = 0; $i < $length; $i++) {
            if ($template[$i] !== '%' || $i + 1 >= $length) {
                $out .= $template[$i];

                continue;
            }

            $next = $template[$i + 1];
            $i++;

            if ($next === 's') {
                $out .= $column;
            } elseif ($next === 'p') {
                if (!isset($placeholders[$index])) {
                    throw new RuntimeException('That condition is missing a value.');
                }

                $out .= $placeholders[$index];
                $index++;
            } else {
                $out .= '%' . $next;
            }
        }

        return $out;
    }

    // =================================================================
    // Rules that reach another table
    // =================================================================

    /**
     * @param array<string,mixed>                              $rule
     * @param array{n:int,params:array<string,mixed>,rules:int} $state
     */
    private static function tagRule(string $operator, array $rule, array &$state): string
    {
        if (!in_array($operator, ['has_tag', 'not_has_tag'], true)) {
            throw new RuntimeException('A tag can only be present or absent.');
        }

        $tag = trim((string) ($rule['value'] ?? ''));

        if ($tag === '') {
            throw new RuntimeException('Which tag?');
        }

        $placeholder = self::bind($state, $tag);

        // `taggables` is polymorphic, so the entity type is part of the
        // match: without it a tag put on a BOOKING whose id happens to equal
        // this contact's would read as a tag on the guest.
        $exists = 'EXISTS (SELECT 1 FROM {taggables} tg
                             JOIN {tags} t ON t.id = tg.tag_id
                            WHERE tg.entity_type = \'contact\'
                              AND tg.entity_id = c.id
                              AND t.name = ' . $placeholder . ')';

        return $operator === 'has_tag' ? $exists : 'NOT ' . $exists;
    }

    /**
     * Has this guest had a party, and when?
     *
     * CANCELLED BOOKINGS DO NOT COUNT. "Guests who have had a party" meaning
     * "guests who once enquired and cancelled" would put a thank-you offer in
     * front of somebody whose party never happened.
     *
     * @param array<string,mixed>                              $rule
     * @param array{n:int,params:array<string,mixed>,rules:int} $state
     */
    private static function bookingRule(string $operator, array $rule, array &$state): string
    {
        $live = "b.status NOT IN ('cancelled','no_show') AND b.deleted_at IS NULL";

        if ($operator === 'ever' || $operator === 'never') {
            $exists = 'EXISTS (SELECT 1 FROM {bookings} b WHERE b.contact_id = c.id AND ' . $live . ')';

            return $operator === 'ever' ? $exists : 'NOT ' . $exists;
        }

        if ($operator === 'in_last_days' || $operator === 'in_next_days') {
            $days = self::positiveInt($rule['value'] ?? null, 'a number of days');
            $slot = self::bind($state, $days);

            $window = $operator === 'in_last_days'
                ? 'b.event_date >= DATE_SUB(UTC_DATE(), INTERVAL ' . $slot . ' DAY) AND b.event_date <= UTC_DATE()'
                : 'b.event_date >= UTC_DATE() AND b.event_date <= DATE_ADD(UTC_DATE(), INTERVAL ' . $slot . ' DAY)';

            return 'EXISTS (SELECT 1 FROM {bookings} b
                             WHERE b.contact_id = c.id AND ' . $live . ' AND ' . $window . ')';
        }

        if ($operator === 'count_gte') {
            $count = self::positiveInt($rule['value'] ?? null, 'a number of parties');

            return '(SELECT COUNT(*) FROM {bookings} b
                      WHERE b.contact_id = c.id AND ' . $live . ') >= ' . self::bind($state, $count);
        }

        throw new RuntimeException('"' . $operator . '" is not something you can ask of party bookings.');
    }

    /**
     * A child in this household with a birthday in a given month.
     *
     * The reason a party venue wants segments at all: an offer six weeks
     * before a child's birthday is the single most valuable message this
     * application sends.
     *
     * @param array<string,mixed>                              $rule
     * @param array{n:int,params:array<string,mixed>,rules:int} $state
     */
    private static function childMonthRule(string $operator, array $rule, array &$state): string
    {
        if (!in_array($operator, ['is', 'is_not'], true)) {
            throw new RuntimeException('A birth month can only be matched or not matched.');
        }

        $month = self::month($rule['value'] ?? null);

        // TWO PLACEHOLDERS FOR ONE VALUE, deliberately. The driver runs with
        // emulated prepares OFF, where a named placeholder may be bound
        // exactly once — reusing `:s4` in both halves of the OR below is an
        // HY093 that surfaces as a bare 500 with no message anywhere. This
        // codebase has shipped that bug twice already (the deal search and
        // the task list), so the rule is: one bind() per occurrence.
        $slot      = self::bind($state, $month);
        $slotAgain = self::bind($state, $month);

        // EITHER SOURCE COUNTS. `children.birth_month` is denormalised from
        // birth_date so the birthday campaign is an indexed lookup — but it
        // is also the only thing filled in for a child whose parent gave the
        // month and not the day, which at a party venue is common. Matching
        // on the date alone would miss exactly those children, and they are
        // the ones this rule exists for.
        $exists = 'EXISTS (SELECT 1 FROM {children} ch
                            WHERE (ch.contact_id = c.id
                                   OR (c.household_id IS NOT NULL AND ch.household_id = c.household_id))
                              AND ch.deleted_at IS NULL
                              AND (ch.birth_month = ' . $slot
                                . ' OR (ch.birth_month IS NULL AND ch.birth_date IS NOT NULL'
                                . ' AND MONTH(ch.birth_date) = ' . $slotAgain . '))'
                            . ')';

        return $operator === 'is' ? $exists : 'NOT ' . $exists;
    }

    /**
     * @param array<string,mixed>                              $rule
     * @param array{n:int,params:array<string,mixed>,rules:int} $state
     * @param array<string,mixed>                              $definition
     */
    private static function membershipRule(
        string $operator,
        array $rule,
        array &$state,
        array $definition
    ): string {
        if (!in_array($operator, ['is', 'is_not', 'ever', 'never'], true)) {
            throw new RuntimeException('"' . $operator . '" is not something you can ask of a membership.');
        }

        if ($operator === 'ever' || $operator === 'never') {
            $exists = 'EXISTS (SELECT 1 FROM {memberships} m WHERE m.contact_id = c.id)';

            return $operator === 'ever' ? $exists : 'NOT ' . $exists;
        }

        $status = (string) ($rule['value'] ?? '');
        $allowed = $definition['values'] ?? [];

        if (!is_array($allowed) || !in_array($status, $allowed, true)) {
            throw new RuntimeException('"' . $status . '" is not a membership status.');
        }

        $exists = 'EXISTS (SELECT 1 FROM {memberships} m
                            WHERE m.contact_id = c.id AND m.status = ' . self::bind($state, $status) . ')';

        return $operator === 'is' ? $exists : 'NOT ' . $exists;
    }

    /**
     * Did they open or click a campaign, and how recently?
     *
     * @param array<string,mixed>                              $rule
     * @param array{n:int,params:array<string,mixed>,rules:int} $state
     * @param array<string,mixed>                              $definition
     */
    private static function engagementRule(
        string $operator,
        array $rule,
        array &$state,
        array $definition
    ): string {
        $column = (string) $definition['column'];

        // From FIELDS, so it is one of two fixed strings — but asserted
        // anyway, because this is the only place in the file where a column
        // name reaches the SQL from a variable rather than a literal.
        if (!in_array($column, ['opened_at', 'clicked_at'], true)) {
            throw new RuntimeException('Unknown engagement measure.');
        }

        $base = 'SELECT 1 FROM {messages} msg
                  WHERE msg.contact_id = c.id
                    AND msg.campaign_id IS NOT NULL
                    AND msg.' . $column . ' IS NOT NULL';

        if ($operator === 'ever' || $operator === 'never') {
            $exists = 'EXISTS (' . $base . ')';

            return $operator === 'ever' ? $exists : 'NOT ' . $exists;
        }

        if ($operator === 'in_last_days') {
            $days = self::positiveInt($rule['value'] ?? null, 'a number of days');

            return 'EXISTS (' . $base . ' AND msg.' . $column
                . ' >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . self::bind($state, $days) . ' DAY))';
        }

        if ($operator === 'not_in_last_days') {
            $days = self::positiveInt($rule['value'] ?? null, 'a number of days');

            return 'NOT EXISTS (' . $base . ' AND msg.' . $column
                . ' >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . self::bind($state, $days) . ' DAY))';
        }

        throw new RuntimeException('"' . $operator . '" is not something you can ask of campaign activity.');
    }

    // =================================================================
    // Values
    // =================================================================

    /**
     * Bind a value and return its placeholder.
     *
     * Named `:s0`, `:s1`, … and counted in $state, so every value in a tree
     * gets its own. That matters more than it looks: the driver runs with
     * emulated prepares OFF, where a named placeholder may be bound exactly
     * once — reusing one is an HY093 that surfaces as a bare 500.
     *
     * @param array{n:int,params:array<string,mixed>,rules:int} $state
     */
    private static function bind(array &$state, mixed $value): string
    {
        $name = 's' . $state['n'];
        $state['n']++;
        $state['params'][$name] = $value;

        return ':' . $name;
    }

    /**
     * Read a rule's value as its field's type.
     *
     * @param array<string,mixed> $definition
     */
    private static function cast(string $type, mixed $value, array $definition): mixed
    {
        return match ($type) {
            'int'      => self::wholeNumber($value),
            'money'    => self::moneyCents($value),
            'month'    => self::month($value),
            'date', 'datetime' => self::dateValue($type, $value),
            'enum'     => self::enumValue($value, $definition),
            default    => self::stringValue($value),
        };
    }

    private static function stringValue(mixed $value): string
    {
        if (is_bool($value) || is_array($value) || $value === null) {
            throw new RuntimeException('That condition needs some text.');
        }

        $text = trim((string) $value);

        if ($text === '') {
            throw new RuntimeException('That condition needs some text.');
        }

        // 191 is the indexed length of every string column this can reach, so
        // a longer value cannot match anything and is almost always a paste
        // accident.
        return mb_substr($text, 0, 191);
    }

    private static function wholeNumber(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw new RuntimeException('That condition needs a number.');
        }

        return (int) $value;
    }

    /**
     * Dollars in, integer cents out.
     *
     * Because an operator types "200" meaning two hundred dollars, and the
     * column holds 20000. Reading it as cents would compare a two-dollar
     * threshold against lifetime spend and match everybody who ever came.
     */
    private static function moneyCents(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw new RuntimeException('That condition needs an amount in dollars.');
        }

        // Half away from zero, like every other money path here.
        $cents = (float) $value * 100;

        return (int) ($cents < 0 ? ceil($cents - 0.5) : floor($cents + 0.5));
    }

    private static function month(mixed $value): int
    {
        $month = is_numeric($value) ? (int) $value : 0;

        if ($month < 1 || $month > 12) {
            throw new RuntimeException('A month is a number from 1 to 12.');
        }

        return $month;
    }

    private static function positiveInt(mixed $value, string $what): int
    {
        if (!is_numeric($value)) {
            throw new RuntimeException('That condition needs ' . $what . '.');
        }

        $number = (int) $value;

        if ($number < 1) {
            throw new RuntimeException('That condition needs ' . $what . ', greater than zero.');
        }

        // A year or so. Beyond this the window is the whole table and the
        // rule is doing nothing but costing a scan.
        return min($number, 3660);
    }

    private static function dateValue(string $type, mixed $value): string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1) {
            return $type === 'datetime' ? $text . ' 00:00:00' : $text;
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})(:\d{2})?$/', $text, $m) === 1) {
            return $m[1] . ' ' . $m[2] . ($m[3] ?? ':00');
        }

        throw new RuntimeException('That condition needs a date like 2026-07-04.');
    }

    /** @param array<string,mixed> $definition */
    private static function enumValue(mixed $value, array $definition): string
    {
        $text    = is_scalar($value) ? (string) $value : '';
        $allowed = $definition['values'] ?? [];

        if (!is_array($allowed) || !in_array($text, $allowed, true)) {
            throw new RuntimeException(
                '"' . $text . '" is not one of the values ' . $definition['label'] . ' can take.'
            );
        }

        return $text;
    }

    /**
     * Neutralise the wildcards inside a LIKE value.
     *
     * Applied to the BOUND value, so a surname containing a per-cent sign is
     * matched as a per-cent sign rather than as "anything". The backslash
     * goes first, or escaping the others would double-escape it.
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    // =================================================================
    // Describing a segment to a person
    // =================================================================

    /**
     * The rule tree in plain English, for the editor and the campaign
     * approval screen.
     *
     * Not decoration: somebody about to send to forty thousand people needs
     * to read back what they built, and a JSON dump is not that. It never
     * throws — a tree that will not compile still has to be describable, or
     * the error page cannot show what was wrong.
     *
     * @param array<string,mixed> $rules
     */
    public static function describe(array $rules, int $depth = 1): string
    {
        $mode  = strtolower((string) ($rules['match'] ?? 'all')) === 'any' ? ' or ' : ' and ';
        $list  = $rules['rules'] ?? [];

        if (!is_array($list) || $list === [] || $depth > self::MAX_DEPTH) {
            return 'nobody (no conditions set)';
        }

        $parts = [];

        foreach ($list as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $parts[] = isset($rule['rules']) || isset($rule['match'])
                ? '(' . self::describe($rule, $depth + 1) . ')'
                : self::describeRule($rule);
        }

        return $parts === [] ? 'nobody (no conditions set)' : implode($mode, $parts);
    }

    /** @param array<string,mixed> $rule */
    private static function describeRule(array $rule): string
    {
        $field = (string) ($rule['field'] ?? '');
        $label = self::FIELDS[$field]['label'] ?? ('an unknown field "' . $field . '"');
        $op    = (string) ($rule['op'] ?? '');
        $value = $rule['value'] ?? null;

        $readable = match ($op) {
            'is'               => 'is',
            'is_not'           => 'is not',
            'contains'         => 'contains',
            'not_contains'     => 'does not contain',
            'starts_with'      => 'starts with',
            'ends_with'        => 'ends with',
            'is_set'           => 'is filled in',
            'is_not_set'       => 'is empty',
            'gt'               => 'is more than',
            'gte'              => 'is at least',
            'lt'               => 'is less than',
            'lte'              => 'is at most',
            'between'          => 'is between',
            'on'               => 'is on',
            'before'           => 'is before',
            'after'            => 'is after',
            'in_last_days'     => 'was in the last',
            'not_in_last_days' => 'was not in the last',
            'in_next_days'     => 'is in the next',
            'is_true'          => 'is yes',
            'is_false'         => 'is no',
            'has_tag'          => 'includes',
            'not_has_tag'      => 'does not include',
            'ever'             => 'has happened',
            'never'            => 'has never happened',
            'count_gte'        => 'is at least',
            default            => $op === '' ? '?' : $op,
        };

        if (in_array($op, self::VALUELESS, true) || in_array($op, ['ever', 'never'], true)) {
            return $label . ' ' . $readable;
        }

        if (is_array($value)) {
            $value = implode(' and ', array_map(
                static fn (mixed $v): string => is_scalar($v) ? (string) $v : '?',
                $value
            ));
        }

        $printed = is_scalar($value) ? (string) $value : '?';

        if (in_array($op, ['in_last_days', 'not_in_last_days', 'in_next_days'], true)) {
            $printed .= ' days';
        }

        if ($field === 'birth_month' || $field === 'child_birth_month') {
            $printed = self::monthName($printed);
        }

        return $label . ' ' . $readable . ' ' . $printed;
    }

    private static function monthName(string $number): string
    {
        $names = [
            1 => 'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December',
        ];

        return $names[(int) $number] ?? $number;
    }

    /**
     * The fields an editor may offer, grouped for a dropdown.
     *
     * @return array<string,array<string,string>>
     */
    public static function fieldsByGroup(): array
    {
        $groups = [
            'Guest'      => ['first_name', 'last_name', 'email', 'phone', 'city', 'state', 'postal_code', 'lifecycle_stage', 'source', 'owner_id'],
            'Behaviour'  => ['visit_count', 'lifetime_value_cents', 'avg_spend_cents', 'first_visit_at', 'last_visit_at', 'last_contacted_at', 'created_at'],
            'Birthdays'  => ['birth_date', 'birth_month', 'child_birth_month'],
            'Consent'    => ['email_consent', 'sms_consent', 'do_not_contact'],
            'Elsewhere'  => ['tag', 'has_booking', 'membership_status', 'campaign_opened', 'campaign_clicked'],
        ];

        $out = [];

        foreach ($groups as $name => $fields) {
            foreach ($fields as $field) {
                if (isset(self::FIELDS[$field])) {
                    $out[$name][$field] = (string) self::FIELDS[$field]['label'];
                }
            }
        }

        return $out;
    }
}
