<?php

namespace Tests\Unit\Services;

use App\Contracts\PixGatewayInterface;
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
use App\Services\Gateway\MockPixGateway;
use App\Services\PaymentSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit Tests for PaymentSettlementService — Phase 3C
 *
 * Tests the core business logic for payment settlement:
 * - Mark Paid (processing → paid)
 * - Mark Failed (processing → failed, with reason)
 * - Retry (failed → processing, with retry_count cap)
 * - Shift Reconciliation (approved → paid when all payments paid)
 * - BR-04: Granular failure — one failed payment never regresses the shift
 * - BR-06: Every attempt logged with unique transaction_ref
 * - Financial integrity — amounts never modified
 *
 * Acceptance Criteria: AC-3C-01 through AC-3C-48
 * Business Rules: BR-02, BR-03, BR-04, BR-06
 *
 * @see docs/plans/phase-3c-payment-failure-and-retry.md
 */
class PaymentSettlementServiceTest extends TestCase
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

        // Phase 4B: Bind MockPixGateway so PaymentSettlementService can resolve it
        // Inject gateway directly into service via constructor (avoids polluting container)
        $this->app->instance(PaymentSettlementService::class, new PaymentSettlementService(
            new MockPixGateway()
        ));
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private function createApprovedShift(array $overrides = []): Shift
    {
        $shift = Shift::factory()->started()->create(array_merge([
            'restaurant_id' => $this->restaurant->id,
            'restaurant_rate' => '15.00',
        ], $overrides));

        $shift->status = ShiftStatus::Closed;
        $shift->closed_at = now();
        $shift->save();
        $shift->status = ShiftStatus::Approved;
        $shift->save();

        return $shift->fresh();
    }

    private function createPaidShift(array $overrides = []): Shift
    {
        $shift = $this->createApprovedShift($overrides);
        $shift->status = ShiftStatus::Paid;
        $shift->save();

        return $shift->fresh();
    }

    /**
     * Create a fully eligible biker (verified PIX + User account).
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
     * Create a biker without a verified PIX key.
     */
    private function createBikerWithoutPix(string $name = 'No PIX Biker'): Biker
    {
        $biker = Biker::factory()->create([
            'name' => $name,
            'rate_per_trip' => '10.00',
            'base_fee' => '25.00',
        ]);

        User::factory()->create([
            'role' => UserRole::Biker,
            'biker_id' => $biker->id,
        ]);

        return $biker;
    }

    /**
     * Create a biker without a User account.
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
     * Assign a biker to a shift with a payment in the given status.
     */
    private function assignBikerWithPayment(
        Shift $shift,
        Biker $biker,
        PaymentStatus $status = PaymentStatus::Processing,
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

        $paymentDefaults = [
            'shift_biker_id' => $shiftBiker->id,
            'amount' => '75.00',
            'revenue' => '0.00',
            'status' => $status,
        ];

        // Add status-appropriate defaults
        if ($status === PaymentStatus::Processing) {
            $paymentDefaults['released_by'] = $this->admin->id;
            $paymentDefaults['released_at'] = now();
        }
        if ($status === PaymentStatus::Paid) {
            $paymentDefaults['released_by'] = $this->admin->id;
            $paymentDefaults['released_at'] = now();
            $paymentDefaults['paid_at'] = now();
        }
        if ($status === PaymentStatus::Failed) {
            $paymentDefaults['released_by'] = $this->admin->id;
            $paymentDefaults['released_at'] = now();
            $paymentDefaults['failed_at'] = now();
            $paymentDefaults['failure_reason'] = 'Test failure reason';
        }

        $payment = Payment::factory()->create(array_merge(
            $paymentDefaults,
            $paymentOverrides,
            ['status' => $status] // ensure status is not overridden
        ));

        return ['shiftBiker' => $shiftBiker, 'payment' => $payment];
    }

    // ========================================================================
    // MARK PAID — AC-3C-11 through AC-3C-18
    // ========================================================================

    /**
     * AC-3C-11: Mark-paid transitions processing → paid.
     */
    public function test_mark_paid_transitions_processing_to_paid(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Processing);

        $service = app(PaymentSettlementService::class);
        $result = $service->markPaid($payment, $this->admin);

        $this->assertEquals(PaymentStatus::Paid, $result->status,
            'AC-3C-11: markPaid must transition payment to paid');
        $this->assertEquals(PaymentStatus::Paid, $payment->fresh()->status,
            'AC-3C-11: Payment in DB must be paid');
    }

    /**
     * AC-3C-12: Mark-paid sets paid_at to current timestamp.
     */
    public function test_mark_paid_sets_paid_at(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Processing);

        $before = now()->subSecond();
        $service = app(PaymentSettlementService::class);
        $service->markPaid($payment, $this->admin);
        $after = now()->addSecond();

        $fresh = $payment->fresh();
        $this->assertNotNull($fresh->paid_at, 'AC-3C-12: paid_at must be set');
        $this->assertTrue(
            $fresh->paid_at->greaterThanOrEqualTo($before)
            && $fresh->paid_at->lessThanOrEqualTo($after),
            'AC-3C-12: paid_at must be approximately current timestamp'
        );
    }

    /**
     * AC-3C-13: Mark-paid creates exactly one audit log with action=succeed.
     */
    public function test_mark_paid_creates_succeed_audit_log(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Processing);

        $service = app(PaymentSettlementService::class);
        $service->markPaid($payment, $this->admin);

        $logs = PaymentAuditLog::where('payment_id', $payment->id)->get();
        $this->assertCount(1, $logs, 'AC-3C-13: Exactly one audit log row');
        $this->assertEquals(PaymentAuditAction::Succeed, $logs->first()->action,
            'AC-3C-13: Audit action must be succeed');

        // Verify transaction_ref is unique and contains payment id
        $this->assertStringContainsString((string) $payment->id, $logs->first()->transaction_ref,
            'AC-3C-43: transaction_ref must contain payment id');
    }

    /**
     * AC-3C-13: Audit log payload contains required fields for mark-paid.
     */
    public function test_mark_paid_audit_log_payload(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Processing);

        $service = app(PaymentSettlementService::class);
        $service->markPaid($payment, $this->admin);

        $log = PaymentAuditLog::where('payment_id', $payment->id)->first();
        $payload = $log->payload;

        $this->assertEquals($this->admin->id, $payload['marked_by'],
            'AC-3C-13: Payload marked_by must be admin id');
        $this->assertEquals('75.00', $payload['amount'],
            'AC-3C-13: Payload amount must match payment amount');
        $this->assertArrayHasKey('paid_at', $payload,
            'AC-3C-13: Payload must contain paid_at');
        $this->assertArrayHasKey('retry_count', $payload,
            'AC-3C-13: Payload must contain retry_count');
    }

    /**
     * AC-3C-14: Mark-paid on a pending payment returns error, no audit log.
     */
    public function test_mark_paid_refuses_pending_payment(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Pending);

        $service = app(PaymentSettlementService::class);

        $this->expectException(\RuntimeException::class);
        try {
            $service->markPaid($payment, $this->admin);
        } catch (\RuntimeException $e) {
            $this->assertEquals(0, PaymentAuditLog::where('payment_id', $payment->id)->count(),
                'AC-3C-14: No audit log for refused mark-paid');
            $this->assertEquals(PaymentStatus::Pending, $payment->fresh()->status,
                'AC-3C-14: Payment must stay pending');
            throw $e;
        }
    }

    /**
     * AC-3C-15: Mark-paid on an already-paid payment returns error (idempotency).
     */
    public function test_mark_paid_refuses_already_paid_payment(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Paid);

        $auditCountBefore = PaymentAuditLog::where('payment_id', $payment->id)->count();

        $service = app(PaymentSettlementService::class);

        $this->expectException(\RuntimeException::class);
        try {
            $service->markPaid($payment, $this->admin);
        } catch (\RuntimeException $e) {
            $this->assertEquals($auditCountBefore, PaymentAuditLog::where('payment_id', $payment->id)->count(),
                'AC-3C-15: No duplicate audit log for idempotent refusal');
            throw $e;
        }
    }

    /**
     * AC-3C-16: Mark-paid on a failed payment returns error.
     */
    public function test_mark_paid_refuses_failed_payment(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Failed);

        $service = app(PaymentSettlementService::class);

        $this->expectException(\RuntimeException::class);
        $service->markPaid($payment, $this->admin);
    }

    // ========================================================================
    // MARK FAILED — AC-3C-19 through AC-3C-27
    // ========================================================================

    /**
     * AC-3C-19: Mark-failed transitions processing → failed.
     */
    public function test_mark_failed_transitions_processing_to_failed(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Processing);

        $service = app(PaymentSettlementService::class);
        $result = $service->markFailed($payment, $this->admin, 'Chave PIX inválida');

        $this->assertEquals(PaymentStatus::Failed, $result->status,
            'AC-3C-19: markFailed must transition payment to failed');
        $this->assertEquals(PaymentStatus::Failed, $payment->fresh()->status,
            'AC-3C-19: Payment in DB must be failed');
    }

    /**
     * AC-3C-20: Mark-failed sets failed_at and failure_reason.
     */
    public function test_mark_failed_sets_failed_at_and_reason(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Processing);

        $before = now()->subSecond();
        $service = app(PaymentSettlementService::class);
        $service->markFailed($payment, $this->admin, 'Chave PIX inválida');
        $after = now()->addSecond();

        $fresh = $payment->fresh();
        $this->assertNotNull($fresh->failed_at, 'AC-3C-20: failed_at must be set');
        $this->assertTrue(
            $fresh->failed_at->greaterThanOrEqualTo($before)
            && $fresh->failed_at->lessThanOrEqualTo($after),
            'AC-3C-20: failed_at must be approximately current timestamp'
        );
        $this->assertEquals('Chave PIX inválida', $fresh->failure_reason,
            'AC-3C-20: failure_reason must match input');
    }

    /**
     * AC-3C-21: Mark-failed creates audit log with action=fail and error_message.
     */
    public function test_mark_failed_creates_fail_audit_log(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Processing);

        $service = app(PaymentSettlementService::class);
        $service->markFailed($payment, $this->admin, 'Chave PIX expirada');

        $logs = PaymentAuditLog::where('payment_id', $payment->id)->get();
        $this->assertCount(1, $logs, 'AC-3C-21: Exactly one audit log row');
        $this->assertEquals(PaymentAuditAction::Fail, $logs->first()->action,
            'AC-3C-21: Audit action must be fail');
        $this->assertEquals('Chave PIX expirada', $logs->first()->error_message,
            'AC-3C-21: error_message must match failure reason');
        $this->assertNotEmpty($logs->first()->transaction_ref,
            'AC-3C-21: transaction_ref must be set');
    }

    /**
     * AC-3C-25: Mark-failed on a non-processing payment returns error, no audit.
     */
    public function test_mark_failed_refuses_non_processing_payment(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();

        // Test with Paid payment
        ['payment' => $paidPayment] = $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Paid);

        $service = app(PaymentSettlementService::class);

        $this->expectException(\RuntimeException::class);
        try {
            $service->markFailed($paidPayment, $this->admin, 'Some reason');
        } catch (\RuntimeException $e) {
            $this->assertEquals(0, PaymentAuditLog::where('payment_id', $paidPayment->id)->count(),
                'AC-3C-25: No audit log for refused mark-failed');
            $this->assertEquals(PaymentStatus::Paid, $paidPayment->fresh()->status,
                'AC-3C-25: Payment must stay paid');
            throw $e;
        }
    }

    /**
     * AC-3C-26, BR-04: Marking a payment as failed does NOT change shift status.
     */
    public function test_mark_failed_does_not_change_shift_status(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Processing);

        $service = app(PaymentSettlementService::class);
        $service->markFailed($payment, $this->admin, 'Falha de conexão');

        $this->assertEquals(ShiftStatus::Approved, $shift->fresh()->status,
            'AC-3C-26, BR-04: Shift must stay approved when payment fails');
    }

    /**
     * AC-3C-25: Mark-failed on pending payment is refused.
     */
    public function test_mark_failed_refuses_pending_payment(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Pending);

        $service = app(PaymentSettlementService::class);

        $this->expectException(\RuntimeException::class);
        $service->markFailed($payment, $this->admin, 'Some reason');
    }

    /**
     * AC-3C-25: Mark-failed on already-failed payment is refused.
     */
    public function test_mark_failed_refuses_already_failed_payment(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Failed);

        $service = app(PaymentSettlementService::class);

        $this->expectException(\RuntimeException::class);
        try {
            $service->markFailed($payment, $this->admin, 'Another reason');
        } catch (\RuntimeException $e) {
            $this->assertEquals(PaymentStatus::Failed, $payment->fresh()->status,
                'AC-3C-25: Payment must stay failed');
            throw $e;
        }
    }

    // ========================================================================
    // RETRY — AC-3C-28 through AC-3C-35
    // ========================================================================

    /**
     * AC-3C-28: Retry transitions failed → processing.
     */
    public function test_retry_transitions_failed_to_processing(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Failed);

        $service = app(PaymentSettlementService::class);
        $result = $service->retry($payment, $this->admin);

        $this->assertEquals(PaymentStatus::Processing, $result->status,
            'AC-3C-28: Retry must transition payment to processing');
        $this->assertEquals(PaymentStatus::Processing, $payment->fresh()->status,
            'AC-3C-28: Payment in DB must be processing');
    }

    /**
     * AC-3C-29: Retry increments retry_count by exactly 1.
     */
    public function test_retry_increments_retry_count(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Failed, [], [
            'retry_count' => 0,
        ]);

        $service = app(PaymentSettlementService::class);
        $service->retry($payment, $this->admin);

        $this->assertEquals(1, $payment->fresh()->retry_count,
            'AC-3C-29: retry_count must be incremented by 1');
    }

    /**
     * AC-3C-29: Multiple retry cycles increment retry_count monotonically.
     */
    public function test_retry_increments_retry_count_monotonically(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Failed, [], [
            'retry_count' => 1,
        ]);

        $service = app(PaymentSettlementService::class);
        $service->retry($payment, $this->admin);

        // After retry (retry_count becomes 2, which is < 3, so it stays processing)
        $this->assertEquals(2, $payment->fresh()->retry_count,
            'AC-3C-29: retry_count must increment to 2');
        $this->assertEquals(PaymentStatus::Processing, $payment->fresh()->status);
    }

    /**
     * AC-3C-30: Retry clears failed_at and failure_reason.
     */
    public function test_retry_clears_failed_at_and_failure_reason(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Failed, [], [
            'retry_count' => 0,
        ]);

        $service = app(PaymentSettlementService::class);
        $service->retry($payment, $this->admin);

        $fresh = $payment->fresh();
        $this->assertNull($fresh->failed_at,
            'AC-3C-30: failed_at must be cleared on retry');
        $this->assertNull($fresh->failure_reason,
            'AC-3C-30: failure_reason must be cleared on retry');
    }

    /**
     * AC-3C-31: Retry creates audit log with action=retry.
     */
    public function test_retry_creates_retry_audit_log(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Failed, [], [
            'retry_count' => 0,
        ]);

        $service = app(PaymentSettlementService::class);
        $service->retry($payment, $this->admin);

        $retryLogs = PaymentAuditLog::where('payment_id', $payment->id)
            ->where('action', PaymentAuditAction::Retry)
            ->get();

        $this->assertCount(1, $retryLogs, 'AC-3C-31: Exactly one retry audit log');
        $this->assertNotEmpty($retryLogs->first()->transaction_ref,
            'AC-3C-31: transaction_ref must be set');

        $payload = $retryLogs->first()->payload;
        $this->assertEquals(1, $payload['new_retry_count'],
            'AC-3C-31: payload.new_retry_count must match new value');
        $this->assertEquals($this->admin->id, $payload['retried_by'],
            'AC-3C-31: payload.retried_by must be admin id');
    }

    /**
     * AC-3C-32: Retry on a non-failed payment returns error, no audit log.
     */
    public function test_retry_refuses_non_failed_payment(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();

        // Test processing payment
        ['payment' => $processingPayment] = $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Processing);

        $service = app(PaymentSettlementService::class);

        $this->expectException(\RuntimeException::class);
        try {
            $service->retry($processingPayment, $this->admin);
        } catch (\RuntimeException $e) {
            $this->assertEquals(0, PaymentAuditLog::where('payment_id', $processingPayment->id)->count(),
                'AC-3C-32: No audit log for refused retry');
            $this->assertEquals(PaymentStatus::Processing, $processingPayment->fresh()->status);
            throw $e;
        }
    }

    /**
     * AC-3C-32: Retry on a paid payment returns error.
     */
    public function test_retry_refuses_paid_payment(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Paid);

        $service = app(PaymentSettlementService::class);

        $this->expectException(\RuntimeException::class);
        $service->retry($payment, $this->admin);
    }

    /**
     * AC-3C-33, BR-02: Retry refuses when PIX key is no longer verified.
     */
    public function test_retry_refuses_when_pix_no_longer_verified(): void
    {
        $shift = $this->createApprovedShift();
        $biker = $this->createBikerWithoutPix('No PIX');
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Failed);

        $service = app(PaymentSettlementService::class);

        $this->expectException(\RuntimeException::class);
        try {
            $service->retry($payment, $this->admin);
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('PIX', $e->getMessage(),
                'AC-3C-33: Error must mention PIX');
            $this->assertEquals(PaymentStatus::Failed, $payment->fresh()->status,
                'AC-3C-33: Payment must stay failed');
            throw $e;
        }
    }

    /**
     * AC-3C-34, ADR-005 D4: Retry refuses when User account is missing.
     */
    public function test_retry_refuses_when_user_account_removed(): void
    {
        $shift = $this->createApprovedShift();
        $biker = $this->createBikerWithoutUser('No User');
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Failed);

        $service = app(PaymentSettlementService::class);

        $this->expectException(\RuntimeException::class);
        try {
            $service->retry($payment, $this->admin);
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('usuário', $e->getMessage(),
                'AC-3C-34: Error must mention user account');
            $this->assertEquals(PaymentStatus::Failed, $payment->fresh()->status,
                'AC-3C-34: Payment must stay failed');
            throw $e;
        }
    }

    /**
     * AC-3C-45: Retry refuses when retry_count >= 3 (hard cap).
     */
    public function test_retry_refuses_when_retry_count_at_cap(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Failed, [], [
            'retry_count' => 3,
        ]);

        $service = app(PaymentSettlementService::class);

        $this->expectException(\RuntimeException::class);
        try {
            $service->retry($payment, $this->admin);
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('maximum retry count', $e->getMessage(),
                'AC-3C-45: Error must mention maximum retry count');
            $this->assertEquals(PaymentStatus::Failed, $payment->fresh()->status,
                'AC-3C-45: Payment must stay failed');
            // No retry audit log should be created
            $retryLogs = PaymentAuditLog::where('payment_id', $payment->id)
                ->where('action', PaymentAuditAction::Retry)
                ->count();
            $this->assertEquals(0, $retryLogs,
                'AC-3C-45: No retry audit log for cap refusal');
            throw $e;
        }
    }

    /**
     * AC-3C-46: 3rd successful retry auto-fails payment with cap reason.
     */
    public function test_retry_auto_fails_on_third_successful_retry(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Failed, [], [
            'retry_count' => 2,
        ]);

        $service = app(PaymentSettlementService::class);
        $result = $service->retry($payment, $this->admin);

        $fresh = $payment->fresh();
        // Payment should be auto-failed
        $this->assertEquals(PaymentStatus::Failed, $fresh->status,
            'AC-3C-46: Payment must be auto-failed after 3rd retry');
        $this->assertEquals(3, $fresh->retry_count,
            'AC-3C-46: retry_count must be 3');
        $this->assertNotNull($fresh->failed_at,
            'AC-3C-46: failed_at must be set');
        $this->assertStringContainsString('Limite de retentativas', $fresh->failure_reason,
            'AC-3C-46: failure_reason must contain cap message');

        // Verify audit logs: one retry + one auto-fail
        $retryLogs = PaymentAuditLog::where('payment_id', $payment->id)
            ->where('action', PaymentAuditAction::Retry)
            ->count();
        $failLogs = PaymentAuditLog::where('payment_id', $payment->id)
            ->where('action', PaymentAuditAction::Fail)
            ->count();

        $this->assertEquals(1, $retryLogs,
            'AC-3C-46: One retry audit log created');
        $this->assertEquals(1, $failLogs,
            'AC-3C-46: One auto-fail audit log created');

        // Verify the fail audit log has retry_cap_exceeded reason
        $failLog = PaymentAuditLog::where('payment_id', $payment->id)
            ->where('action', PaymentAuditAction::Fail)
            ->first();
        $this->assertEquals('retry_cap_exceeded', $failLog->payload['reason'],
            'AC-3C-46: Fail log payload reason must be retry_cap_exceeded');
    }

    /**
     * AC-3C-46: Retry log payload shows retry_cap_reached flag on 3rd retry.
     */
    public function test_retry_log_payload_shows_cap_reached_on_third_retry(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Failed, [], [
            'retry_count' => 2,
        ]);

        $service = app(PaymentSettlementService::class);
        $service->retry($payment, $this->admin);

        $retryLog = PaymentAuditLog::where('payment_id', $payment->id)
            ->where('action', PaymentAuditAction::Retry)
            ->first();

        $this->assertTrue($retryLog->payload['retry_cap_reached'],
            'AC-3C-46: retry_cap_reached must be true on 3rd retry');
        $this->assertEquals(3, $retryLog->payload['new_retry_count'],
            'AC-3C-46: new_retry_count must be 3');
    }

    // ========================================================================
    // SHIFT RECONCILIATION — AC-3C-36 through AC-3C-39
    // ========================================================================

    /**
     * AC-3C-36: Last payment marked paid → shift transitions approved → paid.
     */
    public function test_reconcile_promotes_shift_to_paid_when_all_payments_paid(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Processing);

        $service = app(PaymentSettlementService::class);
        $service->markPaid($payment, $this->admin);

        $this->assertEquals(ShiftStatus::Paid, $shift->fresh()->status,
            'AC-3C-36: Shift must transition to paid when all payments are paid');
    }

    /**
     * AC-3C-37: Marking one payment paid while sibling still processing → shift stays approved.
     */
    public function test_reconcile_keeps_shift_approved_when_any_payment_processing(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker1] = $this->createEligibleBiker('Biker 1');
        ['payment' => $payment1] = $this->assignBikerWithPayment($shift, $biker1, PaymentStatus::Processing);

        ['biker' => $biker2] = $this->createEligibleBiker('Biker 2');
        ['payment' => $payment2] = $this->assignBikerWithPayment($shift, $biker2, PaymentStatus::Processing);

        $service = app(PaymentSettlementService::class);

        // Mark only payment1 as paid
        $service->markPaid($payment1, $this->admin);

        $this->assertEquals(ShiftStatus::Approved, $shift->fresh()->status,
            'AC-3C-37: Shift must stay approved with processing sibling');
    }

    /**
     * AC-3C-38, BR-04: Marking one payment paid while sibling failed → shift stays approved.
     */
    public function test_reconcile_keeps_shift_approved_when_any_payment_failed(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker1] = $this->createEligibleBiker('Biker 1');
        ['payment' => $payment1] = $this->assignBikerWithPayment($shift, $biker1, PaymentStatus::Processing);

        ['biker' => $biker2] = $this->createEligibleBiker('Biker 2');
        $this->assignBikerWithPayment($shift, $biker2, PaymentStatus::Failed);

        $service = app(PaymentSettlementService::class);

        // Mark payment1 as paid, but payment2 is failed
        $service->markPaid($payment1, $this->admin);

        $this->assertEquals(ShiftStatus::Approved, $shift->fresh()->status,
            'AC-3C-38, BR-04: Shift must stay approved with failed sibling');
    }

    /**
     * AC-3C-39: reconcileShiftStatus never moves a paid shift backward.
     */
    public function test_reconcile_does_not_regress_terminal_paid_shift(): void
    {
        $shift = $this->createPaidShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Paid);

        $service = app(PaymentSettlementService::class);

        // Call reconcile directly — should be a no-op
        $service->reconcileShiftStatus($shift);

        $this->assertEquals(ShiftStatus::Paid, $shift->fresh()->status,
            'AC-3C-39: Paid shift must stay paid');
    }

    /**
     * AC-3C-36: Reconciliation works with multiple payments — all must be paid.
     */
    public function test_reconcile_promotes_shift_when_all_multiple_payments_paid(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker1] = $this->createEligibleBiker('Biker 1');
        ['payment' => $payment1] = $this->assignBikerWithPayment($shift, $biker1, PaymentStatus::Processing);

        ['biker' => $biker2] = $this->createEligibleBiker('Biker 2');
        ['payment' => $payment2] = $this->assignBikerWithPayment($shift, $biker2, PaymentStatus::Processing);

        ['biker' => $biker3] = $this->createEligibleBiker('Biker 3');
        ['payment' => $payment3] = $this->assignBikerWithPayment($shift, $biker3, PaymentStatus::Processing);

        $service = app(PaymentSettlementService::class);

        // Mark first two as paid — shift stays approved
        $service->markPaid($payment1, $this->admin);
        $this->assertEquals(ShiftStatus::Approved, $shift->fresh()->status);

        $service->markPaid($payment2, $this->admin);
        $this->assertEquals(ShiftStatus::Approved, $shift->fresh()->status);

        // Mark last as paid — shift transitions to paid
        $service->markPaid($payment3, $this->admin);
        $this->assertEquals(ShiftStatus::Paid, $shift->fresh()->status,
            'AC-3C-36: Shift must transition to paid when last payment is paid');
    }

    // ========================================================================
    // FINANCIAL INTEGRITY — AC-3C-40, AC-3C-41
    // ========================================================================

    /**
     * AC-3C-40: Payment amount and revenue never modified during settlement.
     */
    public function test_settlement_does_not_modify_amount_or_revenue(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Processing, [], [
            'amount' => '75.00',
            'revenue' => '25.00',
        ]);

        $service = app(PaymentSettlementService::class);
        $service->markPaid($payment, $this->admin);

        $fresh = $payment->fresh();
        $this->assertEquals('75.00', $fresh->amount,
            'AC-3C-40: Amount must not change during markPaid');
        $this->assertEquals('25.00', $fresh->revenue,
            'AC-3C-40: Revenue must not change during markPaid');
    }

    /**
     * AC-3C-40: Mark-failed does not modify amount or revenue.
     */
    public function test_mark_failed_does_not_modify_amount_or_revenue(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Processing, [], [
            'amount' => '75.00',
            'revenue' => '25.00',
        ]);

        $service = app(PaymentSettlementService::class);
        $service->markFailed($payment, $this->admin, 'Test failure');

        $fresh = $payment->fresh();
        $this->assertEquals('75.00', $fresh->amount,
            'AC-3C-40: Amount must not change during markFailed');
        $this->assertEquals('25.00', $fresh->revenue,
            'AC-3C-40: Revenue must not change during markFailed');
    }

    /**
     * AC-3C-41: Monetary values remain 2 decimal places.
     */
    public function test_monetary_values_maintain_precision(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Processing, [], [
            'amount' => '112.50',
            'revenue' => '37.50',
        ]);

        $service = app(PaymentSettlementService::class);
        $service->markPaid($payment, $this->admin);

        $fresh = $payment->fresh();
        $this->assertEquals('112.50', $fresh->amount,
            'AC-3C-41: Amount precision maintained');
        $this->assertEquals('37.50', $fresh->revenue,
            'AC-3C-41: Revenue precision maintained');
    }

    // ========================================================================
    // AUDIT TRAIL — AC-3C-42 through AC-3C-44
    // ========================================================================

    /**
     * AC-3C-42: Every successful settlement transition writes exactly one audit log.
     * 
     * NOTE: Phase 4B gateway integration creates a gateway_attempt log alongside retry,
     * so retry() produces 2 logs (retry + gateway_attempt) instead of 1.
     */
    public function test_every_successful_transition_creates_audit_log(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Processing);

        $service = app(PaymentSettlementService::class);

        // Mark as failed
        $service->markFailed($payment, $this->admin, 'First failure');
        $this->assertEquals(1, PaymentAuditLog::where('payment_id', $payment->id)->count(),
            'AC-3C-42: 1 audit log after mark-failed');

        // Retry — Phase 4B: retry() creates retry + gateway_attempt = 2 logs
        // Plus the existing fail log = 3 total
        $fresh = $payment->fresh();
        $service->retry($fresh, $this->admin);
        $this->assertEquals(3, PaymentAuditLog::where('payment_id', $payment->id)->count(),
            'AC-3C-42: 3 audit logs after retry (fail + retry + gateway_attempt)');

        // Mark as paid (payment is now processing after retry)
        $fresh = $payment->fresh();
        $service->markPaid($fresh, $this->admin);
        // 4 logs now: fail + retry + gateway_attempt + succeed
        $this->assertEquals(4, PaymentAuditLog::where('payment_id', $payment->id)->count(),
            'AC-3C-42: 4 audit logs after mark-paid');
    }

    /**
     * AC-3C-43: transaction_ref is unique across all audit log rows.
     * 
     * NOTE: Phase 4B gateway integration creates gateway_attempt logs,
     * so markFailed + retry creates 3 logs (fail + retry + gateway_attempt).
     */
    public function test_transaction_ref_unique_across_all_audit_logs(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Processing);

        $service = app(PaymentSettlementService::class);

        $service->markFailed($payment, $this->admin, 'Failure');
        $fresh = $payment->fresh();
        $service->retry($fresh, $this->admin);

        // Phase 4B: markFailed creates 1 log, retry() creates 2 logs (retry + gateway_attempt)
        // Total = 3 logs, all must have unique transaction_refs
        $logs = PaymentAuditLog::where('payment_id', $payment->id)->get();
        $this->assertCount(3, $logs,
            'AC-3C-43: 3 audit logs (fail + retry + gateway_attempt)');
        
        $refs = $logs->pluck('transaction_ref')->toArray();
        $this->assertEquals(count($refs), count(array_unique($refs)),
            'AC-3C-43: All transaction_refs must be unique');
    }

    /**
     * AC-3C-44: Refused transitions write NO audit log rows.
     */
    public function test_refused_transitions_write_no_audit_logs(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Paid);

        $service = app(PaymentSettlementService::class);

        // Try to mark an already-paid payment as paid — should refuse
        try {
            $service->markPaid($payment, $this->admin);
        } catch (\RuntimeException) {
            // Expected
        }

        // Try to mark paid as failed — should refuse
        try {
            $service->markFailed($payment, $this->admin, 'Some reason');
        } catch (\RuntimeException) {
            // Expected
        }

        // Try to retry a paid payment — should refuse
        try {
            $service->retry($payment, $this->admin);
        } catch (\RuntimeException) {
            // Expected
        }

        $this->assertEquals(0, PaymentAuditLog::where('payment_id', $payment->id)->count(),
            'AC-3C-44: No audit logs for any refused transition');
    }

    // ========================================================================
    // EDGE CASES
    // ========================================================================

    /**
     * Edge case: Zero-amount payment can be marked paid.
     */
    public function test_zero_amount_payment_can_be_marked_paid(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment(
            $shift, $biker, PaymentStatus::Processing,
            ['trips_count' => 0],
            ['amount' => '0.00', 'revenue' => '0.00']
        );

        $service = app(PaymentSettlementService::class);
        $result = $service->markPaid($payment, $this->admin);

        $this->assertEquals(PaymentStatus::Paid, $result->status,
            'Edge case: Zero-amount payment can be marked paid');
        $this->assertEquals('0.00', $result->fresh()->amount,
            'Edge case: Zero amount preserved');
    }

    /**
     * BR-04: Failing one payment does not affect sibling payment.
     */
    public function test_failing_one_payment_does_not_affect_sibling(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker1] = $this->createEligibleBiker('Biker 1');
        ['payment' => $payment1] = $this->assignBikerWithPayment($shift, $biker1, PaymentStatus::Processing);

        ['biker' => $biker2] = $this->createEligibleBiker('Biker 2');
        ['payment' => $payment2] = $this->assignBikerWithPayment($shift, $biker2, PaymentStatus::Processing);

        $service = app(PaymentSettlementService::class);

        // Fail payment1
        $service->markFailed($payment1, $this->admin, 'Network error');

        // payment2 should be unaffected
        $this->assertEquals(PaymentStatus::Failed, $payment1->fresh()->status,
            'BR-04: Payment1 must be failed');
        $this->assertEquals(PaymentStatus::Processing, $payment2->fresh()->status,
            'BR-04: Payment2 must stay processing (independent)');
    }

    /**
     * BR-04: Paying one payment does not affect sibling.
     */
    public function test_paying_one_payment_does_not_affect_sibling(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker1] = $this->createEligibleBiker('Biker 1');
        ['payment' => $payment1] = $this->assignBikerWithPayment($shift, $biker1, PaymentStatus::Processing);

        ['biker' => $biker2] = $this->createEligibleBiker('Biker 2');
        ['payment' => $payment2] = $this->assignBikerWithPayment($shift, $biker2, PaymentStatus::Processing);

        $service = app(PaymentSettlementService::class);

        // Pay payment1
        $service->markPaid($payment1, $this->admin);

        $this->assertEquals(PaymentStatus::Paid, $payment1->fresh()->status,
            'BR-04: Payment1 must be paid');
        $this->assertEquals(PaymentStatus::Processing, $payment2->fresh()->status,
            'BR-04: Payment2 must stay processing (independent)');
    }

    // ========================================================================
    // getSettlementData — Settlement Dashboard Data
    // ========================================================================

    /**
     * AC-3C-05: getSettlementData groups payments by status.
     */
    public function test_get_settlement_data_groups_payments_by_status(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker1] = $this->createEligibleBiker('Biker Processing');
        $this->assignBikerWithPayment($shift, $biker1, PaymentStatus::Processing, [], ['amount' => '75.00']);

        ['biker' => $biker2] = $this->createEligibleBiker('Biker Failed');
        $this->assignBikerWithPayment($shift, $biker2, PaymentStatus::Failed, [], ['amount' => '50.00']);

        ['biker' => $biker3] = $this->createEligibleBiker('Biker Paid');
        $this->assignBikerWithPayment($shift, $biker3, PaymentStatus::Paid, [], ['amount' => '30.00']);

        $service = app(PaymentSettlementService::class);
        $data = $service->getSettlementData($shift);

        $this->assertArrayHasKey('groups', $data, 'Must have groups key');
        $this->assertArrayHasKey('processing', $data['groups'], 'Must have processing group');
        $this->assertArrayHasKey('failed', $data['groups'], 'Must have failed group');
        $this->assertArrayHasKey('paid', $data['groups'], 'Must have paid group');

        $this->assertCount(1, $data['groups']['processing'], '1 processing payment');
        $this->assertCount(1, $data['groups']['failed'], '1 failed payment');
        $this->assertCount(1, $data['groups']['paid'], '1 paid payment');
    }

    /**
     * AC-3C-09: getSettlementData returns BCMath totals per group.
     */
    public function test_get_settlement_data_returns_totals_per_group(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker1] = $this->createEligibleBiker('Biker 1');
        $this->assignBikerWithPayment($shift, $biker1, PaymentStatus::Processing, [], ['amount' => '75.00']);

        ['biker' => $biker2] = $this->createEligibleBiker('Biker 2');
        $this->assignBikerWithPayment($shift, $biker2, PaymentStatus::Processing, [], ['amount' => '50.00']);

        ['biker' => $biker3] = $this->createEligibleBiker('Biker 3');
        $this->assignBikerWithPayment($shift, $biker3, PaymentStatus::Failed, [], ['amount' => '30.00']);

        ['biker' => $biker4] = $this->createEligibleBiker('Biker 4');
        $this->assignBikerWithPayment($shift, $biker4, PaymentStatus::Paid, [], ['amount' => '100.00']);

        $service = app(PaymentSettlementService::class);
        $data = $service->getSettlementData($shift);

        $this->assertArrayHasKey('totals', $data, 'Must have totals key');
        $this->assertEquals('125.00', $data['totals']['processing'],
            'AC-3C-09: Processing total = 75.00 + 50.00 = 125.00');
        $this->assertEquals('30.00', $data['totals']['failed'],
            'AC-3C-09: Failed total = 30.00');
        $this->assertEquals('100.00', $data['totals']['paid'],
            'AC-3C-09: Paid total = 100.00');
    }

    /**
     * AC-3C-09: getSettlementData with all payments paid.
     */
    public function test_get_settlement_data_all_paid(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Paid, [], ['amount' => '75.00']);

        $service = app(PaymentSettlementService::class);
        $data = $service->getSettlementData($shift);

        $this->assertTrue($data['allPaid'], 'allPaid must be true when all payments are paid');
    }

    // ========================================================================
    // Shift::allPaymentsPaid() — Model helper
    // ========================================================================

    /**
     * AC-3C-36: allPaymentsPaid returns true when all payments are paid.
     */
    public function test_all_payments_paid_returns_true_when_all_paid(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Paid);

        $this->assertTrue($shift->allPaymentsPaid(),
            'allPaymentsPaid must be true when all payments are paid');
    }

    /**
     * AC-3C-37: allPaymentsPaid returns false when some payments are processing.
     */
    public function test_all_payments_paid_returns_false_with_processing(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Processing);

        $this->assertFalse($shift->allPaymentsPaid(),
            'allPaymentsPaid must be false with processing payments');
    }

    /**
     * AC-3C-38: allPaymentsPaid returns false when some payments are failed.
     */
    public function test_all_payments_paid_returns_false_with_failed(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Failed);

        $this->assertFalse($shift->allPaymentsPaid(),
            'allPaymentsPaid must be false with failed payments');
    }

    /**
     * Edge case 15: allPaymentsPaid with zero bikers returns true (vacuous truth).
     */
    public function test_all_payments_paid_returns_true_with_zero_bikers(): void
    {
        $shift = $this->createApprovedShift();
        // No bikers assigned

        $this->assertTrue($shift->allPaymentsPaid(),
            'Edge case 15: allPaymentsPaid must return true for empty shift (vacuous truth)');
    }

    // ========================================================================
    // Payment::isEligibleForRetry() — Model helper
    // ========================================================================

    /**
     * AC-3C-33: isEligibleForRetry returns false without verified PIX.
     */
    public function test_is_eligible_for_retry_returns_false_without_pix(): void
    {
        $shift = $this->createApprovedShift();
        $biker = $this->createBikerWithoutPix();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Failed);

        $this->assertFalse($payment->isEligibleForRetry(),
            'AC-3C-33: Payment not eligible for retry without verified PIX');
    }

    /**
     * AC-3C-34: isEligibleForRetry returns false without User account.
     */
    public function test_is_eligible_for_retry_returns_false_without_user(): void
    {
        $shift = $this->createApprovedShift();
        $biker = $this->createBikerWithoutUser();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Failed);

        $this->assertFalse($payment->isEligibleForRetry(),
            'AC-3C-34: Payment not eligible for retry without User account');
    }

    /**
     * isEligibleForRetry returns true for eligible failed payment.
     */
    public function test_is_eligible_for_retry_returns_true_for_eligible(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Failed);

        $this->assertTrue($payment->isEligibleForRetry(),
            'Eligible failed payment must be retryable');
    }

    /**
     * isEligibleForRetry returns false for non-failed payments.
     */
    public function test_is_eligible_for_retry_returns_false_for_processing(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        ['payment' => $payment] = $this->assignBikerWithPayment($shift, $biker, PaymentStatus::Processing);

        $this->assertFalse($payment->isEligibleForRetry(),
            'Processing payment is not eligible for retry');
    }
}
