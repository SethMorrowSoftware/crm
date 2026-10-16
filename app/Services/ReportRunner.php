<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use DateTimeImmutable;
use RuntimeException;
use function count;

/**
 * The reports themselves: one method per report, each returning the same
 * shape.
 *
 * The I/O half of `Reports`, which holds the catalogue and the window
 * arithmetic. Every method here answers one question against the tables the
 * rest of the application writes — there is no reporting warehouse and
 * nothing is pre-aggregated, because at a single venue's volume (thousands of
 * bookings a year, not millions) an indexed query over the real rows is both
 * faster to read and impossible to get out of step with the truth.
 *
 * Three rules every report here keeps:
 *
 *   1. **ACTUALS ONLY.** No projection, no run rate, no "typical day". A
 *      comparison with the previous period is not a projection — both sides
 *      happened — and it is the only context a report adds.
 *   2. **A DATE IS A VENUE DATE.** Every window is a span of venue-local
 *      days, converted once by the caller. A report that compared UTC
 *      timestamps would put every booking taken after about eight in the
 *      evening on the following day, all year.
 *   3. **NULL IS NOT ZERO.** A row with no comparison, no average and no
 *      rate says so. "0%" and "we cannot say" are different answers and a
 *      venue acts differently on each.
 *
 * Each report is bounded: either it groups (so the row count is the number of
 * days, stages or packages) or it has an explicit LIMIT with the cut-off
 * stated in its note.
 */
final class ReportRunner
{
    /** The most rows any listing report returns. Stated in the report's note. */
    public const ROW_LIMIT = 500;

    /**
     * Run a report.
     *
     * A `comparison` block carries `good`: which direction is the good one,
     * or null when neither is. That is the REPORT'S judgement and not the
     * view's — more revenue is better and more cancellations is worse, and a
     * page that paints every rise green ends up congratulating a venue on the
     * parties it lost.
     *
     * @param array{from:string,to:string,prior_from:string,prior_to:string,days:int} $window
     * @return array{columns:list<array{key:string,label:string,type:string}>,
     *     rows:list<array<string,mixed>>, totals:array<string,mixed>, note:?string,
     *     comparison:?array{label:string,now:mixed,before:mixed,type:string,
     *         good:?string,delta:?array<string,mixed>}}
     */
    public static function run(string $id, array $window): array
    {
        if (!Reports::exists($id)) {
            throw new RuntimeException('There is no report called "' . $id . '".');
        }

        $method = str_replace('_', '', ucwords($id, '_'));
        $method = lcfirst($method);

        if (!method_exists(self::class, $method)) {
            throw new RuntimeException('The "' . $id . '" report is in the catalogue but has no query.');
        }

        /** @var array<string,mixed> $result */
        $result = self::$method($window);

        return $result + ['note' => null, 'comparison' => null, 'totals' => []];
    }

    // =================================================================
    // Venue days
    //
    // Every timestamp in this database is UTC and every report is a claim
    // about the venue's calendar. `DATE(created_at)` — the obvious thing —
    // files everything from eight in the evening onwards on the following
    // day, which at a venue whose parties are booked in the evening moves
    // most of a Saturday night onto Sunday. `LocalDay` does the conversion;
    // these four helpers are what the queries below call so that not one of
    // them has to remember to.
    //
    // A column that is already a venue DATE — `bookings.event_date`,
    // `visits.visit_date` — is compared directly and needs none of this. The
    // distinction is deliberate and each query says which it is using.
    // =================================================================

    /** "This UTC column falls inside the window", as venue days. */
    private static function inWindow(string $column, string $prefix = 'w'): string
    {
        return LocalDay::between($column, 'from', 'to', $prefix);
    }

    /** The same for the comparison period. A separate prefix: see below. */
    private static function inPrior(string $column, string $prefix = 'p'): string
    {
        return LocalDay::between($column, 'prior_from', 'prior_to', $prefix);
    }

    /**
     * The bound values `inWindow()` needs.
     *
     * The prefix exists because `PDO::ATTR_EMULATE_PREPARES` is off in this
     * application, so a named placeholder may be bound exactly ONCE. A query
     * that bounds two columns, or one window and its prior, needs a prefix
     * per clause or it fails with HY093 — which surfaces as a bare 500.
     *
     * @param array<string,mixed> $w
     * @return array<string,string>
     */
    private static function windowParams(array $w, string $prefix = 'w'): array
    {
        return ['from' => (string) $w['from'], 'to' => (string) $w['to']]
            + LocalDay::params((string) $w['from'], (string) $w['to'], $prefix);
    }

    /**
     * @param array<string,mixed> $w
     * @return array<string,string>
     */
    private static function priorParams(array $w, string $prefix = 'p'): array
    {
        return ['prior_from' => (string) $w['prior_from'], 'prior_to' => (string) $w['prior_to']]
            + LocalDay::params((string) $w['prior_from'], (string) $w['prior_to'], $prefix);
    }

    /**
     * The venue day of the first non-null of several UTC columns.
     *
     * No sargable pre-filter: a COALESCE of two columns cannot use an index
     * whatever you do to it, and the two tables read this way (campaigns and
     * reviews) are in the hundreds of rows.
     */
    private static function localDayOf(string ...$columns): string
    {
        if (count($columns) === 1) {
            return LocalDay::expr($columns[0]);
        }

        $converted = array_map(
            static fn (string $column): string => LocalDay::datetimeExpr($column),
            $columns
        );

        return 'DATE(COALESCE(' . implode(', ', $converted) . '))';
    }

    // =================================================================
    // Revenue
    // =================================================================

    /** @param array<string,mixed> $w */
    private static function revenueByDay(array $w): array
    {
        $rows = DB::all(
            "SELECT b.event_date AS day,
                    COUNT(*) AS bookings,
                    SUM(b.total_cents) AS revenue,
                    SUM(b.deposit_cents) AS deposits,
                    SUM(b.balance_due_cents) AS outstanding,
                    SUM(b.guest_count) AS guests
               FROM {bookings} b
              WHERE b.deleted_at IS NULL
                AND b.status NOT IN ('cancelled', 'no_show', 'inquiry')
                AND b.event_date BETWEEN :from AND :to
              GROUP BY b.event_date
              ORDER BY b.event_date",
            ['from' => $w['from'], 'to' => $w['to']]
        );

        $prior = (int) DB::value(
            "SELECT COALESCE(SUM(total_cents), 0) FROM {bookings}
              WHERE deleted_at IS NULL AND status NOT IN ('cancelled', 'no_show', 'inquiry')
                AND event_date BETWEEN :from AND :to",
            ['from' => $w['prior_from'], 'to' => $w['prior_to']]
        );

        $totals = self::sumColumns($rows, ['bookings', 'revenue', 'deposits', 'outstanding', 'guests']);

        return [
            'columns' => [
                ['key' => 'day', 'label' => 'Day', 'type' => 'date'],
                ['key' => 'bookings', 'label' => 'Parties', 'type' => 'int'],
                ['key' => 'guests', 'label' => 'Guests', 'type' => 'int'],
                ['key' => 'revenue', 'label' => 'Booked', 'type' => 'money'],
                ['key' => 'deposits', 'label' => 'Deposits taken', 'type' => 'money'],
                ['key' => 'outstanding', 'label' => 'Still owed', 'type' => 'money'],
            ],
            'rows'       => $rows,
            'totals'     => $totals,
            'comparison' => [
                'label'   => 'the ' . (int) $w['days'] . ' days before',
                'now'     => (int) $totals['revenue'],
                'before'  => $prior,
                'type'    => 'money',
                'good'    => 'up',
                'delta'   => Reports::delta((int) $totals['revenue'], $prior),
            ],
            'note' => 'Counted on the day of the party, not the day it was booked, and '
                . 'excluding enquiries, cancellations and no-shows.',
        ];
    }

    /** @param array<string,mixed> $w */
    private static function revenueByPackage(array $w): array
    {
        $rows = DB::all(
            "SELECT COALESCE(p.name, 'No package') AS package,
                    COUNT(*) AS bookings,
                    SUM(b.guest_count) AS guests,
                    SUM(b.total_cents) AS revenue,
                    ROUND(AVG(b.total_cents)) AS average
               FROM {bookings} b
               LEFT JOIN {party_packages} p ON p.id = b.package_id
              WHERE b.deleted_at IS NULL
                AND b.status NOT IN ('cancelled', 'no_show', 'inquiry')
                AND b.event_date BETWEEN :from AND :to
              GROUP BY b.package_id, p.name
              ORDER BY revenue DESC",
            ['from' => $w['from'], 'to' => $w['to']]
        );

        $totals = self::sumColumns($rows, ['bookings', 'guests', 'revenue']);
        $rows   = self::addShare($rows, 'revenue', (int) $totals['revenue'], 'share');

        return [
            'columns' => [
                ['key' => 'package', 'label' => 'Package', 'type' => 'text'],
                ['key' => 'bookings', 'label' => 'Parties', 'type' => 'int'],
                ['key' => 'guests', 'label' => 'Guests', 'type' => 'int'],
                ['key' => 'revenue', 'label' => 'Booked', 'type' => 'money'],
                ['key' => 'average', 'label' => 'Average party', 'type' => 'money'],
                ['key' => 'share', 'label' => 'Share', 'type' => 'percent'],
            ],
            'rows'   => $rows,
            'totals' => $totals,
            'note'   => 'A package sold at a discount still counts at what was charged, not at '
                . 'its list price.',
        ];
    }

    /** @param array<string,mixed> $w */
    private static function revenueByType(array $w): array
    {
        $rows = DB::all(
            "SELECT b.type,
                    COUNT(*) AS bookings,
                    SUM(b.guest_count) AS guests,
                    SUM(b.total_cents) AS revenue,
                    ROUND(AVG(b.total_cents)) AS average
               FROM {bookings} b
              WHERE b.deleted_at IS NULL
                AND b.status NOT IN ('cancelled', 'no_show', 'inquiry')
                AND b.event_date BETWEEN :from AND :to
              GROUP BY b.type
              ORDER BY revenue DESC",
            ['from' => $w['from'], 'to' => $w['to']]
        );

        foreach ($rows as $index => $row) {
            $rows[$index]['type'] = ucwords(str_replace('_', ' ', (string) $row['type']));
        }

        $totals = self::sumColumns($rows, ['bookings', 'guests', 'revenue']);
        $rows   = self::addShare($rows, 'revenue', (int) $totals['revenue'], 'share');

        return [
            'columns' => [
                ['key' => 'type', 'label' => 'Kind of booking', 'type' => 'text'],
                ['key' => 'bookings', 'label' => 'Bookings', 'type' => 'int'],
                ['key' => 'guests', 'label' => 'Guests', 'type' => 'int'],
                ['key' => 'revenue', 'label' => 'Booked', 'type' => 'money'],
                ['key' => 'average', 'label' => 'Average', 'type' => 'money'],
                ['key' => 'share', 'label' => 'Share', 'type' => 'percent'],
            ],
            'rows'   => $rows,
            'totals' => $totals,
        ];
    }

    /** @param array<string,mixed> $w */
    private static function depositsOutstanding(array $w): array
    {
        $rows = DB::all(
            "SELECT b.booking_number, b.event_date, b.type,
                    CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, '')) AS guest,
                    b.total_cents AS total,
                    b.deposit_cents AS deposit,
                    b.balance_due_cents AS owed,
                    DATEDIFF(b.event_date, :today) AS days_away,
                    b.status
               FROM {bookings} b
               LEFT JOIN {contacts} c ON c.id = b.contact_id
              WHERE b.deleted_at IS NULL
                AND b.status IN ('held', 'confirmed', 'deposit_paid')
                AND b.balance_due_cents > 0
                AND b.event_date BETWEEN :from AND :to
              ORDER BY b.event_date
              LIMIT " . self::ROW_LIMIT,
            ['from' => $w['from'], 'to' => $w['to'], 'today' => self::today()]
        );

        foreach ($rows as $index => $row) {
            $rows[$index]['guest']  = trim((string) $row['guest']) ?: 'No guest on the booking';
            $rows[$index]['status'] = ucwords(str_replace('_', ' ', (string) $row['status']));
        }

        return [
            'columns' => [
                ['key' => 'event_date', 'label' => 'Party', 'type' => 'date'],
                ['key' => 'days_away', 'label' => 'Days away', 'type' => 'int'],
                ['key' => 'booking_number', 'label' => 'Booking', 'type' => 'text'],
                ['key' => 'guest', 'label' => 'Guest', 'type' => 'text'],
                ['key' => 'status', 'label' => 'State', 'type' => 'text'],
                ['key' => 'total', 'label' => 'Total', 'type' => 'money'],
                ['key' => 'deposit', 'label' => 'Paid', 'type' => 'money'],
                ['key' => 'owed', 'label' => 'Owed', 'type' => 'money'],
            ],
            'rows'   => $rows,
            'totals' => self::sumColumns($rows, ['total', 'deposit', 'owed']),
            'note'   => 'Parties in the window that still owe something. A negative "days away" '
                . 'is a party that has already happened and was never settled.',
        ];
    }

    /** @param array<string,mixed> $w */
    private static function membershipRevenue(array $w): array
    {
        $rows = DB::all(
            "SELECT pl.name AS plan,
                    COUNT(*) AS payments,
                    COUNT(DISTINCT m.id) AS memberships,
                    SUM(mp.amount_cents) AS taken
               FROM {membership_payments} mp
               JOIN {memberships} m ON m.id = mp.membership_id
               JOIN {membership_plans} pl ON pl.id = m.plan_id
              WHERE mp.status = 'paid'
                AND " . self::inWindow('mp.occurred_at') . "
              GROUP BY pl.id, pl.name
              ORDER BY taken DESC",
            self::windowParams($w)
        );

        return [
            'columns' => [
                ['key' => 'plan', 'label' => 'Plan', 'type' => 'text'],
                ['key' => 'memberships', 'label' => 'Passes', 'type' => 'int'],
                ['key' => 'payments', 'label' => 'Payments', 'type' => 'int'],
                ['key' => 'taken', 'label' => 'Taken', 'type' => 'money'],
            ],
            'rows'   => $rows,
            'totals' => self::sumColumns($rows, ['memberships', 'payments', 'taken']),
            'note'   => 'Counted when the money was taken. A renewal is a second payment on the '
                . 'same pass, which is why payments can exceed passes.',
        ];
    }

    // =================================================================
    // Sales
    // =================================================================

    /** @param array<string,mixed> $w */
    private static function pipelineFunnel(array $w): array
    {
        $rows = DB::all(
            "SELECT s.name AS stage, s.sort_order,
                    COUNT(d.id) AS deals,
                    SUM(d.value_cents) AS value,
                    SUM(d.status = 'won') AS won,
                    SUM(d.status = 'lost') AS lost
               FROM {pipeline_stages} s
               LEFT JOIN {deals} d ON d.stage_id = s.id
                    AND d.deleted_at IS NULL
                    AND " . self::inWindow('d.created_at') . "
              GROUP BY s.id, s.name, s.sort_order
              ORDER BY s.sort_order",
            self::windowParams($w)
        );

        $totals = self::sumColumns($rows, ['deals', 'value', 'won', 'lost']);

        // The share is of everything that entered the pipeline in the window,
        // so a reader can see where the drop-off is rather than reading four
        // unrelated counts.
        $rows = self::addShare($rows, 'deals', (int) $totals['deals'], 'share');

        foreach ($rows as $index => $row) {
            unset($rows[$index]['sort_order']);
        }

        return [
            'columns' => [
                ['key' => 'stage', 'label' => 'Stage', 'type' => 'text'],
                ['key' => 'deals', 'label' => 'Deals', 'type' => 'int'],
                ['key' => 'share', 'label' => 'Share', 'type' => 'percent'],
                ['key' => 'value', 'label' => 'Value', 'type' => 'money'],
                ['key' => 'won', 'label' => 'Won', 'type' => 'int'],
                ['key' => 'lost', 'label' => 'Lost', 'type' => 'int'],
            ],
            'rows'   => $rows,
            'totals' => $totals,
            'note'   => 'Deals are counted by the stage they are in NOW and the date they were '
                . 'created — so this is where the enquiries from this window have got to, not '
                . 'how many passed through each stage.',
        ];
    }

    /** @param array<string,mixed> $w */
    private static function winLoss(array $w): array
    {
        $rows = DB::all(
            "SELECT COALESCE(lr.label, CASE WHEN d.status = 'won' THEN 'Won' ELSE 'No reason given' END)
                        AS reason,
                    d.status,
                    COUNT(*) AS deals,
                    SUM(d.value_cents) AS value
               FROM {deals} d
               LEFT JOIN {lost_reasons} lr ON lr.id = d.lost_reason_id
              WHERE d.deleted_at IS NULL
                AND d.status IN ('won', 'lost')
                AND " . self::localDayOf('d.won_at', 'd.lost_at') . " BETWEEN :from AND :to
              GROUP BY reason, d.status
              ORDER BY d.status, deals DESC",
            ['from' => $w['from'], 'to' => $w['to']]
        );

        $won      = 0;
        $lost     = 0;
        $priorWon = (int) DB::value(
            "SELECT COUNT(*) FROM {deals}
              WHERE deleted_at IS NULL AND status = 'won'
                AND " . self::inPrior('won_at'),
            self::priorParams($w)
        );

        foreach ($rows as $index => $row) {
            $rows[$index]['status'] = ucfirst((string) $row['status']);

            if ((string) $row['status'] === 'won') {
                $won += (int) $row['deals'];
            } else {
                $lost += (int) $row['deals'];
            }
        }

        $totals = self::sumColumns($rows, ['deals', 'value']);
        $totals['win_rate'] = Reports::share($won, $won + $lost);

        return [
            'columns' => [
                ['key' => 'status', 'label' => 'Outcome', 'type' => 'text'],
                ['key' => 'reason', 'label' => 'Reason', 'type' => 'text'],
                ['key' => 'deals', 'label' => 'Deals', 'type' => 'int'],
                ['key' => 'value', 'label' => 'Value', 'type' => 'money'],
            ],
            'rows'       => $rows,
            'totals'     => $totals,
            'comparison' => [
                'label'  => 'deals won in the ' . (int) $w['days'] . ' days before',
                'now'    => $won,
                'before' => $priorWon,
                'type'   => 'int',
                'good'   => 'up',
                'delta'  => Reports::delta($won, $priorWon),
            ],
            'note' => $totals['win_rate'] === null
                ? 'Nothing was settled in this window, so there is no win rate to report.'
                : 'Win rate: ' . $totals['win_rate'] . '% of the ' . ($won + $lost)
                    . ' deals settled in this window. A deal still open is in neither column.',
        ];
    }

    /** @param array<string,mixed> $w */
    private static function repPerformance(array $w): array
    {
        $rows = DB::all(
            "SELECT COALESCE(u.name, 'Nobody') AS owner,
                    COUNT(*) AS deals,
                    SUM(d.status = 'won') AS won,
                    SUM(d.status = 'lost') AS lost,
                    SUM(CASE WHEN d.status = 'won' THEN d.value_cents ELSE 0 END) AS won_value
               FROM {deals} d
               LEFT JOIN {users} u ON u.id = d.owner_id
              WHERE d.deleted_at IS NULL
                AND " . self::inWindow('d.created_at') . "
              GROUP BY d.owner_id, u.name
              ORDER BY won_value DESC, deals DESC",
            self::windowParams($w)
        );

        foreach ($rows as $index => $row) {
            $settled = (int) $row['won'] + (int) $row['lost'];

            // Null, not 0%, when nothing of theirs has been settled: a new
            // starter with three open deals has no win rate yet, and showing
            // them 0% beside a colleague's 60% is a judgement the data does
            // not support.
            $rows[$index]['win_rate'] = $settled === 0 ? null : Reports::share((int) $row['won'], $settled);
        }

        return [
            'columns' => [
                ['key' => 'owner', 'label' => 'Owner', 'type' => 'text'],
                ['key' => 'deals', 'label' => 'Deals', 'type' => 'int'],
                ['key' => 'won', 'label' => 'Won', 'type' => 'int'],
                ['key' => 'lost', 'label' => 'Lost', 'type' => 'int'],
                ['key' => 'win_rate', 'label' => 'Win rate', 'type' => 'percent'],
                ['key' => 'won_value', 'label' => 'Won value', 'type' => 'money'],
            ],
            'rows'   => $rows,
            'totals' => self::sumColumns($rows, ['deals', 'won', 'lost', 'won_value']),
            'note'   => 'Deals created in the window, grouped by who owns them now. A deal '
                . 'reassigned mid-flight counts for whoever holds it today.',
        ];
    }

    /** @param array<string,mixed> $w */
    private static function leadSources(array $w): array
    {
        $rows = DB::all(
            "SELECT COALESCE(NULLIF(d.source, ''), 'Not recorded') AS source,
                    COUNT(*) AS deals,
                    SUM(d.status = 'won') AS won,
                    SUM(CASE WHEN d.status = 'won' THEN d.value_cents ELSE 0 END) AS won_value
               FROM {deals} d
              WHERE d.deleted_at IS NULL
                AND " . self::inWindow('d.created_at') . "
              GROUP BY source
              ORDER BY deals DESC",
            self::windowParams($w)
        );

        foreach ($rows as $index => $row) {
            $rows[$index]['conversion'] = Reports::share((int) $row['won'], (int) $row['deals']);
        }

        return [
            'columns' => [
                ['key' => 'source', 'label' => 'Source', 'type' => 'text'],
                ['key' => 'deals', 'label' => 'Enquiries', 'type' => 'int'],
                ['key' => 'won', 'label' => 'Became bookings', 'type' => 'int'],
                ['key' => 'conversion', 'label' => 'Conversion', 'type' => 'percent'],
                ['key' => 'won_value', 'label' => 'Value won', 'type' => 'money'],
            ],
            'rows'   => $rows,
            'totals' => self::sumColumns($rows, ['deals', 'won', 'won_value']),
            'note'   => '"Not recorded" is the honest answer for an enquiry that arrived with no '
                . 'source on it — usually a phone call typed in by hand.',
        ];
    }

    // =================================================================
    // Marketing
    // =================================================================

    /** @param array<string,mixed> $w */
    private static function campaignPerformance(array $w): array
    {
        $rows = DB::all(
            "SELECT c.name, c.channel, c.status,
                    c.recipients_total AS audience,
                    c.sent_count AS sent,
                    c.failed_count AS failed,
                    c.unsubscribed_count AS opted_out,
                    c.estimated_cost_cents AS cost,
                    " . self::localDayOf('c.scheduled_at') . " AS sent_on
               FROM {campaigns} c
              WHERE " . self::localDayOf('c.scheduled_at', 'c.created_at') . " BETWEEN :from AND :to
              ORDER BY COALESCE(c.scheduled_at, c.created_at) DESC
              LIMIT " . self::ROW_LIMIT,
            ['from' => $w['from'], 'to' => $w['to']]
        );

        foreach ($rows as $index => $row) {
            $rows[$index]['delivered_rate'] = Reports::share(
                (int) $row['sent'] - (int) $row['failed'],
                (int) $row['sent']
            );
        }

        return [
            'columns' => [
                ['key' => 'sent_on', 'label' => 'Sent', 'type' => 'date'],
                ['key' => 'name', 'label' => 'Campaign', 'type' => 'text'],
                ['key' => 'channel', 'label' => 'Channel', 'type' => 'text'],
                ['key' => 'audience', 'label' => 'Audience', 'type' => 'int'],
                ['key' => 'sent', 'label' => 'Attempted', 'type' => 'int'],
                ['key' => 'failed', 'label' => 'Failed', 'type' => 'int'],
                ['key' => 'delivered_rate', 'label' => 'Got through', 'type' => 'percent'],
                ['key' => 'opted_out', 'label' => 'Opted out', 'type' => 'int'],
                ['key' => 'cost', 'label' => 'Cost', 'type' => 'money'],
            ],
            'rows'   => $rows,
            'totals' => self::sumColumns($rows, ['audience', 'sent', 'failed', 'opted_out', 'cost']),
            'note'   => 'Opens and clicks are not counted: this version does not track them, and '
                . 'a column of zeroes would read as nobody opening anything.',
        ];
    }

    /** @param array<string,mixed> $w */
    private static function listGrowth(array $w): array
    {
        $joinedOn  = LocalDay::expr('created_at');
        $optedOutOn = LocalDay::expr('marketing_opt_out_at');

        $added = DB::pairs(
            "SELECT " . $joinedOn . " AS day, COUNT(*) FROM {contacts}
              WHERE deleted_at IS NULL AND " . self::inWindow('created_at') . "
              GROUP BY " . $joinedOn,
            self::windowParams($w)
        );

        $lost = DB::pairs(
            "SELECT " . $optedOutOn . " AS day, COUNT(*) FROM {contacts}
              WHERE marketing_opt_out_at IS NOT NULL
                AND " . self::inWindow('marketing_opt_out_at') . "
              GROUP BY " . $optedOutOn,
            self::windowParams($w)
        );

        $rows    = [];
        $running = 0;

        foreach (self::eachDay((string) $w['from'], (string) $w['to']) as $day) {
            $in      = (int) ($added[$day] ?? 0);
            $out     = (int) ($lost[$day] ?? 0);
            $running += $in - $out;

            $rows[] = [
                'day'     => $day,
                'added'   => $in,
                'left'    => $out,
                'net'     => $in - $out,
                'running' => $running,
            ];
        }

        $totals = self::sumColumns($rows, ['added', 'left', 'net']);

        $priorAdded = (int) DB::value(
            "SELECT COUNT(*) FROM {contacts}
              WHERE deleted_at IS NULL AND " . self::inPrior('created_at'),
            self::priorParams($w)
        );

        return [
            'columns' => [
                ['key' => 'day', 'label' => 'Day', 'type' => 'date'],
                ['key' => 'added', 'label' => 'Added', 'type' => 'int'],
                ['key' => 'left', 'label' => 'Opted out', 'type' => 'int'],
                ['key' => 'net', 'label' => 'Net', 'type' => 'int'],
                ['key' => 'running', 'label' => 'Running total', 'type' => 'int'],
            ],
            'rows'       => $rows,
            'totals'     => $totals,
            'comparison' => [
                'label'  => 'added in the ' . (int) $w['days'] . ' days before',
                'now'    => (int) $totals['added'],
                'before' => $priorAdded,
                'type'   => 'int',
                'good'   => 'up',
                'delta'  => Reports::delta((int) $totals['added'], $priorAdded),
            ],
            'note' => 'The running total is the net change ACROSS THIS WINDOW, not the size of '
                . 'the list — it starts from nought on the first day shown.',
        ];
    }

    /** @param array<string,mixed> $w */
    private static function messageVolume(array $w): array
    {
        $rows = DB::all(
            "SELECT m.channel,
                    COUNT(*) AS messages,
                    SUM(m.direction = 'outbound') AS sent,
                    SUM(m.direction = 'inbound') AS received,
                    SUM(m.status = 'failed') AS failed,
                    SUM(m.cost_cents) AS cost,
                    COUNT(DISTINCT m.contact_id) AS guests
               FROM {messages} m
              WHERE " . self::inWindow('m.created_at') . "
              GROUP BY m.channel
              ORDER BY messages DESC",
            self::windowParams($w)
        );

        $totals = self::sumColumns($rows, ['messages', 'sent', 'received', 'failed', 'cost', 'guests']);

        $prior = (int) DB::value(
            "SELECT COUNT(*) FROM {messages} WHERE " . self::inPrior('created_at'),
            self::priorParams($w)
        );

        return [
            'columns' => [
                ['key' => 'channel', 'label' => 'Channel', 'type' => 'text'],
                ['key' => 'messages', 'label' => 'Messages', 'type' => 'int'],
                ['key' => 'sent', 'label' => 'Out', 'type' => 'int'],
                ['key' => 'received', 'label' => 'In', 'type' => 'int'],
                ['key' => 'failed', 'label' => 'Failed', 'type' => 'int'],
                ['key' => 'guests', 'label' => 'Guests reached', 'type' => 'int'],
                ['key' => 'cost', 'label' => 'Cost', 'type' => 'money'],
            ],
            'rows'       => $rows,
            'totals'     => $totals,
            'comparison' => [
                'label'  => 'messages in the ' . (int) $w['days'] . ' days before',
                'now'    => (int) $totals['messages'],
                'before' => $prior,
                'type'   => 'int',
                // Sending MORE is neither good nor bad — it is a volume, and a
                // venue that doubled its sends may have doubled its complaints.
                'good'   => null,
                'delta'  => Reports::delta((int) $totals['messages'], $prior),
            ],
            'note' => '"Guests reached" counts each person once per channel, so somebody emailed '
                . 'and texted appears in both rows.',
        ];
    }

    // =================================================================
    // Guests
    // =================================================================

    /** @param array<string,mixed> $w */
    private static function newVsReturning(array $w): array
    {
        /*
         * NEW means their FIRST visit fell in this window, which is a
         * property of the guest rather than of the visit — so a family's
         * second visit this month counts as returning even though both
         * visits are inside the window. The alternative (first visit ever
         * versus any later one) is the same thing said from the visit's side
         * and gives a different answer for every window.
         */
        // `visit_date` is ALREADY a venue date, so the window needs no
        // conversion. `first_visit_at` is a UTC timestamp, so comparing the
        // two means converting it first — without that, every guest whose
        // first visit was recorded after eight in the evening reads as
        // returning on the very day they first came.
        $rows = DB::all(
            "SELECT v.visit_date AS day,
                    COUNT(*) AS visits,
                    SUM(CASE WHEN " . LocalDay::expr('c.first_visit_at') . " = v.visit_date
                             THEN 1 ELSE 0 END) AS new_guests,
                    SUM(v.spend_cents) AS spend
               FROM {visits} v
               JOIN {contacts} c ON c.id = v.contact_id
              WHERE v.visit_date BETWEEN :from AND :to
              GROUP BY v.visit_date
              ORDER BY v.visit_date",
            ['from' => $w['from'], 'to' => $w['to']]
        );

        foreach ($rows as $index => $row) {
            $rows[$index]['returning'] = (int) $row['visits'] - (int) $row['new_guests'];
            $rows[$index]['new_share'] = Reports::share((int) $row['new_guests'], (int) $row['visits']);
        }

        $totals = self::sumColumns($rows, ['visits', 'new_guests', 'returning', 'spend']);

        $prior = (int) DB::value(
            'SELECT COUNT(*) FROM {visits} WHERE visit_date BETWEEN :from AND :to',
            ['from' => $w['prior_from'], 'to' => $w['prior_to']]
        );

        return [
            'columns' => [
                ['key' => 'day', 'label' => 'Day', 'type' => 'date'],
                ['key' => 'visits', 'label' => 'Visits', 'type' => 'int'],
                ['key' => 'new_guests', 'label' => 'First time', 'type' => 'int'],
                ['key' => 'returning', 'label' => 'Been before', 'type' => 'int'],
                ['key' => 'new_share', 'label' => 'New', 'type' => 'percent'],
                ['key' => 'spend', 'label' => 'Spend', 'type' => 'money'],
            ],
            'rows'       => $rows,
            'totals'     => $totals,
            'comparison' => [
                'label'  => 'visits in the ' . (int) $w['days'] . ' days before',
                'now'    => (int) $totals['visits'],
                'before' => $prior,
                'type'   => 'int',
                'good'   => 'up',
                'delta'  => Reports::delta((int) $totals['visits'], $prior),
            ],
            'note' => 'Admission is free and there is no gate scan, so a visit is DERIVED — from '
                . 'a party that happened, a guest ticked in at the desk, or the card system. It '
                . 'is not a turnstile count.',
        ];
    }

    /** @param array<string,mixed> $w */
    private static function visitFrequency(array $w): array
    {
        $rows = DB::all(
            "SELECT CASE
                        WHEN visit_count = 1 THEN 'Once'
                        WHEN visit_count BETWEEN 2 AND 3 THEN '2 to 3 times'
                        WHEN visit_count BETWEEN 4 AND 9 THEN '4 to 9 times'
                        WHEN visit_count BETWEEN 10 AND 24 THEN '10 to 24 times'
                        ELSE '25 or more'
                    END AS band,
                    MIN(visit_count) AS sort_key,
                    COUNT(*) AS guests,
                    SUM(lifetime_value_cents) AS value,
                    ROUND(AVG(lifetime_value_cents)) AS average
               FROM {contacts}
              WHERE deleted_at IS NULL AND visit_count > 0
              GROUP BY band
              ORDER BY sort_key",
        );

        $totals = self::sumColumns($rows, ['guests', 'value']);
        $rows   = self::addShare($rows, 'guests', (int) $totals['guests'], 'share');

        foreach ($rows as $index => $row) {
            unset($rows[$index]['sort_key']);
        }

        return [
            'columns' => [
                ['key' => 'band', 'label' => 'Visits', 'type' => 'text'],
                ['key' => 'guests', 'label' => 'Guests', 'type' => 'int'],
                ['key' => 'share', 'label' => 'Share', 'type' => 'percent'],
                ['key' => 'value', 'label' => 'Lifetime spend', 'type' => 'money'],
                ['key' => 'average', 'label' => 'Each', 'type' => 'money'],
            ],
            'rows'   => $rows,
            'totals' => $totals,
            'note'   => 'EVERY GUEST ON RECORD, not just this window — a distribution is about '
                . 'the guest base rather than about a period. Guests with no recorded visit are '
                . 'left out entirely.',
        ];
    }

    /** @param array<string,mixed> $w */
    private static function topGuests(array $w): array
    {
        $rows = DB::all(
            "SELECT CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, '')) AS guest,
                    c.email, c.visit_count AS visits,
                    c.lifetime_value_cents AS value,
                    c.avg_spend_cents AS average,
                    " . LocalDay::expr('c.last_visit_at') . " AS last_visit
               FROM {contacts} c
              WHERE c.deleted_at IS NULL AND c.lifetime_value_cents > 0
              ORDER BY c.lifetime_value_cents DESC
              LIMIT 100"
        );

        foreach ($rows as $index => $row) {
            $rows[$index]['guest'] = trim((string) $row['guest']) ?: 'Unnamed';
        }

        return [
            'columns' => [
                ['key' => 'guest', 'label' => 'Guest', 'type' => 'text'],
                ['key' => 'email', 'label' => 'Email', 'type' => 'text'],
                ['key' => 'visits', 'label' => 'Visits', 'type' => 'int'],
                ['key' => 'value', 'label' => 'Lifetime spend', 'type' => 'money'],
                ['key' => 'average', 'label' => 'Average visit', 'type' => 'money'],
                ['key' => 'last_visit', 'label' => 'Last in', 'type' => 'date'],
            ],
            'rows'   => $rows,
            'totals' => self::sumColumns($rows, ['visits', 'value']),
            'note'   => 'The hundred highest-spending guests on record, whatever the window. '
                . 'Lifetime spend is derived from their visits, so a corrected visit corrects '
                . 'this too.',
        ];
    }

    /** @param array<string,mixed> $w */
    private static function lapsedGuests(array $w): array
    {
        $rows = DB::all(
            "SELECT CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, '')) AS guest,
                    c.email, c.phone, c.visit_count AS visits,
                    c.lifetime_value_cents AS value,
                    " . LocalDay::expr('c.last_visit_at') . " AS last_visit,
                    DATEDIFF(:today, " . LocalDay::expr('c.last_visit_at') . ") AS days_since
               FROM {contacts} c
              WHERE c.deleted_at IS NULL
                AND c.last_visit_at IS NOT NULL
                AND c.visit_count >= 2
                AND " . self::inWindow('c.last_visit_at') . "
              ORDER BY c.lifetime_value_cents DESC
              LIMIT " . self::ROW_LIMIT,
            self::windowParams($w) + ['today' => self::today()]
        );

        foreach ($rows as $index => $row) {
            $rows[$index]['guest'] = trim((string) $row['guest']) ?: 'Unnamed';
        }

        return [
            'columns' => [
                ['key' => 'guest', 'label' => 'Guest', 'type' => 'text'],
                ['key' => 'email', 'label' => 'Email', 'type' => 'text'],
                ['key' => 'phone', 'label' => 'Phone', 'type' => 'text'],
                ['key' => 'visits', 'label' => 'Visits', 'type' => 'int'],
                ['key' => 'value', 'label' => 'Lifetime spend', 'type' => 'money'],
                ['key' => 'last_visit', 'label' => 'Last in', 'type' => 'date'],
                ['key' => 'days_since', 'label' => 'Days since', 'type' => 'int'],
            ],
            'rows'   => $rows,
            'totals' => self::sumColumns($rows, ['visits', 'value']),
            'note'   => 'Guests whose LAST visit falls in the window and who had been more than '
                . 'once — so set the window to a few months ago to find the regulars who stopped '
                . 'coming then.',
        ];
    }

    // =================================================================
    // Operations
    // =================================================================

    /** @param array<string,mixed> $w */
    private static function bookingsByWeekday(array $w): array
    {
        $rows = DB::all(
            "SELECT DAYOFWEEK(b.event_date) AS dow,
                    COUNT(*) AS bookings,
                    SUM(b.guest_count) AS guests,
                    SUM(b.total_cents) AS revenue,
                    COUNT(DISTINCT b.event_date) AS days_used
               FROM {bookings} b
              WHERE b.deleted_at IS NULL
                AND b.status NOT IN ('cancelled', 'no_show', 'inquiry')
                AND b.event_date BETWEEN :from AND :to
              GROUP BY DAYOFWEEK(b.event_date)
              ORDER BY dow",
            ['from' => $w['from'], 'to' => $w['to']]
        );

        // MySQL's DAYOFWEEK is 1 = Sunday. Written out rather than formatted
        // from a date, because there is no date here to format.
        $names = [1 => 'Sunday', 2 => 'Monday', 3 => 'Tuesday', 4 => 'Wednesday',
                  5 => 'Thursday', 6 => 'Friday', 7 => 'Saturday'];

        foreach ($rows as $index => $row) {
            $rows[$index]['weekday'] = $names[(int) $row['dow']] ?? '?';
            unset($rows[$index]['dow']);
        }

        $totals = self::sumColumns($rows, ['bookings', 'guests', 'revenue']);
        $rows   = self::addShare($rows, 'bookings', (int) $totals['bookings'], 'share');

        return [
            'columns' => [
                ['key' => 'weekday', 'label' => 'Day', 'type' => 'text'],
                ['key' => 'bookings', 'label' => 'Parties', 'type' => 'int'],
                ['key' => 'share', 'label' => 'Share', 'type' => 'percent'],
                ['key' => 'guests', 'label' => 'Guests', 'type' => 'int'],
                ['key' => 'days_used', 'label' => 'Dates used', 'type' => 'int'],
                ['key' => 'revenue', 'label' => 'Booked', 'type' => 'money'],
            ],
            'rows'   => $rows,
            'totals' => $totals,
            'note'   => '"Dates used" is how many different Saturdays (or Tuesdays) in the window '
                . 'had a party at all — the figure that says whether a quiet weekday is quiet '
                . 'every week or just once.',
        ];
    }

    /** @param array<string,mixed> $w */
    private static function leadTime(array $w): array
    {
        // How much notice the venue got: the party's date less the venue day
        // the booking was typed in on. Named once because it appears seven
        // times and the bands have to be cut on exactly the same expression.
        $notice = 'DATEDIFF(b.event_date, ' . LocalDay::expr('b.created_at') . ')';

        $rows = DB::all(
            "SELECT CASE
                        WHEN " . $notice . " < 0 THEN 'Booked after the event'
                        WHEN " . $notice . " <= 3 THEN 'Three days or less'
                        WHEN " . $notice . " <= 7 THEN 'Within a week'
                        WHEN " . $notice . " <= 30 THEN 'One to four weeks'
                        WHEN " . $notice . " <= 90 THEN 'One to three months'
                        ELSE 'More than three months'
                    END AS band,
                    MIN(" . $notice . ") AS sort_key,
                    COUNT(*) AS bookings,
                    SUM(b.guest_count) AS guests
               FROM {bookings} b
              WHERE b.deleted_at IS NULL
                AND b.status NOT IN ('cancelled', 'no_show', 'inquiry')
                AND b.event_date BETWEEN :from AND :to
              GROUP BY band
              ORDER BY sort_key",
            ['from' => $w['from'], 'to' => $w['to']]
        );

        $totals = self::sumColumns($rows, ['bookings', 'guests']);
        $rows   = self::addShare($rows, 'bookings', (int) $totals['bookings'], 'share');

        foreach ($rows as $index => $row) {
            unset($rows[$index]['sort_key']);
        }

        return [
            'columns' => [
                ['key' => 'band', 'label' => 'Notice given', 'type' => 'text'],
                ['key' => 'bookings', 'label' => 'Parties', 'type' => 'int'],
                ['key' => 'share', 'label' => 'Share', 'type' => 'percent'],
                ['key' => 'guests', 'label' => 'Guests', 'type' => 'int'],
            ],
            'rows'   => $rows,
            'totals' => $totals,
            'note'   => '"Booked after the event" is a party typed in later — a walk-in written '
                . 'up on the Monday. It is not an error, but it is worth knowing how many there '
                . 'are before reading the rest.',
        ];
    }

    /** @param array<string,mixed> $w */
    private static function waiverCompletion(array $w): array
    {
        $rows = DB::all(
            "SELECT b.booking_number, b.event_date,
                    CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, '')) AS guest,
                    b.guest_count AS expected,
                    (SELECT COUNT(*) FROM {booking_guests} g WHERE g.booking_id = b.id) AS on_list,
                    (SELECT COUNT(*) FROM {waiver_signatures} s WHERE s.booking_id = b.id) AS signed
               FROM {bookings} b
               LEFT JOIN {contacts} c ON c.id = b.contact_id
              WHERE b.deleted_at IS NULL
                AND b.status NOT IN ('cancelled', 'inquiry')
                AND b.event_date BETWEEN :from AND :to
              ORDER BY b.event_date
              LIMIT " . self::ROW_LIMIT,
            ['from' => $w['from'], 'to' => $w['to']]
        );

        foreach ($rows as $index => $row) {
            $rows[$index]['guest'] = trim((string) $row['guest']) ?: 'No guest on the booking';

            // Against the HEADCOUNT, which is what the venue promised to
            // host — not against the guest list, which is usually shorter and
            // would flatter the figure.
            $rows[$index]['covered'] = Reports::share((int) $row['signed'], (int) $row['expected']);
        }

        $totals = self::sumColumns($rows, ['expected', 'on_list', 'signed']);
        $totals['covered'] = Reports::share((int) $totals['signed'], (int) $totals['expected']);

        return [
            'columns' => [
                ['key' => 'event_date', 'label' => 'Party', 'type' => 'date'],
                ['key' => 'booking_number', 'label' => 'Booking', 'type' => 'text'],
                ['key' => 'guest', 'label' => 'Guest', 'type' => 'text'],
                ['key' => 'expected', 'label' => 'Headcount', 'type' => 'int'],
                ['key' => 'on_list', 'label' => 'On the list', 'type' => 'int'],
                ['key' => 'signed', 'label' => 'Waivers signed', 'type' => 'int'],
                ['key' => 'covered', 'label' => 'Covered', 'type' => 'percent'],
            ],
            'rows'   => $rows,
            'totals' => $totals,
            'note'   => 'Measured against the headcount the booking promised, not against the '
                . 'guest list — a party with four names typed in and four waivers is not 100% '
                . 'covered if twelve children are coming.',
        ];
    }

    /** @param array<string,mixed> $w */
    private static function cancellations(array $w): array
    {
        $rows = DB::all(
            "SELECT b.status,
                    COUNT(*) AS bookings,
                    SUM(b.total_cents) AS value,
                    SUM(b.deposit_cents) AS deposits_held,
                    ROUND(AVG(DATEDIFF(b.event_date,
                        " . self::localDayOf('b.cancelled_at', 'b.updated_at') . ")))
                        AS days_notice
               FROM {bookings} b
              WHERE b.deleted_at IS NULL
                AND b.status IN ('cancelled', 'no_show')
                AND b.event_date BETWEEN :from AND :to
              GROUP BY b.status",
            ['from' => $w['from'], 'to' => $w['to']]
        );

        $kept = (int) DB::value(
            "SELECT COUNT(*) FROM {bookings}
              WHERE deleted_at IS NULL AND status NOT IN ('cancelled', 'no_show', 'inquiry')
                AND event_date BETWEEN :from AND :to",
            ['from' => $w['from'], 'to' => $w['to']]
        );

        foreach ($rows as $index => $row) {
            $rows[$index]['status'] = (string) $row['status'] === 'no_show' ? 'No-show' : 'Cancelled';
        }

        $totals = self::sumColumns($rows, ['bookings', 'value', 'deposits_held']);
        $totals['rate'] = Reports::share((int) $totals['bookings'], (int) $totals['bookings'] + $kept);

        $priorLost = (int) DB::value(
            "SELECT COUNT(*) FROM {bookings}
              WHERE deleted_at IS NULL AND status IN ('cancelled', 'no_show')
                AND event_date BETWEEN :prior_from AND :prior_to",
            ['prior_from' => $w['prior_from'], 'prior_to' => $w['prior_to']]
        );

        return [
            'columns' => [
                ['key' => 'status', 'label' => 'What happened', 'type' => 'text'],
                ['key' => 'bookings', 'label' => 'Parties', 'type' => 'int'],
                ['key' => 'days_notice', 'label' => 'Average notice (days)', 'type' => 'int'],
                ['key' => 'value', 'label' => 'Value lost', 'type' => 'money'],
                ['key' => 'deposits_held', 'label' => 'Deposits held', 'type' => 'money'],
            ],
            'rows'       => $rows,
            'totals'     => $totals,
            'comparison' => [
                'label'  => 'lost in the ' . (int) $w['days'] . ' days before',
                'now'    => (int) $totals['bookings'],
                'before' => $priorLost,
                'type'   => 'int',
                // MORE cancellations is worse. Without this the figure renders
                // green, which is the colour saying "well done" under a rise in
                // parties the venue lost.
                'good'   => 'down',
                'delta'  => Reports::delta((int) $totals['bookings'], $priorLost),
            ],
            'note' => $totals['rate'] === null
                ? 'Nothing was booked in this window, so there is no rate to report.'
                : $totals['rate'] . '% of everything booked for this window fell through. '
                    . '"Value lost" is what those parties were worth, not what the venue is out '
                    . 'of pocket — deposits held are shown separately.',
        ];
    }

    // =================================================================
    // Reputation
    // =================================================================

    /** @param array<string,mixed> $w */
    private static function npsTrend(array $w): array
    {
        $answeredOn = LocalDay::expr('r.responded_at');

        $rows = DB::all(
            "SELECT " . $answeredOn . " AS day,
                    COUNT(*) AS answers,
                    SUM(r.sentiment = 'promoter') AS promoters,
                    SUM(r.sentiment = 'passive') AS passives,
                    SUM(r.sentiment = 'detractor') AS detractors,
                    ROUND(AVG(r.score), 1) AS average
               FROM {survey_responses} r
              WHERE r.responded_at IS NOT NULL
                AND " . self::inWindow('r.responded_at') . "
              GROUP BY " . $answeredOn . "
              ORDER BY day",
            self::windowParams($w)
        );

        foreach ($rows as $index => $row) {
            $rows[$index]['nps'] = Reputation::nps(
                (int) $row['promoters'],
                (int) $row['passives'],
                (int) $row['detractors']
            );
        }

        $totals = self::sumColumns($rows, ['answers', 'promoters', 'passives', 'detractors']);
        $totals['nps'] = Reputation::nps(
            (int) $totals['promoters'],
            (int) $totals['passives'],
            (int) $totals['detractors']
        );

        $priorBands = DB::pairs(
            "SELECT sentiment, COUNT(*) FROM {survey_responses}
              WHERE responded_at IS NOT NULL AND sentiment IS NOT NULL
                AND " . self::inPrior('responded_at') . "
              GROUP BY sentiment",
            self::priorParams($w)
        );

        $priorNps = Reputation::nps(
            (int) ($priorBands['promoter'] ?? 0),
            (int) ($priorBands['passive'] ?? 0),
            (int) ($priorBands['detractor'] ?? 0)
        );

        return [
            'columns' => [
                ['key' => 'day', 'label' => 'Day', 'type' => 'date'],
                ['key' => 'answers', 'label' => 'Answers', 'type' => 'int'],
                ['key' => 'promoters', 'label' => 'Happy', 'type' => 'int'],
                ['key' => 'passives', 'label' => 'Lukewarm', 'type' => 'int'],
                ['key' => 'detractors', 'label' => 'Unhappy', 'type' => 'int'],
                ['key' => 'average', 'label' => 'Average score', 'type' => 'text'],
                ['key' => 'nps', 'label' => 'Score', 'type' => 'text'],
            ],
            'rows'       => $rows,
            'totals'     => $totals,
            'comparison' => [
                'label'  => 'the ' . (int) $w['days'] . ' days before',
                'now'    => $totals['nps'],
                'before' => $priorNps,
                'type'   => 'number',
                'good'   => 'up',
                'delta'  => Reports::delta($totals['nps'], $priorNps),
            ],
            'note' => 'Counted on the day the guest ANSWERED, not the day of their visit. A day '
                . 'with one answer has a score of 100 or -100 and means very little; read the '
                . 'total rather than the rows.',
        ];
    }

    /** @param array<string,mixed> $w */
    private static function feedbackByHost(array $w): array
    {
        $rows = DB::all(
            "SELECT COALESCE(u.name, 'Not recorded') AS host,
                    COUNT(*) AS answers,
                    SUM(r.sentiment = 'promoter') AS promoters,
                    SUM(r.sentiment = 'passive') AS passives,
                    SUM(r.sentiment = 'detractor') AS detractors,
                    ROUND(AVG(r.score), 1) AS average
               FROM {survey_responses} r
               LEFT JOIN {users} u ON u.id = r.host_user_id
              WHERE r.responded_at IS NOT NULL
                AND " . self::inWindow('r.responded_at') . "
              GROUP BY r.host_user_id, u.name
              ORDER BY average DESC, answers DESC",
            self::windowParams($w)
        );

        foreach ($rows as $index => $row) {
            $rows[$index]['nps'] = Reputation::nps(
                (int) $row['promoters'],
                (int) $row['passives'],
                (int) $row['detractors']
            );
        }

        return [
            'columns' => [
                ['key' => 'host', 'label' => 'Host', 'type' => 'text'],
                ['key' => 'answers', 'label' => 'Answers', 'type' => 'int'],
                ['key' => 'promoters', 'label' => 'Happy', 'type' => 'int'],
                ['key' => 'detractors', 'label' => 'Unhappy', 'type' => 'int'],
                ['key' => 'average', 'label' => 'Average score', 'type' => 'text'],
                ['key' => 'nps', 'label' => 'Score', 'type' => 'text'],
            ],
            'rows'   => $rows,
            'totals' => self::sumColumns($rows, ['answers', 'promoters', 'detractors']),
            'note'   => 'A host with two or three answers has a score that means almost nothing — '
                . 'read the count beside it before reading the score, and do not put anybody on '
                . 'a performance plan over five parties.',
        ];
    }

    /** @param array<string,mixed> $w */
    private static function reviewVelocity(array $w): array
    {
        $rows = DB::all(
            "SELECT r.platform,
                    COUNT(*) AS reviews,
                    ROUND(AVG(r.rating), 2) AS average,
                    SUM(r.rating >= 4) AS good,
                    SUM(r.rating <= 2) AS poor,
                    SUM(r.replied_at IS NOT NULL) AS replied
               FROM {reviews} r
              WHERE " . self::localDayOf('r.posted_at', 'r.imported_at') . " BETWEEN :from AND :to
              GROUP BY r.platform
              ORDER BY reviews DESC",
            ['from' => $w['from'], 'to' => $w['to']]
        );

        foreach ($rows as $index => $row) {
            $rows[$index]['reply_rate'] = Reports::share((int) $row['replied'], (int) $row['reviews']);
            $rows[$index]['platform']   = Reputation::platformName((string) $row['platform']);
        }

        $totals = self::sumColumns($rows, ['reviews', 'good', 'poor', 'replied']);
        $totals['reply_rate'] = Reports::share((int) $totals['replied'], (int) $totals['reviews']);

        $prior = (int) DB::value(
            "SELECT COUNT(*) FROM {reviews}
              WHERE " . self::localDayOf('posted_at', 'imported_at')
                . " BETWEEN :prior_from AND :prior_to",
            ['prior_from' => $w['prior_from'], 'prior_to' => $w['prior_to']]
        );

        return [
            'columns' => [
                ['key' => 'platform', 'label' => 'Platform', 'type' => 'text'],
                ['key' => 'reviews', 'label' => 'Reviews', 'type' => 'int'],
                ['key' => 'average', 'label' => 'Average stars', 'type' => 'text'],
                ['key' => 'good', 'label' => 'Four or five', 'type' => 'int'],
                ['key' => 'poor', 'label' => 'One or two', 'type' => 'int'],
                ['key' => 'replied', 'label' => 'Answered', 'type' => 'int'],
                ['key' => 'reply_rate', 'label' => 'Answer rate', 'type' => 'percent'],
            ],
            'rows'       => $rows,
            'totals'     => $totals,
            'comparison' => [
                'label'  => 'reviews in the ' . (int) $w['days'] . ' days before',
                'now'    => (int) $totals['reviews'],
                'before' => $prior,
                'type'   => 'int',
                'good'   => 'up',
                'delta'  => Reports::delta((int) $totals['reviews'], $prior),
            ],
            'note' => 'Counted by the date a review was POSTED where the import recorded one, '
                . 'and by the date it was imported otherwise — so a first import of several '
                . 'years of history lands on one day unless the file carried dates.',
        ];
    }

    // =================================================================
    // Plumbing
    // =================================================================

    /**
     * Sum a set of columns across the rows.
     *
     * @param list<array<string,mixed>> $rows
     * @param list<string> $keys
     * @return array<string,int>
     */
    private static function sumColumns(array $rows, array $keys): array
    {
        $totals = array_fill_keys($keys, 0);

        foreach ($rows as $row) {
            foreach ($keys as $key) {
                $totals[$key] += (int) ($row[$key] ?? 0);
            }
        }

        return $totals;
    }

    /**
     * Add a share-of-total column.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function addShare(array $rows, string $key, int $total, string $into): array
    {
        foreach ($rows as $index => $row) {
            $rows[$index][$into] = Reports::share((int) ($row[$key] ?? 0), $total);
        }

        return $rows;
    }

    /**
     * Today, in the venue's calendar.
     *
     * Bound as a parameter rather than written as `UTC_DATE()`, which is the
     * SERVER'S today and is already tomorrow by early evening — so a party
     * this coming Saturday reported as five days away all week and four days
     * away after eight o'clock each night.
     */
    private static function today(): string
    {
        return (new DateTimeImmutable('now', venue_tz()))->format('Y-m-d');
    }

    /**
     * Every day in a window, so a trend has a row for the quiet days too.
     *
     * A report that only lists the days something happened makes a gap look
     * like a missing row rather than a day with nothing on it.
     *
     * @return list<string>
     */
    private static function eachDay(string $from, string $to): array
    {
        $days  = [];
        $start = strtotime($from . ' 00:00:00 UTC');
        $end   = strtotime($to . ' 00:00:00 UTC');

        if ($start === false || $end === false || $end < $start) {
            return [];
        }

        for ($day = $start; $day <= $end; $day += 86400) {
            $days[] = gmdate('Y-m-d', $day);
        }

        return $days;
    }
}
