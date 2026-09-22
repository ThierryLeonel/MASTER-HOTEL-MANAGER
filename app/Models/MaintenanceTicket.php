<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class MaintenanceTicket extends Model
{
    use HasSyncUlid;
    protected $fillable = [
        'room_id', 'title', 'description', 'priority', 'status',
        'takes_room_out_of_service', 'reported_by', 'approved_by',
        'approved_at', 'resolved_by', 'resolved_at', 'resolution_notes',
    ];

    protected function casts(): array
    {
        return [
            'takes_room_out_of_service' => 'boolean',
            'approved_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function reportedBy()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function resolvedBy()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
