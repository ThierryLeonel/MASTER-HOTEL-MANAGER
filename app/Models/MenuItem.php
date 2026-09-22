<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class MenuItem extends Model
{
    use HasSyncUlid;
    use HasFactory;

    protected $fillable = ['menu_category_id', 'outlet', 'name', 'description', 'price', 'is_active'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function category()
    {
        return $this->belongsTo(MenuCategory::class, 'menu_category_id');
    }

    public function recipeItems()
    {
        return $this->hasMany(RecipeItem::class);
    }

    public function hasRecipe(): bool
    {
        return $this->recipeItems()->exists();
    }
}
