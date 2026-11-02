<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Correction round (code-review pass, Nov 2026): CashReconciliationService::
 * record() already refuses a second reconciliation for a business date
 * that already has one, but it did so purely with an application-level
 * "does a row already exist?" check before the insert — nothing stopped
 * two concurrent requests (e.g. the Day Close screen double-submitted, or
 * two managers racing to close the same day) from both passing that check
 * and both inserting a row for the same date. This adds the database-level
 * guarantee the application check was only approximating, matching the
 * same "can't happen twice" pattern already enforced in the database for
 * every other once-per-day record in this system (e.g. accounting_days).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_reconciliations', function (Blueprint $table) {
            $table->unique('business_date');
        });
    }

    public function down(): void
    {
        Schema::table('cash_reconciliations', function (Blueprint $table) {
            $table->dropUnique(['business_date']);
        });
    }
};
