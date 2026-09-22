<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sixth post-9.8 fix round ("all payments are to be made in the accounts
 * that control the payment methods" — PHASE9_AUDIT_AND_PLAN.md §20.9):
 * every other money-moving action in this system (expenses, cash
 * disbursements, folio payments, POS closes, facility sales, AR
 * settlements, payroll, reservation deposits, general payments) requires
 * picking the specific FinancialAccount the money is leaving from/into,
 * with the posted `method` always derived from that account rather than
 * typed freehand — see FinancialAccount::paymentMethod(). Refund
 * approval was the one remaining money-out action still using a bare
 * `method` string (typed by whoever REQUESTED the refund, not by
 * whoever actually approves and pays it out) — this column is what lets
 * RefundService::approve() close that last gap the same way
 * ExpenseService::markPaid() already does.
 *
 * Nullable and additive: existing refund_requests rows (and any refund
 * approved before this deploys) are untouched — `method` on those rows
 * stays exactly as it was requested. This column is only ever set going
 * forward, at approval time.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('refund_requests', 'financial_account_id')) {
            Schema::table('refund_requests', function (Blueprint $table) {
                $table->foreignId('financial_account_id')->nullable()->after('method')
                    ->constrained('financial_accounts')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('refund_requests', 'financial_account_id')) {
            Schema::table('refund_requests', function (Blueprint $table) {
                $table->dropConstrainedForeignId('financial_account_id');
            });
        }
    }
};
