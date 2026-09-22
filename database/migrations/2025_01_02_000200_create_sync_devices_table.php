<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9.7.2, revised in Phase 9.7.4 — see PHASE9_AUDIT_AND_PLAN.md §6's
 * Phase 9.7 sub-plan for the full "why this changed" writeup. Short
 * version: this table's original 9.7.2 shape stored a bcrypt hash of a
 * device's secret (`secret_hash`), which is exactly right for verifying
 * an INCOMING call but cannot be reversed — so once the transport was
 * actually designed in 9.7.4, it became clear this installation would
 * have no way to retrieve its own secret again to PRESENT on an OUTGOING
 * call to that same device. Since nothing here has ever run against a
 * real database (no MySQL in this sandbox — see §2), this migration
 * (never a `down()`-and-`up()`-again away from any real data) was
 * corrected directly rather than shipped broken and patched around
 * later, the same "catch it before calling it done" instinct behind
 * Phase 9.6's removed no-op CLI flag and Phase 9.5's `@section` fix.
 *
 * The corrected model: one row per counterpart device, holding a single
 * `shared_secret` BOTH installations already know (set once, out of
 * band, by whichever operator runs `sync:register-device` first and
 * hands the other operator the printed value to `--import` on their
 * side) — encrypted at rest via Laravel's `encrypted` cast (retrievable
 * with the app's own APP_KEY, unlike a one-way hash) since this same
 * value must be both verified on incoming calls AND presented on
 * outgoing ones. There is no more "is this installation's own row"
 * concept (the old `is_local` flag) — every row is symmetric: one
 * counterpart, one shared secret, one endpoint to reach them.
 *
 * This table is NOT one of the 48 tables that got a `ulid` column in
 * Phase 9.7.1 and does NOT use `HasSyncUlid`: a device row is created
 * independently on each installation during the registration handshake,
 * not replicated through the generic sync outbox the way business data
 * is — there is no "the same device row synced to both sides" concept to
 * give a shared identity to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('device_key')->unique()->comment('Public identifier both installations agree on for this relationship; not secret on its own.');
            $table->text('shared_secret')->nullable()->comment('Encrypted (Laravel encrypted cast, APP_KEY-based) — the one secret both sides know: verified on an incoming call, and decrypted to present on an outgoing one.');
            $table->string('endpoint_url')->nullable()->comment('Where THIS installation reaches that device to push/pull a batch; null if this installation never initiates a call to them (they only ever call in).');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_devices');
    }
};
