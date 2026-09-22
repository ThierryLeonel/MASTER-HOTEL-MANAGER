<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class AccountingDay extends Model
{
    use HasSyncUlid;
    protected $fillable = ['business_date', 'status', 'locked_by', 'locked_at', 'notes'];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'locked_at' => 'datetime',
        ];
    }

    public function lockedBy()
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function isLocked(): bool
    {
        return $this->status === 'locked';
    }
}
