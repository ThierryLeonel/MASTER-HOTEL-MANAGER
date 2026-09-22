<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per user per calendar day (`unique(user_id, work_date)`) —
 * self clock-in/clock-out writes here, and so does an approved leave
 * request (status=on_leave for every date in its range) and a manual
 * correction by a manager. PayrollService reads `status = absent` rows
 * in a run's period to compute the unpaid-absence deduction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('work_date');
            $table->dateTime('clock_in_at')->nullable();
            $table->dateTime('clock_out_at')->nullable();
            $table->enum('status', ['present', 'absent', 'late', 'on_leave', 'holiday'])->default('present');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'work_date']);
            $table->index(['work_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
