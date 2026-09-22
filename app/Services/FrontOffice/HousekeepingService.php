<?php

namespace App\Services\FrontOffice;

use App\Exceptions\HmsException;
use App\Models\HousekeepingTask;
use App\Models\Room;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class HousekeepingService
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * New requirement 5 ("define/complete housekeeping approval workflow
     * if missing"): $requiresApproval is opt-in and defaults to false, so
     * every existing caller (staff creating an ordinary cleaning/turnover
     * task) is completely unaffected — the task starts life
     * `approval_status = 'not_required'`, exactly as if this feature did
     * not exist. Only a caller that explicitly asks for approval (e.g. a
     * Marketing Manager flagging a task that needs sign-off before staff
     * begin it) gets the gated `pending` state that start() below refuses
     * to begin until approve() has run.
     */
    public function createTask(Room $room, string $type, User $createdBy, ?int $assignedTo = null, bool $requiresApproval = false): HousekeepingTask
    {
        $task = HousekeepingTask::create([
            'room_id' => $room->id,
            'type' => $type,
            'status' => 'pending',
            'assigned_to' => $assignedTo,
            'created_by' => $createdBy->id,
            'approval_status' => $requiresApproval ? 'pending' : 'not_required',
        ]);

        $this->audit->log('housekeeping.task_created', 'front_office', $task, null, $task->toArray(), actor: $createdBy);

        return $task;
    }

    /**
     * New requirement 5: the one-time, audited decision that unblocks a
     * task created with requiresApproval: true. Locked+rechecked inside a
     * transaction like every other approval gate in this codebase, so two
     * concurrent approve() calls on the same task can't both succeed.
     */
    public function approve(HousekeepingTask $task, User $approver): HousekeepingTask
    {
        return DB::transaction(function () use ($task, $approver) {
            /** @var HousekeepingTask $task */
            $task = HousekeepingTask::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();

            if ($task->approval_status !== 'pending') {
                throw new HmsException("Only a task awaiting approval can be approved (current: {$task->approval_status}).");
            }

            $old = $task->only(['approval_status']);
            $task->update([
                'approval_status' => 'approved',
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ]);

            $this->audit->log('housekeeping.approved', 'front_office', $task, $old, $task->only(['approval_status']), actor: $approver);

            return $task;
        });
    }

    public function reject(HousekeepingTask $task, User $approver, string $reason): HousekeepingTask
    {
        return DB::transaction(function () use ($task, $approver, $reason) {
            /** @var HousekeepingTask $task */
            $task = HousekeepingTask::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();

            if ($task->approval_status !== 'pending') {
                throw new HmsException("Only a task awaiting approval can be rejected (current: {$task->approval_status}).");
            }

            $old = $task->only(['approval_status']);
            $task->update([
                'approval_status' => 'rejected',
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'rejection_reason' => $reason,
            ]);

            $this->audit->log('housekeeping.rejected', 'front_office', $task, $old, $task->only(['approval_status']), $reason, $approver);

            return $task;
        });
    }

    public function start(HousekeepingTask $task, User $actor): HousekeepingTask
    {
        if ($task->status !== 'pending') {
            throw new HmsException("Task must be pending to start (current: {$task->status}).");
        }

        // New requirement 5: a task explicitly flagged as needing approval
        // cannot begin until that approval has actually happened —
        // 'not_required' (the default for every ordinary task) and
        // 'approved' both pass straight through unaffected.
        if ($task->approval_status === 'pending') {
            throw new HmsException('This task requires approval before work can begin.');
        }

        if ($task->approval_status === 'rejected') {
            throw new HmsException('This task was rejected and cannot be started.');
        }

        $task->update(['status' => 'in_progress', 'started_at' => now(), 'assigned_to' => $task->assigned_to ?? $actor->id]);

        return $task;
    }

    public function complete(HousekeepingTask $task, User $actor): HousekeepingTask
    {
        if ($task->status !== 'in_progress') {
            throw new HmsException("Task must be in progress to complete (current: {$task->status}).");
        }

        // Removed at the user's request ("remove the clean and the dirty
        // status"): completing a task used to also flip the room's
        // separate `housekeeping_status` to 'clean'/'inspected' — that
        // cleanliness label no longer exists on rooms at all. The task
        // itself is still tracked here exactly as before.
        $task->update(['status' => 'done', 'completed_at' => now()]);

        $this->audit->log('housekeeping.task_completed', 'front_office', $task, null, $task->toArray(), actor: $actor);

        return $task;
    }
}
