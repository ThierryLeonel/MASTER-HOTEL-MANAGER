<?php

namespace App\Console\Commands;

use App\Services\Sync\SyncableModels;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 9.7.3 (PHASE9_AUDIT_AND_PLAN.md §6, Phase 9.7 sub-plan). The
 * `HasSyncUlid` trait (Phase 9.7.1) only assigns a ulid at the moment a
 * row is CREATED — every row that already existed before the Phase 9.7.1
 * migration ran has a `ulid` of NULL and would be silently invisible to
 * SyncService::buildOutboxBatch(), which deliberately only scans rows
 * that already have one (see that method's own docblock). This command
 * is the one-time fix: it must be run once, after migrating, before the
 * sync engine is used on an installation with pre-existing data. Running
 * it again afterward is harmless — it only ever touches rows that are
 * still NULL.
 *
 * Uses the query builder (`DB::table`), not Eloquent, so this never fires
 * model events/observers for 48 different model classes over what could
 * be a large amount of historical data — it is a one-off maintenance
 * operation, not a stream of "row created" events.
 */
class BackfillSyncUlids extends Command
{
    protected $signature = 'sync:backfill-ulids';

    protected $description = 'Assign a sync ulid to every existing row across all syncable tables that does not have one yet.';

    public function handle(): int
    {
        $totalAssigned = 0;

        foreach (SyncableModels::ALL as $class) {
            $table = (new $class())->getTable();
            $assigned = 0;

            DB::table($table)->whereNull('ulid')->select('id')
                ->chunkById(500, function ($rows) use ($table, &$assigned) {
                    foreach ($rows as $row) {
                        DB::table($table)->where('id', $row->id)->update(['ulid' => (string) Str::ulid()]);
                    }
                    $assigned += count($rows);
                });

            if ($assigned > 0) {
                $this->line("  {$table}: assigned {$assigned}");
            }
            $totalAssigned += $assigned;
        }

        $this->info("Done. Assigned a ulid to {$totalAssigned} previously-unsynced row(s) across ".count(SyncableModels::ALL).' tables.');

        return self::SUCCESS;
    }
}
