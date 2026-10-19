<?php

declare(strict_types=1);

/**
 * Settings → Hours: the weekly pattern, seasons, closures and outages — and
 * that the availability engine actually reads what the page writes.
 *
 * Every test puts the hours back exactly as it found them: the booking suites
 * compute availability from the seeded week.
 */

use App\Core\DB;
use App\Services\AvailabilityEngine;
use App\Services\BookingRepository;
use Tests\Assert;
use Tests\Harness;

Harness::suite('http/hours');

$baseUrl = getenv('CC_TEST_BASE_URL') ?: '';

if ($baseUrl === '') {
    Harness::skip('hours suite', 'no server; run via tests/run.php');

    return;
}

if (!test_boot()) {
    Harness::skip('hours suite', 'no database available');

    return;
}

require_once CC_ROOT . '/tests/TestBrowser.php';

$owner = new TestBrowser($baseUrl, 'hr-owner');
$owner->login('owner@test.invalid', 'owner-test-passphrase-9');

$desk = new TestBrowser($baseUrl, 'hr-desk');
$desk->login('desk@test.invalid', 'desk-test-passphrase-9');

/** Run $test, then put every hours table back exactly as it was. */
function hr_preserving(callable $test): void
{
    $tables = ['operating_hours', 'operating_exceptions', 'attraction_availability'];
    $saved  = [];

    foreach ($tables as $table) {
        $saved[$table] = DB::all('SELECT * FROM {' . $table . '}');
    }

    try {
        $test();
    } finally {
        foreach ($tables as $table) {
            DB::query('DELETE FROM {' . $table . '}');

            foreach ($saved[$table] as $row) {
                DB::insert($table, $row);
            }
        }
    }
}

/** The standard week as the form posts it. @return array<string,mixed> */
function hr_week(array $changes = []): array
{
    $days = [];

    foreach (DB::all('SELECT * FROM {operating_hours} WHERE effective_from IS NULL') as $row) {
        $days[(int) $row['day_of_week']] = [
            'opens'  => substr((string) $row['opens_at'], 0, 5),
            'closes' => substr((string) $row['closes_at'], 0, 5),
        ];
    }

    foreach ($changes as $dow => $day) {
        $days[$dow] = $day;
    }

    return ['season_label' => 'Standard', 'standard' => '1', 'days' => $days];
}

function hr_standard_id(): int
{
    return (int) DB::value('SELECT MIN(id) FROM {operating_hours} WHERE effective_from IS NULL');
}

/** The next date that falls on this weekday, in the venue's calendar. */
function hr_next(int $dow): string
{
    for ($ahead = 1; $ahead <= 7; $ahead++) {
        $day = test_local_day(-$ahead);

        if (AvailabilityEngine::dayOfWeek($day) === $dow) {
            return $day;
        }
    }

    return test_local_day(-7);
}

function hr_window(string $date): ?array
{
    return AvailabilityEngine::openWindow(
        $date,
        BookingRepository::hoursFor($date),
        BookingRepository::exceptionFor($date)
    );
}

Harness::test('the page shows the week, and the front desk cannot open it', function () use ($owner, $desk): void {
    $page = $owner->get('/settings/hours');

    Assert::same(200, $page['status']);
    Assert::contains('Standard week', $page['body']);
    Assert::contains('Saturday', $page['body']);
    Assert::same(403, $desk->get('/settings/hours')['status']);
});

Harness::test('a changed Monday is what availability then offers', function () use ($owner): void {
    hr_preserving(function () use ($owner): void {
        $path = '/settings/hours/seasons/' . hr_standard_id();
        Assert::same(302, $owner->postWithToken('/settings/hours', $path, hr_week([1 => ['opens' => '12:00', 'closes' => '19:30']]))['status'], 'the save redirects rather than failing');

        Assert::same(['opens' => 720, 'closes' => 1170], hr_window(hr_next(1)));
    });
});

Harness::test('a standard day ticked closed is closed', function () use ($owner): void {
    hr_preserving(function () use ($owner): void {
        $path = '/settings/hours/seasons/' . hr_standard_id();
        Assert::same(302, $owner->postWithToken('/settings/hours', $path, hr_week([2 => ['opens' => '15:00', 'closes' => '20:00', 'closed' => '1']]))['status'], 'the save redirects rather than failing');

        Assert::null(hr_window(hr_next(2)), 'Tuesday closed');
        Assert::true(hr_window(hr_next(3)) !== null, 'Wednesday untouched');
    });
});

Harness::test('closing time before opening time is refused, and nothing changes', function () use ($owner): void {
    hr_preserving(function () use ($owner): void {
        $before = DB::all('SELECT day_of_week, opens_at, closes_at FROM {operating_hours} ORDER BY day_of_week');
        $path = '/settings/hours/seasons/' . hr_standard_id();

        $response = $owner->postWithToken('/settings/hours', $path, hr_week([4 => ['opens' => '21:00', 'closes' => '15:00']]), true);

        Assert::contains('Thursday', $response['body']);
        Assert::same($before, DB::all('SELECT day_of_week, opens_at, closes_at FROM {operating_hours} ORDER BY day_of_week'));
    });
});

Harness::test('a season beats the standard week for its dates, and a blank day falls back', function () use ($owner): void {
    hr_preserving(function () use ($owner): void {
        $friday = hr_next(5);
        $monday = hr_next(1);
        $from   = min($friday, $monday);
        $to     = max($friday, $monday);

        Assert::same(302, $owner->postWithToken('/settings/hours', '/settings/hours/seasons', [
            'season_label'   => 'Test season',
            'effective_from' => $from,
            'effective_to'   => $to,
            'days'           => [5 => ['opens' => '09:00', 'closes' => '23:30']],
        ])['status'], 'the save redirects rather than failing');

        Assert::same(['opens' => 540, 'closes' => 1410], hr_window($friday), 'the season’s Friday');
        Assert::same(['opens' => 900, 'closes' => 1200], hr_window($monday), 'a blank Monday keeps the standard hours');
    });
});

Harness::test('the standard week cannot be deleted', function () use ($owner): void {
    hr_preserving(function () use ($owner): void {
        Assert::same(302, $owner->postWithToken('/settings/hours', '/settings/hours/seasons/' . hr_standard_id() . '/delete', [])['status'], 'the save redirects rather than failing');

        Assert::same(7, (int) DB::value('SELECT COUNT(*) FROM {operating_hours} WHERE effective_from IS NULL'));
    });
});

Harness::test('a closure beats every pattern, and removing it restores the day', function () use ($owner): void {
    hr_preserving(function () use ($owner): void {
        $day = hr_next(6);

        Assert::same(302, $owner->postWithToken('/settings/hours', '/settings/hours/exceptions', [
            'date' => $day, 'is_closed' => '1', 'note' => 'Test closure',
        ])['status'], 'the save redirects rather than failing');
        Assert::null(hr_window($day));

        Assert::same(302, $owner->postWithToken('/settings/hours', '/settings/hours/exceptions', [
            'date' => $day, 'opens_at' => '10:00', 'closes_at' => '14:00', 'note' => 'Early close',
        ])['status'], 'the save redirects rather than failing');
        Assert::same(['opens' => 600, 'closes' => 840], hr_window($day), 'saving the date again replaces it');
        Assert::same(1, (int) DB::value('SELECT COUNT(*) FROM {operating_exceptions} WHERE date = :d', ['d' => $day]));

        $id = (int) DB::value('SELECT id FROM {operating_exceptions} WHERE date = :d', ['d' => $day]);
        Assert::same(302, $owner->postWithToken('/settings/hours', '/settings/hours/exceptions/' . $id . '/delete', [])['status'], 'the save redirects rather than failing');
        Assert::same(['opens' => 660, 'closes' => 1380], hr_window($day), 'a Saturday again');
    });
});

Harness::test('an attraction marked down is down for that date only', function () use ($owner): void {
    hr_preserving(function () use ($owner): void {
        $attraction = (int) DB::value("SELECT id FROM {attractions} WHERE slug = 'laser-tag'");
        $day = hr_next(6);

        Assert::same(302, $owner->postWithToken('/settings/hours', '/settings/hours/outages', [
            'attraction_id' => (string) $attraction, 'date' => $day, 'note' => 'Inspection',
        ])['status'], 'the save redirects rather than failing');

        Assert::same(0, (int) (BookingRepository::attractionOverride($attraction, $day)['is_available'] ?? 1));
        Assert::null(BookingRepository::attractionOverride($attraction, hr_next(5)));
    });
});

Harness::test('THE PAGE RENDERS WITH A SEASON, A CLOSURE AND AN OUTAGE ON IT', function () use ($owner): void {
    // The case that crashed: the page and the closure save both format dates
    // with calendar_date(), and while that helper was missing from main every
    // save of a closure answered 500 after writing the row — so a test that
    // only checked the database passed.
    hr_preserving(function () use ($owner): void {
        $day = hr_next(6);
        $attraction = (int) DB::value("SELECT id FROM {attractions} WHERE slug = 'go-karts'");

        $saves = [
            ['/settings/hours/seasons', ['season_label' => 'Test summer', 'effective_from' => $day, 'effective_to' => $day,
                'days' => [6 => ['opens' => '09:00', 'closes' => '22:00']]]],
            ['/settings/hours/exceptions', ['date' => $day, 'is_closed' => '1', 'note' => 'Render test']],
            ['/settings/hours/outages', ['attraction_id' => (string) $attraction, 'date' => $day, 'note' => 'Render test']],
        ];

        foreach ($saves as [$path, $form]) {
            Assert::same(302, $owner->postWithToken('/settings/hours', $path, $form)['status'], $path . ' redirects');
        }

        $page = $owner->get('/settings/hours');
        Assert::same(200, $page['status']);
        Assert::contains('Test summer', $page['body']);
        Assert::contains('Render test', $page['body']);
        Assert::notContains('Fatal error', $page['body']);
    });
});

