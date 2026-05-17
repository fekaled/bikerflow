<?php

namespace App\Services;

use App\Enums\PaymentAuditAction;
use App\Enums\PaymentStatus;
use App\Enums\ShiftStatus;
use App\Models\Payment;
use App\Models\PaymentAuditLog;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 3C: Orchestrates payment settlement — mark paid, mark failed, retry.
 *
 * BR-02: Re-checks PIX verification on retry.
 * BR-03: All actions are explicit Admin actions (no automated transitions).
 * BR-04: Each payment transitions independently — failure never regresses siblings or shift.
 * BR-06: Every attempt logged with unique transaction_ref.
 *
 * @see docs/plans/phase-3c-payment-failure-and-retry.md
 */
class PaymentSettlementService
{
    /**
     * Returns dashboard data for the per-shift settlement view.
     * Groups payments by current status.
     *
     * @return array{shift: Shift, groups: array{processing: array, failed: array, paid: array}, totals: array{processing: string, failed: string, paid: string}, allPaid: bool}
     */
    public function getSettlementData(Shift $shift): array
    {
        $shift->load([
            'restaurant',
            'shiftBikers.biker.pixKeys',
            'shiftBikers.payment.releasedByUser',
        ]);

        $groups = [
            'processing' => [],
            'failed' => [],
            'paid' => [],
        ];
        $totals = ['processing' => '0.00', 'failed' => '0.00', 'paid' => '0.00'];

        foreach ($shift->shiftBikers as $shiftBiker) {
            $payment = $shiftBiker->payment;
            if ($payment === null) {
                continue;
            }

            $item = [
                'shiftBiker' => $shiftBiker,
                'biker' => $shiftBiker->biker,
                'payment' => $payment,
                'isEligibleForRetry' => $payment->status === PaymentStatus::Failed
                    && $payment->isEligibleForRetry()
                    && $payment->retry_count < 3,
            ];

            match ($payment->status) {
                PaymentStatus::Processing => (function () use (&$groups, &$totals, $item, $payment) {
                    $groups['processing'][] = $item;
                    $totals['processing'] = bcadd($totals['processing'], $payment->amount, 2);
                })(),
                PaymentStatus::Failed => (function () use (&$groups, &$totals, $item, $payment) {
                    $groups['failed'][] = $item;
                    $totals['failed'] = bcadd($totals['failed'], $payment->amount, 2);
                })(),
                PaymentStatus::Paid => (function () use (&$groups, &$totals, $item, $payment) {
                    $groups['paid'][] = $item;
                    $totals['paid'] = bcadd($totals['paid'], $payment->amount, 2);
                })(),
                default => null,
            };
        }

        return [
            'shift' => $shift,
            'groups' => $groups,
            'totals' => $totals,
            'allPaid' => $shift->allPaymentsPaid(),
        ];
    }

    /**
     * Mark a processing payment as paid.
     * Idempotency: if not processing, throws RuntimeException.
     *
     * AC-3C-11 through AC-3C-18.
     */
    public function markPaid(Payment $payment, User $admin): Payment
    {
        return DB::transaction(function () use ($payment, $admin) {
            $payment->refresh();

            if ($payment->status !== PaymentStatus::Processing) {
                throw new \RuntimeException(
                    "Payment not in processing status (current: {$payment->status->value})"
                );
            }

            $payment->status = PaymentStatus::Paid;
            $payment->paid_at = now();
            $payment->save();

            PaymentAuditLog::create([
                'payment_id' => $payment->id,
                'action' => PaymentAuditAction::Succeed,
                'transaction_ref' => "succeed-{$payment->id}-".Str::uuid(),
                'payload' => [
                    'marked_by' => $admin->id,
                    'amount' => $payment->amount,
                    'retry_count' => $payment->retry_count,
                    'paid_at' => $payment->paid_at->toIso8601String(),
                ],
            ]);

            $this->reconcileShiftStatus($payment->shiftBiker->shift);

            return $payment;
        });
    }

    /**
     * Mark a processing payment as failed with a free-form reason.
     * BR-04: failing this payment must NEVER regress the shift status.
     *
     * AC-3C-19 through AC-3C-27.
     */
    public function markFailed(Payment $payment, User $admin, string $reason): Payment
    {
        return DB::transaction(function () use ($payment, $admin, $reason) {
            $payment->refresh();

            if ($payment->status !== PaymentStatus::Processing) {
                throw new \RuntimeException(
                    "Payment not in processing status (current: {$payment->status->value})"
                );
            }

            $payment->status = PaymentStatus::Failed;
            $payment->failed_at = now();
            $payment->failure_reason = $reason;
            $payment->save();

            PaymentAuditLog::create([
                'payment_id' => $payment->id,
                'action' => PaymentAuditAction::Fail,
                'transaction_ref' => "fail-{$payment->id}-".Str::uuid(),
                'error_message' => $reason,
                'payload' => [
                    'marked_by' => $admin->id,
                    'amount' => $payment->amount,
                    'retry_count' => $payment->retry_count,
                    'failed_at' => $payment->failed_at->toIso8601String(),
                ],
            ]);

            // BR-04: DO NOT touch shift.status here.
            // A failed payment leaves the shift at `approved`.

            return $payment;
        });
    }

    /**
     * Retry a failed payment.
     * BR-06: increments retry_count, writes audit log, transitions back to processing.
     * BR-02 + ADR-005 D4: re-checks eligibility.
     * OQ-3C-01: Hard cap at 3 retries.
     *
     * AC-3C-28 through AC-3C-35, AC-3C-45, AC-3C-46.
     */
    public function retry(Payment $payment, User $admin): Payment
    {
        return DB::transaction(function () use ($payment, $admin) {
            $payment->refresh();

            if ($payment->status !== PaymentStatus::Failed) {
                throw new \RuntimeException(
                    "Only failed payments can be retried (current: {$payment->status->value})"
                );
            }

            // OQ-3C-01: Hard retry cap — refuse if already retried 3 times.
            if ($payment->retry_count >= 3) {
                throw new \RuntimeException(
                    'Payment has reached the maximum retry count (3). Admin intervention required — consider manual bank transfer or contact the biker.'
                );
            }

            // Re-evaluate eligibility — state may have drifted since the original release.
            if (! $payment->isEligibleForRetry()) {
                $biker = $payment->shiftBiker->biker;
                $reasons = [];
                if (! $biker->hasVerifiedPixKey()) {
                    $reasons[] = 'Sem chave PIX verificada';
                }
                if (! $biker->hasUserAccount()) {
                    $reasons[] = 'Sem conta de usuário vinculada';
                }
                throw new \RuntimeException(
                    'Payment no longer eligible: '.implode('; ', $reasons)
                );
            }

            $payment->status = PaymentStatus::Processing;
            $payment->retry_count = $payment->retry_count + 1;
            $payment->failed_at = null;
            $payment->failure_reason = null;
            $payment->save();

            $retryCapReached = $payment->retry_count >= 3;

            PaymentAuditLog::create([
                'payment_id' => $payment->id,
                'action' => PaymentAuditAction::Retry,
                'transaction_ref' => "retry-{$payment->id}-".Str::uuid(),
                'payload' => [
                    'retried_by' => $admin->id,
                    'new_retry_count' => $payment->retry_count,
                    'amount' => $payment->amount,
                    'retry_cap_reached' => $retryCapReached,
                ],
            ]);

            // OQ-3C-01: If this was the 3rd retry (retry_count now == 3),
            // immediately mark as permanently failed.
            if ($retryCapReached) {
                $payment->status = PaymentStatus::Failed;
                $payment->failed_at = now();
                $payment->failure_reason = 'Limite de retentativas atingido (3/3). Intervenção manual necessária.';
                $payment->save();

                PaymentAuditLog::create([
                    'payment_id' => $payment->id,
                    'action' => PaymentAuditAction::Fail,
                    'transaction_ref' => "auto-fail-cap-{$payment->id}-".Str::uuid(),
                    'payload' => [
                        'reason' => 'retry_cap_exceeded',
                        'retry_count' => $payment->retry_count,
                    ],
                ]);
            }

            return $payment;
        });
    }

    /**
     * Auto-transition shift approved → paid ONLY when every payment is paid.
     * BR-04 reinforced: shift NEVER moves backward.
     *
     * AC-3C-36 through AC-3C-39.
     */
    public function reconcileShiftStatus(Shift $shift): void
    {
        $shift->refresh();
        $shift->loadMissing('shiftBikers.payment');

        if ($shift->status !== ShiftStatus::Approved) {
            return; // already terminal or not yet approved — nothing to do
        }

        if ($shift->allPaymentsPaid()) {
            $shift->status = ShiftStatus::Paid;
            $shift->save();
        }
    }

    // TODO: Future bulk-retry method for Phase 3C+ (single-payment retry only for now).
}
