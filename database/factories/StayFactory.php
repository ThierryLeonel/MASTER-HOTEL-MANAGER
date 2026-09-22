<?php

namespace Database\Factories;

use App\Models\Guest;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class StayFactory extends Factory
{
    public function definition(): array
    {
        return [
            'reservation_id' => null,
            'guest_id' => Guest::factory(),
            'room_id' => Room::factory(),
            'check_in_at' => now()->subDay(),
            'expected_check_out_date' => now()->addDays(2)->toDateString(),
            'agreed_rate' => 30000,
            'status' => 'active',
            'checked_in_by' => User::factory(),
        ];
    }
}
