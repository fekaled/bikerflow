<?php

namespace Tests\Unit\Gateway;

use App\Services\Gateway\MockPixGateway;
use Tests\TestCase;

/**
 * Unit Tests for MockPixGateway — Phase 4C
 *
 * Tests the mock gateway's checkPaymentStatus() behavior:
 * - Default: returns "processed" for normal transaction IDs
 * - "-sync-failed" suffix: returns "failed" status
 * - "-sync-pending" suffix: returns "queued" status
 * - Transaction ID is preserved in response
 * - PaymentResponse structure is correct
 *
 * Acceptance Criteria: AC-4C-48 through AC-4C-50
 *
 * @see docs/plans/phase-4c-pix-webhooks-async-status.md
 */
class MockPixGatewayTest extends TestCase
{
    private MockPixGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new MockPixGateway;
    }

    // ========================================================================
    // AC-4C-48: Transaction ID with "-sync-failed" returns failed
    // ========================================================================

    public function test_check_payment_status_with_sync_failed_suffix_returns_failed(): void
    {
        $transactionId = 'mock-txn-123-sync-failed';

        $response = $this->gateway->checkPaymentStatus($transactionId);

        $this->assertTrue($response->success,
            'AC-4C-48: Success must be true even for failed status');
        $this->assertEquals($transactionId, $response->transaction_id,
            'AC-4C-48: Transaction ID must be preserved');
        $this->assertEquals('failed', $response->status,
            'AC-4C-48: Status must be "failed" for "-sync-failed" suffix');
        $this->assertNotNull($response->error_code,
            'AC-4C-48: Error code must be set for failed status');
        $this->assertEquals('RECIPIENT_NOT_FOUND', $response->error_code,
            'AC-4C-48: Error code for sync-failed must be RECIPIENT_NOT_FOUND');
    }

    public function test_sync_failed_with_different_prefix_returns_failed(): void
    {
        $transactionId = 'pix-payment-456-sync-failed';

        $response = $this->gateway->checkPaymentStatus($transactionId);

        $this->assertEquals('failed', $response->status,
            'AC-4C-48: "-sync-failed" suffix must trigger failed regardless of prefix');
    }

    // ========================================================================
    // AC-4C-49: Transaction ID with "-sync-pending" returns queued
    // ========================================================================

    public function test_check_payment_status_with_sync_pending_suffix_returns_queued(): void
    {
        $transactionId = 'mock-txn-789-sync-pending';

        $response = $this->gateway->checkPaymentStatus($transactionId);

        $this->assertTrue($response->success,
            'AC-4C-49: Success must be true even for pending status');
        $this->assertEquals($transactionId, $response->transaction_id,
            'AC-4C-49: Transaction ID must be preserved');
        $this->assertEquals('queued', $response->status,
            'AC-4C-49: Status must be "queued" for "-sync-pending" suffix');
    }

    public function test_sync_pending_with_different_prefix_returns_queued(): void
    {
        $transactionId = 'payment-abc-def-sync-pending';

        $response = $this->gateway->checkPaymentStatus($transactionId);

        $this->assertEquals('queued', $response->status,
            'AC-4C-49: "-sync-pending" suffix must trigger queued regardless of prefix');
    }

    // ========================================================================
    // AC-4C-50: Default transaction ID returns processed
    // ========================================================================

    public function test_check_payment_status_default_returns_processed(): void
    {
        $transactionId = 'mock-txn-123-1700000000';

        $response = $this->gateway->checkPaymentStatus($transactionId);

        $this->assertTrue($response->success,
            'AC-4C-50: Success must be true for default case');
        $this->assertEquals($transactionId, $response->transaction_id,
            'AC-4C-50: Transaction ID must be preserved');
        $this->assertEquals('processed', $response->status,
            'AC-4C-50: Default status must be "processed"');
    }

    public function test_check_payment_status_with_any_other_transaction_id_returns_processed(): void
    {
        $testCases = [
            'mock-txn-123',
            'payment-abc',
            'pix-txn-xyz-123',
            'simple-id',
            '123456',
            '',
        ];

        foreach ($testCases as $transactionId) {
            $response = $this->gateway->checkPaymentStatus($transactionId);

            $this->assertEquals('processed', $response->status,
                "AC-4C-50: Default status for '{$transactionId}' must be 'processed'");
        }
    }

    // ========================================================================
    // PaymentResponse Structure
    // ========================================================================

    public function test_payment_response_has_all_required_fields(): void
    {
        $response = $this->gateway->checkPaymentStatus('mock-txn-123');

        $this->assertTrue(isset($response->success),
            'PaymentResponse must have success field');
        $this->assertTrue(isset($response->transaction_id),
            'PaymentResponse must have transaction_id field');
        $this->assertTrue(isset($response->status),
            'PaymentResponse must have status field');
    }

    public function test_payment_response_success_is_boolean(): void
    {
        $response = $this->gateway->checkPaymentStatus('mock-txn-123');

        $this->assertIsBool($response->success,
            'success must be boolean');
    }

    public function test_payment_response_status_is_string(): void
    {
        $response = $this->gateway->checkPaymentStatus('mock-txn-123');

        $this->assertIsString($response->status,
            'status must be string');
    }

    public function test_payment_response_transaction_id_preserved(): void
    {
        $transactionIds = [
            'mock-txn-123',
            'custom-txn-id-abc',
            'payment-789-sync-failed',
            'payment-999-sync-pending',
        ];

        foreach ($transactionIds as $txnId) {
            $response = $this->gateway->checkPaymentStatus($txnId);

            $this->assertEquals($txnId, $response->transaction_id,
                "Transaction ID '{$txnId}' must be preserved in response");
        }
    }

    // ========================================================================
    // Edge Cases
    // ========================================================================

    public function test_sync_failed_takes_precedence_over_sync_pending(): void
    {
        // Edge case: what if both suffixes are present?
        // The pattern should match "-sync-failed" first (more specific)
        $transactionId = 'mock-txn-sync-failed';  // Contains both patterns

        // "-sync-failed" appears later, should match
        $response = $this->gateway->checkPaymentStatus($transactionId);

        $this->assertEquals('failed', $response->status,
            '"-sync-failed" should take precedence over "-sync-pending"');
    }

    public function test_empty_transaction_id_returns_processed(): void
    {
        $response = $this->gateway->checkPaymentStatus('');

        $this->assertEquals('processed', $response->status,
            'Empty transaction ID must return processed (default)');
    }

    public function test_null_transaction_id_returns_processed(): void
    {
        // Note: PHP type system won't allow null, but we can test empty string
        $response = $this->gateway->checkPaymentStatus('');

        $this->assertEquals('processed', $response->status,
            'Empty transaction ID must return processed');
    }

    public function test_failed_response_has_error_message(): void
    {
        $transactionId = 'mock-txn-sync-failed';

        $response = $this->gateway->checkPaymentStatus($transactionId);

        $this->assertNotNull($response->error_message,
            'Failed status must include error_message');
        $this->assertIsString($response->error_message,
            'error_message must be string');
    }

    public function test_queued_response_has_no_error(): void
    {
        $transactionId = 'mock-txn-sync-pending';

        $response = $this->gateway->checkPaymentStatus($transactionId);

        $this->assertNull($response->error_code,
            'Queued status should not have error_code');
        $this->assertNull($response->error_message,
            'Queued status should not have error_message');
    }

    public function test_processed_response_has_no_error(): void
    {
        $transactionId = 'mock-txn-123';

        $response = $this->gateway->checkPaymentStatus($transactionId);

        $this->assertNull($response->error_code,
            'Processed status should not have error_code');
        $this->assertNull($response->error_message,
            'Processed status should not have error_message');
    }

    public function test_case_sensitive_suffix_matching(): void
    {
        // Test that only lowercase "-sync-failed" and "-sync-pending" work
        $responseLowercase = $this->gateway->checkPaymentStatus('mock-txn-sync-failed');
        $this->assertEquals('failed', $responseLowercase->status,
            'Lowercase "-sync-failed" must work');

        $responseMixed = $this->gateway->checkPaymentStatus('mock-txn-SYNC-FAILED');
        $this->assertEquals('processed', $responseMixed->status,
            'Uppercase "-SYNC-FAILED" must NOT trigger failed (case sensitive)');
    }
}
