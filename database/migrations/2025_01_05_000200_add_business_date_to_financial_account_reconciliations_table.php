<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fourth post-9.8 fix round (day-close rebuild, PHASE9_AUDIT_AND_PLAN.md
 * §21/§23): "fix the day close and cash reconciliation because it is not
 * working it is just there, it should be able to capture and reconcile
 * the payments... per day per shift."
 *
 * The root design problem this round fixes: `financial_account_reconciliations`
 * (FinancialAccountService::reconcile()) already reconciles a real
 * account — Cash, Bank, MTN, Orange — against the ledger's own running
 * balance for that account, which is exactly what a day close needs. But
 * it was never day-scoped, so nothing stopped an account being
 * "reconciled" twice in one day (or never), and — critically —
 * AccountingDayService::lock() never looked at it at all: it gated
 * locking the whole business day on the OLD, whole-hotel, cash-only
 * `cash_reconciliations` table instead (CashReconciliationService),
 * which is exactly the "it is just there" feature the owner is
 * describing — a vestigial check disconnected from the real
 * per-account model everything else in Finance already uses.
 *
 * `business_date` makes a reconciliation row belong to a specific
 * calendar day, so:
 *   - FinancialAccountService::reconcile() can refuse a second
 *     reconciliation of the same account on the same day (an honest
 *     mistake, not a way to game the count).
 *   - AccountingDayService::lock() can require every active account
 *     reconciled for *today's* business_date before the day locks —
 *     the real, per-account gate the owner asked for, replacing the
 *     old whole-hotel cash-only one.
 *
 * Nullable, not backfilled with a hard NOT NULL constraint (this
 * project's migration-safety convention — see the financial account
 * reconciliations table's own original migration for the matching
 * `Schema::hasTable()` guard rationale, and PHASE9_AUDIT_AND_PLAN.md's
 * notes on avoiding `->change()` on a column already carrying production
 * data). Existing historical rows are backfilled from the date portion
 * of their own `reconciled_at` timestamp — the closest true business
 * date available for data recorded before this column existed — so
 * nothing already reconciled loses its place in history; only rows
 * created going forward are expected to always set this explicitly
 * (FinancialAccountService::reconcile() always passes one).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('financial_account_reconciliations', 'business_date')) {
            Schema::table('financial_account_reconciliations', function (Blueprint $table) {
                $table->date('business_date')->nullable()->after('financial_account_id');
            });
        }

        DB::table('financial_account_reconciliations')
            ->whereNull('business_date')
            ->whereNotNull('reconciled_at')
            ->update(['business_date' => DB::raw('DATE(reconciled_at)')]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('financial_account_reconciliations', 'business_date')) {
            Schema::table('financial_account_reconciliations', function (Blueprint $table) {
                $table->dropColumn('business_date');
            });
        }
    }
};
