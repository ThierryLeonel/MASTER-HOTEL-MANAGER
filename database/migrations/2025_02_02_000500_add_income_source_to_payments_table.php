<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Direct request ("all in payment must be attached to an income source
 * that is to be represented or seen in the income statement"): the
 * general-purpose "record a payment" screen (PaymentController — for
 * money that doesn't belong to a folio/POS order, e.g. rent from a
 * visitor) had no classification at all beyond a free-text `notes` field,
 * so none of that income was ever visible on the Income Statement. This
 * column is that classification — free text, same convention as
 * `expenses.category` — required by PaymentController::store() (see its
 * validation) but left nullable at the schema level so every other kind
 * of payment (folio, POS, expense, disbursement, payroll — all already
 * self-describing via `source_module`/`reference_type`) is unaffected.
 *
 * Nullable and additive: existing payments are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payments', 'income_source')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->string('income_source', 120)->nullable()->after('notes');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payments', 'income_source')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropColumn('income_source');
            });
        }
    }
};
