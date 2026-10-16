<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The report catalogue and the window arithmetic — the pure half.
 *
 * What a report IS lives here: its family, its name, the question it
 * answers, the columns it returns and whether it carries money. `ReportRunner`
 * holds the queries; `ReportController` renders. Nothing here touches a
 * database or a clock — "today" is always passed in, because every report is
 * a claim about the VENUE'S calendar and the server's is four or five hours
 * ahead of it.
 *
 * ACTUALS ONLY. The standing product rule for this application: every number
 * a report shows is something that happened. No projections, no run rates, no
 * "typical day" baselines, no forecast of where the month will end. A venue
 * acting on a projected figure is acting on a number nobody can check, and
 * the moment one appears beside the real ones nobody can tell which is which.
 * Comparisons against the PREVIOUS period are not projections — both sides
 * happened — and they are the one piece of context every report carries.
 */
final class Reports
{
    /** The six families from spec §8.14, in the order the page lists them. */
    public const FAMILIES = [
        'revenue'    => 'Revenue',
        'sales'      => 'Sales',
        'marketing'  => 'Marketing',
        'guest'      => 'Guests',
        'operations' => 'Operations',
        'reputation' => 'Reputation',
    ];

    /**
     * Every report, and what it is for.
     *
     * `money` marks a report whose columns include cash — those are scrubbed
     * for a role without `view_revenue` rather than hidden, so a technician
     * can still read the counts beside them. `prior` marks a report that can
     * be compared with the period before it.
     *
     * @var array<string,array{family:string,name:string,question:string,money:bool,prior:bool}>
     */
    public const CATALOG = [
        // --- Revenue ----------------------------------------------------
        'revenue_by_day' => [
            'family'   => 'revenue',
            'name'     => 'Revenue by day',
            'question' => 'What did we take, and on which days?',
            'money'    => true,
            'prior'    => true,
        ],
        'revenue_by_package' => [
            'family'   => 'revenue',
            'name'     => 'Revenue by package',
            'question' => 'Which party packages are actually selling?',
            'money'    => true,
            'prior'    => false,
        ],
        'revenue_by_type' => [
            'family'   => 'revenue',
            'name'     => 'Revenue by booking type',
            'question' => 'Birthdays, groups, corporate — where does the money come from?',
            'money'    => true,
            'prior'    => false,
        ],
        'deposits_outstanding' => [
            'family'   => 'revenue',
            'name'     => 'Money still owed',
            'question' => 'Which booked parties have not paid, and how close are they?',
            'money'    => true,
            'prior'    => false,
        ],
        'membership_revenue' => [
            'family'   => 'revenue',
            'name'     => 'Membership income',
            'question' => 'What did passes bring in, and from which plans?',
            'money'    => true,
            'prior'    => false,
        ],

        // --- Sales ------------------------------------------------------
        'pipeline_funnel' => [
            'family'   => 'sales',
            'name'     => 'Pipeline funnel',
            'question' => 'Where do enquiries stop?',
            'money'    => true,
            'prior'    => false,
        ],
        'win_loss' => [
            'family'   => 'sales',
            'name'     => 'Won and lost',
            'question' => 'What are we winning, and why do we lose the rest?',
            'money'    => true,
            'prior'    => true,
        ],
        'rep_performance' => [
            'family'   => 'sales',
            'name'     => 'By owner',
            'question' => 'Who is working which deals, and how are they doing?',
            'money'    => true,
            'prior'    => false,
        ],
        'lead_sources' => [
            'family'   => 'sales',
            'name'     => 'Where leads come from',
            'question' => 'Which sources bring enquiries, and which bring bookings?',
            'money'    => true,
            'prior'    => false,
        ],

        // --- Marketing --------------------------------------------------
        'campaign_performance' => [
            'family'   => 'marketing',
            'name'     => 'Campaigns',
            'question' => 'What was sent, what arrived, and what did it cost?',
            'money'    => true,
            'prior'    => false,
        ],
        'list_growth' => [
            'family'   => 'marketing',
            'name'     => 'List growth',
            'question' => 'Is the list growing, and how fast are people leaving it?',
            'money'    => false,
            'prior'    => true,
        ],
        'message_volume' => [
            'family'   => 'marketing',
            'name'     => 'Messages sent',
            'question' => 'How much are we messaging guests, by channel and by day?',
            'money'    => true,
            'prior'    => true,
        ],

        // --- Guests -----------------------------------------------------
        'new_vs_returning' => [
            'family'   => 'guest',
            'name'     => 'New and returning',
            'question' => 'How many guests are new, and how many came back?',
            // It carries a spend column, so it IS a money report. This read
            // false until a test compared the catalogue against the columns
            // each query really returns: `money` is what decides whether the
            // figures are scrubbed for a role without `view_revenue`, so a
            // report marked false with a money column in it hands guest spend
            // to a technician.
            'money'    => true,
            'prior'    => true,
        ],
        'visit_frequency' => [
            'family'   => 'guest',
            'name'     => 'How often guests come',
            'question' => 'How is the guest base distributed — once, twice, every week?',
            'money'    => true,
            'prior'    => false,
        ],
        'top_guests' => [
            'family'   => 'guest',
            'name'     => 'Best guests',
            'question' => 'Who spends the most, and when were they last in?',
            'money'    => true,
            'prior'    => false,
        ],
        'lapsed_guests' => [
            'family'   => 'guest',
            'name'     => 'Guests who have drifted',
            'question' => 'Who used to come and has not been for a while?',
            'money'    => true,
            'prior'    => false,
        ],

        // --- Operations -------------------------------------------------
        'bookings_by_weekday' => [
            'family'   => 'operations',
            'name'     => 'Parties by day of the week',
            'question' => 'When are we busy, and when is the building empty?',
            'money'    => true,
            'prior'    => false,
        ],
        'lead_time' => [
            'family'   => 'operations',
            'name'     => 'How far ahead people book',
            'question' => 'How much notice do we actually get?',
            'money'    => false,
            'prior'    => false,
        ],
        'waiver_completion' => [
            'family'   => 'operations',
            'name'     => 'Waivers signed',
            'question' => 'Which parties arrive with waivers already done?',
            'money'    => false,
            'prior'    => false,
        ],
        'cancellations' => [
            'family'   => 'operations',
            'name'     => 'Cancellations and no-shows',
            'question' => 'How much of the diary falls through, and when?',
            'money'    => true,
            'prior'    => true,
        ],

        // --- Reputation -------------------------------------------------
        'nps_trend' => [
            'family'   => 'reputation',
            'name'     => 'Score over time',
            'question' => 'Is the score moving, and in which direction?',
            'money'    => false,
            'prior'    => true,
        ],
        'feedback_by_host' => [
            'family'   => 'reputation',
            'name'     => 'Scores by party host',
            'question' => 'Who are guests happiest with?',
            'money'    => false,
            'prior'    => false,
        ],
        'review_velocity' => [
            'family'   => 'reputation',
            'name'     => 'Reviews arriving',
            'question' => 'How many public reviews, at what rating, and are we answering them?',
            'money'    => false,
            'prior'    => true,
        ],
    ];

    /**
     * The date ranges the page offers.
     *
     * Each is a venue-local span. `custom` is the escape hatch and takes the
     * two dates from the request.
     *
     * @var array<string,string>
     */
    public const PRESETS = [
        'last_7'      => 'Last 7 days',
        'last_30'     => 'Last 30 days',
        'last_90'     => 'Last 90 days',
        'this_month'  => 'This month',
        'last_month'  => 'Last month',
        'this_year'   => 'This year',
        'last_year'   => 'Last year',
        'custom'      => 'Custom…',
    ];

    /** A span longer than this is refused: a report is not an export. */
    public const MAX_DAYS = 1100;

    // ------------------------------------------------------------------
    // The window
    // ------------------------------------------------------------------

    /**
     * Resolve a preset (or a custom pair) into a window, with the period
     * before it for comparison.
     *
     * The PRIOR window is always the same NUMBER OF DAYS immediately before,
     * never "the same month last year" — so a 30-day window compares with the
     * 30 days before it and a reader does not have to work out which. Where a
     * year-on-year comparison is the honest one (a seasonal venue), that is a
     * different report rather than a different meaning for the same column.
     *
     * @return array{preset:string,from:string,to:string,days:int,label:string,
     *     prior_from:string,prior_to:string,clamped:bool}
     */
    public static function window(string $preset, ?string $from, ?string $to, string $today): array
    {
        $preset = isset(self::PRESETS[$preset]) ? $preset : 'last_30';

        $start = new DateTimeImmutable($today . ' 00:00:00', new DateTimeZone('UTC'));

        [$fromDate, $toDate] = match ($preset) {
            'last_7'     => [$start->modify('-6 days'), $start],
            'last_90'    => [$start->modify('-89 days'), $start],
            'this_month' => [$start->modify('first day of this month'), $start],
            'last_month' => [
                $start->modify('first day of last month'),
                $start->modify('last day of last month'),
            ],
            'this_year'  => [$start->modify('first day of January'), $start],
            'last_year'  => [
                $start->modify('first day of January last year'),
                $start->modify('last day of December last year'),
            ],
            'custom'     => self::customDates($from, $to, $start),
            default      => [$start->modify('-29 days'), $start],
        };

        if ($fromDate > $toDate) {
            [$fromDate, $toDate] = [$toDate, $fromDate];
        }

        $days    = (int) $fromDate->diff($toDate)->days + 1;
        $clamped = false;

        if ($days > self::MAX_DAYS) {
            // Clamped rather than refused, and it SAYS so: a venue asking for
            // twenty years of bookings wants a report, and what they would
            // get is a timeout.
            $fromDate = $toDate->modify('-' . (self::MAX_DAYS - 1) . ' days');
            $days     = self::MAX_DAYS;
            $clamped  = true;
        }

        $priorTo   = $fromDate->modify('-1 day');
        $priorFrom = $priorTo->modify('-' . ($days - 1) . ' days');

        return [
            'preset'     => $preset,
            'from'       => $fromDate->format('Y-m-d'),
            'to'         => $toDate->format('Y-m-d'),
            'days'       => $days,
            'label'      => self::label($preset, $fromDate, $toDate),
            'prior_from' => $priorFrom->format('Y-m-d'),
            'prior_to'   => $priorTo->format('Y-m-d'),
            'clamped'    => $clamped,
        ];
    }

    /**
     * @return array{0:DateTimeImmutable,1:DateTimeImmutable}
     */
    private static function customDates(?string $from, ?string $to, DateTimeImmutable $today): array
    {
        $parse = static function (?string $value) use ($today): ?DateTimeImmutable {
            if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
                return null;
            }

            try {
                return new DateTimeImmutable($value . ' 00:00:00', new DateTimeZone('UTC'));
            } catch (\Exception) {
                return null;
            }
        };

        $start = $parse($from) ?? $today->modify('-29 days');
        $end   = $parse($to) ?? $today;

        return [$start, $end];
    }

    private static function label(string $preset, DateTimeImmutable $from, DateTimeImmutable $to): string
    {
        if ($preset !== 'custom' && isset(self::PRESETS[$preset])) {
            return self::PRESETS[$preset];
        }

        return $from->format('M j, Y') . ' – ' . $to->format('M j, Y');
    }

    // ------------------------------------------------------------------
    // Comparing
    // ------------------------------------------------------------------

    /**
     * This period against the one before it.
     *
     * Null when there is nothing to compare with — NOT a 100% rise. A report
     * for a venue's first month must not claim everything grew infinitely,
     * and "no prior" is a fact a reader can act on where "+100%" is not.
     *
     * @return array{change:float,percent:?float,direction:string}|null
     */
    public static function delta(float|int|null $now, float|int|null $before): ?array
    {
        if ($now === null || $before === null) {
            return null;
        }

        $change = (float) $now - (float) $before;

        return [
            'change'    => $change,
            // A rise from nothing has no percentage: dividing by zero is
            // where "+∞%" comes from, and a venue reads that as a bug.
            'percent'   => (float) $before == 0.0 ? null : round(($change / abs((float) $before)) * 100, 1),
            'direction' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'flat'),
        ];
    }

    /** One figure as a percentage of another, or null when there is no base. */
    public static function share(float|int $part, float|int $whole): ?float
    {
        if ((float) $whole == 0.0) {
            return null;
        }

        return round(((float) $part / (float) $whole) * 100, 1);
    }

    // ------------------------------------------------------------------
    // Shaping
    // ------------------------------------------------------------------

    /**
     * Strip the money out of a report for a role that may not see it.
     *
     * The columns stay — with their values nulled — rather than being
     * removed, because a technician reading "42 parties" wants the row to
     * line up with what a manager sees when they talk about it. The caller
     * tells the reader the figures are hidden rather than zero.
     *
     * @param array{columns:list<array<string,mixed>>,rows:list<array<string,mixed>>,totals:array<string,mixed>} $report
     * @return array{columns:list<array<string,mixed>>,rows:list<array<string,mixed>>,totals:array<string,mixed>}
     */
    public static function scrubMoney(array $report): array
    {
        $moneyKeys = [];

        foreach ($report['columns'] as $column) {
            if (($column['type'] ?? '') === 'money') {
                $moneyKeys[] = (string) $column['key'];
            }
        }

        if ($moneyKeys === []) {
            return $report;
        }

        foreach ($report['rows'] as $index => $row) {
            foreach ($moneyKeys as $key) {
                if (array_key_exists($key, $row)) {
                    $report['rows'][$index][$key] = null;
                }
            }
        }

        foreach ($moneyKeys as $key) {
            if (array_key_exists($key, $report['totals'])) {
                $report['totals'][$key] = null;
            }
        }

        return $report;
    }

    /**
     * A report's rows as CSV rows, in the order its columns declare.
     *
     * Values are passed through as-is: `Response::csv()` neutralises
     * spreadsheet formulas, and formatting money as "$1,234.00" here would
     * make the column unusable in the spreadsheet the venue is exporting it
     * into. Cents become dollars because a column of 123400 is nobody's idea
     * of money.
     *
     * @param list<array<string,mixed>> $columns
     * @param list<array<string,mixed>> $rows
     * @return list<list<mixed>>
     */
    public static function csvRows(array $columns, array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $line = [];

            foreach ($columns as $column) {
                $key   = (string) $column['key'];
                $value = $row[$key] ?? null;

                $line[] = match ($column['type'] ?? 'text') {
                    'money'   => $value === null ? '' : number_format((int) $value / 100, 2, '.', ''),
                    'percent' => $value === null ? '' : $value,
                    'int'     => $value === null ? '' : (int) $value,
                    default   => $value === null ? '' : (string) $value,
                };
            }

            $out[] = $line;
        }

        return $out;
    }

    /** @param list<array<string,mixed>> $columns */
    public static function csvHeader(array $columns): array
    {
        return array_map(static fn (array $column): string => (string) $column['label'], $columns);
    }

    /** A filename a person can find again in their downloads folder. */
    public static function filename(string $reportId, string $from, string $to): string
    {
        $name = self::CATALOG[$reportId]['name'] ?? $reportId;
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? $reportId);

        return trim($slug, '-') . '-' . $from . '-to-' . $to . '.csv';
    }

    // ------------------------------------------------------------------
    // Reading the catalogue
    // ------------------------------------------------------------------

    public static function exists(string $id): bool
    {
        return isset(self::CATALOG[$id]);
    }

    /** @return array<string,mixed>|null */
    public static function report(string $id): ?array
    {
        return self::CATALOG[$id] ?? null;
    }

    /**
     * The catalogue grouped by family, in the order the page shows them.
     *
     * @return array<string,list<array<string,mixed>>>
     */
    public static function byFamily(): array
    {
        $grouped = [];

        foreach (array_keys(self::FAMILIES) as $family) {
            $grouped[$family] = [];
        }

        foreach (self::CATALOG as $id => $report) {
            $grouped[$report['family']][] = $report + ['id' => $id];
        }

        return $grouped;
    }
}
