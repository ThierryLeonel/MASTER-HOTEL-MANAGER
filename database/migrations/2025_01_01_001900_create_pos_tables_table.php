<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Named `pos_tables` (not `tables`) to avoid any ambiguity with the
     * schema's own vocabulary and to keep the table name unambiguous in
     * raw SQL/tooling — this row represents a physical bar/restaurant
     * table, unrelated to the hotel room `rooms` table.
     */
    public function up(): void
    {
        Schema::create('pos_tables', function (Blueprint $table) {
            $table->id();
            $table->enum('outlet', ['bar', 'restaurant'])->index();
            $table->string('label', 30)->comment('e.g. "Bar 1", "T4", "Terrace 2"');
            $table->unsignedTinyInteger('seats')->default(2);
            $table->enum('status', ['available', 'occupied'])->default('available');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['outlet', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_tables');
    }
};
