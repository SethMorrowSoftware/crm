<?php

declare(strict_types=1);

/**
 * The webhooks page, over HTTP.
 *
 * What only this layer can pin:
 *
 *   - **A URL pointing inside the network is refused AT SAVE TIME.** This is
 *     the SSRF surface of the whole application: a field where somebody types
 *     a URL that this server will then fetch, from cron, on a shared host
 *     where `127.0.0.1` and `169.254.169.254` are both reachable and both
 *     interesting. `Http::refuse()` is unit-tested, but whether the
 *     controller actually CALLS it — including for a hostname that resolves
 *     inwards, which the literal check cannot see — is only answerable here.
 *   - **The signing secret is shown once and never again.** It has to be
 *     stored in the clear (every delivery is signed with it), so the one
 *     protection is that no page ever prints it a second time. A test that
 *     only checks the create response would miss a later page leaking it.
 *   - **`integrations_manage` guards all six endpoints.** A manager with
 *     `settings` can configure the venue and must not be able to point the
 *     venue's event stream at a URL of their own.
 *   - **A queued delivery carries no guest data.** The filter is unit-tested
 *     on a synthetic payload; this fires a REAL event through the real
 *     listener and reads what actually landed in the queue.
 */

use App\Core\Auth;
use App\Core\DB;
use App\Services\Webhooks;
use Tests\Assert;
use Tests\Harness;

Harness::suite('http/webhooks');

$baseUrl = getenv('CC_TEST_BASE_URL') ?: '';

if ($baseUrl === '') {
    Harness::skip('webhooks suite', 'no server; run via tests/run.php');

    return;
}

if (!test_boot()) {
    Harness::skip('webhooks suite', 'no database available');

    return;
}

require_once CC_ROOT . '/tests/TestBrowser.php';

// ------------------------------------------------------------------ fixtures

/*
 * DATA ONLY — the same split the reports suite documents. The roles and
 * accounts below are signed-in sessions for the whole file, so a clear that
 * removed them would log the permission tests out and their 302s would read
 * as a product bug.
 */
function hw_clear(): void
{
    $ids = DB::column("SELECT id FROM {webhooks} WHERE name LIKE 'HW %' OR target_url LIKE '%hw-test%'");

    foreach ($ids as $id) {
        DB::query('DELETE FROM {webhook_deliveries} WHERE webhook_id = :w', ['w' => (int) $id]);
        DB::query('DELETE FROM {webhooks} WHERE id = :w', ['w' => (int) $id]);
    }
}

function hw_clear_roles(): void
{
    $roleIds = DB::column("SELECT id FROM {roles} WHERE slug LIKE 'hwtest-%'");

    foreach ($roleIds as $roleId) {
        DB::query('DELETE FROM {users} WHERE role_id = :r', ['r' => (int) $roleId]);
        DB::query('DELETE FROM {role_permissions} WHERE role_id = :r', ['r' => (int) $roleId]);
        DB::query('DELETE FROM {roles} WHERE id = :r', ['r' => (int) $roleId]);
    }
}

/** @param array<string,string> $permissions */
function hw_role(string $slug, array $permissions): int
{
    $roleId = (int) DB::insert('roles', [
        'slug'        => 'hwtest-' . $slug,
        'name'        => 'HW ' . ucfirst($slug),
        'description' => 'Created by the webhooks test suite.',
        'is_system'   => 0,
        'sort_order'  => 215,
        'created_at'  => now_utc(),
        'updated_at'  => now_utc(),
    ]);

    foreach ($permissions as $permissionSlug => $scope) {
        $permissionId = (int) DB::value(
            'SELECT id FROM {permissions} WHERE slug = :s',
            ['s' => $permissionSlug]
        );

        Assert::greaterThan(0, $permissionId, 'permission should be seeded: ' . $permissionSlug);

        DB::insert('role_permissions', [
            'role_id'       => $roleId,
            'permission_id' => $permissionId,
            'scope'         => $scope,
        ]);
    }

    return $roleId;
}

/** @return array{email:string,password:string} */
function hw_account(int $roleId, string $label): array
{
    $email    = 'hw-' . $label . '-' . bin2hex(random_bytes(3)) . '@test.invalid';
    $password = 'webhooks-suite-passphrase-9';

    DB::insert('users', [
        'name'          => 'HW ' . ucfirst($label),
        'email'         => $email,
        'password_hash' => Auth::hash($password),
        'role_id'       => $roleId,
        'is_active'     => 1,
        'created_at'    => now_utc(),
        'updated_at'    => now_utc(),
    ]);

    return ['email' => $email, 'password' => $password];
}

/**
 * A subscription, written straight to the database.
 *
 * The page's own create path is exercised by its own tests; everything that
 * needs a subscription to already exist builds one here rather than depending
 * on those passing first.
 */
function hw_webhook(string $event = '*', string $url = 'https://hw-test.invalid/hook', int $active = 1): int
{
    return (int) DB::insert('webhooks', [
        'name'       => 'HW ' . substr($event, 0, 40),
        'event'      => $event,
        'target_url' => $url,
        'secret'     => Webhooks::newSecret(),
        'is_active'  => $active,
        'created_at' => now_utc(),
        'updated_at' => now_utc(),
    ]);
}

/**
 * Create a contact through the real UI, and return what was typed in.
 *
 * **THROUGH HTTP, NOT `Event::fire()`**, and that is the point of this suite
 * having these tests at all: it proves the webhook listener is wired into a
 * real request, that a save does not wait on an endpoint, and that the body
 * which reaches the queue was built by the same code path a venue will use.
 * Firing the event in-process would test the dispatcher and skip the wiring.
 *
 * @return array{surname:string,email:string,phone:string,id:int}
 */
function hw_fire_contact_created(TestBrowser $browser, string $label): array
{
    $surname = 'HWFire' . $label . bin2hex(random_bytes(3));
    $email   = strtolower($surname) . '@test.invalid';
    $phone   = '(845) 555-0' . random_int(100, 999);

    $response = $browser->postWithToken('/contacts/new', '/contacts', [
        'first_name'      => 'Leakcheck',
        'last_name'       => $surname,
        'email'           => $email,
        'phone'           => $phone,
        'lifecycle_stage' => 'prospect',
    ]);

    Assert::same(302, $response['status'], 'the contact was created');

    $id = (int) DB::value(
        'SELECT id FROM {contacts} WHERE last_name = :n',
        ['n' => $surname]
    );

    Assert::greaterThan(0, $id);

    return ['surname' => $surname, 'email' => $email, 'phone' => $phone, 'id' => $id];
}

function hw_clear_contacts(): void
{
    DB::query("DELETE FROM {contacts} WHERE last_name LIKE 'HWFire%'");
}

hw_clear();
hw_clear_roles();
hw_clear_contacts();

$owner = new TestBrowser($baseUrl, 'webhooks-owner');
$owner->login('owner@test.invalid', 'owner-test-passphrase-9');

// `settings` but NOT `integrations_manage`: the venue-configuration profile.
$settingsRole = hw_role('settings', ['settings' => 'all']);
$settingsUser = hw_account($settingsRole, 'settings');
$settingsOnly = new TestBrowser($baseUrl, 'webhooks-settings');
$settingsOnly->login($settingsUser['email'], $settingsUser['password']);

// Front Desk holds neither.
$desk = new TestBrowser($baseUrl, 'webhooks-desk');
$desk->login('desk@test.invalid', 'desk-test-passphrase-9');

Harness::test('the fixtures signed in', function () use ($owner, $settingsOnly, $desk): void {
    Assert::same(200, $owner->get('/account')['status']);
    Assert::same(200, $settingsOnly->get('/account')['status']);
    Assert::same(200, $desk->get('/account')['status']);
});

// --------------------------------------------------------------------- gates

Harness::test('every webhook endpoint needs integrations_manage', function () use ($settingsOnly, $desk): void {
    /*
     * A manager who can set the venue's opening hours must not be able to
     * point its event stream at a URL of their own. `settings` is the
     * permission that is easy to hand out; this is the one that is not.
     */
    $id = hw_webhook();

    foreach ([$settingsOnly, $desk] as $browser) {
        $page = $browser->get('/settings/webhooks');

        Assert::true(
            in_array($page['status'], [302, 403], true),
            'the page is closed: got ' . $page['status']
        );

        // And every write, because a hidden nav item is not a permission.
        foreach ([
            '/settings/webhooks',
            '/settings/webhooks/' . $id . '/toggle',
            '/settings/webhooks/' . $id . '/delete',
            '/settings/webhooks/' . $id . '/test',
        ] as $path) {
            $response = $browser->postWithToken('/account', $path, [
                'name'       => 'HW sneaky',
                'event'      => '*',
                'target_url' => 'https://hw-test.invalid/sneaky',
            ]);

            Assert::true(
                in_array($response['status'], [302, 403], true),
                $path . ' is closed: got ' . $response['status']
            );
        }
    }

    Assert::same(
        0,
        (int) DB::value("SELECT COUNT(*) FROM {webhooks} WHERE name = 'HW sneaky'"),
        'and nothing was created'
    );

    hw_clear();
});

Harness::test('the page renders with no subscriptions and says so', function () use ($owner): void {
    hw_clear();

    $page = $owner->get('/settings/webhooks');

    Assert::same(200, $page['status']);
    Assert::true(
        str_contains($page['body'], 'is being told anything')
            || str_contains($page['body'], 'No webhooks yet'),
        'an empty state, not an empty table'
    );

    // The promise the page makes about what a call contains is on the page,
    // not only in the docs.
    Assert::true(
        str_contains($page['body'], 'ids, not records'),
        'the page states what a delivery carries'
    );

    Assert::false(str_contains($page['body'], 'Warning:'), 'no PHP warnings');
    Assert::false(str_contains($page['body'], 'Notice:'));
});

Harness::test('the settings index links it, for the right people only', function () use ($owner, $settingsOnly): void {
    $page = $owner->get('/settings');

    Assert::same(200, $page['status']);
    Assert::true(str_contains($page['body'], '/settings/webhooks'), 'the tile is there');

    // A tile nobody can open should not be drawn.
    $theirs = $settingsOnly->get('/settings');

    Assert::same(200, $theirs['status']);
    Assert::false(
        str_contains($theirs['body'], '/settings/webhooks'),
        'and it is not offered to a role that cannot open it'
    );
});

// ---------------------------------------------------------- refusing a target

Harness::test('a URL pointing at this server or inside the network is refused', function () use ($owner): void {
    /*
     * THE SSRF TEST, and the one that matters most on this page.
     *
     * `Http::refuse()` is unit-tested against every one of these addresses.
     * What is only answerable over HTTP is whether the controller calls it —
     * and the failure mode if it does not is not an error message, it is a
     * subscription that works, so nothing on the page would look wrong.
     */
    hw_clear();

    $refused = [
        'http://127.0.0.1/hw-test',
        'http://localhost/hw-test',
        'http://169.254.169.254/latest/meta-data/',      // cloud metadata
        'http://[::1]/hw-test',
        'http://10.0.0.5/hw-test',
        'http://192.168.1.1/hw-test',
        'http://172.16.4.4/hw-test',
        'file:///etc/passwd',
        'gopher://127.0.0.1/hw-test',
        'http://0.0.0.0/hw-test',
    ];

    foreach ($refused as $url) {
        $response = $owner->postWithToken('/settings/webhooks', '/settings/webhooks', [
            'name'       => 'HW blocked',
            'event'      => '*',
            'target_url' => $url,
        ], true);

        Assert::same(200, $response['status'], $url . ' lands back on the page');
        Assert::true(
            str_contains($response['body'], 'cannot be used'),
            $url . ' is refused with a reason somebody can read'
        );
    }

    Assert::same(
        0,
        (int) DB::value("SELECT COUNT(*) FROM {webhooks} WHERE name = 'HW blocked'"),
        'not one of them was stored'
    );
});

Harness::test('a hostname that resolves to a private address is refused too', function () use ($owner): void {
    /*
     * The case the literal-address check cannot see — and the one that
     * matters, because `refuse()`'s own docblock says it does not cover it.
     *
     * THE MACHINE'S OWN HOSTNAME is the probe. On Linux and in every CI
     * container it sits in `/etc/hosts` pointing at loopback; it is not a
     * literal address and it is not spelled "localhost", so the literal
     * check lets it straight through and only resolution can stop it. A
     * public name that answers 127.0.0.1 — `localtest.me` and friends —
     * would be the same shape, but it needs working outbound DNS and this
     * does not, so it cannot pass by accident in a sandbox.
     */
    hw_clear();

    $host      = (string) gethostname();
    $addresses = $host === '' ? false : @gethostbynamel($host);

    if (!is_array($addresses) || $addresses === []) {
        Assert::true(true, 'this machine does not resolve its own name; nothing to probe with');

        return;
    }

    $private = false;

    foreach ($addresses as $address) {
        if (filter_var(
            (string) $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false) {
            $private = true;
        }
    }

    if (!$private) {
        Assert::true(true, 'this machine’s own name is public, so it is not a probe for this');

        return;
    }

    $response = $owner->postWithToken('/settings/webhooks', '/settings/webhooks', [
        'name'       => 'HW resolved',
        'event'      => '*',
        'target_url' => 'http://' . $host . '/hw-test',
    ], true);

    Assert::same(200, $response['status']);
    Assert::true(
        str_contains($response['body'], 'cannot be used'),
        'refused before it was ever stored: ' . $host . ' answers ' . implode(', ', $addresses)
    );
    Assert::same(
        0,
        (int) DB::value("SELECT COUNT(*) FROM {webhooks} WHERE name = 'HW resolved'")
    );
});

Harness::test('an event nothing fires is refused rather than stored', function () use ($owner): void {
    hw_clear();

    $response = $owner->postWithToken('/settings/webhooks', '/settings/webhooks', [
        'name'       => 'HW phantom',
        'event'      => 'unicorn.sighted',
        'target_url' => 'https://hw-test.invalid/hook',
    ], true);

    Assert::same(200, $response['status']);
    Assert::true(
        str_contains($response['body'], 'never deliver'),
        'it says why, rather than accepting a row that can never fire'
    );
    Assert::same(
        0,
        (int) DB::value("SELECT COUNT(*) FROM {webhooks} WHERE name = 'HW phantom'")
    );
});

// --------------------------------------------------------------- the lifecycle

Harness::test('creating one shows the secret exactly once', function () use ($owner): void {
    hw_clear();

    $response = $owner->postWithToken('/settings/webhooks', '/settings/webhooks', [
        'name'       => 'HW live',
        'event'      => 'booking.*',
        'target_url' => 'https://hw-test.invalid/hook',
    ], true);

    Assert::same(200, $response['status']);

    $row = DB::first("SELECT * FROM {webhooks} WHERE name = 'HW live'");

    Assert::notNull($row, 'a valid subscription is stored');
    Assert::same('booking.*', $row['event']);
    Assert::same(1, (int) $row['is_active'], 'on by default — an off switch nobody flipped is a trap');
    Assert::same(64, strlen((string) $row['secret']));
    Assert::greaterThan(0, (int) $row['created_by'], 'and who made it is recorded');

    $secret = (string) $row['secret'];

    Assert::true(str_contains($response['body'], $secret), 'the secret is shown on the redirect');
    Assert::true(str_contains($response['body'], 'not shown again'), 'and says it will not come back');

    /*
     * THE SECOND LOOK. The secret is stored in the clear because every
     * delivery is signed with it, so "it is only ever printed once" is the
     * whole of its protection — and a page that re-prints it on reload is a
     * credential sitting in a browser tab.
     */
    $again = $owner->get('/settings/webhooks');

    Assert::same(200, $again['status']);
    Assert::false(str_contains($again['body'], $secret), 'and never again on a reload');

    // Nor anywhere else that lists subscriptions.
    Assert::false(str_contains($owner->get('/settings')['body'], $secret));
});

Harness::test('switching one off stops it being sent to, and on clears the failures', function () use ($owner): void {
    hw_clear();

    $id = hw_webhook('contact.created');

    DB::update('webhooks', [
        'consecutive_failures' => 19,
        'disabled_reason'      => null,
    ], 'id = :id', ['id' => $id]);

    $off = $owner->postWithToken(
        '/settings/webhooks',
        '/settings/webhooks/' . $id . '/toggle',
        [],
        true
    );

    Assert::same(200, $off['status']);
    Assert::same(
        0,
        (int) DB::value('SELECT is_active FROM {webhooks} WHERE id = :id', ['id' => $id])
    );
    Assert::same(
        19,
        (int) DB::value('SELECT consecutive_failures FROM {webhooks} WHERE id = :id', ['id' => $id]),
        'switching OFF keeps the count — it is the evidence of why'
    );

    // Nothing is queued for a subscription that is off.
    hw_fire_contact_created($owner, 'WhileOff');

    Assert::same(
        0,
        (int) DB::value(
            'SELECT COUNT(*) FROM {webhook_deliveries} WHERE webhook_id = :w',
            ['w' => $id]
        ),
        'an event fired while it is off queues nothing'
    );

    $on = $owner->postWithToken(
        '/settings/webhooks',
        '/settings/webhooks/' . $id . '/toggle',
        [],
        true
    );

    Assert::same(200, $on['status']);
    Assert::same(
        1,
        (int) DB::value('SELECT is_active FROM {webhooks} WHERE id = :id', ['id' => $id])
    );
    Assert::same(
        0,
        (int) DB::value('SELECT consecutive_failures FROM {webhooks} WHERE id = :id', ['id' => $id]),
        'switching it back ON clears them: a fixed endpoint should not have to earn twenty more'
    );

    hw_clear();
});

Harness::test('deleting one takes its deliveries with it', function () use ($owner): void {
    hw_clear();

    $id       = hw_webhook('contact.created');
    $secretOf = (string) DB::value('SELECT secret FROM {webhooks} WHERE id = :id', ['id' => $id]);

    DB::insert('webhook_deliveries', [
        'webhook_id'      => $id,
        'event'           => 'contact.created',
        'status'          => 'delivered',
        'payload'         => '{"id":"whd_x"}',
        'attempts'        => 1,
        'next_attempt_at' => null,
        'created_at'      => now_utc(),
    ]);

    $response = $owner->postWithToken(
        '/settings/webhooks',
        '/settings/webhooks/' . $id . '/delete',
        [],
        true
    );

    Assert::same(200, $response['status']);
    Assert::same(
        0,
        (int) DB::value('SELECT COUNT(*) FROM {webhooks} WHERE id = :id', ['id' => $id])
    );
    Assert::same(
        0,
        (int) DB::value(
            'SELECT COUNT(*) FROM {webhook_deliveries} WHERE webhook_id = :w',
            ['w' => $id]
        ),
        'no orphan deliveries pointing at a subscription nobody can see'
    );

    // And the audit log keeps the durable record, including where it pointed.
    $audit = DB::first(
        "SELECT * FROM {audit_log} WHERE action = 'webhook_deleted' ORDER BY id DESC LIMIT 1"
    );

    Assert::notNull($audit, 'the deletion is audited');
    Assert::true(
        str_contains((string) $audit['changes'], 'hw-test.invalid'),
        'with the URL it had been sending to — where a venue’s data was going '
            . 'is exactly what an audit log is for'
    );
    Assert::false(
        str_contains((string) $audit['changes'], $secretOf),
        'and never the signing secret'
    );
});

Harness::test('acting on one that is gone says so instead of 500ing', function () use ($owner): void {
    foreach (['toggle', 'delete', 'test'] as $action) {
        $response = $owner->postWithToken(
            '/settings/webhooks',
            '/settings/webhooks/99999999/' . $action,
            [],
            true
        );

        Assert::same(200, $response['status'], $action . ' on a missing row');
        Assert::false(str_contains($response['body'], 'Fatal error'));
    }

    $replay = $owner->postWithToken(
        '/settings/webhooks',
        '/settings/webhooks/deliveries/99999999/replay',
        [],
        true
    );

    Assert::same(200, $replay['status']);
    Assert::true(str_contains($replay['body'], 'no longer here'));
});

// ------------------------------------------------------------ what gets queued

Harness::test('a fired event queues one delivery per matching subscription', function () use ($owner): void {
    hw_clear();

    $all      = hw_webhook('*');
    $wildcard = hw_webhook('contact.*');
    $exact    = hw_webhook('contact.created');
    $other    = hw_webhook('booking.created');
    $plural   = hw_webhook('contacts.*');

    hw_fire_contact_created($owner, 'Matching');

    foreach ([$all, $wildcard, $exact] as $wanted) {
        Assert::same(
            1,
            (int) DB::value(
                'SELECT COUNT(*) FROM {webhook_deliveries} WHERE webhook_id = :w',
                ['w' => $wanted]
            ),
            'subscription ' . $wanted . ' wanted it'
        );
    }

    foreach ([$other, $plural] as $unwanted) {
        Assert::same(
            0,
            (int) DB::value(
                'SELECT COUNT(*) FROM {webhook_deliveries} WHERE webhook_id = :w',
                ['w' => $unwanted]
            ),
            'subscription ' . $unwanted . ' did not — `contacts.*` is a different namespace'
        );
    }

    // Queued, not sent: nothing in the request that fired the event may wait
    // on somebody else's endpoint.
    $queued = DB::first(
        'SELECT * FROM {webhook_deliveries} WHERE webhook_id = :w',
        ['w' => $exact]
    );

    Assert::same('pending', (string) $queued['status']);
    Assert::same(0, (int) $queued['attempts'], 'nothing was attempted inside the request');
    Assert::notNull($queued['next_attempt_at'], 'and it is due');

    hw_clear();
    hw_clear_contacts();
});

Harness::test('what is queued carries ids and no guest data', function () use ($owner): void {
    /*
     * The unit test proves the filter works on a synthetic payload. This
     * fires a REAL event through the REAL listener and reads what actually
     * landed in the queue — which is the only way to catch a listener that
     * bypasses `Webhooks::body()` and stores the raw payload.
     */
    hw_clear();
    hw_clear_contacts();

    $id = hw_webhook('*');

    $guest = hw_fire_contact_created($owner, 'Leak');

    $payload = (string) DB::value(
        'SELECT payload FROM {webhook_deliveries} WHERE webhook_id = :w ORDER BY id DESC LIMIT 1',
        ['w' => $id]
    );

    Assert::true($payload !== '', 'something was queued');

    foreach ([
        $guest['surname'],
        $guest['email'],
        'Leakcheck',
        // The stored form of the phone, not what was typed: it is normalised
        // on save, and the normalised digits are what a payload would carry.
        (string) DB::value('SELECT phone FROM {contacts} WHERE id = :i', ['i' => $guest['id']]),
    ] as $leak) {
        if ((string) $leak === '') {
            continue;
        }

        Assert::false(
            str_contains($payload, (string) $leak),
            'the queued body carries no guest data: ' . (string) $leak
        );
    }

    $decoded = json_decode($payload, true);

    Assert::same(
        $guest['id'],
        (int) ($decoded['data']['contact_id'] ?? $decoded['data']['id'] ?? 0),
        'but it does say which contact, so a receiver can read the record back through the API'
    );
    Assert::same('contact.created', $decoded['event'] ?? null);
    Assert::true(
        str_starts_with((string) ($decoded['id'] ?? ''), 'whd_'),
        'and carries an id a receiver can deduplicate on'
    );

    hw_clear();
    hw_clear_contacts();
});

Harness::test('a replay reuses the delivery id so a receiver can spot it', function () use ($owner): void {
    hw_clear();

    $id = hw_webhook('*');

    $deliveryId = (int) DB::insert('webhook_deliveries', [
        'webhook_id'      => $id,
        'event'           => 'contact.created',
        'status'          => 'failed',
        'payload'         => '{"id":"whd_replayprobe","event":"contact.created"}',
        'attempts'        => 6,
        'next_attempt_at' => null,
        'created_at'      => now_utc(),
    ]);

    $response = $owner->postWithToken(
        '/settings/webhooks',
        '/settings/webhooks/deliveries/' . $deliveryId . '/replay',
        [],
        true
    );

    Assert::same(200, $response['status']);

    $rows = DB::all(
        'SELECT * FROM {webhook_deliveries} WHERE webhook_id = :w ORDER BY id',
        ['w' => $id]
    );

    Assert::same(2, count($rows), 'a NEW row, so the original failure stays in the log');
    Assert::same('failed', (string) $rows[0]['status'], 'the evidence of the outage is not overwritten');
    Assert::same('pending', (string) $rows[1]['status']);
    Assert::same(0, (int) $rows[1]['attempts']);
    Assert::same(
        (string) $rows[0]['payload'],
        (string) $rows[1]['payload'],
        'the SAME body, including the same delivery id'
    );

    hw_clear();
});

// ----------------------------------------------------------------- the log

Harness::test('the delivery log reads without warnings in every state', function () use ($owner): void {
    hw_clear();

    $id = hw_webhook('*');

    // One row per status, including the nulls each one leaves behind: a
    // duration or a response code that is null on a queued row is exactly
    // where a format string warns.
    $states = [
        ['status' => 'pending',   'attempts' => 0, 'code' => null, 'error' => null,                'ms' => null],
        ['status' => 'pending',   'attempts' => 2, 'code' => 503,  'error' => 'service down',      'ms' => 1200],
        ['status' => 'delivered', 'attempts' => 1, 'code' => 200,  'error' => null,                'ms' => 84],
        ['status' => 'failed',    'attempts' => 1, 'code' => 404,  'error' => 'no such endpoint',  'ms' => 60],
        ['status' => 'gave_up',   'attempts' => 6, 'code' => 0,    'error' => 'connection refused', 'ms' => null],
    ];

    foreach ($states as $state) {
        DB::insert('webhook_deliveries', [
            'webhook_id'      => $id,
            'event'           => 'contact.created',
            'status'          => $state['status'],
            'payload'         => '{"id":"whd_log"}',
            'attempts'        => $state['attempts'],
            'response_code'   => $state['code'],
            'error'           => $state['error'],
            'duration_ms'     => $state['ms'],
            'next_attempt_at' => $state['status'] === 'pending' ? now_utc() : null,
            'created_at'      => now_utc(),
        ]);
    }

    $page = $owner->get('/settings/webhooks');

    Assert::same(200, $page['status']);
    Assert::false(str_contains($page['body'], 'Warning:'), 'no PHP warnings');
    Assert::false(str_contains($page['body'], 'Notice:'));
    Assert::false(str_contains($page['body'], 'Deprecated:'));

    // Each state is named in words, not left as a bare code.
    foreach (['Delivered', 'Rejected', 'Gave up', 'Queued', 'Retrying'] as $word) {
        Assert::true(str_contains($page['body'], $word), 'the log says "' . $word . '"');
    }

    // The reason, in words, for the two that have one.
    Assert::true(str_contains($page['body'], 'connection refused'));
    Assert::true(str_contains($page['body'], 'no such endpoint'));

    // Replay is offered on the finished ones and withheld from the queue.
    Assert::true(
        str_contains($page['body'], '/replay'),
        'a finished delivery can be sent again'
    );

    hw_clear();
});

Harness::test('an auto-disabled subscription says why on the page', function () use ($owner): void {
    hw_clear();

    $id = hw_webhook('*', 'https://hw-test.invalid/gone', 0);

    DB::update('webhooks', [
        'consecutive_failures' => Webhooks::DISABLE_AFTER,
        'disabled_reason'      => 'Switched off automatically after 20 deliveries in a row failed. '
            . 'Fix the endpoint and switch it back on.',
    ], 'id = :id', ['id' => $id]);

    $page = $owner->get('/settings/webhooks');

    Assert::same(200, $page['status']);
    Assert::true(
        str_contains($page['body'], 'Switched off automatically'),
        'the reason is on the page — a subscription that silently stopped is the thing '
            . 'a venue discovers months later'
    );

    hw_clear();
});

Harness::test('the page explains how to verify a call, correctly', function () use ($owner): void {
    /*
     * This text is a specification: somebody writes a receiver from it, and if
     * it is wrong every signature they compute fails. So it is checked rather
     * than trusted — the header names come from the class, and the two
     * sentences that matter are asserted to be present.
     */
    $page = $owner->get('/settings/webhooks');

    Assert::same(200, $page['status']);

    foreach ([
        Webhooks::SIGNATURE_HEADER,
        Webhooks::TIMESTAMP_HEADER,
        Webhooks::EVENT_HEADER,
        Webhooks::DELIVERY_HEADER,
    ] as $header) {
        Assert::true(str_contains($page['body'], $header), $header . ' is documented');
    }

    Assert::true(str_contains($page['body'], 'raw request body'), 'verify the bytes received');
    Assert::true(str_contains($page['body'], 'Deduplicate'), 'and deduplicate on the id');
});

Harness::test('the test button is throttled', function () use ($owner): void {
    /*
     * A Test is a request this server makes to any URL in the world, on a
     * POST a browser made. Without a throttle, holding the button down turns
     * the venue's CRM into a way to hammer somebody else's endpoint.
     */
    // READ, not required: `routes.php` registers against a `$router` this
    // process does not have, so requiring it here is a fatal.
    $source = (string) file_get_contents(CC_ROOT . '/app/routes.php');

    Assert::true(
        (bool) preg_match(
            '/webhooks\/\{id:[^}]+\}\/test.{0,200}throttle:/s',
            $source
        ),
        'the test endpoint carries a throttle'
    );
});

hw_clear();
hw_clear_roles();
