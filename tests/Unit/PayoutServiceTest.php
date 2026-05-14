<?php

namespace Tests\Unit;

use App\Services\PayoutService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * BR-03 Payout Formula — Unit Tests
 *
 * Payout = base_fee + (biker_rate × trips_count)  when trips_count > 0
 * Payout = 0.00                                    when trips_count = 0
 *
 * All arithmetic must use BCMath with scale 2.
 * All return values must be PHP string type with exactly 2 decimal places.
 *
 * @see docs/plans/BR-03-payout-formula.md — AC-05 through AC-16
 */
class PayoutServiceTest extends TestCase
{
    private PayoutService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PayoutService;
    }

    // ========================================================================
    // AC-05: Zero trips → zero payout (BR-03 critical guard)
    // ========================================================================

    /**
     * AC-05, BR-03: When trips_count is 0, payout must be exactly '0.00'.
     * The base fee is NOT paid when the biker makes zero deliveries.
     */
    public function test_calculate_with_zero_trips_returns_zero_string(): void
    {
        $result = $this->service->calculate(
            baseFee: '25.00',
            bikerRate: '10.00',
            tripsCount: 0,
        );

        $this->assertEquals('0.00', $result,
            'BR-03: Zero trips must yield payout of exactly "0.00", got: '.var_export($result, true));
    }

    // ========================================================================
    // AC-06: Single trip (minimum non-zero)
    // ========================================================================

    /**
     * AC-06: 25.00 + (10.00 × 1) = 35.00.
     */
    public function test_calculate_with_one_trip_returns_base_fee_plus_one_rate(): void
    {
        $result = $this->service->calculate(
            baseFee: '25.00',
            bikerRate: '10.00',
            tripsCount: 1,
        );

        $this->assertEquals('35.00', $result,
            'AC-06: 25.00 + (10.00 × 1) must equal 35.00, got: '.$result);
    }

    // ========================================================================
    // AC-07: Standard multi-trip
    // ========================================================================

    /**
     * AC-07: 25.00 + (10.00 × 5) = 75.00.
     */
    public function test_calculate_with_five_trips_returns_correct_total(): void
    {
        $result = $this->service->calculate(
            baseFee: '25.00',
            bikerRate: '10.00',
            tripsCount: 5,
        );

        $this->assertEquals('75.00', $result,
            'AC-07: 25.00 + (10.00 × 5) must equal 75.00, got: '.$result);
    }

    // ========================================================================
    // AC-08: Large trip count
    // ========================================================================

    /**
     * AC-08: 25.00 + (10.00 × 100) = 1025.00.
     */
    public function test_calculate_with_hundred_trips_returns_large_total(): void
    {
        $result = $this->service->calculate(
            baseFee: '25.00',
            bikerRate: '10.00',
            tripsCount: 100,
        );

        $this->assertEquals('1025.00', $result,
            'AC-08: 25.00 + (10.00 × 100) must equal 1025.00, got: '.$result);
    }

    // ========================================================================
    // AC-09: Zero base fee
    // ========================================================================

    /**
     * AC-09: 0.00 + (10.00 × 3) = 30.00 — no crash, pure rate × trips.
     */
    public function test_calculate_with_zero_base_fee_returns_rate_times_trips(): void
    {
        $result = $this->service->calculate(
            baseFee: '0.00',
            bikerRate: '10.00',
            tripsCount: 3,
        );

        $this->assertEquals('30.00', $result,
            'AC-09: 0.00 + (10.00 × 3) must equal 30.00, got: '.$result);
    }

    // ========================================================================
    // AC-10: Zero biker rate
    // ========================================================================

    /**
     * AC-10: 25.00 + (0.00 × 3) = 25.00 — only the base fee.
     */
    public function test_calculate_with_zero_biker_rate_returns_base_fee_only(): void
    {
        $result = $this->service->calculate(
            baseFee: '25.00',
            bikerRate: '0.00',
            tripsCount: 3,
        );

        $this->assertEquals('25.00', $result,
            'AC-10: 25.00 + (0.00 × 3) must equal 25.00, got: '.$result);
    }

    // ========================================================================
    // AC-11: Decimal biker rate precision
    // ========================================================================

    /**
     * AC-11: 25.00 + (12.50 × 7) = 112.50 — tests fractional rate precision.
     */
    public function test_calculate_with_decimal_rate_returns_precise_result(): void
    {
        $result = $this->service->calculate(
            baseFee: '25.00',
            bikerRate: '12.50',
            tripsCount: 7,
        );

        $this->assertEquals('112.50', $result,
            'AC-11: 25.00 + (12.50 × 7) must equal 112.50, got: '.$result);
    }

    // ========================================================================
    // AC-12: All zeroes, zero trips
    // ========================================================================

    /**
     * AC-12: Zero everything, zero trips → '0.00'.
     */
    public function test_calculate_with_all_zeroes_zero_trips_returns_zero(): void
    {
        $result = $this->service->calculate(
            baseFee: '0.00',
            bikerRate: '0.00',
            tripsCount: 0,
        );

        $this->assertEquals('0.00', $result,
            'AC-12: All zeroes with 0 trips must equal "0.00", got: '.$result);
    }

    // ========================================================================
    // AC-13: All zeroes, positive trips
    // ========================================================================

    /**
     * AC-13: 0.00 + (0.00 × 5) = 0.00.
     */
    public function test_calculate_with_all_zeroes_positive_trips_returns_zero(): void
    {
        $result = $this->service->calculate(
            baseFee: '0.00',
            bikerRate: '0.00',
            tripsCount: 5,
        );

        $this->assertEquals('0.00', $result,
            'AC-13: 0.00 + (0.00 × 5) must equal "0.00", got: '.$result);
    }

    // ========================================================================
    // AC-14: Negative trips_count must throw InvalidArgumentException
    // ========================================================================

    /**
     * AC-14: Negative trips_count is invalid and must throw InvalidArgumentException.
     */
    public function test_calculate_with_negative_trips_throws_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->calculate(
            baseFee: '25.00',
            bikerRate: '10.00',
            tripsCount: -1,
        );
    }

    /**
     * AC-14 (extended): Negative trips_count with message validation.
     */
    public function test_calculate_with_negative_trips_exception_message_contains_count(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('-5');

        $this->service->calculate(
            baseFee: '25.00',
            bikerRate: '10.00',
            tripsCount: -5,
        );
    }

    // ========================================================================
    // AC-15: Return type must always be string (never float)
    // ========================================================================

    /**
     * AC-15: Verify the return type is string for zero-trip case.
     */
    public function test_calculate_returns_string_type_for_zero_trips(): void
    {
        $result = $this->service->calculate('25.00', '10.00', 0);

        $this->assertIsString($result,
            'AC-15: PayoutService::calculate() must return string, got: '.gettype($result));
    }

    /**
     * AC-15: Verify the return type is string for positive-trip case.
     */
    public function test_calculate_returns_string_type_for_positive_trips(): void
    {
        $result = $this->service->calculate('25.00', '10.00', 5);

        $this->assertIsString($result,
            'AC-15: PayoutService::calculate() must return string, got: '.gettype($result));
    }

    // ========================================================================
    // AC-16: All return values match regex exactly 2 decimal places
    // ========================================================================

    /**
     * AC-16: Every result must match the pattern for exactly 2 decimal places.
     */
    #[DataProvider('decimalFormatProvider')]
    public function test_calculate_result_has_exactly_two_decimal_places(string $baseFee, string $bikerRate, int $tripsCount): void
    {
        $result = $this->service->calculate($baseFee, $bikerRate, $tripsCount);

        $this->assertMatchesRegularExpression(
            '/^-?\d+\.\d{2}$/',
            $result,
            "AC-16: Result must match pattern XX.XX with exactly 2 decimal places, got: '{$result}'"
        );
    }

    public static function decimalFormatProvider(): array
    {
        return [
            'zero trips' => ['25.00', '10.00', 0],
            'one trip' => ['25.00', '10.00', 1],
            'five trips' => ['25.00', '10.00', 5],
            'decimal rate' => ['25.00', '12.50', 7],
            'zero base fee' => ['0.00',  '10.00', 3],
            'zero rate' => ['25.00', '0.00',  3],
            'all zeroes' => ['0.00',  '0.00',  0],
            'all zeroes pos' => ['0.00',  '0.00',  5],
            'large count' => ['25.00', '10.00', 100],
        ];
    }

    // ========================================================================
    // AC-29: Comprehensive Data Provider (≥10 rows)
    // ========================================================================

    /**
     * AC-29: Systematic formula validation via data provider covering all
     * mandatory scenarios from the plan's Payout Formula table.
     */
    #[DataProvider('payoutFormulaProvider')]
    public function test_payout_formula_via_data_provider(
        string $baseFee,
        string $bikerRate,
        int $tripsCount,
        string $expected
    ): void {
        $result = $this->service->calculate($baseFee, $bikerRate, $tripsCount);

        $this->assertEquals($expected, $result, sprintf(
            'Payout formula failed: baseFee=%s, bikerRate=%s, trips=%d → expected %s, got %s',
            $baseFee, $bikerRate, $tripsCount, $expected, $result
        ));
    }

    /**
     * 12 dataset rows covering all plan edge cases + mandatory scenarios.
     */
    public static function payoutFormulaProvider(): array
    {
        return [
            // Plan table: mandatory scenarios
            'zero trips' => ['25.00', '10.00', 0,   '0.00'],     // AC-05
            'one trip' => ['25.00', '10.00', 1,   '35.00'],    // AC-06
            'multiple trips' => ['25.00', '10.00', 5,   '75.00'],    // AC-07
            'large volume' => ['25.00', '10.00', 100, '1025.00'],  // AC-08
            'zero base fee' => ['0.00',  '10.00', 3,   '30.00'],    // AC-09
            'decimal rate' => ['25.00', '12.50', 7,   '112.50'],   // AC-11
            'zero base fee one trip' => ['0.00',  '10.00', 1,   '10.00'],

            // Additional edge cases from plan Section 8
            'zero biker rate' => ['25.00', '0.00',  3,   '25.00'],    // AC-10
            'all zeroes zero trips' => ['0.00',  '0.00',  0,   '0.00'],     // AC-12
            'all zeroes pos trips' => ['0.00',  '0.00',  5,   '0.00'],     // AC-13

            // Precision boundary
            'large numbers' => ['999999.99', '99999.99', 999, '100899990.00'], // Plan edge case #9
        ];
    }

    // ========================================================================
    // BCMath precision: scale 2 at large numbers (Plan edge case #9)
    // ========================================================================

    /**
     * Verifies no precision loss at DECIMAL(12,2) boundary values.
     * 99999.99 × 999 = 99899990.01
     * 999999.99 + 99899990.01 = 100089990.00
     */
    public function test_calculate_large_numbers_no_precision_loss(): void
    {
        $result = $this->service->calculate(
            baseFee: '999999.99',
            bikerRate: '99999.99',
            tripsCount: 999,
        );

        $this->assertEquals('100899990.00', $result,
            'BCMath must handle large DECIMAL(12,2) values without precision loss, got: '.$result);
    }

    // ========================================================================
    // Boundary: trips_count = 1 vs trips_count = 0 (tightest boundary)
    // ========================================================================

    /**
     * The 0→1 boundary is the most critical financial boundary in BR-03.
     * The base_fee "activates" at trips = 1.
     */
    public function test_boundary_trips_zero_vs_one_base_fee_activates(): void
    {
        $zeroTrips = $this->service->calculate('25.00', '10.00', 0);
        $oneTrip = $this->service->calculate('25.00', '10.00', 1);

        $this->assertEquals('0.00', $zeroTrips,
            'BR-03: 0 trips must always be 0.00');

        $this->assertEquals('35.00', $oneTrip,
            'BR-03: 1 trip must include base_fee (25.00 + 10.00 = 35.00)');

        // The difference between 1 trip and 0 trips is base_fee + rate
        $this->assertEquals('35.00', $oneTrip,
            'The delta between 0→1 trips must be base_fee + rate = 35.00');
    }

    /**
     * Verify the formula is consistent: adding one trip always adds exactly
     * the biker_rate to the payout.
     */
    public function test_incremental_trip_adds_exactly_biker_rate(): void
    {
        $at5 = $this->service->calculate('25.00', '10.00', 5);
        $at6 = $this->service->calculate('25.00', '10.00', 6);

        // Using BCMath to verify the difference
        $diff = bcsub($at6, $at5, 2);

        $this->assertEquals('10.00', $diff,
            'Each additional trip must add exactly biker_rate (10.00) to payout');
    }

    /**
     * With zero base fee, the first trip yields exactly the biker rate.
     */
    public function test_zero_base_fee_first_trip_yields_biker_rate(): void
    {
        $result = $this->service->calculate('0.00', '10.00', 1);

        $this->assertEquals('10.00', $result,
            'With zero base fee, 1 trip must return exactly the biker_rate');
    }
}
