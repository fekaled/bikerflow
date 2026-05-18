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
     * Stub for Phase 4B — always returns "queued" status.
     */
    public function initiatePayment(int $paymentId, string $pixKey, string $amount): PaymentResponse
    {
        return new PaymentResponse(
            success: true,
            transaction_id: "mock-txn-{$paymentId}",
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
