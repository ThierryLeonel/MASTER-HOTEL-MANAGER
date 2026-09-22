<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class Payment extends Model
{
    use HasSyncUlid;
    protected $fillable = [
        'payment_number', 'direction', 'amount', 'method', 'financial_account_id', 'source_module',
        'department_id', 'source_reference', 'reference_type', 'reference_id',
        'guest_id', 'recorded_by', 'recorded_at', 'notes',
        // Correction round (audited defects #30 and #32).
        'business_date', 'shift_id', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'recorded_at' => 'datetime',
            'business_date' => 'date',
        ];
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function financialAccount()
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function reference()
    {
        return $this->morphTo();
    }
}
