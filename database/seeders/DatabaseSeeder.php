<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Safe staging/demo data only (spec section 40) — no fabricated
     * financial history. Order matters: roles/permissions and
     * departments before users; room categories before rooms; users
     * before guests (for created_by); PosSeeder before InventorySeeder
     * (recipes link to menu items that must already exist).
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            DepartmentSeeder::class,
            SettingsSeeder::class,
            UserSeeder::class,
            RoomSeeder::class,
            GuestSeeder::class,
            PosSeeder::class,
            FacilitySeeder::class,
            FinancialAccountSeeder::class,
            WebsiteBookingSeeder::class,
            InventorySeeder::class,
            HrSeeder::class,
        ]);
    }
}
