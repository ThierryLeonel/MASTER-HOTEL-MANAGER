<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §22). Copied from
 * the stay onto the Accounts Receivable record at checkout time
 * (CheckOutService::checkOut()) so a company's debt rolls up across every
 * stay it was billed for, even though each AR row still also keeps its
 * own folio_id/guest_id — a company's debt is a view over the same rows,
 * not a separate ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts_receivable', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('guest_id')
                ->constrained('companies')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('accounts_receivable', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
