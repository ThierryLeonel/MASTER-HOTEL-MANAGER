<?php

namespace Database\Seeders;

use App\Models\EventSpace;
use Illuminate\Database\Seeder;

/**
 * Idempotent starter rows for the admin-editable event-space list,
 * clarified via AskUserQuestion ("Main Hall, Mini HALL, Conference halls,
 * etc you know better"). An admin can rename, add to, or deactivate any of
 * these from the Event Spaces screen at any time — this seeder only ever
 * guarantees they exist on first run (firstOrCreate, same pattern as
 * DepartmentSeeder above); it never overwrites a name/description an admin
 * has since edited.
 */
class EventSpaceSeeder extends Seeder
{
    public function run(): void
    {
        $spaces = [
            ['name' => 'Main Hall', 'description' => 'The hotel\'s largest indoor event venue.', 'max_capacity' => null],
            ['name' => 'Mini Hall', 'description' => 'A smaller indoor venue for more intimate gatherings.', 'max_capacity' => null],
            ['name' => 'Pool / Outdoor Area', 'description' => 'The pool and surrounding outdoor grounds.', 'max_capacity' => null],
            ['name' => 'Conference Hall', 'description' => 'Meeting/conference space.', 'max_capacity' => null],
        ];

        foreach ($spaces as $space) {
            EventSpace::firstOrCreate(['name' => $space['name']], $space);
        }
    }
}
