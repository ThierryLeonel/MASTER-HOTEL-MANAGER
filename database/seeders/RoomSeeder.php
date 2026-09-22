<?php

namespace Database\Seeders;

use App\Models\Room;
use App\Models\RoomCategory;
use Illuminate\Database\Seeder;

class RoomSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Standard', 'max_occupancy' => 2, 'base_rate' => 25000, 'rooms' => ['101', '102', '103', '104']],
            ['name' => 'Deluxe', 'max_occupancy' => 2, 'base_rate' => 40000, 'rooms' => ['201', '202', '203']],
            ['name' => 'Suite', 'max_occupancy' => 4, 'base_rate' => 75000, 'rooms' => ['301', '302']],
            ['name' => 'Apartment', 'max_occupancy' => 6, 'base_rate' => 110000, 'rooms' => ['401']],
        ];

        foreach ($categories as $definition) {
            $category = RoomCategory::firstOrCreate(
                ['name' => $definition['name']],
                ['max_occupancy' => $definition['max_occupancy'], 'base_rate' => $definition['base_rate']],
            );

            foreach ($definition['rooms'] as $roomNumber) {
                Room::firstOrCreate(
                    ['room_number' => $roomNumber],
                    ['room_category_id' => $category->id, 'floor' => substr($roomNumber, 0, 1)],
                );
            }
        }
    }
}
