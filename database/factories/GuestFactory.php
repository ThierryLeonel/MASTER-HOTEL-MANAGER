<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class GuestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone' => fake()->unique()->numerify('+2376########'),
            'email' => fake()->unique()->safeEmail(),
            'nationality' => fake()->country(),
            'is_blacklisted' => false,
        ];
    }
}
