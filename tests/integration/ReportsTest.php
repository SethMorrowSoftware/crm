<?php

declare(strict_types=1);

/**
 * Every report, run against a real database.
 *
 * `unit/reports` pins the catalogue and the arithmetic. This pins the
 * QUERIES, and there are three distinct ways a report can be wrong that only
 * a database can catch:
 *
 *   1. **It does not run at all.** Twenty-three hand-written queries against
 *      ninety-nine tables; a column renamed in a later migration breaks one
 *      of them and nothing else in the suite touches it. So the first test
 *      here runs all twenty-three on every supported engine and asserts the
 *      shape of what comes back — that is the regression net.
 *   2. **It binds a placeholder twice.** `PDO::ATTR_EMULATE_PREPARES` is off,
 *      so a name may be bound exactly once. A query that filters its window
 *      and its prior period with the same `:from` fails with HY093 and the
 *      venue sees a bare 500 with no message anywhere.
 *   3. **It cuts the day in the wrong timezone.** Every timestamp here is
 *      UTC and the Castle is four or five hours behind it, so
 *      `DATE(created_at)` files everything from eight in the evening onwards
 *      on the following day. At a venue whose parties are sold in the
 *      evening that is most of a Saturday night landing on Sunday — in every
 *      report, every week. The boundary tests below are the ones that would
 *      have caught it.
 */

use App\Core\DB;
use App\Core\Settings;
use App\Services\LocalDay;
use App\Services\ReportRollup;
use App\Services\ReportRunner;
use App\Services\Reports;
use Tests\Assert;
use Tests\Harness;

Harness::suite('integration/reports');

if (!test_boot()) {
    Harness::skip('reports suite', 'no database available');

    return;
}

// ------------------------------------------------------------------ fixtures

/** Everything this file creates is marked, so nothing else is deleted. */
const RI_MARK = 'ritest';

function ri_clear(): void
{
    // Child rows out first: `visits` and `deals` both point at a contact, and
    // the foreign keys are real.
    DB::query(
        'DELETE FROM {visits} WHERE contact_id IN (SELECT id FROM {contacts} WHERE source = :m)',
        ['m' => RI_MARK]
    );
    DB::query('DELETE FROM {bookings} WHERE source = :m', ['m' => RI_MARK]);
    DB::query('DELETE FROM {deals} WHERE source = :m', ['m' => RI_MARK]);
    DB::query('DELETE FROM {contacts} WHERE source = :m', ['m' => RI_MARK]);
}

/*
 * NOTE the `$overrides + [...]` order on all three of these. PHP's array `+`
 * keeps the LEFT side's value for a duplicate key, so `[...defaults] +
 * $overrides` silently IGNORES every override that names a default — which is
 * how the first run of this file reported four bookings where it had asked
 * for two, and read as a bug in the report rather than in the fixture.
 */
function ri_contact(array $overrides = []): int
{
    return DB::insert('contacts', $overrides + [
        'first_name' => 'Report',
        'last_name'  => 'Tester',
        'email'      => 'ri-' . bin2hex(random_bytes(4)) . '@example.test',
        'source'     => RI_MARK,
        'created_at' => gmdate('Y-m-d H:i:s'),
        'updated_at' => gmdate('Y-m-d H:i:s'),
    ]);
}

/** The first stage of the default pipeline: deals need both, NOT NULL. */
function ri_stage(): array
{
    $row = DB::first(
        'SELECT pipeline_id, id FROM {pipeline_stages} ORDER BY pipeline_id, sort_order LIMIT 1'
    );

    return [
        'pipeline_id' => (int) ($row['pipeline_id'] ?? 1),
        'stage_id'    => (int) ($row['id'] ?? 1),
    ];
}

function ri_deal(array $overrides = []): int
{
    return DB::insert('deals', $overrides + ri_stage() + [
        'title'       => 'Report test enquiry',
        'status'      => 'open',
        'value_cents' => 40000,
        'source'      => RI_MARK,
        'created_at'  => gmdate('Y-m-d H:i:s'),
        'updated_at'  => gmdate('Y-m-d H:i:s'),
    ]);
}

function ri_booking(string $eventDate, array $overrides = []): int
{
    return DB::insert('bookings', $overrides + [
        'booking_number'    => 'RI-' . bin2hex(random_bytes(4)),
        'type'              => 'birthday',
        'status'            => 'confirmed',
        'event_date'        => $eventDate,
        'guest_count'       => 12,
        'total_cents'       => 45000,
        'deposit_cents'     => 10000,
        'balance_due_cents' => 35000,
        'source'            => RI_MARK,
        'created_at'        => gmdate('Y-m-d H:i:s'),
        'updated_at'        => gmdate('Y-m-d H:i:s'),
    ]);
}

/** The window a report is handed, built the way the controller builds it. */
function ri_window(string $from, string $to): array
{
    return Reports::window('custom', $from, $to, $to);
}

/**
 * Is an evening at this venue already tomorrow in UTC?
 *
 * The two evening tests below exist for exactly one fact: that the Castle's
 * busiest hours fall on the far side of midnight UTC, so a report that groups
 * by `DATE(created_at)` files a Saturday night on Sunday. At a venue where
 * that is not true — UTC itself, or anywhere east of it — there is nothing
 * for them to catch, and they say so and stand down rather than failing.
 *
 * They used to ask `$zone === 'UTC'`, which is the same question only for a
 * venue behind UTC. Running the suite under CC_TEST_VENUE_TZ at UTC+14 is
 * what showed the difference: the guard let the test through and the test
 * then failed on its own precondition, which reads as a timezone bug rather
 * than as a case that does not apply.
 */
function ri_evening_crosses_utc_midnight(string $zone, string $localTime): bool
{
    $evening = new DateTimeImmutable(test_local_day(1) . ' ' . $localTime, new DateTimeZone($zone));

    return $evening->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d')
        !== $evening->format('Y-m-d');
}

// ------------------------------------------------- 1. they all actually run

Harness::test('every report in the catalogue runs and returns the agreed shape', function (): void {
    $window = ri_window(test_local_day(60), test_local_day());

    foreach (array_keys(Reports::CATALOG) as $id) {
        $result = ReportRunner::run($id, $window);

        Assert::true(is_array($result['columns']), $id . ': columns must be a list');
        Assert::true($result['columns'] !== [], $id . ': a report with no columns renders nothing');
        Assert::true(is_array($result['rows']), $id . ': rows must be a list');
        Assert::true(is_array($result['totals']), $id . ': totals must be an array, even if empty');

        foreach ($result['columns'] as $column) {
            Assert::hasKey('key', $column, $id . ': every column needs a key');
            Assert::hasKey('label', $column, $id . ': every column needs a label');
            Assert::inArray(
                $column['type'],
                ['date', 'int', 'money', 'percent', 'text'],
                $id . ': column "' . ($column['key'] ?? '?') . '" has an unknown type'
            );
        }

        // Every key a row carries must be declared, or the table silently
        // drops a figure the query went and fetched.
        $declared = array_column($result['columns'], 'key');

        foreach ($result['rows'] as $row) {
            foreach (array_keys($row) as $key) {
                Assert::inArray(
                    $key,
                    $declared,
                    $id . ': the query returns "' . $key . '" but no column declares it'
                );
            }
        }
    }
});

Harness::test('a money report declares at least one money column, and a plain one does not lie', function (): void {
    $window = ri_window(test_local_day(30), test_local_day());

    foreach (Reports::CATALOG as $id => $report) {
        $types = array_column(ReportRunner::run($id, $window)['columns'], 'type');

        // `money` on the catalogue entry is what decides whether a role
        // without `view_revenue` gets the figures scrubbed. A report marked
        // money with no money column scrubs nothing; one NOT marked that has
        // a money column leaks revenue to a technician.
        Assert::same(
            $report['money'],
            in_array('money', $types, true),
            $id . ': the catalogue says money=' . var_export($report['money'], true)
                . ' but the columns say otherwise'
        );
    }
});

Harness::test('a report that is not in the catalogue is refused by name', function (): void {
    $window = ri_window('2026-10-01', '2026-10-16');

    $e = Assert::throws(
        RuntimeException::class,
        static fn (): array => ReportRunner::run('definitely_not_a_report', $window)
    );

    Assert::contains('definitely_not_a_report', $e->getMessage());
});

// ---------------------------------------------- 2. the figures are the figures

Harness::test('revenue by day counts the parties it says it counts', function (): void {
    ri_clear();

    $day = test_local_day(5);

    ri_booking($day, ['total_cents' => 45000, 'guest_count' => 12]);
    ri_booking($day, ['total_cents' => 30000, 'guest_count' => 8]);

    // Each of these must be left OUT, and each is left out for its own
    // reason: an enquiry is not money, a cancellation did not happen, and a
    // deleted row is deleted.
    ri_booking($day, ['status' => 'inquiry', 'total_cents' => 99900]);
    ri_booking($day, ['status' => 'cancelled', 'total_cents' => 99900]);
    ri_booking($day, ['total_cents' => 99900, 'deleted_at' => gmdate('Y-m-d H:i:s')]);

    $result = ReportRunner::run('revenue_by_day', ri_window($day, $day));

    Assert::count(1, $result['rows'], 'one day was asked for, so one row comes back');
    Assert::same(2, (int) $result['rows'][0]['bookings']);
    Assert::same(75000, (int) $result['totals']['revenue']);
    Assert::same(20, (int) $result['totals']['guests']);

    ri_clear();
});

Harness::test('a report with no rows is an empty report, not a broken one', function (): void {
    ri_clear();

    // A window with nothing in it. "Nothing happened" is a real answer and
    // the totals must be zeros rather than nulls or a missing key.
    $result = ReportRunner::run('revenue_by_day', ri_window('2001-03-01', '2001-03-07'));

    Assert::same([], $result['rows']);
    Assert::same(0, (int) $result['totals']['revenue']);
    Assert::notNull($result['comparison'], 'the comparison is still offered, with nothing on both sides');
    Assert::same(0, (int) $result['comparison']['now']);
});

Harness::test('the prior period is read from the prior period', function (): void {
    ri_clear();

    $now    = test_local_day(3);
    $before = test_local_day(10);

    ri_booking($now, ['total_cents' => 50000]);
    ri_booking($before, ['total_cents' => 20000]);

    // A 7-day window ending at $now; its prior window is the 7 days before
    // that, which is where $before sits.
    $window = Reports::window(
        'custom',
        test_local_day(9),
        test_local_day(3),
        test_local_day()
    );

    $result = ReportRunner::run('revenue_by_day', $window);

    Assert::same(50000, (int) $result['comparison']['now']);
    Assert::same(20000, (int) $result['comparison']['before']);
    Assert::same('up', $result['comparison']['delta']['direction']);
    Assert::same(150.0, $result['comparison']['delta']['percent']);

    ri_clear();
});

Harness::test('a rise in cancellations is not reported as a good thing', function (): void {
    // The one report where UP is BAD. Without `good` on the comparison the
    // page paints every rise green, which is the colour saying "well done"
    // over a column of parties the venue lost.
    $window   = ri_window(test_local_day(30), test_local_day());
    $result   = ReportRunner::run('cancellations', $window);

    Assert::same('down', $result['comparison']['good']);

    // And the ordinary case is the other way round, so the field is really
    // carrying a judgement rather than a constant.
    Assert::same('up', ReportRunner::run('revenue_by_day', $window)['comparison']['good']);

    // Volume is neither. A venue that doubled its sends may have doubled its
    // complaints, so the figure gets no verdict at all.
    Assert::null(ReportRunner::run('message_volume', $window)['comparison']['good']);
});

// ------------------------------------------- 3. the day is cut in Chester, NY

Harness::test('an evening booking belongs to the evening it was taken', function (): void {
    ri_clear();

    $zone = Settings::timezone();

    if (!ri_evening_crosses_utc_midnight($zone, '21:00:00')) {
        // Nothing to prove at a venue whose 9pm is still the same day in UTC
        // — on UTC itself, or anywhere east of it. The Castle is not one.
        Assert::true(true, '9pm at ' . $zone . ' is the same day in UTC');

        return;
    }

    /*
     * Nine in the evening, venue time, on a date comfortably inside the
     * window — which in UTC is one or two in the morning the FOLLOWING day.
     * Reported with DATE() on the raw column it lands on tomorrow; reported
     * properly it lands on the evening it happened.
     *
     * This is the single most consequential bug this module could have, and
     * it is invisible without a test like this one: every figure is
     * plausible, every total is right, and the days are all shifted by one
     * for anything that happened after about eight o'clock.
     */
    $localDay = test_local_day(10);
    $evening  = new DateTimeImmutable($localDay . ' 21:00:00', new DateTimeZone($zone));
    $utc      = $evening->setTimezone(new DateTimeZone('UTC'));

    Assert::notSame(
        $localDay,
        $utc->format('Y-m-d'),
        'this test is meaningless unless 9pm local really is the next day in UTC'
    );

    ri_deal([
        'title'      => 'Evening enquiry',
        'contact_id' => ri_contact(),
        'created_at' => $utc->format('Y-m-d H:i:s'),
        'updated_at' => $utc->format('Y-m-d H:i:s'),
    ]);

    // A one-day window on the venue day it happened. If the conversion is
    // missing the deal is outside it and the report reads zero.
    $result = ReportRunner::run('lead_sources', ri_window($localDay, $localDay));

    $found = 0;

    foreach ($result['rows'] as $row) {
        $found += (int) $row['deals'];
    }

    Assert::same(1, $found, 'a 9pm enquiry must be counted on the day it was taken');

    // And the day AFTER — the day UTC would have filed it on — must not
    // contain it, or the fix has merely moved the error.
    $next  = (new DateTimeImmutable($localDay))->modify('+1 day')->format('Y-m-d');
    $after = ReportRunner::run('lead_sources', ri_window($next, $next));

    $leaked = 0;

    foreach ($after['rows'] as $row) {
        $leaked += (int) $row['deals'];
    }

    Assert::same(0, $leaked, 'and it must not also appear on the following day');

    ri_clear();
});

Harness::test('the list-growth trend puts an evening sign-up on the right day', function (): void {
    ri_clear();

    $zone = Settings::timezone();

    if (!ri_evening_crosses_utc_midnight($zone, '22:30:00')) {
        Assert::true(true, '10:30pm at ' . $zone . ' is the same day in UTC');

        return;
    }

    $localDay = test_local_day(4);
    $utc      = (new DateTimeImmutable($localDay . ' 22:30:00', new DateTimeZone($zone)))
        ->setTimezone(new DateTimeZone('UTC'));

    ri_contact(['created_at' => $utc->format('Y-m-d H:i:s')]);

    $result = ReportRunner::run('list_growth', ri_window($localDay, $localDay));

    Assert::count(1, $result['rows'], 'one day asked for, one day back');
    Assert::same($localDay, (string) $result['rows'][0]['day']);
    Assert::same(1, (int) $result['rows'][0]['added']);

    ri_clear();
});

Harness::test('the day expression and the window agree about which day it is', function (): void {
    // The two halves of every converted query: the sargable pre-filter on the
    // raw column, and the exact comparison on the converted one. They are
    // written in two places, so this checks a row at each edge of the window
    // is kept by BOTH — a pre-filter that is too tight silently drops the
    // first and last day of every report.
    $zone = Settings::timezone();

    if ($zone === 'UTC') {
        Assert::true(true, 'venue timezone is UTC');

        return;
    }

    ri_clear();

    $from = test_local_day(7);
    $to   = test_local_day(3);

    // Two minutes past midnight on the first day, and two minutes to
    // midnight on the last: the extremes a local day can hold.
    foreach ([[$from, '00:02:00'], [$to, '23:58:00']] as [$day, $time]) {
        $utc = (new DateTimeImmutable($day . ' ' . $time, new DateTimeZone($zone)))
            ->setTimezone(new DateTimeZone('UTC'));

        ri_contact(['created_at' => $utc->format('Y-m-d H:i:s')]);
    }

    $result = ReportRunner::run('list_growth', ri_window($from, $to));

    Assert::same(2, (int) $result['totals']['added'], 'both edges of the window are inside it');

    $byDay = [];

    foreach ($result['rows'] as $row) {
        $byDay[(string) $row['day']] = (int) $row['added'];
    }

    Assert::same(1, $byDay[$from] ?? 0, 'the first minute of the first day');
    Assert::same(1, $byDay[$to] ?? 0, 'the last minute of the last day');

    ri_clear();
});

Harness::test('the timezone mode is decided by the server, not assumed', function (): void {
    LocalDay::reset();

    $mode = LocalDay::mode();

    Assert::inArray($mode, ['named', 'offset', 'utc']);

    // Whichever it is, the expression it produces has to be valid SQL on THIS
    // server — a `named` mode on a server whose timezone tables were dropped
    // between the probe and the query would return NULL for every row, and a
    // report grouped by NULL is an empty report that reads as a dead venue.
    $value = DB::value('SELECT ' . LocalDay::expr('created_at') . ' FROM {contacts} LIMIT 1');

    if ($value !== null) {
        Assert::same(
            1,
            preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value),
            'the day expression must produce a date, got ' . var_export($value, true)
        );
    }

    // And the note matches the mode: an approximation says so, an exact
    // conversion stays quiet.
    if ($mode === 'offset') {
        Assert::notNull(LocalDay::note());
    } else {
        Assert::null(LocalDay::note());
    }
});

// --------------------------------------------------------- the clamp and CSV

Harness::test('a decade-long window is clamped before it reaches the database', function (): void {
    $window = Reports::window('custom', '2010-01-01', test_local_day(), test_local_day());

    Assert::true($window['clamped']);

    // And the report still runs on the clamped window rather than throwing.
    $result = ReportRunner::run('revenue_by_day', $window);
    Assert::true(is_array($result['rows']));
});

// ------------------------------------------------------- the nightly rollup

Harness::test('the rollup writes a figure for every metric on every day', function (): void {
    ri_clear();
    DB::query('DELETE FROM {report_daily_rollup}');

    $day = test_local_day(2);
    ri_booking($day, ['total_cents' => 40000, 'guest_count' => 10]);
    ri_booking($day, ['total_cents' => 20000, 'guest_count' => 6]);
    ri_booking($day, ['status' => 'cancelled', 'total_cents' => 90000]);

    $result = ReportRollup::refresh();

    Assert::same(ReportRollup::TRAILING_DAYS, $result['days']);
    Assert::same(
        ReportRollup::TRAILING_DAYS * count(ReportRollup::METRICS),
        $result['rows'],
        'every metric is written for every day, so a quiet day is a zero rather than a hole'
    );

    $held = DB::value(
        "SELECT value_numeric FROM {report_daily_rollup}
          WHERE `date` = :day AND metric = 'bookings'",
        ['day' => $day]
    );
    Assert::same(2.0, (float) $held, 'the cancelled party is not held');

    $revenue = DB::value(
        "SELECT value_cents FROM {report_daily_rollup}
          WHERE `date` = :day AND metric = 'booking_revenue'",
        ['day' => $day]
    );
    Assert::same(60000, (int) $revenue, 'and its money is not counted either');

    $lost = DB::value(
        "SELECT value_numeric FROM {report_daily_rollup}
          WHERE `date` = :day AND metric = 'bookings_cancelled'",
        ['day' => $day]
    );
    Assert::same(1.0, (float) $lost, 'it is counted as lost instead');

    ri_clear();
});

Harness::test('today is never in the rollup, because today is not over', function (): void {
    DB::query('DELETE FROM {report_daily_rollup}');

    $result = ReportRollup::refresh();
    $today  = (new DateTimeImmutable('now', venue_tz()))->format('Y-m-d');

    Assert::notSame($today, $result['to']);
    Assert::same(
        0,
        (int) DB::value('SELECT COUNT(*) FROM {report_daily_rollup} WHERE `date` >= :t', ['t' => $today]),
        'a partial day stored as a total is a number that goes DOWN overnight'
    );
});

Harness::test('running the rollup twice writes the same answer, not twice the answer', function (): void {
    ri_clear();
    DB::query('DELETE FROM {report_daily_rollup}');

    $day = test_local_day(3);
    ri_booking($day, ['total_cents' => 33300]);

    ReportRollup::refresh();
    $first = (int) DB::value('SELECT COUNT(*) FROM {report_daily_rollup}');

    ReportRollup::refresh();
    $second = (int) DB::value('SELECT COUNT(*) FROM {report_daily_rollup}');

    // It recomputes rather than accumulating. A rollup that adds to a counter
    // cannot be re-run, which means it cannot be repaired, which means the
    // first wrong night is wrong forever.
    Assert::same($first, $second);
    Assert::same(
        33300,
        (int) DB::value(
            "SELECT value_cents FROM {report_daily_rollup}
              WHERE `date` = :day AND metric = 'booking_revenue'",
            ['day' => $day]
        ),
        'and the figure is the figure, not double it'
    );

    ri_clear();
});

Harness::test('a correction made days later lands in the rollup', function (): void {
    ri_clear();
    DB::query('DELETE FROM {report_daily_rollup}');

    $day = test_local_day(6);
    $id  = ri_booking($day, ['total_cents' => 10000]);

    ReportRollup::refresh();

    // The venue corrects the party's total a week after it happened. The
    // trailing window exists for exactly this: a rollup that only ever wrote
    // the day after would hold the wrong figure for ever.
    DB::update('bookings', ['total_cents' => 48000], 'id = :id', ['id' => $id]);
    ReportRollup::refresh();

    Assert::same(
        48000,
        (int) DB::value(
            "SELECT value_cents FROM {report_daily_rollup}
              WHERE `date` = :day AND metric = 'booking_revenue'",
            ['day' => $day]
        )
    );

    ri_clear();
});

Harness::test('a gap longer than the trailing window is reached back for', function (): void {
    DB::query('DELETE FROM {report_daily_rollup}');

    $yesterday = (new DateTimeImmutable('now', venue_tz()))->modify('-1 day')->format('Y-m-d');

    // An empty table starts at the ordinary trailing window.
    $fresh = ReportRollup::windowStart($yesterday);
    Assert::same(
        (new DateTimeImmutable($yesterday))
            ->modify('-' . (ReportRollup::TRAILING_DAYS - 1) . ' days')->format('Y-m-d'),
        $fresh
    );

    // A table whose newest day is forty days old resumes the day AFTER it,
    // not fourteen days ago. A fixed trailing window would leave a permanent
    // hole between where it stopped and where it resumed — and no later run
    // repairs it, because the missing days are not in the table to be
    // noticed, so every figure spanning them is quietly low.
    $stale = (new DateTimeImmutable($yesterday))->modify('-40 days')->format('Y-m-d');

    DB::query(
        'INSERT INTO {report_daily_rollup}
            (`date`, metric, dimension, dimension_value, value_numeric, value_cents, updated_at)
         VALUES (:day, :metric, :dimension, :dimension_value, 0, 0, :now)',
        [
            'day'             => $stale,
            'metric'          => 'bookings',
            'dimension'       => 'total',
            'dimension_value' => 'all',
            'now'             => now_utc(),
        ]
    );

    Assert::same(
        (new DateTimeImmutable($stale))->modify('+1 day')->format('Y-m-d'),
        ReportRollup::windowStart($yesterday)
    );

    // And it will not reach back for ever: a venue restoring a years-old
    // backup must not spend its nightly tick rebuilding a decade.
    DB::query('DELETE FROM {report_daily_rollup}');
    DB::query(
        'INSERT INTO {report_daily_rollup}
            (`date`, metric, dimension, dimension_value, value_numeric, value_cents, updated_at)
         VALUES (:day, :metric, :dimension, :dimension_value, 0, 0, :now)',
        [
            'day'             => '2015-01-01',
            'metric'          => 'bookings',
            'dimension'       => 'total',
            'dimension_value' => 'all',
            'now'             => now_utc(),
        ]
    );

    $capped = ReportRollup::windowStart($yesterday);
    $floor  = (new DateTimeImmutable($yesterday))
        ->modify('-' . (ReportRollup::CATCHUP_DAYS - 1) . ' days')->format('Y-m-d');

    Assert::same($floor, $capped);

    DB::query('DELETE FROM {report_daily_rollup}');
});

Harness::test('the time budget stops the rollup between days and says so', function (): void {
    DB::query('DELETE FROM {report_daily_rollup}');

    // A budget that is already spent. It is checked BETWEEN days, so the
    // worst case is one day's queries past the deadline rather than the whole
    // window — and the run reports that it stopped rather than leaving a
    // short table to be discovered in a report.
    $result = ReportRollup::refresh(static fn (): bool => false);

    Assert::same(0, $result['days']);
    Assert::true($result['stopped']);

    // The next run picks up where it left off, which on an empty table is the
    // start of the trailing window.
    $finished = ReportRollup::refresh();
    Assert::false($finished['stopped']);
    Assert::same(ReportRollup::TRAILING_DAYS, $finished['days']);
});

Harness::test('a series fills the quiet days with zero and reports its coverage', function (): void {
    ri_clear();
    DB::query('DELETE FROM {report_daily_rollup}');

    $day = test_local_day(5);
    ri_booking($day);

    $result = ReportRollup::refresh();
    $series = ReportRollup::series('bookings', $result['from'], $result['to']);

    Assert::count(ReportRollup::TRAILING_DAYS, $series, 'one entry per day, quiet days included');
    Assert::same(1.0, $series[$day]);

    // Every other day is a real zero rather than a missing key, so a caller
    // charting this never has to decide what a hole means.
    foreach ($series as $when => $value) {
        if ($when !== $day) {
            Assert::same(0.0, $value, $when . ' should be a zero, not absent');
        }
    }

    $coverage = ReportRollup::coverage();
    Assert::same($result['from'], (string) $coverage['oldest']);
    Assert::same($result['to'], (string) $coverage['newest']);
    Assert::same(ReportRollup::TRAILING_DAYS, $coverage['days']);

    ri_clear();
});

Harness::test('the rollup agrees with the report it summarises', function (): void {
    ri_clear();
    DB::query('DELETE FROM {report_daily_rollup}');

    // The one assertion that catches a rollup drifting from the live query:
    // two independent implementations of "what did we take", compared.
    /*
     * The window in the VENUE's calendar, not UTC's. A report groups by the
     * venue's own day, so a booking dated UTC-yesterday lands in local TODAY
     * for four hours every night — and the rollup, which never holds today,
     * then disagreed with the live report by the whole of that booking
     * (measured: 25000 against 96000).
     */
    $from = test_local_day(8);
    $to   = test_local_day(1);

    ri_booking($from, ['total_cents' => 25000]);
    ri_booking($to, ['total_cents' => 71000]);
    ri_booking($to, ['status' => 'inquiry', 'total_cents' => 99900]);

    ReportRollup::refresh();

    $live = ReportRunner::run('revenue_by_day', ri_window($from, $to));
    $rolled = array_sum(ReportRollup::series('booking_revenue', $from, $to, true));

    Assert::same(
        (int) $live['totals']['revenue'],
        (int) $rolled,
        'the nightly total and the live report must agree about the same days'
    );

    ri_clear();
    DB::query('DELETE FROM {report_daily_rollup}');
});

Harness::test('every report exports as CSV with a cell per declared column', function (): void {
    $window = ri_window(test_local_day(30), test_local_day());

    foreach (array_keys(Reports::CATALOG) as $id) {
        $result = ReportRunner::run($id, $window);
        $header = Reports::csvHeader($result['columns']);
        $rows   = Reports::csvRows($result['columns'], $result['rows']);

        foreach ($rows as $row) {
            Assert::same(
                count($header),
                count($row),
                $id . ': a CSV row must have one cell per column or the file is unreadable'
            );
        }
    }
});
