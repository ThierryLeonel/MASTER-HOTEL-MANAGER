<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configurable leave policy (spec section 23: never hardcoded) —
 * Owner/GM define what kinds of leave exist and how many days per
 * year each entitles an employee to, editable from the UI without a
 * code deploy. `max_days_per_year = 0` means "not balance-tracked"
 * (e.g. unpaid leave), so LeaveService never enforces a balance guard
 * for that type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->boolean('is_paid')->default(true);
            $table->unsignedSmallInteger('max_days_per_year')->default(0)->comment('0 = unlimited / not balance-tracked.');
            $table->boolean('requires_approval')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
