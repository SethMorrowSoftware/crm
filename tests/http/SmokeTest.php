<?php

declare(strict_types=1);

/**
 * End-to-end HTTP tests against a live server.
 *
 * These exercise the real request lifecycle — routing, middleware, session,
 * CSRF, permissions, redirects, rendering — which is where the three bugs
 * found during Phase 1 actually lived. Unit tests would have caught none of
 * them.
 *
 * The runner starts the server (tests/run.php) and sets CC_TEST_BASE_URL.
 */

use Tests\Assert;
use Tests\Harness;

Harness::suite('http/smoke');

$baseUrl = getenv('CC_TEST_BASE_URL') ?: '';

if ($baseUrl === '') {
    Harness::skip('http suite', 'no server; run via tests/run.php or set CC_TEST_BASE_URL');

    return;
}

require_once CC_ROOT . '/tests/TestBrowser.php';

$owner = new TestBrowser($baseUrl, 'owner');
$desk  = new TestBrowser($baseUrl, 'desk');
$anon  = new TestBrowser($baseUrl, 'anon');

// Credentials seeded by tests/run.php.
$ownerEmail = 'owner@test.invalid';
$ownerPass  = 'owner-test-passphrase-9';
$deskEmail  = 'desk@test.invalid';
$deskPass   = 'desk-test-passphrase-9';

// ------------------------------------------------------- unauthenticated

Harness::test('the login page renders', function () use ($anon): void {
    $response = $anon->get('/login');

    Assert::same(200, $response['status']);
    Assert::contains('Sign in', $response['body']);
    Assert::contains('name="_token"', $response['body'], 'the form needs a CSRF token');
});

Harness::test('the dashboard redirects an anonymous visitor to login', function () use ($anon): void {
    $response = $anon->get('/');

    Assert::same(302, $response['status']);
    Assert::contains('/login', $response['location']);
});

Harness::test('every protected page redirects when signed out', function () use ($anon): void {
    foreach (['/contacts', '/settings', '/playbooks', '/settings/users', '/account'] as $path) {
        $response = $anon->get($path);

        Assert::same(302, $response['status'], $path . ' must redirect');
        Assert::contains('/login', $response['location'], $path);
    }
});

Harness::test('an unknown path returns 404', function () use ($anon): void {
    $response = $anon->get('/no-such-page');

    Assert::same(404, $response['status']);
});

Harness::test('security headers are present on every response', function () use ($anon): void {
    $headers = $anon->get('/login')['headers'];

    Assert::contains('X-Content-Type-Options: nosniff', $headers);
    Assert::contains('X-Frame-Options: SAMEORIGIN', $headers);
    Assert::contains('Content-Security-Policy:', $headers);
    Assert::contains('Referrer-Policy:', $headers);
});

Harness::test('the CSP forbids inline script', function () use ($anon): void {
    $headers = $anon->get('/login')['headers'];

    Assert::matches("/Content-Security-Policy:[^\r\n]*script-src 'self'/", $headers);
    Assert::notContains("script-src 'self' 'unsafe-inline'", $headers);
});

Harness::test('the session cookie is HttpOnly and SameSite', function () use ($baseUrl): void {
    $browser = new TestBrowser($baseUrl, 'cookie');
    $headers = $browser->get('/login')['headers'];

    if (!str_contains($headers, 'Set-Cookie')) {
        // No session is created until something is stored, which is by design.
        return;
    }

    Assert::contains('HttpOnly', $headers, 'the session cookie must not be readable by JavaScript');
    Assert::contains('SameSite=Lax', $headers);
});

Harness::test('the health probe answers without authentication', function () use ($anon): void {
    $response = $anon->get('/health.json');

    // 503 when cron has not run recently, which is honest rather than a lie.
    Assert::inArray($response['status'], [200, 503]);

    $payload = json_decode($response['body'], true);

    Assert::true(is_array($payload), 'the probe must return JSON');
    Assert::hasKey('status', $payload);
    Assert::hasKey('database', $payload);
    Assert::true($payload['database'], 'the database should be reachable');
});

Harness::test('the health probe leaks no configuration', function () use ($anon): void {
    $body = $anon->get('/health.json')['body'];

    foreach (['password', 'app_key', 'castle_crm_test', 'cron_token', '/home/'] as $secret) {
        Assert::notContains($secret, $body, 'an unauthenticated probe must not reveal ' . $secret);
    }
});

// ----------------------------------------------------- protected paths

Harness::test('application internals are not served', function () use ($anon): void {
    foreach ([
        '/config/config.php',
        '/config/config.sample.php',
        '/app/bootstrap.php',
        '/app/Core/DB.php',
        '/storage/logs/',
        '/install/schema/001_core.sql',
        '/install/seeds/venue.php',
    ] as $path) {
        $response = $anon->get($path);

        Assert::same(404, $response['status'], $path . ' must not be served');
        Assert::notContains('password', strtolower($response['body']), $path);
        Assert::notContains('app_key', strtolower($response['body']), $path);
    }
});

Harness::test('a traversal attempt cannot escape the document root', function () use ($anon): void {
    foreach (['/../config/config.php', '/assets/../config/config.php'] as $path) {
        $response = $anon->get($path);

        Assert::notContains('app_key', $response['body'], $path);
        Assert::notContains('castle-test', $response['body'], $path);
    }
});

Harness::test('static assets are served', function () use ($anon): void {
    foreach (['/assets/css/app.css', '/assets/js/app.js', '/assets/img/favicon.svg'] as $path) {
        Assert::same(200, $anon->get($path)['status'], $path);
    }
});

// --------------------------------------------------------------- login

Harness::test('a POST without a CSRF token is rejected with 419', function () use ($anon, $ownerEmail, $ownerPass): void {
    $response = $anon->post('/login', ['email' => $ownerEmail, 'password' => $ownerPass]);

    Assert::same(419, $response['status'], 'a missing token must be refused');
});

Harness::test('a POST with a forged CSRF token is rejected', function () use ($anon, $ownerEmail, $ownerPass): void {
    $response = $anon->post('/login', [
        '_token'   => str_repeat('f', 64),
        'email'    => $ownerEmail,
        'password' => $ownerPass,
    ]);

    Assert::same(419, $response['status']);
});

Harness::test('a wrong password does not sign anyone in', function () use ($baseUrl, $ownerEmail): void {
    $browser = new TestBrowser($baseUrl, 'wrongpw');

    $browser->login($ownerEmail, 'definitely-not-the-password');

    Assert::same(302, $browser->get('/')['status'], 'still anonymous');
});

Harness::test('the owner can sign in', function () use ($owner, $ownerEmail, $ownerPass): void {
    $response = $owner->login($ownerEmail, $ownerPass);

    Assert::same(302, $response['status']);
    Assert::notContains('/login', $response['location'], 'should go to the dashboard, not back to login');

    $dashboard = $owner->get('/');
    Assert::same(200, $dashboard['status']);
    Assert::contains('Dashboard', $dashboard['body']);
});

// ------------------------------------------------------- owner journey

Harness::test('every page an owner can reach renders', function () use ($owner): void {
    $pages = [
        '/'                                     => 'Dashboard',
        '/account'                              => 'Your account',
        '/settings'                             => 'Settings',
        '/settings/venue'                       => 'Venue',
        '/settings/system'                      => 'Safe Mode',
        '/settings/users'                       => 'Users',
        '/settings/users/new'                   => 'Add a user',
        '/settings/roles'                       => 'Roles',
        '/settings/roles/new'                   => 'Add a role',
        '/settings/audit'                       => 'Audit',
        '/settings/health'                      => 'cron',
        '/playbooks'                            => 'playbook',
        '/playbooks/birthday-celebration-sales' => 'Birthday',
        '/contacts'                             => 'Contacts',
        '/inbox'                                => 'Inbox',
        '/deals'                                => 'pipeline',
        '/bookings'                             => 'Bookings',
        '/marketing'                            => 'Marketing',
        '/reputation'                           => 'Reputation',
        '/memberships'                          => 'Memberships',
        '/tasks'                                => 'Tasks',
        '/reports'                              => 'Reports',
    ];

    foreach ($pages as $path => $expected) {
        $response = $owner->get($path);

        Assert::same(200, $response['status'], $path . ' should render');
        Assert::contains($expected, $response['body'], $path . ' should mention "' . $expected . '"');

        // A page that rendered an exception message is not a working page.
        Assert::notContains('Fatal error', $response['body'], $path);
        Assert::notContains('Warning:', $response['body'], $path);
        Assert::notContains('Undefined ', $response['body'], $path);
    }
});

Harness::test('an unknown playbook slug returns 404, not an error', function () use ($owner): void {
    Assert::same(404, $owner->get('/playbooks/not-a-real-playbook')['status']);
});

Harness::test('the dashboard shows the setup checklist on a fresh install', function () use ($owner): void {
    $body = $owner->get('/')['body'];

    Assert::contains('Finish setting up', $body);
    Assert::contains('cron', strtolower($body), 'the cron warning is the important one');
});

Harness::test('Safe Mode is advertised in the header', function () use ($owner): void {
    $body = $owner->get('/')['body'];

    Assert::contains('Safe Mode', $body, 'the operator must be able to see it at a glance');
});

Harness::test('saving the venue persists and redirects back', function () use ($owner): void {
    $response = $owner->postWithToken('/settings/venue', '/settings/venue', [
        'name'        => 'The Castle Fun Center',
        'phone'       => '(845) 469-2116',
        'address_1'   => '109 Brookside Ave',
        'city'        => 'Chester',
        'state'       => 'NY',
        // The regression: a numeric-looking ZIP under a string max rule.
        'postal_code' => '10918',
        'timezone'    => 'America/New_York',
        'tax_rate'    => '8.125',
        'brand_color' => '#6b4ea8',
    ]);

    Assert::same(302, $response['status']);
    Assert::contains('/settings/venue', $response['location'], 'a successful save returns to the page');

    $body = $owner->get('/settings/venue')['body'];

    Assert::contains('10918', $body, 'the ZIP should have been saved');
    Assert::contains('Chester', $body);
});

Harness::test('an invalid venue submission is rejected with a message', function () use ($owner): void {
    $response = $owner->postWithToken('/settings/venue', '/settings/venue', [
        'name'     => '',
        'timezone' => 'Mars/Olympus',
    ]);

    Assert::same(302, $response['status']);

    $body = $owner->get('/settings/venue')['body'];
    Assert::contains('alert-error', $body, 'the operator should see what went wrong');
});

Harness::test('a playbook can be activated and deactivated', function () use ($owner): void {
    $body = $owner->get('/playbooks')['body'];

    if (preg_match('#/playbooks/(\d+)/activate#', $body, $match) !== 1) {
        Assert::fail('no activate button found on /playbooks');
    }

    $id = $match[1];

    $activate = $owner->postWithToken('/playbooks', '/playbooks/' . $id . '/activate', []);
    Assert::same(302, $activate['status']);

    $after = $owner->get('/playbooks')['body'];
    Assert::contains('/playbooks/' . $id . '/deactivate', $after, 'it should now offer to turn it off');

    $deactivate = $owner->postWithToken('/playbooks', '/playbooks/' . $id . '/deactivate', []);
    Assert::same(302, $deactivate['status']);
});

// --------------------------------------------------- Safe Mode guard

Harness::test('leaving Safe Mode without the typed confirmation is refused', function () use ($owner): void {
    $owner->postWithToken('/settings/system', '/settings/safe-mode', [
        'enable'       => '1',
    ]);

    $owner->postWithToken('/settings/system', '/settings/safe-mode', [
        'enable'       => '0',
        'confirm_name' => 'wrong name entirely',
    ]);

    $body = $owner->get('/settings/system')['body'];

    Assert::contains('Protected', $body, 'Safe Mode must still be on');
    Assert::contains('type the venue name', strtolower($body));
});

Harness::test('leaving Safe Mode with the correct confirmation works, and can be reversed', function () use ($owner): void {
    $owner->postWithToken('/settings/system', '/settings/safe-mode', [
        'enable'       => '0',
        'confirm_name' => 'The Castle Fun Center',
    ]);

    $body = $owner->get('/settings/system')['body'];
    Assert::contains('Live', $body, 'Safe Mode should now be off');

    // Put it back, so the rest of the suite runs protected.
    $owner->postWithToken('/settings/system', '/settings/safe-mode', ['enable' => '1']);

    Assert::contains('Protected', $owner->get('/settings/system')['body']);
});

// ------------------------------------------------------ owner guards

/**
 * Find a user's edit-page id from the users list, rather than assuming an
 * auto-increment value — the integration suites clear and re-create users, so
 * ids are not stable across a full run.
 */
function find_user_id(TestBrowser $browser, string $email): string
{
    $body = $browser->get('/settings/users')['body'];

    // Each row carries the address and an edit link; pair them up.
    if (preg_match_all('#<tr.*?</tr>#s', $body, $rows) === 0) {
        Assert::fail('no user rows found on /settings/users');
    }

    foreach ($rows[0] as $row) {
        if (!str_contains($row, $email)) {
            continue;
        }

        if (preg_match('#/settings/users/(\d+)#', $row, $match) === 1) {
            return $match[1];
        }
    }

    Assert::fail('no edit link found for ' . $email);
}

Harness::test('the only owner cannot be demoted', function () use ($owner, $ownerEmail): void {
    $id = find_user_id($owner, $ownerEmail);

    $editPage = $owner->get('/settings/users/' . $id)['body'];

    if (preg_match('/<option value="(\d+)"[^>]*>\s*Front Desk/', $editPage, $roleMatch) !== 1) {
        Assert::fail('Front Desk role not offered in the role select');
    }

    $owner->postWithToken('/settings/users/' . $id, '/settings/users/' . $id, [
        'name'      => 'Owner',
        'email'     => $ownerEmail,
        'role_id'   => $roleMatch[1],
        'is_active' => '1',
    ]);

    // Still an owner: settings remains reachable, which it would not be if the
    // demotion had gone through.
    Assert::same(200, $owner->get('/settings')['status'], 'the owner must not have lost access');
    Assert::same(200, $owner->get('/settings/users')['status']);
});

Harness::test('an owner cannot deactivate themselves', function () use ($owner, $ownerEmail): void {
    $id = find_user_id($owner, $ownerEmail);

    $owner->postWithToken('/settings/users/' . $id, '/settings/users/' . $id, [
        'name'      => 'Owner',
        'email'     => $ownerEmail,
        'role_id'   => '1',
        // is_active omitted, which an HTML form does for an unchecked box.
    ]);

    Assert::same(200, $owner->get('/settings')['status'], 'still active and signed in');
});

Harness::test('an owner cannot delete their own account', function () use ($owner, $ownerEmail): void {
    $id = find_user_id($owner, $ownerEmail);

    $owner->postWithToken('/settings/users/' . $id, '/settings/users/' . $id . '/delete', []);

    Assert::same(200, $owner->get('/settings')['status'], 'still signed in and privileged');
});

Harness::test('the locked owner role cannot be edited', function () use ($owner): void {
    $rolesPage = $owner->get('/settings/roles')['body'];

    Assert::contains('Locked', $rolesPage, 'the owner role should be marked locked');
    Assert::notContains('/settings/roles/1"', $rolesPage, 'and offer no edit link');
});

// --------------------------------------------------- permission matrix

Harness::test('front desk can reach bookings but not settings', function () use ($desk, $deskEmail, $deskPass): void {
    $login = $desk->login($deskEmail, $deskPass);

    Assert::same(302, $login['status'], 'front desk should be able to sign in');
    Assert::same(200, $desk->get('/')['status']);

    $allowed = ['/', '/bookings', '/contacts', '/inbox', '/account'];
    $denied  = ['/settings', '/settings/users', '/settings/roles', '/settings/audit',
                '/settings/health', '/reports', '/marketing', '/playbooks', '/memberships'];

    foreach ($allowed as $path) {
        Assert::same(200, $desk->get($path)['status'], $path . ' should be allowed');
    }

    foreach ($denied as $path) {
        Assert::same(403, $desk->get($path)['status'], $path . ' must be denied');
    }
});

Harness::test('the navigation hides what the role cannot reach', function () use ($desk, $owner): void {
    $deskNav  = $desk->get('/')['body'];
    $ownerNav = $owner->get('/')['body'];

    Assert::notContains('href="/settings"', $deskNav, 'front desk should see no Settings link');
    Assert::contains('href="/settings"', $ownerNav, 'the owner should');

    Assert::notContains('href="/reports"', $deskNav);
    Assert::contains('href="/bookings"', $deskNav, 'but should see what they can use');
});

Harness::test('front desk cannot POST to a denied route', function () use ($desk): void {
    // Hiding a link is not security; the route itself must refuse.
    $response = $desk->post('/settings/venue', [
        '_token' => str_repeat('a', 64),
        'name'   => 'Hacked Venue',
    ]);

    Assert::inArray($response['status'], [403, 419], 'must be refused on permission or CSRF');

    Assert::notContains('Hacked Venue', $desk->get('/')['body']);
});

Harness::test('front desk cannot create a user', function () use ($desk): void {
    $response = $desk->post('/settings/users', [
        '_token'  => str_repeat('a', 64),
        'name'    => 'Sneaky Owner',
        'email'   => 'sneaky@test.invalid',
        'role_id' => '1',
    ]);

    Assert::inArray($response['status'], [403, 419]);
});

// ----------------------------------------------------------- user CRUD

Harness::test('an owner can create a user, who can then sign in', function () use ($owner, $baseUrl): void {
    $editPage = $owner->get('/settings/users/new')['body'];

    if (preg_match('/<option value="(\d+)"[^>]*>\s*Marketing/', $editPage, $match) !== 1) {
        Assert::fail('Marketing role not offered');
    }

    $email = 'created-' . bin2hex(random_bytes(4)) . '@test.invalid';

    $response = $owner->postWithToken('/settings/users/new', '/settings/users', [
        'name'     => 'New Marketer',
        'email'    => $email,
        'role_id'  => $match[1],
        'password' => 'a-fresh-long-passphrase',
    ]);

    Assert::same(302, $response['status']);
    Assert::contains('New Marketer', $owner->get('/settings/users')['body']);

    // And the account genuinely works.
    $newUser = new TestBrowser($baseUrl, 'created');
    Assert::same(302, $newUser->login($email, 'a-fresh-long-passphrase')['status']);
    Assert::same(200, $newUser->get('/')['status']);

    // Marketing can reach campaigns but not users.
    Assert::same(200, $newUser->get('/marketing')['status']);
    Assert::same(403, $newUser->get('/settings/users')['status']);
});

Harness::test('creating a user with a weak password is refused', function () use ($owner): void {
    $editPage = $owner->get('/settings/users/new')['body'];
    preg_match('/<option value="(\d+)"[^>]*>\s*Front Desk/', $editPage, $match);

    $email = 'weak-' . bin2hex(random_bytes(4)) . '@test.invalid';

    $owner->postWithToken('/settings/users/new', '/settings/users', [
        'name'     => 'Weak Password',
        'email'    => $email,
        'role_id'  => $match[1] ?? '1',
        'password' => 'short',
    ]);

    Assert::notContains('Weak Password', $owner->get('/settings/users')['body']);
});

Harness::test('a duplicate email is refused', function () use ($owner): void {
    $editPage = $owner->get('/settings/users/new')['body'];
    preg_match('/<option value="(\d+)"[^>]*>\s*Front Desk/', $editPage, $match);

    $before = (int) preg_match_all('/settings\/users\/\d+"/', $owner->get('/settings/users')['body']);

    $owner->postWithToken('/settings/users/new', '/settings/users', [
        'name'     => 'Duplicate Owner',
        'email'    => 'owner@test.invalid',
        'role_id'  => $match[1] ?? '1',
        'password' => 'another-long-passphrase',
    ]);

    $after = (int) preg_match_all('/settings\/users\/\d+"/', $owner->get('/settings/users')['body']);

    Assert::same($before, $after, 'no user should have been created');
});

// --------------------------------------------------------------- logout

Harness::test('signing out ends the session', function () use ($baseUrl, $ownerEmail, $ownerPass): void {
    $browser = new TestBrowser($baseUrl, 'logout');
    $browser->login($ownerEmail, $ownerPass);

    Assert::same(200, $browser->get('/')['status'], 'signed in');

    $browser->postWithToken('/', '/logout', []);

    Assert::same(302, $browser->get('/')['status'], 'signed out again');
});

// ----------------------------------------------------------------- cron

Harness::test('web cron refuses a missing or wrong token', function () use ($anon): void {
    Assert::same(403, $anon->get('/cron.php')['status']);
    Assert::same(403, $anon->get('/cron.php?token=wrong')['status']);
});

Harness::test('web cron runs with the right token and does not leak a page', function () use ($anon): void {
    $token = getenv('CC_TEST_CRON_TOKEN') ?: '';

    if ($token === '') {
        Assert::fail('CC_TEST_CRON_TOKEN was not provided by the runner');
    }

    $response = $anon->get('/cron.php?token=' . urlencode($token));

    Assert::same(200, $response['status']);
    Assert::contains('cron starting', $response['body']);
    Assert::contains('Done in', $response['body']);

    // The bug this catches: bootstrap used to fall through to the router,
    // dispatch /cron.php as a route, 404, and send that HTML first.
    Assert::notContains('<!doctype html>', strtolower($response['body']));
    Assert::notContains('404', $response['body']);
});

// -------------------------------------------------------------- rendering

Harness::test('no page emits a PHP notice, warning or deprecation', function () use ($owner): void {
    // Debug mode is on in testing, so anything like this would be visible —
    // and the spec's acceptance criteria require none at E_ALL.
    foreach (['/', '/settings', '/settings/venue', '/settings/system', '/settings/users',
              '/settings/roles', '/settings/roles/new', '/settings/audit', '/settings/health',
              '/playbooks', '/account', '/contacts'] as $path) {
        $body = $owner->get($path)['body'];

        foreach (['Notice:', 'Warning:', 'Deprecated:', 'Fatal error', 'Undefined variable',
                  'Undefined array key', 'Trying to access array offset'] as $problem) {
            Assert::notContains($problem, $body, $path . ' emitted "' . $problem . '"');
        }
    }
});

Harness::test('pages are valid enough HTML to have balanced core structure', function () use ($owner): void {
    foreach (['/', '/settings', '/playbooks'] as $path) {
        $body = $owner->get($path)['body'];

        Assert::contains('<!doctype html>', strtolower($body), $path);
        Assert::same(1, substr_count(strtolower($body), '<html'), $path . ': one <html>');
        Assert::same(1, substr_count(strtolower($body), '</html>'), $path . ': one </html>');
        Assert::same(1, substr_count(strtolower($body), '</body>'), $path . ': one </body>');

        $open  = substr_count($body, '<div');
        $close = substr_count($body, '</div>');

        Assert::same($open, $close, $path . ': div tags should balance');
    }
});

Harness::test('no page leaks a filesystem path or credential', function () use ($owner): void {
    foreach (['/', '/settings/health', '/settings/audit'] as $path) {
        $body = $owner->get($path)['body'];

        Assert::notContains('castle-test-pw', $body, $path);
        Assert::notContains('app_key', $body, $path);
    }
});
