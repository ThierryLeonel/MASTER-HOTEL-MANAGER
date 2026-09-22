<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Correction round (audited defects #30 and #32).
     *
     * Defect #30: a payment's business day/shift used to be INFERRED after
     * the fact by comparing `recorded_at` against a day/shift's timestamp
     * window (CashReconciliationService/ShiftService's `whereBetween`
     * queries) — fragile right at midnight or a shift-change boundary.
     * `business_date`/`shift_id` are now stamped explicitly, once, at the
     * moment PaymentLedgerService::record() creates the row (see that
     * class in this delivery), so attribution is a fact recorded at
     * creation, never a guess made later by a report.
     *
     * Defect #32: `idempotency_key` lets a caller that's worried about a
     * retried submission (a double-click, a network retry on a deposit or
     * folio-payment form) pass a key that's unique per rendered form
     * — PaymentLedgerService::record() returns the EXISTING row instead of
     * creating a new one when the same key is submitted again, while a
     * genuinely separate instalment (a fresh page load, a new key) always
     * creates its own row. Nullable + unique-when-present, so every
     * existing caller that doesn't pass one is completely unaffected.
     *
     * Existing rows: `business_date`/`shift_id` are backfilled below on a
     * best-effort basis from `recorded_at`/the historical shift windows,
     * exactly the same heuristic the code used to rely on live — this is
     * strictly an improvement (an explicit, inspectable value going
     * forward) never a claim that the backfilled historical value is more
     * correct than what the report already showed for that period.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->date('business_date')->nullable()->after('recorded_at');
            $table->foreignId('shift_id')->nullable()->after('business_date')
                ->constrained('shifts')->nullOnDelete();
            $table->string('idempotency_key', 80)->nullable()->unique()->after('shift_id');
        });

        // Backfill is MySQL-only (the project's real deployment driver —
        // see README) and skipped entirely on other drivers (e.g. the
        // sqlite :memory: database the test suite runs against, which
        // never has pre-existing rows to backfill anyway, and doesn't
        // support this multi-table UPDATE syntax).
        if (DB::getDriverName() === 'mysql') {
            DB::statement('UPDATE payments SET business_date = DATE(recorded_at) WHERE business_date IS NULL');

            // Best-effort backfill of shift_id for existing rows: the shift
            // open for that payment's department whose [opened_at, closed_at]
            // window contains recorded_at. Left null (harmless — the column is
            // nullable and every report already tolerates "Unattributed") when
            // no such shift is found, e.g. a payment with no department.
            if (Schema::hasTable('shifts')) {
                DB::statement(<<<'SQL'
                    UPDATE payments p
                    JOIN shifts s ON s.department_id = p.department_id
                        AND p.recorded_at >= s.opened_at
                        AND p.recorded_at <= COALESCE(s.closed_at, NOW())
                    SET p.shift_id = s.id
                    WHERE p.shift_id IS NULL AND p.department_id IS NOT NULL
                SQL);
            }
        } else {
            DB::table('payments')->whereNull('business_date')->update(['business_date' => DB::raw('DATE(recorded_at)')]);
        }
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shift_id');
            $table->dropColumn(['business_date', 'idempotency_key']);
        });
    }
};
