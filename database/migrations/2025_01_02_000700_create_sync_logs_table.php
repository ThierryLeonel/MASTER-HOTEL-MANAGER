<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9.7.2. General-purpose, append-only sync activity log (device
 * registered, batch sent/received/failed, conflict detected, and so on) —
 * what Phase 9.7.6's sync dashboard reads from. Mirrors `audit_logs`'
 * own shape and its `$timestamps = false` + explicit `created_at`
 * convention deliberately (see App\Services\AuditLogger and its
 * migration's docblock) rather than inventing a second logging
 * convention for the same kind of thing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_device_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('level', ['info', 'warning', 'error'])->default('info')->index();
            $table->string('event', 60)->comment('e.g. device_registered, batch_sent, batch_received, batch_applied, conflict_detected');
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
    }
};
