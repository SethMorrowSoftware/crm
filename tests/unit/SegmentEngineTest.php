<?php

declare(strict_types=1);

/**
 * Rules in, parameterised SQL out.
 *
 * This file is two test suites wearing one hat, because the class is two
 * things at once.
 *
 * ## It is a security boundary
 *
 * Field and operator names come from an operator typing into an admin form.
 * The tests below compile rules whose VALUES are SQL injection attempts and
 * assert the generated statement does not contain them — not that they were
 * escaped, that they are not in the statement at all, because every value is
 * bound.
 *
 * ## It decides who gets a message
 *
 * Which makes its failure mode asymmetric, and that asymmetry is what most of
 * these tests are about:
 *
 *   * **A rule that cannot be compiled THROWS; it is never dropped.** Dropping
 *     one widens the segment, so "customers who opted in" with its consent
 *     rule quietly skipped becomes "everybody" — and the venue finds out when
 *     forty thousand people get an offer meant for two hundred.
 *   * **An empty rule set matches NOBODY.** "No conditions" is not "no
 *     filter". A half-finished segment must not be the whole guest list.
 *
 * Both are tested by asserting on the generated SQL rather than on a row
 * count, because a count of zero proves nothing about which direction the
 * engine fails in.
 */

use App\Services\SegmentEngine as S;
use Tests\Assert;
use Tests\Harness;

Harness::suite('unit/segment-engine');

require_once CC_ROOT . '/app/Services/SegmentEngine.php';

/** One rule in a group, which is how every rule is actually used. */
function seg_one(array $rule): array
{
    return ['match' => 'all', 'rules' => [$rule]];
}

/** The compiled clause for one rule. */
function seg_sql(array $rule): string
{
    return S::compile(seg_one($rule))['sql'];
}

/** @return array<string,mixed> */
function seg_params(array $rule): array
{
    return S::compile(seg_one($rule))['params'];
}

// ================================================================ the boundary

Harness::test('AN UNKNOWN FIELD THROWS — it is never dropped', function (): void {
    // The whole point. A renamed or removed field must not silently widen the
    // segment: the rule that disappears is the one narrowing it.
    foreach (['', 'password_hash', 'notes', 'c.id', 'id; DROP TABLE contacts', 'Email'] as $field) {
        Assert::throws(
            RuntimeException::class,
            static fn (): array => S::compile(seg_one(['field' => $field, 'op' => 'is', 'value' => 'x'])),
            '"' . $field . '" is not a field anybody may filter on'
        );
    }
});

Harness::test('AN UNKNOWN OPERATOR THROWS', function (): void {
    foreach (['', 'equals', 'IS', 'regexp', 'in', '= 1 OR 1=1'] as $operator) {
        Assert::throws(
            RuntimeException::class,
            static fn (): array => S::compile(
                seg_one(['field' => 'first_name', 'op' => $operator, 'value' => 'x'])
            ),
            '"' . $operator . '" is not an operator'
        );
    }
});

Harness::test('an operator from the WRONG TYPE throws', function (): void {
    // `gt` is real, and meaningless on a name. Accepting it would compile
    // `c.first_name > 'x'`, which MySQL answers with a collation comparison
    // nobody asked for.
    Assert::throws(
        RuntimeException::class,
        static fn (): array => S::compile(seg_one(['field' => 'first_name', 'op' => 'gt', 'value' => 'x']))
    );

    Assert::throws(
        RuntimeException::class,
        static fn (): array => S::compile(seg_one(['field' => 'visit_count', 'op' => 'contains', 'value' => '2'])),
        'and "contains" means nothing on a count'
    );

    // The positive control for both: each is fine on its own type.
    Assert::doesNotThrow(
        static fn (): array => S::compile(seg_one(['field' => 'visit_count', 'op' => 'gt', 'value' => 2]))
    );
    Assert::doesNotThrow(
        static fn (): array => S::compile(seg_one(['field' => 'first_name', 'op' => 'contains', 'value' => 'x']))
    );
});

Harness::test('EVERY VALUE IS BOUND — nothing an operator types reaches the SQL', function (): void {
    // Not "is escaped". Is not there.
    $attacks = [
        "' OR 1=1 --",
        "'; DROP TABLE cc_contacts; --",
        "x' UNION SELECT password_hash FROM cc_users WHERE '1'='1",
        'x\\',
        "admin'--",
    ];

    foreach ($attacks as $attack) {
        foreach ([
            ['field' => 'first_name', 'op' => 'is', 'value' => $attack],
            ['field' => 'email', 'op' => 'contains', 'value' => $attack],
            ['field' => 'city', 'op' => 'ends_with', 'value' => $attack],
            ['field' => 'tag', 'op' => 'has_tag', 'value' => $attack],
        ] as $rule) {
            $compiled = S::compile(seg_one($rule));

            Assert::notContains(
                $attack,
                $compiled['sql'],
                'the attack string is not in the statement for ' . $rule['field']
            );
            Assert::notContains('DROP', strtoupper($compiled['sql']), 'nor anything from it');
            Assert::notContains('UNION', strtoupper($compiled['sql']));

            // And it IS in the bound parameters, which is where it belongs.
            Assert::greaterThan(0, count($compiled['params']), 'it was bound');
        }
    }
});

Harness::test('and a LIKE value cannot smuggle a wildcard', function (): void {
    // A guest whose surname contains a per-cent sign is a guest, not a
    // pattern. The escaping goes on the BOUND value, so the SQL is unchanged
    // and the match is literal.
    $params = seg_params(['field' => 'last_name', 'op' => 'contains', 'value' => '100%']);
    $value  = (string) array_values($params)[0];

    Assert::same('%100\\%%', $value, 'the guest\'s per-cent sign is escaped, the wrapper is not');

    $underscore = (string) array_values(
        seg_params(['field' => 'last_name', 'op' => 'is_not', 'value' => 'a_b'])
    )[0];

    Assert::same('a_b', $underscore, 'while a plain comparison is left exactly as typed');

    // A backslash is escaped first, or escaping the others would double it.
    $slash = (string) array_values(
        seg_params(['field' => 'last_name', 'op' => 'starts_with', 'value' => 'a\\b'])
    )[0];

    Assert::same('a\\\\b%', $slash);
});

Harness::test('each value gets its OWN placeholder', function (): void {
    // The driver runs with emulated prepares off, where a named placeholder
    // may be bound exactly once. Reusing one is an HY093 that surfaces as a
    // bare 500 with no message — this codebase has shipped it twice (the deal
    // search and the task list), so it is pinned here.
    $compiled = S::compile([
        'match' => 'all',
        'rules' => [
            ['field' => 'first_name', 'op' => 'is', 'value' => 'Alex'],
            ['field' => 'last_name', 'op' => 'is', 'value' => 'Fitzgerald'],
            ['field' => 'visit_count', 'op' => 'between', 'value' => [2, 10]],
            // This one puts the SAME value in two halves of an OR, which is
            // exactly where the bug would be.
            ['field' => 'child_birth_month', 'op' => 'is', 'value' => 5],
        ],
    ]);

    preg_match_all('/:s\d+/', $compiled['sql'], $m);

    $used = $m[0];

    Assert::same(
        count($used),
        count(array_unique($used)),
        'no placeholder appears twice in the statement: ' . implode(' ', $used)
    );
    Assert::same(
        count(array_unique($used)),
        count($compiled['params']),
        'and every placeholder has exactly one bound value'
    );

    foreach ($used as $placeholder) {
        Assert::hasKey(
            ltrim($placeholder, ':'),
            $compiled['params'],
            $placeholder . ' is bound'
        );
    }
});

// ============================================================ the direction

Harness::test('AN EMPTY RULE SET MATCHES NOBODY', function (): void {
    // The single most dangerous default in a marketing tool. "No conditions"
    // is not "no filter": a segment somebody created and has not finished
    // must not be the whole database.
    foreach ([
        [],
        ['match' => 'all'],
        ['match' => 'all', 'rules' => []],
        ['match' => 'any', 'rules' => []],
        ['rules' => []],
    ] as $empty) {
        $sql = S::compile($empty)['sql'];

        Assert::contains('1 = 0', $sql, 'an empty segment matches nobody');
        Assert::notContains('1 = 1', $sql, 'and certainly not everybody');
    }
});

Harness::test('and so does an empty NESTED group', function (): void {
    // The subtle version: an outer group with a real rule and an empty inner
    // one. Under `all` the empty group narrows to nothing, which is right;
    // under `any` it must not WIDEN the result to everybody.
    $any = S::compile([
        'match' => 'any',
        'rules' => [
            ['field' => 'email_consent', 'op' => 'is_true'],
            ['match' => 'all', 'rules' => []],
        ],
    ])['sql'];

    Assert::contains('1 = 0', $any, 'the empty group contributes nothing');
    Assert::notContains('1 = 1', $any);
});

Harness::test('A DELETED GUEST IS NEVER IN A SEGMENT, whatever the rules say', function (): void {
    // Always, and not left to the rules — one segment somewhere would
    // eventually forget it, and that one would mail people who asked to be
    // erased.
    foreach ([
        [],
        seg_one(['field' => 'email_consent', 'op' => 'is_true']),
        ['match' => 'any', 'rules' => [['field' => 'visit_count', 'op' => 'gte', 'value' => 1]]],
    ] as $rules) {
        Assert::contains(
            'c.deleted_at IS NULL',
            S::compile($rules)['sql'],
            'the deleted guard is on every compiled segment'
        );
    }
});

Harness::test('a rule tree nested too deep is refused', function (): void {
    // A denial of service otherwise: each level is a nested set of
    // parentheses the database has to plan.
    $deep = ['field' => 'visit_count', 'op' => 'gte', 'value' => 1];

    for ($i = 0; $i < S::MAX_DEPTH + 2; $i++) {
        $deep = ['match' => 'all', 'rules' => [$deep]];
    }

    Assert::throws(RuntimeException::class, static fn (): array => S::compile($deep));

    // The control: exactly at the limit is fine.
    $allowed = ['field' => 'visit_count', 'op' => 'gte', 'value' => 1];

    for ($i = 0; $i < S::MAX_DEPTH - 1; $i++) {
        $allowed = ['match' => 'all', 'rules' => [$allowed]];
    }

    Assert::doesNotThrow(static fn (): array => S::compile($allowed));
});

Harness::test('too many rules is refused', function (): void {
    $rules = [];

    for ($i = 0; $i <= S::MAX_RULES; $i++) {
        $rules[] = ['field' => 'visit_count', 'op' => 'gte', 'value' => 1];
    }

    Assert::throws(
        RuntimeException::class,
        static fn (): array => S::compile(['match' => 'all', 'rules' => $rules])
    );
});

Harness::test('an unknown match mode is refused rather than defaulted', function (): void {
    // Defaulting to `all` would narrow and defaulting to `any` would widen,
    // and neither is what somebody who typed something else meant.
    foreach (['none', 'AND', 'both', ''] as $mode) {
        Assert::throws(
            RuntimeException::class,
            static fn (): array => S::compile([
                'match' => $mode,
                'rules' => [['field' => 'visit_count', 'op' => 'gte', 'value' => 1]],
            ])
        );
    }
});

// ================================================================ the values

Harness::test('MONEY IS TYPED IN DOLLARS AND STORED IN CENTS', function (): void {
    // An operator types 200 meaning two hundred dollars; the column holds
    // 20000. Reading it as cents compares a two-dollar threshold against
    // lifetime spend and matches everybody who ever came through the door.
    $params = seg_params(['field' => 'lifetime_value_cents', 'op' => 'gte', 'value' => '200']);

    Assert::same(20000, array_values($params)[0], '$200 is 20000 cents');

    Assert::same(
        2050,
        array_values(seg_params(['field' => 'avg_spend_cents', 'op' => 'gt', 'value' => '20.50']))[0],
        'and $20.50 is 2050'
    );

    // Half away from zero, like every other money path here.
    Assert::same(
        2051,
        array_values(seg_params(['field' => 'avg_spend_cents', 'op' => 'gt', 'value' => '20.505']))[0]
    );
});

Harness::test('a count is a plain number, not dollars', function (): void {
    // The control for the test above: the same operator on an `int` field
    // must not be multiplied by a hundred.
    Assert::same(
        3,
        array_values(seg_params(['field' => 'visit_count', 'op' => 'gte', 'value' => '3']))[0]
    );
});

Harness::test('"HAS NOT BEEN IN N DAYS" INCLUDES THE PEOPLE WHO HAVE NEVER BEEN', function (): void {
    /*
     * The single most common retention question a venue asks, and the engine
     * could not express it until this operator existed — `describe()` already
     * had wording for it, so a rule saved through any other route compiled
     * fine on the page and threw on the way to the database.
     *
     * NULL IS IN. Somebody with no recorded visit has not been in sixty days
     * either, and excluding them makes "never been" invisible to every
     * win-back campaign — which is the opposite of what the person building
     * one wants. The pair still partitions the list, because `in_last_days`
     * excludes them.
     */
    $sql = seg_sql(['field' => 'last_visit_at', 'op' => 'not_in_last_days', 'value' => 60]);

    Assert::contains('IS NULL', $sql, 'a guest who has never visited has not visited lately');
    Assert::contains('DATE_SUB', $sql);

    // The value is a NUMBER of days, like its complement — read by the
    // field's type alone it would be refused as "not a date".
    Assert::same(
        60,
        array_values(seg_params(['field' => 'last_visit_at', 'op' => 'not_in_last_days', 'value' => 60]))[0]
    );

    // And it is still guarded like every other relative window.
    Assert::throws(
        RuntimeException::class,
        static fn (): array => S::compile(
            seg_one(['field' => 'last_visit_at', 'op' => 'not_in_last_days', 'value' => 0])
        ),
        'nought days is not a window'
    );

    // The two together are complements rather than overlapping. Asserted on
    // the FIELD's own clause: every compiled segment also carries
    // `c.deleted_at IS NULL`, so looking for "IS NULL" anywhere in the SQL
    // can never fail and would be a test that passes for the wrong reason.
    Assert::contains('c.last_visit_at IS NULL', $sql);
    Assert::notContains(
        'c.last_visit_at IS NULL',
        seg_sql(['field' => 'last_visit_at', 'op' => 'in_last_days', 'value' => 60])
    );
});

Harness::test('A RELATIVE WINDOW TAKES A NUMBER OF DAYS, not a date', function (): void {
    // MEASURED against the real database: reading the value by the FIELD's
    // type alone refused "last visit in the last 90 days" — a perfectly
    // ordinary rule, and most of what a segment is for — because
    // `last_visit_at` is a datetime and 90 is not a date. The operator
    // decides the value's type too.
    $params = seg_params(['field' => 'last_visit_at', 'op' => 'in_last_days', 'value' => 90]);

    Assert::same(90, array_values($params)[0]);

    Assert::doesNotThrow(
        static fn (): array => S::compile(
            seg_one(['field' => 'birth_date', 'op' => 'in_next_days', 'value' => 30])
        ),
        'and a future window on a date field works too'
    );

    // While a date operator on the same field still wants a date.
    Assert::same(
        '2026-07-04 00:00:00',
        array_values(seg_params(['field' => 'last_visit_at', 'op' => 'after', 'value' => '2026-07-04']))[0],
        'a datetime field pads a bare date to midnight'
    );
    Assert::same(
        '2026-07-04',
        array_values(seg_params(['field' => 'birth_date', 'op' => 'on', 'value' => '2026-07-04']))[0],
        'and a date field does not'
    );
});

Harness::test('a nonsense date is refused', function (): void {
    foreach (['', 'yesterday', '04/07/2026', '2026-7-4', 'now()'] as $value) {
        Assert::throws(
            RuntimeException::class,
            static fn (): array => S::compile(
                seg_one(['field' => 'birth_date', 'op' => 'on', 'value' => $value])
            ),
            '"' . $value . '" is not a date'
        );
    }
});

Harness::test('a day count is bounded and must be positive', function (): void {
    Assert::throws(
        RuntimeException::class,
        static fn (): array => S::compile(
            seg_one(['field' => 'last_visit_at', 'op' => 'in_last_days', 'value' => 0])
        ),
        'zero days is not a window'
    );
    Assert::throws(
        RuntimeException::class,
        static fn (): array => S::compile(
            seg_one(['field' => 'last_visit_at', 'op' => 'in_last_days', 'value' => -5])
        )
    );

    // Clamped rather than refused: a very large window is the whole table,
    // which is a pointless rule but not an invalid one.
    Assert::same(
        3660,
        array_values(seg_params(['field' => 'last_visit_at', 'op' => 'in_last_days', 'value' => 99999]))[0]
    );
});

Harness::test('an enum value outside the column\'s own list is refused', function (): void {
    // A value the column cannot hold matches nothing, so accepting it
    // produces an empty segment that looks like a working one.
    foreach (['vip', 'Customer', 'CUSTOMER', ''] as $value) {
        Assert::throws(
            RuntimeException::class,
            static fn (): array => S::compile(
                seg_one(['field' => 'lifecycle_stage', 'op' => 'is', 'value' => $value])
            ),
            '"' . $value . '" is not a lifecycle stage'
        );
    }

    Assert::doesNotThrow(
        static fn (): array => S::compile(
            seg_one(['field' => 'lifecycle_stage', 'op' => 'is', 'value' => 'customer'])
        )
    );
});

Harness::test('the membership statuses are the column\'s own enum, verbatim', function (): void {
    // Measured against the schema: getting this list wrong produces a segment
    // that silently matches nobody.
    foreach (['pending', 'active', 'expiring', 'expired', 'cancelled', 'lapsed', 'frozen'] as $status) {
        Assert::doesNotThrow(
            static fn (): array => S::compile(
                seg_one(['field' => 'membership_status', 'op' => 'is', 'value' => $status])
            ),
            $status . ' is a real membership status'
        );
    }

    Assert::throws(
        RuntimeException::class,
        static fn (): array => S::compile(
            seg_one(['field' => 'membership_status', 'op' => 'is', 'value' => 'paused'])
        ),
        'while "paused" is not'
    );
});

Harness::test('a month is 1 to 12', function (): void {
    foreach ([0, 13, -1, 'May', '', 99] as $value) {
        Assert::throws(
            RuntimeException::class,
            static fn (): array => S::compile(
                seg_one(['field' => 'birth_month', 'op' => 'is', 'value' => $value])
            ),
            var_export($value, true) . ' is not a month'
        );
    }

    foreach ([1, 12, '7'] as $value) {
        Assert::doesNotThrow(
            static fn (): array => S::compile(
                seg_one(['field' => 'birth_month', 'op' => 'is', 'value' => $value])
            )
        );
    }
});

Harness::test('a between needs both ends', function (): void {
    foreach ([null, 5, [5], [1, 2, 3], 'a,b'] as $value) {
        Assert::throws(
            RuntimeException::class,
            static fn (): array => S::compile(
                seg_one(['field' => 'visit_count', 'op' => 'between', 'value' => $value])
            )
        );
    }

    Assert::doesNotThrow(
        static fn (): array => S::compile(
            seg_one(['field' => 'visit_count', 'op' => 'between', 'value' => [2, 10]])
        )
    );
});

Harness::test('a valueless operator needs no value, and takes none', function (): void {
    $compiled = S::compile(seg_one(['field' => 'phone', 'op' => 'is_set']));

    Assert::same([], $compiled['params'], 'nothing is bound');
    Assert::notContains(':s', $compiled['sql'], 'and no placeholder is emitted');

    // A value passed anyway is simply ignored rather than refused: the UI may
    // leave the old one in the field when the operator changes.
    Assert::doesNotThrow(
        static fn (): array => S::compile(
            seg_one(['field' => 'phone', 'op' => 'is_set', 'value' => 'leftover'])
        )
    );
});

Harness::test('a missing value is refused rather than treated as empty', function (): void {
    // An empty string matches the rows with an empty column, which is a
    // different question from the one a half-filled form was asking.
    foreach ([null, '', '   ', [], true] as $value) {
        Assert::throws(
            RuntimeException::class,
            static fn (): array => S::compile(
                seg_one(['field' => 'first_name', 'op' => 'is', 'value' => $value])
            ),
            var_export($value, true) . ' is not a value'
        );
    }
});

// ====================================================== the shape of the SQL

Harness::test('all and any become AND and OR', function (): void {
    $all = S::compile([
        'match' => 'all',
        'rules' => [
            ['field' => 'email_consent', 'op' => 'is_true'],
            ['field' => 'visit_count', 'op' => 'gte', 'value' => 1],
        ],
    ])['sql'];

    Assert::contains(' AND ', $all);
    Assert::notContains(' OR c', $all, 'and nothing is OR-ed that should be AND-ed');

    $any = S::compile([
        'match' => 'any',
        'rules' => [
            ['field' => 'email_consent', 'op' => 'is_true'],
            ['field' => 'sms_consent', 'op' => 'is_true'],
        ],
    ])['sql'];

    Assert::contains(' OR ', $any);
});

Harness::test('a nested group is parenthesised, so precedence cannot slip', function (): void {
    // `a AND b OR c` is not `a AND (b OR c)`, and the difference between them
    // is who gets the message.
    $sql = S::compile([
        'match' => 'all',
        'rules' => [
            ['field' => 'email_consent', 'op' => 'is_true'],
            [
                'match' => 'any',
                'rules' => [
                    ['field' => 'birth_month', 'op' => 'is', 'value' => 7],
                    ['field' => 'child_birth_month', 'op' => 'is', 'value' => 7],
                ],
            ],
        ],
    ])['sql'];

    // Asserted by BRACKET DEPTH rather than by matching a shape, because the
    // shape depends on how each rule happens to be written and the property
    // does not: the OR must sit DEEPER than the AND that joins the group to
    // the rest, which is precisely what the brackets are for. Drop them and
    // the two land at the same depth.
    $depthAt = static function (string $sql, string $needle, int $from = 0): int {
        $at = strpos($sql, $needle, $from);

        Assert::notSame(false, $at, '"' . trim($needle) . '" is in the statement');

        $depth = 0;

        for ($i = 0; $i < $at; $i++) {
            if ($sql[$i] === '(') {
                $depth++;
            } elseif ($sql[$i] === ')') {
                $depth--;
            }
        }

        return $depth;
    };

    // The outer AND is the one joining the consent rule to the group; skip
    // past the leading `c.deleted_at IS NULL AND (` wrapper every segment has.
    $afterWrapper = (int) strpos($sql, 'c.email_consent');

    Assert::greaterThan(
        $depthAt($sql, ' AND ', $afterWrapper),
        $depthAt($sql, ' OR ', $afterWrapper),
        'the OR sits inside brackets the AND does not'
    );
});

Harness::test('an "is not" does not quietly drop the NULLs', function (): void {
    // `column <> 'x'` is FALSE for a NULL in SQL, so "city is not Chester"
    // would exclude every guest whose town is unknown — which is most of a
    // real list, and not what anybody means.
    foreach ([
        ['field' => 'city', 'op' => 'is_not', 'value' => 'Chester'],
        ['field' => 'lifecycle_stage', 'op' => 'is_not', 'value' => 'lead'],
        ['field' => 'visit_count', 'op' => 'is_not', 'value' => 3],
        ['field' => 'email', 'op' => 'not_contains', 'value' => 'example'],
    ] as $rule) {
        Assert::contains(
            'IS NULL OR',
            seg_sql($rule),
            $rule['field'] . ' ' . $rule['op'] . ' includes the rows with nothing in that column'
        );
    }

    // And "is no" on a boolean treats an unset flag as a no, because that is
    // what an unset consent flag means.
    Assert::contains('IS NULL', seg_sql(['field' => 'email_consent', 'op' => 'is_false']));
});

Harness::test('a cancelled party does not count as having had one', function (): void {
    // "Guests who have had a party" meaning "guests who once enquired and
    // cancelled" would put a thank-you offer in front of somebody whose party
    // never happened.
    foreach (['ever', 'never', 'in_last_days', 'count_gte'] as $operator) {
        $sql = seg_sql([
            'field' => 'has_booking',
            'op'    => $operator,
            'value' => 30,
        ]);

        Assert::contains("NOT IN ('cancelled','no_show')", $sql, $operator . ' excludes cancellations');
        Assert::contains('b.deleted_at IS NULL', $sql, 'and deleted bookings');
    }
});

Harness::test('a tag match names the entity type', function (): void {
    // `taggables` is polymorphic: without the type, a tag put on a BOOKING
    // whose id happens to equal this contact's would read as a tag on the
    // guest.
    $sql = seg_sql(['field' => 'tag', 'op' => 'has_tag', 'value' => 'VIP']);

    Assert::contains("tg.entity_type = 'contact'", $sql);
    Assert::contains('tg.entity_id = c.id', $sql);
    Assert::contains('NOT EXISTS', seg_sql(['field' => 'tag', 'op' => 'not_has_tag', 'value' => 'VIP']));
});

Harness::test('a child\'s birth month matches the stored month OR the date', function (): void {
    // `children.birth_month` is denormalised from birth_date so the birthday
    // campaign is an indexed lookup — but it is also the only thing filled in
    // for a child whose parent gave the month and not the day, which at a
    // party venue is common. Matching on the date alone would miss exactly
    // the children this rule exists for.
    $sql = seg_sql(['field' => 'child_birth_month', 'op' => 'is', 'value' => 5]);

    Assert::contains('ch.birth_month =', $sql);
    Assert::contains('MONTH(ch.birth_date)', $sql);
    Assert::contains('ch.deleted_at IS NULL', $sql, 'and a removed child is not a reason to mail');

    // The household, not only the contact: a party is booked by one parent
    // and the child may be recorded against the other.
    Assert::contains('ch.household_id = c.household_id', $sql);
});

// ============================================================== describing

Harness::test('a segment reads back in plain English', function (): void {
    // Somebody about to send to forty thousand people has to be able to read
    // what they built. A JSON dump is not that.
    $described = S::describe([
        'match' => 'all',
        'rules' => [
            ['field' => 'email_consent', 'op' => 'is_true'],
            ['field' => 'lifetime_value_cents', 'op' => 'gte', 'value' => '200'],
            [
                'match' => 'any',
                'rules' => [
                    ['field' => 'child_birth_month', 'op' => 'is', 'value' => 7],
                    ['field' => 'has_booking', 'op' => 'in_last_days', 'value' => 365],
                ],
            ],
        ],
    ]);

    Assert::contains('Said yes to email is yes', $described);
    Assert::contains('Lifetime spend is at least 200', $described);
    Assert::contains('July', $described, 'a month is named, not numbered');
    Assert::contains('365 days', $described);
    Assert::contains(' and ', $described);
    Assert::contains(' or ', $described, 'and the nested group reads as an "or"');
});

Harness::test('and an empty segment says it matches nobody', function (): void {
    Assert::contains('nobody', S::describe([]));
    Assert::contains('nobody', S::describe(['match' => 'all', 'rules' => []]));
});

Harness::test('describing NEVER throws, even on a tree that will not compile', function (): void {
    // The error page has to be able to show what was wrong, so the describer
    // cannot be the thing that fails.
    foreach ([
        ['match' => 'all', 'rules' => [['field' => 'nonsense', 'op' => 'is', 'value' => 'x']]],
        ['match' => 'xyz', 'rules' => [['field' => 'email', 'op' => 'bogus']]],
        ['match' => 'all', 'rules' => ['not an array']],
        ['match' => 'all', 'rules' => [['field' => 'visit_count', 'op' => 'between', 'value' => null]]],
    ] as $broken) {
        Assert::doesNotThrow(static fn (): string => S::describe($broken));
        Assert::throws(RuntimeException::class, static fn (): array => S::compile($broken));
    }
});

// =============================================================== validating

Harness::test('validate answers without throwing, for the editor', function (): void {
    $good = S::validate([
        'match' => 'all',
        'rules' => [['field' => 'email_consent', 'op' => 'is_true']],
    ]);

    Assert::true($good['ok']);
    Assert::same('', $good['error']);
    Assert::same(1, $good['rules'], 'and counts the rules');

    $bad = S::validate([
        'match' => 'all',
        'rules' => [['field' => 'nonsense', 'op' => 'is', 'value' => 'x']],
    ]);

    Assert::false($bad['ok']);
    Assert::contains('nonsense', $bad['error'], 'and names what was wrong');
});

Harness::test('every field in the editor\'s own list actually compiles', function (): void {
    // The list the dropdown is built from must not offer a field the engine
    // refuses — that is a page where picking the obvious thing produces an
    // error.
    $offered = [];

    foreach (S::fieldsByGroup() as $fields) {
        foreach (array_keys($fields) as $field) {
            $offered[] = $field;
        }
    }

    Assert::greaterThan(15, count($offered), 'the editor offers a useful number of fields');

    foreach ($offered as $field) {
        Assert::hasKey($field, S::FIELDS, $field . ' is a real field');

        // And at least one operator works on it, with a plausible value.
        $type  = (string) S::FIELDS[$field]['type'];
        $rule  = match ($type) {
            'bool'        => ['field' => $field, 'op' => 'is_true'],
            'enum'        => ['field' => $field, 'op' => 'is', 'value' => S::FIELDS[$field]['values'][0]],
            'membership'  => ['field' => $field, 'op' => 'ever'],
            'tag'         => ['field' => $field, 'op' => 'has_tag', 'value' => 'VIP'],
            'booking'     => ['field' => $field, 'op' => 'ever'],
            'engagement'  => ['field' => $field, 'op' => 'ever'],
            'month', 'child_month' => ['field' => $field, 'op' => 'is', 'value' => 6],
            'date', 'datetime'     => ['field' => $field, 'op' => 'is_set'],
            'money', 'int'         => ['field' => $field, 'op' => 'gte', 'value' => 1],
            default       => ['field' => $field, 'op' => 'is_set'],
        };

        Assert::doesNotThrow(
            static fn (): array => S::compile(seg_one($rule)),
            $field . ' (' . $type . ') compiles'
        );
    }
});
