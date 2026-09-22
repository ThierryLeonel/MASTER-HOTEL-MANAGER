<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\User;
use App\Services\Hr\AttendanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceService $service)
    {
    }

    public function index(Request $request)
    {
        $date = $request->date ? Carbon::parse($request->date) : Carbon::today();

        // Security-audit fix (Phase 7), narrowed further post-9.8 ("view
        // of HR"): this route is now reachable only by whoever holds
        // clock-own-attendance at all (Owner/General Manager/Accountant —
        // see RolesAndPermissionsSeeder), but even among those three the
        // full-roster query below discloses every *other* employee's
        // clock-in/out times and who recorded them. Only a user who
        // actually holds record-attendance (Owner/General Manager) sees
        // the whole day's roster; an Accountant's own "My attendance
        // today" card (built separately below) is all they get from this
        // page.
        $records = $request->user()->can('record-attendance')
            ? AttendanceRecord::with(['user', 'recordedBy'])
                ->where('work_date', $date->toDateString())
                ->orderBy('user_id')
                ->get()
            : collect();

        $myToday = AttendanceRecord::where('user_id', $request->user()->id)
            ->where('work_date', Carbon::today()->toDateString())
            ->first();

        $staff = $request->user()->can('record-attendance') ? User::active()->orderBy('name')->get() : collect();

        return view('hr.attendance.index', ['records' => $records, 'myToday' => $myToday, 'date' => $date, 'staff' => $staff]);
    }

    public function clockIn(Request $request)
    {
        $this->service->clockIn($request->user());

        return back()->with('status', 'Clocked in.');
    }

    public function clockOut(Request $request)
    {
        $this->service->clockOut($request->user());

        return back()->with('status', 'Clocked out.');
    }

    public function storeManual(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'work_date' => 'required|date',
            'status' => 'required|in:present,absent,late,on_leave,holiday',
            'notes' => 'nullable|string|max:500',
        ]);

        $user = User::findOrFail($data['user_id']);
        $this->service->recordManual($user, Carbon::parse($data['work_date']), $data['status'], $request->user(), $data['notes'] ?? null);

        return back()->with('status', 'Attendance recorded.');
    }
}
