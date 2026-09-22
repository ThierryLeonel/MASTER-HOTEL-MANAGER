<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Feature 6 ("Approved discounts on check-in") / audited defect #6
 * ("prevent zero-rate/unauthorized-discount bypass"). A real, auditable
 * authorization record for any lodging rate below a room category's
 * standard rate — see the create_discount_approvals_table migration's
 * own extensive docblock for the full design, and
 * App\Services\FrontOffice\DiscountApprovalService for the workflow.
 */
class DiscountApproval extends Model
{
    protected $fillable = [
        'approvable_type', 'approvable_id',
        'room_category_id', 'room_id', 'check_in_date', 'check_out_date',
        'standard_rate', 'agreed_rate', 'discount_amount', 'is_complimentary',
        'reason', 'status',
        'requested_by', 'requested_approver_id',
        'approver_id', 'approver_role_snapshot', 'approved_at', 'rejection_reason',
        'superseded_at',
    ];

    protected function casts(): array
    {
        return [
            'check_in_date' => 'date',
            'check_out_date' => 'date',
            'standard_rate' => 'decimal:2',
            'agreed_rate' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'is_complimentary' => 'boolean',
            'approved_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    public function approvable()
    {
        return $this->morphTo();
    }

    public function roomCategory()
    {
        return $this->belongsTo(RoomCategory::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function requestedApprover()
    {
        return $this->belongsTo(User::class, 'requested_approver_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'approved' && $this->superseded_at === null;
    }

    /**
     * Whether this approval's snapshotted terms still match a proposed
     * check-in — "bind approval to reservation/stay + unit/category +
     * dates + rate (material changes require renewed approval)".
     */
    public function matches(int $roomCategoryId, ?int $roomId, \DateTimeInterface $checkIn, \DateTimeInterface $checkOut, float $agreedRate): bool
    {
        return $this->isActive()
            && (int) $this->room_category_id === $roomCategoryId
            && ($roomId === null || $this->room_id === null || (int) $this->room_id === $roomId)
            && $this->check_in_date->isSameDay($checkIn)
            && $this->check_out_date->isSameDay($checkOut)
            && bccomp((string) $this->agreed_rate, (string) $agreedRate, 2) === 0;
    }
}
