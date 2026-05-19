<?php

namespace Tests\Feature\Controllers;

use App\Enums\ShiftStatus;
use App\Enums\UserRole;
use App\Models\Biker;
use App\Models\Restaurant;
use App\Models\Shift;
use App\Models\ShiftBiker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ShiftBiker Controller Feature Tests — Phase 2C
 *
 * Comprehensive test suite for Shift-Biker Assignment CRUD operations.
 * Covers all acceptance criteria AC-2C-01 through AC-2C-46.
 *
 * Business Rules: BR-01 (Workflow Locking — status constraints), BR-05 (Admin-only biker management)
 *
 * @see docs/plans/phase-2c-shift-biker-assignment.md
 */
class ShiftBikerControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $restaurantManager;

    private User $bikerUser;

    private Restaurant $restaurant;

    private Biker $activeBiker;

    private Biker $inactiveBiker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create([
            'name' => 'Test Restaurant',
            'rate_per_trip' => '15.00',
            'active' => true,
        ]);

        $this->admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $this->restaurantManager = User::factory()->create([
            'role' => UserRole::RestaurantManager,
            'restaurant_id' => $this->restaurant->id,
        ]);

        $this->activeBiker = Biker::factory()->create([
            'name' => 'Active Biker',
            'rate_per_trip' => '10.00',
            'base_fee' => '25.00',
            'active' => true,
        ]);

        $this->inactiveBiker = Biker::factory()->create([
            'name' => 'Inactive Biker',
            'rate_per_trip' => '12.00',
            'base_fee' => '30.00',
            'active' => false,
        ]);

        $bikerProfile = Biker::factory()->create();
        $this->bikerUser = User::factory()->create([
            'role' => UserRole::Biker,
            'biker_id' => $bikerProfile->id,
        ]);
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    private function createDraftShift(array $overrides = []): Shift
    {
        return Shift::factory()->create(array_merge([
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
            'status' => 'draft',
            'restaurant_rate' => '15.00',
        ], $overrides));
    }

    private function createOpenShift(array $overrides = []): Shift
    {
        return Shift::factory()->started()->create(array_merge([
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
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

    private function createApprovedShift(array $overrides = []): Shift
    {
        $shift = $this->createClosedShift($overrides);
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

    private function assignBikerToShift(Shift $shift, Biker $biker, array $overrides = []): ShiftBiker
    {
        return ShiftBiker::factory()->create(array_merge([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 0,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ], $overrides));
    }

    // ========================================================================
    // ROUTE & AUTHORIZATION (AC-2C-01 through AC-2C-07)
    // ========================================================================

    // --- AC-2C-01: GET /shifts/{shift}/bikers returns 200 for admin ---

    /**
     * AC-2C-01: Admin can access the shift-biker index route.
     */
    public function test_index_returns_200_for_admin(): void
    {
        $shift = $this->createDraftShift();

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.bikers.index', $shift));

        $response->assertOk();
    }

    // --- AC-2C-02: POST /shifts/{shift}/bikers (store) returns redirect ---

    /**
     * AC-2C-02: Admin store action redirects on success.
     */
    public function test_store_returns_redirect_for_admin_on_valid_data(): void
    {
        $shift = $this->createDraftShift();

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.bikers.store', $shift), [
                'biker_id' => $this->activeBiker->id,
                'biker_rate' => '10.00',
                'base_fee' => '25.00',
            ]);

        $response->assertRedirect(route('shifts.show', $shift));
    }

    // --- AC-2C-03: PATCH /shifts/{shift}/bikers/{biker} returns redirect ---

    /**
     * AC-2C-03: Admin update action redirects on success.
     */
    public function test_update_returns_redirect_for_admin_on_valid_data(): void
    {
        $shift = $this->createDraftShift();
        $shiftBiker = $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->admin)
            ->patch(route('shifts.bikers.update', [$shift, $shiftBiker]), [
                'biker_rate' => '12.50',
            ]);

        $response->assertRedirect(route('shifts.show', $shift));
    }

    // --- AC-2C-04: DELETE /shifts/{shift}/bikers/{biker} returns redirect ---

    /**
     * AC-2C-04: Admin destroy action redirects on success.
     */
    public function test_destroy_returns_redirect_for_admin(): void
    {
        $shift = $this->createDraftShift();
        $shiftBiker = $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->admin)
            ->delete(route('shifts.bikers.destroy', [$shift, $shiftBiker]));

        $response->assertRedirect(route('shifts.show', $shift));
    }

    // --- AC-2C-05: All shift-biker routes require authentication ---

    /**
     * AC-2C-05: GET index requires authentication.
     */
    public function test_index_requires_authentication(): void
    {
        $shift = $this->createDraftShift();

        $response = $this->get(route('shifts.bikers.index', $shift));

        $response->assertRedirect(route('login'));
    }

    /**
     * AC-2C-05: POST store requires authentication.
     */
    public function test_store_requires_authentication(): void
    {
        $shift = $this->createDraftShift();

        $response = $this->post(route('shifts.bikers.store', $shift), [
            'biker_id' => $this->activeBiker->id,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);

        $response->assertRedirect(route('login'));
    }

    /**
     * AC-2C-05: PATCH update requires authentication.
     */
    public function test_update_requires_authentication(): void
    {
        $shift = $this->createDraftShift();
        $shiftBiker = $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->patch(route('shifts.bikers.update', [$shift, $shiftBiker]), [
            'biker_rate' => '12.00',
        ]);

        $response->assertRedirect(route('login'));
    }

    /**
     * AC-2C-05: DELETE destroy requires authentication.
     */
    public function test_destroy_requires_authentication(): void
    {
        $shift = $this->createDraftShift();
        $shiftBiker = $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->delete(route('shifts.bikers.destroy', [$shift, $shiftBiker]));

        $response->assertRedirect(route('login'));
    }

    // --- AC-2C-06: Non-admin (RestaurantManager) receives 403 ---

    /**
     * AC-2C-06: RestaurantManager receives 403 on index.
     */
    public function test_index_returns_403_for_restaurant_manager(): void
    {
        $shift = $this->createDraftShift();

        $response = $this->actingAs($this->restaurantManager)
            ->get(route('shifts.bikers.index', $shift));

        $response->assertForbidden();
    }

    /**
     * AC-2C-06: RestaurantManager receives 403 on store.
     */
    public function test_store_returns_403_for_restaurant_manager(): void
    {
        $shift = $this->createDraftShift();

        $response = $this->actingAs($this->restaurantManager)
            ->post(route('shifts.bikers.store', $shift), [
                'biker_id' => $this->activeBiker->id,
                'biker_rate' => '10.00',
                'base_fee' => '25.00',
            ]);

        $response->assertForbidden();
    }

    /**
     * AC-2C-06: RestaurantManager receives 403 on update.
     */
    public function test_update_returns_403_for_restaurant_manager(): void
    {
        $shift = $this->createDraftShift();
        $shiftBiker = $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->restaurantManager)
            ->patch(route('shifts.bikers.update', [$shift, $shiftBiker]), [
                'biker_rate' => '12.00',
            ]);

        $response->assertForbidden();
    }

    /**
     * AC-2C-06: RestaurantManager receives 403 on destroy.
     */
    public function test_destroy_returns_403_for_restaurant_manager(): void
    {
        $shift = $this->createDraftShift();
        $shiftBiker = $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->restaurantManager)
            ->delete(route('shifts.bikers.destroy', [$shift, $shiftBiker]));

        $response->assertForbidden();
    }

    // --- AC-2C-07: Non-admin (Biker) receives 403 ---

    /**
     * AC-2C-07: Biker user receives 403 on index.
     */
    public function test_index_returns_403_for_biker_user(): void
    {
        $shift = $this->createDraftShift();

        $response = $this->actingAs($this->bikerUser)
            ->get(route('shifts.bikers.index', $shift));

        $response->assertForbidden();
    }

    /**
     * AC-2C-07: Biker user receives 403 on store.
     */
    public function test_store_returns_403_for_biker_user(): void
    {
        $shift = $this->createDraftShift();

        $response = $this->actingAs($this->bikerUser)
            ->post(route('shifts.bikers.store', $shift), [
                'biker_id' => $this->activeBiker->id,
                'biker_rate' => '10.00',
                'base_fee' => '25.00',
            ]);

        $response->assertForbidden();
    }

    /**
     * AC-2C-07: Biker user receives 403 on update.
     */
    public function test_update_returns_403_for_biker_user(): void
    {
        $shift = $this->createDraftShift();
        $shiftBiker = $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->bikerUser)
            ->patch(route('shifts.bikers.update', [$shift, $shiftBiker]), [
                'biker_rate' => '12.00',
            ]);

        $response->assertForbidden();
    }

    /**
     * AC-2C-07: Biker user receives 403 on destroy.
     */
    public function test_destroy_returns_403_for_biker_user(): void
    {
        $shift = $this->createDraftShift();
        $shiftBiker = $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->bikerUser)
            ->delete(route('shifts.bikers.destroy', [$shift, $shiftBiker]));

        $response->assertForbidden();
    }

    // ========================================================================
    // STORE — Assign Biker (AC-2C-08 through AC-2C-22)
    // ========================================================================

    // --- AC-2C-08: Admin can assign biker to draft shift ---

    /**
     * AC-2C-08: Admin assigns an active biker to a draft shift.
     * BR-01: Draft status allows biker assignment.
     */
    public function test_assign_biker_to_draft_shift_creates_record(): void
    {
        $shift = $this->createDraftShift();

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.bikers.store', $shift), [
                'biker_id' => $this->activeBiker->id,
                'biker_rate' => '10.00',
                'base_fee' => '25.00',
            ]);

        $this->assertDatabaseHas('shift_bikers', [
            'shift_id' => $shift->id,
            'biker_id' => $this->activeBiker->id,
        ]);
    }

    // --- AC-2C-09: Admin can assign biker to open shift ---

    /**
     * AC-2C-09: Admin assigns an active biker to an open shift.
     * BR-01: Open status allows biker assignment.
     */
    public function test_assign_biker_to_open_shift_creates_record(): void
    {
        $shift = $this->createOpenShift();

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.bikers.store', $shift), [
                'biker_id' => $this->activeBiker->id,
                'biker_rate' => '10.00',
                'base_fee' => '25.00',
            ]);

        $this->assertDatabaseHas('shift_bikers', [
            'shift_id' => $shift->id,
            'biker_id' => $this->activeBiker->id,
        ]);
    }

    // --- AC-2C-10: Assignment creates with trips_count=0 and provided financial values ---

    /**
     * AC-2C-10: New assignment has trips_count=0, biker_rate and base_fee from form input.
     */
    public function test_assign_biker_sets_trips_count_zero_and_financial_fields(): void
    {
        $shift = $this->createDraftShift();

        $this->actingAs($this->admin)
            ->post(route('shifts.bikers.store', $shift), [
                'biker_id' => $this->activeBiker->id,
                'biker_rate' => '13.50',
                'base_fee' => '30.00',
            ]);

        $this->assertDatabaseHas('shift_bikers', [
            'shift_id' => $shift->id,
            'biker_id' => $this->activeBiker->id,
            'trips_count' => 0,
            'biker_rate' => '13.50',
            'base_fee' => '30.00',
        ]);
    }

    // --- AC-2C-11: Default biker_rate from Biker model when omitted ---

    /**
     * AC-2C-11: When biker_rate is omitted, it defaults to the Biker's rate_per_trip.
     */
    public function test_assign_biker_defaults_biker_rate_from_biker_model(): void
    {
        $shift = $this->createDraftShift();

        $this->actingAs($this->admin)
            ->post(route('shifts.bikers.store', $shift), [
                'biker_id' => $this->activeBiker->id,
                'base_fee' => '25.00',
            ]);

        // Biker's rate_per_trip is 10.00
        $this->assertDatabaseHas('shift_bikers', [
            'shift_id' => $shift->id,
            'biker_id' => $this->activeBiker->id,
            'biker_rate' => '10.00',
        ]);
    }

    // --- AC-2C-12: Default base_fee from Biker model when omitted ---

    /**
     * AC-2C-12: When base_fee is omitted, it defaults to the Biker's base_fee.
     */
    public function test_assign_biker_defaults_base_fee_from_biker_model(): void
    {
        $shift = $this->createDraftShift();

        $this->actingAs($this->admin)
            ->post(route('shifts.bikers.store', $shift), [
                'biker_id' => $this->activeBiker->id,
                'biker_rate' => '10.00',
            ]);

        // Biker's base_fee is 25.00
        $this->assertDatabaseHas('shift_bikers', [
            'shift_id' => $shift->id,
            'biker_id' => $this->activeBiker->id,
            'base_fee' => '25.00',
        ]);
    }

    // --- AC-2C-13: Success redirect and flash ---

    /**
     * AC-2C-13: After successful assignment, redirected to shifts.show with success flash.
     */
    public function test_assign_biker_redirects_to_show_with_success_flash(): void
    {
        $shift = $this->createDraftShift();

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.bikers.store', $shift), [
                'biker_id' => $this->activeBiker->id,
                'biker_rate' => '10.00',
                'base_fee' => '25.00',
            ]);

        $response->assertRedirect(route('shifts.show', $shift));
        $response->assertSessionHas('success');
    }

    // --- AC-2C-14: Assigning to closed shift returns validation error ---

    /**
     * AC-2C-14: Cannot assign a biker to a closed shift.
     * BR-01: Only draft and open shifts accept biker assignments.
     */
    public function test_assign_biker_to_closed_shift_returns_validation_error(): void
    {
        $shift = $this->createClosedShift();

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.bikers.store', $shift), [
                'biker_id' => $this->activeBiker->id,
                'biker_rate' => '10.00',
                'base_fee' => '25.00',
            ]);

        $response->assertSessionHasErrors();

        $this->assertDatabaseMissing('shift_bikers', [
            'shift_id' => $shift->id,
            'biker_id' => $this->activeBiker->id,
        ]);
    }

    // --- AC-2C-15: Assigning to approved shift returns validation error ---

    /**
     * AC-2C-15: Cannot assign a biker to an approved shift.
     * BR-01: Only draft and open shifts accept biker assignments.
     */
    public function test_assign_biker_to_approved_shift_returns_validation_error(): void
    {
        $shift = $this->createApprovedShift();

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.bikers.store', $shift), [
                'biker_id' => $this->activeBiker->id,
                'biker_rate' => '10.00',
                'base_fee' => '25.00',
            ]);

        $response->assertSessionHasErrors();

        $this->assertDatabaseMissing('shift_bikers', [
            'shift_id' => $shift->id,
            'biker_id' => $this->activeBiker->id,
        ]);
    }

    // --- AC-2C-16: Assigning to paid shift returns validation error ---

    /**
     * AC-2C-16: Cannot assign a biker to a paid shift.
     * BR-01: Only draft and open shifts accept biker assignments.
     */
    public function test_assign_biker_to_paid_shift_returns_validation_error(): void
    {
        $shift = $this->createPaidShift();

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.bikers.store', $shift), [
                'biker_id' => $this->activeBiker->id,
                'biker_rate' => '10.00',
                'base_fee' => '25.00',
            ]);

        $response->assertSessionHasErrors();

        $this->assertDatabaseMissing('shift_bikers', [
            'shift_id' => $shift->id,
            'biker_id' => $this->activeBiker->id,
        ]);
    }

    // --- AC-2C-17: Duplicate biker assignment returns validation error ---

    /**
     * AC-2C-17: Assigning the same biker twice to the same shift returns validation error.
     */
    public function test_duplicate_biker_assignment_returns_validation_error(): void
    {
        $shift = $this->createDraftShift();

        // First assignment succeeds
        $this->actingAs($this->admin)
            ->post(route('shifts.bikers.store', $shift), [
                'biker_id' => $this->activeBiker->id,
                'biker_rate' => '10.00',
                'base_fee' => '25.00',
            ]);

        // Second assignment of the same biker must fail
        $response = $this->actingAs($this->admin)
            ->post(route('shifts.bikers.store', $shift), [
                'biker_id' => $this->activeBiker->id,
                'biker_rate' => '10.00',
                'base_fee' => '25.00',
            ]);

        $response->assertSessionHasErrors('biker_id');
    }

    // --- AC-2C-18: Non-existent biker_id returns validation error ---

    /**
     * AC-2C-18: Assigning a non-existent biker_id returns validation error.
     */
    public function test_assign_nonexistent_biker_returns_validation_error(): void
    {
        $shift = $this->createDraftShift();

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.bikers.store', $shift), [
                'biker_id' => 99999,
                'biker_rate' => '10.00',
                'base_fee' => '25.00',
            ]);

        $response->assertSessionHasErrors('biker_id');
    }

    // --- AC-2C-19: Inactive biker returns validation error ---

    /**
     * AC-2C-19: Assigning an inactive biker returns validation error.
     */
    public function test_assign_inactive_biker_returns_validation_error(): void
    {
        $shift = $this->createDraftShift();

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.bikers.store', $shift), [
                'biker_id' => $this->inactiveBiker->id,
                'biker_rate' => '10.00',
                'base_fee' => '25.00',
            ]);

        $response->assertSessionHasErrors('biker_id');
    }

    // --- AC-2C-20: Missing biker_id returns validation error ---

    /**
     * AC-2C-20: Missing biker_id returns validation error.
     */
    public function test_assign_without_biker_id_returns_validation_error(): void
    {
        $shift = $this->createDraftShift();

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.bikers.store', $shift), [
                'biker_rate' => '10.00',
                'base_fee' => '25.00',
            ]);

        $response->assertSessionHasErrors('biker_id');
    }

    // --- AC-2C-21: Negative biker_rate returns validation error ---

    /**
     * AC-2C-21: Negative biker_rate returns validation error (min:0).
     */
    public function test_assign_with_negative_biker_rate_returns_validation_error(): void
    {
        $shift = $this->createDraftShift();

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.bikers.store', $shift), [
                'biker_id' => $this->activeBiker->id,
                'biker_rate' => '-1.00',
                'base_fee' => '25.00',
            ]);

        $response->assertSessionHasErrors('biker_rate');
    }

    // --- AC-2C-22: Negative base_fee returns validation error ---

    /**
     * AC-2C-22: Negative base_fee returns validation error (min:0).
     */
    public function test_assign_with_negative_base_fee_returns_validation_error(): void
    {
        $shift = $this->createDraftShift();

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.bikers.store', $shift), [
                'biker_id' => $this->activeBiker->id,
                'biker_rate' => '10.00',
                'base_fee' => '-5.00',
            ]);

        $response->assertSessionHasErrors('base_fee');
    }

    // ========================================================================
    // UPDATE — Modify Biker Details (AC-2C-23 through AC-2C-31)
    // ========================================================================

    // --- AC-2C-23: Update biker_rate on draft shift ---

    /**
     * AC-2C-23: Admin can update biker_rate on a ShiftBiker in a draft shift.
     */
    public function test_update_biker_rate_on_draft_shift(): void
    {
        $shift = $this->createDraftShift();
        $shiftBiker = $this->assignBikerToShift($shift, $this->activeBiker, [
            'biker_rate' => '10.00',
        ]);

        $response = $this->actingAs($this->admin)
            ->patch(route('shifts.bikers.update', [$shift, $shiftBiker]), [
                'biker_rate' => '15.00',
            ]);

        $this->assertEquals('15.00', $shiftBiker->fresh()->biker_rate,
            'AC-2C-23: biker_rate must be updated to 15.00 on draft shift');
    }

    // --- AC-2C-24: Update biker_rate on open shift ---

    /**
     * AC-2C-24: Admin can update biker_rate on a ShiftBiker in an open shift.
     */
    public function test_update_biker_rate_on_open_shift(): void
    {
        $shift = $this->createOpenShift();
        $shiftBiker = $this->assignBikerToShift($shift, $this->activeBiker, [
            'biker_rate' => '10.00',
        ]);

        $response = $this->actingAs($this->admin)
            ->patch(route('shifts.bikers.update', [$shift, $shiftBiker]), [
                'biker_rate' => '18.50',
            ]);

        $this->assertEquals('18.50', $shiftBiker->fresh()->biker_rate,
            'AC-2C-24: biker_rate must be updated to 18.50 on open shift');
    }

    // --- AC-2C-25: Update base_fee ---

    /**
     * AC-2C-25: Admin can update base_fee on a ShiftBiker.
     */
    public function test_update_base_fee_on_shift_biker(): void
    {
        $shift = $this->createDraftShift();
        $shiftBiker = $this->assignBikerToShift($shift, $this->activeBiker, [
            'base_fee' => '25.00',
        ]);

        $response = $this->actingAs($this->admin)
            ->patch(route('shifts.bikers.update', [$shift, $shiftBiker]), [
                'base_fee' => '30.00',
            ]);

        $this->assertEquals('30.00', $shiftBiker->fresh()->base_fee,
            'AC-2C-25: base_fee must be updated to 30.00');
    }

    // --- AC-2C-26: Update trips_count ---

    /**
     * AC-2C-26: Admin can update trips_count (manual entry workflow).
     */
    public function test_update_trips_count_on_shift_biker(): void
    {
        $shift = $this->createDraftShift();
        $shiftBiker = $this->assignBikerToShift($shift, $this->activeBiker, [
            'trips_count' => 0,
        ]);

        $response = $this->actingAs($this->admin)
            ->patch(route('shifts.bikers.update', [$shift, $shiftBiker]), [
                'trips_count' => 7,
            ]);

        $this->assertEquals(7, $shiftBiker->fresh()->trips_count,
            'AC-2C-26: trips_count must be updated to 7');
    }

    // --- AC-2C-27: Update success redirect and flash ---

    /**
     * AC-2C-27: After successful update, redirected to shifts.show with success flash.
     */
    public function test_update_redirects_to_show_with_success_flash(): void
    {
        $shift = $this->createDraftShift();
        $shiftBiker = $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->admin)
            ->patch(route('shifts.bikers.update', [$shift, $shiftBiker]), [
                'biker_rate' => '12.00',
            ]);

        $response->assertRedirect(route('shifts.show', $shift));
        $response->assertSessionHas('success');
    }

    // --- AC-2C-28: Update on closed shift returns validation error ---

    /**
     * AC-2C-28: Updating a ShiftBiker on a closed shift returns validation error.
     * BR-01: Only draft and open shifts allow biker detail updates.
     */
    public function test_update_on_closed_shift_returns_validation_error(): void
    {
        $shift = $this->createOpenShift();
        $shiftBiker = $this->assignBikerToShift($shift, $this->activeBiker, [
            'biker_rate' => '10.00',
        ]);

        // Close the shift after assignment
        $shift->status = ShiftStatus::Closed;
        $shift->closed_at = now();
        $shift->save();

        $response = $this->actingAs($this->admin)
            ->patch(route('shifts.bikers.update', [$shift->fresh(), $shiftBiker]), [
                'biker_rate' => '20.00',
            ]);

        $response->assertSessionHasErrors();

        // Verify biker_rate was NOT changed
        $this->assertEquals('10.00', $shiftBiker->fresh()->biker_rate,
            'AC-2C-28: biker_rate must remain unchanged on closed shift');
    }

    // --- AC-2C-29: Update on approved shift returns validation error ---

    /**
     * AC-2C-29: Updating a ShiftBiker on an approved shift returns validation error.
     * BR-01: Only draft and open shifts allow biker detail updates.
     */
    public function test_update_on_approved_shift_returns_validation_error(): void
    {
        $shift = $this->createApprovedShift();
        $shiftBiker = $this->assignBikerToShift($shift, $this->activeBiker, [
            'biker_rate' => '10.00',
        ]);

        $response = $this->actingAs($this->admin)
            ->patch(route('shifts.bikers.update', [$shift, $shiftBiker]), [
                'biker_rate' => '20.00',
            ]);

        $response->assertSessionHasErrors();

        $this->assertEquals('10.00', $shiftBiker->fresh()->biker_rate,
            'AC-2C-29: biker_rate must remain unchanged on approved shift');
    }

    // --- AC-2C-30: Negative trips_count returns validation error ---

    /**
     * AC-2C-30: Negative trips_count returns validation error (min:0).
     */
    public function test_update_with_negative_trips_count_returns_validation_error(): void
    {
        $shift = $this->createDraftShift();
        $shiftBiker = $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->admin)
            ->patch(route('shifts.bikers.update', [$shift, $shiftBiker]), [
                'trips_count' => -1,
            ]);

        $response->assertSessionHasErrors('trips_count');
    }

    // --- AC-2C-31: Updating ShiftBiker from different shift returns 404 ---

    /**
     * AC-2C-31: Updating a ShiftBiker that belongs to a different shift returns 404.
     * Route model binding must scope to the correct shift.
     */
    public function test_update_shift_biker_from_different_shift_returns_404(): void
    {
        $shift1 = $this->createDraftShift();
        $shift2 = $this->createDraftShift();

        $shiftBikerOnShift2 = $this->assignBikerToShift($shift2, $this->activeBiker);

        $response = $this->actingAs($this->admin)
            ->patch(route('shifts.bikers.update', [$shift1, $shiftBikerOnShift2]), [
                'biker_rate' => '20.00',
            ]);

        $response->assertNotFound();
    }

    // ========================================================================
    // DESTROY — Remove Biker (AC-2C-32 through AC-2C-38)
    // ========================================================================

    // --- AC-2C-32: Remove biker from draft shift ---

    /**
     * AC-2C-32: Admin can remove a biker from a draft shift.
     */
    public function test_remove_biker_from_draft_shift_deletes_record(): void
    {
        $shift = $this->createDraftShift();
        $shiftBiker = $this->assignBikerToShift($shift, $this->activeBiker);

        $this->actingAs($this->admin)
            ->delete(route('shifts.bikers.destroy', [$shift, $shiftBiker]));

        $this->assertDatabaseMissing('shift_bikers', [
            'id' => $shiftBiker->id,
        ]);
    }

    // --- AC-2C-33: Remove biker from open shift ---

    /**
     * AC-2C-33: Admin can remove a biker from an open shift.
     */
    public function test_remove_biker_from_open_shift_deletes_record(): void
    {
        $shift = $this->createOpenShift();
        $shiftBiker = $this->assignBikerToShift($shift, $this->activeBiker);

        $this->actingAs($this->admin)
            ->delete(route('shifts.bikers.destroy', [$shift, $shiftBiker]));

        $this->assertDatabaseMissing('shift_bikers', [
            'id' => $shiftBiker->id,
        ]);
    }

    // --- AC-2C-34: Remove success redirect and flash ---

    /**
     * AC-2C-34: After successful removal, redirected to shifts.show with success flash.
     */
    public function test_remove_biker_redirects_to_show_with_success_flash(): void
    {
        $shift = $this->createDraftShift();
        $shiftBiker = $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->admin)
            ->delete(route('shifts.bikers.destroy', [$shift, $shiftBiker]));

        $response->assertRedirect(route('shifts.show', $shift));
        $response->assertSessionHas('success');
    }

    // --- AC-2C-35: Remove biker from closed shift returns error ---

    /**
     * AC-2C-35: Removing a biker from a closed shift returns error flash.
     * BR-01: Biker records on closed shifts must be preserved for payout calculations.
     */
    public function test_remove_biker_from_closed_shift_returns_error(): void
    {
        $shift = $this->createOpenShift();
        $shiftBiker = $this->assignBikerToShift($shift, $this->activeBiker);

        // Close the shift after assignment
        $shift->status = ShiftStatus::Closed;
        $shift->closed_at = now();
        $shift->save();

        $response = $this->actingAs($this->admin)
            ->delete(route('shifts.bikers.destroy', [$shift->fresh(), $shiftBiker]));

        $response->assertSessionHas('error');

        // Record must still exist
        $this->assertDatabaseHas('shift_bikers', [
            'id' => $shiftBiker->id,
        ]);
    }

    // --- AC-2C-36: Remove biker from approved shift returns error ---

    /**
     * AC-2C-36: Removing a biker from an approved shift returns error flash.
     * BR-01: Biker records on approved shifts must be preserved for payout calculations.
     */
    public function test_remove_biker_from_approved_shift_returns_error(): void
    {
        $shift = $this->createOpenShift();
        $shiftBiker = $this->assignBikerToShift($shift, $this->activeBiker);

        $shift->status = ShiftStatus::Closed;
        $shift->closed_at = now();
        $shift->save();
        $shift->status = ShiftStatus::Approved;
        $shift->save();

        $response = $this->actingAs($this->admin)
            ->delete(route('shifts.bikers.destroy', [$shift->fresh(), $shiftBiker]));

        $response->assertSessionHas('error');

        // Record must still exist
        $this->assertDatabaseHas('shift_bikers', [
            'id' => $shiftBiker->id,
        ]);
    }

    // --- AC-2C-37: Remove non-existent ShiftBiker returns 404 ---

    /**
     * AC-2C-37: Removing a non-existent ShiftBiker returns 404.
     */
    public function test_remove_nonexistent_shift_biker_returns_404(): void
    {
        $shift = $this->createDraftShift();

        $response = $this->actingAs($this->admin)
            ->delete(route('shifts.bikers.destroy', [$shift, 99999]));

        $response->assertNotFound();
    }

    // --- AC-2C-38: Remove ShiftBiker from different shift returns 404 ---

    /**
     * AC-2C-38: Removing a ShiftBiker that belongs to a different shift returns 404.
     * Route model binding must scope to the correct shift.
     */
    public function test_remove_shift_biker_from_different_shift_returns_404(): void
    {
        $shift1 = $this->createDraftShift();
        $shift2 = $this->createDraftShift();

        $shiftBikerOnShift2 = $this->assignBikerToShift($shift2, $this->activeBiker);

        $response = $this->actingAs($this->admin)
            ->delete(route('shifts.bikers.destroy', [$shift1, $shiftBikerOnShift2]));

        $response->assertNotFound();

        // Record must still exist (on shift2)
        $this->assertDatabaseHas('shift_bikers', [
            'id' => $shiftBikerOnShift2->id,
        ]);
    }

    // ========================================================================
    // VIEWS (AC-2C-39 through AC-2C-46)
    // ========================================================================

    // --- AC-2C-39: Shift show page displays assigned bikers ---

    /**
     * AC-2C-39: The shift show page displays the list of assigned bikers.
     */
    public function test_show_view_displays_assigned_bikers(): void
    {
        $shift = $this->createDraftShift();
        $shiftBiker = $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.show', $shift));

        $response->assertSee($this->activeBiker->name);
    }

    // --- AC-2C-40: Biker list shows name, biker_rate, base_fee, trips_count ---

    /**
     * AC-2C-40: The biker list shows biker name, biker_rate, base_fee, and trips_count.
     */
    public function test_show_view_displays_biker_financial_details(): void
    {
        $shift = $this->createDraftShift();
        $this->assignBikerToShift($shift, $this->activeBiker, [
            'biker_rate' => '13.50',
            'base_fee' => '30.00',
            'trips_count' => 5,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.show', $shift));

        $response->assertSee('13.50');
        $response->assertSee('30.00');
        $response->assertSee('5');
    }

    // --- AC-2C-41: Empty biker list shows placeholder message ---

    /**
     * AC-2C-41: When no bikers are assigned, "Nenhum entregador atribuído" is shown.
     */
    public function test_show_view_displays_empty_biker_message(): void
    {
        $shift = $this->createDraftShift();

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.show', $shift));

        $response->assertSee('Nenhum entregador atribuído');
    }

    // --- AC-2C-42: Assign Biker form visible on draft/open shift ---

    /**
     * AC-2C-42: An "Assign Biker" form is visible when the shift is in draft status.
     */
    public function test_show_view_displays_assign_form_for_draft_shift(): void
    {
        $shift = $this->createDraftShift();

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.show', $shift));

        $response->assertSee(route('shifts.bikers.store', $shift));
    }

    /**
     * AC-2C-42: An "Assign Biker" form is visible when the shift is in open status.
     */
    public function test_show_view_displays_assign_form_for_open_shift(): void
    {
        $shift = $this->createOpenShift();

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.show', $shift));

        $response->assertSee(route('shifts.bikers.store', $shift));
    }

    // --- AC-2C-43: Assign Biker form hidden on closed/approved/paid ---

    /**
     * AC-2C-43: The "Assign Biker" form is hidden when the shift is closed.
     */
    public function test_show_view_hides_assign_form_for_closed_shift(): void
    {
        $shift = $this->createClosedShift();

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.show', $shift));

        $response->assertDontSee(route('shifts.bikers.store', $shift));
    }

    /**
     * AC-2C-43: The "Assign Biker" form is hidden when the shift is approved.
     */
    public function test_show_view_hides_assign_form_for_approved_shift(): void
    {
        $shift = $this->createApprovedShift();

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.show', $shift));

        $response->assertDontSee(route('shifts.bikers.store', $shift));
    }

    /**
     * AC-2C-43: The "Assign Biker" form is hidden when the shift is paid.
     */
    public function test_show_view_hides_assign_form_for_paid_shift(): void
    {
        $shift = $this->createPaidShift();

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.show', $shift));

        $response->assertDontSee(route('shifts.bikers.store', $shift));
    }

    // --- AC-2C-44: Remove button visible for draft/open ---

    /**
     * AC-2C-44: Each assigned biker row has a "Remove" button when shift is draft.
     */
    public function test_show_view_displays_remove_button_for_draft_shift(): void
    {
        $shift = $this->createDraftShift();
        $shiftBiker = $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.show', $shift));

        $response->assertSee(route('shifts.bikers.destroy', [$shift, $shiftBiker]));
    }

    /**
     * AC-2C-44: Each assigned biker row has a "Remove" button when shift is open.
     */
    public function test_show_view_displays_remove_button_for_open_shift(): void
    {
        $shift = $this->createOpenShift();
        $shiftBiker = $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.show', $shift));

        $response->assertSee(route('shifts.bikers.destroy', [$shift, $shiftBiker]));
    }

    // --- AC-2C-45: Remove button hidden for closed/approved/paid ---

    /**
     * AC-2C-45: The "Remove" button is hidden when the shift is closed.
     */
    public function test_show_view_hides_remove_button_for_closed_shift(): void
    {
        $shift = $this->createOpenShift();
        $shiftBiker = $this->assignBikerToShift($shift, $this->activeBiker);

        $shift->status = ShiftStatus::Closed;
        $shift->closed_at = now();
        $shift->save();

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.show', $shift->fresh()));

        $response->assertDontSee(route('shifts.bikers.destroy', [$shift, $shiftBiker]));
    }

    /**
     * AC-2C-45: The "Remove" button is hidden when the shift is approved.
     */
    public function test_show_view_hides_remove_button_for_approved_shift(): void
    {
        $shift = $this->createApprovedShift();
        $shiftBiker = $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.show', $shift));

        $response->assertDontSee(route('shifts.bikers.destroy', [$shift, $shiftBiker]));
    }

    /**
     * AC-2C-45: The "Remove" button is hidden when the shift is paid.
     */
    public function test_show_view_hides_remove_button_for_paid_shift(): void
    {
        $shift = $this->createPaidShift();
        $shiftBiker = $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.show', $shift));

        $response->assertDontSee(route('shifts.bikers.destroy', [$shift, $shiftBiker]));
    }

    // --- AC-2C-46: Edit button visible for draft/open only ---

    /**
     * AC-2C-46: Each assigned biker row has an "Edit" button for draft shift.
     */
    public function test_show_view_displays_edit_button_for_draft_shift(): void
    {
        $shift = $this->createDraftShift();
        $shiftBiker = $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.show', $shift));

        $response->assertSee(route('shifts.bikers.update', [$shift, $shiftBiker]));
    }

    /**
     * AC-2C-46: Each assigned biker row has an "Edit" button for open shift.
     */
    public function test_show_view_displays_edit_button_for_open_shift(): void
    {
        $shift = $this->createOpenShift();
        $shiftBiker = $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.show', $shift));

        $response->assertSee(route('shifts.bikers.update', [$shift, $shiftBiker]));
    }

    /**
     * AC-2C-46: The "Edit" button is hidden when the shift is closed.
     */
    public function test_show_view_hides_edit_button_for_closed_shift(): void
    {
        $shift = $this->createOpenShift();
        $shiftBiker = $this->assignBikerToShift($shift, $this->activeBiker);

        $shift->status = ShiftStatus::Closed;
        $shift->closed_at = now();
        $shift->save();

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.show', $shift->fresh()));

        $response->assertDontSee(route('shifts.bikers.update', [$shift, $shiftBiker]));
    }

    // ========================================================================
    // ADDITIONAL EDGE CASE & BOUNDARY TESTS
    // ========================================================================

    /**
     * Assigning multiple different bikers to the same shift succeeds.
     */
    public function test_assign_multiple_different_bikers_to_same_shift(): void
    {
        $shift = $this->createDraftShift();
        $biker2 = Biker::factory()->create([
            'name' => 'Second Biker',
            'rate_per_trip' => '12.00',
            'base_fee' => '20.00',
            'active' => true,
        ]);

        $this->actingAs($this->admin)
            ->post(route('shifts.bikers.store', $shift), [
                'biker_id' => $this->activeBiker->id,
                'biker_rate' => '10.00',
                'base_fee' => '25.00',
            ]);

        $this->actingAs($this->admin)
            ->post(route('shifts.bikers.store', $shift), [
                'biker_id' => $biker2->id,
                'biker_rate' => '12.00',
                'base_fee' => '20.00',
            ]);

        $this->assertEquals(2, $shift->fresh()->shiftBikers()->count());
    }

    /**
     * The same biker can be assigned to different shifts.
     */
    public function test_same_biker_can_be_assigned_to_different_shifts(): void
    {
        $shift1 = $this->createDraftShift();
        $shift2 = $this->createDraftShift();

        $this->actingAs($this->admin)
            ->post(route('shifts.bikers.store', $shift1), [
                'biker_id' => $this->activeBiker->id,
                'biker_rate' => '10.00',
                'base_fee' => '25.00',
            ]);

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.bikers.store', $shift2), [
                'biker_id' => $this->activeBiker->id,
                'biker_rate' => '10.00',
                'base_fee' => '25.00',
            ]);

        $response->assertRedirect(route('shifts.show', $shift2));
        $this->assertEquals(1, $shift1->fresh()->shiftBikers()->count());
        $this->assertEquals(1, $shift2->fresh()->shiftBikers()->count());
    }

    /**
     * Zero trips_count on update is allowed (resetting to 0 is valid).
     */
    public function test_update_trips_count_to_zero_is_allowed(): void
    {
        $shift = $this->createDraftShift();
        $shiftBiker = $this->assignBikerToShift($shift, $this->activeBiker, [
            'trips_count' => 5,
        ]);

        $response = $this->actingAs($this->admin)
            ->patch(route('shifts.bikers.update', [$shift, $shiftBiker]), [
                'trips_count' => 0,
            ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertEquals(0, $shiftBiker->fresh()->trips_count,
            'Updating trips_count to 0 must be allowed');
    }

    /**
     * Update with zero biker_rate is allowed.
     */
    public function test_update_biker_rate_to_zero_is_allowed(): void
    {
        $shift = $this->createDraftShift();
        $shiftBiker = $this->assignBikerToShift($shift, $this->activeBiker, [
            'biker_rate' => '10.00',
        ]);

        $response = $this->actingAs($this->admin)
            ->patch(route('shifts.bikers.update', [$shift, $shiftBiker]), [
                'biker_rate' => '0.00',
            ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertEquals('0.00', $shiftBiker->fresh()->biker_rate,
            'Updating biker_rate to 0.00 must be allowed');
    }

    /**
     * Update with negative biker_rate returns validation error.
     */
    public function test_update_with_negative_biker_rate_returns_validation_error(): void
    {
        $shift = $this->createDraftShift();
        $shiftBiker = $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->admin)
            ->patch(route('shifts.bikers.update', [$shift, $shiftBiker]), [
                'biker_rate' => '-5.00',
            ]);

        $response->assertSessionHasErrors('biker_rate');
    }

    /**
     * Update with negative base_fee returns validation error.
     */
    public function test_update_with_negative_base_fee_returns_validation_error(): void
    {
        $shift = $this->createDraftShift();
        $shiftBiker = $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->admin)
            ->patch(route('shifts.bikers.update', [$shift, $shiftBiker]), [
                'base_fee' => '-10.00',
            ]);

        $response->assertSessionHasErrors('base_fee');
    }

    /**
     * Non-numeric biker_rate on store returns validation error.
     */
    public function test_store_with_non_numeric_biker_rate_returns_validation_error(): void
    {
        $shift = $this->createDraftShift();

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.bikers.store', $shift), [
                'biker_id' => $this->activeBiker->id,
                'biker_rate' => 'abc',
                'base_fee' => '25.00',
            ]);

        $response->assertSessionHasErrors('biker_rate');
    }

    /**
     * Non-numeric base_fee on store returns validation error.
     */
    public function test_store_with_non_numeric_base_fee_returns_validation_error(): void
    {
        $shift = $this->createDraftShift();

        $response = $this->actingAs($this->admin)
            ->post(route('shifts.bikers.store', $shift), [
                'biker_id' => $this->activeBiker->id,
                'biker_rate' => '10.00',
                'base_fee' => 'xyz',
            ]);

        $response->assertSessionHasErrors('base_fee');
    }

    /**
     * Non-integer trips_count on update returns validation error.
     */
    public function test_update_with_non_integer_trips_count_returns_validation_error(): void
    {
        $shift = $this->createDraftShift();
        $shiftBiker = $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->admin)
            ->patch(route('shifts.bikers.update', [$shift, $shiftBiker]), [
                'trips_count' => 'five',
            ]);

        $response->assertSessionHasErrors('trips_count');
    }

    /**
     * ShiftPolicy@addBiker returns true only for admin (BR-05 defense-in-depth).
     */
    public function test_shift_policy_add_biker_allows_admin(): void
    {
        $shift = $this->createDraftShift();

        $this->assertTrue(
            $this->admin->can('addBiker', $shift),
            'BR-05: Admin must be authorized to add bikers to shifts'
        );
    }

    /**
     * ShiftPolicy@addBiker returns false for restaurant manager (BR-05).
     */
    public function test_shift_policy_add_biker_denies_restaurant_manager(): void
    {
        $shift = $this->createDraftShift();

        $this->assertFalse(
            $this->restaurantManager->can('addBiker', $shift),
            'BR-05: RestaurantManager must NOT be authorized to add bikers to shifts'
        );
    }

    /**
     * ShiftPolicy@addBiker returns false for biker user (BR-05).
     */
    public function test_shift_policy_add_biker_denies_biker_user(): void
    {
        $shift = $this->createDraftShift();

        $this->assertFalse(
            $this->bikerUser->can('addBiker', $shift),
            'BR-05: Biker user must NOT be authorized to add bikers to shifts'
        );
    }
}
