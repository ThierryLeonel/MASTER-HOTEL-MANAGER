<?php

namespace Database\Factories;

use App\Models\Stay;
use Illuminate\Database\Eloquent\Factories\Factory;

class FolioFactory extends Factory
{
    public function definition(): array
    {
        return [
            'stay_id' => Stay::factory(),
            'status' => 'open',
            'balance' => 0,
        ];
    }
}
