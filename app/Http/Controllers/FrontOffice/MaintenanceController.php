<?php

namespace App\Http\Controllers\FrontOffice;

use App\Http\Controllers\Controller;
use App\Models\MaintenanceTicket;
use App\Models\Room;
use App\Services\FrontOffice\MaintenanceService;
use Illuminate\Http\Request;

class MaintenanceController extends Controller
{
    public function __construct(private readonly MaintenanceService $service)
    {
    }

    public function index()
    {
        $tickets = MaintenanceTicket::with(['room', 'reportedBy'])
            ->whereIn('status', ['open', 'approved', 'in_progress'])
            ->orderByDesc('priority')
            ->orderBy('created_at')
            ->get();

        $rooms = Room::orderBy('room_number')->get();

        return view('front-office.maintenance.index', compact('tickets', 'rooms'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'room_id' => 'nullable|exists:rooms,id',
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'priority' => 'required|in:low,medium,high,urgent',
            'takes_room_out_of_service' => 'nullable|boolean',
        ]);

        $this->service->report($data, $request->user());

        return back()->with('status', 'Maintenance ticket reported.');
    }

    public function approve(Request $request, MaintenanceTicket $ticket)
    {
        $this->authorize('approve', $ticket);

        $this->service->approve($ticket, $request->user());

        return back()->with('status', 'Ticket approved.');
    }

    public function resolve(Request $request, MaintenanceTicket $ticket)
    {
        $this->authorize('resolve', $ticket);

        $data = $request->validate(['notes' => 'required|string']);

        $this->service->resolve($ticket, $request->user(), $data['notes']);

        return back()->with('status', 'Ticket resolved.');
    }
}
