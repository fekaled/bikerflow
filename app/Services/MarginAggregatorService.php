<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Enums\ShiftStatus;
use App\Models\Shift;

/**
 * MarginAggregatorService — Phase 5A
 *
 * Aggregates closed shift financials for a given month to power the Admin Margin Dashboard.
 *
 * @see docs/plans/phase-5a-admin-margin-dashboard.md
 */
class MarginAggregatorService
{
    public function __construct(
        private readonly PayoutService $payoutService,
        private readonly RevenueService $revenueService,
    ) {
    }

    /**
     * Aggregate financial metrics for closed shifts in a given year/month.
     *
     * @return array{
     *     total_revenue: string,
     *     total_payout: string,
     *     net_margin: string,
     *     shift_count: int,
     *     paid_count: int,
     *     unpaid_count: int,
     *     payment_detail: array{paid: int, pending: int, failed: int, processing: int}
     * }
     */
    public function aggregate(int $year, int $month): array
    {
        $shifts = Shift::where('status', ShiftStatus::Closed)
            ->whereYear('closed_at', $year)
            ->whereMonth('closed_at', $month)
            ->with(['shiftBikers.payment'])
            ->get();

        $totalRevenue = '0.00';
        $totalPayout = '0.00';
        $shiftCount = 0;
        $paidCount = 0;
        $unpaidCount = 0;
        $paymentDetail = [
            'paid' => 0,
            'pending' => 0,
            'failed' => 0,
            'processing' => 0,
        ];

        foreach ($shifts as $shift) {
            $shiftCount++;
            // restaurant_rate is already cast as decimal:2
            $restaurantRate = $shift->restaurant_rate;

            foreach ($shift->shiftBikers as $shiftBiker) {
                // Per-biker payout via BR-03 formula
                $payout = $this->payoutService->calculate(
                    (string) $shiftBiker->base_fee,
                    (string) $shiftBiker->biker_rate,
                    (int) $shiftBiker->trips_count,
                );

                // Per-biker revenue: (restaurant_rate × trips) - payout
                $revenue = $this->revenueService->calculate(
                    (string) $restaurantRate,
                    (int) $shiftBiker->trips_count,
                    $payout,
                );

                // Accumulate with BCMath scale 2
                $totalPayout = bcadd($totalPayout, $payout, 2);
                $totalRevenue = bcadd($totalRevenue, $revenue, 2);

                // Count payment statuses (BR-04 granular failure)
                if ($shiftBiker->payment !== null) {
                    $status = $shiftBiker->payment->status;

                    if ($status === PaymentStatus::Paid) {
                        $paidCount++;
                        $paymentDetail['paid']++;
                    } elseif ($status === PaymentStatus::Pending) {
                        $unpaidCount++;
                        $paymentDetail['pending']++;
                    } elseif ($status === PaymentStatus::Failed) {
                        $unpaidCount++;
                        $paymentDetail['failed']++;
                    } elseif ($status === PaymentStatus::Processing) {
                        $unpaidCount++;
                        $paymentDetail['processing']++;
                    }
                }
            }
        }

        $netMargin = bcsub($totalRevenue, $totalPayout, 2);

        return [
            'total_revenue' => $totalRevenue,
            'total_payout' => $totalPayout,
            'net_margin' => $netMargin,
            'shift_count' => $shiftCount,
            'paid_count' => $paidCount,
            'unpaid_count' => $unpaidCount,
            'payment_detail' => $paymentDetail,
        ];
    }
}
