<?php

namespace Database\Factories;

use App\Models\Restaurant;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shift>
 */
class ShiftFactory extends Factory
{
    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'workflow_type' => 'live_tick',
            'status' => 'draft',
            'restaurant_rate' => '15.00',
        ];
    }

    public function started(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'open',
            'started_at' => now(),
        ]);
    }
}
