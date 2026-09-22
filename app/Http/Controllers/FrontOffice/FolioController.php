<?php

namespace App\Http\Controllers\FrontOffice;

use App\Http\Controllers\Controller;
use App\Models\FinancialAccount;
use App\Models\Folio;
use App\Models\FolioTransaction;
use App\Services\Finance\ShiftGateService;
use App\Services\FrontOffice\FolioService;
use Illuminate\Http\Request;

class FolioController extends Controller
{
    public function __construct(
        private readonly FolioService $service,
        private readonly ShiftGateService $shiftGate,
    ) {
    }

    public function charge(Request $request, Folio $folio)
    {
        // A folio charge never touches PaymentLedgerService (it's not a
        // payment), so the "no transaction without an open shift" rule
        // (PHASE9_AUDIT_AND_PLAN.md §21) has to be enforced explicitly
        // here rather than picked up automatically the way folio payments
        // are.
        $this->shiftGate->assertOpenForCode('FO');

        $data = $request->validate([
            'type' => 'required|in:room_charge,bar_charge,restaurant_charge,front_desk_charge,other_service',
            'amount' => 'required|numeric|min:0.01',
            'description' => 'required|string|max:255',
        ]);

        $this->service->postCharge($folio, $data['type'], (float) $data['amount'], $data['description'], $request->user());

        return back()->with('status', 'Charge posted to folio.');
    }

    /**
     * Fourth post-9.8 fix round (day-close rebuild): `financial_account_id`
     * replaces the old bare `method` select — same reasoning as
     * ExpenseController::pay()'s "for expenses specify the particular
     * financial account" fix — so a day close can reconcile this payment
     * against the real Cash/Bank/MTN/Orange account it actually landed in,
     * not just a generic "mobile_money" bucket. `method` is derived from
     * the chosen account rather than picked separately, so they can never
     * disagree.
     */
    public function payment(Request $request, Folio $folio)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'financial_account_id' => 'required|exists:financial_accounts,id',
            // Correction round (audited defect #32).
            'idempotency_key' => 'nullable|string|max:80',
        ]);

        // Correction round (audited defect #31): see
        // ReservationController::recordDeposit()'s matching comment —
        // never trust the submitted id alone.
        $account = FinancialAccount::where('is_active', true)->findOrFail($data['financial_account_id']);

        $this->service->postPayment(
            $folio,
            (float) $data['amount'],
            $account->paymentMethod(),
            $request->user(),
            financialAccountId: $account->id,
            idempotencyKey: $data['idempotency_key'] ?? null,
        );

        return back()->with('status', 'Payment posted to folio.');
    }

    public function void(Request $request, FolioTransaction $transaction)
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);

        $this->service->void($transaction, $data['reason'], $request->user());

        return back()->with('status', 'Transaction voided (reversing entry posted).');
    }

    /**
     * Phase 9.5 (PHASE9_AUDIT_AND_PLAN.md §3.6): printable "Payment
     * Receipt" for a single payment transaction — 404s (not merely
     * "wrong data") for a non-payment transaction, since a receipt for a
     * charge or refund is a different named document, not this one.
     */
    public function printReceipt(FolioTransaction $transaction)
    {
        abort_unless($transaction->type === 'payment', 404);

        $transaction->load(['folio.stay.guest', 'folio.stay.room', 'user']);

        return view('front-office.folios.receipt-print', compact('transaction'));
    }
}
