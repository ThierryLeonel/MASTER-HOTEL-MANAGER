<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9.7.2. Created when the (not-yet-built, Phase 9.7.3) apply step
 * finds that a row was changed on BOTH sides since the last successful
 * sync between them. Per §3.1's explicit requirement — "conflict
 * detection... with a resolution policy" — a conflict is always recorded
 * here and the incoming change is held back rather than silently
 * overwriting the local row; `sync_items.status` is set to `conflict`
 * rather than `applied` for that row. Resolution (who wins, or a manual
 * merge) is a human/admin action against this table, not an automatic
 * one — this migration only builds the record-keeping; the resolution
 * UI is Phase 9.7.6 (sync dashboard).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_conflicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_item_id')->constrained()->cascadeOnDelete();
            $table->string('syncable_type');
            $table->string('syncable_ulid');
            $table->json('local_data')->comment('Snapshot of the local row as it stood at the moment the conflict was detected.');
            $table->json('incoming_data')->comment('The incoming payload that could not be safely applied over it.');
            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('resolution', ['kept_local', 'kept_incoming', 'merged', 'ignored'])->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['syncable_type', 'syncable_ulid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_conflicts');
    }
};
