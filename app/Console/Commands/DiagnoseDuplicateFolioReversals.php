<?php

namespace App\Console\Commands;

use App\Models\FolioTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Correction round (audited defect #18): diagnostic-only command that
 * surfaces any FolioTransaction rows that reverse the same original
 * transaction more than once — a state the app-level lock in
 * FolioService::void() and the DB-level unique constraint (migration
 * 2025_02_01_001100) both now prevent going forward, but that could exist
 * in data recorded before this correction round.
 *
 * Never deletes or modifies anything: per the brief's explicit
 * instruction, remediating historical financial records requires informed
 * human judgement about which reversal (if any) was the mistaken one, not
 * an automated script guessing.
 */
class DiagnoseDuplicateFolioReversals extends Command
{
    protected $signature = 'folio:diagnose-duplicate-reversals';

    protected $description = 'List folio_transactions rows that reverse the same original transaction more than once (audited defect #18) — read-only, makes no changes.';

    public function handle(): int
    {
        $duplicateGroups = DB::table('folio_transactions')
            ->select('reverses_transaction_id', DB::raw('COUNT(*) as cnt'))
            ->whereNotNull('reverses_transaction_id')
            ->groupBy('reverses_transaction_id')
            ->having('cnt', '>', 1)
            ->get();

        if ($duplicateGroups->isEmpty()) {
            $this->info('No duplicate reversals found. The unique constraint on reverses_transaction_id can be applied safely (run pending migrations if it has not been added yet).');

            return self::SUCCESS;
        }

        $this->warn("Found {$duplicateGroups->count()} original transaction(s) with more than one reversing entry:");

        foreach ($duplicateGroups as $group) {
            $original = FolioTransaction::find($group->reverses_transaction_id);
            $reversals = FolioTransaction::where('reverses_transaction_id', $group->reverses_transaction_id)
                ->orderBy('id')
                ->get();

            $this->line('');
            $this->line(
                "Original transaction #{$group->reverses_transaction_id}".
                ($original
                    ? " (folio #{$original->folio_id}, type={$original->type}, amount={$original->amount}, voided_at=".($original->voided_at ?? 'null').')'
                    : ' — original row not found (deleted?)')
            );

            foreach ($reversals as $reversal) {
                $this->line("  -> reversal #{$reversal->id}: amount={$reversal->amount}, posted_at={$reversal->created_at}, by user #{$reversal->user_id}, description=\"{$reversal->description}\"");
            }
        }

        $this->line('');
        $this->warn('These rows are NOT modified automatically. Review each group by hand and, if genuinely duplicated, resolve with a correcting adjustment referencing the extra reversal — never by deleting historical financial records. Once resolved, re-run the pending unique-constraint migration to prevent recurrence.');

        return self::FAILURE;
    }
}
