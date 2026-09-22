<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\AccountingDay;
use App\Models\CashReconciliation;
use App\Models\FinancialAccount;
use App\Services\Finance\AccountingDayService;
use App\Services\Finance\CashReconciliationService;
use App\Services\Finance\FinancialAccountService;
use Illuminate\Http\Request;

/**
 * "Day Close & Cash Reconciliation" — the screen the owner described as
 * "not working, it is just there" (PHASE9_AUDIT_AND_PLAN.md §21/§23).
 * It used to only handle a single, whole-hotel, CASH-ONLY count
 * (CashReconciliationService) that had nothing to do with the Bank/MTN/
 * Orange accounts everything else in Finance already tracks per-account
 * (FinancialAccountService) — so it never actually reconciled "the
 * payments from guest, visitors, incomes, expenses... with the cash,
 * bank, mtn mobile money, orange money etc." the way the owner asked.
 *
 * Fourth post-9.8 fix round (day-close rebuild): the real, per-account
 * reconciliation (FinancialAccountService::reconcile(), now day-scoped —
 * see its own docblock) is front and center here. The old whole-hotel
 * cash count is kept lower on the page, clearly labeled legacy, for
 * continuity — nothing is deleted — but AccountingDayService::lock() no
 * longer looks at it at all.
 */
class AccountingDayController extends Controller
{
    public function __construct(
        private readonly AccountingDayService $days,
        private readonly CashReconciliationService $reconciliation,
        private readonly FinancialAccountService $accounts,
    ) {
    }

    public function index()
    {
        $today = $this->days->today();
        $accounts = $this->accounts->accountsWithTodayStatus();
        $balances = $accounts->mapWithKeys(fn (FinancialAccount $account) => [$account->id => $this->accounts->currentBalance($account)]);

        // Legacy whole-hotel cash count — kept visible for continuity
        // (see this class's docblock) but no longer gates locking the day.
        $todayReconciliation = CashReconciliation::where('business_date', $today->business_date->toDateString())->first();
        $expectedCashToday = $todayReconciliation ? null : $this->reconciliation->expectedCashForToday();
        $recentDays = AccountingDay::with('lockedBy')->orderByDesc('business_date')->limit(14)->get();
        $recentReconciliations = CashReconciliation::with('recordedBy')->orderByDesc('business_date')->limit(14)->get();

        return view('finance.accounting-days.index', compact(
            'today', 'accounts', 'balances', 'todayReconciliation', 'expectedCashToday', 'recentDays', 'recentReconciliations'
        ));
    }

    /**
     * The real per-day, per-account reconciliation this screen is built
     * around — separate route/permission from FinancialAccountController::
     * reconcile() (gated on `manage-financial-accounts`, the account-CRUD
     * screen) since this is a routine daily task, not account
     * administration; both call the same FinancialAccountService::
     * reconcile() underneath, so there is only ever one reconciliation
     * mechanism, just two doors into it.
     */
    public function reconcileAccount(Request $request, FinancialAccount $financialAccount)
    {
        $data = $request->validate([
            'declared_balance' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        $this->accounts->reconcile($financialAccount, (float) $data['declared_balance'], $request->user(), $data['notes'] ?? null);

        return back()->with('status', "{$financialAccount->name} reconciled for today.");
    }

    public function storeCashReconciliation(Request $request)
    {
        $data = $request->validate([
            'counted_amount' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        $this->reconciliation->record((float) $data['counted_amount'], $request->user(), $data['notes'] ?? null);

        return back()->with('status', "Today's cash reconciliation recorded.");
    }

    public function lock(Request $request)
    {
        $data = $request->validate(['notes' => 'nullable|string|max:500']);

        $this->days->lock($request->user(), $data['notes'] ?? null);

        return back()->with('status', "Today's accounting day is now locked.");
    }

    /**
     * Phase 9.5 (PHASE9_AUDIT_AND_PLAN.md §3.6): printable "Daily Cash
     * Closing" for one already-recorded reconciliation — same permission
     * as the index list this is reached from. Also shows whether that
     * business date's AccountingDay was locked, since the two are
     * related but separate records (a day can be reconciled without
     * being locked yet).
     */
    public function printCashClosing(CashReconciliation $cashReconciliation)
    {
        $cashReconciliation->load('recordedBy');
        $accountingDay = AccountingDay::where('business_date', $cashReconciliation->business_date->toDateString())
            ->with('lockedBy')
            ->first();

        return view('finance.accounting-days.cash-closing-print', compact('cashReconciliation', 'accountingDay'));
    }
}
