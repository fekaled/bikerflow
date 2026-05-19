<?php

namespace Tests\Unit\Services;

use App\Contracts\PaymentResponse;
use App\Services\Gateway\MockPixGateway;
use Tests\TestCase;

/**
 * Unit Tests for MockPixGateway — Extended initiatePayment() — Phase 4B
 *
 * Tests the mock gateway's initiatePayment() behavior with deterministic scenarios:
 * - Amount ends with ".01" → sync success (processed)
 * - Amount ends with ".02" → sync failure (failed)
 * - All other amounts → async queued (default)
 * - pixKey starts with "FAIL-" → forced failure
 * - pixKey starts with "ERROR" → throws RuntimeException
 * - Transaction ID format is "mock-txn-{paymentId}-{timestamp}"
 *
 * Acceptance Criteria: AC-4B-08 through AC-4B-12
 *
 * @see docs/plans/phase-4b-pix-payment-execution.md
 */
class MockPixGatewayInitiatePaymentTest extends TestCase
{
    private MockPixGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new MockPixGateway;
    }

    // ========================================================================
    // AC-4B-08: Amount ".01" → status "processed"
    // ========================================================================

    public function test_initiate_payment_with_dot_01_amount_returns_processed(): void
    {
        $response = $this->gateway->initiatePayment(123, '11999999999', '75.01');

        $this->assertTrue($response->success,
            'AC-4B-08: Success must be true for processed status');
        $this->assertEquals('processed', $response->status,
            'AC-4B-08: Status must be "processed" for amount ending in .01');
        $this->assertNotNull($response->transaction_id,
            'AC-4B-08: transaction_id must be set for processed status');
        $this->assertNull($response->error_code,
            'AC-4B-08: error_code must be null for processed status');
    }

    public function test_initiate_payment_with_dot_01_returns_consistent_transaction_id_format(): void
    {
        $response = $this->gateway->initiatePayment(456, '11999999999', '100.01');

        $this->assertStringStartsWith('mock-txn-456-', $response->transaction_id,
            'AC-4B-08: transaction_id must start with "mock-txn-{paymentId}-"');
        // Format: mock-txn-{paymentId}-{timestamp}
        $parts = explode('-', $response->transaction_id);
        $this->assertCount(4, $parts,
            'AC-4B-08: transaction_id format must be mock-txn-{paymentId}-{timestamp}');
    }

    public function test_initiate_payment_dot_01_different_payment_ids(): void
    {
        foreach ([1, 99, 999, 9999] as $paymentId) {
            $response = $this->gateway->initiatePayment($paymentId, '11999999999', '50.01');

            $this->assertEquals('processed', $response->status,
                "Payment ID {$paymentId} with .01 amount must return processed");
            $this->assertStringContainsString((string) $paymentId, $response->transaction_id,
                "Transaction ID must contain payment ID {$paymentId}");
        }
    }

    public function test_initiate_payment_dot_01_various_amounts(): void
    {
        $amounts = ['0.01', '1.01', '10.01', '100.01', '1000.01', '12345.01'];

        foreach ($amounts as $amount) {
            $response = $this->gateway->initiatePayment(100, '11999999999', $amount);

            $this->assertEquals('processed', $response->status,
                "Amount {$amount} must return processed status");
        }
    }

    // ========================================================================
    // AC-4B-09: Amount ".02" → status "failed", error_code "INSUFFICIENT_FUNDS"
    // ========================================================================

    public function test_initiate_payment_with_dot_02_amount_returns_failed(): void
    {
        $response = $this->gateway->initiatePayment(123, '11999999999', '75.02');

        $this->assertTrue($response->success,
            'AC-4B-09: Success must be true even for failed status');
        $this->assertEquals('failed', $response->status,
            'AC-4B-09: Status must be "failed" for amount ending in .02');
        $this->assertEquals('INSUFFICIENT_FUNDS', $response->error_code,
            'AC-4B-09: Error code must be INSUFFICIENT_FUNDS');
        $this->assertNotNull($response->error_message,
            'AC-4B-09: Error message must be set for failed status');
    }

    public function test_initiate_payment_with_dot_02_returns_transaction_id(): void
    {
        $response = $this->gateway->initiatePayment(456, '11999999999', '100.02');

        $this->assertNotNull($response->transaction_id,
            'AC-4B-09: transaction_id must be set even for failed status');
        $this->assertStringStartsWith('mock-txn-456-', $response->transaction_id,
            'AC-4B-09: transaction_id must use correct format');
    }

    public function test_initiate_payment_dot_02_different_payment_ids(): void
    {
        foreach ([1, 99, 999, 9999] as $paymentId) {
            $response = $this->gateway->initiatePayment($paymentId, '11999999999', '50.02');

            $this->assertEquals('failed', $response->status,
                "Payment ID {$paymentId} with .02 amount must return failed");
            $this->assertEquals('INSUFFICIENT_FUNDS', $response->error_code,
                "Payment ID {$paymentId} must have INSUFFICIENT_FUNDS error code");
        }
    }

    public function test_initiate_payment_dot_02_various_amounts(): void
    {
        $amounts = ['0.02', '1.02', '10.02', '100.02', '1000.02', '12345.02'];

        foreach ($amounts as $amount) {
            $response = $this->gateway->initiatePayment(100, '11999999999', $amount);

            $this->assertEquals('failed', $response->status,
                "Amount {$amount} must return failed status");
            $this->assertEquals('INSUFFICIENT_FUNDS', $response->error_code,
                "Amount {$amount} must have INSUFFICIENT_FUNDS error code");
        }
    }

    // ========================================================================
    // AC-4B-10: Default amount (not .01/.02) → status "queued"
    // ========================================================================

    public function test_initiate_payment_with_round_amount_returns_queued(): void
    {
        $response = $this->gateway->initiatePayment(123, '11999999999', '75.00');

        $this->assertTrue($response->success,
            'AC-4B-10: Success must be true for queued status');
        $this->assertEquals('queued', $response->status,
            'AC-4B-10: Default status must be "queued"');
        $this->assertNotNull($response->transaction_id,
            'AC-4B-10: transaction_id must be set for queued status');
    }

    public function test_initiate_payment_with_decimal_non_dot_02_returns_queued(): void
    {
        $amounts = ['75.50', '100.99', '25.05', '50.03', '1.10', '99.99'];

        foreach ($amounts as $amount) {
            $response = $this->gateway->initiatePayment(100, '11999999999', $amount);

            $this->assertEquals('queued', $response->status,
                "Amount {$amount} must return queued status (not .01 or .02)");
        }
    }

    public function test_initiate_payment_queued_returns_correct_transaction_id_format(): void
    {
        $response = $this->gateway->initiatePayment(789, '11999999999', '25.50');

        $this->assertStringStartsWith('mock-txn-789-', $response->transaction_id,
            'AC-4B-10: transaction_id format must be consistent');
    }

    public function test_initiate_payment_zero_amount_returns_queued(): void
    {
        $response = $this->gateway->initiatePayment(100, '11999999999', '0.00');

        $this->assertEquals('queued', $response->status,
            'Zero amount must return queued (not .01 or .02)');
    }

    // ========================================================================
    // AC-4B-11: pixKey starting with "FAIL-" → failure response
    // ========================================================================

    public function test_initiate_payment_with_fail_prefix_returns_failure(): void
    {
        $response = $this->gateway->initiatePayment(123, 'FAIL-11999999999', '75.00');

        $this->assertFalse($response->success,
            'AC-4B-11: Success must be false for FAIL- prefix');
        $this->assertEquals('failed', $response->status,
            'AC-4B-11: Status must be "failed" for FAIL- prefix');
        $this->assertEquals('REJECTED_BY_RECEIVER', $response->error_code,
            'AC-4B-11: Error code must be REJECTED_BY_RECEIVER');
        $this->assertNotNull($response->error_message,
            'AC-4B-11: Error message must be set');
    }

    public function test_initiate_payment_with_fail_prefix_ignores_amount(): void
    {
        // Even with .01 (which normally returns processed), FAIL- prefix should force failure
        $response = $this->gateway->initiatePayment(123, 'FAIL-11999999999', '75.01');

        $this->assertEquals('failed', $response->status,
            'AC-4B-11: FAIL- prefix must override amount-based behavior');
        $this->assertEquals('REJECTED_BY_RECEIVER', $response->error_code);
    }

    public function test_initiate_payment_fail_prefix_various_keys(): void
    {
        $keys = ['FAIL-12345678900', 'FAIL-email@test.com', 'FAIL-+5511999999999'];

        foreach ($keys as $pixKey) {
            $response = $this->gateway->initiatePayment(100, $pixKey, '50.00');

            $this->assertEquals('failed', $response->status,
                "Key {$pixKey} must return failed status");
        }
    }

    public function test_initiate_payment_fail_prefix_no_transaction_id(): void
    {
        $response = $this->gateway->initiatePayment(123, 'FAIL-11999999999', '75.00');

        // The plan says transaction_id = null for FAIL- keys
        $this->assertNull($response->transaction_id,
            'AC-4B-11: transaction_id must be null for FAIL- rejection');
    }

    // ========================================================================
    // AC-4B-12: pixKey starting with "ERROR" → throws RuntimeException
    // ========================================================================

    public function test_initiate_payment_with_error_prefix_throws_runtime_exception(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Gateway connection timeout');

        $this->gateway->initiatePayment(123, 'ERROR-11999999999', '75.00');
    }

    public function test_initiate_payment_error_prefix_ignores_amount(): void
    {
        // Even with .02 (which normally returns failed), ERROR prefix should throw
        try {
            $this->gateway->initiatePayment(123, 'ERROR-11999999999', '75.02');
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('timeout', $e->getMessage());
        }
    }

    public function test_initiate_payment_error_prefix_various_keys(): void
    {
        $keys = ['ERROR-12345678900', 'ERROR-email@test.com', 'ERROR-+5511999999999'];

        foreach ($keys as $pixKey) {
            try {
                $this->gateway->initiatePayment(100, $pixKey, '50.00');
                $this->fail("Key {$pixKey} should have thrown RuntimeException");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('timeout', $e->getMessage(),
                    "Key {$pixKey} must throw gateway timeout exception");
            }
        }
    }

    public function test_initiate_payment_gateway_exception_is_runtime(): void
    {
        // Verify the exception type is RuntimeException, not a custom exception
        try {
            $this->gateway->initiatePayment(123, 'ERROR-11999999999', '75.00');
            $this->fail('Should have thrown');
        } catch (\RuntimeException $e) {
            $this->assertInstanceOf(\RuntimeException::class, $e,
                'Gateway exceptions must be RuntimeException for catch-and-handle');
        }
    }

    // ========================================================================
    // Priority / Override Order
    // ========================================================================

    public function test_error_prefix_takes_precedence_over_fail_prefix(): void
    {
        // ERROR takes precedence (throws) over FAIL (returns failure response)
        $this->expectException(\RuntimeException::class);

        // If a key starts with both ERROR and FAIL, ERROR wins
        $this->gateway->initiatePayment(123, 'ERROR-FAIL-something', '75.00');
    }

    public function test_fail_prefix_takes_precedence_over_amount_pattern(): void
    {
        // FAIL- forces failure regardless of amount ending
        $response = $this->gateway->initiatePayment(123, 'FAIL-something', '75.01');

        $this->assertEquals('failed', $response->status,
            'FAIL- prefix must override .01 amount pattern');
    }

    public function test_error_prefix_takes_precedence_over_amount_pattern(): void
    {
        // ERROR throws regardless of amount ending
        try {
            $this->gateway->initiatePayment(123, 'ERROR-something', '75.01');
            $this->fail('ERROR should throw even with .01 amount');
        } catch (\RuntimeException $e) {
            // Expected
        }
    }

    // ========================================================================
    // PaymentResponse Structure
    // ========================================================================

    public function test_payment_response_has_all_required_fields(): void
    {
        $response = $this->gateway->initiatePayment(123, '11999999999', '75.01');

        $this->assertTrue(isset($response->success),
            'PaymentResponse must have success field');
        $this->assertTrue(isset($response->transaction_id),
            'PaymentResponse must have transaction_id field');
        $this->assertTrue(isset($response->status),
            'PaymentResponse must have status field');
    }

    public function test_payment_response_success_is_boolean(): void
    {
        $response = $this->gateway->initiatePayment(123, '11999999999', '75.01');

        $this->assertIsBool($response->success,
            'success must be boolean');
    }

    public function test_payment_response_status_is_string(): void
    {
        $response = $this->gateway->initiatePayment(123, '11999999999', '75.01');

        $this->assertIsString($response->status,
            'status must be string');
    }

    public function test_all_response_types_have_transaction_id(): void
    {
        // processed: has transaction_id
        $processed = $this->gateway->initiatePayment(1, '11999999999', '75.01');
        $this->assertNotNull($processed->transaction_id);

        // queued: has transaction_id
        $queued = $this->gateway->initiatePayment(2, '11999999999', '75.00');
        $this->assertNotNull($queued->transaction_id);

        // failed (amount .02): has transaction_id
        $failed = $this->gateway->initiatePayment(3, '11999999999', '75.02');
        $this->assertNotNull($failed->transaction_id);
    }

    public function test_failed_response_has_error_details(): void
    {
        $response = $this->gateway->initiatePayment(123, '11999999999', '75.02');

        $this->assertNotNull($response->error_code,
            'Failed response must have error_code');
        $this->assertNotNull($response->error_message,
            'Failed response must have error_message');
        $this->assertIsString($response->error_code,
            'error_code must be string');
        $this->assertIsString($response->error_message,
            'error_message must be string');
    }

    public function test_success_response_has_no_error_details(): void
    {
        $response = $this->gateway->initiatePayment(123, '11999999999', '75.01');

        $this->assertNull($response->error_code,
            'Success (processed) response must not have error_code');
        $this->assertNull($response->error_message,
            'Success (processed) response must not have error_message');
    }

    public function test_queued_response_has_no_error_details(): void
    {
        $response = $this->gateway->initiatePayment(123, '11999999999', '75.00');

        $this->assertNull($response->error_code,
            'Queued response must not have error_code');
        $this->assertNull($response->error_message,
            'Queued response must not have error_message');
    }

    // ========================================================================
    // Edge Cases
    // ========================================================================

    public function test_amount_001_takes_precedence_over_002(): void
    {
        // If somehow both .01 and .02 apply, .01 (processed) takes precedence as the success path
        // This is more of a documentation edge case — the amount only ends in one digit
        $response = $this->gateway->initiatePayment(123, '11999999999', '1.01');

        $this->assertEquals('processed', $response->status,
            '.01 takes precedence over .02');
    }

    public function test_empty_pix_key_with_dot_01_returns_processed(): void
    {
        // Empty pix key should still process based on amount
        $response = $this->gateway->initiatePayment(123, '', '75.01');

        $this->assertEquals('processed', $response->status,
            'Empty pix key with .01 amount must return processed');
    }

    public function test_null_payment_id_still_returns_response(): void
    {
        // Payment ID is passed as int, so we test with id=0
        $response = $this->gateway->initiatePayment(0, '11999999999', '75.01');

        $this->assertEquals('processed', $response->status,
            'Payment ID 0 should still return processed');
        $this->assertStringStartsWith('mock-txn-0-', $response->transaction_id);
    }

    public function test_large_payment_id_works(): void
    {
        $response = $this->gateway->initiatePayment(999999, '11999999999', '75.01');

        $this->assertEquals('processed', $response->status,
            'Large payment ID must work');
        $this->assertStringContainsString('999999', $response->transaction_id);
    }

    public function test_various_valid_pix_keys(): void
    {
        $keys = [
            '11999999999',      // Phone
            '12345678900',      // CPF
            'test@example.com', // Email
            '+5521988887777',   // Phone with +
            '12345678900123',   // CNPJ
        ];

        foreach ($keys as $pixKey) {
            $response = $this->gateway->initiatePayment(100, $pixKey, '75.00');

            // All should return queued (default) — none start with ERROR or FAIL
            $this->assertEquals('queued', $response->status,
                "Pix key {$pixKey} should return queued");
        }
    }
}
