<?php

namespace App\Services;

class PayoutService
{
    /**
     * Calculate biker payout per BR-03 formula.
     *
     * Payout = 0.00                                    when trips_count = 0
     * Payout = base_fee + (biker_rate × trips_count)  when trips_count > 0
     *
     * All arithmetic uses BCMath with scale 2.
     *
     * @param  string  $baseFee  Snapshotted base_fee from shift_bikers
     * @param  string  $bikerRate  Snapshotted biker_rate from shift_bikers
     * @param  int  $tripsCount  Current trips_count from shift_bikers
     * @return string Payout amount, exactly 2 decimal places
     *
     * @throws \InvalidArgumentException if tripsCount < 0
     */
    public function calculate(string $baseFee, string $bikerRate, int $tripsCount): string
    {
        if ($tripsCount < 0) {
            throw new \InvalidArgumentException(
                "tripsCount must be >= 0, got: {$tripsCount}"
            );
        }

        // BR-03: zero trips → zero payout (base fee is NOT paid)
        if ($tripsCount === 0) {
            return '0.00';
        }

        // BR-03: Payout = base_fee + (biker_rate × trips_count)
        $tripTotal = bcmul($bikerRate, (string) $tripsCount, 2);
        $payout = bcadd($baseFee, $tripTotal, 2);

        return $payout;
    }
}
