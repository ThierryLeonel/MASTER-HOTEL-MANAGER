<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class GoodsReceiptItem extends Model
{
    use HasSyncUlid;
    use HasFactory;

    protected $fillable = ['goods_receipt_id', 'purchase_order_item_id', 'product_id', 'quantity_received', 'unit_cost', 'line_total'];

    protected function casts(): array
    {
        return [
            'quantity_received' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function goodsReceipt()
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function purchaseOrderItem()
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
