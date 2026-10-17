<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Settings;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * "Which venue day did this happen on?", in SQL.
 *
 * Every timestamp in this database is UTC and every report is a claim about
 * the VENUE'S calendar. Chester is four or five hours behind UTC, so
 * `DATE(created_at)` — the obvious thing, and what a reporting module gets
 * written with the first time — files everything from eight in the evening
 * onwards on the following day. For a venue whose parties are booked in the
 * evening and whose busiest hours are 6pm to 9pm, that is not a rounding
 * error: it is most of a Saturday night landing on Sunday, every week, in
 * every report.
 *
 * MySQL can do this correctly with `CONVERT_TZ(col, 'UTC', 'America/New_York')`
 * — but only when the server's timezone tables have been loaded, which on
 * shared cPanel hosting is a coin toss, and when they are missing
 * `CONVERT_TZ` returns NULL rather than failing. A report grouped by NULL is
 * an empty report, and an empty report reads as "the venue did no business".
 *
 * So this class probes once and picks:
 *
 *   `named`  — the timezone tables are there. Fully correct, including every
 *              daylight-saving changeover in the venue's history.
 *   `offset` — they are not. A fixed offset is used: the one in force TODAY.
 *   `utc`    — the venue runs on UTC anyway, so there is nothing to do.
 *
 * **What the fallback costs, stated exactly.** With a fixed offset, rows in a
 * one-hour band are filed on the wrong day for any date in the opposite
 * daylight-saving phase — the 11pm-to-midnight hour, local. The Castle closes
 * well before then, so in practice almost nothing lands in that band; what
 * does is an automated message or an overnight import. The reports page says
 * which mode is in force rather than leaving a venue to wonder, because an
 * approximation nobody is told about is indistinguishable from a bug.
 *
 * Two things every caller should use together:
 *
 *   `expr()`      for grouping and for the exact day comparison;
 *   `utcBounds()` for a sargable pre-filter on the raw column.
 *
 * The expression is not indexable — it is a function of the column — so a
 * query that only used it would scan the table. Bounding the raw UTC column
 * to the window plus a day either side lets the index do the work and leaves
 * the expression to decide the handful of rows at each edge.
 */
final class LocalDay
{
    /** Cleared by the tests; otherwise probed once per request. */
    private static ?string $mode = null;

    private static ?int $offsetMinutes = null;

    /**
     * Forget the probe. For tests, and for the installer after it has
     * created the connection it is going to use.
     */
    public static function reset(): void
    {
        self::$mode          = null;
        self::$offsetMinutes = null;
    }

    /**
     * Pin the mode and the offset without probing.
     *
     * For the tests, which pin the SQL each mode produces and must do it
     * without a database — and for a caller that already knows (the installer
     * probes once as it builds the schema). It cannot widen anything: the
     * three modes are all this class knows how to emit, and an unrecognised
     * one is refused rather than silently becoming the fallback.
     */
    public static function useMode(string $mode, ?int $offsetMinutes = null): void
    {
        if (!in_array($mode, ['named', 'offset', 'utc'], true)) {
            throw new \InvalidArgumentException('Unknown LocalDay mode "' . $mode . '".');
        }

        self::$mode = $mode;

        if ($offsetMinutes !== null) {
            self::$offsetMinutes = $offsetMinutes;
        }
    }

    /** `named`, `offset` or `utc`. */
    public static function mode(): string
    {
        if (self::$mode !== null) {
            return self::$mode;
        }

        $zone = Settings::timezone();

        if ($zone === 'UTC') {
            return self::$mode = 'utc';
        }

        // The probe is a midsummer instant: it has to come back non-null AND
        // actually differ from the input, because a server with empty
        // timezone tables has been seen to return the input unchanged rather
        // than NULL — which would read as a working conversion.
        try {
            $probe = DB::value(
                'SELECT CONVERT_TZ(:stamp, :from_zone, :to_zone)',
                ['stamp' => '2026-07-01 12:00:00', 'from_zone' => 'UTC', 'to_zone' => $zone]
            );

            if (is_string($probe) && $probe !== '' && $probe !== '2026-07-01 12:00:00') {
                return self::$mode = 'named';
            }
        } catch (Throwable) {
            // A server that refuses CONVERT_TZ outright falls back the same
            // way one with no timezone tables does.
        }

        return self::$mode = 'offset';
    }

    /** The offset used by `offset` mode, in minutes east of UTC (so −240 for EDT). */
    public static function offsetMinutes(): int
    {
        if (self::$offsetMinutes !== null) {
            return self::$offsetMinutes;
        }

        try {
            $tz  = new DateTimeZone(Settings::timezone());
            $now = new DateTimeImmutable('now', $tz);

            return self::$offsetMinutes = (int) ($tz->getOffset($now) / 60);
        } catch (Throwable) {
            return self::$offsetMinutes = 0;
        }
    }

    /**
     * A SQL expression giving the venue-local DATE of a UTC datetime column.
     *
     * The column name is interpolated, so it must be a literal written by
     * this application — `'b.created_at'`, never request input. Every caller
     * in the codebase passes a constant; this is checked by a test that
     * greps for a variable in an `expr()` argument.
     */
    public static function expr(string $column): string
    {
        return 'DATE(' . self::datetimeExpr($column) . ')';
    }

    /** The same conversion without the DATE(), for an hour-of-day grouping. */
    public static function datetimeExpr(string $column): string
    {
        self::assertIdentifier($column);

        return match (self::mode()) {
            'utc'   => $column,
            'named' => 'CONVERT_TZ(' . $column . ", 'UTC', " . self::quotedZone() . ')',
            default => '(' . $column . ' + INTERVAL ' . self::offsetMinutes() . ' MINUTE)',
        };
    }

    /** The venue-local hour (0-23) of a UTC datetime column. */
    public static function hourExpr(string $column): string
    {
        return 'HOUR(' . self::datetimeExpr($column) . ')';
    }

    /**
     * The UTC instants that certainly contain a span of venue-local days.
     *
     * A day of slack either side, which is far more than any real offset, so
     * the pre-filter can never cut a row the expression would have kept. It
     * exists to let an index narrow the scan, not to decide anything.
     *
     * @return array{0:string,1:string} [inclusive start, EXCLUSIVE end]
     */
    public static function utcBounds(string $from, string $to): array
    {
        $start = strtotime($from . ' 00:00:00 UTC');
        $end   = strtotime($to . ' 00:00:00 UTC');

        if ($start === false || $end === false) {
            // Unparseable dates would otherwise produce bounds around 1970,
            // which silently empties the report rather than failing.
            return ['1000-01-01 00:00:00', '9999-12-31 23:59:59'];
        }

        return [
            gmdate('Y-m-d H:i:s', $start - 86400),
            gmdate('Y-m-d H:i:s', $end + (86400 * 2)),
        ];
    }

    /**
     * The whole WHERE fragment for "this UTC column falls in this span of
     * venue days", with the pre-filter in front of it.
     *
     * Binds `:{$prefix}_utc_from` and `:{$prefix}_utc_to` — named per call
     * site because `PDO::ATTR_EMULATE_PREPARES` is off in this application
     * and a named placeholder may be bound exactly once. Two windows in one
     * query (a report and its prior period) need two prefixes.
     */
    public static function between(string $column, string $fromParam, string $toParam, string $prefix = 'w'): string
    {
        return $column . ' >= :' . $prefix . '_utc_from AND ' . $column . ' < :' . $prefix . '_utc_to'
            . ' AND ' . self::expr($column) . ' BETWEEN :' . $fromParam . ' AND :' . $toParam;
    }

    /**
     * The two bound values `between()` needs.
     *
     * @return array<string,string>
     */
    public static function params(string $from, string $to, string $prefix = 'w'): array
    {
        [$utcFrom, $utcTo] = self::utcBounds($from, $to);

        return [
            $prefix . '_utc_from' => $utcFrom,
            $prefix . '_utc_to'   => $utcTo,
        ];
    }

    /**
     * What a report should tell the reader, or null when there is nothing to
     * say because the conversion is exact.
     */
    public static function note(): ?string
    {
        return match (self::mode()) {
            'named', 'utc' => null,
            default        => 'This database server has no timezone tables, so days are '
                . 'cut using the venue\'s current clock offset rather than the one in force '
                . 'on each date. Anything recorded between 11pm and midnight can land on '
                . 'the neighbouring day for dates in the other half of the year. Loading '
                . 'MySQL\'s timezone tables removes the approximation.',
        };
    }

    /** A zone name safe to put in SQL. It comes from settings, not a request. */
    private static function quotedZone(): string
    {
        $zone = Settings::timezone();

        if (preg_match('#^[A-Za-z][A-Za-z0-9+_/-]{1,63}$#', $zone) !== 1) {
            return "'UTC'";
        }

        return "'" . $zone . "'";
    }

    /**
     * Refuse anything that is not a plain `table.column` or `column`.
     *
     * The expression is built by interpolation, which is the one thing this
     * codebase otherwise never does, so the guard is explicit rather than
     * left to the discipline of twenty-three call sites.
     */
    private static function assertIdentifier(string $column): void
    {
        if (preg_match('#^[A-Za-z_][A-Za-z0-9_]{0,63}(\.[A-Za-z_][A-Za-z0-9_]{0,63})?$#', $column) !== 1) {
            throw new \InvalidArgumentException(
                'LocalDay expects a plain column name, got "' . $column . '".'
            );
        }
    }
}
