<?php

namespace Tests\Unit\Sync;

use App\Models\Department;
use App\Models\SyncBatch;
use App\Models\SyncDevice;
use App\Services\Sync\SyncRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 9.7.5 (PHASE9_AUDIT_AND_PLAN.md §6, Phase 9.7 sub-plan). Exercises
 * SyncRunner's HTTP orchestration against a faked counterpart
 * (Http::fake() — no real network access in this sandbox or otherwise),
 * proving the push/pull/acknowledge sequencing and checkpoint-advancing
 * rules described in its own docblock, not just that SyncService works
 * (already covered without any network involved in SyncServiceTest).
 */
class SyncRunnerTest extends TestCase
{
    use RefreshDatabase;

    private function device(): SyncDevice
    {
        return SyncDevice::create([
            'name' => 'Cloud VPS', 'device_key' => (string) Str::ulid(), 'shared_secret' => 'shh',
            'endpoint_url' => 'https://vps.example.com', 'is_active' => true,
        ]);
    }

    private function runner(): SyncRunner
    {
        return app(SyncRunner::class);
    }

    public function test_push_is_skipped_over_the_network_when_the_local_outbox_is_empty_but_still_advances_the_local_checkpoint(): void
    {
        $device = $this->device();

        // Http::fake() with no arguments prevents ANY real request (this
        // sandbox has no network access to a real "vps.example.com"
        // anyway) and returns an empty 200 for anything not explicitly
        // matched below — runFor() also calls pull(), which does hit the
        // network, so this still needs a response for that call even
        // though this test's assertions are all about push()'s behavior.
        Http::fake([
            'vps.example.com/sync/pull' => Http::response(['batch_ulid' => (string) Str::ulid(), 'items' => []], 200),
            'vps.example.com/sync/acknowledge' => Http::response(['status' => 'applied'], 200),
            // Safety net: anything not explicitly matched above gets a
            // deterministic failing response instead of ever attempting
            // a real network request.
            '*' => Http::response(['message' => 'Unexpected URL in SyncRunnerTest fake map.'], 599),
        ]);

        $result = $this->runner()->runFor($device);

        $this->assertSame(0, $result['pushed']);
        $this->assertSame([], array_filter($result['errors'], fn ($e) => str_contains($e, 'push')));
        $this->assertNotNull($device->checkpointFor('outbound')->last_synced_at, 'An empty outbox must still be acknowledged locally so the next run scans forward, not from scratch.');

        Http::assertNotSent(fn ($request) => $request->url() === 'https://vps.example.com/sync/push');
    }

    public function test_push_sends_a_nonempty_outbox_and_acknowledges_locally_once_the_counterpart_confirms(): void
    {
        Department::create(['name' => 'Engineering', 'code' => 'ENG', 'is_active' => true]);
        $device = $this->device();

        Http::fake([
            'vps.example.com/sync/push' => Http::response(['status' => 'applied', 'applied' => 1], 200),
            'vps.example.com/sync/pull' => Http::response(['batch_ulid' => (string) Str::ulid(), 'items' => []], 200),
            'vps.example.com/sync/acknowledge' => Http::response(['status' => 'applied'], 200),
            '*' => Http::response(['message' => 'Unexpected URL in SyncRunnerTest fake map.'], 599),
        ]);

        $result = $this->runner()->runFor($device);

        $this->assertSame(1, $result['pushed']);
        $this->assertSame([], $result['errors']);

        $pushedBatch = SyncBatch::where('sync_device_id', $device->id)->where('direction', 'outbound')->first();
        $this->assertSame('applied', $pushedBatch->status);
        $this->assertNotNull($device->checkpointFor('outbound')->last_synced_at);

        Http::assertSent(function ($request) use ($device) {
            return $request->url() === 'https://vps.example.com/sync/push'
                && $request['device_key'] === $device->device_key
                && $request['secret'] === 'shh'
                && count($request['items']) === 1;
        });
    }

    public function test_push_records_an_error_and_does_not_locally_acknowledge_when_the_counterpart_rejects_it(): void
    {
        Department::create(['name' => 'Engineering', 'code' => 'ENG', 'is_active' => true]);
        $device = $this->device();

        Http::fake([
            'vps.example.com/sync/push' => Http::response(['message' => 'Unauthorized.'], 401),
            'vps.example.com/sync/pull' => Http::response(['batch_ulid' => (string) Str::ulid(), 'items' => []], 200),
            'vps.example.com/sync/acknowledge' => Http::response(['status' => 'applied'], 200),
            '*' => Http::response(['message' => 'Unexpected URL in SyncRunnerTest fake map.'], 599),
        ]);

        $result = $this->runner()->runFor($device);

        $this->assertSame(0, $result['pushed']);
        $this->assertCount(1, array_filter($result['errors'], fn ($e) => str_starts_with($e, "push to 'Cloud VPS' failed")));
        $this->assertStringContainsString('Unauthorized.', $result['errors'][0]);

        $pushedBatch = SyncBatch::where('sync_device_id', $device->id)->where('direction', 'outbound')->first();
        $this->assertSame('pending', $pushedBatch->status, 'A rejected push must not be locally acknowledged, or the row would never be retried.');
        $this->assertNull($device->fresh()->checkpointFor('outbound')->last_synced_at);
    }

    public function test_pull_applies_returned_items_and_acknowledges_the_counterpart(): void
    {
        $device = $this->device();
        $batchUlid = (string) Str::ulid();

        Http::fake([
            'vps.example.com/sync/push' => Http::response(['status' => 'applied'], 200),
            'vps.example.com/sync/pull' => Http::response([
                'batch_ulid' => $batchUlid,
                'items' => [
                    ['type' => Department::class, 'ulid' => (string) Str::ulid(), 'operation' => 'update', 'data' => [
                        'name' => 'Housekeeping', 'code' => 'HSK', 'is_active' => true,
                    ]],
                ],
            ], 200),
            'vps.example.com/sync/acknowledge' => Http::response(['status' => 'applied'], 200),
            '*' => Http::response(['message' => 'Unexpected URL in SyncRunnerTest fake map.'], 599),
        ]);

        $result = $this->runner()->runFor($device);

        $this->assertSame(1, $result['pulled']);
        $this->assertSame([], array_filter($result['errors'], fn ($e) => str_contains($e, 'pull')));
        $this->assertNotNull(Department::where('code', 'HSK')->first());

        Http::assertSent(fn ($request) => $request->url() === 'https://vps.example.com/sync/acknowledge'
            && $request['batch_ulid'] === $batchUlid);
    }

    public function test_pull_still_acknowledges_an_empty_reply_so_the_counterparts_checkpoint_advances(): void
    {
        $device = $this->device();
        $batchUlid = (string) Str::ulid();

        Http::fake([
            'vps.example.com/sync/push' => Http::response(['status' => 'applied'], 200),
            'vps.example.com/sync/pull' => Http::response(['batch_ulid' => $batchUlid, 'items' => []], 200),
            'vps.example.com/sync/acknowledge' => Http::response(['status' => 'applied'], 200),
            '*' => Http::response(['message' => 'Unexpected URL in SyncRunnerTest fake map.'], 599),
        ]);

        $result = $this->runner()->runFor($device);

        $this->assertSame(0, $result['pulled']);
        Http::assertSent(fn ($request) => $request->url() === 'https://vps.example.com/sync/acknowledge'
            && $request['batch_ulid'] === $batchUlid);
    }

    public function test_pull_records_an_error_when_the_counterpart_is_unreachable(): void
    {
        $device = $this->device();

        Http::fake([
            'vps.example.com/sync/push' => Http::response(['status' => 'applied'], 200),
            'vps.example.com/sync/pull' => Http::response(['message' => 'Server error.'], 500),
            '*' => Http::response(['message' => 'Unexpected URL in SyncRunnerTest fake map.'], 599),
        ]);

        $result = $this->runner()->runFor($device);

        $this->assertSame(0, $result['pulled']);
        $this->assertCount(1, array_filter($result['errors'], fn ($e) => str_starts_with($e, "pull from 'Cloud VPS' failed")));
    }
}
