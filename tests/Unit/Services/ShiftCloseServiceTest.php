<?php

namespace Tests\Unit\Services;

use App\Enums\PaymentStatus;
use App\Enums\ShiftStatus;
use App\Enums\UserRole;
use App\Models\Biker;
use App\Models\Payment;
use App\Models\PixKey;
use App\Models\Restaurant;
use App\Models\Shift;
use App\Models\ShiftBiker;
use App\Models\User;
use App\Services\ShiftCloseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit Tests for ShiftCloseService — Phase 3A
 *
 * Tests the service layer: getReviewData() and closeAndCalculate().
 * Covers payout batch calculation, revenue computation, eligibility checks,
 * and data preparation for the review page.
 *
 * Acceptance Criteria: AC-3A-20 through AC-3A-44
 * Business Rules: BR-02, BR-03, BR-04, ADR-005 D1–D5
 *
 * @see docs/plans/phase-3a-shift-close-payout-calculation.md
 */
class ShiftCloseServiceTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;
    private User $admin;
    private ShiftCloseService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create([
            'rate_per_trip' => '15.00',
        ]);

        $this->admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $this->service = new ShiftCloseService();
    }

    // ========================================================================
    // Helper Methods
    // ========================================================================

    private function createOpenShift(array $overrides = []): Shift
    {
        return Shift::factory()->started()->create(array_merge([
            'restaurant_id' => $this->restaurant->id,
            'restaurant_rate' => '15.00',
        ], $overrides));
    }

    private function assignBikerToShift(
        Shift $shift,
        array $bikerOverrides = [],
        array $pivotOverrides = [],
    ): ShiftBiker {
        $biker = Biker::factory()->create(array_merge([
            'rate_per_trip' => '10.00',
            'base_fee' => '25.00',
        ], $bikerOverrides));

        return ShiftBiker::factory()->create(array_merge([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 5,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ], $pivotOverrides));
    }

    // ========================================================================
    // AC-3A-20: On successful close, one Payment per shift_biker
    // ========================================================================

    /**
     * AC-3A-20, BR-04: closeAndCalculate creates one Payment per shift_biker.
     */
    public function test_close_and_calculate_creates_one_payment_per_shift_biker(): void
    {
        $shift = $this->createOpenShift();
        $sb1 = $this->assignBikerToShift($shift, [], ['trips_count' => 3]);
        $sb2 = $this->assignBikerToShift($shift, [], ['trips_count' => 7]);

        $this->service->closeAndCalculate($shift, $this->admin);

        $this->assertEquals(2, Payment::count(),
            'AC-3A-20: Must create exactly 2 Payment rows for 2 shift_bikers');

        $this->assertDatabaseHas('payments', ['shift_biker_id' => $sb1->id]);
        $this->assertDatabaseHas('payments', ['shift_biker_id' => $sb2->id]);
    }

    // ========================================================================
    // AC-3A-21: Payment amount = PayoutService::calculate() output (BCMath string)
    // ========================================================================

    /**
     * AC-3A-21, BR-03: Payment amount matches PayoutService formula.
     * base_fee=25.00, biker_rate=10.00, trips=5 → payout = 25.00 + (10.00 × 5) = 75.00
     */
    public function test_payment_amount_equals_payout_service_output(): void
    {
        $shift = $this->createOpenShift();
        $sb = $this->assignBikerToShift($shift, [], [
            'trips_count' => 5,
            'base_fee' => '25.00',
            'biker_rate' => '10.00',
        ]);

        $this->service->closeAndCalculate($shift, $this->admin);

        $payment = Payment::where('shift_biker_id', $sb->id)->first();
        $this->assertEquals('75.00', $payment->amount,
            'AC-3A-21: Payment amount must equal PayoutService output (25.00 + 10.00×5 = 75.00)');
    }

    // ========================================================================
    // AC-3A-22: Payment revenue = RevenueService::calculate() output
    // ========================================================================

    /**
     * AC-3A-22: Payment revenue matches RevenueService formula.
     * restaurant_rate=15.00, trips=5, payout=75.00 → revenue = (15.00×5) − 75.00 = 0.00
     */
    public function test_payment_revenue_equals_revenue_service_output(): void
    {
        $shift = $this->createOpenShift(['restaurant_rate' => '15.00']);
        $sb = $this->assignBikerToShift($shift, [], [
            'trips_count' => 5,
            'base_fee' => '25.00',
            'biker_rate' => '10.00',
        ]);

        $this->service->closeAndCalculate($shift, $this->admin);

        $payment = Payment::where('shift_biker_id', $sb->id)->first();
        // Revenue = (15.00 × 5) − 75.00 = 75.00 − 75.00 = 0.00
        $this->assertEquals('0.00', $payment->revenue,
            'AC-3A-22: Payment revenue must equal RevenueService output');
    }

    /**
     * AC-3A-22: Revenue with different restaurant_rate produces positive margin.
     * restaurant_rate=20.00, trips=5, payout=75.00 → revenue = (20.00×5) − 75.00 = 25.00
     */
    public function test_payment_revenue_positive_margin(): void
    {
        $shift = $this->createOpenShift(['restaurant_rate' => '20.00']);
        $sb = $this->assignBikerToShift($shift, [], [
            'trips_count' => 5,
            'base_fee' => '25.00',
            'biker_rate' => '10.00',
        ]);

        $this->service->closeAndCalculate($shift, $this->admin);

        $payment = Payment::where('shift_biker_id', $sb->id)->first();
        // Revenue = (20.00 × 5) − 75.00 = 100.00 − 75.00 = 25.00
        $this->assertEquals('25.00', $payment->revenue,
            'AC-3A-22: Revenue must be 25.00 for restaurant_rate=20.00, payout=75.00');
    }

    // ========================================================================
    // AC-3A-23: Each Payment has status = 'pending'
    // ========================================================================

    /**
     * AC-3A-23, BR-03: All created Payments have status = 'pending'.
     */
    public function test_created_payments_have_pending_status(): void
    {
        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, [], ['trips_count' => 3]);

        $this->service->closeAndCalculate($shift, $this->admin);

        $payments = Payment::all();
        foreach ($payments as $payment) {
            $this->assertEquals(PaymentStatus::Pending, $payment->status,
                'AC-3A-23: Every Payment must have status=pending');
        }
    }

    // ========================================================================
    // AC-3A-24: Payment for 0 trips has amount='0.00' and revenue='0.00'
    // ========================================================================

    /**
     * AC-3A-24, BR-03: Zero trips → amount='0.00', revenue='0.00'.
     * Payment row IS still created.
     */
    public function test_payment_for_zero_trips_has_zero_amount_and_revenue(): void
    {
        $shift = $this->createOpenShift();
        $sb = $this->assignBikerToShift($shift, [], [
            'trips_count' => 0,
            'base_fee' => '25.00',
            'biker_rate' => '10.00',
        ]);

        $this->service->closeAndCalculate($shift, $this->admin);

        $payment = Payment::where('shift_biker_id', $sb->id)->first();
        $this->assertNotNull($payment, 'AC-3A-24: Payment row must be created even for 0 trips');
        $this->assertEquals('0.00', $payment->amount,
            'AC-3A-24: Amount for 0 trips must be 0.00');
        $this->assertEquals('0.00', $payment->revenue,
            'AC-3A-24: Revenue for 0 trips must be 0.00');
    }

    // ========================================================================
    // AC-3A-25: Payment for >0 trips follows payout formula
    // ========================================================================

    /**
     * AC-3A-25, BR-03: Payout = base_fee + (biker_rate × trips_count).
     */
    public function test_payment_amount_for_trips_follows_formula(): void
    {
        $shift = $this->createOpenShift();
        $sb = $this->assignBikerToShift($shift, [], [
            'trips_count' => 8,
            'base_fee' => '30.00',
            'biker_rate' => '12.50',
        ]);

        $this->service->closeAndCalculate($shift, $this->admin);

        $payment = Payment::where('shift_biker_id', $sb->id)->first();
        // 30.00 + (12.50 × 8) = 30.00 + 100.00 = 130.00
        $this->assertEquals('130.00', $payment->amount,
            'AC-3A-25: Payout must equal 30.00 + (12.50 × 8) = 130.00');
    }

    // ========================================================================
    // AC-3A-26: Revenue for >0 trips follows revenue formula
    // ========================================================================

    /**
     * AC-3A-26: Revenue = (restaurant_rate × trips_count) − payout.
     * restaurant_rate=20.00, trips=8, payout=130.00 → revenue = 160.00 − 130.00 = 30.00
     */
    public function test_revenue_for_trips_follows_formula(): void
    {
        $shift = $this->createOpenShift(['restaurant_rate' => '20.00']);
        $sb = $this->assignBikerToShift($shift, [], [
            'trips_count' => 8,
            'base_fee' => '30.00',
            'biker_rate' => '12.50',
        ]);

        $this->service->closeAndCalculate($shift, $this->admin);

        $payment = Payment::where('shift_biker_id', $sb->id)->first();
        // Revenue = (20.00 × 8) − 130.00 = 160.00 − 130.00 = 30.00
        $this->assertEquals('30.00', $payment->revenue,
            'AC-3A-26: Revenue must equal (20.00×8) − 130.00 = 30.00');
    }

    // ========================================================================
    // AC-3A-27: No duplicate Payment rows (idempotency guard)
    // ========================================================================

    /**
     * AC-3A-27: Calling closeAndCalculate twice does NOT create duplicate Payment rows.
     * The second call should either be a no-op or update existing rows.
     */
    public function test_no_duplicate_payments_on_double_close(): void
    {
        $shift = $this->createOpenShift();
        $sb = $this->assignBikerToShift($shift, [], ['trips_count' => 3]);

        // First call — creates payment and closes shift
        $this->service->closeAndCalculate($shift, $this->admin);

        // Second call — shift is already closed, should not create duplicates
        try {
            $this->service->closeAndCalculate($shift->fresh(), $this->admin);
        } catch (\RuntimeException) {
            // Expected: service should reject non-open shift
        }

        $paymentCount = Payment::where('shift_biker_id', $sb->id)->count();
        $this->assertEquals(1, $paymentCount,
            'AC-3A-27: No duplicate Payment rows for the same shift_biker');
    }

    // ========================================================================
    // AC-3A-28: Closing shift with zero bikers creates zero Payments
    // ========================================================================

    /**
     * AC-3A-28: Shift with no assigned bikers → no Payment rows.
     * Shift still transitions to closed.
     */
    public function test_close_shift_with_zero_bikers_creates_zero_payments(): void
    {
        $shift = $this->createOpenShift();

        $this->service->closeAndCalculate($shift, $this->admin);

        $this->assertEquals(0, Payment::count(),
            'AC-3A-28: No Payment rows for shift with zero bikers');
        $this->assertEquals(ShiftStatus::Closed, $shift->fresh()->status,
            'AC-3A-28: Shift still transitions to closed');
    }

    // ========================================================================
    // AC-3A-29: getReviewData flags bikers without User account
    // ========================================================================

    /**
     * AC-3A-29, ADR-005 D4: Review data flags bikers where User::where('biker_id') returns null.
     */
    public function test_review_data_flags_biker_without_user_account(): void
    {
        $shift = $this->createOpenShift();
        $biker = Biker::factory()->create(); // No User linked to this biker
        ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 3,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);

        $reviewData = $this->service->getReviewData($shift);

        $this->assertTrue($reviewData['hasWarnings'],
            'AC-3A-29: hasWarnings must be true when biker has no User account');

        $warningItem = collect($reviewData['reviewItems'])->firstWhere('biker.id', $biker->id);
        $this->assertFalse($warningItem['hasUser'],
            'AC-3A-29: hasUser must be false for biker without User account');
        $this->assertNotEmpty($warningItem['warnings'],
            'AC-3A-29: Warnings array must not be empty');
    }

    /**
     * AC-3A-29: Biker WITH User account is not flagged.
     */
    public function test_review_data_does_not_flag_biker_with_user_account(): void
    {
        $shift = $this->createOpenShift();
        $biker = Biker::factory()->create();
        User::factory()->create([
            'role' => UserRole::Biker,
            'biker_id' => $biker->id,
        ]);
        ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 3,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);

        $reviewData = $this->service->getReviewData($shift);

        $reviewItem = collect($reviewData['reviewItems'])->firstWhere('biker.id', $biker->id);
        $this->assertTrue($reviewItem['hasUser'],
            'AC-3A-29: hasUser must be true for biker with User account');
        $userWarning = collect($reviewItem['warnings'])->contains(
            fn ($w) => str_contains($w, 'conta de usuário') || str_contains($w, 'user')
        );
        $this->assertFalse($userWarning,
            'AC-3A-29: No user-account warning for biker with User account');
    }

    // ========================================================================
    // AC-3A-30: getReviewData flags bikers without verified PIX key
    // ========================================================================

    /**
     * AC-3A-30, BR-02: Review data flags bikers with no verified PIX key.
     */
    public function test_review_data_flags_biker_without_verified_pix_key(): void
    {
        $shift = $this->createOpenShift();
        $biker = Biker::factory()->create();
        // Create an unverified PIX key
        PixKey::factory()->create([
            'biker_id' => $biker->id,
            'is_verified' => false,
        ]);
        ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 3,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);

        $reviewData = $this->service->getReviewData($shift);

        $reviewItem = collect($reviewData['reviewItems'])->firstWhere('biker.id', $biker->id);
        $this->assertFalse($reviewItem['hasVerifiedPixKey'],
            'AC-3A-30: hasVerifiedPixKey must be false when no verified PIX key exists');
    }

    /**
     * AC-3A-30: Biker with verified PIX key is not flagged.
     */
    public function test_review_data_does_not_flag_biker_with_verified_pix_key(): void
    {
        $shift = $this->createOpenShift();
        $biker = Biker::factory()->create();
        PixKey::factory()->create([
            'biker_id' => $biker->id,
            'is_verified' => true,
            'verified_at' => now(),
        ]);
        ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 3,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);

        $reviewData = $this->service->getReviewData($shift);

        $reviewItem = collect($reviewData['reviewItems'])->firstWhere('biker.id', $biker->id);
        $this->assertTrue($reviewItem['hasVerifiedPixKey'],
            'AC-3A-30: hasVerifiedPixKey must be true when verified PIX key exists');
    }

    /**
     * AC-3A-30: Biker with no PIX keys at all is flagged.
     */
    public function test_review_data_flags_biker_with_no_pix_keys(): void
    {
        $shift = $this->createOpenShift();
        $biker = Biker::factory()->create(); // No PIX keys
        ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 3,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);

        $reviewData = $this->service->getReviewData($shift);

        $reviewItem = collect($reviewData['reviewItems'])->firstWhere('biker.id', $biker->id);
        $this->assertFalse($reviewItem['hasVerifiedPixKey'],
            'AC-3A-30: hasVerifiedPixKey must be false when biker has no PIX keys');
    }

    // ========================================================================
    // AC-3A-31: Eligibility warnings do NOT prevent Payment creation
    // ========================================================================

    /**
     * AC-3A-31, ADR-005 D4: Payment is created even for bikers without User account.
     */
    public function test_payment_created_despite_missing_user_account(): void
    {
        $shift = $this->createOpenShift();
        $biker = Biker::factory()->create(); // No User linked
        $sb = ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 3,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);

        $this->service->closeAndCalculate($shift, $this->admin);

        $payment = Payment::where('shift_biker_id', $sb->id)->first();
        $this->assertNotNull($payment,
            'AC-3A-31: Payment must be created even when biker has no User account');
        // 25.00 + (10.00 × 3) = 55.00
        $this->assertEquals('55.00', $payment->amount,
            'AC-3A-31: Payment amount must be correct despite missing User account');
    }

    /**
     * AC-3A-31, BR-02: Payment is created even for bikers without verified PIX key.
     */
    public function test_payment_created_despite_missing_verified_pix_key(): void
    {
        $shift = $this->createOpenShift();
        $biker = Biker::factory()->create();
        PixKey::factory()->create([
            'biker_id' => $biker->id,
            'is_verified' => false,
        ]);
        $sb = ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 3,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);

        $this->service->closeAndCalculate($shift, $this->admin);

        $payment = Payment::where('shift_biker_id', $sb->id)->first();
        $this->assertNotNull($payment,
            'AC-3A-31: Payment must be created even when biker has no verified PIX key');
    }

    // ========================================================================
    // AC-3A-33: Payout for 0 trips = '0.00'
    // ========================================================================

    /**
     * AC-3A-33, BR-03: Payout for 0 trips is exactly '0.00' (BCMath string).
     */
    public function test_payout_for_zero_trips_is_zero_string(): void
    {
        $shift = $this->createOpenShift();
        $sb = $this->assignBikerToShift($shift, [], [
            'trips_count' => 0,
            'base_fee' => '30.00',
            'biker_rate' => '15.00',
        ]);

        $this->service->closeAndCalculate($shift, $this->admin);

        $payment = Payment::where('shift_biker_id', $sb->id)->first();
        $this->assertIsString($payment->amount,
            'AC-3A-33: Payout must be a string (BCMath result)');
        $this->assertEquals('0.00', $payment->amount,
            'AC-3A-33: Payout for 0 trips must be exactly 0.00');
    }

    // ========================================================================
    // AC-3A-34: Payout for 1 trip = base_fee + biker_rate
    // ========================================================================

    /**
     * AC-3A-34, BR-03: Payout for 1 trip = base_fee + biker_rate.
     * base_fee=30.00, biker_rate=15.00 → 45.00
     */
    public function test_payout_for_one_trip_equals_base_fee_plus_rate(): void
    {
        $shift = $this->createOpenShift();
        $sb = $this->assignBikerToShift($shift, [], [
            'trips_count' => 1,
            'base_fee' => '30.00',
            'biker_rate' => '15.00',
        ]);

        $this->service->closeAndCalculate($shift, $this->admin);

        $payment = Payment::where('shift_biker_id', $sb->id)->first();
        $this->assertEquals('45.00', $payment->amount,
            'AC-3A-34: Payout for 1 trip must equal base_fee + biker_rate = 45.00');
    }

    // ========================================================================
    // AC-3A-35: Payout for N trips = base_fee + (biker_rate × N) using BCMath scale 2
    // ========================================================================

    /**
     * AC-3A-35, BR-03: Payout for N=100 trips with decimal rate.
     * base_fee=25.00, biker_rate=10.00, trips=100 → 25.00 + (10.00 × 100) = 1025.00
     */
    public function test_payout_for_large_trips_follows_formula(): void
    {
        $shift = $this->createOpenShift();
        $sb = $this->assignBikerToShift($shift, [], [
            'trips_count' => 100,
            'base_fee' => '25.00',
            'biker_rate' => '10.00',
        ]);

        $this->service->closeAndCalculate($shift, $this->admin);

        $payment = Payment::where('shift_biker_id', $sb->id)->first();
        $this->assertEquals('1025.00', $payment->amount,
            'AC-3A-35: Payout for 100 trips must equal 1025.00');
    }

    // ========================================================================
    // AC-3A-36: Revenue for 0 trips = '0.00'
    // ========================================================================

    /**
     * AC-3A-36: Revenue for 0 trips is exactly '0.00'.
     */
    public function test_revenue_for_zero_trips_is_zero_string(): void
    {
        $shift = $this->createOpenShift(['restaurant_rate' => '20.00']);
        $sb = $this->assignBikerToShift($shift, [], [
            'trips_count' => 0,
            'base_fee' => '25.00',
            'biker_rate' => '10.00',
        ]);

        $this->service->closeAndCalculate($shift, $this->admin);

        $payment = Payment::where('shift_biker_id', $sb->id)->first();
        $this->assertEquals('0.00', $payment->revenue,
            'AC-3A-36: Revenue for 0 trips must be 0.00');
    }

    // ========================================================================
    // AC-3A-37: Revenue can be negative (loss scenario)
    // ========================================================================

    /**
     * AC-3A-37: Revenue can be negative and stored correctly.
     * restaurant_rate=5.00, trips=5, payout=75.00 → revenue = (5.00×5) − 75.00 = 25.00 − 75.00 = −50.00
     */
    public function test_revenue_can_be_negative_stored_correctly(): void
    {
        $shift = $this->createOpenShift(['restaurant_rate' => '5.00']);
        $sb = $this->assignBikerToShift($shift, [], [
            'trips_count' => 5,
            'base_fee' => '25.00',
            'biker_rate' => '10.00',
        ]);

        $this->service->closeAndCalculate($shift, $this->admin);

        $payment = Payment::where('shift_biker_id', $sb->id)->first();
        // Revenue = (5.00 × 5) − 75.00 = 25.00 − 75.00 = -50.00
        $this->assertEquals('-50.00', $payment->revenue,
            'AC-3A-37: Negative revenue must be stored correctly as -50.00');
    }

    // ========================================================================
    // AC-3A-38: Shift closed_at is never NULL after successful close
    // ========================================================================

    /**
     * AC-3A-38: closed_at is set after closeAndCalculate.
     */
    public function test_closed_at_is_set_after_close(): void
    {
        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, [], ['trips_count' => 3]);

        $before = now()->subSecond();
        $this->service->closeAndCalculate($shift, $this->admin);
        $after = now()->addSecond();

        $freshShift = $shift->fresh();
        $this->assertNotNull($freshShift->closed_at,
            'AC-3A-38: closed_at must never be NULL after successful close');
        $this->assertTrue(
            $freshShift->closed_at->greaterThanOrEqualTo($before)
            && $freshShift->closed_at->lessThanOrEqualTo($after),
            'AC-3A-38: closed_at must be approximately current timestamp'
        );
    }

    // ========================================================================
    // AC-3A-39: All Payment amounts and revenues are DECIMAL(12,2) — 2 decimal places
    // ========================================================================

    /**
     * AC-3A-39: Payment amounts are stored with exactly 2 decimal places.
     */
    public function test_payment_amounts_have_two_decimal_places(): void
    {
        $shift = $this->createOpenShift(['restaurant_rate' => '12.50']);
        $sb = $this->assignBikerToShift($shift, [], [
            'trips_count' => 7,
            'base_fee' => '25.00',
            'biker_rate' => '12.50',
        ]);

        $this->service->closeAndCalculate($shift, $this->admin);

        $payment = Payment::where('shift_biker_id', $sb->id)->first();
        // Payout = 25.00 + (12.50 × 7) = 25.00 + 87.50 = 112.50
        $this->assertEquals('112.50', $payment->amount,
            'AC-3A-39: Amount must have exactly 2 decimal places');

        // Revenue = (12.50 × 7) − 112.50 = 87.50 − 112.50 = -25.00
        $this->assertEquals('-25.00', $payment->revenue,
            'AC-3A-39: Revenue must have exactly 2 decimal places');
    }

    // ========================================================================
    // AC-3A-40: Payout reads from shift_bikers (snapshotted values), NOT bikers profile
    // ========================================================================

    /**
     * AC-3A-40, ADR-005 D2: Payout uses shift_bikers rates, NOT biker profile rates.
     * Biker profile: rate_per_trip=99.99, base_fee=88.88
     * Shift_bikers: biker_rate=10.00, base_fee=25.00
     * Payout must use shift_bikers values: 25.00 + (10.00 × 3) = 55.00
     */
    public function test_payout_uses_shift_biker_rates_not_biker_profile(): void
    {
        $shift = $this->createOpenShift();
        $biker = Biker::factory()->create([
            'rate_per_trip' => '99.99',
            'base_fee' => '88.88',
        ]);
        $sb = ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 3,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);

        $this->service->closeAndCalculate($shift, $this->admin);

        $payment = Payment::where('shift_biker_id', $sb->id)->first();
        $this->assertEquals('55.00', $payment->amount,
            'AC-3A-40: Payout must use shift_bikers snapshotted rates (25.00 + 10.00×3 = 55.00), not biker profile (88.88 + 99.99×3 = 386.85)');
    }

    // ========================================================================
    // AC-3A-41: Revenue reads from shifts.restaurant_rate, NOT restaurants.rate_per_trip
    // ========================================================================

    /**
     * AC-3A-41, ADR-005 D2: Revenue uses shifts.restaurant_rate, NOT restaurants.rate_per_trip.
     * Restaurant profile: rate_per_trip=99.99
     * Shift: restaurant_rate=15.00
     * Revenue must use shift value: (15.00 × 5) − 75.00 = 0.00
     */
    public function test_revenue_uses_shift_restaurant_rate_not_restaurant_profile(): void
    {
        $restaurant = Restaurant::factory()->create([
            'rate_per_trip' => '99.99',
        ]);
        $shift = Shift::factory()->started()->create([
            'restaurant_id' => $restaurant->id,
            'restaurant_rate' => '15.00',
        ]);
        $sb = $this->assignBikerToShift($shift, [], [
            'trips_count' => 5,
            'base_fee' => '25.00',
            'biker_rate' => '10.00',
        ]);

        $this->service->closeAndCalculate($shift, $this->admin);

        $payment = Payment::where('shift_biker_id', $sb->id)->first();
        // Revenue = (15.00 × 5) − 75.00 = 75.00 − 75.00 = 0.00
        // NOT using restaurant rate: (99.99 × 5) − 75.00 = 499.95 − 75.00 = 424.95
        $this->assertEquals('0.00', $payment->revenue,
            'AC-3A-41: Revenue must use shifts.restaurant_rate (15.00), not restaurants.rate_per_trip (99.99)');
    }

    // ========================================================================
    // getReviewData — Review Items Computation
    // ========================================================================

    /**
     * AC-3A-05: getReviewData computes projected payout per shift_biker.
     */
    public function test_review_data_computes_projected_payout_per_biker(): void
    {
        $shift = $this->createOpenShift();
        $sb = $this->assignBikerToShift($shift, [], [
            'trips_count' => 4,
            'base_fee' => '25.00',
            'biker_rate' => '10.00',
        ]);

        $reviewData = $this->service->getReviewData($shift);

        $item = collect($reviewData['reviewItems'])->firstWhere('shiftBiker.id', $sb->id);
        $this->assertEquals('65.00', $item['payout'],
            'AC-3A-05: Projected payout must be 25.00 + (10.00×4) = 65.00');
    }

    /**
     * AC-3A-06: getReviewData computes projected revenue per shift_biker.
     */
    public function test_review_data_computes_projected_revenue_per_biker(): void
    {
        $shift = $this->createOpenShift(['restaurant_rate' => '20.00']);
        $sb = $this->assignBikerToShift($shift, [], [
            'trips_count' => 4,
            'base_fee' => '25.00',
            'biker_rate' => '10.00',
        ]);

        $reviewData = $this->service->getReviewData($shift);

        $item = collect($reviewData['reviewItems'])->firstWhere('shiftBiker.id', $sb->id);
        // Revenue = (20.00 × 4) − 65.00 = 80.00 − 65.00 = 15.00
        $this->assertEquals('15.00', $item['revenue'],
            'AC-3A-06: Projected revenue must be (20.00×4) − 65.00 = 15.00');
    }

    /**
     * AC-3A-07: getReviewData computes total payout across all shift_bikers.
     */
    public function test_review_data_computes_total_payout(): void
    {
        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, [], [
            'trips_count' => 3,
            'base_fee' => '25.00',
            'biker_rate' => '10.00',
        ]);
        $this->assignBikerToShift($shift, [], [
            'trips_count' => 5,
            'base_fee' => '25.00',
            'biker_rate' => '10.00',
        ]);

        $reviewData = $this->service->getReviewData($shift);

        // Biker 1: 25.00 + (10.00×3) = 55.00
        // Biker 2: 25.00 + (10.00×5) = 75.00
        // Total: 130.00
        $this->assertEquals('130.00', $reviewData['totalPayout'],
            'AC-3A-07: Total payout must be 55.00 + 75.00 = 130.00');
    }

    /**
     * AC-3A-08: getReviewData computes total revenue across all shift_bikers.
     */
    public function test_review_data_computes_total_revenue(): void
    {
        $shift = $this->createOpenShift(['restaurant_rate' => '20.00']);
        $this->assignBikerToShift($shift, [], [
            'trips_count' => 3,
            'base_fee' => '25.00',
            'biker_rate' => '10.00',
        ]);
        $this->assignBikerToShift($shift, [], [
            'trips_count' => 5,
            'base_fee' => '25.00',
            'biker_rate' => '10.00',
        ]);

        $reviewData = $this->service->getReviewData($shift);

        // Biker 1: revenue = (20.00×3) − 55.00 = 60.00 − 55.00 = 5.00
        // Biker 2: revenue = (20.00×5) − 75.00 = 100.00 − 75.00 = 25.00
        // Total: 30.00
        $this->assertEquals('30.00', $reviewData['totalRevenue'],
            'AC-3A-08: Total revenue must be 5.00 + 25.00 = 30.00');
    }

    /**
     * AC-3A-04: getReviewData includes biker name, trip count, biker_rate, base_fee.
     */
    public function test_review_data_includes_biker_details(): void
    {
        $shift = $this->createOpenShift();
        $biker = Biker::factory()->create(['name' => 'João Silva']);
        $sb = ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 6,
            'biker_rate' => '12.00',
            'base_fee' => '30.00',
        ]);

        $reviewData = $this->service->getReviewData($shift);

        $item = collect($reviewData['reviewItems'])->first();
        $this->assertEquals('João Silva', $item['biker']->name,
            'AC-3A-04: Review item must include biker name');
        $this->assertEquals(6, $item['shiftBiker']->trips_count,
            'AC-3A-04: Review item must include trip count');
        $this->assertEquals('12.00', $item['shiftBiker']->biker_rate,
            'AC-3A-04: Review item must include biker_rate');
        $this->assertEquals('30.00', $item['shiftBiker']->base_fee,
            'AC-3A-04: Review item must include base_fee');
    }

    // ========================================================================
    // Edge Case: Deactivated biker still gets Payment (ADR-005 D3)
    // ========================================================================

    /**
     * Edge Case 3, ADR-005 D3: Deactivated biker still receives Payment row.
     */
    public function test_deactivated_biker_still_gets_payment(): void
    {
        $shift = $this->createOpenShift();
        $biker = Biker::factory()->create([
            'active' => false,
            'rate_per_trip' => '10.00',
            'base_fee' => '25.00',
        ]);
        $sb = ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 4,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);

        $this->service->closeAndCalculate($shift, $this->admin);

        $payment = Payment::where('shift_biker_id', $sb->id)->first();
        $this->assertNotNull($payment,
            'Edge Case 3: Deactivated biker must still get a Payment row');
        $this->assertEquals('65.00', $payment->amount,
            'Edge Case 3: Payout for deactivated biker must be correct (25.00 + 10.00×4 = 65.00)');
    }

    // ========================================================================
    // Multiple shift_bikers with different rates
    // ========================================================================

    /**
     * Integration: Multiple bikers with different snapshotted rates.
     */
    public function test_batch_payout_with_different_rates_per_biker(): void
    {
        $shift = $this->createOpenShift(['restaurant_rate' => '20.00']);

        $biker1 = Biker::factory()->create();
        $sb1 = ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => $biker1->id,
            'trips_count' => 5,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);

        $biker2 = Biker::factory()->create();
        $sb2 = ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => $biker2->id,
            'trips_count' => 3,
            'biker_rate' => '15.00',
            'base_fee' => '30.00',
        ]);

        $this->service->closeAndCalculate($shift, $this->admin);

        // Biker 1: 25.00 + (10.00 × 5) = 75.00
        $p1 = Payment::where('shift_biker_id', $sb1->id)->first();
        $this->assertEquals('75.00', $p1->amount);
        // Revenue 1: (20.00 × 5) − 75.00 = 25.00
        $this->assertEquals('25.00', $p1->revenue);

        // Biker 2: 30.00 + (15.00 × 3) = 75.00
        $p2 = Payment::where('shift_biker_id', $sb2->id)->first();
        $this->assertEquals('75.00', $p2->amount);
        // Revenue 2: (20.00 × 3) − 75.00 = -15.00
        $this->assertEquals('-15.00', $p2->revenue);
    }

    // ========================================================================
    // closeAndCalculate — State Transition
    // ========================================================================

    /**
     * AC-3A-14: closeAndCalculate transitions shift from open to closed.
     */
    public function test_close_and_calculate_transitions_shift_to_closed(): void
    {
        $shift = $this->createOpenShift();

        $this->service->closeAndCalculate($shift, $this->admin);

        $this->assertEquals(ShiftStatus::Closed, $shift->fresh()->status,
            'AC-3A-14: Shift must transition to closed');
    }

    /**
     * AC-3A-14: closeAndCalculate throws RuntimeException for non-open shift.
     */
    public function test_close_and_calculate_rejects_non_open_shift(): void
    {
        $shift = $this->createOpenShift();
        $shift->status = ShiftStatus::Closed;
        $shift->save();

        $this->expectException(\RuntimeException::class);

        $this->service->closeAndCalculate($shift->fresh(), $this->admin);
    }
}
