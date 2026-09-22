<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class Order extends Model
{
    use HasSyncUlid;
    use HasFactory;

    protected $fillable = [
        'order_number', 'outlet', 'pos_table_id', 'stay_id', 'status',
        'subtotal', 'tax_amount', 'total_amount', 'payment_method',
        'opened_by', 'confirmed_at', 'closed_by', 'closed_at',
        'cancellation_reason', 'notes',
        // Feature 4 (500 XAF room-service delivery charge).
        'is_room_delivery', 'delivery_destination_stay_id', 'delivery_fee_amount',
        'delivery_fee_waived', 'delivery_fee_waived_by', 'delivery_fee_waived_at',
        'delivery_fee_waive_reason', 'delivery_mode_locked_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'confirmed_at' => 'datetime',
            'closed_at' => 'datetime',
            'is_room_delivery' => 'boolean',
            'delivery_fee_amount' => 'decimal:2',
            'delivery_fee_waived' => 'boolean',
            'delivery_fee_waived_at' => 'datetime',
            'delivery_mode_locked_at' => 'datetime',
        ];
    }

    public function table()
    {
        return $this->belongsTo(PosTable::class, 'pos_table_id');
    }

    public function stay()
    {
        return $this->belongsTo(Stay::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function openedBy()
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function deliveryDestinationStay()
    {
        return $this->belongsTo(Stay::class, 'delivery_destination_stay_id');
    }

    public function deliveryFeeWaivedBy()
    {
        return $this->belongsTo(User::class, 'delivery_fee_waived_by');
    }

    public function deliveryCorrections()
    {
        return $this->hasMany(OrderDeliveryCorrection::class);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'confirmed'], true);
    }
}
