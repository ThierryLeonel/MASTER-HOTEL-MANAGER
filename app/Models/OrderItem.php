<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class OrderItem extends Model
{
    use HasSyncUlid;
    protected $fillable = [
        'order_id', 'menu_item_id', 'item_name', 'unit_price', 'quantity',
        'line_total', 'notes', 'voided_at', 'void_reason', 'added_by',
        'is_complimentary', 'comp_reason',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'voided_at' => 'datetime',
            'is_complimentary' => 'boolean',
        ];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function menuItem()
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function addedBy()
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }
}
