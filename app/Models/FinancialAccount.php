<?php

namespace App\Models;

use App\Services\Sync\Concerns\HasSyncUlid;
use Illuminate\Database\Eloquent\Model;

class FinancialAccount extends Model
{
    use HasSyncUlid;

    protected $fillable = ['name', 'code', 'type', 'opening_balance', 'is_active'];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function reconciliations()
    {
        return $this->hasMany(FinancialAccountReconciliation::class);
    }

    public function latestReconciliation()
    {
        return $this->hasOne(FinancialAccountReconciliation::class)->latestOfMany('reconciled_at');
    }

    /**
     * Fourth post-9.8 fix round (day-close rebuild): translates this
     * account's `type` into a valid value for the `payments.method` /
     * `cash_disbursements.method` columns.
     *
     * These are two genuinely different enums that happen to overlap
     * almost completely:
     *   financial_accounts.type -> cash, bank, mobile_money, card
     *   payments.method / cash_disbursements.method
     *                    -> cash, mobile_money, card, bank_transfer
     *
     * Every call site that used to do `$financialAccount->type` directly
     * when posting a ledger entry or a disbursement was silently relying
     * on those two lists being the same — which they are for three of
     * the four values, but NOT for 'bank': the seeded "Bank" account has
     * type = 'bank', and inserting that string into `payments.method`
     * (an enum column that does not contain 'bank') throws a hard
     * database error. This was a live, previously-shipped bug — every
     * expense payment or cash disbursement made from the Bank account
     * failed outright. Found while investigating the owner's day-close
     * report.
     *
     * We deliberately do NOT rename the `financial_accounts.type` enum
     * value itself (this project avoids `->change()` on enum/typed
     * columns on shared hosting — see PHASE9_AUDIT_AND_PLAN.md's
     * migration-safety notes) — a small translation here is safer than a
     * schema change to a column already in production use.
     */
    public function paymentMethod(): string
    {
        return match ($this->type) {
            'bank' => 'bank_transfer',
            default => $this->type,
        };
    }
}
