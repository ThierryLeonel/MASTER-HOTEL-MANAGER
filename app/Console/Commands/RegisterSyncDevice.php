<?php

namespace App\Console\Commands;

use App\Services\Sync\DeviceAuthService;
use Illuminate\Console\Command;

/**
 * Phase 9.7.2, revised in Phase 9.7.4 to match DeviceAuthService's
 * corrected shared-secret model (see that class's docblock for the full
 * "why" — a hashed secret from 9.7.2 could never be retrieved again to
 * present on an outgoing call).
 *
 * Registering a sync relationship is a two-command, two-operator
 * handshake, run once per pair of installations:
 *
 *   On the local server:
 *     php artisan sync:register-device "Cloud VPS" --endpoint=https://vps.example.com
 *   -> prints a device_key and a secret. Copy both.
 *
 *   On the cloud VPS, using the EXACT values just printed:
 *     php artisan sync:register-device "Local Server" \
 *       --import-key=<the device_key from above> --import-secret=<the secret from above>
 *
 * Either side can run the first command; whichever one has an
 * `endpoint_url` set is the side that can initiate a push to the other.
 * For true bidirectional sync, both operators eventually need one row
 * on each side (this handshake once for each direction of "who calls
 * whom"), though most installs will have the cloud VPS reachable from
 * the local server but not vice versa, in which case only the local
 * server's row needs an endpoint and the VPS-side row can omit one.
 */
class RegisterSyncDevice extends Command
{
    protected $signature = 'sync:register-device {name : A human-readable label, e.g. "Cloud VPS" or "Local Server"}
        {--endpoint= : The URL this installation should call to reach that device (omit if this installation never initiates a call to them)}
        {--import-key= : Import an existing device_key printed by the OTHER installation\'s sync:register-device, instead of generating a new one}
        {--import-secret= : The matching secret printed alongside --import-key. Required together with --import-key.}';

    protected $description = 'Register a sync counterpart device, either generating a new shared secret or importing one printed by the other installation.';

    public function handle(DeviceAuthService $deviceAuth): int
    {
        $importKey = $this->option('import-key');
        $importSecret = $this->option('import-secret');

        if (($importKey && ! $importSecret) || ($importSecret && ! $importKey)) {
            $this->error('--import-key and --import-secret must be given together.');

            return self::FAILURE;
        }

        if ($importKey && $importSecret) {
            $device = $deviceAuth->import(
                name: $this->argument('name'),
                deviceKey: $importKey,
                secret: $importSecret,
                endpointUrl: $this->option('endpoint'),
            );

            $this->info("Device imported: {$device->name} (id {$device->id}), using the device_key/secret provided.");

            return self::SUCCESS;
        }

        $result = $deviceAuth->register(
            name: $this->argument('name'),
            endpointUrl: $this->option('endpoint'),
        );
        $device = $result['device'];

        $this->info("Device registered: {$device->name} (id {$device->id}).");
        $this->newLine();
        $this->line("device_key: {$device->device_key}");
        $this->line("secret:     {$result['secret']}");
        $this->newLine();
        $this->warn('This secret is shown ONE TIME ONLY and is not stored in plaintext anywhere. '
            .'Run sync:register-device on the OTHER installation now, passing these exact two values as '
            .'--import-key and --import-secret, so both sides agree on the same shared secret. '
            .'If you lose it, this device must be revoked and re-registered on both sides.');

        return self::SUCCESS;
    }
}
