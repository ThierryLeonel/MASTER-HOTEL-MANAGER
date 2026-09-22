<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class FolioTransaction extends Model
{
    use HasSyncUlid;
    public const CHARGE_TYPES = ['room_charge', 'bar_charge', 'restaurant_charge', 'front_desk_charge', 'other_service'];

    public const CREDIT_TYPES = ['payment', 'refund', 'credit_settlement'];

    protected $fillable = [
        'folio_id', 'type', 'amount', 'description', 'source_module',
        'reference', 'payment_method', 'user_id', 'transaction_date',
        'reverses_transaction_id', 'voided_at', 'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'transaction_date' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function folio()
    {
        return $this->belongsTo(Folio::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reverses()
    {
        return $this->belongsTo(FolioTransaction::class, 'reverses_transaction_id');
    }

    /**
     * Feature 2 (consolidated guest transaction history): the reverse of
     * Payment::reference() — every FolioService method that also touches
     * the central ledger (postPayment(), postRefund(),
     * postCreditSettlement(), correctPayment()'s reversal) passes
     * `reference: $transaction`, so the matching Payment row (with its
     * real financial_account_id/method/recorded_by) can be found from
     * this side too, without the report needing to know each method's
     * internals. Null for a transaction that never touched the central
     * ledger — a charge, a void's plain 'adjustment', or
     * applyAdvanceCredit()'s deposit-application (deliberately NOT
     * re-ledgered — see that method's own docblock) — the report handles
     * that absence explicitly rather than assuming every row has one.
     */
    public function payment()
    {
        return $this->morphOne(Payment::class, 'reference');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }
}
