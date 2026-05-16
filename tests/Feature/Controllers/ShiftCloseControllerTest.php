<?php

namespace Tests\Feature\Controllers;

use App\Enums\PaymentStatus;
use App\Enums\ShiftStatus;
use App\Enums\UserRole;
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
 * Feature Tests for Shift Close Controller (Two-Step Flow) — Phase 3A
 *
 * Covers the HTTP layer: GET review page, POST confirm close,
 * authorization, validation, payment creation, and view assertions.
 *
 * Acceptance Criteria: AC-3A-01 through AC-3A-44
 * Business Rules: BR-02, BR-03, BR-04, ADR-005 D1–D5
 *
 * @see docs/plans/phase-3a-shift-close-payout-calculation.md
 */
class ShiftCloseControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $restaurantManager;
    private User $bikerUser;
    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create([
            'rate_per_trip' => '15.00',
        ]);

        $this->admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $this->restaurantManager = User::factory()->create([
            'role' => UserRole::RestaurantManager,
            'restaurant_id' => $this->restaurant->id,
        ]);

        $biker = Biker::factory()->create();
        $this->bikerUser = User::factory()->create([
            'role' => UserRole::Biker,
            'biker_id' => $biker->id,
        ]);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private function createOpenShift(array $overrides = []): Shift
    {
        return Shift::factory()->started()->create(array_merge([
            'restaurant_id' => $this->restaurant->id,
            'restaurant_rate' => '15.00',
        ], $overrides));
    }

    private function createClosedShift(array $overrides = []): Shift
    {
        $shift = $this->createOpenShift($overrides);
        $shift->status = ShiftStatus::Closed;
        $shift->closed_at = now();
        $shift->save();

        return $shift->fresh();
    }

    private function assignBikerToShift(
        Shift $shift,
        array $bikerOverrides = [],
        array $pivotOverrides = [],
    ): ShiftBiker {
        $biker = Biker::factory()->create(array_merge([
            'rate_per_trip' => '10.00',
            'base_fee' => '25.00',
        ], $bikerOverrides));

        return ShiftBiker::factory()->create(array_merge([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 5,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ], $pivotOverrides));
    }

    // ========================================================================
    // AC-3A-01: GET close/review returns 200 for Admin on open shift
    // ========================================================================

    /**
     * AC-3A-01: Admin can access the close review page for an open shift.
     */
    public function test_review_close_returns_200_for_admin_on_open_shift(): void
    {
        $shift = $this->createOpenShift();

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.close.review', $shift));

        $response->assertOk();
        $response->assertViewIs('shifts.close-review');
    }

    // ========================================================================
    // AC-3A-02: GET close/review redirects non-Admin with 403
    // ========================================================================

    /**
     * AC-3A-02: Restaurant Manager gets 403 on review page.
     */
    public function test_review_close_returns_403_for_restaurant_manager(): void
    {
        $shift = $this->createOpenShift();

        $response = $this->actingAs($this->restaurantManager)
            ->get(route('shifts.close.review', $shift));

        $response->assertForbidden();
    }

    /**
     * AC-3A-02: Biker gets 403 on review page.
     */
    public function test_review_close_returns_403_for_biker(): void
    {
        $shift = $this->createOpenShift();

        $response = $this->actingAs($this->bikerUser)
            ->get(route('shifts.close.review', $shift));

        $response->assertForbidden();
    }

    /**
     * AC-3A-02: Unauthenticated user redirected to login on review page.
     */
    public function test_review_close_redirects_unauthenticated_to_login(): void
    {
        $shift = $this->createOpenShift();

        $response = $this->get(route('shifts.close.review', $shift));

        $response->assertRedirect(route('login'));
    }

    // ========================================================================
    // AC-3A-03: GET close/review redirects to shifts.show if shift is not open
    // ========================================================================

    /**
     * AC-3A-03: Review page redirects for draft shift.
     */
    public function test_review_close_redirects_for_draft_shift(): void
    {
        $shift = Shift::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'status' => 'draft',
            'restaurant_rate' => '15.00',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.close.review', $shift));

        $response->assertRedirect(route('shifts.show', $shift));
        $response->assertSessionHas('error');
    }

    /**
     * AC-3A-03: Review page redirects for already-closed shift.
     */
    public function test_review_close_redirects_for_closed_shift(): void
    {
        $shift = $this->createClosedShift();

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.close.review', $shift));

        $response->assertRedirect(route('shifts.show', $shift));
        $response->assertSessionHas('error');
    }

    // ========================================================================
    // AC-3A-04: Review view displays shift_biker details
    // ========================================================================

    /**
     * AC-3A-04: Review view displays biker name, trip count, biker_rate, base_fee.
     */
    public function test_review_view_displays_biker_details(): void
    {
        $shift = $this->createOpenShift();
        $biker = Biker::factory()->create(['name' => 'Carlos Souza']);
        ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 6,
            'biker_rate' => '12.00',
            'base_fee' => '30.00',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.close.review', $shift));

        $response->assertSee('Carlos Souza');
        $response->assertSee('12.00');
        $response->assertSee('30.00');
    }

    // ========================================================================
    // AC-3A-05: Review view displays projected payout per shift_biker
    // ========================================================================

    /**
     * AC-3A-05, BR-03: Review view shows projected payout per biker.
     */
    public function test_review_view_displays_projected_payout(): void
    {
        $shift = $this->createOpenShift();
        ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => Biker::factory()->create()->id,
            'trips_count' => 5,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.close.review', $shift));

        // Payout = 25.00 + (10.00 × 5) = 75.00
        $response->assertSee('75.00');
    }

    // ========================================================================
    // AC-3A-06: Review view displays projected revenue per shift_biker
    // ========================================================================

    /**
     * AC-3A-06: Review view shows projected revenue per biker.
     */
    public function test_review_view_displays_projected_revenue(): void
    {
        $shift = $this->createOpenShift(['restaurant_rate' => '20.00']);
        ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => Biker::factory()->create()->id,
            'trips_count' => 5,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.close.review', $shift));

        // Payout = 75.00, Revenue = (20.00×5) − 75.00 = 25.00
        $response->assertSee('25.00');
    }

    // ========================================================================
    // AC-3A-07: Review view displays total payout
    // ========================================================================

    /**
     * AC-3A-07: Review view displays total payout across all bikers.
     */
    public function test_review_view_displays_total_payout(): void
    {
        $shift = $this->createOpenShift();
        ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => Biker::factory()->create()->id,
            'trips_count' => 3,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);
        ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => Biker::factory()->create()->id,
            'trips_count' => 5,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.close.review', $shift));

        // Total = 55.00 + 75.00 = 130.00
        $response->assertSee('130.00');
    }

    // ========================================================================
    // AC-3A-08: Review view displays total revenue
    // ========================================================================

    /**
     * AC-3A-08: Review view displays total revenue across all bikers.
     */
    public function test_review_view_displays_total_revenue(): void
    {
        $shift = $this->createOpenShift(['restaurant_rate' => '20.00']);
        ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => Biker::factory()->create()->id,
            'trips_count' => 3,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);
        ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => Biker::factory()->create()->id,
            'trips_count' => 5,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.close.review', $shift));

        // Revenue 1: (20.00×3)−55.00 = 5.00, Revenue 2: (20.00×5)−75.00 = 25.00
        // Total revenue: 30.00
        $response->assertSee('30.00');
    }

    // ========================================================================
    // AC-3A-09: Review view shows warning for bikers without User account
    // ========================================================================

    /**
     * AC-3A-09, ADR-005 D4: Review view warns about biker without User account.
     */
    public function test_review_view_warns_about_biker_without_user_account(): void
    {
        $shift = $this->createOpenShift();
        $biker = Biker::factory()->create(['name' => 'No User Biker']);
        // No User created for this biker
        ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 3,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.close.review', $shift));

        $response->assertSee('conta de usuário');
    }

    // ========================================================================
    // AC-3A-10: Review view shows warning for bikers without verified PIX key
    // ========================================================================

    /**
     * AC-3A-10, BR-02: Review view warns about biker without verified PIX key.
     */
    public function test_review_view_warns_about_biker_without_verified_pix_key(): void
    {
        $shift = $this->createOpenShift();
        $biker = Biker::factory()->create();
        // Unverified PIX key
        PixKey::factory()->create([
            'biker_id' => $biker->id,
            'is_verified' => false,
        ]);
        ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 3,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.close.review', $shift));

        $response->assertSee('PIX');
    }

    // ========================================================================
    // AC-3A-11: Review view includes confirmation checkbox
    // ========================================================================

    /**
     * AC-3A-11, ADR-005 D5: Review view has "Confirmo que não há viagens contestadas" checkbox.
     */
    public function test_review_view_includes_confirmation_checkbox(): void
    {
        $shift = $this->createOpenShift();

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.close.review', $shift));

        $response->assertSee('confirmed');
        $response->assertSee('contest');
    }

    // ========================================================================
    // AC-3A-12: Review view includes submit button
    // ========================================================================

    /**
     * AC-3A-12: Review view has "Confirmar Encerramento" submit button.
     */
    public function test_review_view_includes_confirm_button(): void
    {
        $shift = $this->createOpenShift();

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.close.review', $shift));

        $response->assertSee('Confirmar Encerramento');
    }

    // ========================================================================
    // AC-3A-13: Review view shows empty state for shift with no bikers
    // ========================================================================

    /**
     * AC-3A-13: Review page shows empty state when shift has no bikers.
     */
    public function test_review_view_shows_empty_state_for_no_bikers(): void
    {
        $shift = $this->createOpenShift();

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.close.review', $shift));

        $response->assertOk();
        // Should show some kind of empty state message
        $content = $response->getContent();
        $this->assertTrue(
            str_contains($content, 'nenhum') ||
            str_contains($content, 'Nenhum') ||
            str_contains($content, 'vazio') ||
            str_contains($content, 'No bikers'),
            'AC-3A-13: Review view must show empty state when no bikers assigned'
        );
    }

    // ========================================================================
    // AC-3A-14: POST close transitions shift from open to closed
    // ========================================================================

    /**
     * AC-3A-14, ADR-005 D1: POST close with confirmed=1 transitions shift to closed.
     */
    public function test_confirm_close_transitions_shift_to_closed(): void
    {
        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, [], ['trips_count' => 3]);

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.close', $shift), ['confirmed' => 1]);

        $this->assertEquals(ShiftStatus::Closed, $shift->fresh()->status,
            'AC-3A-14: Shift must transition to closed');
    }

    // ========================================================================
    // AC-3A-15: POST close sets closed_at
    // ========================================================================

    /**
     * AC-3A-15: Confirm close sets closed_at to current timestamp.
     */
    public function test_confirm_close_sets_closed_at(): void
    {
        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, [], ['trips_count' => 3]);

        $before = now()->subSecond();
        $this->actingAs($this->admin)
            ->post(route('shifts.close', $shift), ['confirmed' => 1]);
        $after = now()->addSecond();

        $freshShift = $shift->fresh();
        $this->assertNotNull($freshShift->closed_at,
            'AC-3A-15: closed_at must be set');
        $this->assertTrue(
            $freshShift->closed_at->greaterThanOrEqualTo($before)
            && $freshShift->closed_at->lessThanOrEqualTo($after),
            'AC-3A-15: closed_at must be approximately current timestamp'
        );
    }

    // ========================================================================
    // AC-3A-16: POST close without confirmed returns validation error
    // ========================================================================

    /**
     * AC-3A-16, ADR-005 D5: POST close without confirmed field returns validation error.
     */
    public function test_confirm_close_without_confirmed_returns_validation_error(): void
    {
        $shift = $this->createOpenShift();

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.close', $shift), []);

        $response->assertSessionHasErrors('confirmed');
        $this->assertEquals(ShiftStatus::Open, $shift->fresh()->status,
            'AC-3A-16: Shift must remain open when confirmed is missing');
    }

    /**
     * AC-3A-16: POST close with confirmed=0 returns validation error.
     */
    public function test_confirm_close_with_confirmed_zero_returns_validation_error(): void
    {
        $shift = $this->createOpenShift();

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.close', $shift), ['confirmed' => 0]);

        $response->assertSessionHasErrors('confirmed');
        $this->assertEquals(ShiftStatus::Open, $shift->fresh()->status,
            'AC-3A-16: Shift must remain open when confirmed is 0');
    }

    // ========================================================================
    // AC-3A-17: POST close for non-open shift returns validation error
    // ========================================================================

    /**
     * AC-3A-17: POST close for closed shift returns validation error.
     */
    public function test_confirm_close_for_closed_shift_returns_validation_error(): void
    {
        $shift = $this->createClosedShift();

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.close', $shift), ['confirmed' => 1]);

        $response->assertSessionHasErrors();
    }

    /**
     * AC-3A-17: POST close for draft shift returns validation error.
     */
    public function test_confirm_close_for_draft_shift_returns_validation_error(): void
    {
        $shift = Shift::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'status' => 'draft',
            'restaurant_rate' => '15.00',
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.close', $shift), ['confirmed' => 1]);

        $response->assertSessionHasErrors();
    }

    // ========================================================================
    // AC-3A-18: POST close redirects non-Admin with 403
    // ========================================================================

    /**
     * AC-3A-18: Restaurant Manager gets 403 on confirm close.
     */
    public function test_confirm_close_returns_403_for_restaurant_manager(): void
    {
        $shift = $this->createOpenShift();

        $response = $this->actingAs($this->restaurantManager)
            ->post(route('shifts.close', $shift), ['confirmed' => 1]);

        $response->assertForbidden();
    }

    /**
     * AC-3A-18: Biker gets 403 on confirm close.
     */
    public function test_confirm_close_returns_403_for_biker(): void
    {
        $shift = $this->createOpenShift();

        $response = $this->actingAs($this->bikerUser)
            ->post(route('shifts.close', $shift), ['confirmed' => 1]);

        $response->assertForbidden();
    }

    // ========================================================================
    // AC-3A-19: Successful close redirects to shifts.show with success message
    // ========================================================================

    /**
     * AC-3A-19: Successful close redirects to shifts.show with success flash.
     */
    public function test_confirm_close_redirects_to_show_with_success(): void
    {
        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, [], ['trips_count' => 3]);

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.close', $shift), ['confirmed' => 1]);

        $response->assertRedirect(route('shifts.show', $shift));
        $response->assertSessionHas('success');
    }

    // ========================================================================
    // AC-3A-20: Payment creation — one per shift_biker
    // ========================================================================

    /**
     * AC-3A-20, BR-04: Confirm close creates one Payment per shift_biker.
     */
    public function test_confirm_close_creates_payment_per_shift_biker(): void
    {
        $shift = $this->createOpenShift();
        $sb1 = $this->assignBikerToShift($shift, [], ['trips_count' => 3]);
        $sb2 = $this->assignBikerToShift($shift, [], ['trips_count' => 5]);

        $this->actingAs($this->admin)
            ->post(route('shifts.close', $shift), ['confirmed' => 1]);

        $this->assertEquals(2, Payment::count(),
            'AC-3A-20: Must create exactly 2 Payment rows');
        $this->assertDatabaseHas('payments', ['shift_biker_id' => $sb1->id]);
        $this->assertDatabaseHas('payments', ['shift_biker_id' => $sb2->id]);
    }

    // ========================================================================
    // AC-3A-21: Payment amount correctness via HTTP
    // ========================================================================

    /**
     * AC-3A-21, BR-03: Payment amount matches PayoutService via full HTTP flow.
     */
    public function test_confirm_close_payment_amount_matches_formula(): void
    {
        $shift = $this->createOpenShift();
        $sb = $this->assignBikerToShift($shift, [], [
            'trips_count' => 5,
            'base_fee' => '25.00',
            'biker_rate' => '10.00',
        ]);

        $this->actingAs($this->admin)
            ->post(route('shifts.close', $shift), ['confirmed' => 1]);

        $payment = Payment::where('shift_biker_id', $sb->id)->first();
        $this->assertEquals('75.00', $payment->amount,
            'AC-3A-21: Payment amount must be 25.00 + (10.00×5) = 75.00');
    }

    // ========================================================================
    // AC-3A-22: Payment revenue correctness via HTTP
    // ========================================================================

    /**
     * AC-3A-22: Payment revenue matches RevenueService via full HTTP flow.
     */
    public function test_confirm_close_payment_revenue_matches_formula(): void
    {
        $shift = $this->createOpenShift(['restaurant_rate' => '20.00']);
        $sb = $this->assignBikerToShift($shift, [], [
            'trips_count' => 5,
            'base_fee' => '25.00',
            'biker_rate' => '10.00',
        ]);

        $this->actingAs($this->admin)
            ->post(route('shifts.close', $shift), ['confirmed' => 1]);

        $payment = Payment::where('shift_biker_id', $sb->id)->first();
        // Revenue = (20.00 × 5) − 75.00 = 25.00
        $this->assertEquals('25.00', $payment->revenue,
            'AC-3A-22: Payment revenue must be (20.00×5) − 75.00 = 25.00');
    }

    // ========================================================================
    // AC-3A-23: Payments have status = 'pending'
    // ========================================================================

    /**
     * AC-3A-23, BR-03: Created Payments have pending status.
     */
    public function test_confirm_close_payments_have_pending_status(): void
    {
        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, [], ['trips_count' => 3]);

        $this->actingAs($this->admin)
            ->post(route('shifts.close', $shift), ['confirmed' => 1]);

        $this->assertEquals(1, Payment::where('status', 'pending')->count(),
            'AC-3A-23: All created Payments must have pending status');
    }

    // ========================================================================
    // AC-3A-24: Zero-trip Payment has amount='0.00', revenue='0.00'
    // ========================================================================

    /**
     * AC-3A-24, BR-03: Zero-trip biker gets Payment with amount='0.00' and revenue='0.00'.
     */
    public function test_confirm_close_zero_trips_payment_is_zero(): void
    {
        $shift = $this->createOpenShift();
        $sb = $this->assignBikerToShift($shift, [], [
            'trips_count' => 0,
            'base_fee' => '25.00',
            'biker_rate' => '10.00',
        ]);

        $this->actingAs($this->admin)
            ->post(route('shifts.close', $shift), ['confirmed' => 1]);

        $payment = Payment::where('shift_biker_id', $sb->id)->first();
        $this->assertNotNull($payment, 'AC-3A-24: Payment must exist for 0-trip biker');
        $this->assertEquals('0.00', $payment->amount,
            'AC-3A-24: Zero-trip amount must be 0.00');
        $this->assertEquals('0.00', $payment->revenue,
            'AC-3A-24: Zero-trip revenue must be 0.00');
    }

    // ========================================================================
    // AC-3A-25: Payment amount follows payout formula
    // ========================================================================

    /**
     * AC-3A-25: Payment for >0 trips: amount = base_fee + (biker_rate × trips_count).
     */
    public function test_confirm_close_payout_formula_integration(): void
    {
        $shift = $this->createOpenShift();
        $sb = $this->assignBikerToShift($shift, [], [
            'trips_count' => 8,
            'base_fee' => '30.00',
            'biker_rate' => '12.50',
        ]);

        $this->actingAs($this->admin)
            ->post(route('shifts.close', $shift), ['confirmed' => 1]);

        $payment = Payment::where('shift_biker_id', $sb->id)->first();
        // 30.00 + (12.50 × 8) = 130.00
        $this->assertEquals('130.00', $payment->amount,
            'AC-3A-25: Payout must be 30.00 + (12.50×8) = 130.00');
    }

    // ========================================================================
    // AC-3A-26: Revenue follows revenue formula
    // ========================================================================

    /**
     * AC-3A-26: Revenue = (restaurant_rate × trips_count) − amount.
     */
    public function test_confirm_close_revenue_formula_integration(): void
    {
        $shift = $this->createOpenShift(['restaurant_rate' => '20.00']);
        $sb = $this->assignBikerToShift($shift, [], [
            'trips_count' => 8,
            'base_fee' => '30.00',
            'biker_rate' => '12.50',
        ]);

        $this->actingAs($this->admin)
            ->post(route('shifts.close', $shift), ['confirmed' => 1]);

        $payment = Payment::where('shift_biker_id', $sb->id)->first();
        // Revenue = (20.00 × 8) − 130.00 = 160.00 − 130.00 = 30.00
        $this->assertEquals('30.00', $payment->revenue,
            'AC-3A-26: Revenue must be (20.00×8) − 130.00 = 30.00');
    }

    // ========================================================================
    // AC-3A-27: No duplicate Payments (idempotency)
    // ========================================================================

    /**
     * AC-3A-27: Second confirm close attempt does not create duplicates.
     */
    public function test_confirm_close_idempotency_no_duplicate_payments(): void
    {
        $shift = $this->createOpenShift();
        $sb = $this->assignBikerToShift($shift, [], ['trips_count' => 3]);

        // First close
        $this->actingAs($this->admin)
            ->post(route('shifts.close', $shift), ['confirmed' => 1]);

        // Second close attempt (shift is already closed)
        $this->actingAs($this->admin)
            ->post(route('shifts.close', $shift->fresh()), ['confirmed' => 1]);

        $count = Payment::where('shift_biker_id', $sb->id)->count();
        $this->assertEquals(1, $count,
            'AC-3A-27: No duplicate Payment rows after double close attempt');
    }

    // ========================================================================
    // AC-3A-28: Close shift with zero bikers creates zero Payments
    // ========================================================================

    /**
     * AC-3A-28: Shift with no bikers → 0 Payment rows, shift still closes.
     */
    public function test_confirm_close_with_zero_bikers_creates_zero_payments(): void
    {
        $shift = $this->createOpenShift();

        $this->actingAs($this->admin)
            ->post(route('shifts.close', $shift), ['confirmed' => 1]);

        $this->assertEquals(0, Payment::count(),
            'AC-3A-28: Zero Payment rows for shift with no bikers');
        $this->assertEquals(ShiftStatus::Closed, $shift->fresh()->status,
            'AC-3A-28: Shift must still transition to closed');
    }

    // ========================================================================
    // AC-3A-31: Eligibility warnings don't block Payment creation
    // ========================================================================

    /**
     * AC-3A-31: Payment created for biker without User account.
     */
    public function test_payment_created_for_biker_without_user_via_http(): void
    {
        $shift = $this->createOpenShift();
        $biker = Biker::factory()->create(); // No User linked
        $sb = ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 3,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);

        $this->actingAs($this->admin)
            ->post(route('shifts.close', $shift), ['confirmed' => 1]);

        $this->assertDatabaseHas('payments', ['shift_biker_id' => $sb->id]);
    }

    // ========================================================================
    // AC-3A-32: Eligibility warnings are purely informational
    // ========================================================================

    /**
     * AC-3A-32: Warnings are display-only — they do not block the close.
     */
    public function test_eligibility_warnings_do_not_block_close(): void
    {
        $shift = $this->createOpenShift();
        // Biker without User AND without verified PIX
        $biker = Biker::factory()->create();
        PixKey::factory()->create(['biker_id' => $biker->id, 'is_verified' => false]);
        ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 3,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.close', $shift), ['confirmed' => 1]);

        $response->assertRedirect(route('shifts.show', $shift));
        $response->assertSessionHas('success');
        $this->assertEquals(ShiftStatus::Closed, $shift->fresh()->status,
            'AC-3A-32: Close must succeed despite warnings');
    }

    // ========================================================================
    // AC-3A-37: Revenue can be negative (stored correctly)
    // ========================================================================

    /**
     * AC-3A-37: Negative revenue is stored correctly via HTTP flow.
     */
    public function test_negative_revenue_stored_via_http(): void
    {
        $shift = $this->createOpenShift(['restaurant_rate' => '5.00']);
        $sb = $this->assignBikerToShift($shift, [], [
            'trips_count' => 5,
            'base_fee' => '25.00',
            'biker_rate' => '10.00',
        ]);

        $this->actingAs($this->admin)
            ->post(route('shifts.close', $shift), ['confirmed' => 1]);

        $payment = Payment::where('shift_biker_id', $sb->id)->first();
        // Revenue = (5.00 × 5) − 75.00 = -50.00
        $this->assertEquals('-50.00', $payment->revenue,
            'AC-3A-37: Negative revenue -50.00 must be stored correctly');
    }

    // ========================================================================
    // AC-3A-38: closed_at is never NULL after close
    // ========================================================================

    /**
     * AC-3A-38: closed_at is set after close via HTTP.
     */
    public function test_closed_at_set_via_http(): void
    {
        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, [], ['trips_count' => 3]);

        $this->actingAs($this->admin)
            ->post(route('shifts.close', $shift), ['confirmed' => 1]);

        $this->assertNotNull($shift->fresh()->closed_at,
            'AC-3A-38: closed_at must be set after successful close');
    }

    // ========================================================================
    // AC-3A-42: Open shift show page links to review (not direct close)
    // ========================================================================

    /**
     * AC-3A-42: Open shift show page links to GET close/review, not POST close.
     */
    public function test_show_page_links_to_review_for_open_shift(): void
    {
        $shift = $this->createOpenShift();

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.show', $shift));

        $response->assertSee(route('shifts.close.review', $shift));
    }

    // ========================================================================
    // AC-3A-43: Closed shift show page displays Payment rows
    // ========================================================================

    /**
     * AC-3A-43: Closed shift show page displays Payment amount, revenue, status.
     */
    public function test_show_page_displays_payments_for_closed_shift(): void
    {
        $shift = $this->createOpenShift(['restaurant_rate' => '20.00']);
        $biker = Biker::factory()->create(['name' => 'Maria Santos']);
        ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 5,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);

        // Close the shift
        $this->actingAs($this->admin)
            ->post(route('shifts.close', $shift), ['confirmed' => 1]);

        // View the closed shift
        $response = $this->actingAs($this->admin)
            ->get(route('shifts.show', $shift->fresh()));

        // Payout = 75.00, Revenue = 25.00
        $response->assertSee('75.00');
        $response->assertSee('25.00');
        $response->assertSee('pending');
    }

    // ========================================================================
    // AC-3A-44: Closed shift shows payout and revenue per biker
    // ========================================================================

    /**
     * AC-3A-44: Closed shift biker-assignments partial shows payout and revenue.
     */
    public function test_show_page_displays_payout_and_revenue_per_biker(): void
    {
        $shift = $this->createOpenShift(['restaurant_rate' => '20.00']);
        $biker = Biker::factory()->create(['name' => 'Pedro Lima']);
        ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 3,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);

        // Close the shift
        $this->actingAs($this->admin)
            ->post(route('shifts.close', $shift), ['confirmed' => 1]);

        // View the closed shift
        $response = $this->actingAs($this->admin)
            ->get(route('shifts.show', $shift->fresh()));

        // Payout = 25.00 + (10.00×3) = 55.00, Revenue = (20.00×3) − 55.00 = 5.00
        $response->assertSee('55.00');
        $response->assertSee('5.00');
    }

    // ========================================================================
    // Edge Case: Concurrent close attempts
    // ========================================================================

    /**
     * Edge Case 7: Second concurrent close gets validation error.
     */
    public function test_concurrent_close_attempt_rejected(): void
    {
        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, [], ['trips_count' => 3]);

        // First close succeeds
        $this->actingAs($this->admin)
            ->post(route('shifts.close', $shift), ['confirmed' => 1]);

        // Second close fails (shift already closed)
        $response = $this->actingAs($this->admin)
            ->post(route('shifts.close', $shift->fresh()), ['confirmed' => 1]);

        $response->assertSessionHasErrors();
    }

    // ========================================================================
    // Edge Case: Deactivated biker still gets Payment
    // ========================================================================

    /**
     * Edge Case 3, ADR-005 D3: Deactivated biker gets Payment via HTTP flow.
     */
    public function test_deactivated_biker_gets_payment_via_http(): void
    {
        $shift = $this->createOpenShift();
        $biker = Biker::factory()->create(['active' => false]);
        $sb = ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 4,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);

        $this->actingAs($this->admin)
            ->post(route('shifts.close', $shift), ['confirmed' => 1]);

        $payment = Payment::where('shift_biker_id', $sb->id)->first();
        $this->assertNotNull($payment,
            'Edge Case 3: Deactivated biker must still get Payment');
        $this->assertEquals('65.00', $payment->amount,
            'Edge Case 3: Payout for deactivated biker = 25.00 + (10.00×4) = 65.00');
    }

    // ========================================================================
    // Edge Case: Admin navigates away — no state change
    // ========================================================================

    /**
     * Edge Case 12: Viewing review page does not change shift state.
     */
    public function test_review_page_does_not_change_shift_state(): void
    {
        $shift = $this->createOpenShift();

        $this->actingAs($this->admin)
            ->get(route('shifts.close.review', $shift));

        $this->assertEquals(ShiftStatus::Open, $shift->fresh()->status,
            'Edge Case 12: Review page must NOT change shift status');
        $this->assertNull($shift->fresh()->closed_at,
            'Edge Case 12: Review page must NOT set closed_at');
        $this->assertEquals(0, Payment::count(),
            'Edge Case 12: Review page must NOT create Payment rows');
    }

    // ========================================================================
    // Policy: reviewClose authorization
    // ========================================================================

    /**
     * AC-3A-02: ShiftPolicy@reviewClose returns true for admin.
     */
    public function test_policy_review_close_allows_admin(): void
    {
        $shift = $this->createOpenShift();

        $this->assertTrue(
            $this->admin->can('reviewClose', $shift),
            'Policy: Admin must be able to reviewClose'
        );
    }

    /**
     * AC-3A-02: ShiftPolicy@reviewClose returns false for restaurant manager.
     */
    public function test_policy_review_close_denies_restaurant_manager(): void
    {
        $shift = $this->createOpenShift();

        $this->assertFalse(
            $this->restaurantManager->can('reviewClose', $shift),
            'Policy: Restaurant Manager must NOT be able to reviewClose'
        );
    }

    /**
     * AC-3A-02: ShiftPolicy@reviewClose returns false for biker.
     */
    public function test_policy_review_close_denies_biker(): void
    {
        $shift = $this->createOpenShift();

        $this->assertFalse(
            $this->bikerUser->can('reviewClose', $shift),
            'Policy: Biker must NOT be able to reviewClose'
        );
    }

    // ========================================================================
    // Route existence checks
    // ========================================================================

    /**
     * AC-3A-01: Route shifts.close.review exists.
     */
    public function test_close_review_route_exists(): void
    {
        $shift = $this->createOpenShift();

        // This will throw if the route doesn't exist
        $url = route('shifts.close.review', $shift);
        $this->assertStringContainsString("/shifts/{$shift->id}/close/review", $url,
            'Route shifts.close.review must follow the pattern shifts/{shift}/close/review');
    }

    // ========================================================================
    // BCMath precision in HTTP context
    // ========================================================================

    /**
     * AC-3A-39: Decimal precision maintained through full HTTP round-trip.
     */
    public function test_decimal_precision_maintained_via_http(): void
    {
        $shift = $this->createOpenShift(['restaurant_rate' => '12.50']);
        $sb = $this->assignBikerToShift($shift, [], [
            'trips_count' => 7,
            'base_fee' => '25.00',
            'biker_rate' => '12.50',
        ]);

        $this->actingAs($this->admin)
            ->post(route('shifts.close', $shift), ['confirmed' => 1]);

        $payment = Payment::where('shift_biker_id', $sb->id)->first();
        // Payout = 25.00 + (12.50 × 7) = 25.00 + 87.50 = 112.50
        $this->assertEquals('112.50', $payment->amount,
            'AC-3A-39: Decimal precision maintained — payout = 112.50');
        // Revenue = (12.50 × 7) − 112.50 = 87.50 − 112.50 = -25.00
        $this->assertEquals('-25.00', $payment->revenue,
            'AC-3A-39: Decimal precision maintained — revenue = -25.00');
    }
}
