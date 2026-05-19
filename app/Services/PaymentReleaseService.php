<?php

namespace App\Services;

use App\Contracts\PixGatewayInterface;
use App\Enums\PaymentAuditAction;
use App\Enums\PaymentStatus;
use App\Enums\ShiftStatus;
use App\Models\Biker;
use App\Models\Payment;
use App\Models\PaymentAuditLog;
use App\Models\Shift;
use App\Models\ShiftBiker;
use App\Models\User;
use App\Services\PixPaymentService;

/**
 * Phase 3B: Orchestrates payment release workflow.
 *
 * BR-02: Payments blocked if biker has no verified PIX key.
 * BR-03: Admin must explicitly release each eligible payment.
 * BR-04: Each payment is independent — releasing one doesn't affect others.
 *
 * @see docs/plans/phase-3b-payment-release-admin-approval.md
 */
class PaymentReleaseService
{
    public function __construct(
        private readonly ?PixGatewayInterface $gateway = null
    ) {}

    /**
     * Get payment review data for a closed/approved shift.
     *
     * @return array{
     *     shift: Shift,
     *     paymentItems: array<int, array{shiftBiker: ShiftBiker, biker: Biker, payment: Payment, hasUser: bool, hasVerifiedPixKey: bool, isEligible: bool, blockReasons: array<int, string>}>,
     *     totalPending: string,
     *     totalProcessing: string,
     *     eligibleCount: int,
     *     ineligibleCount: int
     * }
     */
    public function getPaymentReviewData(Shift $shift): array
    {
        $shift->load(['shiftBikers.biker.pixKeys', 'shiftBikers.payment']);

        $paymentItems = [];
        $totalPending = '0.00';
        $totalProcessing = '0.00';
        $eligibleCount = 0;
        $ineligibleCount = 0;

        foreach ($shift->shiftBikers as $shiftBiker) {
            $payment = $shiftBiker->payment;
            $biker = $shiftBiker->biker;

            if ($payment === null) {
                continue;
            }

            $hasUser = User::where('biker_id', $biker->id)->exists();
            $hasVerifiedPixKey = $biker->pixKeys()
                ->where('is_verified', true)
                ->exists();

            $isEligible = $hasUser && $hasVerifiedPixKey
                && $payment->status === PaymentStatus::Pending;

            $blockReasons = [];
            if (! $hasUser) {
                $blockReasons[] = 'Entregador sem conta de usuário vinculada';
            }
            if (! $hasVerifiedPixKey) {
                $blockReasons[] = 'Entregador sem chave PIX verificada';
            }
            if ($payment->status !== PaymentStatus::Pending) {
                $blockReasons[] = "Pagamento não está pendente (status: {$payment->status->value})";
            }

            if ($payment->status === PaymentStatus::Pending) {
                $totalPending = bcadd($totalPending, $payment->amount, 2);
            }
            if ($payment->status === PaymentStatus::Processing) {
                $totalProcessing = bcadd($totalProcessing, $payment->amount, 2);
            }

            if ($isEligible) {
                $eligibleCount++;
            } elseif ($payment->status === PaymentStatus::Pending) {
                $ineligibleCount++;
            }

            $paymentItems[] = [
                'shiftBiker' => $shiftBiker,
                'biker' => $biker,
                'payment' => $payment,
                'hasUser' => $hasUser,
                'hasVerifiedPixKey' => $hasVerifiedPixKey,
                'isEligible' => $isEligible,
                'blockReasons' => $blockReasons,
            ];
        }

        return [
            'shift' => $shift,
            'paymentItems' => $paymentItems,
            'totalPending' => $totalPending,
            'totalProcessing' => $totalProcessing,
            'eligibleCount' => $eligibleCount,
            'ineligibleCount' => $ineligibleCount,
        ];
    }

    /**
     * Release a single payment.
     *
     * BR-02: Block if no verified PIX key.
     * ADR-005 D4: Block if no user account.
     * BR-03: Explicit admin action required.
     *
     * @throws \RuntimeException if payment is not eligible
     */
    public function releasePayment(Payment $payment, User $admin): Payment
    {
        if ($payment->status !== PaymentStatus::Pending) {
            throw new \RuntimeException(
                "Payment is not pending. Status: {$payment->status->value}"
            );
        }

        $biker = $payment->shiftBiker->biker;

        // BR-02: PIX verification gate
        $hasVerifiedPixKey = $biker->pixKeys()
            ->where('is_verified', true)
            ->exists();
        if (! $hasVerifiedPixKey) {
            throw new \RuntimeException('Biker has no verified PIX key. Payment blocked.');
        }

        // ADR-005 D4: User account gate
        $hasUser = User::where('biker_id', $biker->id)->exists();
        if (! $hasUser) {
            throw new \RuntimeException('Biker has no linked User account. Payment blocked.');
        }

        // Transition payment status
        $payment->status = PaymentStatus::Processing;
        $payment->released_by = $admin->id;
        $payment->released_at = now();
        $payment->save();

        // Create audit log entry
        PaymentAuditLog::create([
            'payment_id' => $payment->id,
            'action' => PaymentAuditAction::Release,
            'transaction_ref' => "release-{$payment->id}-".now()->timestamp,
            'payload' => [
                'released_by' => $admin->id,
                'released_at' => now()->toIso8601String(),
                'amount' => $payment->amount,
                'biker_id' => $biker->id,
            ],
        ]);

        // Check if all payments for this shift are now released
        $this->checkAndTransitionShiftToApproved($payment->shiftBiker->shift);


        // Phase 4B: Initiate gateway transfer after payment transitions to processing
        $this->gatewayInitiateTransfer($payment, $admin);

        return $payment->refresh();
    }

    /**
     * Resolve the gateway and call initiateTransfer.
     * Uses injected gateway if available, otherwise resolves from container.
     */
    private function gatewayInitiateTransfer(Payment $payment, User $admin): void
    {
        $gateway = $this->gateway ?? app(PixGatewayInterface::class);
        (new PixPaymentService($gateway))->initiateTransfer($payment, $admin);
    }

    /**
     * Batch release all eligible payments for a shift.
     *
     * BR-04: Each payment is independent — one failure doesn't stop others.
     *
     * @return array{released: array<int, int>, blocked: array<int, array{payment_id: int, biker: string, reason: string}>}
     *
     * @throws \RuntimeException if shift is not closed
     */
    public function releaseAllEligiblePayments(Shift $shift, User $admin): array
    {
        if ($shift->status !== ShiftStatus::Closed && $shift->status !== ShiftStatus::Approved) {
            throw new \RuntimeException(
                "Only closed shifts can have payments released. Current status: {$shift->status->value}"
            );
        }

        $results = ['released' => [], 'blocked' => []];

        $shift->load('shiftBikers.payment', 'shiftBikers.biker.pixKeys');

        foreach ($shift->shiftBikers as $shiftBiker) {
            $payment = $shiftBiker->payment;
            if ($payment === null || $payment->status !== PaymentStatus::Pending) {
                continue;
            }

            try {
                $this->releasePayment($payment, $admin);
                $results['released'][] = $payment->id;
            } catch (\RuntimeException $e) {
                $results['blocked'][] = [
                    'payment_id' => $payment->id,
                    'biker' => $shiftBiker->biker->name,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Auto-transition shift to Approved when all payments are released.
     */
    public function checkAndTransitionShiftToApproved(Shift $shift): void
    {
        $shift->refresh();
        $shift->loadMissing('shiftBikers.payment');

        $allReleased = $shift->shiftBikers->every(
            fn (ShiftBiker $sb) => $sb->payment
                && $sb->payment->status !== PaymentStatus::Pending
        );

        if ($allReleased && $shift->status === ShiftStatus::Closed) {
            $shift->status = ShiftStatus::Approved;
            $shift->save();
        }
    }
}
