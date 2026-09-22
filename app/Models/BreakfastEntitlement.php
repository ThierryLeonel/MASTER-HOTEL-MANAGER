<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Feature 3 ("one complimentary breakfast per occupied unit per business
 * date") — see the creating migration's docblock for the full reasoning
 * behind the (stay_id, business_date) unique key. A row is never deleted
 * and a reversal never frees the slot back up (`reversed_at` records the
 * correction; the row, and therefore the unique key's count, stays).
 */
class BreakfastEntitlement extends Model
{
    protected $fillable = [
        'stay_id', 'room_id', 'business_date', 'served_at', 'served_by',
        'order_id', 'order_item_id', 'menu_item_id',
        'reversed_at', 'reversed_by', 'reversal_reason',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'served_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function stay()
    {
        return $this->belongsTo(Stay::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function servedBy()
    {
        return $this->belongsTo(User::class, 'served_by');
    }

    public function reversedBy()
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function menuItem()
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }
}
