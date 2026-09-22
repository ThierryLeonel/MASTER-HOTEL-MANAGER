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
            FinancialAccountReconciliation::where('financial_account_id', $resolvedAccountId)
                ->where('business_date', $payment->business_date)
                ->where('is_stale', false)
                ->update([
                    'is_stale' => true,
                    'invalidated_at' => now(),
                    'invalidated_reason' => "Payment #{$payment->payment_number} posted to this account after this reconciliation was recorded.",
                ]);
        }

        return $payment;
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
