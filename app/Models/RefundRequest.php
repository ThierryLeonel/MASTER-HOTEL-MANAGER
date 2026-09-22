<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class RefundRequest extends Model
{
    use HasSyncUlid;
    use HasFactory;

    protected $fillable = [
        'refund_number', 'folio_id', 'amount', 'reason', 'method', 'status',
        'requested_by', 'approved_by', 'rejection_reason', 'approved_at',
        // Sixth post-9.8 fix round: the real account the refund was paid
        // out of, set at approval time — see the migration that added
        // this column for why it's separate from the request-time
        // `method` guess.
        'financial_account_id',
        // Correction round (audited defect #20): a goodwill compensation
        // is exempt from the "available refundable funds" cap — see
        // RefundService::approve()/requestGoodwill().
        'is_goodwill',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'is_goodwill' => 'boolean',
        ];
    }

    public function folio()
    {
        return $this->belongsTo(Folio::class);
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function financialAccount()
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
