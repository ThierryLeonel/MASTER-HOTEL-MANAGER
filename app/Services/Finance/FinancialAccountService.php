<?php

namespace App\Services\Finance;

use App\Exceptions\HmsException;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountReconciliation;
use App\Models\Payment;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §21): "we need a
 * reconciled cash account, bank account, mtn mobile money, orange money
 * etc." See FinancialAccount's migration docblock for why
 * `opening_balance` exists, and PaymentLedgerService's docblock for how
 * a payment gets attributed to one of these accounts.
 */
class FinancialAccountService
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function create(array $data, User $actor): FinancialAccount
    {
        $account = FinancialAccount::create([
            'name' => $data['name'],
            'code' => strtoupper($data['code']),
            'type' => $data['type'],
            'opening_balance' => $data['opening_balance'] ?? 0,
            'is_active' => true,
        ]);

        $this->audit->log('finance.financial_account_created', 'finance', $account, null, $account->toArray(), actor: $actor);

        return $account;
    }

    /**
     * opening_balance + every payment ledgered against this account
     * (in - out) — the same "computed live from the ledger, never hand
     * entered" pattern ShiftService::expectedCashFor() already uses for
     * a department's till.
     */
    public function currentBalance(FinancialAccount $account): float
    {
        // Direct request ("the owner is suppose to be able to edit all
        // transaction[s]"): a voided payment (PaymentLedgerService::void())
        // must stop counting toward this account's balance the instant
        // it's voided — this is the one figure every other reconciliation
        // in the app is ultimately built on, so it's the most important
        // place this exclusion applies.
        $in = (float) Payment::where('financial_account_id', $account->id)->where('direction', 'in')->whereNull('voided_at')->sum('amount');
        $out = (float) Payment::where('financial_account_id', $account->id)->where('direction', 'out')->whereNull('voided_at')->sum('amount');

        return round((float) $account->opening_balance + $in - $out, 2);
    }

    /**
     * Fourth post-9.8 fix round (day-close rebuild, PHASE9_AUDIT_AND_PLAN.md
     * §21/§23): now day-scoped — `$businessDate` defaults to today (this
     * system never backdates a transaction, so "today" is the only
     * business date that's ever actually open; see AccountingDayService's
     * own docblock), and a second reconciliation of the SAME account on
     * the SAME day is refused rather than silently overwriting the first
     * one's place in history. This is what lets AccountingDayService::
     * lock() require "every active account reconciled today" as a real,
     * checkable precondition instead of the old whole-hotel, cash-only
     * check it used to gate on.
     *
     * `currentBalance()` is a live, all-time running balance — but since
     * nothing in this system is ever backdated, that running balance IS
     * the correct "expected balance as of the end of today" figure; no
     * separate day-filtered calculation is needed.
     */
    public function reconcile(FinancialAccount $account, float $declaredBalance, User $actor, ?string $notes = null, ?Carbon $businessDate = null): FinancialAccountReconciliation
    {
        $businessDate ??= Carbon::today();

        return DB::transaction(function () use ($account, $declaredBalance, $actor, $notes, $businessDate) {
            // Correction round (audited defect #29): a STALE reconciliation
            // (PaymentLedgerService::record() flags one stale the moment a
            // later payment posts to that account/day) must be reconcilable
            // again — otherwise a staff member could never clear the
            // staleness AccountingDayService::lock() now refuses to close
            // the day over. Only a still-fresh (non-stale) reconciliation
            // blocks a second one for the same account/day.
            if (FinancialAccountReconciliation::where('financial_account_id', $account->id)
                ->where('business_date', $businessDate->toDateString())
                ->where('is_stale', false)
                ->exists()) {
                throw new HmsException("{$account->name} has already been reconciled for {$businessDate->format('d M Y')}.");
            }

            $expected = $this->currentBalance($account);
            $variance = round($declaredBalance - $expected, 2);

            $reconciliation = FinancialAccountReconciliation::create([
                'financial_account_id' => $account->id,
                'business_date' => $businessDate->toDateString(),
                'reconciled_by' => $actor->id,
                'reconciled_at' => now(),
                'expected_balance' => $expected,
                'declared_balance' => $declaredBalance,
                'variance' => $variance,
                'notes' => $notes,
            ]);

            $this->audit->log('finance.financial_account_reconciled', 'finance', $reconciliation, null, $reconciliation->toArray(), actor: $actor);

            return $reconciliation;
        });
    }

    /**
     * Sixth post-9.8 fix round, follow-up ("the accountant should be able
     * to add, modify and edit real accounts") — `create()` and
     * `deactivate()` already existed; nothing let anyone fix a typo'd
     * name, correct a wrong `type`, or adjust the opening balance on an
     * account that already exists. `type` is safe to change freely here —
     * it's plain row data within the same fixed enum `create()` already
     * validates against, not a schema change, and `paymentMethod()`
     * (called fresh every time a payment posts) simply reflects whatever
     * the row currently says. Changing `opening_balance` is likewise
     * safe: `currentBalance()` always recomputes live as
     * opening_balance + ledgered payments, so a correction here applies
     * the same way a first-time entry would, with no double-counting.
     */
    public function update(FinancialAccount $account, array $data, User $actor): FinancialAccount
    {
        $oldValues = $account->only(['name', 'code', 'type', 'opening_balance']);

        $account->update([
            'name' => $data['name'],
            'code' => strtoupper($data['code']),
            'type' => $data['type'],
            'opening_balance' => $data['opening_balance'] ?? $account->opening_balance,
        ]);

        $this->audit->log(
            'finance.financial_account_updated',
            'finance',
            $account,
            oldValues: $oldValues,
            newValues: $account->fresh()->only(['name', 'code', 'type', 'opening_balance']),
            actor: $actor,
        );

        return $account->fresh();
    }

    /**
     * The way back from deactivate() — without this, deactivating an
     * account by mistake (or one that turns out to still be needed) was a
     * dead end with no undo short of editing the database directly.
     */
    public function reactivate(FinancialAccount $account, User $actor): FinancialAccount
    {
        if ($account->is_active) {
            throw new HmsException('This account is already active.');
        }

        $account->update(['is_active' => true]);

        $this->audit->log('finance.financial_account_reactivated', 'finance', $account, null, $account->toArray(), actor: $actor);

        return $account->fresh();
    }

    /**
     * Every account a day close needs reconciled today, and whether it
     * already has been — the exact precondition AccountingDayService::
     * lock() checks, exposed here so the Day Close screen can show staff
     * the same list before they even try to lock.
     *
     * @return \Illuminate\Support\Collection<int, FinancialAccount> active accounts, each with ->reconciledToday (bool) set
     */
    public function accountsWithTodayStatus(): \Illuminate\Support\Collection
    {
        $today = Carbon::today()->toDateString();
        $reconciledAccountIds = FinancialAccountReconciliation::where('business_date', $today)
            ->pluck('financial_account_id')
            ->all();

        return FinancialAccount::where('is_active', true)
            ->orderBy('name')
            ->get()
            ->each(fn (FinancialAccount $account) => $account->reconciledToday = in_array($account->id, $reconciledAccountIds, true));
    }

    public function deactivate(FinancialAccount $account, User $actor): FinancialAccount
    {
        if (! $account->is_active) {
            throw new HmsException('This account is already inactive.');
        }

        $account->update(['is_active' => false]);

        $this->audit->log('finance.financial_account_deactivated', 'finance', $account, null, $account->toArray(), actor: $actor);

        return $account->fresh();
    }
}
