<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Log;
use App\Core\Settings;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * The nightly rollup: one row per venue day, per metric.
 *
 * Reports read the real rows, and at a single venue's volume that is both
 * faster to reason about and impossible to get out of step with the truth.
 * This exists for the one thing a live query cannot do — answer a question
 * about a day whose detail has since been pruned, and answer it in one
 * indexed read rather than a scan.
 *
 * Two properties are the whole design, and both were learned the hard way in
 * the sibling application this one is modelled on:
 *
 * **IT RECOMPUTES, IT DOES NOT ACCUMULATE.** Every run rewrites the trailing
 * window from the source tables with an UPSERT keyed on
 * (date, metric, dimension, dimension_value). So a run that happens twice,
 * or a run that crashed half way, or a booking corrected three days later,
 * all converge on the same answer. A rollup that adds to a counter cannot be
 * re-run, which means it cannot be repaired, which means the first wrong
 * night is wrong forever.
 *
 * **IT REACHES BACK TO WHERE IT STOPPED.** The trailing window is
 * `TRAILING_DAYS`, but if the newest stored day is further behind than that
 * — the venue's cron was off for a fortnight, or the host suspended the
 * account — the window extends to cover the gap, capped at `CATCHUP_DAYS`.
 * A fixed trailing window leaves a PERMANENT hole between where it stopped
 * and where it resumed, and no later run ever repairs it: the missing days
 * are not in the table to be noticed, so every figure spanning them is
 * quietly low.
 *
 * It is NOT a projection of any kind. Each row is a count or a sum of things
 * that happened on that day, which is the standing rule for every number in
 * this application.
 */
final class ReportRollup
{
    /** Recomputed every run, so a late correction lands. */
    public const TRAILING_DAYS = 14;

    /** The most a single run will reach back to repair. */
    public const CATCHUP_DAYS = 400;

    /**
     * How far the ONE-TIME backfill reaches, and the flag that stops it
     * running twice.
     *
     * The trailing window fills FORWARD, a day a night, for ever. It never
     * fills backward — so without this a venue that imported five years of
     * history would have a rollup covering the fortnight since they installed
     * the application, and every long-range figure would read as though the
     * venue opened last month. The flag carries a VERSION so that changing
     * what a metric means rebuilds the table on the next run rather than
     * being blocked by a stale "done".
     */
    public const BACKFILL_DAYS = 2000;

    public const BACKFILL_VERSION = 1;

    public const BACKFILL_FLAG = 'system.rollup_backfill_done';

    /** Today is never written: it is not over, so it is not an actual. */
    public const METRICS = [
        'bookings'           => 'Parties held',
        'booking_revenue'    => 'Booked, in cents',
        'booking_guests'     => 'Guests expected',
        'bookings_cancelled' => 'Parties lost',
        'deals_created'      => 'Enquiries taken',
        'deals_won'          => 'Enquiries won',
        'deals_won_value'    => 'Won, in cents',
        'contacts_added'     => 'Guests added to the list',
        'opt_outs'           => 'Guests who opted out',
        'messages_out'       => 'Messages sent',
        'messages_in'        => 'Messages received',
        'message_cost'       => 'Messaging spend, in cents',
        'visits'             => 'Visits',
        'visit_spend'        => 'Visit spend, in cents',
        'survey_answers'     => 'Feedback answers',
        'promoters'          => 'Happy answers',
        'detractors'         => 'Unhappy answers',
    ];

    /**
     * Rewrite the trailing window, reaching back to any gap.
     *
     * @param callable|null $budgetLeft Returns false when the tick is out of
     *     time. Checked between DAYS, so the worst case is one day's queries
     *     past the deadline rather than the whole window.
     * @return array{days:int,rows:int,from:string,to:string,stopped:bool}
     */
    public static function refresh(?callable $budgetLeft = null): array
    {
        $today     = self::today();
        $yesterday = (new DateTimeImmutable($today, new DateTimeZone('UTC')))
            ->modify('-1 day')->format('Y-m-d');

        $from = self::windowStart($yesterday);

        $rows    = 0;
        $days    = 0;
        $stopped = false;

        foreach (self::daysBetween($from, $yesterday) as $day) {
            if ($budgetLeft !== null && !$budgetLeft()) {
                // Said out loud rather than left to be inferred from a short
                // table: a venue whose rollup never finishes needs to know
                // that, not to discover it in a report.
                $stopped = true;
                break;
            }

            $rows += self::rebuildDay($day);
            $days++;
        }

        return [
            'days'    => $days,
            'rows'    => $rows,
            'from'    => $from,
            'to'      => $yesterday,
            'stopped' => $stopped,
        ];
    }

    /**
     * Build the whole history, once.
     *
     * Runs when the stored flag does not match `BACKFILL_VERSION`, so a
     * change to what a metric means rebuilds rather than being blocked by a
     * stale "done". It starts at the OLDEST thing the venue has a record of
     * — not at a fixed date — so an install with three weeks of data costs
     * three weeks of work and one with five years of imported history gets
     * five years of rollup.
     *
     * It is BUDGETED and RESUMABLE: the flag is only set once it reaches
     * yesterday, so a run that stops on the clock is picked up by the next
     * one from where it got to (the `windowStart()` reach-back does that
     * part). A shared host killing the tick must not leave a permanent hole.
     *
     * @param callable|null $budgetLeft Returns false when the tick is out of time.
     * @return array{ran:bool,reason:string,days:int,rows:int,from:string,to:string,complete:bool}
     */
    public static function backfill(?callable $budgetLeft = null): array
    {
        $done = (int) Settings::get(self::BACKFILL_FLAG, 0);

        if ($done >= self::BACKFILL_VERSION) {
            return ['ran' => false, 'reason' => 'already done', 'days' => 0, 'rows' => 0,
                    'from' => '', 'to' => '', 'complete' => true];
        }

        $today     = self::today();
        $yesterday = (new DateTimeImmutable($today, new DateTimeZone('UTC')))
            ->modify('-1 day')->format('Y-m-d');

        $oldest = self::oldestRecord();

        if ($oldest === null) {
            // Nothing to roll up at all. Mark it done: an empty install does
            // not need to re-ask this question every night, and the first real
            // booking is inside the trailing window anyway.
            Settings::set(self::BACKFILL_FLAG, self::BACKFILL_VERSION);

            return ['ran' => false, 'reason' => 'no history to roll up', 'days' => 0, 'rows' => 0,
                    'from' => '', 'to' => '', 'complete' => true];
        }

        $floor = (new DateTimeImmutable($yesterday, new DateTimeZone('UTC')))
            ->modify('-' . (self::BACKFILL_DAYS - 1) . ' days')->format('Y-m-d');

        $from = $oldest < $floor ? $floor : $oldest;

        /*
         * WHICH DAYS ARE STILL MISSING, not "where did I get to".
         *
         * This used to resume from `MAX(date)`, which is wrong and silently
         * so: the TRAILING refresh has already written the last fortnight, so
         * the newest row is yesterday before the backfill has done anything.
         * The backfill therefore started at today, found nothing to do, and
         * marked itself complete — leaving a year of history unrolled and a
         * flag saying it was finished. MEASURED on a database holding 400
         * days: "days: 0, complete: true", coverage still fourteen days.
         *
         * Asking which days are absent needs no marker, cannot be fooled by
         * another job writing rows, and makes the backfill resumable for
         * free.
         */
        $covered = DB::column(
            'SELECT DISTINCT `date` FROM {report_daily_rollup}
              WHERE `date` BETWEEN :from AND :to',
            ['from' => $from, 'to' => $yesterday]
        );

        $have = array_fill_keys(array_map('strval', $covered), true);

        $rows     = 0;
        $days     = 0;
        $complete = true;
        $lastDone = null;

        foreach (self::daysBetween($from, $yesterday) as $day) {
            if (isset($have[$day])) {
                continue;
            }

            if ($budgetLeft !== null && !$budgetLeft()) {
                $complete = false;
                break;
            }

            $rows += self::rebuildDay($day);
            $days++;
            $lastDone = $day;
        }

        if ($complete) {
            Settings::set(self::BACKFILL_FLAG, self::BACKFILL_VERSION);
        }

        return [
            'ran'      => true,
            'reason'   => $complete ? 'complete' : 'ran out of time, will resume',
            'days'     => $days,
            'rows'     => $rows,
            'from'     => $from,
            'to'       => $lastDone ?? $from,
            'complete' => $complete,
        ];
    }

    /**
     * The oldest day the venue has any record of.
     *
     * Asked of the tables the rollup reads, so the backfill covers exactly
     * what there is. A table missing on an older install is skipped rather
     * than failing the whole thing.
     */
    public static function oldestRecord(): ?string
    {
        $queries = [
            'SELECT MIN(event_date) FROM {bookings} WHERE deleted_at IS NULL',
            'SELECT MIN(visit_date) FROM {visits}',
            'SELECT MIN(' . LocalDay::expr('created_at') . ') FROM {deals} WHERE deleted_at IS NULL',
            'SELECT MIN(' . LocalDay::expr('created_at') . ') FROM {contacts} WHERE deleted_at IS NULL',
        ];

        $oldest = null;

        foreach ($queries as $sql) {
            try {
                $value = DB::value($sql);
            } catch (Throwable) {
                continue;
            }

            if (!is_string($value) || $value === '' || str_starts_with($value, '0000')) {
                continue;
            }

            if ($oldest === null || $value < $oldest) {
                $oldest = $value;
            }
        }

        return $oldest;
    }

    /**
     * Where this run should start.
     *
     * The trailing window normally, but further back when the newest stored
     * day is older than that — see the class note on permanent holes.
     */
    public static function windowStart(string $yesterday): string
    {
        $end   = new DateTimeImmutable($yesterday, new DateTimeZone('UTC'));
        $start = $end->modify('-' . (self::TRAILING_DAYS - 1) . ' days');

        $newest = DB::value('SELECT MAX(`date`) FROM {report_daily_rollup}');

        if (is_string($newest) && $newest !== '') {
            try {
                // The day after the newest one stored, since that is the
                // first day nobody has covered.
                $resume = (new DateTimeImmutable($newest, new DateTimeZone('UTC')))->modify('+1 day');

                if ($resume < $start) {
                    $start = $resume;
                }
            } catch (Throwable) {
                // A junk date in the table is not worth failing the run over.
            }
        }

        $floor = $end->modify('-' . (self::CATCHUP_DAYS - 1) . ' days');

        return ($start < $floor ? $floor : $start)->format('Y-m-d');
    }

    /**
     * Recompute one venue day.
     *
     * Each metric is its own query and its own try: a table missing on an
     * older install, or one metric whose source has a problem, must not cost
     * the whole day.
     */
    public static function rebuildDay(string $day): int
    {
        $written = 0;

        foreach (self::valuesFor($day) as $metric => $pair) {
            try {
                self::put($day, $metric, (float) $pair[0], (int) $pair[1]);
                $written++;
            } catch (Throwable $e) {
                Log::warning('Rollup: could not write a metric', [
                    'day'    => $day,
                    'metric' => $metric,
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        return $written;
    }

    /**
     * Every metric for one day, as [numeric, cents].
     *
     * One query per source table rather than one per metric: the figures a
     * table can answer together are asked for together, which is four reads
     * for seventeen metrics.
     *
     * @return array<string,array{0:float,1:int}>
     */
    private static function valuesFor(string $day): array
    {
        $out = [];

        // --- Bookings, by the day of the party
        $bookings = DB::first(
            "SELECT
                SUM(status NOT IN ('cancelled', 'no_show', 'inquiry')) AS held,
                SUM(CASE WHEN status NOT IN ('cancelled', 'no_show', 'inquiry')
                         THEN total_cents ELSE 0 END) AS revenue,
                SUM(CASE WHEN status NOT IN ('cancelled', 'no_show', 'inquiry')
                         THEN guest_count ELSE 0 END) AS guests,
                SUM(status IN ('cancelled', 'no_show')) AS lost
               FROM {bookings}
              WHERE deleted_at IS NULL AND event_date = :day",
            ['day' => $day]
        ) ?? [];

        $out['bookings']           = [(float) ($bookings['held'] ?? 0), 0];
        $out['booking_revenue']    = [0.0, (int) ($bookings['revenue'] ?? 0)];
        $out['booking_guests']     = [(float) ($bookings['guests'] ?? 0), 0];
        $out['bookings_cancelled'] = [(float) ($bookings['lost'] ?? 0), 0];

        // --- Deals. Created on the day; won counted on the day they were WON,
        //     which is a different day and deliberately so — a deal created in
        //     March and won in May is March's enquiry and May's win.
        $created = DB::value(
            "SELECT COUNT(*) FROM {deals}
              WHERE deleted_at IS NULL AND " . LocalDay::expr('created_at') . " = :day",
            ['day' => $day]
        );

        $won = DB::first(
            "SELECT COUNT(*) AS deals, COALESCE(SUM(value_cents), 0) AS value
               FROM {deals}
              WHERE deleted_at IS NULL AND status = 'won'
                AND " . LocalDay::expr('won_at') . " = :day",
            ['day' => $day]
        ) ?? [];

        $out['deals_created']   = [(float) $created, 0];
        $out['deals_won']       = [(float) ($won['deals'] ?? 0), 0];
        $out['deals_won_value'] = [0.0, (int) ($won['value'] ?? 0)];

        // --- The list
        $out['contacts_added'] = [(float) DB::value(
            "SELECT COUNT(*) FROM {contacts}
              WHERE deleted_at IS NULL AND " . LocalDay::expr('created_at') . " = :day",
            ['day' => $day]
        ), 0];

        $out['opt_outs'] = [(float) DB::value(
            "SELECT COUNT(*) FROM {contacts}
              WHERE marketing_opt_out_at IS NOT NULL
                AND " . LocalDay::expr('marketing_opt_out_at') . " = :day",
            ['day' => $day]
        ), 0];

        // --- Messaging
        $messages = DB::first(
            "SELECT
                SUM(direction = 'outbound') AS out_count,
                SUM(direction = 'inbound') AS in_count,
                COALESCE(SUM(cost_cents), 0) AS cost
               FROM {messages}
              WHERE " . LocalDay::expr('created_at') . " = :day",
            ['day' => $day]
        ) ?? [];

        $out['messages_out']  = [(float) ($messages['out_count'] ?? 0), 0];
        $out['messages_in']   = [(float) ($messages['in_count'] ?? 0), 0];
        $out['message_cost']  = [0.0, (int) ($messages['cost'] ?? 0)];

        // --- Visits. `visit_date` is already a venue date.
        $visits = DB::first(
            'SELECT COUNT(*) AS visits, COALESCE(SUM(spend_cents), 0) AS spend
               FROM {visits} WHERE visit_date = :day',
            ['day' => $day]
        ) ?? [];

        $out['visits']      = [(float) ($visits['visits'] ?? 0), 0];
        $out['visit_spend'] = [0.0, (int) ($visits['spend'] ?? 0)];

        // --- Feedback, on the day the guest ANSWERED
        $feedback = DB::first(
            "SELECT COUNT(*) AS answers,
                    SUM(sentiment = 'promoter') AS promoters,
                    SUM(sentiment = 'detractor') AS detractors
               FROM {survey_responses}
              WHERE responded_at IS NOT NULL
                AND " . LocalDay::expr('responded_at') . " = :day",
            ['day' => $day]
        ) ?? [];

        $out['survey_answers'] = [(float) ($feedback['answers'] ?? 0), 0];
        $out['promoters']      = [(float) ($feedback['promoters'] ?? 0), 0];
        $out['detractors']     = [(float) ($feedback['detractors'] ?? 0), 0];

        return $out;
    }

    /** One UPSERT. Re-running a day replaces it rather than doubling it. */
    private static function put(string $day, string $metric, float $numeric, int $cents): void
    {
        DB::query(
            'INSERT INTO {report_daily_rollup}
                (`date`, metric, dimension, dimension_value, value_numeric, value_cents, updated_at)
             VALUES (:day, :metric, :dimension, :dimension_value, :numeric, :cents, :now)
             ON DUPLICATE KEY UPDATE
                value_numeric = VALUES(value_numeric),
                value_cents   = VALUES(value_cents),
                updated_at    = VALUES(updated_at)',
            [
                'day'             => $day,
                'metric'          => $metric,
                'dimension'       => 'total',
                'dimension_value' => 'all',
                'numeric'         => $numeric,
                'cents'           => $cents,
                'now'             => gmdate('Y-m-d H:i:s'),
            ]
        );
    }

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    /**
     * One metric as a day => value series, with the quiet days filled in.
     *
     * A day the venue did nothing is stored as a zero rather than left out,
     * so a gap in this series means the rollup has NOT COVERED that day —
     * which is a different fact from "nothing happened", and the only one a
     * reader cannot work out for themselves.
     *
     * @return array<string,float>
     */
    public static function series(string $metric, string $from, string $to, bool $cents = false): array
    {
        // Two literal column names chosen by a boolean — not a column name
        // from a caller. The money column is separate because cents are an
        // integer and a decimal `value_numeric` would round them.
        $column = $cents ? 'value_cents' : 'value_numeric';

        $rows = DB::pairs(
            'SELECT `date`, ' . $column . '
               FROM {report_daily_rollup}
              WHERE metric = :metric AND `date` BETWEEN :from AND :to
                AND dimension = :dimension',
            ['metric' => $metric, 'from' => $from, 'to' => $to, 'dimension' => 'total']
        );

        $series = [];

        foreach (self::daysBetween($from, $to) as $day) {
            $series[$day] = array_key_exists($day, $rows) ? (float) $rows[$day] : 0.0;
        }

        return $series;
    }

    /** What the rollup has covered: the oldest and newest day it holds. */
    public static function coverage(): array
    {
        $row = DB::first(
            'SELECT MIN(`date`) AS oldest, MAX(`date`) AS newest, COUNT(DISTINCT `date`) AS days
               FROM {report_daily_rollup}'
        ) ?? [];

        return [
            'oldest' => $row['oldest'] ?? null,
            'newest' => $row['newest'] ?? null,
            'days'   => (int) ($row['days'] ?? 0),
        ];
    }

    /**
     * Rows older than this are dropped.
     *
     * Deliberately long: a daily total is a few dozen bytes and the whole
     * point of the table is to outlive the detail it was built from.
     */
    public static function prune(int $keepDays = 1460): int
    {
        if ($keepDays <= 0) {
            return 0;
        }

        $cutoff = (new DateTimeImmutable(self::today(), new DateTimeZone('UTC')))
            ->modify('-' . $keepDays . ' days')->format('Y-m-d');

        return DB::delete('report_daily_rollup', '`date` < :cutoff', ['cutoff' => $cutoff]);
    }

    // ------------------------------------------------------------------
    // Plumbing
    // ------------------------------------------------------------------

    /** @return list<string> */
    public static function daysBetween(string $from, string $to): array
    {
        $start = strtotime($from . ' 00:00:00 UTC');
        $end   = strtotime($to . ' 00:00:00 UTC');

        if ($start === false || $end === false || $end < $start) {
            return [];
        }

        $days = [];

        for ($day = $start; $day <= $end; $day += 86400) {
            $days[] = gmdate('Y-m-d', $day);
        }

        return $days;
    }

    private static function today(): string
    {
        return (new DateTimeImmutable('now', venue_tz()))->format('Y-m-d');
    }
}
