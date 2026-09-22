<?php

namespace App\Services\Hr;

use App\Exceptions\HmsException;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Leave lifecycle: pending -> approved (deducts the balance and marks
 * attendance) or rejected/cancelled (no effect on either). The same
 * approval-gate shape as MaintenanceTicket/StockWastage — recording a
 * request never by itself changes anything else in the system.
 */
class LeaveService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AttendanceService $attendance,
    ) {
    }

    /**
     * Finds or creates this employee's balance row for a leave type/
     * year, seeding `entitled_days` from the leave type's current
     * policy the first time it's needed — a later policy change never
     * retroactively rewrites an entitlement already granted for a year
     * in progress.
     */
    public function balanceFor(User $user, LeaveType $type, int $year): LeaveBalance
    {
        return LeaveBalance::firstOrCreate(
            ['user_id' => $user->id, 'leave_type_id' => $type->id, 'year' => $year],
            ['entitled_days' => $type->max_days_per_year],
        );
    }

    /**
     * A read-only view of remaining balance that never persists a row
     * — used by request() so that merely *asking* for leave (which may
     * end up rejected/cancelled) can't create HR records of its own.
     * balanceFor() (which does persist) is reserved for approve(),
     * where the balance is actually about to be consumed.
     */
    private function remainingDays(User $user, LeaveType $type, int $year): float
    {
        $balance = LeaveBalance::where(['user_id' => $user->id, 'leave_type_id' => $type->id, 'year' => $year])->first();

        $entitled = $balance ? (float) $balance->entitled_days : (float) $type->max_days_per_year;
        $used = $balance ? (float) $balance->used_days : 0.0;

        return $entitled - $used;
    }

    public function request(User $user, LeaveType $type, Carbon $start, Carbon $end, string $reason, User $actor): LeaveRequest
    {
        if ($end->lt($start)) {
            throw new HmsException('Leave end date cannot be before the start date.');
        }
        if (! $type->is_active) {
            throw new HmsException('This leave type is no longer active.');
        }

        $days = $start->diffInDays($end) + 1;

        if ($type->isBalanceTracked()) {
            $remaining = $this->remainingDays($user, $type, $start->year);
            if ($days > $remaining) {
                throw new HmsException(
                    "Insufficient {$type->name} balance: {$remaining} day(s) remaining, {$days} requested."
                );
            }
        }

        $leave = LeaveRequest::create([
            'user_id' => $user->id,
            'leave_type_id' => $type->id,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'days_requested' => $days,
            'reason' => $reason,
            'status' => 'pending',
            'requested_by' => $actor->id,
        ]);

        $this->audit->log('hr.leave_requested', 'hr', $leave, null, $leave->toArray(), actor: $actor);

        return $leave;
    }

    public function approve(LeaveRequest $leave, User $actor): LeaveRequest
    {
        if (! $leave->isPending()) {
            throw new HmsException("Only a pending leave request can be approved (current status: {$leave->status}).");
        }
        // Security-audit fix (Phase 7): approve-leave is held by GM/Owner
        // (post-9.8's "view of HR" restriction removed it from Duty
        // Manager along with the rest of HR — see
        // RolesAndPermissionsSeeder), both of whom also hold request-leave
        // — without this guard, either could file their own leave request
        // and then approve it themselves, defeating the point of an
        // approval step. This is a hard business rule, not a permission
        // check, so it
        // applies uniformly regardless of role (the same way the
        // accounting-day lock applies regardless of role).
        if ($leave->user_id === $actor->id) {
            throw new HmsException('You cannot approve your own leave request.');
        }

        return DB::transaction(function () use ($leave, $actor) {
            $type = $leave->leaveType;

            if ($type->isBalanceTracked()) {
                $balance = $this->balanceFor($leave->user, $type, $leave->start_date->year);
                if ((float) $leave->days_requested > $balance->remainingDays()) {
                    throw new HmsException(
                        "Insufficient {$type->name} balance at approval time: {$balance->remainingDays()} day(s) remaining, {$leave->days_requested} requested."
                    );
                }
                $balance->increment('used_days', $leave->days_requested);
            }

            for ($date = $leave->start_date->copy(); $date->lte($leave->end_date); $date->addDay()) {
                $this->attendance->recordManual($leave->user, $date, 'on_leave', $actor, "Leave request #{$leave->id}");
            }

            $leave->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]);

            $this->audit->log('hr.leave_approved', 'hr', $leave, null, $leave->fresh()->toArray(), actor: $actor);

            return $leave->fresh();
        });
    }

    public function reject(LeaveRequest $leave, User $actor, string $reason): LeaveRequest
    {
        if (! $leave->isPending()) {
            throw new HmsException("Only a pending leave request can be rejected (current status: {$leave->status}).");
        }

        $leave->update(['status' => 'rejected', 'rejection_reason' => $reason]);

        $this->audit->log('hr.leave_rejected', 'hr', $leave, null, $leave->toArray(), $reason, $actor);

        return $leave->fresh();
    }

    public function cancel(LeaveRequest $leave, User $actor): LeaveRequest
    {
        if (! $leave->isPending()) {
            throw new HmsException("Only a pending leave request can be cancelled (current status: {$leave->status}).");
        }
        // Security-audit fix (Phase 7): request-leave was granted to
        // every role as a self-service permission (narrowed post-9.8 to
        // Owner/GM/Accountant only — see RolesAndPermissionsSeeder's "HR
        // visibility restriction" note), so a route gate alone let any
        // holder of it cancel *any other* holder's pending leave request
        // just by knowing/guessing its ID (a textbook IDOR). A manager
        // who holds approve-leave may still cancel a request on someone's
        // behalf; anyone else may only cancel their own.
        if ($leave->user_id !== $actor->id && ! $actor->can('approve-leave')) {
            throw new HmsException('You can only cancel your own leave requests.');
        }

        $leave->update(['status' => 'cancelled']);

        $this->audit->log('hr.leave_cancelled', 'hr', $leave, null, $leave->toArray(), actor: $actor);

        return $leave->fresh();
    }
}
