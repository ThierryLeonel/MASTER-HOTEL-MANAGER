<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('room_number', 20)->unique();
            $table->foreignId('room_category_id')->constrained('room_categories')->restrictOnDelete();
            $table->string('floor', 20)->nullable();

            // Occupancy status is the source of truth for booking logic.
            $table->enum('status', ['available', 'occupied', 'maintenance', 'out_of_service'])->default('available')->index();

            // Housekeeping status is tracked independently of occupancy —
            // a room can be vacant but dirty, or occupied and serviced.
            $table->enum('housekeeping_status', ['clean', 'dirty', 'inspected'])->default('clean')->index();

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
