<?php

namespace Tests\Feature\Payout;

use App\Models\Biker;
use App\Models\Restaurant;
use App\Models\Shift;
use App\Models\ShiftBiker;
use App\Services\PayoutService;
use App\Services\RevenueService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BR-03 Integration Tests — Schema + Service + Model end-to-end
 *
 * Verifies that:
 * - Migrations create the correct tables with correct column types (AC-01 → AC-04)
 * - Models have correct fillable and casts (AC-22 → AC-26)
 * - Services work correctly when fed data from the database
 * - Full flow: create entities → snapshot rates → calculate payout
 *
 * @see docs/plans/BR-03-payout-formula.md — AC-01 through AC-04, AC-22 through AC-26
 */
class PayoutIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private PayoutService $payoutService;

    private RevenueService $revenueService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->payoutService = new PayoutService;
        $this->revenueService = new RevenueService;
    }

    // ========================================================================
    // AC-01: Migrations run without errors
    // ========================================================================

    /**
     * AC-01: php artisan migrate creates restaurants, bikers, shifts, shift_bikers.
     * If RefreshDatabase works, the tables exist.
     */
    public function test_migrations_create_all_required_tables(): void
    {
        // Verify tables exist by inserting and querying
        $restaurant = Restaurant::create([
            'name' => 'Test Restaurant',
            'rate_per_trip' => '15.00',
        ]);
        $this->assertDatabaseHas('restaurants', ['id' => $restaurant->id]);

        $biker = Biker::create([
            'name' => 'Test Biker',
            'phone' => '11999999999',
            'rate_per_trip' => '10.00',
            'base_fee' => '25.00',
        ]);
        $this->assertDatabaseHas('bikers', ['id' => $biker->id]);

        $shift = Shift::create([
            'restaurant_id' => $restaurant->id,
            'workflow_type' => 'live_tick',
            'status' => 'open',
            'restaurant_rate' => '15.00',
            'started_at' => now(),
        ]);
        $this->assertDatabaseHas('shifts', ['id' => $shift->id]);

        $shiftBiker = ShiftBiker::create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 0,
            'biker_rate' => $biker->rate_per_trip,
            'base_fee' => $biker->base_fee,
        ]);
        $this->assertDatabaseHas('shift_bikers', ['id' => $shiftBiker->id]);
    }

    // ========================================================================
    // AC-02: Bikers table has correct financial columns
    // ========================================================================

    /**
     * AC-02: bikers.rate_per_trip and bikers.base_fee are DECIMAL(12,2) default '0.00'.
     */
    public function test_biker_model_stores_decimal_rates(): void
    {
        $biker = Biker::create([
            'name' => 'Decimal Biker',
            'phone' => '11111111111',
            'rate_per_trip' => '12.50',
            'base_fee' => '30.75',
        ]);

        $fresh = Biker::find($biker->id);
        $this->assertEquals('12.50', $fresh->rate_per_trip,
            'AC-02: rate_per_trip must store as decimal string');
        $this->assertEquals('30.75', $fresh->base_fee,
            'AC-02: base_fee must store as decimal string');
    }

    /**
     * AC-02: Default values when rate_per_trip and base_fee are not specified.
     */
    public function test_biker_model_defaults_rates_to_zero(): void
    {
        $biker = Biker::create([
            'name' => 'Default Biker',
            'phone' => '22222222222',
        ]);

        $fresh = Biker::find($biker->id);
        $this->assertEquals('0.00', $fresh->rate_per_trip,
            'AC-02: rate_per_trip must default to 0.00');
        $this->assertEquals('0.00', $fresh->base_fee,
            'AC-02: base_fee must default to 0.00');
    }

    // ========================================================================
    // AC-03: shift_bikers has correct columns
    // ========================================================================

    /**
     * AC-03: shift_bikers has trips_count as UNSIGNED INT default 0,
     *        biker_rate and base_fee as DECIMAL(12,2).
     */
    public function test_shift_biker_stores_formula_inputs(): void
    {
        $restaurant = Restaurant::create(['name' => 'R1', 'rate_per_trip' => '15.00']);
        $biker = Biker::create([
            'name' => 'B1',
            'phone' => '33333333333',
            'rate_per_trip' => '10.00',
            'base_fee' => '25.00',
        ]);
        $shift = Shift::create([
            'restaurant_id' => $restaurant->id,
            'workflow_type' => 'live_tick',
            'status' => 'open',
            'restaurant_rate' => '15.00',
            'started_at' => now(),
        ]);

        $shiftBiker = ShiftBiker::create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 5,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);

        $fresh = ShiftBiker::find($shiftBiker->id);
        $this->assertEquals(5, $fresh->trips_count,
            'AC-03: trips_count must be stored as integer');
        $this->assertEquals('10.00', $fresh->biker_rate,
            'AC-03: biker_rate must be stored as decimal string');
        $this->assertEquals('25.00', $fresh->base_fee,
            'AC-03: base_fee must be stored as decimal string');
    }

    /**
     * AC-03: trips_count defaults to 0.
     */
    public function test_shift_biker_trips_count_defaults_to_zero(): void
    {
        $restaurant = Restaurant::create(['name' => 'R2', 'rate_per_trip' => '15.00']);
        $biker = Biker::create([
            'name' => 'B2',
            'phone' => '44444444444',
        ]);
        $shift = Shift::create([
            'restaurant_id' => $restaurant->id,
            'workflow_type' => 'manual_entry',
            'status' => 'open',
            'restaurant_rate' => '15.00',
            'started_at' => now(),
        ]);

        $shiftBiker = ShiftBiker::create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);

        $fresh = ShiftBiker::find($shiftBiker->id);
        $this->assertEquals(0, $fresh->trips_count,
            'AC-03: trips_count must default to 0');
    }

    // ========================================================================
    // AC-04: Unique index on (shift_id, biker_id)
    // ========================================================================

    /**
     * AC-04: The same biker cannot be assigned to the same shift twice.
     */
    public function test_shift_biker_unique_constraint_prevents_duplicate_assignment(): void
    {
        $restaurant = Restaurant::create(['name' => 'R3', 'rate_per_trip' => '15.00']);
        $biker = Biker::create([
            'name' => 'B3',
            'phone' => '55555555555',
        ]);
        $shift = Shift::create([
            'restaurant_id' => $restaurant->id,
            'workflow_type' => 'live_tick',
            'status' => 'open',
            'restaurant_rate' => '15.00',
            'started_at' => now(),
        ]);

        ShiftBiker::create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);

        $this->expectException(QueryException::class);

        // Attempting duplicate (shift_id, biker_id) must fail
        ShiftBiker::create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);
    }

    // ========================================================================
    // AC-22: Biker model fillable
    // ========================================================================

    /**
     * AC-22: Biker model $fillable contains name, phone, rate_per_trip, base_fee, active.
     */
    public function test_biker_model_has_required_fillable_fields(): void
    {
        $biker = Biker::create([
            'name' => 'Fillable Biker',
            'phone' => '66666666666',
            'rate_per_trip' => '15.00',
            'base_fee' => '30.00',
            'active' => true,
        ]);

        $fresh = Biker::find($biker->id);
        $this->assertEquals('Fillable Biker', $fresh->name);
        $this->assertEquals('66666666666', $fresh->phone);
        $this->assertEquals('15.00', $fresh->rate_per_trip);
        $this->assertEquals('30.00', $fresh->base_fee);
        $this->assertTrue((bool) $fresh->active);
    }

    // ========================================================================
    // AC-23: Biker model casts rate_per_trip and base_fee as decimal:2
    // ========================================================================

    /**
     * AC-23: Biker model must cast rate_per_trip and base_fee as decimal:2.
     */
    public function test_biker_model_casts_financial_fields_as_decimal(): void
    {
        $biker = Biker::create([
            'name' => 'Cast Biker',
            'phone' => '77777777777',
            'rate_per_trip' => '10.00',
            'base_fee' => '25.00',
        ]);

        $fresh = Biker::find($biker->id);
        // Verify the casts produce string values (decimal:2 cast returns strings)
        $this->assertIsString($fresh->rate_per_trip,
            'AC-23: rate_per_trip must be cast to string via decimal:2');
        $this->assertIsString($fresh->base_fee,
            'AC-23: base_fee must be cast to string via decimal:2');
    }

    // ========================================================================
    // AC-24: ShiftBiker model fillable
    // ========================================================================

    /**
     * AC-24: ShiftBiker model $fillable contains shift_id, biker_id, trips_count, biker_rate, base_fee.
     */
    public function test_shift_biker_model_has_required_fillable_fields(): void
    {
        $restaurant = Restaurant::create(['name' => 'R4', 'rate_per_trip' => '15.00']);
        $biker = Biker::create([
            'name' => 'B4',
            'phone' => '88888888888',
            'rate_per_trip' => '12.50',
            'base_fee' => '30.00',
        ]);
        $shift = Shift::create([
            'restaurant_id' => $restaurant->id,
            'workflow_type' => 'live_tick',
            'status' => 'open',
            'restaurant_rate' => '15.00',
            'started_at' => now(),
        ]);

        $shiftBiker = ShiftBiker::create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 7,
            'biker_rate' => '12.50',
            'base_fee' => '30.00',
        ]);

        $fresh = ShiftBiker::find($shiftBiker->id);
        $this->assertEquals($shift->id, $fresh->shift_id);
        $this->assertEquals($biker->id, $fresh->biker_id);
        $this->assertEquals(7, $fresh->trips_count);
        $this->assertEquals('12.50', $fresh->biker_rate);
        $this->assertEquals('30.00', $fresh->base_fee);
    }

    // ========================================================================
    // AC-25: ShiftBiker model casts biker_rate and base_fee as decimal:2
    // ========================================================================

    /**
     * AC-25: ShiftBiker model must cast biker_rate and base_fee as decimal:2.
     */
    public function test_shift_biker_model_casts_financial_fields_as_decimal(): void
    {
        $restaurant = Restaurant::create(['name' => 'R5', 'rate_per_trip' => '15.00']);
        $biker = Biker::create([
            'name' => 'B5',
            'phone' => '99999999999',
        ]);
        $shift = Shift::create([
            'restaurant_id' => $restaurant->id,
            'workflow_type' => 'manual_entry',
            'status' => 'open',
            'restaurant_rate' => '15.00',
            'started_at' => now(),
        ]);

        $shiftBiker = ShiftBiker::create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);

        $fresh = ShiftBiker::find($shiftBiker->id);
        $this->assertIsString($fresh->biker_rate,
            'AC-25: biker_rate must be cast to string via decimal:2');
        $this->assertIsString($fresh->base_fee,
            'AC-25: base_fee must be cast to string via decimal:2');
    }

    // ========================================================================
    // AC-26: ShiftBiker belongsTo Shift and Biker
    // ========================================================================

    /**
     * AC-26: ShiftBiker model has belongsTo(Shift::class) relationship.
     */
    public function test_shift_biker_belongs_to_shift(): void
    {
        $restaurant = Restaurant::create(['name' => 'R6', 'rate_per_trip' => '15.00']);
        $biker = Biker::create(['name' => 'B6', 'phone' => '12121212121']);
        $shift = Shift::create([
            'restaurant_id' => $restaurant->id,
            'workflow_type' => 'live_tick',
            'status' => 'open',
            'restaurant_rate' => '15.00',
            'started_at' => now(),
        ]);
        $shiftBiker = ShiftBiker::create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);

        $this->assertInstanceOf(Shift::class, $shiftBiker->shift);
        $this->assertEquals($shift->id, $shiftBiker->shift->id);
    }

    /**
     * AC-26: ShiftBiker model has belongsTo(Biker::class) relationship.
     */
    public function test_shift_biker_belongs_to_biker(): void
    {
        $restaurant = Restaurant::create(['name' => 'R7', 'rate_per_trip' => '15.00']);
        $biker = Biker::create(['name' => 'B7', 'phone' => '13131313131']);
        $shift = Shift::create([
            'restaurant_id' => $restaurant->id,
            'workflow_type' => 'live_tick',
            'status' => 'open',
            'restaurant_rate' => '15.00',
            'started_at' => now(),
        ]);
        $shiftBiker = ShiftBiker::create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);

        $this->assertInstanceOf(Biker::class, $shiftBiker->biker);
        $this->assertEquals($biker->id, $shiftBiker->biker->id);
    }

    // ========================================================================
    // Integration: Full flow — create → snapshot → calculate payout
    // ========================================================================

    /**
     * Full integration: Create entities, snapshot rates into shift_bikers,
     * feed those values to PayoutService, verify the result.
     */
    public function test_full_payout_flow_from_database_to_service(): void
    {
        // Setup
        $restaurant = Restaurant::create([
            'name' => 'Integration Restaurant',
            'rate_per_trip' => '15.00',
        ]);
        $biker = Biker::create([
            'name' => 'Integration Biker',
            'phone' => '14141414141',
            'rate_per_trip' => '10.00',
            'base_fee' => '25.00',
        ]);
        $shift = Shift::create([
            'restaurant_id' => $restaurant->id,
            'workflow_type' => 'live_tick',
            'status' => 'open',
            'restaurant_rate' => '15.00',
            'started_at' => now(),
        ]);

        // Snapshot rates at assignment time
        $shiftBiker = ShiftBiker::create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 5,
            'biker_rate' => $biker->rate_per_trip,
            'base_fee' => $biker->base_fee,
        ]);

        // Reload from DB
        $shiftBiker = ShiftBiker::find($shiftBiker->id);

        // Calculate payout using snapshotted values
        $payout = $this->payoutService->calculate(
            baseFee: $shiftBiker->base_fee,
            bikerRate: $shiftBiker->biker_rate,
            tripsCount: $shiftBiker->trips_count,
        );

        // BR-03: 25.00 + (10.00 × 5) = 75.00
        $this->assertEquals('75.00', $payout,
            'Full flow payout must equal 75.00, got: '.$payout);

        // Calculate revenue
        $revenue = $this->revenueService->calculate(
            restaurantRate: $shift->restaurant_rate,
            tripsCount: $shiftBiker->trips_count,
            payout: $payout,
        );

        // Revenue: (15.00 × 5) - 75.00 = 75.00 - 75.00 = 0.00
        $this->assertEquals('0.00', $revenue,
            'Full flow revenue must equal 0.00 (break-even), got: '.$revenue);
    }

    /**
     * Integration: Zero trips → zero payout via database values.
     */
    public function test_full_payout_flow_zero_trips_via_database(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Zero Trip Restaurant',
            'rate_per_trip' => '15.00',
        ]);
        $biker = Biker::create([
            'name' => 'Zero Trip Biker',
            'phone' => '15151515151',
            'rate_per_trip' => '10.00',
            'base_fee' => '25.00',
        ]);
        $shift = Shift::create([
            'restaurant_id' => $restaurant->id,
            'workflow_type' => 'manual_entry',
            'status' => 'open',
            'restaurant_rate' => '15.00',
            'started_at' => now(),
        ]);

        $shiftBiker = ShiftBiker::create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 0,
            'biker_rate' => $biker->rate_per_trip,
            'base_fee' => $biker->base_fee,
        ]);

        $shiftBiker = ShiftBiker::find($shiftBiker->id);

        $payout = $this->payoutService->calculate(
            baseFee: $shiftBiker->base_fee,
            bikerRate: $shiftBiker->biker_rate,
            tripsCount: $shiftBiker->trips_count,
        );

        // BR-03: Zero trips → zero payout, even with non-zero base_fee
        $this->assertEquals('0.00', $payout,
            'Zero trips via DB must yield payout of 0.00, got: '.$payout);

        $revenue = $this->revenueService->calculate(
            restaurantRate: $shift->restaurant_rate,
            tripsCount: $shiftBiker->trips_count,
            payout: $payout,
        );

        $this->assertEquals('0.00', $revenue,
            'Zero trips via DB must yield revenue of 0.00, got: '.$revenue);
    }

    /**
     * Integration: Rate snapshot isolation — changing biker rate after
     * assignment does not affect the snapshotted shift_biker values.
     */
    public function test_rate_snapshot_isolation(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Snapshot Restaurant',
            'rate_per_trip' => '15.00',
        ]);
        $biker = Biker::create([
            'name' => 'Snapshot Biker',
            'phone' => '16161616161',
            'rate_per_trip' => '10.00',
            'base_fee' => '25.00',
        ]);
        $shift = Shift::create([
            'restaurant_id' => $restaurant->id,
            'workflow_type' => 'live_tick',
            'status' => 'open',
            'restaurant_rate' => '15.00',
            'started_at' => now(),
        ]);

        // Snapshot at original rate
        $shiftBiker = ShiftBiker::create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 5,
            'biker_rate' => $biker->rate_per_trip,  // '10.00'
            'base_fee' => $biker->base_fee,          // '25.00'
        ]);

        // Now change the biker's rate
        $biker->update(['rate_per_trip' => '20.00', 'base_fee' => '50.00']);

        // The shift_biker snapshot must be unchanged
        $shiftBiker = ShiftBiker::find($shiftBiker->id);
        $this->assertEquals('10.00', $shiftBiker->biker_rate,
            'Snapshotted biker_rate must not change when biker rate changes');
        $this->assertEquals('25.00', $shiftBiker->base_fee,
            'Snapshotted base_fee must not change when biker fee changes');

        // Payout must use the original snapshot, not the new rate
        $payout = $this->payoutService->calculate(
            baseFee: $shiftBiker->base_fee,
            bikerRate: $shiftBiker->biker_rate,
            tripsCount: $shiftBiker->trips_count,
        );

        $this->assertEquals('75.00', $payout,
            'Payout must use snapshotted rate (10.00), not updated rate (20.00)');
    }
}
