<?php

declare(strict_types=1);

/**
 * What a party costs.
 *
 * `docs/SPEC.md` §8.5 asks for this service to be pure and unit-tested, and
 * this file is why that was worth asking for: every case below is a venue
 * situation that would otherwise only be reachable by booking a real party.
 * A 90-minute party with an hourly add-on, a package whose deposit is a
 * percentage, a discount larger than the party, a field trip that pays no tax,
 * and the one that matters most — a total that has to agree with its own
 * breakdown to the cent, because the venue has put the figure in writing.
 *
 * No database, no clock, no settings. Every input is passed in.
 */

use App\Services\QuoteCalculator as Q;
use Tests\Assert;
use Tests\Harness;

Harness::suite('unit/quote-calculator');

/** The venue's own Bowling Birthday, read from the seed. */
function qc_package(array $overrides = []): array
{
    return array_merge([
        'base_price_cents'       => 39900,
        'included_guests'        => 8,
        'additional_guest_cents' => 2999,
        'duration_minutes'       => 120,
        'deposit_cents'          => 10000,
        'deposit_percent'        => null,
    ], $overrides);
}

/** Orange County NY: 4% state + 3.75% county + 0.375% MCTD. */
const QC_RATE = 8125;

// ------------------------------------------------------------- the base case

Harness::test('a party inside the included headcount pays the package price', function (): void {
    $priced = Q::price(qc_package(), 8);

    Assert::same(39900, $priced['base_cents']);
    Assert::same(0, $priced['extra_guests']);
    Assert::same(0, $priced['guests_cents']);
    Assert::same(39900, $priced['subtotal_cents']);
    Assert::same(39900, $priced['total_cents'], 'no tax rate was given, so no tax is added');
});

Harness::test('a party UNDER the included headcount pays the same, not less', function (): void {
    // "Includes 8 guests" is a floor, not a per-head price with 8 pre-paid.
    // Refunding the difference would make the package cheaper than itself.
    $priced = Q::price(qc_package(), 3);

    Assert::same(39900, $priced['subtotal_cents']);
    Assert::same(0, $priced['extra_guests']);
});

Harness::test('extra guests are charged at the package rate', function (): void {
    // 14 guests: 8 included, 6 extra at $29.99 = $179.94, plus $399 = $578.94.
    $priced = Q::price(qc_package(), 14);

    Assert::same(6, $priced['extra_guests']);
    Assert::same(17994, $priced['guests_cents']);
    Assert::same(57894, $priced['subtotal_cents']);
});

Harness::test('a party with no package at all is priced from its add-ons', function (): void {
    // A group booking quoted entirely from add-ons is a real case, and an
    // empty package must not divide by anything or invent a base price.
    $priced = Q::price([], 20, [
        ['name' => 'Pizza', 'unit' => 'per_guest', 'price_cents' => 450],
    ]);

    Assert::same(0, $priced['base_cents']);
    Assert::same(9000, $priced['addons_cents']);
    Assert::same(9000, $priced['total_cents']);
});

// -------------------------------------------------------------- add-on units

Harness::test('a flat add-on is charged once, whatever the headcount', function (): void {
    $priced = Q::price(qc_package(), 30, [
        ['name' => 'Character visit', 'unit' => 'flat', 'price_cents' => 7500],
    ]);

    Assert::same(7500, $priced['addons_cents']);
});

Harness::test('a per-guest add-on multiplies by the headcount', function (): void {
    $priced = Q::price(qc_package(), 14, [
        ['name' => 'Goody bag', 'unit' => 'per_guest', 'price_cents' => 599],
    ]);

    Assert::same(8386, $priced['addons_cents'], '14 × $5.99');
});

Harness::test('a per-hour add-on is exact on a part-hour party', function (): void {
    // THE ROUNDING TEST. A 90-minute party with a $40/hour host is $60, and
    // the only way to get there is to divide once at the end: $40 × 1.5. Doing
    // it as "an hourly rate times a rounded number of hours" gives $40 or $80.
    $priced = Q::price(qc_package(['duration_minutes' => 90]), 10, [
        ['name' => 'Extra host', 'unit' => 'per_hour', 'price_cents' => 4000],
    ]);

    Assert::same(6000, $priced['addons_cents']);
    Assert::same(90, $priced['duration_minutes']);
});

Harness::test('a per-hour add-on on an odd duration rounds half away from zero', function (): void {
    // 50 minutes at $10/hour is $8.3333 — $8.33, not $8.34 and not $8.
    $priced = Q::price(qc_package(['duration_minutes' => 50]), 8, [
        ['name' => 'Hourly thing', 'unit' => 'per_hour', 'price_cents' => 1000],
    ]);

    Assert::same(833, $priced['addons_cents']);

    // 45 minutes at $10/hour is exactly $7.50, which rounds UP under
    // half-away-from-zero. intdiv() alone would give $7.49.
    $half = Q::price(qc_package(['duration_minutes' => 45]), 8, [
        ['name' => 'Hourly thing', 'unit' => 'per_hour', 'price_cents' => 1000],
    ]);

    Assert::same(750, $half['addons_cents']);
});

Harness::test('an add-on quantity is carried as hundredths, never a float', function (): void {
    // 3 × $4.50 = $13.50 exactly. The classic float case: 0.1 three times.
    $priced = Q::price(qc_package(), 8, [
        ['name' => 'Pizza', 'unit' => 'flat', 'price_cents' => 450, 'qty_hundredths' => 300],
        ['name' => 'Tenths', 'unit' => 'flat', 'price_cents' => 100, 'qty_hundredths' => 10],
        ['name' => 'Tenths', 'unit' => 'flat', 'price_cents' => 100, 'qty_hundredths' => 10],
        ['name' => 'Tenths', 'unit' => 'flat', 'price_cents' => 100, 'qty_hundredths' => 10],
    ]);

    Assert::same(1350 + 30, $priced['addons_cents']);
});

Harness::test('a quantity multiplies a per-guest add-on as well as a flat one', function (): void {
    // Two goody bags each for 10 guests.
    $priced = Q::price(qc_package(), 10, [
        ['name' => 'Goody bag', 'unit' => 'per_guest', 'price_cents' => 500, 'qty_hundredths' => 200],
    ]);

    Assert::same(10000, $priced['addons_cents']);
});

Harness::test('an unknown add-on unit is treated as flat rather than skipped', function (): void {
    // A unit the enum does not declare means a bad write somewhere. Charging
    // it once is wrong-but-visible; dropping the line silently loses revenue
    // and nobody notices until the reconciliation.
    $line = Q::addonLine(['unit' => 'per_fortnight', 'price_cents' => 1000], 10, 120);

    Assert::same('flat', $line['unit']);
    Assert::same(1000, $line['total_cents']);
});

Harness::test('the breakdown explains each add-on line in words', function (): void {
    $priced = Q::price(qc_package(['duration_minutes' => 90]), 12, [
        ['name' => 'Goody bag', 'unit' => 'per_guest', 'price_cents' => 500],
        ['name' => 'Host', 'unit' => 'per_hour', 'price_cents' => 4000],
        ['name' => 'Cake', 'unit' => 'flat', 'price_cents' => 3000],
    ]);

    Assert::count(3, $priced['addon_lines']);
    Assert::same('1 × 12 guests', $priced['addon_lines'][0]['basis']);
    Assert::same('1 × 1.5 hours', $priced['addon_lines'][1]['basis']);
    Assert::same('1', $priced['addon_lines'][2]['basis']);
});

// -------------------------------------------------------------------- tax

Harness::test('tax is applied at the venue rate after the discount', function (): void {
    // §8.5: tax = (subtotal - discount) × rate. $399 at 8.125% = $32.419, so
    // $32.42.
    $priced = Q::price(qc_package(), 8, [], ['tax_rate' => QC_RATE]);

    Assert::same(39900, $priced['taxable_cents']);
    Assert::same(3242, $priced['tax_cents']);
    Assert::same(43142, $priced['total_cents']);
    Assert::same(QC_RATE, $priced['tax_rate']);
    Assert::true($priced['tax_estimated'], 'worked out from the rate, not typed in');
});

Harness::test('the tax rate holds Orange County\'s third decimal exactly', function (): void {
    // 8.125% cannot be expressed in basis points, which is why the rate is in
    // thousandths of a percent. A rate rounded to 8.13% overcharges $0.21 on
    // a $4,000 group booking — small, and wrong in a column that gets
    // reconciled against a POS.
    Assert::same(8125, Q::parseRateThousandths('8.125'));
    Assert::same('8.125', Q::rateLabel(8125));

    $exact   = Q::price(['base_price_cents' => 400000], 0, [], ['tax_rate' => 8125]);
    $rounded = Q::price(['base_price_cents' => 400000], 0, [], ['tax_rate' => 8130]);

    Assert::same(32500, $exact['tax_cents']);
    Assert::same(32520, $rounded['tax_cents'], 'the difference the third decimal makes');
});

Harness::test('a tax-exempt booking is charged no tax even with a rate set', function (): void {
    // Field trips and fundraisers are two of the booking types the schema
    // declares, and both are routinely exempt.
    $priced = Q::price(qc_package(), 20, [], ['tax_rate' => QC_RATE, 'tax_exempt' => true]);

    Assert::same(0, $priced['tax_cents']);
    Assert::same(0, $priced['tax_rate'], 'and the rate it reports is the one it applied');
    Assert::same($priced['subtotal_cents'], $priced['total_cents']);
});

Harness::test('an explicit tax figure beats the rate, and zero is not the same as absent', function (): void {
    // Somebody at the desk occasionally has to match a POS receipt to the
    // cent. Passing 0 means no tax; leaving it out means work it out.
    $typed = Q::price(qc_package(), 8, [], ['tax_rate' => QC_RATE, 'tax_cents' => 3000]);

    Assert::same(3000, $typed['tax_cents']);
    Assert::false($typed['tax_estimated']);

    $zero = Q::price(qc_package(), 8, [], ['tax_rate' => QC_RATE, 'tax_cents' => 0]);

    Assert::same(0, $zero['tax_cents']);

    $absent = Q::price(qc_package(), 8, [], ['tax_rate' => QC_RATE]);

    Assert::same(3242, $absent['tax_cents']);
});

Harness::test('a rate with a fourth decimal is refused rather than rounded', function (): void {
    Assert::null(Q::parseRateThousandths('8.1255'));
    Assert::null(Q::parseRateThousandths('abc'));
    Assert::null(Q::parseRateThousandths('101'), 'over 100% is not a tax rate');
    Assert::same(0, Q::parseRateThousandths('0'));
    Assert::same(100000, Q::parseRateThousandths('100'));
});

// --------------------------------------------------------------- discounts

Harness::test('a percentage discount is taken off the subtotal', function (): void {
    // 10% of $578.94 is $57.894 → $57.89.
    $priced = Q::price(qc_package(), 14, [], ['discount_mode' => 'percent', 'discount_input' => 1000]);

    Assert::same(57894, $priced['subtotal_cents']);
    Assert::same(5789, $priced['discount_cents']);
    Assert::same(52105, $priced['total_cents']);
});

Harness::test('a discount can zero a party but never invert it', function (): void {
    $priced = Q::price(qc_package(), 8, [], ['discount_cents' => 99999999]);

    Assert::same(39900, $priced['discount_cents'], 'capped at the subtotal');
    Assert::same(0, $priced['total_cents']);
    Assert::same(0, $priced['balance_cents']);
});

Harness::test('a 100% discount lands on exactly zero', function (): void {
    $priced = Q::price(qc_package(), 14, [], [
        'discount_mode'  => 'percent',
        'discount_input' => 10000,
        'tax_rate'       => QC_RATE,
    ]);

    Assert::same(57894, $priced['discount_cents']);
    Assert::same(0, $priced['taxable_cents']);
    Assert::same(0, $priced['tax_cents'], 'nothing owed means nothing taxed');
    Assert::same(0, $priced['total_cents']);
});

Harness::test('a negative discount is treated as none', function (): void {
    $priced = Q::price(qc_package(), 8, [], ['discount_cents' => -5000]);

    Assert::same(0, $priced['discount_cents']);
    Assert::same(39900, $priced['total_cents']);
});

// ---------------------------------------------------------------- deposits

Harness::test('a flat package deposit is used as it stands', function (): void {
    $priced = Q::price(qc_package(), 14, [], ['tax_rate' => QC_RATE]);

    Assert::same(10000, $priced['deposit_cents']);
    Assert::same($priced['total_cents'] - 10000, $priced['balance_cents']);
});

Harness::test('a percentage deposit is worked out from the total', function (): void {
    // 25% of $399 + tax. The percentage is DECIMAL(5,2) in the schema, so it
    // arrives as the string "25.00" and must not be multiplied as a float.
    $priced = Q::price(
        qc_package(['deposit_cents' => 0, 'deposit_percent' => '25.00']),
        8,
        [],
        ['tax_rate' => QC_RATE]
    );

    Assert::same(43142, $priced['total_cents']);
    Assert::same(10786, $priced['deposit_cents'], '25% of $431.42 is $107.855 → $107.86');
});

Harness::test('a flat deposit wins over a percentage when both are set', function (): void {
    // §8.5 names the order: deposit_cents OR total × percent.
    $priced = Q::price(qc_package(['deposit_percent' => '50.00']), 8);

    Assert::same(10000, $priced['deposit_cents']);
});

Harness::test('a deposit never exceeds the total', function (): void {
    // A $100 deposit on a party discounted to $40 would otherwise show the
    // guest a negative balance.
    $priced = Q::price(qc_package(), 8, [], ['discount_cents' => 35900]);

    Assert::same(4000, $priced['total_cents']);
    Assert::same(4000, $priced['deposit_cents']);
    Assert::same(0, $priced['balance_cents']);
});

Harness::test('an explicit deposit beats both the flat and the percentage', function (): void {
    $priced = Q::price(qc_package(['deposit_percent' => '50.00']), 8, [], ['deposit_cents' => 2500]);

    Assert::same(2500, $priced['deposit_cents']);
});

Harness::test('a package with neither deposit figure asks for nothing up front', function (): void {
    $priced = Q::price(qc_package(['deposit_cents' => 0, 'deposit_percent' => null]), 8);

    Assert::same(0, $priced['deposit_cents']);
    Assert::same($priced['total_cents'], $priced['balance_cents']);
});

Harness::test('an unreadable deposit percentage asks for nothing rather than guessing', function (): void {
    $priced = Q::price(qc_package(['deposit_cents' => 0, 'deposit_percent' => 'half']), 8);

    Assert::same(0, $priced['deposit_cents']);
});

// --------------------------------------------- the breakdown has to add up

Harness::test('the figures agree with each other on a fully loaded party', function (): void {
    // THE TEST THAT MATTERS. Everything at once, checked as a chain rather
    // than as a remembered total: whatever the arithmetic does, the parts have
    // to sum to the whole, or the venue and the guest read different numbers
    // off the same document.
    $priced = Q::price(
        qc_package(['duration_minutes' => 150, 'deposit_cents' => 0, 'deposit_percent' => '20.00']),
        23,
        [
            ['name' => 'Pizza',  'unit' => 'per_guest', 'price_cents' => 450],
            ['name' => 'Host',   'unit' => 'per_hour',  'price_cents' => 3500],
            ['name' => 'Cake',   'unit' => 'flat',      'price_cents' => 4295, 'qty_hundredths' => 150],
        ],
        ['discount_mode' => 'percent', 'discount_input' => 750, 'tax_rate' => QC_RATE]
    );

    $addonSum = 0;

    foreach ($priced['addon_lines'] as $line) {
        $addonSum += $line['total_cents'];
    }

    Assert::same($priced['addons_cents'], $addonSum, 'the add-on lines sum to the add-on total');
    Assert::same(
        $priced['base_cents'] + $priced['guests_cents'] + $priced['addons_cents'],
        $priced['subtotal_cents'],
        'base + extra guests + add-ons is the subtotal'
    );
    Assert::same(
        $priced['subtotal_cents'] - $priced['discount_cents'],
        $priced['taxable_cents'],
        'the taxable figure is the subtotal less the discount'
    );
    Assert::same(
        $priced['taxable_cents'] + $priced['tax_cents'],
        $priced['total_cents'],
        'and the total is that plus tax'
    );
    Assert::same(
        $priced['total_cents'] - $priced['deposit_cents'],
        $priced['balance_cents'],
        'with the balance being what is left after the deposit'
    );

    // And the actual numbers, so a change in the arithmetic is visible rather
    // than merely self-consistent: 15 extra guests at $29.99 = $449.85;
    // pizza 23 × $4.50 = $103.50; host 2.5h × $35 = $87.50; cake 1.5 × $42.95
    // = $64.43 (64.425 rounded up).
    Assert::same(44985, $priced['guests_cents']);
    Assert::same(10350 + 8750 + 6443, $priced['addons_cents']);
    Assert::same(39900 + 44985 + 25543, $priced['subtotal_cents']);
});

// ------------------------------------------------------------------ bounds

Harness::test('absurd inputs are clamped rather than overflowing', function (): void {
    // Every factor is bounded so the products stay inside PHP_INT_MAX. The
    // documented worst case is 1e6 × 1440 × 1e8 = 1.4e17.
    $priced = Q::price(
        ['base_price_cents' => 0, 'included_guests' => 0, 'additional_guest_cents' => PHP_INT_MAX],
        PHP_INT_MAX,
        [[
            'unit'           => 'per_hour',
            'price_cents'    => PHP_INT_MAX,
            'qty_hundredths' => PHP_INT_MAX,
        ]],
        ['duration_minutes' => PHP_INT_MAX]
    );

    Assert::same(Q::MAX_GUESTS, $priced['guest_count']);
    Assert::same(Q::MAX_DURATION_MINUTES, $priced['duration_minutes']);
    Assert::true($priced['total_cents'] > 0, 'and the result is still a positive number');
    Assert::true($priced['total_cents'] < PHP_INT_MAX, 'rather than a wrapped one');
});

Harness::test('a zero-duration party does not divide by zero', function (): void {
    $priced = Q::price(qc_package(['duration_minutes' => 0]), 8, [
        ['unit' => 'per_hour', 'price_cents' => 4000],
    ]);

    Assert::same(0, $priced['addons_cents']);
});

// ------------------------------------------------------------------ parsers

Harness::test('money typed by a human parses to cents, and junk does not', function (): void {
    Assert::same(39900, Q::parseMoney('399.00'));
    Assert::same(39900, Q::parseMoney('$399'));
    Assert::same(123456, Q::parseMoney('1,234.56'));
    Assert::same(0, Q::parseMoney(''), 'an empty money field means nothing, not an error');
    Assert::same(450, Q::parseMoney('4.5'), 'one decimal place is a legitimate way to type it');
    Assert::null(Q::parseMoney('4.555'), 'three decimals cannot be stored in cents');
    Assert::null(Q::parseMoney('-5'));
    Assert::null(Q::parseMoney('lots'));
});

Harness::test('a quantity parses to hundredths and refuses a third decimal', function (): void {
    // cc_booking_addons.qty is DECIMAL(10,2): a third place would be rounded
    // by MySQL, and the stored line total would then disagree with the stored
    // quantity.
    Assert::same(100, Q::parseQty('1'));
    Assert::same(150, Q::parseQty('1.5'));
    Assert::same(125, Q::parseQty('1.25'));
    Assert::same(0, Q::parseQty('0'));
    Assert::null(Q::parseQty('1.255'));
    Assert::null(Q::parseQty(''));
    Assert::null(Q::parseQty('-1'));
    Assert::null(Q::parseQty('20000'), 'over the 10,000.00 ceiling');
});

Harness::test('quantity and rate labels read back the way they were typed', function (): void {
    Assert::same('1.5', Q::qtyLabel(150));
    Assert::same('3', Q::qtyLabel(300));
    Assert::same('0.25', Q::qtyLabel(25));
    Assert::same('8', Q::rateLabel(8000));
    Assert::same('8.1', Q::rateLabel(8100));
    Assert::same('0', Q::rateLabel(0));
});
