<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * New feature round ("cash disbursement ... is to reference the financial
 * account were the disbursment is done and also the reason, expense, or
 * payment done"): the financial account and the "reason" (`purpose`) were
 * already there — this closes the one real gap, letting a disbursement
 * optionally point at the specific Expense it's paying out, for
 * accountability, without creating a second way to pay the same expense
 * twice. See CashDisbursementService::disburse()'s handling of
 * `expense_id`: it re-checks the expense is still 'approved' under a row
 * lock (the same guard ExpenseService::markPaid() uses) and marks it paid
 * itself — through ExpenseService::markPaidViaDisbursement(), which never
 * posts its own ledger entry — so the disbursement's own ledger post is
 * the ONE and only payment recorded, never two.
 *
 * `unique()` (nullable-safe — a unique index allows any number of NULLs)
 * so the same expense can never be attached to a second disbursement.
 * Nullable and additive: existing disbursements are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('cash_disbursements', 'expense_id')) {
            Schema::table('cash_disbursements', function (Blueprint $table) {
                $table->foreignId('expense_id')->nullable()->unique()->after('purpose')
                    ->constrained('expenses')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('cash_disbursements', 'expense_id')) {
            Schema::table('cash_disbursements', function (Blueprint $table) {
                $table->dropConstrainedForeignId('expense_id');
            });
        }
    }
};
