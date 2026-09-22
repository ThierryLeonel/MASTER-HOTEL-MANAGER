<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class Payslip extends Model
{
    use HasSyncUlid;
    use HasFactory;

    protected $fillable = [
        'payroll_run_id', 'user_id', 'basic_salary', 'total_allowances',
        'absence_deduction', 'gross_pay', 'total_statutory_deductions',
        'other_deductions', 'net_pay', 'status', 'payment_method', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'basic_salary' => 'decimal:2',
            'total_allowances' => 'decimal:2',
            'absence_deduction' => 'decimal:2',
            'gross_pay' => 'decimal:2',
            'total_statutory_deductions' => 'decimal:2',
            'other_deductions' => 'decimal:2',
            'net_pay' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function payrollRun()
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function lines()
    {
        return $this->hasMany(PayslipLine::class);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }
}
