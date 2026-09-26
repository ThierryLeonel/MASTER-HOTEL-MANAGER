<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Direct request ("the owner is suppose to be able to edit all
 * transaction[s]"): this system has no edit capability for any posted
 * financial transaction, for any role — the standing rule for this whole
 * engagement is "never rewrite historical financial records", and an
 * in-place edit of a posted amount would violate that and silently
 * invalidate every reconciliation/report computed since. The safe,
 * reversible mechanism this codebase already uses for exactly this
 * situation is void-then-correct — `FolioTransaction` already has
 * `voided_at`/`voided_by`/`void_reason` for folio charges/payments/
 * refunds (see its own migration). This is that same mechanism, applied
 * to the ONE central ledger every other kind of transaction in the system
 * already flows through (`payments` — see PaymentLedgerService's
 * docblock), so "edit all transactions" is handled uniformly in one place
 * rather than needing bespoke void logic per module (Expense,
 * CashDisbursement, Order, Payslip all already post through this table).
 *
 * A voided payment's row, amount, and every other original field are left
 * completely untouched — nothing here is ever rewritten. Every financial
 * total that sums `payments.amount` (account balances, shift/cash/
 * department reconciliation, the Income Statement, the Payment Summary
 * report) now excludes `voided_at IS NOT NULL` rows — see
 * PaymentLedgerService::void()'s docblock for the full list of call sites
 * updated alongside this migration.
 *
 * Nullable and additive: existing payments are untouched (voided_at null
 * means "not voided", exactly like FolioTransaction's own column).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payments', 'voided_at')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->timestamp('voided_at')->nullable()->after('income_source');
                $table->foreignId('voided_by')->nullable()->after('voided_at')
                    ->constrained('users')->nullOnDelete();
                $table->string('void_reason', 500)->nullable()->after('voided_by');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payments', 'voided_at')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropConstrainedForeignId('voided_by');
                $table->dropColumn(['voided_at', 'void_reason']);
            });
        }
    }
};
