<?php

namespace App\Http\Controllers\FrontOffice;

use App\Http\Controllers\Controller;
use App\Models\HousekeepingTask;
use App\Models\Room;
use App\Services\FrontOffice\HousekeepingService;
use Illuminate\Http\Request;

class HousekeepingController extends Controller
{
    public function __construct(private readonly HousekeepingService $service)
    {
    }

    public function index()
    {
        $tasks = HousekeepingTask::with(['room', 'assignedTo'])
            ->whereIn('status', ['pending', 'in_progress'])
            ->orderBy('created_at')
            ->get();

        $rooms = Room::orderBy('room_number')->get();

        return view('front-office.housekeeping.index', compact('tasks', 'rooms'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'room_id' => 'required|exists:rooms,id',
            'type' => 'required|in:cleaning,turnover,inspection',
            'assigned_to' => 'nullable|exists:users,id',
            'requires_approval' => 'nullable|boolean',
        ]);

        $room = Room::findOrFail($data['room_id']);
        $this->service->createTask(
            $room,
            $data['type'],
            $request->user(),
            $data['assigned_to'] ?? null,
            (bool) ($data['requires_approval'] ?? false),
        );

        return back()->with('status', 'Housekeeping task created.');
    }

    /**
     * New requirement 5: approve/reject a task created with
     * requires_approval — gated on 'approve-housekeeping', separate from
     * the 'manage-housekeeping' permission this controller's other actions
     * share, so it can be granted independently (e.g. to Marketing
     * Manager) exactly like Maintenance's report/approve split.
     */
    public function approve(Request $request, HousekeepingTask $task)
    {
        $this->service->approve($task, $request->user());

        return back()->with('status', 'Housekeeping task approved.');
    }

    public function reject(Request $request, HousekeepingTask $task)
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);

        $this->service->reject($task, $request->user(), $data['reason']);

        return back()->with('status', 'Housekeeping task rejected.');
    }

    public function start(Request $request, HousekeepingTask $task)
    {
        $this->service->start($task, $request->user());

        return back()->with('status', 'Task started.');
    }

    public function complete(Request $request, HousekeepingTask $task)
    {
        $this->service->complete($task, $request->user());

        return back()->with('status', 'Task completed.');
    }
}
