<?php

namespace App\Models;

use App\Services\Sync\Concerns\HasSyncUlid;
use Illuminate\Database\Eloquent\Model;

class FinancialAccountReconciliation extends Model
{
    use HasSyncUlid;

    protected $fillable = [
        'financial_account_id', 'business_date', 'reconciled_by', 'reconciled_at',
        'expected_balance', 'declared_balance', 'variance', 'notes',
        // Correction round (audited defect #29).
        'is_stale', 'invalidated_at', 'invalidated_reason',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'reconciled_at' => 'datetime',
            'expected_balance' => 'decimal:2',
            'declared_balance' => 'decimal:2',
            'variance' => 'decimal:2',
            'is_stale' => 'boolean',
            'invalidated_at' => 'datetime',
        ];
    }

    public function financialAccount()
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function reconciledBy()
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }
}
