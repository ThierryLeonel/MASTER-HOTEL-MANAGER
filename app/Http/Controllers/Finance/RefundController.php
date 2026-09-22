<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\FinancialAccount;
use App\Models\Folio;
use App\Models\RefundRequest;
use App\Services\Finance\RefundService;
use Illuminate\Http\Request;

class RefundController extends Controller
{
    public function __construct(private readonly RefundService $service)
    {
    }

    public function index(Request $request)
    {
        $refunds = RefundRequest::with(['folio.stay.guest', 'requestedBy', 'financialAccount'])
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('id')
            ->paginate(20);
        $financialAccounts = FinancialAccount::where('is_active', true)->orderBy('name')->get();

        return view('finance.refunds.index', compact('refunds', 'financialAccounts'));
    }

    public function store(Request $request, Folio $folio)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:255',
            'method' => 'required|in:cash,mobile_money,card,bank_transfer',
            // Correction round (audited defect #20): an explicit,
            // separate checkbox — never inferred — for a goodwill
            // compensation, which is exempt from the "available
            // refundable funds" cap at approval.
            'is_goodwill' => 'nullable|boolean',
        ]);

        $this->service->request(
            $folio,
            (float) $data['amount'],
            $data['reason'],
            $data['method'],
            $request->user(),
            (bool) ($data['is_goodwill'] ?? false),
        );

        return back()->with('status', 'Refund requested and awaiting approval.');
    }

    /**
     * Sixth post-9.8 fix round: which specific account the refund is
     * actually paid back out of, required — see RefundService::approve()'s
     * docblock. Mirrors ExpenseController::pay()'s exact pattern.
     */
    public function approve(Request $request, RefundRequest $refund)
    {
        $data = $request->validate(['financial_account_id' => 'required|exists:financial_accounts,id']);

        // Correction round (audited defect #31): see PaymentController::
        // store()'s matching comment — never trust the submitted id alone.
        $account = FinancialAccount::where('is_active', true)->findOrFail($data['financial_account_id']);

        $this->service->approve($refund, $request->user(), $account);

        return back()->with('status', "Refund approved and paid out from {$account->name}.");
    }

    public function reject(Request $request, RefundRequest $refund)
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);

        $this->service->reject($refund, $request->user(), $data['reason']);

        return back()->with('status', 'Refund rejected.');
    }
}
