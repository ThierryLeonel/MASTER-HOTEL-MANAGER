<?php

namespace App\Services\Hr;

use App\Exceptions\HmsException;
use App\Models\AttendanceRecord;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Carbon;

/**
 * The single writer of `attendance_records`. Self clock-in/clock-out,
 * a manager's manual correction, and LeaveService's automatic
 * on_leave marking (on approval) all go through here, so there is one
 * place that enforces "exactly one row per user per calendar day."
 */
class AttendanceService
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function clockIn(User $user): AttendanceRecord
    {
        $today = Carbon::today()->toDateString();
        $record = AttendanceRecord::where('user_id', $user->id)->where('work_date', $today)->first();

        if ($record && $record->clock_in_at !== null) {
            throw new HmsException('Already clocked in today.');
        }

        $record = $record ?? new AttendanceRecord(['user_id' => $user->id, 'work_date' => $today]);
        $record->fill(['clock_in_at' => now(), 'status' => 'present', 'recorded_by' => $user->id])->save();

        $this->audit->log('hr.attendance_clocked_in', 'hr', $record, null, $record->fresh()->toArray(), actor: $user);

        return $record->fresh();
    }

    public function clockOut(User $user): AttendanceRecord
    {
        $today = Carbon::today()->toDateString();
        $record = AttendanceRecord::where('user_id', $user->id)->where('work_date', $today)->first();

        if (! $record || $record->clock_in_at === null) {
            throw new HmsException('You have not clocked in today.');
        }
        if ($record->clock_out_at !== null) {
            throw new HmsException('Already clocked out today.');
        }

        $record->update(['clock_out_at' => now()]);

        $this->audit->log('hr.attendance_clocked_out', 'hr', $record, null, $record->fresh()->toArray(), actor: $user);

        return $record->fresh();
    }

    /**
     * A manager's manual entry/correction — a forgotten clock-in, an
     * absence, a public holiday. Upserts the one row for that day.
     */
    public function recordManual(User $targetUser, Carbon $date, string $status, User $actor, ?string $notes = null): AttendanceRecord
    {
        if (! in_array($status, ['present', 'absent', 'late', 'on_leave', 'holiday'], true)) {
            throw new HmsException("Invalid attendance status: {$status}");
        }

        $record = AttendanceRecord::updateOrCreate(
            ['user_id' => $targetUser->id, 'work_date' => $date->toDateString()],
            ['status' => $status, 'recorded_by' => $actor->id, 'notes' => $notes],
        );

        $this->audit->log('hr.attendance_recorded', 'hr', $record, null, $record->fresh()->toArray(), $notes, $actor);

        return $record->fresh();
    }

    /**
     * Used by PayrollService to compute the unpaid-absence deduction
     * for a payroll period.
     */
    public function countAbsentDays(User $user, Carbon $start, Carbon $end): float
    {
        return (float) AttendanceRecord::where('user_id', $user->id)
            ->where('status', 'absent')
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->count();
    }
}
