<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One cash-drawer count per business date. `expected_amount` is
 * computed live from the central `payments` ledger (net cash movement
 * for that date) at the moment the count is recorded, never hand
 * entered — the whole point of a reconciliation is comparing a real
 * ledger figure to what's physically in the drawer. Recording one is a
 * prerequisite for locking that accounting day (see AccountingDayService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->date('business_date')->unique();
            $table->decimal('expected_amount', 14, 2);
            $table->decimal('counted_amount', 14, 2);
            $table->decimal('variance', 14, 2);
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_reconciliations');
    }
};
