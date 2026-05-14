<?php

namespace Tests\Feature\Models;

use App\Enums\ShiftStatus;
use App\Enums\WorkflowType;
use App\Exceptions\WorkflowLockedException;
use App\Models\Biker;
use App\Models\Restaurant;
use App\Models\Shift;
use App\Models\ShiftBiker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shift Model Tests
 *
 * AC-05: Shifts table schema (draft default, nullable started_at, created_by, indexes).
 * AC-12: Shift has belongsTo(Restaurant) and hasMany(ShiftBiker) relationships.
 * AC-36 → AC-38a: BR-01 Workflow locking with Option C draft state.
 */
class ShiftModelTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->restaurant = Restaurant::create([
            'name' => 'Test Restaurant',
            'rate_per_trip' => '15.00',
        ]);
    }

    // ========================================================================
    // AC-05: Shifts table schema
    // ========================================================================

    /**
     * AC-05: New shifts default to status 'draft'.
     */
    public function test_shift_default_status_is_draft(): void
    {
        $shift = Shift::create([
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '15.00',
        ]);

        $this->assertEquals(ShiftStatus::Draft, $shift->status,
            'AC-05: New shift must default to status "draft"');
    }

    /**
     * AC-05: New shifts have nullable started_at (NULL at creation in draft).
     */
    public function test_shift_started_at_is_nullable_and_null_by_default(): void
    {
        $shift = Shift::create([
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '15.00',
        ]);

        $this->assertNull($shift->started_at,
            'AC-05: started_at must be NULL when shift is in draft');
    }

    /**
     * AC-05: Shift has created_by column that is nullable.
     */
    public function test_shift_has_nullable_created_by(): void
    {
        $shift = Shift::create([
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '15.00',
        ]);

        $this->assertNull($shift->created_by,
            'AC-05: created_by must be nullable and NULL by default');
    }

    /**
     * AC-05: Shift workflow_type defaults to 'live_tick'.
     */
    public function test_shift_default_workflow_type_is_live_tick(): void
    {
        $shift = Shift::create([
            'restaurant_id' => $this->restaurant->id,
            'restaurant_rate' => '15.00',
        ]);

        $this->assertEquals(WorkflowType::LiveTick, $shift->workflow_type,
            'AC-05: workflow_type must default to "live_tick"');
    }

    /**
     * AC-05: Shift restaurant_rate is DECIMAL(12,2).
     */
    public function test_shift_restaurant_rate_is_decimal(): void
    {
        $shift = Shift::create([
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '17.50',
        ]);

        $fresh = Shift::find($shift->id);
        $this->assertEquals('17.50', $fresh->restaurant_rate,
            'AC-05: restaurant_rate must be stored as DECIMAL(12,2)');
    }

    // ========================================================================
    // AC-12: Shift belongsTo Restaurant
    // ========================================================================

    /**
     * AC-12: Shift has a restaurant() relationship returning BelongsTo.
     */
    public function test_shift_belongs_to_restaurant(): void
    {
        $shift = Shift::create([
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '15.00',
        ]);

        $this->assertInstanceOf(Restaurant::class, $shift->restaurant);
        $this->assertEquals($this->restaurant->id, $shift->restaurant->id,
            'AC-12: Shift::restaurant() must return the owning restaurant');
    }

    // ========================================================================
    // AC-12: Shift hasMany ShiftBikers
    // ========================================================================

    /**
     * AC-12: Shift has a shiftBikers() relationship returning HasMany.
     */
    public function test_shift_has_shift_bikers_relationship(): void
    {
        $shift = Shift::create([
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '15.00',
        ]);

        $biker = Biker::create([
            'name' => 'Test Biker',
            'phone' => '11999999999',
        ]);

        ShiftBiker::create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);

        $this->assertCount(1, $shift->shiftBikers,
            'AC-12: Shift should have exactly 1 ShiftBiker');
    }

    /**
     * AC-12: Shift can have multiple bikers.
     */
    public function test_shift_can_have_multiple_shift_bikers(): void
    {
        $shift = Shift::create([
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '15.00',
        ]);

        $biker1 = Biker::create(['name' => 'B1', 'phone' => '11111111111']);
        $biker2 = Biker::create(['name' => 'B2', 'phone' => '22222222222']);

        ShiftBiker::create([
            'shift_id' => $shift->id,
            'biker_id' => $biker1->id,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);

        ShiftBiker::create([
            'shift_id' => $shift->id,
            'biker_id' => $biker2->id,
            'biker_rate' => '12.00',
            'base_fee' => '30.00',
        ]);

        $this->assertCount(2, $shift->refresh()->shiftBikers,
            'AC-12: Shift should have exactly 2 ShiftBikers');
    }

    // ========================================================================
    // AC-12: Enum casts on Shift model
    // ========================================================================

    /**
     * AC-12: Shift model casts status as ShiftStatus enum.
     */
    public function test_shift_casts_status_as_shift_status_enum(): void
    {
        $shift = Shift::create([
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '15.00',
        ]);

        $fresh = Shift::find($shift->id);
        $this->assertInstanceOf(ShiftStatus::class, $fresh->status,
            'AC-12: Shift::status must be cast to ShiftStatus enum');
        $this->assertEquals(ShiftStatus::Draft, $fresh->status,
            'AC-12: Default status must be ShiftStatus::Draft');
    }

    /**
     * AC-12: Shift model casts workflow_type as WorkflowType enum.
     */
    public function test_shift_casts_workflow_type_as_workflow_type_enum(): void
    {
        $shift = Shift::create([
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '15.00',
        ]);

        $fresh = Shift::find($shift->id);
        $this->assertInstanceOf(WorkflowType::class, $fresh->workflow_type,
            'AC-12: Shift::workflow_type must be cast to WorkflowType enum');
        $this->assertEquals(WorkflowType::LiveTick, $fresh->workflow_type,
            'AC-12: Default workflow_type must be WorkflowType::LiveTick');
    }

    // ========================================================================
    // AC-36: Creating a shift with workflow_type in draft succeeds
    // ========================================================================

    /**
     * AC-36: Creating a new Shift with workflow_type = 'live_tick' and default
     * status 'draft' succeeds without exception.
     */
    public function test_create_shift_with_workflow_type_in_draft_succeeds(): void
    {
        $shift = Shift::create([
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '15.00',
        ]);

        $this->assertNotNull($shift->id,
            'AC-36: Shift creation with live_tick workflow must succeed');
        $this->assertEquals(ShiftStatus::Draft, $shift->status,
            'AC-36: New shift must be in draft status');
        $this->assertEquals(WorkflowType::LiveTick, $shift->workflow_type,
            'AC-36: Workflow type must be preserved');
    }

    /**
     * AC-36: Creating a shift with manual_entry workflow in draft also succeeds.
     */
    public function test_create_shift_with_manual_entry_in_draft_succeeds(): void
    {
        $shift = Shift::create([
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'manual_entry',
            'restaurant_rate' => '15.00',
        ]);

        $this->assertNotNull($shift->id,
            'AC-36: Shift creation with manual_entry workflow must succeed');
        $this->assertEquals(WorkflowType::ManualEntry, $shift->workflow_type);
    }

    // ========================================================================
    // AC-36a: Workflow type is freely editable while in draft
    // ========================================================================

    /**
     * AC-36a: A Shift in draft status can have its workflow_type changed.
     * No WorkflowLockedException should be thrown.
     */
    public function test_workflow_type_editable_in_draft_status(): void
    {
        $shift = Shift::create([
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '15.00',
        ]);

        // Change workflow_type while still in draft — must succeed
        $shift->workflow_type = 'manual_entry';
        $shift->save();

        $this->assertEquals(WorkflowType::ManualEntry, $shift->fresh()->workflow_type,
            'AC-36a: workflow_type must be changeable in draft status');
    }

    /**
     * AC-36a: Multiple workflow_type changes in draft all succeed.
     */
    public function test_workflow_type_multiple_changes_in_draft(): void
    {
        $shift = Shift::create([
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '15.00',
        ]);

        $shift->workflow_type = 'manual_entry';
        $shift->save();

        $shift->workflow_type = 'live_tick';
        $shift->save();

        $this->assertEquals(WorkflowType::LiveTick, $shift->fresh()->workflow_type,
            'AC-36a: Multiple workflow_type changes in draft must all succeed');
    }

    // ========================================================================
    // AC-36b: Transitioning draft → open sets started_at
    // ========================================================================

    /**
     * AC-36b: Transitioning a Shift from draft to open sets started_at.
     */
    public function test_transition_draft_to_open_sets_started_at(): void
    {
        $shift = Shift::create([
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '15.00',
        ]);

        $this->assertNull($shift->started_at,
            'AC-36b: started_at must be NULL in draft');

        $shift->status = 'open';
        $shift->save();

        $this->assertNotNull($shift->fresh()->started_at,
            'AC-36b: started_at must be set when transitioning draft → open');
    }

    /**
     * AC-36b: started_at is set to approximately the current time.
     */
    public function test_transition_draft_to_open_started_at_is_recent(): void
    {
        $before = now()->subSecond();

        $shift = Shift::create([
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '15.00',
        ]);

        $shift->status = 'open';
        $shift->save();

        $after = now()->addSecond();

        $startedAt = $shift->fresh()->started_at;
        $this->assertTrue(
            $startedAt->greaterThanOrEqualTo($before) && $startedAt->lessThanOrEqualTo($after),
            'AC-36b: started_at must be approximately the current timestamp'
        );
    }

    // ========================================================================
    // AC-37: Workflow type locked in open status
    // ========================================================================

    /**
     * AC-37: A Shift with status 'open' throws WorkflowLockedException when
     * attempting to change workflow_type.
     */
    public function test_workflow_type_locked_in_open_status(): void
    {
        $shift = Shift::create([
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '15.00',
        ]);

        // Transition to open
        $shift->status = 'open';
        $shift->save();

        // Now attempt to change workflow_type — must throw
        $this->expectException(WorkflowLockedException::class);

        $shift->workflow_type = 'manual_entry';
        $shift->save();
    }

    // ========================================================================
    // AC-37a: Workflow type locked in closed, approved, paid statuses
    // ========================================================================

    /**
     * AC-37a: Workflow type locked in closed status.
     */
    public function test_workflow_type_locked_in_closed_status(): void
    {
        $shift = $this->createShiftThroughLifecycle('closed');

        $this->expectException(WorkflowLockedException::class);

        $shift->workflow_type = 'manual_entry';
        $shift->save();
    }

    /**
     * AC-37a: Workflow type locked in approved status.
     */
    public function test_workflow_type_locked_in_approved_status(): void
    {
        $shift = $this->createShiftThroughLifecycle('approved');

        $this->expectException(WorkflowLockedException::class);

        $shift->workflow_type = 'manual_entry';
        $shift->save();
    }

    /**
     * AC-37a: Workflow type locked in paid status.
     */
    public function test_workflow_type_locked_in_paid_status(): void
    {
        $shift = $this->createShiftThroughLifecycle('paid');

        $this->expectException(WorkflowLockedException::class);

        $shift->workflow_type = 'manual_entry';
        $shift->save();
    }

    // ========================================================================
    // AC-37/37a: Exception contains shift and attempted value
    // ========================================================================

    /**
     * AC-37: WorkflowLockedException contains the shift and attempted value.
     */
    public function test_workflow_locked_exception_contains_shift_and_attempted_value(): void
    {
        $shift = Shift::create([
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '15.00',
        ]);

        $shift->status = 'open';
        $shift->save();

        try {
            $shift->workflow_type = 'manual_entry';
            $shift->save();
            $this->fail('Expected WorkflowLockedException was not thrown');
        } catch (WorkflowLockedException $e) {
            $this->assertSame($shift->id, $e->getShift()->id,
                'AC-37: Exception must contain the shift that was being modified');
            $this->assertEquals('manual_entry', $e->getAttemptedValue(),
                'AC-37: Exception must contain the attempted workflow_type value');
        }
    }

    // ========================================================================
    // AC-38: Non-workflow updates on started shift do NOT throw
    // ========================================================================

    /**
     * AC-38: Updating status on a non-draft shift does NOT throw.
     * Only workflow_type changes are blocked.
     */
    public function test_status_update_on_open_shift_does_not_throw(): void
    {
        $shift = Shift::create([
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '15.00',
        ]);

        $shift->status = 'open';
        $shift->save();

        // Update status to closed — must NOT throw
        $shift->status = 'closed';
        $shift->closed_at = now();
        $shift->save();

        $this->assertEquals(ShiftStatus::Closed, $shift->fresh()->status,
            'AC-38: Status changes on non-draft shift must be allowed');
    }

    /**
     * AC-38: Updating restaurant_rate on an open shift does NOT throw.
     */
    public function test_restaurant_rate_update_on_open_shift_does_not_throw(): void
    {
        $shift = Shift::create([
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '15.00',
        ]);

        $shift->status = 'open';
        $shift->save();

        $shift->restaurant_rate = '20.00';
        $shift->save();

        $this->assertEquals('20.00', $shift->fresh()->restaurant_rate,
            'AC-38: restaurant_rate changes must be allowed on non-draft shift');
    }

    /**
     * AC-38: Updating closed_at on an open shift does NOT throw.
     */
    public function test_closed_at_update_on_open_shift_does_not_throw(): void
    {
        $shift = Shift::create([
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '15.00',
        ]);

        $shift->status = 'open';
        $shift->save();

        $shift->closed_at = now();
        $shift->save();

        $this->assertNotNull($shift->fresh()->closed_at,
            'AC-38: closed_at changes must be allowed on non-draft shift');
    }

    // ========================================================================
    // AC-38a: Draft cannot skip to closed/approved/paid
    // ========================================================================

    /**
     * AC-38a: A Shift in draft status cannot transition directly to closed.
     * Must go through open first.
     */
    public function test_draft_cannot_transition_to_closed(): void
    {
        $shift = Shift::create([
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '15.00',
        ]);

        $this->expectException(\RuntimeException::class);

        $shift->status = 'closed';
        $shift->save();
    }

    /**
     * AC-38a: A Shift in draft status cannot transition directly to approved.
     */
    public function test_draft_cannot_transition_to_approved(): void
    {
        $shift = Shift::create([
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '15.00',
        ]);

        $this->expectException(\RuntimeException::class);

        $shift->status = 'approved';
        $shift->save();
    }

    /**
     * AC-38a: A Shift in draft status cannot transition directly to paid.
     */
    public function test_draft_cannot_transition_to_paid(): void
    {
        $shift = Shift::create([
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '15.00',
        ]);

        $this->expectException(\RuntimeException::class);

        $shift->status = 'paid';
        $shift->save();
    }

    /**
     * AC-38a: Draft → open transition IS allowed (valid path).
     */
    public function test_draft_to_open_transition_is_allowed(): void
    {
        $shift = Shift::create([
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '15.00',
        ]);

        $shift->status = 'open';
        $shift->save();

        $this->assertEquals(ShiftStatus::Open, $shift->fresh()->status,
            'AC-38a: draft → open must be allowed');
    }

    // ========================================================================
    // AC-16/AC-17: Shift model fillable and casts
    // ========================================================================

    /**
     * AC-17: Shift model uses explicit $fillable.
     */
    public function test_shift_has_fillable_array(): void
    {
        $shift = new Shift;
        $fillable = $shift->getFillable();

        $this->assertContains('restaurant_id', $fillable);
        $this->assertContains('workflow_type', $fillable);
        $this->assertContains('status', $fillable);
        $this->assertContains('restaurant_rate', $fillable);
        $this->assertContains('started_at', $fillable);
        $this->assertContains('closed_at', $fillable);

        $this->assertNotEmpty($fillable,
            'AC-17: Shift must use explicit $fillable');
    }

    /**
     * AC-16: Shift model casts restaurant_rate as decimal:2.
     */
    public function test_shift_casts_restaurant_rate_as_decimal(): void
    {
        $shift = Shift::create([
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '12.50',
        ]);

        $fresh = Shift::find($shift->id);
        $this->assertIsString($fresh->restaurant_rate,
            'AC-16: restaurant_rate must be cast to string via decimal:2');
        $this->assertEquals('12.50', $fresh->restaurant_rate);
    }

    // ========================================================================
    // Helper: Create shift through lifecycle states
    // ========================================================================

    /**
     * Creates a shift and advances it through the lifecycle to the target status.
     * Used for testing BR-01 on non-draft statuses.
     */
    private function createShiftThroughLifecycle(string $targetStatus): Shift
    {
        $shift = Shift::create([
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
            'restaurant_rate' => '15.00',
        ]);

        // draft → open
        $shift->status = 'open';
        $shift->save();

        if ($targetStatus === 'open') {
            return $shift->fresh();
        }

        // open → closed
        $shift->status = 'closed';
        $shift->closed_at = now();
        $shift->save();

        if ($targetStatus === 'closed') {
            return $shift->fresh();
        }

        // closed → approved
        $shift->status = 'approved';
        $shift->save();

        if ($targetStatus === 'approved') {
            return $shift->fresh();
        }

        // approved → paid
        $shift->status = 'paid';
        $shift->save();

        return $shift->fresh();
    }
}
