<?php

namespace App\Http\Controllers\FrontOffice;

use App\Http\Controllers\Controller;
use App\Models\FinancialAccount;
use App\Models\Guest;
use App\Models\Payment;
use App\Services\PaymentLedgerService;
use Illuminate\Http\Request;

/**
 * Post-9.8 fix round ("there is supposed to be a payment module, where
 * the receptionist records all payments... while there is still room to
 * create new payments, since other payments from may be rent, etc can be
 * made by visitors"): before this controller, PaymentLedgerService
 * (the single writer of the central `payments` ledger) was only ever
 * called FROM another module's own flow — a folio payment, a POS order
 * close, an expense payout, a payroll run — there was no screen where a
 * payment could be recorded on its own, for something that isn't a
 * guest's room/POS bill at all (rent from a visitor, a random walk-in
 * payment, etc.). This is that screen. It intentionally does not attach
 * to a folio or order — for a guest's actual room/POS bill, staff should
 * still use that stay's "Post payment" form (FolioController::payment())
 * or the relevant POS close screen, both of which already correctly tie
 * the payment to the right ledger entry automatically. This module is
 * for the money that doesn't belong to either of those.
 */
class PaymentController extends Controller
{
    public function __construct(private readonly PaymentLedgerService $ledger)
    {
    }

    public function index(Request $request)
    {
        $payments = Payment::with(['guest', 'recordedBy', 'financialAccount'])
            ->where('source_module', 'general_payment')
            ->orderByDesc('id')
            ->paginate(25);
        // Fourth post-9.8 fix round (day-close rebuild): the "record
        // payment" form now picks a real financial account instead of a
        // bare method — see this controller's store() docblock.
        $financialAccounts = FinancialAccount::where('is_active', true)->orderBy('name')->get();

        return view('front-office.payments.index', compact('payments', 'financialAccounts'));
    }

    /**
     * Fourth post-9.8 fix round (day-close rebuild): `financial_account_id`
     * replaces the old bare `method` select — see
     * FolioController::payment()'s docblock for the same reasoning. This
     * screen in particular is exactly the kind of payment the owner
     * described as needing to reconcile ("capture and reconcile the
     * payments from guest, visitors, incomes...") — an unattributed
     * "mobile_money" payment here used to be untraceable to MTN vs Orange
     * at reconciliation time; now it's tied to the real account it landed
     * in from the moment it's recorded.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'financial_account_id' => 'required|exists:financial_accounts,id',
            'guest_id' => 'nullable|exists:guests,id',
            'payer_name' => 'nullable|string|max:255',
            'notes' => 'required|string|max:255',
        ]);

        $guest = ! empty($data['guest_id']) ? Guest::find($data['guest_id']) : null;
        // Correction round (audited defect #31): "require active financial
        // account for receipts/payouts" — the account picklist only ever
        // offers active accounts, but that's a UI convenience, not a
        // guarantee; a crafted request naming a since-deactivated account's
        // id must still be refused server-side rather than silently
        // recording a receipt against a frozen/closed account.
        $account = FinancialAccount::where('is_active', true)->findOrFail($data['financial_account_id']);

        $description = $data['notes'];
        if (! $guest && ! empty($data['payer_name'])) {
            $description = "{$data['payer_name']} — {$description}";
        }

        $this->ledger->record(
            amount: (float) $data['amount'],
            method: $account->paymentMethod(),
            sourceModule: 'general_payment',
            recordedBy: $request->user(),
            sourceReference: $guest ? "Guest #{$guest->id}" : ($data['payer_name'] ?? null),
            guestId: $guest?->id,
            notes: $description,
            financialAccountId: $account->id,
        );

        return back()->with('status', 'Payment recorded.');
    }
}
