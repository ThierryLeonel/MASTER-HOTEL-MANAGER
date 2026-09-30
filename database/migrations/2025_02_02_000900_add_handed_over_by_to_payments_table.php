<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Direct request (Sept 2026 refinement of the revenue-recognition fix):
 * "the place in the payment form that request on who handed the sales
 * should either be the department or a textbox that receive the name of
 * the person handing." `payments.department_id` (see
 * 2025_01_02_001300_add_department_id_to_payments_table.php) already
 * lets a general payment be formally attributed to a department for
 * revenue-recognition purposes (Bar, Restaurant, ...) — this column is
 * the other half: a free-text note of WHO physically handed the cash
 * over, for when there either is no clean department to pick, or the
 * accountant just wants to record the individual's name alongside
 * whichever department was chosen (e.g. "Ngozi, bar attendant" next to
 * "Bar sales handover"). Purely informational — unlike `department_id`,
 * it has no effect on revenue recognition, shift gating, or any report
 * total; it never substitutes for `recorded_by` (the staff member who
 * actually typed the payment into the system, which is very often a
 * different person from whoever physically brought the cash to them).
 *
 * Nullable and additive: existing payments are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payments', 'handed_over_by')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->string('handed_over_by', 120)->nullable()->after('department_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payments', 'handed_over_by')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropColumn('handed_over_by');
            });
        }
    }
};
