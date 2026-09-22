<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class StockCountItem extends Model
{
    use HasSyncUlid;
    use HasFactory;

    protected $fillable = ['stock_count_id', 'product_id', 'system_quantity', 'counted_quantity', 'variance', 'variance_value'];

    protected function casts(): array
    {
        return [
            'system_quantity' => 'decimal:3',
            'counted_quantity' => 'decimal:3',
            'variance' => 'decimal:3',
            'variance_value' => 'decimal:2',
        ];
    }

    public function stockCount()
    {
        return $this->belongsTo(StockCount::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function isCounted(): bool
    {
        return ! is_null($this->counted_quantity);
    }
}
