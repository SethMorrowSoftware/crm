<?php

declare(strict_types=1);

/**
 * Settings → Party packages: the only place a party's price can be changed
 * without editing the database.
 */

use App\Core\DB;
use App\Core\Settings;
use App\Services\BookingRepository;
use Tests\Assert;
use Tests\Harness;

Harness::suite('http/packages');

$baseUrl = getenv('CC_TEST_BASE_URL') ?: '';

if ($baseUrl === '') {
    Harness::skip('packages suite', 'no server; run via tests/run.php');

    return;
}

if (!test_boot()) {
    Harness::skip('packages suite', 'no database available');

    return;
}

require_once CC_ROOT . '/tests/TestBrowser.php';

$owner = new TestBrowser($baseUrl, 'pk-owner');
$owner->login('owner@test.invalid', 'owner-test-passphrase-9');

$desk = new TestBrowser($baseUrl, 'pk-desk');
$desk->login('desk@test.invalid', 'desk-test-passphrase-9');

/** @return array<string,mixed> */
function pk_package(string $slug): array
{
    $row = DB::first('SELECT * FROM {party_packages} WHERE slug = :s', ['s' => $slug]);
    Assert::notNull($row, 'package missing: ' . $slug);

    return $row;
}

/** @return array<string,string> Every field the form posts, from a row. */
function pk_form(array $package, array $overrides = []): array
{
    $includes = is_string($package['includes'] ?? null) ? (json_decode((string) $package['includes'], true) ?: []) : [];

    return array_merge([
        'name'                   => (string) $package['name'],
        'main_attraction_id'     => (string) ($package['main_attraction_id'] ?? ''),
        'description'            => (string) ($package['description'] ?? ''),
        'includes'               => implode("\n", $includes),
        'base_price'             => number_format((int) $package['base_price_cents'] / 100, 2, '.', ''),
        'included_guests'        => (string) $package['included_guests'],
        'additional_guest_price' => number_format((int) $package['additional_guest_cents'] / 100, 2, '.', ''),
        'deposit'                => number_format((int) $package['deposit_cents'] / 100, 2, '.', ''),
        'duration_minutes'       => (string) $package['duration_minutes'],
        'min_notice_days'        => (string) $package['min_notice_days'],
        'sort_order'             => (string) $package['sort_order'],
        'is_active'              => (string) $package['is_active'],
        'is_online'              => (string) $package['is_online'],
    ], (int) $package['is_seasonal'] === 1 ? ['is_seasonal' => '1'] : [], $overrides);
}

Harness::test('the list shows every package with its real price', function () use ($owner): void {
    $response = $owner->get('/settings/packages');

    Assert::same(200, $response['status']);
    Assert::contains('InflataPark Birthday', $response['body']);
    Assert::contains('$349.99', $response['body'], 'Laser Tag at the CenterEdge price');
    Assert::contains('Desk only', $response['body'], 'the phone-only parties say so');
});

Harness::test('the front desk cannot open it, let alone change a price', function () use ($desk): void {
    Assert::same(403, $desk->get('/settings/packages')['status']);

    $before = pk_package('bowling-birthday');
    $response = $desk->postWithToken('/bookings', '/settings/packages/' . (int) $before['id'], pk_form($before, ['base_price' => '1.00']));

    Assert::same(403, $response['status']);
    Assert::same((int) $before['base_price_cents'], (int) pk_package('bowling-birthday')['base_price_cents']);
});

Harness::test('a price is saved to the cent, and audited', function () use ($owner): void {
    $package = pk_package('bowling-birthday');
    $path = '/settings/packages/' . (int) $package['id'];

    // 409.99 * 100 is 40998.999… in floating point; a cast would store 40998.
    $owner->postWithToken($path, $path, pk_form($package, ['base_price' => '409.99']));

    Assert::same(40999, (int) pk_package('bowling-birthday')['base_price_cents']);
    Assert::same(1, (int) DB::value(
        "SELECT COUNT(*) FROM {audit_log} WHERE action = 'party_package_updated' AND entity_id = :id",
        ['id' => (int) $package['id']]
    ));

    $owner->postWithToken($path, $path, pk_form(pk_package('bowling-birthday'), ['base_price' => '399.00']));
    Assert::same(39900, (int) pk_package('bowling-birthday')['base_price_cents']);
});

Harness::test('renaming a package keeps its slug', function () use ($owner): void {
    // The upgrade files and the CenterEdge import find a package by slug.
    $package = pk_package('glow-room-party');
    $path = '/settings/packages/' . (int) $package['id'];

    $owner->postWithToken($path, $path, pk_form($package, ['name' => 'Glow Party']));

    $after = pk_package('glow-room-party');
    Assert::same('Glow Party', (string) $after['name']);

    $owner->postWithToken($path, $path, pk_form($after, ['name' => 'Glow Room Party']));
});

Harness::test('taking a package offline removes it from the booking page, not the desk', function () use ($owner): void {
    $package = pk_package('ballocity-birthday');
    $path = '/settings/packages/' . (int) $package['id'];
    $form = pk_form($package);
    unset($form['is_online']);

    $owner->postWithToken($path, $path, $form);

    $after = pk_package('ballocity-birthday');
    Assert::same(0, (int) $after['is_online']);
    Assert::same(1, (int) $after['is_active'], 'still active for staff');

    // The booking page is off until a venue turns it on.
    $stored = Settings::get(BookingRepository::PUBLIC_SETTING);
    Settings::set(BookingRepository::PUBLIC_SETTING, '1');

    try {
        $public = (new TestBrowser(getenv('CC_TEST_BASE_URL') ?: '', 'pk-visitor'))->get('/book');
        Assert::same(200, $public['status']);
        Assert::notContains('Ballocity Birthday', $public['body'], 'not offered online');
        Assert::notContains('All Access Party', $public['body'], 'nor is a desk-only party');
        Assert::contains('InflataPark Birthday', $public['body'], 'while an online one is');
    } finally {
        if ($stored === null) {
            Settings::forget(BookingRepository::PUBLIC_SETTING);
        } else {
            Settings::set(BookingRepository::PUBLIC_SETTING, $stored);
        }
    }

    $owner->postWithToken($path, $path, pk_form($after, ['is_online' => '1']));
    Assert::same(1, (int) pk_package('ballocity-birthday')['is_online']);
});

Harness::test('a new package gets a slug of its own and lands last', function () use ($owner): void {
    $max = (int) DB::value('SELECT MAX(sort_order) FROM {party_packages}');

    $owner->postWithToken('/settings/packages/new', '/settings/packages', [
        'name'                   => 'Test Escape Room Party',
        'base_price'             => '299',
        'included_guests'        => '6',
        'additional_guest_price' => '25',
        'deposit'                => '50',
        'duration_minutes'       => '90',
        'min_notice_days'        => '7',
        'includes'               => "60 minutes in the room\n\nA souvenir photo",
        'is_active'              => '1',
    ]);

    $row = pk_package('test-escape-room-party');
    Assert::same(29900, (int) $row['base_price_cents']);
    Assert::same(0, (int) $row['is_online'], 'an unticked box is off');
    Assert::greaterThan($max, (int) $row['sort_order']);
    Assert::same(['60 minutes in the room', 'A souvenir photo'], json_decode((string) $row['includes'], true), 'blank lines dropped');
});

Harness::test('a package nobody booked can be deleted', function () use ($owner): void {
    $row = pk_package('test-escape-room-party');
    $path = '/settings/packages/' . (int) $row['id'];

    $owner->postWithToken($path, $path . '/delete', []);

    Assert::same(0, (int) DB::value("SELECT COUNT(*) FROM {party_packages} WHERE slug = 'test-escape-room-party'"));
});

Harness::test('A PACKAGE WITH BOOKINGS IS NOT DELETED, WHICH WOULD BLANK THEM', function () use ($owner): void {
    $package = pk_package('laser-tag-birthday');
    $contact = test_make_contact();

    $bookingId = DB::insert('bookings', [
        'booking_number' => 'PKTEST-' . bin2hex(random_bytes(3)),
        'contact_id'     => $contact,
        'package_id'     => (int) $package['id'],
        'event_date'     => test_local_day(-30),
        'start_time'     => '12:00:00',
        'end_time'       => '14:00:00',
        'status'         => 'confirmed',
        'created_at'     => gmdate('Y-m-d H:i:s'),
        'updated_at'     => gmdate('Y-m-d H:i:s'),
    ]);

    try {
        $path = '/settings/packages/' . (int) $package['id'];
        $response = $owner->postWithToken($path, $path . '/delete', [], true);

        Assert::same(1, (int) DB::value('SELECT COUNT(*) FROM {party_packages} WHERE id = :id', ['id' => (int) $package['id']]));
        Assert::contains('take it off sale', $response['body']);
        Assert::same((int) $package['id'], (int) DB::value('SELECT package_id FROM {bookings} WHERE id = :id', ['id' => $bookingId]));
    } finally {
        DB::delete('bookings', 'id = :id', ['id' => $bookingId]);
    }
});

Harness::test('an add-on is created, priced per guest, and deleted', function () use ($owner): void {
    $owner->postWithToken('/settings/packages', '/settings/packages/addons', [
        'name'      => 'Test Glow Bracelets',
        'price'     => '2.50',
        'unit'      => 'per_guest',
        'is_active' => '1',
    ]);

    $addon = DB::first("SELECT * FROM {package_addons} WHERE name = 'Test Glow Bracelets'");
    Assert::notNull($addon);
    Assert::same(250, (int) $addon['price_cents']);
    Assert::same('per_guest', (string) $addon['unit']);

    $owner->postWithToken('/settings/packages', '/settings/packages/addons/' . (int) $addon['id'] . '/delete', []);
    Assert::same(0, (int) DB::value("SELECT COUNT(*) FROM {package_addons} WHERE name = 'Test Glow Bracelets'"));
});

Harness::test('a price that is not a number is refused with the field named', function () use ($owner): void {
    $package = pk_package('roller-skating-birthday');
    $path = '/settings/packages/' . (int) $package['id'];

    $response = $owner->postWithToken($path, $path, pk_form($package, ['base_price' => 'three hundred']), true);

    Assert::contains('Price', $response['body']);
    Assert::same((int) $package['base_price_cents'], (int) pk_package('roller-skating-birthday')['base_price_cents']);
});

Harness::test('an add-on has an edit page of its own, closed to the front desk', function () use ($owner, $desk): void {
    $addon = DB::first("SELECT * FROM {package_addons} WHERE name = 'Goody Bags'");
    Assert::notNull($addon, 'the seeded add-on');

    $page = $owner->get('/settings/packages/addons/' . (int) $addon['id']);
    Assert::same(200, $page['status']);
    Assert::contains('Goody Bags', $page['body']);
    Assert::contains('name="price"', $page['body']);

    Assert::same(403, $desk->get('/settings/packages/addons/' . (int) $addon['id'])['status']);
    Assert::same(404, $owner->get('/settings/packages/addons/999999999')['status']);
});
