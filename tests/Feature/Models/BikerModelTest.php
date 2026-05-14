<?php

namespace Tests\Feature\Models;

use App\Models\Biker;
use App\Models\PixKey;
use App\Models\Restaurant;
use App\Models\Shift;
use App\Models\ShiftBiker;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Biker Model Tests
 *
 * AC-10: Biker has hasMany(PixKey::class) and hasMany(ShiftBiker::class) relationships.
 * AC-16: Biker model casts financial fields as decimal:2.
 * AC-17: Biker model has $fillable array.
 */
class BikerModelTest extends TestCase
{
    use RefreshDatabase;

    // ========================================================================
    // AC-10: Biker hasMany PixKeys
    // ========================================================================

    /**
     * AC-10: Biker model has a pixKeys() relationship returning HasMany.
     */
    public function test_biker_has_pix_keys_relationship(): void
    {
        $biker = Biker::create([
            'name' => 'Test Biker',
            'phone' => '11999999999',
            'rate_per_trip' => '10.00',
            'base_fee' => '25.00',
        ]);

        PixKey::create([
            'biker_id' => $biker->id,
            'key_type' => 'cpf',
            'key_value' => '12345678901',
        ]);

        $this->assertTrue(
            $biker->pixKeys()->exists(),
            'AC-10: Biker::pixKeys() must return related PIX keys'
        );

        $this->assertCount(1, $biker->pixKeys,
            'AC-10: Biker should have exactly 1 PIX key');
    }

    /**
     * AC-10: Biker can have multiple PIX keys of different types.
     */
    public function test_biker_can_have_multiple_pix_keys(): void
    {
        $biker = Biker::create([
            'name' => 'Multi PIX Biker',
            'phone' => '11888888888',
            'rate_per_trip' => '10.00',
            'base_fee' => '25.00',
        ]);

        PixKey::create([
            'biker_id' => $biker->id,
            'key_type' => 'cpf',
            'key_value' => '11111111111',
        ]);

        PixKey::create([
            'biker_id' => $biker->id,
            'key_type' => 'phone',
            'key_value' => '5511999999999',
        ]);

        PixKey::create([
            'biker_id' => $biker->id,
            'key_type' => 'email',
            'key_value' => 'biker@test.com',
        ]);

        $this->assertCount(3, $biker->refresh()->pixKeys,
            'AC-10: Biker should have exactly 3 PIX keys');
    }

    /**
     * AC-10: Deleting a biker cascades to delete PIX keys.
     */
    public function test_biker_deletion_cascades_to_pix_keys(): void
    {
        $biker = Biker::create([
            'name' => 'Cascade Biker',
            'phone' => '11777777777',
            'rate_per_trip' => '10.00',
            'base_fee' => '25.00',
        ]);

        PixKey::create([
            'biker_id' => $biker->id,
            'key_type' => 'cpf',
            'key_value' => '22222222222',
        ]);

        $biker->delete();

        $this->assertDatabaseEmpty('pix_keys');
    }

    // ========================================================================
    // AC-10: Biker hasMany ShiftBikers
    // ========================================================================

    /**
     * AC-10: Biker model has a shiftBikers() relationship returning HasMany.
     */
    public function test_biker_has_shift_bikers_relationship(): void
    {
        $biker = Biker::create([
            'name' => 'Test Biker',
            'phone' => '11666666666',
            'rate_per_trip' => '10.00',
            'base_fee' => '25.00',
        ]);

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

        ShiftBiker::create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);

        $this->assertTrue(
            $biker->shiftBikers()->exists(),
            'AC-10: Biker::shiftBikers() must return related shift assignments'
        );

        $this->assertCount(1, $biker->shiftBikers,
            'AC-10: Biker should have exactly 1 shift assignment');
    }

    // ========================================================================
    // AC-16: Biker model casts financial fields as decimal:2
    // ========================================================================

    /**
     * AC-16: Biker model casts rate_per_trip and base_fee as decimal:2.
     */
    public function test_biker_casts_financial_fields_as_decimal(): void
    {
        $biker = Biker::create([
            'name' => 'Cast Biker',
            'phone' => '11555555555',
            'rate_per_trip' => '10.00',
            'base_fee' => '25.00',
        ]);

        $fresh = Biker::find($biker->id);
        $this->assertIsString($fresh->rate_per_trip,
            'AC-16: rate_per_trip must be cast to string via decimal:2');
        $this->assertIsString($fresh->base_fee,
            'AC-16: base_fee must be cast to string via decimal:2');
        $this->assertEquals('10.00', $fresh->rate_per_trip);
        $this->assertEquals('25.00', $fresh->base_fee);
    }

    // ========================================================================
    // AC-17: Biker model has $fillable array
    // ========================================================================

    /**
     * AC-17: Biker model uses explicit $fillable, not $guarded = [].
     */
    public function test_biker_has_fillable_array(): void
    {
        $biker = new Biker;
        $fillable = $biker->getFillable();

        $this->assertContains('name', $fillable);
        $this->assertContains('phone', $fillable);
        $this->assertContains('rate_per_trip', $fillable);
        $this->assertContains('base_fee', $fillable);
        $this->assertContains('active', $fillable);

        $this->assertNotEmpty($fillable,
            'AC-17: Biker must use explicit $fillable');
    }

    /**
     * AC-03: Biker phone is unique.
     */
    public function test_biker_phone_is_unique(): void
    {
        Biker::create([
            'name' => 'Biker 1',
            'phone' => '11999999999',
        ]);

        $this->expectException(QueryException::class);

        Biker::create([
            'name' => 'Biker 2',
            'phone' => '11999999999',
        ]);
    }
}
