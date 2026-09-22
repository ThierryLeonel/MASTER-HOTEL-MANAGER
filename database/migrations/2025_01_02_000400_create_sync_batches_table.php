<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9.7.2. One row per batch attempt, in either direction. `ulid` here
 * is the batch's OWN identity (assigned by whichever side originates the
 * batch, via Str::ulid() in Phase 9.7.3's SyncService — not the generic
 * `HasSyncUlid` trait from Phase 9.7.1, since a batch is never itself
 * replicated as business data) and is the idempotency key: before
 * applying an inbound batch, the apply step checks whether a row with
 * that ulid + direction=inbound already exists and is already `applied`,
 * and if so skips it rather than re-applying — this is what makes
 * replaying the same batch twice safe, per §3.1's requirement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_batches', function (Blueprint $table) {
            $table->id();
            $table->string('ulid')->unique();
            $table->foreignId('sync_device_id')->constrained()->cascadeOnDelete()->comment('The counterparty device this batch was sent to (outbound) or received from (inbound).');
            $table->enum('direction', ['outbound', 'inbound']);
            $table->enum('status', ['pending', 'applied', 'failed'])->default('pending')->index();
            $table->unsignedInteger('item_count')->default(0);
            $table->string('checksum')->nullable()->comment('Optional integrity check of the payload, verified before apply.');
            $table->json('payload')->nullable()->comment('The raw batch content, kept for audit/replay/debugging.');
            $table->timestamp('applied_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_batches');
    }
};
