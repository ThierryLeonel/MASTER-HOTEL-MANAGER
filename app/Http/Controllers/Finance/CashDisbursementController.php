<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\CashDisbursement;
use App\Models\Department;
use App\Models\DisbursementTicket;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\Finance\CashDisbursementService;
use Illuminate\Http\Request;

class CashDisbursementController extends Controller
{
    public function __construct(private readonly CashDisbursementService $service)
    {
    }

    public function index(Request $request)
    {
        $tickets = DisbursementTicket::with(['department', 'authorizedBy'])
            ->orderByDesc('id')->limit(50)->get();
        $disbursements = CashDisbursement::with(['ticket', 'department', 'authorizedByUser', 'recordedBy', 'financialAccount', 'expense'])
            ->orderByDesc('id')->paginate(20);
        $departments = Department::where('is_active', true)->orderBy('name')->get();
        $openTickets = $tickets->where('status', 'open');
        $managers = User::role(['owner', 'general_manager', 'duty_manager'])->orderBy('name')->get();
        $financialAccounts = FinancialAccount::where('is_active', true)->orderBy('name')->get();
        // "Reference ... the expense ... done": only an 'approved' expense
        // can be picked — the moment one is disbursed against, it flips to
        // 'paid' (see ExpenseService::markPaidViaDisbursement()) and drops
        // out of this list on its own, so there's no separate "already
        // linked" state to filter out. CashDisbursementService::disburse()
        // re-checks this same status under a row lock at submission time,
        // so this list being a moment stale is never a correctness issue.
        $payableExpenses = Expense::where('status', 'approved')->orderByDesc('id')->get();

        return view('finance.cash-disbursements.index', compact('tickets', 'disbursements', 'departments', 'openTickets', 'managers', 'financialAccounts', 'payableExpenses'));
    }

    public function storeTicket(Request $request)
    {
        $data = $request->validate([
            'department_id' => 'nullable|exists:departments,id',
            'purpose' => 'required|string|max:500',
            'amount' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string|max:500',
        ]);

        $this->service->createTicket(
            (float) $data['amount'], $data['purpose'], $request->user(), $data['department_id'] ?? null, $data['notes'] ?? null,
        );

        return back()->with('status', 'Disbursement ticket created.');
    }

    public function cancelTicket(Request $request, DisbursementTicket $ticket)
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);

        $this->service->cancelTicket($ticket, $request->user(), $data['reason']);

        return back()->with('status', 'Ticket cancelled.');
    }

    /**
     * Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §21): "same
     * goes for cash disbursement" — `financial_account_id` is required,
     * same as ExpenseController::pay(); `method` is derived from the
     * chosen account inside CashDisbursementService::disburse().
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'ticket_id' => 'nullable|exists:disbursement_tickets,id',
            'amount' => 'required|numeric|min:0.01',
            'paid_to' => 'required|string|max:150',
            'purpose' => 'required|string|max:1000',
            'expense_id' => 'nullable|exists:expenses,id',
            'financial_account_id' => 'required|exists:financial_accounts,id',
            'department_id' => 'nullable|exists:departments,id',
            'authorized_by_user_id' => 'nullable|exists:users,id',
            'authorized_by_name' => 'nullable|string|max:150',
            'authorized_by_title' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
        ]);

        $this->service->disburse($data, $request->user());

        return back()->with('status', 'Finance disbursement recorded and posted to the ledger.');
    }

    public function print(CashDisbursement $cashDisbursement)
    {
        $cashDisbursement->load(['ticket.authorizedBy', 'department', 'authorizedByUser', 'recordedBy', 'financialAccount', 'expense']);

        return view('finance.cash-disbursements.print', ['disbursement' => $cashDisbursement]);
    }
}
