<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class StockRequisition extends Model
{
    use HasSyncUlid;
    use HasFactory;

    protected $fillable = [
        'requisition_number', 'department_id', 'status', 'requested_by',
        'approved_by', 'notes', 'rejection_reason', 'approved_at',
    ];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function items()
    {
        return $this->hasMany(StockRequisitionItem::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
