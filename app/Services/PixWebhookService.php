<?php

namespace App\Services;

use App\Enums\PaymentAuditAction;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentAuditLog;
use App\Models\PixWebhookLog;
use Illuminate\Support\Str;

/**
 * Phase 4C: PIX Webhook processing service.
 *
 * Processes incoming webhook callbacks from the PIX gateway:
 * - Resolves payment by gateway_transaction_id
 * - Handles idempotency (duplicate webhooks are no-ops)
 * - Transitions payment to paid/failed based on gateway status
 * - Writes audit log entries (BR-06)
 * - Delegates shift reconciliation to PixPaymentService (ADR-006 D4)
 *
 * Business Rules: BR-04 (Granular Failure), BR-06 (Payment Retries)
 *
 * @see docs/plans/phase-4c-pix-webhooks-async-status.md
 */
class PixWebhookService
{
    public function __construct(
        private readonly PixPaymentService $paymentService
    ) {}

    /**
     * Process an incoming webhook payload.
     *
     * @param  array  $payload  Validated webhook payload
     * @param  string  $ipAddress  Source IP of the webhook request
     * @return PixWebhookLog Record of webhook processing result
     */
    public function processWebhook(array $payload, string $ipAddress = '127.0.0.1'): PixWebhookLog
    {
        $transactionId = $payload['transaction_id'];
        $status = $payload['status'];
        $errorCode = $payload['error_code'] ?? null;
        $errorMessage = $payload['error_message'] ?? null;

        // Step 1: Find payment by gateway_transaction_id
        $payment = Payment::where('gateway_transaction_id', $transactionId)->first();

        if ($payment === null) {
            // AC-4C-22: Payment not found → ignored
            return $this->createWebhookLog(
                $transactionId,
                $payload,
                'ignored',
                "Payment not found for transaction_id: {$transactionId}",
                $ipAddress
            );
        }

        // Step 2: Idempotency check — payment already in terminal state
        if ($payment->status === PaymentStatus::Paid || $payment->status === PaymentStatus::Failed) {
            // AC-4C-24, AC-4C-25: Duplicate webhook — no status change
            return $this->createWebhookLog(
                $transactionId,
                $payload,
                'duplicate',
                "Payment already in terminal status: {$payment->status->value}",
                $ipAddress
            );
        }

        // Step 3: Guard — payment must be in processing status
        if ($payment->status !== PaymentStatus::Processing) {
            // AC-4C-27, AC-4C-28: Not in processing → ignored
            return $this->createWebhookLog(
                $transactionId,
                $payload,
                'ignored',
                "Payment not in processing status (current: {$payment->status->value})",
                $ipAddress
            );
        }

        // Step 4: Process status update
        if ($status === 'processed') {
            return $this->handlePaymentSuccess($payment, $payload, $transactionId, $ipAddress);
        }

        if ($status === 'failed') {
            return $this->handlePaymentFailure($payment, $payload, $transactionId, $ipAddress, $errorMessage, $errorCode);
        }

        // AC-4C-38: Unknown status — log but don't change payment
        return $this->createWebhookLog(
            $transactionId,
            $payload,
            'ignored',
            "Unknown webhook status: {$status}",
            $ipAddress
        );
    }

    /**
     * Handle webhook status "processed" — transition payment to paid.
     *
     * AC-4C-29, AC-4C-30, AC-4C-31, AC-4C-32
     */
    private function handlePaymentSuccess(
        Payment $payment,
        array $payload,
        string $transactionId,
        string $ipAddress
    ): PixWebhookLog {
        // AC-4C-29: Transition to paid
        $payment->status = PaymentStatus::Paid;
        $payment->paid_at = now();
        $payment->gateway_status = 'processed';
        $payment->save();

        // AC-4C-30: Write audit log
        PaymentAuditLog::create([
            'payment_id' => $payment->id,
            'action' => PaymentAuditAction::Succeed,
            'transaction_ref' => "webhook-paid-{$payment->id}-".Str::uuid(),
            'payload' => [
                'source' => 'webhook',
                'transaction_id' => $transactionId,
                'paid_at' => $payment->paid_at->toIso8601String(),
                'amount' => (string) $payment->amount,
                'webhook_ip' => $ipAddress,
            ],
        ]);

        // AC-4C-31: Delegate reconciliation to PixPaymentService (ADR-006 D4)
        $this->paymentService->reconcileShiftStatus($payment->shiftBiker->shift);

        // AC-4C-32: Create webhook log
        return $this->createWebhookLog($transactionId, $payload, 'processed', null, $ipAddress);
    }

    /**
     * Handle webhook status "failed" — transition payment to failed.
     *
     * AC-4C-33 through AC-4C-37
     * BR-04: DO NOT touch shift status
     */
    private function handlePaymentFailure(
        Payment $payment,
        array $payload,
        string $transactionId,
        string $ipAddress,
        ?string $errorMessage,
        ?string $errorCode
    ): PixWebhookLog {
        // AC-4C-33: Determine failure reason
        $failureReason = $errorMessage ?? "Gateway webhook: {$errorCode}";

        // AC-4C-33: Transition to failed
        $payment->status = PaymentStatus::Failed;
        $payment->failed_at = now();
        $payment->failure_reason = $failureReason;
        $payment->gateway_status = 'failed';
        $payment->save();

        // AC-4C-35: Write audit log
        PaymentAuditLog::create([
            'payment_id' => $payment->id,
            'action' => PaymentAuditAction::Fail,
            'transaction_ref' => "webhook-failed-{$payment->id}-".Str::uuid(),
            'error_message' => $errorMessage,
            'payload' => [
                'source' => 'webhook',
                'transaction_id' => $transactionId,
                'error_code' => $errorCode,
                'failed_at' => $payment->failed_at->toIso8601String(),
                'amount' => (string) $payment->amount,
                'webhook_ip' => $ipAddress,
            ],
        ]);

        // AC-4C-36, BR-04: DO NOT touch shift.status
        // No call to reconcileShiftStatus for failures

        // AC-4C-37: Create webhook log
        return $this->createWebhookLog($transactionId, $payload, 'processed', null, $ipAddress);
    }

    /**
     * Create a PixWebhookLog record.
     */
    private function createWebhookLog(
        string $transactionId,
        array $payload,
        string $status,
        ?string $errorMessage,
        string $ipAddress
    ): PixWebhookLog {
        return PixWebhookLog::create([
            'gateway_transaction_id' => $transactionId,
            'payload' => $payload,
            'status' => $status,
            'error_message' => $errorMessage,
            'ip_address' => $ipAddress,
            'received_at' => now(),
        ]);
    }
}
