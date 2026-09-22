<?php

namespace Tests\Feature\Admin;

use App\Models\Department;
use App\Models\SyncBatch;
use App\Models\SyncConflict;
use App\Models\SyncDevice;
use App\Models\SyncItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Phase 9.7.6 (PHASE9_AUDIT_AND_PLAN.md §6, Phase 9.7 sub-plan). Confirms
 * the sync dashboard is wired to real data (not a mock) and gated on
 * `manage-sync`, and that resolving a conflict through the HTTP endpoint
 * actually reaches SyncConflictResolver (unit-tested directly in
 * SyncConflictResolverTest) rather than duplicating its logic here.
 */
class SyncDashboardTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    private function conflict(): SyncConflict
    {
        $department = Department::create(['name' => 'Local Name', 'code' => 'LOC', 'is_active' => true]);
        $device = SyncDevice::create(['name' => 'Cloud VPS', 'device_key' => (string) Str::ulid(), 'shared_secret' => 'x', 'is_active' => true]);
        $batch = SyncBatch::create([
            'ulid' => (string) Str::ulid(), 'sync_device_id' => $device->id, 'direction' => 'inbound',
            'status' => 'failed', 'item_count' => 1, 'payload' => [],
        ]);
        $item = SyncItem::create([
            'sync_batch_id' => $batch->id, 'syncable_type' => Department::class,
            'syncable_ulid' => $department->ulid, 'operation' => 'update', 'status' => 'conflict',
        ]);

        return SyncConflict::create([
            'sync_item_id' => $item->id,
            'syncable_type' => Department::class,
            'syncable_ulid' => $department->ulid,
            'local_data' => $department->getAttributes(),
            'incoming_data' => ['name' => 'Incoming Name', 'code' => 'LOC', 'is_active' => true],
            'detected_at' => now(),
        ]);
    }

    public function test_owner_can_view_the_sync_status_page_with_a_registered_device(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        SyncDevice::create(['name' => 'Cloud VPS', 'device_key' => (string) Str::ulid(), 'shared_secret' => 'x', 'is_active' => true]);

        $this->actingAs($owner)->get(route('admin.sync.index'))
            ->assertOk()
            ->assertSee('Cloud VPS');
    }

    public function test_the_conflicts_page_lists_an_unresolved_conflict(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $this->conflict();

        $this->actingAs($owner)->get(route('admin.sync.conflicts'))
            ->assertOk()
            ->assertSee('Department')
            ->assertSee('Incoming Name');
    }

    public function test_resolving_a_conflict_applies_the_incoming_version_and_removes_it_from_the_unresolved_list(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $conflict = $this->conflict();

        $this->actingAs($owner)->post(route('admin.sync.conflicts.resolve', $conflict), [
            'resolution' => 'kept_incoming',
        ])->assertRedirect(route('admin.sync.conflicts'));

        $this->assertNotNull($conflict->fresh()->resolved_at);
        $this->assertSame('Incoming Name', Department::whereUlid($conflict->syncable_ulid)->first()->name);

        $this->actingAs($owner)->get(route('admin.sync.conflicts'))->assertDontSee('Incoming Name');
    }

    public function test_duty_manager_can_reach_the_dashboard_but_front_desk_cannot(): void
    {
        $dutyManager = $this->userWithRole('duty_manager', ['auth_type' => 'password_only']);
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'pin', 'password' => null, 'pin_hash' => bcrypt('1234')]);

        $this->actingAs($dutyManager)->get(route('admin.sync.index'))->assertOk();
        $this->actingAs($frontDesk)->get(route('admin.sync.index'))->assertForbidden();
    }

    /** @dataProvider rolesWithoutManageSync */
    public function test_roles_without_manage_sync_are_forbidden(string $role): void
    {
        $user = $this->userWithRole($role, ['auth_type' => 'password_only']);

        $this->actingAs($user)->get(route('admin.sync.index'))->assertForbidden();
    }

    public static function rolesWithoutManageSync(): array
    {
        return [['accountant'], ['cashier'], ['front_desk'], ['bar_staff'], ['restaurant_staff']];
    }
}
