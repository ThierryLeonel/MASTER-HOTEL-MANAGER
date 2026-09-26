<?php

namespace App\Http\Controllers\Hr;

use App\Exceptions\HmsException;
use App\Http\Controllers\Controller;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\AuditLogger;
use App\Services\Hr\LeaveService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class LeaveController extends Controller
{
    public function __construct(
        private readonly LeaveService $service,
        private readonly AuditLogger $audit,
    ) {
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
        // All types (including inactive) for the manage-employees admin
        // list below, so a deactivated type stays visible/reactivatable
        // rather than disappearing once it's no longer offered above.
        $allLeaveTypes = $request->user()->can('manage-employees')
            ? LeaveType::orderBy('name')->get()
            : collect();

        return view('hr.leave.index', compact('requests', 'leaveTypes', 'allLeaveTypes', 'canApprove'));
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

    /**
     * Broader request ("...edit, delete and update all categories and
     * every input, except financial inputs"): LeaveType had no edit or
     * delete at all until now — see the inventory this round did before
     * building. Never touches an already-decided LeaveRequest's own
     * days/dates/status.
     */
    public function updateType(Request $request, LeaveType $leaveType)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => ['required', 'string', 'max:50', Rule::unique('leave_types', 'code')->ignore($leaveType->id)],
            'is_paid' => 'nullable|boolean',
            'max_days_per_year' => 'required|integer|min:0',
            'requires_approval' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ]);
        $data['is_paid'] = $request->boolean('is_paid');
        $data['requires_approval'] = $request->boolean('requires_approval', true);
        $data['is_active'] = $request->boolean('is_active', $leaveType->is_active);

        $oldValues = $leaveType->only(array_keys($data));
        $leaveType->update($data);

        $this->audit->log('hr.leave_type_updated', 'hr', $leaveType, oldValues: $oldValues, newValues: $leaveType->only(array_keys($data)), actor: $request->user());

        return back()->with('status', "Leave type \"{$leaveType->name}\" updated.");
    }

    /**
     * leave_requests.leave_type_id is restrictOnDelete (real approval
     * history) and leave_balances.leave_type_id is cascadeOnDelete (would
     * silently wipe accrual tracking) — both are checked here so a type
     * with any real usage is deactivated instead (it already disappears
     * from the "Request leave"/"Add leave type" pickers via is_active,
     * see index()'s where clause).
     */
    public function destroyType(Request $request, LeaveType $leaveType)
    {
        if (LeaveRequest::where('leave_type_id', $leaveType->id)->exists()) {
            throw new HmsException("Leave type \"{$leaveType->name}\" has request history and can't be deleted without erasing that record. Deactivate it instead.");
        }

        if (LeaveBalance::where('leave_type_id', $leaveType->id)->exists()) {
            throw new HmsException("Leave type \"{$leaveType->name}\" has accrued balances and can't be deleted without erasing that record. Deactivate it instead.");
        }

        $name = $leaveType->name;
        $this->audit->log('hr.leave_type_deleted', 'hr', $leaveType, oldValues: $leaveType->toArray(), actor: $request->user());
        $leaveType->delete();

        return back()->with('status', "Leave type \"{$name}\" deleted.");
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
