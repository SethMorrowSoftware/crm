<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Acl;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\LocalDay;
use App\Services\ReportRollup;
use App\Services\Reports;
use App\Services\ReportRunner;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * The reporting screens.
 *
 * Three classes, the usual split:
 *
 *   `Services\Reports`      — the catalogue and the window arithmetic. Pure.
 *   `Services\ReportRunner` — one query per report.
 *   this class              — the picker, the table, the CSV, saved reports.
 *
 * Two properties of this controller are load-bearing and easy to undo:
 *
 * **MONEY IS SCRUBBED, NOT HIDDEN.** A role without `view_revenue` gets the
 * report with its money columns present and empty, and a line saying so. Every
 * other figure on the page is still true, so a technician reading "14 parties
 * on Saturday" is reading the same row a manager is — removing the columns
 * would silently renumber the table between two people looking at the same
 * report.
 *
 * **EVERY WINDOW IS VENUE-LOCAL.** `Reports::window()` takes today as a
 * string and this is the one place that decides what today is. The server runs
 * four or five hours ahead of Chester, so a report built from the server's
 * date would move to tomorrow every evening at eight — and "today" would show
 * an empty table while the venue was still open.
 */
final class ReportController extends Controller
{
    /** Exporting is its own permission: a CSV leaves the building. */
    public const EXPORT_PERMISSION = 'reports_export';

    /** The most saved reports one venue can keep. A list, not a filesystem. */
    private const MAX_SAVED = 200;

    // =================================================================
    // Reading
    // =================================================================

    /** The picker: every report, grouped by family, with its question. */
    public function index(Request $request): Response
    {
        return $this->view('reports/index', [
            'pageTitle' => 'Reports',
            'families'  => Reports::FAMILIES,
            'byFamily'  => Reports::byFamily(),
            'saved'     => $this->savedReports(),
            'canExport' => Acl::can(self::EXPORT_PERMISSION),
            'pulse'     => $this->pulse(),
            'dayNote'   => LocalDay::note(),
        ]);
    }

    /**
     * The last ninety days at a glance, from the nightly rollup.
     *
     * Four indexed reads rather than four reports. The rollup is the only
     * thing here that does not read the live rows, and the reason is this
     * page: a strip of sparklines built from four full report queries would
     * make the picker the slowest page in the application.
     *
     * **It reports its own coverage, and renders NOTHING when there is none.**
     * A fresh install, or one whose cron has never run, has an empty rollup —
     * and a strip of flat lines at zero on that install says the venue did no
     * business for three months. "We have not got this yet" and "it was zero"
     * are different answers.
     *
     * @return array{ready:bool,from:string,to:string,metrics:list<array<string,mixed>>,coverage:array<string,mixed>}
     */
    private function pulse(): array
    {
        $coverage = ReportRollup::coverage();

        $to   = (new DateTimeImmutable('now', venue_tz()))->modify('-1 day')->format('Y-m-d');
        $from = (new DateTimeImmutable($to, new DateTimeZone('UTC')))->modify('-89 days')->format('Y-m-d');

        if ($coverage['newest'] === null) {
            return ['ready' => false, 'from' => $from, 'to' => $to, 'metrics' => [], 'coverage' => $coverage];
        }

        // Only as far back as the rollup actually reaches: drawing the days
        // before it as zeroes would invent a quiet spell.
        if ((string) $coverage['oldest'] > $from) {
            $from = (string) $coverage['oldest'];
        }

        $wanted = [
            ['metric' => 'bookings', 'label' => 'Parties held', 'cents' => false, 'money' => false],
            ['metric' => 'booking_revenue', 'label' => 'Booked', 'cents' => true, 'money' => true],
            ['metric' => 'deals_created', 'label' => 'Enquiries', 'cents' => false, 'money' => false],
            ['metric' => 'visits', 'label' => 'Visits', 'cents' => false, 'money' => false],
        ];

        $canSeeMoney = Acl::can('view_revenue');
        $metrics     = [];

        foreach ($wanted as $spec) {
            if ($spec['money'] && !$canSeeMoney) {
                continue;
            }

            $series = ReportRollup::series(
                (string) $spec['metric'],
                $from,
                $to,
                (bool) $spec['cents']
            );

            $metrics[] = [
                'label'  => (string) $spec['label'],
                'money'  => (bool) $spec['money'],
                'series' => $series,
                'total'  => array_sum($series),
                'peak'   => $series === [] ? 0.0 : max($series),
            ];
        }

        return [
            'ready'    => $metrics !== [],
            'from'     => $from,
            'to'       => $to,
            'metrics'  => $metrics,
            'coverage' => $coverage,
        ];
    }

    /** One report, run. */
    public function show(Request $request): Response
    {
        $id     = (string) $request->route('report', '');
        $report = Reports::report($id);

        if ($report === null) {
            return $this->notFound('There is no report by that name.');
        }

        $window = $this->window($request);

        try {
            $result = ReportRunner::run($id, $window);
        } catch (RuntimeException $e) {
            // A report that cannot run says why, on its own page, rather than
            // turning into the generic 500 that tells a venue nothing.
            return $this->view('reports/show', [
                'pageTitle' => $report['name'],
                'report'    => $report + ['id' => $id],
                'window'    => $window,
                'presets'   => Reports::PRESETS,
                'result'    => null,
                'failure'   => $e->getMessage(),
                'canExport' => false,
                'scrubbed'  => false,
                'saved'     => $this->savedReports(),
            ]);
        }

        $scrubbed = $report['money'] && !Acl::can('view_revenue');

        if ($scrubbed) {
            $result = Reports::scrubMoney($result);

            // The headline comparison is a money figure on most of these, and
            // scrubMoney only reaches columns. Dropping it entirely is the
            // honest move: a comparison with a nulled base reads as "no prior
            // period", which is a different and wrong statement.
            if (($result['comparison']['type'] ?? '') === 'money') {
                $result['comparison'] = null;
            }
        }

        return $this->view('reports/show', [
            'pageTitle' => $report['name'],
            'report'    => $report + ['id' => $id],
            'window'    => $window,
            'presets'   => Reports::PRESETS,
            'result'    => $result,
            'failure'   => null,
            'canExport' => Acl::can(self::EXPORT_PERMISSION),
            'scrubbed'  => $scrubbed,
            'saved'     => $this->savedReports(),
        ]);
    }

    /**
     * The same report as a CSV.
     *
     * Gated on `reports_export` AND scrubbed the same way the page is: a role
     * that may not see revenue on screen may not download it either, which is
     * the hole an export endpoint is for.
     */
    public function export(Request $request): Response
    {
        if (($denied = $this->authorize(self::EXPORT_PERMISSION)) !== null) {
            return $denied;
        }

        $id = (string) $request->route('report', '');

        if (!Reports::exists($id)) {
            return $this->notFound('There is no report by that name.');
        }

        $report = Reports::report($id) ?? [];
        $window = $this->window($request);
        $result = ReportRunner::run($id, $window);

        if (($report['money'] ?? false) && !Acl::can('view_revenue')) {
            $result = Reports::scrubMoney($result);
        }

        Audit::event('report_exported', [
            'report' => $id,
            'from'   => $window['from'],
            'to'     => $window['to'],
            'rows'   => count($result['rows']),
        ], 'report');

        return Response::csv(
            Reports::filename($id, $window['from'], $window['to']),
            Reports::csvHeader($result['columns']),
            Reports::csvRows($result['columns'], $result['rows'])
        );
    }

    // =================================================================
    // Saved reports
    // =================================================================

    /**
     * Keep a report and its window under a name.
     *
     * The window is stored as it was CHOSEN, not as it resolved — a saved
     * "Last 30 days" means the last 30 days whenever it is opened, where a
     * stored pair of dates would quietly become a report about last March.
     * A custom window is the exception and stores its dates, because that is
     * what custom means.
     */
    public function save(Request $request): Response
    {
        $data = $this->validate($request, [
            'name'   => 'required|max:120',
            'report' => 'required|max:40',
        ], ['name' => 'Name', 'report' => 'Report']);

        if ($data instanceof Response) {
            return $data;
        }

        $id = (string) $data['report'];

        if (!Reports::exists($id)) {
            flash('error', 'There is no report by that name.');

            return $this->redirect('/reports');
        }

        if ($this->savedCount() >= self::MAX_SAVED) {
            flash('error', 'There are already ' . self::MAX_SAVED . ' saved reports. Delete one first.');

            return $this->redirect('/reports/' . $id);
        }

        $window = $this->window($request);

        DB::insert('saved_reports', [
            'name'       => (string) $data['name'],
            'type'       => $id,
            'filters'    => json_encode([
                'preset' => $window['preset'],
                'from'   => $window['preset'] === 'custom' ? $window['from'] : null,
                'to'     => $window['preset'] === 'custom' ? $window['to'] : null,
            ], JSON_THROW_ON_ERROR),
            'created_by' => Auth::id(),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        Audit::event('report_saved', ['report' => $id, 'name' => (string) $data['name']], 'report');
        flash('success', 'Saved. It is on the reports page.');

        return $this->redirect('/reports/' . $id . '?' . $this->queryFor($window));
    }

    /** Open a saved report: resolve its window and show it. */
    public function open(Request $request): Response
    {
        $saved = DB::first(
            'SELECT * FROM {saved_reports} WHERE id = :id',
            ['id' => $request->routeInt('id')]
        );

        if ($saved === null) {
            return $this->notFound('That saved report was deleted.');
        }

        $filters = $this->decodeFilters($saved['filters'] ?? null);
        $query   = ['range' => (string) ($filters['preset'] ?? 'last_30')];

        if (($filters['from'] ?? null) !== null) {
            $query['from'] = (string) $filters['from'];
        }

        if (($filters['to'] ?? null) !== null) {
            $query['to'] = (string) $filters['to'];
        }

        DB::update(
            'saved_reports',
            ['last_run_at' => gmdate('Y-m-d H:i:s')],
            'id = :id',
            ['id' => (int) $saved['id']]
        );

        return $this->redirect('/reports/' . (string) $saved['type'] . '?' . http_build_query($query));
    }

    public function deleteSaved(Request $request): Response
    {
        $saved = DB::first(
            'SELECT * FROM {saved_reports} WHERE id = :id',
            ['id' => $request->routeInt('id')]
        );

        if ($saved === null) {
            flash('error', 'That saved report is already gone.');

            return $this->redirect('/reports');
        }

        DB::delete('saved_reports', 'id = :id', ['id' => (int) $saved['id']]);
        Audit::event('report_unsaved', ['name' => (string) $saved['name']], 'report');
        flash('success', 'Deleted.');

        return $this->redirect('/reports');
    }

    // =================================================================
    // Plumbing
    // =================================================================

    /**
     * The window for this request.
     *
     * @return array{preset:string,from:string,to:string,days:int,label:string,
     *     prior_from:string,prior_to:string,clamped:bool}
     */
    private function window(Request $request): array
    {
        return Reports::window(
            $request->str('range', 'last_30'),
            $request->str('from', '') ?: null,
            $request->str('to', '') ?: null,
            (new DateTimeImmutable('now', venue_tz()))->format('Y-m-d')
        );
    }

    /** The query string that reproduces a window, for links and redirects. */
    private function queryFor(array $window): string
    {
        $query = ['range' => (string) $window['preset']];

        if ($window['preset'] === 'custom') {
            $query['from'] = (string) $window['from'];
            $query['to']   = (string) $window['to'];
        }

        return http_build_query($query);
    }

    /**
     * Saved reports, newest first, with the catalogue entry resolved.
     *
     * A saved report whose type is no longer in the catalogue is LISTED and
     * marked, not dropped: a venue that renamed a report wants to know their
     * saved one stopped working, and silently hiding it means they find out
     * when somebody asks where it went.
     *
     * @return list<array<string,mixed>>
     */
    private function savedReports(): array
    {
        $rows = DB::all(
            'SELECT s.*, u.name AS created_by_name
               FROM {saved_reports} s
               LEFT JOIN {users} u ON u.id = s.created_by
              ORDER BY s.created_at DESC, s.id DESC
              LIMIT ' . self::MAX_SAVED
        );

        foreach ($rows as $index => $row) {
            $catalog  = Reports::report((string) $row['type']);
            $filters  = $this->decodeFilters($row['filters'] ?? null);
            $preset   = (string) ($filters['preset'] ?? 'last_30');

            $rows[$index]['report_name'] = $catalog['name'] ?? null;
            $rows[$index]['family']      = $catalog['family'] ?? null;
            $rows[$index]['window']      = Reports::PRESETS[$preset] ?? $preset;
        }

        return $rows;
    }

    private function savedCount(): int
    {
        return (int) DB::value('SELECT COUNT(*) FROM {saved_reports}');
    }

    /** @return array<string,mixed> */
    private function decodeFilters(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
