<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class StockCount extends Model
{
    use HasSyncUlid;
    use HasFactory;

    protected $fillable = ['count_number', 'status', 'started_by', 'completed_by', 'notes', 'started_at', 'completed_at'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function startedBy()
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function completedBy()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function items()
    {
        return $this->hasMany(StockCountItem::class);
    }

    public function isInProgress(): bool
    {
        return $this->status === 'in_progress';
    }
}
