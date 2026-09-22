<?php

namespace App\Console\Commands;

use App\Services\Integrity\DataIntegrityCheckerService;
use Illuminate\Console\Command;

/**
 * Phase 9.6 (PHASE9_AUDIT_AND_PLAN.md §3.7): `php artisan integrity:check`
 * — a real, runnable command a cron job (or the deploy helper, see
 * DeployHelperController::integrityCheck()) can call. Exits non-zero on
 * any failed check specifically so it's usable as a monitoring/CI gate,
 * not just a human-readable report.
 */
class RunIntegrityCheck extends Command
{
    protected $signature = 'integrity:check';

    protected $description = 'Run the data integrity checks (folio balances, Accounts Receivable, room/stay consistency, stock, purchase orders) and report any discrepancies found.';

    public function handle(DataIntegrityCheckerService $checker): int
    {
        $results = $checker->run();

        foreach ($results as $result) {
            if ($result['passed']) {
                $this->info("[PASS] {$result['label']}");

                continue;
            }

            $this->error("[FAIL] {$result['label']} — {$result['issue_count']} issue(s):");
            foreach ($result['issues'] as $issue) {
                $this->line("    - {$issue}");
            }
        }

        $failed = $checker->hasFailures($results);

        $this->newLine();
        $this->line($failed
            ? 'Data integrity check found real issues — see above.'
            : 'Data integrity check found no issues.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
