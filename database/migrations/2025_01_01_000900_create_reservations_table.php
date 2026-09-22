<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reservation lifecycle (spec section 10):
     *   pending -> approved -> confirmed -> checked_in -> checked_out
     *                       -> rejected
     *   pending/confirmed -> cancelled
     *   confirmed -> no_show (guest never arrived by cutoff)
     *
     * A walk-in guest skips straight to a Stay without a Reservation row.
     */
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->string('reservation_number', 30)->unique();
            $table->foreignId('guest_id')->constrained('guests')->restrictOnDelete();
            $table->foreignId('room_category_id')->constrained('room_categories')->restrictOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete()
                ->comment('Assigned at approval/check-in time; null while only a category is requested.');

            $table->date('check_in_date');
            $table->date('check_out_date');
            $table->unsignedTinyInteger('adults')->default(1);
            $table->unsignedTinyInteger('children')->default(0);

            $table->enum('status', [
                'pending', 'approved', 'rejected', 'confirmed',
                'checked_in', 'checked_out', 'no_show', 'cancelled',
            ])->default('pending')->index();

            $table->decimal('rate_quoted', 12, 2)->comment('Nightly rate agreed for this reservation; may differ from room_categories.base_rate.');
            $table->decimal('deposit_required', 12, 2)->default(0);
            $table->decimal('deposit_paid', 12, 2)->default(0);

            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['check_in_date', 'check_out_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
