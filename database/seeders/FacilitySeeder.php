<?php

namespace Database\Seeders;

use App\Models\Facility;
use Illuminate\Database\Seeder;

/**
 * Post-9.8 second fix round (PHASE9_AUDIT_AND_PLAN.md §20). Seeds Gym
 * and Pool with one starter rate each so the feature is immediately
 * usable; these are demo/default amounts only (spec section 40 — never
 * fake financial history) and should be reviewed and adjusted from the
 * Facilities screen ('manage-facilities') before real use.
 */
class FacilitySeeder extends Seeder
{
    public function run(): void
    {
        $gym = Facility::firstOrCreate(
            ['code' => 'gym'],
            ['name' => 'Gym', 'description' => 'Guests: free access. Walk-ins: pay a registration fee at the front desk.'],
        );
        $gym->rates()->firstOrCreate(['name' => 'Gym Registration'], ['amount' => 5000, 'is_active' => true]);

        $pool = Facility::firstOrCreate(
            ['code' => 'pool'],
            ['name' => 'Swimming Pool', 'description' => 'Guests: free access. Walk-ins: pay per slot at the front desk before entry.'],
        );
        $pool->rates()->firstOrCreate(['name' => 'Pool Slot'], ['amount' => 2000, 'is_active' => true]);
    }
}
