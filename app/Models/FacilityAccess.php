<?php

namespace App\Models;

use App\Services\Sync\Concerns\HasSyncUlid;
use Illuminate\Database\Eloquent\Model;

class FacilityAccess extends Model
{
    use HasSyncUlid;

    protected $fillable = [
        'access_number', 'facility_id', 'stay_id', 'visitor_name', 'visitor_phone',
        'facility_rate_id', 'amount_charged', 'payment_id', 'granted_by', 'granted_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount_charged' => 'decimal:2',
            'granted_at' => 'datetime',
        ];
    }

    public function facility()
    {
        return $this->belongsTo(Facility::class);
    }

    public function stay()
    {
        return $this->belongsTo(Stay::class);
    }

    public function rate()
    {
        return $this->belongsTo(FacilityRate::class, 'facility_rate_id');
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function grantedBy()
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function isFree(): bool
    {
        return $this->stay_id !== null;
    }

    public function visitorLabel(): string
    {
        return $this->isFree()
            ? ($this->stay?->guest?->full_name ?? 'Guest').' (in-house guest)'
            : $this->visitor_name.' (walk-in)';
    }
}
