<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class StockRequisitionItem extends Model
{
    use HasSyncUlid;
    use HasFactory;

    protected $fillable = ['stock_requisition_id', 'product_id', 'quantity_requested'];

    protected function casts(): array
    {
        return ['quantity_requested' => 'decimal:3'];
    }

    public function requisition()
    {
        return $this->belongsTo(StockRequisition::class, 'stock_requisition_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
