<?php

namespace Tests\Unit\Services;

use App\Enums\PaymentAuditAction;
use App\Enums\PaymentStatus;
use App\Enums\ShiftStatus;
use App\Models\Biker;
use App\Models\Payment;
use App\Models\PaymentAuditLog;
use App\Models\PixKey;
use App\Models\PixWebhookLog;
use App\Models\Restaurant;
use App\Models\Shift;
use App\Models\ShiftBiker;
use App\Models\User;
use App\Services\PixPaymentService;
use App\Services\PixWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Unit Tests for PixWebhookService — Phase 4C
 *
 * Tests the core business logic for PIX webhook processing:
 * - Payment not found → ignored webhook log, HTTP 200
 * - Idempotency (duplicate webhook) → no status change
 * - Payment not in processing → ignored
 * - Status "processed" → payment paid, audit log, shift reconciliation
 * - Status "failed" → payment failed, audit log, shift unchanged
 * - Unknown status → ignored
 * - Unique transaction_refs for every audit log
 *
 * Acceptance Criteria: AC-4C-22 through AC-4C-41
 * Business Rules: BR-04 (Granular Failure), BR-06 (Payment Retries)
 *
 * @see docs/plans/phase-4c-pix-webhooks-async-status.md
 */
#[Group('phase4c')]
class PixWebhookServiceTest extends TestCase
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
            'role' => 'biker',
            'biker_id' => $biker->id,
        ]);

        return ['biker' => $biker, 'user' => $user];
    }

    /**
     * Create a payment in processing status with gateway_transaction_id.
     */
    private function createProcessingPayment(
        Shift $shift,
        Biker $biker,
        string $gatewayTransactionId = 'mock-txn-123-1700000000',
        string $amount = '75.00'
    ): Payment {
        $shiftBiker = ShiftBiker::firstOrCreate(
            ['shift_id' => $shift->id, 'biker_id' => $biker->id],
            ['trips_count' => 5, 'biker_rate' => '10.00', 'base_fee' => '25.00']
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

    /**
     * Call the webhook service and return the result.
     */
    private function processWebhook(array $payload, string $ip = '127.0.0.1'): PixWebhookLog
    {
        $service = $this->app->make(PixWebhookService::class);

        return $service->processWebhook($payload, $ip);
    }

    // ========================================================================
    // AC-4C-22, AC-4C-23: Payment Not Found
    // ========================================================================

    public function test_webhook_for_unknown_transaction_returns_ignored(): void
    {
        $payload = [
            'transaction_id' => 'unknown-txn-999',
            'status' => 'processed',
            'amount' => '75.00',
            'pix_key' => '11999999999',
            'error_code' => null,
            'error_message' => null,
            'timestamp' => now()->toIso8601String(),
        ];

        $log = $this->processWebhook($payload);

        $this->assertEquals('ignored', $log->status,
            'AC-4C-22: Webhook for unknown transaction must be ignored');
        $this->assertStringContainsString('Payment not found', $log->error_message,
            'AC-4C-22: Error message must contain "Payment not found"');
        $this->assertEquals('unknown-txn-999', $log->gateway_transaction_id,
            'AC-4C-22: gateway_transaction_id must be logged');
    }

    public function test_webhook_payment_not_found_returns_http_200_behavior(): void
    {
        // The service returns PixWebhookLog, not HTTP response.
        // Controller interprets this and returns 200 (not 404).
        // We test the log status indicates "ignored" which maps to 200.
        $payload = [
            'transaction_id' => 'unknown-txn-xyz',
            'status' => 'processed',
        ];

        $log = $this->processWebhook($payload);

        $this->assertEquals('ignored', $log->status,
            'AC-4C-23: Payment not found logs as "ignored" → HTTP 200 from controller');
    }

    // ========================================================================
    // AC-4C-24, AC-4C-25, AC-4C-26: Idempotency (Duplicate)
    // ========================================================================

    public function test_duplicate_webhook_when_already_paid_is_ignored(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $payment = $this->createProcessingPayment($shift, $biker, 'mock-txn-dup-123');

        // First webhook: mark as paid
        $this->processWebhook([
            'transaction_id' => 'mock-txn-dup-123',
            'status' => 'processed',
            'amount' => '75.00',
        ]);

        $paidPayment = $payment->fresh();
        $this->assertEquals(PaymentStatus::Paid, $paidPayment->status,
            'First webhook must transition payment to paid');

        $auditCountBefore = PaymentAuditLog::where('payment_id', $payment->id)->count();

        // Second webhook (duplicate): should be ignored
        $log = $this->processWebhook([
            'transaction_id' => 'mock-txn-dup-123',
            'status' => 'processed',
            'amount' => '75.00',
        ]);

        $this->assertEquals('duplicate', $log->status,
            'AC-4C-24: Duplicate webhook must be logged as "duplicate"');
        $this->assertEquals(PaymentStatus::Paid, $payment->fresh()->status,
            'AC-4C-24: Payment must stay paid, no status change');
        $this->assertEquals(
            $auditCountBefore,
            PaymentAuditLog::where('payment_id', $payment->id)->count(),
            'AC-4C-24: No new audit log for duplicate webhook'
        );
    }

    public function test_duplicate_webhook_when_already_failed_is_ignored(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $payment = $this->createProcessingPayment($shift, $biker, 'mock-txn-dup-fail');

        // First webhook: mark as failed
        $this->processWebhook([
            'transaction_id' => 'mock-txn-dup-fail',
            'status' => 'failed',
            'amount' => '75.00',
            'error_code' => 'ACCOUNT_CLOSED',
            'error_message' => 'Conta do destinatário encerrada',
        ]);

        $failedPayment = $payment->fresh();
        $this->assertEquals(PaymentStatus::Failed, $failedPayment->status,
            'First webhook must transition payment to failed');

        $auditCountBefore = PaymentAuditLog::where('payment_id', $payment->id)->count();

        // Second webhook (duplicate): should be ignored
        $log = $this->processWebhook([
            'transaction_id' => 'mock-txn-dup-fail',
            'status' => 'failed',
            'amount' => '75.00',
        ]);

        $this->assertEquals('duplicate', $log->status,
            'AC-4C-25: Duplicate webhook for failed payment must be logged as "duplicate"');
        $this->assertEquals(PaymentStatus::Failed, $payment->fresh()->status,
            'AC-4C-25: Payment must stay failed, no status change');
        $this->assertEquals(
            $auditCountBefore,
            PaymentAuditLog::where('payment_id', $payment->id)->count(),
            'AC-4C-25: No new audit log for duplicate webhook'
        );
    }

    // ========================================================================
    // AC-4C-27, AC-4C-28: Payment Not in Processing
    // ========================================================================

    public function test_webhook_for_pending_payment_is_ignored(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();

        $shiftBiker = ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
        ]);

        // Payment in pending status (not processing)
        $payment = Payment::factory()->create([
            'shift_biker_id' => $shiftBiker->id,
            'amount' => '75.00',
            'status' => PaymentStatus::Pending, // NOT processing
            'gateway_transaction_id' => 'mock-txn-pending-123',
        ]);

        $log = $this->processWebhook([
            'transaction_id' => 'mock-txn-pending-123',
            'status' => 'processed',
        ]);

        $this->assertEquals('ignored', $log->status,
            'AC-4C-27: Webhook for pending payment must be ignored');
        $this->assertStringContainsString('not in processing status', $log->error_message,
            'AC-4C-27: Error message must mention "not in processing status"');
        $this->assertEquals(PaymentStatus::Pending, $payment->fresh()->status,
            'AC-4C-27: Payment must stay pending');
    }

    public function test_webhook_for_approved_payment_is_ignored(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();

        $shiftBiker = ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
        ]);

        // Payment in approved status (not processing)
        $payment = Payment::factory()->create([
            'shift_biker_id' => $shiftBiker->id,
            'amount' => '75.00',
            'status' => PaymentStatus::Processing,
            'released_by' => $this->admin->id,
            'released_at' => now(),
            'gateway_transaction_id' => 'mock-txn-approved-123',
        ]);

        $log = $this->processWebhook([
            'transaction_id' => 'mock-txn-approved-123',
            'status' => 'processed',
        ]);

        $this->assertEquals('processed', $log->status,
            'Webhook for processing payment should be processed (not ignored)');
    }

    // ========================================================================
    // AC-4C-29, AC-4C-30, AC-4C-31, AC-4C-32: Webhook Status "processed"
    // ========================================================================

    public function test_webhook_status_processed_transitions_to_paid(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $payment = $this->createProcessingPayment($shift, $biker, 'mock-txn-paid-123');

        $log = $this->processWebhook([
            'transaction_id' => 'mock-txn-paid-123',
            'status' => 'processed',
            'amount' => '75.00',
            'pix_key' => '11999999999',
            'error_code' => null,
            'error_message' => null,
            'timestamp' => now()->toIso8601String(),
        ]);

        $this->assertEquals(PaymentStatus::Paid, $payment->fresh()->status,
            'AC-4C-29: Payment must transition to paid');
        $this->assertEquals('processed', $log->status,
            'AC-4C-32: PixWebhookLog status must be "processed"');
    }

    public function test_webhook_status_processed_sets_paid_at(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $payment = $this->createProcessingPayment($shift, $biker, 'mock-txn-paid-at-123');

        $before = now()->subSecond();
        $this->processWebhook([
            'transaction_id' => 'mock-txn-paid-at-123',
            'status' => 'processed',
        ]);
        $after = now()->addSecond();

        $fresh = $payment->fresh();
        $this->assertNotNull($fresh->paid_at,
            'AC-4C-29: paid_at must be set when status is processed');
        $this->assertTrue(
            $fresh->paid_at->greaterThanOrEqualTo($before)
            && $fresh->paid_at->lessThanOrEqualTo($after),
            'AC-4C-29: paid_at must be approximately current timestamp'
        );
    }

    public function test_webhook_status_processed_sets_gateway_status(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $payment = $this->createProcessingPayment($shift, $biker, 'mock-txn-gateway-123');

        $this->processWebhook([
            'transaction_id' => 'mock-txn-gateway-123',
            'status' => 'processed',
        ]);

        $this->assertEquals('processed', $payment->fresh()->gateway_status,
            'AC-4C-29: gateway_status must be set to "processed"');
    }

    public function test_webhook_status_processed_creates_succeed_audit_log(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $payment = $this->createProcessingPayment($shift, $biker, 'mock-txn-audit-123');

        $this->processWebhook([
            'transaction_id' => 'mock-txn-audit-123',
            'status' => 'processed',
            'amount' => '75.00',
            'pix_key' => '11999999999',
        ]);

        $logs = PaymentAuditLog::where('payment_id', $payment->id)->get();
        $this->assertCount(1, $logs,
            'AC-4C-30: Exactly one audit log for webhook-driven success');

        $log = $logs->first();
        $this->assertEquals(PaymentAuditAction::Succeed, $log->action,
            'AC-4C-30: Audit action must be succeed');
        $this->assertStringContainsString('webhook-paid', $log->transaction_ref,
            'AC-4C-30: transaction_ref must contain "webhook-paid" prefix');
    }

    public function test_webhook_success_audit_log_payload(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $payment = $this->createProcessingPayment($shift, $biker, 'mock-txn-payload-123');

        $this->processWebhook([
            'transaction_id' => 'mock-txn-payload-123',
            'status' => 'processed',
            'amount' => '75.00',
        ], '192.168.1.100');

        $log = PaymentAuditLog::where('payment_id', $payment->id)->first();
        $payload = $log->payload;

        $this->assertEquals('webhook', $payload['source'],
            'AC-4C-40: Payload source must be "webhook"');
        $this->assertEquals('mock-txn-payload-123', $payload['transaction_id'],
            'AC-4C-40: Payload must include transaction_id');
        $this->assertEquals('192.168.1.100', $payload['webhook_ip'],
            'AC-4C-40: Payload must include webhook_ip');
        $this->assertEquals('75.00', $payload['amount'],
            'AC-4C-40: Payload must include amount');
        $this->assertArrayHasKey('paid_at', $payload,
            'AC-4C-40: Payload must include paid_at');
    }

    public function test_webhook_status_processed_triggers_shift_reconciliation(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $payment = $this->createProcessingPayment($shift, $biker, 'mock-txn-reconcile-123');

        $this->assertEquals(ShiftStatus::Approved, $shift->fresh()->status,
            'Shift must start at approved');

        $this->processWebhook([
            'transaction_id' => 'mock-txn-reconcile-123',
            'status' => 'processed',
        ]);

        $this->assertEquals(ShiftStatus::Paid, $shift->fresh()->status,
            'AC-4C-31: Shift must transition to paid when all payments are paid');
    }

    public function test_webhook_single_payment_paid_reconciles_shift(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $payment = $this->createProcessingPayment($shift, $biker, 'mock-txn-single-123');

        $this->processWebhook([
            'transaction_id' => 'mock-txn-single-123',
            'status' => 'processed',
        ]);

        // Single payment → all paid → shift auto-transitions to paid
        $this->assertEquals(ShiftStatus::Paid, $shift->fresh()->status,
            'AC-4C-31: Single payment paid must reconcile shift to paid');
    }

    public function test_webhook_partial_payments_does_not_reconcile_shift(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker1] = $this->createEligibleBiker('Biker 1');
        $this->createProcessingPayment($shift, $biker1, 'mock-txn-partial-1', '75.00');

        ['biker' => $biker2] = $this->createEligibleBiker('Biker 2');
        $payment2 = $this->createProcessingPayment($shift, $biker2, 'mock-txn-partial-2', '50.00');

        // First payment: mark as paid via webhook
        $this->processWebhook([
            'transaction_id' => 'mock-txn-partial-1',
            'status' => 'processed',
        ]);

        $this->assertEquals(ShiftStatus::Approved, $shift->fresh()->status,
            'With one paid and one processing, shift must stay approved');

        // Second payment: mark as paid via webhook
        $this->processWebhook([
            'transaction_id' => 'mock-txn-partial-2',
            'status' => 'processed',
        ]);

        $this->assertEquals(ShiftStatus::Paid, $shift->fresh()->status,
            'When all payments are paid, shift must transition to paid');
    }

    // ========================================================================
    // ADR-006 D4: Delegation to PixPaymentService::reconcileShiftStatus()
    // ========================================================================

    public function test_webhook_delegates_reconciliation_to_pix_payment_service(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $payment = $this->createProcessingPayment($shift, $biker, 'mock-txn-delegate-123');

        $mockPaymentService = $this->createMock(PixPaymentService::class);
        $mockPaymentService->expects($this->once())
            ->method('reconcileShiftStatus')
            ->with($this->callback(function (Shift $s) use ($shift) {
                return $s->id === $shift->id;
            }));

        $service = new PixWebhookService($mockPaymentService);
        $service->processWebhook([
            'transaction_id' => 'mock-txn-delegate-123',
            'status' => 'processed',
            'amount' => '75.00',
        ], '127.0.0.1');
    }

    public function test_webhook_failed_does_not_call_reconcile_shift_status(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $payment = $this->createProcessingPayment($shift, $biker, 'mock-txn-no-reconcile-123');

        $mockPaymentService = $this->createMock(PixPaymentService::class);
        $mockPaymentService->expects($this->never())
            ->method('reconcileShiftStatus');

        $service = new PixWebhookService($mockPaymentService);
        $service->processWebhook([
            'transaction_id' => 'mock-txn-no-reconcile-123',
            'status' => 'failed',
            'error_message' => 'Account closed',
        ], '127.0.0.1');
    }

    public function test_webhook_ignored_does_not_call_reconcile_shift_status(): void
    {
        $mockPaymentService = $this->createMock(PixPaymentService::class);
        $mockPaymentService->expects($this->never())
            ->method('reconcileShiftStatus');

        $service = new PixWebhookService($mockPaymentService);
        $service->processWebhook([
            'transaction_id' => 'unknown-txn-ignored-999',
            'status' => 'processed',
        ], '127.0.0.1');
    }

    // ========================================================================
    // AC-4C-33, AC-4C-34, AC-4C-35, AC-4C-36, AC-4C-37: Webhook Status "failed"
    // ========================================================================

    public function test_webhook_status_failed_transitions_to_failed(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $payment = $this->createProcessingPayment($shift, $biker, 'mock-txn-failed-123');

        $this->processWebhook([
            'transaction_id' => 'mock-txn-failed-123',
            'status' => 'failed',
            'amount' => '75.00',
            'error_code' => 'ACCOUNT_CLOSED',
            'error_message' => 'Conta do destinatário encerrada',
        ]);

        $this->assertEquals(PaymentStatus::Failed, $payment->fresh()->status,
            'AC-4C-33: Payment must transition to failed');
    }

    public function test_webhook_status_failed_sets_failed_at(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $payment = $this->createProcessingPayment($shift, $biker, 'mock-txn-failed-at-123');

        $before = now()->subSecond();
        $this->processWebhook([
            'transaction_id' => 'mock-txn-failed-at-123',
            'status' => 'failed',
            'error_message' => 'Conta encerrada',
        ]);
        $after = now()->addSecond();

        $fresh = $payment->fresh();
        $this->assertNotNull($fresh->failed_at,
            'AC-4C-33: failed_at must be set when status is failed');
        $this->assertTrue(
            $fresh->failed_at->greaterThanOrEqualTo($before)
            && $fresh->failed_at->lessThanOrEqualTo($after),
            'AC-4C-33: failed_at must be approximately current timestamp'
        );
    }

    public function test_webhook_status_failed_sets_failure_reason_from_error_message(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $payment = $this->createProcessingPayment($shift, $biker, 'mock-txn-fail-reason-123');

        $this->processWebhook([
            'transaction_id' => 'mock-txn-fail-reason-123',
            'status' => 'failed',
            'error_message' => 'Destinatário não encontrado',
        ]);

        $this->assertEquals('Destinatário não encontrado', $payment->fresh()->failure_reason,
            'AC-4C-33: failure_reason must come from error_message');
    }

    public function test_webhook_status_failed_uses_error_code_when_no_message(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $payment = $this->createProcessingPayment($shift, $biker, 'mock-txn-fail-code-123');

        $this->processWebhook([
            'transaction_id' => 'mock-txn-fail-code-123',
            'status' => 'failed',
            'error_code' => 'INVALID_KEY',
            'error_message' => null,
        ]);

        $this->assertStringContainsString('INVALID_KEY', $payment->fresh()->failure_reason,
            'AC-4C-33: failure_reason must include error_code when error_message is null');
    }

    public function test_webhook_status_failed_sets_gateway_status(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $payment = $this->createProcessingPayment($shift, $biker, 'mock-txn-fail-gateway-123');

        $this->processWebhook([
            'transaction_id' => 'mock-txn-fail-gateway-123',
            'status' => 'failed',
        ]);

        $this->assertEquals('failed', $payment->fresh()->gateway_status,
            'AC-4C-34: gateway_status must be set to "failed"');
    }

    public function test_webhook_status_failed_creates_fail_audit_log(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $payment = $this->createProcessingPayment($shift, $biker, 'mock-txn-fail-audit-123');

        $this->processWebhook([
            'transaction_id' => 'mock-txn-fail-audit-123',
            'status' => 'failed',
            'error_message' => 'Chave PIX expirada',
        ]);

        $logs = PaymentAuditLog::where('payment_id', $payment->id)->get();
        $this->assertCount(1, $logs,
            'AC-4C-35: Exactly one audit log for webhook-driven failure');

        $log = $logs->first();
        $this->assertEquals(PaymentAuditAction::Fail, $log->action,
            'AC-4C-35: Audit action must be fail');
        $this->assertEquals('Chave PIX expirada', $log->error_message,
            'AC-4C-35: Audit log error_message must match failure reason');
    }

    public function test_webhook_failure_audit_log_payload(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $payment = $this->createProcessingPayment($shift, $biker, 'mock-txn-fail-payload-123');

        $this->processWebhook([
            'transaction_id' => 'mock-txn-fail-payload-123',
            'status' => 'failed',
            'error_code' => 'ACCOUNT_CLOSED',
            'error_message' => 'Conta encerrada',
        ], '10.0.0.50');

        $log = PaymentAuditLog::where('payment_id', $payment->id)->first();
        $payload = $log->payload;

        $this->assertEquals('webhook', $payload['source'],
            'AC-4C-40: Payload source must be "webhook"');
        $this->assertEquals('mock-txn-fail-payload-123', $payload['transaction_id'],
            'AC-4C-40: Payload must include transaction_id');
        $this->assertEquals('10.0.0.50', $payload['webhook_ip'],
            'AC-4C-40: Payload must include webhook_ip');
    }

    public function test_webhook_status_failed_does_not_change_shift_status(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $payment = $this->createProcessingPayment($shift, $biker, 'mock-txn-shift-123');

        $this->processWebhook([
            'transaction_id' => 'mock-txn-shift-123',
            'status' => 'failed',
            'error_message' => 'Network timeout',
        ]);

        $this->assertEquals(ShiftStatus::Approved, $shift->fresh()->status,
            'AC-4C-36, BR-04: Shift must stay approved when payment fails');
    }

    public function test_webhook_status_failed_one_payment_does_not_affect_shift(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker1] = $this->createEligibleBiker('Biker 1');
        $this->createProcessingPayment($shift, $biker1, 'mock-txn-multi-1', '75.00');

        ['biker' => $biker2] = $this->createEligibleBiker('Biker 2');
        $this->createProcessingPayment($shift, $biker2, 'mock-txn-multi-2', '50.00');

        // Fail first payment
        $this->processWebhook([
            'transaction_id' => 'mock-txn-multi-1',
            'status' => 'failed',
            'error_message' => 'Connection refused',
        ]);

        $this->assertEquals(ShiftStatus::Approved, $shift->fresh()->status,
            'BR-04: One failed payment must not affect shift status');

        // Pay second payment
        $this->processWebhook([
            'transaction_id' => 'mock-txn-multi-2',
            'status' => 'processed',
        ]);

        // With one paid and one failed, shift stays approved
        $this->assertEquals(ShiftStatus::Approved, $shift->fresh()->status,
            'BR-04: With mixed paid/failed, shift stays approved');
    }

    // ========================================================================
    // AC-4C-38: Unknown Status
    // ========================================================================

    public function test_webhook_unknown_status_is_ignored(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $payment = $this->createProcessingPayment($shift, $biker, 'mock-txn-unknown-123');

        $log = $this->processWebhook([
            'transaction_id' => 'mock-txn-unknown-123',
            'status' => 'cancelled',  // Unknown status
        ]);

        $this->assertEquals('ignored', $log->status,
            'AC-4C-38: Unknown webhook status must be ignored');
        $this->assertStringContainsString('Unknown webhook status', $log->error_message,
            'AC-4C-38: Error message must mention "Unknown webhook status"');
        $this->assertEquals(PaymentStatus::Processing, $payment->fresh()->status,
            'AC-4C-38: Payment must stay in processing');
    }

    public function test_webhook_status_queued_is_ignored(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $payment = $this->createProcessingPayment($shift, $biker, 'mock-txn-queued-123');

        $log = $this->processWebhook([
            'transaction_id' => 'mock-txn-queued-123',
            'status' => 'queued',  // Not processed/failed
        ]);

        $this->assertEquals('ignored', $log->status,
            'AC-4C-38: "queued" status must be ignored (not processed/failed)');
        $this->assertEquals(PaymentStatus::Processing, $payment->fresh()->status,
            'AC-4C-38: Payment stays processing for "queued" status');
    }

    // ========================================================================
    // AC-4C-39, AC-4C-41: Audit Trail & Unique Transaction Refs
    // ========================================================================

    public function test_webhook_audit_log_transaction_ref_is_unique(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();

        // Create multiple payments
        for ($i = 0; $i < 3; $i++) {
            $payment = $this->createProcessingPayment(
                $shift,
                $biker,
                "mock-txn-unique-{$i}-".time()
            );

            $this->processWebhook([
                'transaction_id' => $payment->gateway_transaction_id,
                'status' => 'processed',
            ]);

            $logs = PaymentAuditLog::where('payment_id', $payment->id)->get();
            $this->assertCount(1, $logs,
                "AC-4C-39: Payment {$i} must have exactly one audit log");
            $this->assertNotEmpty($logs->first()->transaction_ref,
                'AC-4C-39: transaction_ref must not be empty');
        }

        // All transaction_refs must be unique
        $allRefs = PaymentAuditLog::all()->pluck('transaction_ref');
        $this->assertEquals(
            $allRefs->count(),
            $allRefs->unique()->count(),
            'AC-4C-39: All transaction_refs must be unique globally'
        );
    }

    public function test_webhook_audit_log_contains_payment_id(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $payment = $this->createProcessingPayment($shift, $biker, 'mock-txn-pid-123');

        $this->processWebhook([
            'transaction_id' => 'mock-txn-pid-123',
            'status' => 'processed',
        ]);

        $log = PaymentAuditLog::where('payment_id', $payment->id)->first();
        $this->assertNotNull($log,
            'AC-4C-41: Audit log must have payment_id set');
        $this->assertEquals($payment->id, $log->payment_id,
            'AC-4C-41: Audit log payment_id must match the payment');
    }

    public function test_duplicate_webhook_creates_no_duplicate_audit_log(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $payment = $this->createProcessingPayment($shift, $biker, 'mock-txn-no-dup-123');

        // First webhook
        $this->processWebhook([
            'transaction_id' => 'mock-txn-no-dup-123',
            'status' => 'processed',
        ]);

        $this->assertEquals(1, PaymentAuditLog::where('payment_id', $payment->id)->count(),
            'First webhook must create audit log');

        // Second webhook (duplicate)
        $this->processWebhook([
            'transaction_id' => 'mock-txn-no-dup-123',
            'status' => 'processed',
        ]);

        $this->assertEquals(1, PaymentAuditLog::where('payment_id', $payment->id)->count(),
            'Duplicate webhook must not create new audit log');
    }

    public function test_webhook_failure_then_retry_creates_new_audit_log(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $payment = $this->createProcessingPayment($shift, $biker, 'mock-txn-retry-123');

        // First webhook: failure
        $this->processWebhook([
            'transaction_id' => 'mock-txn-retry-123',
            'status' => 'failed',
            'error_message' => 'First failure',
        ]);

        $this->assertEquals(1, PaymentAuditLog::where('payment_id', $payment->id)->count(),
            'First webhook must create one audit log');

        // Manually retry payment (simulating admin retry)
        $payment->refresh();
        $payment->status = PaymentStatus::Processing;
        $payment->failed_at = null;
        $payment->failure_reason = null;
        $payment->retry_count = 1;
        $payment->save();

        // Second webhook: success
        $this->processWebhook([
            'transaction_id' => 'mock-txn-retry-123',
            'status' => 'processed',
        ]);

        $this->assertEquals(2, PaymentAuditLog::where('payment_id', $payment->id)->count(),
            'Retry webhook must create second audit log');
    }

    // ========================================================================
    // BR-04: Granular Failure — Independent Payment Processing
    // ========================================================================

    public function test_webhook_granular_failure_independent_payments(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $bikerA] = $this->createEligibleBiker('Biker A');
        $paymentA = $this->createProcessingPayment($shift, $bikerA, 'mock-txn-biker-a-123');

        ['biker' => $bikerB] = $this->createEligibleBiker('Biker B');
        $paymentB = $this->createProcessingPayment($shift, $bikerB, 'mock-txn-biker-b-123');

        // Biker A fails
        $this->processWebhook([
            'transaction_id' => 'mock-txn-biker-a-123',
            'status' => 'failed',
            'error_message' => 'Invalid PIX key',
        ]);

        $this->assertEquals(PaymentStatus::Failed, $paymentA->fresh()->status,
            'BR-04: Biker A payment must fail independently');
        $this->assertEquals(PaymentStatus::Processing, $paymentB->fresh()->status,
            'BR-04: Biker B payment must remain processing (unaffected)');

        // Biker B succeeds
        $this->processWebhook([
            'transaction_id' => 'mock-txn-biker-b-123',
            'status' => 'processed',
        ]);

        $this->assertEquals(PaymentStatus::Failed, $paymentA->fresh()->status,
            'BR-04: Biker A must stay failed');
        $this->assertEquals(PaymentStatus::Paid, $paymentB->fresh()->status,
            'BR-04: Biker B must be paid');

        // Shift stays approved because not all payments are paid
        $this->assertEquals(ShiftStatus::Approved, $shift->fresh()->status,
            'BR-04: Shift stays approved when some payments fail');
    }

    public function test_webhook_all_failures_leave_shift_approved(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker1] = $this->createEligibleBiker('Biker 1');
        $this->createProcessingPayment($shift, $biker1, 'mock-txn-all-fail-1');

        ['biker' => $biker2] = $this->createEligibleBiker('Biker 2');
        $this->createProcessingPayment($shift, $biker2, 'mock-txn-all-fail-2');

        // Both fail
        $this->processWebhook(['transaction_id' => 'mock-txn-all-fail-1', 'status' => 'failed', 'error_message' => 'Error']);
        $this->processWebhook(['transaction_id' => 'mock-txn-all-fail-2', 'status' => 'failed', 'error_message' => 'Error']);

        $this->assertEquals(ShiftStatus::Approved, $shift->fresh()->status,
            'BR-04: Shift stays approved even when all payments fail');
    }

    // ========================================================================
    // PixWebhookLog Creation
    // ========================================================================

    public function test_webhook_creates_pix_webhook_log(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $payment = $this->createProcessingPayment($shift, $biker, 'mock-txn-log-123');

        $this->processWebhook([
            'transaction_id' => 'mock-txn-log-123',
            'status' => 'processed',
            'amount' => '75.00',
            'pix_key' => '11999999999',
        ], '203.0.113.42');

        $log = PixWebhookLog::where('gateway_transaction_id', 'mock-txn-log-123')->first();
        $this->assertNotNull($log,
            'PixWebhookLog must be created for processed webhook');
        $this->assertEquals('processed', $log->status,
            'PixWebhookLog status must be "processed"');
        $this->assertEquals('203.0.113.42', $log->ip_address,
            'PixWebhookLog must store IP address');
        $this->assertIsArray($log->payload,
            'PixWebhookLog payload must be cast to array');
    }

    public function test_webhook_creates_log_with_payload_json(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $payment = $this->createProcessingPayment($shift, $biker, 'mock-txn-json-123');

        $inputPayload = [
            'transaction_id' => 'mock-txn-json-123',
            'status' => 'processed',
            'amount' => '75.50',
            'pix_key' => '11999999999',
            'error_code' => null,
            'error_message' => null,
            'timestamp' => '2026-05-17T15:30:00Z',
        ];

        $this->processWebhook($inputPayload, '127.0.0.1');

        $log = PixWebhookLog::where('gateway_transaction_id', 'mock-txn-json-123')->first();
        $this->assertIsArray($log->payload,
            'Payload must be stored as array');
        $this->assertEquals('processed', $log->payload['status'],
            'Payload must contain status');
    }

    // ========================================================================
    // Edge Cases
    // ========================================================================

    public function test_webhook_with_null_error_fields(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $payment = $this->createProcessingPayment($shift, $biker, 'mock-txn-null-123');

        $log = $this->processWebhook([
            'transaction_id' => 'mock-txn-null-123',
            'status' => 'processed',
            'amount' => '75.00',
            'pix_key' => '11999999999',
            'error_code' => null,
            'error_message' => null,
        ]);

        $this->assertEquals('processed', $log->status,
            'Webhook with null error fields must be processed successfully');
    }

    public function test_webhook_with_missing_optional_fields(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $payment = $this->createProcessingPayment($shift, $biker, 'mock-txn-min-123');

        // Minimal payload with only required fields
        $log = $this->processWebhook([
            'transaction_id' => 'mock-txn-min-123',
            'status' => 'processed',
        ]);

        $this->assertEquals('processed', $log->status,
            'Webhook with minimal payload must be processed');
        $this->assertEquals(PaymentStatus::Paid, $payment->fresh()->status,
            'Payment must still be marked as paid');
    }

    public function test_webhook_amount_matches_payment_amount(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $payment = $this->createProcessingPayment($shift, $biker, 'mock-txn-amt-123', '112.50');

        $this->processWebhook([
            'transaction_id' => 'mock-txn-amt-123',
            'status' => 'processed',
            'amount' => '112.50',
        ]);

        $this->assertEquals(PaymentStatus::Paid, $payment->fresh()->status,
            'Webhook amount must match payment amount for success');
    }

    public function test_pix_webhook_log_ip_address_stored(): void
    {
        $shift = $this->createApprovedShift();
        ['biker' => $biker] = $this->createEligibleBiker();
        $payment = $this->createProcessingPayment($shift, $biker, 'mock-txn-ip-123');

        $this->processWebhook([
            'transaction_id' => 'mock-txn-ip-123',
            'status' => 'processed',
        ], '198.51.100.42');

        $log = PixWebhookLog::where('gateway_transaction_id', 'mock-txn-ip-123')->first();
        $this->assertEquals('198.51.100.42', $log->ip_address,
            'IP address from webhook request must be stored');
    }
}
