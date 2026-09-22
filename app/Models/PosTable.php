<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class PosTable extends Model
{
    use HasSyncUlid;
    use HasFactory;

    protected $table = 'pos_tables';

    protected $fillable = ['outlet', 'label', 'seats', 'status', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function currentOrder()
    {
        return $this->hasOne(Order::class)->whereIn('status', ['draft', 'confirmed']);
    }
}
