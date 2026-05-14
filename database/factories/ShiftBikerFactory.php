<?php

namespace Database\Factories;

use App\Models\Biker;
use App\Models\Shift;
use App\Models\ShiftBiker;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShiftBiker>
 */
class ShiftBikerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'shift_id' => Shift::factory(),
            'biker_id' => Biker::factory(),
            'trips_count' => 0,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ];
    }
}
