<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Feature 4 (500 XAF room-service delivery charge): an append-only audit
 * trail of every change to an order's delivery mode/fee made AFTER
 * confirm() locked it (`orders.delivery_mode_locked_at`) — see
 * OrderService::correctDeliveryMode(). Never edited or deleted; the order
 * row itself always reflects the latest correction, this table is the
 * history of how it got there.
 */
class OrderDeliveryCorrection extends Model
{
    protected $fillable = [
        'order_id', 'actor_id',
        'previous_is_room_delivery', 'previous_delivery_fee_amount',
        'new_is_room_delivery', 'new_delivery_fee_amount',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'previous_is_room_delivery' => 'boolean',
            'previous_delivery_fee_amount' => 'decimal:2',
            'new_is_room_delivery' => 'boolean',
            'new_delivery_fee_amount' => 'decimal:2',
        ];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
