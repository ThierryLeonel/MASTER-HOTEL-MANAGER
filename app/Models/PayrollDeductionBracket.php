<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class PayrollDeductionBracket extends Model
{
    use HasSyncUlid;
    use HasFactory;

    protected $fillable = ['payroll_deduction_type_id', 'min_amount', 'max_amount', 'rate_percent', 'display_order'];

    protected function casts(): array
    {
        return [
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
            'rate_percent' => 'decimal:2',
        ];
    }

    public function deductionType()
    {
        return $this->belongsTo(PayrollDeductionType::class, 'payroll_deduction_type_id');
    }
}
