<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;

/**
 * Settings → Offers: the codes automations hand out and the desk redeems.
 *
 * The seed made three (WELCOME10, BIRTHDAY, COMEBACK) and nothing could make
 * a fourth, change what one is worth or when it ends, though `coupons_manage`
 * had been in the permission catalogue since phase 1.
 *
 * As with templates, an edit must not break a send:
 *
 *  - **A code never changes.** An automation step issues an offer by code,
 *    and a guest is holding the code in a text.
 *  - **An offer a switched-on automation issues cannot be switched off**; the
 *    step would be skipped on its next run.
 *  - **Dates are the venue's days.** An offer "until December 31" lasts until
 *    the end of the 31st in Chester. Stored as UTC, which the automation
 *    compares against; stored as a bare midnight it would have ended at 7pm
 *    on the 30th.
 */
final class CouponController extends Controller
{
    private const MANAGE = 'coupons_manage';

    public const TYPES = [
        'percent'   => 'Percent off',
        'fixed'     => 'Dollars off',
        'free_item' => 'A free item',
        'bogo'      => 'Buy one, get one',
    ];

    public function index(Request $request): Response
    {
        return $this->view('coupons/index', [
            'pageTitle' => 'Offers',
            'coupons'   => DB::all(
                'SELECT c.*,
                        (SELECT COUNT(*) FROM {coupon_redemptions} r WHERE r.coupon_id = c.id AND r.issued_at IS NOT NULL) AS issued,
                        (SELECT COUNT(*) FROM {coupon_redemptions} r WHERE r.coupon_id = c.id AND r.redeemed_at IS NOT NULL) AS redeemed
                   FROM {coupons} c
                  ORDER BY c.is_active DESC, c.code'
            ),
            'uses'      => $this->uses(),
            'types'     => self::TYPES,
        ]);
    }

    public function edit(Request $request): Response
    {
        $coupon = $this->find($request->routeInt('id'));

        if ($coupon === null) {
            return $this->notFound('That offer does not exist.');
        }

        return $this->view('coupons/form', [
            'pageTitle' => (string) $coupon['code'],
            'coupon'    => $coupon,
            'types'     => self::TYPES,
            'uses'      => $this->uses()[(string) $coupon['code']] ?? ['all' => 0, 'live' => 0],
        ]);
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
                return $this->notFound('That offer does not exist.');
            }
        }

        $back = $id > 0 ? '/settings/offers/' . $id : '/settings/offers';

        $result = $this->validate($request, [
            'name'            => 'required|string|max:120',
            'type'            => 'required|in:' . implode(',', array_keys(self::TYPES)),
            'value'           => 'nullable|numeric|min:0|max:10000',
            'min_spend'       => 'nullable|numeric|min:0|max:100000',
            'max_redemptions' => 'nullable|int|min:1|max:1000000',
            'max_per_contact' => 'required|int|min:1|max:1000',
        ], [
            'name'            => 'Name',
            'type'            => 'Kind of offer',
            'value'           => 'Value',
            'min_spend'       => 'Minimum spend',
            'max_redemptions' => 'Total uses',
            'max_per_contact' => 'Uses per guest',
        ]);

        if ($result instanceof Response) {
            return $result;
        }

        $type  = (string) $result['type'];
        $value = round((float) ($result['value'] ?? 0), 2);

        if ($type === 'percent' && ($value <= 0 || $value > 100)) {
            return $this->refuse($request, 'A percent-off offer needs a percentage between 1 and 100.', $back);
        }

        if ($type === 'fixed' && $value <= 0) {
            return $this->refuse($request, 'A dollars-off offer needs an amount.', $back);
        }

        $starts  = self::dayStart((string) $request->input('starts_on', ''));
        $expires = self::dayEnd((string) $request->input('expires_on', ''));

        if ($starts !== null && $expires !== null && $expires < $starts) {
            return $this->refuse($request, 'The offer ends before it starts.', $back);
        }

        $active = $request->bool('is_active');

        if ($before !== null && !$active && (int) $before['is_active'] === 1) {
            $live = $this->uses()[(string) $before['code']]['live'] ?? 0;

            if ($live > 0) {
                return $this->refuse(
                    $request,
                    sprintf(
                        '%d switched-on automation%s hand%s this offer out, so it cannot be switched off. Switch %s off first.',
                        $live,
                        $live === 1 ? '' : 's',
                        $live === 1 ? 's' : '',
                        $live === 1 ? 'it' : 'them'
                    ),
                    $back
                );
            }
        }

        $maxTotal = (string) ($result['max_redemptions'] ?? '');

        $columns = [
            'name'            => trim((string) $result['name']),
            'type'            => $type,
            'value'           => in_array($type, ['percent', 'fixed'], true) ? $value : 0,
            'min_spend_cents' => max(0, (int) round((float) ($result['min_spend'] ?? 0) * 100)),
            'max_redemptions' => $maxTotal === '' ? null : (int) $maxTotal,
            'max_per_contact' => (int) $result['max_per_contact'],
            'starts_at'       => $starts,
            'expires_at'      => $expires,
            'is_active'       => $active ? 1 : 0,
            'updated_at'      => now_utc(),
        ];

        if ($before !== null) {
            DB::update('coupons', $columns, 'id = :id', ['id' => $id]);
            Audit::record('coupon_updated', 'coupon', $id, $before, $columns);

            return $this->success($request, (string) $before['code'] . ' saved.', '/settings/offers');
        }

        $code = strtoupper(trim((string) $request->input('code', '')));

        if (preg_match('/^[A-Z0-9][A-Z0-9-]{2,39}$/', $code) !== 1) {
            return $this->refuse($request, 'A code is 3 to 40 letters, digits or dashes, like FALL20.', $back);
        }

        if (DB::exists('SELECT id FROM {coupons} WHERE code = :c', ['c' => $code])) {
            return $this->refuse($request, $code . ' is already an offer. Codes are never reused.', $back);
        }

        $columns['code']             = $code;
        $columns['redemption_count'] = 0;
        $columns['created_at']       = now_utc();

        $newId = (int) DB::insert('coupons', $columns);
        Audit::record('coupon_created', 'coupon', $newId, null, $columns);

        return $this->success($request, $code . ' created.', '/settings/offers');
    }

    // =================================================================
    // Plumbing
    // =================================================================

    /**
     * How many automation steps issue each code, and how many of those belong
     * to a switched-on automation.
     *
     * @return array<string,array{all:int,live:int}>
     */
    private function uses(): array
    {
        $uses = [];

        foreach (DB::all(
            "SELECT s.config, a.is_active FROM {automation_steps} s
               JOIN {automations} a ON a.id = s.automation_id
              WHERE s.type = 'issue_coupon'"
        ) as $step) {
            $config = json_decode((string) $step['config'], true);
            $code   = is_array($config) ? strtoupper(trim((string) ($config['code'] ?? ''))) : '';

            if ($code === '') {
                continue;
            }

            $uses[$code] ??= ['all' => 0, 'live' => 0];
            $uses[$code]['all']++;

            if ((int) $step['is_active'] === 1) {
                $uses[$code]['live']++;
            }
        }

        return $uses;
    }

    /** @return array<string,mixed>|null */
    private function find(int $id): ?array
    {
        return $id > 0 ? DB::first('SELECT * FROM {coupons} WHERE id = :id', ['id' => $id]) : null;
    }

    /** The first moment of a venue day, in UTC. */
    public static function dayStart(string $date): ?string
    {
        return self::venueMoment($date, '00:00:00');
    }

    /** The last moment of a venue day, in UTC. */
    public static function dayEnd(string $date): ?string
    {
        return self::venueMoment($date, '23:59:59');
    }

    private static function venueMoment(string $date, string $time): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        $moment = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $date . ' ' . $time, venue_tz());

        return $moment === false ? null : $moment->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
