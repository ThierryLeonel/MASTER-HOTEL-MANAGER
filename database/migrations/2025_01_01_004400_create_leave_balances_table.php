<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per user/leave-type/year. `entitled_days` is copied from
 * `leave_types.max_days_per_year` the first time it's needed (so a
 * later policy change doesn't retroactively rewrite an employee's
 * already-granted entitlement for a year in progress); `used_days` is
 * incremented only by LeaveService::approve(), never decremented
 * directly — a cancelled/rejected request never touched it in the
 * first place, matching the append-only-effect pattern used
 * throughout (a correction is a new transaction, not an edit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('entitled_days', 5, 1);
            $table->decimal('used_days', 5, 1)->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'leave_type_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_balances');
    }
};
