<?php

namespace Tests\Feature\Controllers;

use App\Enums\PaymentAuditAction;
use App\Enums\PaymentStatus;
use App\Enums\ShiftStatus;
use App\Enums\UserRole;
use App\Enums\WorkflowType;
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
 * Feature Tests: Payment Retry with Gateway Integration
 *
 * Tests that PaymentSettlementService calls PixPaymentService for gateway integration
 * when retrying a failed payment.
 *
 * @see docs/plans/phase-4b-pix-payment-execution.md
 * @see docs/plans/phase-3c-payment-failure-and-retry.md
 */
class PaymentRetryWithGatewayTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $this->restaurant = Restaurant::factory()->create([
            'rate_per_trip' => '15.00',
        ]);
    }

    private function createApprovedShiftWithFailedPayment(
        string $amount = '75.00',
        string $bikerRate = '10.00',
        string $baseFee = '25.00',
        string $pixKey = '11999999999',
        int $retryCount = 0,
    ): array {
        $biker = Biker::factory()->create([
            'name' => 'João da Silva',
            'rate_per_trip' => $bikerRate,
            'base_fee' => $baseFee,
        ]);

        PixKey::factory()->create([
            'biker_id' => $biker->id,
            'key_type' => 'cpf',
            'key_value' => $pixKey,
            'is_verified' => true,
            'verified_at' => now(),
        ]);

        User::factory()->create([
            'role' => UserRole::Biker,
            'biker_id' => $biker->id,
        ]);

        $shift = Shift::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'status' => ShiftStatus::Approved,
            'workflow_type' => WorkflowType::LiveTick,
            'restaurant_rate' => '15.00',
        ]);

        $shiftBiker = ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 5,
            'biker_rate' => $bikerRate,
            'base_fee' => $baseFee,
        ]);

        $payment = Payment::factory()->create([
            'shift_biker_id' => $shiftBiker->id,
            'amount' => $amount,
            'status' => PaymentStatus::Failed,
            'retry_count' => $retryCount,
            'failed_at' => now(),
            'failure_reason' => 'Initial failure for testing',
        ]);

        return [
            'shift' => $shift,
            'biker' => $biker,
            'shiftBiker' => $shiftBiker,
            'payment' => $payment,
        ];
    }

    // ========================================================================
    // Retry Gateway Integration Tests
    // ========================================================================

    public function test_retry_payment_calls_gateway_and_transitions_to_paid(): void
    {
        // Amount ending with .01 triggers "processed" status in MockPixGateway
        $data = $this->createApprovedShiftWithFailedPayment(amount: '100.01');

        $this->actingAs($this->admin)->postJson(
            route('shifts.payments.retry', [$data['shift'], $data['payment']])
        );

        $data['payment']->refresh();
        $this->assertEquals(PaymentStatus::Paid, $data['payment']->status,
            'Payment should auto-transition to Paid on gateway processed');
        $this->assertNotNull($data['payment']->paid_at,
            'paid_at should be set');
        $this->assertNotNull($data['payment']->gateway_transaction_id,
            'gateway_transaction_id should be set');
        $this->assertEquals('processed', $data['payment']->gateway_status,
            'gateway_status should be processed');
        $this->assertEquals(1, $data['payment']->retry_count,
            'retry_count should be incremented');
    }

    public function test_retry_payment_calls_gateway_and_transitions_to_failed(): void
    {
        // Amount ending with .02 triggers "failed" status in MockPixGateway
        $data = $this->createApprovedShiftWithFailedPayment(amount: '100.02');

        $this->actingAs($this->admin)->postJson(
            route('shifts.payments.retry', [$data['shift'], $data['payment']])
        );

        $data['payment']->refresh();
        $this->assertEquals(PaymentStatus::Failed, $data['payment']->status,
            'Payment should auto-transition to Failed on gateway failed');
        $this->assertNotNull($data['payment']->failed_at,
            'failed_at should be set');
        $this->assertEquals('failed', $data['payment']->gateway_status,
            'gateway_status should be failed');
        $this->assertEquals(1, $data['payment']->retry_count,
            'retry_count should be incremented');
    }

    public function test_retry_payment_calls_gateway_stays_processing_on_queued(): void
    {
        // Round amount triggers "queued" status in MockPixGateway
        $data = $this->createApprovedShiftWithFailedPayment(amount: '100.00');

        $this->actingAs($this->admin)->postJson(
            route('shifts.payments.retry', [$data['shift'], $data['payment']])
        );

        $data['payment']->refresh();
        $this->assertEquals(PaymentStatus::Processing, $data['payment']->status,
            'Payment should transition to Processing on gateway queued');
        $this->assertNotNull($data['payment']->gateway_transaction_id,
            'gateway_transaction_id should be set');
        $this->assertEquals('queued', $data['payment']->gateway_status,
            'gateway_status should be queued');
    }

    public function test_retry_payment_with_fail_key_fails(): void
    {
        // pixKey starting with FAIL- triggers "failed" status in MockPixGateway
        $data = $this->createApprovedShiftWithFailedPayment(pixKey: 'FAIL-12345678');

        $this->actingAs($this->admin)->postJson(
            route('shifts.payments.retry', [$data['shift'], $data['payment']])
        );

        $data['payment']->refresh();
        $this->assertEquals(PaymentStatus::Failed, $data['payment']->status,
            'Payment should auto-transition to Failed when gateway rejects');
        $this->assertEquals('failed', $data['payment']->gateway_status,
            'gateway_status should be failed');
    }

    public function test_retry_payment_creates_audit_logs(): void
    {
        $data = $this->createApprovedShiftWithFailedPayment(amount: '100.01');

        $this->actingAs($this->admin)->postJson(
            route('shifts.payments.retry', [$data['shift'], $data['payment']])
        );

        $logs = PaymentAuditLog::where('payment_id', $data['payment']->id)
            ->orderBy('id')
            ->get();

        $this->assertGreaterThanOrEqual(2, $logs->count(),
            'Should have at least Retry and Succeed audit logs');

        // Last log: Succeed action (from gateway auto-transition)
        $succeedLog = $logs->last();
        $this->assertEquals(PaymentAuditAction::Succeed, $succeedLog->action,
            'Last log should be Succeed (gateway auto)');
    }

    public function test_retry_payment_gateway_attempt_creates_audit_log(): void
    {
        $data = $this->createApprovedShiftWithFailedPayment(amount: '100.01');

        $this->actingAs($this->admin)->postJson(
            route('shifts.payments.retry', [$data['shift'], $data['payment']])
        );

        // Should have GatewayAttempt audit log
        $gatewayLog = PaymentAuditLog::where('payment_id', $data['payment']->id)
            ->where('action', PaymentAuditAction::GatewayAttempt)
            ->first();

        $this->assertNotNull($gatewayLog,
            'Should have GatewayAttempt audit log');
        $this->assertNotNull($gatewayLog->transaction_ref,
            'GatewayAttempt log should have transaction_ref');
    }

    public function test_retry_payment_with_error_key_throws_exception_payment_stays_processing(): void
    {
        // pixKey starting with ERROR- throws RuntimeException in MockPixGateway
        $data = $this->createApprovedShiftWithFailedPayment(pixKey: 'ERROR-12345678');

        $this->actingAs($this->admin)->postJson(
            route('shifts.payments.retry', [$data['shift'], $data['payment']])
        );

        $data['payment']->refresh();
        $this->assertEquals(PaymentStatus::Processing, $data['payment']->status,
            'Payment should stay Processing when gateway throws');
        $this->assertEquals('error', $data['payment']->gateway_status,
            'gateway_status should be error');
    }

    public function test_retry_payment_increments_retry_count(): void
    {
        $data = $this->createApprovedShiftWithFailedPayment(
            amount: '100.01',
            retryCount: 1
        );

        $this->actingAs($this->admin)->postJson(
            route('shifts.payments.retry', [$data['shift'], $data['payment']])
        );

        $data['payment']->refresh();
        $this->assertEquals(2, $data['payment']->retry_count,
            'retry_count should be incremented from 1 to 2');
    }

    public function test_third_retry_auto_fails_without_gateway_call(): void
    {
        // At 3 retries, payment auto-fails without gateway call
        $data = $this->createApprovedShiftWithFailedPayment(
            amount: '100.01',
            retryCount: 2  // This will be the 3rd retry
        );

        $this->actingAs($this->admin)->postJson(
            route('shifts.payments.retry', [$data['shift'], $data['payment']])
        );

        $data['payment']->refresh();
        $this->assertEquals(PaymentStatus::Failed, $data['payment']->status,
            'Payment should auto-fail at retry cap without gateway call');
        $this->assertStringContainsString('Limite de retentativas', $data['payment']->failure_reason,
            'Failure reason should mention retry limit');
    }

    public function test_fourth_retry_is_rejected(): void
    {
        // At 4 retries, should reject (past cap of 3)
        $data = $this->createApprovedShiftWithFailedPayment(
            amount: '100.01',
            retryCount: 3
        );

        $response = $this->actingAs($this->admin)->postJson(
            route('shifts.payments.retry', [$data['shift'], $data['payment']])
        );

        // Controller returns 422 with error message for exceeded retry cap
        $response->assertStatus(422);

        $data['payment']->refresh();
        $this->assertEquals(3, $data['payment']->retry_count,
            'retry_count should not change after rejection');
    }
}
