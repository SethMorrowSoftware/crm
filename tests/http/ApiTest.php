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
 * `$body` takes a scalar as well as an array, so a test can send a body that
 * is NOT a JSON object — a bare string, a number, a list — which is exactly
 * the shape a handler must refuse rather than cast.
 *
 * With `$raw` the body goes out byte for byte instead of being encoded,
 * which is the only way to send the bodies json_encode() cannot express from
 * PHP's own types: `{}` (an empty assoc array encodes as `[]`), `null` (the
 * encoder's own "no body" value), and bytes that are not JSON at all.
 *
 * @return array{status:int,body:string,json:array<mixed>,headers:string}
 */
function ha_call(
    string $method,
    string $path,
    ?string $token,
    array|string|int|null $body = null,
    array $extraHeaders = [],
    bool $raw = false
): array {
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
        $options[CURLOPT_POSTFIELDS] = $raw ? (string) $body : json_encode($body, JSON_THROW_ON_ERROR);
        $options[CURLOPT_HTTPHEADER] = array_merge($headers, ['Content-Type: application/json']);
    }

    curl_setopt_array($handle, $options);

    $raw = curl_exec($handle);

    if ($raw === false) {
        $error = curl_error($handle);

        throw new RuntimeException('API call to ' . $path . ' failed: ' . $error);
    }

    $status     = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);

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
        // UTC-CORRECT: the API index has no date window — it pages by id and
        // filters only on `updated_since` — so this row comes back whichever
        // calendar its event_date was written in. The subject is the money
        // keys, not the day.
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

    $id = (int) $created['json']['data']['id'];

    // UTC-CORRECT: this day is posted and then posted again, and the only
    // thing read from it is whether the two match. Nothing buckets it by the
    // venue's calendar, so the frame it is built in cannot change the answer.
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

// ============================================ scope, on the WRITING side

/*
 * The block review asked for, and the one the API had no test of at all.
 *
 * Scopes are per permission (`role_permissions.scope`), and `Acl::can()`
 * answers only whether a permission is held AT ALL — not how widely. So
 * "see everyone, edit your own" — an ordinary pairing an operator can build
 * in Settings — was honoured on the read and ignored on every write: the
 * record was loaded under the READ scope and then changed.
 */

$splitRole = ha_role('split', [
    'view_contacts'   => 'all',
    'contacts_manage' => 'own',
    'contacts_delete' => 'own',
    'view_deals'      => 'all',
    'deals_manage'    => 'own',
]);
$splitUser = ha_user($splitRole, 'split');
$split     = ha_token($splitUser, 'split');

/** A contact owned by somebody — or by nobody, which 0 means here. */
function ha_contact(int $ownerId, string $last): int
{
    return (int) DB::insert('contacts', [
        'first_name' => 'Scope',
        'last_name'  => $last,
        // `owner_id` is a real foreign key, so unowned is NULL rather than 0.
        'owner_id'   => $ownerId > 0 ? $ownerId : null,
        'source'     => 'hatest',
        'created_at' => now_utc(),
        'updated_at' => now_utc(),
    ]);
}

Harness::test('A WRITE IS SCOPED BY THE WRITE PERMISSION, NOT THE READ ONE', function () use ($split, $splitUser, $ownerId): void {
    ha_clear();

    $mine      = ha_contact($splitUser, 'Mine');
    $somebodys = ha_contact($ownerId, 'Somebody Elses');

    // Both are READABLE: view_contacts is held at 'all', and that half was
    // never in doubt.
    Assert::same(200, ha_call('GET', '/api/v1/contacts/' . $mine, $split)['status']);
    Assert::same(200, ha_call('GET', '/api/v1/contacts/' . $somebodys, $split)['status'], 'reading everyone is the point of all+own');

    // Mine, I may edit.
    Assert::same(200, ha_call('PATCH', '/api/v1/contacts/' . $mine, $split, ['city' => 'Chester'])['status']);

    /*
     * Somebody else's, I may not — and it is a 404, not a 403. A 403 here
     * would confirm the record exists, which is how an id space gets walked
     * by a token that was never meant to see one.
     */
    $patch = ha_call('PATCH', '/api/v1/contacts/' . $somebodys, $split, ['city' => 'Taken Over']);
    Assert::same(404, $patch['status'], 'editing out of scope is refused');

    Assert::null(
        DB::value('SELECT city FROM {contacts} WHERE id = :id', ['id' => $somebodys]),
        'and nothing was written'
    );

    // The same for every other write that takes an id: delete, consent, and
    // a visit. Each one moves a figure on a guest's record.
    Assert::same(404, ha_call('DELETE', '/api/v1/contacts/' . $somebodys, $split)['status'], 'delete');

    $consent = ha_call('POST', '/api/v1/contacts/' . $somebodys . '/consent', $split, [
        'channel'    => 'email',
        'granted'    => true,
        'source'     => 'ha-scope',
        'disclosure' => 'Agreed to hear about parties.',
    ]);
    Assert::same(404, $consent['status'], 'consent');

    $visit = ha_call('POST', '/api/v1/visits', $split, [
        'contact_id'  => $somebodys,
        // UTC-CORRECT: filler for a payload that is refused on scope before
        // the date is looked at. No calendar reads it.
        'visit_date'  => gmdate('Y-m-d', time() - 86400),
        'spend_cents' => 5000,
    ]);
    Assert::same(404, $visit['status'], 'a visit on somebody else\'s guest');

    // None of it landed: not the flag, not the visit, not the derived
    // figures a visit moves.
    $row = DB::first(
        'SELECT email_consent, visit_count, lifetime_value_cents, deleted_at
           FROM {contacts} WHERE id = :id',
        ['id' => $somebodys]
    );

    Assert::same(0, (int) $row['email_consent']);
    Assert::same(0, (int) $row['visit_count']);
    Assert::same(0, (int) $row['lifetime_value_cents']);
    Assert::null($row['deleted_at']);

    Assert::same(
        0,
        (int) DB::value('SELECT COUNT(*) FROM {visits} WHERE contact_id = :c', ['c' => $somebodys])
    );

    // And my own guest still works, so the scope narrowed rather than broke.
    $ok = ha_call('POST', '/api/v1/visits', $split, [
        'contact_id'  => $mine,
        // UTC-CORRECT: stored verbatim and never read back as a day — this
        // test asserts the status, not what the visit is filed under.
        'visit_date'  => gmdate('Y-m-d', time() - 86400),
        'spend_cents' => 5000,
    ]);
    Assert::same(201, $ok['status'], 'my own guest is fine');

    ha_clear();
});

Harness::test('a visit cannot be recorded on a DELETED guest either', function () use ($full): void {
    ha_clear();

    $id = ha_contact(0, 'Deleted Guest');
    DB::update('contacts', ['deleted_at' => now_utc()], 'id = :id', ['id' => $id]);

    // Quietly reviving a deleted guest in every report that counts visits is
    // not something an integration should be able to do by accident.
    $response = ha_call('POST', '/api/v1/visits', $full, [
        'contact_id'  => $id,
        // UTC-CORRECT: filler for a payload refused because the guest is
        // deleted. The day never reaches a report.
        'visit_date'  => gmdate('Y-m-d', time() - 86400),
        'spend_cents' => 1000,
    ]);

    Assert::same(404, $response['status']);
    Assert::same(
        0,
        (int) DB::value('SELECT COUNT(*) FROM {visits} WHERE contact_id = :c', ['c' => $id])
    );

    ha_clear();
});

Harness::test('A VISIT NEEDS A REAL CONTACT, so the id space cannot be walked', function () use ($full): void {
    /*
     * An id proven ABSENT, computed here — not a literal.
     *
     * This read `[999999999, 1, -1, 0]`, and 1 is a real guest on any
     * database that has one: the demo seed's first contact, which
     * `integration/seeds` loads a few suites earlier. The assertion then
     * depended on an unrelated suite's cleanup, and when it did not hold the
     * test did not merely fail — it recorded a real visit on a real guest
     * through `VisitRecorder`, moving their visit count, last-visit date and
     * lifetime value, and `ha_clear()` would not have removed it because that
     * guest's source is not `hatest`. A test that asserts a record does not
     * exist must establish that itself.
     */
    $absent = (int) DB::value('SELECT COALESCE(MAX(id), 0) FROM {contacts}') + 1000;

    foreach ([$absent, $absent + 7, 999999999, -1, 0] as $id) {
        $response = ha_call('POST', '/api/v1/visits', $full, [
            'contact_id' => $id,
            // UTC-CORRECT: filler for a payload refused on the contact id.
            'visit_date' => gmdate('Y-m-d', time() - 86400),
        ]);

        Assert::true(
            in_array($response['status'], [404, 422], true),
            'contact_id ' . $id . ' gave ' . $response['status']
        );
    }
});

// ================================================== deleting is its own power

Harness::test('DELETING NEEDS THE DELETE PERMISSION, NOT THE EDIT ONE', function () use ($ownerId): void {
    ha_clear();

    /*
     * The front-desk role, as an operator really builds it: create and edit
     * guests, but not remove them. Deleting used to be checked against the
     * WRITE permission, so this token could delete through the API exactly
     * what this person's own pages refuse them.
     */
    $editorRole = ha_role('editor', [
        'view_contacts'   => 'all',
        'contacts_manage' => 'all',
        'view_bookings'   => 'all',
        'bookings_manage' => 'all',
    ]);
    $editor = ha_token(ha_user($editorRole, 'editor'), 'editor');

    $created = ha_call('POST', '/api/v1/contacts', $editor, [
        'first_name' => 'Editable',
        'last_name'  => 'NotDeletable',
        'source'     => 'hatest',
    ]);

    Assert::same(201, $created['status'], 'it can still create');
    $id = (int) $created['json']['data']['id'];

    Assert::same(
        200,
        ha_call('PATCH', '/api/v1/contacts/' . $id, $editor, ['city' => 'Chester'])['status'],
        'and edit'
    );

    $refused = ha_call('DELETE', '/api/v1/contacts/' . $id, $editor);

    Assert::same(403, $refused['status']);
    Assert::same('contacts_delete', $refused['json']['permission'] ?? null, 'and it names the right permission');

    Assert::true(
        DB::exists('SELECT id FROM {contacts} WHERE id = :id AND deleted_at IS NULL', ['id' => $id]),
        'the guest is still there'
    );

    // A booking says `bookings_cancel`, which is the permission its own
    // pages check — the names differ per resource and the message must too.
    $booking = (int) DB::insert('bookings', [
        'booking_number' => 'HA-' . strtoupper(bin2hex(random_bytes(3))),
        'type'           => 'birthday',
        'status'         => 'inquiry',
        // UTC-CORRECT: a row that exists only to be refused by permission.
        'event_date'     => gmdate('Y-m-d', time() + 86400 * 30),
        'source'         => 'hatest',
        'created_at'     => now_utc(),
        'updated_at'     => now_utc(),
    ]);

    $cancel = ha_call('DELETE', '/api/v1/bookings/' . $booking, $editor);

    Assert::same(403, $cancel['status']);
    Assert::same('bookings_cancel', $cancel['json']['permission'] ?? null);

    // With the permission, it goes.
    $deleterRole = ha_role('deleter', [
        'view_contacts'   => 'all',
        'contacts_manage' => 'all',
        'contacts_delete' => 'all',
    ]);
    $deleter = ha_token(ha_user($deleterRole, 'deleter'), 'deleter');

    Assert::same(200, ha_call('DELETE', '/api/v1/contacts/' . $id, $deleter)['status']);
    Assert::true(
        DB::exists('SELECT id FROM {contacts} WHERE id = :id AND deleted_at IS NOT NULL', ['id' => $id])
    );

    ha_clear();
});

Harness::test('the index says what a token may delete, separately from what it may write', function () use ($full): void {
    $index = ha_call('GET', '/api/v1', $full);

    Assert::same(200, $index['status']);

    $contacts = $index['json']['resources']['contacts'] ?? [];

    Assert::true((bool) ($contacts['writable'] ?? false));
    Assert::true((bool) ($contacts['deletable'] ?? false), 'the owner token can delete');

    // A resource with no delete path says so rather than leaving an
    // integration to find out from a 405.
    Assert::false((bool) ($index['json']['resources']['visits']['deletable'] ?? true));
    Assert::false((bool) ($index['json']['resources']['memberships']['deletable'] ?? true));
});

Harness::test('the index is an object even when a token can read nothing', function () use ($ownerId): void {
    /*
     * `view_logs` gates no API resource, so this token reads none of them —
     * which is what a narrowly scoped token legitimately looks like, and what
     * the fail-closed abilities fix makes more common.
     *
     * A PHP array with no keys encodes as `[]`, so the shape of `resources`
     * would change with its contents: a map on every ordinary response and a
     * list on this one. A JavaScript client survives that; a typed one reads
     * it as a parse failure rather than as "no resources".
     */
    $narrow = ha_token($ownerId, 'reads nothing', ['view_logs']);
    $index  = ha_call('GET', '/api/v1', $narrow);

    Assert::same(200, $index['status']);
    Assert::contains('"resources":{}', $index['body']);
    Assert::same([], $index['json']['resources'], 'and it really is empty');
});

// =================================================== a stage is a transition

Harness::test('A STAGE CANNOT BE PATCHED, AND MOVING ONE DOES EVERYTHING A MOVE DOES', function () use ($full): void {
    ha_clear();

    $stages = DB::all(
        'SELECT id, name, pipeline_id, is_won FROM {pipeline_stages}
          WHERE pipeline_id = (SELECT MIN(pipeline_id) FROM {pipeline_stages})
          ORDER BY sort_order, id'
    );

    Assert::greaterThan(2, count($stages), 'precondition: the first pipeline has stages to move between');

    $first = $stages[0];
    $next  = $stages[1];
    $won   = null;

    foreach ($stages as $stage) {
        if ((int) $stage['is_won'] === 1) {
            $won = $stage;
        }
    }

    Assert::notNull($won, 'precondition: the first pipeline has a won stage');

    $created = ha_call('POST', '/api/v1/deals', $full, [
        'title'  => 'HA stage walk',
        'source' => 'hatest',
    ]);

    Assert::same(201, $created['status']);
    $id = (int) $created['json']['data']['id'];

    /*
     * A deal created through the API opens exactly as one created on the
     * board does: in the first stage, with the stage clock running and an
     * opening history row. Without them the velocity report cannot measure
     * the time it spent in its first stage, which is the figure the pipeline
     * report opens with.
     */
    $row = DB::first('SELECT * FROM {deals} WHERE id = :id', ['id' => $id]);

    Assert::same((int) $first['id'], (int) $row['stage_id']);
    Assert::notNull($row['stage_entered_at'], 'the stage clock is running');
    Assert::same('open', (string) $row['status']);
    Assert::same(
        1,
        (int) DB::value('SELECT COUNT(*) FROM {deal_stage_history} WHERE deal_id = :d', ['d' => $id]),
        'the opening history row'
    );

    // `stage_id` is not a writable field, and a refusal says so rather than
    // ignoring it.
    $patch = ha_call('PATCH', '/api/v1/deals/' . $id, $full, ['stage_id' => (int) $next['id']]);

    Assert::same(422, $patch['status']);
    Assert::inArray('stage_id', $patch['json']['refused']);
    Assert::same(
        (int) $first['id'],
        (int) DB::value('SELECT stage_id FROM {deals} WHERE id = :id', ['id' => $id]),
        'and the deal did not move'
    );

    // By name, the way an automation step names one.
    $move = ha_call('POST', '/api/v1/deals/' . $id . '/stage', $full, ['stage' => (string) $next['name']]);

    Assert::same(200, $move['status']);
    Assert::true((bool) $move['json']['moved']);
    Assert::same((string) $next['name'], $move['json']['to']);
    Assert::same((int) $next['id'], (int) $move['json']['data']['stage_id']);

    Assert::same(
        2,
        (int) DB::value('SELECT COUNT(*) FROM {deal_stage_history} WHERE deal_id = :d', ['d' => $id]),
        'the move is in the history'
    );

    // Again, to the same stage: not an error, and not a second history row —
    // re-entering would reset the stage clock the report measures.
    $same = ha_call('POST', '/api/v1/deals/' . $id . '/stage', $full, ['stage_id' => (int) $next['id']]);

    Assert::same(200, $same['status']);
    Assert::false((bool) $same['json']['moved']);
    Assert::notNull($same['json']['note']);
    Assert::same(
        2,
        (int) DB::value('SELECT COUNT(*) FROM {deal_stage_history} WHERE deal_id = :d', ['d' => $id])
    );

    // A stage that does not exist, and one on another board: refused with a
    // reason, never a silent no-op.
    foreach (['Not A Stage Here', '0'] as $nonsense) {
        $bad = ha_call('POST', '/api/v1/deals/' . $id . '/stage', $full, ['stage' => $nonsense]);
        Assert::same(422, $bad['status'], $nonsense);
    }

    $elsewhere = DB::first(
        'SELECT id, name FROM {pipeline_stages} WHERE pipeline_id <> :p ORDER BY id LIMIT 1',
        ['p' => (int) $first['pipeline_id']]
    );

    if ($elsewhere !== null) {
        $crossed = ha_call('POST', '/api/v1/deals/' . $id . '/stage', $full, [
            'stage_id' => (int) $elsewhere['id'],
        ]);

        Assert::same(422, $crossed['status'], 'a stage on another board is refused');
        Assert::contains('pipeline', strtolower($crossed['body']));
    }

    // Winning stamps the won date and closes the deal, which is the whole
    // reason this is not a column.
    $win = ha_call('POST', '/api/v1/deals/' . $id . '/stage', $full, ['stage_id' => (int) $won['id']]);

    Assert::same(200, $win['status']);

    $after = DB::first('SELECT status, won_at, stage_id FROM {deals} WHERE id = :id', ['id' => $id]);

    Assert::same('won', (string) $after['status']);
    Assert::notNull($after['won_at'], 'and it says when');
    Assert::same((int) $won['id'], (int) $after['stage_id']);

    ha_clear();
});

Harness::test('moving a stage needs deals_manage, and the deal has to be in scope', function () use ($readOnly, $split, $splitUser, $ownerId): void {
    ha_clear();

    $mine = (int) DB::insert('deals', [
        'pipeline_id' => (int) DB::value('SELECT MIN(pipeline_id) FROM {pipeline_stages}'),
        'stage_id'    => (int) DB::value('SELECT id FROM {pipeline_stages} ORDER BY pipeline_id, sort_order LIMIT 1'),
        'title'       => 'HA scoped deal',
        'owner_id'    => $splitUser,
        'status'      => 'open',
        'source'      => 'hatest',
        'created_at'  => now_utc(),
        'updated_at'  => now_utc(),
    ]);

    $theirs = (int) DB::insert('deals', [
        'pipeline_id' => (int) DB::value('SELECT MIN(pipeline_id) FROM {pipeline_stages}'),
        'stage_id'    => (int) DB::value('SELECT id FROM {pipeline_stages} ORDER BY pipeline_id, sort_order LIMIT 1'),
        'title'       => 'HA somebody elses deal',
        'owner_id'    => $ownerId,
        'status'      => 'open',
        'source'      => 'hatest',
        'created_at'  => now_utc(),
        'updated_at'  => now_utc(),
    ]);

    $target = DB::first(
        'SELECT id, name FROM {pipeline_stages}
          WHERE pipeline_id = (SELECT MIN(pipeline_id) FROM {pipeline_stages})
          ORDER BY sort_order, id LIMIT 1 OFFSET 1'
    );

    // A read-only token cannot move anything, and says which permission it
    // wanted.
    $refused = ha_call('POST', '/api/v1/deals/' . $mine . '/stage', $readOnly, ['stage_id' => (int) $target['id']]);

    Assert::same(403, $refused['status']);
    Assert::same('deals_manage', $refused['json']['permission'] ?? null);

    // The split token holds deals_manage at 'own': its own deal moves...
    Assert::same(
        200,
        ha_call('POST', '/api/v1/deals/' . $mine . '/stage', $split, ['stage_id' => (int) $target['id']])['status']
    );

    // ...and somebody else's is a 404, with no history row to show for it.
    Assert::same(
        404,
        ha_call('POST', '/api/v1/deals/' . $theirs . '/stage', $split, ['stage_id' => (int) $target['id']])['status']
    );

    Assert::same(
        0,
        (int) DB::value('SELECT COUNT(*) FROM {deal_stage_history} WHERE deal_id = :d', ['d' => $theirs])
    );

    ha_clear();
});

// ================== what the stage endpoint and a board move refuse

Harness::test('A STAGE IS NAMED BY A STRING OR AN ID, and nothing else is coerced into one', function () use ($full): void {
    ha_clear();

    $stages = DB::all(
        'SELECT id, name FROM {pipeline_stages}
          WHERE pipeline_id = (SELECT MIN(pipeline_id) FROM {pipeline_stages})
          ORDER BY sort_order, id'
    );

    Assert::greaterThan(2, count($stages));

    $created = ha_call('POST', '/api/v1/deals', $full, ['title' => 'HA type walk', 'source' => 'hatest']);
    $id      = (int) $created['json']['data']['id'];

    // Park it away from the top of the board, so a wrong move is visible.
    Assert::same(
        200,
        ha_call('POST', '/api/v1/deals/' . $id . '/stage', $full, ['stage_id' => (int) $stages[2]['id']])['status']
    );

    $parked  = (int) DB::value('SELECT stage_id FROM {deals} WHERE id = :id', ['id' => $id]);
    $history = (int) DB::value('SELECT COUNT(*) FROM {deal_stage_history} WHERE deal_id = :d', ['d' => $id]);

    /*
     * `true` WAS A MOVE. `(string) true` is "1", which the resolver reads as
     * stage id 1 — the top of the first board on every seeded install. So a
     * client whose stage field carried a flag rather than a name walked the
     * deal backwards and got a 200 saying it had. MEASURED before the fix.
     */
    foreach ([true, false, ['stage'], ['id' => 3], 1.5, null] as $rubbish) {
        foreach (['stage', 'stage_id'] as $field) {
            $response = ha_call('POST', '/api/v1/deals/' . $id . '/stage', $full, [$field => $rubbish]);

            Assert::same(
                422,
                $response['status'],
                $field . ' = ' . var_export($rubbish, true) . ' must be refused, not cast'
            );
        }
    }

    // Not moved, and no history row written by any of it.
    Assert::same($parked, (int) DB::value('SELECT stage_id FROM {deals} WHERE id = :id', ['id' => $id]));
    Assert::same(
        $history,
        (int) DB::value('SELECT COUNT(*) FROM {deal_stage_history} WHERE deal_id = :d', ['d' => $id])
    );

    // And the real forms still work, so the guard narrowed rather than broke.
    Assert::same(
        200,
        ha_call('POST', '/api/v1/deals/' . $id . '/stage', $full, ['stage' => (string) $stages[1]['name']])['status']
    );
    Assert::same(
        200,
        ha_call('POST', '/api/v1/deals/' . $id . '/stage', $full, ['stage_id' => (int) $stages[0]['id']])['status']
    );

    ha_clear();
});

Harness::test('MOVING A DEAL TO ANOTHER BOARD TAKES ITS STAGE WITH IT', function () use ($full): void {
    ha_clear();

    $here  = (int) DB::value('SELECT MIN(pipeline_id) FROM {pipeline_stages}');
    $there = (int) DB::value('SELECT MIN(pipeline_id) FROM {pipeline_stages} WHERE pipeline_id > :p', ['p' => $here]);

    if ($there <= 0) {
        Assert::true(true, 'this install has only one pipeline, so there is no board to move to');

        return;
    }

    $created = ha_call('POST', '/api/v1/deals', $full, ['title' => 'HA board move', 'source' => 'hatest']);
    $id      = (int) $created['json']['data']['id'];

    $stages = DB::all(
        'SELECT id, name FROM {pipeline_stages} WHERE pipeline_id = :p ORDER BY sort_order, id',
        ['p' => $here]
    );

    // Move it along its own board first, so the board move has a real
    // from-stage to record.
    ha_call('POST', '/api/v1/deals/' . $id . '/stage', $full, ['stage_id' => (int) $stages[1]['id']]);

    $before = (int) DB::value('SELECT COUNT(*) FROM {deal_stage_history} WHERE deal_id = :d', ['d' => $id]);

    /*
     * `pipeline_id` is writable and `stage_id` is not, and that pairing left a
     * deal on a board it had no stage on: MEASURED as `pipeline_id: 2,
     * stage_id: 1` with stage 1 belonging to pipeline 1. No page renders that
     * sensibly and every stage-keyed report mis-attributes it.
     */
    $moved = ha_call('PATCH', '/api/v1/deals/' . $id, $full, ['pipeline_id' => $there]);

    Assert::same(200, $moved['status']);

    $row = DB::first(
        'SELECT d.pipeline_id, d.stage_id, d.stage_entered_at, d.status, s.pipeline_id AS stage_pipeline
           FROM {deals} d JOIN {pipeline_stages} s ON s.id = d.stage_id
          WHERE d.id = :id',
        ['id' => $id]
    );

    Assert::same($there, (int) $row['pipeline_id'], 'it is on the board that was asked for');
    Assert::same(
        $there,
        (int) $row['stage_pipeline'],
        'AND ITS STAGE BELONGS TO THAT BOARD — the whole point'
    );
    Assert::notNull($row['stage_entered_at'], 'the stage clock restarted');

    // It is the first stage of the new board, not an arbitrary one.
    Assert::same(
        (int) DB::value(
            'SELECT id FROM {pipeline_stages} WHERE pipeline_id = :p ORDER BY sort_order, id LIMIT 1',
            ['p' => $there]
        ),
        (int) $row['stage_id']
    );

    // And it is recorded as the move it is, so the history does not jump.
    Assert::same(
        $before + 1,
        (int) DB::value('SELECT COUNT(*) FROM {deal_stage_history} WHERE deal_id = :d', ['d' => $id]),
        'a board move is a stage move and leaves a history row'
    );

    // A pipeline that does not exist is refused rather than stranding it.
    $nowhere = ha_call('PATCH', '/api/v1/deals/' . $id, $full, ['pipeline_id' => 99999999]);

    Assert::same(422, $nowhere['status']);
    Assert::same(
        $there,
        (int) DB::value('SELECT pipeline_id FROM {deals} WHERE id = :id', ['id' => $id]),
        'and it stayed where it was'
    );

    // Naming the board it is already on changes nothing and writes no history.
    $same = ha_call('PATCH', '/api/v1/deals/' . $id, $full, ['pipeline_id' => $there, 'title' => 'HA board move 2']);

    Assert::same(200, $same['status']);
    Assert::same(
        $before + 1,
        (int) DB::value('SELECT COUNT(*) FROM {deal_stage_history} WHERE deal_id = :d', ['d' => $id]),
        'no second history row for a no-op'
    );

    ha_clear();
});

Harness::test('a body that is not an object is refused with 400, and never leaks a path', function () use ($full): void {
    ha_clear();

    $created = ha_call('POST', '/api/v1/deals', $full, ['title' => 'HA body walk', 'source' => 'hatest']);
    $id      = (int) $created['json']['data']['id'];

    /*
     * 400 EXACTLY, and about the BODY rather than a field.
     *
     * `Request::json()` is typed `: array` and returns `[]` for a body that
     * is a list, a scalar, or not JSON at all, which made every write
     * handler's `if (!is_array($input)) return 400` branch unreachable: the
     * request arrived looking like an empty object and was refused by
     * whatever field check came next. Measured before the fix — a list, a
     * string, a number, `null`, `true`, unparseable text and a 2MB body all
     * came back 422, so a caller who sent `[1,2,3]` was told "Name the stage
     * to move to", about a field a list cannot carry.
     *
     * `Request::jsonShape()` reads the body itself, and these are the shapes
     * that can never be a set of fields.
     */
    foreach ([
        'a list body'        => '[1, 2, 3]',
        'an empty list body' => '[]',
        'a string body'      => '"Deposit Paid"',
        'a number body'      => '7',
        'a null body'        => 'null',
        'a true body'        => 'true',
        'unparseable text'   => 'Deposit Paid, please',
        'a 2MB string body'  => '"' . str_repeat('a', 2_000_000) . '"',
    ] as $label => $body) {
        $response = ha_call('POST', '/api/v1/deals/' . $id . '/stage', $full, $body, [], true);

        Assert::same(400, $response['status'], $label);
        Assert::contains('JSON object', (string) ($response['json']['error'] ?? ''), $label . ' names the body');
        Assert::notContains('/home/', $response['body'], $label . ' must not carry a path');
        Assert::notContains('Stack trace', $response['body'], $label);
    }

    /*
     * A body that COULD carry fields is not this error, whether or not it
     * actually carries any: the field checks answer those, and their
     * messages already say precisely what is missing.
     */
    foreach (['an empty object' => '{}', 'an object with a flag for a stage' => '{"stage":true}'] as $label => $body) {
        $object = ha_call('POST', '/api/v1/deals/' . $id . '/stage', $full, $body, [], true);

        Assert::same(422, $object['status'], $label);
        Assert::contains('stage', strtolower((string) ($object['json']['error'] ?? '')), $label . ' names the field');
    }

    $absent = ha_call('POST', '/api/v1/deals/' . $id . '/stage', $full);

    Assert::same(422, $absent['status'], 'no body at all is a missing field, not a malformed one');
    Assert::contains('stage', strtolower((string) ($absent['json']['error'] ?? '')), 'and it names the field');

    // And the deal did not move for any of them.
    Assert::same(
        1,
        (int) DB::value('SELECT COUNT(*) FROM {deal_stage_history} WHERE deal_id = :d', ['d' => $id]),
        'only the opening history row'
    );

    /*
     * EVERY write, not just this one: the dead guard sat in five handlers,
     * so a list body has to be read the same way by all five.
     */
    $contact = (int) ha_call('POST', '/api/v1/contacts', $full, [
        'first_name' => 'Body',
        'last_name'  => 'Walk',
        'source'     => 'hatest',
    ])['json']['data']['id'];

    foreach ([
        'store'   => ['POST', '/api/v1/contacts'],
        'update'  => ['PATCH', '/api/v1/contacts/' . $contact],
        'visit'   => ['POST', '/api/v1/visits'],
        'consent' => ['POST', '/api/v1/contacts/' . $contact . '/consent'],
    ] as $handler => [$method, $path]) {
        $response = ha_call($method, $path, $full, '[1, 2, 3]', [], true);

        Assert::same(400, $response['status'], $handler . ' refuses a list body');
        Assert::contains('JSON object', (string) ($response['json']['error'] ?? ''), $handler . ' names the body');
    }

    Assert::same(
        0,
        (int) DB::value('SELECT COUNT(*) FROM {visits} WHERE contact_id = :c', ['c' => $contact]),
        'and none of them wrote anything'
    );

    ha_clear();
});

Harness::test('A PATCH CANNOT OPEN OR CLOSE A DEAL', function () use ($full): void {
    ha_clear();

    $here  = (int) DB::value('SELECT MIN(pipeline_id) FROM {pipeline_stages}');
    $there = (int) DB::value('SELECT MIN(pipeline_id) FROM {pipeline_stages} WHERE pipeline_id > :p', ['p' => $here]);

    if ($there <= 0) {
        Assert::true(true, 'one pipeline only on this install');

        return;
    }

    $won = DB::first(
        'SELECT id, name FROM {pipeline_stages} WHERE pipeline_id = :p AND is_won = 1 ORDER BY sort_order LIMIT 1',
        ['p' => $here]
    );

    Assert::notNull($won, 'precondition: the first board has a won stage');

    $created = ha_call('POST', '/api/v1/deals', $full, ['title' => 'HA won guard', 'source' => 'hatest']);
    $id      = (int) $created['json']['data']['id'];

    Assert::same(
        200,
        ha_call('POST', '/api/v1/deals/' . $id . '/stage', $full, ['stage_id' => (int) $won['id']])['status']
    );

    $wonRow = DB::first('SELECT status, won_at FROM {deals} WHERE id = :id', ['id' => $id]);

    Assert::same('won', (string) $wonRow['status']);
    Assert::notNull($wonRow['won_at']);

    /*
     * MEASURED BEFORE THE FIX: a PATCH naming nothing but a board turned a
     * deal won two minutes earlier into `status: open, won_at: NULL` — with a
     * 200 and no event — because the new board's first stage is an open one
     * and the stage columns follow the stage. That erases the date every
     * revenue report keys on.
     */
    $refused = ha_call('PATCH', '/api/v1/deals/' . $id, $full, ['pipeline_id' => $there]);

    Assert::same(422, $refused['status']);
    Assert::contains('won', strtolower($refused['json']['error'] ?? ''), 'and it says why');

    $after = DB::first('SELECT pipeline_id, status, won_at FROM {deals} WHERE id = :id', ['id' => $id]);

    Assert::same($here, (int) $after['pipeline_id'], 'it did not move');
    Assert::same('won', (string) $after['status'], 'it is still won');
    Assert::same((string) $wonRow['won_at'], (string) $after['won_at'], 'and the date it was won is intact');

    /*
     * The mirror image: a board whose first stage CLOSES a deal would have
     * won or lost it from the same PATCH, firing `deal.won` or `deal.lost` —
     * and `deal.lost` starts a guest-facing email. Also refused.
     */
    $open  = ha_call('POST', '/api/v1/deals', $full, ['title' => 'HA closing board', 'source' => 'hatest']);
    $openId = (int) $open['json']['data']['id'];

    $firstThere = (int) DB::value(
        'SELECT id FROM {pipeline_stages} WHERE pipeline_id = :p ORDER BY sort_order, id LIMIT 1',
        ['p' => $there]
    );

    DB::update('pipeline_stages', ['is_won' => 1], 'id = :id', ['id' => $firstThere]);

    try {
        $closing = ha_call('PATCH', '/api/v1/deals/' . $openId, $full, ['pipeline_id' => $there]);

        Assert::same(422, $closing['status']);

        $still = DB::first('SELECT pipeline_id, status FROM {deals} WHERE id = :id', ['id' => $openId]);

        Assert::same($here, (int) $still['pipeline_id'], 'it did not move');
        Assert::same('open', (string) $still['status'], 'and it was not closed');
    } finally {
        DB::update('pipeline_stages', ['is_won' => 0], 'id = :id', ['id' => $firstThere]);
    }

    // With that board back to normal, the ordinary move still works.
    Assert::same(
        200,
        ha_call('PATCH', '/api/v1/deals/' . $openId, $full, ['pipeline_id' => $there])['status']
    );

    ha_clear();
});

Harness::test('A BOARD IS NAMED BY AN ID, NOT BY WHATEVER CASTS TO ONE', function () use ($full): void {
    ha_clear();

    /*
     * The third appearance of one mistake, and the reason this test is phrased
     * as a sweep rather than a case.
     *
     * A stage named by `true` moved a deal to stage id 1. That was fixed. The
     * fix for it then read `(int) $data['pipeline_id']`, and MEASURED:
     * `null`, `0`, `""`, `false`, `true`, `-3`, `"abc"` and `[]` each cast to
     * 0 or 1, fell through to "no pipeline named", and relocated the deal to
     * the FIRST board's first stage — 200, with a history row. Worse than the
     * stranding it replaced: a stranded deal is at least still on the board
     * somebody picked.
     *
     * So: anything that is not the id of a board is a 422, and the deal does
     * not move an inch.
     */
    $there = (int) DB::value(
        'SELECT MIN(pipeline_id) FROM {pipeline_stages}
          WHERE pipeline_id > (SELECT MIN(pipeline_id) FROM {pipeline_stages})'
    );

    if ($there <= 0) {
        Assert::true(true, 'one pipeline only on this install');

        return;
    }

    $created = ha_call('POST', '/api/v1/deals', $full, [
        'title'       => 'HA coercion walk',
        'source'      => 'hatest',
        'pipeline_id' => $there,
    ]);

    Assert::same(201, $created['status']);
    $id = (int) $created['json']['data']['id'];

    $parked  = DB::first('SELECT pipeline_id, stage_id FROM {deals} WHERE id = :id', ['id' => $id]);
    $history = (int) DB::value('SELECT COUNT(*) FROM {deal_stage_history} WHERE deal_id = :d', ['d' => $id]);

    Assert::same($there, (int) $parked['pipeline_id'], 'it was created on the board that was asked for');

    foreach ([null, 0, '', false, true, -3, 'abc', ['x'], 1.5, '2abc', ' '] as $rubbish) {
        $response = ha_call('PATCH', '/api/v1/deals/' . $id, $full, ['pipeline_id' => $rubbish]);

        Assert::same(
            422,
            $response['status'],
            'pipeline_id = ' . var_export($rubbish, true) . ' must be refused, not cast'
        );

        $now = DB::first('SELECT pipeline_id, stage_id FROM {deals} WHERE id = :id', ['id' => $id]);

        Assert::same((int) $parked['pipeline_id'], (int) $now['pipeline_id'], 'and the board did not change');
        Assert::same((int) $parked['stage_id'], (int) $now['stage_id'], 'and neither did the stage');
    }

    Assert::same(
        $history,
        (int) DB::value('SELECT COUNT(*) FROM {deal_stage_history} WHERE deal_id = :d', ['d' => $id]),
        'and none of it wrote a history row'
    );

    // A real id still works, as a number and as the string a JSON client may
    // well send.
    $here = (int) DB::value('SELECT MIN(pipeline_id) FROM {pipeline_stages}');

    Assert::same(200, ha_call('PATCH', '/api/v1/deals/' . $id, $full, ['pipeline_id' => $here])['status']);
    Assert::same(200, ha_call('PATCH', '/api/v1/deals/' . $id, $full, ['pipeline_id' => (string) $there])['status']);
    Assert::same(
        $there,
        (int) DB::value('SELECT pipeline_id FROM {deals} WHERE id = :id', ['id' => $id]),
        'a digit string names a board too'
    );

    // And the same rule on CREATE, where a bogus id used to land the deal on
    // the default board with a 201.
    $bogus = ha_call('POST', '/api/v1/deals', $full, [
        'title'       => 'HA coercion create',
        'source'      => 'hatest',
        'pipeline_id' => 'abc',
    ]);

    Assert::same(422, $bogus['status']);
    Assert::false(
        DB::exists("SELECT id FROM {deals} WHERE title = 'HA coercion create'"),
        'and nothing was created'
    );

    ha_clear();
});

Harness::test('a bad id is a 422 that NAMES the field, on any resource', function () use ($full): void {
    ha_clear();

    $created = ha_call('POST', '/api/v1/contacts', $full, [
        'first_name' => 'Root', 'last_name' => 'Cause', 'source' => 'hatest',
    ]);

    $id = (int) $created['json']['data']['id'];

    // Every foreign key on the resource, not just the one a bug was found in.
    foreach (['household_id', 'company_id', 'owner_id'] as $field) {
        foreach ([true, 'abc', 0, -1, ['x']] as $rubbish) {
            $response = ha_call('PATCH', '/api/v1/contacts/' . $id, $full, [$field => $rubbish]);

            Assert::same(422, $response['status'], $field . ' = ' . var_export($rubbish, true));
            Assert::hasKey($field, $response['json']['invalid'] ?? [], 'and it says which field');
        }
    }

    // Nothing landed on the row from any of it.
    $row = DB::first(
        'SELECT household_id, company_id, owner_id FROM {contacts} WHERE id = :id',
        ['id' => $id]
    );

    Assert::null($row['household_id']);
    Assert::null($row['company_id']);
    Assert::null($row['owner_id']);

    // Null clears a link, which is a real thing to want.
    Assert::same(200, ha_call('PATCH', '/api/v1/contacts/' . $id, $full, ['company_id' => null])['status']);

    // And the visit endpoint, which takes its body raw rather than through
    // the writable filter, follows the same rule.
    foreach ([['contact_id' => true], ['contact_id' => 'abc'],
              ['contact_id' => $id, 'booking_id' => true],
              ['contact_id' => $id, 'booking_id' => 'abc']] as $body) {
        Assert::same(422, ha_call('POST', '/api/v1/visits', $full, $body)['status'], json_encode($body));
    }

    Assert::same(
        0,
        (int) DB::value('SELECT COUNT(*) FROM {visits} WHERE contact_id = :c', ['c' => $id]),
        'and no visit was recorded by any of them'
    );

    ha_clear();
});

Harness::test('CONSENT IS NEVER WITHDRAWN BY A VALUE NOBODY MEANT AS "NO"', function () use ($full): void {
    ha_clear();

    $created = ha_call('POST', '/api/v1/contacts', $full, [
        'first_name' => 'Consent', 'last_name' => 'Shapes',
        'email' => 'consent.shapes@example.test', 'source' => 'hatest',
    ]);

    $id = (int) $created['json']['data']['id'];

    /*
     * `filter_var(..., FILTER_VALIDATE_BOOLEAN)` returns FALSE for anything it
     * does not recognise, so each of these recorded a WITHDRAWAL the guest
     * never made: the flag cleared, `marketing_opt_out_at` stamped, and a row
     * in an append-only compliance log saying they revoked. `"Y"` is the one
     * that matters most — it is what a venue's own spreadsheets send.
     *
     * Losing a reachable guest to a typo is bad. A false entry in the log
     * that exists to BE the evidence is worse.
     */
    foreach (['maybe', 'Y', 'N', '2', 'unknown', 'nope', 'granted'] as $ambiguous) {
        $response = ha_call('POST', '/api/v1/contacts/' . $id . '/consent', $full, [
            'channel' => 'email',
            'granted' => $ambiguous,
            'source'  => 'ha-shapes',
        ]);

        Assert::same(422, $response['status'], var_export($ambiguous, true) . ' must be refused');
    }

    // Nothing was written by any of them — not the flag, not the opt-out
    // stamp, and not a single row in the log.
    $row = DB::first(
        'SELECT email_consent, marketing_opt_out_at FROM {contacts} WHERE id = :id',
        ['id' => $id]
    );

    Assert::same(0, (int) $row['email_consent']);
    Assert::null($row['marketing_opt_out_at'], 'and the guest was not opted out');
    Assert::same(
        0,
        (int) DB::value('SELECT COUNT(*) FROM {consent_log} WHERE contact_id = :c', ['c' => $id])
    );

    // Every real way of saying yes and no still works.
    foreach ([[true, 'granted'], ['yes', 'granted'], ['on', 'granted'], [1, 'granted'],
              [false, 'revoked'], ['no', 'revoked'], ['off', 'revoked'], [0, 'revoked']] as [$value, $action]) {
        $response = ha_call('POST', '/api/v1/contacts/' . $id . '/consent', $full, [
            'channel'    => 'email',
            'granted'    => $value,
            'source'     => 'ha-shapes',
            'disclosure' => 'I agree to hear about parties at The Castle Fun Center.',
        ]);

        Assert::same(200, $response['status'], var_export($value, true));
        Assert::same($action, (string) $response['json']['data']['granted'] ? 'granted' : 'revoked', var_export($value, true));
    }

    // A text field that is not text is refused BEFORE anything is written —
    // `max:80` does not check a type, so an array passed validation and then
    // died on the cast, after the flag and before its evidence.
    $before = (int) DB::value('SELECT COUNT(*) FROM {consent_log} WHERE contact_id = :c', ['c' => $id]);

    foreach ([['source' => ['a', 'b']], ['source' => ['x' => 1]],
              ['disclosure' => ['a']], ['channel' => ['email']]] as $bad) {
        $body = array_merge([
            'channel' => 'email', 'granted' => true, 'source' => 'ha-shapes',
            'disclosure' => 'I agree to hear about parties.',
        ], $bad);

        $response = ha_call('POST', '/api/v1/contacts/' . $id . '/consent', $full, $body);

        Assert::same(422, $response['status'], json_encode($bad));
        Assert::notContains('/home/', $response['body'], json_encode($bad));
    }

    Assert::same(
        $before,
        (int) DB::value('SELECT COUNT(*) FROM {consent_log} WHERE contact_id = :c', ['c' => $id]),
        'and none of them wrote a half-record'
    );

    /*
     * THE FLAG AND ITS EVIDENCE MOVE TOGETHER. Every log row this guest has
     * should correspond to the flag as it stands after the last one — a flag
     * set with no row behind it is the state the endpoint exists to prevent.
     */
    $last = DB::first(
        'SELECT action FROM {consent_log} WHERE contact_id = :c ORDER BY id DESC LIMIT 1',
        ['c' => $id]
    );

    Assert::notNull($last, 'the successful writes left evidence');

    $flag = (int) DB::value('SELECT email_consent FROM {contacts} WHERE id = :id', ['id' => $id]);

    Assert::same(
        (string) $last['action'] === 'granted' ? 1 : 0,
        $flag,
        'the flag agrees with the last thing the log recorded'
    );

    ha_clear();
});

Harness::test('A POS BRIDGE FINDS A GUEST BY CARD NUMBER, AND ONLY THAT GUEST', function () use ($full): void {
    // The CenterEdge sync matches by customer number. Before the exact
    // filter it had to page the whole guest list to learn one id.
    $made = ha_call('POST', '/api/v1/contacts', $full, [
        'first_name'      => 'Card',
        'last_name'       => 'Holder',
        'email'           => 'card.holder@example.test',
        'external_pos_id' => 'HA-4021',
        'address_1'       => '1 Test Lane',
        'source'          => 'hatest',
    ]);

    Assert::same(201, $made['status'], $made['body']);
    Assert::same('HA-4021', $made['json']['data']['external_pos_id'] ?? null, 'the card number is readable back');
    Assert::same('1 Test Lane', $made['json']['data']['address_1'] ?? null);

    ha_call('POST', '/api/v1/contacts', $full, [
        'first_name' => 'Other', 'last_name' => 'Guest', 'external_pos_id' => 'HA-40210', 'source' => 'hatest',
    ]);

    $found = ha_call('GET', '/api/v1/contacts?external_pos_id=HA-4021', $full);

    Assert::same(200, $found['status']);
    Assert::same(['HA-4021'], array_column($found['json']['data'], 'external_pos_id'), 'exact, not a prefix or a LIKE');

    $byEmail = ha_call('GET', '/api/v1/contacts?email=' . urlencode('card.holder@example.test'), $full);
    Assert::same(1, count($byEmail['json']['data']));

    // A visit can be looked up by the reference the bridge sent with it.
    $id = (int) $made['json']['data']['id'];
    ha_call('POST', '/api/v1/visits', $full, [
        'contact_id' => $id, 'visit_date' => '2026-10-01', 'source' => 'pos_import',
        'spend_cents' => 1250, 'external_ref' => 'CE-HA-4021-2026-10-01',
    ]);

    $visit = ha_call('GET', '/api/v1/visits?external_ref=CE-HA-4021-2026-10-01', $full);
    Assert::same([$id], array_map('intval', array_column($visit['json']['data'], 'contact_id')));

    ha_clear();
});

ha_clear();
ha_clear_users();
ha_clear_tokens();
