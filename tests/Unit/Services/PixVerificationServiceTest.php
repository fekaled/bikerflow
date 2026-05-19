<?php

namespace Tests\Unit\Services;

use App\Contracts\PixGatewayInterface;
use App\Enums\PaymentAuditAction;
use App\Enums\UserRole;
use App\Models\Biker;
use App\Models\PaymentAuditLog;
use App\Models\PixKey;
use App\Models\User;
use App\Services\PixVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Unit Tests for PixVerificationService — Phase 4A
 *
 * Tests the BR-02 verification orchestrator:
 * - Verify: gateway call, pix_key update, audit log (AC-4A-11 through AC-4A-18)
 * - Unverify: pix_key reset, audit log (AC-4A-19 through AC-4A-21)
 * - Audit trail integrity (AC-4A-42 through AC-4A-44)
 * - Edge cases: concurrent verify, missing biker, multiple keys
 *
 * Business Rules: BR-02 (PIX Verification)
 *
 * @see docs/plans/phase-4a-pix-gateway-key-verification.md
 */
class PixVerificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private PixVerificationService $service;

    private Biker $biker;

    private User $admin;

    private PixKey $unverifiedKey;

    protected function setUp(): void
    {
        parent::setUp();

        // Resolve the real service from the container — MockPixGateway is bound
        $this->service = $this->app->make(PixVerificationService::class);

        $this->admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $this->biker = Biker::factory()->create([
            'name' => 'João da Silva',
            'phone' => '11999999999',
            'rate_per_trip' => '10.00',
            'base_fee' => '25.00',
        ]);

        $this->unverifiedKey = PixKey::factory()->create([
            'biker_id' => $this->biker->id,
            'key_type' => 'cpf',
            'key_value' => '12345678901',
            'is_verified' => false,
            'verified_at' => null,
            'account_holder_name' => null,
        ]);
    }

    // ========================================================================
    // AC-4A-11: verify() calls gateway.verifyKey() with key type and value
    // ========================================================================

    public function test_verify_calls_gateway_with_correct_key_type_and_value(): void
    {
        $result = $this->service->verify($this->unverifiedKey, $this->admin);

        // The mock gateway populates account_holder_name only if called correctly
        $this->assertEquals(
            'MOCK HOLDER for 12345678901',
            $result->account_holder_name,
            'verify() must call gateway.verifyKey() with the key type and value (AC-4A-11)',
        );
    }

    // ========================================================================
    // AC-4A-12: On success: sets is_verified, verified_at, account_holder_name
    // ========================================================================

    public function test_verify_on_success_sets_is_verified_true(): void
    {
        $result = $this->service->verify($this->unverifiedKey, $this->admin);

        $this->assertTrue(
            $result->is_verified,
            'verify() must set is_verified=true on success (AC-4A-12)',
        );
        $this->assertTrue(
            $result->fresh()->is_verified,
            'is_verified must be persisted in database (AC-4A-12)',
        );
    }

    public function test_verify_on_success_sets_verified_at(): void
    {
        $before = now();
        $result = $this->service->verify($this->unverifiedKey, $this->admin);
        $after = now();

        $this->assertNotNull(
            $result->verified_at,
            'verify() must set verified_at on success (AC-4A-12)',
        );
        $this->assertTrue(
            $result->verified_at->between($before, $after),
            'verified_at must be set to current timestamp (AC-4A-12)',
        );
    }

    public function test_verify_on_success_sets_account_holder_name(): void
    {
        $result = $this->service->verify($this->unverifiedKey, $this->admin);

        $this->assertEquals(
            'MOCK HOLDER for 12345678901',
            $result->account_holder_name,
            'verify() must set account_holder_name from gateway response (AC-4A-12)',
        );
    }

    // ========================================================================
    // AC-4A-13: On success: writes PaymentAuditLog
    // ========================================================================

    public function test_verify_on_success_writes_audit_log(): void
    {
        $this->service->verify($this->unverifiedKey, $this->admin);

        $this->assertDatabaseCount('payment_audit_logs', 1);

        $log = PaymentAuditLog::first();
        $this->assertEquals(
            PaymentAuditAction::VerifyPix,
            $log->action,
            'Audit log action must be VerifyPix (AC-4A-13)',
        );
    }

    public function test_verify_on_success_audit_log_has_correct_transaction_ref(): void
    {
        $this->service->verify($this->unverifiedKey, $this->admin);

        $log = PaymentAuditLog::first();
        $this->assertStringStartsWith(
            'pix-verify-ok-'.$this->unverifiedKey->id.'-',
            $log->transaction_ref,
            'Audit log transaction_ref must start with "pix-verify-ok-{id}-" (AC-4A-13)',
        );
    }

    public function test_verify_on_success_audit_log_contains_payload(): void
    {
        $this->service->verify($this->unverifiedKey, $this->admin);

        $log = PaymentAuditLog::first();
        $payload = $log->payload;

        $this->assertEquals($this->unverifiedKey->id, $payload['pix_key_id'], 'Payload must contain pix_key_id (AC-4A-13, AC-4A-44)');
        $this->assertEquals($this->biker->id, $payload['biker_id'], 'Payload must contain biker_id (AC-4A-13, AC-4A-44)');
        $this->assertEquals('cpf', $payload['key_type'], 'Payload must contain key_type (AC-4A-13, AC-4A-44)');
        $this->assertEquals('12345678901', $payload['key_value'], 'Payload must contain key_value (AC-4A-13, AC-4A-44)');
        $this->assertEquals('MOCK HOLDER for 12345678901', $payload['account_holder_name'], 'Payload must contain account_holder_name (AC-4A-13)');
        $this->assertEquals($this->admin->id, $payload['verified_by'], 'Payload must contain verified_by admin id (AC-4A-13)');
    }

    // ========================================================================
    // AC-4A-42: Audit log has unique transaction_ref (UUID-based)
    // ========================================================================

    public function test_verify_audit_log_transaction_ref_contains_uuid(): void
    {
        $this->service->verify($this->unverifiedKey, $this->admin);

        $log = PaymentAuditLog::first();
        // UUID v4 is 36 chars with dashes
        $parts = explode('-', $log->transaction_ref);
        $uuidPart = collect($parts)->skip(4)->join('-'); // after "pix-verify-ok-{id}-"
        $this->assertTrue(
            Str::isUuid($uuidPart),
            'transaction_ref must contain a UUID for uniqueness (AC-4A-42)',
        );
    }

    // ========================================================================
    // AC-4A-43: payment_id is null for PIX verification events
    // ========================================================================

    public function test_verify_audit_log_payment_id_is_null(): void
    {
        $this->service->verify($this->unverifiedKey, $this->admin);

        $log = PaymentAuditLog::first();
        $this->assertNull(
            $log->payment_id,
            'payment_id must be null for PIX verification events (AC-4A-43)',
        );
    }

    // ========================================================================
    // AC-4A-14: On gateway failure: throws RuntimeException, does NOT modify pixKey
    // ========================================================================

    public function test_verify_on_gateway_failure_throws_runtime_exception(): void
    {
        $failingKey = PixKey::factory()->create([
            'biker_id' => $this->biker->id,
            'key_type' => 'cpf',
            'key_value' => 'FAIL_NOT_FOUND',
            'is_verified' => false,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PIX verification failed');

        $this->service->verify($failingKey, $this->admin);
    }

    public function test_verify_on_gateway_failure_does_not_modify_pix_key(): void
    {
        $failingKey = PixKey::factory()->create([
            'biker_id' => $this->biker->id,
            'key_type' => 'cpf',
            'key_value' => 'FAIL_NOT_FOUND',
            'is_verified' => false,
        ]);

        try {
            $this->service->verify($failingKey, $this->admin);
        } catch (\RuntimeException $e) {
            // Expected
        }

        $fresh = $failingKey->fresh();
        $this->assertFalse(
            $fresh->is_verified,
            'is_verified must remain false after gateway failure (AC-4A-14)',
        );
        $this->assertNull(
            $fresh->verified_at,
            'verified_at must remain null after gateway failure (AC-4A-14)',
        );
        $this->assertNull(
            $fresh->account_holder_name,
            'account_holder_name must remain null after gateway failure (AC-4A-14)',
        );
    }

    // ========================================================================
    // AC-4A-15: On gateway failure: writes audit log with error details
    // ========================================================================

    public function test_verify_on_gateway_failure_writes_audit_log(): void
    {
        $failingKey = PixKey::factory()->create([
            'biker_id' => $this->biker->id,
            'key_type' => 'cpf',
            'key_value' => 'FAIL_NOT_FOUND',
            'is_verified' => false,
        ]);

        try {
            $this->service->verify($failingKey, $this->admin);
        } catch (\RuntimeException $e) {
            // Expected
        }

        $log = PaymentAuditLog::first();
        $this->assertNotNull($log, 'Audit log must be written even on gateway failure (AC-4A-15)');
        $this->assertEquals(
            PaymentAuditAction::VerifyPix,
            $log->action,
            'Failure audit log action must be VerifyPix (AC-4A-15)',
        );
        $this->assertNotNull(
            $log->error_message,
            'Failure audit log must have error_message set (AC-4A-15)',
        );
        $this->assertStringStartsWith(
            'pix-verify-fail-'.$failingKey->id.'-',
            $log->transaction_ref,
            'Failure audit log transaction_ref must start with "pix-verify-fail-{id}-" (AC-4A-15)',
        );
    }

    public function test_verify_on_gateway_failure_audit_log_contains_error_payload(): void
    {
        $failingKey = PixKey::factory()->create([
            'biker_id' => $this->biker->id,
            'key_type' => 'cpf',
            'key_value' => 'FAIL_NOT_FOUND',
            'is_verified' => false,
        ]);

        try {
            $this->service->verify($failingKey, $this->admin);
        } catch (\RuntimeException $e) {
            // Expected
        }

        $log = PaymentAuditLog::first();
        $payload = $log->payload;

        $this->assertEquals($failingKey->id, $payload['pix_key_id'], 'Failure payload must contain pix_key_id (AC-4A-15)');
        $this->assertEquals($this->biker->id, $payload['biker_id'], 'Failure payload must contain biker_id (AC-4A-15)');
        $this->assertEquals('KEY_NOT_FOUND', $payload['error_code'], 'Failure payload must contain error_code (AC-4A-15)');
    }

    // ========================================================================
    // AC-4A-16: On gateway exception: does NOT modify pixKey, does NOT write audit log
    // ========================================================================

    public function test_verify_on_gateway_exception_does_not_modify_pix_key(): void
    {
        $errorKey = PixKey::factory()->create([
            'biker_id' => $this->biker->id,
            'key_type' => 'cpf',
            'key_value' => 'ERROR_TIMEOUT',
            'is_verified' => false,
        ]);

        try {
            $this->service->verify($errorKey, $this->admin);
        } catch (\RuntimeException $e) {
            // Expected — gateway threw RuntimeException
        }

        $fresh = $errorKey->fresh();
        $this->assertFalse(
            $fresh->is_verified,
            'is_verified must remain false when gateway throws exception (AC-4A-16)',
        );
        $this->assertNull(
            $fresh->verified_at,
            'verified_at must remain null when gateway throws exception (AC-4A-16)',
        );
        $this->assertNull(
            $fresh->account_holder_name,
            'account_holder_name must remain null when gateway throws exception (AC-4A-16)',
        );
    }

    public function test_verify_on_gateway_exception_does_not_write_audit_log(): void
    {
        $errorKey = PixKey::factory()->create([
            'biker_id' => $this->biker->id,
            'key_type' => 'cpf',
            'key_value' => 'ERROR_TIMEOUT',
            'is_verified' => false,
        ]);

        try {
            $this->service->verify($errorKey, $this->admin);
        } catch (\RuntimeException $e) {
            // Expected
        }

        $this->assertDatabaseCount(
            'payment_audit_logs',
            0,
        );
    }

    // ========================================================================
    // AC-4A-17: Throws RuntimeException if pixKey.is_verified is already true
    // ========================================================================

    public function test_verify_throws_if_already_verified(): void
    {
        $verifiedKey = PixKey::factory()->create([
            'biker_id' => $this->biker->id,
            'key_type' => 'cpf',
            'key_value' => '98765432100',
            'is_verified' => true,
            'verified_at' => now(),
            'account_holder_name' => 'Existing Holder',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already verified');

        $this->service->verify($verifiedKey, $this->admin);
    }

    public function test_verify_already_verified_does_not_change_key(): void
    {
        $verifiedKey = PixKey::factory()->create([
            'biker_id' => $this->biker->id,
            'key_type' => 'cpf',
            'key_value' => '98765432100',
            'is_verified' => true,
            'verified_at' => now(),
            'account_holder_name' => 'Existing Holder',
        ]);

        try {
            $this->service->verify($verifiedKey, $this->admin);
        } catch (\RuntimeException $e) {
            // Expected
        }

        $fresh = $verifiedKey->fresh();
        $this->assertEquals('Existing Holder', $fresh->account_holder_name, 'Already verified key must not be modified');
    }

    // ========================================================================
    // AC-4A-18: Throws RuntimeException if biker does not exist
    // ========================================================================

    public function test_verify_throws_if_biker_does_not_exist(): void
    {
        // Use a mock/stub gateway that throws before any DB insert would happen.
        // This simulates the edge case where pix_key.biker_id references a deleted biker.
        $stubGateway = $this->createMock(PixGatewayInterface::class);
        $stubGateway->method('verifyKey')
            ->willThrowException(new \RuntimeException('Gateway connection timeout'));

        $service = new PixVerificationService($stubGateway);

        // Create a biker then remove it so pix_key has orphan biker_id
        $biker = Biker::factory()->create();
        $orphanPixKey = PixKey::factory()->create([
            'biker_id' => $biker->id,
            'key_type' => 'cpf',
            'key_value' => 'ERROR_TIMEOUT',
            'is_verified' => false,
        ]);

        // Force delete the biker to simulate FK orphan (not blocked by FK constraint)
        $biker->forceDelete();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Biker not found');

        $service->verify($orphanPixKey, $this->admin);
    }

    // ========================================================================
    // AC-4A-19: unverify() sets is_verified=false, verified_at=null, account_holder_name=null
    // ========================================================================

    public function test_unverify_sets_is_verified_false(): void
    {
        $verifiedKey = PixKey::factory()->create([
            'biker_id' => $this->biker->id,
            'key_type' => 'cpf',
            'key_value' => '98765432100',
            'is_verified' => true,
            'verified_at' => now(),
            'account_holder_name' => 'Verified Holder',
        ]);

        $result = $this->service->unverify($verifiedKey, $this->admin);

        $this->assertFalse(
            $result->is_verified,
            'unverify() must set is_verified=false (AC-4A-19)',
        );
        $this->assertFalse(
            $result->fresh()->is_verified,
            'is_verified=false must be persisted (AC-4A-19)',
        );
    }

    public function test_unverify_clears_verified_at(): void
    {
        $verifiedKey = PixKey::factory()->create([
            'biker_id' => $this->biker->id,
            'key_type' => 'cpf',
            'key_value' => '98765432100',
            'is_verified' => true,
            'verified_at' => now(),
            'account_holder_name' => 'Verified Holder',
        ]);

        $result = $this->service->unverify($verifiedKey, $this->admin);

        $this->assertNull(
            $result->verified_at,
            'unverify() must set verified_at=null (AC-4A-19)',
        );
    }

    public function test_unverify_clears_account_holder_name(): void
    {
        $verifiedKey = PixKey::factory()->create([
            'biker_id' => $this->biker->id,
            'key_type' => 'cpf',
            'key_value' => '98765432100',
            'is_verified' => true,
            'verified_at' => now(),
            'account_holder_name' => 'Verified Holder',
        ]);

        $result = $this->service->unverify($verifiedKey, $this->admin);

        $this->assertNull(
            $result->account_holder_name,
            'unverify() must set account_holder_name=null (AC-4A-19)',
        );
    }

    // ========================================================================
    // AC-4A-20: Unverify writes PaymentAuditLog
    // ========================================================================

    public function test_unverify_writes_audit_log(): void
    {
        $verifiedKey = PixKey::factory()->create([
            'biker_id' => $this->biker->id,
            'key_type' => 'cpf',
            'key_value' => '98765432100',
            'is_verified' => true,
            'verified_at' => now(),
            'account_holder_name' => 'Verified Holder',
        ]);

        $this->service->unverify($verifiedKey, $this->admin);

        $log = PaymentAuditLog::first();
        $this->assertNotNull($log, 'unverify() must write audit log (AC-4A-20)');
        $this->assertEquals(
            PaymentAuditAction::VerifyPix,
            $log->action,
            'Unverify audit log must use VerifyPix action (AC-4A-20)',
        );
        $this->assertStringStartsWith(
            'pix-unverify-'.$verifiedKey->id.'-',
            $log->transaction_ref,
            'Unverify audit log transaction_ref must start with "pix-unverify-{id}-" (AC-4A-20)',
        );
    }

    public function test_unverify_audit_log_contains_unverified_by(): void
    {
        $verifiedKey = PixKey::factory()->create([
            'biker_id' => $this->biker->id,
            'key_type' => 'cpf',
            'key_value' => '98765432100',
            'is_verified' => true,
            'verified_at' => now(),
            'account_holder_name' => 'Verified Holder',
        ]);

        $this->service->unverify($verifiedKey, $this->admin);

        $log = PaymentAuditLog::first();
        $payload = $log->payload;

        $this->assertEquals($this->admin->id, $payload['unverified_by'], 'Payload must contain unverified_by admin id (AC-4A-20)');
        $this->assertEquals($verifiedKey->id, $payload['pix_key_id'], 'Payload must contain pix_key_id (AC-4A-20, AC-4A-44)');
        $this->assertEquals($this->biker->id, $payload['biker_id'], 'Payload must contain biker_id (AC-4A-20, AC-4A-44)');
        $this->assertEquals('cpf', $payload['key_type'], 'Payload must contain key_type (AC-4A-20, AC-4A-44)');
        $this->assertEquals('98765432100', $payload['key_value'], 'Payload must contain key_value (AC-4A-20, AC-4A-44)');
    }

    public function test_unverify_audit_log_payment_id_is_null(): void
    {
        $verifiedKey = PixKey::factory()->create([
            'biker_id' => $this->biker->id,
            'key_type' => 'cpf',
            'key_value' => '98765432100',
            'is_verified' => true,
            'verified_at' => now(),
            'account_holder_name' => 'Verified Holder',
        ]);

        $this->service->unverify($verifiedKey, $this->admin);

        $log = PaymentAuditLog::first();
        $this->assertNull(
            $log->payment_id,
            'payment_id must be null for unverify events (AC-4A-43)',
        );
    }

    // ========================================================================
    // AC-4A-21: Throws RuntimeException if pixKey.is_verified is already false
    // ========================================================================

    public function test_unverify_throws_if_not_verified(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not verified');

        $this->service->unverify($this->unverifiedKey, $this->admin);
    }

    public function test_unverify_not_verified_does_not_modify_key(): void
    {
        try {
            $this->service->unverify($this->unverifiedKey, $this->admin);
        } catch (\RuntimeException $e) {
            // Expected
        }

        $fresh = $this->unverifiedKey->fresh();
        $this->assertFalse($fresh->is_verified, 'Unverified key must not be modified');
        $this->assertNull($fresh->verified_at);
        $this->assertNull($fresh->account_holder_name);
    }

    // ========================================================================
    // Edge Case #5: Multiple PIX keys per biker are independent
    // ========================================================================

    public function test_verifying_one_key_does_not_affect_other_keys(): void
    {
        $key1 = PixKey::factory()->create([
            'biker_id' => $this->biker->id,
            'key_type' => 'cpf',
            'key_value' => '11122233344',
            'is_verified' => false,
        ]);

        $key2 = PixKey::factory()->create([
            'biker_id' => $this->biker->id,
            'key_type' => 'email',
            'key_value' => 'biker@example.com',
            'is_verified' => false,
        ]);

        $this->service->verify($key1, $this->admin);

        $fresh1 = $key1->fresh();
        $fresh2 = $key2->fresh();

        $this->assertTrue($fresh1->is_verified, 'Key 1 should be verified');
        $this->assertFalse($fresh2->is_verified, 'Key 2 should remain unverified — keys are independent (Edge Case #5)');
    }

    public function test_unverifying_one_key_does_not_affect_other_verified_key(): void
    {
        $key1 = PixKey::factory()->create([
            'biker_id' => $this->biker->id,
            'key_type' => 'cpf',
            'key_value' => '11122233344',
            'is_verified' => true,
            'verified_at' => now(),
            'account_holder_name' => 'Holder 1',
        ]);

        $key2 = PixKey::factory()->create([
            'biker_id' => $this->biker->id,
            'key_type' => 'email',
            'key_value' => 'biker@example.com',
            'is_verified' => true,
            'verified_at' => now(),
            'account_holder_name' => 'Holder 2',
        ]);

        $this->service->unverify($key1, $this->admin);

        $fresh1 = $key1->fresh();
        $fresh2 = $key2->fresh();

        $this->assertFalse($fresh1->is_verified, 'Key 1 should be unverified');
        $this->assertTrue($fresh2->is_verified, 'Key 2 should remain verified — keys are independent (Edge Case #5)');
    }

    // ========================================================================
    // Edge Case #6: Concurrent verification — second attempt blocked
    // ========================================================================

    public function test_concurrent_verify_second_attempt_throws(): void
    {
        // First verify succeeds
        $this->service->verify($this->unverifiedKey, $this->admin);

        // Second verify throws — idempotency guard (Edge Case #6)
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already verified');

        $this->service->verify($this->unverifiedKey->fresh(), $this->admin);
    }

    // ========================================================================
    // Edge Case #9: Empty key_value guard
    // ========================================================================

    public function test_verify_throws_for_empty_key_value(): void
    {
        $emptyKey = PixKey::factory()->create([
            'biker_id' => $this->biker->id,
            'key_type' => 'cpf',
            'key_value' => '',
            'is_verified' => false,
        ]);

        $this->expectException(\RuntimeException::class);

        $this->service->verify($emptyKey, $this->admin);
    }

    // ========================================================================
    // AC-4A-46: hasVerifiedPixKey() reflects verification state changes
    // ========================================================================

    public function test_biker_has_verified_pix_key_returns_false_before_verify(): void
    {
        $this->assertFalse(
            $this->biker->fresh()->hasVerifiedPixKey(),
            'Biker with no verified keys should return false (AC-4A-46)',
        );
    }

    public function test_biker_has_verified_pix_key_returns_true_after_verify(): void
    {
        $this->service->verify($this->unverifiedKey, $this->admin);

        $this->assertTrue(
            $this->biker->fresh()->hasVerifiedPixKey(),
            'Biker with one verified key should return true (AC-4A-46)',
        );
    }

    public function test_biker_has_verified_pix_key_returns_false_after_unverify_only_key(): void
    {
        $this->service->verify($this->unverifiedKey, $this->admin);
        $this->assertTrue($this->biker->fresh()->hasVerifiedPixKey());

        $this->service->unverify($this->unverifiedKey->fresh(), $this->admin);

        $this->assertFalse(
            $this->biker->fresh()->hasVerifiedPixKey(),
            'Unverifying the only verified key should make hasVerifiedPixKey() return false (AC-4A-46, Edge Case #5)',
        );
    }

    public function test_biker_has_verified_pix_key_still_true_after_unverify_one_of_two(): void
    {
        $key1 = PixKey::factory()->create([
            'biker_id' => $this->biker->id,
            'key_type' => 'cpf',
            'key_value' => '11122233344',
            'is_verified' => true,
            'verified_at' => now(),
            'account_holder_name' => 'Holder 1',
        ]);

        $key2 = PixKey::factory()->create([
            'biker_id' => $this->biker->id,
            'key_type' => 'email',
            'key_value' => 'biker@example.com',
            'is_verified' => true,
            'verified_at' => now(),
            'account_holder_name' => 'Holder 2',
        ]);

        $this->service->unverify($key1, $this->admin);

        $this->assertTrue(
            $this->biker->fresh()->hasVerifiedPixKey(),
            'Biker should still have a verified key after unverifying only one (AC-4A-46, Edge Case #5)',
        );
    }

    // ========================================================================
    // Audit uniqueness across multiple operations
    // ========================================================================

    public function test_verify_and_unverify_produce_unique_transaction_refs(): void
    {
        $this->service->verify($this->unverifiedKey, $this->admin);
        $this->service->unverify($this->unverifiedKey->fresh(), $this->admin);

        $logs = PaymentAuditLog::orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertNotEquals(
            $logs[0]->transaction_ref,
            $logs[1]->transaction_ref,
            'Each audit entry must have a unique transaction_ref (AC-4A-42)',
        );
    }
}
