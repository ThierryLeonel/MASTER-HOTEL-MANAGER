<?php

namespace App\Services\Reporting;

use App\Models\AttendanceRecord;
use App\Models\LeaveBalance;
use App\Models\Payslip;
use Illuminate\Support\Carbon;

/**
 * Payroll cost and attendance figures read directly from Phase 5's
 * ledgers (`payslips`, `attendance_records`, `leave_balances`) —
 * consistent with every other reporting service in this phase, never a
 * separately-maintained total.
 */
class HrReportService
{
    /**
     * Only *paid* payslips count as a real cost — a draft or finalized
     * (approved-but-unpaid) payslip is a plan, not money that has
     * actually moved yet.
     *
     * @return array{gross: float, statutory: float, net: float, count: int}
     */
    public function payrollCost(Carbon $start, Carbon $end): array
    {
        $paid = Payslip::where('status', 'paid')
            ->whereBetween('paid_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->get();

        return [
            'gross' => round((float) $paid->sum('gross_pay'), 2),
            'statutory' => round((float) $paid->sum('total_statutory_deductions'), 2),
            'net' => round((float) $paid->sum('net_pay'), 2),
            'count' => $paid->count(),
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{user: \App\Models\User, present: int, absent: int, late: int, on_leave: int}>
     */
    public function attendanceSummary(Carbon $start, Carbon $end): \Illuminate\Support\Collection
    {
        $records = AttendanceRecord::with('user')
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->groupBy('user_id');

        return $records->map(function ($rows) {
            return [
                'user' => $rows->first()->user,
                'present' => $rows->where('status', 'present')->count(),
                'absent' => $rows->where('status', 'absent')->count(),
                'late' => $rows->where('status', 'late')->count(),
                'on_leave' => $rows->where('status', 'on_leave')->count(),
            ];
        })->values();
    }

    /**
     * @return \Illuminate\Support\Collection<int, LeaveBalance>
     */
    public function leaveBalances(int $year)
    {
        return LeaveBalance::with(['user', 'leaveType'])->where('year', $year)->get();
    }
}
