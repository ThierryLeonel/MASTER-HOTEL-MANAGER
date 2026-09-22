<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class StockWastage extends Model
{
    use HasSyncUlid;
    use HasFactory;

    protected $fillable = [
        'wastage_number', 'product_id', 'department_id', 'quantity', 'reason', 'status',
        'recorded_by', 'approved_by', 'rejection_reason', 'recorded_at', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'recorded_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
