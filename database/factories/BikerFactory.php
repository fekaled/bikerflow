<?php

namespace Database\Factories;

use App\Models\Biker;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Biker>
 */
class BikerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => fake()->unique()->numerify('11#########'),
            'rate_per_trip' => '10.00',
            'base_fee' => '25.00',
            'active' => true,
        ];
    }
}
