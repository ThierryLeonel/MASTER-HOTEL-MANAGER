<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §21). Nullable and
 * left NULL on historical rows, same going-forward-only attribution
 * pattern as `payments.department_id` before it. Auto-defaulted by
 * PaymentLedgerService for the unambiguous methods (cash -> the Cash
 * account, bank_transfer/card -> the Bank account) when a caller doesn't
 * pass one explicitly — see that class's docblock for the one
 * deliberately-not-yet-covered case (mobile_money, which needs a human
 * to say MTN or Orange).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('financial_account_id')->nullable()->after('method')
                ->constrained('financial_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('financial_account_id');
        });
    }
};
