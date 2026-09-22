<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Stay represents an active or completed occupancy of a specific room
     * by a specific guest — created at check-in (from a confirmed
     * Reservation, or directly for a walk-in) and closed at check-out.
     * Exactly one open (status=active) Stay may exist per room at a time;
     * enforced in App\Services\FrontOffice\CheckInService via a DB
     * transaction + row lock, not just at the application layer.
     */
    public function up(): void
    {
        Schema::create('stays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->nullable()->constrained('reservations')->nullOnDelete();
            $table->foreignId('guest_id')->constrained('guests')->restrictOnDelete();
            $table->foreignId('room_id')->constrained('rooms')->restrictOnDelete();

            $table->timestamp('check_in_at');
            $table->date('expected_check_out_date');
            $table->timestamp('actual_check_out_at')->nullable();

            $table->decimal('agreed_rate', 12, 2)->comment('Nightly rate charged to the folio for each night of this stay.');
            $table->enum('status', ['active', 'closed'])->default('active')->index();

            $table->foreignId('checked_in_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('checked_out_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stays');
    }
};
