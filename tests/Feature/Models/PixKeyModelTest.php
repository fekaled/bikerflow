<?php

namespace Tests\Feature\Models;

use App\Models\Biker;
use App\Models\PixKey;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * PixKey Model Tests
 *
 * AC-04: pix_keys table schema (is_verified, verified_at, unique index).
 * AC-11: PixKey has belongsTo(Biker::class) relationship.
 * AC-16/17: PixKey model casts and fillable.
 * Edge case #12: Duplicate PIX key for same biker rejected.
 */
class PixKeyModelTest extends TestCase
{
    use RefreshDatabase;

    private Biker $biker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->biker = Biker::create([
            'name' => 'Test Biker',
            'phone' => '11999999999',
            'rate_per_trip' => '10.00',
            'base_fee' => '25.00',
        ]);
    }

    // ========================================================================
    // AC-04: pix_keys table schema
    // ========================================================================

    /**
     * AC-04: pix_keys table has is_verified boolean default false.
     */
    public function test_pix_key_is_verified_defaults_to_false(): void
    {
        $pixKey = PixKey::create([
            'biker_id' => $this->biker->id,
            'key_type' => 'cpf',
            'key_value' => '12345678901',
        ]);

        $this->assertFalse($pixKey->is_verified,
            'AC-04: is_verified must default to false');
    }

    /**
     * AC-04: pix_keys table has verified_at nullable timestamp.
     */
    public function test_pix_key_verified_at_is_nullable(): void
    {
        $pixKey = PixKey::create([
            'biker_id' => $this->biker->id,
            'key_type' => 'cpf',
            'key_value' => '12345678901',
        ]);

        $this->assertNull($pixKey->verified_at,
            'AC-04: verified_at must be nullable and NULL by default');
    }

    /**
     * AC-04: pix_keys table has account_holder_name nullable.
     */
    public function test_pix_key_account_holder_name_is_nullable(): void
    {
        $pixKey = PixKey::create([
            'biker_id' => $this->biker->id,
            'key_type' => 'cpf',
            'key_value' => '12345678901',
        ]);

        $this->assertNull($pixKey->account_holder_name,
            'AC-04: account_holder_name must be nullable');
    }

    /**
     * AC-04: pix_keys unique index on (biker_id, key_type, key_value).
     */
    public function test_duplicate_pix_key_for_same_biker_rejected(): void
    {
        PixKey::create([
            'biker_id' => $this->biker->id,
            'key_type' => 'cpf',
            'key_value' => '12345678901',
        ]);

        $this->expectException(QueryException::class);

        // Same biker, same key_type, same key_value — must be rejected
        PixKey::create([
            'biker_id' => $this->biker->id,
            'key_type' => 'cpf',
            'key_value' => '12345678901',
        ]);
    }

    /**
     * AC-04: Same key_value allowed for different bikers or different key_types.
     */
    public function test_same_key_value_different_biker_allowed(): void
    {
        $biker2 = Biker::create([
            'name' => 'Another Biker',
            'phone' => '11888888888',
        ]);

        PixKey::create([
            'biker_id' => $this->biker->id,
            'key_type' => 'cpf',
            'key_value' => '12345678901',
        ]);

        // Different biker, same key_type/value — should succeed
        $pixKey2 = PixKey::create([
            'biker_id' => $biker2->id,
            'key_type' => 'cpf',
            'key_value' => '12345678901',
        ]);

        $this->assertNotNull($pixKey2->id,
            'AC-04: Same key_value for different biker must be allowed');
    }

    /**
     * AC-04: Same biker can have different key_types with different values.
     */
    public function test_same_biker_different_key_types_allowed(): void
    {
        PixKey::create([
            'biker_id' => $this->biker->id,
            'key_type' => 'cpf',
            'key_value' => '12345678901',
        ]);

        $pixKey2 = PixKey::create([
            'biker_id' => $this->biker->id,
            'key_type' => 'phone',
            'key_value' => '5511999999999',
        ]);

        $this->assertNotNull($pixKey2->id,
            'AC-04: Same biker with different key_types must be allowed');
    }

    // ========================================================================
    // AC-11: PixKey belongsTo Biker
    // ========================================================================

    /**
     * AC-11: PixKey has a biker() relationship returning BelongsTo.
     */
    public function test_pix_key_belongs_to_biker(): void
    {
        $pixKey = PixKey::create([
            'biker_id' => $this->biker->id,
            'key_type' => 'cpf',
            'key_value' => '12345678901',
        ]);

        $this->assertInstanceOf(Biker::class, $pixKey->biker,
            'AC-11: PixKey::biker() must return a Biker instance');
        $this->assertEquals($this->biker->id, $pixKey->biker->id,
            'AC-11: PixKey must belong to the correct biker');
    }

    // ========================================================================
    // AC-16/17: Casts and fillable
    // ========================================================================

    /**
     * AC-16: PixKey casts is_verified as boolean.
     */
    public function test_pix_key_casts_is_verified_as_boolean(): void
    {
        $pixKey = PixKey::create([
            'biker_id' => $this->biker->id,
            'key_type' => 'cpf',
            'key_value' => '12345678901',
            'is_verified' => true,
        ]);

        $fresh = PixKey::find($pixKey->id);
        $this->assertIsBool($fresh->is_verified,
            'AC-16: is_verified must be cast as boolean');
        $this->assertTrue($fresh->is_verified);
    }

    /**
     * AC-16: PixKey casts verified_at as datetime.
     */
    public function test_pix_key_casts_verified_at_as_datetime(): void
    {
        $pixKey = PixKey::create([
            'biker_id' => $this->biker->id,
            'key_type' => 'cpf',
            'key_value' => '12345678901',
            'verified_at' => now(),
        ]);

        $fresh = PixKey::find($pixKey->id);
        $this->assertInstanceOf(Carbon::class, $fresh->verified_at,
            'AC-16: verified_at must be cast as datetime');
    }

    /**
     * AC-17: PixKey has explicit $fillable.
     */
    public function test_pix_key_has_fillable_array(): void
    {
        $model = new PixKey;
        $fillable = $model->getFillable();

        $this->assertContains('biker_id', $fillable);
        $this->assertContains('key_type', $fillable);
        $this->assertContains('key_value', $fillable);
        $this->assertContains('account_holder_name', $fillable);
        $this->assertContains('is_verified', $fillable);
        $this->assertContains('verified_at', $fillable);

        $this->assertNotEmpty($fillable,
            'AC-17: PixKey must use explicit $fillable');
    }

    // ========================================================================
    // BR-02 partial: verification tracking
    // ========================================================================

    /**
     * BR-02: PixKey can be marked as verified with timestamp.
     */
    public function test_pix_key_can_be_verified(): void
    {
        $pixKey = PixKey::create([
            'biker_id' => $this->biker->id,
            'key_type' => 'cpf',
            'key_value' => '12345678901',
        ]);

        $pixKey->is_verified = true;
        $pixKey->verified_at = now();
        $pixKey->account_holder_name = 'John Doe';
        $pixKey->save();

        $fresh = PixKey::find($pixKey->id);
        $this->assertTrue($fresh->is_verified,
            'BR-02: PixKey must be markable as verified');
        $this->assertNotNull($fresh->verified_at,
            'BR-02: verified_at must be set on verification');
        $this->assertEquals('John Doe', $fresh->account_holder_name,
            'BR-02: account_holder_name must be set on verification');
    }
}
