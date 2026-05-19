<?php

namespace App\Services\Gateway;

use App\Contracts\PaymentResponse;
use App\Contracts\PixGatewayInterface;
use App\Contracts\VerifyKeyResponse;
use App\Enums\PixKeyType;

class MockPixGateway implements PixGatewayInterface
{
    /**
     * Verify a PIX key against the mock bank API.
     *
     * Deterministic behavior:
     * - Normal key → success with "MOCK HOLDER for {keyValue}"
     * - Key starting with "FAIL" → failure with KEY_NOT_FOUND
     * - Key starting with "ERROR" → throws RuntimeException (simulates gateway down)
     */
    public function verifyKey(PixKeyType $keyType, string $keyValue): VerifyKeyResponse
    {
        if (str_starts_with($keyValue, 'FAIL')) {
            return new VerifyKeyResponse(
                success: false,
                error_code: 'KEY_NOT_FOUND',
                error_message: 'Chave PIX não encontrada',
            );
        }

        if (str_starts_with($keyValue, 'ERROR')) {
            throw new \RuntimeException('Gateway connection timeout');
        }

        $prefix = config('pix.gateway.mock.holder_name_prefix', 'MOCK HOLDER for');

        return new VerifyKeyResponse(
            success: true,
            account_holder_name: "{$prefix} {$keyValue}",
        );
    }

    /**
     * Initiate a mock PIX payment.
     *
     * Deterministic mock based on amount patterns and pixKey for testing:
     *
     * 1. pixKey starting with "ERROR" → throws RuntimeException (gateway unreachable)
     *    AC-4B-12
     *
     * 2. pixKey starting with "FAIL-" → failure response
     *    Returns PaymentResponse(status="failed", error_code="REJECTED_BY_RECEIVER")
     *    AC-4B-11
     *
     * 3. Amount ending with ".01" → sync success (processed)
     *    Returns PaymentResponse(status="processed", transaction_id="mock-txn-{id}-{ts}")
     *    AC-4B-08
     *
     * 4. Amount ending with ".02" → sync failure (failed)
     *    Returns PaymentResponse(status="failed", error_code="INSUFFICIENT_FUNDS")
     *    AC-4B-09
     *
     * 5. All other amounts → async queued (default)
     *    Returns PaymentResponse(status="queued", transaction_id="mock-txn-{id}-{ts}")
     *    AC-4B-10
     *
     * @see docs/plans/phase-4b-pix-payment-execution.md
     */
    public function initiatePayment(int $paymentId, string $pixKey, string $amount): PaymentResponse
    {
        // pixKey starting with "ERROR" → RuntimeException (gateway unreachable)
        if (str_starts_with($pixKey, 'ERROR')) {
            throw new \RuntimeException('Gateway connection timeout');
        }

        // pixKey starting with "FAIL-" → failure response
        // API call succeeds but payment is rejected by receiver
        // AC-4B-11: transaction_id is null for rejected payments
        if (str_starts_with($pixKey, 'FAIL-')) {
            return new PaymentResponse(
                success: false,
                transaction_id: null,
                status: 'failed',
                error_code: 'REJECTED_BY_RECEIVER',
                error_message: 'Destinatário rejeitou o pagamento',
            );
        }

        // Amount ending with ".01" → sync success (processed)
        if (str_ends_with($amount, '.01')) {
            $txnId = "mock-txn-{$paymentId}-".time();

            return new PaymentResponse(
                success: true,
                transaction_id: $txnId,
                status: 'processed',
            );
        }

        // Amount ending with ".02" → sync failure (failed)
        // AC-4B-09: API call succeeds but payment is rejected
        // success=true indicates the gateway API call succeeded;
        // status="failed" indicates the payment itself was rejected
        if (str_ends_with($amount, '.02')) {
            $txnId = "mock-txn-{$paymentId}-".time();

            return new PaymentResponse(
                success: true,
                transaction_id: $txnId,
                status: 'failed',
                error_code: 'INSUFFICIENT_FUNDS',
                error_message: 'Saldo insuficiente para PIX',
            );
        }

        // Default: async queued
        $txnId = "mock-txn-{$paymentId}-".time();

        return new PaymentResponse(
            success: true,
            transaction_id: $txnId,
            status: 'queued',
        );
    }

    /**
     * Check the status of a mock PIX payment.
     *
     * For Phase 4C: returns sync status based on transaction ID suffixes:
     *   "-sync-failed"  → failed status with RECIPIENT_NOT_FOUND
     *   "-sync-pending"  → queued status
     *   anything else    → processed status
     *
     * For Phase 4A stub (compatibility): all return "processed".
     */
    public function checkPaymentStatus(string $transactionId): PaymentResponse
    {
        if (str_ends_with($transactionId, '-sync-failed')) {
            return new PaymentResponse(
                success: true,
                transaction_id: $transactionId,
                status: 'failed',
                error_code: 'RECIPIENT_NOT_FOUND',
                error_message: 'Beneficiário não encontrado',
            );
        }

        if (str_ends_with($transactionId, '-sync-pending')) {
            return new PaymentResponse(
                success: true,
                transaction_id: $transactionId,
                status: 'queued',
            );
        }

        return new PaymentResponse(
            success: true,
            transaction_id: $transactionId,
            status: 'processed',
        );
    }
}
