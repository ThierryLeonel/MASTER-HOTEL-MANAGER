<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\Hr\LeaveService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class LeaveController extends Controller
{
    public function __construct(private readonly LeaveService $service)
    {
    }

    public function index(Request $request)
    {
        $canApprove = $request->user()->can('approve-leave');

        $requests = LeaveRequest::with(['user', 'leaveType', 'approvedBy'])
            ->when(! $canApprove, fn ($q) => $q->where('user_id', $request->user()->id))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('id')
            ->paginate(20);

        $leaveTypes = LeaveType::where('is_active', true)->orderBy('name')->get();

        return view('hr.leave.index', compact('requests', 'leaveTypes', 'canApprove'));
    }

    public function storeType(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:leave_types,code',
            'is_paid' => 'nullable|boolean',
            'max_days_per_year' => 'required|integer|min:0',
            'requires_approval' => 'nullable|boolean',
        ]);

        LeaveType::create([
            'name' => $data['name'],
            'code' => $data['code'],
            'is_paid' => $request->boolean('is_paid'),
            'max_days_per_year' => $data['max_days_per_year'],
            'requires_approval' => $request->boolean('requires_approval', true),
        ]);

        return back()->with('status', 'Leave type saved.');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'leave_type_id' => 'required|exists:leave_types,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
            'reason' => 'required|string|max:255',
        ]);

        $type = LeaveType::findOrFail($data['leave_type_id']);
        $this->service->request(
            $request->user(), $type, Carbon::parse($data['start_date']), Carbon::parse($data['end_date']), $data['reason'], $request->user(),
        );

        return back()->with('status', 'Leave request submitted and awaiting approval.');
    }

    public function approve(Request $request, LeaveRequest $leaveRequest)
    {
        $this->service->approve($leaveRequest, $request->user());

        return back()->with('status', 'Leave approved.');
    }

    public function reject(Request $request, LeaveRequest $leaveRequest)
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);

        $this->service->reject($leaveRequest, $request->user(), $data['reason']);

        return back()->with('status', 'Leave rejected.');
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest)
    {
        $this->service->cancel($leaveRequest, $request->user());

        return back()->with('status', 'Leave request cancelled.');
    }
}
