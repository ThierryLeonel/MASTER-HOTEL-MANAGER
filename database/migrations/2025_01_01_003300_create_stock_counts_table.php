<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A physical stock count. Starting one snapshots the live system
 * quantity for every counted product (stock_count_items.system_quantity)
 * so a later dispute over "what did the system say at count time" can
 * always be answered even after further movements have happened since.
 * Completing it posts one adjustment stock_movement per line with a
 * non-zero variance, through the same FIFO engine as every other
 * inbound/outbound movement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->string('count_number')->unique();
            $table->enum('status', ['in_progress', 'completed', 'cancelled'])->default('in_progress');
            $table->foreignId('started_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_counts');
    }
};
