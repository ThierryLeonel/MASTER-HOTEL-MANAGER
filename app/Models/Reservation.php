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
        'reservation_number', 'guest_id', 'room_category_id', 'room_id',
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
}
