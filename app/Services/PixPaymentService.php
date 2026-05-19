<?php

namespace App\Services;

use App\Contracts\PixGatewayInterface;
use App\Enums\PaymentAuditAction;
use App\Enums\PaymentStatus;
use App\Enums\ShiftStatus;
use App\Models\Payment;
use App\Models\PaymentAuditLog;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Phase 4B: Gateway call orchestrator — initiates PIX transfer, handles response.
 *
 * BR-02: Uses the biker's verified PIX key.
 * BR-04: Each payment is independent — one gateway failure doesn't affect others.
 * BR-06: Every attempt writes a unique audit log with transaction_ref.
 *
 * AC-4B-13 through AC-4B-34
 *
 * @see docs/plans/phase-4b-pix-payment-execution.md
 */
class PixPaymentService
{
    public function __construct(
        private readonly PixGatewayInterface $gateway
    ) {}

    /**
     * Initiate a PIX transfer via the gateway.
     *
     * - Guard: payment must be in processing status
     * - Guard: biker must have verified PIX key
     * - Calls gateway.initiatePayment()
     * - Handles sync success → auto-paid
     * - Handles sync failure → auto-failed
     * - Handles async queued → stays processing
     * - Handles gateway exception → stays processing, gateway_status = error
     * - Handles unknown status → treated as queued
     *
     * @throws \RuntimeException if payment is not in processing status or no verified PIX key
     */
    public function initiateTransfer(Payment $payment, User $admin): Payment
    {
        // Guard: payment must be in processing status
        if ($payment->status !== PaymentStatus::Processing) {
            throw new \RuntimeException(
                "Payment must be in processing status to initiate transfer (current: {$payment->status->value})"
            );
        }

        // Resolve the biker's verified PIX key
        $biker = $payment->shiftBiker->biker;
        $verifiedPixKey = $biker->pixKeys()
            ->where('is_verified', true)
            ->first();

        if ($verifiedPixKey === null) {
            throw new \RuntimeException(
                "No verified PIX key found for biker #{$biker->id}"
            );
        }

        // Call gateway
        try {
            $response = $this->gateway->initiatePayment(
                paymentId: $payment->id,
                pixKey: $verifiedPixKey->key_value,
                amount: $payment->amount
            );
        } catch (\RuntimeException $e) {
            // Gateway unreachable — log and leave payment in processing
            // Admin can manually mark paid/failed later
            PaymentAuditLog::create([
                'payment_id' => $payment->id,
                'action' => PaymentAuditAction::GatewayAttempt,
                'transaction_ref' => "gateway-error-{$payment->id}-".Str::uuid(),
                'error_message' => "Gateway unreachable: {$e->getMessage()}",
                'payload' => [
                    'amount' => $payment->amount,
                    'pix_key_id' => $verifiedPixKey->id,
                    'error_type' => 'gateway_exception',
                    'initiated_by' => $admin->id,
                ],
            ]);

            $payment->gateway_status = 'error';
            $payment->save();

            return $payment;
        }

        // Store gateway transaction ID and status
        $payment->gateway_transaction_id = $response->transaction_id;
        $payment->gateway_status = $response->status;
        $payment->save();

        // Write audit log for gateway attempt
        PaymentAuditLog::create([
            'payment_id' => $payment->id,
            'action' => PaymentAuditAction::GatewayAttempt,
            'transaction_ref' => "gateway-attempt-{$payment->id}-".Str::uuid(),
            'payload' => [
                'transaction_id' => $response->transaction_id,
                'gateway_status' => $response->status,
                'amount' => $payment->amount,
                'pix_key_id' => $verifiedPixKey->id,
                'pix_key_value' => $verifiedPixKey->key_value,
                'initiated_by' => $admin->id,
            ],
        ]);

        // Handle synchronous gateway response
        if ($response->status === 'processed') {
            // Sync success — auto-transition to paid
            $payment->status = PaymentStatus::Paid;
            $payment->paid_at = now();
            $payment->gateway_status = 'processed';
            $payment->save();

            PaymentAuditLog::create([
                'payment_id' => $payment->id,
                'action' => PaymentAuditAction::Succeed,
                'transaction_ref' => "gateway-paid-{$payment->id}-".Str::uuid(),
                'payload' => [
                    'source' => 'gateway_auto',
                    'transaction_id' => $response->transaction_id,
                    'paid_at' => $payment->paid_at->toIso8601String(),
                    'amount' => $payment->amount,
                ],
            ]);

            // Reconcile shift status (approved → paid if all paid)
            $this->reconcileShiftStatus($payment->shiftBiker->shift);

        } elseif ($response->status === 'failed') {
            // Sync failure — auto-transition to failed
            $payment->status = PaymentStatus::Failed;
            $payment->failed_at = now();
            $payment->failure_reason = $response->error_message ?? 'Gateway returned failure';
            $payment->gateway_status = 'failed';
            $payment->save();

            PaymentAuditLog::create([
                'payment_id' => $payment->id,
                'action' => PaymentAuditAction::Fail,
                'transaction_ref' => "gateway-failed-{$payment->id}-".Str::uuid(),
                'error_message' => $response->error_message,
                'payload' => [
                    'source' => 'gateway_auto',
                    'transaction_id' => $response->transaction_id,
                    'error_code' => $response->error_code,
                    'failed_at' => $payment->failed_at->toIso8601String(),
                    'amount' => $payment->amount,
                ],
            ]);

            // BR-04: DO NOT touch shift.status — failed payment leaves shift at approved

        } else {
            // Async queued or unknown status — payment stays in processing
            // gateway_status already set above
        }

        return $payment;
    }

    /**
     * Auto-transition shift approved → paid ONLY when every payment is paid.
     * BR-04 reinforced: shift NEVER moves backward.
     */
    private function reconcileShiftStatus(Shift $shift): void
    {
        $shift->refresh();
        $shift->loadMissing('shiftBikers.payment');

        if ($shift->status !== ShiftStatus::Approved) {
            return;
        }

        if ($shift->allPaymentsPaid()) {
            $shift->status = ShiftStatus::Paid;
            $shift->save();
        }
    }
}
