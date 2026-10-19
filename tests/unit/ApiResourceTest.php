<?php

declare(strict_types=1);

/**
 * What the REST API exposes, with no database and no server.
 *
 * THE FIELD LIST IS AN ALLOWLIST, and these tests exist to keep it one. The
 * failure this prevents is not a bug anybody writes on purpose: somebody adds
 * a column — a hash, a token, an internal note — and an API built on
 * `SELECT *` starts publishing it that afternoon, to every integration, with
 * no error anywhere and no line in any diff that looks like a decision.
 *
 * So: nothing secret-shaped may be in a field list, the writable set is
 * always a subset of the readable one, money is scrubbed rather than dropped,
 * and a field nobody declared cannot reach the wire however it got into the
 * row.
 */

use App\Services\ApiResource;
use Tests\Assert;
use Tests\Harness;

Harness::suite('unit/api-resource');

// -------------------------------------------------------------- the shapes

Harness::test('every resource declares the keys the controller relies on', function (): void {
    foreach (ApiResource::RESOURCES as $name => $definition) {
        foreach (['table', 'read', 'write', 'delete', 'owner', 'fields', 'writable',
                  'money', 'dates', 'soft_delete', 'search', 'sort'] as $key) {
            Assert::hasKey($key, $definition, $name . ' is missing "' . $key . '"');
        }

        Assert::true($definition['fields'] !== [], $name . ' exposes no fields at all');
        Assert::inArray('id', $definition['fields'], $name . ' must expose its id');

        // The read permission is what gates the whole resource, so an empty
        // one would be an open door.
        Assert::true(
            is_string($definition['read']) && $definition['read'] !== '',
            $name . ' has no read permission'
        );
    }
});

Harness::test('AN EXACT FILTER IS ON A FIELD THE CALLER COULD READ ANYWAY', function (): void {
    // Otherwise `?some_hidden_column=x` answers "is there a row where it is
    // x?" one guess at a time, about a column the allowlist exists to hide.
    foreach (ApiResource::RESOURCES as $name => $definition) {
        foreach ($definition['exact'] ?? [] as $field) {
            Assert::inArray($field, $definition['fields'], $name . ' filters on "' . $field . '", which it does not expose');
        }
    }
});

Harness::test('every permission a resource names is in the ACL catalogue', function (): void {
    // A typo here is not a locked door, it is an UNLOCKED one: `Acl::can()`
    // on an unknown permission returns false for a normal role but TRUE for
    // an owner, so a misspelt read permission would hide the resource from
    // staff and expose it to anybody with an owner token.
    foreach (ApiResource::RESOURCES as $name => $definition) {
        Assert::hasKey(
            (string) $definition['read'],
            App\Core\Acl::CATALOG,
            $name . ': read permission "' . $definition['read'] . '" is not a real permission'
        );

        if ($definition['write'] !== null) {
            Assert::hasKey(
                (string) $definition['write'],
                App\Core\Acl::CATALOG,
                $name . ': write permission "' . $definition['write'] . '" is not a real permission'
            );
        }
    }
});

Harness::test('WHAT CAN BE WRITTEN IS A SUBSET OF WHAT CAN BE READ', function (): void {
    // A writable field that is not readable would be a value a caller can set
    // and never see back — so they cannot tell whether their write landed,
    // and every integration ends up reading the row some other way.
    foreach (ApiResource::RESOURCES as $name => $definition) {
        foreach ($definition['writable'] as $field) {
            Assert::inArray(
                $field,
                $definition['fields'],
                $name . ': "' . $field . '" is writable but not readable'
            );
        }
    }
});

Harness::test('NOTHING SECRET-SHAPED IS EXPOSED', function (): void {
    /*
     * A deliberately blunt test. It cannot know what a future column means,
     * so it refuses anything whose NAME says "credential" — and the moment
     * somebody adds `api_key` to a table and lists it here, this fails rather
     * than the field quietly appearing in an integration's JSON.
     *
     * If a column legitimately named one of these ever has to be exposed,
     * the exception belongs here, in writing, where it is reviewed.
     */
    $forbidden = [
        'password', 'password_hash', 'token', 'token_hash', 'secret', 'api_key',
        'pin', 'pin_hash', 'ssn', 'card_number', 'cvv', 'signature_image',
        'payment_reference', 'remember_token', 'reset_token', 'private_key',
    ];

    $offenders = [];

    foreach (ApiResource::RESOURCES as $name => $definition) {
        foreach ($definition['fields'] as $field) {
            foreach ($forbidden as $bad) {
                if ($field === $bad || str_ends_with($field, '_' . $bad)) {
                    $offenders[] = $name . '.' . $field;
                }
            }
        }
    }

    Assert::same([], $offenders, "the API would publish:\n   " . implode("\n   ", $offenders));
});

Harness::test('the bulk listings do not carry guest prose or network identity', function (): void {
    /*
     * A listing is paginated, so anything in it can be walked in full. Message
     * bodies, form answers, IP addresses and user agents are therefore absent
     * from every listing by design — a single-record read is a deliberate
     * look at one conversation, and paging is a bulk export wearing a
     * different hat.
     */
    $neverInAList = ['body', 'body_text', 'data', 'ip', 'user_agent', 'answers', 'comment'];

    $offenders = [];

    foreach (ApiResource::RESOURCES as $name => $definition) {
        foreach ($definition['fields'] as $field) {
            if (in_array($field, $neverInAList, true)) {
                $offenders[] = $name . '.' . $field;
            }
        }
    }

    // `reviews.body` is the exception and is public by nature: a review is
    // already on Google for anybody to read.
    $offenders = array_values(array_diff($offenders, ['reviews.body']));

    Assert::same([], $offenders, "in a paginated listing:\n   " . implode("\n   ", $offenders));
});

Harness::test('consent flags are readable and never directly writable', function (): void {
    $contacts = ApiResource::get('contacts') ?? [];

    Assert::inArray('email_consent', $contacts['fields'], 'a caller can see where consent stands');

    // Setting one needs the evidence with it, which is its own endpoint. A
    // consent flag that could be PATCHed is a flag with no record of what the
    // guest agreed to — which is the thing a venue would have to produce if a
    // complaint ever went anywhere.
    foreach (['email_consent', 'sms_consent', 'do_not_contact', 'marketing_opt_out_at'] as $field) {
        Assert::false(
            in_array($field, $contacts['writable'], true),
            $field . ' must not be writable through a plain PATCH'
        );
    }
});

Harness::test('derived figures are readable and never writable', function (): void {
    $contacts = ApiResource::get('contacts') ?? [];

    // Writing one would put a number on the page that no visit supports, and
    // the next recalculation would silently contradict the caller.
    foreach (['visit_count', 'lifetime_value_cents', 'avg_spend_cents',
              'first_visit_at', 'last_visit_at'] as $field) {
        Assert::inArray($field, $contacts['fields']);
        Assert::false(
            in_array($field, $contacts['writable'], true),
            $field . ' is derived and must not be writable'
        );
    }

    // Same for a booking's money: it comes from the payment ledger.
    $bookings = ApiResource::get('bookings') ?? [];

    foreach ($bookings['money'] as $field) {
        Assert::false(
            in_array($field, $bookings['writable'], true),
            'bookings.' . $field . ' is derived from payments'
        );
    }
});

// ----------------------------------------------------------- presentation

Harness::test('a row is shaped by the ALLOWLIST, not by what the query returned', function (): void {
    $definition = ApiResource::get('contacts') ?? [];

    // A row carrying something nobody declared — which is exactly what
    // `SELECT *` hands you after the next migration.
    $row = [
        'id'            => 42,
        'first_name'    => 'Dana',
        'email'         => 'dana@example.test',
        'password_hash' => '$argon2id$v=19$m=47104...',
        'pin_hash'      => 'abc123',
        'secret_note'   => 'do not publish',
    ];

    $out = ApiResource::present($row, $definition);

    Assert::same(42, $out['id']);
    Assert::same('Dana', $out['first_name']);

    Assert::false(array_key_exists('password_hash', $out), 'the hash cannot reach the wire');
    Assert::false(array_key_exists('pin_hash', $out));
    Assert::false(array_key_exists('secret_note', $out));
});

Harness::test('money is EMPTIED for a money-blind caller, never removed', function (): void {
    $definition = ApiResource::get('bookings') ?? [];

    $row = ['id' => 7, 'booking_number' => 'B-1', 'total_cents' => 54900, 'guest_count' => 14];

    $withMoney = ApiResource::present($row, $definition, true);
    Assert::same(54900, $withMoney['total_cents']);

    $without = ApiResource::present($row, $definition, false);

    // The KEY stays. A key that vanishes reads as "this booking has no
    // total"; null reads as "you were not shown one", and an integration
    // parsing the two behaves differently.
    Assert::true(array_key_exists('total_cents', $without));
    Assert::null($without['total_cents']);

    // And nothing else is touched.
    Assert::same(14, $without['guest_count']);
    Assert::same('B-1', $without['booking_number']);
});

Harness::test('timestamps carry their zone', function (): void {
    $definition = ApiResource::get('contacts') ?? [];

    $out = ApiResource::present(
        ['id' => 1, 'created_at' => '2026-10-16 14:03:11'],
        $definition
    );

    // "2026-10-16 14:03:11" with no zone is read as LOCAL time by most client
    // libraries, which puts every timestamp four or five hours out at this
    // venue — consistently enough that nobody notices for a while.
    Assert::same('2026-10-16T14:03:11Z', $out['created_at']);
});

Harness::test('ids are integers and flags are booleans', function (): void {
    $definition = ApiResource::get('contacts') ?? [];

    // PDO hands back strings for integer columns on some drivers. An
    // integration doing `if (contact.id === 42)` in a typed language fails on
    // "42".
    $out = ApiResource::present(
        ['id' => '42', 'company_id' => '7', 'email_consent' => '1', 'visit_count' => '3'],
        $definition
    );

    Assert::same(42, $out['id']);
    Assert::same(7, $out['company_id']);
    Assert::same(true, $out['email_consent']);
    Assert::same(3, $out['visit_count']);
});

// ---------------------------------------------------------------- writing

Harness::test('an unwritable field is REFUSED, not silently dropped', function (): void {
    $definition = ApiResource::get('contacts') ?? [];

    $result = ApiResource::writable([
        'first_name'  => 'Dana',
        'visit_count' => 999,
        'made_up'     => 'x',
    ], $definition);

    Assert::same(['first_name' => 'Dana'], $result['data']);

    // Reported, not dropped. Dropping silently is how an integration spends a
    // morning wondering why its PATCH has no effect: the request succeeded,
    // the response looked right, and the field it cared about was never
    // written.
    Assert::same(['visit_count', 'made_up'], $result['refused']);
});

// ---------------------------------------------------------------- paging

Harness::test('a cursor round-trips and refuses rubbish', function (): void {
    $cursor = ApiResource::encodeCursor(4182);

    Assert::same(4182, ApiResource::decodeCursor($cursor));

    // URL-safe: a cursor goes in a query string, and a raw "+" there decodes
    // to a space.
    Assert::false(str_contains($cursor, '+'));
    Assert::false(str_contains($cursor, '/'));
    Assert::false(str_contains($cursor, '='));

    Assert::null(ApiResource::decodeCursor(null));
    Assert::null(ApiResource::decodeCursor(''));
    Assert::null(ApiResource::decodeCursor('not-base64!!'));

    // Decoded garbage that is not a plain integer is refused rather than
    // becoming 0 — `WHERE id < 0` is an empty page that looks like the end of
    // the list.
    Assert::null(ApiResource::decodeCursor(base64_encode('1 OR 1=1')));
    Assert::null(ApiResource::decodeCursor(base64_encode('-5')));
});

Harness::test('a page is bounded whatever the caller asks for', function (): void {
    Assert::same(ApiResource::DEFAULT_PER_PAGE, ApiResource::perPage(''));
    Assert::same(ApiResource::DEFAULT_PER_PAGE, ApiResource::perPage('abc'));
    Assert::same(10, ApiResource::perPage('10'));

    // `?per_page=100000` is a page of 100, not an outage.
    Assert::same(ApiResource::MAX_PER_PAGE, ApiResource::perPage('100000'));
    Assert::same(1, ApiResource::perPage('0'));
    Assert::same(1, ApiResource::perPage('-20'));
});

// ----------------------------------------------- deleting is its own power

Harness::test('DELETING NAMES ITS OWN PERMISSION, NEVER THE WRITE ONE', function (): void {
    /*
     * The hole this closes, and why it is worth a test of its own.
     *
     * This application separates editing from deleting everywhere a page does
     * it: `contacts_manage` edits a guest and `contacts_delete` removes one,
     * `bookings_manage` edits a party and `bookings_cancel` takes it off the
     * calendar. It separates them because the front-desk role an operator
     * actually builds holds the first and not the second.
     *
     * The API's DELETE checked the WRITE permission, so a front-desk token
     * could delete through `/api/v1` exactly what the same person's own pages
     * refuse them — and nothing on any page would have shown it. Found by
     * review.
     */
    foreach (ApiResource::RESOURCES as $name => $definition) {
        if ($definition['delete'] === null) {
            continue;
        }

        Assert::notSame(
            $definition['write'],
            $definition['delete'],
            $name . ' deletes under its write permission, which is the hole this test exists for'
        );

        Assert::hasKey(
            (string) $definition['delete'],
            \App\Core\Acl::CATALOG,
            $name . ' names a delete permission that is not in the catalogue, so nothing can grant it'
        );

        // A delete permission on a resource with no `deleted_at` column would
        // be a promise the controller cannot keep.
        Assert::true(
            (bool) $definition['soft_delete'],
            $name . ' names a delete permission but has nothing to soft-delete into'
        );
    }
});

Harness::test('the resources a venue deletes through the API are the ones with a delete key', function (): void {
    // Stated as data rather than derived, so that ADDING a soft-deletable
    // resource is a decision somebody makes here instead of an endpoint that
    // opens itself.
    $expected = [
        'contacts'   => 'contacts_delete',
        'companies'  => 'contacts_delete',
        'deals'      => 'deals_delete',
        'bookings'   => 'bookings_cancel',
    ];

    $actual = [];

    foreach (ApiResource::RESOURCES as $name => $definition) {
        if ($definition['delete'] !== null) {
            $actual[$name] = (string) $definition['delete'];
        }
    }

    Assert::same($expected, $actual);
});

Harness::test('A STAGE IS NOT A WRITABLE COLUMN ON A DEAL', function (): void {
    /*
     * `stage_id` was in this list, and a PATCH that set it skipped every
     * consequence of a move: the stage clock the velocity report measures,
     * `won_at`/`lost_at`, the SLA deadline, the stage-history row the
     * pipeline report reads, and `deal.stage_changed`/`deal.won`/`deal.lost`
     * — the events that start the deposit chase and the thank-you. The board
     * would show the deal in "Won" with no won date, no history and no
     * automation ever running.
     *
     * `POST /api/v1/deals/{id}/stage` goes through `DealStage::move()`
     * instead, which is the path the board itself posts to.
     */
    $deals = ApiResource::get('deals') ?? [];

    Assert::notInArray('stage_id', $deals['writable'], 'a stage is a move, not a field');
    Assert::notInArray('status', $deals['writable'], 'won and lost are transitions');

    // Still READABLE: an integration has to be able to see where a deal is.
    Assert::inArray('stage_id', $deals['fields']);
    Assert::inArray('status', $deals['fields']);

    // `pipeline_id` stays writable — moving between boards is a plain
    // reassignment, and `DealStage::move()` refuses a stage from another
    // pipeline, so the two cannot disagree.
    Assert::inArray('pipeline_id', $deals['writable']);
});

// ------------------------------------------- a field that names a record

Harness::test('A FIELD THAT NAMES ANOTHER RECORD HAS TO BE AN ID', function (): void {
    /*
     * THE ROOT CAUSE OF THREE SEPARATE BUGS, fixed here once instead of a
     * fourth time at a fourth call site.
     *
     * `writable()` filtered WHICH keys could be written and never what shape
     * they were. PHP casts `true` to 1 and `"abc"` to 0, so every `*_id` field
     * in every resource quietly resolved to "record 1" or "no record" and
     * answered 200: a stage named by `true` moved a deal to stage 1, a
     * pipeline named by `null` relocated it to the first board, a booking
     * named by `true` filed a guest's visit against somebody else's party.
     * Three symptoms, one cause.
     */
    foreach ([true, false, 'abc', '', '  ', 0, -1, 1.5, ['x'], (object) [], '12abc', '1.0'] as $rubbish) {
        Assert::notNull(
            ApiResource::idProblem($rubbish),
            var_export($rubbish, true) . ' is not a record id and must be refused'
        );
    }

    // What a real client sends, including one that quotes its numbers.
    foreach ([1, 42, 999999, '1', '42', ' 42 '] as $good) {
        Assert::null(ApiResource::idProblem($good), var_export($good, true) . ' is a record id');
    }

    // Null clears a link, which is a real thing to want — "this deal is not
    // attached to a company any more". The column decides whether it may.
    Assert::null(ApiResource::idProblem(null));
});

Harness::test('every foreign key in a writable list is covered by that rule', function (): void {
    // The rule is by NAME, so this asserts the names really do follow the
    // convention it depends on — a foreign key called `company` rather than
    // `company_id` would slip past it silently.
    $covered   = 0;
    $uncovered = [];

    foreach (ApiResource::RESOURCES as $name => $definition) {
        foreach ($definition['writable'] as $field) {
            if (ApiResource::namesARecord($field)) {
                $covered++;

                continue;
            }

            // A foreign system's own identifier, exempt by name and on
            // purpose: a CenterEdge customer number is whatever CenterEdge
            // says it is, not the id of a row here.
            if (in_array($field, ['external_pos_id', 'external_ref'], true)) {
                continue;
            }

            // A field that LOOKS like a reference but is not covered.
            if (preg_match('/(^|_)(id|ref|key)$/', $field) === 1) {
                $uncovered[] = $name . '.' . $field;
            }
        }
    }

    Assert::greaterThan(5, $covered, 'the rule should be protecting real foreign keys');

    /*
     * EMPTY, and that is the assertion. Every writable field that reads like a
     * reference ends in `_id` and is therefore covered, except the helper's
     * two named exemptions — `external_pos_id` and `external_ref`, a foreign
     * system's own strings, which may be anything at all. `external_pos_id`
     * became writable on contacts so the CenterEdge bridge can record the
     * customer number it matches on.
     *
     * If this ever fails it is naming a foreign key the id rule does not
     * protect, which is the hole this whole family of bugs came through.
     */
    Assert::same([], $uncovered);
});

Harness::test('writable() refuses a bad id by name, and still writes a good one', function (): void {
    $contacts = ApiResource::get('contacts') ?? [];

    $result = ApiResource::writable([
        'first_name'   => 'Dana',
        'household_id' => true,
        'company_id'   => 'abc',
    ], $contacts);

    Assert::same(['first_name' => 'Dana'], $result['data'], 'the good field is kept');
    Assert::same([], $result['refused']);
    Assert::hasKey('household_id', $result['invalid']);
    Assert::hasKey('company_id', $result['invalid']);

    // Normalised to an int, so a downstream `(int)` cast cannot be the thing
    // that decides anything.
    $good = ApiResource::writable(['household_id' => ' 42 ', 'company_id' => null], $contacts);

    Assert::same(42, $good['data']['household_id']);
    Assert::null($good['data']['company_id'], 'null clears the link');
    Assert::same([], $good['invalid']);
});
