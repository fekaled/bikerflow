<?php

namespace Database\Factories;

use App\Models\PixWebhookLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PixWebhookLog>
 */
class PixWebhookLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'gateway_transaction_id' => 'mock-txn-'.$this->faker->uuid(),
            'payload' => [
                'transaction_id' => $this->faker->uuid(),
                'status' => $this->faker->randomElement(['processed', 'failed']),
                'amount' => $this->faker->randomFloat(2, 10, 100),
                'pix_key' => $this->faker->numerify('119########'),
                'error_code' => null,
                'error_message' => null,
                'timestamp' => now()->toIso8601String(),
            ],
            'status' => 'processed',
            'error_message' => null,
            'ip_address' => $this->faker->ipv4(),
            'received_at' => now(),
        ];
    }

    /**
     * Create a log for a successful webhook.
     */
    public function processed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'processed',
            'error_message' => null,
        ]);
    }

    /**
     * Create a log for a failed webhook.
     */
    public function failed(string $errorMessage = 'Test error'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'processed',
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Create a log for a duplicate webhook.
     */
    public function duplicate(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'duplicate',
            'error_message' => 'Payment already in terminal status',
        ]);
    }

    /**
     * Create a log for an ignored webhook.
     */
    public function ignored(string $reason = 'Payment not found'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'ignored',
            'error_message' => $reason,
        ]);
    }
}
