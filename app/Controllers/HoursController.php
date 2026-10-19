<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\AvailabilityEngine;

/**
 * Settings → Hours: when the venue is open, and the days it is not.
 *
 * The availability engine has always read three tables — the weekly pattern
 * (`operating_hours`, with optional dated seasons), one-off closures and
 * special hours (`operating_exceptions`), and an attraction down for the day
 * (`attraction_availability`) — and nothing could write them but the seed.
 * So the online booking page offered whatever hours were on the website the
 * day the seed was written, through every holiday. `hours_manage` had been in
 * the permission catalogue since phase 1, used by nothing.
 *
 * The engine's rules, which this page explains rather than restates in code:
 * a dated season beats the standard week; a day missing from the standard week
 * is closed; a day missing from a season falls back to the standard week; and
 * a closure or special-hours date beats both.
 */
final class HoursController extends Controller
{
    private const MANAGE = 'hours_manage';

    private const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    // =================================================================
    // Reading
    // =================================================================

    public function index(Request $request): Response
    {
        $today = (new \DateTimeImmutable('now', venue_tz()))->format('Y-m-d');

        return $this->view('hours/index', [
            'pageTitle'   => 'Hours',
            'seasons'     => $this->seasons(),
            'days'        => self::DAYS,
            'exceptions'  => DB::all(
                'SELECT * FROM {operating_exceptions} WHERE date >= :today ORDER BY date',
                ['today' => $today]
            ),
            'outages'     => DB::all(
                'SELECT x.*, a.name AS attraction_name
                   FROM {attraction_availability} x
                   JOIN {attractions} a ON a.id = x.attraction_id
                  WHERE x.date >= :today AND x.is_available = 0
                  ORDER BY x.date, a.name',
                ['today' => $today]
            ),
            'attractions' => DB::all('SELECT id, name FROM {attractions} WHERE is_active = 1 ORDER BY sort_order, name'),
            'today'       => $today,
        ]);
    }

    // =================================================================
    // Writing: the weekly pattern
    // =================================================================

    /** Save one season's week — or the standard one — replacing its rows. */
    public function saveSeason(Request $request): Response
    {
        if ($denied = $this->authorize(self::MANAGE)) {
            return $denied;
        }

        $id      = $request->routeInt('id');
        $current = $id > 0 ? $this->seasonOf($id) : null;

        if ($id > 0 && $current === null) {
            return $this->notFound('That season does not exist.');
        }

        // The standard week is the undated one. Editing it keeps it undated;
        // a venue with none at all creates it from the empty card the page
        // shows, which says so with `standard`.
        $standard = $current !== null
            ? $current['from'] === null && $current['to'] === null
            : $request->bool('standard');

        $label = trim((string) $request->input('season_label', ''));
        $from  = trim((string) $request->input('effective_from', ''));
        $to    = trim((string) $request->input('effective_to', ''));

        if ($standard) {
            // The standard week is the one with no dates; giving it some would
            // leave the venue with no pattern at all outside them.
            $from = '';
            $to   = '';
            $label = $label === '' ? 'Standard' : $label;
        } else {
            if ($label === '') {
                return $this->refuse($request, 'Give the season a name, like "Summer".', '/settings/hours');
            }

            if (!AvailabilityEngine::isDate($from) || !AvailabilityEngine::isDate($to)) {
                return $this->refuse($request, 'A season needs both a first and a last day.', '/settings/hours');
            }

            if ($to < $from) {
                return $this->refuse($request, 'The season ends before it starts.', '/settings/hours');
            }
        }

        $rows = [];
        $days = $request->input('days', []);
        $days = is_array($days) ? $days : [];

        foreach (self::DAYS as $dow => $name) {
            $day = is_array($days[$dow] ?? null) ? $days[$dow] : [];

            // Only the standard week can close a day: in a season a missing
            // day falls back to the standard week (the engine's rule), so a
            // season has no way to say "closed" — that is what a closure date
            // is for, and the form offers no closed box there.
            if ($standard && !empty($day['closed'])) {
                continue;
            }

            $opens  = self::time((string) ($day['opens'] ?? ''));
            $closes = self::time((string) ($day['closes'] ?? ''));

            if ($opens === null && $closes === null && !$standard) {
                // Blank in a season: the standard week applies that day.
                continue;
            }

            if ($opens === null || $closes === null || $closes <= $opens) {
                return $this->refuse(
                    $request,
                    $name . ' needs an opening time before its closing time — or tick "Closed".',
                    '/settings/hours'
                );
            }

            $rows[] = [$dow, $opens, $closes];
        }

        $before = $current === null ? null : $current['rows'];
        $now    = now_utc();

        DB::transaction(function () use ($current, $rows, $label, $from, $to, $now): void {
            if ($current !== null) {
                $this->deleteSeasonRows($current);
            }

            foreach ($rows as [$dow, $opens, $closes]) {
                DB::insert('operating_hours', [
                    'day_of_week'    => $dow,
                    'opens_at'       => $opens,
                    'closes_at'      => $closes,
                    'season_label'   => mb_substr($label, 0, 40),
                    'effective_from' => $from === '' ? null : $from,
                    'effective_to'   => $to === '' ? null : $to,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]);
            }
        });

        Audit::record('operating_hours_saved', 'operating_hours', null, $before, [
            'season' => $label, 'from' => $from, 'to' => $to, 'days' => $rows,
        ]);

        return $this->success($request, $label . ' hours saved.', '/settings/hours');
    }

    public function deleteSeason(Request $request): Response
    {
        if ($denied = $this->authorize(self::MANAGE)) {
            return $denied;
        }

        $season = $this->seasonOf($request->routeInt('id'));

        if ($season === null) {
            return $this->notFound('That season does not exist.');
        }

        if ($season['from'] === null && $season['to'] === null) {
            return $this->refuse($request, 'The standard week cannot be deleted — it is what every other day falls back to.', '/settings/hours');
        }

        $this->deleteSeasonRows($season);
        Audit::record('operating_hours_season_deleted', 'operating_hours', null, $season['rows'], null);

        return $this->success($request, $season['label'] . ' deleted. Those dates follow the standard week again.', '/settings/hours');
    }

    // =================================================================
    // Writing: closures and special hours
    // =================================================================

    public function saveException(Request $request): Response
    {
        if ($denied = $this->authorize(self::MANAGE)) {
            return $denied;
        }

        $date   = trim((string) $request->input('date', ''));
        $closed = $request->bool('is_closed');
        $note   = trim((string) $request->input('note', ''));
        $opens  = self::time((string) $request->input('opens_at', ''));
        $closes = self::time((string) $request->input('closes_at', ''));

        if (!AvailabilityEngine::isDate($date)) {
            return $this->refuse($request, 'Pick a date.', '/settings/hours');
        }

        if (!$closed && ($opens === null || $closes === null || $closes <= $opens)) {
            return $this->refuse($request, 'Special hours need an opening time before the closing time — or tick "Closed all day".', '/settings/hours');
        }

        $row = [
            'is_closed' => $closed ? 1 : 0,
            'opens_at'  => $closed ? null : $opens,
            'closes_at' => $closed ? null : $closes,
            'note'      => $note === '' ? null : mb_substr($note, 0, 191),
        ];

        $existing = DB::first('SELECT * FROM {operating_exceptions} WHERE date = :d', ['d' => $date]);

        if ($existing !== null) {
            DB::update('operating_exceptions', $row, 'id = :id', ['id' => (int) $existing['id']]);
        } else {
            DB::insert('operating_exceptions', $row + ['date' => $date]);
        }

        Audit::record('operating_exception_saved', 'operating_exception', null, $existing, $row + ['date' => $date]);

        return $this->success(
            $request,
            calendar_date($date, 'l, F j') . ($closed ? ' is marked closed.' : ' has special hours.'),
            '/settings/hours'
        );
    }

    public function deleteException(Request $request): Response
    {
        if ($denied = $this->authorize(self::MANAGE)) {
            return $denied;
        }

        $id  = $request->routeInt('id');
        $row = DB::first('SELECT * FROM {operating_exceptions} WHERE id = :id', ['id' => $id]);

        if ($row === null) {
            return $this->notFound('That date has no special hours.');
        }

        DB::delete('operating_exceptions', 'id = :id', ['id' => $id]);
        Audit::record('operating_exception_deleted', 'operating_exception', $id, $row, null);

        return $this->success($request, calendar_date((string) $row['date'], 'l, F j') . ' follows the usual hours again.', '/settings/hours');
    }

    // =================================================================
    // Writing: an attraction down for the day
    // =================================================================

    public function saveOutage(Request $request): Response
    {
        if ($denied = $this->authorize(self::MANAGE)) {
            return $denied;
        }

        $attraction = $request->int('attraction_id');
        $date       = trim((string) $request->input('date', ''));
        $note       = trim((string) $request->input('note', ''));

        if (!DB::exists('SELECT id FROM {attractions} WHERE id = :id', ['id' => $attraction])) {
            return $this->refuse($request, 'Pick the attraction that is down.', '/settings/hours');
        }

        if (!AvailabilityEngine::isDate($date)) {
            return $this->refuse($request, 'Pick a date.', '/settings/hours');
        }

        $row = ['is_available' => 0, 'note' => $note === '' ? null : mb_substr($note, 0, 191)];

        $existing = DB::first(
            'SELECT * FROM {attraction_availability} WHERE attraction_id = :a AND date = :d',
            ['a' => $attraction, 'd' => $date]
        );

        if ($existing !== null) {
            DB::update('attraction_availability', $row, 'id = :id', ['id' => (int) $existing['id']]);
        } else {
            DB::insert('attraction_availability', $row + ['attraction_id' => $attraction, 'date' => $date]);
        }

        Audit::record('attraction_outage_saved', 'attraction', $attraction, $existing, $row + ['date' => $date]);

        return $this->success($request, 'Marked down for ' . calendar_date($date, 'l, F j') . '. Parties built around it will not be offered that day.', '/settings/hours');
    }

    public function deleteOutage(Request $request): Response
    {
        if ($denied = $this->authorize(self::MANAGE)) {
            return $denied;
        }

        $id  = $request->routeInt('id');
        $row = DB::first('SELECT * FROM {attraction_availability} WHERE id = :id', ['id' => $id]);

        if ($row === null) {
            return $this->notFound('That outage does not exist.');
        }

        DB::delete('attraction_availability', 'id = :id', ['id' => $id]);
        Audit::record('attraction_outage_deleted', 'attraction', (int) $row['attraction_id'], $row, null);

        return $this->success($request, 'Back in service for ' . calendar_date((string) $row['date'], 'l, F j') . '.', '/settings/hours');
    }

    // =================================================================
    // Plumbing
    // =================================================================

    /**
     * The weekly pattern, grouped into seasons: the standard week first, then
     * dated seasons in date order. A season is the rows sharing a label and a
     * date range; any one of its row ids names it.
     *
     * @return list<array{id:int,label:string,from:?string,to:?string,days:array<int,array{opens:string,closes:string}>}>
     */
    private function seasons(): array
    {
        $seasons = [];

        foreach (DB::all('SELECT * FROM {operating_hours} ORDER BY effective_from IS NOT NULL, effective_from, day_of_week, id') as $row) {
            $key = ($row['season_label'] ?? '') . '|' . ($row['effective_from'] ?? '') . '|' . ($row['effective_to'] ?? '');

            $seasons[$key] ??= [
                'id'    => (int) $row['id'],
                'label' => (string) ($row['season_label'] ?? 'Standard'),
                'from'  => $row['effective_from'] === null ? null : (string) $row['effective_from'],
                'to'    => $row['effective_to'] === null ? null : (string) $row['effective_to'],
                'days'  => [],
            ];

            $seasons[$key]['days'][(int) $row['day_of_week']] = [
                'opens'  => substr((string) $row['opens_at'], 0, 5),
                'closes' => substr((string) $row['closes_at'], 0, 5),
            ];
        }

        $list = array_values($seasons);

        // A venue that somehow has no undated week gets an empty standard one
        // to fill in, rather than a page with nothing to edit.
        if (!array_filter($list, static fn (array $s): bool => $s['from'] === null && $s['to'] === null)) {
            array_unshift($list, ['id' => 0, 'label' => 'Standard', 'from' => null, 'to' => null, 'days' => []]);
        }

        return $list;
    }

    /** @return array{label:string,from:?string,to:?string,rows:list<array<string,mixed>>}|null */
    private function seasonOf(int $rowId): ?array
    {
        $row = $rowId > 0 ? DB::first('SELECT * FROM {operating_hours} WHERE id = :id', ['id' => $rowId]) : null;

        if ($row === null) {
            return null;
        }

        $from = $row['effective_from'] === null ? null : (string) $row['effective_from'];
        $to   = $row['effective_to'] === null ? null : (string) $row['effective_to'];

        return [
            'label' => (string) ($row['season_label'] ?? ''),
            'from'  => $from,
            'to'    => $to,
            'rows'  => DB::all(
                'SELECT * FROM {operating_hours}
                  WHERE COALESCE(season_label, \'\') = :l
                    AND COALESCE(effective_from, \'\') = :f AND COALESCE(effective_to, \'\') = :t',
                ['l' => (string) ($row['season_label'] ?? ''), 'f' => $from ?? '', 't' => $to ?? '']
            ),
        ];
    }

    /** @param array{rows:list<array<string,mixed>>} $season */
    private function deleteSeasonRows(array $season): void
    {
        foreach ($season['rows'] as $row) {
            DB::delete('operating_hours', 'id = :id', ['id' => (int) $row['id']]);
        }
    }

    /** "HH:MM" from a time input, or null. */
    private static function time(string $value): ?string
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value . ':00' : null;
    }
}
