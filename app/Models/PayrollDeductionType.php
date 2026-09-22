<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class PayrollDeductionType extends Model
{
    use HasSyncUlid;
    use HasFactory;

    protected $fillable = ['name', 'code', 'calculation_method', 'flat_rate_percent', 'is_active', 'display_order'];

    protected function casts(): array
    {
        return [
            'flat_rate_percent' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function brackets()
    {
        return $this->hasMany(PayrollDeductionBracket::class)->orderBy('display_order')->orderBy('min_amount');
    }

    public function isProgressive(): bool
    {
        return $this->calculation_method === 'progressive_brackets';
    }
}
