<?php

declare(strict_types=1);

/**
 * Settings → Offers: creating and editing the codes automations hand out,
 * without breaking one an automation is about to send.
 */

use App\Core\DB;
use Tests\Assert;
use Tests\Harness;

Harness::suite('http/offers');

$baseUrl = getenv('CC_TEST_BASE_URL') ?: '';

if ($baseUrl === '') {
    Harness::skip('offers suite', 'no server; run via tests/run.php');

    return;
}

if (!test_boot()) {
    Harness::skip('offers suite', 'no database available');

    return;
}

require_once CC_ROOT . '/tests/TestBrowser.php';

$owner = new TestBrowser($baseUrl, 'of-owner');
$owner->login('owner@test.invalid', 'owner-test-passphrase-9');

$desk = new TestBrowser($baseUrl, 'of-desk');
$desk->login('desk@test.invalid', 'desk-test-passphrase-9');

function of_clear(): void
{
    DB::query("DELETE FROM {coupons} WHERE code LIKE 'OFTEST%'");
}

/** @return array<string,string> */
function of_form(array $overrides = []): array
{
    return array_merge([
        'name'            => 'Test offer',
        'type'            => 'percent',
        'value'           => '20',
        'max_per_contact' => '1',
        'is_active'       => '1',
    ], $overrides);
}

Harness::test('the list shows the seeded offers, and the front desk cannot open it', function () use ($owner, $desk): void {
    $page = $owner->get('/settings/offers');

    Assert::same(200, $page['status']);
    Assert::contains('WELCOME10', $page['body']);
    Assert::contains('10% off', $page['body']);
    Assert::same(403, $desk->get('/settings/offers')['status']);
});

Harness::test('a new offer is saved in capitals, good until the end of its last day here', function () use ($owner): void {
    of_clear();

    $owner->postWithToken('/settings/offers', '/settings/offers', of_form([
        'code' => 'oftest-fall', 'expires_on' => '2026-12-31', 'min_spend' => '50',
    ]));

    $row = DB::first("SELECT * FROM {coupons} WHERE code = 'OFTEST-FALL'");
    Assert::notNull($row, 'created, in capitals');
    Assert::same(5000, (int) $row['min_spend_cents']);

    // The end of 31 December in the venue's zone, as UTC. Stored as a bare
    // "2026-12-31 00:00:00" it would have been read as UTC, which is 7pm on
    // the 30th in New York — the offer would die a day early.
    $endOfDay = (new DateTimeImmutable('2026-12-31 23:59:59', venue_tz()))
        ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    Assert::same($endOfDay, (string) $row['expires_at']);

    of_clear();
});

Harness::test('a code is never reused, and a bad one is refused', function () use ($owner): void {
    of_clear();

    $owner->postWithToken('/settings/offers', '/settings/offers', of_form(['code' => 'OFTEST1']));
    $owner->postWithToken('/settings/offers', '/settings/offers', of_form(['code' => 'OFTEST1', 'name' => 'Second']));
    $owner->postWithToken('/settings/offers', '/settings/offers', of_form(['code' => 'OF TEST!']));

    Assert::same(1, (int) DB::value("SELECT COUNT(*) FROM {coupons} WHERE code = 'OFTEST1'"));
    Assert::same('Test offer', (string) DB::value("SELECT name FROM {coupons} WHERE code = 'OFTEST1'"), 'the first kept');
    Assert::same(0, (int) DB::value("SELECT COUNT(*) FROM {coupons} WHERE code LIKE 'OF TEST%'"));

    of_clear();
});

Harness::test('a percentage over 100 is refused', function () use ($owner): void {
    of_clear();

    $owner->postWithToken('/settings/offers', '/settings/offers', of_form(['code' => 'OFTEST-BIG', 'value' => '150']));

    Assert::same(0, (int) DB::value("SELECT COUNT(*) FROM {coupons} WHERE code = 'OFTEST-BIG'"));
});

Harness::test('editing an offer cannot change its code', function () use ($owner): void {
    of_clear();

    $owner->postWithToken('/settings/offers', '/settings/offers', of_form(['code' => 'OFTEST-KEEP']));
    $id = (int) DB::value("SELECT id FROM {coupons} WHERE code = 'OFTEST-KEEP'");

    $owner->postWithToken('/settings/offers/' . $id, '/settings/offers/' . $id, of_form([
        'code' => 'OFTEST-CHANGED', 'value' => '25',
    ]));

    $row = DB::first('SELECT code, value FROM {coupons} WHERE id = :id', ['id' => $id]);
    Assert::same('OFTEST-KEEP', (string) $row['code']);
    Assert::same(25.0, (float) $row['value']);

    of_clear();
});

Harness::test('AN OFFER A SWITCHED-ON AUTOMATION HANDS OUT CANNOT BE SWITCHED OFF', function () use ($owner): void {
    $step = DB::first(
        "SELECT s.automation_id, UPPER(JSON_UNQUOTE(JSON_EXTRACT(s.config, '$.code'))) AS code
           FROM {automation_steps} s WHERE s.type = 'issue_coupon' LIMIT 1"
    );
    Assert::notNull($step, 'a seeded automation that issues an offer');

    $automationId = (int) $step['automation_id'];
    $wasActive    = (int) DB::value('SELECT is_active FROM {automations} WHERE id = :id', ['id' => $automationId]);
    DB::update('automations', ['is_active' => 1], 'id = :id', ['id' => $automationId]);

    try {
        $coupon = DB::first('SELECT * FROM {coupons} WHERE code = :c', ['c' => (string) $step['code']]);
        Assert::notNull($coupon);
        $path = '/settings/offers/' . (int) $coupon['id'];

        $form = of_form(['name' => (string) $coupon['name'], 'type' => (string) $coupon['type'], 'value' => (string) $coupon['value']]);
        unset($form['is_active']);

        $response = $owner->postWithToken($path, $path, $form, true);

        Assert::same(1, (int) DB::value('SELECT is_active FROM {coupons} WHERE id = :id', ['id' => (int) $coupon['id']]));
        Assert::contains('Switch', $response['body']);
    } finally {
        DB::update('automations', ['is_active' => $wasActive], 'id = :id', ['id' => $automationId]);
    }
});
