<?php

declare(strict_types=1);

/**
 * Settings → Attractions: seasons, rules and capacity, which the booking
 * page reads.
 */

use App\Core\DB;
use App\Services\AvailabilityEngine;
use Tests\Assert;
use Tests\Harness;

Harness::suite('http/attractions');

$baseUrl = getenv('CC_TEST_BASE_URL') ?: '';

if ($baseUrl === '') {
    Harness::skip('attractions suite', 'no server; run via tests/run.php');

    return;
}

if (!test_boot()) {
    Harness::skip('attractions suite', 'no database available');

    return;
}

require_once CC_ROOT . '/tests/TestBrowser.php';

$owner = new TestBrowser($baseUrl, 'at-owner');
$owner->login('owner@test.invalid', 'owner-test-passphrase-9');

$desk = new TestBrowser($baseUrl, 'at-desk');
$desk->login('desk@test.invalid', 'desk-test-passphrase-9');

/** The form as it posts for an existing attraction, with changes. @return array<string,string> */
function at_form(array $a, array $changes = []): array
{
    $form = [
        'name' => (string) $a['name'], 'category' => (string) ($a['category'] ?? ''),
        'description' => (string) ($a['description'] ?? ''),
        'min_age' => (string) ($a['min_age'] ?? ''), 'min_height_in' => (string) ($a['min_height_in'] ?? ''),
        'max_height_in' => (string) ($a['max_height_in'] ?? ''),
        'capacity_per_slot' => (string) ($a['capacity_per_slot'] ?? ''), 'slot_minutes' => (string) ($a['slot_minutes'] ?? ''),
        'sort_order' => (string) $a['sort_order'],
    ];

    foreach (['is_indoor', 'is_seasonal', 'is_party_eligible', 'is_party_main', 'requires_waiver', 'is_active'] as $flag) {
        if ((int) $a[$flag] === 1) {
            $form[$flag] = '1';
        }
    }

    if ($a['season_start_md'] !== null) {
        [$form['season_start_month'], $form['season_start_day']] = explode('-', (string) $a['season_start_md']);
        [$form['season_end_month'], $form['season_end_day']] = explode('-', (string) $a['season_end_md']);
    }

    return array_merge($form, $changes);
}

/** Run, then put the attraction back exactly. */
function at_preserving(string $slug, callable $test): void
{
    $row = DB::first('SELECT * FROM {attractions} WHERE slug = :s', ['s' => $slug]);
    Assert::notNull($row, 'attraction missing: ' . $slug);

    try {
        $test($row);
    } finally {
        $id = (int) $row['id'];
        unset($row['id']);
        DB::update('attractions', $row, 'id = :id', ['id' => $id]);
    }
}

Harness::test('the list shows every attraction with its season, and the desk cannot open it', function () use ($owner, $desk): void {
    $page = $owner->get('/settings/attractions');

    Assert::same(200, $page['status']);
    Assert::contains('Go Karts', $page['body']);
    Assert::contains('Apr 1 – Oct 31', $page['body']);
    Assert::same(403, $desk->get('/settings/attractions')['status']);
});

Harness::test('opening the go-karts a fortnight early is what the season check then says', function () use ($owner): void {
    at_preserving('go-karts', function (array $karts) use ($owner): void {
        $path = '/settings/attractions/' . (int) $karts['id'];
        Assert::same(302, $owner->postWithToken($path, $path, at_form($karts, ['season_start_month' => '3', 'season_start_day' => '15']))['status'], 'the save redirects rather than failing');

        $after = DB::first('SELECT * FROM {attractions} WHERE id = :id', ['id' => (int) $karts['id']]);
        Assert::same('03-15', (string) $after['season_start_md']);
        Assert::true(AvailabilityEngine::inSeason('2027-03-20', $after), 'open on March 20');
        Assert::false(AvailabilityEngine::inSeason('2027-03-10', $after), 'not yet on March 10');
    });
});

Harness::test('a season may wrap the new year', function () use ($owner): void {
    at_preserving('swing-ride', function (array $ride) use ($owner): void {
        $path = '/settings/attractions/' . (int) $ride['id'];
        Assert::same(302, $owner->postWithToken($path, $path, at_form($ride, [
            'is_seasonal' => '1',
            'season_start_month' => '11', 'season_start_day' => '15',
            'season_end_month' => '3', 'season_end_day' => '15',
        ]))['status'], 'the save redirects rather than failing');

        $after = DB::first('SELECT * FROM {attractions} WHERE id = :id', ['id' => (int) $ride['id']]);
        Assert::same(['11-15', '03-15'], [(string) $after['season_start_md'], (string) $after['season_end_md']]);
        Assert::true(AvailabilityEngine::inSeason('2026-12-25', $after), 'open at Christmas');
        Assert::false(AvailabilityEngine::inSeason('2026-07-04', $after), 'closed in July');
    });
});

Harness::test('a maximum height below the minimum is refused', function () use ($owner): void {
    at_preserving('free-fall', function (array $ride) use ($owner): void {
        $path = '/settings/attractions/' . (int) $ride['id'];
        Assert::same(302, $owner->postWithToken($path, $path, at_form($ride, ['min_height_in' => '48', 'max_height_in' => '40']))['status'], 'the save redirects rather than failing');

        Assert::null(DB::value('SELECT max_height_in FROM {attractions} WHERE id = :id', ['id' => (int) $ride['id']]));
    });
});

Harness::test('a new attraction gets a slug and lands last', function () use ($owner): void {
    Assert::same(302, $owner->postWithToken('/settings/attractions/new', '/settings/attractions', [
        'name' => 'Test Glow Room', 'is_indoor' => '1', 'is_party_main' => '1', 'is_active' => '1', 'capacity_per_slot' => '15',
    ])['status'], 'the save redirects rather than failing');

    $row = DB::first("SELECT * FROM {attractions} WHERE slug = 'test-glow-room'");
    Assert::notNull($row);
    Assert::same(1, (int) $row['is_party_eligible'], 'a party main is always party-eligible');
    Assert::same(15, (int) $row['capacity_per_slot']);

    DB::delete('attractions', 'id = :id', ['id' => (int) $row['id']]);
});
