<?php

namespace App\Services\Finance;

use App\Exceptions\HmsException;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PaymentLedgerService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Expense lifecycle: pending -> approved -> paid (or rejected/
 * cancelled). The configurable `expense_approval_threshold` setting
 * (spec section 23 — never hardcoded) determines the approval tier
 * required: at/above threshold needs `approve-large-expense` on top of
 * the base `approve-expense` permission a route-level middleware
 * already checked. The threshold is read fresh at approval time, not
 * frozen when the expense was requested, so a policy change applies to
 * whatever is still pending.
 */
class ExpenseService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SettingsService $settings,
        private readonly PaymentLedgerService $ledger,
    ) {
    }

    public function request(float $amount, string $category, string $description, User $actor, ?int $departmentId = null): Expense
    {
        if ($amount <= 0) {
            throw new HmsException('Expense amount must be greater than zero.');
        }

        $expense = Expense::create([
            'expense_number' => $this->generateNumber(),
            'department_id' => $departmentId,
            'category' => $category,
            'description' => $description,
            'amount' => $amount,
            'status' => 'pending',
            'requested_by' => $actor->id,
        ]);

        $this->audit->log('finance.expense_requested', 'finance', $expense, null, $expense->toArray(), actor: $actor);

        return $expense;
    }

    public function isAboveThreshold(Expense $expense): bool
    {
        return (float) $expense->amount >= $this->settings->expenseApprovalThreshold();
    }

    public function approve(Expense $expense, User $actor): Expense
    {
        if (! $expense->isPending()) {
            throw new HmsException("Only a pending expense can be approved (current status: {$expense->status}).");
        }

        if ($this->isAboveThreshold($expense) && ! $actor->can('approve-large-expense')) {
            $threshold = $this->settings->expenseApprovalThreshold();

            throw new HmsException(
                "This expense ({$expense->amount}) is at or above the configured approval threshold ({$threshold}) and requires GM/Owner/Accountant sign-off."
            );
        }

        // Security-audit fix (Phase 7): Duty Manager holds both
        // manage-expenses (request) and approve-expense (approve small
        // ones) — every other approval pair in this system deliberately
        // withholds the approve-tier from whoever holds the request-tier
        // at the Duty Manager level (purchase orders, refunds, payroll,
        // accounting-day lock all do this already). Without this guard a
        // single Duty Manager account could request and approve the same
        // small expense with no second party involved at all, which is
        // exactly what an approval workflow exists to prevent. A hard
        // business rule, not a permission check, so it applies regardless
        // of role.
        if ((int) $expense->requested_by === (int) $actor->id) {
            throw new HmsException('You cannot approve your own expense request.');
        }

        $expense->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]);

        $this->audit->log('finance.expense_approved', 'finance', $expense, null, $expense->fresh()->toArray(), actor: $actor);

        return $expense->fresh();
    }

    public function reject(Expense $expense, User $actor, string $reason): Expense
    {
        if (! $expense->isPending()) {
            throw new HmsException("Only a pending expense can be rejected (current status: {$expense->status}).");
        }

        $expense->update(['status' => 'rejected', 'rejection_reason' => $reason]);

        $this->audit->log('finance.expense_rejected', 'finance', $expense, null, $expense->toArray(), $reason, $actor);

        return $expense->fresh();
    }

    public function cancel(Expense $expense, User $actor, string $reason): Expense
    {
        if (! $expense->isPending()) {
            throw new HmsException("Only a pending expense can be cancelled (current status: {$expense->status}).");
        }

        $expense->update(['status' => 'cancelled']);

        $this->audit->log('finance.expense_cancelled', 'finance', $expense, null, $expense->toArray(), $reason, $actor);

        return $expense->fresh();
    }

    /**
     * The only step that actually moves money — posts to the central
     * payment ledger (which itself enforces the accounting-day lock).
     *
     * Concurrency fix: two duplicate "mark paid" requests (a double form
     * submission, a retried click) racing against the same expense could
     * previously both pass the isApproved() check before either had
     * committed its update, and both post a Payment to the ledger — a
     * real duplicate payment. The status check is now re-done INSIDE the
     * transaction, against a row locked with lockForUpdate(), so the
     * second request blocks until the first commits, then sees the
     * now-'paid' status and fails cleanly instead of paying twice.
     */
    /**
     * `$financialAccount` is now required (PHASE9_AUDIT_AND_PLAN.md §21,
     * "for expenses specify the particular financial account... the
     * money is leaving for the expense") — `method` is derived from the
     * account's own `type` rather than accepted as a separate value, so
     * they can never disagree.
     */
    public function markPaid(Expense $expense, User $actor, FinancialAccount $financialAccount): Expense
    {
        return DB::transaction(function () use ($expense, $actor, $financialAccount) {
            /** @var Expense $locked */
            $locked = Expense::query()->whereKey($expense->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isApproved()) {
                throw new HmsException("Only an approved expense can be paid (current status: {$locked->status}).");
            }

            $this->ledger->record(
                amount: (float) $locked->amount,
                // Fourth post-9.8 fix round: paymentMethod() rather than
                // ->type directly — the Bank account's type ('bank') is
                // not a valid payments.method enum value ('bank_transfer'
                // is). See FinancialAccount::paymentMethod()'s docblock.
                method: $financialAccount->paymentMethod(),
                sourceModule: 'finance_expense',
                recordedBy: $actor,
                sourceReference: $locked->expense_number,
                reference: $locked,
                direction: 'out',
                // The expense already carries its own department_id (see
                // request()) — pass it explicitly rather than letting
                // PaymentLedgerService guess from 'finance_expense' alone
                // (which carries no department signal by itself). This
                // also means the shift gate never applies here — see
                // PaymentLedgerService's docblock.
                departmentId: $locked->department_id,
                financialAccountId: $financialAccount->id,
            );

            $locked->update([
                'status' => 'paid',
                'paid_by' => $actor->id,
                'paid_at' => now(),
                'payment_method' => $financialAccount->paymentMethod(),
                'financial_account_id' => $financialAccount->id,
            ]);

            $this->audit->log('finance.expense_paid', 'finance', $locked, null, $locked->fresh()->toArray(), actor: $actor);

            return $locked->fresh();
        });
    }

    private function generateNumber(): string
    {
        return 'EXP-'.now()->format('Ymd').'-'.strtoupper(Str::random(5));
    }
}
