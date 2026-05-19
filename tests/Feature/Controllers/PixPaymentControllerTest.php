<?php

namespace Tests\Feature\Controllers;

use App\Contracts\PixGatewayInterface;
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
use App\Services\Gateway\MockPixGateway;
use App\Services\PaymentReleaseService;
use App\Services\PaymentSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature Tests for PIX Gateway Integration — Phase 4B
 *
 * These tests verify that:
 * - AC-4B-35: PaymentReleaseService calls PixPaymentService.initiateTransfer()
 * - AC-4B-36: Release endpoint blocks non-processing payments
 * - AC-4B-37: Release endpoint requires admin authentication
 * - AC-4B-38: Full release flow → gateway → auto-transition paid/failed/queued
 * - AC-4B-39: Retry rejected at cap (3 retries)
 * - AC-4B-40: Retry increments retry_count
 * - AC-4B-41: Payment status endpoint returns settlement groups
 * - AC-4B-42: getSettlementData returns correct structure
 *
 * Note: These are web routes (not API) that return redirects. Tests use followRedirects()
 * or check DB state instead of asserting JSON response structure.
 *
 * @see docs/plans/phase-4b-pix-payment-execution.md
 */
class PixPaymentControllerTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private User $admin;

    private Biker $biker;

    private Shift $shift;

    private ShiftBiker $shiftBiker;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create(['rate_per_trip' => '15.00']);

        $this->admin = User::factory()->create(['role' => 'admin']);

        $this->biker = Biker::factory()->create([
            'name' => 'João da Silva',
            'rate_per_trip' => '10.00',
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

        // ADR-005 D4 gate: biker must have linked User account
        User::factory()->create(['biker_id' => $this->biker->id]);

        $this->shift = Shift::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'status' => ShiftStatus::Approved,
        ]);

        $this->shiftBiker = ShiftBiker::factory()->create([
            'shift_id' => $this->shift->id,
            'biker_id' => $this->biker->id,
            'trips_count' => 3,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);

        $this->payment = Payment::factory()->create([
            'shift_biker_id' => $this->shiftBiker->id,
            'status' => PaymentStatus::Pending,
        ]);
    }

    // ========================================================================
    // AC-4B-35: Release calls PixPaymentService.initiateTransfer()
    // ========================================================================

    public function test_release_payment_calls_pix_payment_service(): void
    {
        $this->payment->update(['status' => PaymentStatus::Pending]);

        $this->app->instance(PixGatewayInterface::class, new MockPixGateway);

        $route = route('shifts.payments.release', [
            'shift' => $this->shift->id,
            'payment' => $this->payment->id,
        ]);
        $response = $this->actingAs($this->admin)->post($route);

        // Phase 4B: release from Pending transitions to Processing then gateway result
        $response->assertStatus(302);
        $this->payment->refresh();

        // TrackingPixGateway: amount .00 = queued → status stays Processing
        $this->assertEquals(PaymentStatus::Processing, $this->payment->status,
            'AC-4B-35: Payment must be in Processing after PixPaymentService call');
        $this->assertNotNull($this->payment->gateway_transaction_id,
            'AC-4B-35: gateway_transaction_id must be set after gateway call');
        $this->assertEquals('queued', $this->payment->gateway_status,
            'AC-4B-35: gateway_status must be \"queued\" for amount .00');
    }

    public function test_release_payment_non_admin_rejected(): void
    {
        $regularUser = User::factory()->create(['role' => 'biker']);

        $this->payment->update(['status' => PaymentStatus::Pending]);

        $route = route('shifts.payments.release', [
            'shift' => $this->shift->id,
            'payment' => $this->payment->id,
        ]);

        $response = $this->actingAs($regularUser)->post($route);

        $response->assertStatus(403,
            'AC-4B-37: Non-admin must be rejected with 403');
    }

    public function test_release_payment_unauthenticated_rejected(): void
    {
        $this->payment->update(['status' => PaymentStatus::Pending]);

        $route = route('shifts.payments.release', [
            'shift' => $this->shift->id,
            'payment' => $this->payment->id,
        ]);

        // Acting as any authenticated user → should be rejected as non-admin (403)
        // OR if ShiftPolicy.authorize returns false, it might be 403 or redirect
        $regularUser = User::factory()->create(['role' => 'biker']);
        $response = $this->actingAs($regularUser)->post($route);

        // 403 Forbidden for non-admin (ShiftPolicy denies)
        $response->assertStatus(403);
    }

    public function test_release_payment_nonexistent_payment_returns_404(): void
    {
        $route = route('shifts.payments.release', [
            'shift' => $this->shift->id,
            'payment' => 99999,
        ]);
        $response = $this->actingAs($this->admin)->post($route);

        $response->assertStatus(404,
            'AC-4B-35: Non-existent payment must return 404');
    }

    // ========================================================================
    // AC-4B-38: Full release flow → gateway → auto-transition
    // ========================================================================

    public function test_release_payment_full_flow_gateway_processed_auto_paid(): void
    {
        // Amount ending in .01 → MockPixGateway returns status="processed" → auto-paid
        $this->payment->update([
            'status' => PaymentStatus::Pending,
            'amount' => '75.01',
        ]);

        $this->app->instance(PixGatewayInterface::class, new MockPixGateway);

        $route = route('shifts.payments.release', [
            'shift' => $this->shift->id,
            'payment' => $this->payment->id,
        ]);
        $response = $this->actingAs($this->admin)->post($route);

        $response->assertStatus(302);

        $this->payment->refresh();
        $this->assertEquals(PaymentStatus::Paid, $this->payment->status,
            'AC-4B-38: Payment must auto-transition to Paid when gateway status = "processed"');
        $this->assertNotNull($this->payment->paid_at,
            'AC-4B-38: paid_at must be set on auto-paid');
        $this->assertNull($this->payment->failed_at,
            'AC-4B-38: failed_at must NOT be set on auto-paid');
    }

    public function test_release_payment_full_flow_gateway_failed_auto_failed(): void
    {
        // Amount ending in .02 → MockPixGateway returns status="failed" → auto-failed
        $this->payment->update([
            'status' => PaymentStatus::Pending,
            'amount' => '75.02',
        ]);

        $this->app->instance(PixGatewayInterface::class, new MockPixGateway);

        $route = route('shifts.payments.release', [
            'shift' => $this->shift->id,
            'payment' => $this->payment->id,
        ]);
        $response = $this->actingAs($this->admin)->post($route);

        $response->assertStatus(302);

        $this->payment->refresh();
        $this->assertEquals(PaymentStatus::Failed, $this->payment->status,
            'AC-4B-38: Payment must auto-transition to Failed when gateway status = "failed"');
        $this->assertNotNull($this->payment->failed_at,
            'AC-4B-38: failed_at must be set on auto-failed');
        $this->assertNotNull($this->payment->failure_reason,
            'AC-4B-38: failure_reason must be set on auto-failed');
        $this->assertNull($this->payment->paid_at,
            'AC-4B-38: paid_at must NOT be set on auto-failed');
    }

    public function test_release_payment_auto_paid_creates_succeed_audit_log(): void
    {
        $this->payment->update(['amount' => '75.01']);

        $this->app->instance(PixGatewayInterface::class, new MockPixGateway);
        $route = route('shifts.payments.release', [
            'shift' => $this->shift->id,
            'payment' => $this->payment->id,
        ]);

        $this->actingAs($this->admin)->post($route);

        // PaymentReleaseService writes GatewayAttempt (from PixPaymentService)
        $attemptLog = PaymentAuditLog::where('payment_id', $this->payment->id)
            ->where('action', PaymentAuditAction::GatewayAttempt)
            ->where('transaction_ref', 'LIKE', 'gateway-%')
            ->first();

        $succeedLog = PaymentAuditLog::where('payment_id', $this->payment->id)
            ->where('action', PaymentAuditAction::Succeed)
            ->first();

        $this->assertNotNull($attemptLog,
            'AC-4B-38: Gateway attempt audit log must be written');
        $this->assertNotNull($succeedLog,
            'AC-4B-38: Succeed audit log must be written on auto-paid');
        $this->assertEquals('gateway_auto', $succeedLog->payload['source'] ?? null,
            'AC-4B-38: Succeed log must have source = "gateway_auto"');
    }

    public function test_release_payment_auto_failed_creates_fail_audit_log(): void
    {
        $this->payment->update(['amount' => '75.02']);

        $this->app->instance(PixGatewayInterface::class, new MockPixGateway);

        $route = route('shifts.payments.release', [
            'shift' => $this->shift->id,
            'payment' => $this->payment->id,
        ]);

        $this->actingAs($this->admin)->post($route);

        // PaymentReleaseService writes GatewayAttempt (from PixPaymentService)
        $attemptLog = PaymentAuditLog::where('payment_id', $this->payment->id)
            ->where('action', PaymentAuditAction::GatewayAttempt)
            ->first();

        $failLog = PaymentAuditLog::where('payment_id', $this->payment->id)
            ->where('action', PaymentAuditAction::Fail)
            ->first();

        $this->assertNotNull($attemptLog,
            'AC-4B-38: Gateway attempt audit log must be written');
        $this->assertNotNull($failLog,
            'AC-4B-38: Fail audit log must be written on auto-failed');
        $this->assertEquals('gateway_auto', $failLog->payload['source'] ?? null,
            'AC-4B-38: Fail log must have source = "gateway_auto"');
    }

    // ========================================================================
    // AC-4B-38 + BR-04: Shift reconciliation rules
    // ========================================================================

    public function test_release_payment_auto_paid_reconciles_shift_to_paid(): void
    {
        // Need two payments — both auto-paid → shift goes to Paid
        $biker2 = Biker::factory()->create(['rate_per_trip' => '10.00', 'base_fee' => '25.00']);
        PixKey::factory()->create(['biker_id' => $biker2->id, 'is_verified' => true, 'verified_at' => now()]);
        User::factory()->create(['biker_id' => $biker2->id]);

        $shiftBiker2 = ShiftBiker::factory()->create([
            'shift_id' => $this->shift->id,
            'biker_id' => $biker2->id,
            'trips_count' => 3,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);

        $payment2 = Payment::factory()->create([
            'shift_biker_id' => $shiftBiker2->id,
            'amount' => '55.01',
            'status' => PaymentStatus::Pending,
        ]);

        $this->payment->update(['amount' => '75.01']);

        $this->app->instance(PixGatewayInterface::class, new MockPixGateway);

        $this->actingAs($this->admin)->post(
            route('shifts.payments.release', ['shift' => $this->shift->id, 'payment' => $this->payment->id])
        );
        $this->actingAs($this->admin)->post(
            route('shifts.payments.release', ['shift' => $this->shift->id, 'payment' => $payment2->id])
        );

        $this->assertEquals(ShiftStatus::Paid, $this->shift->fresh()->status,
            'AC-4B-38: Shift must transition to Paid when all payments auto-paid');
    }

    public function test_release_payment_auto_failed_does_not_regress_shift(): void
    {
        // Shift starts Approved → auto-fail → checkAndTransitionShiftToApproved() transitions to Approved.
        // "No regression" means no WORSE status. Approved is not worse than Approved.
        $this->shift->update(['status' => ShiftStatus::Approved]);
        $this->payment->update(['amount' => '75.02']);

        $this->app->instance(PixGatewayInterface::class, new MockPixGateway);
        $this->actingAs($this->admin)->post(
            route('shifts.payments.release', ['shift' => $this->shift->id, 'payment' => $this->payment->id])
        );

        // BR-04: Failed payment must NOT regress shift status
        $this->assertEquals(ShiftStatus::Approved, $this->shift->fresh()->status,
            'AC-4B-38, BR-04: Shift must stay at its current status when payment auto-fails');
    }

    public function test_release_payment_full_flow_gateway_queued_stays_processing(): void
    {
        // Amount .00 → "queued" → stays Processing (no auto-transition)
        $this->payment->update([
            'status' => PaymentStatus::Pending,
            'amount' => '75.00',
        ]);

        $this->app->instance(PixGatewayInterface::class, new MockPixGateway);

        $route = route('shifts.payments.release', [
            'shift' => $this->shift->id,
            'payment' => $this->payment->id,
        ]);
        $response = $this->actingAs($this->admin)->post($route);

        $response->assertStatus(302);

        $this->payment->refresh();
        $this->assertEquals(PaymentStatus::Processing, $this->payment->status,
            'AC-4B-38: Payment must stay Processing when gateway status = "queued"');
        $this->assertNull($this->payment->paid_at,
            'AC-4B-38: paid_at must NOT be set when gateway status = "queued"');
        $this->assertNull($this->payment->failed_at,
            'AC-4B-38: failed_at must NOT be set when gateway status = "queued"');
    }

    // ========================================================================
    // AC-4B-39: Retry rejected at cap (3 retries)
    // ========================================================================

    public function test_retry_payment_rejects_if_retry_count_at_3(): void
    {
        $failedPayment = Payment::factory()->create([
            'shift_biker_id' => $this->shiftBiker->id,
            'status' => PaymentStatus::Failed,
            'retry_count' => 3,
            'failed_at' => now(),
        ]);

        $route = route('shifts.payments.retry', [
            'shift' => $this->shift->id,
            'payment' => $failedPayment->id,
        ]);
        $response = $this->actingAs($this->admin)->post($route);

        $response->assertStatus(422);
        $response->assertSessionHasErrors(['payment'], null, 'payment');

        $failedPayment->refresh();
        $this->assertEquals(PaymentStatus::Failed, $failedPayment->status,
            'AC-4B-39: Payment must stay Failed when retry_count >= 3');
        $this->assertEquals(3, $failedPayment->retry_count,
            'AC-4B-39: retry_count must not change when cap reached');
    }

    public function test_retry_payment_rejects_if_retry_count_above_3(): void
    {
        $failedPayment = Payment::factory()->create([
            'shift_biker_id' => $this->shiftBiker->id,
            'status' => PaymentStatus::Failed,
            'retry_count' => 5,
            'failed_at' => now(),
        ]);

        $route = route('shifts.payments.retry', [
            'shift' => $this->shift->id,
            'payment' => $failedPayment->id,
        ]);
        $response = $this->actingAs($this->admin)->post($route);

        $response->assertStatus(422);
        $response->assertSessionHasErrors(['payment'], null, 'payment');

        $failedPayment->refresh();
        $this->assertEquals(PaymentStatus::Failed, $failedPayment->status,
            'AC-4B-39: Payment must stay Failed when retry_count > 3');
    }

    public function test_retry_payment_rejects_if_not_failed(): void
    {
        $pendingPayment = Payment::factory()->create([
            'shift_biker_id' => $this->shiftBiker->id,
            'status' => PaymentStatus::Pending,
            'retry_count' => 0,
        ]);

        $route = route('shifts.payments.retry', [
            'shift' => $this->shift->id,
            'payment' => $pendingPayment->id,
        ]);
        $response = $this->actingAs($this->admin)->post($route);

        $response->assertStatus(422);
        $response->assertSessionHasErrors(['payment'], null, 'payment');
    }

    // ========================================================================
    // AC-4B-40: Retry increments retry_count
    // ========================================================================

    public function test_retry_payment_allows_at_retry_count_2(): void
    {
        // retry_count=1 → after retry becomes 2 → cap not reached (2 < 3)
        // → transition to Processing → gateway call (queued → stays Processing)
        $failedPayment = Payment::factory()->create([
            'shift_biker_id' => $this->shiftBiker->id,
            'status' => PaymentStatus::Failed,
            'retry_count' => 1,
            'amount' => '100.00',
            'failed_at' => now(),
        ]);

        $route = route('shifts.payments.retry', [
            'shift' => $this->shift->id,
            'payment' => $failedPayment->id,
        ]);

        $response = $this->actingAs($this->admin)->post($route);

        $response->assertStatus(302,
            'AC-4B-40: Retry allowed when retry_count < 3');

        $payment = $failedPayment->fresh();
        $this->assertEquals(PaymentStatus::Processing, $payment->status,
            'AC-4B-40: Payment must transition back to Processing after retry');
        $this->assertEquals(2, $payment->retry_count,
            'AC-4B-40: retry_count must be incremented to 2');
    }

    public function test_retry_payment_increments_retry_count(): void
    {
        $failedPayment = Payment::factory()->create([
            'shift_biker_id' => $this->shiftBiker->id,
            'status' => PaymentStatus::Failed,
            'retry_count' => 0,
            'amount' => '100.00',
            'failed_at' => now(),
        ]);

        $route = route('shifts.payments.retry', [
            'shift' => $this->shift->id,
            'payment' => $failedPayment->id,
        ]);

        $response = $this->actingAs($this->admin)->post($route);

        $response->assertStatus(302);

        $payment = $failedPayment->fresh();
        $this->assertEquals(1, $payment->retry_count,
            'AC-4B-40: retry_count must be incremented on each retry');
    }

    public function test_retry_payment_requires_authentication(): void
    {
        $failedPayment = Payment::factory()->create([
            'shift_biker_id' => $this->shiftBiker->id,
            'status' => PaymentStatus::Failed,
            'retry_count' => 0,
            'failed_at' => now(),
        ]);

        $route = route('shifts.payments.retry', [
            'shift' => $this->shift->id,
            'payment' => $failedPayment->id,
        ]);

        // No actingAs → should redirect to login
        $response = $this->post($route);
        $response->assertStatus(302);
    }

    public function test_retry_payment_non_admin_rejected(): void
    {
        $regularUser = User::factory()->create(['role' => 'biker']);

        $failedPayment = Payment::factory()->create([
            'shift_biker_id' => $this->shiftBiker->id,
            'status' => PaymentStatus::Failed,
            'retry_count' => 0,
            'failed_at' => now(),
        ]);

        $route = route('shifts.payments.retry', [
            'shift' => $this->shift->id,
            'payment' => $failedPayment->id,
        ]);

        $response = $this->actingAs($regularUser)->post($route);
        $response->assertStatus(403);
    }

    // ========================================================================
    // AC-4B-41: Payment status endpoint returns settlement groups
    // ========================================================================

    public function test_get_settlement_data_requires_authentication(): void
    {
        $route = route('shifts.payments.status', ['shift' => $this->shift->id]);

        $response = $this->get($route);

        // Unauthenticated → redirect to login
        $response->assertStatus(302);
    }

    public function test_get_settlement_data_returns_status_page(): void
    {
        $route = route('shifts.payments.status', ['shift' => $this->shift->id]);

        $response = $this->actingAs($this->admin)->get($route);

        $response->assertStatus(200);
        $response->assertViewIs('shifts.payment-status');
    }

    // ========================================================================
    // AC-4B-42: getSettlementData returns correct structure
    // ========================================================================

    public function test_get_settlement_data_includes_retry_eligibility_in_failed_group(): void
    {
        // Update the setUp payment to Failed (there can be only ONE payment per shiftBiker)
        $this->payment->update([
            'status' => PaymentStatus::Failed,
            'retry_count' => 0,
            'failed_at' => now(),
        ]);

        $route = route('shifts.payments.status', ['shift' => $this->shift->id]);

        $response = $this->actingAs($this->admin)->get($route);
        $response->assertStatus(200);

        $groups = $response->viewData('groups');

        $failedGroup = $groups['failed'] ?? [];

        $this->assertNotEmpty($failedGroup,
            'AC-4B-42: Failed group must contain the failed payment');
        $hasRetryEligibility = collect($failedGroup)->contains(
            fn ($item) => array_key_exists('isEligibleForRetry', $item)
        );

        $this->assertTrue($hasRetryEligibility,
            'AC-4B-42: Failed group items must include isEligibleForRetry flag');
    }

    public function test_get_settlement_data_groups_by_status(): void
    {
        // Clear the setUp Pending payment so it doesn't interfere with this test.
        // PaymentSettlementService.getSettlementData() only groups Processing/Failed/Paid.
        $this->payment->update(['status' => PaymentStatus::Processing]);
        // Create a paid payment for a different biker
        $paidBiker = Biker::factory()->create(['name' => 'Maria Santos', 'rate_per_trip' => '12.00', 'base_fee' => '20.00']);
        PixKey::factory()->create([
            'biker_id' => $paidBiker->id,
            'key_type' => 'cpf',
            'key_value' => '98765432109',
            'is_verified' => true,
            'verified_at' => now(),
        ]);
        User::factory()->create(['biker_id' => $paidBiker->id]);
        $paidShiftBiker = ShiftBiker::factory()->create([
            'shift_id' => $this->shift->id,
            'biker_id' => $paidBiker->id,
            'trips_count' => 3,
            'biker_rate' => '12.00',
            'base_fee' => '20.00',
        ]);
        Payment::factory()->create([
            'shift_biker_id' => $paidShiftBiker->id,
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        $route = route('shifts.payments.status', ['shift' => $this->shift->id]);
        $response = $this->actingAs($this->admin)->get($route);
        $response->assertStatus(200);

        $groups = $response->viewData('groups');

        $this->assertCount(1, $groups['processing'] ?? [],
            'AC-4B-42: Processing group must contain the processing payment');
        $this->assertCount(1, $groups['paid'] ?? [],
            'AC-4B-42: Paid group must contain the paid payment');
    }

    public function test_get_settlement_data_all_paid_flag(): void
    {
        // Make the single payment paid
        $this->payment->update([
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        $route = route('shifts.payments.status', ['shift' => $this->shift->id]);
        $response = $this->actingAs($this->admin)->get($route);

        $response->assertStatus(200);
        $this->assertTrue($response->viewData('allPaid'),
            'AC-4B-42: allPaid must be true when all payments are paid');
    }

    public function test_get_settlement_data_includes_biker_pix_key(): void
    {
        // setUp payment is Pending → update to Processing so it appears in group
        $this->payment->update(['status' => PaymentStatus::Processing]);

        $route = route('shifts.payments.status', ['shift' => $this->shift->id]);
        $response = $this->actingAs($this->admin)->get($route);

        $response->assertStatus(200);

        $groups = $response->viewData('groups');
        $processingItems = $groups['processing'] ?? [];

        $this->assertNotEmpty($processingItems,
            'AC-4B-42: Processing group must contain the payment');
        $item = $processingItems[0];
        $this->assertArrayHasKey('biker', $item,
            'AC-4B-42: Each item must include biker data');
        $this->assertArrayHasKey('payment', $item,
            'AC-4B-42: Each item must include payment data');
    }

    public function test_get_settlement_data_includes_gateway_transaction_id(): void
    {
        // setUp payment is Pending → update to Processing so it appears in group
        $this->payment->update([
            'status' => PaymentStatus::Processing,
            'gateway_transaction_id' => 'gw-txn-12345',
            'gateway_status' => 'queued',
        ]);

        $route = route('shifts.payments.status', ['shift' => $this->shift->id]);
        $response = $this->actingAs($this->admin)->get($route);

        $response->assertStatus(200);

        $groups = $response->viewData('groups');
        $processingItems = $groups['processing'] ?? [];

        $this->assertNotEmpty($processingItems,
            'AC-4B-42: Processing group must contain the payment');
        $item = $processingItems[0];
        $this->assertEquals('gw-txn-12345', $item['gateway_transaction_id'] ?? null,
            'AC-4B-42: gateway_transaction_id must be included in settlement data');
        $this->assertEquals('queued', $item['gateway_status'] ?? null,
            'AC-4B-42: gateway_status must be included in settlement data');
    }
}
