<?php

namespace App\Services\Sync;

use App\Models\SyncDevice;
use Illuminate\Support\Str;

/**
 * Phase 9.7.2, revised in Phase 9.7.4. Device registration and
 * authentication for the sync engine's transport.
 *
 * **The correction this sub-phase made, and why**: the original 9.7.2
 * design hashed a device's secret with Hash::make()/Hash::check() —
 * exactly the right tool for verifying an INCOMING call, but by design
 * impossible to reverse. Once 9.7.4 actually built the transport, it
 * became clear this installation would have no way to retrieve its own
 * secret again to PRESENT when it initiates an OUTGOING call to that
 * same device — bidirectional sync needs both directions to work, and a
 * one-way hash only ever supports one of them. Since nothing here has
 * run against a real database (no MySQL in this sandbox — see §2), the
 * schema and this service were corrected directly rather than shipped
 * broken.
 *
 * **The corrected model**: exactly one shared secret per counterpart
 * relationship, known by both sides, stored ENCRYPTED (Laravel's
 * `encrypted` cast, APP_KEY-based — retrievable) rather than hashed,
 * because it must be both verified on incoming calls and presented on
 * outgoing ones. It is set once, out of band, by whichever operator runs
 * `register()` first (via `sync:register-device`) and hands the printed
 * device_key + secret to the OTHER operator, who records the identical
 * pair on their side via `import()` — deliberately NOT a network
 * handshake (there is no bootstrapping-trust-over-the-network step in
 * this design; the human operators are the trust anchor, the same role
 * they already play by hand-copying the deploy helper's DEPLOY_TOKEN
 * into .env).
 */
class DeviceAuthService
{
    /**
     * Run on whichever installation registers a counterpart FIRST.
     * Generates a fresh device_key + secret and returns them — the
     * secret exists in plaintext for exactly this one return value and
     * is never persisted anywhere unencrypted. The operator must copy
     * both values into the counterpart's own `import()` call.
     *
     * @return array{device: SyncDevice, secret: string}
     */
    public function register(string $name, ?string $endpointUrl = null): array
    {
        $secret = Str::random(48);

        $device = SyncDevice::create([
            'name' => $name,
            'device_key' => (string) Str::ulid(),
            'shared_secret' => $secret,
            'endpoint_url' => $endpointUrl,
            'is_active' => true,
        ]);

        return ['device' => $device, 'secret' => $secret];
    }

    /**
     * Run on the OTHER installation, with the exact device_key + secret
     * `register()` printed on the first one — records the same shared
     * secret rather than generating a new one, so both sides end up
     * agreeing on one value instead of each minting their own.
     */
    public function import(string $name, string $deviceKey, string $secret, ?string $endpointUrl = null): SyncDevice
    {
        return SyncDevice::create([
            'name' => $name,
            'device_key' => $deviceKey,
            'shared_secret' => $secret,
            'endpoint_url' => $endpointUrl,
            'is_active' => true,
        ]);
    }

    /**
     * Returns the authenticated device, or null if the key/secret pair
     * is wrong or belongs to a deactivated device. Deliberately returns
     * null rather than throwing: the (Phase 9.7.4) transport controller
     * turns this into an HTTP 401 without distinguishing which half was
     * wrong — the same "don't tell an attacker which half is wrong"
     * principle DeployHelperController::authorizeRequest() already
     * follows for the deploy helper's own token. hash_equals() keeps the
     * comparison itself timing-safe even though the value came from a
     * reversible cast rather than a one-way hash.
     */
    public function authenticate(string $deviceKey, string $secret): ?SyncDevice
    {
        $device = SyncDevice::where('device_key', $deviceKey)->where('is_active', true)->first();

        if (! $device || ! $device->shared_secret || ! hash_equals($device->shared_secret, $secret)) {
            return null;
        }

        $device->forceFill(['last_seen_at' => now()])->save();

        return $device;
    }

    /**
     * What THIS installation must present when it initiates a call to
     * $device — used by the transport client (Phase 9.7.4) and the
     * scheduled `sync:run` command (Phase 9.7.5). Fails loudly rather
     * than returning a null/empty secret if the relationship was somehow
     * never fully set up, since silently sending an empty credential
     * would just surface as a confusing 401 on the other end instead of
     * a clear error here.
     *
     * @return array{device_key: string, secret: string}
     */
    public function credentialsFor(SyncDevice $device): array
    {
        if (! $device->shared_secret) {
            throw new \RuntimeException("Device '{$device->name}' has no shared secret on file — run sync:register-device or sync:register-device --import-* to set one up.");
        }

        return ['device_key' => $device->device_key, 'secret' => $device->shared_secret];
    }

    public function revoke(SyncDevice $device): void
    {
        $device->update(['is_active' => false]);
    }
}
