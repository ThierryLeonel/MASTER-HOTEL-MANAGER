<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class FolioTransaction extends Model
{
    use HasSyncUlid;

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

    /**
     * Charge-types unification (Sept 2026 — see ChargeType's own
     * docblock): replaces the old hardcoded
     * `CHARGE_TYPES = ['room_charge', 'bar_charge', 'restaurant_charge',
     * 'front_desk_charge', 'other_service']` constant with a live read of
     * the admin-editable `charge_types` table, so a newly added charge
     * type (e.g. "Rental Charge") is usable everywhere this used to be
     * checked without another code deploy.
     *
     * Every code this system has EVER considered valid to post to a
     * folio, whether or not it's still active today — used for lifetime/
     * historical totals (guest stay history, department reconciliation)
     * where a since-deactivated or admin-added type must still be counted
     * correctly against transactions posted while it was active. This
     * deliberately does NOT filter by `is_active`.
     */
    public static function allChargeTypeCodes(): array
    {
        return ChargeType::query()->pluck('code')->all();
    }

    /**
     * Only the charge types allowed to be POSTED to a folio right now —
     * active AND `postable_to_folio`. This is what live validation
     * (FolioService::postCharge(), FolioController::charge()) and the
     * "Post charge" dropdown use; a deactivated type, or a walk-in-only
     * one like Gym/Pool (`postable_to_folio = false`), is correctly
     * excluded even though it still counts in allChargeTypeCodes() for
     * historical totals.
     */
    public static function postableChargeTypeCodes(): array
    {
        return ChargeType::query()
            ->where('is_active', true)
            ->where('postable_to_folio', true)
            ->pluck('code')
            ->all();
    }
}
