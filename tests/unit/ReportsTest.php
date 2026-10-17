<?php

declare(strict_types=1);

/**
 * The report catalogue and the window arithmetic, with no database and no
 * clock — "today" is passed in, which is the whole reason these are testable.
 *
 * What is pinned here, and why each one matters:
 *
 *   - **The prior window.** Every comparison on every report rests on it. If
 *     it is off by a day the two sides of "+12% on last month" overlap, and
 *     nobody reading the page can tell.
 *   - **Null, not zero.** `delta()` returns null with no prior period and a
 *     null PERCENTAGE when the base is zero. A venue's first month must not
 *     read "+100% on nothing", and a rise from zero has no percentage —
 *     printing one is where "+∞%" comes from.
 *   - **Money scrubbing keeps its columns.** A role without `view_revenue`
 *     sees the money column EMPTY rather than removed, so the row still lines
 *     up with the one a manager is looking at while they talk about it.
 *   - **CSV money is a number.** A column of `123400` is not money and
 *     `"$1,234.00"` is not a number a spreadsheet can total.
 */

use App\Services\LocalDay;
use App\Services\Reports;
use Tests\Assert;
use Tests\Harness;

Harness::suite('unit/reports');

// ------------------------------------------------------------- the catalogue

Harness::test('every report belongs to one of the six families', function (): void {
    Assert::same(
        ['revenue', 'sales', 'marketing', 'guest', 'operations', 'reputation'],
        array_keys(Reports::FAMILIES)
    );

    foreach (Reports::CATALOG as $id => $report) {
        Assert::true(
            isset(Reports::FAMILIES[$report['family']]),
            $id . ' is in family "' . $report['family'] . '", which is not one of the six'
        );

        // The question is what the picker shows, so an empty one leaves a
        // report listed by a name nobody outside this office understands.
        Assert::true($report['question'] !== '', $id . ' has no question');
        Assert::true($report['name'] !== '', $id . ' has no name');
        Assert::true(str_ends_with($report['question'], '?'), $id . '\'s question is not a question');
    }
});

Harness::test('every catalogued report has a query behind it', function (): void {
    // The catalogue and the runner are two files, so a report can be listed
    // with nothing to run. That is a 500 from the picker, which is how a
    // venue finds out.
    foreach (array_keys(Reports::CATALOG) as $id) {
        $method = lcfirst(str_replace('_', '', ucwords($id, '_')));

        Assert::true(
            method_exists(\App\Services\ReportRunner::class, $method),
            $id . ' is catalogued but ReportRunner::' . $method . '() does not exist'
        );
    }
});

Harness::test('report ids are URL-safe, because they are route parameters', function (): void {
    foreach (array_keys(Reports::CATALOG) as $id) {
        Assert::same(
            1,
            preg_match('/^[a-z0-9_]+$/', $id),
            $id . ' is not matched by the route pattern [a-z0-9_]+'
        );
    }
});

Harness::test('byFamily lists every report under its family, in family order', function (): void {
    $grouped = Reports::byFamily();

    Assert::same(array_keys(Reports::FAMILIES), array_keys($grouped));

    $counted = 0;

    foreach ($grouped as $family => $reports) {
        foreach ($reports as $report) {
            Assert::same($family, $report['family']);
            Assert::true(isset($report['id']), 'the id is added so the picker can link to it');
            $counted++;
        }
    }

    Assert::same(count(Reports::CATALOG), $counted, 'no report is dropped or listed twice');
});

// ----------------------------------------------------------------- the window

Harness::test('each preset is the span its name promises', function (): void {
    $today = '2026-10-16';

    // Last 7 days INCLUDES today, so it is the 10th to the 16th — seven days,
    // not eight. Off by one here is off by one on every figure.
    $week = Reports::window('last_7', null, null, $today);
    Assert::same('2026-10-10', $week['from']);
    Assert::same('2026-10-16', $week['to']);
    Assert::same(7, $week['days']);

    $month = Reports::window('last_30', null, null, $today);
    Assert::same('2026-09-17', $month['from']);
    Assert::same('2026-10-16', $month['to']);
    Assert::same(30, $month['days']);

    $thisMonth = Reports::window('this_month', null, null, $today);
    Assert::same('2026-10-01', $thisMonth['from']);
    Assert::same('2026-10-16', $thisMonth['to'], 'this month stops today, not at the end of October');

    // Last month is the WHOLE of it, both ends — the one preset where "to" is
    // not today.
    $lastMonth = Reports::window('last_month', null, null, $today);
    Assert::same('2026-09-01', $lastMonth['from']);
    Assert::same('2026-09-30', $lastMonth['to']);
    Assert::same(30, $lastMonth['days']);

    $thisYear = Reports::window('this_year', null, null, $today);
    Assert::same('2026-01-01', $thisYear['from']);
    Assert::same('2026-10-16', $thisYear['to']);

    $lastYear = Reports::window('last_year', null, null, $today);
    Assert::same('2025-01-01', $lastYear['from']);
    Assert::same('2025-12-31', $lastYear['to']);
    Assert::same(365, $lastYear['days']);
});

Harness::test('the end of a month is not the end of the one before it', function (): void {
    // 31 March: "first day of last month" from the 31st is a date that does
    // not exist in February, and PHP's relative formats are the one place
    // this reliably goes wrong.
    $window = Reports::window('last_month', null, null, '2026-03-31');

    Assert::same('2026-02-01', $window['from']);
    Assert::same('2026-02-28', $window['to']);
    Assert::same(28, $window['days']);
});

Harness::test('the prior window is the same length, immediately before', function (): void {
    $window = Reports::window('last_30', null, null, '2026-10-16');

    // The day before "from", counting back the same number of days. Any
    // overlap and both sides of the comparison contain the same business.
    Assert::same('2026-09-16', $window['prior_to']);
    Assert::same('2026-08-18', $window['prior_from']);

    $days = (int) (new DateTimeImmutable($window['prior_from']))
        ->diff(new DateTimeImmutable($window['prior_to']))->days + 1;

    Assert::same(30, $days, 'the prior window must be the same length as the window');
});

Harness::test('a custom window takes the dates, and refuses rubbish', function (): void {
    $window = Reports::window('custom', '2026-01-01', '2026-01-31', '2026-10-16');
    Assert::same('2026-01-01', $window['from']);
    Assert::same('2026-01-31', $window['to']);
    Assert::same(31, $window['days']);

    // A custom window labels itself with its dates; a preset labels itself
    // with its name.
    Assert::same('Jan 1, 2026 – Jan 31, 2026', $window['label']);
    Assert::same('Last 30 days', Reports::window('last_30', null, null, '2026-10-16')['label']);

    // Garbage in either box falls back rather than throwing: a typed date is
    // the most likely thing on this page to be wrong, and a 500 is a worse
    // answer than the default window.
    $junk = Reports::window('custom', 'tomorrow-ish', null, '2026-10-16');
    Assert::same('2026-09-17', $junk['from']);
    Assert::same('2026-10-16', $junk['to']);

    // Backwards dates are swapped, not refused.
    $back = Reports::window('custom', '2026-05-31', '2026-05-01', '2026-10-16');
    Assert::same('2026-05-01', $back['from']);
    Assert::same('2026-05-31', $back['to']);
});

Harness::test('an unknown preset becomes the default rather than an empty window', function (): void {
    $window = Reports::window('since_the_dawn_of_time', null, null, '2026-10-16');

    Assert::same('last_30', $window['preset']);
    Assert::same(30, $window['days']);
});

Harness::test('an absurd span is clamped, and says so', function (): void {
    $window = Reports::window('custom', '1999-01-01', '2026-10-16', '2026-10-16');

    Assert::same(Reports::MAX_DAYS, $window['days']);
    Assert::true($window['clamped'], 'clamping silently would under-report by two decades');
    Assert::same('2026-10-16', $window['to'], 'the recent end is kept — that is the end people want');

    // And an ordinary window is not marked clamped, or the warning cries wolf.
    Assert::false(Reports::window('last_90', null, null, '2026-10-16')['clamped']);
});

// ------------------------------------------------------------------ comparing

Harness::test('no prior figure is not a 100% rise', function (): void {
    Assert::null(Reports::delta(50, null));
    Assert::null(Reports::delta(null, 50));
    Assert::null(Reports::delta(null, null));
});

Harness::test('a rise from zero has a change but no percentage', function (): void {
    $delta = Reports::delta(1200, 0);

    Assert::same(1200.0, $delta['change']);
    Assert::null($delta['percent'], 'dividing by zero is where "+∞%" comes from');
    Assert::same('up', $delta['direction']);
});

Harness::test('a delta knows its direction, including flat', function (): void {
    Assert::same('up', Reports::delta(10, 5)['direction']);
    Assert::same('down', Reports::delta(5, 10)['direction']);
    Assert::same('flat', Reports::delta(7, 7)['direction']);
    Assert::same(0.0, Reports::delta(7, 7)['percent']);
});

Harness::test('a percentage is signed against the SIZE of the base', function (): void {
    Assert::same(50.0, Reports::delta(150, 100)['percent']);
    Assert::same(-25.0, Reports::delta(75, 100)['percent']);

    // A negative base — a category that refunded more than it sold last
    // period. Dividing by the signed value flips the sign of the change, so
    // a recovery from -100 to -50 would read as a 50% FALL.
    $recovered = Reports::delta(-50, -100);
    Assert::same(50.0, $recovered['change']);
    Assert::same(50.0, $recovered['percent'], 'moving from -100 to -50 is a rise of 50%');
    Assert::same('up', $recovered['direction']);
});

Harness::test('a share has no value without a base', function (): void {
    Assert::same(25.0, Reports::share(25, 100));
    Assert::null(Reports::share(25, 0), 'not 0%, and not infinity');
    Assert::same(33.3, Reports::share(1, 3));
});

// ------------------------------------------------------------------- scrubbing

Harness::test('scrubbing money empties the columns and keeps them', function (): void {
    $report = [
        'columns' => [
            ['key' => 'day', 'label' => 'Day', 'type' => 'date'],
            ['key' => 'parties', 'label' => 'Parties', 'type' => 'int'],
            ['key' => 'booked', 'label' => 'Booked', 'type' => 'money'],
        ],
        'rows' => [
            ['day' => '2026-10-01', 'parties' => 4, 'booked' => 120000],
            ['day' => '2026-10-02', 'parties' => 2, 'booked' => 60000],
        ],
        'totals' => ['parties' => 6, 'booked' => 180000],
    ];

    $scrubbed = Reports::scrubMoney($report);

    Assert::count(3, $scrubbed['columns'], 'the column stays, so the rows still line up');
    Assert::null($scrubbed['rows'][0]['booked']);
    Assert::null($scrubbed['rows'][1]['booked']);
    Assert::null($scrubbed['totals']['booked']);

    // And nothing else is touched: the counts are the figures a technician is
    // allowed to read, and they must still be right.
    Assert::same(4, $scrubbed['rows'][0]['parties']);
    Assert::same(6, $scrubbed['totals']['parties']);
    Assert::same('2026-10-01', $scrubbed['rows'][0]['day']);
});

Harness::test('a report with no money is returned untouched', function (): void {
    $report = [
        'columns' => [['key' => 'band', 'label' => 'Visits', 'type' => 'text']],
        'rows'    => [['band' => 'Once']],
        'totals'  => [],
    ];

    Assert::same($report, Reports::scrubMoney($report));
});

// ------------------------------------------------------------------------ CSV

Harness::test('CSV money is a plain decimal a spreadsheet can total', function (): void {
    $columns = [
        ['key' => 'day', 'label' => 'Day', 'type' => 'date'],
        ['key' => 'parties', 'label' => 'Parties', 'type' => 'int'],
        ['key' => 'booked', 'label' => 'Booked', 'type' => 'money'],
        ['key' => 'share', 'label' => 'Share', 'type' => 'percent'],
    ];

    $rows = Reports::csvRows($columns, [
        ['day' => '2026-10-01', 'parties' => 4, 'booked' => 123400, 'share' => 66.7],
        ['day' => '2026-10-02', 'parties' => 0, 'booked' => null, 'share' => null],
    ]);

    // Not "$1,234.00" (which no spreadsheet will add up) and not 123400
    // (which is not money).
    Assert::same(['2026-10-01', 4, '1234.00', 66.7], $rows[0]);

    // A null is an EMPTY cell, not a zero: "we cannot say" and "none" are
    // different answers, and a blank keeps that true in the export too.
    Assert::same(['2026-10-02', 0, '', ''], $rows[1]);
});

Harness::test('the CSV header is the column labels, in column order', function (): void {
    Assert::same(
        ['Day', 'Parties'],
        Reports::csvHeader([
            ['key' => 'day', 'label' => 'Day', 'type' => 'date'],
            ['key' => 'parties', 'label' => 'Parties', 'type' => 'int'],
        ])
    );
});

Harness::test('an export is named so it can be found again', function (): void {
    Assert::same(
        'revenue-by-day-2026-10-01-to-2026-10-16.csv',
        Reports::filename('revenue_by_day', '2026-10-01', '2026-10-16')
    );

    // An unknown id still produces a usable filename rather than an empty one.
    Assert::same(
        'mystery-2026-01-01-to-2026-01-02.csv',
        Reports::filename('mystery', '2026-01-01', '2026-01-02')
    );
});

// -------------------------------------------------------------- the venue day

Harness::test('a venue day is cut in the venue timezone, not the server\'s', function (): void {
    // Named zones: correct, and what a server with its timezone tables loaded
    // gets. The one thing asserted is that the conversion is IN the SQL —
    // without it, DATE() on a UTC column is the server's day.
    LocalDay::useMode('named');
    Assert::contains('CONVERT_TZ', LocalDay::expr('b.created_at'));
    Assert::contains('b.created_at', LocalDay::expr('b.created_at'));
    Assert::null(LocalDay::note(), 'nothing to warn about when the conversion is exact');

    // The fallback: a fixed offset, which is an APPROXIMATION and says so.
    LocalDay::useMode('offset', -240);
    Assert::same('DATE((b.created_at + INTERVAL -240 MINUTE))', LocalDay::expr('b.created_at'));
    Assert::true(LocalDay::note() !== null, 'an approximation nobody is told about reads as a bug');

    // A venue actually on UTC needs no conversion at all.
    LocalDay::useMode('utc');
    Assert::same('DATE(b.created_at)', LocalDay::expr('b.created_at'));
    Assert::null(LocalDay::note());

    LocalDay::reset();
});

Harness::test('the day bounds bracket the window generously', function (): void {
    [$from, $to] = LocalDay::utcBounds('2026-10-01', '2026-10-16');

    // A day of slack at the start and two at the end, so the index-friendly
    // pre-filter can never cut a row the exact expression would have kept —
    // it exists to narrow the scan, not to decide anything.
    Assert::same('2026-09-30 00:00:00', $from);
    Assert::same('2026-10-18 00:00:00', $to);

    // An unparseable date opens the bounds rather than closing them around
    // 1970, which would silently empty the report.
    [$wideFrom, $wideTo] = LocalDay::utcBounds('not-a-date', 'nor-this');
    Assert::same('1000-01-01 00:00:00', $wideFrom);
    Assert::same('9999-12-31 23:59:59', $wideTo);
});

Harness::test('two windows in one query get separate placeholders', function (): void {
    LocalDay::useMode('offset', -240);

    // PDO runs with emulated prepares OFF, so a named placeholder may be
    // bound exactly once. A report that bounds its window and its prior
    // period with the same names fails with HY093 — a bare 500.
    $window = LocalDay::between('created_at', 'from', 'to', 'w');
    $prior  = LocalDay::between('created_at', 'prior_from', 'prior_to', 'p');

    Assert::contains(':w_utc_from', $window);
    Assert::contains(':p_utc_from', $prior);
    Assert::false(str_contains($prior, ':w_utc_from'), 'the prefixes must not collide');

    Assert::same(
        ['w_utc_from', 'w_utc_to'],
        array_keys(LocalDay::params('2026-10-01', '2026-10-16', 'w'))
    );

    LocalDay::reset();
});

Harness::test('a column name is a column name', function (): void {
    LocalDay::useMode('offset', -240);

    // The expression is built by interpolation — the one thing this codebase
    // otherwise never does — so the guard is explicit rather than left to the
    // discipline of twenty-three call sites.
    Assert::same('DATE((b.created_at + INTERVAL -240 MINUTE))', LocalDay::expr('b.created_at'));

    Assert::throws(
        InvalidArgumentException::class,
        static fn (): string => LocalDay::expr('created_at) OR 1=1 --')
    );
    Assert::throws(
        InvalidArgumentException::class,
        static fn (): string => LocalDay::expr('(SELECT 1)')
    );

    LocalDay::reset();
});

Harness::test('an unknown mode is refused rather than falling back quietly', function (): void {
    Assert::throws(
        InvalidArgumentException::class,
        static function (): void { LocalDay::useMode('whatever'); }
    );

    LocalDay::reset();
});
