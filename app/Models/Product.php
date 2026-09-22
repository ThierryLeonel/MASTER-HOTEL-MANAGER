<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class Product extends Model
{
    use HasSyncUlid;
    use HasFactory;

    protected $fillable = ['sku', 'name', 'unit_id', 'category', 'reorder_level', 'is_active', 'notes'];

    protected function casts(): array
    {
        return [
            'reorder_level' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function batches()
    {
        return $this->hasMany(StockBatch::class);
    }

    public function movements()
    {
        return $this->hasMany(StockMovement::class);
    }

    public function recipeItems()
    {
        return $this->hasMany(RecipeItem::class);
    }

    /**
     * Live stock on hand — the sum of every FIFO batch's remaining
     * quantity. Deliberately not a stored/denormalized column: the
     * whole system's dashboards work this way (spec section 24), so a
     * number here is never allowed to drift from the ledger it's
     * derived from.
     */
    public function quantityOnHand(): string
    {
        return (string) $this->batches()->sum('quantity_remaining');
    }

    public function isBelowReorderLevel(): bool
    {
        return (float) $this->quantityOnHand() < (float) $this->reorder_level;
    }
}
