<?php

namespace App\Services;

class RevenueService
{
    /**
     * Calculate company revenue.
     *
     * Revenue = 0.00                                  when trips_count = 0
     * Revenue = (restaurant_rate × trips_count) - Payout  when trips_count > 0
     *
     * Revenue CAN be negative (loss scenario — valid business case).
     * All arithmetic uses BCMath with scale 2.
     *
     * @param  string  $restaurantRate  Snapshotted rate from shifts.restaurant_rate
     * @param  int  $tripsCount  Trips from shift_bikers
     * @param  string  $payout  Output of PayoutService::calculate()
     * @return string Revenue amount, exactly 2 decimal places
     */
    public function calculate(string $restaurantRate, int $tripsCount, string $payout): string
    {
        // Zero trips → zero revenue
        if ($tripsCount === 0) {
            return '0.00';
        }

        // Revenue = (restaurant_rate × trips_count) - Payout
        $gross = bcmul($restaurantRate, (string) $tripsCount, 2);
        $revenue = bcsub($gross, $payout, 2);

        return $revenue;
    }
}
