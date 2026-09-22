<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approval-gate pattern already used for MaintenanceTicket (Phase 1)
 * and StockWastage (Phase 3): recording a request never by itself
 * changes a balance or attendance record — only LeaveService::approve()
 * does both, atomically, in one DB transaction — so a disputed or
 * mis-keyed request is simply rejected/cancelled rather than needing
 * to be "undone".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->comment('The employee taking the leave.');
            $table->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('days_requested', 5, 1);
            $table->string('reason');
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
