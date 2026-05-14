<?php

namespace Database\Factories;

use App\Models\Biker;
use App\Models\PixKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PixKey>
 */
class PixKeyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'biker_id' => Biker::factory(),
            'key_type' => 'cpf',
            'key_value' => fake()->unique()->numerify('###########'),
            'is_verified' => false,
        ];
    }
}
