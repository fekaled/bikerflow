<?php

namespace Tests\Unit\Services;

use App\Enums\PaymentStatus;
use App\Enums\ShiftStatus;
use App\Models\Biker;
use App\Models\Payment;
use App\Models\Restaurant;
use App\Models\Shift;
use App\Models\ShiftBiker;
use App\Services\MarginAggregatorService;
use App\Services\PayoutService;
use App\Services\RevenueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MarginAggregatorService Unit Tests — Phase 5A
 *
 * Tests the pure aggregation layer: aggregate($year, $month).
 * Covers all financial formula assertions, BCMath precision, empty month,
 * zero trips, multi-shift aggregation, negative margin, and payment status counts.
 *
 * Acceptance Criteria: AC-04, AC-05, AC-06, AC-07, AC-08, AC-09, AC-10, AC-11, AC-12, AC-13
 * Business Rules: BR-03 (Payout Formula), BR-04 (Granular Failure)
 *
 * @see docs/plans/phase-5a-admin-margin-dashboard.md
 */
class MarginAggregatorServiceTest extends TestCase
{
    use RefreshDatabase;

    private MarginAggregatorService $service;

    private int $currentYear;

    private int $currentMonth;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentYear = (int) now()->format('Y');
        $this->currentMonth = (int) now()->format('m');

        $this->service = new MarginAggregatorService(
            new PayoutService(),
            new RevenueService(),
        );
    }

    // ========================================================================
    // AC-04: Empty month — no closed shifts → all financial zeros
    // ========================================================================

    /**
     * AC-04: When no shifts are closed in the target month,
     * all financial metrics return '0.00' and counts return 0.
     */
    public function test_empty_month_returns_all_zeros(): void
    {
        // Intentionally create no data — database is empty via RefreshDatabase

        $result = $this->service->aggregate($this->currentYear, $this->currentMonth);

        $this->assertEquals('0.00', $result['total_revenue'],
            'AC-04: total_revenue must be 0.00 when no closed shifts exist');
        $this->assertEquals('0.00', $result['total_payout'],
            'AC-04: total_payout must be 0.00 when no closed shifts exist');
        $this->assertEquals('0.00', $result['net_margin'],
            'AC-04: net_margin must be 0.00 when no closed shifts exist');
        $this->assertEquals(0, $result['shift_count'],
            'AC-04: shift_count must be 0 when no closed shifts exist');
        $this->assertEquals(0, $result['paid_count'],
            'AC-04: paid_count must be 0 when no closed shifts exist');
        $this->assertEquals(0, $result['unpaid_count'],
            'AC-04: unpaid_count must be 0 when no closed shifts exist');
    }

    // ========================================================================
    // AC-05 / AC-06 / AC-07: Single shift, single biker, trips > 0
    // ========================================================================

    /**
     * AC-05: Single shift, single biker, trips = 5 → payout = base_fee + (biker_rate × trips).
     * base_fee=25.00, biker_rate=10.00, trips=5 → payout = 75.00
     * Also verifies AC-06 (revenue) and AC-07 (net margin) in one scenario.
     */
    public function test_single_shift_single_biker_trips_greater_than_zero(): void
    {
        $restaurant = Restaurant::factory()->create([
            'rate_per_trip' => '15.00',
        ]);
        $shift = $this->createClosedShiftInMonth($restaurant, '15.00', $this->currentYear, $this->currentMonth);
        $shiftBiker = $this->assignBikerToShift($shift, [
            'trips_count' => 5,
            'base_fee' => '25.00',
            'biker_rate' => '10.00',
        ]);

        $result = $this->service->aggregate($this->currentYear, $this->currentMonth);

        // AC-05: Payout = 25.00 + (10.00 × 5) = 75.00
        $this->assertEquals('75.00', $result['total_payout'],
            'AC-05: total_payout must equal base_fee + (biker_rate × trips) = 25.00 + 50.00 = 75.00');

        // AC-06: Revenue = (restaurant_rate × trips) − payout = (15.00 × 5) − 75.00 = 0.00
        $this->assertEquals('0.00', $result['total_revenue'],
            'AC-06: total_revenue must equal (restaurant_rate × trips) − payout = 75.00 − 75.00 = 0.00');

        // AC-07: Net Margin = Revenue − Payout = 0.00 − 75.00 = -75.00
        $this->assertEquals('-75.00', $result['net_margin'],
            'AC-07: net_margin must equal total_revenue − total_payout = 0.00 − 75.00 = -75.00');

        $this->assertEquals(1, $result['shift_count'],
            'AC-09: shift_count must be 1');
    }

    // ========================================================================
    // AC-08: Zero trips → payout = 0.00 (BR-03)
    // ========================================================================

    /**
     * AC-08: Biker with trips_count = 0 contributes exactly 0.00 to payout.
     */
    public function test_zero_trips_payout_contribution_is_zero(): void
    {
        $restaurant = Restaurant::factory()->create([
            'rate_per_trip' => '20.00',
        ]);
        $shift = $this->createClosedShiftInMonth($restaurant, '20.00', $this->currentYear, $this->currentMonth);
        $this->assignBikerToShift($shift, [
            'trips_count' => 0,
            'base_fee' => '25.00',
            'biker_rate' => '10.00',
        ]);

        $result = $this->service->aggregate($this->currentYear, $this->currentMonth);

        $this->assertEquals('0.00', $result['total_payout'],
            'AC-08: total_payout must be 0.00 when trips_count = 0');
        $this->assertEquals('0.00', $result['total_revenue'],
            'AC-08: total_revenue must be 0.00 when trips_count = 0');
        $this->assertEquals('0.00', $result['net_margin'],
            'AC-08: net_margin must be 0.00 when trips_count = 0');
        $this->assertEquals(1, $result['shift_count'],
            'AC-09: shift_count must still count the shift even with 0 trips');
    }

    // ========================================================================
    // AC-09: Shift counting — multiple closed shifts in current month
    // ========================================================================

    /**
     * AC-09: Multiple closed shifts in the same month are counted correctly.
     */
    public function test_shift_count_equals_number_of_closed_shifts(): void
    {
        $restaurant = Restaurant::factory()->create([
            'rate_per_trip' => '15.00',
        ]);

        // Create 3 closed shifts in the current month, each closed_at on a different day
        for ($day = 1; $day <= 3; $day++) {
            $shift = $this->createClosedShiftInMonth(
                $restaurant,
                '15.00',
                $this->currentYear,
                $this->currentMonth,
                $day
            );
            $this->assignBikerToShift($shift, [
                'trips_count' => 2,
                'base_fee' => '25.00',
                'biker_rate' => '10.00',
            ]);
        }

        $result = $this->service->aggregate($this->currentYear, $this->currentMonth);

        $this->assertEquals(3, $result['shift_count'],
            'AC-09: shift_count must equal the number of closed shifts in the month (3)');
    }

    /**
     * AC-09 (boundary): Shifts closed in a different month are NOT counted.
     */
    public function test_shifts_from_other_months_are_excluded(): void
    {
        $restaurant = Restaurant::factory()->create([
            'rate_per_trip' => '15.00',
        ]);

        // A closed shift in the previous month
        $previousMonth = $this->currentMonth === 1 ? 12 : $this->currentMonth - 1;
        $yearForPrev = $this->currentMonth === 1 ? $this->currentYear - 1 : $this->currentYear;
        $prevShift = $this->createClosedShiftInMonth($restaurant, '15.00', $yearForPrev, $previousMonth);
        $this->assignBikerToShift($prevShift, [
            'trips_count' => 5,
            'base_fee' => '25.00',
            'biker_rate' => '10.00',
        ]);

        // A closed shift in the current month
        $currentShift = $this->createClosedShiftInMonth($restaurant, '15.00', $this->currentYear, $this->currentMonth);
        $this->assignBikerToShift($currentShift, [
            'trips_count' => 3,
            'base_fee' => '25.00',
            'biker_rate' => '10.00',
        ]);

        $result = $this->service->aggregate($this->currentYear, $this->currentMonth);

        $this->assertEquals(1, $result['shift_count'],
            'AC-09: Only shifts closed in the target month are counted (1, not 2)');
    }

    // ========================================================================
    // AC-10 / AC-11: Payment status counts
    // ========================================================================

    /**
     * AC-10: Paid count equals number of payments with status = paid.
     */
    public function test_paid_count_equals_paid_payments(): void
    {
        $restaurant = Restaurant::factory()->create([
            'rate_per_trip' => '15.00',
        ]);
        $shift = $this->createClosedShiftInMonth($restaurant, '15.00', $this->currentYear, $this->currentMonth);

        // Create 3 bikers with different payment statuses
        $sb1 = $this->assignBikerToShift($shift, ['trips_count' => 5, 'base_fee' => '25.00', 'biker_rate' => '10.00']);
        $sb2 = $this->assignBikerToShift($shift, ['trips_count' => 3, 'base_fee' => '25.00', 'biker_rate' => '10.00']);
        $sb3 = $this->assignBikerToShift($shift, ['trips_count' => 7, 'base_fee' => '25.00', 'biker_rate' => '10.00']);

        Payment::factory()->create(['shift_biker_id' => $sb1->id, 'amount' => '75.00', 'revenue' => '0.00', 'status' => PaymentStatus::Paid]);
        Payment::factory()->create(['shift_biker_id' => $sb2->id, 'amount' => '55.00', 'revenue' => '-10.00', 'status' => PaymentStatus::Failed]);
        Payment::factory()->create(['shift_biker_id' => $sb3->id, 'amount' => '95.00', 'revenue' => '10.00', 'status' => PaymentStatus::Pending]);

        $result = $this->service->aggregate($this->currentYear, $this->currentMonth);

        $this->assertEquals(1, $result['paid_count'],
            'AC-10: paid_count must equal the number of paid payments (1)');
    }

    /**
     * AC-11: Unpaid count = pending + failed + processing.
     */
    public function test_unpaid_count_equals_pending_plus_failed_plus_processing(): void
    {
        $restaurant = Restaurant::factory()->create([
            'rate_per_trip' => '15.00',
        ]);
        $shift = $this->createClosedShiftInMonth($restaurant, '15.00', $this->currentYear, $this->currentMonth);

        $sb1 = $this->assignBikerToShift($shift, ['trips_count' => 5, 'base_fee' => '25.00', 'biker_rate' => '10.00']);
        $sb2 = $this->assignBikerToShift($shift, ['trips_count' => 3, 'base_fee' => '25.00', 'biker_rate' => '10.00']);
        $sb3 = $this->assignBikerToShift($shift, ['trips_count' => 7, 'base_fee' => '25.00', 'biker_rate' => '10.00']);
        $sb4 = $this->assignBikerToShift($shift, ['trips_count' => 2, 'base_fee' => '25.00', 'biker_rate' => '10.00']);

        Payment::factory()->create(['shift_biker_id' => $sb1->id, 'amount' => '75.00', 'revenue' => '0.00', 'status' => PaymentStatus::Paid]);
        Payment::factory()->create(['shift_biker_id' => $sb2->id, 'amount' => '55.00', 'revenue' => '-10.00', 'status' => PaymentStatus::Pending]);
        Payment::factory()->create(['shift_biker_id' => $sb3->id, 'amount' => '95.00', 'revenue' => '10.00', 'status' => PaymentStatus::Failed]);
        Payment::factory()->create(['shift_biker_id' => $sb4->id, 'amount' => '45.00', 'revenue' => '-5.00', 'status' => PaymentStatus::Processing]);

        $result = $this->service->aggregate($this->currentYear, $this->currentMonth);

        // unpaid = pending(1) + failed(1) + processing(1) = 3
        $this->assertEquals(3, $result['unpaid_count'],
            'AC-11: unpaid_count must equal pending + failed + processing = 1 + 1 + 1 = 3');

        // Payment detail breakdown
        $this->assertEquals(1, $result['payment_detail']['paid'],
            'AC-11: payment_detail.paid must be 1');
        $this->assertEquals(1, $result['payment_detail']['pending'],
            'AC-11: payment_detail.pending must be 1');
        $this->assertEquals(1, $result['payment_detail']['failed'],
            'AC-11: payment_detail.failed must be 1');
        $this->assertEquals(1, $result['payment_detail']['processing'],
            'AC-11: payment_detail.processing must be 1');
    }

    // ========================================================================
    // AC-12: Multi-shift, multi-biker BCMath aggregation
    // ========================================================================

    /**
     * AC-12: Multiple shifts with multiple bikers each — aggregated values
     * must be the exact BCMath sum with no floating-point drift.
     *
     * Shift 1:
     *   Biker A: trips=3, base_fee=25.00, biker_rate=10.00
     *     payout = 25.00 + (10.00 × 3) = 55.00
     *     revenue = (15.00 × 3) − 55.00 = 45.00 − 55.00 = -10.00
     *   Biker B: trips=7, base_fee=30.00, biker_rate=12.50
     *     payout = 30.00 + (12.50 × 7) = 117.50
     *     revenue = (15.00 × 7) − 117.50 = 105.00 − 117.50 = -12.50
     * Shift 2:
     *   Biker C: trips=1, base_fee=25.00, biker_rate=10.00
     *     payout = 25.00 + (10.00 × 1) = 35.00
     *     revenue = (15.00 × 1) − 35.00 = 15.00 − 35.00 = -20.00
     *
     * Total payout:  55.00 + 117.50 + 35.00 = 207.50
     * Total revenue: -10.00 + -12.50 + -20.00 = -42.50
     * Net margin: -42.50 − 207.50 = -250.00
     */
    public function test_multi_shift_multi_biker_bcmath_aggregation(): void
    {
        $restaurant = Restaurant::factory()->create([
            'rate_per_trip' => '15.00',
        ]);

        // Shift 1
        $shift1 = $this->createClosedShiftInMonth($restaurant, '15.00', $this->currentYear, $this->currentMonth);
        $sbA = $this->assignBikerToShift($shift1, ['trips_count' => 3, 'base_fee' => '25.00', 'biker_rate' => '10.00']);
        $sbB = $this->assignBikerToShift($shift1, ['trips_count' => 7, 'base_fee' => '30.00', 'biker_rate' => '12.50']);

        Payment::factory()->create(['shift_biker_id' => $sbA->id, 'amount' => '55.00', 'revenue' => '-10.00', 'status' => PaymentStatus::Pending]);
        Payment::factory()->create(['shift_biker_id' => $sbB->id, 'amount' => '117.50', 'revenue' => '-12.50', 'status' => PaymentStatus::Paid]);

        // Shift 2
        $shift2 = $this->createClosedShiftInMonth($restaurant, '15.00', $this->currentYear, $this->currentMonth);
        $sbC = $this->assignBikerToShift($shift2, ['trips_count' => 1, 'base_fee' => '25.00', 'biker_rate' => '10.00']);

        Payment::factory()->create(['shift_biker_id' => $sbC->id, 'amount' => '35.00', 'revenue' => '-20.00', 'status' => PaymentStatus::Pending]);

        $result = $this->service->aggregate($this->currentYear, $this->currentMonth);

        // Total payout: 55.00 + 117.50 + 35.00 = 207.50
        $this->assertEquals('207.50', $result['total_payout'],
            'AC-12: total_payout must be 207.50 (55.00 + 117.50 + 35.00)');

        // Total revenue: -10.00 + -12.50 + -20.00 = -42.50
        $this->assertEquals('-42.50', $result['total_revenue'],
            'AC-12: total_revenue must be -42.50 (-10.00 + -12.50 + -20.00)');

        // Net margin: -42.50 − 207.50 = -250.00
        $this->assertEquals('-250.00', $result['net_margin'],
            'AC-12: net_margin must be -250.00 (-42.50 − 207.50)');

        $this->assertEquals(2, $result['shift_count'],
            'AC-12: shift_count must be 2');

        $this->assertEquals(1, $result['paid_count'],
            'AC-12: paid_count must be 1');
        $this->assertEquals(2, $result['unpaid_count'],
            'AC-12: unpaid_count must be 2 (both pending)');
    }

    // ========================================================================
    // AC-13: Negative margin (restaurant_rate < effective biker_rate)
    // ========================================================================

    /**
     * AC-13: When restaurant_rate is lower than biker_rate,
     * net_margin must be a negative string (e.g., "-15.50").
     *
     * Restaurant rate = 5.00, trips = 3, base_fee = 25.00, biker_rate = 10.00
     * Payout = 25.00 + (10.00 × 3) = 55.00
     * Revenue = (5.00 × 3) − 55.00 = 15.00 − 55.00 = -40.00
     * Net margin = -40.00 − 55.00 = -95.00
     */
    public function test_negative_margin_when_restaurant_rate_below_biker_rate(): void
    {
        $restaurant = Restaurant::factory()->create([
            'rate_per_trip' => '5.00',
        ]);
        $shift = $this->createClosedShiftInMonth($restaurant, '5.00', $this->currentYear, $this->currentMonth);
        $sb = $this->assignBikerToShift($shift, [
            'trips_count' => 3,
            'base_fee' => '25.00',
            'biker_rate' => '10.00',
        ]);

        $result = $this->service->aggregate($this->currentYear, $this->currentMonth);

        $this->assertEquals('-95.00', $result['net_margin'],
            'AC-13: net_margin must be -95.00 (negative margin scenario)');

        // Verify the negative sign is present in the string representation
        $this->assertStringStartsWith('-', $result['net_margin'],
            'AC-13: net_margin must start with a negative sign when revenue < payout');
    }

    /**
     * AC-13: Large-scale negative margin with many shifts.
     * Multiple shifts where each produces negative revenue.
     */
    public function test_large_scale_negative_margin(): void
    {
        $restaurant = Restaurant::factory()->create([
            'rate_per_trip' => '3.00',
        ]);

        for ($i = 0; $i < 5; $i++) {
            $shift = $this->createClosedShiftInMonth($restaurant, '3.00', $this->currentYear, $this->currentMonth, $i + 1);
            $sb = $this->assignBikerToShift($shift, [
                'trips_count' => 10,
                'base_fee' => '50.00',
                'biker_rate' => '15.00',
            ]);
            // Payout per shift: 50.00 + (15.00 × 10) = 200.00
            // Revenue per shift: (3.00 × 10) − 200.00 = 30.00 − 200.00 = -170.00
        }

        $result = $this->service->aggregate($this->currentYear, $this->currentMonth);

        // Total payout: 5 × 200.00 = 1000.00
        $this->assertEquals('1000.00', $result['total_payout'],
            'AC-13: total_payout must be 1000.00');
        // Total revenue: 5 × -170.00 = -850.00
        $this->assertEquals('-850.00', $result['total_revenue'],
            'AC-13: total_revenue must be -850.00');
        // Net margin: -850.00 − 1000.00 = -1850.00
        $this->assertEquals('-1850.00', $result['net_margin'],
            'AC-13: net_margin must be -1850.00');
    }

    // ========================================================================
    // BCMath precision at scale
    // ========================================================================

    /**
     * Edge Case: Accumulating many BCMath additions preserves scale 2 precision.
     * 10 shifts × 3 bikers each = 30 rows to aggregate.
     */
    public function test_bcmath_precision_across_many_rows(): void
    {
        $restaurant = Restaurant::factory()->create([
            'rate_per_trip' => '11.11',
        ]);

        $totalExpectedPayout = '0.00';
        $totalExpectedRevenue = '0.00';

        for ($shiftNum = 1; $shiftNum <= 10; $shiftNum++) {
            $shift = $this->createClosedShiftInMonth($restaurant, '11.11', $this->currentYear, $this->currentMonth, $shiftNum);

            for ($bikerNum = 1; $bikerNum <= 3; $bikerNum++) {
                $trips = $shiftNum + $bikerNum; // varies: 2–13
                $payoutRow = bcmul('7.77', (string) $trips, 2);
                $payoutRow = bcadd('12.34', $payoutRow, 2);
                $revenueRow = bcsub(bcmul('11.11', (string) $trips, 2), $payoutRow, 2);

                $totalExpectedPayout = bcadd($totalExpectedPayout, $payoutRow, 2);
                $totalExpectedRevenue = bcadd($totalExpectedRevenue, $revenueRow, 2);

                $sb = $this->assignBikerToShift($shift, [
                    'trips_count' => $trips,
                    'base_fee' => '12.34',
                    'biker_rate' => '7.77',
                ]);
            }
        }

        $result = $this->service->aggregate($this->currentYear, $this->currentMonth);

        $this->assertEquals($totalExpectedPayout, $result['total_payout'],
            'BCMath precision: total_payout must match manual BCMath sum');
        $this->assertEquals($totalExpectedRevenue, $result['total_revenue'],
            'BCMath precision: total_revenue must match manual BCMath sum');

        $expectedNetMargin = bcsub($totalExpectedRevenue, $totalExpectedPayout, 2);
        $this->assertEquals($expectedNetMargin, $result['net_margin'],
            'BCMath precision: net_margin must match bcsub(total_revenue, total_payout, 2)');
    }

    // ========================================================================
    // Shifts without payments — still counted
    // ========================================================================

    /**
     * Edge Case: A closed shift with bikers but without Payment rows
     * still contributes to shift_count and financial aggregation.
     */
    public function test_closed_shift_without_payment_rows_still_counted_in_finance(): void
    {
        $restaurant = Restaurant::factory()->create([
            'rate_per_trip' => '20.00',
        ]);
        $shift = $this->createClosedShiftInMonth($restaurant, '20.00', $this->currentYear, $this->currentMonth);
        // Biker assigned to shift but NO Payment row created
        $this->assignBikerToShift($shift, [
            'trips_count' => 5,
            'base_fee' => '25.00',
            'biker_rate' => '10.00',
        ]);

        $result = $this->service->aggregate($this->currentYear, $this->currentMonth);

        $this->assertEquals(1, $result['shift_count'],
            'Edge Case: shift_count must count all closed shifts regardless of payments');
        // Financials still aggregate from shift_biker data (not via Payment.amount)
        $this->assertEquals('75.00', $result['total_payout'],
            'Edge Case: total_payout must be 75.00 derived from shift_biker data');
    }

    // ========================================================================
    // Helper methods
    // ========================================================================

    /**
     * Create a closed shift with closed_at in a specific year/month.
     */
    private function createClosedShiftInMonth(
        Restaurant $restaurant,
        string $restaurantRate,
        int $year,
        int $month,
        int $day = 15
    ): Shift {
        return Shift::factory()->create([
            'restaurant_id' => $restaurant->id,
            'restaurant_rate' => $restaurantRate,
            'status' => ShiftStatus::Closed,
            'started_at' => now()->subDays(10),
            'closed_at' => now()->setDate($year, $month, $day)->setTime(12, 0, 0),
        ]);
    }

    /**
     * Assign a biker to a shift with known financial values.
     */
    private function assignBikerToShift(
        Shift $shift,
        array $overrides = []
    ): ShiftBiker {
        $biker = Biker::factory()->create();

        return ShiftBiker::factory()->create(array_merge([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 0,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ], $overrides));
    }
}
