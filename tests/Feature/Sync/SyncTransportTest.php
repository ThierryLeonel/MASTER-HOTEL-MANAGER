<?php

namespace Tests\Feature\Sync;

use App\Models\Department;
use App\Models\SyncBatch;
use App\Services\Sync\DeviceAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 9.7.4 (PHASE9_AUDIT_AND_PLAN.md §6, Phase 9.7 sub-plan). Exercises
 * the three JSON endpoints as real HTTP requests (postJson, going through
 * routing/middleware exactly as routes/sync.php registers them) rather
 * than calling SyncTransportController's methods directly — the thing
 * actually worth proving here is that authentication, routing, and JSON
 * (de)serialization all work together, since SyncService's own logic is
 * already covered by tests/Unit/Sync/SyncServiceTest.php without any of
 * that.
 */
class SyncTransportTest extends TestCase
{
    use RefreshDatabase;

    private function registerDevice(): array
    {
        return app(DeviceAuthService::class)->register('Cloud VPS', 'https://vps.example.com');
    }

    public function test_push_is_unauthorized_without_valid_credentials(): void
    {
        $response = $this->postJson('/sync/push', [
            'device_key' => 'not-a-real-key', 'secret' => 'nope', 'batch_ulid' => 'x', 'items' => [],
        ]);

        $response->assertStatus(401);
    }

    public function test_push_applies_a_batch_and_reports_a_status_breakdown(): void
    {
        $result = $this->registerDevice();

        $response = $this->postJson('/sync/push', [
            'device_key' => $result['device']->device_key,
            'secret' => $result['secret'],
            'batch_ulid' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'items' => [
                ['type' => Department::class, 'ulid' => '01ARZ3NDEKTSV4RRFFQ69G5FAW', 'operation' => 'update', 'data' => [
                    'name' => 'Engineering', 'code' => 'ENG', 'is_active' => true,
                ]],
            ],
        ]);

        $response->assertOk();
        $response->assertJson(['status' => 'applied', 'item_count' => 1, 'applied' => 1, 'conflicts' => 0, 'failed' => 0]);
        $this->assertNotNull(Department::whereUlid('01ARZ3NDEKTSV4RRFFQ69G5FAW')->first());
    }

    public function test_push_missing_batch_ulid_is_a_validation_error(): void
    {
        $result = $this->registerDevice();

        $response = $this->postJson('/sync/push', [
            'device_key' => $result['device']->device_key, 'secret' => $result['secret'], 'items' => [],
        ]);

        $response->assertStatus(422);
    }

    public function test_pull_returns_a_pending_batch_without_advancing_the_checkpoint(): void
    {
        $result = $this->registerDevice();
        Department::create(['name' => 'Housekeeping', 'code' => 'HSK', 'is_active' => true]);

        $response = $this->postJson('/sync/pull', [
            'device_key' => $result['device']->device_key, 'secret' => $result['secret'],
        ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'items');
        $batchUlid = $response->json('batch_ulid');

        $batch = SyncBatch::where('ulid', $batchUlid)->first();
        $this->assertSame('pending', $batch->status);

        // buildOutboxBatch() creates the checkpoint row on first use, but
        // must not yet have advanced its watermark — that only happens on
        // acknowledge().
        $checkpoint = $result['device']->checkpointFor('outbound');
        $this->assertNotNull($checkpoint);
        $this->assertNull($checkpoint->last_synced_at);
    }

    public function test_acknowledge_advances_the_checkpoint_and_is_idempotent(): void
    {
        $result = $this->registerDevice();
        Department::create(['name' => 'Housekeeping', 'code' => 'HSK', 'is_active' => true]);

        $pullResponse = $this->postJson('/sync/pull', [
            'device_key' => $result['device']->device_key, 'secret' => $result['secret'],
        ]);
        $batchUlid = $pullResponse->json('batch_ulid');

        $ackResponse = $this->postJson('/sync/acknowledge', [
            'device_key' => $result['device']->device_key, 'secret' => $result['secret'], 'batch_ulid' => $batchUlid,
        ]);
        $ackResponse->assertOk();
        $ackResponse->assertJson(['status' => 'applied']);

        $checkpoint = $result['device']->fresh()->checkpointFor('outbound');
        $this->assertNotNull($checkpoint->last_synced_at);
        $firstAckTime = $checkpoint->last_synced_at;

        // Acknowledging the same batch again must not re-advance the
        // checkpoint a second time.
        $this->postJson('/sync/acknowledge', [
            'device_key' => $result['device']->device_key, 'secret' => $result['secret'], 'batch_ulid' => $batchUlid,
        ])->assertOk();

        $this->assertTrue($checkpoint->fresh()->last_synced_at->eq($firstAckTime));
    }

    public function test_acknowledge_unknown_batch_ulid_is_a_404(): void
    {
        $result = $this->registerDevice();

        $response = $this->postJson('/sync/acknowledge', [
            'device_key' => $result['device']->device_key, 'secret' => $result['secret'], 'batch_ulid' => 'does-not-exist',
        ]);

        $response->assertStatus(404);
    }

    public function test_a_revoked_device_cannot_use_any_endpoint(): void
    {
        $result = $this->registerDevice();
        app(DeviceAuthService::class)->revoke($result['device']);

        $this->postJson('/sync/pull', [
            'device_key' => $result['device']->device_key, 'secret' => $result['secret'],
        ])->assertStatus(401);
    }
}
