<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class AuditLog extends Model
{
    use HasSyncUlid;
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'role_name', 'action', 'module', 'record_type',
        'record_id', 'old_values', 'new_values', 'ip_address', 'reason', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
