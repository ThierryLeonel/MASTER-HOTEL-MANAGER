<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 9.7.2, revised in Phase 9.7.4 (see the migration's own docblock
 * for the full "why" — in short, `secret_hash` couldn't be un-hashed to
 * present on an outgoing call, so it was replaced with an encrypted,
 * retrievable `shared_secret` before this table was ever used against a
 * real database). One row per counterpart in the sync topology.
 * Deliberately does NOT use HasSyncUlid — `device_key` is its own,
 * independently agreed-upon public identifier, not a HasSyncUlid-managed
 * one, and this table is never itself replicated as business data.
 */
class SyncDevice extends Model
{
    protected $fillable = [
        'name', 'device_key', 'shared_secret', 'endpoint_url', 'is_active', 'last_seen_at',
    ];

    protected $hidden = ['shared_secret'];

    protected function casts(): array
    {
        return [
            'shared_secret' => 'encrypted',
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    public function checkpoints()
    {
        return $this->hasMany(SyncCheckpoint::class);
    }

    public function batches()
    {
        return $this->hasMany(SyncBatch::class);
    }

    public function logs()
    {
        return $this->hasMany(SyncLog::class);
    }

    public function checkpointFor(string $direction): ?SyncCheckpoint
    {
        return $this->checkpoints()->where('direction', $direction)->first();
    }
}
