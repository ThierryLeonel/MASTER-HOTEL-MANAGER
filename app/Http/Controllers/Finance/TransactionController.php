<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\PaymentLedgerService;
use Illuminate\Http\Request;

/**
 * Direct request ("the owner is suppose to be able to edit all
 * transaction[s]"): a single, hotel-wide view of the central `payments`
 * ledger — every payment, refund, expense payout, disbursement, and
 * payroll payout in the system, regardless of which module originally
 * posted it (see PaymentLedgerService's own docblock on why this one
 * table is the single point of entry for all of them). Owner-only (the
 * 'void-transactions' permission on every route below), consistent with
 * how this system has repeatedly carved out Owner-only capabilities from
 * a broader one — see 'manage-system-data'/'edit-room-details'/
 * 'delete-users' in RolesAndPermissionsSeeder.
 *
 * "Edit" here means void-then-correct, never an in-place rewrite of a
 * posted amount — see PaymentLedgerService::void()'s docblock for why.
 */
class TransactionController extends Controller
{
    public function __construct(private readonly PaymentLedgerService $ledger)
    {
    }

    public function index(Request $request)
    {
        $transactions = Payment::with(['financialAccount', 'recordedBy', 'guest', 'voidedBy', 'department'])
            ->when($request->search, fn ($q, $search) => $q->where('payment_number', 'like', "%{$search}%")
                ->orWhere('source_reference', 'like', "%{$search}%")
                ->orWhere('notes', 'like', "%{$search}%")
                ->orWhere('income_source', 'like', "%{$search}%"))
            ->when($request->source_module, fn ($q, $module) => $q->where('source_module', $module))
            ->when($request->boolean('voided_only'), fn ($q) => $q->whereNotNull('voided_at'))
            ->orderByDesc('recorded_at')
            ->paginate(30)
            ->withQueryString();

        $sourceModules = Payment::query()->distinct()->orderBy('source_module')->pluck('source_module');

        return view('finance.transactions.index', compact('transactions', 'sourceModules'));
    }

    public function void(Request $request, Payment $payment)
    {
        $data = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $this->ledger->void($payment, $data['reason'], $request->user());

        return back()->with('status', "Transaction {$payment->payment_number} voided. It no longer counts toward any account balance, reconciliation, or report.");
    }
}
