<?php

namespace App\Console\Commands;

use App\Models\SyncDevice;
use App\Services\Sync\SyncRunner;
use Illuminate\Console\Command;

/**
 * Phase 9.7.5 (PHASE9_AUDIT_AND_PLAN.md §6, Phase 9.7 sub-plan). The
 * scheduled entry point for the whole hybrid sync engine: pushes this
 * installation's changes to, and pulls each counterpart's changes from,
 * every active `sync_devices` row that has an `endpoint_url` on file —
 * a real cron/CI-usable command (auto-discovered, no `Kernel.php`
 * registration needed on Laravel 12), the same pattern already
 * established by `integrity:check` (Phase 9.6). Suggested crontab line
 * for each installation (adjust the interval and path):
 *
 *   (every 5 minutes) cd /path/to/hms && php artisan sync:run >> storage/logs/sync-run.log 2>&1
 *   crontab field order: minute(0-59 step 5) hour(*) day-of-month(*) month(*) day-of-week(*)
 *
 * This command contains no sync logic of its own — that's entirely
 * SyncRunner (this sub-phase) delegating to SyncService (9.7.3) and
 * DeviceAuthService (9.7.2/9.7.4); this class only selects which
 * devices to run against and prints a human-readable summary.
 */
class SyncRun extends Command
{
    protected $signature = 'sync:run
        {--device=* : Limit to one or more specific device_key values; default is every active device with an endpoint_url}';

    protected $description = 'Push local changes to, and pull remote changes from, every registered sync counterpart.';

    public function handle(SyncRunner $runner): int
    {
        $query = SyncDevice::where('is_active', true)->whereNotNull('endpoint_url');

        $deviceKeys = $this->option('device');
        if (! empty($deviceKeys)) {
            $query->whereIn('device_key', $deviceKeys);
        }

        $devices = $query->get();

        $skippedNoEndpoint = SyncDevice::where('is_active', true)->whereNull('endpoint_url')->count();
        if ($skippedNoEndpoint > 0 && empty($deviceKeys)) {
            $this->line("Note: {$skippedNoEndpoint} active device(s) have no endpoint_url and were skipped — they can still receive incoming push/pull calls initiated by their counterpart, just not be the target of an outgoing one from here.");
        }

        if ($devices->isEmpty()) {
            $this->info('No active sync device with an endpoint_url to run against.');

            return self::SUCCESS;
        }

        $hadFailure = false;

        foreach ($devices as $device) {
            $this->line("Syncing with '{$device->name}' ({$device->endpoint_url})...");

            $result = $runner->runFor($device);

            $this->info("  pushed {$result['pushed']} item(s), pulled {$result['pulled']} item(s).");

            foreach ($result['errors'] as $error) {
                $this->error("  {$error}");
                $hadFailure = true;
            }
        }

        return $hadFailure ? self::FAILURE : self::SUCCESS;
    }
}
