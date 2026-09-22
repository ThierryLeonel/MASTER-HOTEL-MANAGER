<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class UnitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['Bottle', 'Kilogram', 'Litre', 'Piece', 'Carton', 'Crate']),
            'abbreviation' => fake()->unique()->lexify('???'),
        ];
    }
}
