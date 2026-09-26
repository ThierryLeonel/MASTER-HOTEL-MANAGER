<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * New feature round ("invoice numberings ... should be automatically
 * generated"): the folio itself never had a human-facing number at all —
 * `StayController::printInvoice()` rendered "Guest Invoice" straight off
 * the folio's ledger with nothing to identify that specific document by.
 * This adds a system-generated `invoice_number`, set once when the folio
 * opens at check-in (see CheckInService::openFolio()) and printed on the
 * invoice/final-bill view.
 *
 * Nullable and additive: every folio opened before this deploys keeps
 * `invoice_number` null — its printed invoice falls back to a label built
 * from the folio id rather than having history rewritten with a number
 * that was never actually assigned at the time.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('folios', 'invoice_number')) {
            Schema::table('folios', function (Blueprint $table) {
                $table->string('invoice_number', 40)->nullable()->unique()->after('stay_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('folios', 'invoice_number')) {
            Schema::table('folios', function (Blueprint $table) {
                $table->dropUnique(['invoice_number']);
                $table->dropColumn('invoice_number');
            });
        }
    }
};
