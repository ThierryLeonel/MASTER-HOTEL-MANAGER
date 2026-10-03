<?php

namespace App\Http\Controllers\FrontOffice;

use App\Http\Controllers\Controller;
use App\Models\EventSpace;
use App\Services\FrontOffice\EventSpaceService;
use Illuminate\Http\Request;

/**
 * Admin CRUD for the managed event-space list — see EventSpaceService's
 * docblock. Every action here is gated on 'manage-event-spaces' (see
 * routes/web.php), deliberately a narrower permission than
 * 'manage-event-reservations': configuring what venues EXIST is an
 * admin-level decision, while booking an event against one of them is a
 * front-line front-desk action — the same split IncomeSource/ChargeType
 * already draw from the reservation/payment flows that use them.
 */
class EventSpaceController extends Controller
{
    public function __construct(private readonly EventSpaceService $service)
    {
    }

    public function index()
    {
        $eventSpaces = EventSpace::withCount('eventReservations')->orderByDesc('is_active')->orderBy('name')->get();

        return view('front-office.event-spaces.index', compact('eventSpaces'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100|unique:event_spaces,name',
            'description' => 'nullable|string|max:500',
            'max_capacity' => 'nullable|integer|min:1',
        ]);

        $this->service->create($data, $request->user());

        return back()->with('status', "\"{$data['name']}\" added to the event spaces list.");
    }

    public function update(Request $request, EventSpace $eventSpace)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100|unique:event_spaces,name,'.$eventSpace->id,
            'description' => 'nullable|string|max:500',
            'max_capacity' => 'nullable|integer|min:1',
            'is_active' => 'nullable|boolean',
        ]);

        $this->service->update($eventSpace, $data, $request->user());

        return back()->with('status', "\"{$eventSpace->name}\" updated.");
    }

    public function destroy(Request $request, EventSpace $eventSpace)
    {
        $name = $eventSpace->name;
        $this->service->delete($eventSpace, $request->user());

        return back()->with('status', "\"{$name}\" deleted.");
    }
}
