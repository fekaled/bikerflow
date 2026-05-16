<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Enums\ShiftStatus;
use App\Models\Biker;
use App\Models\Payment;
use App\Models\Shift;
use App\Models\ShiftBiker;
use App\Models\User;

/**
 * Phase 3A: Orchestrates shift close review and payout batch calculation.
 *
 * @see docs/plans/phase-3a-shift-close-payout-calculation.md
 */
class ShiftCloseService
{
    public function __construct(
        private PayoutService $payoutService = new PayoutService,
        private RevenueService $revenueService = new RevenueService,
    ) {}

    /**
     * Prepare review data for the close confirmation page.
     *
     * Loads shift with all shift_bikers and their bikers/PIX keys,
     * computes projected payouts and revenues, and checks eligibility.
     *
     * @return array{
     *     shift: Shift,
     *     reviewItems: array<int, array{shiftBiker: ShiftBiker, biker: Biker, payout: string, revenue: string, hasUser: bool, hasVerifiedPixKey: bool, warnings: array<int, string>}>,
     *     totalPayout: string,
     *     totalRevenue: string,
     *     hasWarnings: bool
     * }
     */
    public function getReviewData(Shift $shift): array
    {
        $shift->load(['shiftBikers.biker.pixKeys']);

        $payoutService = $this->payoutService;
        $revenueService = $this->revenueService;

        $reviewItems = [];
        $totalPayout = '0.00';
        $totalRevenue = '0.00';
        $hasWarnings = false;

        foreach ($shift->shiftBikers as $shiftBiker) {
            $biker = $shiftBiker->biker;

            $payout = $payoutService->calculate(
                $shiftBiker->base_fee,
                $shiftBiker->biker_rate,
                $shiftBiker->trips_count,
            );

            $revenue = $revenueService->calculate(
                $shift->restaurant_rate,
                $shiftBiker->trips_count,
                $payout,
            );

            // Eligibility checks (ADR-005 D4, BR-02)
            $hasUser = User::where('biker_id', $biker->id)->exists();
            $hasVerifiedPixKey = $biker->pixKeys()
                ->where('is_verified', true)
                ->exists();

            $warnings = [];

            if (! $hasUser) {
                $warnings[] = 'Entregador sem conta de usuário vinculada';
                $hasWarnings = true;
            }

            if (! $hasVerifiedPixKey) {
                $warnings[] = 'Entregador sem chave PIX verificada';
                $hasWarnings = true;
            }

            $totalPayout = bcadd($totalPayout, $payout, 2);
            $totalRevenue = bcadd($totalRevenue, $revenue, 2);

            $reviewItems[] = [
                'shiftBiker' => $shiftBiker,
                'biker' => $biker,
                'payout' => $payout,
                'revenue' => $revenue,
                'hasUser' => $hasUser,
                'hasVerifiedPixKey' => $hasVerifiedPixKey,
                'warnings' => $warnings,
            ];
        }

        return [
            'shift' => $shift,
            'reviewItems' => $reviewItems,
            'totalPayout' => $totalPayout,
            'totalRevenue' => $totalRevenue,
            'hasWarnings' => $hasWarnings,
        ];
    }

    /**
     * Close the shift and batch-create Payment rows for each shift_biker.
     *
     * @throws \RuntimeException if shift is not open
     */
    public function closeAndCalculate(Shift $shift, User $admin): Shift
    {
        if ($shift->status !== ShiftStatus::Open) {
            throw new \RuntimeException(
                "Only open shifts can be closed. Current status: {$shift->status->value}"
            );
        }

        // Transition shift to Closed
        $shift->status = ShiftStatus::Closed;
        $shift->closed_at = now();
        $shift->save();

        // Load shift_bikers for payment creation
        $shift->load('shiftBikers');

        $payoutService = $this->payoutService;
        $revenueService = $this->revenueService;

        foreach ($shift->shiftBikers as $shiftBiker) {
            $payout = $payoutService->calculate(
                $shiftBiker->base_fee,
                $shiftBiker->biker_rate,
                $shiftBiker->trips_count,
            );

            $revenue = $revenueService->calculate(
                $shift->restaurant_rate,
                $shiftBiker->trips_count,
                $payout,
            );

            // Create Payment row — idempotent guard via firstOrCreate
            Payment::firstOrCreate(
                ['shift_biker_id' => $shiftBiker->id],
                [
                    'amount' => $payout,
                    'revenue' => $revenue,
                    'status' => PaymentStatus::Pending,
                ],
            );
        }

        return $shift;
    }
}
