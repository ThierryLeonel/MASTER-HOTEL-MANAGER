<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A department (e.g. Bar) requesting stock out of the central store.
 * Lifecycle: pending -> approved (approval performs the actual FIFO
 * issue, all-or-nothing across every line, inside one DB transaction)
 * or rejected. There is deliberately no separate "issued" state:
 * unlike a purchase order (which can be partially received over several
 * deliveries), a requisition either can be fully satisfied right now or
 * it can't — modelling a third state would add a step without a real
 * different fact to describe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_requisitions', function (Blueprint $table) {
            $table->id();
            $table->string('requisition_number')->unique();
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_requisitions');
    }
};
