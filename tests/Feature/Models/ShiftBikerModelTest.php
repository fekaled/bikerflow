<?php

namespace Tests\Feature\Models;

use App\Models\Biker;
use App\Models\Payment;
use App\Models\Restaurant;
use App\Models\Shift;
use App\Models\ShiftBiker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ShiftBiker Model Tests
 *
 * AC-13: ShiftBiker has belongsTo(Shift), belongsTo(Biker), hasOne(Payment) relationships.
 * AC-16: ShiftBiker model casts financial fields as decimal:2.
 * AC-17: ShiftBiker model has $fillable array.
 */
class ShiftBikerModelTest extends TestCase
{
    use RefreshDatabase;

    private ShiftBiker $shiftBiker;

    protected function setUp(): void
    {
        parent::setUp();

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

        $biker = Biker::create([
            'name' => 'Test Biker',
            'phone' => '11999999999',
            'rate_per_trip' => '10.00',
            'base_fee' => '25.00',
        ]);

        $this->shiftBiker = ShiftBiker::create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 5,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);
    }

    // ========================================================================
    // AC-13: ShiftBiker belongsTo Shift
    // ========================================================================

    /**
     * AC-13: ShiftBiker has a shift() relationship returning BelongsTo.
     */
    public function test_shift_biker_belongs_to_shift(): void
    {
        $this->assertInstanceOf(Shift::class, $this->shiftBiker->shift,
            'AC-13: ShiftBiker::shift() must return a Shift instance');
    }

    // ========================================================================
    // AC-13: ShiftBiker belongsTo Biker
    // ========================================================================

    /**
     * AC-13: ShiftBiker has a biker() relationship returning BelongsTo.
     */
    public function test_shift_biker_belongs_to_biker(): void
    {
        $this->assertInstanceOf(Biker::class, $this->shiftBiker->biker,
            'AC-13: ShiftBiker::biker() must return a Biker instance');
    }

    // ========================================================================
    // AC-13: ShiftBiker hasOne Payment
    // ========================================================================

    /**
     * AC-13: ShiftBiker has a payment() relationship returning HasOne.
     */
    public function test_shift_biker_has_one_payment(): void
    {
        // Initially no payment
        $this->assertNull($this->shiftBiker->payment,
            'AC-13: ShiftBiker::payment() must be null when no payment exists');

        // Create a payment
        Payment::create([
            'shift_biker_id' => $this->shiftBiker->id,
            'amount' => '75.00',
            'status' => 'pending',
        ]);

        $this->assertNotNull($this->shiftBiker->fresh()->payment,
            'AC-13: ShiftBiker::payment() must return the related payment');
        $this->assertEquals('75.00', $this->shiftBiker->fresh()->payment->amount,
            'AC-13: Payment amount must match');
    }

    /**
     * AC-13: ShiftBiker can only have one payment (hasOne, not hasMany).
     */
    public function test_shift_biker_has_exactly_one_payment(): void
    {
        Payment::create([
            'shift_biker_id' => $this->shiftBiker->id,
            'amount' => '75.00',
            'status' => 'pending',
        ]);

        $payment = $this->shiftBiker->fresh()->payment;
        $this->assertInstanceOf(Payment::class, $payment,
            'AC-13: payment() must return a single Payment model (hasOne)');
    }

    // ========================================================================
    // AC-16: ShiftBiker casts financial fields as decimal:2
    // ========================================================================

    /**
     * AC-16: biker_rate and base_fee are cast as decimal:2.
     */
    public function test_shift_biker_casts_financial_fields_as_decimal(): void
    {
        $fresh = ShiftBiker::find($this->shiftBiker->id);

        $this->assertIsString($fresh->biker_rate,
            'AC-16: biker_rate must be cast to string via decimal:2');
        $this->assertIsString($fresh->base_fee,
            'AC-16: base_fee must be cast to string via decimal:2');
        $this->assertEquals('10.00', $fresh->biker_rate);
        $this->assertEquals('25.00', $fresh->base_fee);
    }

    // ========================================================================
    // AC-17: ShiftBiker has $fillable array
    // ========================================================================

    /**
     * AC-17: ShiftBiker model uses explicit $fillable.
     */
    public function test_shift_biker_has_fillable_array(): void
    {
        $shiftBiker = new ShiftBiker;
        $fillable = $shiftBiker->getFillable();

        $this->assertContains('shift_id', $fillable);
        $this->assertContains('biker_id', $fillable);
        $this->assertContains('trips_count', $fillable);
        $this->assertContains('biker_rate', $fillable);
        $this->assertContains('base_fee', $fillable);

        $this->assertNotEmpty($fillable,
            'AC-17: ShiftBiker must use explicit $fillable');
    }

    // ========================================================================
    // AC-06: trips_count is stored correctly
    // ========================================================================

    /**
     * AC-06: trips_count is stored as integer.
     */
    public function test_trips_count_stored_as_integer(): void
    {
        $fresh = ShiftBiker::find($this->shiftBiker->id);
        $this->assertIsInt($fresh->trips_count,
            'AC-06: trips_count must be stored as integer');
        $this->assertEquals(5, $fresh->trips_count);
    }
}
