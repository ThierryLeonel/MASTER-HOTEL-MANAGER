<?php

namespace App\Services\Reporting;

use App\Models\DiscountApproval;
use App\Models\FolioTransaction;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\Stay;
use Illuminate\Support\Collection;

/**
 * Feature 2 ("Complete guest transaction history report across all
 * stays"): a single, per-guest consolidated view built directly from the
 * two ledgers this system already treats as sources of truth — each
 * stay's `folio_transactions` (every charge/payment/refund/credit-
 * settlement/adjustment, exactly once per real event) and the central
 * `payments` table (real-world method/account/receipt/staff for whichever
 * of those folio rows also moved actual cash). Nothing here re-derives or
 * re-sums money independently of those ledgers, which is what keeps this
 * report from ever double-counting: a deposit's RECEIPT (a `payments` row
 * against the reservation, source_module 'reservation_deposit') and its
 * later APPLICATION to a folio (FolioService::applyAdvanceCredit(), which
 * deliberately never re-ledgers it) are shown as the same money moving
 * through two stages, not as two separate charges; a POS sale settled to
 * a folio is the one `{outlet}_charge` row FolioService::post() already
 * created, never a second entry; and an Accounts Receivable settlement is
 * the `credit_settlement` folio row, never treated as a fresh sale.
 */
class GuestTransactionHistoryService
{
    public function searchGuests(?string $query, int $limit = 25): Collection
    {
        return Guest::query()
            ->when($query, function ($q) use ($query) {
                $q->where(function ($q) use ($query) {
                    $q->where('first_name', 'like', "%{$query}%")
                        ->orWhere('last_name', 'like', "%{$query}%")
                        ->orWhere('phone', 'like', "%{$query}%")
                        ->orWhere('email', 'like', "%{$query}%");
                });
            })
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->limit($limit)
            ->get();
    }

    /**
     * The full consolidated report for one guest. Deliberately scoped to
     * a single, already-identified Guest row rather than a free-text
     * "merge by name" — the spec's "restricting guest identity merges"
     * requirement is satisfied by never guessing that two Guest rows are
     * the same person; only a real, already-established one-to-one
     * (this guest's own id) is ever reported on.
     */
    public function report(Guest $guest): array
    {
        $stays = Stay::with([
            'room.category', 'company', 'checkedInBy', 'checkedOutBy',
            'reservation.discountApprovals.approver', 'reservation.discountApprovals.requestedApprover',
            'discountApprovals.approver', 'discountApprovals.requestedApprover',
            'folio.transactions' => fn ($q) => $q->orderBy('transaction_date'),
            'folio.accountsReceivable',
        ])
            ->where('guest_id', $guest->id)
            ->orderByDesc('check_in_at')
            ->get()
            ->map(fn (Stay $stay) => $this->buildStayReport($stay));

        $depositPayments = Payment::with(['financialAccount', 'recordedBy'])
            ->where('guest_id', $guest->id)
            ->where('source_module', 'reservation_deposit')
            ->orderBy('recorded_at')
            ->get();

        $consolidated = $this->consolidate($stays);

        return [
            'guest' => $guest,
            'stays' => $stays,
            'deposit_payments' => $depositPayments,
            'consolidated' => $consolidated,
        ];
    }

    private function buildStayReport(Stay $stay): array
    {
        $folio = $stay->folio;

        $discountApproval = $this->activeDiscountApprovalFor($stay);

        $transactions = $folio
            ? $folio->transactions->map(fn ($tx) => $this->enrichTransaction($tx))
            : collect();

        $checkoutAt = $stay->actual_check_out_at;

        // Correction round (audited defect #21, "never describe negative
        // balance as fully settled" / "pending refunds surviving
        // checkout"): split the ledger at the moment of checkout so the
        // report shows plainly what the guest owed (or was owed) THE
        // MOMENT they left, distinct from anything settled afterward —
        // rather than only ever showing today's already-netted balance,
        // which can silently hide a real post-checkout credit or a
        // refund that took days to process.
        $atCheckout = $checkoutAt
            ? $transactions->filter(fn ($t) => $t['transaction_date'] && $t['transaction_date']->lte($checkoutAt))
            : $transactions;
        $afterCheckout = $checkoutAt
            ? $transactions->filter(fn ($t) => $t['transaction_date'] && $t['transaction_date']->gt($checkoutAt))
            : collect();

        $balanceAtCheckout = $atCheckout->sum('amount');
        $subsequentSettlementTotal = $afterCheckout->sum('amount');

        return [
            'stay' => $stay,
            'discount_approval' => $discountApproval,
            'transactions' => $transactions,
            'totals' => $this->totalsByType($transactions),
            'balance_at_checkout' => $checkoutAt ? round((float) $balanceAtCheckout, 2) : null,
            'subsequent_settlements' => $afterCheckout->values(),
            'current_balance' => $folio ? (float) $folio->balance : 0.0,
            'is_closed' => $folio?->status === 'closed',
            'accounts_receivable' => $folio?->accountsReceivable,
        ];
    }

    private function activeDiscountApprovalFor(Stay $stay): ?DiscountApproval
    {
        $fromReservation = $stay->reservation?->discountApprovals
            ->filter(fn (DiscountApproval $a) => $a->isActive())
            ->sortByDesc('id')
            ->first();

        if ($fromReservation) {
            return $fromReservation;
        }

        return $stay->discountApprovals
            ->filter(fn (DiscountApproval $a) => $a->isActive())
            ->sortByDesc('id')
            ->first();
    }

    private function enrichTransaction($transaction): array
    {
        // Correction round: only a transaction type that also touches the
        // central ledger (see FolioTransaction::payment()'s own docblock)
        // ever has a linked Payment — a plain charge or an advance-credit
        // application legitimately has none, and that absence is shown
        // as "—" rather than guessed at.
        $payment = $transaction->relationLoaded('payment') ? $transaction->payment : $transaction->payment()->first();

        return [
            'model' => $transaction,
            'type' => $transaction->type,
            'description' => $transaction->description,
            'amount' => (float) $transaction->amount,
            'transaction_date' => $transaction->transaction_date,
            'staff' => $transaction->user,
            'is_voided' => $transaction->isVoided(),
            'void_reason' => $transaction->void_reason,
            'payment_method' => $payment->method ?? $transaction->payment_method,
            'financial_account' => $payment?->financialAccount,
            'receipt_transaction_id' => $transaction->type === 'payment' && ! $transaction->isVoided() ? $transaction->id : null,
        ];
    }

    private function totalsByType(Collection $transactions): array
    {
        $byType = $transactions->groupBy('type')->map(fn ($rows) => round((float) $rows->sum('amount'), 2));

        return [
            'charges' => round(
                collect(FolioTransaction::CHARGE_TYPES)->sum(fn ($type) => (float) ($byType[$type] ?? 0)),
                2
            ),
            'payments' => (float) ($byType['payment'] ?? 0),
            'refunds' => (float) ($byType['refund'] ?? 0),
            'credit_settlements' => (float) ($byType['credit_settlement'] ?? 0),
            'adjustments' => (float) ($byType['adjustment'] ?? 0),
            'net' => round((float) $transactions->sum('amount'), 2),
        ];
    }

    private function consolidate(Collection $stayReports): array
    {
        return [
            'stays_count' => $stayReports->count(),
            'total_charges' => round($stayReports->sum(fn ($s) => $s['totals']['charges']), 2),
            'total_payments' => round($stayReports->sum(fn ($s) => $s['totals']['payments']), 2),
            'total_refunds' => round($stayReports->sum(fn ($s) => $s['totals']['refunds']), 2),
            'total_credit_settlements' => round($stayReports->sum(fn ($s) => $s['totals']['credit_settlements']), 2),
            'net_outstanding' => round($stayReports->sum('current_balance'), 2),
        ];
    }
}
