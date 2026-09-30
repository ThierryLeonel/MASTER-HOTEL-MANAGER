<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class Reservation extends Model
{
    use HasSyncUlid;
    use HasFactory;

    protected $fillable = [
        'reservation_number',
        // Direct request ("i want to be able to book multi reservations
        // and multi bookings instead of entering 1 by 1") — shared by
        // every reservation created together in one multi-room booking;
        // null for an ordinary single reservation. See this column's
        // migration and ReservationService::createGroup().
        'group_reference',
        'guest_id', 'room_category_id', 'room_id',
        'check_in_date', 'check_out_date', 'adults', 'children', 'status', 'source',
        'rate_quoted', 'deposit_required', 'deposit_paid', 'requested_by',
        'deposit_proof_path', 'deposit_claimed_amount', 'deposit_reference', 'deposit_claimed_method',
        'approved_by', 'approved_at', 'rejection_reason', 'cancellation_reason', 'notes',
        // Correction round (audited defects #3 and #8) — see migration
        // 2025_02_01_000200_add_deposit_verification_and_disposition_to_reservations.
        'deposit_claim_verified_at', 'deposit_claim_verified_by',
        'deposit_disposition', 'deposit_disposition_by', 'deposit_disposition_at',
        'deposit_disposition_notes', 'deposit_disposition_financial_account_id',
        'deposit_disposition_target_reservation_id',
    ];

    protected function casts(): array
    {
        return [
            'check_in_date' => 'date',
            'check_out_date' => 'date',
            'rate_quoted' => 'decimal:2',
            'deposit_required' => 'decimal:2',
            'deposit_paid' => 'decimal:2',
            'deposit_claimed_amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'deposit_claim_verified_at' => 'datetime',
            'deposit_disposition_at' => 'datetime',
        ];
    }

    /**
     * Fourth post-9.8 fix round — true for a reservation submitted
     * through the public booking page rather than entered by staff.
     */
    public function isFromWebsite(): bool
    {
        return $this->source === 'website';
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function roomCategory()
    {
        return $this->belongsTo(RoomCategory::class, 'room_category_id');
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function stay()
    {
        return $this->hasOne(Stay::class);
    }

    public function depositClaimVerifiedBy()
    {
        return $this->belongsTo(User::class, 'deposit_claim_verified_by');
    }

    public function depositDispositionBy()
    {
        return $this->belongsTo(User::class, 'deposit_disposition_by');
    }

    public function depositDispositionFinancialAccount()
    {
        return $this->belongsTo(FinancialAccount::class, 'deposit_disposition_financial_account_id');
    }

    public function depositDispositionTargetReservation()
    {
        return $this->belongsTo(Reservation::class, 'deposit_disposition_target_reservation_id');
    }

    /**
     * Feature 6 (approved discounts): every discount/complimentary
     * approval ever recorded against this reservation — see
     * DiscountApprovalService and the discount_approvals table. Ordered
     * oldest-first; callers that only want the currently-active one use
     * ->whereNull('superseded_at')->latest('approved_at')->first().
     */
    public function discountApprovals()
    {
        return $this->morphMany(DiscountApproval::class, 'approvable');
    }

    public function nights(): int
    {
        return max(1, $this->check_in_date->diffInDays($this->check_out_date));
    }

    /**
     * True when this reservation was created as part of a multi-room
     * booking (ReservationService::createGroup()) rather than on its own.
     */
    public function isGrouped(): bool
    {
        return $this->group_reference !== null;
    }

    /**
     * Every other room booked in the same multi-room submission as this
     * one — never includes this reservation itself. Empty for an
     * ordinary single reservation (group_reference is null).
     */
    public function groupSiblings()
    {
        if ($this->group_reference === null) {
            return static::query()->whereRaw('1 = 0');
        }

        return static::where('group_reference', $this->group_reference)
            ->where('id', '!=', $this->id);
    }
}
