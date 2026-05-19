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
 * Feature Tests: Payment Release with Gateway Integration
 *
 * Tests that PaymentReleaseService calls PixPaymentService for gateway integration.
 * This is an integration test that covers the full release flow including
 * the gateway call from controller through service to gateway.
 *
 * @see docs/plans/phase-4b-pix-payment-execution.md
 * @see docs/plans/phase-3b-payment-release-admin-approval.md
 */
class PaymentReleaseWithGatewayTest extends TestCase
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

    private function createClosedShiftWithPendingPayment(
        string $amount = '75.00',
        string $bikerRate = '10.00',
        string $baseFee = '25.00',
        string $pixKey = '11999999999',
        bool $pixKeyVerified = true,
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
            'is_verified' => $pixKeyVerified,
            'verified_at' => $pixKeyVerified ? now() : null,
        ]);

        User::factory()->create([
            'role' => UserRole::Biker,
            'biker_id' => $biker->id,
        ]);

        $shift = Shift::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'status' => ShiftStatus::Closed,
            'workflow_type' => WorkflowType::LiveTick,
            'restaurant_rate' => '15.00',
            'closed_at' => now(),
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
            'status' => PaymentStatus::Pending,
        ]);

        return [
            'shift' => $shift,
            'biker' => $biker,
            'shiftBiker' => $shiftBiker,
            'payment' => $payment,
        ];
    }

    private function createShiftBikerWithPayment(Shift $shift, string $amount, string $pixKey): array
    {
        $biker = Biker::factory()->create([
            'rate_per_trip' => '10.00',
            'base_fee' => '25.00',
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

        $shiftBiker = ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 5,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);

        $payment = Payment::factory()->create([
            'shift_biker_id' => $shiftBiker->id,
            'amount' => $amount,
            'status' => PaymentStatus::Pending,
        ]);

        return [
            'shift' => $shift,
            'biker' => $biker,
            'shiftBiker' => $shiftBiker,
            'payment' => $payment,
        ];
    }

    // ========================================================================
    // Gateway Integration Tests
    // ========================================================================

    public function test_release_single_payment_calls_gateway_and_transitions_to_paid(): void
    {
        // Amount ending with .01 triggers "processed" status in MockPixGateway
        $data = $this->createClosedShiftWithPendingPayment(amount: '100.01');

        $this->actingAs($this->admin)->postJson(
            route('shifts.payments.release', [$data['shift'], $data['payment']])
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
    }

    public function test_release_single_payment_calls_gateway_and_transitions_to_failed(): void
    {
        // Amount ending with .02 triggers "failed" status in MockPixGateway
        $data = $this->createClosedShiftWithPendingPayment(amount: '100.02');

        $this->actingAs($this->admin)->postJson(
            route('shifts.payments.release', [$data['shift'], $data['payment']])
        );

        $data['payment']->refresh();
        $this->assertEquals(PaymentStatus::Failed, $data['payment']->status,
            'Payment should auto-transition to Failed on gateway failed');
        $this->assertNotNull($data['payment']->failed_at,
            'failed_at should be set');
        $this->assertNotNull($data['payment']->gateway_transaction_id,
            'gateway_transaction_id should be set');
        $this->assertEquals('failed', $data['payment']->gateway_status,
            'gateway_status should be failed');
    }

    public function test_release_single_payment_calls_gateway_stays_processing_on_queued(): void
    {
        // Round amount triggers "queued" status in MockPixGateway
        $data = $this->createClosedShiftWithPendingPayment(amount: '100.00');

        $this->actingAs($this->admin)->postJson(
            route('shifts.payments.release', [$data['shift'], $data['payment']])
        );

        $data['payment']->refresh();
        $this->assertEquals(PaymentStatus::Processing, $data['payment']->status,
            'Payment should stay Processing on gateway queued');
        $this->assertNotNull($data['payment']->gateway_transaction_id,
            'gateway_transaction_id should be set');
        $this->assertEquals('queued', $data['payment']->gateway_status,
            'gateway_status should be queued');
    }

    public function test_release_single_payment_with_fail_key_fails(): void
    {
        // pixKey starting with FAIL- triggers "failed" status in MockPixGateway
        $data = $this->createClosedShiftWithPendingPayment(pixKey: 'FAIL-12345678');

        $this->actingAs($this->admin)->postJson(
            route('shifts.payments.release', [$data['shift'], $data['payment']])
        );

        $data['payment']->refresh();
        $this->assertEquals(PaymentStatus::Failed, $data['payment']->status,
            'Payment should auto-transition to Failed when gateway rejects');
        $this->assertEquals('failed', $data['payment']->gateway_status,
            'gateway_status should be failed');
        $this->assertNotNull($data['payment']->failure_reason,
            'failure_reason should be set');
    }

    public function test_release_single_payment_creates_audit_logs(): void
    {
        $data = $this->createClosedShiftWithPendingPayment(amount: '100.01');

        $this->actingAs($this->admin)->postJson(
            route('shifts.payments.release', [$data['shift'], $data['payment']])
        );

        $logs = PaymentAuditLog::where('payment_id', $data['payment']->id)
            ->orderBy('id')
            ->get();

        $this->assertGreaterThanOrEqual(2, $logs->count(),
            'Should have at least Release and Succeed audit logs');

        // First log: Release action
        $releaseLog = $logs->first();
        $this->assertEquals(PaymentAuditAction::Release, $releaseLog->action,
            'First log should be Release');

        // Last log: Succeed action (from gateway auto-transition)
        $succeedLog = $logs->last();
        $this->assertEquals(PaymentAuditAction::Succeed, $succeedLog->action,
            'Last log should be Succeed (gateway auto)');
    }

    public function test_release_single_payment_gateway_attempt_creates_audit_log(): void
    {
        $data = $this->createClosedShiftWithPendingPayment(amount: '100.01');

        $this->actingAs($this->admin)->postJson(
            route('shifts.payments.release', [$data['shift'], $data['payment']])
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

    public function test_batch_release_calls_gateway_for_each_eligible_payment(): void
    {
        // Create a single shift with 2 biker's with .01 amounts (processed) → both Paid
        $shift = Shift::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'status' => ShiftStatus::Closed,
            'workflow_type' => WorkflowType::LiveTick,
            'restaurant_rate' => '15.00',
            'closed_at' => now(),
        ]);

        // First biker
        $data1 = $this->createShiftBikerWithPayment($shift, '50.01', '11999999999');
        // Second biker on same shift
        $data2 = $this->createShiftBikerWithPayment($shift, '60.01', '11999999998');

        $this->actingAs($this->admin)->postJson(
            route('shifts.payments.release-all', $shift)
        );

        // Verify both payments are paid
        $data1['payment']->refresh();
        $data2['payment']->refresh();

        $this->assertEquals(PaymentStatus::Paid, $data1['payment']->status);
        $this->assertEquals(PaymentStatus::Paid, $data2['payment']->status);
    }

    public function test_batch_release_one_fails_one_succeeds(): void
    {
        // Create a single shift with 2 biker's:
        // First biker: .01 amount (processed) → Paid
        // Second biker: .02 amount (failed) → Failed
        $shift = Shift::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'status' => ShiftStatus::Closed,
            'workflow_type' => WorkflowType::LiveTick,
            'restaurant_rate' => '15.00',
            'closed_at' => now(),
        ]);

        $data1 = $this->createShiftBikerWithPayment($shift, '50.01', '11999999999');
        $data2 = $this->createShiftBikerWithPayment($shift, '60.02', '11999999998');

        $this->actingAs($this->admin)->postJson(
            route('shifts.payments.release-all', $shift)
        );

        $data1['payment']->refresh();
        $data2['payment']->refresh();

        $this->assertEquals(PaymentStatus::Paid, $data1['payment']->status,
            'Biker A should be paid');
        $this->assertEquals(PaymentStatus::Failed, $data2['payment']->status,
            'Biker B should be failed but this should not affect Biker A');
    }

    public function test_release_with_error_key_throws_exception_payment_stays_processing(): void
    {
        // pixKey starting with ERROR- throws RuntimeException in MockPixGateway
        $data = $this->createClosedShiftWithPendingPayment(pixKey: 'ERROR-12345678');

        $this->actingAs($this->admin)->postJson(
            route('shifts.payments.release', [$data['shift'], $data['payment']])
        );

        $data['payment']->refresh();
        $this->assertEquals(PaymentStatus::Processing, $data['payment']->status,
            'Payment should stay Processing when gateway throws');
        $this->assertEquals('error', $data['payment']->gateway_status,
            'gateway_status should be error');
    }
}
