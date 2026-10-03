<?php

namespace App\Http\Controllers\FrontOffice;

use App\Http\Controllers\Controller;
use App\Models\EventReservation;
use App\Models\EventSpace;
use App\Models\FinancialAccount;
use App\Models\Guest;
use App\Models\Room;
use App\Services\FrontOffice\EventReservationService;
use Illuminate\Http\Request;

/**
 * Direct request ("WE ALSO NEED RESERVATION FOR EVENT..."), mirroring
 * ReservationController's own shape on explicit request ("mirroring how
 * room reservations already handle deposits and approval"). Gated on
 * 'manage-event-reservations' for the everyday lifecycle (create/store/
 * show/cancel/deposit) and the narrower 'approve-event-reservation' for
 * approve/reject — see routes/web.php and RolesAndPermissionsSeeder.
 */
class EventReservationController extends Controller
{
    public function __construct(private readonly EventReservationService $service)
    {
    }

    public function index(Request $request)
    {
        $events = EventReservation::with(['guest', 'eventSpaces', 'rooms'])
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('id')
            ->paginate(20);

        return view('front-office.event-reservations.index', compact('events'));
    }

    public function create()
    {
        $eventSpaces = EventSpace::where('is_active', true)->orderBy('name')->get();
        $rooms = Room::with('category')->where('is_active', true)->where('status', '!=', 'out_of_service')->orderBy('room_number')->get();

        return view('front-office.event-reservations.create', compact('eventSpaces', 'rooms'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'event_name' => 'required|string|max:150',
            'event_type' => 'required|string|max:100',
            'guest_id' => 'nullable|exists:guests,id',
            'contact_name' => 'nullable|string|max:150',
            'contact_phone' => 'nullable|string|max:30',
            'contact_email' => 'nullable|email|max:255',
            'expected_guests' => 'nullable|integer|min:1',
            'start_at' => 'required|date|after_or_equal:today',
            'end_at' => 'required|date|after:start_at',
            'agreed_amount' => 'nullable|numeric|min:0',
            'deposit_required' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'event_space_ids' => 'nullable|array',
            'event_space_ids.*' => 'exists:event_spaces,id',
            'room_ids' => 'nullable|array',
            'room_ids.*' => 'exists:rooms,id',
        ]);

        if (empty($data['guest_id']) && empty($data['contact_name'])) {
            return back()->withInput()->withErrors(['contact_name' => 'Select an existing guest or enter a contact name for this event.']);
        }

        $event = $this->service->create(
            $data,
            $data['event_space_ids'] ?? [],
            $data['room_ids'] ?? [],
            $request->user(),
        );

        return redirect()->route('event-reservations.show', $event)->with('status', 'Event reservation created and awaiting approval.');
    }

    public function show(EventReservation $eventReservation)
    {
        $eventReservation->load(['guest', 'requestedBy', 'approvedBy', 'eventSpaces', 'rooms.category']);

        $financialAccounts = FinancialAccount::where('is_active', true)->orderBy('name')->get();

        return view('front-office.event-reservations.show', compact('eventReservation', 'financialAccounts'));
    }

    public function approve(Request $request, EventReservation $eventReservation)
    {
        $this->service->approve($eventReservation, $request->user());

        return back()->with('status', 'Event reservation approved and confirmed.');
    }

    public function reject(Request $request, EventReservation $eventReservation)
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);

        $this->service->reject($eventReservation, $request->user(), $data['reason']);

        return back()->with('status', 'Event reservation rejected.');
    }

    public function cancel(Request $request, EventReservation $eventReservation)
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);

        $this->service->cancel($eventReservation, $request->user(), $data['reason']);

        return back()->with('status', 'Event reservation cancelled.');
    }

    public function recordDeposit(Request $request, EventReservation $eventReservation)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'financial_account_id' => 'required|exists:financial_accounts,id',
            'idempotency_key' => 'nullable|string|max:80',
        ]);

        $account = FinancialAccount::where('is_active', true)->findOrFail($data['financial_account_id']);

        $this->service->recordDeposit(
            $eventReservation,
            (float) $data['amount'],
            $account->paymentMethod(),
            $request->user(),
            financialAccountId: $account->id,
            idempotencyKey: $data['idempotency_key'] ?? null,
        );

        return back()->with('status', 'Deposit recorded.');
    }
}
