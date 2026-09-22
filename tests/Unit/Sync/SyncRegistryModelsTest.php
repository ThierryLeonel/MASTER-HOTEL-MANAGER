<?php

namespace Tests\Unit\Sync;

use App\Models\Guest;
use App\Models\SyncBatch;
use App\Models\SyncCheckpoint;
use App\Models\SyncConflict;
use App\Models\SyncDevice;
use App\Models\SyncItem;
use App\Models\SyncLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 9.7.2 (PHASE9_AUDIT_AND_PLAN.md §6, Phase 9.7 sub-plan). Builds
 * one row of each of the 6 new sync registry tables and confirms the
 * relations between them resolve correctly — the same shape the future
 * SyncService (Phase 9.7.3) and sync dashboard (Phase 9.7.6) will
 * traverse (device -> batch -> item -> conflict).
 */
class SyncRegistryModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_device_has_checkpoints_and_batches(): void
    {
        $device = SyncDevice::create([
            'name' => 'Cloud VPS', 'device_key' => (string) Str::ulid(), 'shared_secret' => 'x', 'is_active' => true,
        ]);

        $checkpoint = SyncCheckpoint::create([
            'sync_device_id' => $device->id, 'direction' => 'outbound', 'last_synced_at' => now(),
        ]);

        $batch = SyncBatch::create([
            'ulid' => (string) Str::ulid(), 'sync_device_id' => $device->id, 'direction' => 'outbound',
            'status' => 'pending', 'item_count' => 0,
        ]);

        $this->assertTrue($device->checkpoints->contains($checkpoint));
        $this->assertTrue($device->batches->contains($batch));
        $this->assertTrue($checkpoint->device->is($device));
        $this->assertTrue($batch->device->is($device));
    }

    public function test_checkpoint_for_helper_finds_the_right_direction(): void
    {
        $device = SyncDevice::create([
            'name' => 'Cloud VPS', 'device_key' => (string) Str::ulid(), 'shared_secret' => 'x', 'is_active' => true,
        ]);
        SyncCheckpoint::create(['sync_device_id' => $device->id, 'direction' => 'outbound', 'last_synced_at' => now()->subDay()]);
        $inbound = SyncCheckpoint::create(['sync_device_id' => $device->id, 'direction' => 'inbound', 'last_synced_at' => now()]);

        $found = $device->checkpointFor('inbound');

        $this->assertNotNull($found);
        $this->assertTrue($found->is($inbound));
    }

    public function test_a_batch_has_items_and_an_item_belongs_to_its_batch(): void
    {
        $device = SyncDevice::create([
            'name' => 'Cloud VPS', 'device_key' => (string) Str::ulid(), 'shared_secret' => 'x', 'is_active' => true,
        ]);
        $batch = SyncBatch::create([
            'ulid' => (string) Str::ulid(), 'sync_device_id' => $device->id, 'direction' => 'inbound',
            'status' => 'pending', 'item_count' => 1,
        ]);
        $guest = Guest::factory()->create();

        $item = SyncItem::create([
            'sync_batch_id' => $batch->id, 'syncable_type' => Guest::class, 'syncable_ulid' => $guest->ulid,
            'operation' => 'update', 'status' => 'pending',
        ]);

        $this->assertTrue($batch->items->contains($item));
        $this->assertTrue($item->batch->is($batch));
        $this->assertFalse($item->hasConflict());
    }

    public function test_an_item_can_have_a_conflict_and_it_resolves_correctly(): void
    {
        $device = SyncDevice::create([
            'name' => 'Cloud VPS', 'device_key' => (string) Str::ulid(), 'shared_secret' => 'x', 'is_active' => true,
        ]);
        $batch = SyncBatch::create([
            'ulid' => (string) Str::ulid(), 'sync_device_id' => $device->id, 'direction' => 'inbound',
            'status' => 'pending', 'item_count' => 1,
        ]);
        $guest = Guest::factory()->create();
        $item = SyncItem::create([
            'sync_batch_id' => $batch->id, 'syncable_type' => Guest::class, 'syncable_ulid' => $guest->ulid,
            'operation' => 'update', 'status' => 'conflict',
        ]);
        $manager = User::factory()->create();

        $conflict = SyncConflict::create([
            'sync_item_id' => $item->id, 'syncable_type' => Guest::class, 'syncable_ulid' => $guest->ulid,
            'local_data' => ['first_name' => 'Local'], 'incoming_data' => ['first_name' => 'Remote'],
            'detected_at' => now(),
        ]);

        $this->assertTrue($item->hasConflict());
        $this->assertTrue($item->conflict->is($conflict));
        $this->assertFalse($conflict->isResolved());

        $conflict->update(['resolved_at' => now(), 'resolved_by' => $manager->id, 'resolution' => 'kept_local']);

        $this->assertTrue($conflict->fresh()->isResolved());
        $this->assertTrue($conflict->fresh()->resolvedBy->is($manager));
    }

    public function test_a_sync_log_can_be_created_with_or_without_a_device(): void
    {
        $device = SyncDevice::create([
            'name' => 'Cloud VPS', 'device_key' => (string) Str::ulid(), 'shared_secret' => 'x', 'is_active' => true,
        ]);

        $withDevice = SyncLog::create([
            'sync_device_id' => $device->id, 'level' => 'info', 'event' => 'batch_sent',
            'message' => 'Sent 3 items.', 'created_at' => now(),
        ]);
        $withoutDevice = SyncLog::create([
            'level' => 'info', 'event' => 'device_registered', 'message' => 'Registered Local Server.',
            'created_at' => now(),
        ]);

        $this->assertTrue($withDevice->device->is($device));
        $this->assertNull($withoutDevice->device);
    }
}
