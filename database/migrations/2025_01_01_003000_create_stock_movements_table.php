<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only inventory ledger (same pattern as folio_transactions
 * and the central `payments` table — corrections are new reversing/
 * adjustment rows, never edits or deletes). Every unit of stock that
 * ever enters or leaves the building is exactly one row here, always
 * tied to the specific FIFO batch it was drawn from or added to, so
 * the true cost of every issue is traceable back to what was actually
 * paid for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('stock_batch_id')->constrained('stock_batches')->restrictOnDelete();
            $table->enum('type', ['receipt', 'issue', 'wastage', 'adjustment', 'requisition']);
            $table->enum('direction', ['in', 'out']);
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_cost', 12, 2);
            $table->decimal('total_cost', 14, 2);
            $table->string('source_module');
            $table->string('source_reference')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'recorded_at']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
