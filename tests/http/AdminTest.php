<?php

declare(strict_types=1);

/**
 * The administration pages added for the operator: email settings, backups,
 * data export, and the force-HTTPS switch.
 *
 * All of these hold or hand out something sensitive — a mail password, the
 * whole guest database, a redirect that could lock the operator out — so each
 * one is tested for who can reach it as much as for whether it renders.
 */

use App\Core\Backup;
use App\Core\DB;
use App\Core\Settings;
use Tests\Assert;
use Tests\Harness;

Harness::suite('http/admin');

$baseUrl = getenv('CC_TEST_BASE_URL') ?: '';

if ($baseUrl === '') {
    Harness::skip('admin suite', 'no server; run via tests/run.php');

    return;
}

if (!test_boot()) {
    Harness::skip('admin suite', 'no database available');

    return;
}

require_once CC_ROOT . '/tests/TestBrowser.php';

$owner = new TestBrowser($baseUrl, 'admin-owner');
$desk  = new TestBrowser($baseUrl, 'admin-desk');
$anon  = new TestBrowser($baseUrl, 'admin-anon');

$owner->login('owner@test.invalid', 'owner-test-passphrase-9');
$desk->login('desk@test.invalid', 'desk-test-passphrase-9');

/**
 * The server reads settings from its own connection, so a setting changed
 * here has to be written through the database and the cache cleared. The
 * server re-reads settings on each request, so this is enough.
 */
function server_setting(string $key, mixed $value): void
{
    Settings::set($key, $value);
    App\Core\Cache::flush();
}

// ------------------------------------------------------------- email settings

Harness::test('the email settings page renders for the owner', function () use ($owner): void {
    $response = $owner->get('/settings/mail');

    Assert::same(200, $response['status']);
    Assert::contains('How mail is sent', $response['body']);
    Assert::contains('name="from_email"', $response['body']);
    Assert::contains('Deliverability', $response['body'], 'the DNS records should be on the page');
    Assert::contains('_dmarc.', $response['body']);
});

Harness::test('the front desk cannot reach the email settings page', function () use ($desk): void {
    $response = $desk->get('/settings/mail');

    Assert::inArray(
        $response['status'],
        [302, 403],
        'a mail password is not front-desk business'
    );
});

Harness::test('the stored SMTP password is never rendered back into the form', function () use ($owner): void {
    // Set through the form rather than through Settings::set() here: the
    // credential is encrypted with the app_key, and the test runner gives the
    // server a different key from this process, so a value written here would
    // simply be unreadable over there — and the assertion would pass for the
    // wrong reason.
    $owner->postWithToken('/settings/mail', '/settings/mail', [
        'driver'        => 'smtp',
        'from_email'    => 'parties@example.test',
        'from_name'     => 'Castle',
        'smtp_host'     => 'mail.example.test',
        'smtp_password' => 'a-very-secret-password',
    ], true);

    $response = $owner->get('/settings/mail');

    Assert::same(200, $response['status']);
    Assert::notContains(
        'a-very-secret-password',
        $response['body'],
        'a credential in the HTML is a credential in the browser cache and in every screenshot'
    );
    Assert::contains('blank to keep it', strtolower($response['body']));

    Settings::forget('mail.smtp_password');
});

Harness::test('saving mail settings rejects SMTP with no host', function () use ($owner): void {
    $response = $owner->postWithToken('/settings/mail', '/settings/mail', [
        'driver'     => 'smtp',
        'from_email' => 'hello@example.test',
        'from_name'  => 'Castle',
        'smtp_host'  => '',
    ], true);

    Assert::contains('needs a host', $response['body']);
});

Harness::test('saving mail settings persists the driver', function () use ($owner): void {
    $response = $owner->postWithToken('/settings/mail', '/settings/mail', [
        'driver'     => 'log',
        'from_email' => 'parties@example.test',
        'from_name'  => 'The Castle Fun Center',
    ], true);

    Assert::same(200, $response['status']);

    Settings::load(true);

    Assert::same('log', Settings::get('mail.driver'));
    Assert::same('parties@example.test', Settings::get('mail.from_email'));
});

Harness::test('a blank password field leaves the stored password alone', function () use ($owner): void {
    // Written through the form, for the app_key reason above. What matters is
    // that the ciphertext in the row is unchanged by a save that left the
    // password field empty — which is checkable without being able to read it.
    $owner->postWithToken('/settings/mail', '/settings/mail', [
        'driver'        => 'smtp',
        'from_email'    => 'parties@example.test',
        'from_name'     => 'Castle',
        'smtp_host'     => 'mail.example.test',
        'smtp_password' => 'keep-me-please',
    ], true);

    $stored = DB::value("SELECT value FROM {settings} WHERE `key` = 'mail.smtp_password'");

    Assert::notNull($stored, 'the password should have been saved');
    Assert::notContains('keep-me-please', (string) $stored, 'and stored encrypted, not in the clear');

    // Now save again, changing the host and leaving the password blank.
    $owner->postWithToken('/settings/mail', '/settings/mail', [
        'driver'        => 'smtp',
        'from_email'    => 'parties@example.test',
        'from_name'     => 'Castle',
        'smtp_host'     => 'mail2.example.test',
        'smtp_password' => '',
    ], true);

    Assert::same(
        $stored,
        DB::value("SELECT value FROM {settings} WHERE `key` = 'mail.smtp_password'"),
        'otherwise changing the host silently wipes the credential'
    );

    Settings::load(true);

    Assert::same('mail2.example.test', Settings::get('mail.smtp_host'), 'and the host did change');

    Settings::forget('mail.smtp_password');
});

// ------------------------------------------------------------------- backups

Harness::test('the backups page renders', function () use ($owner): void {
    $response = $owner->get('/settings/backups');

    Assert::same(200, $response['status']);
    Assert::contains('Take a backup now', $response['body']);
    Assert::contains('config/config.php', $response['body'], 'the page must say what is NOT backed up');
});

Harness::test('the front desk cannot reach backups', function () use ($desk): void {
    foreach (['/settings/backups', '/settings/backups/export?table=contacts'] as $path) {
        $response = $desk->get($path);

        Assert::inArray(
            $response['status'],
            [302, 403],
            $path . ' must not be reachable by an account that can see one contact at a time'
        );
    }
});

Harness::test('an anonymous visitor cannot download a backup', function () use ($anon): void {
    $response = $anon->get('/settings/backups/castle-crm-20260101-000000.sql/download');

    Assert::inArray($response['status'], [302, 403, 404]);
    Assert::notContains('CREATE TABLE', $response['body']);
});

Harness::test('taking a backup over HTTP produces a downloadable file', function () use ($owner): void {
    foreach (Backup::all() as $existing) {
        Backup::delete($existing['filename']);
    }

    $response = $owner->postWithToken('/settings/backups', '/settings/backups', [], true);


    Assert::same(200, $response['status']);
    Assert::contains('Backup written', $response['body']);

    $backups = Backup::all();

    Assert::count(1, $backups);

    $download = $owner->get('/settings/backups/' . $backups[0]['filename'] . '/download');


    Assert::same(200, $download['status']);
    Assert::matches('/Content-Disposition:\s*attachment/i', $download['headers']);
    Assert::greaterThan(1000, strlen($download['body']), 'an empty download is not a backup');

    // Gzipped by default where zlib exists, so check the magic bytes rather
    // than the text.
    if (str_ends_with($backups[0]['filename'], '.gz')) {
        Assert::same("\x1f\x8b", substr($download['body'], 0, 2), 'that is not a gzip stream');
    } else {
        Assert::contains('CREATE TABLE', $download['body']);
    }
});

Harness::test('a backup download is audited with the actor', function () use ($owner): void {
    $backups = Backup::all();

    if ($backups === []) {
        Assert::fail('the previous test should have left a backup in place');
    }

    DB::delete('audit_log', "action = 'backup.downloaded'");

    $owner->get('/settings/backups/' . $backups[0]['filename'] . '/download');

    $entry = DB::first("SELECT * FROM {audit_log} WHERE action = 'backup.downloaded' ORDER BY id DESC LIMIT 1");

    Assert::notNull($entry, 'somebody taking a copy of the guest list must leave a trace');
    Assert::notNull($entry['user_id'], 'and the trace must name them');
});

Harness::test('a traversal attempt on the download route does not reach a file', function () use ($owner): void {
    // The guarantee being tested is that no file outside storage/backups is
    // ever served — NOT that each attempt produces a particular status. An
    // encoded `..` is caught by Request::resolvePath(), which rewrites the
    // whole path to `/` rather than trying to be clever, so that one lands on
    // the dashboard; the others miss the route constraint and 404. All three
    // are safe, and asserting on the content is what proves it.
    $configContents = (string) file_get_contents(CC_ROOT . '/config/config.php');

    preg_match("/'app_key'\s*=>\s*'([^']+)'/", $configContents, $keyMatch);

    foreach ([
        '/settings/backups/..%2f..%2fconfig%2fconfig.php/download',
        '/settings/backups/..%2F..%2Fconfig%2Fconfig.php/download',
        '/settings/backups/config.php/download',
        '/settings/backups/castle-crm-00000000-000000.sql/download',
        '/settings/backups/castle-crm-20260101-000000.sql.gz%00.png/download',
    ] as $path) {
        $response = $owner->get($path);

        Assert::notContains('app_key', $response['body'], $path . ' served the config file');
        Assert::notContains('DB_PASSWORD', $response['body'], $path);

        if (isset($keyMatch[1])) {
            Assert::notContains($keyMatch[1], $response['body'], $path . ' leaked the encryption key');
        }

        Assert::false(
            str_starts_with($response['body'], "\x1f\x8b"),
            $path . ' returned a gzip stream, so something was downloaded'
        );
    }
});

Harness::test('deleting a backup needs a CSRF token', function () use ($owner): void {
    $backups = Backup::all();

    if ($backups === []) {
        Assert::fail('expected a backup to delete');
    }

    $response = $owner->post('/settings/backups/' . $backups[0]['filename'] . '/delete', ['_token' => 'wrong']);

    Assert::inArray($response['status'], [419, 403, 302]);
    Assert::count(count($backups), Backup::all(), 'the file should still be there');
});

Harness::test('retention is enforced when the limit is lowered', function () use ($owner): void {
    $dir = Backup::directory();

    foreach (['20260201-010000', '20260202-010000', '20260203-010000'] as $i => $stamp) {
        file_put_contents($dir . '/castle-crm-' . $stamp . '.sql', '-- filler');
        touch($dir . '/castle-crm-' . $stamp . '.sql', 1_770_000_000 + ($i * 86400));
    }

    Assert::greaterThan(2, count(Backup::all()));

    $response = $owner->postWithToken('/settings/backups', '/settings/backups/settings', [
        'keep'    => '1',
        'nightly' => '1',
    ], true);

    Assert::same(200, $response['status']);
    Assert::count(1, Backup::all(), 'lowering the limit should prune immediately');

    $owner->postWithToken('/settings/backups', '/settings/backups/settings', [
        'keep'    => '7',
        'nightly' => '1',
    ], true);
});

// ------------------------------------------------------------- CSV export

Harness::test('a CSV export downloads with a header row', function () use ($owner): void {
    $id = test_make_contact(['first_name' => 'Exported', 'last_name' => 'Guest']);

    $response = $owner->get('/settings/backups/export?table=contacts');

    Assert::same(200, $response['status']);
    Assert::matches('/Content-Type:\s*text\/csv/i', $response['headers']);
    Assert::matches('/Content-Disposition:\s*attachment; filename="contacts-/i', $response['headers']);
    Assert::contains('first_name', $response['body']);
    Assert::contains('Exported', $response['body']);

    DB::delete('contacts', 'id = :id', ['id' => $id]);
});

Harness::test('an export of a table outside the allowlist is refused', function () use ($owner): void {
    foreach (['sessions', 'api_tokens', 'settings', 'users'] as $table) {
        $response = $owner->get('/settings/backups/export?table=' . $table, true);

        Assert::notContains(
            'password_hash',
            $response['body'],
            $table . ' must not be exportable — it holds credentials'
        );
        Assert::notContains('payload', $response['body']);
    }
});

Harness::test('an export is audited', function () use ($owner): void {
    DB::delete('audit_log', "action = 'data.exported'");

    $owner->get('/settings/backups/export?table=consent_log');

    Assert::true(
        DB::exists("SELECT id FROM {audit_log} WHERE action = 'data.exported'"),
        'exporting the guest list must be recorded'
    );
});

// ------------------------------------------------------------- force HTTPS

Harness::test('the system page offers the force-HTTPS switch', function () use ($owner): void {
    $response = $owner->get('/settings/system');

    Assert::same(200, $response['status']);
    Assert::contains('name="force_https"', $response['body']);
    Assert::contains('Require HTTPS', $response['body']);
    Assert::contains(
        'plain HTTP',
        $response['body'],
        'over http:// the page should warn before the operator turns this on'
    );
});

Harness::test('the audit retention control is on the system page', function () use ($owner): void {
    $response = $owner->get('/settings/system');

    Assert::contains('name="audit_retention_days"', $response['body']);
});

Harness::test('saving the system form stores both new settings', function () use ($owner): void {
    $response = $owner->postWithToken('/settings/system', '/settings/system', [
        'quiet_hours_start'    => '09:00',
        'quiet_hours_end'      => '20:00',
        'message_cap_per_week' => '4',
        'lapse_days'           => '120',
        'attribution_days'     => '30',
        'audit_retention_days' => '365',
        'force_https'          => '0',
    ], true);

    Assert::same(200, $response['status']);

    Settings::load(true);

    Assert::same(365, (int) Settings::get('system.audit_retention_days'));
    Assert::false((bool) Settings::get('system.force_https'));
});

Harness::test('force_https redirects a plain-HTTP GET and then can be turned off again', function () use ($owner, $anon): void {
    // The dangerous setting in the application: get this wrong and the
    // operator cannot reach the page that would let them fix it. So the test
    // proves both directions, and restores the setting even on failure.
    server_setting('system.force_https', true);

    try {
        $response = $anon->get('/login');

        Assert::same(301, $response['status'], 'a plain-HTTP GET should be redirected permanently');
        Assert::true(
            str_starts_with($response['location'], 'https://'),
            'it should point at https, not back at itself: ' . $response['location']
        );
        Assert::contains('/login', $response['location'], 'and keep the path');

        // A POST is refused rather than redirected: bouncing it would drop the
        // body and look to the user like the form silently failed.
        $post = $anon->post('/login', ['email' => 'a@b.test', 'password' => 'x']);

        Assert::same(400, $post['status'], 'a POST must not be 301d into losing its body');
        Assert::contains('not secure', strtolower($post['body']));
    } finally {
        server_setting('system.force_https', false);
    }

    // And the application is reachable again.
    Assert::same(200, $anon->get('/login')['status']);
});

Harness::test('the settings index links to the new pages', function () use ($owner): void {
    $response = $owner->get('/settings');

    Assert::same(200, $response['status']);
    Assert::contains('/settings/mail', $response['body']);
    Assert::contains('/settings/backups', $response['body']);
});

Harness::test('health and settings pages all still render for the owner', function () use ($owner): void {
    // A cheap regression net: a view that references a variable the controller
    // stopped passing is a 500, and a 500 on a settings page is the kind of
    // thing nobody notices until the operator needs that page.
    foreach ([
        '/settings', '/settings/venue', '/settings/system', '/settings/mail',
        '/settings/backups', '/settings/health', '/settings/audit',
        '/settings/users', '/settings/roles', '/playbooks', '/account',
    ] as $path) {
        $response = $owner->get($path);

        Assert::same(200, $response['status'], $path . ' did not render');
        Assert::notContains('Something went wrong', $response['body'], $path . ' rendered an error');
    }
});
