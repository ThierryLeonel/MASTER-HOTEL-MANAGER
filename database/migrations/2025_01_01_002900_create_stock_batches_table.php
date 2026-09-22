<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One FIFO lot of stock for a product. `quantity_remaining` is
 * decremented as the lot is consumed (oldest lot first — see
 * App\Services\Inventory\StockService::issueStock()); a batch is never
 * deleted once its quantity_remaining reaches zero, so it stays as a
 * permanent costing record of what was received and when.
 *
 * `source` is polymorphic (nullableMorphs) rather than a fixed FK
 * because a batch can originate from a goods receipt line OR a positive
 * stock-count adjustment — two different tables, same shape of fact
 * ("this many units entered stock at this cost on this date").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity_received', 12, 3);
            $table->decimal('quantity_remaining', 12, 3);
            $table->decimal('unit_cost', 12, 2);
            $table->timestamp('received_at');
            $table->nullableMorphs('source');
            $table->timestamps();

            $table->index(['product_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_batches');
    }
};
