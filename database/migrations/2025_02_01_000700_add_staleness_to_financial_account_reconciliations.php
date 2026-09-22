<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Correction round (audited defect #29): if a new payment lands in an
     * account after that account was reconciled for the business date, the
     * existing reconciliation's `expected_balance` silently becomes wrong
     * with nothing to flag it, and AccountingDayService::lock() had no way
     * to tell. PaymentLedgerService::record() (this delivery) now flags
     * the same-day reconciliation stale the moment a later payment posts
     * against that account/date; AccountingDayService::lock() now also
     * refuses to lock while any of today's reconciliations are stale.
     */
    public function up(): void
    {
        // business_date itself already exists — added by
        // 2025_01_05_000200_add_business_date_to_financial_account_reconciliations_table.php.
        // This migration only adds the new staleness-tracking columns.
        Schema::table('financial_account_reconciliations', function (Blueprint $table) {
            $table->boolean('is_stale')->default(false)->after('variance');
            $table->timestamp('invalidated_at')->nullable()->after('is_stale');
            $table->text('invalidated_reason')->nullable()->after('invalidated_at');
        });
    }

    public function down(): void
    {
        Schema::table('financial_account_reconciliations', function (Blueprint $table) {
            $table->dropColumn(['is_stale', 'invalidated_at', 'invalidated_reason']);
        });
    }
};
