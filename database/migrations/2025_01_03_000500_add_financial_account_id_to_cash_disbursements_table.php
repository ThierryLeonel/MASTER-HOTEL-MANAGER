<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §21): "same goes for
 * cash disbursement" — required exactly like Expense's, see
 * CashDisbursementService::disburse()'s docblock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_disbursements', function (Blueprint $table) {
            $table->foreignId('financial_account_id')->nullable()->after('method')
                ->constrained('financial_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cash_disbursements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('financial_account_id');
        });
    }
};
