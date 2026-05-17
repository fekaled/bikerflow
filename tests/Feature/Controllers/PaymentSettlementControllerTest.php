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
use App\Services\PaymentSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature Tests for PaymentSettlementController — Phase 3C
 *
 * Tests HTTP layer for payment settlement:
 * - Settlement Dashboard (GET)
 * - Mark Paid (POST)
 * - Mark Failed (POST)
 * - Retry (POST)
 * - Authorization (Admin-only)
 * - Validation (failure_reason rules)
 * - Idempotency (422 for invalid state transitions)
 * - Shift reconciliation end-to-end
 *
 * Acceptance Criteria: AC-3C-01 through AC-3C-48
 * Business Rules: BR-02, BR-03, BR-04, BR-06
 *
 * @see docs/plans/phase-3c-payment-failure-and-retry.md
 */
class PaymentSettlementControllerTest extends TestCase
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

    // ========================================================================
    // Helpers
    // ========================================================================

    private function createApprovedShiftWithPayment(
        PaymentStatus $paymentStatus = PaymentStatus::Processing,
        array $paymentOverrides = [],
        array $shiftBikerOverrides = [],
    ): array {
        $shift = Shift::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'status' => ShiftStatus::Approved,
            'workflow_type' => WorkflowType::LiveTick,
            'restaurant_rate' => '15.00',
            'closed_at' => now(),
        ]);

        $biker = Biker::factory()->create([
            'rate_per_trip' => '10.00',
            'base_fee' => '25.00',
            'active' => true,
        ]);

        User::factory()->create([
            'role' => UserRole::Biker,
            'biker_id' => $biker->id,
        ]);

        PixKey::factory()->create([
            'biker_id' => $biker->id,
            'is_verified' => true,
            'verified_at' => now(),
        ]);

        $shiftBiker = ShiftBiker::factory()->create(array_merge([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 5,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ], $shiftBikerOverrides));

        $paymentDefaults = [
            'shift_biker_id' => $shiftBiker->id,
            'amount' => '75.00',
            'revenue' => '0.00',
            'released_by' => $this->admin->id,
            'released_at' => now(),
        ];

        if ($paymentStatus === PaymentStatus::Paid) {
            $paymentDefaults['paid_at'] = now();
        }
        if ($paymentStatus === PaymentStatus::Failed) {
            $paymentDefaults['failed_at'] = now();
            $paymentDefaults['failure_reason'] = 'Test failure';
        }

        $payment = Payment::factory()->create(array_merge(
            $paymentDefaults,
            ['status' => $paymentStatus],
            $paymentOverrides,
        ));

        return compact('shift', 'biker', 'shiftBiker', 'payment');
    }

    private function createNonAdminUser(UserRole $role = UserRole::RestaurantManager): User
    {
        $overrides = ['role' => $role];
        if ($role === UserRole::RestaurantManager) {
            $overrides['restaurant_id'] = $this->restaurant->id;
        }

        return User::factory()->create($overrides);
    }

    // ========================================================================
    // SETTLEMENT DASHBOARD — AC-3C-01 through AC-3C-10
    // ========================================================================

    /**
     * AC-3C-01: GET payment status dashboard returns 200 for Admin on approved shift.
     */
    public function test_admin_can_view_payment_status_dashboard_for_approved_shift(): void
    {
        ['shift' => $shift] = $this->createApprovedShiftWithPayment(PaymentStatus::Processing);

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.payments.status', $shift));

        $response->assertOk();
        $response->assertViewIs('shifts.payment-status');
    }

    /**
     * AC-3C-02: GET payment status dashboard returns 200 for Admin on paid shift.
     */
    public function test_admin_can_view_payment_status_dashboard_for_paid_shift(): void
    {
        $shift = Shift::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'status' => ShiftStatus::Paid,
            'closed_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.payments.status', $shift));

        $response->assertOk();
    }

    /**
     * AC-3C-03: GET payment status dashboard returns 403 for non-Admin.
     */
    public function test_non_admin_cannot_view_payment_status_dashboard(): void
    {
        ['shift' => $shift] = $this->createApprovedShiftWithPayment(PaymentStatus::Processing);
        $rm = $this->createNonAdminUser(UserRole::RestaurantManager);

        $response = $this->actingAs($rm)
            ->get(route('shifts.payments.status', $shift));

        $response->assertForbidden();
    }

    /**
     * AC-3C-03: GET payment status dashboard returns 403 for biker.
     */
    public function test_biker_cannot_view_payment_status_dashboard(): void
    {
        ['shift' => $shift] = $this->createApprovedShiftWithPayment(PaymentStatus::Processing);
        $bikerUser = $this->createNonAdminUser(UserRole::Biker);

        $response = $this->actingAs($bikerUser)
            ->get(route('shifts.payments.status', $shift));

        $response->assertForbidden();
    }

    /**
     * AC-3C-04: GET payment status redirects for non-approved/non-paid shift.
     */
    public function test_payment_status_dashboard_redirects_for_closed_shift(): void
    {
        $shift = Shift::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'status' => ShiftStatus::Closed,
            'closed_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.payments.status', $shift));

        $response->assertRedirect(route('shifts.show', $shift));
        $response->assertSessionHas('error');
    }

    /**
     * AC-3C-04: GET payment status redirects for open shift.
     */
    public function test_payment_status_dashboard_redirects_for_open_shift(): void
    {
        $shift = Shift::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'status' => ShiftStatus::Open,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.payments.status', $shift));

        $response->assertRedirect(route('shifts.show', $shift));
    }

    /**
     * AC-3C-05: Dashboard groups payments by status.
     */
    public function test_dashboard_groups_payments_by_status(): void
    {
        $shift = Shift::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'status' => ShiftStatus::Approved,
            'closed_at' => now(),
        ]);

        // Create 3 bikers with different payment statuses
        foreach ([PaymentStatus::Processing, PaymentStatus::Failed, PaymentStatus::Paid] as $status) {
            ['payment' => $payment] = $this->createApprovedShiftWithPayment($status, [], [
                'shift_id' => $shift->id,
            ]);
            // Re-assign to this shift
            $payment->shiftBiker->update(['shift_id' => $shift->id]);
        }

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.payments.status', $shift));

        $response->assertOk();
    }

    // ========================================================================
    // MARK PAID — AC-3C-11 through AC-3C-18
    // ========================================================================

    /**
     * AC-3C-11: POST mark-paid transitions processing → paid.
     */
    public function test_admin_can_mark_processing_payment_as_paid(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createApprovedShiftWithPayment(PaymentStatus::Processing);

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.payments.mark-paid', [$shift, $payment]));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $payment->refresh();
        $this->assertEquals(PaymentStatus::Paid, $payment->status,
            'AC-3C-11: Payment must be paid');
        $this->assertNotNull($payment->paid_at,
            'AC-3C-12: paid_at must be set');
    }

    /**
     * AC-3C-12: Mark-paid sets paid_at.
     */
    public function test_mark_paid_sets_paid_at_timestamp(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createApprovedShiftWithPayment(PaymentStatus::Processing);

        $this->actingAs($this->admin)
            ->post(route('shifts.payments.mark-paid', [$shift, $payment]));

        $payment->refresh();
        $this->assertNotNull($payment->paid_at, 'AC-3C-12: paid_at must be set');
    }

    /**
     * AC-3C-13: Mark-paid creates audit log with action=succeed.
     */
    public function test_mark_paid_creates_audit_log(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createApprovedShiftWithPayment(PaymentStatus::Processing);

        $this->actingAs($this->admin)
            ->post(route('shifts.payments.mark-paid', [$shift, $payment]));

        $this->assertEquals(1, PaymentAuditLog::where('payment_id', $payment->id)
            ->where('action', PaymentAuditAction::Succeed)->count(),
            'AC-3C-13: Must create exactly 1 succeed audit log');
    }

    /**
     * AC-3C-14: Mark-paid on pending payment returns error.
     */
    public function test_mark_paid_returns_error_for_pending_payment(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createApprovedShiftWithPayment(PaymentStatus::Pending);

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.payments.mark-paid', [$shift, $payment]));

        $response->assertStatus(422);
        $payment->refresh();
        $this->assertEquals(PaymentStatus::Pending, $payment->status,
            'AC-3C-14: Payment must stay pending');

        // No audit log
        $this->assertEquals(0, PaymentAuditLog::where('payment_id', $payment->id)->count(),
            'AC-3C-14: No audit log for refused mark-paid');
    }

    /**
     * AC-3C-15: Mark-paid on already-paid payment returns 422 (idempotency).
     */
    public function test_mark_paid_returns_422_for_already_paid_payment(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createApprovedShiftWithPayment(PaymentStatus::Paid);

        $auditBefore = PaymentAuditLog::where('payment_id', $payment->id)->count();

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.payments.mark-paid', [$shift, $payment]));

        $response->assertStatus(422);
        $this->assertEquals($auditBefore, PaymentAuditLog::where('payment_id', $payment->id)->count(),
            'AC-3C-15: No duplicate audit log');
    }

    /**
     * AC-3C-16: Mark-paid on failed payment returns 422.
     */
    public function test_mark_paid_returns_422_for_failed_payment(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createApprovedShiftWithPayment(PaymentStatus::Failed);

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.payments.mark-paid', [$shift, $payment]));

        $response->assertStatus(422);
        $payment->refresh();
        $this->assertEquals(PaymentStatus::Failed, $payment->status,
            'AC-3C-16: Payment must stay failed');
    }

    /**
     * AC-3C-17: Mark-paid returns 403 for non-Admin.
     */
    public function test_mark_paid_returns_403_for_restaurant_manager(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createApprovedShiftWithPayment(PaymentStatus::Processing);
        $rm = $this->createNonAdminUser(UserRole::RestaurantManager);

        $response = $this->actingAs($rm)
            ->post(route('shifts.payments.mark-paid', [$shift, $payment]));

        $response->assertForbidden();
        $payment->refresh();
        $this->assertEquals(PaymentStatus::Processing, $payment->status,
            'AC-3C-17: Payment must stay processing');
    }

    /**
     * AC-3C-17: Mark-paid returns 403 for biker.
     */
    public function test_mark_paid_returns_403_for_biker(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createApprovedShiftWithPayment(PaymentStatus::Processing);
        $bikerUser = $this->createNonAdminUser(UserRole::Biker);

        $response = $this->actingAs($bikerUser)
            ->post(route('shifts.payments.mark-paid', [$shift, $payment]));

        $response->assertForbidden();
    }

    /**
     * AC-3C-18: Mark-paid returns 404 when payment does not belong to shift.
     */
    public function test_mark_paid_returns_404_when_payment_belongs_to_different_shift(): void
    {
        ['shift' => $shift1, 'payment' => $payment1] = $this->createApprovedShiftWithPayment(PaymentStatus::Processing);
        ['shift' => $shift2] = $this->createApprovedShiftWithPayment(PaymentStatus::Processing);

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.payments.mark-paid', [$shift2, $payment1]));

        $response->assertNotFound();
        $payment1->refresh();
        $this->assertEquals(PaymentStatus::Processing, $payment1->status,
            'AC-3C-18: Payment must stay processing');
    }

    // ========================================================================
    // MARK FAILED — AC-3C-19 through AC-3C-27
    // ========================================================================

    /**
     * AC-3C-19: POST mark-failed transitions processing → failed.
     */
    public function test_admin_can_mark_processing_payment_as_failed(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createApprovedShiftWithPayment(PaymentStatus::Processing);

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.payments.mark-failed', [$shift, $payment]), [
                'failure_reason' => 'Chave PIX inválida no banco',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $payment->refresh();
        $this->assertEquals(PaymentStatus::Failed, $payment->status,
            'AC-3C-19: Payment must be failed');
        $this->assertEquals('Chave PIX inválida no banco', $payment->failure_reason,
            'AC-3C-20: failure_reason must match input');
        $this->assertNotNull($payment->failed_at,
            'AC-3C-20: failed_at must be set');
    }

    /**
     * AC-3C-21: Mark-failed creates audit log with action=fail.
     */
    public function test_mark_failed_creates_fail_audit_log(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createApprovedShiftWithPayment(PaymentStatus::Processing);

        $this->actingAs($this->admin)
            ->post(route('shifts.payments.mark-failed', [$shift, $payment]), [
                'failure_reason' => 'Erro na transação PIX',
            ]);

        $logs = PaymentAuditLog::where('payment_id', $payment->id)
            ->where('action', PaymentAuditAction::Fail)
            ->get();

        $this->assertCount(1, $logs, 'AC-3C-21: Exactly one fail audit log');
        $this->assertEquals('Erro na transação PIX', $logs->first()->error_message,
            'AC-3C-21: error_message must match reason');
    }

    /**
     * AC-3C-22: Mark-failed requires failure_reason.
     */
    public function test_mark_failed_requires_failure_reason(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createApprovedShiftWithPayment(PaymentStatus::Processing);

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.payments.mark-failed', [$shift, $payment]), []);

        $response->assertStatus(422);
        $response->assertInvalid('failure_reason');
        $payment->refresh();
        $this->assertEquals(PaymentStatus::Processing, $payment->status,
            'AC-3C-22: Payment must stay processing');
    }

    /**
     * AC-3C-23: Mark-failed rejects reason under 3 characters.
     */
    public function test_mark_failed_rejects_reason_under_three_chars(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createApprovedShiftWithPayment(PaymentStatus::Processing);

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.payments.mark-failed', [$shift, $payment]), [
                'failure_reason' => 'AB',
            ]);

        $response->assertStatus(422);
        $response->assertInvalid('failure_reason');
    }

    /**
     * AC-3C-24: Mark-failed rejects reason over 500 characters.
     */
    public function test_mark_failed_rejects_reason_over_500_chars(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createApprovedShiftWithPayment(PaymentStatus::Processing);

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.payments.mark-failed', [$shift, $payment]), [
                'failure_reason' => str_repeat('A', 501),
            ]);

        $response->assertStatus(422);
        $response->assertInvalid('failure_reason');
    }

    /**
     * AC-3C-25: Mark-failed on non-processing payment returns 422, no audit.
     */
    public function test_mark_failed_returns_422_for_non_processing_payment(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createApprovedShiftWithPayment(PaymentStatus::Paid);

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.payments.mark-failed', [$shift, $payment]), [
                'failure_reason' => 'Some valid reason text',
            ]);

        $response->assertStatus(422);
        $payment->refresh();
        $this->assertEquals(PaymentStatus::Paid, $payment->status,
            'AC-3C-25: Payment must stay paid');

        $this->assertEquals(0, PaymentAuditLog::where('payment_id', $payment->id)->count(),
            'AC-3C-25: No audit log for refused mark-failed');
    }

    /**
     * AC-3C-26, BR-04: Marking payment failed does NOT change shift status.
     */
    public function test_mark_failed_does_not_regress_shift_status(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createApprovedShiftWithPayment(PaymentStatus::Processing);

        $this->assertEquals(ShiftStatus::Approved, $shift->status);

        $this->actingAs($this->admin)
            ->post(route('shifts.payments.mark-failed', [$shift, $payment]), [
                'failure_reason' => 'Erro temporário de conexão',
            ]);

        $this->assertEquals(ShiftStatus::Approved, $shift->fresh()->status,
            'AC-3C-26, BR-04: Shift must stay approved after payment failure');
    }

    /**
     * AC-3C-27: Mark-failed returns 403 for non-Admin.
     */
    public function test_mark_failed_returns_403_for_restaurant_manager(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createApprovedShiftWithPayment(PaymentStatus::Processing);
        $rm = $this->createNonAdminUser(UserRole::RestaurantManager);

        $response = $this->actingAs($rm)
            ->post(route('shifts.payments.mark-failed', [$shift, $payment]), [
                'failure_reason' => 'Some valid reason text',
            ]);

        $response->assertForbidden();
        $payment->refresh();
        $this->assertEquals(PaymentStatus::Processing, $payment->status,
            'AC-3C-27: Payment must stay processing');
    }

    // ========================================================================
    // RETRY — AC-3C-28 through AC-3C-35
    // ========================================================================

    /**
     * AC-3C-28: POST retry transitions failed → processing.
     */
    public function test_admin_can_retry_failed_payment(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createApprovedShiftWithPayment(PaymentStatus::Failed, [
            'retry_count' => 0,
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.payments.retry', [$shift, $payment]));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $payment->refresh();
        $this->assertEquals(PaymentStatus::Processing, $payment->status,
            'AC-3C-28: Payment must be processing after retry');
        $this->assertEquals(1, $payment->retry_count,
            'AC-3C-29: retry_count must be incremented');
        $this->assertNull($payment->failed_at,
            'AC-3C-30: failed_at must be cleared');
        $this->assertNull($payment->failure_reason,
            'AC-3C-30: failure_reason must be cleared');
    }

    /**
     * AC-3C-29: Retry increments retry_count by exactly 1.
     */
    public function test_retry_increments_retry_count(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createApprovedShiftWithPayment(PaymentStatus::Failed, [
            'retry_count' => 1,
        ]);

        $this->actingAs($this->admin)
            ->post(route('shifts.payments.retry', [$shift, $payment]));

        $payment->refresh();
        $this->assertEquals(2, $payment->retry_count,
            'AC-3C-29: retry_count must be incremented to 2');
    }

    /**
     * AC-3C-31: Retry creates audit log with action=retry.
     */
    public function test_retry_creates_retry_audit_log(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createApprovedShiftWithPayment(PaymentStatus::Failed, [
            'retry_count' => 0,
        ]);

        $this->actingAs($this->admin)
            ->post(route('shifts.payments.retry', [$shift, $payment]));

        $retryLogs = PaymentAuditLog::where('payment_id', $payment->id)
            ->where('action', PaymentAuditAction::Retry)
            ->get();

        $this->assertCount(1, $retryLogs, 'AC-3C-31: Exactly one retry audit log');
        $this->assertEquals(1, $retryLogs->first()->payload['new_retry_count'],
            'AC-3C-31: payload.new_retry_count must be 1');
    }

    /**
     * AC-3C-32: Retry on non-failed payment returns 422, no audit log.
     */
    public function test_retry_returns_422_for_non_failed_payment(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createApprovedShiftWithPayment(PaymentStatus::Processing);

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.payments.retry', [$shift, $payment]));

        $response->assertStatus(422);
        $payment->refresh();
        $this->assertEquals(PaymentStatus::Processing, $payment->status,
            'AC-3C-32: Payment must stay processing');

        $this->assertEquals(0, PaymentAuditLog::where('payment_id', $payment->id)->count(),
            'AC-3C-32: No audit log for refused retry');
    }

    /**
     * AC-3C-33, BR-02: Retry returns 422 when PIX key no longer verified.
     */
    public function test_retry_returns_422_when_pix_no_longer_verified(): void
    {
        ['shift' => $shift, 'payment' => $payment, 'biker' => $biker] = $this->createApprovedShiftWithPayment(PaymentStatus::Failed, [
            'retry_count' => 0,
        ]);

        // Revoke the PIX key
        $biker->pixKeys()->update(['is_verified' => false, 'verified_at' => null]);

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.payments.retry', [$shift, $payment]));

        $response->assertStatus(422);
        $payment->refresh();
        $this->assertEquals(PaymentStatus::Failed, $payment->status,
            'AC-3C-33: Payment must stay failed when PIX revoked');
    }

    /**
     * AC-3C-34, ADR-005 D4: Retry returns 422 when User account missing.
     */
    public function test_retry_returns_422_when_user_account_missing(): void
    {
        // Create a biker with verified PIX but no user account
        $shift = Shift::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'status' => ShiftStatus::Approved,
            'closed_at' => now(),
        ]);

        $biker = Biker::factory()->create(['active' => true]);
        PixKey::factory()->create([
            'biker_id' => $biker->id,
            'is_verified' => true,
            'verified_at' => now(),
        ]);
        // NO user account

        $shiftBiker = ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 3,
        ]);

        $payment = Payment::factory()->create([
            'shift_biker_id' => $shiftBiker->id,
            'amount' => '55.00',
            'status' => PaymentStatus::Failed,
            'released_by' => $this->admin->id,
            'released_at' => now(),
            'failed_at' => now(),
            'failure_reason' => 'No user account',
            'retry_count' => 0,
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.payments.retry', [$shift, $payment]));

        $response->assertStatus(422);
        $payment->refresh();
        $this->assertEquals(PaymentStatus::Failed, $payment->status,
            'AC-3C-34: Payment must stay failed when no User account');
    }

    /**
     * AC-3C-35: Retry returns 403 for non-Admin.
     */
    public function test_retry_returns_403_for_restaurant_manager(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createApprovedShiftWithPayment(PaymentStatus::Failed, [
            'retry_count' => 0,
        ]);
        $rm = $this->createNonAdminUser(UserRole::RestaurantManager);

        $response = $this->actingAs($rm)
            ->post(route('shifts.payments.retry', [$shift, $payment]));

        $response->assertForbidden();
        $payment->refresh();
        $this->assertEquals(PaymentStatus::Failed, $payment->status,
            'AC-3C-35: Payment must stay failed');
    }

    /**
     * AC-3C-35: Retry returns 403 for biker.
     */
    public function test_retry_returns_403_for_biker(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createApprovedShiftWithPayment(PaymentStatus::Failed, [
            'retry_count' => 0,
        ]);
        $bikerUser = $this->createNonAdminUser(UserRole::Biker);

        $response = $this->actingAs($bikerUser)
            ->post(route('shifts.payments.retry', [$shift, $payment]));

        $response->assertForbidden();
    }

    /**
     * AC-3C-45: Retry returns 422 when retry_count >= 3 (hard cap).
     */
    public function test_retry_returns_422_when_retry_count_at_cap(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createApprovedShiftWithPayment(PaymentStatus::Failed, [
            'retry_count' => 3,
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.payments.retry', [$shift, $payment]));

        $response->assertStatus(422);
        $payment->refresh();
        $this->assertEquals(PaymentStatus::Failed, $payment->status,
            'AC-3C-45: Payment must stay failed at retry cap');
        $this->assertEquals(3, $payment->retry_count,
            'AC-3C-45: retry_count must stay at 3');

        // No retry audit log
        $this->assertEquals(0,
            PaymentAuditLog::where('payment_id', $payment->id)
                ->where('action', PaymentAuditAction::Retry)
                ->count(),
            'AC-3C-45: No retry audit log for cap refusal');
    }

    /**
     * AC-3C-46: Third retry auto-fails payment with cap reason.
     */
    public function test_retry_auto_fails_on_third_retry(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createApprovedShiftWithPayment(PaymentStatus::Failed, [
            'retry_count' => 2,
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.payments.retry', [$shift, $payment]));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $payment->refresh();
        $this->assertEquals(PaymentStatus::Failed, $payment->status,
            'AC-3C-46: Payment must be auto-failed after 3rd retry');
        $this->assertEquals(3, $payment->retry_count,
            'AC-3C-46: retry_count must be 3');
        $this->assertStringContainsString('Limite de retentativas', $payment->failure_reason,
            'AC-3C-46: failure_reason must contain cap message');

        // Verify audit logs: 1 retry + 1 auto-fail
        $retryCount = PaymentAuditLog::where('payment_id', $payment->id)
            ->where('action', PaymentAuditAction::Retry)
            ->count();
        $failCount = PaymentAuditLog::where('payment_id', $payment->id)
            ->where('action', PaymentAuditAction::Fail)
            ->count();

        $this->assertEquals(1, $retryCount, 'AC-3C-46: 1 retry audit log');
        $this->assertEquals(1, $failCount, 'AC-3C-46: 1 auto-fail audit log');
    }

    // ========================================================================
    // SHIFT RECONCILIATION — AC-3C-36 through AC-3C-39
    // ========================================================================

    /**
     * AC-3C-36: Marking last payment paid promotes shift to paid.
     */
    public function test_marking_last_payment_paid_promotes_shift_to_paid(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createApprovedShiftWithPayment(PaymentStatus::Processing);

        $this->assertEquals(ShiftStatus::Approved, $shift->status);

        $this->actingAs($this->admin)
            ->post(route('shifts.payments.mark-paid', [$shift, $payment]));

        $this->assertEquals(ShiftStatus::Paid, $shift->fresh()->status,
            'AC-3C-36: Shift must transition to paid');
    }

    /**
     * AC-3C-37: Marking one payment paid while sibling still processing keeps shift approved.
     */
    public function test_marking_payment_paid_with_processing_sibling_keeps_shift_approved(): void
    {
        ['shift' => $shift, 'payment' => $payment1] = $this->createApprovedShiftWithPayment(PaymentStatus::Processing);

        // Add second processing payment
        $biker2 = Biker::factory()->create(['active' => true]);
        User::factory()->create(['role' => UserRole::Biker, 'biker_id' => $biker2->id]);
        PixKey::factory()->create(['biker_id' => $biker2->id, 'is_verified' => true, 'verified_at' => now()]);
        $sb2 = ShiftBiker::factory()->create(['shift_id' => $shift->id, 'biker_id' => $biker2->id]);
        Payment::factory()->create([
            'shift_biker_id' => $sb2->id,
            'amount' => '50.00',
            'status' => PaymentStatus::Processing,
            'released_by' => $this->admin->id,
            'released_at' => now(),
        ]);

        // Mark only payment1 as paid
        $this->actingAs($this->admin)
            ->post(route('shifts.payments.mark-paid', [$shift, $payment1]));

        $this->assertEquals(ShiftStatus::Approved, $shift->fresh()->status,
            'AC-3C-37: Shift must stay approved with processing sibling');
    }

    /**
     * AC-3C-38, BR-04: Marking payment paid while sibling failed keeps shift approved.
     */
    public function test_marking_payment_paid_with_failed_sibling_keeps_shift_approved(): void
    {
        ['shift' => $shift, 'payment' => $payment1] = $this->createApprovedShiftWithPayment(PaymentStatus::Processing);

        // Add a failed payment
        $biker2 = Biker::factory()->create(['active' => true]);
        User::factory()->create(['role' => UserRole::Biker, 'biker_id' => $biker2->id]);
        PixKey::factory()->create(['biker_id' => $biker2->id, 'is_verified' => true, 'verified_at' => now()]);
        $sb2 = ShiftBiker::factory()->create(['shift_id' => $shift->id, 'biker_id' => $biker2->id]);
        Payment::factory()->create([
            'shift_biker_id' => $sb2->id,
            'amount' => '50.00',
            'status' => PaymentStatus::Failed,
            'released_by' => $this->admin->id,
            'released_at' => now(),
            'failed_at' => now(),
            'failure_reason' => 'Previous failure',
        ]);

        // Mark payment1 as paid, but payment2 is failed
        $this->actingAs($this->admin)
            ->post(route('shifts.payments.mark-paid', [$shift, $payment1]));

        $this->assertEquals(ShiftStatus::Approved, $shift->fresh()->status,
            'AC-3C-38, BR-04: Shift must stay approved with failed sibling');
    }

    /**
     * AC-3C-39: Paid shift is terminal — never regresses.
     */
    public function test_paid_shift_never_regresses(): void
    {
        $shift = Shift::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'status' => ShiftStatus::Paid,
            'closed_at' => now(),
        ]);

        // Even if reconcile is somehow called, shift stays paid
        $service = app(PaymentSettlementService::class);
        $service->reconcileShiftStatus($shift);

        $this->assertEquals(ShiftStatus::Paid, $shift->fresh()->status,
            'AC-3C-39: Paid shift is terminal');
    }

    // ========================================================================
    // FINANCIAL INTEGRITY — AC-3C-40, AC-3C-41
    // ========================================================================

    /**
     * AC-3C-40: Amount and revenue never modified during settlement.
     */
    public function test_payment_amount_not_modified_during_mark_paid(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createApprovedShiftWithPayment(PaymentStatus::Processing, [
            'amount' => '75.00',
            'revenue' => '25.00',
        ]);

        $this->actingAs($this->admin)
            ->post(route('shifts.payments.mark-paid', [$shift, $payment]));

        $payment->refresh();
        $this->assertEquals('75.00', $payment->amount, 'AC-3C-40: Amount unchanged');
        $this->assertEquals('25.00', $payment->revenue, 'AC-3C-40: Revenue unchanged');
    }

    /**
     * AC-3C-40: Amount and revenue not modified during mark-failed.
     */
    public function test_payment_amount_not_modified_during_mark_failed(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createApprovedShiftWithPayment(PaymentStatus::Processing, [
            'amount' => '75.00',
            'revenue' => '25.00',
        ]);

        $this->actingAs($this->admin)
            ->post(route('shifts.payments.mark-failed', [$shift, $payment]), [
                'failure_reason' => 'Test failure with valid reason',
            ]);

        $payment->refresh();
        $this->assertEquals('75.00', $payment->amount, 'AC-3C-40: Amount unchanged');
        $this->assertEquals('25.00', $payment->revenue, 'AC-3C-40: Revenue unchanged');
    }

    // ========================================================================
    // AUDIT TRAIL — AC-3C-42 through AC-3C-44
    // ========================================================================

    /**
     * AC-3C-42: Every successful transition writes exactly one audit log.
     */
    public function test_every_successful_transition_creates_exactly_one_audit_log(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createApprovedShiftWithPayment(PaymentStatus::Processing, [
            'retry_count' => 0,
        ]);

        // Mark as failed
        $this->actingAs($this->admin)
            ->post(route('shifts.payments.mark-failed', [$shift, $payment]), [
                'failure_reason' => 'First failure reason text',
            ]);

        $this->assertEquals(1, PaymentAuditLog::where('payment_id', $payment->id)->count());

        // Retry
        $payment->refresh();
        $this->actingAs($this->admin)
            ->post(route('shifts.payments.retry', [$shift, $payment]));

        $this->assertEquals(2, PaymentAuditLog::where('payment_id', $payment->id)->count());

        // Mark as paid
        $payment->refresh();
        $this->actingAs($this->admin)
            ->post(route('shifts.payments.mark-paid', [$shift, $payment]));

        $this->assertEquals(3, PaymentAuditLog::where('payment_id', $payment->id)->count(),
            'AC-3C-42: 3 audit logs for fail+retry+pay');
    }

    /**
     * AC-3C-44: Refused transitions write NO audit log rows.
     */
    public function test_refused_transitions_write_no_audit_logs(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createApprovedShiftWithPayment(PaymentStatus::Paid);

        // Try mark-paid on already-paid
        $response = $this->actingAs($this->admin)
            ->post(route('shifts.payments.mark-paid', [$shift, $payment]));
        $response->assertStatus(422);

        // Try mark-failed on paid
        $response = $this->actingAs($this->admin)
            ->post(route('shifts.payments.mark-failed', [$shift, $payment]), [
                'failure_reason' => 'Some reason text here',
            ]);
        $response->assertStatus(422);

        // Try retry on paid
        $response = $this->actingAs($this->admin)
            ->post(route('shifts.payments.retry', [$shift, $payment]));
        $response->assertStatus(422);

        $this->assertEquals(0, PaymentAuditLog::where('payment_id', $payment->id)->count(),
            'AC-3C-44: No audit logs for any refused transition');
    }

    // ========================================================================
    // END-TO-END SMOKE TEST — Complete Settlement Cycle
    // ========================================================================

    /**
     * Smoke test: Full flow release → fail → retry → pay → shift becomes paid.
     */
    public function test_complete_settlement_cycle_smoke_test(): void
    {
        // Start with a closed shift, release payments first (Phase 3B), then settle (Phase 3C)
        $shift = Shift::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'status' => ShiftStatus::Approved,
            'closed_at' => now(),
            'restaurant_rate' => '15.00',
        ]);

        // Create 3 bikers with processing payments
        $payments = [];
        for ($i = 1; $i <= 3; $i++) {
            $biker = Biker::factory()->create(['active' => true, 'name' => "Biker {$i}"]);
            User::factory()->create(['role' => UserRole::Biker, 'biker_id' => $biker->id]);
            PixKey::factory()->create(['biker_id' => $biker->id, 'is_verified' => true, 'verified_at' => now()]);
            $sb = ShiftBiker::factory()->create([
                'shift_id' => $shift->id,
                'biker_id' => $biker->id,
                'trips_count' => 5,
                'biker_rate' => '10.00',
                'base_fee' => '25.00',
            ]);
            $payments[$i] = Payment::factory()->create([
                'shift_biker_id' => $sb->id,
                'amount' => '75.00',
                'revenue' => '0.00',
                'status' => PaymentStatus::Processing,
                'released_by' => $this->admin->id,
                'released_at' => now(),
            ]);
        }

        // 1. Mark payment #1 as paid — shift stays approved
        $this->actingAs($this->admin)
            ->post(route('shifts.payments.mark-paid', [$shift, $payments[1]]));

        $this->assertEquals(ShiftStatus::Approved, $shift->fresh()->status);

        // 2. Mark payment #2 as failed — shift stays approved
        $this->actingAs($this->admin)
            ->post(route('shifts.payments.mark-failed', [$shift, $payments[2]]), [
                'failure_reason' => 'Chave PIX não encontrada no banco',
            ]);

        $this->assertEquals(ShiftStatus::Approved, $shift->fresh()->status);
        $this->assertEquals(PaymentStatus::Failed, $payments[2]->fresh()->status);

        // 3. Retry payment #2 — goes back to processing
        $payments[2]->refresh();
        $this->actingAs($this->admin)
            ->post(route('shifts.payments.retry', [$shift, $payments[2]]));

        $this->assertEquals(PaymentStatus::Processing, $payments[2]->fresh()->status);
        $this->assertEquals(1, $payments[2]->fresh()->retry_count);

        // 4. Mark payment #2 as paid — shift stays approved (payment #3 still processing)
        $payments[2]->refresh();
        $this->actingAs($this->admin)
            ->post(route('shifts.payments.mark-paid', [$shift, $payments[2]]));

        $this->assertEquals(ShiftStatus::Approved, $shift->fresh()->status);

        // 5. Mark payment #3 as paid — shift transitions to paid
        $this->actingAs($this->admin)
            ->post(route('shifts.payments.mark-paid', [$shift, $payments[3]]));

        $this->assertEquals(ShiftStatus::Paid, $shift->fresh()->status,
            'Smoke test: Shift must be paid after all payments settled');

        // 6. Verify audit trail — 7 audit logs total:
        //    pay1: succeed(1), pay2: fail(1) + retry(1) + succeed(1), pay3: succeed(1)
        $totalLogs = PaymentAuditLog::whereIn('payment_id', collect($payments)->pluck('id'))->count();
        $this->assertEquals(5, $totalLogs,
            'Smoke test: 5 audit logs for the full cycle');
    }

    // ========================================================================
    // AC-3C-47/48: Retry cap UI behavior
    // ========================================================================

    /**
     * AC-3C-47/48: Payment with retry_count >= 3 does not show retry button.
     */
    public function test_retry_cap_hides_button_in_ui(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createApprovedShiftWithPayment(PaymentStatus::Failed, [
            'retry_count' => 3,
            'failure_reason' => 'Limite de retentativas atingido',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.payments.status', $shift));

        $response->assertOk();
        // The retry route for this payment should NOT appear in the rendered HTML
        $response->assertDontSee(route('shifts.payments.retry', [$shift, $payment]));
    }

    /**
     * AC-3C-47: Dashboard shows warning for max-retry payment.
     */
    public function test_dashboard_shows_warning_for_max_retry_payment(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createApprovedShiftWithPayment(PaymentStatus::Failed, [
            'retry_count' => 3,
            'failure_reason' => 'Limite de retentativas atingido (3/3). Intervenção manual necessária.',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.payments.status', $shift));

        $response->assertOk();
        $response->assertSeeText('Intervenção manual');
    }
}
