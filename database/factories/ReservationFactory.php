<?php

namespace Database\Factories;

use App\Models\Guest;
use App\Models\RoomCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ReservationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'reservation_number' => 'RSV-'.now()->format('Ymd').'-'.strtoupper(Str::random(5)),
            'guest_id' => Guest::factory(),
            'room_category_id' => RoomCategory::factory(),
            'room_id' => null,
            'check_in_date' => now()->addDays(2)->toDateString(),
            'check_out_date' => now()->addDays(4)->toDateString(),
            'adults' => 1,
            'children' => 0,
            'status' => 'pending',
            'rate_quoted' => 30000,
            'deposit_required' => 0,
            'deposit_paid' => 0,
            'requested_by' => User::factory(),
        ];
    }
}
