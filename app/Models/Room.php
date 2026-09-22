<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class Room extends Model
{
    use HasSyncUlid;
    use HasFactory;

    protected $fillable = [
        'room_number', 'room_category_id', 'floor', 'status',
        'is_active', 'notes',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function category()
    {
        return $this->belongsTo(RoomCategory::class, 'room_category_id');
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }

    public function stays()
    {
        return $this->hasMany(Stay::class);
    }

    public function currentStay()
    {
        return $this->hasOne(Stay::class)->where('status', 'active');
    }

    public function housekeepingTasks()
    {
        return $this->hasMany(HousekeepingTask::class);
    }

    public function maintenanceTickets()
    {
        return $this->hasMany(MaintenanceTicket::class);
    }

    /**
     * Correction round (audited defect #11): whether this room currently
     * has an open, DUTY-MANAGER/GM-APPROVED maintenance ticket that takes
     * it out of service. Deliberately checks ALL matching tickets, not
     * just "the most recent one" or a single boolean flag on the room —
     * with two tickets open, resolving one must leave the room correctly
     * unavailable because of the other. `in_progress` counts as blocking
     * too (approved work that has started but not finished is still
     * out-of-service); only `resolved`/`closed` tickets stop counting.
     */
    public function hasBlockingMaintenance(): bool
    {
        return $this->maintenanceTickets()
            ->where('takes_room_out_of_service', true)
            ->whereIn('status', ['approved', 'in_progress'])
            ->exists();
    }

    /**
     * Correction round (audited defect #4): the single source of truth for
     * "is a guest physically in this room right now" — an active Stay,
     * never the mutable `status` column, which other code can (and
     * historically did) leave stale/incorrect. A walk-in has no
     * Reservation row at all, so this is the only way their occupancy is
     * ever visible to availability logic.
     */
    public function hasActiveStay(): bool
    {
        return $this->currentStay()->exists();
    }

    /**
     * Correction round (audited defect #4): previously checked ONLY the
     * `reservations` table for date overlap, and only rejected `status`
     * values of `maintenance`/`out_of_service` — a room with `status`
     * still (incorrectly) showing `available`/`occupied` while carrying an
     * approved blocking maintenance ticket fell through, and a room
     * currently occupied by a WALK-IN (no Reservation row exists at all
     * for that occupancy) was invisible to this check entirely, so it
     * could be double-booked or reassigned into. Now checks, in order:
     * blocking maintenance (authoritative regardless of the `status`
     * column), the room's `is_active` flag, any active Stay whose
     * [check_in_at date, expected_check_out_date] window overlaps the
     * requested period (covers walk-ins), and finally the existing
     * reservation-overlap check.
     */
    public function isAvailableFor(\DateTimeInterface $checkIn, \DateTimeInterface $checkOut, ?int $excludeReservationId = null): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->status === 'out_of_service') {
            return false;
        }

        if ($this->hasBlockingMaintenance()) {
            return false;
        }

        $stayOverlap = $this->stays()
            ->where('status', 'active')
            ->whereDate('check_in_at', '<', $checkOut)
            ->where('expected_check_out_date', '>=', $checkIn)
            ->exists();

        if ($stayOverlap) {
            return false;
        }

        $overlap = $this->reservations()
            ->whereIn('status', ['pending', 'approved', 'confirmed', 'checked_in'])
            ->when($excludeReservationId, fn ($q) => $q->where('id', '!=', $excludeReservationId))
            ->where('check_in_date', '<', $checkOut)
            ->where('check_out_date', '>', $checkIn)
            ->exists();

        return ! $overlap;
    }
}
