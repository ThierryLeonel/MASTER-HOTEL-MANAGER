<?php

namespace App\Services\Reporting;

use App\Models\AccountsReceivable;
use App\Models\Expense;
use App\Models\FolioTransaction;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Every figure here is a live query against the ledgers built in
 * earlier phases (folio_transactions, payments, expenses,
 * accounts_receivable) — spec section 24: a report is never a stored,
 * separately-maintained total that can drift from the transactions it
 * describes.
 *
 * Two deliberately distinct views are exposed rather than one blended
 * number: `revenueByChargeType()` is *accrual* revenue (what was
 * charged to guests, from folio_transactions), while `cashFlow()` is
 * *cash* movement (what actually moved through the central payment
 * ledger). They will not match — a room charge posted today might not
 * be paid until checkout next week — and collapsing them into one
 * "revenue" figure would misrepresent both.
 */
class FinancialReportService
{
    /**
     * @return array{by_type: array<string, float>, total: float}
     */
    public function revenueByChargeType(Carbon $start, Carbon $end): array
    {
        $rows = FolioTransaction::whereIn('type', FolioTransaction::CHARGE_TYPES)
            ->whereNull('voided_at')
            ->whereBetween('transaction_date', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->selectRaw('type, SUM(amount) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $byType = [];
        foreach (FolioTransaction::CHARGE_TYPES as $type) {
            $byType[$type] = (float) ($rows[$type] ?? 0);
        }

        return ['by_type' => $byType, 'total' => array_sum($byType)];
    }

    /**
     * @return array{cash_in: float, cash_out: array<string, float>, total_cash_out: float, net: float}
     */
    public function cashFlow(Carbon $start, Carbon $end): array
    {
        $range = [$start->copy()->startOfDay(), $end->copy()->endOfDay()];

        $cashIn = (float) Payment::where('direction', 'in')->whereBetween('recorded_at', $range)->sum('amount');

        $outRows = Payment::where('direction', 'out')
            ->whereBetween('recorded_at', $range)
            ->selectRaw('source_module, SUM(amount) as total')
            ->groupBy('source_module')
            ->pluck('total', 'source_module');

        $cashOut = [];
        foreach ($outRows as $module => $total) {
            $cashOut[$module] = (float) $total;
        }
        $totalOut = array_sum($cashOut);

        return ['cash_in' => $cashIn, 'cash_out' => $cashOut, 'total_cash_out' => $totalOut, 'net' => $cashIn - $totalOut];
    }

    /**
     * Correction round (audited defect #28): a rejected or cancelled
     * expense REQUEST is not recognized spending — it never left the
     * building. `by_category`/`total_requested` here now only ever sum
     * `pending`/`approved`/`paid` rows (money that either has moved, or is
     * still a live, un-refused request); `total_rejected_or_cancelled` is
     * reported separately so "what did people ask for that was turned
     * down" stays visible without contaminating the recognized-expense
     * figure that `incomeStatement()` below feeds from.
     *
     * @return array{by_category: array<string, float>, total_requested: float, total_paid: float, total_rejected_or_cancelled: float}
     */
    public function expenseSummary(Carbon $start, Carbon $end): array
    {
        $range = [$start->copy()->startOfDay(), $end->copy()->endOfDay()];

        $byCategory = Expense::whereBetween('created_at', $range)
            ->whereIn('status', ['pending', 'approved', 'paid'])
            ->selectRaw('category, SUM(amount) as total')
            ->groupBy('category')
            ->pluck('total', 'category')
            ->map(fn ($v) => (float) $v)
            ->all();

        $totalPaid = (float) Expense::where('status', 'paid')->whereBetween('paid_at', $range)->sum('amount');

        $totalRejectedOrCancelled = (float) Expense::whereBetween('created_at', $range)
            ->whereIn('status', ['rejected', 'cancelled'])
            ->sum('amount');

        return [
            'by_category' => $byCategory,
            'total_requested' => array_sum($byCategory),
            'total_paid' => $totalPaid,
            'total_rejected_or_cancelled' => $totalRejectedOrCancelled,
        ];
    }

    /**
     * Phase 9.3 (PHASE9_AUDIT_AND_PLAN.md §3.4/§4.1): an Income Statement
     * in the strict accounting sense is a Chart-of-Accounts/General-Ledger
     * output, and this system has neither (confirmed by audit — there is
     * no `chart_of_accounts`/`general_ledger` table or posting logic
     * anywhere). Per the resolved §4.1 decision, this is a deliberate
     * **management-style approximation**: accrual revenue (charges posted
     * to guest folios, from `revenueByChargeType()`) minus expenses
     * incurred in the same period (requested, not necessarily yet paid,
     * from `expenseSummary()`'s `by_category`/`total_requested` — the
     * accrual-consistent figure, matching how revenue here is "charged"
     * rather than "collected"). It is NOT a substitute for a real
     * double-entry Income Statement and must never be presented as one to
     * an auditor or investor without that caveat.
     *
     * @return array{
     *   revenue_by_type: array<string, float>, total_revenue: float,
     *   expenses_by_category: array<string, float>, total_expenses: float,
     *   net_operating_result: float
     * }
     */
    public function incomeStatement(Carbon $start, Carbon $end): array
    {
        $revenue = $this->revenueByChargeType($start, $end);
        $expenses = $this->expenseSummary($start, $end);

        return [
            'revenue_by_type' => $revenue['by_type'],
            'total_revenue' => $revenue['total'],
            'expenses_by_category' => $expenses['by_category'],
            'total_expenses' => $expenses['total_requested'],
            'net_operating_result' => $revenue['total'] - $expenses['total_requested'],
        ];
    }

    /**
     * Phase 9.5 (PHASE9_AUDIT_AND_PLAN.md §3.6): the "Payment Summary"
     * printable document — every individual row from the central
     * `payments` ledger in the range, plus a by-method breakdown. Reuses
     * the same `Payment` model `cashFlow()` already aggregates from
     * above, just at row-level detail instead of a total.
     *
     * @return array{payments: Collection, by_method: Collection, total_in: float, total_out: float}
     */
    public function paymentSummary(Carbon $start, Carbon $end): array
    {
        $range = [$start->copy()->startOfDay(), $end->copy()->endOfDay()];

        $payments = Payment::with(['guest', 'recordedBy'])
            ->whereBetween('recorded_at', $range)
            ->orderByDesc('recorded_at')
            ->get();

        $byMethod = $payments->groupBy('method')->map(fn (Collection $group) => (float) $group->sum('amount'));

        return [
            'payments' => $payments,
            'by_method' => $byMethod,
            'total_in' => (float) $payments->where('direction', 'in')->sum('amount'),
            'total_out' => (float) $payments->where('direction', 'out')->sum('amount'),
        ];
    }

    /**
     * Phase 9.5: the "Expense Report" printable document — individual
     * expense line items in the range (not just the category totals
     * `expenseSummary()` above already provides, which this reuses for
     * the aggregate rather than recomputing it).
     *
     * @return array{expenses: Collection, summary: array}
     */
    public function expenseReport(Carbon $start, Carbon $end): array
    {
        $range = [$start->copy()->startOfDay(), $end->copy()->endOfDay()];

        $expenses = Expense::with(['department', 'requestedBy', 'approvedBy', 'paidBy'])
            ->whereBetween('created_at', $range)
            ->orderByDesc('id')
            ->get();

        return [
            'expenses' => $expenses,
            'summary' => $this->expenseSummary($start, $end),
        ];
    }

    /**
     * A snapshot, not a date-ranged report — "how much is currently
     * owed to us" only makes sense as of right now.
     *
     * @return array{buckets: array<string, array{count: int, amount: float}>, total_outstanding: float}
     */
    public function receivablesAging(): array
    {
        // Correction round (audited defect #26): a partially-paid debt is
        // still real outstanding debt — isSettleable() already treats
        // 'open' and 'partially_paid' as equally valid outstanding states;
        // this report now agrees, summing amount_outstanding (the true
        // remaining balance) rather than dropping the row the moment any
        // partial payment lands.
        $open = AccountsReceivable::whereIn('status', ['open', 'partially_paid'])->get();

        $buckets = [
            '0-30 days' => ['count' => 0, 'amount' => 0.0],
            '31-60 days' => ['count' => 0, 'amount' => 0.0],
            '61-90 days' => ['count' => 0, 'amount' => 0.0],
            '90+ days' => ['count' => 0, 'amount' => 0.0],
        ];

        foreach ($open as $ar) {
            $ageDays = $ar->created_at->diffInDays(now());
            $bucket = match (true) {
                $ageDays <= 30 => '0-30 days',
                $ageDays <= 60 => '31-60 days',
                $ageDays <= 90 => '61-90 days',
                default => '90+ days',
            };
            $buckets[$bucket]['count']++;
            $buckets[$bucket]['amount'] += (float) $ar->amount_outstanding;
        }

        return ['buckets' => $buckets, 'total_outstanding' => array_sum(array_column($buckets, 'amount'))];
    }
}
