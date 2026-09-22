<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §22). Optional —
 * front desk can mark a stay "billed to" a company at check-in (walk-in
 * or from a reservation). A stay with no company is completely
 * unaffected; this is purely additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stays', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('guest_id')
                ->constrained('companies')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stays', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
