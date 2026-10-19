<?php

declare(strict_types=1);

/**
 * Settings → Message templates: the words guests receive, and the three rules
 * that keep editing them from breaking a send.
 */

use App\Core\DB;
use Tests\Assert;
use Tests\Harness;

Harness::suite('http/templates');

$baseUrl = getenv('CC_TEST_BASE_URL') ?: '';

if ($baseUrl === '') {
    Harness::skip('templates suite', 'no server; run via tests/run.php');

    return;
}

if (!test_boot()) {
    Harness::skip('templates suite', 'no database available');

    return;
}

require_once CC_ROOT . '/tests/TestBrowser.php';

$owner = new TestBrowser($baseUrl, 'tpl-owner');
$owner->login('owner@test.invalid', 'owner-test-passphrase-9');

$desk = new TestBrowser($baseUrl, 'tpl-desk');
$desk->login('desk@test.invalid', 'desk-test-passphrase-9');

/** @return array<string,mixed> */
function tpl(string $slug): array
{
    $row = DB::first('SELECT * FROM {templates} WHERE slug = :s', ['s' => $slug]);
    Assert::notNull($row, 'template missing: ' . $slug);

    return $row;
}

/** @return array<string,string> */
function tpl_form(array $template, array $overrides = []): array
{
    return array_merge([
        'name'      => (string) $template['name'],
        'subject'   => (string) ($template['subject'] ?? ''),
        'preheader' => (string) ($template['preheader'] ?? ''),
        'body'      => (string) $template['body'],
    ], (int) $template['is_active'] === 1 ? ['is_active' => '1'] : [], $overrides);
}

Harness::test('the list shows every email and text template', function () use ($owner): void {
    $page = $owner->get('/settings/templates');

    Assert::same(200, $page['status']);
    Assert::contains('Booking confirmed', $page['body']);
    Assert::contains('Text messages', $page['body']);
});

Harness::test('the front desk cannot open or change them', function () use ($desk): void {
    Assert::same(403, $desk->get('/settings/templates')['status']);

    $template = tpl('booking-confirmation');
    $response = $desk->postWithToken('/bookings', '/settings/templates/' . (int) $template['id'], tpl_form($template, ['subject' => 'Hijacked']));

    Assert::same(403, $response['status']);
    Assert::same((string) $template['subject'], (string) tpl('booking-confirmation')['subject']);
});

Harness::test('the preview fills a sample family in, and can run nothing', function () use ($owner): void {
    $template = tpl('booking-confirmation');
    $preview  = $owner->get('/settings/templates/' . (int) $template['id'] . '/preview');

    Assert::same(200, $preview['status']);
    Assert::contains('Maria', $preview['body'], 'first_name filled in');
    Assert::notContains('{{first_name}}', $preview['body']);
    Assert::true(
        preg_match('/Content-Security-Policy:\s*sandbox/i', $preview['headers'] ?? '') === 1,
        'served sandboxed: a template is HTML, and HTML can carry script'
    );
});

Harness::test('a draft can be previewed before it is saved', function () use ($owner): void {
    $template = tpl('booking-confirmation');
    $path = '/settings/templates/' . (int) $template['id'];

    $preview = $owner->postWithToken($path, $path . '/preview', tpl_form($template, [
        'body' => '<p>Draft for {{child_name}}</p>',
    ]));

    Assert::contains('Draft for Emma', $preview['body']);
    Assert::same((string) $template['body'], (string) tpl('booking-confirmation')['body'], 'and nothing was saved');
});

Harness::test('a saved change is audited, and a mistyped tag is named', function () use ($owner): void {
    $template = tpl('booking-confirmation');
    $path = '/settings/templates/' . (int) $template['id'];

    $response = $owner->postWithToken($path, $path, tpl_form($template, [
        'subject' => 'Confirmed for {{frist_name}}',
    ]), true);

    Assert::same('Confirmed for {{frist_name}}', (string) tpl('booking-confirmation')['subject']);
    Assert::contains('frist_name', $response['body'], 'the warning names the tag that will come out blank');
    Assert::same(1, (int) DB::value(
        "SELECT COUNT(*) FROM {audit_log} WHERE action = 'template_updated' AND entity_id = :id",
        ['id' => (int) $template['id']]
    ));

    DB::update('templates', ['subject' => $template['subject']], 'id = :id', ['id' => (int) $template['id']]);
});

Harness::test('the password reset cannot be switched off', function () use ($owner): void {
    $template = tpl('password-reset');
    $path = '/settings/templates/' . (int) $template['id'];
    $form = tpl_form($template);
    unset($form['is_active']);

    $owner->postWithToken($path, $path, $form);

    Assert::same(1, (int) tpl('password-reset')['is_active']);
});

Harness::test('A TEMPLATE A SWITCHED-ON AUTOMATION SENDS CANNOT BE SWITCHED OFF', function () use ($owner): void {
    $step = DB::first(
        "SELECT s.automation_id, JSON_UNQUOTE(JSON_EXTRACT(s.config, '$.template')) AS slug
           FROM {automation_steps} s
          WHERE JSON_EXTRACT(s.config, '$.template') IS NOT NULL
          LIMIT 1"
    );
    Assert::notNull($step, 'a seeded automation that sends a template');

    $automationId = (int) $step['automation_id'];
    $wasActive    = (int) DB::value('SELECT is_active FROM {automations} WHERE id = :id', ['id' => $automationId]);
    DB::update('automations', ['is_active' => 1], 'id = :id', ['id' => $automationId]);

    try {
        $template = tpl((string) $step['slug']);
        $path = '/settings/templates/' . (int) $template['id'];
        $form = tpl_form($template);
        unset($form['is_active']);

        $response = $owner->postWithToken($path, $path, $form, true);

        Assert::same(1, (int) tpl((string) $step['slug'])['is_active'], 'still on');
        Assert::contains('Switch', $response['body'], 'and the page says what to do instead');
    } finally {
        DB::update('automations', ['is_active' => $wasActive], 'id = :id', ['id' => $automationId]);
    }
});

Harness::test('a copy starts a new template with a slug of its own', function () use ($owner): void {
    $source = tpl('party-thank-you');
    $path = '/settings/templates/' . (int) $source['id'];
    $before = (int) DB::value('SELECT MAX(id) FROM {templates}');

    $owner->postWithToken($path, $path . '/duplicate', []);

    $copy = DB::first('SELECT * FROM {templates} WHERE id > :id ORDER BY id DESC LIMIT 1', ['id' => $before]);
    Assert::notNull($copy);
    Assert::same('Copy of ' . (string) $source['name'], (string) $copy['name']);
    Assert::notSame((string) $source['slug'], (string) $copy['slug']);
    Assert::same((string) $source['body'], (string) $copy['body']);
    Assert::same(0, (int) $copy['is_system']);

    DB::delete('templates', 'id = :id', ['id' => (int) $copy['id']]);
});

Harness::test('a test email goes to the person editing, through the real send path', function () use ($owner): void {
    $template = tpl('booking-confirmation');
    $path = '/settings/templates/' . (int) $template['id'];

    $response = $owner->postWithToken($path, $path . '/test', [], true);

    Assert::contains('Test sent to owner@test.invalid', $response['body']);
});
