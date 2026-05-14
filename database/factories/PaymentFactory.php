<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\ShiftBiker;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'shift_biker_id' => ShiftBiker::factory(),
            'amount' => '0.00',
            'status' => 'pending',
        ];
    }
}
