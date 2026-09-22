<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class StockMovement extends Model
{
    use HasSyncUlid;
    use HasFactory;

    protected $fillable = [
        'product_id', 'stock_batch_id', 'type', 'direction', 'quantity', 'unit_cost',
        'total_cost', 'source_module', 'source_reference', 'recorded_by', 'recorded_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'recorded_at' => 'datetime',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function batch()
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
