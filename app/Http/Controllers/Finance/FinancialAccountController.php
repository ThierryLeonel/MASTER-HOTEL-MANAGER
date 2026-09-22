<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountReconciliation;
use App\Services\Finance\FinancialAccountService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FinancialAccountController extends Controller
{
    public function __construct(private readonly FinancialAccountService $service)
    {
    }

    public function index(Request $request)
    {
        $accounts = FinancialAccount::with('latestReconciliation.reconciledBy')->orderBy('name')->get();
        $balances = $accounts->mapWithKeys(fn (FinancialAccount $account) => [$account->id => $this->service->currentBalance($account)]);

        return view('finance.financial-accounts.index', compact('accounts', 'balances'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:30|alpha_dash|unique:financial_accounts,code',
            'type' => 'required|in:cash,bank,mobile_money,card',
            'opening_balance' => 'nullable|numeric|min:0',
        ]);

        $this->service->create($data, $request->user());

        return back()->with('status', 'Financial account created.');
    }

    /**
     * Sixth post-9.8 fix round, follow-up ("add, modify and edit real
     * accounts") — same edit/update shape as admin.departments.
     */
    public function edit(FinancialAccount $financialAccount)
    {
        return view('finance.financial-accounts.edit', compact('financialAccount'));
    }

    public function update(Request $request, FinancialAccount $financialAccount)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'code' => ['required', 'string', 'max:30', 'alpha_dash', Rule::unique('financial_accounts', 'code')->ignore($financialAccount->id)],
            'type' => 'required|in:cash,bank,mobile_money,card',
            'opening_balance' => 'nullable|numeric|min:0',
        ]);

        $this->service->update($financialAccount, $data, $request->user());

        return redirect()->route('finance.financial-accounts.index')->with('status', "{$financialAccount->name} updated.");
    }

    public function activate(Request $request, FinancialAccount $financialAccount)
    {
        $this->service->reactivate($financialAccount, $request->user());

        return back()->with('status', "{$financialAccount->name} reactivated.");
    }

    public function reconcile(Request $request, FinancialAccount $financialAccount)
    {
        $data = $request->validate([
            'declared_balance' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        $this->service->reconcile($financialAccount, (float) $data['declared_balance'], $request->user(), $data['notes'] ?? null);

        return back()->with('status', "{$financialAccount->name} reconciled.");
    }

    public function deactivate(Request $request, FinancialAccount $financialAccount)
    {
        $this->service->deactivate($financialAccount, $request->user());

        return back()->with('status', "{$financialAccount->name} deactivated.");
    }

    public function printReconciliation(FinancialAccountReconciliation $reconciliation)
    {
        $reconciliation->load(['financialAccount', 'reconciledBy']);

        return view('finance.financial-accounts.reconciliation-print', compact('reconciliation'));
    }
}
