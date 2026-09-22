<?php

namespace Tests\Unit;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Follow-up fix ("the front desk can still not update reservation
 * status" — PHASE9_AUDIT_AND_PLAN.md §21 root-cause note): Spatie caches
 * the whole role->permission map (config/permission.php's
 * `cache.expiration_time`, 24 hours by default). Re-running
 * RolesAndPermissionsSeeder used to update the `roles`/`permissions`
 * tables but never clear that cache, so a live site kept answering
 * `$user->can(...)` from the stale cached map — a grant added to the
 * seeder could be correctly in the database and still not "work" until
 * the cache separately expired. This test proves the seeder itself now
 * clears it, directly, every time it runs — not just via the separate
 * `hms:check-access` diagnostic command, which only ever worked around
 * the symptom.
 */
class RolesAndPermissionsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_running_the_seeder_clears_the_spatie_permission_cache(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        // Warm the cache exactly the way a real permission check does.
        app(PermissionRegistrar::class)->getPermissions();
        $this->assertTrue(Cache::has(config('permission.cache.key')), 'Test setup: the cache should be warm before re-seeding.');

        // Re-seeding (exactly what redeploying / re-running the seeder in
        // production does) must leave the cache cleared, not stale.
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertFalse(
            Cache::has(config('permission.cache.key')),
            'RolesAndPermissionsSeeder must clear the Spatie permission cache on every run, so a grant it just changed takes effect immediately instead of waiting for the cache to separately expire.'
        );
    }
}
