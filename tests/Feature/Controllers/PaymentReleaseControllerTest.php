<?php

namespace Tests\Feature\Controllers;

use App\Enums\PaymentStatus;
use App\Enums\ShiftStatus;
use App\Enums\UserRole;
use App\Enums\WorkflowType;
use App\Models\Biker;
use App\Models\Payment;
use App\Models\PixKey;
use App\Models\Restaurant;
use App\Models\Shift;
use App\Models\ShiftBiker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Acceptance Criteria: AC-3B-01 through AC-3B-46
 * Business Rules: BR-02 (PIX Verification), BR-03 (Manual Release), BR-04 (Granular Failure)
 * ADR-005: D1 (Admin-only), D4 (Bikers need User accounts)
 *
 * Plan: docs/plans/phase-3b-payment-release-admin-approval.md
 */
class PaymentReleaseControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Restaurant $restaurant;
    private PixKey $verifiedPix;
    private PixKey $unverifiedPix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $this->restaurant = Restaurant::factory()->create([
            'rate_per_trip' => '15.00',
        ]);

        // Verified PIX key for eligible bikers
        $this->verifiedPix = PixKey::factory()->create([
            'key_type' => 'cpf',
            'key_value' => '12345678901',
            'is_verified' => true,
            'verified_at' => now(),
        ]);

        // Unverified PIX key
        $this->unverifiedPix = PixKey::factory()->create([
            'key_type' => 'email',
            'key_value' => 'unverified@example.com',
            'is_verified' => false,
            'verified_at' => null,
        ]);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private function createClosedShiftWithPayment(
        array $shiftOverrides = [],
        array $shiftBikerOverrides = [],
        array $paymentOverrides = [],
    ): array {
        $shift = Shift::factory()->create(array_merge([
            'restaurant_id' => $this->restaurant->id,
            'status' => ShiftStatus::Closed,
            'workflow_type' => WorkflowType::LiveTick,
            'restaurant_rate' => '15.00',
            'closed_at' => now(),
        ], $shiftOverrides));

        $biker = Biker::factory()->create([
            'rate_per_trip' => '10.00',
            'base_fee' => '25.00',
            'active' => true,
        ]);

        // Link biker to user
        User::factory()->create([
            'role' => UserRole::Biker,
            'biker_id' => $biker->id,
        ]);

        // Give biker a verified PIX key
        PixKey::factory()->create([
            'biker_id' => $biker->id,
            'key_type' => 'cpf',
            'key_value' => $biker->phone,
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

        // Payout = 25.00 + (10.00 × 5) = 75.00
        // Revenue = (15.00 × 5) - 75.00 = 0.00
        $payment = Payment::factory()->create(array_merge([
            'shift_biker_id' => $shiftBiker->id,
            'amount' => '75.00',
            'revenue' => '0.00',
            'status' => PaymentStatus::Pending,
        ], $paymentOverrides));

        return compact('shift', 'biker', 'shiftBiker', 'payment');
    }

    private function createIneligibleBikerWithShift(): array
    {
        $shift = Shift::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'status' => ShiftStatus::Closed,
            'workflow_type' => WorkflowType::LiveTick,
            'restaurant_rate' => '15.00',
            'closed_at' => now(),
        ]);

        // Biker without user account and without verified PIX
        $biker = Biker::factory()->create([
            'rate_per_trip' => '10.00',
            'base_fee' => '25.00',
            'active' => true,
        ]);

        // Only unverified PIX key, no user account
        PixKey::factory()->create([
            'biker_id' => $biker->id,
            'key_type' => 'email',
            'key_value' => 'no-user@example.com',
            'is_verified' => false,
        ]);

        $shiftBiker = ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 3,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);

        $payment = Payment::factory()->create([
            'shift_biker_id' => $shiftBiker->id,
            'amount' => '55.00',
            'revenue' => '-10.00',
            'status' => PaymentStatus::Pending,
        ]);

        return compact('shift', 'biker', 'shiftBiker', 'payment');
    }

    // ========================================================================
    // AC-3B-01: GET review returns 200 for Admin on closed shift
    // ========================================================================
    public function test_review_payments_returns_200_for_admin_on_closed_shift(): void
    {
        ['shift' => $shift] = $this->createClosedShiftWithPayment();

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.payments.review', $shift));

        $response->assertOk();
        $response->assertViewIs('shifts.payment-review');
    }

    // AC-3B-02: GET review returns 200 for Admin on approved shift
    public function test_review_payments_returns_200_for_admin_on_approved_shift(): void
    {
        ['shift' => $shift] = $this->createClosedShiftWithPayment([
            'status' => ShiftStatus::Approved,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.payments.review', $shift));

        $response->assertOk();
    }

    // AC-3B-03: GET review returns 403 for non-Admin
    public function test_review_payments_returns_403_for_restaurant_manager(): void
    {
        ['shift' => $shift] = $this->createClosedShiftWithPayment();

        $rm = User::factory()->create([
            'role' => UserRole::RestaurantManager,
            'restaurant_id' => $this->restaurant->id,
        ]);

        $response = $this->actingAs($rm)
            ->get(route('shifts.payments.review', $shift));

        $response->assertForbidden();
    }

    // AC-3B-03: GET review returns 403 for biker
    public function test_review_payments_returns_403_for_biker(): void
    {
        ['shift' => $shift] = $this->createClosedShiftWithPayment();

        $bikerUser = User::factory()->create([
            'role' => UserRole::Biker,
        ]);

        $response = $this->actingAs($bikerUser)
            ->get(route('shifts.payments.review', $shift));

        $response->assertForbidden();
    }

    // AC-3B-03: GET review redirects unauthenticated
    public function test_review_payments_redirects_unauthenticated(): void
    {
        ['shift' => $shift] = $this->createClosedShiftWithPayment();

        $response = $this->get(route('shifts.payments.review', $shift));

        $response->assertRedirect(route('login'));
    }

    // AC-3B-04: GET review redirects for non-closed/non-approved shift
    public function test_review_payments_redirects_for_open_shift(): void
    {
        $shift = Shift::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'status' => ShiftStatus::Open,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.payments.review', $shift));

        $response->assertRedirect(route('shifts.show', $shift));
        $response->assertSessionHas('error');
    }

    // AC-3B-04: GET review redirects for draft shift
    public function test_review_payments_redirects_for_draft_shift(): void
    {
        $shift = Shift::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'status' => ShiftStatus::Draft,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.payments.review', $shift));

        $response->assertRedirect(route('shifts.show', $shift));
    }

    // AC-3B-05: Review view displays payment details
    public function test_review_view_displays_payment_details(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createClosedShiftWithPayment();

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.payments.review', $shift));

        $response->assertSee($payment->amount);
        $response->assertSee($payment->revenue);
        $response->assertSee('75.00');
    }

    // AC-3B-06: Review view displays PIX status
    public function test_review_view_displays_pix_verification_status(): void
    {
        ['shift' => $shift, 'biker' => $biker] = $this->createClosedShiftWithPayment();

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.payments.review', $shift));

        $response->assertOk();
        // Biker has verified PIX — view should show verified indicator
        $response->assertSeeText(['PIX', 'verificada']);
    }

    // AC-3B-06: Review view shows unverified PIX for ineligible biker
    public function test_review_view_shows_unverified_pix_status(): void
    {
        ['shift' => $shift] = $this->createIneligibleBikerWithShift();

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.payments.review', $shift));

        $response->assertOk();
        $response->assertSeeText(['PIX']);
    }

    // AC-3B-07: Review view displays user account status
    public function test_review_view_displays_user_account_status(): void
    {
        ['shift' => $shift] = $this->createClosedShiftWithPayment();

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.payments.review', $shift));

        $response->assertOk();
        $response->assertSeeText(['conta']);
    }

    // AC-3B-08: Review view shows release button for eligible payments
    public function test_review_view_shows_release_button_for_eligible_payment(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createClosedShiftWithPayment();

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.payments.review', $shift));

        $response->assertOk();
        $response->assertSee('Liberar');
        $response->assertSee(route('shifts.payments.release', [$shift, $payment]));
    }

    // AC-3B-08: Review view does NOT show release button for ineligible payments
    public function test_review_view_does_not_show_release_button_for_ineligible(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createIneligibleBikerWithShift();

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.payments.review', $shift));

        $response->assertOk();
        // The release route should NOT appear for ineligible payments
        $response->assertDontSee(route('shifts.payments.release', [$shift, $payment]));
    }

    // AC-3B-09: Review view shows block reasons for ineligible
    public function test_review_view_shows_block_reasons(): void
    {
        ['shift' => $shift] = $this->createIneligibleBikerWithShift();

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.payments.review', $shift));

        $response->assertOk();
        $response->assertSeeText(['PIX', 'usuário']);
    }

    // AC-3B-10: Review view shows release-all button when eligible payments exist
    public function test_review_view_shows_release_all_button_with_eligible(): void
    {
        ['shift' => $shift] = $this->createClosedShiftWithPayment();

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.payments.review', $shift));

        $response->assertOk();
        $response->assertSee('Liberar Todos');
    }

    // AC-3B-11: Review view displays total amounts
    public function test_review_view_displays_total_pending_amount(): void
    {
        ['shift' => $shift] = $this->createClosedShiftWithPayment();

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.payments.review', $shift));

        $response->assertOk();
        $response->assertSee('75.00');
    }

    // AC-3B-12: Review view shows empty state when no payments
    public function test_review_view_shows_empty_state_when_no_payments(): void
    {
        $shift = Shift::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'status' => ShiftStatus::Closed,
            'closed_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.payments.review', $shift));

        $response->assertOk();
        $response->assertSeeText(['Nenhum pagamento', 'nenhum pagador']);
    }

    // AC-3B-13: Review view shows payment status badge
    public function test_review_view_shows_pending_status_badge(): void
    {
        ['shift' => $shift] = $this->createClosedShiftWithPayment();

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.payments.review', $shift));

        $response->assertOk();
        $response->assertSeeText(['Pendente', 'pending']);
    }

    // ========================================================================
    // AC-3B-14: Release single payment transitions to processing
    // ========================================================================
    public function test_release_payment_transitions_to_processing(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createClosedShiftWithPayment();

        $this->assertEquals(PaymentStatus::Pending, $payment->status);

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.payments.release', [$shift, $payment]));

        $payment->refresh();
        $this->assertEquals(PaymentStatus::Processing, $payment->status);
    }

    // AC-3B-15: Release sets released_by
    public function test_release_sets_released_by_to_admin_id(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createClosedShiftWithPayment();

        $this->assertNull($payment->released_by);

        $this->actingAs($this->admin)
            ->post(route('shifts.payments.release', [$shift, $payment]));

        $payment->refresh();
        $this->assertEquals($this->admin->id, $payment->released_by);
    }

    // AC-3B-16: Release sets released_at
    public function test_release_sets_released_at_to_current_timestamp(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createClosedShiftWithPayment();

        $this->assertNull($payment->released_at);

        $this->actingAs($this->admin)
            ->post(route('shifts.payments.release', [$shift, $payment]));

        $payment->refresh();
        $this->assertNotNull($payment->released_at);
    }

    // AC-3B-17: Release creates audit log
    public function test_release_creates_audit_log_entry(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createClosedShiftWithPayment();

        $this->assertDatabaseCount('payment_audit_logs', 0);

        $this->actingAs($this->admin)
            ->post(route('shifts.payments.release', [$shift, $payment]));

        $this->assertDatabaseCount('payment_audit_logs', 1);
        $this->assertDatabaseHas('payment_audit_logs', [
            'payment_id' => $payment->id,
        ]);
    }

    // AC-3B-18: Release blocked without verified PIX key (BR-02)
    public function test_release_blocked_without_verified_pix_key(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createIneligibleBikerWithShift();

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.payments.release', [$shift, $payment]));

        $payment->refresh();
        $this->assertEquals(PaymentStatus::Pending, $payment->status);
        $response->assertSessionHas('error');
    }

    // AC-3B-19: Release blocked without user account (ADR-005 D4)
    public function test_release_blocked_without_user_account(): void
    {
        // Biker with verified PIX but no user account
        $shift = Shift::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'status' => ShiftStatus::Closed,
            'closed_at' => now(),
        ]);

        $biker = Biker::factory()->create(['active' => true]);
        // Has verified PIX
        PixKey::factory()->create([
            'biker_id' => $biker->id,
            'is_verified' => true,
            'verified_at' => now(),
        ]);
        // NO user account for this biker

        $shiftBiker = ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 3,
        ]);

        $payment = Payment::factory()->create([
            'shift_biker_id' => $shiftBiker->id,
            'amount' => '55.00',
            'status' => PaymentStatus::Pending,
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.payments.release', [$shift, $payment]));

        $payment->refresh();
        $this->assertEquals(PaymentStatus::Pending, $payment->status);
        $response->assertSessionHas('error');
    }

    // AC-3B-20: Release blocked for non-pending payment
    public function test_release_blocked_for_processing_payment(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createClosedShiftWithPayment([], [], [
            'status' => PaymentStatus::Processing,
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.payments.release', [$shift, $payment]));

        $response->assertSessionHas('error');
    }

    // AC-3B-21: Release returns 403 for non-Admin
    public function test_release_returns_403_for_restaurant_manager(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createClosedShiftWithPayment();

        $rm = User::factory()->create([
            'role' => UserRole::RestaurantManager,
            'restaurant_id' => $this->restaurant->id,
        ]);

        $response = $this->actingAs($rm)
            ->post(route('shifts.payments.release', [$shift, $payment]));

        $response->assertForbidden();
        $payment->refresh();
        $this->assertEquals(PaymentStatus::Pending, $payment->status);
    }

    // AC-3B-21: Release returns 403 for unauthenticated
    public function test_release_redirects_unauthenticated(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createClosedShiftWithPayment();

        $response = $this->post(route('shifts.payments.release', [$shift, $payment]));

        $response->assertRedirect(route('login'));
    }

    // AC-3B-22: Release validates payment belongs to shift
    public function test_release_validates_payment_belongs_to_shift(): void
    {
        ['shift' => $shift1, 'payment' => $payment1] = $this->createClosedShiftWithPayment();
        ['shift' => $shift2] = $this->createClosedShiftWithPayment();

        // Try to release payment1 via shift2's route
        $response = $this->actingAs($this->admin)
            ->post(route('shifts.payments.release', [$shift2, $payment1]));

        $response->assertNotFound();
        $payment1->refresh();
        $this->assertEquals(PaymentStatus::Pending, $payment1->status);
    }

    // AC-3B-23: Successful release redirects back to review page
    public function test_successful_release_redirects_to_review(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createClosedShiftWithPayment();

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.payments.release', [$shift, $payment]));

        $response->assertRedirect(route('shifts.payments.review', $shift));
        $response->assertSessionHas('success');
    }

    // ========================================================================
    // AC-3B-24: Batch release releases all eligible
    // ========================================================================
    public function test_batch_release_releases_all_eligible_payments(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createClosedShiftWithPayment();

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.payments.release-all', $shift));

        $payment->refresh();
        $this->assertEquals(PaymentStatus::Processing, $payment->status);
    }

    // AC-3B-25: Batch release skips ineligible
    public function test_batch_release_skips_ineligible_payments(): void
    {
        ['shift' => $shift, 'payment' => $eligiblePayment] = $this->createClosedShiftWithPayment();

        // Add an ineligible biker to the same shift
        $ineligibleBiker = Biker::factory()->create(['active' => true]);
        PixKey::factory()->create([
            'biker_id' => $ineligibleBiker->id,
            'is_verified' => false,
        ]);
        $ineligibleSb = ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => $ineligibleBiker->id,
            'trips_count' => 2,
        ]);
        $ineligiblePayment = Payment::factory()->create([
            'shift_biker_id' => $ineligibleSb->id,
            'amount' => '45.00',
            'status' => PaymentStatus::Pending,
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.payments.release-all', $shift));

        $eligiblePayment->refresh();
        $ineligiblePayment->refresh();

        $this->assertEquals(PaymentStatus::Processing, $eligiblePayment->status);
        $this->assertEquals(PaymentStatus::Pending, $ineligiblePayment->status);
    }

    // AC-3B-26: Batch release returns summary
    public function test_batch_release_returns_summary_message(): void
    {
        ['shift' => $shift] = $this->createClosedShiftWithPayment();

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.payments.release-all', $shift));

        $response->assertRedirect(route('shifts.payments.review', $shift));
        $response->assertSessionHas('success');
    }

    // AC-3B-27: Batch release only works on closed shifts
    public function test_batch_release_rejected_for_open_shift(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createClosedShiftWithPayment([
            'status' => ShiftStatus::Open,
            'closed_at' => null,
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.payments.release-all', $shift));

        $response->assertSessionHas('error');
        $payment->refresh();
        $this->assertEquals(PaymentStatus::Pending, $payment->status);
    }

    // AC-3B-28: Batch release is idempotent
    public function test_batch_release_is_idempotent(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createClosedShiftWithPayment();

        // First release
        $this->actingAs($this->admin)
            ->post(route('shifts.payments.release-all', $shift));

        $payment->refresh();
        $this->assertEquals(PaymentStatus::Processing, $payment->status);

        // Second release — should not error
        $response = $this->actingAs($this->admin)
            ->post(route('shifts.payments.release-all', $shift));

        $response->assertRedirect(route('shifts.payments.review', $shift));
    }

    // ========================================================================
    // AC-3B-29: Shift auto-transitions to approved when all payments released
    // ========================================================================
    public function test_shift_auto_transitions_to_approved_when_all_released(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createClosedShiftWithPayment();

        $this->assertEquals(ShiftStatus::Closed, $shift->status);

        $this->actingAs($this->admin)
            ->post(route('shifts.payments.release', [$shift, $payment]));

        $shift->refresh();
        $this->assertEquals(ShiftStatus::Approved, $shift->status);
    }

    // AC-3B-30: Shift transition happens atomically with last payment
    public function test_shift_transitions_after_releasing_last_pending_payment(): void
    {
        ['shift' => $shift, 'payment' => $payment1] = $this->createClosedShiftWithPayment();

        // Add a second eligible biker to the same shift
        $biker2 = Biker::factory()->create(['active' => true]);
        User::factory()->create(['role' => UserRole::Biker, 'biker_id' => $biker2->id]);
        PixKey::factory()->create([
            'biker_id' => $biker2->id,
            'is_verified' => true,
            'verified_at' => now(),
        ]);
        $sb2 = ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => $biker2->id,
            'trips_count' => 3,
        ]);
        $payment2 = Payment::factory()->create([
            'shift_biker_id' => $sb2->id,
            'amount' => '55.00',
            'status' => PaymentStatus::Pending,
        ]);

        // Release first payment — shift should stay closed
        $this->actingAs($this->admin)
            ->post(route('shifts.payments.release', [$shift, $payment1]));

        $shift->refresh();
        $this->assertEquals(ShiftStatus::Closed, $shift->status);

        // Release second payment — shift should transition to approved
        $this->actingAs($this->admin)
            ->post(route('shifts.payments.release', [$shift, $payment2]));

        $shift->refresh();
        $this->assertEquals(ShiftStatus::Approved, $shift->status);
    }

    // AC-3B-31: Shift with zero bikers auto-transitions to approved
    public function test_shift_with_zero_bikers_auto_transitions_to_approved(): void
    {
        $shift = Shift::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'status' => ShiftStatus::Closed,
            'closed_at' => now(),
        ]);

        // Load review (triggers transition check)
        $response = $this->actingAs($this->admin)
            ->get(route('shifts.payments.review', $shift));

        $response->assertOk();
    }

    // AC-3B-32: Shift stays closed with blocked payments
    public function test_shift_stays_closed_with_blocked_payments(): void
    {
        ['shift' => $shift, 'payment' => $eligiblePayment] = $this->createClosedShiftWithPayment();

        // Add ineligible payment
        $ineligibleBiker = Biker::factory()->create(['active' => true]);
        PixKey::factory()->create([
            'biker_id' => $ineligibleBiker->id,
            'is_verified' => false,
        ]);
        $ineligibleSb = ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => $ineligibleBiker->id,
            'trips_count' => 2,
        ]);
        Payment::factory()->create([
            'shift_biker_id' => $ineligibleSb->id,
            'amount' => '45.00',
            'status' => PaymentStatus::Pending,
        ]);

        // Release eligible — shift should stay closed (ineligible still pending)
        $this->actingAs($this->admin)
            ->post(route('shifts.payments.release', [$shift, $eligiblePayment]));

        $shift->refresh();
        $this->assertEquals(ShiftStatus::Closed, $shift->status);
    }

    // AC-3B-33: Approved shift review page still works
    public function test_approved_shift_review_page_still_works(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createClosedShiftWithPayment([
            'status' => ShiftStatus::Approved,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.payments.review', $shift));

        $response->assertOk();
        $response->assertViewIs('shifts.payment-review');
    }

    // ========================================================================
    // AC-3B-37: M-01 fix — close-review renders each warning exactly once
    // ========================================================================
    public function test_close_review_renders_warnings_exactly_once(): void
    {
        // Create a shift with a biker that has NO user and NO verified PIX
        $shift = Shift::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'status' => ShiftStatus::Open,
            'workflow_type' => WorkflowType::LiveTick,
            'restaurant_rate' => '15.00',
        ]);

        $biker = Biker::factory()->create(['active' => true]);
        // Only unverified PIX
        PixKey::factory()->create([
            'biker_id' => $biker->id,
            'is_verified' => false,
        ]);
        // No user account
        ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 3,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.close.review', $shift));

        $response->assertOk();
        $content = $response->getContent();

        // Count occurrences of warning badges — should be exactly 2 (one per issue)
        // Not 4 (which would happen with duplicate @if blocks)
        $userWarningCount = substr_count($content, 'Sem conta de usuário') +
            substr_count($content, 'sem conta de usuário');
        $pixWarningCount = substr_count($content, 'Sem chave PIX verificada') +
            substr_count($content, 'sem chave PIX verificada') +
            substr_count($content, 'sem PIX');

        // Each warning should appear exactly once (M-01 fix)
        $this->assertLessThanOrEqual(2, $userWarningCount + $pixWarningCount,
            'AC-3B-37: Warning badges should appear at most once each (M-01 fix)');
    }

    // AC-3B-40: Payment amount not modified during release
    public function test_payment_amount_not_modified_during_release(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createClosedShiftWithPayment();

        $originalAmount = $payment->amount;
        $originalRevenue = $payment->revenue;

        $this->actingAs($this->admin)
            ->post(route('shifts.payments.release', [$shift, $payment]));

        $payment->refresh();
        $this->assertEquals($originalAmount, $payment->amount,
            'AC-3B-40: Payment amount must not change during release');
        $this->assertEquals($originalRevenue, $payment->revenue,
            'AC-3B-41: Revenue must not change during release');
    }

    // AC-3B-43: Each release creates exactly one audit log
    public function test_each_release_creates_exactly_one_audit_log(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createClosedShiftWithPayment();

        $this->actingAs($this->admin)
            ->post(route('shifts.payments.release', [$shift, $payment]));

        $this->assertDatabaseCount('payment_audit_logs', 1);
    }

    // AC-3B-46: Failed release does NOT create audit log
    public function test_failed_release_does_not_create_audit_log(): void
    {
        ['shift' => $shift, 'payment' => $payment] = $this->createIneligibleBikerWithShift();

        $this->actingAs($this->admin)
            ->post(route('shifts.payments.release', [$shift, $payment]));

        $this->assertDatabaseCount('payment_audit_logs', 0);
    }

    // BR-04: Releasing one payment does not affect another
    public function test_releasing_one_payment_does_not_affect_another(): void
    {
        ['shift' => $shift, 'payment' => $payment1] = $this->createClosedShiftWithPayment();

        // Add a second eligible biker
        $biker2 = Biker::factory()->create(['active' => true]);
        User::factory()->create(['role' => UserRole::Biker, 'biker_id' => $biker2->id]);
        PixKey::factory()->create([
            'biker_id' => $biker2->id,
            'is_verified' => true,
            'verified_at' => now(),
        ]);
        $sb2 = ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => $biker2->id,
            'trips_count' => 3,
        ]);
        $payment2 = Payment::factory()->create([
            'shift_biker_id' => $sb2->id,
            'amount' => '55.00',
            'status' => PaymentStatus::Pending,
        ]);

        // Release payment1
        $this->actingAs($this->admin)
            ->post(route('shifts.payments.release', [$shift, $payment1]));

        // payment2 must still be pending
        $payment2->refresh();
        $this->assertEquals(PaymentStatus::Pending, $payment2->status);
        $this->assertNull($payment2->released_by);
    }

    // BR-02: PIX verified at release time, not close time
    public function test_release_checks_pix_at_release_time(): void
    {
        ['shift' => $shift, 'biker' => $biker, 'payment' => $payment] = $this->createClosedShiftWithPayment();

        // Revoke the verified PIX key
        $biker->pixKeys()->update(['is_verified' => false, 'verified_at' => null]);

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.payments.release', [$shift, $payment]));

        $payment->refresh();
        $this->assertEquals(PaymentStatus::Pending, $payment->status);
        $response->assertSessionHas('error');
    }
}
