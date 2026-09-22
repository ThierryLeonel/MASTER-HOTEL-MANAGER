<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shift-based department cash reconciliation (post-9.8 second fix round,
 * PHASE9_AUDIT_AND_PLAN.md §19). The user explicitly corrected the first
 * proposal for this feature: "not really per day but in shifts because
 * they work in shifts." Each department (front desk, bar, restaurant,
 * bar outside, bucareaux) opens its own shift when staff start their
 * till/counter, and closes it — declaring the cash actually counted —
 * when they hand over, exactly like CashReconciliationService already
 * does once a day for the whole hotel, just scoped to one department and
 * one work period instead.
 *
 * Deliberately additive, not a replacement: the existing whole-hotel
 * daily CashReconciliation/AccountingDay lock (App\Services\Finance\
 * AccountingDayService) is untouched and keeps working exactly as
 * before — see App\Services\Finance\ShiftService's docblock for why
 * payments are NOT hard-gated on an open shift existing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->nullable()->unique();
            $table->string('shift_number', 40)->unique();
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('opened_at')->useCurrent();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->enum('status', ['open', 'closed'])->default('open')->index();
            $table->decimal('expected_cash', 14, 2)->nullable()->comment('Computed live from the payments ledger at close time — never hand entered.');
            $table->decimal('declared_cash', 14, 2)->nullable()->comment('What the department actually counted and handed in.');
            $table->decimal('variance', 14, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['department_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
