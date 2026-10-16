<?php

declare(strict_types=1);

namespace App\Services;

/**
 * What a party costs.
 *
 * `docs/SPEC.md` §8.5 specifies this as a pure, unit-tested service, and it is
 * the right call: this is the one place in the application where getting the
 * arithmetic wrong means a family is quoted one figure and charged another.
 * Nothing here reads the database, the clock, the session or the settings —
 * every input is passed in, so every case can be written as a test.
 *
 * THREE RULES HOLD THROUGHOUT, and they are the same three QuoteController
 * follows, because a booking priced from a package must agree to the cent with
 * a quote typed by hand.
 *
 *   1. **Integer cents, and no float anywhere in the path.** 0.1 has no exact
 *      binary representation; a cent of drift per booking is a real
 *      reconciliation problem against the POS at the end of a season. Every
 *      fractional quantity is carried as integer HUNDREDTHS and divided once,
 *      rounding half away from zero — `intdiv()` truncates towards zero, which
 *      would systematically under-charge on every half-hour add-on.
 *   2. **A discount may zero a party but never invert it.** A negative total
 *      reads as the venue owing the guest money.
 *   3. **The breakdown is returned, not just the total.** The run sheet, the
 *      quote and the guest's own confirmation all have to show their working,
 *      and a figure nobody can explain at the front desk is a figure that gets
 *      overridden by hand.
 *
 * THE TAX RATE IS IN THOUSANDTHS OF ONE PERCENT, which is not fussiness. The
 * venue is in Chester, Orange County NY: 4% state + 3.75% county + 0.375%
 * MCTD = **8.125%**. Basis points (hundredths of a percent) cannot hold that
 * third decimal, so the rate would have to be rounded before it was ever
 * applied. Thousandths can: 8.125% is 8125, and the divisor is 100,000.
 * (Whether a given party is taxable at all, and at what rate, is the venue's
 * accountant's call — see `Booking::TAX_RATE_SETTING` and §14.)
 */
final class QuoteCalculator
{
    /** The add-on units `cc_package_addons.unit` declares. Read, not guessed. */
    public const UNITS = ['flat', 'per_guest', 'per_hour'];

    /** $999,999.99 for one unit. More than that is a typo, not a party. */
    public const MAX_UNIT_CENTS = 99999999;

    /** Quantity ceiling in hundredths: 10,000.00. */
    public const MAX_QTY_HUNDREDTHS = 1000000;

    /** A party cannot run longer than a day. Also the overflow bound below. */
    public const MAX_DURATION_MINUTES = 1440;

    /** 2,000 guests. The Event Center Hall does not hold a tenth of that. */
    public const MAX_GUESTS = 2000;

    /** 100% in thousandths of a percent. */
    public const MAX_RATE = 100000;

    /**
     * Price a party.
     *
     * Every money figure in the result is derived here; nothing a form posted
     * is passed through. The caller stores what this returns, so a tampered
     * `total_cents` field in a POST body cannot become the price.
     *
     * @param array{
     *     base_price_cents?:int|string,
     *     included_guests?:int|string,
     *     additional_guest_cents?:int|string,
     *     duration_minutes?:int|string,
     *     deposit_cents?:int|string,
     *     deposit_percent?:float|string|null
     * } $package A row from `cc_party_packages`, or any subset of it. An empty
     *            array prices a party with no package, which is a real case:
     *            a group booking quoted entirely from add-ons.
     * @param list<array{
     *     addon_id?:int|null,
     *     name?:string,
     *     unit?:string,
     *     price_cents?:int|string,
     *     qty_hundredths?:int|string
     * }> $addons
     * @param array{
     *     discount_cents?:int|string,
     *     discount_mode?:string,
     *     discount_input?:int|string,
     *     tax_rate?:int|string,
     *     tax_exempt?:bool,
     *     tax_cents?:int|string|null,
     *     deposit_cents?:int|string|null,
     *     duration_minutes?:int|string
     * } $options
     * @return array<string,mixed>
     */
    public static function price(array $package, int $guestCount, array $addons = [], array $options = []): array
    {
        $guests  = self::clamp($guestCount, 0, self::MAX_GUESTS);
        $minutes = self::clamp(
            (int) ($options['duration_minutes'] ?? $package['duration_minutes'] ?? 0),
            0,
            self::MAX_DURATION_MINUTES
        );

        $base     = self::clamp((int) ($package['base_price_cents'] ?? 0), 0, PHP_INT_MAX);
        $included = self::clamp((int) ($package['included_guests'] ?? 0), 0, self::MAX_GUESTS);
        $perExtra = self::clamp((int) ($package['additional_guest_cents'] ?? 0), 0, self::MAX_UNIT_CENTS);

        // The guests the package already covers are not charged again. A party
        // UNDER the included headcount pays the base price and no less: that is
        // what "includes 8 guests" means, and refunding the difference would
        // make the package cheaper than its own floor.
        $extraGuests = max(0, $guests - $included);
        $guestsCents = $extraGuests * $perExtra;

        $lines      = [];
        $addonCents = 0;

        foreach ($addons as $addon) {
            $line        = self::addonLine($addon, $guests, $minutes);
            $lines[]     = $line;
            $addonCents += $line['total_cents'];
        }

        $subtotal = $base + $guestsCents + $addonCents;

        // A percentage discount arrives in hundredths of a percent, so 100% is
        // 10000 and 33.33% is 3333 — the same encoding QuoteController uses.
        $mode = (string) ($options['discount_mode'] ?? 'amount');

        $discount = $mode === 'percent'
            ? self::divideRound($subtotal * self::clamp((int) ($options['discount_input'] ?? 0), 0, 10000), 10000)
            : (int) ($options['discount_cents'] ?? $options['discount_input'] ?? 0);

        $discount = self::clamp($discount, 0, $subtotal);
        $taxable  = $subtotal - $discount;

        // The rate is applied to what is actually owed, after the discount —
        // §8.5's formula, and the way a register does it.
        $rate = self::clamp((int) ($options['tax_rate'] ?? 0), 0, self::MAX_RATE);

        if (($options['tax_exempt'] ?? false) === true) {
            $rate = 0;
        }

        // An explicit tax figure wins over the rate, because somebody at the
        // desk occasionally has to match a POS receipt to the cent and the
        // rate alone cannot always get there. Passing null (or leaving it out)
        // means "work it out", which is the normal path; passing 0 means
        // "no tax", which is not the same thing as leaving it out.
        $taxOverride = $options['tax_cents'] ?? null;

        $tax = $taxOverride === null
            ? self::divideRound($taxable * $rate, self::MAX_RATE)
            : self::clamp((int) $taxOverride, 0, PHP_INT_MAX);

        $total = $taxable + $tax;

        // deposit = package.deposit_cents OR total × deposit_percent, in that
        // order of preference, and an override beats both. Capped at the total:
        // a deposit larger than the party is a data-entry error that would
        // otherwise show the guest a negative balance.
        $deposit = self::deposit($package, $total, $options);

        return [
            'guest_count'      => $guests,
            'included_guests'  => $included,
            'extra_guests'     => $extraGuests,
            'duration_minutes' => $minutes,

            'base_cents'       => $base,
            'per_extra_cents'  => $perExtra,
            'guests_cents'     => $guestsCents,
            'addon_lines'      => $lines,
            'addons_cents'     => $addonCents,

            'subtotal_cents'   => $subtotal,
            'discount_cents'   => $discount,
            'taxable_cents'    => $taxable,
            'tax_rate'         => $rate,
            'tax_cents'        => $tax,
            'tax_estimated'    => $taxOverride === null,
            'total_cents'      => $total,
            'deposit_cents'    => $deposit,
            'balance_cents'    => $total - $deposit,
        ];
    }

    /**
     * One add-on line.
     *
     * @param array<string,mixed> $addon
     * @return array{addon_id:int|null,name:string,unit:string,price_cents:int,qty_hundredths:int,basis:string,total_cents:int}
     */
    public static function addonLine(array $addon, int $guests, int $minutes): array
    {
        $unit = (string) ($addon['unit'] ?? 'flat');

        if (!in_array($unit, self::UNITS, true)) {
            $unit = 'flat';
        }

        $price = self::clamp((int) ($addon['price_cents'] ?? 0), 0, self::MAX_UNIT_CENTS);
        $qty   = self::clamp((int) ($addon['qty_hundredths'] ?? 100), 0, self::MAX_QTY_HUNDREDTHS);

        // Each of these divides ONCE, at the end, so a per-hour add-on on a
        // 90-minute party is exact rather than a rounded hourly rate times a
        // rounded number of hours. The bound on every factor is what keeps the
        // products inside PHP_INT_MAX: 1e6 × 1440 × 1e8 is 1.4e17, against a
        // ceiling of 9.2e18.
        $total = match ($unit) {
            'per_guest' => self::divideRound($qty * $guests * $price, 100),
            'per_hour'  => self::divideRound($qty * $minutes * $price, 6000),
            default     => self::divideRound($qty * $price, 100),
        };

        return [
            'addon_id'       => isset($addon['addon_id']) && $addon['addon_id'] !== null
                ? (int) $addon['addon_id']
                : null,
            'name'           => (string) ($addon['name'] ?? ''),
            'unit'           => $unit,
            'price_cents'    => $price,
            'qty_hundredths' => $qty,
            'basis'          => self::basis($unit, $qty, $guests, $minutes),
            'total_cents'    => $total,
        ];
    }

    /**
     * The deposit this party should carry.
     *
     * @param array<string,mixed> $package
     * @param array<string,mixed> $options
     */
    public static function deposit(array $package, int $total, array $options = []): int
    {
        $override = $options['deposit_cents'] ?? null;

        if ($override !== null) {
            return self::clamp((int) $override, 0, $total);
        }

        $flat = self::clamp((int) ($package['deposit_cents'] ?? 0), 0, PHP_INT_MAX);

        if ($flat > 0) {
            return min($flat, $total);
        }

        // `deposit_percent` is DECIMAL(5,2) in the schema, so it reaches us as
        // a string like "25.00". It is converted to integer hundredths of a
        // percent BEFORE any arithmetic rather than multiplied as a float.
        $percent = $package['deposit_percent'] ?? null;

        if ($percent === null || $percent === '') {
            return 0;
        }

        $hundredths = self::parsePercentHundredths((string) $percent);

        if ($hundredths === null) {
            return 0;
        }

        return min($total, self::divideRound($total * $hundredths, 10000));
    }

    /**
     * Parse a human percentage into integer hundredths of a percent.
     *
     * "8.125" is rejected: this reads the two-decimal column the schema
     * declares, and silently dropping a third decimal would store a rate the
     * venue did not type. The tax rate, which genuinely needs three, has its
     * own encoding — see the class docblock.
     */
    public static function parsePercentHundredths(string $input): ?int
    {
        $input = trim($input);

        if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $input, $match) !== 1) {
            return null;
        }

        $hundredths = ((int) $match[1]) * 100 + (int) str_pad($match[2] ?? '0', 2, '0');

        return $hundredths <= 10000 ? $hundredths : null;
    }

    /**
     * Parse a tax rate typed as a percentage into thousandths of a percent.
     *
     * Accepts the three decimals Orange County's 8.125% actually needs, and
     * refuses a fourth rather than rounding it away.
     */
    public static function parseRateThousandths(string $input): ?int
    {
        $input = trim($input);

        if ($input === '') {
            return null;
        }

        if (preg_match('/^(\d{1,3})(?:\.(\d{1,3}))?$/', $input, $match) !== 1) {
            return null;
        }

        $thousandths = ((int) $match[1]) * 1000 + (int) str_pad($match[2] ?? '0', 3, '0');

        return $thousandths <= self::MAX_RATE ? $thousandths : null;
    }

    /** A rate in thousandths of a percent, as a human string: 8125 → "8.125". */
    public static function rateLabel(int $thousandths): string
    {
        $whole    = intdiv($thousandths, 1000);
        $fraction = rtrim(str_pad((string) ($thousandths % 1000), 3, '0', STR_PAD_LEFT), '0');

        return $fraction === '' ? (string) $whole : $whole . '.' . $fraction;
    }

    /** A quantity in hundredths, as a human string: 150 → "1.5", 300 → "3". */
    public static function qtyLabel(int $hundredths): string
    {
        $whole    = intdiv($hundredths, 100);
        $fraction = rtrim(str_pad((string) ($hundredths % 100), 2, '0', STR_PAD_LEFT), '0');

        return $fraction === '' ? (string) $whole : $whole . '.' . $fraction;
    }

    /**
     * Parse a decimal quantity into integer hundredths.
     *
     * The same shape as QuoteController::parseQty(), and for the same reason:
     * `cc_booking_addons.qty` is DECIMAL(10,2), so a third decimal would be
     * rounded by MySQL and the stored line total would then disagree with the
     * stored quantity.
     */
    public static function parseQty(string $input): ?int
    {
        $input = trim($input);

        if ($input === '') {
            return null;
        }

        if (preg_match('/^(\d{1,7})(?:\.(\d{1,2}))?$/', $input, $match) !== 1) {
            return null;
        }

        $hundredths = ((int) $match[1]) * 100 + (int) str_pad($match[2] ?? '0', 2, '0');

        return $hundredths >= 0 && $hundredths <= self::MAX_QTY_HUNDREDTHS ? $hundredths : null;
    }

    /** Dollars and cents typed by a human into integer cents. */
    public static function parseMoney(string $input): ?int
    {
        $input = trim(str_replace([',', '$'], '', $input));

        if ($input === '') {
            return 0;
        }

        if (preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/', $input, $match) !== 1) {
            return null;
        }

        return ((int) $match[1]) * 100 + (int) str_pad($match[2] ?? '0', 2, '0');
    }

    /**
     * Integer division rounding half away from zero.
     *
     * The whole money path stays free of float: `round()` takes and returns
     * one, which is the thing this class exists to avoid.
     */
    private static function divideRound(int $numerator, int $denominator): int
    {
        if ($denominator === 0) {
            return 0;
        }

        $half = intdiv($denominator, 2);

        return intdiv($numerator + ($numerator >= 0 ? $half : -$half), $denominator);
    }

    private static function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }

    /** How a line's quantity was arrived at, in words, for the breakdown. */
    private static function basis(string $unit, int $qtyHundredths, int $guests, int $minutes): string
    {
        $qty = self::qtyLabel($qtyHundredths);

        return match ($unit) {
            'per_guest' => $qty . ' × ' . $guests . ' ' . ($guests === 1 ? 'guest' : 'guests'),
            'per_hour'  => $qty . ' × ' . self::qtyLabel(self::divideRound($minutes * 100, 60)) . ' hours',
            default     => $qty,
        };
    }
}
