<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bill-of-materials link between a POS menu item and the inventory
 * products it consumes (e.g. "1x Grilled Fish & Plantain" consumes
 * 0.3kg Fish and 0.25kg Plantain). A menu item with no recipe_items
 * rows is simply not tracked against stock — this keeps Phase 2's POS
 * fully backward compatible for outlets that don't want to manage
 * inventory at the recipe level (e.g. a resold bottled drink could
 * either get a 1:1 recipe row against its own product, or be left
 * untracked entirely; both are valid, the system doesn't force either).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_item_id')->constrained('menu_items')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity_per_unit', 12, 3);
            $table->timestamps();

            $table->unique(['menu_item_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_items');
    }
};
