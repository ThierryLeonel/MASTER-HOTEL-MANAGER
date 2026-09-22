<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9.7.2. One row per (device, direction): where this installation's
 * sync with that device last left off, so the outbox (Phase 9.7.3's
 * SyncService) knows which rows still need to go out ("outbound": every
 * syncable row changed since this watermark) and the apply step knows it
 * has already caught up to a given point ("inbound": the watermark from
 * the sender's own outbox as of the last successfully applied batch).
 * Deliberately a plain watermark timestamp, not a foreign key into any
 * business table, since the set of syncable tables (48 of them, per
 * Phase 9.7.1) is far too broad for one checkpoint to point at a single
 * row across all of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_device_id')->constrained()->cascadeOnDelete();
            $table->enum('direction', ['outbound', 'inbound'])->comment('outbound = what we have sent TO this device; inbound = what we have applied FROM this device.');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['sync_device_id', 'direction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_checkpoints');
    }
};
