<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class StockBatch extends Model
{
    use HasSyncUlid;
    use HasFactory;

    protected $fillable = ['product_id', 'quantity_received', 'quantity_remaining', 'unit_cost', 'received_at', 'source_type', 'source_id'];

    protected function casts(): array
    {
        return [
            'quantity_received' => 'decimal:3',
            'quantity_remaining' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'received_at' => 'datetime',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function source()
    {
        return $this->morphTo();
    }

    public function movements()
    {
        return $this->hasMany(StockMovement::class);
    }

    public function isExhausted(): bool
    {
        return (float) $this->quantity_remaining <= 0.0;
    }
}
