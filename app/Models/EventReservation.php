<?php

namespace App\Models;

use App\Services\Sync\Concerns\HasSyncUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Direct request ("WE ALSO NEED RESERVATION FOR EVENT: WHAT KIND OF
 * EVENT, WHICH SPACES DO THEY WANT TO RESERVE, ROOM, WHICH ROOMS
 * (SELECT)..."). See this table's migration for the full design
 * reasoning (why spaces/rooms are two separate pivots, what was
 * deliberately left out of this first version) and
 * EventReservationService for the lifecycle (create/approve/reject/
 * cancel/recordDeposit), which mirrors ReservationService closely on
 * request ("mirroring how room reservations already handle deposits and
 * approval").
 */
class EventReservation extends Model
{
    use HasSyncUlid;
    use HasFactory;

    protected $fillable = [
        'event_reference', 'event_name', 'event_type',
        'guest_id', 'contact_name', 'contact_phone', 'contact_email',
        'expected_guests', 'start_at', 'end_at', 'status',
        'agreed_amount', 'deposit_required', 'deposit_paid',
        'requested_by', 'approved_by', 'approved_at',
        'rejection_reason', 'cancellation_reason', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'expected_guests' => 'integer',
            'agreed_amount' => 'decimal:2',
            'deposit_required' => 'decimal:2',
            'deposit_paid' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function eventSpaces()
    {
        return $this->belongsToMany(EventSpace::class, 'event_reservation_spaces');
    }

    public function rooms()
    {
        return $this->belongsToMany(Room::class, 'event_reservation_rooms');
    }

    /**
     * The booking contact's display name, whether they're an existing
     * guest or someone recorded inline — mirrors how CheckInController's
     * walk-in form treats the two interchangeably.
     */
    public function contactDisplayName(): string
    {
        return $this->guest?->full_name ?? $this->contact_name ?? 'Unknown contact';
    }

    public function isPast(): bool
    {
        return $this->end_at->isPast();
    }
}
