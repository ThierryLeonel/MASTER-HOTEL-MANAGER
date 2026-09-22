<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class MenuCategory extends Model
{
    use HasSyncUlid;
    use HasFactory;

    protected $fillable = ['outlet', 'name', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function items()
    {
        return $this->hasMany(MenuItem::class);
    }
}
