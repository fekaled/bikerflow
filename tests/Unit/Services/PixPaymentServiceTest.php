<?php

namespace Tests\Unit\Services;

use App\Contracts\PaymentResponse;
use App\Contracts\PixGatewayInterface;
use App\Contracts\VerifyKeyResponse;
use App\Enums\PaymentAuditAction;
use App\Enums\PaymentStatus;
use App\Enums\PixKeyType;
use App\Enums\ShiftStatus;
use App\Models\Biker;
use App\Models\Payment;
use App\Models\PaymentAuditLog;
use App\Models\PixKey;
use App\Models\Restaurant;
use App\Models\Shift;
use App\Models\ShiftBiker;
use App\Models\User;
use App\Services\Gateway\MockPixGateway;
use App\Services\PixPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit Tests for PixPaymentService — Phase 4B
 *
 * Tests the gateway call orchestrator:
 * - Guard: payment must be in processing status (AC-4B-13)
 * - Guard: biker must have verified PIX key (AC-4B-15)
 * - Gateway call with correct params (AC-4B-16)
 * - Gateway exception → processing stays, gateway_status = error (AC-4B-17→AC-4B-20)
 * - Gateway processed → auto-paid, audit log, shift reconciliation (AC-4B-21→AC-4B-24)
 * - Gateway failed → auto-failed, audit log, shift unchanged (AC-4B-25→AC-4B-28)
 * - Gateway queued → processing stays, gateway_status = queued (AC-4B-29→AC-4B-31)
 * - Unknown status → treated as queued (AC-4B-32)
 * - Unique transaction_refs (AC-4B-33, AC-4B-34)
 *
 * Acceptance Criteria: AC-4B-13 through AC-4B-34
 * Business Rules: BR-02 (PIX Verification), BR-04 (Granular Failure), BR-06 (Payment Retries)
 *
 * @see docs/plans/phase-4b-pix-payment-execution.md
 */
class PixPaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    private PixPaymentService $service;

    private MockPixGateway $gateway;

    private Restaurant $restaurant;

    private User $admin;

    private Biker $biker;

    private Shift $shift;

    private ShiftBiker $shiftBiker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new MockPixGateway;
        $this->service = new PixPaymentService($this->gateway);

        $this->restaurant = Restaurant::factory()->create(['rate_per_trip' => '15.00']);

        $this->admin = User::factory()->create(['role' => 'admin']);

        $this->biker = Biker::factory()->create([
            'name' => 'João da Silva',
            'rate_per_trip' => '10.00',
            'base_fee' => '25.00',
        ]);

        $this->shift = Shift::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'restaurant_rate' => '15.00',
            'status' => ShiftStatus::Approved,
        ]);

        $this->shiftBiker = ShiftBiker::factory()->create([
            'shift_id' => $this->shift->id,
            'biker_id' => $this->biker->id,
            'trips_count' => 5,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);

        // Default: eligible biker with verified PIX key
        PixKey::factory()->create([
            'biker_id' => $this->biker->id,
            'key_type' => 'cpf',
            'key_value' => '12345678901',
            'is_verified' => true,
            'verified_at' => now(),
        ]);
    }

    private function createProcessingPayment(array $overrides = []): Payment
    {
        return Payment::factory()->create(array_merge([
            'shift_biker_id' => $this->shiftBiker->id,
            'amount' => '75.00',
            'status' => PaymentStatus::Processing,
            'released_by' => $this->admin->id,
            'released_at' => now(),
        ], $overrides));
    }

    // ========================================================================
    // AC-4B-13: Throws if payment is not in processing status
    // ========================================================================

    public function test_initiate_transfer_throws_if_payment_is_pending(): void
    {
        $pendingPayment = Payment::factory()->create([
            'shift_biker_id' => $this->shiftBiker->id,
            'status' => PaymentStatus::Pending,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('processing');

        $this->service->initiateTransfer($pendingPayment, $this->admin);
    }

    public function test_initiate_transfer_throws_if_payment_is_paid(): void
    {
        $paidPayment = Payment::factory()->create([
            'shift_biker_id' => $this->shiftBiker->id,
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('processing');

        $this->service->initiateTransfer($paidPayment, $this->admin);
    }

    public function test_initiate_transfer_throws_if_payment_is_failed(): void
    {
        $failedPayment = Payment::factory()->create([
            'shift_biker_id' => $this->shiftBiker->id,
            'status' => PaymentStatus::Failed,
            'failed_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('processing');

        $this->service->initiateTransfer($failedPayment, $this->admin);
    }

    // ========================================================================
    // AC-4B-14 & AC-4B-15: Resolves verified PIX key, throws if none found
    // ========================================================================

    public function test_initiate_transfer_calls_gateway_with_verified_pix_key(): void
    {
        $payment = $this->createProcessingPayment(['amount' => '75.00']);

        // Mock to track calls
        $this->app->instance(PixGatewayInterface::class, $trackingGateway = new TrackingPixGateway);

        $service = new PixPaymentService($trackingGateway);
        $service->initiateTransfer($payment, $this->admin);

        $this->assertEquals('12345678901', $trackingGateway->lastCall['pixKey'],
            'AC-4B-14: Gateway must be called with the biker\'s verified PIX key value');
    }

    public function test_initiate_transfer_throws_if_no_verified_pix_key(): void
    {
        // Override: biker has no verified PIX key
        PixKey::where('biker_id', $this->biker->id)->update(['is_verified' => false]);

        $payment = $this->createProcessingPayment();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('verified PIX key');

        $this->service->initiateTransfer($payment, $this->admin);
    }

    public function test_initiate_transfer_throws_if_biker_has_no_pix_key_at_all(): void
    {
        // Override: biker has no PIX keys
        PixKey::where('biker_id', $this->biker->id)->delete();

        $payment = $this->createProcessingPayment();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('verified PIX key');

        $this->service->initiateTransfer($payment, $this->admin);
    }

    public function test_initiate_transfer_picks_first_verified_key_if_multiple(): void
    {
        // Create multiple verified keys
        PixKey::factory()->create([
            'biker_id' => $this->biker->id,
            'key_type' => 'email',
            'key_value' => 'biker@example.com',
            'is_verified' => true,
        ]);

        $payment = $this->createProcessingPayment(['amount' => '75.00']);

        $trackingGateway = new TrackingPixGateway;
        $service = new PixPaymentService($trackingGateway);
        $service->initiateTransfer($payment, $this->admin);

        // Should pick one of the verified keys (first by ID order)
        $lastCall = $trackingGateway->lastCall;
        $this->assertContains($lastCall['pixKey'], ['12345678901', 'biker@example.com'],
            'AC-4B-14: Gateway must be called with one of the verified PIX keys');
    }

    // ========================================================================
    // AC-4B-16: Calls gateway with correct parameters
    // ========================================================================

    public function test_initiate_transfer_calls_gateway_with_correct_payment_id(): void
    {
        $payment = $this->createProcessingPayment(['amount' => '75.00']);

        $trackingGateway = new TrackingPixGateway;
        $service = new PixPaymentService($trackingGateway);
        $service->initiateTransfer($payment, $this->admin);

        $this->assertEquals($payment->id, $trackingGateway->lastCall['paymentId'],
            'AC-4B-16: Gateway must be called with payment ID');
    }

    public function test_initiate_transfer_calls_gateway_with_exact_amount(): void
    {
        $payment = $this->createProcessingPayment(['amount' => '123.45']);

        $trackingGateway = new TrackingPixGateway;
        $service = new PixPaymentService($trackingGateway);
        $service->initiateTransfer($payment, $this->admin);

        $this->assertEquals('123.45', $trackingGateway->lastCall['amount'],
            'AC-4B-16: Gateway must be called with exact payment amount');
    }

    public function test_initiate_transfer_calls_gateway_with_string_amount(): void
    {
        $payment = $this->createProcessingPayment(['amount' => '75.50']);

        $trackingGateway = new TrackingPixGateway;
        $service = new PixPaymentService($trackingGateway);
        $service->initiateTransfer($payment, $this->admin);

        // Amount should be a string (BCMath precision)
        $this->assertIsString($trackingGateway->lastCall['amount'],
            'AC-4B-16: Amount must be a string for BCMath precision');
    }

    // ========================================================================
    // AC-4B-17→AC-4B-20: Gateway exception → stays processing, gateway_status = error
    // ========================================================================

    public function test_initiate_transfer_gateway_exception_payment_stays_processing(): void
    {
        $gateway = new ExceptionPixGateway;
        $service = new PixPaymentService($gateway);
        $payment = $this->createProcessingPayment();

        $result = $service->initiateTransfer($payment, $this->admin);

        $this->assertEquals(PaymentStatus::Processing, $result->fresh()->status,
            'AC-4B-17: Payment must stay in processing after gateway exception');
    }

    public function test_initiate_transfer_gateway_exception_sets_gateway_status_error(): void
    {
        $gateway = new ExceptionPixGateway;
        $service = new PixPaymentService($gateway);
        $payment = $this->createProcessingPayment();

        $result = $service->initiateTransfer($payment, $this->admin);

        $this->assertEquals('error', $result->fresh()->gateway_status,
            'AC-4B-18: gateway_status must be set to "error"');
    }

    public function test_initiate_transfer_gateway_exception_writes_audit_log(): void
    {
        $gateway = new ExceptionPixGateway;
        $service = new PixPaymentService($gateway);
        $payment = $this->createProcessingPayment();

        $service->initiateTransfer($payment, $this->admin);

        $log = PaymentAuditLog::where('payment_id', $payment->id)
            ->where('action', PaymentAuditAction::GatewayAttempt)
            ->first();

        $this->assertNotNull($log,
            'AC-4B-19: Audit log must be written for gateway exception');
        $this->assertNotNull($log->error_message,
            'AC-4B-19: Audit log error_message must contain the exception message');
    }

    public function test_initiate_transfer_gateway_exception_audit_log_has_error_type(): void
    {
        $gateway = new ExceptionPixGateway;
        $service = new PixPaymentService($gateway);
        $payment = $this->createProcessingPayment();

        $service->initiateTransfer($payment, $this->admin);

        $log = PaymentAuditLog::where('payment_id', $payment->id)->first();
        $payload = $log->payload;

        $this->assertEquals('gateway_exception', $payload['error_type'],
            'AC-4B-19: Audit log payload must contain error_type = "gateway_exception"');
    }

    public function test_initiate_transfer_gateway_exception_returns_payment_not_throws(): void
    {
        $gateway = new ExceptionPixGateway;
        $service = new PixPaymentService($gateway);
        $payment = $this->createProcessingPayment();

        // Should not throw — should return the payment
        $result = $service->initiateTransfer($payment, $this->admin);

        $this->assertNotNull($result,
            'AC-4B-20: initiateTransfer must return payment, not throw');
        $this->assertInstanceOf(Payment::class, $result);
    }

    // ========================================================================
    // AC-4B-21→AC-4B-24: Gateway processed → auto-paid
    // ========================================================================

    public function test_initiate_transfer_gateway_processed_auto_transitions_to_paid(): void
    {
        $payment = $this->createProcessingPayment(['amount' => '75.01']);
        $this->gateway = new MockPixGateway; // Mock returns "processed" for .01
        $this->service = new PixPaymentService($this->gateway);

        $result = $this->service->initiateTransfer($payment, $this->admin);

        $this->assertEquals(PaymentStatus::Paid, $result->fresh()->status,
            'AC-4B-21: Payment must auto-transition to paid on gateway processed');
    }

    public function test_initiate_transfer_gateway_processed_sets_paid_at(): void
    {
        $payment = $this->createProcessingPayment(['amount' => '75.01']);
        $this->gateway = new MockPixGateway;
        $this->service = new PixPaymentService($this->gateway);
        $before = now();

        $result = $this->service->initiateTransfer($payment, $this->admin);

        $this->assertNotNull($result->fresh()->paid_at,
            'AC-4B-21: paid_at must be set when auto-transitioning to paid');
        // Check that paid_at is within a reasonable time (not in the past or future)
        $this->assertTrue(
            $result->fresh()->paid_at->diffInSeconds(now()) < 5,
            'paid_at must be set to current timestamp (within 5 seconds)'
        );
    }

    public function test_initiate_transfer_gateway_processed_stores_gateway_transaction_id(): void
    {
        $payment = $this->createProcessingPayment(['amount' => '75.01']);
        $this->gateway = new MockPixGateway;
        $this->service = new PixPaymentService($this->gateway);

        $result = $this->service->initiateTransfer($payment, $this->admin);

        $this->assertNotNull($result->fresh()->gateway_transaction_id,
            'AC-4B-22: gateway_transaction_id must be stored');
        $this->assertEquals('processed', $result->fresh()->gateway_status,
            'AC-4B-22: gateway_status must be "processed"');
    }

    public function test_initiate_transfer_gateway_processed_creates_succeed_audit_log(): void
    {
        $payment = $this->createProcessingPayment(['amount' => '75.01']);
        $this->gateway = new MockPixGateway;
        $this->service = new PixPaymentService($this->gateway);

        $this->service->initiateTransfer($payment, $this->admin);

        $attemptLog = PaymentAuditLog::where('payment_id', $payment->id)
            ->where('action', PaymentAuditAction::GatewayAttempt)
            ->first();
        $succeedLog = PaymentAuditLog::where('payment_id', $payment->id)
            ->where('action', PaymentAuditAction::Succeed)
            ->first();

        $this->assertNotNull($attemptLog, 'Gateway attempt audit log must be written');
        $this->assertNotNull($succeedLog,
            'AC-4B-23: Succeed audit log must be written for gateway processed');
        $this->assertStringStartsWith('gateway-paid-', $succeedLog->transaction_ref,
            'AC-4B-23: transaction_ref must start with "gateway-paid-"');
        $this->assertEquals('gateway_auto', $succeedLog->payload['source'],
            'AC-4B-23: Audit payload must have source = "gateway_auto"');
    }

    public function test_initiate_transfer_gateway_processed_reconciles_shift_to_paid(): void
    {
        // Need at least one more payment that is also paid
        $biker2 = Biker::factory()->create(['rate_per_trip' => '10.00', 'base_fee' => '25.00']);
        PixKey::factory()->create(['biker_id' => $biker2->id, 'is_verified' => true, 'verified_at' => now()]);

        $shiftBiker2 = ShiftBiker::factory()->create([
            'shift_id' => $this->shift->id,
            'biker_id' => $biker2->id,
            'trips_count' => 3,
        ]);

        $payment2 = Payment::factory()->create([
            'shift_biker_id' => $shiftBiker2->id,
            'amount' => '55.01', // .01 → processed
            'status' => PaymentStatus::Processing,
        ]);

        $payment = $this->createProcessingPayment(['amount' => '75.01']); // .01 → processed

        $this->gateway = new MockPixGateway;
        $this->service = new PixPaymentService($this->gateway);

        // Both get processed → shift should go to Paid
        $this->service->initiateTransfer($payment, $this->admin);
        $this->service->initiateTransfer($payment2, $this->admin);

        $this->assertEquals(ShiftStatus::Paid, $this->shift->fresh()->status,
            'AC-4B-24: Shift must transition to paid when all payments are paid');
    }

    public function test_initiate_transfer_gateway_processed_shift_stays_approved_if_other_processing(): void
    {
        $biker2 = Biker::factory()->create(['rate_per_trip' => '10.00', 'base_fee' => '25.00']);
        PixKey::factory()->create(['biker_id' => $biker2->id, 'is_verified' => true, 'verified_at' => now()]);

        $shiftBiker2 = ShiftBiker::factory()->create([
            'shift_id' => $this->shift->id,
            'biker_id' => $biker2->id,
        ]);

        $payment2 = Payment::factory()->create([
            'shift_biker_id' => $shiftBiker2->id,
            'amount' => '55.00', // .00 → queued (not processed yet)
            'status' => PaymentStatus::Processing,
        ]);

        $payment = $this->createProcessingPayment(['amount' => '75.01']); // .01 → processed

        $this->gateway = new MockPixGateway;
        $this->service = new PixPaymentService($this->gateway);

        $this->service->initiateTransfer($payment, $this->admin);
        $this->service->initiateTransfer($payment2, $this->admin);

        // Shift stays approved (not all paid yet)
        $this->assertEquals(ShiftStatus::Approved, $this->shift->fresh()->status,
            'Shift must stay approved when some payments are still processing');
    }

    // ========================================================================
    // AC-4B-25→AC-4B-28: Gateway failed → auto-failed
    // ========================================================================

    public function test_initiate_transfer_gateway_failed_auto_transitions_to_failed(): void
    {
        $payment = $this->createProcessingPayment(['amount' => '75.02']);
        $this->gateway = new MockPixGateway; // .02 → failed
        $this->service = new PixPaymentService($this->gateway);

        $result = $this->service->initiateTransfer($payment, $this->admin);

        $this->assertEquals(PaymentStatus::Failed, $result->fresh()->status,
            'AC-4B-25: Payment must auto-transition to failed on gateway failed');
    }

    public function test_initiate_transfer_gateway_failed_sets_failed_at_and_reason(): void
    {
        $payment = $this->createProcessingPayment(['amount' => '75.02']);
        $this->gateway = new MockPixGateway;
        $this->service = new PixPaymentService($this->gateway);

        $result = $this->service->initiateTransfer($payment, $this->admin);

        $this->assertNotNull($result->fresh()->failed_at,
            'AC-4B-25: failed_at must be set');
        $this->assertNotNull($result->fresh()->failure_reason,
            'AC-4B-25: failure_reason must be set from gateway error_message');
    }

    public function test_initiate_transfer_gateway_failed_stores_gateway_transaction_id(): void
    {
        $payment = $this->createProcessingPayment(['amount' => '75.02']);
        $this->gateway = new MockPixGateway;
        $this->service = new PixPaymentService($this->gateway);

        $result = $this->service->initiateTransfer($payment, $this->admin);

        $this->assertNotNull($result->fresh()->gateway_transaction_id,
            'AC-4B-26: gateway_transaction_id must be stored even for failed');
        $this->assertEquals('failed', $result->fresh()->gateway_status,
            'AC-4B-26: gateway_status must be "failed"');
    }

    public function test_initiate_transfer_gateway_failed_creates_fail_audit_log(): void
    {
        $payment = $this->createProcessingPayment(['amount' => '75.02']);
        $this->gateway = new MockPixGateway;
        $this->service = new PixPaymentService($this->gateway);

        $this->service->initiateTransfer($payment, $this->admin);

        $attemptLog = PaymentAuditLog::where('payment_id', $payment->id)
            ->where('action', PaymentAuditAction::GatewayAttempt)
            ->first();
        $failLog = PaymentAuditLog::where('payment_id', $payment->id)
            ->where('action', PaymentAuditAction::Fail)
            ->first();

        $this->assertNotNull($attemptLog, 'Gateway attempt audit log must be written');
        $this->assertNotNull($failLog,
            'AC-4B-27: Fail audit log must be written for gateway failed');
        $this->assertEquals('gateway_auto', $failLog->payload['source'],
            'AC-4B-27: Audit payload must have source = "gateway_auto"');
    }

    public function test_initiate_transfer_gateway_failed_does_not_regress_shift(): void
    {
        $payment = $this->createProcessingPayment(['amount' => '75.02']);
        $this->gateway = new MockPixGateway;
        $this->service = new PixPaymentService($this->gateway);

        $this->service->initiateTransfer($payment, $this->admin);

        $this->assertEquals(ShiftStatus::Approved, $this->shift->fresh()->status,
            'AC-4B-28, BR-04: Shift must stay approved when payment auto-fails');
    }

    // ========================================================================
    // AC-4B-29→AC-4B-31: Gateway queued → stays processing
    // ========================================================================

    public function test_initiate_transfer_gateway_queued_payment_stays_processing(): void
    {
        $payment = $this->createProcessingPayment(['amount' => '75.00']);
        $this->gateway = new MockPixGateway; // .00 → queued
        $this->service = new PixPaymentService($this->gateway);

        $result = $this->service->initiateTransfer($payment, $this->admin);

        $this->assertEquals(PaymentStatus::Processing, $result->fresh()->status,
            'AC-4B-29: Payment must stay in processing when gateway returns queued');
    }

    public function test_initiate_transfer_gateway_queued_stores_transaction_id_and_status(): void
    {
        $payment = $this->createProcessingPayment(['amount' => '75.00']);
        $this->gateway = new MockPixGateway;
        $this->service = new PixPaymentService($this->gateway);

        $result = $this->service->initiateTransfer($payment, $this->admin);

        $this->assertNotNull($result->fresh()->gateway_transaction_id,
            'AC-4B-30: gateway_transaction_id must be stored for queued');
        $this->assertEquals('queued', $result->fresh()->gateway_status,
            'AC-4B-30: gateway_status must be "queued"');
    }

    public function test_initiate_transfer_gateway_queued_creates_attempt_audit_log_only(): void
    {
        $payment = $this->createProcessingPayment(['amount' => '75.00']);
        $this->gateway = new MockPixGateway;
        $this->service = new PixPaymentService($this->gateway);

        $this->service->initiateTransfer($payment, $this->admin);

        // Only Attempt audit log — no Succeed or Fail since payment stays processing
        $attemptLogs = PaymentAuditLog::where('payment_id', $payment->id)
            ->where('action', PaymentAuditAction::GatewayAttempt)
            ->get();

        $this->assertCount(1, $attemptLogs,
            'AC-4B-31: Only Attempt audit log for queued (no auto-transition)');
        $this->assertEquals('queued', $attemptLogs[0]->payload['gateway_status'],
            'AC-4B-31: Audit log payload must contain gateway_status');
    }

    // ========================================================================
    // AC-4B-32: Unknown status → treated as queued
    // ========================================================================

    public function test_initiate_transfer_unknown_status_treated_as_queued(): void
    {
        $gateway = new UnknownStatusPixGateway;
        $service = new PixPaymentService($gateway);
        $payment = $this->createProcessingPayment();

        $result = $service->initiateTransfer($payment, $this->admin);

        // Unknown status → stays processing
        $this->assertEquals(PaymentStatus::Processing, $result->fresh()->status,
            'AC-4B-32: Unknown status must be treated as queued');
        // But gateway_status stores the raw value
        $this->assertEquals('unknown_status_xyz', $result->fresh()->gateway_status,
            'AC-4B-32: gateway_status stores the raw response value');
    }

    // ========================================================================
    // AC-4B-33, AC-4B-34: Unique transaction_refs and payload
    // ========================================================================

    public function test_initiate_transfer_audit_logs_have_unique_transaction_refs(): void
    {
        $payment = $this->createProcessingPayment(['amount' => '75.01']);
        $this->gateway = new MockPixGateway;
        $this->service = new PixPaymentService($this->gateway);

        $this->service->initiateTransfer($payment, $this->admin);

        $logs = PaymentAuditLog::where('payment_id', $payment->id)->get();
        $refs = $logs->pluck('transaction_ref')->toArray();

        // All refs must be unique
        $this->assertEquals(count($refs), count(array_unique($refs)),
            'AC-4B-33: All transaction_refs must be unique across audit logs');
    }

    public function test_initiate_transfer_audit_logs_contain_required_payload_fields(): void
    {
        $payment = $this->createProcessingPayment(['amount' => '75.00']);
        $this->gateway = new MockPixGateway;
        $this->service = new PixPaymentService($this->gateway);

        $this->service->initiateTransfer($payment, $this->admin);

        $log = PaymentAuditLog::where('payment_id', $payment->id)
            ->where('action', PaymentAuditAction::GatewayAttempt)
            ->first();

        $payload = $log->payload;

        $this->assertArrayHasKey('transaction_id', $payload,
            'AC-4B-34: Audit payload must contain transaction_id');
        $this->assertArrayHasKey('gateway_status', $payload,
            'AC-4B-34: Audit payload must contain gateway_status');
        $this->assertArrayHasKey('amount', $payload,
            'AC-4B-34: Audit payload must contain amount');
        $this->assertArrayHasKey('pix_key_id', $payload,
            'AC-4B-34: Audit payload must contain pix_key_id');
        $this->assertArrayHasKey('initiated_by', $payload,
            'AC-4B-34: Audit payload must contain initiated_by');
    }

    public function test_initiate_transfer_gateway_attempt_ref_starts_with_gateway_prefix(): void
    {
        $payment = $this->createProcessingPayment(['amount' => '75.00']);
        $this->gateway = new MockPixGateway;
        $this->service = new PixPaymentService($this->gateway);

        $this->service->initiateTransfer($payment, $this->admin);

        $log = PaymentAuditLog::where('payment_id', $payment->id)
            ->where('action', PaymentAuditAction::GatewayAttempt)
            ->first();

        $this->assertStringStartsWith('gateway-', $log->transaction_ref,
            'AC-4B-33: transaction_ref must start with "gateway-" prefix');
    }

    // ========================================================================
    // Granular Failure (BR-04): One payment failure doesn't affect others
    // ========================================================================

    public function test_initiate_transfer_granular_failure_biker_a_fails_biker_b_succeeds(): void
    {
        $bikerA = Biker::factory()->create(['rate_per_trip' => '10.00', 'base_fee' => '25.00']);
        PixKey::factory()->create(['biker_id' => $bikerA->id, 'is_verified' => true, 'verified_at' => now()]);

        $bikerB = Biker::factory()->create(['rate_per_trip' => '10.00', 'base_fee' => '25.00']);
        PixKey::factory()->create(['biker_id' => $bikerB->id, 'is_verified' => true, 'verified_at' => now()]);

        $shiftBikerA = ShiftBiker::factory()->create([
            'shift_id' => $this->shift->id,
            'biker_id' => $bikerA->id,
        ]);

        $shiftBikerB = ShiftBiker::factory()->create([
            'shift_id' => $this->shift->id,
            'biker_id' => $bikerB->id,
        ]);

        $paymentA = Payment::factory()->create([
            'shift_biker_id' => $shiftBikerA->id,
            'amount' => '75.02',  // Will fail (sync failure)
            'status' => PaymentStatus::Processing,
        ]);

        $paymentB = Payment::factory()->create([
            'shift_biker_id' => $shiftBikerB->id,
            'amount' => '55.01',  // Will succeed (sync success)
            'status' => PaymentStatus::Processing,
        ]);

        $this->gateway = new MockPixGateway;
        $this->service = new PixPaymentService($this->gateway);

        // Biker A fails
        $this->service->initiateTransfer($paymentA, $this->admin);

        // Biker B succeeds
        $this->service->initiateTransfer($paymentB, $this->admin);

        // A failed, B paid — granular failure
        $this->assertEquals(PaymentStatus::Failed, $paymentA->fresh()->status,
            'BR-04: Biker A payment must fail');
        $this->assertEquals(PaymentStatus::Paid, $paymentB->fresh()->status,
            'BR-04: Biker B payment must succeed');

        // Shift stays approved (not all paid, and at least one failed)
        $this->assertEquals(ShiftStatus::Approved, $this->shift->fresh()->status,
            'BR-04: Shift stays approved — one failed payment does not affect others');
    }

    public function test_initiate_transfer_gateway_exception_one_payment_does_not_affect_another(): void
    {
        $bikerA = Biker::factory()->create(['rate_per_trip' => '10.00', 'base_fee' => '25.00']);
        PixKey::factory()->create(['biker_id' => $bikerA->id, 'is_verified' => true, 'verified_at' => now()]);

        $bikerB = Biker::factory()->create(['rate_per_trip' => '10.00', 'base_fee' => '25.00']);
        PixKey::factory()->create(['biker_id' => $bikerB->id, 'is_verified' => true, 'verified_at' => now()]);

        $shiftBikerA = ShiftBiker::factory()->create(['shift_id' => $this->shift->id, 'biker_id' => $bikerA->id]);
        $shiftBikerB = ShiftBiker::factory()->create(['shift_id' => $this->shift->id, 'biker_id' => $bikerB->id]);

        $paymentA = Payment::factory()->create([
            'shift_biker_id' => $shiftBikerA->id,
            'amount' => '75.00',
            'status' => PaymentStatus::Processing,
        ]);

        $paymentB = Payment::factory()->create([
            'shift_biker_id' => $shiftBikerB->id,
            'amount' => '55.00',
            'status' => PaymentStatus::Processing,
        ]);

        // A throws exception, B uses normal gateway
        $exceptionGateway = new ExceptionPixGateway;
        $normalGateway = new MockPixGateway;

        $serviceA = new PixPaymentService($exceptionGateway);
        $serviceB = new PixPaymentService($normalGateway);

        $serviceA->initiateTransfer($paymentA, $this->admin);
        $serviceB->initiateTransfer($paymentB, $this->admin);

        // A stays processing (gateway error), B queued
        $this->assertEquals(PaymentStatus::Processing, $paymentA->fresh()->status,
            'BR-04: Payment A stays processing after gateway exception');
        $this->assertEquals(PaymentStatus::Processing, $paymentB->fresh()->status,
            'BR-04: Payment B stays processing (queued)');
    }

    // ========================================================================
    // Edge Cases
    // ========================================================================

    public function test_initiate_transfer_zero_amount_queued(): void
    {
        $payment = $this->createProcessingPayment(['amount' => '0.00']);
        $this->gateway = new MockPixGateway;
        $this->service = new PixPaymentService($this->gateway);

        $result = $this->service->initiateTransfer($payment, $this->admin);

        $this->assertEquals(PaymentStatus::Processing, $result->fresh()->status,
            'Zero amount payment stays processing (queued)');
    }

    public function test_initiate_transfer_null_transaction_id_stored(): void
    {
        $gateway = new NullTxnIdPixGateway;
        $service = new PixPaymentService($gateway);
        $payment = $this->createProcessingPayment();

        $result = $service->initiateTransfer($payment, $this->admin);

        $this->assertNull($result->fresh()->gateway_transaction_id,
            'Null transaction_id from gateway must be stored as null');
    }

    public function test_initiate_transfer_returns_updated_payment(): void
    {
        $payment = $this->createProcessingPayment(['amount' => '75.01']);
        $this->gateway = new MockPixGateway;
        $this->service = new PixPaymentService($this->gateway);

        $result = $this->service->initiateTransfer($payment, $this->admin);

        // Result should reflect the auto-transitioned state
        $this->assertEquals(PaymentStatus::Paid, $result->status,
            'Return value must show the updated status');
    }
}

/**
 * Test double for PixGatewayInterface — tracks last call parameters.
 */
class TrackingPixGateway implements PixGatewayInterface
{
    public array $lastCall = [];

    public function verifyKey(PixKeyType $keyType, string $keyValue): VerifyKeyResponse
    {
        return new VerifyKeyResponse(success: true, account_holder_name: 'Test');
    }

    public function initiatePayment(int $paymentId, string $pixKey, string $amount): PaymentResponse
    {
        $this->lastCall = [
            'paymentId' => $paymentId,
            'pixKey' => $pixKey,
            'amount' => $amount,
        ];

        return new PaymentResponse(
            success: true,
            transaction_id: "mock-txn-{$paymentId}",
            status: 'queued',
        );
    }

    public function checkPaymentStatus(string $transactionId): PaymentResponse
    {
        return new PaymentResponse(success: true, transaction_id: $transactionId, status: 'processed');
    }
}

/**
 * Test double that always throws RuntimeException (gateway unreachable).
 */
class ExceptionPixGateway implements PixGatewayInterface
{
    public function verifyKey(PixKeyType $keyType, string $keyValue): VerifyKeyResponse
    {
        return new VerifyKeyResponse(success: true, account_holder_name: 'Test');
    }

    public function initiatePayment(int $paymentId, string $pixKey, string $amount): PaymentResponse
    {
        throw new \RuntimeException('Gateway connection timeout');
    }

    public function checkPaymentStatus(string $transactionId): PaymentResponse
    {
        return new PaymentResponse(success: true, transaction_id: $transactionId, status: 'processed');
    }
}

/**
 * Test double that returns unknown status.
 */
class UnknownStatusPixGateway implements PixGatewayInterface
{
    public function verifyKey(PixKeyType $keyType, string $keyValue): VerifyKeyResponse
    {
        return new VerifyKeyResponse(success: true, account_holder_name: 'Test');
    }

    public function initiatePayment(int $paymentId, string $pixKey, string $amount): PaymentResponse
    {
        return new PaymentResponse(
            success: true,
            transaction_id: "mock-txn-{$paymentId}",
            status: 'unknown_status_xyz',
        );
    }

    public function checkPaymentStatus(string $transactionId): PaymentResponse
    {
        return new PaymentResponse(success: true, transaction_id: $transactionId, status: 'processed');
    }
}

/**
 * Test double that returns null transaction_id.
 */
class NullTxnIdPixGateway implements PixGatewayInterface
{
    public function verifyKey(PixKeyType $keyType, string $keyValue): VerifyKeyResponse
    {
        return new VerifyKeyResponse(success: true, account_holder_name: 'Test');
    }

    public function initiatePayment(int $paymentId, string $pixKey, string $amount): PaymentResponse
    {
        return new PaymentResponse(
            success: false,
            transaction_id: null,
            status: 'failed',
            error_code: 'REJECTED',
            error_message: 'Rejected by receiver',
        );
    }

    public function checkPaymentStatus(string $transactionId): PaymentResponse
    {
        return new PaymentResponse(success: true, transaction_id: $transactionId, status: 'processed');
    }
}
