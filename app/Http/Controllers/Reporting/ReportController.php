<?php

namespace App\Http\Controllers\Reporting;

use App\Http\Controllers\Concerns\ExportsCsv;
use App\Http\Controllers\Controller;
use App\Services\Reporting\DepartmentReconciliationReportService;
use App\Services\Reporting\FinancialReportService;
use App\Services\Reporting\HrReportService;
use App\Services\Reporting\InventoryReportService;
use App\Services\Reporting\KpiDashboardService;
use App\Services\Reporting\OperationsReportService;
use App\Services\Reporting\PurchaseReportService;
use App\Services\Reporting\WeeklyOwnerSummaryService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReportController extends Controller
{
    use ExportsCsv;

    public function __construct(
        private readonly FinancialReportService $financial,
        private readonly OperationsReportService $operations,
        private readonly InventoryReportService $inventory,
        private readonly HrReportService $hr,
        private readonly KpiDashboardService $kpis,
        private readonly WeeklyOwnerSummaryService $weeklyOwnerSummary,
        private readonly DepartmentReconciliationReportService $departmentReconciliationService,
        private readonly PurchaseReportService $purchases,
    ) {
    }

    public function index()
    {
        return view('reports.index');
    }

    public function kpiDashboard(Request $request)
    {
        [$start, $end] = $this->range($request);
        $kpis = $this->kpis->forRange($start, $end);

        return view('reports.kpi-dashboard', compact('kpis', 'start', 'end'));
    }

    /**
     * Post-9.8 second fix round (PHASE9_AUDIT_AND_PLAN.md §21): "make
     * sure all reports are printable and can be periodically filtered."
     * Every report below already supported the date-range filter via the
     * shared range() helper — this print sibling is the piece that was
     * missing, following the exact same @extends('layouts.print') pattern
     * every other report's print view already uses (see e.g.
     * expense-report-print.blade.php), reusing the same data the screen
     * view already renders rather than recomputing anything.
     */
    public function kpiDashboardPrint(Request $request)
    {
        [$start, $end] = $this->range($request);
        $kpis = $this->kpis->forRange($start, $end);

        return view('reports.kpi-dashboard-print', compact('kpis', 'start', 'end'));
    }

    public function financial(Request $request)
    {
        [$start, $end] = $this->range($request);

        $revenue = $this->financial->revenueByChargeType($start, $end);
        $cashFlow = $this->financial->cashFlow($start, $end);
        $expenses = $this->financial->expenseSummary($start, $end);
        $receivables = $this->financial->receivablesAging();

        return view('reports.financial', compact('revenue', 'cashFlow', 'expenses', 'receivables', 'start', 'end'));
    }

    public function financialPrint(Request $request)
    {
        [$start, $end] = $this->range($request);

        $revenue = $this->financial->revenueByChargeType($start, $end);
        $cashFlow = $this->financial->cashFlow($start, $end);
        $expenses = $this->financial->expenseSummary($start, $end);
        $receivables = $this->financial->receivablesAging();

        return view('reports.financial-print', compact('revenue', 'cashFlow', 'expenses', 'receivables', 'start', 'end'));
    }

    /**
     * Phase 9.3: see FinancialReportService::incomeStatement()'s docblock
     * for why this is explicitly a management-style approximation, not a
     * formal double-entry Income Statement.
     */
    public function incomeStatement(Request $request)
    {
        [$start, $end] = $this->range($request);
        $statement = $this->financial->incomeStatement($start, $end);

        return view('reports.income-statement', compact('statement', 'start', 'end'));
    }

    public function incomeStatementPrint(Request $request)
    {
        [$start, $end] = $this->range($request);
        $statement = $this->financial->incomeStatement($start, $end);

        return view('reports.income-statement-print', compact('statement', 'start', 'end'));
    }

    /**
     * Phase 9.4 (PHASE9_AUDIT_AND_PLAN.md §3.5): defaults to the 7 days
     * ending today; ?week_ending=YYYY-MM-DD lets an Owner/GM pull up a
     * past week's rollup instead.
     */
    public function weeklyOwnerSummary(Request $request)
    {
        $weekEnding = $request->filled('week_ending')
            ? Carbon::parse($request->string('week_ending'))
            : Carbon::today();

        $summary = $this->weeklyOwnerSummary->forWeekEnding($weekEnding);

        return view('reports.weekly-owner-summary', $summary);
    }

    /**
     * Phase 9.5 (PHASE9_AUDIT_AND_PLAN.md §3.6): printable version of the
     * same data weeklyOwnerSummary() above renders — deliberately shares
     * the "management estimate" caveat and figures, never recomputed.
     */
    public function weeklyOwnerSummaryPrint(Request $request)
    {
        $weekEnding = $request->filled('week_ending')
            ? Carbon::parse($request->string('week_ending'))
            : Carbon::today();

        $summary = $this->weeklyOwnerSummary->forWeekEnding($weekEnding);

        return view('reports.weekly-owner-summary-print', $summary);
    }

    /**
     * Phase 9.5: the "Payment Summary" named document — every payment in
     * range from the central ledger, plus a by-method breakdown.
     */
    public function paymentSummary(Request $request)
    {
        [$start, $end] = $this->range($request);
        $summary = $this->financial->paymentSummary($start, $end);

        return view('reports.payment-summary', array_merge($summary, compact('start', 'end')));
    }

    public function paymentSummaryPrint(Request $request)
    {
        [$start, $end] = $this->range($request);
        $summary = $this->financial->paymentSummary($start, $end);

        return view('reports.payment-summary-print', array_merge($summary, compact('start', 'end')));
    }

    public function paymentSummaryCsv(Request $request)
    {
        [$start, $end] = $this->range($request);
        $summary = $this->financial->paymentSummary($start, $end);

        $rows = $summary['payments']->map(fn ($p) => [
            $p->recorded_at->toDateTimeString(),
            $p->payment_number,
            $p->direction,
            $p->method,
            $p->source_module,
            $p->guest?->full_name,
            $p->recordedBy?->name,
            $p->amount,
        ]);

        return $this->csvResponse(
            "payment-summary-{$start->toDateString()}-to-{$end->toDateString()}.csv",
            ['Date', 'Payment number', 'Direction', 'Method', 'Source module', 'Guest', 'Recorded by', 'Amount'],
            $rows
        );
    }

    /**
     * Phase 9.5: the "Expense Report" named document — every individual
     * expense in range, not just the category totals the Financial
     * report already shows.
     */
    public function expenseReport(Request $request)
    {
        [$start, $end] = $this->range($request);
        $report = $this->financial->expenseReport($start, $end);

        return view('reports.expense-report', array_merge($report, compact('start', 'end')));
    }

    public function expenseReportPrint(Request $request)
    {
        [$start, $end] = $this->range($request);
        $report = $this->financial->expenseReport($start, $end);

        return view('reports.expense-report-print', array_merge($report, compact('start', 'end')));
    }

    public function expenseReportCsv(Request $request)
    {
        [$start, $end] = $this->range($request);
        $report = $this->financial->expenseReport($start, $end);

        $rows = $report['expenses']->map(fn ($e) => [
            $e->expense_number,
            $e->created_at->toDateString(),
            $e->category,
            $e->description,
            $e->department?->name,
            $e->status,
            $e->requestedBy?->name,
            $e->approvedBy?->name,
            $e->amount,
        ]);

        return $this->csvResponse(
            "expense-report-{$start->toDateString()}-to-{$end->toDateString()}.csv",
            ['Expense number', 'Date', 'Category', 'Description', 'Department', 'Status', 'Requested by', 'Approved by', 'Amount'],
            $rows
        );
    }

    /**
     * Phase 9.5: the "Management Report" named document — combines the
     * KPI headline tiles with the Financial report's categorized
     * breakdowns on one printable page. Every figure is delegated to the
     * same services kpiDashboard()/financial() above already call —
     * nothing here is computed independently.
     */
    public function managementReport(Request $request)
    {
        [$start, $end] = $this->range($request);

        $kpis = $this->kpis->forRange($start, $end);
        $revenue = $this->financial->revenueByChargeType($start, $end);
        $expenses = $this->financial->expenseSummary($start, $end);
        $receivables = $this->financial->receivablesAging();

        return view('reports.management-report', compact('kpis', 'revenue', 'expenses', 'receivables', 'start', 'end'));
    }

    public function managementReportPrint(Request $request)
    {
        [$start, $end] = $this->range($request);

        $kpis = $this->kpis->forRange($start, $end);
        $revenue = $this->financial->revenueByChargeType($start, $end);
        $expenses = $this->financial->expenseSummary($start, $end);
        $receivables = $this->financial->receivablesAging();

        return view('reports.management-report-print', compact('kpis', 'revenue', 'expenses', 'receivables', 'start', 'end'));
    }

    public function occupancy(Request $request)
    {
        [$start, $end] = $this->range($request);
        $occupancy = $this->operations->occupancy($start, $end);

        return view('reports.occupancy', compact('occupancy', 'start', 'end'));
    }

    public function occupancyPrint(Request $request)
    {
        [$start, $end] = $this->range($request);
        $occupancy = $this->operations->occupancy($start, $end);

        return view('reports.occupancy-print', compact('occupancy', 'start', 'end'));
    }

    public function arrivalsDepartures(Request $request)
    {
        [$start, $end] = $this->range($request);
        $data = $this->operations->arrivalsAndDepartures($start, $end);

        return view('reports.arrivals-departures', array_merge($data, compact('start', 'end')));
    }

    public function arrivalsDeparturesPrint(Request $request)
    {
        [$start, $end] = $this->range($request);
        $data = $this->operations->arrivalsAndDepartures($start, $end);

        return view('reports.arrivals-departures-print', array_merge($data, compact('start', 'end')));
    }

    /**
     * Phase 9.3 (PHASE9_AUDIT_AND_PLAN.md §3.4): the only stay history
     * that existed before this was the *active* stays list
     * (front-office/stays) — nothing showed completed stays. Gated on
     * 'view-operational-reports' (same as occupancy/arrivals-departures)
     * rather than a financial permission, but note it does surface each
     * stay's charge/payment totals — front_desk itself holds no
     * view-*-reports permission at all, so this is already restricted to
     * Owner/GM/Duty Manager, consistent with the Phase 9.0 principle that
     * front-line roles should not see guest financial data.
     */
    public function guestStayHistory(Request $request)
    {
        [$start, $end] = $this->range($request);
        $stays = $this->operations->guestStayHistory($start, $end);

        return view('reports.guest-stay-history', compact('stays', 'start', 'end'));
    }

    public function guestStayHistoryPrint(Request $request)
    {
        [$start, $end] = $this->range($request);
        $stays = $this->operations->guestStayHistory($start, $end);

        return view('reports.guest-stay-history-print', compact('stays', 'start', 'end'));
    }

    /**
     * Phase 9.5 (PHASE9_AUDIT_AND_PLAN.md §3.6): CSV export of the same
     * data guestStayHistory() above renders — a natural first CSV target
     * since it's already a per-row list, unlike the aggregate reports.
     */
    public function guestStayHistoryCsv(Request $request)
    {
        [$start, $end] = $this->range($request);
        $stays = $this->operations->guestStayHistory($start, $end);

        $rows = $stays->map(fn ($row) => [
            $row['stay']->guest->full_name,
            $row['stay']->room->room_number,
            $row['stay']->check_in_at->toDateString(),
            $row['stay']->actual_check_out_at->toDateString(),
            $row['nights'],
            $row['total_charged'],
            $row['total_paid'],
            $row['balance'],
            $row['sent_to_receivables'] ? 'Yes' : 'No',
        ]);

        return $this->csvResponse(
            "guest-stay-history-{$start->toDateString()}-to-{$end->toDateString()}.csv",
            ['Guest', 'Room', 'Check-in', 'Check-out', 'Nights', 'Total charged', 'Total paid', 'Balance', 'Sent to receivables'],
            $rows
        );
    }

    /**
     * Note (§21): `stockValuation()`/`lowStock()` are point-in-time
     * snapshots ("as of today") — a start/end range genuinely does not
     * apply to "what's on the shelf right now" the way it does to a
     * period's transactions, so the date filter above only narrows
     * `wastage`, exactly as the screen view already makes clear with its
     * own "(as of today)" labels. This is left as-is rather than forcing
     * a misleading range onto a snapshot figure.
     */
    public function inventory(Request $request)
    {
        [$start, $end] = $this->range($request);

        $valuation = $this->inventory->stockValuation();
        $wastage = $this->inventory->wastageCost($start, $end);
        $lowStock = $this->inventory->lowStock();

        return view('reports.inventory', compact('valuation', 'wastage', 'lowStock', 'start', 'end'));
    }

    public function inventoryPrint(Request $request)
    {
        [$start, $end] = $this->range($request);

        $valuation = $this->inventory->stockValuation();
        $wastage = $this->inventory->wastageCost($start, $end);
        $lowStock = $this->inventory->lowStock();

        return view('reports.inventory-print', compact('valuation', 'wastage', 'lowStock', 'start', 'end'));
    }

    public function hr(Request $request)
    {
        [$start, $end] = $this->range($request);

        $payrollCost = $this->hr->payrollCost($start, $end);
        $attendance = $this->hr->attendanceSummary($start, $end);
        $leaveBalances = $this->hr->leaveBalances((int) $start->year);

        return view('reports.hr', compact('payrollCost', 'attendance', 'leaveBalances', 'start', 'end'));
    }

    public function hrPrint(Request $request)
    {
        [$start, $end] = $this->range($request);

        $payrollCost = $this->hr->payrollCost($start, $end);
        $attendance = $this->hr->attendanceSummary($start, $end);
        $leaveBalances = $this->hr->leaveBalances((int) $start->year);

        return view('reports.hr-print', compact('payrollCost', 'attendance', 'leaveBalances', 'start', 'end'));
    }

    /**
     * Post-9.8 second fix round (PHASE9_AUDIT_AND_PLAN.md §19): cash +
     * sales/income by department (front desk, bar, restaurant, bar
     * outside, bucareaux), plus each department's closed shifts in
     * range — the report the user explicitly asked for.
     */
    public function departmentReconciliation(Request $request)
    {
        [$start, $end] = $this->range($request);
        $report = $this->departmentReconciliationService->forRange($start, $end);

        return view('reports.department-reconciliation', array_merge($report, compact('start', 'end')));
    }

    public function departmentReconciliationPrint(Request $request)
    {
        [$start, $end] = $this->range($request);
        $report = $this->departmentReconciliationService->forRange($start, $end);

        return view('reports.department-reconciliation-print', array_merge($report, compact('start', 'end')));
    }

    public function departmentReconciliationCsv(Request $request)
    {
        [$start, $end] = $this->range($request);
        $report = $this->departmentReconciliationService->forRange($start, $end);

        $rows = $report['departments']->map(fn ($row) => [
            $row['department']->name,
            $row['sales'],
            $row['cash_in'],
            $row['cash_out'],
            $row['net_cash'],
            $row['non_cash_in'],
            // Fourth post-9.8 fix round (day-close rebuild): the same
            // per-account breakdown now shown on-screen and on the print
            // view, flattened into one column here so the CSV stays one
            // row per department.
            $row['non_cash_by_account']->map(fn ($acct) => "{$acct['label']}: {$acct['amount']}")->implode('; '),
            $row['total_collected'],
            $row['shifts']->count(),
            $row['shift_variance_total'],
        ]);

        return $this->csvResponse(
            "department-reconciliation-{$start->toDateString()}-to-{$end->toDateString()}.csv",
            ['Department', 'Sales/income', 'Cash in', 'Cash out', 'Net cash', 'Non-cash in', 'Non-cash by account', 'Total collected', 'Shifts closed', 'Shift variance total'],
            $rows
        );
    }

    /**
     * "Purchase reports should be generated from purchase order and goods
     * received" — see PurchaseReportService's docblock for how the ordered
     * vs. received figures are derived straight from those two ledgers.
     */
    public function purchases(Request $request)
    {
        [$start, $end] = $this->range($request);
        $report = $this->purchases->forRange($start, $end);

        return view('reports.purchases', array_merge($report, compact('start', 'end')));
    }

    public function purchasesPrint(Request $request)
    {
        [$start, $end] = $this->range($request);
        $report = $this->purchases->forRange($start, $end);

        return view('reports.purchases-print', array_merge($report, compact('start', 'end')));
    }

    public function purchasesCsv(Request $request)
    {
        [$start, $end] = $this->range($request);
        $report = $this->purchases->forRange($start, $end);

        $rows = $report['purchase_orders']->map(fn ($row) => [
            $row['po']->po_number,
            $row['po']->created_at->toDateString(),
            $row['po']->supplier?->name,
            $row['po']->status,
            $row['ordered_value'],
            $row['received_value'],
            $row['outstanding_value'],
        ]);

        return $this->csvResponse(
            "purchase-report-{$start->toDateString()}-to-{$end->toDateString()}.csv",
            ['PO number', 'Date', 'Supplier', 'Status', 'Ordered value', 'Received value', 'Outstanding value'],
            $rows
        );
    }

    /**
     * Every report accepts ?start=&end= (YYYY-MM-DD); defaults to
     * month-to-date, which is the range an Owner/GM/Accountant most
     * often wants without having to pick dates every time.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(Request $request): array
    {
        $start = $request->filled('start') ? Carbon::parse($request->string('start')) : Carbon::today()->startOfMonth();
        $end = $request->filled('end') ? Carbon::parse($request->string('end')) : Carbon::today();

        if ($end->lt($start)) {
            [$start, $end] = [$end, $start];
        }

        return [$start, $end];
    }
}
