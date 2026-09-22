<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class PosTableFactory extends Factory
{
    public function definition(): array
    {
        return [
            'outlet' => fake()->randomElement(['bar', 'restaurant']),
            'label' => 'T'.fake()->unique()->numberBetween(1, 999),
            'seats' => fake()->numberBetween(2, 6),
            'status' => 'available',
            'is_active' => true,
        ];
    }
}
