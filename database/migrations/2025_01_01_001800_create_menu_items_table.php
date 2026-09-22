<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `outlet` is denormalized from the parent category onto the item
     * itself so POS order screens can filter/query menu items directly
     * without a join, and so it stays fixed even if a category is later
     * renamed. Inventory linkage (deducting stock on sale — spec section
     * 14) is intentionally not modeled yet; it arrives with the
     * Inventory phase as a nullable `stock_item_id` on this table rather
     * than being guessed at now.
     */
    public function up(): void
    {
        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_category_id')->constrained('menu_categories')->restrictOnDelete();
            $table->enum('outlet', ['bar', 'restaurant'])->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['outlet', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_items');
    }
};
