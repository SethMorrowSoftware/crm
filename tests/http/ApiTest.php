<?php

declare(strict_types=1);

/**
 * The REST API, over HTTP, with real tokens.
 *
 * The API is the easiest place in this application to leak what the UI hides:
 * it has no pages to look at, no screenshots anybody reviews, and an
 * integration that quietly receives too much will never complain. So these
 * tests are mostly about what the API REFUSES.
 *
 * The four that matter most:
 *
 *   1. **A read-only token minted BY THE OWNER cannot write.** The owner
 *      bypasses every permission check in this codebase, so if the token's
 *      abilities were applied after that bypass, every token an owner ever
 *      created would be a full-access token whatever its abilities said —
 *      and nothing on any page would show it.
 *   2. **The API accepts a token and never a session.** A route that took
 *      both would be CSRF-able from any logged-in tab, because the API has
 *      no form to carry a token in.
 *   3. **Money follows `view_revenue`**, as it does on every page.
 *   4. **A record outside the caller's scope is a 404, not a 403.** A 403
 *      confirms the record exists, which is how an id space gets walked.
 */

use App\Core\ApiToken;
use App\Core\Auth;
use App\Core\DB;
use Tests\Assert;
use Tests\Harness;

Harness::suite('http/api');

$baseUrl = getenv('CC_TEST_BASE_URL') ?: '';

if ($baseUrl === '') {
    Harness::skip('api suite', 'no server; run via tests/run.php');

    return;
}

if (!test_boot()) {
    Harness::skip('api suite', 'no database available');

    return;
}

require_once CC_ROOT . '/tests/TestBrowser.php';

// ------------------------------------------------------------------ fixtures

/*
 * DATA ONLY. The tokens minted below are the credentials for the whole file,
 * so a clear that removed them turned every later test into a 401 — which
 * reads as a broken API rather than a broken fixture. `ha_clear_tokens()` is
 * separate and runs once at the end.
 */
function ha_clear(): void
{
    DB::query("DELETE FROM {consent_log} WHERE source LIKE 'ha-%'");
    DB::query("DELETE FROM {visits} WHERE contact_id IN (SELECT id FROM {contacts} WHERE source = 'hatest')");
    DB::query("DELETE FROM {deals} WHERE source = 'hatest'");
    DB::query("DELETE FROM {bookings} WHERE source = 'hatest'");
    DB::query("DELETE FROM {contacts} WHERE source = 'hatest'");
}

function ha_clear_tokens(): void
{
    DB::query("DELETE FROM {api_tokens} WHERE name LIKE 'HA %'");
}

function ha_clear_users(): void
{
    $roleIds = DB::column("SELECT id FROM {roles} WHERE slug LIKE 'hatest-%'");

    foreach ($roleIds as $roleId) {
        DB::query('DELETE FROM {api_tokens} WHERE user_id IN (SELECT id FROM {users} WHERE role_id = :r)', ['r' => (int) $roleId]);
        DB::query('DELETE FROM {users} WHERE role_id = :r', ['r' => (int) $roleId]);
        DB::query('DELETE FROM {role_permissions} WHERE role_id = :r', ['r' => (int) $roleId]);
        DB::query('DELETE FROM {roles} WHERE id = :r', ['r' => (int) $roleId]);
    }
}

/** @param array<string,string> $permissions */
function ha_role(string $slug, array $permissions): int
{
    $roleId = (int) DB::insert('roles', [
        'slug'        => 'hatest-' . $slug,
        'name'        => 'HA ' . ucfirst($slug),
        'description' => 'Created by the API test suite.',
        'is_system'   => 0,
        'sort_order'  => 220,
        'created_at'  => now_utc(),
        'updated_at'  => now_utc(),
    ]);

    foreach ($permissions as $permissionSlug => $scope) {
        $permissionId = (int) DB::value('SELECT id FROM {permissions} WHERE slug = :s', ['s' => $permissionSlug]);
        Assert::greaterThan(0, $permissionId, 'permission should be seeded: ' . $permissionSlug);

        DB::insert('role_permissions', [
            'role_id'       => $roleId,
            'permission_id' => $permissionId,
            'scope'         => $scope,
        ]);
    }

    return $roleId;
}

function ha_user(int $roleId, string $label): int
{
    return (int) DB::insert('users', [
        'name'          => 'HA ' . ucfirst($label),
        'email'         => 'ha-' . $label . '-' . bin2hex(random_bytes(3)) . '@test.invalid',
        'password_hash' => Auth::hash('api-suite-passphrase-9'),
        'role_id'       => $roleId,
        'is_active'     => 1,
        'created_at'    => now_utc(),
        'updated_at'    => now_utc(),
    ]);
}

/** @param list<string> $abilities */
function ha_token(int $userId, string $name, array $abilities = ['*'], ?string $expires = null): string
{
    return ApiToken::create($userId, 'HA ' . $name, $abilities, $expires)['token'];
}

/**
 * One API call. Deliberately NOT a TestBrowser: the whole point is that this
 * carries a bearer token and no cookie jar at all.
 *
 * @return array{status:int,body:string,json:array<mixed>,headers:string}
 */
function ha_call(string $method, string $path, ?string $token, ?array $body = null, array $extraHeaders = []): array
{
    $base = getenv('CC_TEST_BASE_URL') ?: '';

    $handle  = curl_init();
    $headers = array_merge(['Accept: application/json'], $extraHeaders);

    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $options = [
        CURLOPT_URL            => $base . $path,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => $headers,
        // No cookie jar whatsoever: an API client has no session and this
        // test must not accidentally become a browser one.
        CURLOPT_COOKIEFILE     => '',
        CURLOPT_FOLLOWLOCATION => false,
    ];

    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR);
        $options[CURLOPT_HTTPHEADER] = array_merge($headers, ['Content-Type: application/json']);
    }

    curl_setopt_array($handle, $options);

    $raw = curl_exec($handle);

    if ($raw === false) {
        $error = curl_error($handle);
        curl_close($handle);

        throw new RuntimeException('API call to ' . $path . ' failed: ' . $error);
    }

    $status     = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    curl_close($handle);

    $responseBody = substr((string) $raw, $headerSize);
    $decoded      = json_decode($responseBody, true);

    return [
        'status'  => $status,
        'body'    => $responseBody,
        'json'    => is_array($decoded) ? $decoded : [],
        'headers' => substr((string) $raw, 0, $headerSize),
    ];
}

ha_clear();
ha_clear_users();
ha_clear_tokens();

$ownerId = (int) DB::value(
    "SELECT u.id FROM {users} u JOIN {roles} r ON r.id = u.role_id
      WHERE r.slug = 'owner' AND u.is_active = 1 ORDER BY u.id LIMIT 1"
);

Assert::greaterThan(0, $ownerId, 'precondition: an owner exists');

$full     = ha_token($ownerId, 'full');
$readOnly = ha_token($ownerId, 'read only', ['view_contacts', 'view_bookings', 'view_deals']);

// A role with no view_revenue, for the money tests.
$deskRole = ha_role('desk', ['view_contacts' => 'all', 'view_bookings' => 'all', 'contacts_manage' => 'all']);
$deskUser = ha_user($deskRole, 'desk');
$desk     = ha_token($deskUser, 'desk');

// ================================================================= the door

Harness::test('no token is a 401 with a JSON body, never a redirect', function (): void {
    $response = ha_call('GET', '/api/v1/contacts', null);

    Assert::same(401, $response['status']);

    // A 302 to the login page is a 200-with-HTML to most HTTP clients, so an
    // integration whose token expired would see a login form where it
    // expected JSON and report success.
    Assert::contains('error', $response['body']);
    Assert::contains('WWW-Authenticate', $response['headers']);
});

Harness::test('a token that is not real is a 401, and says nothing about why', function (): void {
    foreach (['cc_live_' . str_repeat('a', 64), 'not-even-a-token', ''] as $bad) {
        $response = ha_call('GET', '/api/v1/contacts', $bad === '' ? null : $bad);

        Assert::same(401, $response['status'], 'rejected: ' . $bad);
    }

    // Unknown, expired and deactivated all read the same. Telling a caller
    // which would let them sort real tokens from invented ones.
    $unknown = ha_call('GET', '/api/v1/contacts', 'cc_live_' . str_repeat('b', 64));
    Assert::notContains('expired', strtolower($unknown['body']));
    Assert::notContains('user', strtolower($unknown['body']));
});

Harness::test('an EXPIRED token stops working, and a revoked one stops immediately', function () use ($ownerId): void {
    $expired = ha_token($ownerId, 'expired', ['*'], gmdate('Y-m-d H:i:s', time() - 60));

    Assert::same(401, ha_call('GET', '/api/v1/contacts', $expired)['status']);

    $live = ha_token($ownerId, 'to revoke');
    Assert::same(200, ha_call('GET', '/api/v1/contacts', $live)['status']);

    DB::query('DELETE FROM {api_tokens} WHERE token_hash = :h', ['h' => ApiToken::hash($live)]);

    // The NEXT request, not when something expires.
    Assert::same(401, ha_call('GET', '/api/v1/contacts', $live)['status']);
});

Harness::test('a token whose owner is deactivated stops working', function () use ($deskRole): void {
    $userId = ha_user($deskRole, 'leaver');
    $token  = ha_token($userId, 'leaver');

    Assert::same(200, ha_call('GET', '/api/v1/contacts', $token)['status']);

    DB::update('users', ['is_active' => 0], 'id = :id', ['id' => $userId]);

    // Nobody had to remember to revoke it. A token is a key cut for a person,
    // and when the person goes the key stops turning.
    Assert::same(401, ha_call('GET', '/api/v1/contacts', $token)['status']);
});

Harness::test('a browser session is NOT a credential for the API', function () use ($baseUrl): void {
    /*
     * The one that makes the CSRF exemption safe. A logged-in session must
     * not reach the API — if it did, every write would be forgeable from any
     * page a staff member visits while signed in, because the API has no form
     * and therefore no CSRF token.
     */
    $browser = new TestBrowser($baseUrl, 'api-session');
    $browser->login('owner@test.invalid', 'owner-test-passphrase-9');

    Assert::same(200, $browser->get('/account')['status'], 'precondition: the session works');

    $response = $browser->get('/api/v1/contacts');

    Assert::same(401, $response['status'], 'a session cookie must not authenticate the API');
});

// ============================================================ the narrowing

Harness::test('A READ-ONLY TOKEN MINTED BY THE OWNER CANNOT WRITE', function () use ($readOnly): void {
    /*
     * THE TEST THIS WHOLE FEATURE TURNS ON.
     *
     * The owner bypasses every permission check in this codebase. If the
     * token's abilities were applied AFTER that bypass, every token an owner
     * ever created would carry full access whatever its abilities said — the
     * exact opposite of what they chose on the page, and invisible until
     * something wrote.
     */
    Assert::same(200, ha_call('GET', '/api/v1/contacts', $readOnly)['status'], 'it reads');

    $write = ha_call('POST', '/api/v1/contacts', $readOnly, [
        'first_name' => 'Should',
        'last_name'  => 'Not Exist',
        'source'     => 'hatest',
    ]);

    Assert::same(403, $write['status']);
    Assert::same('contacts_manage', $write['json']['permission'] ?? null);

    Assert::false(
        DB::exists("SELECT id FROM {contacts} WHERE last_name = 'Not Exist'"),
        'and nothing was written'
    );
});

Harness::test('a token cannot reach a resource outside its abilities', function () use ($readOnly): void {
    // The token holds view_contacts, view_bookings and view_deals. Everything
    // else its OWNER can read is still refused.
    foreach (['reviews' => 'view_reputation', 'memberships' => 'view_memberships'] as $resource => $permission) {
        $response = ha_call('GET', '/api/v1/' . $resource, $readOnly);

        Assert::same(403, $response['status'], $resource);
        Assert::same($permission, $response['json']['permission'] ?? null);
    }
});

Harness::test('a token cannot reach what its USER cannot reach either', function () use ($desk): void {
    // The desk token asks for everything its owner can do — and its owner
    // cannot see reviews. Abilities narrow; they never add.
    Assert::same(403, ha_call('GET', '/api/v1/reviews', $desk)['status']);
    Assert::same(403, ha_call('GET', '/api/v1/memberships', $desk)['status']);
});

Harness::test('the index lists only what this token can really do', function () use ($desk, $full): void {
    $deskIndex = ha_call('GET', '/api/v1', $desk);

    Assert::same(200, $deskIndex['status']);
    Assert::hasKey('contacts', $deskIndex['json']['resources']);
    Assert::false(isset($deskIndex['json']['resources']['reviews']));
    Assert::false((bool) $deskIndex['json']['you']['revenue']);

    $ownerIndex = ha_call('GET', '/api/v1', $full);
    Assert::hasKey('reviews', $ownerIndex['json']['resources']);
    Assert::true((bool) $ownerIndex['json']['you']['revenue']);
});

// =================================================================== money

Harness::test('money is EMPTY for a money-blind token, and the keys stay', function () use ($desk, $full): void {
    ha_clear();

    DB::insert('bookings', [
        'booking_number' => 'HA-' . bin2hex(random_bytes(3)),
        'type'           => 'birthday',
        'status'         => 'confirmed',
        'event_date'     => gmdate('Y-m-d'),
        'guest_count'    => 14,
        'total_cents'    => 54900,
        'deposit_cents'  => 10000,
        'balance_due_cents' => 44900,
        'source'         => 'hatest',
        'created_at'     => now_utc(),
        'updated_at'     => now_utc(),
    ]);

    $rich = ha_call('GET', '/api/v1/bookings?per_page=50', $full);
    $poor = ha_call('GET', '/api/v1/bookings?per_page=50', $desk);

    Assert::same(200, $rich['status']);
    Assert::same(200, $poor['status']);

    $find = static function (array $response): ?array {
        foreach ($response['json']['data'] ?? [] as $row) {
            if (str_starts_with((string) ($row['booking_number'] ?? ''), 'HA-')) {
                return $row;
            }
        }

        return null;
    };

    $withMoney = $find($rich);
    $without   = $find($poor);

    Assert::notNull($withMoney, 'the booking is visible to the owner');
    Assert::notNull($without, 'and to the front desk');

    Assert::same(54900, $withMoney['total_cents']);

    // The key stays, holding null. A key that vanishes reads as "this booking
    // has no total".
    Assert::true(array_key_exists('total_cents', $without));
    Assert::null($without['total_cents']);
    Assert::same(14, $without['guest_count'], 'and the counts are untouched');

    Assert::true((bool) ($poor['json']['meta']['money_hidden'] ?? false), 'and it says so');
    Assert::false((bool) ($rich['json']['meta']['money_hidden'] ?? true));

    ha_clear();
});

// ================================================================== paging

Harness::test('the page is bounded and the cursor walks without repeating', function () use ($full): void {
    ha_clear();

    for ($i = 0; $i < 7; $i++) {
        DB::insert('contacts', [
            'first_name' => 'Pager' . $i,
            'last_name'  => 'Test',
            'email'      => 'pager' . $i . '.' . bin2hex(random_bytes(3)) . '@example.test',
            'source'     => 'hatest',
            'created_at' => now_utc(),
            'updated_at' => now_utc(),
        ]);
    }

    $seen   = [];
    $cursor = null;
    $pages  = 0;

    do {
        $path = '/api/v1/contacts?per_page=3' . ($cursor === null ? '' : '&cursor=' . urlencode($cursor));
        $page = ha_call('GET', $path, $full);

        Assert::same(200, $page['status']);

        foreach ($page['json']['data'] as $row) {
            Assert::false(in_array($row['id'], $seen, true), 'id ' . $row['id'] . ' came back twice');
            $seen[] = $row['id'];
        }

        $cursor = $page['json']['meta']['next_cursor'] ?? null;
        $pages++;
    } while ($cursor !== null && $pages < 40);

    Assert::greaterThan(6, count($seen), 'it walked the whole list');
    Assert::true($pages < 40, 'and it terminated');

    // A huge per_page is a bounded page, not an outage.
    $huge = ha_call('GET', '/api/v1/contacts?per_page=100000', $full);
    Assert::same(100, $huge['json']['meta']['per_page']);
    Assert::true(count($huge['json']['data']) <= 100);

    ha_clear();
});

// ================================================================= writing

Harness::test('a write refuses fields it will not write rather than ignoring them', function () use ($full): void {
    ha_clear();

    $created = ha_call('POST', '/api/v1/contacts', $full, [
        'first_name' => 'Written',
        'last_name'  => 'ByApi',
        'email'      => 'written.byapi@example.test',
        'source'     => 'hatest',
    ]);

    Assert::same(201, $created['status']);
    $id = (int) $created['json']['data']['id'];

    $refused = ha_call('PATCH', '/api/v1/contacts/' . $id, $full, [
        'city'          => 'Chester',
        'visit_count'   => 999,
        'email_consent' => true,
    ]);

    Assert::same(422, $refused['status']);
    Assert::inArray('visit_count', $refused['json']['refused']);
    Assert::inArray('email_consent', $refused['json']['refused']);

    // AND NOTHING WAS WRITTEN. A partial write that applied `city` and
    // refused the rest would leave the caller unable to tell what landed.
    $row = DB::first('SELECT city, visit_count FROM {contacts} WHERE id = :id', ['id' => $id]);
    Assert::null($row['city'], 'the valid field was not applied either');
    Assert::same(0, (int) $row['visit_count']);

    ha_clear();
});

Harness::test('a soft delete is a soft delete', function () use ($full): void {
    ha_clear();

    $created = ha_call('POST', '/api/v1/contacts', $full, [
        'first_name' => 'Deleted',
        'last_name'  => 'ByApi',
        'source'     => 'hatest',
    ]);

    $id = (int) $created['json']['data']['id'];

    Assert::same(200, ha_call('DELETE', '/api/v1/contacts/' . $id, $full)['status']);
    Assert::same(404, ha_call('GET', '/api/v1/contacts/' . $id, $full)['status'], 'gone from the API');

    // The row is still there, with its visits and its consent evidence, which
    // is what a soft delete is for.
    Assert::true(
        DB::exists('SELECT id FROM {contacts} WHERE id = :id AND deleted_at IS NOT NULL', ['id' => $id]),
        'the row survives, marked deleted'
    );

    ha_clear();
});

Harness::test('CONSENT NEEDS ITS EVIDENCE, and withdrawing never does', function () use ($full): void {
    ha_clear();

    $created = ha_call('POST', '/api/v1/contacts', $full, [
        'first_name' => 'Consent',
        'last_name'  => 'Tester',
        'email'      => 'consent.tester@example.test',
        'source'     => 'hatest',
    ]);

    $id = (int) $created['json']['data']['id'];

    // Granting with no disclosure: refused. A consent flag with no record of
    // what the guest agreed to is the thing a venue would have to produce if
    // a complaint ever went anywhere.
    $bare = ha_call('POST', '/api/v1/contacts/' . $id . '/consent', $full, [
        'channel' => 'email',
        'granted' => true,
        'source'  => 'ha-test',
    ]);

    Assert::same(422, $bare['status']);
    Assert::same(0, (int) DB::value('SELECT email_consent FROM {contacts} WHERE id = :id', ['id' => $id]));

    $granted = ha_call('POST', '/api/v1/contacts/' . $id . '/consent', $full, [
        'channel'    => 'email',
        'granted'    => true,
        'source'     => 'ha-test',
        'disclosure' => 'I agree to receive email from the venue.',
    ]);

    Assert::same(200, $granted['status']);
    Assert::same(1, (int) DB::value('SELECT email_consent FROM {contacts} WHERE id = :id', ['id' => $id]));

    $log = DB::first(
        "SELECT action, evidence FROM {consent_log} WHERE contact_id = :c ORDER BY id DESC LIMIT 1",
        ['c' => $id]
    );
    Assert::same('granted', (string) $log['action']);
    Assert::contains('I agree to receive email', (string) $log['evidence'], 'the words are kept');

    // WITHDRAWING needs nothing. A guest saying stop is honoured
    // unconditionally — refusing to record it because a field was missing
    // would be the worst possible reading of a compliance rule.
    $withdrawn = ha_call('POST', '/api/v1/contacts/' . $id . '/consent', $full, [
        'channel' => 'email',
        'granted' => false,
        'source'  => 'ha-test',
    ]);

    Assert::same(200, $withdrawn['status']);
    Assert::same(0, (int) DB::value('SELECT email_consent FROM {contacts} WHERE id = :id', ['id' => $id]));
    Assert::notNull(DB::value('SELECT marketing_opt_out_at FROM {contacts} WHERE id = :id', ['id' => $id]));

    ha_clear();
});

Harness::test('a visit goes through the recorder, so a repeat is not a second visit', function () use ($full): void {
    ha_clear();

    $created = ha_call('POST', '/api/v1/contacts', $full, [
        'first_name' => 'Visitor',
        'last_name'  => 'Tester',
        'source'     => 'hatest',
    ]);

    $id  = (int) $created['json']['data']['id'];
    $day = gmdate('Y-m-d', time() - 86400);

    $first = ha_call('POST', '/api/v1/visits', $full, [
        'contact_id'  => $id,
        'visit_date'  => $day,
        'source'      => 'check_in',
        'spend_cents' => 4200,
    ]);

    Assert::same(201, $first['status']);
    Assert::true((bool) $first['json']['data']['recorded']);

    // The same day again. A family checked in one child at a time is ONE
    // visit, and an integration retrying a failed batch depends on this
    // being idempotent rather than giving somebody eleven visits.
    $again = ha_call('POST', '/api/v1/visits', $full, [
        'contact_id'  => $id,
        'visit_date'  => $day,
        'source'      => 'check_in',
        'spend_cents' => 4200,
    ]);

    Assert::same(200, $again['status']);
    Assert::false((bool) $again['json']['data']['recorded']);
    Assert::same($first['json']['data']['id'], $again['json']['data']['id'], 'the same visit');
    Assert::notNull($again['json']['data']['note'], 'and it says why');

    Assert::same(
        1,
        (int) DB::value('SELECT COUNT(*) FROM {visits} WHERE contact_id = :c', ['c' => $id])
    );

    // The derived figures were refreshed, which is the other half of why a
    // visit does not go in as a plain insert.
    Assert::same(
        1,
        (int) DB::value('SELECT visit_count FROM {contacts} WHERE id = :id', ['id' => $id])
    );

    ha_clear();
});

Harness::test('read-only resources refuse a write with 405, not 500', function () use ($full): void {
    foreach (['memberships', 'reviews', 'messages'] as $resource) {
        $response = ha_call('POST', '/api/v1/' . $resource, $full, ['anything' => 1]);

        Assert::same(405, $response['status'], $resource);
        Assert::contains('read-only', strtolower($response['body']), $resource);
    }
});

Harness::test('an unknown resource is a 404 that names the problem', function () use ($full): void {
    $response = ha_call('GET', '/api/v1/unicorns', $full);

    Assert::same(404, $response['status']);
    Assert::contains('unicorns', $response['body']);
});

// =============================================================== the listing

Harness::test('a message listing carries no bodies; one message does', function () use ($full): void {
    $list = ha_call('GET', '/api/v1/messages?per_page=1', $full);

    Assert::same(200, $list['status']);

    if (($list['json']['data'] ?? []) === []) {
        Assert::true(true, 'no messages on this install to check');

        return;
    }

    $row = $list['json']['data'][0];

    // Paging is a bulk export wearing a different hat, so the text of every
    // message a venue has ever sent is not in it.
    Assert::false(array_key_exists('body', $row), 'a listing must not carry message text');
    Assert::false(array_key_exists('body_text', $row));

    $single = ha_call('GET', '/api/v1/messages/' . (int) $row['id'], $full);

    Assert::same(200, $single['status']);
    Assert::true(array_key_exists('body', $single['json']['data']), 'one deliberate read does');
});

Harness::test('updated_since filters, and rubbish is refused rather than ignored', function () use ($full): void {
    $good = ha_call('GET', '/api/v1/contacts?updated_since=2000-01-01', $full);
    Assert::same(200, $good['status']);

    $future = ha_call('GET', '/api/v1/contacts?updated_since=2099-01-01', $full);
    Assert::same(200, $future['status']);
    Assert::same([], $future['json']['data'], 'nothing is updated in 2099');

    // Ignored, it would silently return the whole table — and an integration
    // syncing incrementally would re-import everything on every poll without
    // knowing why.
    $bad = ha_call('GET', '/api/v1/contacts?updated_since=whenever', $full);
    Assert::same(422, $bad['status']);
});

ha_clear();
ha_clear_users();
ha_clear_tokens();
