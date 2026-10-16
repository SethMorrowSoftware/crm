<?php

declare(strict_types=1);

/**
 * What an API token's abilities mean, with no database and no server.
 *
 * `ApiToken::allows()` is the narrowing that makes a read-only token
 * read-only, and it narrows the OWNER too — who otherwise bypasses every
 * permission check in this codebase. So the direction it fails in is the
 * whole feature: an abilities list that is misread as "no restrictions"
 * turns the most locked-down token a venue can mint into the most powerful
 * one, and nothing on the page beside it would say so.
 *
 * It read exactly that way until review: an empty list was treated as a
 * synonym for `['*']`. The tests below pin every reachable shape of the
 * stored column, because the one that mattered was not hypothetical —
 * `cleanAbilities()` drops anything outside the permission catalogue, on
 * purpose, so a token asked for with a renamed or misspelled permission was
 * cleaned to `[]`, stored as `[]`, and read back as full access.
 */

use App\Core\Acl;
use App\Core\ApiToken;
use Tests\Assert;
use Tests\Harness;

Harness::suite('unit/api-token');

Harness::test('AN EMPTY ABILITY LIST GRANTS NOTHING', function (): void {
    /*
     * The one this file exists for. `[]` is a chosen set that survived
     * nothing — not an absent one — and the difference decides whether the
     * narrowest request anybody can make produces the widest token.
     */
    $token = ['id' => 1, 'abilities' => '[]'];

    Assert::same([], ApiToken::abilities($token));

    foreach (['view_contacts', 'contacts_manage', 'contacts_delete', 'settings',
              'users_manage', 'backup_manage', '*'] as $permission) {
        Assert::false(
            ApiToken::allows($token, $permission),
            'an empty list must not grant ' . $permission
        );
    }

    // And the same for the shapes that reduce to an empty list: a JSON object,
    // and a list whose entries are all non-strings.
    foreach (['{}', '[1,2,3]', '[true]', '[[]]'] as $raw) {
        Assert::same([], ApiToken::abilities(['abilities' => $raw]), $raw);
        Assert::false(ApiToken::allows(['abilities' => $raw], 'view_contacts'), $raw);
    }
});

Harness::test('NO value at all is the legacy full-access row, and stays one', function (): void {
    /*
     * Deliberately NOT the same as an empty list. A row whose column is null
     * or blank predates the column, when every token was a full-access token;
     * reading those as powerless would break a venue's working integration on
     * upgrade, silently, at whatever hour their nightly sync runs.
     *
     * Unparseable is read the same way for the same reason — a corrupt value
     * is not a choice anybody made, where `[]` is.
     */
    foreach ([null, '', '   ', 'not json at all', 'null'] as $raw) {
        $token = ['id' => 1, 'abilities' => $raw];

        Assert::same([ApiToken::ALL], ApiToken::abilities($token), var_export($raw, true));
        Assert::true(ApiToken::allows($token, 'contacts_manage'), var_export($raw, true));
    }

    // A token with no `abilities` key at all — a row read with a narrower
    // SELECT — reads the same.
    Assert::true(ApiToken::allows(['id' => 1], 'contacts_manage'));
});

Harness::test('a star grants everything its user holds, and a named list only itself', function (): void {
    $star = ['abilities' => '["*"]'];

    Assert::true(ApiToken::allows($star, 'view_contacts'));
    Assert::true(ApiToken::allows($star, 'backup_manage'));

    $narrow = ['abilities' => '["view_contacts","view_bookings"]'];

    Assert::true(ApiToken::allows($narrow, 'view_contacts'));
    Assert::true(ApiToken::allows($narrow, 'view_bookings'));

    // Everything else is refused, including the edit permission that looks
    // like a natural companion to the read one. A token that reads contacts
    // does not write them unless somebody said so.
    foreach (['contacts_manage', 'contacts_delete', 'view_deals', 'settings'] as $permission) {
        Assert::false(ApiToken::allows($narrow, $permission), $permission);
    }

    // A star anywhere in the list wins — the page offers it as a radio
    // choice, so a list carrying both is a UI the user did not see.
    Assert::true(ApiToken::allows(['abilities' => '["view_contacts","*"]'], 'settings'));
});

Harness::test('cleaning an ability list keeps only real permissions, and can empty it', function (): void {
    Assert::same(['view_contacts'], ApiToken::cleanAbilities(['view_contacts', 'nope']));
    Assert::same([ApiToken::ALL], ApiToken::cleanAbilities(['view_contacts', '*']));
    Assert::same(['view_contacts'], ApiToken::cleanAbilities(['view_contacts', ' view_contacts ']));

    /*
     * THE PATH THAT MADE THE FAIL-OPEN REACHABLE. Dropping an unrecognised
     * ability is right — one that was honoured would be a permission nothing
     * checks — but it means a list of nothing but typos cleans to `[]`. With
     * `allows()` failing closed that token now refuses every call, and
     * `ApiTokenController` declines to mint it at all.
     */
    Assert::same([], ApiToken::cleanAbilities(['contacts_create', 'view_everything']));
    Assert::same([], ApiToken::cleanAbilities([]));

    // Every permission the catalogue lists survives cleaning, so the page
    // cannot offer a checkbox that silently does nothing.
    $all = ApiToken::cleanAbilities(array_keys(Acl::CATALOG));

    Assert::count(count(Acl::CATALOG), $all);
});
