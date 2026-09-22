<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class RoomCategory extends Model
{
    use HasSyncUlid;
    use HasFactory;

    protected $fillable = ['name', 'description', 'max_occupancy', 'base_rate', 'is_active'];

    protected function casts(): array
    {
        return [
            'base_rate' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function rooms()
    {
        return $this->hasMany(Room::class);
    }
}
