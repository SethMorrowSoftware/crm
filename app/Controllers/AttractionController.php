<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;

/**
 * Settings → Attractions: what the venue has, when each is open for the
 * season, who may ride it, and how many at a time.
 *
 * The availability engine reads every one of these — an outdoor ride out of
 * season is not offered, a party is built around a "main" attraction, a
 * capacity caps a slot — and only the installer's seed could set them. When
 * the go-karts open a week early, or the bumper boats close for good, that
 * had to be a database edit. `attractions_manage` covers it, as the catalogue
 * has always said.
 *
 * Nothing is deleted: packages, add-ons, resources and past bookings point at
 * attractions. Switching one off takes it out of everything new.
 */
final class AttractionController extends Controller
{
    private const MANAGE = 'attractions_manage';

    public function index(Request $request): Response
    {
        return $this->view('attractions/index', [
            'pageTitle'   => 'Attractions',
            'attractions' => DB::all('SELECT * FROM {attractions} ORDER BY is_active DESC, sort_order, name'),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->view('attractions/form', ['pageTitle' => 'New attraction', 'attraction' => null]);
    }

    public function edit(Request $request): Response
    {
        $attraction = $this->find($request->routeInt('id'));

        if ($attraction === null) {
            return $this->notFound('That attraction does not exist.');
        }

        return $this->view('attractions/form', ['pageTitle' => (string) $attraction['name'], 'attraction' => $attraction]);
    }

    public function store(Request $request): Response
    {
        return $this->save($request, 0);
    }

    public function update(Request $request): Response
    {
        return $this->save($request, $request->routeInt('id'));
    }

    private function save(Request $request, int $id): Response
    {
        if ($denied = $this->authorize(self::MANAGE)) {
            return $denied;
        }

        $before = null;

        if ($id > 0) {
            $before = $this->find($id);

            if ($before === null) {
                return $this->notFound('That attraction does not exist.');
            }
        }

        $back = $id > 0 ? '/settings/attractions/' . $id : '/settings/attractions/new';

        $result = $this->validate($request, [
            'name'              => 'required|string|max:120',
            'category'          => 'nullable|string|max:40',
            'description'       => 'nullable|string|max:2000',
            'min_age'           => 'nullable|int|min:0|max:99',
            'min_height_in'     => 'nullable|int|min:0|max:96',
            'max_height_in'     => 'nullable|int|min:0|max:96',
            'capacity_per_slot' => 'nullable|int|min:1|max:5000',
            'slot_minutes'      => 'nullable|int|min:1|max:720',
            'sort_order'        => 'nullable|int|min:0|max:9999',
        ], [
            'name'              => 'Name',
            'category'          => 'Category',
            'description'       => 'Description',
            'min_age'           => 'Minimum age',
            'min_height_in'     => 'Minimum height',
            'max_height_in'     => 'Maximum height',
            'capacity_per_slot' => 'People at a time',
            'slot_minutes'      => 'Slot length',
            'sort_order'        => 'Position',
        ]);

        if ($result instanceof Response) {
            return $result;
        }

        $min = self::number($result['min_height_in'] ?? null);
        $max = self::number($result['max_height_in'] ?? null);

        if ($min !== null && $max !== null && $max < $min) {
            return $this->refuse($request, 'The maximum height is below the minimum.', $back);
        }

        $seasonal = $request->bool('is_seasonal');
        $start    = self::monthDay((string) $request->input('season_start_month', ''), (string) $request->input('season_start_day', ''));
        $end      = self::monthDay((string) $request->input('season_end_month', ''), (string) $request->input('season_end_day', ''));

        if ($seasonal && ($start === null || $end === null)) {
            return $this->refuse($request, 'A seasonal attraction needs the day its season opens and the day it closes.', $back);
        }

        $description = trim((string) ($result['description'] ?? ''));
        $category    = trim((string) ($result['category'] ?? ''));

        $columns = [
            'name'              => trim((string) $result['name']),
            'category'          => $category === '' ? null : $category,
            'description'       => $description === '' ? null : $description,
            'is_indoor'         => $request->bool('is_indoor') ? 1 : 0,
            'is_seasonal'       => $seasonal ? 1 : 0,
            // A season that wraps the new year (11-15 to 03-15) is fine: the
            // availability engine reads it as wrapping.
            'season_start_md'   => $seasonal ? $start : null,
            'season_end_md'     => $seasonal ? $end : null,
            'min_age'           => self::number($result['min_age'] ?? null),
            'min_height_in'     => $min,
            'max_height_in'     => $max,
            'capacity_per_slot' => self::number($result['capacity_per_slot'] ?? null),
            'slot_minutes'      => self::number($result['slot_minutes'] ?? null),
            'is_party_eligible' => $request->bool('is_party_eligible') ? 1 : 0,
            'is_party_main'     => $request->bool('is_party_main') ? 1 : 0,
            'requires_waiver'   => $request->bool('requires_waiver') ? 1 : 0,
            'is_active'         => $request->bool('is_active') ? 1 : 0,
            'updated_at'        => now_utc(),
        ];

        if ($columns['is_party_main'] === 1 && $columns['is_party_eligible'] === 0) {
            // A party built around something it may not include is a contradiction.
            $columns['is_party_eligible'] = 1;
        }

        $sort = (string) ($result['sort_order'] ?? '');

        if ($sort !== '') {
            $columns['sort_order'] = (int) $sort;
        }

        if ($before !== null) {
            DB::update('attractions', $columns, 'id = :id', ['id' => $id]);
            Audit::record('attraction_updated', 'attraction', $id, $before, $columns);

            return $this->success($request, (string) $columns['name'] . ' saved.', '/settings/attractions');
        }

        $columns['slug']       = $this->slug((string) $columns['name']);
        $columns['created_at'] = now_utc();
        $columns['sort_order'] ??= 10 + (int) DB::value('SELECT COALESCE(MAX(sort_order), 0) FROM {attractions}');

        $newId = (int) DB::insert('attractions', $columns);
        Audit::record('attraction_created', 'attraction', $newId, null, $columns);

        return $this->success($request, (string) $columns['name'] . ' added.', '/settings/attractions');
    }

    /** @return array<string,mixed>|null */
    private function find(int $id): ?array
    {
        return $id > 0 ? DB::first('SELECT * FROM {attractions} WHERE id = :id', ['id' => $id]) : null;
    }

    private static function number(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    /** "MM-DD", or null. 29 February is allowed: a leap-year season is a real season. */
    public static function monthDay(string $month, string $day): ?string
    {
        $m = (int) $month;
        $d = (int) $day;

        if ($month === '' || $day === '' || !checkdate($m, $d, 2000)) {
            return null;
        }

        return sprintf('%02d-%02d', $m, $d);
    }

    private function slug(string $name): string
    {
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? '', '-');
        $base = $base === '' ? 'attraction' : substr($base, 0, 56);
        $slug = $base;

        for ($n = 2; DB::exists('SELECT id FROM {attractions} WHERE slug = :s', ['s' => $slug]); $n++) {
            $slug = $base . '-' . $n;
        }

        return $slug;
    }
}
