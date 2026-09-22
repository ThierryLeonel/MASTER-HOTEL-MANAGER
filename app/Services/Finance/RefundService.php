<?php

namespace App\Services\Finance;

use App\Exceptions\HmsException;
use App\Models\FinancialAccount;
use App\Models\Folio;
use App\Models\FolioTransaction;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\FrontOffice\FolioService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The approval gate FolioService::postRefund() has always assumed
 * existed (its docblock has said "requires approval upstream" since
 * Phase 1) — recording a request never moves money; only approving one
 * calls postRefund(), which itself posts to the central payment ledger
 * and is therefore subject to the accounting-day lock.
 */
class RefundService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly FolioService $folioService,
    ) {
    }

    /**
     * Correction round (audited defect #21, "pending refunds surviving
     * checkout"): a refund can legitimately need to be requested AFTER a
     * guest has already checked out (a dispute noticed later, a delayed
     * dispute over a charge) — the closed-folio guard that used to block
     * this outright is removed. postRefund() (via FolioService::post())
     * already allows a 'refund' transaction onto a closed folio, so
     * approval will succeed exactly the same way once this is approved.
     */
    public function request(Folio $folio, float $amount, string $reason, string $method, User $actor, bool $isGoodwill = false): RefundRequest
    {
        if ($amount <= 0) {
            throw new HmsException('Refund amount must be greater than zero.');
        }

        $refund = RefundRequest::create([
            'refund_number' => $this->generateNumber(),
            'folio_id' => $folio->id,
            'amount' => $amount,
            'reason' => $reason,
            'method' => $method,
            'status' => 'pending',
            'requested_by' => $actor->id,
            'is_goodwill' => $isGoodwill,
        ]);

        $this->audit->log('finance.refund_requested', 'finance', $refund, null, $refund->toArray(), actor: $actor);

        return $refund;
    }

    /**
     * Concurrency fix: two duplicate "approve" requests racing against the
     * same refund could previously both pass the isPending() check before
     * either had committed, and both post a refund to the folio/central
     * ledger — a real duplicate refund payment. The status (and the
     * self-approval rule, which depends on it still being the same
     * request) are now re-checked INSIDE the transaction against a row
     * locked with lockForUpdate(), so a second, racing request blocks
     * until the first commits, then sees the now-'approved' status and
     * fails cleanly instead of refunding twice.
     *
     * Sixth post-9.8 fix round ("all payments are to be made in the
     * accounts that control the payment methods"): the `method` typed at
     * REQUEST time was never guaranteed to match a real account, and
     * nothing tied it to one — the approver, who is the one actually
     * handing the money back, now has to pick the specific
     * FinancialAccount it's coming out of, exactly like
     * ExpenseService::markPaid() already requires for expenses. The
     * posted `method` is always derived from that account
     * (`paymentMethod()`, never the account's raw `type` — see that
     * method's own docblock for the Bank/'bank_transfer' enum mismatch
     * this avoids), so the refund's original requested method is kept on
     * the row purely as a record of what was asked for, and never itself
     * reaches the ledger.
     */
    public function approve(RefundRequest $refund, User $actor, FinancialAccount $financialAccount): RefundRequest
    {
        return DB::transaction(function () use ($refund, $actor, $financialAccount) {
            /** @var RefundRequest $locked */
            $locked = RefundRequest::query()->whereKey($refund->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw new HmsException("Only a pending refund request can be approved (current status: {$locked->status}).");
            }
            // Security-audit fix (Phase 7): request-refund and approve-refund
            // overlap for GM/Owner in the role matrix (the same broad-authority
            // pattern used everywhere else for those two roles), but adding
            // this guard costs nothing for the normal case (a different person
            // approving) and keeps the rule consistent with Expense/Leave —
            // the one account that filed a refund request is never also the
            // one that signs off on it.
            if ((int) $locked->requested_by === (int) $actor->id) {
                throw new HmsException('You cannot approve your own refund request.');
            }

            // Correction round (audited defect #20): an ordinary refund
            // (is_goodwill = false) is capped against what is actually
            // still available to refund on this folio — total received
            // (payments + debt settlements) minus refunds already
            // approved — locked at the FOLIO level too (not just this
            // request) so two refund requests approved concurrently
            // against the same folio can't together exceed what was
            // really paid in. A goodwill compensation is deliberately
            // exempt — it is compensating the guest beyond what they
            // actually paid, a separate authorized decision, not a
            // mistaken double-refund.
            if (! $locked->is_goodwill) {
                /** @var Folio $folio */
                $folio = Folio::lockForUpdate()->findOrFail($locked->folio_id);

                $totalReceived = abs((float) FolioTransaction::where('folio_id', $folio->id)
                    ->whereIn('type', ['payment', 'credit_settlement'])
                    ->whereNull('voided_at')
                    ->sum('amount'));

                $totalAlreadyRefunded = (float) FolioTransaction::where('folio_id', $folio->id)
                    ->where('type', 'refund')
                    ->whereNull('voided_at')
                    ->sum('amount');

                $availableToRefund = max(0.0, $totalReceived - $totalAlreadyRefunded);

                if (bccomp((string) $locked->amount, (string) $availableToRefund, 2) > 0) {
                    throw new HmsException(
                        "Refund amount ({$locked->amount}) exceeds the {$availableToRefund} still available to refund on this folio. ".
                        'Use a goodwill compensation request if this is intentionally more than the guest paid.'
                    );
                }
            }

            $this->folioService->postRefund(
                $locked->folio,
                (float) $locked->amount,
                $locked->reason,
                $actor,
                sourceModule: 'finance_refund',
                method: $financialAccount->paymentMethod(),
                financialAccountId: $financialAccount->id,
            );

            $locked->update([
                'status' => 'approved',
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'financial_account_id' => $financialAccount->id,
            ]);

            $this->audit->log('finance.refund_approved', 'finance', $locked, null, $locked->fresh()->toArray(), actor: $actor);

            return $locked->fresh();
        });
    }

    /**
     * Correction round (audited defect #20): the explicit, separate
     * "goodwill compensation" action the spec calls for — a thin wrapper
     * over request() with is_goodwill forced true, so a caller can't
     * accidentally get the exemption from the availability cap by
     * forgetting a flag on the generic method.
     */
    public function requestGoodwill(Folio $folio, float $amount, string $reason, string $method, User $actor): RefundRequest
    {
        return $this->request($folio, $amount, $reason, $method, $actor, isGoodwill: true);
    }

    public function reject(RefundRequest $refund, User $actor, string $reason): RefundRequest
    {
        if (! $refund->isPending()) {
            throw new HmsException("Only a pending refund request can be rejected (current status: {$refund->status}).");
        }

        $refund->update(['status' => 'rejected', 'rejection_reason' => $reason]);

        $this->audit->log('finance.refund_rejected', 'finance', $refund, null, $refund->toArray(), $reason, $actor);

        return $refund->fresh();
    }

    private function generateNumber(): string
    {
        return 'REF-'.now()->format('Ymd').'-'.strtoupper(Str::random(5));
    }
}
