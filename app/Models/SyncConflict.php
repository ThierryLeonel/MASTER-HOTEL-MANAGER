<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncConflict extends Model
{
    protected $fillable = [
        'sync_item_id', 'syncable_type', 'syncable_ulid', 'local_data', 'incoming_data',
        'detected_at', 'resolved_at', 'resolved_by', 'resolution', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'local_data' => 'array',
            'incoming_data' => 'array',
            'detected_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function item()
    {
        return $this->belongsTo(SyncItem::class, 'sync_item_id');
    }

    public function resolvedBy()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }
}
