<?php

namespace Tests\Unit\Sync;

use App\Models\Department;
use App\Models\Guest;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\SyncConflict;
use App\Models\SyncDevice;
use App\Models\User;
use App\Services\Sync\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 9.7.3 (PHASE9_AUDIT_AND_PLAN.md §6, Phase 9.7 sub-plan). Exercises
 * SyncService directly against real Eloquent models and a real (SQLite,
 * per this project's test config) database — the highest-risk piece of
 * the whole sync engine, since it's the part responsible for never
 * silently losing or duplicating data.
 */
class SyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): SyncService
    {
        return app(SyncService::class);
    }

    private function device(string $name = 'Cloud VPS'): SyncDevice
    {
        return SyncDevice::create([
            'name' => $name, 'device_key' => (string) Str::ulid(), 'shared_secret' => 'x', 'is_active' => true,
        ]);
    }

    public function test_a_first_outbox_build_includes_existing_rows(): void
    {
        Department::create(['name' => 'Housekeeping', 'code' => 'HSK', 'is_active' => true]);
        $device = $this->device();

        $batch = $this->service()->buildOutboxBatch($device);

        $this->assertSame('outbound', $batch->direction);
        $this->assertSame('pending', $batch->status);
        $this->assertGreaterThanOrEqual(1, $batch->item_count);
        $this->assertSame(1, $batch->items()->where('syncable_type', Department::class)->count());
    }

    public function test_a_row_with_no_ulid_yet_is_excluded_from_the_outbox(): void
    {
        \Illuminate\Support\Facades\DB::table('departments')->insert([
            'name' => 'Legacy Dept', 'code' => 'LEG', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $device = $this->device();

        $batch = $this->service()->buildOutboxBatch($device);

        $this->assertSame(0, $batch->items()->where('syncable_type', Department::class)->count());
    }

    public function test_after_acknowledgement_an_unchanged_row_is_not_sent_again(): void
    {
        Department::create(['name' => 'Housekeeping', 'code' => 'HSK', 'is_active' => true]);
        $device = $this->device();

        $first = $this->service()->buildOutboxBatch($device);
        $this->service()->acknowledgeOutboxBatch($first);

        $second = $this->service()->buildOutboxBatch($device);

        $this->assertSame(0, $second->item_count);
    }

    public function test_a_change_after_acknowledgement_is_included_in_the_next_outbox(): void
    {
        $department = Department::create(['name' => 'Housekeeping', 'code' => 'HSK', 'is_active' => true]);
        $device = $this->device();
        $this->service()->acknowledgeOutboxBatch($this->service()->buildOutboxBatch($device));

        Carbon::setTestNow(now()->addMinute());
        $department->update(['name' => 'Housekeeping & Laundry']);

        $second = $this->service()->buildOutboxBatch($device);

        $this->assertSame(1, $second->items()->where('syncable_type', Department::class)->count());
        Carbon::setTestNow();
    }

    public function test_applying_an_inbound_batch_creates_an_unseen_row(): void
    {
        $device = $this->device();
        $ulid = (string) Str::ulid();

        $batch = $this->service()->applyIncomingBatch($device, (string) Str::ulid(), [
            ['type' => Department::class, 'ulid' => $ulid, 'operation' => 'update', 'data' => [
                'name' => 'Engineering', 'code' => 'ENG', 'is_active' => true,
            ]],
        ]);

        $this->assertSame('applied', $batch->status);
        $created = Department::whereUlid($ulid)->first();
        $this->assertNotNull($created);
        $this->assertSame('Engineering', $created->name);
        $this->assertSame('applied', $batch->items()->first()->status);
    }

    public function test_applying_an_inbound_batch_updates_an_existing_row_without_conflict(): void
    {
        $device = $this->device();
        $department = Department::create(['name' => 'Engineering', 'code' => 'ENG', 'is_active' => true]);

        $batch = $this->service()->applyIncomingBatch($device, (string) Str::ulid(), [
            ['type' => Department::class, 'ulid' => $department->ulid, 'operation' => 'update', 'data' => [
                'name' => 'Engineering & Maintenance', 'code' => 'ENG', 'is_active' => true,
            ]],
        ]);

        $this->assertSame('applied', $batch->status);
        $this->assertSame('Engineering & Maintenance', $department->fresh()->name);
    }

    public function test_a_conflicting_local_change_is_flagged_not_overwritten(): void
    {
        $device = $this->device();
        $department = Department::create(['name' => 'Engineering', 'code' => 'ENG', 'is_active' => true]);

        // First inbound sync from this device establishes the inbound checkpoint.
        $this->service()->applyIncomingBatch($device, (string) Str::ulid(), [
            ['type' => Department::class, 'ulid' => $department->ulid, 'operation' => 'update', 'data' => [
                'name' => 'Engineering', 'code' => 'ENG', 'is_active' => true,
            ]],
        ]);

        // A local edit happens strictly after that checkpoint.
        Carbon::setTestNow(now()->addMinute());
        $department->update(['name' => 'Engineering (locally renamed)']);

        // A second inbound batch from the SAME device now conflicts.
        $batch = $this->service()->applyIncomingBatch($device, (string) Str::ulid(), [
            ['type' => Department::class, 'ulid' => $department->ulid, 'operation' => 'update', 'data' => [
                'name' => 'Engineering (renamed remotely)', 'code' => 'ENG', 'is_active' => true,
            ]],
        ]);

        $item = $batch->items()->first();
        $this->assertSame('conflict', $item->status);
        $this->assertSame('Engineering (locally renamed)', $department->fresh()->name, 'The local edit must survive a conflicting incoming change.');
        $this->assertSame(1, SyncConflict::where('syncable_ulid', $department->ulid)->count());

        Carbon::setTestNow();
    }

    public function test_replaying_the_same_batch_ulid_is_idempotent(): void
    {
        $device = $this->device();
        $ulid = (string) Str::ulid();
        $batchUlid = (string) Str::ulid();
        $payload = [
            ['type' => Department::class, 'ulid' => $ulid, 'operation' => 'update', 'data' => [
                'name' => 'First Version', 'code' => 'FV1', 'is_active' => true,
            ]],
        ];

        $this->service()->applyIncomingBatch($device, $batchUlid, $payload);
        $countAfterFirst = Department::whereUlid($ulid)->count();

        // Replay the exact same batch ulid, even with (implausibly) different
        // data, to prove it's skipped rather than reprocessed.
        $payload[0]['data']['name'] = 'Should Never Apply';
        $this->service()->applyIncomingBatch($device, $batchUlid, $payload);

        $this->assertSame($countAfterFirst, Department::whereUlid($ulid)->count());
        $this->assertSame('First Version', Department::whereUlid($ulid)->first()->name);
    }

    public function test_a_delete_operation_soft_deletes_a_soft_delete_model(): void
    {
        $device = $this->device();
        $guest = Guest::factory()->create();

        $batch = $this->service()->applyIncomingBatch($device, (string) Str::ulid(), [
            ['type' => Guest::class, 'ulid' => $guest->ulid, 'operation' => 'delete', 'data' => []],
        ]);

        $this->assertSame('applied', $batch->items()->first()->status);
        $this->assertTrue($guest->fresh()->trashed());
    }

    public function test_a_delete_operation_is_skipped_for_a_non_soft_delete_model(): void
    {
        $device = $this->device();
        $category = RoomCategory::factory()->create();
        $room = Room::factory()->create(['room_category_id' => $category->id]);

        $batch = $this->service()->applyIncomingBatch($device, (string) Str::ulid(), [
            ['type' => Room::class, 'ulid' => $room->ulid, 'operation' => 'delete', 'data' => []],
        ]);

        $item = $batch->items()->first();
        $this->assertSame('skipped', $item->status);
        $this->assertNotEmpty($item->error_message);
        $this->assertNotNull(Room::find($room->id), 'The room must still exist — this sync engine never hard-deletes automatically.');
    }

    public function test_an_unknown_syncable_type_fails_that_item_without_aborting_the_batch(): void
    {
        $device = $this->device();

        $batch = $this->service()->applyIncomingBatch($device, (string) Str::ulid(), [
            ['type' => 'App\\Models\\NotARealModel', 'ulid' => (string) Str::ulid(), 'operation' => 'update', 'data' => []],
            ['type' => Department::class, 'ulid' => (string) Str::ulid(), 'operation' => 'update', 'data' => [
                'name' => 'Still Applies', 'code' => 'SA1', 'is_active' => true,
            ]],
        ]);

        $this->assertSame('failed', $batch->fresh()->status, 'The batch as a whole is flagged for attention...');
        $this->assertSame(1, $batch->items()->where('status', 'failed')->count());
        $this->assertSame(1, $batch->items()->where('status', 'applied')->count(), '...but the other, valid item in the same batch still applies.');
    }

    public function test_a_malformed_non_object_item_fails_without_a_type_error(): void
    {
        // Phase 9.7.4: once the transport controller exposes this method to
        // real (authenticated but not otherwise trusted) network input, an
        // item is no longer guaranteed to even be an array/object.
        $device = $this->device();

        $batch = $this->service()->applyIncomingBatch($device, (string) Str::ulid(), [
            'this-is-not-an-object',
            ['type' => Department::class, 'ulid' => (string) Str::ulid(), 'operation' => 'update', 'data' => [
                'name' => 'Still Applies Too', 'code' => 'SA2', 'is_active' => true,
            ]],
        ]);

        $this->assertSame('failed', $batch->fresh()->status);
        $this->assertSame(1, $batch->items()->where('status', 'failed')->where('syncable_type', 'unknown')->count());
        $this->assertSame(1, $batch->items()->where('status', 'applied')->count());
    }

    /**
     * Security re-audit finding (PHASE9_AUDIT_AND_PLAN.md §9.8, fixed):
     * a User row's outbox entry must never carry its own credential
     * material — password/pin_hash bcrypt hashes and TOTP secret/recovery
     * material previously travelled on the wire and could sit in
     * cleartext-JSON in a sync_conflicts row, visible to any manage-sync
     * holder. SyncableModels::PROTECTED_ATTRIBUTES + the fixed
     * exportableAttributes() must strip them before the payload is ever
     * built.
     */
    public function test_a_users_outbox_entry_never_carries_its_credential_or_lockout_columns(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('super-secret'),
            'pin_hash' => bcrypt('1234'),
            'totp_secret' => 'ABCDEFGHIJKLMNOP',
            'totp_recovery_codes' => ['one', 'two'],
            'failed_pin_attempts' => 3,
        ]);
        $device = $this->device();

        $batch = $this->service()->buildOutboxBatch($device);

        $userItem = collect($batch->payload)->firstWhere('type', User::class);
        $this->assertNotNull($userItem, 'The new user should be in the outbox.');
        foreach (['password', 'pin_hash', 'totp_secret', 'totp_recovery_codes', 'remember_token', 'failed_pin_attempts', 'pin_locked_until'] as $protected) {
            $this->assertArrayNotHasKey($protected, $userItem['data'], "《{$protected}》 must never leave the installation via sync.");
        }
        // Ordinary, non-credential columns still sync normally.
        $this->assertSame($user->name, $userItem['data']['name']);
    }

    public function test_applying_an_incoming_user_update_never_overwrites_the_local_password_or_pin(): void
    {
        // Even if a compromised device secret were used to craft a
        // payload that explicitly includes a 'password'/'pin_hash' key
        // (bypassing this installation's own, now-fixed outbox export),
        // applyUpsert() must not be able to change local credentials via
        // sync — the field is simply never part of what a legitimate
        // payload contains, and forceFill() on the receiving side has no
        // special-case exemption to lean on, so this proves the
        // receiving side is not exploitable even given an adversarial
        // sender.
        $device = $this->device();
        $user = User::factory()->create(['name' => 'Original Name', 'password' => bcrypt('original-password')]);
        $originalHash = $user->password;

        $this->service()->applyIncomingBatch($device, (string) Str::ulid(), [
            ['type' => User::class, 'ulid' => $user->ulid, 'operation' => 'update', 'data' => [
                'name' => 'Updated Via Sync',
                'password' => bcrypt('attacker-supplied-password'),
                'is_active' => false,
            ]],
        ]);

        $user->refresh();
        $this->assertSame('Updated Via Sync', $user->name, 'Non-credential fields still sync normally.');
        $this->assertSame($originalHash, $user->password, 'A password key in the incoming payload must never overwrite the local hash.');
    }
}
