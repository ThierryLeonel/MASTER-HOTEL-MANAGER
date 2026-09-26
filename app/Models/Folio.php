<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class Folio extends Model
{
    use HasSyncUlid;
    use HasFactory;

    protected $fillable = ['stay_id', 'invoice_number', 'status', 'balance', 'closed_at', 'closed_by'];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'closed_at' => 'datetime',
        ];
    }

    public function stay()
    {
        return $this->belongsTo(Stay::class);
    }

    public function transactions()
    {
        return $this->hasMany(FolioTransaction::class)->orderBy('transaction_date');
    }

    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function accountsReceivable()
    {
        return $this->hasOne(AccountsReceivable::class);
    }

    /**
     * Recompute the balance directly from the ledger. Used by tests and
     * reconciliation tooling to prove the denormalized `balance` column
     * never drifts from the source-of-truth transactions.
     *
     * Correction round (audited defect #16): this used to filter out
     * every voided row (`whereNull('voided_at')`) — which excludes a
     * voided ORIGINAL transaction but not the reversing 'adjustment' row
     * that reverses it (the reversal itself is never itself voided). The
     * cached `balance` column is incremented by BOTH the original post
     * and its later reversal (see FolioService::post()), so it correctly
     * nets a voided-then-reversed pair to zero net effect — this method
     * used to disagree with that by dropping only the original half of
     * the pair, permanently drifting further from `balance` with every
     * void ever performed on this folio (exactly the false "integrity
     * check" failure DataIntegrityCheckerService was built to catch, but
     * on the wrong side — the drift was in this method, not `balance`).
     * Summing every row unconditionally — including voided ones —
     * matches `balance`'s own accumulation exactly, since a genuinely
     * voided-and-reversed pair already nets to zero once both halves are
     * counted.
     */
    public function recomputeBalance(): string
    {
        return (string) $this->transactions()->sum('amount');
    }

    /**
     * Post-9.8 fix round ("in the guest folio it should carry the
     * summaries of the bills to be paid to the different departments"):
     * a per-department breakdown of what's owed, computed straight from
     * the ledger (never a separately-maintained total, same philosophy
     * as `balance` above) so it can never drift from the transactions it
     * summarizes. Requires `transactions` to already be loaded (or loads
     * it now) — every non-voided charge groups under its department;
     * payments/refunds/adjustments are shown as a single "Payments &
     * adjustments" credit line so the departments' totals plus this one
     * line reconcile exactly to the folio balance.
     *
     * @return array<string, string> label => signed amount (decimal string)
     */
    public function departmentSummary(): array
    {
        $labels = [
            'room_charge' => 'Room (lodging)',
            'bar_charge' => 'Bar',
            'restaurant_charge' => 'Restaurant',
            'front_desk_charge' => 'Front desk sales',
            'other_service' => 'Other services',
        ];

        // Correction round (audited defect #16): same fix as
        // recomputeBalance() above — no longer excludes voided rows,
        // since that dropped a voided ORIGINAL while keeping its
        // never-voided reversal, permanently drifting this summary from
        // the real per-department balance with every void.
        $transactions = $this->relationLoaded('transactions') ? $this->transactions : $this->transactions()->get();
        $live = $transactions;

        $summary = [];
        foreach ($labels as $type => $label) {
            $sum = $live->where('type', $type)->sum('amount');
            if ((float) $sum !== 0.0) {
                $summary[$label] = (string) $sum;
            }
        }

        $creditsAndAdjustments = $live->whereNotIn('type', array_keys($labels))->sum('amount');
        if ((float) $creditsAndAdjustments !== 0.0) {
            $summary['Payments & adjustments'] = (string) $creditsAndAdjustments;
        }

        return $summary;
    }
}
