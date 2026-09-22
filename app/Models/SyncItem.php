<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncItem extends Model
{
    protected $fillable = [
        'sync_batch_id', 'syncable_type', 'syncable_ulid', 'operation', 'status', 'error_message',
    ];

    public function batch()
    {
        return $this->belongsTo(SyncBatch::class, 'sync_batch_id');
    }

    public function conflict()
    {
        return $this->hasOne(SyncConflict::class);
    }

    public function hasConflict(): bool
    {
        return $this->status === 'conflict';
    }
}
