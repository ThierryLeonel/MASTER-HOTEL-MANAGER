<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class MenuCategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'outlet' => fake()->randomElement(['bar', 'restaurant']),
            'name' => fake()->unique()->words(2, true),
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    public function bar(): static
    {
        return $this->state(fn () => ['outlet' => 'bar']);
    }

    public function restaurant(): static
    {
        return $this->state(fn () => ['outlet' => 'restaurant']);
    }

    public function frontDesk(): static
    {
        return $this->state(fn () => ['outlet' => 'front_desk']);
    }
}
