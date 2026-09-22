<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class LeaveType extends Model
{
    use HasSyncUlid;
    use HasFactory;

    protected $fillable = ['name', 'code', 'is_paid', 'max_days_per_year', 'requires_approval', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_paid' => 'boolean',
            'requires_approval' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function isBalanceTracked(): bool
    {
        return $this->max_days_per_year > 0;
    }
}
