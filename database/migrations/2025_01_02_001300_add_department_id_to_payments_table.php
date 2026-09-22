<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Post-9.8 second fix round (PHASE9_AUDIT_AND_PLAN.md §18): "the
 * accountant... has to be making entries of reconciled cash payments per
 * departments." Every payment already carries a `source_module` string
 * (pos_bar, pos_restaurant, front_office, finance_expense, ...) that
 * tells you WHICH MODULE posted it, but not which of the existing
 * `departments` rows it belongs to for cash-handling/reconciliation
 * purposes — that mapping is derived automatically in
 * App\Services\PaymentLedgerService::record() (see its docblock) so
 * this column gets populated on every future payment without every
 * existing call site needing to change. Nullable, and left NULL on
 * historical rows — this is a going-forward attribution, not a
 * fabricated backfill of past data (spec section 40).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('source_module')
                ->constrained('departments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
        });
    }
};
