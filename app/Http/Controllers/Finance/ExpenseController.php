<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Services\Finance\ExpenseService;
use App\Services\SettingsService;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function __construct(
        private readonly ExpenseService $service,
        private readonly SettingsService $settings,
    ) {
    }

    public function index(Request $request)
    {
        $expenses = Expense::with(['department', 'requestedBy', 'approvedBy', 'financialAccount'])
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('id')
            ->paginate(20);
        $departments = Department::where('is_active', true)->orderBy('name')->get();
        $threshold = $this->settings->expenseApprovalThreshold();
        $financialAccounts = FinancialAccount::where('is_active', true)->orderBy('name')->get();

        return view('finance.expenses.index', compact('expenses', 'departments', 'threshold', 'financialAccounts'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'department_id' => 'nullable|exists:departments,id',
            'category' => 'required|string|max:100',
            'description' => 'required|string|max:500',
            'amount' => 'required|numeric|min:0.01',
        ]);

        $this->service->request(
            (float) $data['amount'], $data['category'], $data['description'], $request->user(), $data['department_id'] ?? null,
        );

        return back()->with('status', 'Expense requested and awaiting approval.');
    }

    public function approve(Request $request, Expense $expense)
    {
        $this->service->approve($expense, $request->user());

        return back()->with('status', 'Expense approved.');
    }

    public function reject(Request $request, Expense $expense)
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);

        $this->service->reject($expense, $request->user(), $data['reason']);

        return back()->with('status', 'Expense rejected.');
    }

    public function cancel(Request $request, Expense $expense)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        $this->service->cancel($expense, $request->user(), $data['reason'] ?? 'Cancelled by requester.');

        return back()->with('status', 'Expense cancelled.');
    }

    /**
     * Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §21): "for
     * expenses specify the particular financial account (bank, cash,
     * orange money, MTN mobile money, etc.) the money is leaving for the
     * expense" — required, not optional; `method` is derived from the
     * chosen account's `type` (see ExpenseService::markPaid()) rather
     * than picked separately, so the two can never disagree.
     */
    public function pay(Request $request, Expense $expense)
    {
        $data = $request->validate(['financial_account_id' => 'required|exists:financial_accounts,id']);

        // Correction round (audited defect #31): see PaymentController::
        // store()'s matching comment — never trust the submitted id alone.
        $account = FinancialAccount::where('is_active', true)->findOrFail($data['financial_account_id']);

        $this->service->markPaid($expense, $request->user(), $account);

        return back()->with('status', "Expense marked as paid from {$account->name} and posted to the ledger.");
    }
}
