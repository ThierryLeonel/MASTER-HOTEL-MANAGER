<?php

namespace App\Models;

use App\Services\Sync\Concerns\HasSyncUlid;
use Illuminate\Database\Eloquent\Model;

class Facility extends Model
{
    use HasSyncUlid;

    protected $fillable = ['code', 'name', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function rates()
    {
        return $this->hasMany(FacilityRate::class);
    }

    public function accesses()
    {
        return $this->hasMany(FacilityAccess::class);
    }
}
