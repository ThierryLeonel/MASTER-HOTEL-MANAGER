<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class RecipeItem extends Model
{
    use HasSyncUlid;
    use HasFactory;

    protected $fillable = ['menu_item_id', 'product_id', 'quantity_per_unit'];

    protected function casts(): array
    {
        return ['quantity_per_unit' => 'decimal:3'];
    }

    public function menuItem()
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
