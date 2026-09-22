<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique()->comment('e.g. Standard, Deluxe, Suite, Apartment');
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('max_occupancy')->default(2);
            $table->decimal('base_rate', 12, 2)->comment('Nightly rate in XAF; the authoritative default — reservations may quote a different rate_quoted with reason.');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_categories');
    }
};
