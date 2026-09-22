<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncCheckpoint extends Model
{
    protected $fillable = ['sync_device_id', 'direction', 'last_synced_at'];

    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
        ];
    }

    public function device()
    {
        return $this->belongsTo(SyncDevice::class, 'sync_device_id');
    }
}
