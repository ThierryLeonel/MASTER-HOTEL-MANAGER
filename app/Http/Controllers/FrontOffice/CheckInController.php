<?php

namespace App\Http\Controllers\FrontOffice;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Services\FrontOffice\CheckInService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CheckInController extends Controller
{
    public function __construct(private readonly CheckInService $service)
    {
    }

    public function fromReservation(Request $request, Reservation $reservation)
    {
        $data = $request->validate([
            'room_id' => 'nullable|exists:rooms,id',
            'company_id' => 'nullable|exists:companies,id',
        ]);

        $stay = $this->service->checkInFromReservation($reservation, $request->user(), $data['room_id'] ?? null, $data['company_id'] ?? null);

        return redirect()->route('stays.show', $stay)->with('status', 'Guest checked in.');
    }

    /**
     * Post-9.8 fix round: the guest picker is now a live search box
     * (GuestController::search()) rather than a giant <select> of every
     * guest, so this no longer needs to load a guest list at all — only
     * an optional prefill when arriving via the "Check In" button on a
     * just-created guest's page (guests.show's `new=1` callout).
     */
    public function walkInForm(Request $request)
    {
        $availableRooms = Room::where('status', 'available')->with('category')->get();
        $prefillGuest = $request->filled('guest_id') ? Guest::find($request->query('guest_id')) : null;
        $companies = Company::where('is_active', true)->orderBy('name')->get();

        return view('front-office.checkin.walk-in', compact('availableRooms', 'prefillGuest', 'companies'));
    }

    public function walkInStore(Request $request)
    {
        $data = $request->validate([
            'guest_id' => 'nullable|exists:guests,id',
            // Post-9.8 fix round ("even if a guest is not existing the
            // informations should just populate the guest list, rather
            // than going to create a new guest before check in"): when no
            // existing guest was picked from the search box, these fields
            // create the guest record right here, as part of the same
            // check-in submission, instead of forcing a separate trip to
            // the "New guest" page first. Checked manually below rather
            // than via required_without (whose "present but empty" edge
            // case is easy to get wrong for a hidden input that always
            // submits, even when blank).
            'new_first_name' => 'nullable|string|max:255',
            'new_last_name' => 'nullable|string|max:255',
            'new_phone' => 'nullable|string|max:30',
            'new_email' => 'nullable|email|max:255',
            'room_id' => 'required|exists:rooms,id',
            'expected_check_out_date' => 'required|date|after:today',
            'agreed_rate' => 'required|numeric|min:0',
            'company_id' => 'nullable|exists:companies,id',
        ]);

        if (empty($data['guest_id']) && empty($data['new_first_name'])) {
            throw ValidationException::withMessages([
                'new_first_name' => 'Search for an existing guest above, or type a name to check in a new guest.',
            ]);
        }

        $guest = ! empty($data['guest_id'])
            ? Guest::findOrFail($data['guest_id'])
            : Guest::create([
                'first_name' => $data['new_first_name'],
                'last_name' => $data['new_last_name'] ?? '',
                'phone' => $data['new_phone'] ?? null,
                'email' => $data['new_email'] ?? null,
                'created_by' => $request->user()->id,
            ]);

        $stay = $this->service->checkInWalkIn(
            $guest,
            $data['room_id'],
            new \DateTime($data['expected_check_out_date']),
            (float) $data['agreed_rate'],
            $request->user(),
            $data['company_id'] ?? null,
        );

        return redirect()->route('stays.show', $stay)->with('status', 'Walk-in guest checked in.');
    }
}
