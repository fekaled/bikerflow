<?php

namespace App\Contracts;

use App\Enums\PixKeyType;

interface PixGatewayInterface
{
    /**
     * Verify a PIX key against the bank API.
     *
     * Returns a VerifyKeyResponse DTO with:
     * - success: bool
     * - account_holder_name: string|null
     * - error_code: string|null
     * - error_message: string|null
     */
    public function verifyKey(PixKeyType $keyType, string $keyValue): VerifyKeyResponse;

    /**
     * Initiate a PIX payment.
     *
     * Returns a PaymentResponse DTO with:
     * - success: bool
     * - transaction_id: string|null
     * - status: string ("queued", "processed", "failed")
     * - error_code: string|null
     * - error_message: string|null
     */
    public function initiatePayment(int $paymentId, string $pixKey, string $amount): PaymentResponse;

    /**
     * Check the status of a PIX payment.
     *
     * Returns a PaymentResponse DTO (same structure as initiatePayment).
     */
    public function checkPaymentStatus(string $transactionId): PaymentResponse;
}
