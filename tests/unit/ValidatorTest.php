<?php

declare(strict_types=1);

/**
 * Validator.
 *
 * The min/max tests exist because of a real bug: 'max:20' on a postal_code
 * compared "10918" as a NUMBER, silently rejecting every valid ZIP in the
 * venue's own state. Numeric-vs-length now comes from the declared type.
 */

use App\Core\Validator;
use Tests\Assert;
use Tests\Harness;

Harness::suite('unit/validator');

// --------------------------------------------------------------- required

Harness::test('required rejects missing, empty and empty-array values', function (): void {
    foreach ([[], ['a' => ''], ['a' => null], ['a' => []]] as $data) {
        Assert::false(Validator::make($data, ['a' => 'required'])->passes());
    }
});

Harness::test('required accepts zero and "0"', function (): void {
    // A guest count of 0 or a price of "0" is a real value, not an absence.
    Assert::true(Validator::make(['a' => 0], ['a' => 'required|int'])->passes());
    Assert::true(Validator::make(['a' => '0'], ['a' => 'required|string'])->passes());
});

Harness::test('nullable skips validation for an absent value', function (): void {
    Assert::true(Validator::make([], ['a' => 'nullable|email'])->passes());
    Assert::true(Validator::make(['a' => ''], ['a' => 'nullable|email'])->passes());
});

// ------------------------------------------------------------ min and max

Harness::test('max on a string field measures LENGTH, not value', function (): void {
    // The regression. A ZIP, phone or order reference is a numeric-looking
    // string whose length is what matters.
    Assert::true(
        Validator::make(['postal_code' => '10918'], ['postal_code' => 'nullable|string|max:20'])->passes(),
        'a 5-character ZIP must pass max:20'
    );

    Assert::false(
        Validator::make(['postal_code' => str_repeat('9', 21)], ['postal_code' => 'nullable|string|max:20'])->passes(),
        'a 21-character value must fail max:20'
    );
});

Harness::test('max on a numeric field measures VALUE', function (): void {
    Assert::true(Validator::make(['rate' => '8.125'], ['rate' => 'nullable|numeric|max:30'])->passes());
    Assert::false(Validator::make(['rate' => '31'], ['rate' => 'nullable|numeric|max:30'])->passes());
});

Harness::test('min on a numeric field measures VALUE', function (): void {
    Assert::false(Validator::make(['n' => '-1'], ['n' => 'int|min:0'])->passes());
    Assert::true(Validator::make(['n' => '0'], ['n' => 'int|min:0'])->passes());
});

Harness::test('min on a string field measures LENGTH', function (): void {
    Assert::false(Validator::make(['p' => 'short'], ['p' => 'required|string|min:12'])->passes());
    Assert::true(Validator::make(['p' => 'a-long-enough-passphrase'], ['p' => 'required|string|min:12'])->passes());
});

Harness::test('min and max count items on an array field', function (): void {
    Assert::false(Validator::make(['t' => ['a']], ['t' => 'array|min:2'])->passes());
    Assert::true(Validator::make(['t' => ['a', 'b']], ['t' => 'array|min:2'])->passes());
    Assert::false(Validator::make(['t' => ['a', 'b', 'c']], ['t' => 'array|max:2'])->passes());
});

// ----------------------------------------------------------------- formats

Harness::test('email accepts real addresses and rejects malformed ones', function (): void {
    foreach (['a@b.co', 'parent+party@example.test', 'first.last@sub.domain.org'] as $good) {
        Assert::true(Validator::make(['e' => $good], ['e' => 'email'])->passes(), $good);
    }

    foreach (['not-an-email', 'a@b', '@example.test', 'a b@example.test', 'a@'] as $bad) {
        Assert::false(Validator::make(['e' => $bad], ['e' => 'email'])->passes(), $bad);
    }
});

Harness::test('phone accepts formatted US numbers and rejects short ones', function (): void {
    foreach (['(845) 469-2116', '845-469-2116', '18454692116'] as $good) {
        Assert::true(Validator::make(['p' => $good], ['p' => 'phone'])->passes(), $good);
    }

    foreach (['12345', 'abc', '1'] as $bad) {
        Assert::false(Validator::make(['p' => $bad], ['p' => 'phone'])->passes(), $bad);
    }
});

Harness::test('url requires an http(s) scheme', function (): void {
    Assert::true(Validator::make(['u' => 'https://thecastlefuncenter.com'], ['u' => 'url'])->passes());
    Assert::false(Validator::make(['u' => 'thecastlefuncenter.com'], ['u' => 'url'])->passes());
    Assert::false(Validator::make(['u' => 'javascript:alert(1)'], ['u' => 'url'])->passes());
});

Harness::test('int rejects a decimal and a numeric string with padding', function (): void {
    Assert::true(Validator::make(['n' => '42'], ['n' => 'int'])->passes());
    Assert::false(Validator::make(['n' => '4.2'], ['n' => 'int'])->passes());
    Assert::false(Validator::make(['n' => 'twelve'], ['n' => 'int'])->passes());
});

Harness::test('in restricts to an allowed set', function (): void {
    Assert::true(Validator::make(['s' => 'won'], ['s' => 'in:open,won,lost'])->passes());
    Assert::false(Validator::make(['s' => 'maybe'], ['s' => 'in:open,won,lost'])->passes());
});

Harness::test('time accepts 24-hour values only', function (): void {
    Assert::true(Validator::make(['t' => '09:00'], ['t' => 'time'])->passes());
    Assert::true(Validator::make(['t' => '23:59'], ['t' => 'time'])->passes());
    Assert::false(Validator::make(['t' => '24:00'], ['t' => 'time'])->passes());
    Assert::false(Validator::make(['t' => '9am'], ['t' => 'time'])->passes());
});

Harness::test('color requires a six-digit hex value', function (): void {
    Assert::true(Validator::make(['c' => '#6b4ea8'], ['c' => 'color'])->passes());
    Assert::false(Validator::make(['c' => '6b4ea8'], ['c' => 'color'])->passes());
    Assert::false(Validator::make(['c' => '#abc'], ['c' => 'color'])->passes());
});

Harness::test('timezone accepts a real identifier only', function (): void {
    Assert::true(Validator::make(['z' => 'America/New_York'], ['z' => 'timezone'])->passes());
    Assert::false(Validator::make(['z' => 'Mars/Olympus'], ['z' => 'timezone'])->passes());
});

Harness::test('slug enforces lowercase-hyphen form', function (): void {
    Assert::true(Validator::make(['s' => 'laser-tag-birthday'], ['s' => 'slug'])->passes());
    Assert::false(Validator::make(['s' => 'Laser Tag'], ['s' => 'slug'])->passes());
    Assert::false(Validator::make(['s' => 'laser_tag'], ['s' => 'slug'])->passes());
});

Harness::test('confirmed compares against the _confirmation field', function (): void {
    Assert::true(Validator::make(
        ['p' => 'secret-passphrase', 'p_confirmation' => 'secret-passphrase'],
        ['p' => 'confirmed']
    )->passes());

    Assert::false(Validator::make(
        ['p' => 'secret-passphrase', 'p_confirmation' => 'different'],
        ['p' => 'confirmed']
    )->passes());
});

// ------------------------------------------------------------- behaviours

Harness::test('an unknown rule fails closed', function (): void {
    // A typo'd rule must not silently pass the field through unvalidated.
    Assert::false(Validator::make(['a' => 'x'], ['a' => 'no_such_rule'])->passes());
});

Harness::test('validated() returns only fields that had rules and passed', function (): void {
    $validator = Validator::make(
        ['name' => 'Ada', 'sneaky' => 'role_id=1', 'bad' => 'nope'],
        ['name' => 'required|string', 'bad' => 'int']
    );

    $validated = $validator->validated();

    Assert::hasKey('name', $validated);
    Assert::false(array_key_exists('sneaky', $validated), 'an unruled field must not be returned');
    Assert::false(array_key_exists('bad', $validated), 'a failed field must not be returned');
});

Harness::test('validated() casts by declared type', function (): void {
    $validated = Validator::make(
        ['n' => '42', 'f' => '1.5', 'b' => 'on', 'e' => 'A@B.CO'],
        ['n' => 'int', 'f' => 'numeric', 'b' => 'bool', 'e' => 'email']
    )->validated();

    Assert::same(42, $validated['n']);
    Assert::same(1.5, $validated['f']);
    Assert::same(true, $validated['b']);
    Assert::same('a@b.co', $validated['e'], 'emails are lowercased for consistent matching');
});

Harness::test('messages name the field in human terms', function (): void {
    $errors = Validator::make([], ['first_name' => 'required'])->firstErrors();

    Assert::contains('First name', $errors['first_name']);
    Assert::notContains('first_name', $errors['first_name'], 'a user should not see a column name');
});

Harness::test('a custom label overrides the derived one', function (): void {
    $errors = Validator::make([], ['role_id' => 'required'], ['role_id' => 'Role'])->firstErrors();

    Assert::contains('Role is required', $errors['role_id']);
});

Harness::test('validation stops at the first failing rule per field', function (): void {
    // Reporting "must be text" and "must be 12 characters" together would be
    // noise; the first problem is the one to fix.
    $errors = Validator::make(['a' => 'x'], ['a' => 'string|min:12|max:5'])->errors();

    Assert::count(1, $errors['a']);
});
