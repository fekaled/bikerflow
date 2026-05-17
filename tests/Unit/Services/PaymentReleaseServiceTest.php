<?php

namespace Tests\Unit\Services;

use App\Enums\PaymentAuditAction;
use App\Enums\PaymentStatus;
use App\Enums\ShiftStatus;
use App\Enums\UserRole;
use App\Models\Biker;
use App\Models\Payment;
use App\Models\PaymentAuditLog;
use App\Models\PixKey;
use App\Models\Restaurant;
use App\Models\Shift;
use App\Models\ShiftBiker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit Tests for PaymentReleaseService — Phase 3B
 *
 * Tests the core business logic for payment release:
 * - Eligibility checks (BR-02, ADR-005 D4)
 * - Individual payment release (BR-03 Manual Release)
 * - Batch release (BR-04 Granular Failure)
 * - Shift auto-transition (closed → approved)
 * - Audit trail (BR-06 pattern)
 * - Financial integrity (amounts never modified)
 *
 * Acceptance Criteria: AC-3B-14 through AC-3B-46
 * Business Rules: BR-02, BR-03, BR-04
 *
 * @see docs/plans/phase-3b-payment-release-admin-approval.md
 */
class PaymentReleaseServiceTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create([
            'rate_per_trip' => '15.00',
        ]);

        $this->admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private function createClosedShift(array $overrides = []): Shift
    {
        $shift = Shift::factory()->started()->create(array_merge([
            'restaurant_id' => $this->restaurant->id,
            'restaurant_rate' => '15.00',
        ], $overrides));

        $shift->status = ShiftStatus::Closed;
        $shift->closed_at = now();
        $shift->save();

        return $shift->fresh();
    }

    private function createApprovedShift(array $overrides = []): Shift
    {
        $shift = $this->createClosedShift($overrides);
        $shift->status = ShiftStatus::Approved;
        $shift->save();

        return $shift->fresh();
    }

    /**
     * Create a biker with a verified PIX key and linked User account (fully eligible).
     */
    private function createEligibleBiker(string $name = 'Eligible Biker'): array
    {
        $biker = Biker::factory()->create([
            'name' => $name,
            'rate_per_trip' => '10.00',
            'base_fee' => '25.00',
        ]);

        PixKey::factory()->create([
            'biker_id' => $biker->id,
            'is_verified' => true,
            'verified_at' => now(),
        ]);

        $user = User::factory()->create([
            'role' => UserRole::Biker,
            'biker_id' => $biker->id,
        ]);

        return ['biker' => $biker, 'user' => $user];
    }

    /**
     * Create a biker without verified PIX key (ineligible for release).
     */
    private function createBikerWithoutPix(string $name = 'No PIX Biker'): Biker
    {
        $biker = Biker::factory()->create([
            'name' => $name,
            'rate_per_trip' => '10.00',
            'base_fee' => '25.00',
        ]);

        // Create user but NO verified PIX
        User::factory()->create([
            'role' => UserRole::Biker,
            'biker_id' => $biker->id,
        ]);

        return $biker;
    }

    /**
     * Create a biker without User account (ineligible for release).
     */
    private function createBikerWithoutUser(string $name = 'No User Biker'): Biker
    {
        $biker = Biker::factory()->create([
            'name' => $name,
            'rate_per_trip' => '10.00',
            'base_fee' => '25.00',
        ]);

        PixKey::factory()->create([
            'biker_id' => $biker->id,
            'is_verified' => true,
            'verified_at' => now(),
        ]);

        return $biker;
    }

    /**
     * Assign a biker to a shift with a pending payment.
     */
    private function assignBikerWithPayment(
        Shift $shift,
        Biker $biker,
        array $pivotOverrides = [],
        array $paymentOverrides = [],
    ): array {
        $shiftBiker = ShiftBiker::factory()->create(array_merge([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 5,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ], $pivotOverrides));

        $payment = Payment::factory()->create(array_merge([
            'shift_biker_id' => $shiftBiker->id,
            'amount' => '75.00',
            'revenue' => '0.00',
            'status' => PaymentStatus::Pending,
        ], $paymentOverrides));

        return ['shiftBiker' => $shiftBiker, 'payment' => $payment];
    }

    // ========================================================================
    // AC-3B-14: Release transitions payment from pending to processing
    // ========================================================================

    /**
     * AC-3B-14, BR-03: Releasing an eligible payment transitions status to processing.
     */
    public function test_release_eligible_payment_transitions_to_processing(): void
    {
        $shift = $this->createClosedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker);

        $service = app(\App\Services\PaymentReleaseService::class);
        $result = $service->releasePayment($payment, $this->admin);

        $this->assertEquals(PaymentStatus::Processing, $result->status,
            'AC-3B-14: Released payment must have processing status');
        $this->assertEquals(PaymentStatus::Processing, $payment->fresh()->status,
            'AC-3B-14: Payment in DB must be processing');
    }

    // ========================================================================
    // AC-3B-15: Release sets released_by to admin user ID
    // ========================================================================

    /**
     * AC-3B-15, BR-03: Release sets released_by to authenticated admin's user ID.
     */
    public function test_release_sets_released_by_to_admin_id(): void
    {
        $shift = $this->createClosedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker);

        $service = app(\App\Services\PaymentReleaseService::class);
        $service->releasePayment($payment, $this->admin);

        $this->assertEquals($this->admin->id, $payment->fresh()->released_by,
            'AC-3B-15: released_by must be set to the admin user ID');
    }

    // ========================================================================
    // AC-3B-16: Release sets released_at to current timestamp
    // ========================================================================

    /**
     * AC-3B-16, BR-03: Release sets released_at to current timestamp.
     */
    public function test_release_sets_released_at_to_current_timestamp(): void
    {
        $shift = $this->createClosedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker);

        $before = now()->subSecond();
        $service = app(\App\Services\PaymentReleaseService::class);
        $service->releasePayment($payment, $this->admin);
        $after = now()->addSecond();

        $freshPayment = $payment->fresh();
        $this->assertNotNull($freshPayment->released_at,
            'AC-3B-16: released_at must be set');
        $this->assertTrue(
            $freshPayment->released_at->greaterThanOrEqualTo($before)
            && $freshPayment->released_at->lessThanOrEqualTo($after),
            'AC-3B-16: released_at must be approximately current timestamp'
        );
    }

    // ========================================================================
    // AC-3B-17: Release creates audit log entry
    // ========================================================================

    /**
     * AC-3B-17, AC-3B-43: Release creates a PaymentAuditLog entry with action=release.
     */
    public function test_release_creates_audit_log_entry(): void
    {
        $shift = $this->createClosedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker);

        $service = app(\App\Services\PaymentReleaseService::class);
        $service->releasePayment($payment, $this->admin);

        $this->assertEquals(1, PaymentAuditLog::where('payment_id', $payment->id)
            ->where('action', PaymentAuditAction::Release)->count(),
            'AC-3B-17: Must create exactly 1 release audit log entry');
    }

    // ========================================================================
    // AC-3B-44: Audit log transaction_ref is unique per release
    // ========================================================================

    /**
     * AC-3B-44: Audit log transaction_ref is unique per release action.
     */
    public function test_audit_log_transaction_ref_is_unique(): void
    {
        $shift = $this->createClosedShift();
        ['biker' => $biker1] = $this->createEligibleBiker('Biker 1');
        ['payment' => $payment1] = $this->assignBikerWithPayment($shift, $biker1);
        ['biker' => $biker2] = $this->createEligibleBiker('Biker 2');
        ['payment' => $payment2] = $this->assignBikerWithPayment($shift, $biker2);

        $service = app(\App\Services\PaymentReleaseService::class);
        $service->releasePayment($payment1, $this->admin);
        $service->releasePayment($payment2, $this->admin);

        $log1 = PaymentAuditLog::where('payment_id', $payment1->id)->first();
        $log2 = PaymentAuditLog::where('payment_id', $payment2->id)->first();

        $this->assertNotEquals($log1->transaction_ref, $log2->transaction_ref,
            'AC-3B-44: transaction_ref must be unique per release');
    }

    // ========================================================================
    // AC-3B-45: Audit log payload contains required fields
    // ========================================================================

    /**
     * AC-3B-45: Audit log payload contains released_by, released_at, amount, and biker_id.
     */
    public function test_audit_log_payload_contains_required_fields(): void
    {
        $shift = $this->createClosedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker);

        $service = app(\App\Services\PaymentReleaseService::class);
        $service->releasePayment($payment, $this->admin);

        $log = PaymentAuditLog::where('payment_id', $payment->id)->first();
        $payload = $log->payload;

        $this->assertArrayHasKey('released_by', $payload,
            'AC-3B-45: Payload must contain released_by');
        $this->assertArrayHasKey('released_at', $payload,
            'AC-3B-45: Payload must contain released_at');
        $this->assertArrayHasKey('amount', $payload,
            'AC-3B-45: Payload must contain amount');
        $this->assertArrayHasKey('biker_id', $payload,
            'AC-3B-45: Payload must contain biker_id');

        $this->assertEquals($this->admin->id, $payload['released_by']);
        $this->assertEquals($payment->amount, $payload['amount']);
        $this->assertEquals($biker->id, $payload['biker_id']);
    }

    // ========================================================================
    // AC-3B-18: Release blocked if biker has no verified PIX key (BR-02)
    // ========================================================================

    /**
     * AC-3B-18, BR-02: Release blocked if biker has no verified PIX key.
     */
    public function test_release_blocked_without_verified_pix_key(): void
    {
        $shift = $this->createClosedShift();
        $biker = $this->createBikerWithoutPix();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker);

        $service = app(\App\Services\PaymentReleaseService::class);

        $this->expectException(\RuntimeException::class);
        $service->releasePayment($payment, $this->admin);
    }

    /**
     * AC-3B-18, BR-02: Payment stays pending when PIX verification fails.
     */
    public function test_payment_stays_pending_when_pix_not_verified(): void
    {
        $shift = $this->createClosedShift();
        $biker = $this->createBikerWithoutPix();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker);

        $service = app(\App\Services\PaymentReleaseService::class);

        try {
            $service->releasePayment($payment, $this->admin);
        } catch (\RuntimeException) {
            // Expected
        }

        $this->assertEquals(PaymentStatus::Pending, $payment->fresh()->status,
            'AC-3B-18: Payment must stay pending when PIX is not verified');
    }

    // ========================================================================
    // AC-3B-19: Release blocked if biker has no linked User account (ADR-005 D4)
    // ========================================================================

    /**
     * AC-3B-19, ADR-005 D4: Release blocked if biker has no linked User account.
     */
    public function test_release_blocked_without_user_account(): void
    {
        $shift = $this->createClosedShift();
        $biker = $this->createBikerWithoutUser();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker);

        $service = app(\App\Services\PaymentReleaseService::class);

        $this->expectException(\RuntimeException::class);
        $service->releasePayment($payment, $this->admin);
    }

    /**
     * AC-3B-19: Payment stays pending when no User account linked.
     */
    public function test_payment_stays_pending_when_no_user_account(): void
    {
        $shift = $this->createClosedShift();
        $biker = $this->createBikerWithoutUser();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker);

        $service = app(\App\Services\PaymentReleaseService::class);

        try {
            $service->releasePayment($payment, $this->admin);
        } catch (\RuntimeException) {
            // Expected
        }

        $this->assertEquals(PaymentStatus::Pending, $payment->fresh()->status,
            'AC-3B-19: Payment must stay pending when no User account');
    }

    // ========================================================================
    // AC-3B-20: Release blocked if payment is not in pending status
    // ========================================================================

    /**
     * AC-3B-20: Release blocked if payment is already processing.
     */
    public function test_release_blocked_for_processing_payment(): void
    {
        $shift = $this->createClosedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, [], [
            'status' => PaymentStatus::Processing,
        ]);

        $service = app(\App\Services\PaymentReleaseService::class);

        $this->expectException(\RuntimeException::class);
        $service->releasePayment($payment, $this->admin);
    }

    /**
     * AC-3B-20: Release blocked if payment is already paid.
     */
    public function test_release_blocked_for_paid_payment(): void
    {
        $shift = $this->createClosedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, [], [
            'status' => PaymentStatus::Paid,
        ]);

        $service = app(\App\Services\PaymentReleaseService::class);

        $this->expectException(\RuntimeException::class);
        $service->releasePayment($payment, $this->admin);
    }

    /**
     * AC-3B-20: Release blocked if payment has failed status.
     */
    public function test_release_blocked_for_failed_payment(): void
    {
        $shift = $this->createClosedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, [], [
            'status' => PaymentStatus::Failed,
        ]);

        $service = app(\App\Services\PaymentReleaseService::class);

        $this->expectException(\RuntimeException::class);
        $service->releasePayment($payment, $this->admin);
    }

    // ========================================================================
    // AC-3B-46: Failed release attempts do NOT create audit log entries
    // ========================================================================

    /**
     * AC-3B-46: No audit log created when release is blocked by eligibility.
     */
    public function test_failed_release_does_not_create_audit_log(): void
    {
        $shift = $this->createClosedShift();
        $biker = $this->createBikerWithoutPix();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker);

        $service = app(\App\Services\PaymentReleaseService::class);

        try {
            $service->releasePayment($payment, $this->admin);
        } catch (\RuntimeException) {
            // Expected
        }

        $this->assertEquals(0, PaymentAuditLog::where('payment_id', $payment->id)->count(),
            'AC-3B-46: No audit log must be created for failed release attempt');
    }

    // ========================================================================
    // AC-3B-40: Payment amounts are never modified during release
    // ========================================================================

    /**
     * AC-3B-40, AC-3B-41: Release does not modify payment amount or revenue.
     */
    public function test_release_does_not_modify_amount_or_revenue(): void
    {
        $shift = $this->createClosedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, [], [
            'amount' => '75.00',
            'revenue' => '25.00',
        ]);

        $service = app(\App\Services\PaymentReleaseService::class);
        $service->releasePayment($payment, $this->admin);

        $fresh = $payment->fresh();
        $this->assertEquals('75.00', $fresh->amount,
            'AC-3B-40: Payment amount must not change during release');
        $this->assertEquals('25.00', $fresh->revenue,
            'AC-3B-41: Payment revenue must not change during release');
    }

    // ========================================================================
    // AC-3B-24: Batch release releases all eligible payments
    // ========================================================================

    /**
     * AC-3B-24, BR-04: Batch release releases all eligible payments.
     */
    public function test_batch_release_releases_all_eligible_payments(): void
    {
        $shift = $this->createClosedShift();
        ['biker' => $biker1] = $this->createEligibleBiker('Biker 1');
        ['payment' => $payment1] = $this->assignBikerWithPayment($shift, $biker1);
        ['biker' => $biker2] = $this->createEligibleBiker('Biker 2');
        ['payment' => $payment2] = $this->assignBikerWithPayment($shift, $biker2);

        $service = app(\App\Services\PaymentReleaseService::class);
        $results = $service->releaseAllEligiblePayments($shift, $this->admin);

        $this->assertCount(2, $results['released'],
            'AC-3B-24: Both eligible payments must be released');
        $this->assertEquals(PaymentStatus::Processing, $payment1->fresh()->status);
        $this->assertEquals(PaymentStatus::Processing, $payment2->fresh()->status);
    }

    // ========================================================================
    // AC-3B-25: Batch release skips ineligible payments (BR-04 granularity)
    // ========================================================================

    /**
     * AC-3B-25, BR-04: Batch release skips ineligible payments without error.
     */
    public function test_batch_release_skips_ineligible_payments(): void
    {
        $shift = $this->createClosedShift();
        ['biker' => $eligibleBiker] = $this->createEligibleBiker('Eligible');
        ['payment' => $eligiblePayment] = $this->assignBikerWithPayment($shift, $eligibleBiker);

        $noPixBiker = $this->createBikerWithoutPix('No PIX');
        ['payment' => $blockedPayment] = $this->assignBikerWithPayment($shift, $noPixBiker);

        $service = app(\App\Services\PaymentReleaseService::class);
        $results = $service->releaseAllEligiblePayments($shift, $this->admin);

        $this->assertCount(1, $results['released'],
            'AC-3B-25: Only 1 eligible payment must be released');
        $this->assertCount(1, $results['blocked'],
            'AC-3B-25: 1 ineligible payment must be blocked');
        $this->assertEquals(PaymentStatus::Processing, $eligiblePayment->fresh()->status);
        $this->assertEquals(PaymentStatus::Pending, $blockedPayment->fresh()->status);
    }

    // ========================================================================
    // AC-3B-26: Batch release returns summary with counts and reasons
    // ========================================================================

    /**
     * AC-3B-26: Batch release returns summary with released count and blocked reasons.
     */
    public function test_batch_release_returns_summary(): void
    {
        $shift = $this->createClosedShift();
        ['biker' => $eligibleBiker] = $this->createEligibleBiker('Eligible');
        $this->assignBikerWithPayment($shift, $eligibleBiker);

        $noPixBiker = $this->createBikerWithoutPix('No PIX');
        $this->assignBikerWithPayment($shift, $noPixBiker);

        $service = app(\App\Services\PaymentReleaseService::class);
        $results = $service->releaseAllEligiblePayments($shift, $this->admin);

        $this->assertArrayHasKey('released', $results,
            'AC-3B-26: Results must have released key');
        $this->assertArrayHasKey('blocked', $results,
            'AC-3B-26: Results must have blocked key');
        $this->assertCount(1, $results['released']);
        $this->assertCount(1, $results['blocked']);

        $blocked = $results['blocked'][0];
        $this->assertArrayHasKey('payment_id', $blocked);
        $this->assertArrayHasKey('biker', $blocked);
        $this->assertArrayHasKey('reason', $blocked);
    }

    // ========================================================================
    // AC-3B-27: Batch release only works on closed shifts
    // ========================================================================

    /**
     * AC-3B-27: Batch release only works on closed shifts — open shift rejected.
     */
    public function test_batch_release_rejected_for_open_shift(): void
    {
        $shift = Shift::factory()->started()->create([
            'restaurant_id' => $this->restaurant->id,
            'restaurant_rate' => '15.00',
        ]);

        $service = app(\App\Services\PaymentReleaseService::class);

        $this->expectException(\RuntimeException::class);
        $service->releaseAllEligiblePayments($shift, $this->admin);
    }

    // ========================================================================
    // AC-3B-28: Batch release is idempotent
    // ========================================================================

    /**
     * AC-3B-28: Batch release is idempotent — second call releases nothing.
     */
    public function test_batch_release_is_idempotent(): void
    {
        $shift = $this->createClosedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $this->assignBikerWithPayment($shift, $biker);

        $service = app(\App\Services\PaymentReleaseService::class);

        // First release
        $results1 = $service->releaseAllEligiblePayments($shift, $this->admin);
        $this->assertCount(1, $results1['released']);

        // Second release — nothing to release
        $results2 = $service->releaseAllEligiblePayments($shift->fresh(), $this->admin);
        $this->assertCount(0, $results2['released'],
            'AC-3B-28: Second batch release must release nothing');
    }

    // ========================================================================
    // AC-3B-29: Shift auto-transitions to approved when all payments released
    // ========================================================================

    /**
     * AC-3B-29: Shift auto-transitions from closed to approved when all payments released.
     */
    public function test_shift_auto_transitions_to_approved_when_all_released(): void
    {
        $shift = $this->createClosedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker);

        $service = app(\App\Services\PaymentReleaseService::class);
        $service->releasePayment($payment, $this->admin);

        $this->assertEquals(ShiftStatus::Approved, $shift->fresh()->status,
            'AC-3B-29: Shift must auto-transition to approved when all payments released');
    }

    /**
     * AC-3B-29: Shift auto-transitions after releasing last of multiple payments.
     */
    public function test_shift_transitions_after_releasing_last_payment(): void
    {
        $shift = $this->createClosedShift();
        ['biker' => $biker1] = $this->createEligibleBiker('Biker 1');
        ['payment' => $payment1] = $this->assignBikerWithPayment($shift, $biker1);
        ['biker' => $biker2] = $this->createEligibleBiker('Biker 2');
        ['payment' => $payment2] = $this->assignBikerWithPayment($shift, $biker2);

        $service = app(\App\Services\PaymentReleaseService::class);

        // Release first payment — shift should stay closed (one more pending)
        $service->releasePayment($payment1, $this->admin);
        $this->assertEquals(ShiftStatus::Closed, $shift->fresh()->status,
            'AC-3B-29: Shift must stay closed with 1 pending payment');

        // Release second payment — shift should transition to approved
        $service->releasePayment($payment2, $this->admin);
        $this->assertEquals(ShiftStatus::Approved, $shift->fresh()->status,
            'AC-3B-29: Shift must transition to approved when all payments released');
    }

    // ========================================================================
    // AC-3B-30: Shift transition happens atomically after last payment release
    // ========================================================================

    /**
     * AC-3B-30: Shift transition is atomic — payment is processing AND shift is approved in one release.
     */
    public function test_shift_transition_atomic_with_last_payment_release(): void
    {
        $shift = $this->createClosedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker);

        $service = app(\App\Services\PaymentReleaseService::class);
        $service->releasePayment($payment, $this->admin);

        // Both changes must be consistent
        $freshPayment = $payment->fresh();
        $freshShift = $shift->fresh();
        $this->assertEquals(PaymentStatus::Processing, $freshPayment->status,
            'AC-3B-30: Payment must be processing');
        $this->assertEquals(ShiftStatus::Approved, $freshShift->status,
            'AC-3B-30: Shift must be approved');
    }

    // ========================================================================
    // AC-3B-31: Shift with zero bikers auto-transitions to approved
    // ========================================================================

    /**
     * AC-3B-31: Shift with zero bikers/payments auto-transitions to approved (vacuous truth).
     */
    public function test_shift_with_zero_bikers_auto_transitions_to_approved(): void
    {
        $shift = $this->createClosedShift();
        // No bikers, no payments

        $service = app(\App\Services\PaymentReleaseService::class);
        $service->checkAndTransitionShiftToApproved($shift);

        $this->assertEquals(ShiftStatus::Approved, $shift->fresh()->status,
            'AC-3B-31: Zero-biker shift must auto-transition to approved');
    }

    // ========================================================================
    // AC-3B-32: Shift with some blocked payments stays closed
    // ========================================================================

    /**
     * AC-3B-32: Shift stays closed when some payments are blocked (not all released).
     */
    public function test_shift_stays_closed_with_blocked_payments(): void
    {
        $shift = $this->createClosedShift();
        ['biker' => $eligibleBiker] = $this->createEligibleBiker('Eligible');
        ['payment' => $eligiblePayment] = $this->assignBikerWithPayment($shift, $eligibleBiker);

        $noPixBiker = $this->createBikerWithoutPix('No PIX');
        $this->assignBikerWithPayment($shift, $noPixBiker);

        $service = app(\App\Services\PaymentReleaseService::class);

        // Release eligible — one is still blocked
        $service->releasePayment($eligiblePayment, $this->admin);

        $this->assertEquals(ShiftStatus::Closed, $shift->fresh()->status,
            'AC-3B-32: Shift must stay closed when blocked payments remain');
    }

    // ========================================================================
    // AC-3B-33: Approved shift review page shows all payment details
    // ========================================================================

    /**
     * AC-3B-33: getPaymentReviewData works for approved shifts (read-only view).
     */
    public function test_review_data_works_for_approved_shift(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, [], [
            'status' => PaymentStatus::Processing,
            'released_by' => $this->admin->id,
            'released_at' => now(),
        ]);

        $service = app(\App\Services\PaymentReleaseService::class);
        $reviewData = $service->getPaymentReviewData($shift);

        $this->assertArrayHasKey('paymentItems', $reviewData);
        $this->assertCount(1, $reviewData['paymentItems']);
        $this->assertEquals(PaymentStatus::Processing, $reviewData['paymentItems'][0]['payment']->status);
    }

    // ========================================================================
    // AC-3B-01/02: getPaymentReviewData works for closed and approved shifts
    // ========================================================================

    /**
     * AC-3B-01: getPaymentReviewData returns structured data for closed shift.
     */
    public function test_review_data_returns_structured_data_for_closed_shift(): void
    {
        $shift = $this->createClosedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $this->assignBikerWithPayment($shift, $biker);

        $service = app(\App\Services\PaymentReleaseService::class);
        $reviewData = $service->getPaymentReviewData($shift);

        $this->assertArrayHasKey('shift', $reviewData);
        $this->assertArrayHasKey('paymentItems', $reviewData);
        $this->assertArrayHasKey('totalPending', $reviewData);
        $this->assertArrayHasKey('totalProcessing', $reviewData);
        $this->assertArrayHasKey('eligibleCount', $reviewData);
        $this->assertArrayHasKey('ineligibleCount', $reviewData);
        $this->assertCount(1, $reviewData['paymentItems']);
    }

    // ========================================================================
    // Review data: Eligibility status per payment
    // ========================================================================

    /**
     * AC-3B-05, AC-3B-06, AC-3B-07: Review data includes eligibility info.
     */
    public function test_review_data_includes_eligibility_info(): void
    {
        $shift = $this->createClosedShift();
        ['biker' => $eligibleBiker] = $this->createEligibleBiker('Eligible');
        $this->assignBikerWithPayment($shift, $eligibleBiker);

        $noPixBiker = $this->createBikerWithoutPix('No PIX');
        $this->assignBikerWithPayment($shift, $noPixBiker);

        $service = app(\App\Services\PaymentReleaseService::class);
        $reviewData = $service->getPaymentReviewData($shift);

        $items = $reviewData['paymentItems'];

        // Find the eligible biker's item
        $eligibleItem = collect($items)->first(fn ($item) => $item['biker']->name === 'Eligible');
        $this->assertTrue($eligibleItem['hasUser'], 'AC-3B-07: Eligible biker should have user account');
        $this->assertTrue($eligibleItem['hasVerifiedPixKey'], 'AC-3B-06: Eligible biker should have verified PIX');
        $this->assertTrue($eligibleItem['isEligible']);
        $this->assertEmpty($eligibleItem['blockReasons']);

        // Find the ineligible biker's item
        $ineligibleItem = collect($items)->first(fn ($item) => $item['biker']->name === 'No PIX');
        $this->assertFalse($ineligibleItem['hasVerifiedPixKey'], 'AC-3B-06: Should show no verified PIX');
        $this->assertFalse($ineligibleItem['isEligible']);
        $this->assertNotEmpty($ineligibleItem['blockReasons']);
    }

    // ========================================================================
    // AC-3B-11: Review data shows total pending and processing amounts
    // ========================================================================

    /**
     * AC-3B-11: Review data calculates total pending and processing amounts correctly.
     */
    public function test_review_data_shows_total_amounts(): void
    {
        $shift = $this->createClosedShift();
        ['biker' => $biker1] = $this->createEligibleBiker('Biker 1');
        $this->assignBikerWithPayment($shift, $biker1, [], [
            'amount' => '75.00',
        ]);

        ['biker' => $biker2] = $this->createEligibleBiker('Biker 2');
        $this->assignBikerWithPayment($shift, $biker2, [], [
            'amount' => '50.00',
        ]);

        $service = app(\App\Services\PaymentReleaseService::class);
        $reviewData = $service->getPaymentReviewData($shift);

        // Both are pending
        $this->assertEquals('125.00', $reviewData['totalPending'],
            'AC-3B-11: Total pending must be 75.00 + 50.00 = 125.00');
        $this->assertEquals('0.00', $reviewData['totalProcessing']);
    }

    // ========================================================================
    // AC-3B-12: Review data handles empty state (no bikers/payments)
    // ========================================================================

    /**
     * AC-3B-12: Review data for shift with no bikers returns empty items.
     */
    public function test_review_data_empty_state(): void
    {
        $shift = $this->createClosedShift();

        $service = app(\App\Services\PaymentReleaseService::class);
        $reviewData = $service->getPaymentReviewData($shift);

        $this->assertCount(0, $reviewData['paymentItems'],
            'AC-3B-12: Empty payment items for shift with no bikers');
        $this->assertEquals('0.00', $reviewData['totalPending']);
        $this->assertEquals('0.00', $reviewData['totalProcessing']);
    }

    // ========================================================================
    // BR-04 Granular Failure: Releasing one payment doesn't affect another
    // ========================================================================

    /**
     * BR-04: Releasing Payment A does not affect Payment B.
     */
    public function test_releasing_one_payment_does_not_affect_another(): void
    {
        $shift = $this->createClosedShift();
        ['biker' => $biker1] = $this->createEligibleBiker('Biker 1');
        ['payment' => $payment1] = $this->assignBikerWithPayment($shift, $biker1);

        // Biker 2 is ineligible (no PIX)
        $noPixBiker = $this->createBikerWithoutPix('No PIX Biker');
        ['payment' => $payment2] = $this->assignBikerWithPayment($shift, $noPixBiker);

        $service = app(\App\Services\PaymentReleaseService::class);
        $service->releasePayment($payment1, $this->admin);

        $this->assertEquals(PaymentStatus::Processing, $payment1->fresh()->status,
            'BR-04: Payment 1 must be processing');
        $this->assertEquals(PaymentStatus::Pending, $payment2->fresh()->status,
            'BR-04: Payment 2 must remain pending (independent)');
        $this->assertNull($payment2->fresh()->released_by,
            'BR-04: Payment 2 must not have released_by set');
    }

    // ========================================================================
    // Edge Case 1: Payment already in processing status
    // ========================================================================

    /**
     * Edge Case 1: Releasing an already-processing payment throws exception.
     */
    public function test_double_release_throws_exception(): void
    {
        $shift = $this->createClosedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker);

        $service = app(\App\Services\PaymentReleaseService::class);

        // First release succeeds
        $service->releasePayment($payment, $this->admin);

        // Second release throws
        $this->expectException(\RuntimeException::class);
        $service->releasePayment($payment->fresh(), $this->admin);
    }

    // ========================================================================
    // Edge Case 10: Zero-amount payment can still be released
    // ========================================================================

    /**
     * Edge Case 10, AC-3B-40: Zero-amount payment can be released.
     */
    public function test_zero_amount_payment_can_be_released(): void
    {
        $shift = $this->createClosedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, [
            'trips_count' => 0,
        ], [
            'amount' => '0.00',
            'revenue' => '0.00',
        ]);

        $service = app(\App\Services\PaymentReleaseService::class);
        $result = $service->releasePayment($payment, $this->admin);

        $this->assertEquals(PaymentStatus::Processing, $result->status,
            'Edge Case 10: Zero-amount payment can be released');
        $this->assertEquals('0.00', $result->fresh()->amount,
            'Edge Case 10: Zero amount preserved');
    }

    // ========================================================================
    // Edge Case 6: PIX key revoked after close blocks release
    // ========================================================================

    /**
     * Edge Case 6: PIX verified at close but revoked before release → blocked.
     */
    public function test_revoked_pix_key_blocks_release(): void
    {
        $shift = $this->createClosedShift();
        $biker = Biker::factory()->create([
            'rate_per_trip' => '10.00',
            'base_fee' => '25.00',
        ]);

        // Create verified PIX
        $pixKey = PixKey::factory()->create([
            'biker_id' => $biker->id,
            'is_verified' => true,
            'verified_at' => now(),
        ]);

        User::factory()->create([
            'role' => UserRole::Biker,
            'biker_id' => $biker->id,
        ]);

        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker);

        // Revoke the PIX key after shift close
        $pixKey->update(['is_verified' => false, 'verified_at' => null]);

        $service = app(\App\Services\PaymentReleaseService::class);

        $this->expectException(\RuntimeException::class);
        $service->releasePayment($payment, $this->admin);
    }

    // ========================================================================
    // AC-3B-42: All monetary values remain 2 decimal places
    // ========================================================================

    /**
     * AC-3B-42: Payment amounts maintain 2 decimal precision through release.
     */
    public function test_monetary_values_maintain_precision(): void
    {
        $shift = $this->createClosedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, [], [
            'amount' => '112.50',
            'revenue' => '37.50',
        ]);

        $service = app(\App\Services\PaymentReleaseService::class);
        $service->releasePayment($payment, $this->admin);

        $fresh = $payment->fresh();
        $this->assertEquals('112.50', $fresh->amount,
            'AC-3B-42: Amount precision maintained');
        $this->assertEquals('37.50', $fresh->revenue,
            'AC-3B-42: Revenue precision maintained');
    }

    // ========================================================================
    // AC-3B-43: Each release creates exactly one audit log
    // ========================================================================

    /**
     * AC-3B-43: Each successful release creates exactly one audit log row.
     */
    public function test_each_release_creates_exactly_one_audit_log(): void
    {
        $shift = $this->createClosedShift();
        ['biker' => $biker1] = $this->createEligibleBiker('Biker 1');
        ['payment' => $payment1] = $this->assignBikerWithPayment($shift, $biker1);
        ['biker' => $biker2] = $this->createEligibleBiker('Biker 2');
        ['payment' => $payment2] = $this->assignBikerWithPayment($shift, $biker2);

        $service = app(\App\Services\PaymentReleaseService::class);
        $service->releasePayment($payment1, $this->admin);
        $service->releasePayment($payment2, $this->admin);

        $this->assertEquals(2, PaymentAuditLog::where('action', PaymentAuditAction::Release)->count(),
            'AC-3B-43: Exactly 2 release audit logs for 2 releases');
    }

    // ========================================================================
    // Batch release: Mixed eligible and ineligible
    // ========================================================================

    /**
     * BR-04, AC-3B-25: Batch release with mixed eligibility.
     * Biker A: eligible → released
     * Biker B: no PIX → blocked
     * Biker C: no user → blocked
     * Biker D: eligible → released
     */
    public function test_batch_release_mixed_eligibility(): void
    {
        $shift = $this->createClosedShift();

        ['biker' => $bikerA] = $this->createEligibleBiker('Biker A');
        $this->assignBikerWithPayment($shift, $bikerA);

        $bikerB = $this->createBikerWithoutPix('Biker B');
        $this->assignBikerWithPayment($shift, $bikerB);

        $bikerC = $this->createBikerWithoutUser('Biker C');
        $this->assignBikerWithPayment($shift, $bikerC);

        ['biker' => $bikerD] = $this->createEligibleBiker('Biker D');
        $this->assignBikerWithPayment($shift, $bikerD);

        $service = app(\App\Services\PaymentReleaseService::class);
        $results = $service->releaseAllEligiblePayments($shift, $this->admin);

        $this->assertCount(2, $results['released'],
            'BR-04: 2 eligible payments released');
        $this->assertCount(2, $results['blocked'],
            'BR-04: 2 ineligible payments blocked');
        $this->assertEquals(ShiftStatus::Closed, $shift->fresh()->status,
            'BR-04: Shift stays closed with blocked payments');
    }

    // ========================================================================
    // Batch release: None eligible
    // ========================================================================

    /**
     * Edge Case 12: Batch release with none eligible.
     */
    public function test_batch_release_none_eligible(): void
    {
        $shift = $this->createClosedShift();

        $biker1 = $this->createBikerWithoutPix('No PIX 1');
        $this->assignBikerWithPayment($shift, $biker1);

        $biker2 = $this->createBikerWithoutUser('No User 1');
        $this->assignBikerWithPayment($shift, $biker2);

        $service = app(\App\Services\PaymentReleaseService::class);
        $results = $service->releaseAllEligiblePayments($shift, $this->admin);

        $this->assertCount(0, $results['released'],
            'Edge Case 12: No payments released');
        $this->assertCount(2, $results['blocked'],
            'Edge Case 12: All payments blocked');
        $this->assertEquals(ShiftStatus::Closed, $shift->fresh()->status,
            'Edge Case 12: Shift stays closed');
    }

    // ========================================================================
    // Batch release: All eligible → shift auto-transitions
    // ========================================================================

    /**
     * Edge Case 11: Batch release all eligible → shift auto-transitions to approved.
     */
    public function test_batch_release_all_eligible_transitions_shift(): void
    {
        $shift = $this->createClosedShift();
        ['biker' => $biker1] = $this->createEligibleBiker('Biker 1');
        $this->assignBikerWithPayment($shift, $biker1);
        ['biker' => $biker2] = $this->createEligibleBiker('Biker 2');
        $this->assignBikerWithPayment($shift, $biker2);

        $service = app(\App\Services\PaymentReleaseService::class);
        $service->releaseAllEligiblePayments($shift, $this->admin);

        $this->assertEquals(ShiftStatus::Approved, $shift->fresh()->status,
            'Edge Case 11: Shift must transition to approved after batch release');
    }

    // ========================================================================
    // Review data: block reasons populated correctly
    // ========================================================================

    /**
     * AC-3B-09: Review data shows block reasons for ineligible payments.
     */
    public function test_review_data_shows_block_reasons(): void
    {
        $shift = $this->createClosedShift();
        $biker = Biker::factory()->create([
            'name' => 'Fully Blocked',
            'rate_per_trip' => '10.00',
            'base_fee' => '25.00',
        ]);
        // No user, no verified PIX
        $this->assignBikerWithPayment($shift, $biker);

        $service = app(\App\Services\PaymentReleaseService::class);
        $reviewData = $service->getPaymentReviewData($shift);

        $item = $reviewData['paymentItems'][0];
        $this->assertFalse($item['isEligible']);
        $this->assertCount(2, $item['blockReasons'],
            'AC-3B-09: Must have 2 block reasons (no user + no verified PIX)');
    }

    // ========================================================================
    // Review data: eligible count and ineligible count
    // ========================================================================

    /**
     * AC-3B-10: Review data includes eligible/ineligible counts.
     */
    public function test_review_data_includes_eligibility_counts(): void
    {
        $shift = $this->createClosedShift();
        ['biker' => $eligible] = $this->createEligibleBiker('Eligible');
        $this->assignBikerWithPayment($shift, $eligible);

        $noPix = $this->createBikerWithoutPix('No PIX');
        $this->assignBikerWithPayment($shift, $noPix);

        $service = app(\App\Services\PaymentReleaseService::class);
        $reviewData = $service->getPaymentReviewData($shift);

        $this->assertEquals(1, $reviewData['eligibleCount'],
            'AC-3B-10: 1 eligible payment');
        $this->assertEquals(1, $reviewData['ineligibleCount'],
            'AC-3B-10: 1 ineligible payment');
    }
}
