<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A refund against a guest folio requires approval before
 * FolioService::postRefund() is ever called (that method's own
 * docblock has said so since Phase 1) — this table and
 * App\Services\Finance\RefundService are that approval gate. Recording
 * a request never moves money; only approving one does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->string('refund_number')->unique();
            $table->foreignId('folio_id')->constrained('folios')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('reason');
            $table->string('method');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
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
        Schema::dropIfExists('refund_requests');
    }
};
