<?php

namespace App\Services;

use App\Exceptions\HmsException;
use App\Models\Department;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountReconciliation;
use App\Models\Payment;
use App\Models\Shift;
use App\Models\User;
use App\Services\Finance\AccountingDayService;
use App\Services\Finance\ShiftGateService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The single point of entry into the central `payments` ledger (spec
 * section 16). Every module that takes or refunds money calls this
 * rather than writing to `payments` directly, so the ledger's shape
 * never drifts between callers. Module-specific records (a
 * FolioTransaction, an Order) still exist alongside this — this table
 * is the cross-module view, not a replacement for them.
 *
 * Phase 4 addition: this is also the single enforcement point for the
 * accounting-day lock (spec section 17) — see AccountingDayService's
 * docblock for why checking it here, rather than in every caller, is
 * deliberate.
 *
 * Post-9.8 second fix round addition: every payment is also attributed
 * to one of the existing `departments` rows, for the new shift-based
 * cash reconciliation and department reconciliation report
 * (PHASE9_AUDIT_AND_PLAN.md §18-19). Rather than update every one of the
 * dozen call sites across the app to pass a department explicitly, this
 * is derived automatically here from `sourceModule` — which every caller
 * already passes — via SOURCE_MODULE_DEPARTMENT_CODES below. A caller
 * that already knows a more specific department than the generic mapping
 * would guess (Expense/CashDisbursement, which carry their own
 * department_id) passes `$departmentId` explicitly and that always wins.
 *
 * Third post-9.8 fix round additions (PHASE9_AUDIT_AND_PLAN.md §21):
 *
 *  - `financial_account_id`: which real-world place (Cash, Bank, MTN
 *    Mobile Money, Orange Money, ...) the money actually moved through.
 *    Auto-defaulted from `$method` when a caller doesn't pass one
 *    explicitly — cash/bank_transfer/card map to an unambiguous default
 *    account; mobile_money deliberately does NOT (MTN vs Orange can't be
 *    guessed), so it's left null unless the caller knows and passes it.
 *
 *  - the "no transaction without an open shift" gate
 *    (App\Services\Finance\ShiftGateService): only applied when a
 *    department was AUTO-derived from `sourceModule` below (a guest-
 *    facing sale/payment), never when the caller passed `$departmentId`
 *    explicitly (Expense/CashDisbursement/Payroll — an accountant's cost
 *    attribution, not a live "is someone on duty" check). See
 *    ShiftGateService's own docblock for the full reasoning.
 */
class PaymentLedgerService
{
    /**
     * Maps a `source_module` value to the `departments.code` it belongs
     * to for cash-attribution purposes. 'pos_{outlet}' entries are
     * derived generically in resolveDepartmentId() instead of listed
     * here one by one, so a future new outlet needs no change here as
     * long as its outlet code matches a departments.code (case-
     * insensitively, ignoring underscores — see resolveDepartmentId()).
     */
    private const SOURCE_MODULE_DEPARTMENT_CODES = [
        'front_office' => 'FO',
        'general_payment' => 'FO',
        'reservation_deposit' => 'FO',
        // Correction round (audited defect #8): a deposit refund is still
        // Front Office cash movement (the same department that took the
        // original deposit), so it's attributed and shift-gated the same
        // way — see ReservationService::disposeDeposit().
        'reservation_deposit_refund' => 'FO',
        'facility_access' => 'FO',
    ];

    /**
     * Maps an unambiguous `method` to its default `financial_accounts.code`
     * for auto-attribution when a caller doesn't specify one. Deliberately
     * has no 'mobile_money' entry — see this class's docblock.
     */
    private const METHOD_DEFAULT_ACCOUNT_CODES = [
        'cash' => 'CASH',
        'bank_transfer' => 'BANK',
        'card' => 'BANK',
    ];

    public function __construct(
        private readonly AccountingDayService $accountingDay,
        private readonly ShiftGateService $shiftGate,
    ) {
    }

    public function record(
        float $amount,
        string $method,
        string $sourceModule,
        User $recordedBy,
        ?string $sourceReference = null,
        ?Model $reference = null,
        ?int $guestId = null,
        string $direction = 'in',
        ?string $notes = null,
        ?int $departmentId = null,
        ?int $financialAccountId = null,
        ?string $idempotencyKey = null,
        // Direct request ("all in payment must be attached to an income
        // source that is to be represented ... in the income statement"):
        // only ever passed by PaymentController::store() today (the
        // general-purpose "record a payment" screen) — every other caller
        // already self-describes via `sourceModule`/`reference`, so this
        // is null for them, exactly like `notes` often is.
        ?string $incomeSource = null,
        // Direct request ("payments can involve discount if granted") —
        // informational only, never affects `$amount` (the real net cash
        // received) or any ledger total. See this column's migration.
        ?float $discountAmount = null,
        ?string $discountReason = null,
        // Direct request (Sept 2026 refinement of the revenue-recognition
        // fix): "the place in the payment form that request on who
        // handed the sales should either be the department or a textbox
        // that receive the name of the person handing." Purely
        // informational, independent of `$departmentId` — see
        // `payments.handed_over_by`'s migration for the full reasoning.
        ?string $handedOverBy = null,
    ): Payment {
        // Correction round (audited defect #32): a caller worried about a
        // retried submission (double-click, network retry on a deposit or
        // folio-payment form) passes a key unique to that one rendered
        // form/attempt. If a Payment with this exact key already exists,
        // return it unchanged rather than creating a duplicate — this
        // check runs BEFORE the accounting-day/shift gates below so a
        // retried request that's already succeeded never gets blocked by
        // a day/shift that has since closed. A caller that never passes a
        // key (the overwhelming majority, unchanged) is completely
        // unaffected, and a genuinely separate instalment simply uses its
        // own distinct key (or none at all).
        if ($idempotencyKey !== null) {
            $existing = Payment::where('idempotency_key', $idempotencyKey)->first();

            if ($existing) {
                return $existing;
            }
        }

        $this->accountingDay->assertOpen();

        // Correction round (audited defect #31): "require active financial
        // account for receipts/payouts including website mobile-money
        // deposits" — this is the single funnel every payment/refund/
        // payout in the system passes through, so it's the one place that
        // can guarantee this for every caller, not just the ones a
        // controller-level check happens to have been added to. A caller
        // that names a since-deactivated account explicitly is refused
        // here even if some earlier, less careful check let it through;
        // a caller that passes no account at all (financialAccountId is
        // optional — see this class's docblock) is unaffected, since there
        // is nothing to validate.
        if ($financialAccountId !== null && ! FinancialAccount::where('id', $financialAccountId)->where('is_active', true)->exists()) {
            throw new HmsException('The selected financial account is inactive and can no longer receive or pay out funds. Choose an active account.');
        }

        $departmentWasExplicit = $departmentId !== null;
        $resolvedDepartmentId = $this->resolveDepartmentId($sourceModule, $departmentId);

        // Only gate a department that was AUTO-derived from a guest-facing
        // sourceModule — never one an Expense/CashDisbursement/Payroll
        // caller passed explicitly for cost-attribution purposes. See this
        // class's docblock.
        if (! $departmentWasExplicit && $resolvedDepartmentId !== null) {
            $this->shiftGate->assertOpenForDepartment(Department::find($resolvedDepartmentId));
        }

        // Correction round (audited defect #30): business_date/shift_id
        // are now stamped explicitly, once, right here — never inferred
        // later by a report comparing recorded_at against a day/shift's
        // timestamp window (fragile right at a midnight or shift-change
        // boundary). The open shift for the resolved department (if any)
        // is looked up the same way ShiftGateService just asserted one is
        // open, so this is never null for a department that just passed
        // that gate; a payment with no resolved department (e.g. an
        // Expense/Payroll payout) simply gets no shift, same as today.
        $shiftId = $resolvedDepartmentId !== null
            ? Shift::where('department_id', $resolvedDepartmentId)->where('status', 'open')->value('id')
            : null;

        try {
            $payment = Payment::create([
                'payment_number' => $this->generateNumber(),
                'direction' => $direction,
                'amount' => abs($amount),
                'method' => $method,
                'financial_account_id' => $financialAccountId ?? $this->defaultFinancialAccountId($method),
                'source_module' => $sourceModule,
                'department_id' => $resolvedDepartmentId,
                'handed_over_by' => $handedOverBy,
                'source_reference' => $sourceReference,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'guest_id' => $guestId,
                'recorded_by' => $recordedBy->id,
                'recorded_at' => now(),
                'business_date' => $this->accountingDay->today()->business_date,
                'shift_id' => $shiftId,
                'idempotency_key' => $idempotencyKey,
                'notes' => $notes,
                'income_source' => $incomeSource,
                'discount_amount' => $discountAmount,
                'discount_reason' => $discountReason,
            ]);
        } catch (QueryException $e) {
            // Correction round (audited defect #32) — closing a genuine
            // TOCTOU race that the "does a Payment with this key already
            // exist?" check above cannot close by itself: that check and
            // this insert are two separate statements, so two requests
            // carrying the SAME idempotency key (a real double-click, or a
            // network retry that fires before the first response ever
            // came back) can both run the check above, both find nothing
            // yet, and both reach this insert — a single PHP process/
            // connection can never exercise this gap (see
            // tests/Feature/Concurrency/RealDatabaseConcurrencyTest.php),
            // but two genuinely separate DB connections racing at the
            // same instant can. The `payments.idempotency_key` unique
            // index (migration
            // 2025_02_01_000600_add_business_date_shift_and_idempotency_to_payments)
            // is the real backstop: exactly one of the two INSERTs can
            // succeed, and the loser lands here. Rather than let that
            // surface as a raw, uncaught 500 to whichever request lost
            // the race, it is treated exactly like the pre-existing-key
            // check above already treats it — return the row the winner
            // just committed. Nothing else has been written by this
            // method before this line (the department/shift resolution
            // above is pure computation; the reconciliation-staleness
            // update below depends on $payment, which is why this insert
            // is deliberately the FIRST write here), so there is nothing
            // to unwind on this path.
            if ($idempotencyKey !== null) {
                $existing = Payment::where('idempotency_key', $idempotencyKey)->first();

                if ($existing) {
                    return $existing;
                }
            }

            throw $e;
        }

        // Correction round (audited defect #29): a financial account's
        // reconciliation for today, if one already exists, was computed
        // against the balance BEFORE this payment — it's now stale the
        // instant this payment posts. Flagged here, immediately, rather
        // than left for someone to notice; AccountingDayService::lock()
        // refuses to close the day while any of today's reconciliations
        // are stale.
        $resolvedAccountId = $financialAccountId ?? $this->defaultFinancialAccountId($method);
        if ($resolvedAccountId !== null) {
            $this->flagReconciliationStale($resolvedAccountId, $payment->business_date, "Payment #{$payment->payment_number} posted to this account after this reconciliation was recorded.");
        }

        return $payment;
    }

    /**
     * Direct request ("the owner is suppose to be able to edit all
     * transaction[s]"): void-then-correct, never an in-place edit — see
     * this column's migration (2025_02_02_000600_add_void_columns_to_payments_table)
     * for the full reasoning. The row, its amount, and every other
     * original field are left completely untouched; only the three void
     * columns are set. Unlike `FolioTransaction::void()` (which both
     * marks the original AND posts a separate reversal transaction,
     * because a folio's balance/AR tracking needs an explicit ledger
     * entry to move), nothing else needs to be posted here: every
     * financial total that reads from this table computes itself live by
     * summing `payments.amount` (account balances, shift/cash/department
     * reconciliation, the Income Statement, the Payment Summary report —
     * see this method's own call sites, all updated alongside this
     * feature to add `whereNull('voided_at')`), so simply marking a row
     * voided removes it from every one of those sums automatically, with
     * nothing left to double-post or drift.
     *
     * Deliberately does NOT attempt to also revert the state of whatever
     * business record this payment came from (an Expense back to
     * 'approved', a closed POS Order reopened, a paid Payslip reverted) —
     * voiding here corrects the CASH/financial figures; if the underlying
     * record also needs reopening to be reprocessed, that is each
     * module's own concern and out of scope for this generic ledger-level
     * fix. Gated at the route/controller layer on the Owner-only
     * `void-transactions` permission — this service method itself does
     * not check permissions, consistent with every other service in this
     * class.
     */
    public function void(Payment $payment, string $reason, User $actor): Payment
    {
        return DB::transaction(function () use ($payment, $reason, $actor) {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isVoided()) {
                throw new HmsException('This transaction has already been voided.');
            }

            $locked->update([
                'voided_at' => now(),
                'voided_by' => $actor->id,
                'void_reason' => $reason,
            ]);

            if ($locked->financial_account_id !== null) {
                $this->flagReconciliationStale($locked->financial_account_id, $locked->business_date, "Payment #{$locked->payment_number} was voided after this reconciliation was recorded.");
            }

            return $locked->fresh();
        });
    }

    private function flagReconciliationStale(int $financialAccountId, $businessDate, string $reason): void
    {
        FinancialAccountReconciliation::where('financial_account_id', $financialAccountId)
            ->where('business_date', $businessDate)
            ->where('is_stale', false)
            ->update([
                'is_stale' => true,
                'invalidated_at' => now(),
                'invalidated_reason' => $reason,
            ]);
    }

    private function resolveDepartmentId(string $sourceModule, ?int $explicit): ?int
    {
        if ($explicit !== null) {
            return $explicit;
        }

        $code = self::SOURCE_MODULE_DEPARTMENT_CODES[$sourceModule] ?? null;

        if ($code === null && str_starts_with($sourceModule, 'pos_')) {
            $code = Department::codeForOutlet(substr($sourceModule, 4));
        }

        if ($code === null) {
            return null;
        }

        return Department::where('code', $code)->value('id');
    }

    /**
     * Auto-attributes an unambiguous method to its default financial
     * account (e.g. every cash sale reconciles against the one "Cash"
     * account without staff having to pick it every time). Returns null
     * — not an error — when there's no unambiguous default (mobile_money),
     * the matching seeded account doesn't exist (an older install that
     * hasn't run FinancialAccountSeeder yet), or — correction round,
     * audited defect #31 — that account has since been deactivated: an
     * auto-attributed payment must never silently land on a frozen/closed
     * account either, so a deactivated default is treated exactly like a
     * missing one (no attribution) rather than validated with an
     * exception, since the caller never explicitly chose it.
     */
    private function defaultFinancialAccountId(string $method): ?int
    {
        $code = self::METHOD_DEFAULT_ACCOUNT_CODES[$method] ?? null;

        if ($code === null) {
            return null;
        }

        return FinancialAccount::where('code', $code)->where('is_active', true)->value('id');
    }

    private function generateNumber(): string
    {
        return 'PAY-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));
    }
}
