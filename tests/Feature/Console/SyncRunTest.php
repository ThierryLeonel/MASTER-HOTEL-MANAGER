<?php

namespace Tests\Feature\Console;

use App\Models\SyncDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 9.7.5 (PHASE9_AUDIT_AND_PLAN.md §6, Phase 9.7 sub-plan). Confirms
 * `php artisan sync:run` (the cron entry point) selects the right
 * devices — skipping any without an endpoint_url, honoring --device —
 * and surfaces SyncRunner's per-device result and exit code, the same
 * way RunIntegrityCheckTest confirms integrity:check is wired to its
 * real service rather than asserted only at the unit level.
 */
class SyncRunTest extends TestCase
{
    use RefreshDatabase;

    private function device(string $name, ?string $endpoint = 'https://vps.example.com'): SyncDevice
    {
        return SyncDevice::create([
            'name' => $name, 'device_key' => (string) Str::ulid(), 'shared_secret' => 'shh',
            'endpoint_url' => $endpoint, 'is_active' => true,
        ]);
    }

    public function test_it_reports_no_devices_when_none_are_registered(): void
    {
        $this->artisan('sync:run')
            ->assertSuccessful()
            ->expectsOutputToContain('No active sync device with an endpoint_url to run against.');
    }

    public function test_it_skips_an_active_device_with_no_endpoint_url_and_still_succeeds(): void
    {
        $this->device('Local Server', endpoint: null);

        $this->artisan('sync:run')
            ->assertSuccessful()
            ->expectsOutputToContain("1 active device(s) have no endpoint_url and were skipped")
            ->expectsOutputToContain('No active sync device with an endpoint_url to run against.');
    }

    public function test_it_syncs_every_active_device_with_an_endpoint_and_reports_success(): void
    {
        $this->device('Cloud VPS');

        Http::fake([
            '*' => Http::response(['batch_ulid' => (string) Str::ulid(), 'items' => [], 'status' => 'applied'], 200),
        ]);

        $this->artisan('sync:run')
            ->assertSuccessful()
            ->expectsOutputToContain("Syncing with 'Cloud VPS'")
            ->expectsOutputToContain('pushed 0 item(s), pulled 0 item(s).');
    }

    public function test_it_exits_with_failure_when_a_device_sync_errors(): void
    {
        $this->device('Cloud VPS');

        Http::fake([
            '*' => Http::response(['message' => 'Server error.'], 500),
        ]);

        $this->artisan('sync:run')
            ->assertFailed()
            ->expectsOutputToContain("pull from 'Cloud VPS' failed");
    }

    public function test_the_device_option_limits_the_run_to_the_named_device_key(): void
    {
        $target = $this->device('Cloud VPS');
        $other = $this->device('Branch Office');

        Http::fake([
            '*' => Http::response(['batch_ulid' => (string) Str::ulid(), 'items' => [], 'status' => 'applied'], 200),
        ]);

        $this->artisan('sync:run', ['--device' => [$target->device_key]])
            ->assertSuccessful()
            ->expectsOutputToContain("Syncing with 'Cloud VPS'")
            ->doesntExpectOutputToContain("Syncing with 'Branch Office'");
    }
}
