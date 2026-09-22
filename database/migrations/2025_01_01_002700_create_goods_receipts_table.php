<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A goods receipt records stock physically arriving. It can be tied to
 * a purchase order (normal path, supports partial receiving across
 * several receipts) or stand alone (`purchase_order_id` null) for an
 * ad-hoc/cash purchase that never had a formal PO. Either way, every
 * receipt line creates a real stock_batch — this is the only place new
 * stock enters the system other than a positive stock-count adjustment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number')->unique();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('received_at');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipts');
    }
};
