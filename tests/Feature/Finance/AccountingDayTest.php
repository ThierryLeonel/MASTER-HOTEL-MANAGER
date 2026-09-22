<?php

namespace Tests\Feature\Finance;

use App\Models\AccountingDay;
use App\Models\CashReconciliation;
use App\Models\FinancialAccountReconciliation;
use App\Models\Folio;
use App\Models\Stay;
use App\Services\Finance\CashReconciliationService;
use App\Services\Finance\FinancialAccountService;
use App\Services\FrontOffice\FolioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class AccountingDayTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();

        // Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §21): a folio
        // payment now also requires an open Front Office shift — checked
        // AFTER the accounting-day lock, so the locked-day tests below are
        // unaffected either way, but the others need this.
        $this->openShiftFor('FO');
    }

    private function openFolio(): Folio
    {
        $stay = Stay::factory()->create(['status' => 'active']);

        return Folio::factory()->for($stay)->create(['status' => 'open', 'balance' => 0]);
    }

    /**
     * Fourth post-9.8 fix round (day-close rebuild, PHASE9_AUDIT_AND_PLAN.md
     * §21/§23): the day-lock gate no longer looks at the old whole-hotel,
     * cash-only CashReconciliation at all — it requires every ACTIVE
     * FinancialAccount reconciled for today instead (see
     * AccountingDayService::lock()'s own docblock for why: the old check
     * never touched Bank/MTN/Orange, which is exactly the "it is just
     * there" complaint this round fixes). Recording the legacy cash count
     * alone must no longer be enough to lock.
     */
    public function test_cannot_lock_the_day_before_every_active_account_is_reconciled(): void
    {
        $accountant = $this->userWithRole('accountant');
        $this->seedFinancialAccounts();

        $response = $this->actingAs($accountant)->post(route('finance.accounting-days.lock'));

        $response->assertSessionHasErrors('error');
        $this->assertSame('open', AccountingDay::first()?->status ?? 'open');
    }

    public function test_recording_only_the_legacy_cash_count_is_no_longer_enough_to_lock(): void
    {
        $cashier = $this->userWithRole('cashier');
        $accountant = $this->userWithRole('accountant');
        $this->seedFinancialAccounts();

        $this->actingAs($cashier)->post(route('finance.accounting-days.cash-reconciliation.store'), [
            'counted_amount' => 0,
        ])->assertRedirect();
        $this->assertDatabaseCount('cash_reconciliations', 1);

        $response = $this->actingAs($accountant)->post(route('finance.accounting-days.lock'));

        $response->assertSessionHasErrors('error');
        $this->assertSame('open', AccountingDay::first()?->status ?? 'open');
    }

    public function test_reconciling_every_active_account_then_lock_succeeds(): void
    {
        $accountant = $this->userWithRole('accountant');
        $this->reconcileAllActiveAccountsToday();

        $this->actingAs($accountant)->post(route('finance.accounting-days.lock'))->assertRedirect();

        $day = AccountingDay::first();
        $this->assertSame('locked', $day->status);
        $this->assertSame($accountant->id, $day->locked_by);
    }

    public function test_locking_is_blocked_while_even_one_active_account_is_unreconciled(): void
    {
        $accountant = $this->userWithRole('accountant');
        $this->seedFinancialAccounts();
        $service = app(FinancialAccountService::class);

        // Reconcile every account except the Bank one.
        foreach (\App\Models\FinancialAccount::where('is_active', true)->where('code', '!=', 'BANK')->get() as $account) {
            $service->reconcile($account, $service->currentBalance($account), $accountant);
        }

        $response = $this->actingAs($accountant)->post(route('finance.accounting-days.lock'));

        $response->assertSessionHasErrors('error');
        $this->assertSame('open', AccountingDay::first()?->status ?? 'open');
    }

    public function test_reconciling_the_same_account_twice_in_one_day_is_rejected(): void
    {
        $cash = $this->financialAccount('CASH');
        // 'record-cash-reconciliation' (not 'manage-financial-accounts')
        // is what gates this route — the cashier, same role that already
        // records the legacy whole-hotel cash count, not the accountant
        // (who instead holds 'lock-accounting-day' and reviews the
        // result). See AccountingDayController::reconcileAccount()'s
        // docblock for why this is a separate door from
        // FinancialAccountController::reconcile().
        $cashier = $this->userWithRole('cashier');

        $this->actingAs($cashier)->post(route('finance.accounting-days.reconcile-account', $cash), [
            'declared_balance' => 0,
        ])->assertRedirect();

        $response = $this->actingAs($cashier)->post(route('finance.accounting-days.reconcile-account', $cash), [
            'declared_balance' => 0,
        ]);

        $response->assertSessionHasErrors('error');
        $this->assertSame(1, FinancialAccountReconciliation::where('financial_account_id', $cash->id)->count());
    }

    public function test_duty_manager_cannot_lock_the_accounting_day(): void
    {
        $dutyManager = $this->userWithRole('duty_manager');

        $this->actingAs($dutyManager)->post(route('finance.accounting-days.cash-reconciliation.store'), [
            'counted_amount' => 0,
        ]);

        $this->actingAs($dutyManager)->post(route('finance.accounting-days.lock'))->assertForbidden();
    }

    public function test_a_locked_day_blocks_new_folio_payments(): void
    {
        $cashier = $this->userWithRole('cashier');
        $accountant = $this->userWithRole('accountant');
        $folio = $this->openFolio();

        $this->reconcileAllActiveAccountsToday();
        $this->actingAs($accountant)->post(route('finance.accounting-days.lock'));

        $response = $this->actingAs($cashier)->post(route('folios.payment', $folio), [
            'amount' => 5000,
            'financial_account_id' => $this->financialAccount('CASH')->id,
        ]);

        $response->assertSessionHasErrors('error');
        $this->assertSame('0.00', $folio->fresh()->balance);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_expected_cash_matches_what_the_ledger_actually_shows(): void
    {
        $cashier = $this->userWithRole('cashier');
        $folio = $this->openFolio();

        $this->actingAs($cashier)->post(route('folios.payment', $folio), [
            'amount' => 15000,
            'financial_account_id' => $this->financialAccount('CASH')->id,
        ]);
        // A non-cash payment must not affect the cash-drawer expectation.
        $this->actingAs($cashier)->post(route('folios.payment', $folio), [
            'amount' => 8000,
            'financial_account_id' => $this->financialAccount('MTN')->id,
        ]);

        $expected = app(CashReconciliationService::class)->expectedCashForToday();

        $this->assertSame(15000.0, $expected);

        $this->actingAs($cashier)->post(route('finance.accounting-days.cash-reconciliation.store'), [
            'counted_amount' => 15000,
        ]);

        $reconciliation = CashReconciliation::first();
        $this->assertSame('15000.00', $reconciliation->expected_amount);
        $this->assertSame('15000.00', $reconciliation->counted_amount);
        $this->assertSame('0.00', $reconciliation->variance);
    }

    /**
     * The rebuilt screen itself (task #124) — every active account
     * listed with its live balance and today's reconciled/outstanding
     * status, which is what makes the day-lock precondition legible to
     * staff before they even try to lock.
     */
    public function test_the_day_close_screen_lists_every_active_account_with_its_reconciliation_status(): void
    {
        $accountant = $this->userWithRole('accountant');
        $cash = $this->financialAccount('CASH');
        $this->seedFinancialAccounts();
        app(FinancialAccountService::class)->reconcile($cash, 0, $accountant);

        $response = $this->actingAs($accountant)->get(route('finance.accounting-days.index'));

        $response->assertOk();
        $response->assertSee('Cash');
        $response->assertSee('Bank');
        $response->assertSee('Reconciled today');
        $response->assertSee('Outstanding');
    }

    public function test_cash_reconciliation_cannot_be_recorded_twice_in_one_day(): void
    {
        $cashier = $this->userWithRole('cashier');

        $this->actingAs($cashier)->post(route('finance.accounting-days.cash-reconciliation.store'), [
            'counted_amount' => 0,
        ]);

        $response = $this->actingAs($cashier)->post(route('finance.accounting-days.cash-reconciliation.store'), [
            'counted_amount' => 100,
        ]);

        $response->assertSessionHasErrors('error');
        $this->assertDatabaseCount('cash_reconciliations', 1);
    }
}
