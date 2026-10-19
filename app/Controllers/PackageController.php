<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;

/**
 * Party packages and add-ons: what the venue sells, and for how much.
 *
 * Until this existed the only way to change a party's price was to edit the
 * database, so the seeded prices were the prices — including the four the
 * first seed copied off the website, a cent out on three of them and missing
 * the party that is half the venue's bookings.
 *
 * `attractions_manage`, which the permission catalogue has always described
 * as "Edit attractions, packages and resources": a front desk that books a
 * party must not be able to decide what one costs.
 *
 * A SLUG NEVER CHANGES AFTER CREATION. Membership plans regenerate theirs from
 * the name on every save, which is harmless there; here the upgrade files and
 * the CenterEdge import find a package by its slug, and renaming "Laser Tag
 * Birthday" must not quietly make it a different package to both.
 */
final class PackageController extends Controller
{
    private const MANAGE = 'attractions_manage';

    private const ADDON_UNITS = ['flat', 'per_guest', 'per_hour'];

    // =================================================================
    // Reading
    // =================================================================

    public function index(Request $request): Response
    {
        $since = gmdate('Y-m-d', strtotime('-12 months'));

        return $this->view('packages/index', [
            'pageTitle' => 'Party packages',
            'packages'  => DB::all(
                'SELECT p.*, a.name AS attraction_name,
                        (SELECT COUNT(*) FROM {bookings} b WHERE b.package_id = p.id) AS bookings_total,
                        (SELECT COUNT(*) FROM {bookings} b
                          WHERE b.package_id = p.id AND b.event_date >= :since
                            AND b.deleted_at IS NULL) AS bookings_year
                   FROM {party_packages} p
                   LEFT JOIN {attractions} a ON a.id = p.main_attraction_id
                  ORDER BY p.is_active DESC, p.sort_order, p.name',
                ['since' => $since]
            ),
            'addons'    => DB::all(
                'SELECT d.*, a.name AS attraction_name,
                        (SELECT COUNT(*) FROM {booking_addons} ba WHERE ba.addon_id = d.id) AS used
                   FROM {package_addons} d
                   LEFT JOIN {attractions} a ON a.id = d.attraction_id
                  ORDER BY d.is_active DESC, d.sort_order, d.name'
            ),
            'attractions' => $this->attractions(),
            'units'       => self::ADDON_UNITS,
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->view('packages/form', [
            'pageTitle'   => 'New party package',
            'package'     => null,
            'attractions' => $this->attractions(true),
            'bookings'    => 0,
        ]);
    }

    public function edit(Request $request): Response
    {
        $package = $this->find($request->routeInt('id'));

        if ($package === null) {
            return $this->notFound('That package does not exist.');
        }

        return $this->view('packages/form', [
            'pageTitle'   => (string) $package['name'],
            'package'     => $package,
            'attractions' => $this->attractions(true),
            'bookings'    => $this->bookingCount((int) $package['id']),
        ]);
    }

    // =================================================================
    // Writing: packages
    // =================================================================

    public function store(Request $request): Response
    {
        return $this->save($request, 0);
    }

    public function update(Request $request): Response
    {
        return $this->save($request, $request->routeInt('id'));
    }

    /**
     * Delete a package nobody has booked.
     *
     * A package with bookings is NOT deleted, even though the foreign key
     * would allow it: `ON DELETE SET NULL` would turn every one of those
     * bookings into "no package", and the run sheets, the reports by party
     * type and the history of what a family had all go with it. Taking it off
     * sale does what the operator actually wants.
     */
    public function destroy(Request $request): Response
    {
        if ($denied = $this->authorize(self::MANAGE)) {
            return $denied;
        }

        $id = $request->routeInt('id');
        $package = $this->find($id);

        if ($package === null) {
            return $this->notFound('That package does not exist.');
        }

        $bookings = $this->bookingCount($id);

        if ($bookings > 0) {
            return $this->refuse(
                $request,
                sprintf(
                    '%s has %d booking%s, so it cannot be deleted without losing what those parties were. '
                    . 'Untick "Active" to take it off sale instead.',
                    (string) $package['name'],
                    $bookings,
                    $bookings === 1 ? '' : 's'
                ),
                '/settings/packages/' . $id
            );
        }

        DB::delete('party_packages', 'id = :id', ['id' => $id]);
        Audit::record('party_package_deleted', 'party_package', $id, $package, null);

        return $this->success($request, (string) $package['name'] . ' deleted.', '/settings/packages');
    }

    // =================================================================
    // Writing: add-ons
    // =================================================================

    public function editAddon(Request $request): Response
    {
        $id = $request->routeInt('id');
        $addon = DB::first('SELECT * FROM {package_addons} WHERE id = :id', ['id' => $id]);

        if ($addon === null) {
            return $this->notFound('That add-on does not exist.');
        }

        return $this->view('packages/addon', [
            'pageTitle'   => (string) $addon['name'],
            'addon'       => $addon,
            'attractions' => $this->attractions(),
            'units'       => self::ADDON_UNITS,
            'used'        => (int) DB::value('SELECT COUNT(*) FROM {booking_addons} WHERE addon_id = :id', ['id' => $id]),
        ]);
    }

    public function storeAddon(Request $request): Response
    {
        return $this->saveAddon($request, 0);
    }

    public function updateAddon(Request $request): Response
    {
        return $this->saveAddon($request, $request->routeInt('id'));
    }

    /** As with packages: an add-on that is on a booking is retired, not deleted. */
    public function destroyAddon(Request $request): Response
    {
        if ($denied = $this->authorize(self::MANAGE)) {
            return $denied;
        }

        $id = $request->routeInt('id');
        $addon = DB::first('SELECT * FROM {package_addons} WHERE id = :id', ['id' => $id]);

        if ($addon === null) {
            return $this->notFound('That add-on does not exist.');
        }

        $used = (int) DB::value('SELECT COUNT(*) FROM {booking_addons} WHERE addon_id = :id', ['id' => $id]);

        if ($used > 0) {
            return $this->refuse(
                $request,
                sprintf(
                    '%s is on %d booking%s, so it cannot be deleted. Untick "Active" to stop offering it.',
                    (string) $addon['name'],
                    $used,
                    $used === 1 ? '' : 's'
                ),
                '/settings/packages'
            );
        }

        DB::delete('package_addons', 'id = :id', ['id' => $id]);
        Audit::record('package_addon_deleted', 'package_addon', $id, $addon, null);

        return $this->success($request, (string) $addon['name'] . ' deleted.', '/settings/packages');
    }

    // =================================================================
    // Plumbing
    // =================================================================

    private function save(Request $request, int $id): Response
    {
        if ($denied = $this->authorize(self::MANAGE)) {
            return $denied;
        }

        $before = null;

        if ($id > 0) {
            $before = $this->find($id);

            if ($before === null) {
                return $this->notFound('That package does not exist.');
            }
        }

        $result = $this->validate($request, [
            'name'                   => 'required|string|max:120',
            'description'            => 'nullable|string|max:2000',
            'includes'               => 'nullable|string|max:2000',
            'main_attraction_id'     => 'nullable|int|exists:attractions',
            'base_price'             => 'required|numeric|min:0|max:100000',
            'included_guests'        => 'required|int|min:1|max:500',
            'additional_guest_price' => 'required|numeric|min:0|max:10000',
            'deposit'                => 'nullable|numeric|min:0|max:100000',
            'duration_minutes'       => 'required|int|min:15|max:720',
            'min_notice_days'        => 'required|int|min:0|max:365',
            'sort_order'             => 'nullable|int|min:0|max:9999',
        ], [
            'name'                   => 'Name',
            'description'            => 'Description',
            'includes'               => 'What is included',
            'main_attraction_id'     => 'Main activity',
            'base_price'             => 'Price',
            'included_guests'        => 'Guests included',
            'additional_guest_price' => 'Each additional guest',
            'deposit'                => 'Deposit',
            'duration_minutes'       => 'Length',
            'min_notice_days'        => 'Online notice',
            'sort_order'             => 'Position',
        ]);

        if ($result instanceof Response) {
            return $result;
        }

        $includes = array_values(array_filter(
            array_map('trim', preg_split('/\R/', (string) ($result['includes'] ?? '')) ?: []),
            static fn (string $line): bool => $line !== ''
        ));

        $attraction = (int) ($result['main_attraction_id'] ?? 0);
        $description = trim((string) ($result['description'] ?? ''));

        $columns = [
            'name'                   => trim((string) $result['name']),
            'description'            => $description === '' ? null : $description,
            'includes'               => $includes === []
                ? null
                : json_encode(array_slice($includes, 0, 12), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'main_attraction_id'     => $attraction > 0 ? $attraction : null,
            'base_price_cents'       => self::cents($result['base_price']),
            'included_guests'        => (int) $result['included_guests'],
            'additional_guest_cents' => self::cents($result['additional_guest_price']),
            'deposit_cents'          => self::cents($result['deposit'] ?? 0),
            'duration_minutes'       => (int) $result['duration_minutes'],
            'min_notice_days'        => (int) $result['min_notice_days'],
            'is_seasonal'            => $request->bool('is_seasonal') ? 1 : 0,
            'is_active'              => $request->bool('is_active') ? 1 : 0,
            'is_online'              => $request->bool('is_online') ? 1 : 0,
            'updated_at'             => now_utc(),
        ];

        $sort = (string) ($result['sort_order'] ?? '');

        if ($sort !== '') {
            $columns['sort_order'] = (int) $sort;
        }

        if ($before !== null) {
            DB::update('party_packages', $columns, 'id = :id', ['id' => $id]);
            Audit::record('party_package_updated', 'party_package', $id, $before, $columns);

            return $this->success($request, 'Package saved.', '/settings/packages');
        }

        $columns['slug'] = $this->slug((string) $columns['name']);
        $columns['created_at'] = now_utc();
        $columns['sort_order'] ??= 10 + (int) DB::value('SELECT COALESCE(MAX(sort_order), 0) FROM {party_packages}');

        $newId = (int) DB::insert('party_packages', $columns);
        Audit::record('party_package_created', 'party_package', $newId, null, $columns);

        return $this->success($request, 'Package created.', '/settings/packages');
    }

    private function saveAddon(Request $request, int $id): Response
    {
        if ($denied = $this->authorize(self::MANAGE)) {
            return $denied;
        }

        $before = null;

        if ($id > 0) {
            $before = DB::first('SELECT * FROM {package_addons} WHERE id = :id', ['id' => $id]);

            if ($before === null) {
                return $this->notFound('That add-on does not exist.');
            }
        }

        $result = $this->validate($request, [
            'name'          => 'required|string|max:120',
            'price'         => 'required|numeric|min:0|max:100000',
            'unit'          => 'required|in:' . implode(',', self::ADDON_UNITS),
            'attraction_id' => 'nullable|int|exists:attractions',
            'description'   => 'nullable|string|max:500',
        ], [
            'name'          => 'Name',
            'price'         => 'Price',
            'unit'          => 'Charged',
            'attraction_id' => 'Attraction',
            'description'   => 'Description',
        ]);

        if ($result instanceof Response) {
            return $result;
        }

        $attraction = (int) ($result['attraction_id'] ?? 0);
        $description = trim((string) ($result['description'] ?? ''));

        $columns = [
            'name'          => trim((string) $result['name']),
            'price_cents'   => self::cents($result['price']),
            'unit'          => (string) $result['unit'],
            'attraction_id' => $attraction > 0 ? $attraction : null,
            'description'   => $description === '' ? null : $description,
            'is_active'     => $request->bool('is_active') ? 1 : 0,
            'updated_at'    => now_utc(),
        ];

        if ($before !== null) {
            DB::update('package_addons', $columns, 'id = :id', ['id' => $id]);
            Audit::record('package_addon_updated', 'package_addon', $id, $before, $columns);

            return $this->success($request, 'Add-on saved.', '/settings/packages');
        }

        $columns['created_at'] = now_utc();
        $columns['sort_order'] = 10 + (int) DB::value('SELECT COALESCE(MAX(sort_order), 0) FROM {package_addons}');

        $newId = (int) DB::insert('package_addons', $columns);
        Audit::record('package_addon_created', 'package_addon', $newId, null, $columns);

        return $this->success($request, 'Add-on created.', '/settings/packages');
    }

    /**
     * Dollars as typed to integer cents. The validator has already refused
     * anything that is not a number; round() rather than a cast, because
     * 349.99 * 100 is 34998.999… in floating point.
     */
    private static function cents(mixed $dollars): int
    {
        return max(0, (int) round((float) $dollars * 100));
    }

    /** @return array<string,mixed>|null */
    private function find(int $id): ?array
    {
        return $id > 0 ? DB::first('SELECT * FROM {party_packages} WHERE id = :id', ['id' => $id]) : null;
    }

    private function bookingCount(int $packageId): int
    {
        return (int) DB::value('SELECT COUNT(*) FROM {bookings} WHERE package_id = :id', ['id' => $packageId]);
    }

    /**
     * Attractions for a picker. A package is built AROUND a party-main
     * attraction (the arcade cannot be one), so the package form offers only
     * those; an add-on can be any attraction.
     *
     * @return list<array{id:int,name:string}>
     */
    private function attractions(bool $partyMainOnly = false): array
    {
        return DB::all(
            'SELECT id, name FROM {attractions}'
            . ($partyMainOnly ? ' WHERE is_party_main = 1' : '')
            . ' ORDER BY sort_order, name'
        );
    }

    private function slug(string $name): string
    {
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? '', '-');
        $base = $base === '' ? 'package' : substr($base, 0, 56);
        $slug = $base;

        for ($n = 2; DB::exists('SELECT id FROM {party_packages} WHERE slug = :s', ['s' => $slug]); $n++) {
            $slug = $base . '-' . $n;
        }

        return $slug;
    }
}
