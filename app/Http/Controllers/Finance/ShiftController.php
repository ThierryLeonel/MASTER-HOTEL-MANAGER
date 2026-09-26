<?php

namespace App\Http\Controllers\Finance;

use App\Exceptions\HmsException;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Shift;
use App\Services\Finance\ShiftService;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
    public function __construct(private readonly ShiftService $service)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();

        // A department-scoped role only sees/opens shifts for its own
        // department (mirroring how the POS module scopes bar_staff to
        // the bar outlet); a broader view-shift-reports holder sees every
        // department's shifts. Front desk staff hold both view-rooms-style
        // scoped access and a department via users.department_id already.
        $departments = Department::where('is_active', true)->orderBy('name')->get();
        $ownDepartment = $user->department;

        $query = Shift::with(['department', 'openedBy', 'closedBy']);

        if (! $user->can('view-shift-reports') && $ownDepartment) {
            $query->where('department_id', $ownDepartment->id);
        }

        $shifts = $query->orderByDesc('id')->paginate(25);
        $openShifts = (clone $query)->where('status', 'open')->get();

        return view('finance.shifts.index', compact('shifts', 'departments', 'ownDepartment', 'openShifts'));
    }

    /**
     * Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §21): "let the
     * shift not be a dropdown of the departments but the department
     * should be automatic based on the login." A shift is now always
     * opened for the logged-in user's own `users.department_id` — never
     * picked from a list — so staff can't accidentally (or deliberately)
     * open a shift for a department they don't belong to.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'opening_cash' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        $department = $request->user()->department;

        if (! $department) {
            throw new HmsException('Your account has no department assigned, so a shift cannot be opened automatically — ask an administrator to set your department first.');
        }

        $this->service->open($department, $request->user(), (float) $data['opening_cash'], $data['notes'] ?? null);

        return back()->with('status', "Shift opened for {$department->name}.");
    }

    /**
     * "Let the expected balance per the shift be visibly changing as the
     * day and transactions are moving": a small polled JSON endpoint (see
     * finance/shifts/index.blade.php's script) rather than a Livewire
     * component, matching this app's existing plain-JS/CDN-only front end.
     * Scoped the same way index() scopes the shift list itself — a
     * department-scoped user can only poll their own department's shift.
     */
    public function expected(Request $request, Shift $shift)
    {
        $user = $request->user();

        if (! $user->can('view-shift-reports') && (! $user->department || $shift->department_id !== $user->department->id)) {
            abort(403);
        }

        return response()->json(['expected_cash' => $this->service->expectedCashFor($shift)]);
    }

    public function close(Request $request, Shift $shift)
    {
        $data = $request->validate([
            'declared_cash' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        $this->service->close($shift, (float) $data['declared_cash'], $request->user(), $data['notes'] ?? null);

        return back()->with('status', 'Shift closed and reconciled.');
    }

    public function print(Shift $shift)
    {
        $shift->load(['department', 'openedBy', 'closedBy']);
        // Fourth post-9.8 fix round (day-close rebuild): the handover
        // document now shows every account this shift actually moved
        // money through, not just the cash figure it declares — see
        // ShiftService::expectedByAccountFor()'s docblock.
        $accountBreakdown = $this->service->expectedByAccountFor($shift);

        return view('finance.shifts.print', compact('shift', 'accountBreakdown'));
    }
}
