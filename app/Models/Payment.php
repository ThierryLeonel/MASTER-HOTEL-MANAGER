<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class Payment extends Model
{
    use HasSyncUlid;

    // Direct request ("let the income source be a dropdown of the income
    // sources... you should identify all the income sources"), then a
    // fast follow-up ("the list should not be fixed... the accountant, or
    // finance officer [create/update], and [the owner should be able to]
    // update, create and delete it"): the dropdown on the general "record
    // a payment" screen (front-office/payments/index.blade.php) first
    // shipped as a hardcoded PHP constant here, then — per the follow-up
    // — was moved into a proper managed table the moment ago that list
    // needed to be editable without a code deployment. See the
    // IncomeSource model, its migration, and IncomeSourceService for
    // where that list now lives and who can change it.
    //
    // This column itself always stayed, and stays, free text (see its own
    // migration and PaymentController::store()) — the dropdown (backed by
    // IncomeSource) or its "Other" option just decide what string ends up
    // here, so this table can be edited freely later without a migration
    // and old values already in the database still display fine.

    protected $fillable = [
        'payment_number', 'direction', 'amount', 'method', 'financial_account_id', 'source_module',
        'department_id', 'source_reference', 'reference_type', 'reference_id',
        'guest_id', 'recorded_by', 'recorded_at', 'notes',
        // Correction round (audited defects #30 and #32).
        'business_date', 'shift_id', 'idempotency_key',
        // Direct request ("all in payment must be attached to an income
        // source that is to be represented ... in the income statement")
        // — see this column's migration for why it's free text, and
        // PaymentController::store() for where it's required.
        'income_source',
        // Direct request ("the owner is suppose to be able to edit all
        // transaction[s]") — see this migration's docblock and
        // PaymentLedgerService::void() for the full reasoning. Never set
        // directly; always go through void().
        'voided_at', 'voided_by', 'void_reason',
        // Direct request ("payments can involve discount if granted") —
        // informational only; `amount` above is always the real net cash
        // received. See this column's migration for the full reasoning.
        'discount_amount', 'discount_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'recorded_at' => 'datetime',
            'business_date' => 'date',
            'voided_at' => 'datetime',
            'discount_amount' => 'decimal:2',
        ];
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function hasDiscount(): bool
    {
        return $this->discount_amount !== null && (float) $this->discount_amount > 0;
    }

    /**
     * The normal/quoted price before the discount — `amount` is always
     * the real net cash actually received (see this column's migration).
     */
    public function grossAmount(): float
    {
        return (float) $this->amount + (float) ($this->discount_amount ?? 0);
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

    public function voidedBy()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function reference()
    {
        return $this->morphTo();
    }
}
