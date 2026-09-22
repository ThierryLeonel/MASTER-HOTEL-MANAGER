<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class Unit extends Model
{
    use HasSyncUlid;
    use HasFactory;

    protected $fillable = ['name', 'abbreviation'];

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
