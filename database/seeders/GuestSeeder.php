<?php

namespace Database\Seeders;

use App\Models\Guest;
use App\Models\User;
use Illuminate\Database\Seeder;

class GuestSeeder extends Seeder
{
    public function run(): void
    {
        $creator = User::where('email', 'frontdesk1@hms.test')->first();

        $guests = [
            ['first_name' => 'Jean', 'last_name' => 'Mbarga', 'phone' => '+237670000001', 'email' => 'jean.mbarga@example.com', 'nationality' => 'Cameroonian'],
            ['first_name' => 'Aisha', 'last_name' => 'Bello', 'phone' => '+237670000002', 'email' => 'aisha.bello@example.com', 'nationality' => 'Nigerian'],
            ['first_name' => 'Pierre', 'last_name' => 'Dubois', 'phone' => '+33600000003', 'email' => 'pierre.dubois@example.com', 'nationality' => 'French'],
        ];

        foreach ($guests as $guest) {
            Guest::firstOrCreate(
                ['phone' => $guest['phone']],
                [...$guest, 'created_by' => $creator?->id],
            );
        }
    }
}
