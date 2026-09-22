<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class EmployeeProfile extends Model
{
    use HasSyncUlid;
    use HasFactory;

    protected $fillable = [
        'user_id', 'employee_number', 'job_title', 'employment_type',
        'hire_date', 'termination_date', 'basic_salary', 'payment_method',
        'bank_account', 'national_id', 'employment_status',
    ];

    protected function casts(): array
    {
        return [
            'hire_date' => 'date',
            'termination_date' => 'date',
            'basic_salary' => 'decimal:2',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->employment_status === 'active';
    }
}
