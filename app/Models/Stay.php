<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class Stay extends Model
{
    use HasSyncUlid;
    use HasFactory;

    protected $fillable = [
        'reservation_id', 'guest_id', 'company_id', 'room_id', 'group_reference', 'check_in_at',
        'expected_check_out_date', 'actual_check_out_at', 'agreed_rate',
        'status', 'checked_in_by', 'checked_out_by', 'notes',
        'is_complimentary', 'complimentary_reason', 'marked_complimentary_by', 'marked_complimentary_at',
        'nights_charged',
    ];

    protected function casts(): array
    {
        return [
            'check_in_at' => 'datetime',
            'expected_check_out_date' => 'date',
            'actual_check_out_at' => 'datetime',
            'agreed_rate' => 'decimal:2',
            'is_complimentary' => 'boolean',
            'marked_complimentary_at' => 'datetime',
            'nights_charged' => 'integer',
        ];
    }

    /**
     * Calendar-night count between check-in and an arbitrary date/time,
     * floored to whole nights with a one-night minimum — the same model
     * Reservation::nights() already uses, just parametrized so both the
     * *expected* length of stay and the *actual* one (at checkout) can
     * share one implementation.
     */
    public function nightsUntil(\DateTimeInterface|string $checkOut): int
    {
        $checkIn = $this->check_in_at->copy()->startOfDay();
        $checkOutDay = Carbon::parse($checkOut)->startOfDay();

        return max(1, $checkIn->diffInDays($checkOutDay));
    }

    /**
     * Nights anticipated at check-in (or currently, if still active) —
     * what CheckInService charges upfront.
     */
    public function expectedNights(): int
    {
        return $this->nightsUntil($this->expected_check_out_date);
    }

    /**
     * Nights actually stayed, using the real checkout time once closed,
     * or "now" while still active (e.g. for an early, in-progress view of
     * what checkout would currently charge).
     */
    public function actualNights(): int
    {
        return $this->nightsUntil($this->actual_check_out_at ?? now());
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function checkedInBy()
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    public function checkedOutBy()
    {
        return $this->belongsTo(User::class, 'checked_out_by');
    }

    public function markedComplimentaryBy()
    {
        return $this->belongsTo(User::class, 'marked_complimentary_by');
    }

    public function folio()
    {
        return $this->hasOne(Folio::class);
    }

    /**
     * Feature 6 (approved discounts): approvals recorded directly against
     * this stay (e.g. a walk-in check-in, which has no Reservation row) —
     * see DiscountApprovalService and Reservation::discountApprovals().
     */
    public function discountApprovals()
    {
        return $this->morphMany(DiscountApproval::class, 'approvable');
    }

    /**
     * Feature 3 (complimentary breakfast): every breakfast entitlement/
     * redemption ever recorded against this stay — see
     * BreakfastEntitlementService and the breakfast_entitlements table.
     */
    public function breakfastEntitlements()
    {
        return $this->hasMany(BreakfastEntitlement::class);
    }

    /**
     * True when this stay was created as part of a multi-room walk-in
     * check-in (CheckInService::checkInWalkInGroup()) rather than on its
     * own. Mirrors Reservation::isGrouped() — see this table's
     * group_reference migration for the full reasoning.
     */
    public function isGrouped(): bool
    {
        return $this->group_reference !== null;
    }

    /**
     * Every other room checked in as part of the same multi-room walk-in
     * submission as this one — never includes this stay itself. Empty for
     * an ordinary single check-in (group_reference is null), including
     * every check-in made from a Reservation, which never sets this
     * column.
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
