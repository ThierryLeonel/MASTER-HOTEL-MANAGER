<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wastage requires approval before stock is actually deducted (same
 * approval-gate shape as MaintenanceTicket in Phase 1): recording
 * wastage never by itself changes stock on hand, only an approval
 * does — so a mistaken or disputed report never has to be "undone".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_wastages', function (Blueprint $table) {
            $table->id();
            $table->string('wastage_number')->unique();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->string('reason');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_wastages');
    }
};
