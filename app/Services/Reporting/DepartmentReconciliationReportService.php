<?php

namespace App\Services\Reporting;

use App\Models\Department;
use App\Models\FolioTransaction;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shift;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "We will need a report that reconciles daily payment by the various
 * departments, that is bar, restaurant, front desk, the bar outside,
 * the bucareaux and their income recorded from their sales, lodging
 * etc." (post-9.8 second fix round, PHASE9_AUDIT_AND_PLAN.md §19).
 *
 * Two figures per department, deliberately kept distinct — same
 * accrual-vs-cash principle FinancialReportService::revenueByChargeType()
 * vs cashFlow() already establishes for the whole hotel, just broken
 * down per department here:
 *  - `sales`: what the department actually sold/charged in the period
 *    (POS order totals for Bar/Restaurant/Bar Outside/Bucareaux; folio
 *    charges — room/lodging + front-desk petty sales/other service —
 *    for Front Desk).
 *  - `cash_in`/`cash_out`/`net_cash`: what actually moved through the
 *    central payment ledger for that department, cash only — this is
 *    the figure a department's cash handover should match.
 * They will not be equal (a bar tab settled onto a guest's room folio
 * is bar `sales` but front-desk `cash_in` once the guest pays at
 * checkout) — that gap is exactly what makes this report useful rather
 * than redundant with the sales figure alone.
 *
 * Every department's closed shifts in range are also listed, since "not
 * really per day but in shifts" (the user's own correction) is how this
 * cash is actually handed in — see App\Services\Finance\ShiftService.
 */
class DepartmentReconciliationReportService
{
    /**
     * The cash-handling departments named by the user, in the order they
     * named them. Any other department (Finance, Management, Housekeeping,
     * Maintenance) has no outlet/POS sales of its own and is left out of
     * this report — it would only ever show zeroes.
     */
    private const DEPARTMENT_CODES = ['FO', 'BAR', 'REST', 'BAROUT', 'BUCA'];

    /**
     * departments.code -> orders.outlet, for the departments that are
     * also POS outlets. Front Desk (FO) is deliberately absent here — its
     * "sales" figure is lodging/folio charges, computed separately below,
     * even though it also has its own POS outlet (petty sales) counted
     * within that folio-charge figure via the front_desk_charge type.
     */
    private const DEPARTMENT_OUTLET = [
        'BAR' => 'bar',
        'REST' => 'restaurant',
        'BAROUT' => 'bar_outside',
        'BUCA' => 'bucareaux',
    ];

    /**
     * @return array{departments: Collection, totals: array}
     */
    public function forRange(Carbon $start, Carbon $end): array
    {
        $range = [$start->copy()->startOfDay(), $end->copy()->endOfDay()];

        $departments = Department::whereIn('code', self::DEPARTMENT_CODES)->get()->sortBy(
            fn ($d) => array_search($d->code, self::DEPARTMENT_CODES)
        )->values();

        $rows = $departments->map(function (Department $department) use ($range, $start, $end) {
            // Direct request ("the owner is suppose to be able to edit
            // all transaction[s]"): a voided payment must not count
            // toward any department's reconciliation figures either.
            $cashIn = (float) Payment::where('department_id', $department->id)
                ->where('direction', 'in')->where('method', 'cash')
                ->whereNull('voided_at')->whereBetween('recorded_at', $range)->sum('amount');
            $cashOut = (float) Payment::where('department_id', $department->id)
                ->where('direction', 'out')->where('method', 'cash')
                ->whereNull('voided_at')->whereBetween('recorded_at', $range)->sum('amount');
            $nonCashIn = (float) Payment::where('department_id', $department->id)
                ->where('direction', 'in')->where('method', '!=', 'cash')
                ->whereNull('voided_at')->whereBetween('recorded_at', $range)->sum('amount');

            // Fourth post-9.8 fix round (day-close rebuild): the single
            // lumped `non_cash_in` figure above used to be the only
            // non-cash number this report showed — every mobile money
            // payment, MTN or Orange, Bank or Card, landed in one bucket,
            // which is exactly the kind of unattributed figure that made
            // the owner's day close impossible to actually reconcile
            // against real accounts. Broken down per real financial
            // account here, same "Unattributed" fallback as
            // ShiftService::expectedByAccountFor() for any payment still
            // missing one.
            $nonCashByAccount = Payment::where('department_id', $department->id)
                ->where('direction', 'in')->where('method', '!=', 'cash')
                ->whereNull('voided_at')
                ->whereBetween('recorded_at', $range)
                ->with('financialAccount')
                ->get()
                ->groupBy(fn (Payment $payment) => $payment->financial_account_id ?? 'unattributed')
                ->map(fn ($payments, $key) => [
                    'label' => $key === 'unattributed'
                        ? 'Unattributed (no account recorded)'
                        : ($payments->first()->financialAccount->name ?? 'Deleted account'),
                    'amount' => (float) $payments->sum('amount'),
                ])
                ->sortBy('label')
                ->values();

            $sales = $this->salesFor($department, $range);

            $shifts = Shift::where('department_id', $department->id)
                ->where('status', 'closed')
                ->whereBetween('closed_at', $range)
                ->orderBy('closed_at')
                ->get();

            return [
                'department' => $department,
                'sales' => $sales,
                'cash_in' => $cashIn,
                'cash_out' => $cashOut,
                'net_cash' => $cashIn - $cashOut,
                'non_cash_in' => $nonCashIn,
                'non_cash_by_account' => $nonCashByAccount,
                'total_collected' => $cashIn + $nonCashIn,
                'shifts' => $shifts,
                'shift_variance_total' => (float) $shifts->sum('variance'),
            ];
        });

        $totals = [
            'sales' => $rows->sum('sales'),
            'cash_in' => $rows->sum('cash_in'),
            'cash_out' => $rows->sum('cash_out'),
            'net_cash' => $rows->sum('net_cash'),
            'non_cash_in' => $rows->sum('non_cash_in'),
            'total_collected' => $rows->sum('total_collected'),
        ];

        return ['departments' => $rows, 'totals' => $totals];
    }

    /**
     * @param array{0: Carbon, 1: Carbon} $range
     */
    /**
     * Correction round (audited defect #25/#3 in the reporting section): a
     * Bar/Restaurant/Bar-Outside/Bucareaux order deferred to a guest's room
     * folio used to be counted twice in forRange()'s totals — once here as
     * that outlet's own `sales` (every closed Order, deferred or not), and
     * a second time as Front Office `sales` (which summed EVERY
     * FolioTransaction::CHARGE_TYPES, including the bar_charge/
     * restaurant_charge rows those same deferred orders post). Front
     * Office's own genuine sales are only room lodging, front-desk petty
     * sales, and other_service — never another outlet's charge types,
     * which only ever appear on a folio because that outlet chose to defer
     * them, not because Front Office sold anything.
     */
    private const FRONT_OFFICE_OWN_CHARGE_TYPES = ['room_charge', 'front_desk_charge', 'other_service'];

    private function salesFor(Department $department, array $range): float
    {
        if ($department->code === 'FO') {
            return (float) FolioTransaction::whereIn('type', self::FRONT_OFFICE_OWN_CHARGE_TYPES)
                ->whereNull('voided_at')
                ->whereBetween('transaction_date', $range)
                ->sum('amount');
        }

        $outlet = self::DEPARTMENT_OUTLET[$department->code] ?? null;

        if ($outlet === null) {
            return 0.0;
        }

        return (float) Order::where('outlet', $outlet)
            ->where('status', 'closed')
            ->whereBetween('closed_at', $range)
            ->sum('total_amount');
    }
}
