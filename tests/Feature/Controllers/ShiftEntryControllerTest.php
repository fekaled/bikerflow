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
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * ShiftEntry Controller Feature Tests — Phase 2E
 *
 * Comprehensive test suite for End-of-Shift Entry (Restaurant Manager workflow).
 * Covers all acceptance criteria AC-2E-01 through AC-2E-33.
 *
 * Business Rules: BR-01 (Workflow Locking — manual_entry enforcement)
 *
 * @see docs/plans/phase-2e-end-of-shift-entry.md
 */
class ShiftEntryControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $restaurantManager;

    private User $otherRestaurantManager;

    private User $bikerUser;

    private Restaurant $restaurant;

    private Restaurant $otherRestaurant;

    private Biker $activeBiker;

    private Biker $secondBiker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create([
            'name' => 'Test Restaurant',
            'rate_per_trip' => '15.00',
            'active' => true,
        ]);

        $this->otherRestaurant = Restaurant::factory()->create([
            'name' => 'Other Restaurant',
            'rate_per_trip' => '20.00',
            'active' => true,
        ]);

        $this->admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $this->restaurantManager = User::factory()->create([
            'role' => UserRole::RestaurantManager,
            'restaurant_id' => $this->restaurant->id,
        ]);

        $this->otherRestaurantManager = User::factory()->create([
            'role' => UserRole::RestaurantManager,
            'restaurant_id' => $this->otherRestaurant->id,
        ]);

        $this->activeBiker = Biker::factory()->create([
            'name' => 'Active Biker',
            'rate_per_trip' => '10.00',
            'base_fee' => '25.00',
            'active' => true,
        ]);

        $this->secondBiker = Biker::factory()->create([
            'name' => 'Second Biker',
            'rate_per_trip' => '12.00',
            'base_fee' => '20.00',
            'active' => true,
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
            'workflow_type' => 'manual_entry',
            'restaurant_rate' => '15.00',
        ], $overrides));
    }

    private function createOpenShift(array $overrides = []): Shift
    {
        return Shift::factory()->started()->create(array_merge([
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'manual_entry',
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

    private function validEntryPayload(Shift $shift, array $bikerOverrides = [], bool $closeShift = false): array
    {
        $shiftBikers = ShiftBiker::where('shift_id', $shift->id)->get();
        $bikers = [];
        foreach ($shiftBikers as $index => $sb) {
            $bikers[] = [
                'biker_id' => $sb->biker_id,
                'trips_count' => $bikerOverrides[$index]['trips_count'] ?? 5,
            ];
        }

        return array_filter([
            'bikers' => $bikers,
            'close_shift' => $closeShift,
        ], fn ($value) => $value !== false);
    }

    // ========================================================================
    // ROUTE & MIDDLEWARE (AC-2E-01 through AC-2E-04)
    // ========================================================================

    // --- AC-2E-01 ---

    public function test_entry_show_returns_200_for_restaurant_manager(): void
    {
        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->restaurantManager)
            ->get(route('entry.show', $shift));

        $response->assertOk();
    }

    // --- AC-2E-02 ---

    public function test_entry_store_route_is_registered_and_reachable(): void
    {
        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->restaurantManager)
            ->post(route('entry.store', $shift), $this->validEntryPayload($shift));

        // Should not return 404 — even if validation fails, it should be a redirect back or 403
        $response->assertStatus(302);
    }

    // --- AC-2E-03 ---

    public function test_entry_show_redirects_to_login_for_unauthenticated(): void
    {
        $shift = $this->createOpenShift();

        $response = $this->get(route('entry.show', $shift));

        $response->assertRedirect(route('login'));
    }

    // --- AC-2E-04 ---

    public function test_entry_store_returns_403_for_biker_user(): void
    {
        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->bikerUser)
            ->post(route('entry.store', $shift), $this->validEntryPayload($shift));

        $response->assertForbidden();
    }

    public function test_entry_show_returns_403_for_biker_user(): void
    {
        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->bikerUser)
            ->get(route('entry.show', $shift));

        $response->assertForbidden();
    }

    // ========================================================================
    // AUTHORIZATION (AC-2E-05 through AC-2E-10)
    // ========================================================================

    // --- AC-2E-05 ---

    public function test_rm_can_view_entry_form_for_own_restaurant_open_manual_entry_shift(): void
    {
        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->restaurantManager)
            ->get(route('entry.show', $shift));

        $response->assertOk();
        $response->assertViewIs('entry.show');
    }

    // --- AC-2E-06 ---

    public function test_rm_receives_403_submitting_trips_for_other_restaurant_shift(): void
    {
        $shift = $this->createOpenShift(['restaurant_id' => $this->otherRestaurant->id]);
        $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->restaurantManager)
            ->post(route('entry.store', $shift), $this->validEntryPayload($shift));

        $response->assertForbidden();
    }

    public function test_rm_receives_403_viewing_entry_form_for_other_restaurant_shift(): void
    {
        $shift = $this->createOpenShift(['restaurant_id' => $this->otherRestaurant->id]);
        $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->restaurantManager)
            ->get(route('entry.show', $shift));

        $response->assertForbidden();
    }

    // --- AC-2E-07 ---

    public function test_admin_can_view_entry_form_for_any_restaurant(): void
    {
        $shift = $this->createOpenShift(['restaurant_id' => $this->otherRestaurant->id]);
        $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->admin)
            ->get(route('entry.show', $shift));

        $response->assertOk();
    }

    // --- AC-2E-08 ---

    public function test_admin_can_submit_trips_for_any_restaurant(): void
    {
        $shift = $this->createOpenShift(['restaurant_id' => $this->otherRestaurant->id]);
        $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->admin)
            ->post(route('entry.store', $shift), $this->validEntryPayload($shift));

        $response->assertRedirect(route('tracking.dashboard'));
    }

    // --- AC-2E-09 ---

    public function test_shift_policy_submit_trips_returns_true_for_admin(): void
    {
        $shift = $this->createOpenShift();

        $result = Gate::forUser($this->admin)->check('submitTrips', $shift);

        $this->assertTrue($result);
    }

    // --- AC-2E-10 ---

    public function test_shift_policy_submit_trips_returns_false_for_rm_other_restaurant(): void
    {
        $shift = $this->createOpenShift(['restaurant_id' => $this->otherRestaurant->id]);

        $result = Gate::forUser($this->restaurantManager)->check('submitTrips', $shift);

        $this->assertFalse($result);
    }

    public function test_shift_policy_submit_trips_returns_true_for_rm_own_restaurant_open_shift(): void
    {
        $shift = $this->createOpenShift();

        $result = Gate::forUser($this->restaurantManager)->check('submitTrips', $shift);

        $this->assertTrue($result);
    }

    public function test_shift_policy_submit_trips_returns_false_for_biker_user(): void
    {
        $shift = $this->createOpenShift();

        $result = Gate::forUser($this->bikerUser)->check('submitTrips', $shift);

        $this->assertFalse($result);
    }

    public function test_shift_policy_submit_trips_returns_false_for_rm_on_closed_shift(): void
    {
        $shift = $this->createClosedShift();

        $result = Gate::forUser($this->restaurantManager)->check('submitTrips', $shift);

        $this->assertFalse($result);
    }

    public function test_shift_policy_submit_trips_returns_true_for_admin_on_closed_shift(): void
    {
        $shift = $this->createClosedShift();

        $result = Gate::forUser($this->admin)->check('submitTrips', $shift);

        $this->assertTrue($result);
    }

    public function test_shift_policy_submit_trips_returns_false_for_rm_with_null_restaurant_id(): void
    {
        $rmNoRestaurant = User::factory()->create([
            'role' => UserRole::RestaurantManager,
            'restaurant_id' => null,
        ]);

        $shift = $this->createOpenShift();

        $result = Gate::forUser($rmNoRestaurant)->check('submitTrips', $shift);

        $this->assertFalse($result);
    }

    // ========================================================================
    // BR-01 ENFORCEMENT — VALIDATION (AC-2E-11 through AC-2E-16)
    // ========================================================================

    // --- AC-2E-11: Reject live_tick workflow ---

    public function test_submit_rejects_live_tick_workflow(): void
    {
        $shift = $this->createOpenShift(['workflow_type' => 'live_tick']);
        $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->restaurantManager)
            ->post(route('entry.store', $shift), $this->validEntryPayload($shift));

        $response->assertSessionHasErrors('workflow_type');
    }

    // --- AC-2E-12: Reject non-open shifts ---

    public function test_submit_rejects_draft_shift(): void
    {
        $shift = $this->createDraftShift();
        $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->restaurantManager)
            ->post(route('entry.store', $shift), $this->validEntryPayload($shift));

        $response->assertSessionHasErrors('shift');
    }

    public function test_submit_rejects_closed_shift(): void
    {
        $shift = $this->createClosedShift();
        $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->restaurantManager)
            ->post(route('entry.store', $shift), $this->validEntryPayload($shift));

        $response->assertSessionHasErrors('shift');
    }

    public function test_submit_rejects_approved_shift(): void
    {
        $shift = $this->createApprovedShift();
        $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->restaurantManager)
            ->post(route('entry.store', $shift), $this->validEntryPayload($shift));

        $response->assertSessionHasErrors('shift');
    }

    public function test_submit_rejects_paid_shift(): void
    {
        $shift = $this->createPaidShift();
        $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->restaurantManager)
            ->post(route('entry.store', $shift), $this->validEntryPayload($shift));

        $response->assertSessionHasErrors('shift');
    }

    // --- AC-2E-13: Reject empty/missing bikers array ---

    public function test_submit_rejects_empty_bikers_array(): void
    {
        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->restaurantManager)
            ->post(route('entry.store', $shift), ['bikers' => []]);

        $response->assertSessionHasErrors('bikers');
    }

    public function test_submit_rejects_missing_bikers(): void
    {
        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->restaurantManager)
            ->post(route('entry.store', $shift), []);

        $response->assertSessionHasErrors('bikers');
    }

    // --- AC-2E-14: Reject nonexistent biker_id ---

    public function test_submit_rejects_nonexistent_biker_id(): void
    {
        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->restaurantManager)
            ->post(route('entry.store', $shift), [
                'bikers' => [
                    ['biker_id' => 99999, 'trips_count' => 5],
                ],
            ]);

        $response->assertSessionHasErrors('bikers.0.biker_id');
    }

    // --- AC-2E-15: Reject unassigned biker ---

    public function test_submit_rejects_unassigned_biker(): void
    {
        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, $this->activeBiker);

        // Use a valid biker that exists but is NOT assigned to this shift
        $unassignedBiker = Biker::factory()->create(['name' => 'Unassigned Biker', 'active' => true]);

        $response = $this->actingAs($this->restaurantManager)
            ->post(route('entry.store', $shift), [
                'bikers' => [
                    ['biker_id' => $this->activeBiker->id, 'trips_count' => 5],
                    ['biker_id' => $unassignedBiker->id, 'trips_count' => 3],
                ],
            ]);

        $response->assertSessionHasErrors('bikers.1.biker_id');
    }

    // --- AC-2E-16: Reject negative or non-integer trips_count ---

    public function test_submit_rejects_negative_trips_count(): void
    {
        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->restaurantManager)
            ->post(route('entry.store', $shift), [
                'bikers' => [
                    ['biker_id' => $this->activeBiker->id, 'trips_count' => -1],
                ],
            ]);

        $response->assertSessionHasErrors('bikers.0.trips_count');
    }

    public function test_submit_rejects_string_trips_count(): void
    {
        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->restaurantManager)
            ->post(route('entry.store', $shift), [
                'bikers' => [
                    ['biker_id' => $this->activeBiker->id, 'trips_count' => 'abc'],
                ],
            ]);

        $response->assertSessionHasErrors('bikers.0.trips_count');
    }

    public function test_submit_rejects_float_trips_count(): void
    {
        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->restaurantManager)
            ->post(route('entry.store', $shift), [
                'bikers' => [
                    ['biker_id' => $this->activeBiker->id, 'trips_count' => 3.5],
                ],
            ]);

        $response->assertSessionHasErrors('bikers.0.trips_count');
    }

    // ========================================================================
    // SUBMISSION EXECUTION (AC-2E-17 through AC-2E-24)
    // ========================================================================

    // --- AC-2E-17: Valid submission updates all ShiftBiker records ---

    public function test_valid_submission_updates_all_shift_biker_trips_count(): void
    {
        $shift = $this->createOpenShift();
        $sb1 = $this->assignBikerToShift($shift, $this->activeBiker);
        $sb2 = $this->assignBikerToShift($shift, $this->secondBiker);

        $this->actingAs($this->restaurantManager)
            ->post(route('entry.store', $shift), [
                'bikers' => [
                    ['biker_id' => $this->activeBiker->id, 'trips_count' => 7],
                    ['biker_id' => $this->secondBiker->id, 'trips_count' => 12],
                ],
            ]);

        $this->assertEquals(7, $sb1->fresh()->trips_count);
        $this->assertEquals(12, $sb2->fresh()->trips_count);
    }

    // --- AC-2E-18: Redirect to tracking dashboard with success flash ---

    public function test_valid_submission_redirects_to_tracking_dashboard_with_success_flash(): void
    {
        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->restaurantManager)
            ->post(route('entry.store', $shift), [
                'bikers' => [
                    ['biker_id' => $this->activeBiker->id, 'trips_count' => 5],
                ],
            ]);

        $response->assertRedirect(route('tracking.dashboard'));
        $response->assertSessionHas('success', 'Viagens registradas com sucesso!');
    }

    // --- AC-2E-19: Multiple bikers updated independently ---

    public function test_multiple_bikers_updated_independently(): void
    {
        $shift = $this->createOpenShift();
        $sb1 = $this->assignBikerToShift($shift, $this->activeBiker, ['trips_count' => 0]);
        $sb2 = $this->assignBikerToShift($shift, $this->secondBiker, ['trips_count' => 0]);

        $this->actingAs($this->restaurantManager)
            ->post(route('entry.store', $shift), [
                'bikers' => [
                    ['biker_id' => $this->activeBiker->id, 'trips_count' => 10],
                    ['biker_id' => $this->secondBiker->id, 'trips_count' => 0],
                ],
            ]);

        $this->assertEquals(10, $sb1->fresh()->trips_count);
        $this->assertEquals(0, $sb2->fresh()->trips_count);
    }

    // --- AC-2E-20: trips_count = 0 sets to exactly 0 ---

    public function test_submitting_zero_trips_count_sets_to_zero(): void
    {
        $shift = $this->createOpenShift();
        $sb = $this->assignBikerToShift($shift, $this->activeBiker, ['trips_count' => 5]);

        $this->actingAs($this->restaurantManager)
            ->post(route('entry.store', $shift), [
                'bikers' => [
                    ['biker_id' => $this->activeBiker->id, 'trips_count' => 0],
                ],
            ]);

        $this->assertSame(0, $sb->fresh()->trips_count);
    }

    // --- AC-2E-21: Re-submission overwrites previous values ---

    public function test_resubmission_overwrites_previous_trips_count(): void
    {
        $shift = $this->createOpenShift();
        $sb = $this->assignBikerToShift($shift, $this->activeBiker, ['trips_count' => 0]);

        // First submission
        $this->actingAs($this->restaurantManager)
            ->post(route('entry.store', $shift), [
                'bikers' => [
                    ['biker_id' => $this->activeBiker->id, 'trips_count' => 8],
                ],
            ]);

        $this->assertEquals(8, $sb->fresh()->trips_count);

        // Re-submission with different values
        $this->actingAs($this->restaurantManager)
            ->post(route('entry.store', $shift), [
                'bikers' => [
                    ['biker_id' => $this->activeBiker->id, 'trips_count' => 15],
                ],
            ]);

        $this->assertEquals(15, $sb->fresh()->trips_count);
    }

    // --- AC-2E-22: close_shift transitions shift to closed ---

    public function test_submission_with_close_shift_transitions_to_closed(): void
    {
        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, $this->activeBiker);

        $this->actingAs($this->restaurantManager)
            ->post(route('entry.store', $shift), [
                'bikers' => [
                    ['biker_id' => $this->activeBiker->id, 'trips_count' => 5],
                ],
                'close_shift' => true,
            ]);

        $shift->refresh();
        $this->assertEquals(ShiftStatus::Closed, $shift->status);
        $this->assertNotNull($shift->closed_at);
    }

    // --- AC-2E-23: Submission without close_shift keeps shift open ---

    public function test_submission_without_close_shift_keeps_shift_open(): void
    {
        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, $this->activeBiker);

        $this->actingAs($this->restaurantManager)
            ->post(route('entry.store', $shift), [
                'bikers' => [
                    ['biker_id' => $this->activeBiker->id, 'trips_count' => 5],
                ],
            ]);

        $shift->refresh();
        $this->assertEquals(ShiftStatus::Open, $shift->status);
        $this->assertNull($shift->closed_at);
    }

    // --- AC-2E-24: Partial submission rejected ---

    public function test_partial_submission_rejected(): void
    {
        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, $this->activeBiker);
        $this->assignBikerToShift($shift, $this->secondBiker);

        // Submit only one biker out of two
        $response = $this->actingAs($this->restaurantManager)
            ->post(route('entry.store', $shift), [
                'bikers' => [
                    ['biker_id' => $this->activeBiker->id, 'trips_count' => 5],
                ],
            ]);

        $response->assertSessionHasErrors('bikers');
    }

    // ========================================================================
    // ENTRY FORM VIEW (AC-2E-25 through AC-2E-31)
    // ========================================================================

    // --- AC-2E-25: Form displays restaurant name and shift ID ---

    public function test_entry_form_displays_restaurant_name(): void
    {
        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->restaurantManager)
            ->get(route('entry.show', $shift));

        $response->assertSee('Test Restaurant');
    }

    public function test_entry_form_displays_shift_id(): void
    {
        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->restaurantManager)
            ->get(route('entry.show', $shift));

        $response->assertSee('#'.$shift->id);
    }

    // --- AC-2E-26: Form displays biker names ---

    public function test_entry_form_displays_biker_names(): void
    {
        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->restaurantManager)
            ->get(route('entry.show', $shift));

        $response->assertSee('Active Biker');
    }

    public function test_entry_form_displays_multiple_biker_names(): void
    {
        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, $this->activeBiker);
        $this->assignBikerToShift($shift, $this->secondBiker);

        $response = $this->actingAs($this->restaurantManager)
            ->get(route('entry.show', $shift));

        $response->assertSee('Active Biker');
        $response->assertSee('Second Biker');
    }

    // --- AC-2E-27: Form displays input fields pre-filled with current trips_count ---

    public function test_entry_form_displays_trips_count_input_prefilled(): void
    {
        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, $this->activeBiker, ['trips_count' => 7]);

        $response = $this->actingAs($this->restaurantManager)
            ->get(route('entry.show', $shift));

        $response->assertSee('value="7"', false);
    }

    public function test_entry_form_displays_zero_trips_for_newly_assigned_biker(): void
    {
        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, $this->activeBiker, ['trips_count' => 0]);

        $response = $this->actingAs($this->restaurantManager)
            ->get(route('entry.show', $shift));

        $response->assertSee('value="0"', false);
    }

    // --- AC-2E-28: Form contains hidden biker_id fields ---

    public function test_entry_form_contains_hidden_biker_id_fields(): void
    {
        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->restaurantManager)
            ->get(route('entry.show', $shift));

        $response->assertSee('type="hidden"', false);
        $response->assertSee('name="bikers[0][biker_id]"', false);
        $response->assertSee('value="'.$this->activeBiker->id.'"', false);
    }

    // --- AC-2E-29: Form includes "Encerrar turno" checkbox ---

    public function test_entry_form_includes_close_shift_checkbox(): void
    {
        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->restaurantManager)
            ->get(route('entry.show', $shift));

        $response->assertSee('close_shift');
        $response->assertSee('Encerrar turno');
    }

    // --- AC-2E-30: Form POSTs to entry.store with CSRF ---

    public function test_entry_form_posts_to_entry_store_with_csrf(): void
    {
        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->restaurantManager)
            ->get(route('entry.show', $shift));

        $response->assertSee('action="'.route('entry.store', $shift).'"', false);
        $response->assertSee('_token', false);
    }

    // --- AC-2E-31: Form shows message when no bikers assigned ---

    public function test_entry_form_shows_message_when_no_bikers_assigned(): void
    {
        $shift = $this->createOpenShift();

        $response = $this->actingAs($this->restaurantManager)
            ->get(route('entry.show', $shift));

        $response->assertSee('Nenhum entregador atribuído');
    }

    // ========================================================================
    // DASHBOARD INTEGRATION (AC-2E-32)
    // ========================================================================

    // --- AC-2E-32: Dashboard shows "Registrar Viagens" for manual_entry shifts ---

    public function test_dashboard_shows_registrar_viagens_button_for_manual_entry_shift(): void
    {
        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->restaurantManager)
            ->get(route('tracking.dashboard'));

        $response->assertSee('Registrar Viagens');
    }

    public function test_dashboard_registrar_viagens_links_to_entry_show(): void
    {
        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->restaurantManager)
            ->get(route('tracking.dashboard'));

        $response->assertSee(route('entry.show', $shift));
    }

    public function test_dashboard_does_not_show_registrar_viagens_for_live_tick_shift(): void
    {
        $shift = $this->createOpenShift(['workflow_type' => 'live_tick']);
        $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->restaurantManager)
            ->get(route('tracking.dashboard'));

        $response->assertDontSee('Registrar Viagens');
    }

    // ========================================================================
    // ADDITIONAL EDGE CASE TESTS
    // ========================================================================

    public function test_submit_on_closed_shift_does_not_change_trips_count(): void
    {
        $shift = $this->createClosedShift();
        $sb = $this->assignBikerToShift($shift, $this->activeBiker, ['trips_count' => 3]);

        $this->actingAs($this->restaurantManager)
            ->post(route('entry.store', $shift), [
                'bikers' => [
                    ['biker_id' => $this->activeBiker->id, 'trips_count' => 99],
                ],
            ]);

        // trips_count should remain unchanged
        $this->assertEquals(3, $sb->fresh()->trips_count);
    }

    public function test_entry_show_nonexistent_shift_returns_404(): void
    {
        $response = $this->actingAs($this->restaurantManager)
            ->get(route('entry.show', 99999));

        $response->assertNotFound();
    }

    public function test_entry_store_nonexistent_shift_returns_404(): void
    {
        $response = $this->actingAs($this->restaurantManager)
            ->post(route('entry.store', 99999), []);

        $response->assertNotFound();
    }

    public function test_admin_can_submit_for_own_restaurant_manual_entry_shift(): void
    {
        $shift = $this->createOpenShift();
        $sb = $this->assignBikerToShift($shift, $this->activeBiker);

        $this->actingAs($this->admin)
            ->post(route('entry.store', $shift), [
                'bikers' => [
                    ['biker_id' => $this->activeBiker->id, 'trips_count' => 20],
                ],
            ]);

        $this->assertEquals(20, $sb->fresh()->trips_count);
    }

    public function test_br01_live_tick_shift_rejects_entry_even_for_admin(): void
    {
        $shift = $this->createOpenShift(['workflow_type' => 'live_tick']);
        $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->admin)
            ->post(route('entry.store', $shift), $this->validEntryPayload($shift));

        $response->assertSessionHasErrors('workflow_type');
    }

    public function test_entry_requires_authentication(): void
    {
        $shift = $this->createOpenShift();

        $response = $this->post(route('entry.store', $shift), []);

        $response->assertRedirect(route('login'));
    }

    public function test_entry_form_uses_post_method(): void
    {
        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->restaurantManager)
            ->get(route('entry.show', $shift));

        $response->assertSee('method="POST"', false);
    }

    public function test_rm_with_null_restaurant_id_gets_403_on_show(): void
    {
        $rmNoRestaurant = User::factory()->create([
            'role' => UserRole::RestaurantManager,
            'restaurant_id' => null,
        ]);

        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($rmNoRestaurant)
            ->get(route('entry.show', $shift));

        $response->assertForbidden();
    }

    public function test_rm_with_null_restaurant_id_gets_403_on_store(): void
    {
        $rmNoRestaurant = User::factory()->create([
            'role' => UserRole::RestaurantManager,
            'restaurant_id' => null,
        ]);

        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($rmNoRestaurant)
            ->post(route('entry.store', $shift), $this->validEntryPayload($shift));

        $response->assertForbidden();
    }

    public function test_submit_rejects_missing_trips_count(): void
    {
        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->restaurantManager)
            ->post(route('entry.store', $shift), [
                'bikers' => [
                    ['biker_id' => $this->activeBiker->id],
                ],
            ]);

        $response->assertSessionHasErrors('bikers.0.trips_count');
    }

    public function test_submit_rejects_missing_biker_id_in_entry(): void
    {
        $shift = $this->createOpenShift();
        $this->assignBikerToShift($shift, $this->activeBiker);

        $response = $this->actingAs($this->restaurantManager)
            ->post(route('entry.store', $shift), [
                'bikers' => [
                    ['trips_count' => 5],
                ],
            ]);

        $response->assertSessionHasErrors('bikers.0.biker_id');
    }
}
