<?php

namespace App\Services\Finance;

use App\Exceptions\HmsException;
use App\Models\AccountingDay;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountReconciliation;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The accounting-day open/lock model (spec section 17). This system
 * never backdates a transaction — every posting happens "now" — so
 * there is only ever one day that matters: today's. `today()` opens it
 * implicitly on first use; `assertOpen()` is the single enforcement
 * point, called from PaymentLedgerService::record() (and therefore
 * covers every folio payment/refund and every POS payment, since they
 * all already funnel through that one service).
 *
 * Scope note: the lock governs the central *payment* ledger — cash and
 * other money movement — not folio charges, stock movements, or other
 * non-financial workflow, which continue regardless of whether today's
 * day is locked. A hotel doesn't stop checking guests in because
 * yesterday's books closed; it stops accepting new cash postings
 * against a day whose reconciliation has already been signed off.
 *
 * Locking is one-way. There is deliberately no reopen(): a mistake
 * found after lock is fixed with a new, correcting transaction on the
 * next open day, never by reopening history (the same rule already
 * applied to folio_transactions/stock_movements/payments themselves).
 *
 * Correction round (audited defect #27, "corrections post to the correct
 * accounting period"): this one-way, no-reopen design is itself the fix —
 * there is no code path anywhere in this service (or PaymentLedgerService,
 * the single caller of assertOpen()) that can attach a NEW payment to a
 * business_date other than today()'s. A correction (FolioService::
 * correctPayment(), void(), a refund, a credit-settlement) always posts
 * "now", which is always either today's still-open day or, once locked,
 * refused outright until the next day opens — so a correction can never
 * land on an already-closed period by construction, not merely by
 * discipline. See assertOpen()'s exception message below for the same
 * guarantee surfaced to the user in the moment it would otherwise happen.
 */
class AccountingDayService
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function today(): AccountingDay
    {
        return $this->forDate(Carbon::today());
    }

    public function forDate(Carbon $date): AccountingDay
    {
        return AccountingDay::firstOrCreate(
            ['business_date' => $date->toDateString()],
            ['status' => 'open'],
        );
    }

    public function isTodayLocked(): bool
    {
        return $this->today()->isLocked();
    }

    /**
     * The single guard every payment posting must pass. Called from
     * PaymentLedgerService::record() so nothing needs to remember to
     * call it individually — the central ledger IS the enforcement
     * point.
     */
    public function assertOpen(): void
    {
        if ($this->isTodayLocked()) {
            throw new HmsException(
                "Today's accounting day is locked — no new payment can be posted until the next business day opens. Post a correcting transaction on the next open day instead of trying to backdate one."
            );
        }
    }

    /**
     * Fourth post-9.8 fix round (day-close rebuild, PHASE9_AUDIT_AND_PLAN.md
     * §21/§23): "fix the day close and cash reconciliation... it should
     * be able to capture and reconcile the payments... with the cash,
     * bank, mtn mobile money, orange money etc." — locking used to
     * require only the OLD, whole-hotel, cash-only CashReconciliation to
     * exist for today (CashReconciliationService/AccountingDayController's
     * original "Record today's cash reconciliation" form), which never
     * touched the Bank/MTN/Orange accounts at all — exactly the
     * disconnected, vestigial check the owner is describing as "it is
     * just there". The real gate now is FinancialAccountService's
     * per-account, per-day reconciliation (§21's Cash/Bank/MTN/Orange
     * account model, day-scoped by the business_date column added
     * alongside this fix): every currently ACTIVE financial account must
     * have been reconciled for today's business_date before the day can
     * lock. A newly-deactivated account is exempt (nothing left to
     * reconcile going forward), but it still keeps whatever reconciliation
     * history it already has.
     *
     * The old CashReconciliation table/service/form are deliberately left
     * fully intact — this project never deletes an existing feature or
     * its data, it just stops being the thing that gates anything (see
     * this codebase's other superseded-feature notes, e.g. the original
     * migration-safety fix). Staff can still record one for their own
     * historical record; it just no longer blocks or unblocks the lock.
     */
    public function lock(User $actor, ?string $notes = null): AccountingDay
    {
        $day = $this->today();

        if ($day->isLocked()) {
            throw new HmsException('Today\'s accounting day is already locked.');
        }

        $today = $day->business_date->toDateString();
        $reconciledAccountIds = FinancialAccountReconciliation::where('business_date', $today)
            ->pluck('financial_account_id');

        $unreconciled = FinancialAccount::where('is_active', true)
            ->whereNotIn('id', $reconciledAccountIds)
            ->pluck('name');

        if ($unreconciled->isNotEmpty()) {
            throw new HmsException(
                'Reconcile every active financial account before closing the day — still outstanding: '.$unreconciled->implode(', ').'.'
            );
        }

        // Correction round (audited defect #29): a reconciliation that a
        // later payment has since made stale (PaymentLedgerService::
        // record() flags this the moment it happens) is not a real,
        // trustworthy reconciliation any more even though a row exists for
        // today — it must be redone against the account's current balance
        // before the day can close. Grouped to each active account's MOST
        // RECENT reconciliation for today, not every historical row —
        // FinancialAccountService::reconcile() allows a fresh reconciliation
        // once a prior one for the same account/day has gone stale, which
        // leaves the old, now-superseded stale row in place for history;
        // only the latest one per account should ever gate the lock.
        $latestReconciliationIdsToday = FinancialAccountReconciliation::where('business_date', $today)
            ->selectRaw('MAX(id) as id')
            ->groupBy('financial_account_id')
            ->pluck('id');

        $staleAccounts = FinancialAccountReconciliation::whereIn('id', $latestReconciliationIdsToday)
            ->where('is_stale', true)
            ->with('financialAccount')
            ->get()
            ->pluck('financialAccount.name');

        if ($staleAccounts->isNotEmpty()) {
            throw new HmsException(
                'These accounts were reconciled today but a later payment has made that reconciliation stale — reconcile them again before closing the day: '.$staleAccounts->implode(', ').'.'
            );
        }

        return DB::transaction(function () use ($day, $actor, $notes) {
            $day->update(['status' => 'locked', 'locked_by' => $actor->id, 'locked_at' => now(), 'notes' => $notes]);

            $this->audit->log('finance.accounting_day_locked', 'finance', $day, null, $day->fresh()->toArray(), actor: $actor);

            return $day->fresh();
        });
    }
}
