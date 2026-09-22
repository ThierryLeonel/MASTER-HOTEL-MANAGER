<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class PurchaseOrder extends Model
{
    use HasSyncUlid;
    use HasFactory;

    protected $fillable = [
        'po_number', 'supplier_id', 'status', 'ordered_by', 'approved_by',
        'notes', 'rejection_reason', 'submitted_at', 'approved_at', 'received_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function orderedBy()
    {
        return $this->belongsTo(User::class, 'ordered_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function goodsReceipts()
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function isFullyReceived(): bool
    {
        return $this->items()->whereColumn('quantity_received', '<', 'quantity_ordered')->doesntExist();
    }
}
