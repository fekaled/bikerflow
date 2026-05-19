<?php

namespace App\Console\Commands;

use App\Contracts\PixGatewayInterface;
use App\Enums\PaymentAuditAction;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentAuditLog;
use App\Services\PixPaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Phase 4C: Artisan command for manually checking payment status from the gateway.
 *
 * Usage: php artisan pix:webhook:verify {gatewayTransactionId}
 *
 * AC-4C-51 through AC-4C-55
 *
 * @see docs/plans/phase-4c-pix-webhooks-async-status.md
 */
class VerifyPixPayment extends Command
{
    protected $signature = 'pix:webhook:verify {gatewayTransactionId : The gateway transaction ID to check}';

    protected $description = 'Manually check payment status from the gateway';

    public function handle(PixGatewayInterface $gateway, PixPaymentService $paymentService): int
    {
        $gatewayTransactionId = $this->argument('gatewayTransactionId');

        // AC-4C-51: Find payment by gateway_transaction_id
        $payment = Payment::where('gateway_transaction_id', $gatewayTransactionId)->first();

        if ($payment === null) {
            // AC-4C-52: Payment not found → error (exit code 1)
            $this->error("Payment not found for transaction: {$gatewayTransactionId}");

            return 1;
        }

        // AC-4C-53: If payment is not in processing, it's already resolved
        if ($payment->status !== PaymentStatus::Processing) {
            $this->info("Payment {$payment->id} is already {$payment->status->value}. No check needed.");

            return 0;
        }

        // AC-4C-54: Call gateway to check status
        $response = $gateway->checkPaymentStatus($gatewayTransactionId);

        $this->info("Gateway status: {$response->status}");
        $this->info("Transaction ID: {$response->transaction_id}");

        if ($response->status === 'processed') {
            $payment->status = PaymentStatus::Paid;
            $payment->paid_at = now();
            $payment->gateway_status = 'processed';
            $payment->save();

            PaymentAuditLog::create([
                'payment_id' => $payment->id,
                'action' => PaymentAuditAction::Succeed,
                'transaction_ref' => "verify-paid-{$payment->id}-".Str::uuid(),
                'payload' => [
                    'source' => 'manual_verify',
                    'transaction_id' => $gatewayTransactionId,
                    'paid_at' => $payment->paid_at->toIso8601String(),
                    'amount' => (string) $payment->amount,
                ],
            ]);

            $paymentService->reconcileShiftStatus($payment->shiftBiker->shift);

            $this->info("Payment {$payment->id} marked as PAID.");

        } elseif ($response->status === 'failed') {
            $payment->status = PaymentStatus::Failed;
            $payment->failed_at = now();
            $payment->failure_reason = $response->error_message ?? 'Manual verify: gateway returned failed';
            $payment->gateway_status = 'failed';
            $payment->save();

            PaymentAuditLog::create([
                'payment_id' => $payment->id,
                'action' => PaymentAuditAction::Fail,
                'transaction_ref' => "verify-failed-{$payment->id}-".Str::uuid(),
                'error_message' => $response->error_message,
                'payload' => [
                    'source' => 'manual_verify',
                    'transaction_id' => $gatewayTransactionId,
                    'error_code' => $response->error_code,
                    'failed_at' => $payment->failed_at->toIso8601String(),
                    'amount' => (string) $payment->amount,
                ],
            ]);

            $this->info("Payment {$payment->id} marked as FAILED: {$response->error_message}");

        } else {
            // AC-4C-55: Output the resolved status
            $this->info("Payment still in processing. Gateway status: {$response->status}");
        }

        return 0;
    }
}
