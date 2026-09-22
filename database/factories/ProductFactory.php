<?php

namespace Database\Factories;

use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'sku' => strtoupper(fake()->unique()->bothify('SKU-####')),
            'name' => fake()->unique()->words(3, true),
            'unit_id' => Unit::factory(),
            'category' => fake()->randomElement(['Beverages', 'Food', 'Supplies']),
            'reorder_level' => 10,
            'is_active' => true,
        ];
    }
}
