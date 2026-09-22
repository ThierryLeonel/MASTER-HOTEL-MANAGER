<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Correction round (audited defect #18, "prevent multiple reversals of
 * the same transaction — locking + DB constraints"): FolioService::void()
 * already re-locks and rechecks the original transaction row inside its
 * own DB::transaction() before inserting a reversing 'adjustment' row —
 * that is the LOCKING half of this defect. This migration adds the DB
 * CONSTRAINT half the brief explicitly also asks for: a unique index on
 * `reverses_transaction_id`, so that even a future code path that creates
 * a FolioTransaction directly (bypassing FolioService::void()), or two
 * connections that somehow raced past the application-level lock, are
 * refused by the database itself rather than silently producing a second
 * reversal of the same original transaction.
 *
 * Per the brief's explicit instruction ("do not silently delete
 * duplicates or rewrite historical financial records — supply diagnostic
 * and remediation instead"), this migration never touches existing data.
 * If any pre-existing duplicate reversals are found (more than one
 * folio_transactions row sharing the same non-null
 * reverses_transaction_id — possible from before this correction round's
 * void() locking fix), the unique index is intentionally NOT added and a
 * clear diagnostic is logged instead. Run
 * `php artisan folio:diagnose-duplicate-reversals` to see exactly which
 * rows are involved; once a human has reviewed and resolved them (never
 * by deleting the historical rows — see that command's own output for the
 * recommended approach), re-running this migration will add the
 * constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('folio_transactions')
            ->select('reverses_transaction_id', DB::raw('COUNT(*) as cnt'))
            ->whereNotNull('reverses_transaction_id')
            ->groupBy('reverses_transaction_id')
            ->having('cnt', '>', 1)
            ->get();

        if ($duplicates->isNotEmpty()) {
            $ids = $duplicates->pluck('reverses_transaction_id')->implode(', ');

            // Deliberately not thrown — failing this migration would block
            // every other pending migration in the same batch. Logged
            // loudly instead; `folio:diagnose-duplicate-reversals` surfaces
            // the same information on demand at any later point.
            Log::warning(
                'Skipping unique constraint on folio_transactions.reverses_transaction_id: '.
                "existing duplicate reversals found for original transaction id(s): {$ids}. ".
                'Run `php artisan folio:diagnose-duplicate-reversals` and see that command\'s '.
                'output for remediation before this constraint can be safely added.'
            );

            return;
        }

        Schema::table('folio_transactions', function (Blueprint $table) {
            $table->unique('reverses_transaction_id', 'folio_transactions_reverses_transaction_id_unique');
        });
    }

    public function down(): void
    {
        try {
            Schema::table('folio_transactions', function (Blueprint $table) {
                $table->dropUnique('folio_transactions_reverses_transaction_id_unique');
            });
        } catch (\Throwable $e) {
            // up() may have skipped adding the constraint (duplicate
            // reversals existed at migrate time) — nothing to roll back.
        }
    }
};
