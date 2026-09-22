<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncBatch extends Model
{
    protected $fillable = [
        'ulid', 'sync_device_id', 'direction', 'status', 'item_count',
        'checksum', 'payload', 'applied_at', 'error_message',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'applied_at' => 'datetime',
        ];
    }

    public function device()
    {
        return $this->belongsTo(SyncDevice::class, 'sync_device_id');
    }

    public function items()
    {
        return $this->hasMany(SyncItem::class);
    }

    public function isApplied(): bool
    {
        return $this->status === 'applied';
    }
}
