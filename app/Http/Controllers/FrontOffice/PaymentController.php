<?php

namespace App\Http\Controllers\FrontOffice;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\FinancialAccount;
use App\Models\Guest;
use App\Models\IncomeSource;
use App\Models\Payment;
use App\Services\PaymentLedgerService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
    /**
     * Sept 2026 revenue-recognition fix: the only departments a general
     * payment can be explicitly attributed to from this screen — the
     * outlets that hand over their takings here in lieu of their own POS
     * device (see this class's store() docblock and
     * FinancialReportService::revenueByChargeType() for the full
     * reasoning). Front Office is deliberately excluded from this list —
     * it's already the automatic default for every payment recorded
     * here (PaymentLedgerService::SOURCE_MODULE_DEPARTMENT_CODES), so
     * there's nothing for staff to choose. Back-office departments
     * (Finance, Management, Housekeeping, Maintenance) have no sales of
     * their own and are deliberately never offered here either.
     */
    private const ATTRIBUTABLE_DEPARTMENT_CODES = ['BAR', 'REST', 'BAROUT', 'BUCA'];

    public function __construct(private readonly PaymentLedgerService $ledger)
    {
    }

    public function index(Request $request)
    {
        $payments = Payment::with(['guest', 'recordedBy', 'financialAccount', 'department'])
            ->where('source_module', 'general_payment')
            ->orderByDesc('id')
            ->paginate(25);
        // Fourth post-9.8 fix round (day-close rebuild): the "record
        // payment" form now picks a real financial account instead of a
        // bare method — see this controller's store() docblock.
        $financialAccounts = FinancialAccount::where('is_active', true)->orderBy('name')->get();
        // Follow-up request ("the list should not be fixed... [staff]
        // should be able to update, create and delete it"): the dropdown
        // now reads the managed IncomeSource list instead of a hardcoded
        // PHP constant — see that model's own docblock.
        $incomeSources = IncomeSource::orderBy('name')->get();
        // Sept 2026 revenue-recognition fix — see ATTRIBUTABLE_DEPARTMENT_CODES above.
        $attributableDepartments = Department::whereIn('code', self::ATTRIBUTABLE_DEPARTMENT_CODES)
            ->get()
            ->sortBy(fn ($d) => array_search($d->code, self::ATTRIBUTABLE_DEPARTMENT_CODES))
            ->values();

        return view('front-office.payments.index', compact('payments', 'financialAccounts', 'incomeSources', 'attributableDepartments'));
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
            // Direct request ("all in payment must be attached to an
            // income source that is to be represented ... in the income
            // statement"): every payment recorded here is money coming
            // in (PaymentLedgerService::record() defaults `direction` to
            // 'in', and this screen never overrides it), so every one of
            // them must say what kind of income it is. Stays free text
            // here so the column and this validation are backward
            // compatible with values already in the database and with
            // existing tests — the managed list (see the IncomeSource
            // model) is offered by the form's <select>, not enforced
            // here. Follow-up request ("let the income source be a
            // dropdown... if other then state"): the form submits the
            // sentinel '__other__' plus `income_source_other` when the
            // accountant picks "Other" — resolved below into the same
            // free-text value. Feeds FinancialReportService::otherIncomeBySource().
            'income_source' => 'required|string|max:120',
            'income_source_other' => 'required_if:income_source,__other__|nullable|string|max:120',
            // Direct request ("payments can involve discount if
            // granted"): `amount` above is always what was actually
            // received; a discount, if one was granted, is recorded
            // alongside it purely for transparency (gross vs net) — see
            // this column's migration. Both optional; `discount_reason`
            // is required the moment an amount is given, so "a discount
            // was granted" is never recorded without saying why.
            'discount_amount' => 'nullable|numeric|min:0.01',
            'discount_reason' => 'required_with:discount_amount|nullable|string|max:255',
            // Sept 2026 revenue-recognition fix ("the income payment
            // recorded by the accountant is not completely recognised as
            // income... because the bar and the restaurant are not
            // having their own device at the moment so they pay their
            // total amount sold to the accountant and he reconciles
            // them"): optional — leaving this blank keeps today's
            // behaviour exactly (auto-attributed to Front Office).
            // Restricted server-side to the real outlet department ids,
            // never trusting an arbitrary id from the request, the same
            // way `financial_account_id` above is re-verified against
            // active accounts rather than trusted at face value.
            'department_id' => [
                'nullable',
                Rule::in(Department::whereIn('code', self::ATTRIBUTABLE_DEPARTMENT_CODES)->pluck('id')->all()),
            ],
            // Direct request (Sept 2026 refinement): "the place in the
            // payment form that request on who handed the sales should
            // either be the department or a textbox that receive the
            // name of the person handing." Independent of department_id
            // above — either, both, or neither can be filled in.
            'handed_over_by' => 'nullable|string|max:120',
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

        // Follow-up request ("let the income source be a dropdown... if
        // other then state"): "Other" is a UI sentinel only — the actual
        // value stored is always the stated text, exactly as it was
        // before this dropdown existed for every other choice.
        $incomeSource = $data['income_source'] === '__other__'
            ? $data['income_source_other']
            : $data['income_source'];

        $this->ledger->record(
            amount: (float) $data['amount'],
            method: $account->paymentMethod(),
            sourceModule: 'general_payment',
            recordedBy: $request->user(),
            sourceReference: $guest ? "Guest #{$guest->id}" : ($data['payer_name'] ?? null),
            guestId: $guest?->id,
            notes: $description,
            financialAccountId: $account->id,
            incomeSource: $incomeSource,
            discountAmount: isset($data['discount_amount']) ? (float) $data['discount_amount'] : null,
            discountReason: $data['discount_reason'] ?? null,
            departmentId: ! empty($data['department_id']) ? (int) $data['department_id'] : null,
            handedOverBy: $data['handed_over_by'] ?? null,
        );

        return back()->with('status', 'Payment recorded.');
    }
}
