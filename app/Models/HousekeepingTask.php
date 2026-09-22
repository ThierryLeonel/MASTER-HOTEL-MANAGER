<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class HousekeepingTask extends Model
{
    use HasSyncUlid;
    protected $fillable = [
        'room_id', 'type', 'status', 'assigned_to', 'created_by',
        'started_at', 'completed_at', 'notes',
        'approval_status', 'approved_by', 'approved_at', 'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function assignedTo()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
