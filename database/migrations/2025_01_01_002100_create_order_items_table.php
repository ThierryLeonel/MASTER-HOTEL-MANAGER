<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `item_name`/`unit_price` are snapshotted at the time the line was
     * added rather than always read live from menu_items — so a later
     * price change or renamed/deleted menu item never rewrites history
     * on an order that already charged the guest the old price.
     */
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('menu_item_id')->nullable()->constrained('menu_items')->nullOnDelete();

            $table->string('item_name');
            $table->decimal('unit_price', 12, 2);
            $table->unsignedSmallInteger('quantity');
            $table->decimal('line_total', 14, 2);

            $table->text('notes')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();

            $table->foreignId('added_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
