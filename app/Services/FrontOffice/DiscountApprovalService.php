<?php

namespace App\Services\FrontOffice;

use App\Exceptions\HmsException;
use App\Models\DiscountApproval;
use App\Models\RoomCategory;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Feature 6 ("Approved discounts on check-in") / audited defect #6
 * ("prevent zero-rate/unauthorized-discount bypass"): before this, a
 * reservation's rate_quoted / a stay's agreed_rate was validated only as
 * `min:0` — any staff member with ordinary reservation/check-in access
 * could quote or agree ANY rate, including a full 100% discount, with no
 * approval trail at all. This service is the one and only way an
 * agreed/quoted rate below a room category's standard rate is ever
 * authorized, for both a reservation-based check-in and a walk-in.
 *
 * Server-bound identity (spec requirement: "selecting another employee's
 * name is NOT proof of approval"): selfApprove() always records the
 * ACTUAL authenticated $approver the caller passes — callers must never
 * construct that User from a client-supplied id, only from
 * $request->user(). A staff member who is not personally eligible
 * (doesn't hold 'approve-discount') cannot self-approve at all; they must
 * either use an already-approved DiscountApproval matching this exact
 * booking (created earlier by an eligible approver), or submit a request
 * to a specific eligible approver via requestApproval() — that approver
 * must then separately authenticate their own session and call
 * approveRequest() themselves. No endpoint anywhere accepts a raw
 * approver id as proof of who approved.
 *
 * Walk-in exception (explicit product decision, not a relaxation of the
 * rule above): a reservation gets its discount approved, or sent for
 * approval, days before check-in ever happens, so blocking check-in
 * until that's resolved costs nothing. A walk-in has no such lead time —
 * the discount decision and the guest standing at the desk happen at the
 * same moment, and the manager who'd approve it is very often not the
 * person working the desk. For that one case only,
 * authorizeRateForCheckIn() accepts a $requestedApproverId and, when
 * given, lets check-in proceed with a PENDING DiscountApproval attached
 * rather than refusing outright — see that method's own docblock. The
 * named approver still has to separately authenticate and call
 * approveRequest()/rejectRequest() themselves; nothing here treats "the
 * receptionist picked a name" as proof of approval. This path is wired
 * up only from CheckInService::checkInWalkIn() — the reservation-based
 * check-in path never passes a $requestedApproverId and keeps blocking
 * exactly as before.
 */
class DiscountApprovalService
{
    public const ELIGIBLE_ROLES = ['owner', 'general_manager', 'duty_manager', 'marketing_manager'];

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public static function isEligibleApprover(User $user): bool
    {
        return $user->can('approve-discount');
    }

    /**
     * The currently-authenticated $approver personally authorizes this
     * rate, right now. Used both as a genuine "I am the manager, I
     * approve this" action, and internally by CheckInService when the
     * person performing check-in is themselves eligible.
     */
    public function selfApprove(
        Model $approvable,
        RoomCategory $category,
        ?int $roomId,
        \DateTimeInterface $checkIn,
        \DateTimeInterface $checkOut,
        float $agreedRate,
        User $approver,
        string $reason,
    ): DiscountApproval {
        if (! self::isEligibleApprover($approver)) {
            throw new HmsException('You are not authorized to approve a discounted or complimentary rate.');
        }

        return DB::transaction(function () use ($approvable, $category, $roomId, $checkIn, $checkOut, $agreedRate, $approver, $reason) {
            $this->supersedeActive($approvable, 'Replaced by a new self-approval');

            $approval = DiscountApproval::create([
                'approvable_type' => $approvable->getMorphClass(),
                'approvable_id' => $approvable->getKey(),
                'room_category_id' => $category->id,
                'room_id' => $roomId,
                'check_in_date' => $checkIn,
                'check_out_date' => $checkOut,
                'standard_rate' => $category->base_rate,
                'agreed_rate' => $agreedRate,
                'discount_amount' => bcsub((string) $category->base_rate, (string) $agreedRate, 2),
                'is_complimentary' => bccomp((string) $agreedRate, '0.00', 2) === 0,
                'reason' => $reason,
                'status' => 'approved',
                'requested_by' => $approver->id,
                'approver_id' => $approver->id,
                'approver_role_snapshot' => $approver->getRoleNames()->first(),
                'approved_at' => now(),
            ]);

            $this->audit->log('discount.self_approved', 'front_office', $approval, null, $approval->toArray(), actor: $approver);

            return $approval;
        });
    }

    /**
     * A non-eligible staff member addresses this request to one specific
     * eligible approver — who must separately authenticate and call
     * approveRequest() themselves. Never usable to check in immediately;
     * this only creates the pending record.
     */
    public function requestApproval(
        Model $approvable,
        RoomCategory $category,
        ?int $roomId,
        \DateTimeInterface $checkIn,
        \DateTimeInterface $checkOut,
        float $agreedRate,
        User $requestedBy,
        User $requestedApprover,
        string $reason,
    ): DiscountApproval {
        if (! self::isEligibleApprover($requestedApprover)) {
            throw new HmsException("{$requestedApprover->name} is not an eligible discount approver.");
        }

        return DB::transaction(function () use ($approvable, $category, $roomId, $checkIn, $checkOut, $agreedRate, $requestedBy, $requestedApprover, $reason) {
            $this->supersedeActive($approvable, 'Replaced by a new approval request');

            $approval = DiscountApproval::create([
                'approvable_type' => $approvable->getMorphClass(),
                'approvable_id' => $approvable->getKey(),
                'room_category_id' => $category->id,
                'room_id' => $roomId,
                'check_in_date' => $checkIn,
                'check_out_date' => $checkOut,
                'standard_rate' => $category->base_rate,
                'agreed_rate' => $agreedRate,
                'discount_amount' => bcsub((string) $category->base_rate, (string) $agreedRate, 2),
                'is_complimentary' => bccomp((string) $agreedRate, '0.00', 2) === 0,
                'reason' => $reason,
                'status' => 'pending',
                'requested_by' => $requestedBy->id,
                'requested_approver_id' => $requestedApprover->id,
            ]);

            $this->audit->log('discount.requested', 'front_office', $approval, null, $approval->toArray(), actor: $requestedBy);

            return $approval;
        });
    }

    /**
     * Only the SPECIFIC user this request was addressed to may approve
     * it — checked against the authenticated $approver, never a
     * client-supplied id, and re-checked inside a row lock so two
     * concurrent approve/reject calls can't both succeed.
     */
    public function approveRequest(DiscountApproval $approval, User $approver): DiscountApproval
    {
        return DB::transaction(function () use ($approval, $approver) {
            /** @var DiscountApproval $approval */
            $approval = DiscountApproval::query()->whereKey($approval->getKey())->lockForUpdate()->firstOrFail();

            if ($approval->status !== 'pending') {
                throw new HmsException("Only a pending discount request can be approved (current: {$approval->status}).");
            }

            if ((int) $approval->requested_approver_id !== (int) $approver->id) {
                throw new HmsException('This discount request was addressed to a different approver.');
            }

            $old = $approval->only(['status']);
            $approval->update([
                'status' => 'approved',
                'approver_id' => $approver->id,
                'approver_role_snapshot' => $approver->getRoleNames()->first(),
                'approved_at' => now(),
            ]);

            $this->audit->log('discount.approved', 'front_office', $approval, $old, $approval->only(['status']), actor: $approver);

            return $approval;
        });
    }

    public function rejectRequest(DiscountApproval $approval, User $approver, string $reason): DiscountApproval
    {
        return DB::transaction(function () use ($approval, $approver, $reason) {
            /** @var DiscountApproval $approval */
            $approval = DiscountApproval::query()->whereKey($approval->getKey())->lockForUpdate()->firstOrFail();

            if ($approval->status !== 'pending') {
                throw new HmsException("Only a pending discount request can be rejected (current: {$approval->status}).");
            }

            if ((int) $approval->requested_approver_id !== (int) $approver->id) {
                throw new HmsException('This discount request was addressed to a different approver.');
            }

            $old = $approval->only(['status']);
            $approval->update([
                'status' => 'rejected',
                'approver_id' => $approver->id,
                'rejection_reason' => $reason,
            ]);

            $this->audit->log('discount.rejected', 'front_office', $approval, $old, $approval->only(['status']), $reason, $approver);

            return $approval;
        });
    }

    /**
     * The single gate CheckInService calls for both a reservation-based
     * and a walk-in check-in. Returns silently (no approval needed) when
     * $agreedRate is at or above the category's standard rate — the
     * overwhelmingly common case. Otherwise: reuses a still-active,
     * exactly-matching approval already on $approvable if one exists;
     * else, when $actor is personally eligible, self-approves inline
     * (the front desk manager doing their own check-in).
     *
     * Otherwise, behaviour depends on $requestedApproverId (see the class
     * docblock's "Walk-in exception"):
     *   - null (the reservation-based check-in path, and any walk-in
     *     where the receptionist didn't name an approver): throws,
     *     exactly as before — check-in does not proceed.
     *   - a user id: routes the decision to that specific eligible
     *     approver via requestApproval() (which independently verifies
     *     they actually hold 'approve-discount' — a bad id here fails
     *     loudly, it never silently proceeds) and returns the resulting
     *     PENDING DiscountApproval instead of throwing, so the caller's
     *     DB transaction commits — a walk-in guest is checked in with an
     *     outstanding discount approval, which appears in that named
     *     approver's own queue (DiscountApprovalController::index()) for
     *     them to approve or reject from their own authenticated session.
     */
    public function authorizeRateForCheckIn(
        Model $approvable,
        RoomCategory $category,
        ?int $roomId,
        \DateTimeInterface $checkIn,
        \DateTimeInterface $checkOut,
        float $agreedRate,
        User $actor,
        ?string $reason = null,
        ?int $requestedApproverId = null,
    ): ?DiscountApproval {
        if (bccomp((string) $agreedRate, (string) $category->base_rate, 2) >= 0) {
            return null;
        }

        $existing = $approvable->discountApprovals()
            ->where('status', 'approved')
            ->whereNull('superseded_at')
            ->latest('approved_at')
            ->get()
            ->first(fn (DiscountApproval $a) => $a->matches((int) $category->id, $roomId, $checkIn, $checkOut, $agreedRate));

        if ($existing) {
            return $existing;
        }

        if (self::isEligibleApprover($actor)) {
            return $this->selfApprove(
                $approvable,
                $category,
                $roomId,
                $checkIn,
                $checkOut,
                $agreedRate,
                $actor,
                $reason ?? 'Approved directly at check-in.',
            );
        }

        if ($requestedApproverId !== null) {
            return $this->requestApproval(
                $approvable,
                $category,
                $roomId,
                $checkIn,
                $checkOut,
                $agreedRate,
                $actor,
                User::findOrFail($requestedApproverId),
                $reason ?? 'Approved verbally at the desk at check-in; routed for confirmation.',
            );
        }

        throw new HmsException(
            "The agreed rate ({$agreedRate}) is below the standard rate ({$category->base_rate}) for this room category. ".
            'An eligible approver (Owner, General Manager, Duty Manager, or Marketing Manager) must approve this discount before check-in can proceed.'
        );
    }

    private function supersedeActive(Model $approvable, string $reason): void
    {
        $approvable->discountApprovals()
            ->where('status', 'approved')
            ->whereNull('superseded_at')
            ->update(['superseded_at' => now()]);
    }
}
