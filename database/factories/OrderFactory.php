<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class OrderFactory extends Factory
{
    public function definition(): array
    {
        $outlet = fake()->randomElement(['bar', 'restaurant']);

        return [
            'order_number' => strtoupper(substr($outlet, 0, 3)).'-'.now()->format('Ymd').'-'.strtoupper(Str::random(5)),
            'outlet' => $outlet,
            'status' => 'draft',
            'opened_by' => User::factory(),
        ];
    }
}
