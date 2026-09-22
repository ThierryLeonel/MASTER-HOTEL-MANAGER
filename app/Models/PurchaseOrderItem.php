<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class PurchaseOrderItem extends Model
{
    use HasSyncUlid;
    use HasFactory;

    protected $fillable = ['purchase_order_id', 'product_id', 'quantity_ordered', 'quantity_received', 'unit_cost', 'line_total'];

    protected function casts(): array
    {
        return [
            'quantity_ordered' => 'decimal:3',
            'quantity_received' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function quantityOutstanding(): string
    {
        return bcsub((string) $this->quantity_ordered, (string) $this->quantity_received, 3);
    }
}
