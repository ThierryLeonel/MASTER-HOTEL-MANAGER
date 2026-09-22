<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\AccountsReceivable;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Services\Finance\AccountsReceivableService;
use Illuminate\Http\Request;

/**
 * "Client debt" (PHASE9_AUDIT_AND_PLAN.md §22) — every outstanding
 * balance left over from a checkout, whether owed by a single guest or
 * rolled up under a company across several stays.
 */
class AccountsReceivableController extends Controller
{
    public function __construct(private readonly AccountsReceivableService $service)
    {
    }

    public function index(Request $request)
    {
        $receivables = AccountsReceivable::with(['guest', 'company', 'folio.stay'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when(! $request->filled('status'), fn ($q) => $q->whereIn('status', ['open', 'partially_paid']))
            ->orderBy('due_date')
            ->paginate(25);

        $companies = Company::where('is_active', true)
            ->withSum(['accountsReceivable as outstanding_sum' => fn ($q) => $q->whereIn('status', ['open', 'partially_paid'])], 'amount_outstanding')
            ->orderBy('name')
            ->get();
        // Fourth post-9.8 fix round (day-close rebuild): the "settle" form
        // now picks a real financial account instead of a bare method —
        // see FolioController::payment()'s docblock.
        $financialAccounts = FinancialAccount::where('is_active', true)->orderBy('name')->get();

        return view('finance.accounts-receivable.index', compact('receivables', 'companies', 'financialAccounts'));
    }

    /**
     * Fourth post-9.8 fix round (day-close rebuild): `financial_account_id`
     * replaces the old bare `method` select — see
     * FolioController::payment()'s docblock for the same reasoning.
     */
    public function settle(Request $request, AccountsReceivable $accountsReceivable)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'financial_account_id' => 'required|exists:financial_accounts,id',
            'notes' => 'nullable|string|max:500',
        ]);

        // Correction round (audited defect #31): see PaymentController::
        // store()'s matching comment — never trust the submitted id alone.
        $account = FinancialAccount::where('is_active', true)->findOrFail($data['financial_account_id']);

        $this->service->recordSettlement($accountsReceivable, (float) $data['amount'], $account->paymentMethod(), $request->user(), $data['notes'] ?? null, $account->id);

        return back()->with('status', 'Payment recorded against the outstanding debt.');
    }
}
