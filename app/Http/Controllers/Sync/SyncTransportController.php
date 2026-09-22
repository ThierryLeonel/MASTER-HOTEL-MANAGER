<?php

namespace App\Http\Controllers\Sync;

use App\Http\Controllers\Controller;
use App\Models\SyncDevice;
use App\Services\Sync\DeviceAuthService;
use App\Services\Sync\SyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 9.7.4 (PHASE9_AUDIT_AND_PLAN.md §6, Phase 9.7 sub-plan): the
 * network transport — three JSON endpoints one installation calls on the
 * other to actually move a batch. This controller does no sync LOGIC of
 * its own (that's entirely SyncService, Phase 9.7.3, already built and
 * unit-tested without any network involved); it only authenticates the
 * request, translates it to/from JSON, and delegates.
 *
 * Registered in routes/sync.php, OUTSIDE the `web` middleware group
 * (see bootstrap/app.php's `then:` callback and routes/deploy.php's own
 * docblock for the precedent) — this is server-to-server JSON traffic
 * between two trusted installations, not a browser session, so CSRF
 * verification and cookie/session handling would only get in the way.
 * `throttle` is kept (it only touches the in-memory rate limiter, not a
 * database table) as basic abuse protection.
 *
 * Authentication is per-request (device_key + secret in the JSON body,
 * verified by DeviceAuthService::authenticate()), not a Laravel auth
 * guard/session — there is no logged-in user on either end of this
 * conversation, only two installations that agree on a shared secret.
 * A bad or missing credential always gets the same generic 401, never a
 * hint about which half (key vs. secret) was wrong — the same
 * "don't tell an attacker which half is wrong" principle
 * DeployHelperController::authorizeRequest() already follows.
 */
class SyncTransportController extends Controller
{
    public function __construct(
        private readonly DeviceAuthService $deviceAuth,
        private readonly SyncService $syncService,
    ) {
    }

    /**
     * The caller is pushing ITS outbound batch to US. We apply it as an
     * inbound batch and report back a per-status breakdown so the caller
     * can decide whether to alert an operator about conflicts/failures —
     * this endpoint itself never rejects a batch just because some of its
     * items conflicted or failed; that's an expected, recorded outcome,
     * not a transport-level error (see SyncService::applyIncomingBatch()).
     */
    public function push(Request $request): JsonResponse
    {
        $device = $this->authenticate($request);
        if (! $device) {
            return $this->unauthorized();
        }

        $batchUlid = (string) $request->input('batch_ulid', '');
        if ($batchUlid === '') {
            return response()->json(['message' => 'batch_ulid is required.'], 422);
        }

        $items = $request->input('items', []);
        if (! is_array($items)) {
            return response()->json(['message' => 'items must be an array.'], 422);
        }

        $batch = $this->syncService->applyIncomingBatch($device, $batchUlid, $items);

        return response()->json([
            'batch_ulid' => $batch->ulid,
            'status' => $batch->status,
            'item_count' => $batch->item_count,
            'applied' => $batch->items()->where('status', 'applied')->count(),
            'conflicts' => $batch->items()->where('status', 'conflict')->count(),
            'skipped' => $batch->items()->where('status', 'skipped')->count(),
            'failed' => $batch->items()->where('status', 'failed')->count(),
        ]);
    }

    /**
     * The caller is asking US to hand over OUR outbound batch for them.
     * This only BUILDS the batch (status stays `pending`) — it does not
     * advance our outbound checkpoint. The caller must call acknowledge()
     * once it has safely stored/applied what it received, exactly the
     * same "don't advance the watermark until delivery is confirmed"
     * principle SyncService::acknowledgeOutboxBatch()'s own docblock
     * describes.
     */
    public function pull(Request $request): JsonResponse
    {
        $device = $this->authenticate($request);
        if (! $device) {
            return $this->unauthorized();
        }

        $batch = $this->syncService->buildOutboxBatch($device);

        return response()->json([
            'batch_ulid' => $batch->ulid,
            'item_count' => $batch->item_count,
            'items' => $batch->payload ?? [],
        ]);
    }

    /**
     * Confirms the batch pull() handed over was safely received, so we
     * can advance our outbound checkpoint. Idempotent on purpose: acking
     * an already-acked batch is a silent no-op rather than re-advancing
     * the checkpoint a second time, which could skip over genuinely new
     * changes made in between the two acknowledgements.
     */
    public function acknowledge(Request $request): JsonResponse
    {
        $device = $this->authenticate($request);
        if (! $device) {
            return $this->unauthorized();
        }

        $batchUlid = (string) $request->input('batch_ulid', '');
        $batch = $device->batches()->where('ulid', $batchUlid)->where('direction', 'outbound')->first();

        if (! $batch) {
            return response()->json(['message' => 'Unknown batch_ulid for this device.'], 404);
        }

        if ($batch->status !== 'applied') {
            $batch = $this->syncService->acknowledgeOutboxBatch($batch);
        }

        return response()->json(['batch_ulid' => $batch->ulid, 'status' => $batch->status]);
    }

    private function authenticate(Request $request): ?SyncDevice
    {
        $deviceKey = (string) $request->input('device_key', '');
        $secret = (string) $request->input('secret', '');

        if ($deviceKey === '' || $secret === '') {
            return null;
        }

        return $this->deviceAuth->authenticate($deviceKey, $secret);
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json(['message' => 'Unauthorized.'], 401);
    }
}
