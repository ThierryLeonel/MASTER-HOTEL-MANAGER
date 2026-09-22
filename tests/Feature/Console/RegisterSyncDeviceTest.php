<?php

namespace Tests\Feature\Console;

use App\Models\SyncDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 9.7.2, revised in Phase 9.7.4 for the corrected two-command
 * register/import handshake (see DeviceAuthService's docblock).
 */
class RegisterSyncDeviceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_device_and_prints_its_key_and_secret(): void
    {
        $this->artisan('sync:register-device', ['name' => 'Cloud VPS'])
            ->assertSuccessful()
            ->expectsOutputToContain('Device registered: Cloud VPS')
            ->expectsOutputToContain('device_key:')
            ->expectsOutputToContain('secret:');

        $device = SyncDevice::where('name', 'Cloud VPS')->first();
        $this->assertNotNull($device);
        $this->assertTrue($device->is_active);
    }

    public function test_the_endpoint_option_is_stored_on_the_device(): void
    {
        $this->artisan('sync:register-device', [
            'name' => 'Cloud VPS',
            '--endpoint' => 'https://vps.example.com/sync',
        ])->assertSuccessful();

        $device = SyncDevice::where('name', 'Cloud VPS')->first();

        $this->assertSame('https://vps.example.com/sync', $device->endpoint_url);
    }

    public function test_import_key_and_secret_record_the_exact_given_values(): void
    {
        $this->artisan('sync:register-device', [
            'name' => 'Local Server',
            '--import-key' => 'the-given-key',
            '--import-secret' => 'the-given-secret',
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('Device imported: Local Server');

        $device = SyncDevice::where('name', 'Local Server')->first();
        $this->assertSame('the-given-key', $device->device_key);
        $this->assertSame('the-given-secret', $device->shared_secret);
    }

    public function test_import_key_without_import_secret_fails_cleanly(): void
    {
        $this->artisan('sync:register-device', [
            'name' => 'Local Server',
            '--import-key' => 'the-given-key',
        ])->assertFailed();

        $this->assertNull(SyncDevice::where('name', 'Local Server')->first());
    }
}
