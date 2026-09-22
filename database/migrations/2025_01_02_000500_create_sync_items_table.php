<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9.7.2. One row per synced record within a batch — the granular
 * unit `sync_conflicts` (next migration) hangs off of. `syncable_type` +
 * `syncable_ulid` (not a foreign key: the referenced row lives in one of
 * 48 different tables, per Phase 9.7.1, so this is a polymorphic
 * reference by design, resolved in application code the same way
 * `audit_logs.record_type`/`record_id` already is) identify exactly which
 * row, on which of the 48 syncable tables, this item concerns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_batch_id')->constrained()->cascadeOnDelete();
            $table->string('syncable_type')->comment('Fully qualified model class, e.g. App\\Models\\Guest.');
            $table->string('syncable_ulid');
            $table->enum('operation', ['create', 'update', 'delete']);
            $table->enum('status', ['pending', 'applied', 'conflict', 'skipped', 'failed'])->default('pending')->index();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['syncable_type', 'syncable_ulid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_items');
    }
};
