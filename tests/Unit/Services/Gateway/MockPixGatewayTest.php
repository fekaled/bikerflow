<?php

namespace Tests\Unit\Services\Gateway;

use App\Contracts\PixGatewayInterface;
use App\Enums\PixKeyType;
use App\Services\Gateway\MockPixGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit Tests for MockPixGateway — Phase 4A
 *
 * Tests the mock PIX gateway implementation:
 * - Interface contract compliance (AC-4A-01 through AC-4A-05)
 * - verifyKey() success, failure, and error scenarios (AC-4A-06 through AC-4A-08)
 * - initiatePayment() stub response (AC-4A-09)
 * - checkPaymentStatus() stub response (AC-4A-10)
 *
 * Business Rules: BR-02 (PIX Verification)
 *
 * @see docs/plans/phase-4a-pix-gateway-key-verification.md
 */
class MockPixGatewayTest extends TestCase
{
    use RefreshDatabase;

    private MockPixGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new MockPixGateway;
    }

    // ========================================================================
    // AC-4A-01: PixGatewayInterface exists with three methods
    // ========================================================================

    public function test_mock_gateway_implements_pix_gateway_interface(): void
    {
        $this->assertInstanceOf(
            PixGatewayInterface::class,
            $this->gateway,
            'MockPixGateway must implement PixGatewayInterface (AC-4A-01, AC-4A-05)',
        );
    }

    public function test_gateway_has_verify_key_method(): void
    {
        $this->assertTrue(
            method_exists($this->gateway, 'verifyKey'),
            'PixGatewayInterface must define verifyKey() method (AC-4A-01)',
        );
    }

    public function test_gateway_has_initiate_payment_method(): void
    {
        $this->assertTrue(
            method_exists($this->gateway, 'initiatePayment'),
            'PixGatewayInterface must define initiatePayment() method (AC-4A-01)',
        );
    }

    public function test_gateway_has_check_payment_status_method(): void
    {
        $this->assertTrue(
            method_exists($this->gateway, 'checkPaymentStatus'),
            'PixGatewayInterface must define checkPaymentStatus() method (AC-4A-01)',
        );
    }

    // ========================================================================
    // AC-4A-06: verifyKey returns success for normal keys
    // ========================================================================

    public function test_verify_key_returns_success_for_normal_cpf_key(): void
    {
        $response = $this->gateway->verifyKey(PixKeyType::Cpf, '12345678901');

        $this->assertTrue(
            $response->success,
            'verifyKey() must return success=true for normal keys (AC-4A-06)',
        );
        $this->assertEquals(
            'MOCK HOLDER for 12345678901',
            $response->account_holder_name,
            'verifyKey() must return "MOCK HOLDER for {keyValue}" as account_holder_name (AC-4A-06)',
        );
        $this->assertNull(
            $response->error_code,
            'verifyKey() success must have null error_code (AC-4A-06)',
        );
        $this->assertNull(
            $response->error_message,
            'verifyKey() success must have null error_message (AC-4A-06)',
        );
    }

    public function test_verify_key_returns_success_for_email_key(): void
    {
        $response = $this->gateway->verifyKey(PixKeyType::Email, 'user@example.com');

        $this->assertTrue($response->success, 'verifyKey() must succeed for email keys (AC-4A-06)');
        $this->assertEquals(
            'MOCK HOLDER for user@example.com',
            $response->account_holder_name,
            'account_holder_name must follow "MOCK HOLDER for {keyValue}" pattern (AC-4A-06)',
        );
    }

    public function test_verify_key_returns_success_for_phone_key(): void
    {
        $response = $this->gateway->verifyKey(PixKeyType::Phone, '5511999999999');

        $this->assertTrue($response->success, 'verifyKey() must succeed for phone keys (AC-4A-06)');
        $this->assertEquals(
            'MOCK HOLDER for 5511999999999',
            $response->account_holder_name,
            'account_holder_name must follow "MOCK HOLDER for {keyValue}" pattern (AC-4A-06)',
        );
    }

    public function test_verify_key_returns_success_for_random_key(): void
    {
        $response = $this->gateway->verifyKey(PixKeyType::Random, 'abc123-def456');

        $this->assertTrue($response->success, 'verifyKey() must succeed for random keys (AC-4A-06)');
        $this->assertEquals(
            'MOCK HOLDER for abc123-def456',
            $response->account_holder_name,
            'account_holder_name must follow "MOCK HOLDER for {keyValue}" pattern (AC-4A-06)',
        );
    }

    // ========================================================================
    // AC-4A-07: verifyKey returns failure when keyValue starts with "FAIL"
    // ========================================================================

    public function test_verify_key_returns_failure_for_fail_prefix(): void
    {
        $response = $this->gateway->verifyKey(PixKeyType::Cpf, 'FAIL123456');

        $this->assertFalse(
            $response->success,
            'verifyKey() must return success=false when keyValue starts with "FAIL" (AC-4A-07)',
        );
        $this->assertEquals(
            'KEY_NOT_FOUND',
            $response->error_code,
            'verifyKey() failure must have error_code "KEY_NOT_FOUND" (AC-4A-07)',
        );
        $this->assertEquals(
            'Chave PIX não encontrada',
            $response->error_message,
            'verifyKey() failure must have Portuguese error message (AC-4A-07)',
        );
        $this->assertNull(
            $response->account_holder_name,
            'verifyKey() failure must have null account_holder_name (AC-4A-07)',
        );
    }

    public function test_verify_key_returns_failure_for_fail_email_prefix(): void
    {
        $response = $this->gateway->verifyKey(PixKeyType::Email, 'FAIL_user@test.com');

        $this->assertFalse($response->success, 'FAIL prefix must trigger failure for any key type (AC-4A-07)');
        $this->assertEquals('KEY_NOT_FOUND', $response->error_code);
    }

    // ========================================================================
    // AC-4A-08: verifyKey throws RuntimeException when keyValue starts with "ERROR"
    // ========================================================================

    public function test_verify_key_throws_exception_for_error_prefix(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Gateway connection timeout');

        $this->gateway->verifyKey(PixKeyType::Cpf, 'ERROR_TIMEOUT');
    }

    public function test_verify_key_throws_exception_for_error_phone_key(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Gateway connection timeout');

        $this->gateway->verifyKey(PixKeyType::Phone, 'ERROR551199999');
    }

    // ========================================================================
    // AC-4A-02: verifyKey returns structured VerifyKeyResponse
    // ========================================================================

    public function test_verify_key_response_has_required_properties(): void
    {
        $response = $this->gateway->verifyKey(PixKeyType::Cpf, '12345678901');

        // Verify the response object has all required properties
        $this->assertTrue(
            property_exists($response, 'success'),
            'VerifyKeyResponse must have "success" property (AC-4A-02)',
        );
        $this->assertTrue(
            property_exists($response, 'account_holder_name'),
            'VerifyKeyResponse must have "account_holder_name" property (AC-4A-02)',
        );
        $this->assertTrue(
            property_exists($response, 'error_code'),
            'VerifyKeyResponse must have "error_code" property (AC-4A-02)',
        );
        $this->assertTrue(
            property_exists($response, 'error_message'),
            'VerifyKeyResponse must have "error_message" property (AC-4A-02)',
        );
    }

    // ========================================================================
    // AC-4A-09: initiatePayment returns stub response with status "queued"
    // ========================================================================

    public function test_initiate_payment_returns_stub_queued_response(): void
    {
        $response = $this->gateway->initiatePayment(1, '12345678901', '150.00');

        $this->assertTrue(
            $response->success,
            'initiatePayment() stub must return success=true (AC-4A-09)',
        );
        $this->assertTrue(
            str_starts_with($response->transaction_id, 'mock-txn-1-'),
            'initiatePayment() stub must return transaction_id starting with "mock-txn-{paymentId}-" (AC-4A-09)',
        );
        $this->assertEquals(
            'queued',
            $response->status,
            'initiatePayment() stub must return status "queued" (AC-4A-09)',
        );
    }

    public function test_initiate_payment_returns_unique_transaction_ids(): void
    {
        $response1 = $this->gateway->initiatePayment(1, 'key1', '100.00');
        $response2 = $this->gateway->initiatePayment(2, 'key2', '200.00');

        $this->assertNotEquals(
            $response1->transaction_id,
            $response2->transaction_id,
            'initiatePayment() must return unique transaction IDs per paymentId',
        );
    }

    // ========================================================================
    // AC-4A-10: checkPaymentStatus returns stub response with status "processed"
    // ========================================================================

    public function test_check_payment_status_returns_stub_processed_response(): void
    {
        $response = $this->gateway->checkPaymentStatus('mock-txn-1');

        $this->assertTrue(
            $response->success,
            'checkPaymentStatus() stub must return success=true (AC-4A-10)',
        );
        $this->assertEquals(
            'mock-txn-1',
            $response->transaction_id,
            'checkPaymentStatus() must echo back the transaction_id (AC-4A-10)',
        );
        $this->assertEquals(
            'processed',
            $response->status,
            'checkPaymentStatus() stub must return status "processed" (AC-4A-10)',
        );
    }

    // ========================================================================
    // AC-4A-03, AC-4A-04: PaymentResponse has required properties
    // ========================================================================

    public function test_payment_response_has_required_properties(): void
    {
        $response = $this->gateway->initiatePayment(1, 'key', '100.00');

        $this->assertTrue(
            property_exists($response, 'success'),
            'PaymentResponse must have "success" property (AC-4A-03)',
        );
        $this->assertTrue(
            property_exists($response, 'transaction_id'),
            'PaymentResponse must have "transaction_id" property (AC-4A-03)',
        );
        $this->assertTrue(
            property_exists($response, 'status'),
            'PaymentResponse must have "status" property (AC-4A-03)',
        );
        $this->assertTrue(
            property_exists($response, 'error_code'),
            'PaymentResponse must have "error_code" property (AC-4A-03)',
        );
        $this->assertTrue(
            property_exists($response, 'error_message'),
            'PaymentResponse must have "error_message" property (AC-4A-03)',
        );
    }

    // ========================================================================
    // AC-4A-05: MockPixGateway is bound in the container
    // ========================================================================

    public function test_mock_gateway_bound_in_container(): void
    {
        $resolved = $this->app->make(PixGatewayInterface::class);

        $this->assertInstanceOf(
            MockPixGateway::class,
            $resolved,
            'PixGatewayInterface must resolve to MockPixGateway via container (AC-4A-05, AC-4A-23)',
        );
    }

    // ========================================================================
    // Edge Case: Gateway handles all PixKeyType values
    // ========================================================================

    public function test_verify_key_handles_all_key_types_gracefully(): void
    {
        foreach (PixKeyType::cases() as $keyType) {
            $response = $this->gateway->verifyKey($keyType, 'test-key-value');
            $this->assertTrue(
                $response->success,
                "verifyKey() must succeed for PixKeyType::{$keyType->name} (Edge Case #8: unknown key_type)",
            );
        }
    }
}
