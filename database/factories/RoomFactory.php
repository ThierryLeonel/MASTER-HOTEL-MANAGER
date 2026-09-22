<?php

namespace Database\Factories;

use App\Models\RoomCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class RoomFactory extends Factory
{
    public function definition(): array
    {
        return [
            'room_number' => fake()->unique()->numerify('###'),
            'room_category_id' => RoomCategory::factory(),
            'floor' => fake()->numberBetween(1, 5),
            'status' => 'available',
            'is_active' => true,
        ];
    }

    public function occupied(): static
    {
        return $this->state(fn () => ['status' => 'occupied']);
    }

    public function maintenance(): static
    {
        return $this->state(fn () => ['status' => 'maintenance']);
    }
}
