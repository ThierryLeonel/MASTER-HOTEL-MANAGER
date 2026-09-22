<?php

namespace App\Services\Reporting;

use Illuminate\Support\Carbon;

/**
 * A single screen combining the headline number from each report
 * service for a date range — every figure here is delegated to the
 * service that owns it (never recomputed independently), so the
 * dashboard can never show a number that disagrees with its own
 * detail report.
 */
class KpiDashboardService
{
    public function __construct(
        private readonly FinancialReportService $financial,
        private readonly OperationsReportService $operations,
    ) {
    }

    public function forRange(Carbon $start, Carbon $end): array
    {
        $occupancy = $this->operations->occupancy($start, $end);
        $revenue = $this->financial->revenueByChargeType($start, $end);
        $cashFlow = $this->financial->cashFlow($start, $end);
        $expenses = $this->financial->expenseSummary($start, $end);

        return [
            'occupancy_rate' => $occupancy['occupancy_rate'],
            'adr' => $occupancy['adr'],
            'revpar' => $occupancy['revpar'],
            'total_revenue' => $revenue['total'],
            'total_expenses_paid' => $expenses['total_paid'],
            'cash_in' => $cashFlow['cash_in'],
            'cash_out' => $cashFlow['total_cash_out'],
            'net_cash_flow' => $cashFlow['net'],
        ];
    }
}
