<?php

namespace App\Http\Controllers\FrontOffice;

use App\Http\Controllers\Controller;
use App\Models\FinancialAccount;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\User;
use App\Services\FrontOffice\DiscountApprovalService;
use App\Services\FrontOffice\ReservationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReservationController extends Controller
{
    public function __construct(
        private readonly ReservationService $service,
        private readonly DiscountApprovalService $discountApproval,
    ) {
    }

    public function index(Request $request)
    {
        $reservations = Reservation::with(['guest', 'roomCategory', 'room'])
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('id')
            ->paginate(20);

        return view('front-office.reservations.index', compact('reservations'));
    }

    public function create(Request $request)
    {
        $categories = RoomCategory::where('is_active', true)->get();

        // Fourth post-9.8 fix round, follow-up: the guest field used to be
        // a <select> built from Guest::orderBy('first_name')->limit(200) —
        // unusable past 200 guests, and a poor experience even under that,
        // since finding one person meant scrolling a long alphabetical
        // list. Replaced with a search-as-you-type box (guests.search),
        // same pattern as checkin/walk-in.blade.php. This method now only
        // needs to resolve one specific guest to prefill — arriving here
        // via the "Reservation" button on a just-created guest's page
        // (guests.show's `new=1` callout) — not build a list of every
        // guest in the database.
        $prefillGuest = null;
        if ($prefillGuestId = $request->integer('guest_id') ?: null) {
            $prefillGuest = Guest::find($prefillGuestId);
        }

        return view('front-office.reservations.create', compact('categories', 'prefillGuest'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'guest_id' => 'required|exists:guests,id',
            'room_category_id' => 'required|exists:room_categories,id',
            'check_in_date' => 'required|date|after_or_equal:today',
            'check_out_date' => 'required|date|after:check_in_date',
            'adults' => 'nullable|integer|min:1|max:20',
            'children' => 'nullable|integer|min:0|max:20',
            'rate_quoted' => 'required|numeric|min:0',
            'deposit_required' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $reservation = $this->service->create($data, $request->user());

        return redirect()->route('reservations.show', $reservation)->with('status', 'Reservation created and awaiting approval.');
    }

    /**
     * Direct request ("i want to be able to book multi reservations and
     * multi bookings instead of entering 1 by 1"): the form for one
     * guest/party booking several rooms, for the same stay dates, in one
     * submission — see ReservationService::createGroup()'s docblock for
     * the full reasoning.
     */
    public function createMulti(Request $request)
    {
        $categories = RoomCategory::where('is_active', true)->get();

        $prefillGuest = null;
        if ($prefillGuestId = $request->integer('guest_id') ?: null) {
            $prefillGuest = Guest::find($prefillGuestId);
        }

        return view('front-office.reservations.create-multi', compact('categories', 'prefillGuest'));
    }

    public function storeMulti(Request $request)
    {
        $data = $request->validate([
            'guest_id' => 'required|exists:guests,id',
            'check_in_date' => 'required|date|after_or_equal:today',
            'check_out_date' => 'required|date|after:check_in_date',
            'notes' => 'nullable|string',
            'rooms' => 'required|array|min:1',
            'rooms.*.room_category_id' => 'required|exists:room_categories,id',
            'rooms.*.rate_quoted' => 'required|numeric|min:0',
            'rooms.*.adults' => 'nullable|integer|min:1|max:20',
            'rooms.*.children' => 'nullable|integer|min:0|max:20',
            'rooms.*.deposit_required' => 'nullable|numeric|min:0',
        ]);

        $shared = [
            'guest_id' => $data['guest_id'],
            'check_in_date' => $data['check_in_date'],
            'check_out_date' => $data['check_out_date'],
            'notes' => $data['notes'] ?? null,
        ];

        $reservations = $this->service->createGroup($shared, $data['rooms'], $request->user());

        return redirect()->route('reservations.show', $reservations->first())
            ->with('status', $reservations->count().' rooms booked together and awaiting approval.');
    }

    public function show(Reservation $reservation)
    {
        $reservation->load(['guest', 'roomCategory', 'room', 'requestedBy', 'approvedBy']);
        // Correction round (audited defect #5): no longer restricted to
        // ONLY this reservation's category — an approver may deliberately
        // reassign to a different category via the explicit
        // confirm_category_override checkbox (ReservationService::
        // approve()), so every available room across all categories is
        // listed, with its category shown, for that decision to be made
        // with full information rather than being impossible from this
        // screen.
        $availableRooms = Room::with('category')
            ->where('status', '!=', 'out_of_service')
            ->get()
            ->filter(fn (Room $room) => $room->isAvailableFor($reservation->check_in_date, $reservation->check_out_date, $reservation->id));

        // Fourth post-9.8 fix round (day-close rebuild): the "Record
        // deposit" form now picks a real financial account instead of a
        // bare method — see FolioController::payment()'s docblock.
        $financialAccounts = FinancialAccount::where('is_active', true)->orderBy('name')->get();

        // Feature 6: the "Discount approved by" dropdown — every active
        // user holding an eligible role, for a non-eligible staff member
        // to address a discount request to. Purely informational for
        // picking WHO to ask; selecting a name here is never itself proof
        // of approval (see DiscountApprovalService's docblock).
        $eligibleApprovers = User::role(DiscountApprovalService::ELIGIBLE_ROLES)->where('is_active', true)->orderBy('name')->get();

        // Direct request ("book multi reservations and multi bookings
        // instead of entering 1 by 1"): every other room booked in the
        // same multi-room submission as this one, so staff can see and
        // jump to the rest of the party from any one of its rooms.
        $groupSiblings = $reservation->isGrouped()
            ? $reservation->groupSiblings()->with(['roomCategory', 'room'])->orderBy('id')->get()
            : collect();

        return view('front-office.reservations.show', compact('reservation', 'availableRooms', 'financialAccounts', 'eligibleApprovers', 'groupSiblings'));
    }

    /**
     * Phase 9.5 (PHASE9_AUDIT_AND_PLAN.md §3.6): printable reservation
     * confirmation — the same data show() already loads and authorizes,
     * rendered through the bare layouts.print instead of layouts.app.
     */
    public function print(Reservation $reservation)
    {
        $reservation->load(['guest', 'roomCategory', 'room', 'requestedBy', 'approvedBy']);

        return view('front-office.reservations.print', compact('reservation'));
    }

    /**
     * "the client can also make advance payment for the lodging... the
     * advance on reservation are also payments" — records a reservation
     * deposit through the same central ledger as every other payment
     * (see ReservationService::recordDeposit()). Gated on
     * 'post-folio-payment' rather than 'manage-reservations' since this
     * is a cash-handling action, not a reservation-lifecycle one — the
     * same permission already used for posting a payment on a guest's
     * folio (routes/web.php's folios.payment route).
     */
    /**
     * Fourth post-9.8 fix round (day-close rebuild): `financial_account_id`
     * replaces the old bare `method` select — see
     * FolioController::payment()'s docblock for the same reasoning.
     */
    /**
     * Correction round (audited defect #33): the only way a guest's
     * uploaded deposit-proof file is ever served — requires authentication
     * plus 'manage-reservations' (this route sits in that permission's
     * middleware group in routes/web.php), streamed straight from the
     * private 'local' disk it's stored on. Never a public, guessable URL.
     */
    public function depositProof(Reservation $reservation): StreamedResponse
    {
        abort_unless($reservation->deposit_proof_path, 404);
        abort_unless(Storage::disk('local')->exists($reservation->deposit_proof_path), 404);

        return Storage::disk('local')->response($reservation->deposit_proof_path);
    }

    public function recordDeposit(Request $request, Reservation $reservation)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'financial_account_id' => 'required|exists:financial_accounts,id',
            // Correction round (audited defect #32): a UUID rendered once
            // into the form — a double-click/retried submit resubmits the
            // same key, which ReservationService::recordDeposit() then
            // recognizes and refuses to double-record.
            'idempotency_key' => 'nullable|string|max:80',
        ]);

        // Correction round (audited defect #31): "require active financial
        // account for receipts/payouts including website mobile-money
        // deposits" — the account picklist only offers active accounts,
        // but a crafted request naming a since-deactivated account's id
        // must still be refused server-side.
        $account = FinancialAccount::where('is_active', true)->findOrFail($data['financial_account_id']);

        $this->service->recordDeposit(
            $reservation,
            (float) $data['amount'],
            $account->paymentMethod(),
            $request->user(),
            financialAccountId: $account->id,
            idempotencyKey: $data['idempotency_key'] ?? null,
        );

        return back()->with('status', 'Deposit recorded — it will be credited against the guest folio automatically at check-in.');
    }

    public function approve(Request $request, Reservation $reservation)
    {
        $this->authorize('approve', $reservation);

        // Correction round (audited defect #5): `confirm_category_override`
        // is a deliberate, explicit checkbox on the approve form — never
        // inferred just because a different room id was submitted. See
        // ReservationService::approve()'s docblock.
        $data = $request->validate([
            'room_id' => 'nullable|exists:rooms,id',
            'confirm_category_override' => 'nullable|boolean',
        ]);

        $this->service->approve(
            $reservation,
            $request->user(),
            $data['room_id'] ?? null,
            (bool) ($data['confirm_category_override'] ?? false),
        );

        return back()->with('status', 'Reservation approved and confirmed.');
    }

    /**
     * Correction round (audited defect #3): the one-time, explicit act of
     * verifying a website guest's claimed deposit and posting it to the
     * ledger — see ReservationService::verifyWebsiteDeposit().
     */
    public function verifyDeposit(Request $request, Reservation $reservation)
    {
        $this->authorize('verifyDeposit', $reservation);

        $data = $request->validate([
            'financial_account_id' => 'required|exists:financial_accounts,id',
            'notes' => 'nullable|string|max:500',
        ]);

        $this->service->verifyWebsiteDeposit(
            $reservation,
            $request->user(),
            (int) $data['financial_account_id'],
            $data['notes'] ?? null,
        );

        return back()->with('status', 'Website deposit verified and posted to the ledger.');
    }

    /**
     * Correction round (audited defect #8): records the one-time,
     * audited outcome of a cancelled/rejected reservation's deposit — see
     * ReservationService::disposeDeposit().
     */
    public function disposeDeposit(Request $request, Reservation $reservation)
    {
        $this->authorize('disposeDeposit', $reservation);

        $data = $request->validate([
            'disposition' => 'required|in:refunded,forfeited,transferred',
            'notes' => 'nullable|string|max:500',
            'financial_account_id' => 'required_if:disposition,refunded|nullable|exists:financial_accounts,id',
            'target_reservation_id' => 'required_if:disposition,transferred|nullable|exists:reservations,id',
        ]);

        $target = isset($data['target_reservation_id'])
            ? Reservation::findOrFail($data['target_reservation_id'])
            : null;

        $this->service->disposeDeposit(
            $reservation,
            $data['disposition'],
            $request->user(),
            $data['notes'] ?? null,
            isset($data['financial_account_id']) ? (int) $data['financial_account_id'] : null,
            $target,
        );

        return back()->with('status', 'Deposit disposition recorded.');
    }

    public function reject(Request $request, Reservation $reservation)
    {
        $this->authorize('reject', $reservation);

        $data = $request->validate(['reason' => 'required|string|max:500']);

        $this->service->reject($reservation, $request->user(), $data['reason']);

        return back()->with('status', 'Reservation rejected.');
    }

    public function cancel(Request $request, Reservation $reservation)
    {
        $this->authorize('cancel', $reservation);

        $data = $request->validate(['reason' => 'required|string|max:500']);

        $this->service->cancel($reservation, $request->user(), $data['reason']);

        return back()->with('status', 'Reservation cancelled.');
    }

    public function noShow(Request $request, Reservation $reservation)
    {
        $this->authorize('cancel', $reservation);

        $this->service->markNoShow($reservation, $request->user());

        return back()->with('status', 'Reservation marked as no-show.');
    }

    /**
     * Feature 6: the currently-authenticated user, if eligible, approves
     * this reservation's own quoted discount directly. Gated by
     * DiscountApprovalService::selfApprove() itself refusing a
     * non-eligible user — the route has no separate permission middleware
     * for exactly that reason (any authenticated reservation-page viewer
     * may attempt it; only an eligible one succeeds).
     */
    public function selfApproveDiscount(Request $request, Reservation $reservation)
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);

        $this->discountApproval->selfApprove(
            $reservation,
            $reservation->roomCategory,
            $reservation->room_id,
            $reservation->check_in_date,
            $reservation->check_out_date,
            (float) $reservation->rate_quoted,
            $request->user(),
            $data['reason'],
        );

        return back()->with('status', 'Discount approved.');
    }

    /**
     * Feature 6: a non-eligible staff member addresses the decision to a
     * specific eligible approver — never treated as approval itself; see
     * DiscountApprovalController::approve() for where that approver
     * actually acts on it, from their own session.
     */
    public function requestDiscount(Request $request, Reservation $reservation)
    {
        $data = $request->validate([
            'requested_approver_id' => 'required|exists:users,id',
            'reason' => 'required|string|max:500',
        ]);

        $this->discountApproval->requestApproval(
            $reservation,
            $reservation->roomCategory,
            $reservation->room_id,
            $reservation->check_in_date,
            $reservation->check_out_date,
            (float) $reservation->rate_quoted,
            $request->user(),
            User::findOrFail($data['requested_approver_id']),
            $data['reason'],
        );

        return back()->with('status', 'Discount approval requested.');
    }
}
