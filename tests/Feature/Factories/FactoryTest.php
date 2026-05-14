<?php

namespace Tests\Feature\Factories;

use App\Enums\PaymentStatus;
use App\Enums\ShiftStatus;
use App\Models\Biker;
use App\Models\Payment;
use App\Models\PaymentAuditLog;
use App\Models\PixKey;
use App\Models\Restaurant;
use App\Models\Shift;
use App\Models\ShiftBiker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Factory Validation Tests
 *
 * AC-39: Restaurant factory produces valid records with financial format.
 * AC-40: Biker factory produces valid records with explicit financial strings.
 * AC-41: All 7 factories produce valid records that pass database constraints.
 */
class FactoryTest extends TestCase
{
    use RefreshDatabase;

    // ========================================================================
    // AC-39: RestaurantFactory
    // ========================================================================

    /**
     * AC-39: Restaurant::factory()->create() produces a valid restaurant.
     */
    public function test_restaurant_factory_creates_valid_record(): void
    {
        $restaurant = Restaurant::factory()->create();

        $this->assertNotNull($restaurant->id,
            'AC-39: Restaurant factory must create a persisted record');
        $this->assertNotEmpty($restaurant->name,
            'AC-39: Restaurant name must not be empty');
        $this->assertNotNull($restaurant->rate_per_trip,
            'AC-39: rate_per_trip must be set');
    }

    /**
     * AC-39: Restaurant factory rate_per_trip matches 2-decimal-place format.
     */
    public function test_restaurant_factory_rate_per_trip_is_two_decimal_string(): void
    {
        $restaurant = Restaurant::factory()->create();

        $this->assertMatchesRegularExpression(
            '/^\d+\.\d{2}$/',
            $restaurant->rate_per_trip,
            'AC-39: rate_per_trip must match pattern XX.XX with exactly 2 decimal places'
        );
    }

    /**
     * AC-39: Restaurant factory creates with explicit rate override.
     */
    public function test_restaurant_factory_accepts_explicit_rate_override(): void
    {
        $restaurant = Restaurant::factory()->create([
            'rate_per_trip' => '20.00',
        ]);

        $this->assertEquals('20.00', $restaurant->rate_per_trip,
            'AC-39: Factory must accept explicit rate_per_trip override');
    }

    /**
     * AC-39: Restaurant factory active defaults to true.
     */
    public function test_restaurant_factory_active_defaults_to_true(): void
    {
        $restaurant = Restaurant::factory()->create();

        $this->assertTrue($restaurant->active,
            'AC-39: Restaurant factory must default active to true');
    }

    // ========================================================================
    // AC-40: BikerFactory
    // ========================================================================

    /**
     * AC-40: Biker::factory()->create() produces a valid biker.
     */
    public function test_biker_factory_creates_valid_record(): void
    {
        $biker = Biker::factory()->create();

        $this->assertNotNull($biker->id,
            'AC-40: Biker factory must create a persisted record');
        $this->assertNotEmpty($biker->name,
            'AC-40: Biker name must not be empty');
        $this->assertNotEmpty($biker->phone,
            'AC-40: Biker phone must not be empty');
    }

    /**
     * AC-40: Biker factory rate_per_trip and base_fee are explicit strings (not random floats).
     */
    public function test_biker_factory_financial_fields_are_strings(): void
    {
        $biker = Biker::factory()->create();

        $this->assertIsString($biker->rate_per_trip,
            'AC-40: rate_per_trip must be a string, not a random float');
        $this->assertIsString($biker->base_fee,
            'AC-40: base_fee must be a string, not a random float');
    }

    /**
     * AC-40: Biker factory financial fields match 2-decimal-place format.
     */
    public function test_biker_factory_financial_fields_have_two_decimals(): void
    {
        $biker = Biker::factory()->create();

        $this->assertMatchesRegularExpression(
            '/^\d+\.\d{2}$/',
            $biker->rate_per_trip,
            'AC-40: rate_per_trip must match pattern XX.XX'
        );
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d{2}$/',
            $biker->base_fee,
            'AC-40: base_fee must match pattern XX.XX'
        );
    }

    /**
     * AC-40: Biker factory creates with explicit financial overrides.
     */
    public function test_biker_factory_accepts_explicit_financial_overrides(): void
    {
        $biker = Biker::factory()->create([
            'rate_per_trip' => '15.50',
            'base_fee' => '30.00',
        ]);

        $this->assertEquals('15.50', $biker->rate_per_trip);
        $this->assertEquals('30.00', $biker->base_fee);
    }

    /**
     * AC-40: Biker factory phone is unique across multiple creates.
     */
    public function test_biker_factory_generates_unique_phones(): void
    {
        $biker1 = Biker::factory()->create();
        $biker2 = Biker::factory()->create();

        $this->assertNotEquals($biker1->phone, $biker2->phone,
            'AC-40: Biker factory must generate unique phone numbers');
    }

    /**
     * AC-40: Biker factory active defaults to true.
     */
    public function test_biker_factory_active_defaults_to_true(): void
    {
        $biker = Biker::factory()->create();

        $this->assertTrue($biker->active,
            'AC-40: Biker factory must default active to true');
    }

    // ========================================================================
    // AC-41: PixKeyFactory
    // ========================================================================

    /**
     * AC-41: PixKey::factory()->create() produces a valid PIX key record.
     */
    public function test_pix_key_factory_creates_valid_record(): void
    {
        $pixKey = PixKey::factory()->create();

        $this->assertNotNull($pixKey->id,
            'AC-41: PixKey factory must create a persisted record');
        $this->assertNotEmpty($pixKey->key_type);
        $this->assertNotEmpty($pixKey->key_value);
        $this->assertNotNull($pixKey->biker_id);
    }

    /**
     * AC-41: PixKey factory creates associated biker.
     */
    public function test_pix_key_factory_creates_associated_biker(): void
    {
        $pixKey = PixKey::factory()->create();

        $this->assertInstanceOf(Biker::class, $pixKey->biker,
            'AC-41: PixKey factory must create or associate a biker');
    }

    // ========================================================================
    // AC-41: ShiftFactory
    // ========================================================================

    /**
     * AC-41: Shift::factory()->create() produces a valid shift record.
     */
    public function test_shift_factory_creates_valid_record(): void
    {
        $shift = Shift::factory()->create();

        $this->assertNotNull($shift->id,
            'AC-41: Shift factory must create a persisted record');
        $this->assertNotEmpty($shift->workflow_type);
        $this->assertNotEmpty($shift->status);
        $this->assertNotNull($shift->restaurant_id);
    }

    /**
     * AC-41: Shift factory defaults to draft status.
     */
    public function test_shift_factory_defaults_to_draft(): void
    {
        $shift = Shift::factory()->create();

        $this->assertEquals(ShiftStatus::Draft, $shift->status,
            'AC-41: Shift factory must default to draft status');
    }

    /**
     * AC-41: Shift factory started_at is null in draft state.
     */
    public function test_shift_factory_started_at_null_in_draft(): void
    {
        $shift = Shift::factory()->create();

        $this->assertNull($shift->started_at,
            'AC-41: Shift factory must have null started_at in draft');
    }

    /**
     * AC-41: Shift factory creates associated restaurant.
     */
    public function test_shift_factory_creates_associated_restaurant(): void
    {
        $shift = Shift::factory()->create();

        $this->assertInstanceOf(Restaurant::class, $shift->restaurant,
            'AC-41: Shift factory must create or associate a restaurant');
    }

    /**
     * AC-41: Shift factory has a "started" named state.
     */
    public function test_shift_factory_started_state_creates_open_shift(): void
    {
        $shift = Shift::factory()->started()->create();

        $this->assertEquals(ShiftStatus::Open, $shift->status,
            'AC-41: Shift factory started() state must set status to "open"');
        $this->assertNotNull($shift->started_at,
            'AC-41: Shift factory started() state must set started_at');
    }

    // ========================================================================
    // AC-41: ShiftBikerFactory
    // ========================================================================

    /**
     * AC-41: ShiftBiker::factory()->create() produces a valid record.
     */
    public function test_shift_biker_factory_creates_valid_record(): void
    {
        $shiftBiker = ShiftBiker::factory()->create();

        $this->assertNotNull($shiftBiker->id,
            'AC-41: ShiftBiker factory must create a persisted record');
        $this->assertNotNull($shiftBiker->shift_id);
        $this->assertNotNull($shiftBiker->biker_id);
    }

    /**
     * AC-41: ShiftBiker factory creates associated shift and biker.
     */
    public function test_shift_biker_factory_creates_associated_models(): void
    {
        $shiftBiker = ShiftBiker::factory()->create();

        $this->assertInstanceOf(Shift::class, $shiftBiker->shift,
            'AC-41: ShiftBiker factory must create or associate a shift');
        $this->assertInstanceOf(Biker::class, $shiftBiker->biker,
            'AC-41: ShiftBiker factory must create or associate a biker');
    }

    /**
     * AC-41: ShiftBiker factory defaults trips_count to 0.
     */
    public function test_shift_biker_factory_defaults_trips_to_zero(): void
    {
        $shiftBiker = ShiftBiker::factory()->create();

        $this->assertEquals(0, $shiftBiker->trips_count,
            'AC-41: ShiftBiker factory must default trips_count to 0');
    }

    /**
     * AC-41: ShiftBiker factory financial fields are strings.
     */
    public function test_shift_biker_factory_financial_fields_are_strings(): void
    {
        $shiftBiker = ShiftBiker::factory()->create();

        $this->assertMatchesRegularExpression(
            '/^\d+\.\d{2}$/',
            $shiftBiker->biker_rate,
            'AC-41: biker_rate must match pattern XX.XX'
        );
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d{2}$/',
            $shiftBiker->base_fee,
            'AC-41: base_fee must match pattern XX.XX'
        );
    }

    // ========================================================================
    // AC-41: PaymentFactory
    // ========================================================================

    /**
     * AC-41: Payment::factory()->create() produces a valid payment record.
     */
    public function test_payment_factory_creates_valid_record(): void
    {
        $payment = Payment::factory()->create();

        $this->assertNotNull($payment->id,
            'AC-41: Payment factory must create a persisted record');
        $this->assertNotNull($payment->shift_biker_id);
        $this->assertEquals(PaymentStatus::Pending, $payment->status,
            'AC-41: Payment factory must default to pending status');
    }

    /**
     * AC-41: Payment factory creates associated ShiftBiker.
     */
    public function test_payment_factory_creates_associated_shift_biker(): void
    {
        $payment = Payment::factory()->create();

        $this->assertInstanceOf(ShiftBiker::class, $payment->shiftBiker,
            'AC-41: Payment factory must create or associate a ShiftBiker');
    }

    /**
     * AC-41: Payment factory amount defaults to '0.00'.
     */
    public function test_payment_factory_amount_defaults_to_zero(): void
    {
        $payment = Payment::factory()->create();

        $this->assertEquals('0.00', $payment->amount,
            'AC-41: Payment factory must default amount to 0.00');
    }

    // ========================================================================
    // AC-41: PaymentAuditLogFactory
    // ========================================================================

    /**
     * AC-41: PaymentAuditLog::factory()->create() produces a valid audit log record.
     */
    public function test_payment_audit_log_factory_creates_valid_record(): void
    {
        $log = PaymentAuditLog::factory()->create();

        $this->assertNotNull($log->id,
            'AC-41: PaymentAuditLog factory must create a persisted record');
        $this->assertNotNull($log->payment_id);
        $this->assertNotEmpty($log->action);
        $this->assertNotEmpty($log->transaction_ref);
    }

    /**
     * AC-41: PaymentAuditLog factory creates associated Payment.
     */
    public function test_payment_audit_log_factory_creates_associated_payment(): void
    {
        $log = PaymentAuditLog::factory()->create();

        $this->assertInstanceOf(Payment::class, $log->payment,
            'AC-41: PaymentAuditLog factory must create or associate a Payment');
    }

    /**
     * AC-41: PaymentAuditLog factory generates unique transaction_refs.
     */
    public function test_payment_audit_log_factory_generates_unique_refs(): void
    {
        $log1 = PaymentAuditLog::factory()->create();
        $log2 = PaymentAuditLog::factory()->create();

        $this->assertNotEquals($log1->transaction_ref, $log2->transaction_ref,
            'AC-41: PaymentAuditLog factory must generate unique transaction refs');
    }

    // ========================================================================
    // AC-41: Full factory chain creates all related records
    // ========================================================================

    /**
     * AC-41: Creating a PaymentAuditLog via factory creates the full chain:
     * PaymentAuditLog → Payment → ShiftBiker → Shift → Restaurant + Biker.
     */
    public function test_full_factory_chain_creates_all_related_records(): void
    {
        $log = PaymentAuditLog::factory()->create();

        // Walk the full chain
        $this->assertInstanceOf(Payment::class, $log->payment);
        $this->assertInstanceOf(ShiftBiker::class, $log->payment->shiftBiker);
        $this->assertInstanceOf(Shift::class, $log->payment->shiftBiker->shift);
        $this->assertInstanceOf(Biker::class, $log->payment->shiftBiker->biker);
        $this->assertInstanceOf(Restaurant::class, $log->payment->shiftBiker->shift->restaurant);

        // Verify all records are persisted
        $this->assertDatabaseCount('restaurants', 1);
        $this->assertDatabaseCount('bikers', 1);
        $this->assertDatabaseCount('shifts', 1);
        $this->assertDatabaseCount('shift_bikers', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_audit_logs', 1);
    }

    /**
     * AC-41: Multiple factories can coexist — creating multiple records.
     */
    public function test_multiple_factory_creates_coexist(): void
    {
        Restaurant::factory()->count(3)->create();
        Biker::factory()->count(5)->create();
        Shift::factory()->count(2)->create();
        ShiftBiker::factory()->count(4)->create();
        Payment::factory()->count(4)->create();
        PaymentAuditLog::factory()->count(8)->create();

        $this->assertDatabaseCount('restaurants', 21);
        $this->assertDatabaseCount('bikers', 21);
        $this->assertDatabaseCount('shifts', 18);
        $this->assertDatabaseCount('shift_bikers', 16);
        $this->assertDatabaseCount('payments', 12);
        $this->assertDatabaseCount('payment_audit_logs', 8);
    }
}
