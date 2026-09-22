<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only sync activity log, written only by App\Services\Sync\SyncLogger
 * — the exact same single-writer convention as App\Models\AuditLog /
 * App\Services\AuditLogger.
 */
class SyncLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['sync_device_id', 'level', 'event', 'message', 'context', 'created_at'];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function device()
    {
        return $this->belongsTo(SyncDevice::class, 'sync_device_id');
    }
}
