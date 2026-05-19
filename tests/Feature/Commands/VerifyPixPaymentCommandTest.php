<?php

namespace Tests\Feature\Commands;

use App\Enums\PaymentAuditAction;
use App\Enums\PaymentStatus;
use App\Enums\ShiftStatus;
use App\Models\Biker;
use App\Models\Payment;
use App\Models\PaymentAuditLog;
use App\Models\PixKey;
use App\Models\Restaurant;
use App\Models\Shift;
use App\Models\ShiftBiker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Feature Tests for VerifyPixPayment Artisan Command — Phase 4C
 *
 * Tests the `pix:webhook:verify {gatewayTransactionId}` command:
 * - Payment not found → exit code 1 with error message
 * - Payment already resolved → exit code 0 with info message
 * - Gateway returns "processed" → payment marked as paid, audit log, reconciliation
 * - Gateway returns "failed" → payment marked as failed, audit log
 * - Gateway returns "queued" → payment stays processing, info message
 *
 * Acceptance Criteria: AC-4C-51 through AC-4C-55
 *
 * @see docs/plans/phase-4c-pix-webhooks-async-status.md
 */
#[Group('phase4c')]
class VerifyPixPaymentCommandTest extends TestCase
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
            'role' => 'admin',
        ]);
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
            'role' => 'biker',
            'biker_id' => $biker->id,
        ]);

        return ['biker' => $biker, 'user' => $user];
    }

    private function createProcessingPayment(
        Shift $shift,
        Biker $biker,
        string $gatewayTransactionId = 'mock-txn-verify-123',
        string $amount = '75.00'
    ): Payment {
        $shiftBiker = ShiftBiker::firstOrCreate(
            [
                'shift_id' => $shift->id,
                'biker_id' => $biker->id,
            ],
            [
                'trips_count' => 5,
                'biker_rate' => '10.00',
                'base_fee' => '25.00',
            ]
        );

        return Payment::factory()->create([
            'shift_biker_id' => $shiftBiker->id,
            'amount' => $amount,
            'revenue' => '0.00',
            'status' => PaymentStatus::Processing,
            'released_by' => $this->admin->id,
            'released_at' => now(),
            'gateway_transaction_id' => $gatewayTransactionId,
        ]);
    }

    // ========================================================================
    // AC-4C-51: Find payment by gateway_transaction_id
    // AC-4C-52: Payment not found → exit code 1
    // ========================================================================

    public function test_command_fails_when_payment_not_found(): void
    {
        $this->artisan('pix:webhook:verify', ['gatewayTransactionId' => 'nonexistent-txn-999'])
            ->expectsOutputToContain('Payment not found for transaction: nonexistent-txn-999')
            ->assertExitCode(1);
    }

    // ========================================================================
    // AC-4C-53: Payment already resolved → exit code 0
    // ========================================================================

    public function test_command_returns_info_when_payment_already_paid(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $payment = $this->createProcessingPayment($shift, $biker, 'mock-txn-already-paid');

        // Manually mark as paid
        $payment->status = PaymentStatus::Paid;
        $payment->paid_at = now();
        $payment->save();

        $this->artisan('pix:webhook:verify', ['gatewayTransactionId' => 'mock-txn-already-paid'])
            ->expectsOutputToContain('already paid')
            ->assertExitCode(0);
    }

    public function test_command_returns_info_when_payment_already_failed(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $payment = $this->createProcessingPayment($shift, $biker, 'mock-txn-already-failed');

        // Manually mark as failed
        $payment->status = PaymentStatus::Failed;
        $payment->failed_at = now();
        $payment->failure_reason = 'Already resolved';
        $payment->save();

        $this->artisan('pix:webhook:verify', ['gatewayTransactionId' => 'mock-txn-already-failed'])
            ->expectsOutputToContain('already failed')
            ->assertExitCode(0);
    }

    public function test_command_returns_info_when_payment_still_pending(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();

        $shiftBiker = ShiftBiker::firstOrCreate(
            ['shift_id' => $shift->id, 'biker_id' => $biker->id],
            ['trips_count' => 3, 'biker_rate' => '10.00', 'base_fee' => '25.00']
        );

        $payment = Payment::factory()->create([
            'shift_biker_id' => $shiftBiker->id,
            'amount' => '55.00',
            'status' => PaymentStatus::Pending,
            'gateway_transaction_id' => 'mock-txn-pending-verify',
        ]);

        $this->artisan('pix:webhook:verify', ['gatewayTransactionId' => 'mock-txn-pending-verify'])
            ->expectsOutputToContain('already pending')
            ->assertExitCode(0);
    }

    // ========================================================================
    // AC-4C-54: Gateway returns "processed" → payment paid + audit + reconcile
    // ========================================================================

    public function test_command_marks_payment_as_paid_when_gateway_returns_processed(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $payment = $this->createProcessingPayment($shift, $biker, 'mock-txn-verify-paid');

        $this->artisan('pix:webhook:verify', ['gatewayTransactionId' => 'mock-txn-verify-paid'])
            ->expectsOutputToContain('Gateway status: processed')
            ->expectsOutputToContain('marked as PAID')
            ->assertExitCode(0);

        $fresh = $payment->fresh();
        $this->assertEquals(PaymentStatus::Paid, $fresh->status,
            'AC-4C-54: Payment must be marked as paid');
        $this->assertNotNull($fresh->paid_at,
            'AC-4C-54: paid_at must be set');
        $this->assertEquals('processed', $fresh->gateway_status,
            'AC-4C-54: gateway_status must be "processed"');
    }

    public function test_command_creates_succeed_audit_log_when_paid(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $payment = $this->createProcessingPayment($shift, $biker, 'mock-txn-verify-audit');

        $this->artisan('pix:webhook:verify', ['gatewayTransactionId' => 'mock-txn-verify-audit']);

        $log = PaymentAuditLog::where('payment_id', $payment->id)->first();
        $this->assertNotNull($log,
            'AC-4C-54: Audit log must be created');
        $this->assertEquals(PaymentAuditAction::Succeed, $log->action,
            'AC-4C-54: Audit action must be Succeed');
        $this->assertStringContainsString('verify-paid', $log->transaction_ref,
            'AC-4C-54: transaction_ref must contain "verify-paid"');
        $this->assertEquals('manual_verify', $log->payload['source'],
            'AC-4C-54: Payload source must be "manual_verify"');
    }

    public function test_command_reconciles_shift_when_payment_paid(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $this->createProcessingPayment($shift, $biker, 'mock-txn-verify-reconcile');

        $this->assertEquals(ShiftStatus::Approved, $shift->fresh()->status);

        $this->artisan('pix:webhook:verify', ['gatewayTransactionId' => 'mock-txn-verify-reconcile']);

        $this->assertEquals(ShiftStatus::Paid, $shift->fresh()->status,
            'AC-4C-54: Shift must transition to paid when all payments are paid');
    }

    // ========================================================================
    // AC-4C-54: Gateway returns "failed" → payment failed + audit
    // ========================================================================

    public function test_command_marks_payment_as_failed_when_gateway_returns_failed(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $payment = $this->createProcessingPayment($shift, $biker, 'mock-txn-verify-sync-failed');

        $this->artisan('pix:webhook:verify', ['gatewayTransactionId' => 'mock-txn-verify-sync-failed'])
            ->expectsOutputToContain('Gateway status: failed')
            ->expectsOutputToContain('marked as FAILED')
            ->assertExitCode(0);

        $fresh = $payment->fresh();
        $this->assertEquals(PaymentStatus::Failed, $fresh->status,
            'AC-4C-54: Payment must be marked as failed');
        $this->assertNotNull($fresh->failed_at,
            'AC-4C-54: failed_at must be set');
        $this->assertEquals('failed', $fresh->gateway_status,
            'AC-4C-54: gateway_status must be "failed"');
    }

    public function test_command_creates_fail_audit_log_when_failed(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $payment = $this->createProcessingPayment($shift, $biker, 'mock-txn-verify-fail-audit-sync-failed');

        $this->artisan('pix:webhook:verify', ['gatewayTransactionId' => 'mock-txn-verify-fail-audit-sync-failed']);

        $log = PaymentAuditLog::where('payment_id', $payment->id)->first();
        $this->assertNotNull($log,
            'AC-4C-54: Audit log must be created for failed payment');
        $this->assertEquals(PaymentAuditAction::Fail, $log->action,
            'AC-4C-54: Audit action must be Fail');
        $this->assertStringContainsString('verify-failed', $log->transaction_ref,
            'AC-4C-54: transaction_ref must contain "verify-failed"');
        $this->assertEquals('manual_verify', $log->payload['source'],
            'AC-4C-54: Payload source must be "manual_verify"');
    }

    public function test_command_failure_does_not_change_shift_status(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $this->createProcessingPayment($shift, $biker, 'mock-txn-verify-shift-sync-failed');

        $this->artisan('pix:webhook:verify', ['gatewayTransactionId' => 'mock-txn-verify-shift-sync-failed']);

        $this->assertEquals(ShiftStatus::Approved, $shift->fresh()->status,
            'AC-4C-54: Shift must stay approved when payment fails (BR-04)');
    }

    // ========================================================================
    // AC-4C-55: Gateway returns unknown/queued → payment stays processing
    // ========================================================================

    public function test_command_leaves_payment_processing_when_gateway_returns_queued(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $payment = $this->createProcessingPayment($shift, $biker, 'mock-txn-verify-sync-pending');

        $this->artisan('pix:webhook:verify', ['gatewayTransactionId' => 'mock-txn-verify-sync-pending'])
            ->expectsOutputToContain('still in processing')
            ->assertExitCode(0);

        $this->assertEquals(PaymentStatus::Processing, $payment->fresh()->status,
            'AC-4C-55: Payment must stay in processing when gateway returns queued');
    }

    public function test_command_outputs_resolved_status_to_console(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $this->createProcessingPayment($shift, $biker, 'mock-txn-verify-output');

        $this->artisan('pix:webhook:verify', ['gatewayTransactionId' => 'mock-txn-verify-output'])
            ->expectsOutputToContain('Gateway status: processed')
            ->expectsOutputToContain('Transaction ID: mock-txn-verify-output')
            ->expectsOutputToContain('marked as PAID')
            ->assertExitCode(0);
    }
}
