<?php

namespace App\Services\Reporting;

use App\Models\AccountsReceivable;
use App\Models\CashDisbursement;
use App\Models\ChargeType;
use App\Models\Department;
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
     * Direct request (screenshots review, Sept 2026): "the income payment
     * received that was recorded by the accountant is not completely
     * recognised as income or revenue even though it states income from
     * bar and restau[rant]... this income payment recording happened
     * because the bar and the restaurant are not having their own device
     * at the moment so they pay their total amount sold to the accountant
     * and he reconciles them. and because of this the reports are not
     * straight."
     *
     * Before this fix, EVERY payment recorded on the general "record a
     * payment" screen (PaymentController) was always auto-attributed to
     * Front Office (PaymentLedgerService::SOURCE_MODULE_DEPARTMENT_CODES
     * ['general_payment'] = 'FO'), with no way to say it was actually a
     * department's own sales handed over in cash — so a bar/restaurant
     * takings handover only ever showed up as a generic, disconnected
     * "other income" line (see otherIncomeBySource() below), never as
     * real Bar/Restaurant revenue anywhere revenue is broken down by
     * charge type (this method — and by extension the Financial report,
     * the KPI dashboard's "Revenue (accrual)" tile, the Weekly Owner
     * Summary, and the Management report, all of which read this method
     * directly or via incomeStatement()).
     *
     * The fix: the payment form (front-office/payments/index.blade.php)
     * now lets whoever records a bar/restaurant handover explicitly
     * attribute it to that department (PaymentController::store() ->
     * PaymentLedgerService::record()'s existing `$departmentId`
     * parameter — see that service's docblock for why an explicit
     * department also correctly skips the shift-open gate, since these
     * outlets have no shift workflow of their own for this manual
     * process). Once attributed, manuallyRecognizedOutletRevenue() below
     * folds it straight into the matching FolioTransaction charge-type
     * bucket, so it reads exactly like any other bar/restaurant sale
     * everywhere revenue-by-type is shown, rather than a separately
     * labelled catch-all.
     *
     * otherIncomeBySource() below deliberately EXCLUDES exactly these
     * same rows (same department-id filter) so this money is recognised
     * exactly once across the whole reporting suite, never twice.
     *
     * Charge-types unification (Sept 2026 — see ChargeType's own
     * docblock): this used to be scoped to Bar and Restaurant only, via a
     * hardcoded `DEPARTMENT_CODE_TO_CHARGE_TYPE = ['BAR' => 'bar_charge',
     * 'REST' => 'restaurant_charge']` map — the only two departments that
     * had a matching FolioTransaction charge type at the time. Now that
     * every charge type can carry its own `department_id` (an admin
     * setting, not a code constant), manuallyRecognizedOutletRevenue()
     * below reads that mapping live from `charge_types` instead, so Bar
     * Outside and Bucareaux (closing the exact "other income" limitation
     * this docblock used to describe) and any future admin-added type
     * with a department attached are recognised the same way, with no
     * further code changes ever needed.
     *
     * IMPORTANT — this does not retroactively reclassify anything already
     * recorded: a payment with a blank/Front-Office department_id (every
     * payment recorded before this fix shipped) keeps behaving exactly
     * as it always has, under "other income"/Uncategorized. Only a
     * payment where a department is explicitly chosen, from now on, is
     * recognised this way — consistent with this engagement's rule of
     * never rewriting historical financial records.
     */

    /**
     * @return array{by_type: array<string, float>, total: float}
     */
    public function revenueByChargeType(Carbon $start, Carbon $end): array
    {
        $range = [$start->copy()->startOfDay(), $end->copy()->endOfDay()];
        $chargeTypeCodes = FolioTransaction::postableChargeTypeCodes();

        $rows = FolioTransaction::whereIn('type', $chargeTypeCodes)
            ->whereNull('voided_at')
            ->whereBetween('transaction_date', $range)
            ->selectRaw('type, SUM(amount) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $byType = [];
        foreach ($chargeTypeCodes as $type) {
            $byType[$type] = (float) ($rows[$type] ?? 0);
        }

        foreach ($this->manuallyRecognizedOutletRevenue($range) as $type => $amount) {
            $byType[$type] += $amount;
        }

        return ['by_type' => $byType, 'total' => array_sum($byType)];
    }

    /**
     * See revenueByChargeType()'s docblock for the full reasoning.
     *
     * Front Office's own department is deliberately excluded from this
     * mapping (same as before this generalization, when the map only
     * ever held BAR/REST): this mechanism exists for an outlet that has
     * no device/terminal of its own and so hands its cash takings to the
     * accountant to record and attribute — Front Office always has its
     * own terminal and posts its own charges directly to folios, so a
     * payment ever manually attributed to it isn't this kind of handover.
     * Excluding it also sidesteps a real ambiguity: Front Office
     * legitimately owns THREE charge types (room_charge,
     * front_desk_charge, other_service — see
     * DepartmentReconciliationReportService::salesFor()), so a payment
     * attributed to it could never be resolved to a single one of them
     * anyway.
     *
     * Known, accepted limitation: if an admin ever attaches the SAME
     * (non-Front-Office) department to two different charge types, only
     * one of them receives a manually recorded payment's revenue here
     * (the lowest charge_type id wins, since it's applied last below) —
     * this can't happen from the seeded data and would take a
     * deliberate, unusual admin choice to trigger, so it's documented
     * here rather than engineered around speculatively.
     *
     * @param array{0: Carbon, 1: Carbon} $range
     * @return array<string, float> keyed by charge type code
     */
    private function manuallyRecognizedOutletRevenue(array $range): array
    {
        $foDepartmentId = Department::where('code', 'FO')->value('id');

        $departmentIdToType = ChargeType::whereNotNull('department_id')
            ->where('postable_to_folio', true)
            ->when($foDepartmentId, fn ($q) => $q->where('department_id', '!=', $foDepartmentId))
            ->orderByDesc('id')
            ->pluck('code', 'department_id');

        if ($departmentIdToType->isEmpty()) {
            return [];
        }

        $sums = Payment::where('source_module', 'general_payment')
            ->where('direction', 'in')
            ->whereNull('voided_at')
            ->whereBetween('recorded_at', $range)
            ->whereIn('department_id', $departmentIdToType->keys())
            ->selectRaw('department_id, SUM(amount) as total')
            ->groupBy('department_id')
            ->pluck('total', 'department_id');

        $byType = [];
        foreach ($sums as $departmentId => $total) {
            $type = $departmentIdToType[$departmentId];
            $byType[$type] = ($byType[$type] ?? 0) + (float) $total;
        }

        return $byType;
    }

    /**
     * The department ids folded into revenueByChargeType() above — see
     * that method's docblock. Shared here so otherIncomeBySource() can
     * exclude exactly the same rows and avoid double-counting them.
     *
     * @return array<int>
     */
    private function recognizedOutletDepartmentIds(): array
    {
        $foDepartmentId = Department::where('code', 'FO')->value('id');

        return ChargeType::whereNotNull('department_id')
            ->where('postable_to_folio', true)
            ->when($foDepartmentId, fn ($q) => $q->where('department_id', '!=', $foDepartmentId))
            ->pluck('department_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{cash_in: float, cash_out: array<string, float>, total_cash_out: float, net: float}
     */
    public function cashFlow(Carbon $start, Carbon $end): array
    {
        $range = [$start->copy()->startOfDay(), $end->copy()->endOfDay()];

        // Direct request ("the owner is suppose to be able to edit all
        // transaction[s]"): a voided payment (PaymentLedgerService::void())
        // must not count toward cash flow either.
        $cashIn = (float) Payment::where('direction', 'in')->whereNull('voided_at')->whereBetween('recorded_at', $range)->sum('amount');

        $outRows = Payment::where('direction', 'out')
            ->whereNull('voided_at')
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
     * Direct request ("all in payment must be attached to an income
     * source that is to be represented or seen in the income statement"):
     * `general_payment` is the general-purpose "record a payment" screen
     * (PaymentController) — money that isn't a guest's room/POS bill (rent,
     * a one-off visitor payment, etc.). Every payment recorded there is
     * `direction = 'in'` (the ledger defaults to it and that screen never
     * overrides it), and, since this correction round, is required to
     * carry `income_source` (free text, same convention as
     * `expenses.category`). Grouped by exactly what was typed, so a fresh
     * `income_source` value shows up as its own line the very next time
     * this report runs — no master list to keep in sync.
     *
     * @return array{by_source: array<string, float>, total: float}
     */
    public function otherIncomeBySource(Carbon $start, Carbon $end): array
    {
        $range = [$start->copy()->startOfDay(), $end->copy()->endOfDay()];

        $query = Payment::where('direction', 'in')
            ->where('source_module', 'general_payment')
            // Direct request ("the owner is suppose to be able to edit
            // all transaction[s]"): a voided payment must not count as
            // income either.
            ->whereNull('voided_at')
            ->whereBetween('recorded_at', $range);

        // Sept 2026 revenue-recognition fix — see revenueByChargeType()'s
        // docblock: a payment explicitly attributed to Bar or Restaurant
        // is recognised as that department's own charge-type revenue
        // there instead, so it is excluded here to avoid counting the
        // same handover twice on the Income Statement.
        $recognizedDepartmentIds = $this->recognizedOutletDepartmentIds();
        if ($recognizedDepartmentIds !== []) {
            $query->where(function ($q) use ($recognizedDepartmentIds) {
                $q->whereNull('department_id')->orWhereNotIn('department_id', $recognizedDepartmentIds);
            });
        }

        $rows = $query->selectRaw("COALESCE(NULLIF(income_source, ''), 'Uncategorized') as source, SUM(amount) as total")
            ->groupBy('source')
            ->pluck('total', 'source')
            ->map(fn ($v) => (float) $v)
            ->all();

        return ['by_source' => $rows, 'total' => array_sum($rows)];
    }

    /**
     * Direct request ("out payment related to expenses also represented
     * in the income statement too"): audited while building the above —
     * `expenseSummary()`'s `by_category` only ever reads the `expenses`
     * table, so two genuine categories of real cash going out were
     * completely invisible on the Income Statement before this:
     *
     *  - A `CashDisbursement` with no `expense_id` (a payout that never
     *    went through the Expense request/approval flow at all — e.g. a
     *    ticket-authorized disbursement). One that DOES have an
     *    `expense_id` is deliberately excluded here — that money is
     *    already counted exactly once, via the `Expense` row itself in
     *    `expenseSummary()` (see `CashDisbursementService::disburse()`'s
     *    docblock: the disbursement's ledger post IS that expense's
     *    payment, never a second one), so including it here too would
     *    double-count it.
     *  - Payroll payouts (`PayrollService::pay()`, `source_module =
     *    'payroll'`) — salaries never had ANY representation on this
     *    report before.
     *
     * Both are read from the `payments` ledger itself (not the
     * `cash_disbursements` table directly) so a voided payment
     * (PaymentLedgerService::void() — direct request, "the owner is
     * suppose to be able to edit all transaction[s]") is excluded
     * automatically, the same way every other figure in this class is,
     * with one `whereNull('voided_at')` rather than a second, separate
     * exclusion mechanism on `cash_disbursements`.
     *
     * @return array{by_category: array<string, float>, total: float}
     */
    public function unrecognizedCashOutflows(Carbon $start, Carbon $end): array
    {
        $range = [$start->copy()->startOfDay(), $end->copy()->endOfDay()];

        $disbursementPayments = Payment::where('direction', 'out')
            ->where('source_module', 'cash_disbursement')
            ->whereNull('voided_at')
            ->whereBetween('recorded_at', $range)
            ->with('reference')
            ->get()
            ->filter(fn (Payment $p) => $p->reference instanceof CashDisbursement && $p->reference->expense_id === null);

        $byCategory = [];
        foreach ($disbursementPayments as $payment) {
            $category = $payment->reference->purpose;
            $byCategory[$category] = ($byCategory[$category] ?? 0) + (float) $payment->amount;
        }

        $payrollTotal = (float) Payment::where('direction', 'out')
            ->where('source_module', 'payroll')
            ->whereNull('voided_at')
            ->whereBetween('recorded_at', $range)
            ->sum('amount');

        if ($payrollTotal > 0) {
            $byCategory['Payroll (salaries)'] = ($byCategory['Payroll (salaries)'] ?? 0) + $payrollTotal;
        }

        return ['by_category' => $byCategory, 'total' => array_sum($byCategory)];
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
     * This correction round folds in two more real cash movements that
     * were completely absent before — see `otherIncomeBySource()` and
     * `unrecognizedCashOutflows()`'s own docblocks for exactly what and
     * why. Both are merged straight into the same `revenue_by_type`/
     * `expenses_by_category` maps (every consumer of this method already
     * just iterates whatever keys are there), so nothing downstream needed
     * to change to display them.
     *
     * Known remaining gap, found while auditing this — NOT fixed here,
     * flagged for a future round the same way this engagement flags every
     * finding it doesn't fold into the current request: a POS order
     * closed with a direct payment method (a walk-in bar/restaurant sale
     * paid on the spot, never deferred to a guest's folio —
     * `OrderService::close()`) posts straight to the `payments` ledger and
     * NEVER creates a `FolioTransaction`. Since `revenue_by_type` below is
     * still sourced from `revenueByChargeType()` (accrual, FolioTransaction
     * -based), that walk-in revenue is invisible here — only room/POS
     * charges that were actually posted to a folio show up. Closing this
     * one properly means deciding how to blend accrual folio revenue with
     * cash POS revenue without double-counting a POS sale that's later
     * deferred to a folio anyway; that's a bigger design decision than
     * this round's scope.
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
        $otherIncome = $this->otherIncomeBySource($start, $end);
        $unrecognizedOutflows = $this->unrecognizedCashOutflows($start, $end);

        $revenueByType = $revenue['by_type'];
        foreach ($otherIncome['by_source'] as $source => $amount) {
            $revenueByType[$source] = ($revenueByType[$source] ?? 0) + $amount;
        }

        $expensesByCategory = $expenses['by_category'];
        foreach ($unrecognizedOutflows['by_category'] as $category => $amount) {
            $expensesByCategory[$category] = ($expensesByCategory[$category] ?? 0) + $amount;
        }

        $totalRevenue = $revenue['total'] + $otherIncome['total'];
        $totalExpenses = $expenses['total_requested'] + $unrecognizedOutflows['total'];

        return [
            'revenue_by_type' => $revenueByType,
            'total_revenue' => $totalRevenue,
            'expenses_by_category' => $expensesByCategory,
            'total_expenses' => $totalExpenses,
            'net_operating_result' => $totalRevenue - $totalExpenses,
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

        // Direct request ("the owner is suppose to be able to edit all
        // transaction[s]"): a voided row still appears in the printed
        // detail list below (transparency — the same way a voided
        // FolioTransaction stays visible rather than disappearing), but
        // must not count toward the by-method/total figures.
        $notVoided = $payments->whereNull('voided_at');
        $byMethod = $notVoided->groupBy('method')->map(fn (Collection $group) => (float) $group->sum('amount'));

        return [
            'payments' => $payments,
            'by_method' => $byMethod,
            'total_in' => (float) $notVoided->where('direction', 'in')->sum('amount'),
            'total_out' => (float) $notVoided->where('direction', 'out')->sum('amount'),
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
