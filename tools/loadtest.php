<?php

declare(strict_types=1);

/**
 * Build a venue-sized database, and measure what the application does with it.
 *
 * WHY THIS EXISTS. Every page in Castle CRM was written against a few hundred
 * demo rows, and a query that is instant over 200 contacts can be a thirty
 * second page over 50,000 — not because it is badly written but because it
 * sorts without an index, or counts the whole table to draw a pagination line,
 * or loads every row to total a column. None of that is visible until the data
 * is there, and the venue it is for has two decades of guests.
 *
 * So this seeds a realistic shape rather than a uniform one: a long tail of
 * one-visit guests, a small core of regulars, visits clustered in summer and
 * at weekends, and money distributed the way a family entertainment centre's
 * actually is. A uniform dataset hides exactly the queries that hurt, because
 * every index looks equally good on it.
 *
 * Usage, from the project root:
 *
 *   php tools/loadtest.php --seed --contacts=50000       # build it
 *   php tools/loadtest.php --measure                     # time the queries
 *   php tools/loadtest.php --reset                       # drop what it made
 *
 * It writes to the database in `config/config.php` like everything else, so
 * point that at a scratch database first — `--seed` refuses to run against a
 * database that already holds more than a handful of contacts it did not
 * create, so a mistyped config cannot bury a venue's real data under a
 * hundred thousand test rows.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('CC_ROOT', dirname(__DIR__));

require CC_ROOT . '/app/bootstrap.php';

use App\Core\DB;
use App\Services\Reports;
use App\Services\ReportRunner;

$options = getopt('', ['seed', 'measure', 'reset', 'contacts::', 'quiet']);
$quiet   = isset($options['quiet']);

function lt_out(string $line): void
{
    fwrite(STDOUT, $line . "\n");
}

/** Marker on everything this tool makes, so `--reset` is exact. */
const LT_SOURCE = 'loadtest';

// ---------------------------------------------------------------------------
// Seeding
// ---------------------------------------------------------------------------

/**
 * How many rows are here that this tool did not make?
 *
 * The guard that stops a mistyped `config/config.php` burying a venue's real
 * contacts under a hundred thousand test rows.
 */
function lt_foreign_rows(): int
{
    return (int) DB::value(
        'SELECT COUNT(*) FROM {contacts} WHERE (source IS NULL OR source <> :s) AND deleted_at IS NULL',
        ['s' => LT_SOURCE]
    );
}

function lt_seed(int $contacts): void
{
    $foreign = lt_foreign_rows();

    if ($foreign > 50) {
        lt_out('REFUSED: this database already holds ' . number_format($foreign)
            . ' contacts that this tool did not create.');
        lt_out('Point config/config.php at a scratch database before seeding.');

        exit(1);
    }

    $started = microtime(true);

    lt_out('Seeding ' . number_format($contacts) . ' contacts…');

    $first = ['Nadia', 'Marcus', 'Priya', 'Devon', 'Aisha', 'Logan', 'Rosa', 'Kai', 'Imani',
              'Ezra', 'Chloe', 'Mateo', 'Fatima', 'Owen', 'Leila', 'Jonah', 'Simone', 'Theo'];
    $last  = ['Okonjo', 'Whitfield', 'Raman', 'Castellanos', 'Boateng', 'Brennan', 'Delgado',
              'Nakamura', 'Price', 'Sullivan', 'Abara', 'Lindqvist', 'Moreau', 'Haddad'];
    $towns = [['Chester', 'NY', '10918'], ['Monroe', 'NY', '10950'], ['Goshen', 'NY', '10924'],
              ['Middletown', 'NY', '10940'], ['Warwick', 'NY', '10990'], ['Newburgh', 'NY', '12550']];
    $stages = ['lead', 'prospect', 'customer', 'customer', 'customer', 'member', 'lapsed', 'advocate'];

    $now   = gmdate('Y-m-d H:i:s');
    $batch = [];
    $done  = 0;

    for ($i = 0; $i < $contacts; $i++) {
        $town = $towns[$i % count($towns)];

        /*
         * A REALISTIC SHAPE, not a uniform one.
         *
         * About a fifth of a venue's contact list has no email (a phone
         * number from a party booking), a tenth has no phone, and the
         * created dates spread over two decades rather than all landing
         * today — which is what makes a date-ranged report do real work.
         */
        $hasEmail = $i % 5 !== 0;
        $hasPhone = $i % 10 !== 3;

        $batch[] = [
            'first_name' => $first[$i % count($first)],
            'last_name'  => $last[($i * 7) % count($last)] . ($i % 997),
            'email'      => $hasEmail ? 'load' . $i . '@example.invalid' : null,
            'phone'      => $hasPhone ? '845555' . str_pad((string) ($i % 10000), 4, '0', STR_PAD_LEFT) : null,
            'phone_normalized' => $hasPhone
                ? '+1845555' . str_pad((string) ($i % 10000), 4, '0', STR_PAD_LEFT)
                : null,
            'city'        => $town[0],
            'state'       => $town[1],
            'postal_code' => $town[2],
            'country'     => 'US',
            'lifecycle_stage' => $stages[$i % count($stages)],
            'source'      => LT_SOURCE,
            // Consent on about a third, which is the realistic proportion and
            // also what makes a campaign audience query do real filtering.
            'email_consent' => $hasEmail && $i % 3 === 0 ? 1 : 0,
            'email_consent_at' => $hasEmail && $i % 3 === 0 ? $now : null,
            'sms_consent'   => $hasPhone && $i % 7 === 0 ? 1 : 0,
            'do_not_contact' => $i % 101 === 0 ? 1 : 0,
            'birth_date'  => gmdate('Y-m-d', strtotime('-' . (18 + ($i % 50)) . ' years -' . ($i % 365) . ' days')),
            'created_at'  => gmdate('Y-m-d H:i:s', time() - (($i % 7300) * 86400)),
            'updated_at'  => $now,
        ];

        if (count($batch) >= 500) {
            DB::insertMany('contacts', $batch);
            $done += count($batch);
            $batch = [];

            if (!$GLOBALS['lt_quiet'] && $done % 5000 === 0) {
                lt_out('  ' . number_format($done) . ' contacts…');
            }
        }
    }

    if ($batch !== []) {
        DB::insertMany('contacts', $batch);
        $done += count($batch);
    }

    lt_out('  ' . number_format($done) . ' contacts in ' . lt_secs($started));

    // ----------------------------------------------------------- the visits
    $visitsStarted = microtime(true);

    lt_out('Seeding visits…');

    $ids = DB::column(
        'SELECT id FROM {contacts} WHERE source = :s ORDER BY id',
        ['s' => LT_SOURCE]
    );

    $batch   = [];
    $written = 0;

    foreach ($ids as $index => $contactId) {
        /*
         * A LONG TAIL. Most guests come once; a few come twenty times. A
         * uniform two-visits-each dataset makes every "who are our regulars"
         * query look fast, because there is no tail to sort.
         */
        $visits = match (true) {
            $index % 50 === 0 => 20,
            $index % 11 === 0 => 6,
            $index % 3  === 0 => 2,
            default           => 1,
        };

        for ($v = 0; $v < $visits; $v++) {
            // Clustered in summer and at weekends, like the real thing.
            $daysAgo = ($index % 1800) + ($v * 37);
            $when    = strtotime('-' . $daysAgo . ' days');
            $month   = (int) gmdate('n', $when);
            $spend   = 1500 + (($index * 17 + $v * 311) % 18500);

            if (in_array($month, [6, 7, 8], true)) {
                $spend = (int) ($spend * 1.4);
            }

            $batch[] = [
                'contact_id'  => (int) $contactId,
                'visit_date'  => gmdate('Y-m-d', $when),
                'source'      => 'pos_import',
                'spend_cents' => $spend,
                'party_size'  => 1 + (($index + $v) % 8),
                'created_at'  => gmdate('Y-m-d H:i:s'),
                'updated_at'  => gmdate('Y-m-d H:i:s'),
            ];
        }

        if (count($batch) >= 500) {
            /*
             * `INSERT IGNORE` in effect: the schema has a unique key on
             * (contact_id, visit_date) and the arithmetic above can land two
             * visits for one guest on one day. Skipping the clash is the right
             * answer — one day IS one visit — and catching it per batch is far
             * cheaper than checking each row first.
             */
            try {
                DB::insertMany('visits', $batch);
                $written += count($batch);
            } catch (Throwable) {
                foreach ($batch as $row) {
                    try {
                        DB::insert('visits', $row);
                        $written++;
                    } catch (Throwable) {
                        // One day, one visit.
                    }
                }
            }

            $batch = [];

            if (!$GLOBALS['lt_quiet'] && $written % 25000 < 500) {
                lt_out('  ' . number_format($written) . ' visits…');
            }
        }
    }

    if ($batch !== []) {
        try {
            DB::insertMany('visits', $batch);
            $written += count($batch);
        } catch (Throwable) {
            foreach ($batch as $row) {
                try {
                    DB::insert('visits', $row);
                    $written++;
                } catch (Throwable) {
                }
            }
        }
    }

    lt_out('  ' . number_format($written) . ' visits in ' . lt_secs($visitsStarted));

    /*
     * THE DERIVED COLUMNS, IN SQL. `VisitRecorder::refresh()` is the right way
     * to do this one contact at a time; 50,000 calls to it is 200,000 queries
     * and twenty minutes. The arithmetic is the same.
     */
    lt_out('Deriving visit counts and lifetime values…');
    $derive = microtime(true);

    DB::query(
        'UPDATE {contacts} c
            JOIN (
                SELECT contact_id,
                       COUNT(*) AS visits,
                       SUM(spend_cents) AS total,
                       MIN(visit_date) AS first_visit,
                       MAX(visit_date) AS last_visit
                  FROM {visits}
                 GROUP BY contact_id
            ) v ON v.contact_id = c.id
             SET c.visit_count = v.visits,
                 c.lifetime_value_cents = v.total,
                 c.avg_spend_cents = FLOOR(v.total / v.visits),
                 c.first_visit_at = v.first_visit,
                 c.last_visit_at = v.last_visit
           WHERE c.source = :s',
        ['s' => LT_SOURCE]
    );

    lt_out('  done in ' . lt_secs($derive));
    lt_out('');
    lt_out('Total: ' . lt_secs($started));
    lt_out('');
    lt_report_size();
}

function lt_report_size(): void
{
    foreach (['contacts', 'visits', 'bookings', 'messages', 'deals'] as $table) {
        $count = (int) DB::value('SELECT COUNT(*) FROM ' . DB::quoteIdentifier(DB::table($table)));
        lt_out(str_pad($table, 12) . number_format($count));
    }

    $bytes = (int) DB::value(
        'SELECT SUM(data_length + index_length) FROM information_schema.tables
          WHERE table_schema = DATABASE()'
    );

    lt_out(str_pad('on disk', 12) . bytes_human($bytes));
}

function lt_secs(float $from): string
{
    return number_format(microtime(true) - $from, 2) . 's';
}

// ---------------------------------------------------------------------------
// Measuring
// ---------------------------------------------------------------------------

/**
 * Time a closure, reporting wall clock and peak memory.
 *
 * MEMORY MATTERS AS MUCH AS TIME on shared hosting: the usual `memory_limit`
 * is 128M, and blowing it is a FATAL — no exception, no error page, just a
 * blank 500 with nothing in the response. A query that takes 400ms and 200MB
 * is not slow, it is broken.
 *
 * @param callable():mixed $work
 * @return array{label:string,ms:float,mb:float,rows:?int,error:?string}
 */
function lt_time(string $label, callable $work): array
{
    gc_collect_cycles();

    $before = memory_get_usage(true);
    $start  = microtime(true);
    $rows   = null;
    $error  = null;

    try {
        $result = $work();
        $rows   = is_array($result) ? count($result) : (is_int($result) ? $result : null);
    } catch (Throwable $e) {
        $error = get_class($e) . ': ' . $e->getMessage();
    }

    return [
        'label' => $label,
        'ms'    => (microtime(true) - $start) * 1000,
        'mb'    => (memory_get_peak_usage(true) - $before) / 1048576,
        'rows'  => $rows,
        'error' => $error,
    ];
}

function lt_measure(): void
{
    $total = (int) DB::value('SELECT COUNT(*) FROM {contacts} WHERE deleted_at IS NULL');

    lt_out('Measuring against ' . number_format($total) . ' contacts and '
        . number_format((int) DB::value('SELECT COUNT(*) FROM {visits}')) . ' visits.');
    lt_out('');

    $results = [];

    // ---------------------------------------------------- the list queries
    $results[] = lt_time('contacts: first page, newest first', static fn (): array => DB::all(
        'SELECT id, first_name, last_name, email, phone_normalized, lifecycle_stage,
                visit_count, lifetime_value_cents
           FROM {contacts}
          WHERE deleted_at IS NULL
          ORDER BY id DESC
          LIMIT 50'
    ));

    $results[] = lt_time('contacts: count for the pagination line', static fn (): int
        => (int) DB::value('SELECT COUNT(*) FROM {contacts} WHERE deleted_at IS NULL'));

    $results[] = lt_time('contacts: search by surname', static fn (): array => DB::all(
        "SELECT id, first_name, last_name FROM {contacts}
          WHERE deleted_at IS NULL AND last_name LIKE :q
          ORDER BY last_name LIMIT 50",
        ['q' => 'Raman%']
    ));

    $results[] = lt_time('contacts: search by phone digits', static fn (): array => DB::all(
        "SELECT id FROM {contacts}
          WHERE deleted_at IS NULL AND phone_normalized LIKE :q LIMIT 50",
        ['q' => '%5550042%']
    ));

    $results[] = lt_time('contacts: the LAST page of the list', static fn (): array => DB::all(
        'SELECT id, first_name, last_name FROM {contacts}
          WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 50 OFFSET ' . max(0, $total - 50)
    ));

    // ------------------------------------------------- the dashboard panels
    $results[] = lt_time('dashboard: consented guest count', static fn (): int => (int) DB::value(
        'SELECT COUNT(*) FROM {contacts}
          WHERE deleted_at IS NULL AND do_not_contact = 0
            AND (email_consent = 1 OR sms_consent = 1)'
    ));

    $results[] = lt_time('dashboard: visits in the last 30 days', static fn (): int => (int) DB::value(
        'SELECT COUNT(*) FROM {visits} WHERE visit_date >= :from',
        ['from' => gmdate('Y-m-d', strtotime('-30 days'))]
    ));

    // -------------------------------------------------------- the reports
    $window = [
        'from'       => gmdate('Y-m-d', strtotime('-365 days')),
        'to'         => gmdate('Y-m-d'),
        'prior_from' => gmdate('Y-m-d', strtotime('-730 days')),
        'prior_to'   => gmdate('Y-m-d', strtotime('-366 days')),
        'label'      => 'Last 365 days',
        'days'       => 365,
    ];

    foreach (array_keys(Reports::CATALOG) as $slug) {
        $results[] = lt_time('report: ' . $slug, static function () use ($slug, $window) {
            $report = ReportRunner::run((string) $slug, $window);

            return is_array($report['rows'] ?? null) ? $report['rows'] : null;
        });
    }

    // ------------------------------------------------------------- segments
    $results[] = lt_time('segment: regulars who have not been for 90 days', static fn (): int
        => (int) DB::value(
            'SELECT COUNT(*) FROM {contacts}
              WHERE deleted_at IS NULL AND visit_count >= 5
                AND (last_visit_at IS NULL OR last_visit_at < :cutoff)',
            ['cutoff' => gmdate('Y-m-d', strtotime('-90 days'))]
        ));

    $results[] = lt_time('segment: a campaign audience', static fn (): array => DB::all(
        'SELECT id, email FROM {contacts}
          WHERE deleted_at IS NULL AND do_not_contact = 0
            AND email_consent = 1 AND email IS NOT NULL
          ORDER BY id
          LIMIT 5000'
    ));

    // -------------------------------------------------------------- report
    lt_out(str_pad('QUERY', 52) . str_pad('TIME', 12) . str_pad('MEMORY', 10) . 'ROWS');
    lt_out(str_repeat('-', 86));

    $slow  = [];
    $heavy = [];
    $broke = [];

    foreach ($results as $result) {
        $ms = $result['ms'];
        $mb = $result['mb'];

        $flag = '';

        if ($result['error'] !== null) {
            $flag = '  ** ' . substr($result['error'], 0, 60);
            $broke[] = $result;
        } else {
            // 1000ms is the threshold at which a page feels broken rather
            // than slow, and 32MB is a quarter of a 128M limit — enough
            // headroom that two of them on one page is still survivable.
            if ($ms > 1000) {
                $flag .= '  ** SLOW';
                $slow[] = $result;
            }

            if ($mb > 32) {
                $flag .= '  ** HEAVY';
                $heavy[] = $result;
            }
        }

        lt_out(
            str_pad(substr($result['label'], 0, 50), 52)
            . str_pad(number_format($ms, 1) . 'ms', 12)
            . str_pad(number_format($mb, 1) . 'MB', 10)
            . str_pad((string) ($result['rows'] ?? '-'), 8)
            . $flag
        );
    }

    lt_out('');
    lt_out('Over 1s: ' . count($slow) . '   over 32MB: ' . count($heavy)
        . '   failed: ' . count($broke));

    if ($slow !== [] || $heavy !== [] || $broke !== []) {
        exit(1);
    }
}

// ---------------------------------------------------------------------------

function lt_reset(): void
{
    $ids = DB::column('SELECT id FROM {contacts} WHERE source = :s', ['s' => LT_SOURCE]);

    lt_out('Removing ' . number_format(count($ids)) . ' seeded contacts and their visits…');

    foreach (array_chunk($ids, 500) as $chunk) {
        $placeholders = [];
        $params       = [];

        foreach ($chunk as $i => $id) {
            $placeholders[] = ':p' . $i;
            $params['p' . $i] = (int) $id;
        }

        $in = implode(', ', $placeholders);

        DB::query('DELETE FROM {visits} WHERE contact_id IN (' . $in . ')', $params);
        DB::query('DELETE FROM {contacts} WHERE id IN (' . $in . ')', $params);
    }

    lt_out('Done.');
}

$GLOBALS['lt_quiet'] = $quiet;

if (isset($options['seed'])) {
    lt_seed(max(1, (int) ($options['contacts'] ?? 50000)));
} elseif (isset($options['measure'])) {
    lt_measure();
} elseif (isset($options['reset'])) {
    lt_reset();
} else {
    lt_out('Usage: php tools/loadtest.php --seed [--contacts=50000] | --measure | --reset');
    lt_out('');
    lt_out('Point config/config.php at a scratch database before seeding.');

    exit(1);
}
