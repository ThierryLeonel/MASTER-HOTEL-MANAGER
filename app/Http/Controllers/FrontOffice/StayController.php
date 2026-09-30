<?php

namespace App\Http\Controllers\FrontOffice;

use App\Exceptions\HmsException;
use App\Http\Controllers\Controller;
use App\Models\FinancialAccount;
use App\Models\Stay;
use App\Services\AuditLogger;
use App\Services\FrontOffice\CheckOutService;
use App\Services\FrontOffice\FolioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StayController extends Controller
{
    public function __construct(
        private readonly CheckOutService $checkOutService,
        private readonly FolioService $folioService,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index()
    {
        $stays = Stay::with(['guest', 'room', 'folio'])
            ->where('status', 'active')
            ->orderBy('expected_check_out_date')
            ->get();

        return view('front-office.stays.index', compact('stays'));
    }

    public function show(Stay $stay)
    {
        $stay->load(['guest', 'room.category', 'folio.transactions.user', 'folio.accountsReceivable']);

        // Fourth post-9.8 fix round (day-close rebuild): the "Post
        // payment" form now picks a real financial account instead of a
        // bare method — see FolioController::payment()'s docblock.
        $financialAccounts = FinancialAccount::where('is_active', true)->orderBy('name')->get();

        // Charge-types unification (Sept 2026): the "Post charge" dropdown
        // used to be a hardcoded 7-option <select> — now reads the live,
        // admin-editable list, so a newly added charge type (e.g. "Rental
        // Charge") shows up here immediately with no template change.
        $postableChargeTypes = \App\Models\ChargeType::where('is_active', true)
            ->where('postable_to_folio', true)
            ->orderBy('name')
            ->get();

        return view('front-office.stays.show', compact('stay', 'financialAccounts', 'postableChargeTypes'));
    }

    /**
     * Phase 9.5 (PHASE9_AUDIT_AND_PLAN.md §3.6): "Guest Invoice" — a
     * printable folio ledger available at any point during (or after) a
     * stay. Shares its content with printFinalBill() below via the
     * folio-print partial, since both are the exact same folio ledger
     * data presented at two different points in the stay's lifecycle —
     * never two independently-computed totals.
     */
    public function printInvoice(Stay $stay)
    {
        $stay->load(['guest', 'room.category', 'folio.transactions.user', 'folio.accountsReceivable']);

        // hidePrintHeader: this document already carries its own header,
        // twice (once per printed copy) — see layouts/print.blade.php's
        // docblock and _folio-receipt-copy.blade.php.
        return view('front-office.stays.folio-print', ['stay' => $stay, 'documentTitle' => 'Guest Invoice', 'hidePrintHeader' => true]);
    }

    /**
     * "Final Hotel Bill" — the same folio ledger, but only meaningful
     * once the stay is actually closed (checked out); requesting it
     * before then is a genuine business-rule violation, not just a
     * missing-data case, so it goes through HmsException like every
     * other state-machine guard in this codebase.
     */
    public function printFinalBill(Stay $stay)
    {
        if ($stay->status !== 'closed') {
            throw new HmsException('The final hotel bill is only available after the guest has checked out — use the Guest Invoice for an in-stay folio.');
        }

        $stay->load(['guest', 'room.category', 'folio.transactions.user', 'folio.accountsReceivable']);

        return view('front-office.stays.folio-print', ['stay' => $stay, 'documentTitle' => 'Final Hotel Bill', 'hidePrintHeader' => true]);
    }

    public function checkout(Request $request, Stay $stay)
    {
        // Global hospitality-standard change: charging is now automatic —
        // the full stay cost was already posted at check-in, so checkout
        // just charges any genuine extension (see CheckOutService) and no
        // longer takes a manual "post final night" flag at all.
        $stay = $this->checkOutService->checkOut($stay, $request->user());

        // Correction round (audited defect #21, "never describe negative
        // balance as fully settled"): a folio balance is only zero (truly
        // "fully settled") when nothing is owed in either direction.
        // CheckOutService only ever creates an AccountsReceivable for a
        // POSITIVE remaining balance (bccomp(...) > 0) — a NEGATIVE balance
        // (the guest overpaid, e.g. an unapplied deposit or an approved
        // refund/credit not yet paid out) has no AR record either, so this
        // used to fall into the same "fully settled" branch as a genuine
        // zero balance, wrongly implying nothing further was owed when in
        // fact the hotel owes the guest a credit. Now branches on the
        // folio's actual balance, not merely on whether AR exists.
        $balance = (float) $stay->folio->balance;

        $message = match (true) {
            $stay->folio->accountsReceivable !== null => 'Guest checked out. Outstanding balance moved to Accounts Receivable.',
            bccomp((string) $balance, '0.00', 2) < 0 => 'Guest checked out. Guest has a credit balance of '.number_format(abs($balance), 0).' XAF still owed to them — settle via a refund or credit-settlement on the folio.',
            default => 'Guest checked out. Folio fully settled.',
        };

        return redirect()->route('stays.show', $stay)->with('status', $message);
    }

    /**
     * "Special guest lodging" (added at the user's request — see
     * PHASE9_AUDIT_AND_PLAN.md §9): waives ONLY this stay's room charge
     * — CheckOutService skips its automatic final-night post for a
     * complimentary stay, and FolioService::postCharge() refuses a
     * manual room_charge post against one. Every other charge type
     * (bar/restaurant/front_desk/other_service) is completely unaffected
     * and still posts and gets paid normally. Restricted to an active
     * stay: marking one complimentary after checkout would have no
     * remaining effect (there's nothing left to waive), so it would only
     * misleadingly rewrite history.
     *
     * Global hospitality-standard change: since CheckInService now posts
     * the full lodging cost upfront at check-in (rather than leaving it
     * to an optional checkout-time charge), a stay that is later marked
     * complimentary may already carry a real room_charge on its folio.
     * "Waiving" the room charge here has to actually waive it — so every
     * not-yet-voided room_charge transaction on the folio is reversed
     * (via FolioService::void(), the existing reversing-entry mechanism —
     * history is never edited or deleted, spec section 12) as part of
     * marking the stay complimentary.
     */
    /**
     * Correction round (audited defect #22): had no DB::transaction/lock
     * at all — the active-stay check ran against the caller-supplied,
     * unlocked $stay, so a race against checkout (which does lock the
     * Stay) could mark a just-checked-out stay complimentary, voiding
     * room charges on a folio that had already closed. Locked and
     * re-checked here like every other stay-lifecycle action.
     */
    public function markComplimentary(Request $request, Stay $stay)
    {
        $data = $request->validate(['reason' => 'required|string|max:1000']);

        DB::transaction(function () use ($request, $stay, $data) {
            /** @var Stay $stay */
            $stay = Stay::lockForUpdate()->findOrFail($stay->id);

            if ($stay->status !== 'active') {
                throw new HmsException('Only an active stay can be marked complimentary.');
            }

            $oldValues = $stay->only(['is_complimentary', 'complimentary_reason']);

            $stay->update([
                'is_complimentary' => true,
                'complimentary_reason' => $data['reason'],
                'marked_complimentary_by' => $request->user()->id,
                'marked_complimentary_at' => now(),
                // Reset so that if complimentary status is later removed
                // (unmarkComplimentary below), the room charge is
                // immediately re-posted for the full stay-so-far rather
                // than silently relying on checkout's extra-nights
                // calculation to ever recover it.
                'nights_charged' => 0,
            ]);

            $stay->folio->transactions()
                ->where('type', 'room_charge')
                ->whereNull('voided_at')
                ->get()
                ->each(fn ($transaction) => $this->folioService->void($transaction, "Stay marked complimentary: {$data['reason']}", $request->user()));

            $this->audit->log('stay.marked_complimentary', 'front_office', $stay, $oldValues, $stay->only(['is_complimentary', 'complimentary_reason']), $data['reason'], $request->user());
        });

        return back()->with('status', "Stay marked complimentary — the room charge is waived (any already-posted room charge has been reversed); other charges are unaffected.");
    }

    /**
     * Correction round (audited defect #22): previously flipped
     * is_complimentary back to false and did nothing else — with
     * nights_charged left at 0 (set by markComplimentary() above), the
     * folio's balance showed the room as fully paid/free until checkout's
     * own extra-nights math eventually caught up, which — if anyone
     * checked the folio balance in between — silently looked like the
     * hotel was owed nothing for the room during that whole window. Now
     * locked+rechecked like every other stay action, and immediately
     * re-posts a real room_charge for the nights owed so far (same
     * calculation CheckInService uses), so the folio balance is correct
     * the moment complimentary status is removed, not just eventually.
     */
    public function unmarkComplimentary(Request $request, Stay $stay)
    {
        DB::transaction(function () use ($request, $stay) {
            /** @var Stay $stay */
            $stay = Stay::lockForUpdate()->findOrFail($stay->id);

            if ($stay->status !== 'active') {
                throw new HmsException('Only an active stay can have its complimentary status changed.');
            }

            if (! $stay->is_complimentary) {
                throw new HmsException('This stay is not currently marked complimentary.');
            }

            $oldValues = $stay->only(['is_complimentary', 'complimentary_reason', 'nights_charged']);

            $stay->update([
                'is_complimentary' => false,
                'complimentary_reason' => null,
                'marked_complimentary_by' => null,
                'marked_complimentary_at' => null,
            ]);

            $nightsOwed = $stay->nightsUntil(now());

            if ($nightsOwed > $stay->nights_charged) {
                $extraNights = $nightsOwed - $stay->nights_charged;
                $folio = $stay->folio()->firstOrFail();

                $this->folioService->postCharge(
                    $folio,
                    'room_charge',
                    round((float) $stay->agreed_rate * $extraNights, 2),
                    "Room charge — complimentary status removed, {$extraNights} night(s) now billable",
                    $request->user(),
                );

                $stay->update(['nights_charged' => $nightsOwed]);
            }

            $this->audit->log('stay.unmarked_complimentary', 'front_office', $stay, $oldValues, $stay->only(['is_complimentary', 'complimentary_reason', 'nights_charged']), actor: $request->user());
        });

        return back()->with('status', 'Complimentary status removed — the room charge for the stay so far has been re-posted, and normal billing resumes.');
    }
}
