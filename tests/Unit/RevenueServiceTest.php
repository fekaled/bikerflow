<?php

namespace Tests\Unit;

use App\Services\RevenueService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Revenue Formula — Unit Tests
 *
 * Revenue = (restaurant_rate × trips_count) - Payout
 * Revenue = 0.00  when trips_count = 0
 *
 * All arithmetic uses BCMath with scale 2.
 * Revenue CAN be negative (loss scenario — valid).
 *
 * @see docs/plans/BR-03-payout-formula.md — AC-17 through AC-21
 */
class RevenueServiceTest extends TestCase
{
    private RevenueService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RevenueService;
    }

    // ========================================================================
    // AC-17: Break-even (Revenue = 0.00)
    // ========================================================================

    /**
     * AC-17: (15.00 × 5) - 75.00 = 75.00 - 75.00 = 0.00.
     */
    public function test_calculate_break_even_returns_zero(): void
    {
        $result = $this->service->calculate(
            restaurantRate: '15.00',
            tripsCount: 5,
            payout: '75.00',
        );

        $this->assertEquals('0.00', $result,
            'AC-17: Break-even: (15.00 × 5) - 75.00 must equal 0.00, got: '.$result);
    }

    // ========================================================================
    // AC-18: Profit (Revenue > 0)
    // ========================================================================

    /**
     * AC-18: (20.00 × 5) - 75.00 = 100.00 - 75.00 = 25.00.
     */
    public function test_calculate_profit_returns_positive(): void
    {
        $result = $this->service->calculate(
            restaurantRate: '20.00',
            tripsCount: 5,
            payout: '75.00',
        );

        $this->assertEquals('25.00', $result,
            'AC-18: Profit: (20.00 × 5) - 75.00 must equal 25.00, got: '.$result);
    }

    // ========================================================================
    // AC-19: Loss (Revenue < 0 — negative is valid)
    // ========================================================================

    /**
     * AC-19: (10.00 × 5) - 75.00 = 50.00 - 75.00 = -25.00.
     * Negative revenue is a valid business scenario, not an error.
     */
    public function test_calculate_loss_returns_negative(): void
    {
        $result = $this->service->calculate(
            restaurantRate: '10.00',
            tripsCount: 5,
            payout: '75.00',
        );

        $this->assertEquals('-25.00', $result,
            'AC-19: Loss: (10.00 × 5) - 75.00 must equal -25.00, got: '.$result);
    }

    // ========================================================================
    // AC-20: Zero trips → zero revenue
    // ========================================================================

    /**
     * AC-20: When trips_count = 0, revenue must be 0.00 regardless of inputs.
     */
    public function test_calculate_zero_trips_returns_zero(): void
    {
        $result = $this->service->calculate(
            restaurantRate: '15.00',
            tripsCount: 0,
            payout: '0.00',
        );

        $this->assertEquals('0.00', $result,
            'AC-20: Zero trips must yield revenue of exactly "0.00", got: '.$result);
    }

    // ========================================================================
    // AC-21: Return type must always be string
    // ========================================================================

    /**
     * AC-21: Verify the return type is string for zero-trip case.
     */
    public function test_calculate_returns_string_type_for_zero_trips(): void
    {
        $result = $this->service->calculate('15.00', 0, '0.00');

        $this->assertIsString($result,
            'AC-21: RevenueService::calculate() must return string, got: '.gettype($result));
    }

    /**
     * AC-21: Verify the return type is string for profit case.
     */
    public function test_calculate_returns_string_type_for_profit(): void
    {
        $result = $this->service->calculate('20.00', 5, '75.00');

        $this->assertIsString($result,
            'AC-21: RevenueService::calculate() must return string, got: '.gettype($result));
    }

    /**
     * AC-21: Verify the return type is string even for negative result.
     */
    public function test_calculate_returns_string_type_for_loss(): void
    {
        $result = $this->service->calculate('10.00', 5, '75.00');

        $this->assertIsString($result,
            'AC-21: RevenueService::calculate() must return string for loss, got: '.gettype($result));
    }

    // ========================================================================
    // Comprehensive data provider
    // ========================================================================

    #[DataProvider('revenueFormulaProvider')]
    public function test_revenue_formula_via_data_provider(
        string $restaurantRate,
        int $tripsCount,
        string $payout,
        string $expected
    ): void {
        $result = $this->service->calculate($restaurantRate, $tripsCount, $payout);

        $this->assertEquals($expected, $result, sprintf(
            'Revenue formula failed: rate=%s, trips=%d, payout=%s → expected %s, got %s',
            $restaurantRate, $tripsCount, $payout, $expected, $result
        ));
    }

    public static function revenueFormulaProvider(): array
    {
        return [
            'zero trips' => ['15.00', 0,   '0.00',  '0.00'],    // AC-20
            'break even' => ['15.00', 5,   '75.00', '0.00'],    // AC-17
            'profit' => ['20.00', 5,   '75.00', '25.00'],   // AC-18
            'loss' => ['10.00', 5,   '75.00', '-25.00'],  // AC-19
            'large profit' => ['50.00', 100, '525.00', '4475.00'],
            'zero rate' => ['0.00',  5,   '0.00',  '0.00'],
            'zero rate with payout' => ['0.00',  5,   '25.00', '-25.00'],
            'single trip profit' => ['20.00', 1,   '10.00', '10.00'],
            'decimal rate' => ['17.50', 4,   '50.00', '20.00'],
        ];
    }

    // ========================================================================
    // Decimal format validation (all results have exactly 2 decimal places)
    // ========================================================================

    #[DataProvider('decimalFormatProvider')]
    public function test_calculate_result_has_exactly_two_decimal_places(
        string $restaurantRate,
        int $tripsCount,
        string $payout
    ): void {
        $result = $this->service->calculate($restaurantRate, $tripsCount, $payout);

        $this->assertMatchesRegularExpression(
            '/^-?\d+\.\d{2}$/',
            $result,
            "Result must match pattern XX.XX with exactly 2 decimal places, got: '{$result}'"
        );
    }

    public static function decimalFormatProvider(): array
    {
        return [
            'zero trips' => ['15.00', 0, '0.00'],
            'break even' => ['15.00', 5, '75.00'],
            'profit' => ['20.00', 5, '75.00'],
            'loss' => ['10.00', 5, '75.00'],
            'zero rate' => ['0.00',  5, '0.00'],
        ];
    }
}
