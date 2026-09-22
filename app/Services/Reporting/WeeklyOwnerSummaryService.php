<?php

namespace App\Services\Reporting;

use Illuminate\Support\Carbon;

/**
 * Phase 9.4 (PHASE9_AUDIT_AND_PLAN.md §3.5): "aggregated financials +
 * occupancy + a narrative-style rollup" for an Owner/GM who wants one
 * page summarizing the last week rather than visiting four separate
 * reports. Every figure is delegated to the report service that already
 * owns it (FinancialReportService, OperationsReportService) — this class
 * computes nothing on its own, so the summary can never disagree with
 * the detail reports it is built from. Same "management estimate" caveat
 * as incomeStatement() applies to the net-operating-result figure here.
 */
class WeeklyOwnerSummaryService
{
    public function __construct(
        private readonly FinancialReportService $financial,
        private readonly OperationsReportService $operations,
    ) {
    }

    /**
     * @return array{
     *   start: Carbon, end: Carbon,
     *   occupancy: array, statement: array, cash_flow: array, receivables: array
     * }
     */
    public function forWeekEnding(Carbon $end): array
    {
        $end = $end->copy()->startOfDay();
        $start = $end->copy()->subDays(6);

        return [
            'start' => $start,
            'end' => $end,
            'occupancy' => $this->operations->occupancy($start, $end),
            'statement' => $this->financial->incomeStatement($start, $end),
            'cash_flow' => $this->financial->cashFlow($start, $end),
            'receivables' => $this->financial->receivablesAging(),
        ];
    }
}
