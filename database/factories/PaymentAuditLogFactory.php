<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\PaymentAuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentAuditLog>
 */
class PaymentAuditLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'action' => 'create',
            'transaction_ref' => fake()->unique()->uuid(),
        ];
    }
}
