<?php

namespace Tests\Feature\Models;

use App\Models\Restaurant;
use App\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Restaurant Model Tests
 *
 * AC-09: Restaurant has hasMany(Shift::class) relationship.
 */
class RestaurantModelTest extends TestCase
{
    use RefreshDatabase;

    // ========================================================================
    // AC-09: Restaurant hasMany Shifts
    // ========================================================================

    /**
     * AC-09: Restaurant model has a shifts() relationship returning HasMany.
     */
    public function test_restaurant_has_shifts_relationship(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Test Restaurant',
            'rate_per_trip' => '15.00',
        ]);

        $shift = Shift::create([
            'restaurant_id' => $restaurant->id,
            'workflow_type' => 'live_tick',
            'status' => 'draft',
            'restaurant_rate' => '15.00',
        ]);

        $this->assertTrue(
            $restaurant->shifts()->exists(),
            'AC-09: Restaurant::shifts() must return related shifts'
        );

        $this->assertCount(1, $restaurant->shifts,
            'AC-09: Restaurant should have exactly 1 shift');

        $this->assertEquals($shift->id, $restaurant->shifts->first()->id,
            'AC-09: The related shift must match the created shift');
    }

    /**
     * AC-09: Restaurant can have multiple shifts.
     */
    public function test_restaurant_can_have_multiple_shifts(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Multi Shift Restaurant',
            'rate_per_trip' => '15.00',
        ]);

        Shift::create([
            'restaurant_id' => $restaurant->id,
            'workflow_type' => 'live_tick',
            'status' => 'draft',
            'restaurant_rate' => '15.00',
        ]);

        Shift::create([
            'restaurant_id' => $restaurant->id,
            'workflow_type' => 'manual_entry',
            'status' => 'draft',
            'restaurant_rate' => '20.00',
        ]);

        $this->assertCount(2, $restaurant->refresh()->shifts,
            'AC-09: Restaurant should have exactly 2 shifts');
    }

    /**
     * AC-09: Deleting a restaurant cascades to delete its shifts.
     */
    public function test_restaurant_deletion_cascades_to_shifts(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Cascade Restaurant',
            'rate_per_trip' => '15.00',
        ]);

        Shift::create([
            'restaurant_id' => $restaurant->id,
            'workflow_type' => 'live_tick',
            'status' => 'draft',
            'restaurant_rate' => '15.00',
        ]);

        $restaurant->delete();

        $this->assertDatabaseEmpty('shifts');
    }

    /**
     * AC-16: Restaurant model casts rate_per_trip as decimal:2.
     */
    public function test_restaurant_casts_rate_per_trip_as_decimal(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Cast Test',
            'rate_per_trip' => '12.50',
        ]);

        $fresh = Restaurant::find($restaurant->id);
        $this->assertIsString($fresh->rate_per_trip,
            'AC-16: rate_per_trip must be cast to string via decimal:2');
        $this->assertEquals('12.50', $fresh->rate_per_trip,
            'AC-16: rate_per_trip must preserve exact decimal value');
    }

    /**
     * AC-17: Restaurant model has $fillable array (not $guarded = []).
     */
    public function test_restaurant_has_fillable_array(): void
    {
        $restaurant = new Restaurant;

        $fillable = $restaurant->getFillable();

        $this->assertContains('name', $fillable,
            'AC-17: Restaurant $fillable must include "name"');
        $this->assertContains('rate_per_trip', $fillable,
            'AC-17: Restaurant $fillable must include "rate_per_trip"');
        $this->assertContains('active', $fillable,
            'AC-17: Restaurant $fillable must include "active"');

        // Verify it uses $fillable, not $guarded
        $this->assertNotEmpty($fillable,
            'AC-17: Restaurant must use explicit $fillable');
        $this->assertNotSame([], $restaurant->getGuarded(),
            'AC-17: Restaurant must not use $guarded = [] (mass assignment vulnerability)');
    }
}
