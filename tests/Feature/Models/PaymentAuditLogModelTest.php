<?php

namespace Tests\Feature\Models;

use App\Enums\PaymentAuditAction;
use App\Models\Biker;
use App\Models\Payment;
use App\Models\PaymentAuditLog;
use App\Models\Restaurant;
use App\Models\Shift;
use App\Models\ShiftBiker;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PaymentAuditLog Model Tests
 *
 * AC-08: payment_audit_logs table schema (transaction_ref UNIQUE, action, payload JSON, indexes).
 * AC-15: PaymentAuditLog has belongsTo(Payment::class) relationship.
 * BR-06: Unique transaction_ref prevents double-billing.
 */
class PaymentAuditLogModelTest extends TestCase
{
    use RefreshDatabase;

    private Payment $payment;

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

        $shiftBiker = ShiftBiker::create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 5,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);

        $this->payment = Payment::create([
            'shift_biker_id' => $shiftBiker->id,
            'amount' => '75.00',
            'status' => 'pending',
        ]);
    }

    // ========================================================================
    // AC-08: payment_audit_logs schema
    // ========================================================================

    /**
     * AC-08: transaction_ref is VARCHAR(255) and stored correctly.
     */
    public function test_audit_log_stores_transaction_ref(): void
    {
        $log = PaymentAuditLog::create([
            'payment_id' => $this->payment->id,
            'action' => 'create',
            'transaction_ref' => 'unique-tx-ref-001',
        ]);

        $this->assertEquals('unique-tx-ref-001', $log->transaction_ref,
            'AC-08: transaction_ref must be stored correctly');
    }

    /**
     * AC-08: action is VARCHAR(20) and stored correctly.
     */
    public function test_audit_log_stores_action(): void
    {
        $log = PaymentAuditLog::create([
            'payment_id' => $this->payment->id,
            'action' => 'create',
            'transaction_ref' => 'tx-action-test',
        ]);

        $this->assertEquals(PaymentAuditAction::Create, $log->action,
            'AC-08: action must be stored correctly');
    }

    /**
     * AC-08: payload is JSON and nullable.
     */
    public function test_audit_log_stores_json_payload(): void
    {
        $payload = [
            'bank_response' => 'approved',
            'code' => 200,
            'data' => ['id' => 'abc-123'],
        ];

        $log = PaymentAuditLog::create([
            'payment_id' => $this->payment->id,
            'action' => 'succeed',
            'transaction_ref' => 'tx-payload-test',
            'payload' => $payload,
        ]);

        $fresh = PaymentAuditLog::find($log->id);
        $this->assertEquals($payload, $fresh->payload,
            'AC-08: JSON payload must be stored and retrieved correctly');
    }

    /**
     * AC-08: payload is nullable.
     */
    public function test_audit_log_payload_is_nullable(): void
    {
        $log = PaymentAuditLog::create([
            'payment_id' => $this->payment->id,
            'action' => 'create',
            'transaction_ref' => 'tx-null-payload',
        ]);

        $this->assertNull($log->payload,
            'AC-08: payload must be nullable');
    }

    /**
     * AC-08: error_message is nullable text.
     */
    public function test_audit_log_error_message_is_nullable(): void
    {
        $log = PaymentAuditLog::create([
            'payment_id' => $this->payment->id,
            'action' => 'fail',
            'transaction_ref' => 'tx-err-test',
        ]);

        $this->assertNull($log->error_message,
            'AC-08: error_message must be nullable');
    }

    /**
     * AC-08: error_message stores text.
     */
    public function test_audit_log_stores_error_message(): void
    {
        $log = PaymentAuditLog::create([
            'payment_id' => $this->payment->id,
            'action' => 'fail',
            'transaction_ref' => 'tx-err-msg',
            'error_message' => 'Bank rejected: invalid PIX key',
        ]);

        $this->assertEquals('Bank rejected: invalid PIX key', $log->error_message,
            'AC-08: error_message must be stored correctly');
    }

    // ========================================================================
    // BR-06: transaction_ref is UNIQUE (prevents double-billing)
    // ========================================================================

    /**
     * BR-06: Duplicate transaction_ref must be rejected by the database.
     * This prevents double-billing on retries.
     */
    public function test_duplicate_transaction_ref_is_rejected(): void
    {
        PaymentAuditLog::create([
            'payment_id' => $this->payment->id,
            'action' => 'attempt',
            'transaction_ref' => 'duplicate-tx-ref',
        ]);

        $this->expectException(QueryException::class);

        PaymentAuditLog::create([
            'payment_id' => $this->payment->id,
            'action' => 'retry',
            'transaction_ref' => 'duplicate-tx-ref',
        ]);
    }

    /**
     * BR-06: Different transaction_refs for the same payment are allowed (retries).
     */
    public function test_different_transaction_refs_for_same_payment_allowed(): void
    {
        PaymentAuditLog::create([
            'payment_id' => $this->payment->id,
            'action' => 'attempt',
            'transaction_ref' => 'tx-ref-001',
        ]);

        PaymentAuditLog::create([
            'payment_id' => $this->payment->id,
            'action' => 'retry',
            'transaction_ref' => 'tx-ref-002',
        ]);

        $this->assertEquals(2, PaymentAuditLog::where('payment_id', $this->payment->id)->count(),
            'BR-06: Multiple audit entries with unique refs must be allowed');
    }

    // ========================================================================
    // AC-15: PaymentAuditLog belongsTo Payment
    // ========================================================================

    /**
     * AC-15: PaymentAuditLog has a payment() relationship returning BelongsTo.
     */
    public function test_audit_log_belongs_to_payment(): void
    {
        $log = PaymentAuditLog::create([
            'payment_id' => $this->payment->id,
            'action' => 'create',
            'transaction_ref' => 'tx-belongs-test',
        ]);

        $this->assertInstanceOf(Payment::class, $log->payment,
            'AC-15: PaymentAuditLog::payment() must return a Payment instance');
        $this->assertEquals($this->payment->id, $log->payment->id,
            'AC-15: Audit log must belong to the correct payment');
    }

    // ========================================================================
    // AC-16/17: Casts and fillable
    // ========================================================================

    /**
     * AC-16: PaymentAuditLog casts action as PaymentAuditAction enum.
     */
    public function test_audit_log_casts_action_as_enum(): void
    {
        $log = PaymentAuditLog::create([
            'payment_id' => $this->payment->id,
            'action' => 'create',
            'transaction_ref' => 'tx-cast-test',
        ]);

        $fresh = PaymentAuditLog::find($log->id);
        $this->assertInstanceOf(PaymentAuditAction::class, $fresh->action,
            'AC-16: action must be cast to PaymentAuditAction enum');
        $this->assertEquals(PaymentAuditAction::Create, $fresh->action);
    }

    /**
     * AC-16: PaymentAuditLog casts payload as array.
     */
    public function test_audit_log_casts_payload_as_array(): void
    {
        $log = PaymentAuditLog::create([
            'payment_id' => $this->payment->id,
            'action' => 'create',
            'transaction_ref' => 'tx-payload-cast',
            'payload' => ['key' => 'value'],
        ]);

        $fresh = PaymentAuditLog::find($log->id);
        $this->assertIsArray($fresh->payload,
            'AC-16: payload must be cast to array');
    }

    /**
     * AC-17: PaymentAuditLog has explicit $fillable.
     */
    public function test_audit_log_has_fillable_array(): void
    {
        $model = new PaymentAuditLog;
        $fillable = $model->getFillable();

        $this->assertContains('payment_id', $fillable);
        $this->assertContains('action', $fillable);
        $this->assertContains('transaction_ref', $fillable);
        $this->assertContains('payload', $fillable);
        $this->assertContains('error_message', $fillable);

        $this->assertNotEmpty($fillable,
            'AC-17: PaymentAuditLog must use explicit $fillable');
    }
}
