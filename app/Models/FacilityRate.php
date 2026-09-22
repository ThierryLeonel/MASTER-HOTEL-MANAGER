<?php

namespace App\Models;

use App\Services\Sync\Concerns\HasSyncUlid;
use Illuminate\Database\Eloquent\Model;

class FacilityRate extends Model
{
    use HasSyncUlid;

    protected $fillable = ['facility_id', 'name', 'amount', 'is_active'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function facility()
    {
        return $this->belongsTo(Facility::class);
    }
}
