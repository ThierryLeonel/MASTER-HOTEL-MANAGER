<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory "products" are stock-keeping items — raw materials or
 * resale goods (a case of beer, a kg of rice, a bottle of vodka) — as
 * distinct from POS `menu_items`, which are what a guest orders. A
 * menu item may consume one or more products via `recipe_items`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('name');
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->string('category')->nullable();
            $table->decimal('reorder_level', 12, 3)->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
