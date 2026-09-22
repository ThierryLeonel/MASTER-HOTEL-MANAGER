<?php

namespace App\Services\Sync;

use App\Models\SyncDevice;
use Illuminate\Support\Facades\Http;

/**
 * Phase 9.7.5 (PHASE9_AUDIT_AND_PLAN.md §6, Phase 9.7 sub-plan). The
 * scheduled orchestration layer — the one piece of the sync engine that
 * actually initiates an outgoing HTTP call. Everything it calls
 * (SyncService::buildOutboxBatch()/acknowledgeOutboxBatch()/
 * applyIncomingBatch(), DeviceAuthService::credentialsFor()) was already
 * built and unit-tested in 9.7.3/9.7.4 without any network involved;
 * this class's only new responsibility is driving both directions of a
 * sync against one counterpart's already-built receiving endpoints
 * (routes/sync.php, Phase 9.7.4) and reporting what happened, so
 * `php artisan sync:run` (this sub-phase) can be a thin command that
 * just loops over devices and prints the result.
 *
 * Both directions run every time `runFor()` is called:
 *   PUSH — build OUR outbound batch for this device, POST it to the
 *   device's own /sync/push (it applies synchronously and replies with a
 *   status breakdown), then acknowledge locally so our own outbound
 *   checkpoint advances. Skipped entirely (no network call) when there
 *   is nothing new to push — see push()'s docblock for why that's safe.
 *   PULL — POST to the device's /sync/pull to ask what it has for us,
 *   apply whatever comes back locally, then POST to its /sync/acknowledge
 *   so ITS outbound checkpoint advances. Always performed, even when the
 *   reply turns out to have zero items — see pull()'s docblock for why.
 *
 * A device with no `endpoint_url` is skipped by the console command
 * before this class is ever called — it can still receive incoming
 * push/pull calls initiated by its counterpart (that direction needs no
 * endpoint_url on this side at all), it just cannot be the one to
 * initiate an outgoing call.
 */
class SyncRunner
{
    public function __construct(
        private readonly SyncService $syncService,
        private readonly DeviceAuthService $deviceAuth,
    ) {
    }

    /**
     * @return array{device: string, pushed: int, pulled: int, errors: list<string>}
     */
    public function runFor(SyncDevice $device): array
    {
        $result = ['device' => $device->name, 'pushed' => 0, 'pulled' => 0, 'errors' => []];

        try {
            $result['pushed'] = $this->push($device);
        } catch (\Throwable $e) {
            $result['errors'][] = "push to '{$device->name}' failed: {$e->getMessage()}";
        }

        try {
            $result['pulled'] = $this->pull($device);
        } catch (\Throwable $e) {
            $result['errors'][] = "pull from '{$device->name}' failed: {$e->getMessage()}";
        }

        return $result;
    }

    /**
     * When our own outbox has nothing new (item_count === 0), it is
     * deliberately never sent over the network at all — there is
     * nothing for the counterpart to apply, so the only thing to do is
     * advance our own outbound checkpoint locally (via
     * acknowledgeOutboxBatch(), exactly as if the counterpart had
     * confirmed an empty batch) so the NEXT run's buildOutboxBatch() call
     * scans "changed since last time" instead of re-scanning every
     * syncable row from the beginning again.
     */
    private function push(SyncDevice $device): int
    {
        $batch = $this->syncService->buildOutboxBatch($device);

        if ($batch->item_count === 0) {
            $this->syncService->acknowledgeOutboxBatch($batch);

            return 0;
        }

        $credentials = $this->deviceAuth->credentialsFor($device);

        $response = Http::timeout(30)->post($this->endpoint($device, 'push'), [
            'device_key' => $credentials['device_key'],
            'secret' => $credentials['secret'],
            'batch_ulid' => $batch->ulid,
            'items' => $batch->payload,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException("HTTP {$response->status()} from {$device->name}'s /sync/push — {$this->summarizeBody($response)}");
        }

        $this->syncService->acknowledgeOutboxBatch($batch);

        return $batch->item_count;
    }

    /**
     * Always performed, even when the counterpart's reply has zero
     * items: the counterpart's own buildOutboxBatch() (which its /sync/
     * pull endpoint calls) never advances ITS outbound checkpoint on its
     * own (see SyncTransportController::pull()'s docblock) — only our
     * acknowledge call does that. If we skipped acknowledging an empty
     * reply, the counterpart's checkpoint would stay wherever it was
     * (possibly null), and every future pull from it would re-scan its
     * entire syncable dataset instead of just what changed since last
     * time — the exact wastefulness push() above avoids from the other
     * direction. The one-time cost of applying/acknowledging an empty
     * batch is a handful of no-op queries; the alternative compounds.
     */
    private function pull(SyncDevice $device): int
    {
        $credentials = $this->deviceAuth->credentialsFor($device);

        $response = Http::timeout(30)->post($this->endpoint($device, 'pull'), [
            'device_key' => $credentials['device_key'],
            'secret' => $credentials['secret'],
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException("HTTP {$response->status()} from {$device->name}'s /sync/pull — {$this->summarizeBody($response)}");
        }

        $batchUlid = (string) $response->json('batch_ulid');
        $items = (array) $response->json('items', []);

        $this->syncService->applyIncomingBatch($device, $batchUlid, $items);

        $ackResponse = Http::timeout(30)->post($this->endpoint($device, 'acknowledge'), [
            'device_key' => $credentials['device_key'],
            'secret' => $credentials['secret'],
            'batch_ulid' => $batchUlid,
        ]);

        if (! $ackResponse->successful()) {
            throw new \RuntimeException("HTTP {$ackResponse->status()} from {$device->name}'s /sync/acknowledge — {$this->summarizeBody($ackResponse)}");
        }

        return count($items);
    }

    private function endpoint(SyncDevice $device, string $path): string
    {
        return rtrim((string) $device->endpoint_url, '/')."/sync/{$path}";
    }

    private function summarizeBody(\Illuminate\Http\Client\Response $response): string
    {
        $message = $response->json('message');

        return is_string($message) ? $message : substr($response->body(), 0, 200);
    }
}
