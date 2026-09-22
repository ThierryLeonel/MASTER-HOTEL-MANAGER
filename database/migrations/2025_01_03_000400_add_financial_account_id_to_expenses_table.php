<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §21): "for expenses
 * specify the particular financial account (bank, cash, orange money,
 * MTN mobile money, etc.) the money is leaving for the expense." Unlike
 * `payments.financial_account_id` (auto-defaulted where safe),
 * ExpenseController::pay() now REQUIRES this — see ExpenseService::
 * markPaid()'s docblock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('financial_account_id')->nullable()->after('payment_method')
                ->constrained('financial_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('financial_account_id');
        });
    }
};
