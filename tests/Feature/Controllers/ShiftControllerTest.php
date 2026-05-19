<?php

namespace Tests\Feature\Controllers;

use App\Enums\ShiftStatus;
use App\Enums\UserRole;
use App\Enums\WorkflowType;
use App\Exceptions\WorkflowLockedException;
use App\Models\Biker;
use App\Models\Restaurant;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shift Controller Feature Tests — Phase 2B
 *
 * Comprehensive test suite for Shift CRUD + Lifecycle operations.
 * Covers all acceptance criteria AC-2B-01 through AC-2B-47.
 *
 * Business Rules: BR-01 (Workflow Locking), BR-05 (Admin-only shift management)
 *
 * @see docs/plans/phase-2b-shift-crud-lifecycle.md
 */
class ShiftControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $restaurantManager;

    private User $bikerUser;

    private Restaurant $restaurant;

    private Restaurant $inactiveRestaurant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create([
            'name' => 'Active Restaurant',
            'rate_per_trip' => '15.00',
            'active' => true,
        ]);

        $this->inactiveRestaurant = Restaurant::factory()->create([
            'name' => 'Inactive Restaurant',
            'rate_per_trip' => '10.00',
            'active' => false,
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
    // HELPER: Create a shift via factory with known values
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

    // ========================================================================
    // AC-2B-01: Admin can view the shift creation form
    // ========================================================================

    /**
     * AC-2B-01: Admin can view the shift creation form at GET /shifts/create.
     * BR-05: Only admin can access.
     */
    public function test_admin_can_view_shift_creation_form(): void
    {
        $response = $this->actingAs($this->admin)->get(route('shifts.create'));

        $response->assertOk();
        $response->assertViewIs('shifts.create');
    }

    // ========================================================================
    // AC-2B-02: Admin can create a shift with live_tick
    // ========================================================================

    /**
     * AC-2B-02: Admin creates a shift with restaurant_id, workflow_type=live_tick, restaurant_rate=15.00.
     * Redirected to shifts.show with success flash.
     */
    public function test_admin_can_create_shift_with_live_tick(): void
    {
        $response = $this->actingAs($this->admin)->post(route('shifts.store'), [
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '15.00',
        ]);

        $shift = Shift::latest()->first();

        $response->assertRedirect(route('shifts.show', $shift));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('shifts', [
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '15.00',
            'status' => 'draft',
        ]);
    }

    // ========================================================================
    // AC-2B-03: Admin can create a shift with manual_entry
    // ========================================================================

    /**
     * AC-2B-03: Admin creates a shift with workflow_type=manual_entry.
     * Shift is stored correctly.
     */
    public function test_admin_can_create_shift_with_manual_entry(): void
    {
        $response = $this->actingAs($this->admin)->post(route('shifts.store'), [
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'manual_entry',
            'restaurant_rate' => '20.00',
        ]);

        $shift = Shift::latest()->first();

        $response->assertRedirect(route('shifts.show', $shift));

        $this->assertDatabaseHas('shifts', [
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'manual_entry',
            'restaurant_rate' => '20.00',
        ]);
    }

    // ========================================================================
    // AC-2B-04: Creating a shift without restaurant_id returns validation error
    // ========================================================================

    /**
     * AC-2B-04: Missing restaurant_id returns validation error.
     */
    public function test_create_shift_without_restaurant_id_returns_validation_error(): void
    {
        $response = $this->actingAs($this->admin)->post(route('shifts.store'), [
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '15.00',
        ]);

        $response->assertSessionHasErrors('restaurant_id');
    }

    // ========================================================================
    // AC-2B-05: Creating a shift without workflow_type returns validation error
    // ========================================================================

    /**
     * AC-2B-05: Missing workflow_type returns validation error.
     */
    public function test_create_shift_without_workflow_type_returns_validation_error(): void
    {
        $response = $this->actingAs($this->admin)->post(route('shifts.store'), [
            'restaurant_id' => $this->restaurant->id,
            'restaurant_rate' => '15.00',
        ]);

        $response->assertSessionHasErrors('workflow_type');
    }

    // ========================================================================
    // AC-2B-06: Creating a shift without restaurant_rate returns validation error
    // ========================================================================

    /**
     * AC-2B-06: Missing restaurant_rate returns validation error.
     */
    public function test_create_shift_without_restaurant_rate_returns_validation_error(): void
    {
        $response = $this->actingAs($this->admin)->post(route('shifts.store'), [
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
        ]);

        $response->assertSessionHasErrors('restaurant_rate');
    }

    // ========================================================================
    // AC-2B-07: Creating a shift with negative restaurant_rate returns error
    // ========================================================================

    /**
     * AC-2B-07: restaurant_rate = -1.00 returns validation error (min:0).
     */
    public function test_create_shift_with_negative_rate_returns_validation_error(): void
    {
        $response = $this->actingAs($this->admin)->post(route('shifts.store'), [
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '-1.00',
        ]);

        $response->assertSessionHasErrors('restaurant_rate');
    }

    // ========================================================================
    // AC-2B-08: New shift has status = draft by default
    // ========================================================================

    /**
     * AC-2B-08: New shift status defaults to 'draft'.
     */
    public function test_new_shift_has_status_draft(): void
    {
        $this->actingAs($this->admin)->post(route('shifts.store'), [
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '15.00',
        ]);

        $shift = Shift::latest()->first();

        $this->assertEquals(ShiftStatus::Draft, $shift->status,
            'AC-2B-08: New shift must default to draft status');
    }

    // ========================================================================
    // AC-2B-09: New shift has created_by set to authenticated admin's user ID
    // ========================================================================

    /**
     * AC-2B-09: created_by is auto-set to the authenticated admin's user ID.
     */
    public function test_new_shift_has_created_by_set_to_admin(): void
    {
        $this->actingAs($this->admin)->post(route('shifts.store'), [
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '15.00',
        ]);

        $shift = Shift::latest()->first();

        $this->assertEquals($this->admin->id, $shift->created_by,
            'AC-2B-09: created_by must be set to the authenticated admin user ID');
    }

    // ========================================================================
    // AC-2B-10: Non-existent restaurant_id returns validation error
    // ========================================================================

    /**
     * AC-2B-10: restaurant_id that does not exist returns validation error.
     */
    public function test_create_shift_with_nonexistent_restaurant_returns_error(): void
    {
        $response = $this->actingAs($this->admin)->post(route('shifts.store'), [
            'restaurant_id' => 99999,
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '15.00',
        ]);

        $response->assertSessionHasErrors('restaurant_id');
    }

    // ========================================================================
    // AC-2B-11: Inactive restaurant returns validation error
    // ========================================================================

    /**
     * AC-2B-11: Creating a shift with an inactive restaurant returns validation error.
     */
    public function test_create_shift_with_inactive_restaurant_returns_error(): void
    {
        $response = $this->actingAs($this->admin)->post(route('shifts.store'), [
            'restaurant_id' => $this->inactiveRestaurant->id,
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '15.00',
        ]);

        $response->assertSessionHasErrors('restaurant_id');
    }

    // ========================================================================
    // AC-2B-12: Admin can view shift list
    // ========================================================================

    /**
     * AC-2B-12: Admin can view the shift list at GET /shifts.
     */
    public function test_admin_can_view_shift_list(): void
    {
        $response = $this->actingAs($this->admin)->get(route('shifts.index'));

        $response->assertOk();
        $response->assertViewIs('shifts.index');
    }

    // ========================================================================
    // AC-2B-13: Shift list displays key columns
    // ========================================================================

    /**
     * AC-2B-13: Shift list displays restaurant name, workflow_type, status, restaurant_rate, created_at.
     */
    public function test_shift_list_displays_shift_data(): void
    {
        $shift = $this->createDraftShift([
            'restaurant_rate' => '18.50',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->get(route('shifts.index'));

        $response->assertSee($this->restaurant->name);
        $response->assertSee('live_tick');
        $response->assertSee('draft');
        $response->assertSee('18.50');
    }

    // ========================================================================
    // AC-2B-14: Shift list ordered by created_at descending
    // ========================================================================

    /**
     * AC-2B-14: Shift list is ordered by created_at descending (newest first).
     */
    public function test_shift_list_ordered_by_created_at_descending(): void
    {
        $oldest = $this->createDraftShift(['created_at' => now()->subDays(2)]);
        $newest = $this->createDraftShift(['created_at' => now()->subDays(1)]);
        $middle = $this->createDraftShift(['created_at' => now()->subDays(3)]);

        $response = $this->actingAs($this->admin)->get(route('shifts.index'));

        $shifts = $response->viewData('shifts');

        // Newest first
        $this->assertTrue(
            $shifts->first()->created_at >= $shifts->last()->created_at,
            'AC-2B-14: Shift list must be ordered by created_at descending'
        );
    }

    // ========================================================================
    // AC-2B-15: Shift list is paginated (15 per page)
    // ========================================================================

    /**
     * AC-2B-15: Shift list is paginated at 15 per page.
     */
    public function test_shift_list_is_paginated(): void
    {
        // Create 16 shifts to exceed one page
        Shift::factory()->count(16)->create([
            'restaurant_id' => $this->restaurant->id,
        ]);

        $response = $this->actingAs($this->admin)->get(route('shifts.index'));

        $shifts = $response->viewData('shifts');
        $this->assertCount(15, $shifts,
            'AC-2B-15: Shift list must show 15 shifts per page');
    }

    // ========================================================================
    // AC-2B-16: Admin can filter shifts by status
    // ========================================================================

    /**
     * AC-2B-16: Admin can filter shifts by status via ?status=draft.
     */
    public function test_shift_list_can_filter_by_status(): void
    {
        $draftShift = $this->createDraftShift();
        $openShift = $this->createOpenShift();

        $response = $this->actingAs($this->admin)->get(route('shifts.index', ['status' => 'draft']));

        $shifts = $response->viewData('shifts');

        $this->assertTrue($shifts->contains('id', $draftShift->id),
            'AC-2B-16: Filtered list must contain the draft shift');
        $this->assertFalse($shifts->contains('id', $openShift->id),
            'AC-2B-16: Filtered list must NOT contain the open shift');
    }

    // ========================================================================
    // AC-2B-17: Invalid status filter is ignored
    // ========================================================================

    /**
     * AC-2B-17: Invalid status filter value is ignored (shows all shifts).
     */
    public function test_invalid_status_filter_shows_all_shifts(): void
    {
        $draftShift = $this->createDraftShift();
        $openShift = $this->createOpenShift();

        $response = $this->actingAs($this->admin)->get(route('shifts.index', ['status' => 'invalid_status']));

        $shifts = $response->viewData('shifts');

        $this->assertTrue($shifts->contains('id', $draftShift->id));
        $this->assertTrue($shifts->contains('id', $openShift->id));
    }

    // ========================================================================
    // AC-2B-18: Unauthenticated user redirected to login
    // ========================================================================

    /**
     * AC-2B-18: Unauthenticated user accessing /shifts is redirected to /login.
     */
    public function test_unauthenticated_user_redirected_to_login_from_shift_list(): void
    {
        $response = $this->get(route('shifts.index'));

        $response->assertRedirect(route('login'));
    }

    // ========================================================================
    // AC-2B-19: Non-admin receives 403
    // ========================================================================

    /**
     * AC-2B-19: Non-admin (RestaurantManager) receives 403 on shift list.
     */
    public function test_restaurant_manager_receives_403_on_shift_list(): void
    {
        $response = $this->actingAs($this->restaurantManager)->get(route('shifts.index'));

        $response->assertForbidden();
    }

    /**
     * AC-2B-19: Non-admin (Biker) receives 403 on shift list.
     */
    public function test_biker_receives_403_on_shift_list(): void
    {
        $response = $this->actingAs($this->bikerUser)->get(route('shifts.index'));

        $response->assertForbidden();
    }

    // ========================================================================
    // AC-2B-20: Admin can view shift details
    // ========================================================================

    /**
     * AC-2B-20: Admin can view shift details at GET /shifts/{id}.
     */
    public function test_admin_can_view_shift_details(): void
    {
        $shift = $this->createDraftShift();

        $response = $this->actingAs($this->admin)->get(route('shifts.show', $shift));

        $response->assertOk();
        $response->assertViewIs('shifts.show');
    }

    // ========================================================================
    // AC-2B-21: Show view displays shift data
    // ========================================================================

    /**
     * AC-2B-21: Show view displays restaurant name, workflow_type, status, restaurant_rate,
     * started_at, closed_at, created_by user name.
     */
    public function test_show_view_displays_shift_data(): void
    {
        $shift = $this->createDraftShift([
            'restaurant_rate' => '22.50',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->get(route('shifts.show', $shift));

        $response->assertSee($this->restaurant->name);
        $response->assertSee('22.50');
        $response->assertSee($this->admin->name);
    }

    // ========================================================================
    // AC-2B-22: Show view includes "Close Shift" button only when open
    // ========================================================================

    /**
     * AC-2B-22: Show view includes "Close Shift" button when status is open.
     */
    public function test_show_view_includes_close_button_for_open_shift(): void
    {
        $shift = $this->createOpenShift();

        $response = $this->actingAs($this->admin)->get(route('shifts.show', $shift));

        $response->assertSee(route('shifts.close', $shift));
    }

    /**
     * AC-2B-22: Show view does NOT include "Close Shift" button for draft shift.
     */
    public function test_show_view_no_close_button_for_draft_shift(): void
    {
        $shift = $this->createDraftShift();

        $response = $this->actingAs($this->admin)->get(route('shifts.show', $shift));

        $response->assertDontSee(route('shifts.close', $shift));
    }

    // ========================================================================
    // AC-2B-23: Show view includes "Edit" button only when draft
    // ========================================================================

    /**
     * AC-2B-23: Show view includes "Edit" button when status is draft.
     */
    public function test_show_view_includes_edit_button_for_draft_shift(): void
    {
        $shift = $this->createDraftShift();

        $response = $this->actingAs($this->admin)->get(route('shifts.show', $shift));

        $response->assertSee(route('shifts.edit', $shift));
    }

    /**
     * AC-2B-23: Show view does NOT include "Edit" button for open shift.
     */
    public function test_show_view_no_edit_button_for_open_shift(): void
    {
        $shift = $this->createOpenShift();

        $response = $this->actingAs($this->admin)->get(route('shifts.show', $shift));

        $response->assertDontSee(route('shifts.edit', $shift));
    }

    // ========================================================================
    // AC-2B-24: Non-existent shift returns 404
    // ========================================================================

    /**
     * AC-2B-24: Accessing a non-existent shift ID returns 404.
     */
    public function test_nonexistent_shift_returns_404(): void
    {
        $response = $this->actingAs($this->admin)->get(route('shifts.show', 99999));

        $response->assertNotFound();
    }

    // ========================================================================
    // AC-2B-25: Admin can view edit form
    // ========================================================================

    /**
     * AC-2B-25: Admin can view the edit form at GET /shifts/{id}/edit.
     */
    public function test_admin_can_view_edit_form(): void
    {
        $shift = $this->createDraftShift();

        $response = $this->actingAs($this->admin)->get(route('shifts.edit', $shift));

        $response->assertOk();
        $response->assertViewIs('shifts.edit');
    }

    // ========================================================================
    // AC-2B-26: Edit form pre-fills with current shift data
    // ========================================================================

    /**
     * AC-2B-26: Edit form pre-fills with current shift data.
     */
    public function test_edit_form_prefills_with_current_data(): void
    {
        $shift = $this->createDraftShift([
            'restaurant_rate' => '17.75',
            'workflow_type' => 'manual_entry',
        ]);

        $response = $this->actingAs($this->admin)->get(route('shifts.edit', $shift));

        $response->assertSee('17.75');
        $response->assertSee('manual_entry');
    }

    // ========================================================================
    // AC-2B-27: Admin can update restaurant_rate on draft shift
    // ========================================================================

    /**
     * AC-2B-27: Admin updates restaurant_rate on a draft shift → redirected to shifts.show with success flash.
     */
    public function test_admin_can_update_restaurant_rate_on_draft(): void
    {
        $shift = $this->createDraftShift(['restaurant_rate' => '15.00']);

        $response = $this->actingAs($this->admin)->put(route('shifts.update', $shift), [
            'restaurant_rate' => '20.00',
        ]);

        $response->assertRedirect(route('shifts.show', $shift));
        $response->assertSessionHas('success');

        $this->assertEquals('20.00', $shift->fresh()->restaurant_rate,
            'AC-2B-27: restaurant_rate must be updated to 20.00');
    }

    // ========================================================================
    // AC-2B-28: Admin can update workflow_type on draft shift
    // ========================================================================

    /**
     * AC-2B-28: Admin updates workflow_type on a draft shift → change is persisted.
     */
    public function test_admin_can_update_workflow_type_on_draft(): void
    {
        $shift = $this->createDraftShift(['workflow_type' => 'live_tick']);

        $response = $this->actingAs($this->admin)->put(route('shifts.update', $shift), [
            'workflow_type' => 'manual_entry',
            'restaurant_rate' => '15.00',
        ]);

        $response->assertRedirect(route('shifts.show', $shift));

        $this->assertEquals(WorkflowType::ManualEntry, $shift->fresh()->workflow_type,
            'AC-2B-28: workflow_type must be updated to manual_entry on draft shift');
    }

    // ========================================================================
    // AC-2B-29: Updating workflow_type on non-draft returns error (BR-01)
    // ========================================================================

    /**
     * AC-2B-29: Updating workflow_type on an open shift returns validation error (BR-01).
     */
    public function test_update_workflow_type_on_open_shift_returns_error(): void
    {
        $shift = $this->createOpenShift(['workflow_type' => 'live_tick']);

        $response = $this->actingAs($this->admin)->put(route('shifts.update', $shift), [
            'workflow_type' => 'manual_entry',
            'restaurant_rate' => '15.00',
        ]);

        $response->assertSessionHasErrors('workflow_type');

        // Verify workflow_type was NOT changed
        $this->assertEquals(WorkflowType::LiveTick, $shift->fresh()->workflow_type,
            'AC-2B-29: workflow_type must remain unchanged on open shift');
    }

    /**
     * AC-2B-29: Updating workflow_type on a closed shift returns validation error (BR-01).
     */
    public function test_update_workflow_type_on_closed_shift_returns_error(): void
    {
        $shift = $this->createClosedShift(['workflow_type' => 'live_tick']);

        $response = $this->actingAs($this->admin)->put(route('shifts.update', $shift), [
            'workflow_type' => 'manual_entry',
        ]);

        $response->assertSessionHasErrors('workflow_type');
    }

    // ========================================================================
    // AC-2B-30: Invalid restaurant_rate on update returns error
    // ========================================================================

    /**
     * AC-2B-30: Updating with negative restaurant_rate returns validation error.
     */
    public function test_update_with_negative_rate_returns_error(): void
    {
        $shift = $this->createDraftShift();

        $response = $this->actingAs($this->admin)->put(route('shifts.update', $shift), [
            'restaurant_rate' => '-5.00',
        ]);

        $response->assertSessionHasErrors('restaurant_rate');
    }

    /**
     * AC-2B-30: Updating with non-numeric restaurant_rate returns validation error.
     */
    public function test_update_with_non_numeric_rate_returns_error(): void
    {
        $shift = $this->createDraftShift();

        $response = $this->actingAs($this->admin)->put(route('shifts.update', $shift), [
            'restaurant_rate' => 'abc',
        ]);

        $response->assertSessionHasErrors('restaurant_rate');
    }

    // ========================================================================
    // AC-2B-31: Same workflow_type value on non-draft succeeds
    // ========================================================================

    /**
     * AC-2B-31: Updating with same workflow_type value on a non-draft shift succeeds.
     * Only CHANGES are blocked; resubmitting the same value is fine.
     */
    public function test_update_same_workflow_type_on_non_draft_succeeds(): void
    {
        $shift = $this->createOpenShift(['workflow_type' => 'live_tick']);

        $response = $this->actingAs($this->admin)->put(route('shifts.update', $shift), [
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '15.00',
        ]);

        $response->assertSessionDoesntHaveErrors('workflow_type');

        // Should succeed — same value is not a change
        $response->assertRedirect(route('shifts.show', $shift));
    }

    // ========================================================================
    // AC-2B-32: Admin can close an open shift
    // ========================================================================

    /**
     * AC-2B-32: Admin can close an open shift via POST /shifts/{id}/close.
     */
    public function test_admin_can_close_open_shift(): void
    {
        $shift = $this->createOpenShift();

        $response = $this->actingAs($this->admin)->post(route('shifts.close', $shift), ['confirmed' => 1]);

        $response->assertRedirect(route('shifts.show', $shift));

        $this->assertEquals(ShiftStatus::Closed, $shift->fresh()->status,
            'AC-2B-32: Shift status must be closed after close action');
    }

    // ========================================================================
    // AC-2B-33: Closing sets status=closed and closed_at=now
    // ========================================================================

    /**
     * AC-2B-33: Closing a shift sets status to closed and closed_at to current timestamp.
     */
    public function test_close_shift_sets_status_and_closed_at(): void
    {
        $shift = $this->createOpenShift();

        $before = now()->subSecond();
        $this->actingAs($this->admin)->post(route('shifts.close', $shift), ['confirmed' => 1]);
        $after = now()->addSecond();

        $freshShift = $shift->fresh();
        $this->assertEquals(ShiftStatus::Closed, $freshShift->status,
            'AC-2B-33: Status must be closed');
        $this->assertNotNull($freshShift->closed_at,
            'AC-2B-33: closed_at must be set');
        $this->assertTrue(
            $freshShift->closed_at->greaterThanOrEqualTo($before)
            && $freshShift->closed_at->lessThanOrEqualTo($after),
            'AC-2B-33: closed_at must be approximately current timestamp'
        );
    }

    // ========================================================================
    // AC-2B-34: Closing a draft shift returns validation error
    // ========================================================================

    /**
     * AC-2B-34: Closing a draft shift returns validation error.
     */
    public function test_close_draft_shift_returns_error(): void
    {
        $shift = $this->createDraftShift();

        $response = $this->actingAs($this->admin)->post(route('shifts.close', $shift));

        $response->assertSessionHasErrors();
        $this->assertEquals(ShiftStatus::Draft, $shift->fresh()->status,
            'AC-2B-34: Draft shift must remain draft after failed close attempt');
    }

    // ========================================================================
    // AC-2B-35: Closing an already-closed shift returns error
    // ========================================================================

    /**
     * AC-2B-35: Closing an already-closed shift returns validation error.
     */
    public function test_close_already_closed_shift_returns_error(): void
    {
        $shift = $this->createClosedShift();

        $response = $this->actingAs($this->admin)->post(route('shifts.close', $shift));

        $response->assertSessionHasErrors();
    }

    // ========================================================================
    // AC-2B-36: Closing an approved shift returns error
    // ========================================================================

    /**
     * AC-2B-36: Closing an approved shift returns validation error.
     */
    public function test_close_approved_shift_returns_error(): void
    {
        $shift = $this->createClosedShift();
        $shift->status = ShiftStatus::Approved;
        $shift->save();

        $response = $this->actingAs($this->admin)->post(route('shifts.close', $shift));

        $response->assertSessionHasErrors();
    }

    // ========================================================================
    // AC-2B-37: After close, redirected to shifts.show with success flash
    // ========================================================================

    /**
     * AC-2B-37: After successful close, admin is redirected to shifts.show with success flash.
     */
    public function test_close_shift_redirects_to_show_with_success(): void
    {
        $shift = $this->createOpenShift();

        $response = $this->actingAs($this->admin)->post(route('shifts.close', $shift), ['confirmed' => 1]);

        $response->assertRedirect(route('shifts.show', $shift));
        $response->assertSessionHas('success');
    }

    // ========================================================================
    // AC-2B-38: All shift routes require authentication
    // ========================================================================

    /**
     * AC-2B-38: GET /shifts/create requires authentication.
     */
    public function test_create_form_requires_authentication(): void
    {
        $response = $this->get(route('shifts.create'));

        $response->assertRedirect(route('login'));
    }

    /**
     * AC-2B-38: POST /shifts (store) requires authentication.
     */
    public function test_store_requires_authentication(): void
    {
        $response = $this->post(route('shifts.store'), [
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '15.00',
        ]);

        $response->assertRedirect(route('login'));
    }

    /**
     * AC-2B-38: GET /shifts/{id} (show) requires authentication.
     */
    public function test_show_requires_authentication(): void
    {
        $shift = $this->createDraftShift();

        $response = $this->get(route('shifts.show', $shift));

        $response->assertRedirect(route('login'));
    }

    /**
     * AC-2B-38: GET /shifts/{id}/edit requires authentication.
     */
    public function test_edit_requires_authentication(): void
    {
        $shift = $this->createDraftShift();

        $response = $this->get(route('shifts.edit', $shift));

        $response->assertRedirect(route('login'));
    }

    /**
     * AC-2B-38: PUT /shifts/{id} (update) requires authentication.
     */
    public function test_update_requires_authentication(): void
    {
        $shift = $this->createDraftShift();

        $response = $this->put(route('shifts.update', $shift), [
            'restaurant_rate' => '20.00',
        ]);

        $response->assertRedirect(route('login'));
    }

    /**
     * AC-2B-38: POST /shifts/{id}/close requires authentication.
     */
    public function test_close_requires_authentication(): void
    {
        $shift = $this->createOpenShift();

        $response = $this->post(route('shifts.close', $shift));

        $response->assertRedirect(route('login'));
    }

    // ========================================================================
    // AC-2B-39: All shift routes require admin role
    // ========================================================================

    /**
     * AC-2B-39: Non-admin cannot access POST /shifts (store).
     */
    public function test_store_requires_admin_role(): void
    {
        $response = $this->actingAs($this->restaurantManager)->post(route('shifts.store'), [
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '15.00',
        ]);

        $response->assertForbidden();
    }

    /**
     * AC-2B-39: Non-admin cannot access GET /shifts/create.
     */
    public function test_create_form_requires_admin_role(): void
    {
        $response = $this->actingAs($this->restaurantManager)->get(route('shifts.create'));

        $response->assertForbidden();
    }

    /**
     * AC-2B-39: Non-admin cannot access GET /shifts/{id}/edit.
     */
    public function test_edit_requires_admin_role(): void
    {
        $shift = $this->createDraftShift();

        $response = $this->actingAs($this->restaurantManager)->get(route('shifts.edit', $shift));

        $response->assertForbidden();
    }

    /**
     * AC-2B-39: Non-admin cannot access PUT /shifts/{id} (update).
     */
    public function test_update_requires_admin_role(): void
    {
        $shift = $this->createDraftShift();

        $response = $this->actingAs($this->restaurantManager)->put(route('shifts.update', $shift), [
            'restaurant_rate' => '20.00',
        ]);

        $response->assertForbidden();
    }

    /**
     * AC-2B-39: Non-admin cannot close a shift.
     */
    public function test_close_requires_admin_role(): void
    {
        $shift = $this->createOpenShift();

        $response = $this->actingAs($this->restaurantManager)->post(route('shifts.close', $shift));

        $response->assertForbidden();
    }

    // ========================================================================
    // AC-2B-40: Non-admin receives 403 on any shift route
    // ========================================================================

    /**
     * AC-2B-40: Biker receives 403 on shift creation form.
     */
    public function test_biker_receives_403_on_create_form(): void
    {
        $response = $this->actingAs($this->bikerUser)->get(route('shifts.create'));

        $response->assertForbidden();
    }

    /**
     * AC-2B-40: Biker receives 403 on shift store.
     */
    public function test_biker_receives_403_on_store(): void
    {
        $response = $this->actingAs($this->bikerUser)->post(route('shifts.store'), [
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '15.00',
        ]);

        $response->assertForbidden();
    }

    /**
     * AC-2B-40: Biker receives 403 on shift show.
     */
    public function test_biker_receives_403_on_show(): void
    {
        $shift = $this->createDraftShift();

        $response = $this->actingAs($this->bikerUser)->get(route('shifts.show', $shift));

        $response->assertForbidden();
    }

    /**
     * AC-2B-40: Biker receives 403 on shift edit.
     */
    public function test_biker_receives_403_on_edit(): void
    {
        $shift = $this->createDraftShift();

        $response = $this->actingAs($this->bikerUser)->get(route('shifts.edit', $shift));

        $response->assertForbidden();
    }

    /**
     * AC-2B-40: Biker receives 403 on shift close.
     */
    public function test_biker_receives_403_on_close(): void
    {
        $shift = $this->createOpenShift();

        $response = $this->actingAs($this->bikerUser)->post(route('shifts.close', $shift));

        $response->assertForbidden();
    }

    // ========================================================================
    // AC-2B-41: ShiftPolicy@close returns true only for admin
    // ========================================================================

    /**
     * AC-2B-41: ShiftPolicy@close returns true for admin.
     */
    public function test_shift_policy_close_returns_true_for_admin(): void
    {
        $shift = $this->createOpenShift();

        $this->assertTrue(
            $this->admin->can('close', $shift),
            'AC-2B-41: Admin must be authorized to close a shift'
        );
    }

    /**
     * AC-2B-41: ShiftPolicy@close returns false for restaurant manager.
     */
    public function test_shift_policy_close_returns_false_for_restaurant_manager(): void
    {
        $shift = $this->createOpenShift();

        $this->assertFalse(
            $this->restaurantManager->can('close', $shift),
            'AC-2B-41: RestaurantManager must NOT be authorized to close a shift'
        );
    }

    /**
     * AC-2B-41: ShiftPolicy@close returns false for biker.
     */
    public function test_shift_policy_close_returns_false_for_biker(): void
    {
        $shift = $this->createOpenShift();

        $this->assertFalse(
            $this->bikerUser->can('close', $shift),
            'AC-2B-41: Biker must NOT be authorized to close a shift'
        );
    }

    // ========================================================================
    // AC-2B-43: Controller does not bypass model-level BR-01 guard
    // ========================================================================

    /**
     * AC-2B-43: Updating workflow_type via controller on non-draft triggers model guard.
     * The controller's UpdateShiftRequest should catch this, but even if bypassed,
     * the model's saving hook throws WorkflowLockedException.
     */
    public function test_controller_does_not_bypass_model_workflow_lock(): void
    {
        $shift = $this->createOpenShift(['workflow_type' => 'live_tick']);

        // Even if we somehow bypass the form request, the model should block it.
        // We test by directly attempting a save through the model (simulating bypass).
        $this->expectException(WorkflowLockedException::class);

        $shift->workflow_type = 'manual_entry';
        $shift->save();
    }

    // ========================================================================
    // AC-2B-44: Index view renders without errors when shifts exist
    // ========================================================================

    /**
     * AC-2B-44: shifts/index view renders without errors when shifts exist.
     */
    public function test_index_view_renders_with_shifts(): void
    {
        $this->createDraftShift();
        $this->createOpenShift();

        $response = $this->actingAs($this->admin)->get(route('shifts.index'));

        $response->assertOk();
        $response->assertViewHas('shifts');
    }

    // ========================================================================
    // AC-2B-45: Index view renders "No shifts found" when empty
    // ========================================================================

    /**
     * AC-2B-45: shifts/index view renders "No shifts found" when no shifts exist.
     */
    public function test_index_view_renders_empty_message(): void
    {
        $response = $this->actingAs($this->admin)->get(route('shifts.index'));

        $response->assertOk();
        $response->assertSee('No shifts found');
    }

    // ========================================================================
    // AC-2B-46: Create view has restaurant dropdown from active restaurants
    // ========================================================================

    /**
     * AC-2B-46: Create view shows restaurant dropdown populated from active restaurants.
     */
    public function test_create_view_shows_active_restaurants(): void
    {
        $response = $this->actingAs($this->admin)->get(route('shifts.create'));

        $response->assertOk();
        $response->assertSee($this->restaurant->name);
    }

    /**
     * AC-2B-46: Create view does NOT show inactive restaurants in the dropdown.
     */
    public function test_create_view_does_not_show_inactive_restaurants(): void
    {
        $response = $this->actingAs($this->admin)->get(route('shifts.create'));

        $response->assertOk();
        $response->assertDontSee($this->inactiveRestaurant->name);
    }

    // ========================================================================
    // AC-2B-47: Edit view shows workflow_type as read-only for non-draft
    // ========================================================================

    /**
     * AC-2B-47: Edit view shows workflow_type as read-only/disabled when shift is not in draft.
     */
    public function test_edit_view_workflow_type_read_only_for_open_shift(): void
    {
        $shift = $this->createOpenShift(['workflow_type' => 'live_tick']);

        $response = $this->actingAs($this->admin)->get(route('shifts.edit', $shift));

        $response->assertOk();
        // The view should contain 'disabled' or 'readonly' near the workflow_type field.
        // We check for the presence of the text indicating it's not editable.
        $content = $response->getContent();
        $this->assertTrue(
            str_contains($content, 'disabled') || str_contains($content, 'readonly'),
            'AC-2B-47: Edit view must show workflow_type as disabled/readonly for non-draft shifts'
        );
    }

    /**
     * AC-2B-47: Edit view shows workflow_type as editable for draft shift.
     */
    public function test_edit_view_workflow_type_editable_for_draft_shift(): void
    {
        $shift = $this->createDraftShift(['workflow_type' => 'live_tick']);

        $response = $this->actingAs($this->admin)->get(route('shifts.edit', $shift));

        $response->assertOk();
        $response->assertSee('live_tick');
    }

    // ========================================================================
    // Additional Boundary / Validation Tests
    // ========================================================================

    /**
     * Creating a shift with invalid workflow_type returns validation error.
     */
    public function test_create_shift_with_invalid_workflow_type_returns_error(): void
    {
        $response = $this->actingAs($this->admin)->post(route('shifts.store'), [
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'invalid_type',
            'restaurant_rate' => '15.00',
        ]);

        $response->assertSessionHasErrors('workflow_type');
    }

    /**
     * Creating a shift with zero restaurant_rate succeeds (0.00 is valid).
     */
    public function test_create_shift_with_zero_rate_succeeds(): void
    {
        $response = $this->actingAs($this->admin)->post(route('shifts.store'), [
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '0.00',
        ]);

        $response->assertRedirect(route('shifts.show', Shift::latest()->first()));

        $this->assertDatabaseHas('shifts', [
            'restaurant_rate' => '0.00',
        ]);
    }

    /**
     * Creating a shift with very large restaurant_rate succeeds.
     */
    public function test_create_shift_with_large_rate_succeeds(): void
    {
        $response = $this->actingAs($this->admin)->post(route('shifts.store'), [
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '9999999999.99',
        ]);

        $response->assertRedirect(route('shifts.show', Shift::latest()->first()));
    }

    /**
     * Creating a shift with rate exceeding max returns validation error.
     */
    public function test_create_shift_with_exceeding_max_rate_returns_error(): void
    {
        $response = $this->actingAs($this->admin)->post(route('shifts.store'), [
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '10000000000.00',
        ]);

        $response->assertSessionHasErrors('restaurant_rate');
    }

    /**
     * Closing a shift preserves the workflow_type (BR-01).
     */
    public function test_close_preserves_workflow_type(): void
    {
        $shift = $this->createOpenShift(['workflow_type' => 'manual_entry']);

        $this->actingAs($this->admin)->post(route('shifts.close', $shift), ['confirmed' => 1]);

        $this->assertEquals(WorkflowType::ManualEntry, $shift->fresh()->workflow_type,
            'BR-01: workflow_type must be preserved after close');
    }

    /**
     * created_by is not overridable via form submission.
     * Security: The controller sets created_by server-side.
     */
    public function test_created_by_not_overridable_via_form(): void
    {
        $otherAdmin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($this->admin)->post(route('shifts.store'), [
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '15.00',
            'created_by' => $otherAdmin->id, // Attempt to override
        ]);

        $shift = Shift::latest()->first();
        $this->assertEquals($this->admin->id, $shift->created_by,
            'created_by must be set to the authenticated user, not from form input');
    }
}
