<?php

namespace Tests\Unit\Sync;

use App\Exceptions\HmsException;
use App\Models\Department;
use App\Models\SyncBatch;
use App\Models\SyncConflict;
use App\Models\SyncDevice;
use App\Models\SyncItem;
use App\Models\User;
use App\Services\Sync\SyncConflictResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 9.7.6 (PHASE9_AUDIT_AND_PLAN.md §6, Phase 9.7 sub-plan). Exercises
 * every one of the four resolutions directly against SyncConflictResolver
 * — the sync dashboard controller is a thin wrapper around this, tested
 * separately in tests/Feature/Admin/SyncDashboardTest.php.
 */
class SyncConflictResolverTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): SyncConflictResolver
    {
        return app(SyncConflictResolver::class);
    }

    private function conflictOn(Department $department, array $incoming): SyncConflict
    {
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
            'incoming_data' => $incoming,
            'detected_at' => now(),
        ]);
    }

    public function test_kept_local_marks_resolved_without_changing_the_record(): void
    {
        $department = Department::create(['name' => 'Local Name', 'code' => 'LOC', 'is_active' => true]);
        $conflict = $this->conflictOn($department, ['name' => 'Incoming Name', 'code' => 'LOC', 'is_active' => true]);
        $actor = User::factory()->create();

        $resolved = $this->resolver()->resolve($conflict, 'kept_local', $actor, 'keeping ours');

        $this->assertTrue($resolved->isResolved());
        $this->assertSame('kept_local', $resolved->resolution);
        $this->assertSame($actor->id, $resolved->resolved_by);
        $this->assertSame('Local Name', $department->fresh()->name);
    }

    public function test_kept_incoming_overwrites_the_local_record_with_the_held_back_data(): void
    {
        $department = Department::create(['name' => 'Local Name', 'code' => 'LOC', 'is_active' => true]);
        $conflict = $this->conflictOn($department, ['name' => 'Incoming Name', 'code' => 'LOC', 'is_active' => true]);
        $actor = User::factory()->create();

        $this->resolver()->resolve($conflict, 'kept_incoming', $actor);

        $this->assertSame('Incoming Name', $department->fresh()->name);
    }

    public function test_kept_incoming_never_overwrites_the_id_or_ulid(): void
    {
        $department = Department::create(['name' => 'Local Name', 'code' => 'LOC', 'is_active' => true]);
        $originalUlid = $department->ulid;
        $conflict = $this->conflictOn($department, [
            'id' => 999999, 'ulid' => (string) Str::ulid(), 'name' => 'Incoming Name', 'code' => 'LOC', 'is_active' => true,
        ]);

        $this->resolver()->resolve($conflict, 'kept_incoming', User::factory()->create());

        $fresh = $department->fresh();
        $this->assertSame($department->id, $fresh->id);
        $this->assertSame($originalUlid, $fresh->ulid);
    }

    public function test_merged_and_ignored_also_mark_resolved_without_changing_the_record(): void
    {
        $department = Department::create(['name' => 'Local Name', 'code' => 'LOC', 'is_active' => true]);
        $conflictA = $this->conflictOn($department, ['name' => 'X']);
        $conflictB = $this->conflictOn($department, ['name' => 'Y']);
        $actor = User::factory()->create();

        $this->resolver()->resolve($conflictA, 'merged', $actor, 'reconciled by hand in tinker');
        $this->resolver()->resolve($conflictB, 'ignored', $actor);

        $this->assertSame('merged', $conflictA->fresh()->resolution);
        $this->assertSame('ignored', $conflictB->fresh()->resolution);
        $this->assertSame('Local Name', $department->fresh()->name);
    }

    public function test_an_already_resolved_conflict_cannot_be_resolved_again(): void
    {
        $department = Department::create(['name' => 'Local Name', 'code' => 'LOC', 'is_active' => true]);
        $conflict = $this->conflictOn($department, ['name' => 'X']);
        $actor = User::factory()->create();

        $this->resolver()->resolve($conflict, 'kept_local', $actor);

        $this->expectException(HmsException::class);
        $this->resolver()->resolve($conflict->fresh(), 'kept_incoming', $actor);
    }

    public function test_an_unknown_resolution_value_is_rejected(): void
    {
        $department = Department::create(['name' => 'Local Name', 'code' => 'LOC', 'is_active' => true]);
        $conflict = $this->conflictOn($department, ['name' => 'X']);

        $this->expectException(HmsException::class);
        $this->resolver()->resolve($conflict, 'flip_a_coin', User::factory()->create());
    }

    public function test_kept_incoming_fails_loudly_if_the_local_record_no_longer_exists(): void
    {
        $department = Department::create(['name' => 'Local Name', 'code' => 'LOC', 'is_active' => true]);
        $conflict = $this->conflictOn($department, ['name' => 'X']);
        $department->delete(); // Department has no SoftDeletes — this is a real hard delete.

        $this->expectException(HmsException::class);
        $this->resolver()->resolve($conflict, 'kept_incoming', User::factory()->create());
    }

    /**
     * Security re-audit finding (PHASE9_AUDIT_AND_PLAN.md §9.8, fixed):
     * a User conflict's held-back incoming_data must never restore a
     * credential column via kept_incoming — belt-and-braces alongside
     * exportableAttributes() (which stops the data reaching
     * incoming_data in the first place) and applyUpsert()'s own exclusion.
     */
    public function test_kept_incoming_on_a_user_conflict_never_overwrites_the_password(): void
    {
        $subject = User::factory()->create(['name' => 'Original Name', 'password' => bcrypt('original-password')]);
        $originalHash = $subject->password;

        $device = SyncDevice::create(['name' => 'Cloud VPS', 'device_key' => (string) Str::ulid(), 'shared_secret' => 'x', 'is_active' => true]);
        $batch = SyncBatch::create([
            'ulid' => (string) Str::ulid(), 'sync_device_id' => $device->id, 'direction' => 'inbound',
            'status' => 'failed', 'item_count' => 1, 'payload' => [],
        ]);
        $item = SyncItem::create([
            'sync_batch_id' => $batch->id, 'syncable_type' => User::class,
            'syncable_ulid' => $subject->ulid, 'operation' => 'update', 'status' => 'conflict',
        ]);
        // Simulating a payload that (through a bug elsewhere, or an
        // adversarial sender) still somehow contains a credential key —
        // this resolver must be the final backstop regardless.
        $conflict = SyncConflict::create([
            'sync_item_id' => $item->id,
            'syncable_type' => User::class,
            'syncable_ulid' => $subject->ulid,
            'local_data' => $subject->getAttributes(),
            'incoming_data' => ['name' => 'Updated Via Conflict', 'password' => bcrypt('attacker-password')],
            'detected_at' => now(),
        ]);

        $this->resolver()->resolve($conflict, 'kept_incoming', User::factory()->create());

        $fresh = $subject->fresh();
        $this->assertSame('Updated Via Conflict', $fresh->name);
        $this->assertSame($originalHash, $fresh->password);
    }
}
