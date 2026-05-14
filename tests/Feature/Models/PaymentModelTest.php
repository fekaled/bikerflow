<?php

namespace Tests\Feature\Models;

use App\Enums\PaymentStatus;
use App\Models\Biker;
use App\Models\Payment;
use App\Models\PaymentAuditLog;
use App\Models\Restaurant;
use App\Models\Shift;
use App\Models\ShiftBiker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Payment Model Tests
 *
 * AC-07: Payments table schema (amount, status, released_by, released_at, paid_at, indexes).
 * AC-14: Payment has belongsTo(ShiftBiker) and hasMany(PaymentAuditLog) relationships.
 * AC-16: Payment model casts financial fields as decimal:2.
 * AC-17: Payment model has $fillable array.
 */
class PaymentModelTest extends TestCase
{
    use RefreshDatabase;

    private Payment $payment;

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

        $this->payment = Payment::create([
            'shift_biker_id' => $this->shiftBiker->id,
            'amount' => '75.00',
            'status' => 'pending',
        ]);
    }

    // ========================================================================
    // AC-07: Payments table schema
    // ========================================================================

    /**
     * AC-07: Payment amount is DECIMAL(12,2) default '0.00'.
     */
    public function test_payment_amount_is_decimal(): void
    {
        $payment = Payment::create([
            'shift_biker_id' => $this->shiftBiker->id,
            'amount' => '123.45',
        ]);

        $fresh = Payment::find($payment->id);
        $this->assertEquals('123.45', $fresh->amount,
            'AC-07: Payment amount must be stored as DECIMAL(12,2)');
    }

    /**
     * AC-07: Payment status defaults to 'pending'.
     */
    public function test_payment_status_defaults_to_pending(): void
    {
        $payment = Payment::create([
            'shift_biker_id' => $this->shiftBiker->id,
            'amount' => '50.00',
        ]);

        $this->assertEquals(PaymentStatus::Pending, $payment->status,
            'AC-07: Payment status must default to "pending"');
    }

    /**
     * AC-07: Payment released_by is nullable.
     */
    public function test_payment_released_by_is_nullable(): void
    {
        $this->assertNull($this->payment->released_by,
            'AC-07: released_by must be nullable');
    }

    /**
     * AC-07: Payment released_at is nullable.
     */
    public function test_payment_released_at_is_nullable(): void
    {
        $this->assertNull($this->payment->released_at,
            'AC-07: released_at must be nullable');
    }

    /**
     * AC-07: Payment paid_at is nullable.
     */
    public function test_payment_paid_at_is_nullable(): void
    {
        $this->assertNull($this->payment->paid_at,
            'AC-07: paid_at must be nullable');
    }

    // ========================================================================
    // AC-14: Payment belongsTo ShiftBiker
    // ========================================================================

    /**
     * AC-14: Payment has a shiftBiker() relationship returning BelongsTo.
     */
    public function test_payment_belongs_to_shift_biker(): void
    {
        $this->assertInstanceOf(ShiftBiker::class, $this->payment->shiftBiker,
            'AC-14: Payment::shiftBiker() must return a ShiftBiker instance');
        $this->assertEquals($this->shiftBiker->id, $this->payment->shiftBiker->id,
            'AC-14: Payment must belong to the correct ShiftBiker');
    }

    // ========================================================================
    // AC-14: Payment hasMany PaymentAuditLogs
    // ========================================================================

    /**
     * AC-14: Payment has a paymentAuditLogs() relationship returning HasMany.
     */
    public function test_payment_has_audit_logs_relationship(): void
    {
        // Initially no audit logs
        $this->assertCount(0, $this->payment->paymentAuditLogs,
            'AC-14: Payment should have 0 audit logs initially');

        // Create audit log
        PaymentAuditLog::create([
            'payment_id' => $this->payment->id,
            'action' => 'create',
            'transaction_ref' => 'tx-ref-001',
        ]);

        $this->assertCount(1, $this->payment->fresh()->paymentAuditLogs,
            'AC-14: Payment should have 1 audit log after creation');
    }

    /**
     * AC-14: Payment can have multiple audit logs (BR-06: retries).
     */
    public function test_payment_can_have_multiple_audit_logs(): void
    {
        PaymentAuditLog::create([
            'payment_id' => $this->payment->id,
            'action' => 'create',
            'transaction_ref' => 'tx-ref-001',
        ]);

        PaymentAuditLog::create([
            'payment_id' => $this->payment->id,
            'action' => 'attempt',
            'transaction_ref' => 'tx-ref-002',
        ]);

        PaymentAuditLog::create([
            'payment_id' => $this->payment->id,
            'action' => 'fail',
            'transaction_ref' => 'tx-ref-003',
        ]);

        PaymentAuditLog::create([
            'payment_id' => $this->payment->id,
            'action' => 'retry',
            'transaction_ref' => 'tx-ref-004',
        ]);

        $this->assertCount(4, $this->payment->fresh()->paymentAuditLogs,
            'AC-14: Payment should track all audit log entries');
    }

    /**
     * AC-14: Deleting a payment cascades to delete its audit logs.
     */
    public function test_payment_deletion_cascades_to_audit_logs(): void
    {
        PaymentAuditLog::create([
            'payment_id' => $this->payment->id,
            'action' => 'create',
            'transaction_ref' => 'tx-ref-cascade',
        ]);

        $this->payment->delete();

        $this->assertDatabaseEmpty('payment_audit_logs');
    }

    // ========================================================================
    // AC-16: Payment casts
    // ========================================================================

    /**
     * AC-16: Payment model casts amount as decimal:2.
     */
    public function test_payment_casts_amount_as_decimal(): void
    {
        $fresh = Payment::find($this->payment->id);
        $this->assertIsString($fresh->amount,
            'AC-16: Payment amount must be cast to string via decimal:2');
        $this->assertEquals('75.00', $fresh->amount);
    }

    /**
     * AC-16: Payment model casts status as PaymentStatus enum.
     */
    public function test_payment_casts_status_as_payment_status_enum(): void
    {
        $fresh = Payment::find($this->payment->id);
        $this->assertInstanceOf(PaymentStatus::class, $fresh->status,
            'AC-16: Payment status must be cast to PaymentStatus enum');
    }

    /**
     * AC-16: Payment model casts released_at and paid_at as datetime.
     */
    public function test_payment_casts_timestamps_as_datetime(): void
    {
        $payment = Payment::create([
            'shift_biker_id' => $this->shiftBiker->id,
            'amount' => '75.00',
            'released_at' => now(),
            'paid_at' => now(),
        ]);

        $fresh = Payment::find($payment->id);
        $this->assertInstanceOf(Carbon::class, $fresh->released_at,
            'AC-16: released_at must be cast to datetime');
        $this->assertInstanceOf(Carbon::class, $fresh->paid_at,
            'AC-16: paid_at must be cast to datetime');
    }

    // ========================================================================
    // AC-17: Payment has $fillable array
    // ========================================================================

    /**
     * AC-17: Payment model uses explicit $fillable.
     */
    public function test_payment_has_fillable_array(): void
    {
        $payment = new Payment;
        $fillable = $payment->getFillable();

        $this->assertContains('shift_biker_id', $fillable);
        $this->assertContains('amount', $fillable);
        $this->assertContains('status', $fillable);
        $this->assertContains('released_by', $fillable);
        $this->assertContains('released_at', $fillable);
        $this->assertContains('paid_at', $fillable);

        $this->assertNotEmpty($fillable,
            'AC-17: Payment must use explicit $fillable');
    }
}
