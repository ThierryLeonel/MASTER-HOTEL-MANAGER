<?php

namespace Tests\Feature\Finance;

use App\Models\Folio;
use App\Models\Stay;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountReconciliation;
use App\Services\Finance\FinancialAccountService;
use App\Services\FrontOffice\FolioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §21) — "we need a
 * reconciled cash account, bank account, mtn mobile money, orange money
 * etc." The clarifying answer picked "the accountant enters the real
 * balance from the statement/app," which is exactly what reconcile()
 * records here (expected, computed live from the ledger, vs declared,
 * typed in by a human) — never the other way around.
 */
class FinancialAccountTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    private function openFolio(): Folio
    {
        $stay = Stay::factory()->create(['status' => 'active']);

        return Folio::factory()->for($stay)->create(['status' => 'open', 'balance' => 0]);
    }

    public function test_accountant_can_create_a_new_financial_account(): void
    {
        $accountant = $this->userWithRole('accountant');

        $this->actingAs($accountant)->post(route('finance.financial-accounts.store'), [
            'name' => 'Second Bank Account',
            'code' => 'BANK2',
            'type' => 'bank',
            'opening_balance' => 100000,
        ])->assertRedirect();

        $account = FinancialAccount::where('code', 'BANK2')->first();
        $this->assertNotNull($account);
        $this->assertSame('Second Bank Account', $account->name);
        $this->assertTrue($account->is_active);
        $this->assertSame('100000.00', $account->opening_balance);
    }

    /**
     * Sixth post-9.8 fix round ("the accountant, manager, should have the
     * right to create new account and or modify account"): Duty Manager
     * used to be deliberately excluded from `manage-financial-accounts`
     * (see the git history of this exact test) — that decision was
     * overridden by direct project-owner instruction, so Duty Manager now
     * holds the same account create/edit/deactivate/reconcile rights as
     * Accountant, General Manager, and Owner.
     */
    public function test_duty_manager_can_now_create_a_financial_account(): void
    {
        $dutyManager = $this->userWithRole('duty_manager');

        $this->actingAs($dutyManager)->post(route('finance.financial-accounts.store'), [
            'name' => 'Second Bank Account',
            'code' => 'BANK2',
            'type' => 'bank',
        ])->assertRedirect();

        $this->assertSame(1, FinancialAccount::where('code', 'BANK2')->count());
    }

    /**
     * A role genuinely without the permission (front line staff, never
     * granted it in any round) is still forbidden — the real negative
     * control now that Duty Manager itself is no longer it.
     */
    public function test_a_role_without_manage_financial_accounts_is_forbidden(): void
    {
        $frontDesk = $this->pinUser('front_desk');

        $this->actingAs($frontDesk)->post(route('finance.financial-accounts.store'), [
            'name' => 'Should Not Be Created',
            'code' => 'NOPE',
            'type' => 'cash',
        ])->assertForbidden();

        $this->assertSame(0, FinancialAccount::where('code', 'NOPE')->count());
    }

    public function test_a_duplicate_account_code_is_rejected(): void
    {
        $accountant = $this->userWithRole('accountant');
        $this->seedFinancialAccounts();

        // Same case as the already-seeded 'CASH' account — an exact
        // string match is guaranteed to violate the unique rule
        // regardless of the underlying database's collation.
        $response = $this->actingAs($accountant)->post(route('finance.financial-accounts.store'), [
            'name' => 'Another Cash Drawer',
            'code' => 'CASH',
            'type' => 'cash',
        ]);

        $response->assertSessionHasErrors('code');
        $this->assertSame(1, FinancialAccount::where('code', 'CASH')->count());
    }

    /**
     * The core of "a reconciled cash account" — currentBalance() is
     * computed live from opening_balance plus every payment ledgered
     * against the account (in minus out), never hand-maintained. A cash
     * folio payment auto-attributes to the seeded Cash account with no
     * caller having to say so explicitly (PaymentLedgerService's
     * METHOD_DEFAULT_ACCOUNT_CODES).
     */
    public function test_current_balance_reflects_opening_balance_plus_every_ledgered_payment(): void
    {
        $this->openShiftFor('FO');
        $cash = $this->financialAccount('CASH');
        $cash->update(['opening_balance' => 50000]);

        $frontDesk = $this->userWithRole('front_desk');
        $folio = $this->openFolio();
        app(FolioService::class)->postPayment($folio, 20000, 'cash', $frontDesk);

        $balance = app(FinancialAccountService::class)->currentBalance($cash->fresh());

        $this->assertSame(70000.0, $balance);
    }

    public function test_accountant_reconciles_an_account_and_the_variance_is_computed(): void
    {
        $accountant = $this->userWithRole('accountant');
        $cash = $this->financialAccount('CASH');
        $cash->update(['opening_balance' => 10000]);

        $this->actingAs($accountant)->post(route('finance.financial-accounts.reconcile', $cash), [
            'declared_balance' => 9500,
            'notes' => 'Counted the drawer at close of business.',
        ])->assertRedirect();

        $reconciliation = FinancialAccountReconciliation::first();
        $this->assertSame($cash->id, $reconciliation->financial_account_id);
        $this->assertSame($accountant->id, $reconciliation->reconciled_by);
        $this->assertSame('10000.00', $reconciliation->expected_balance);
        $this->assertSame('9500.00', $reconciliation->declared_balance);
        $this->assertSame('-500.00', $reconciliation->variance);
    }

    /**
     * Sixth post-9.8 fix round, follow-up ("the accountant should be able
     * to add, modify and edit real accounts") — create() and deactivate()
     * already existed; nothing let anyone actually edit an account after
     * creating it. This is the real gap the follow-up request pointed at.
     */
    public function test_accountant_can_edit_an_existing_financial_account(): void
    {
        $accountant = $this->userWithRole('accountant');
        $cash = $this->financialAccount('CASH');

        $this->actingAs($accountant)->put(route('finance.financial-accounts.update', $cash), [
            'name' => 'Front Desk Cash Drawer',
            'code' => 'CASH',
            'type' => 'cash',
            'opening_balance' => 25000,
        ])->assertRedirect();

        $cash->refresh();
        $this->assertSame('Front Desk Cash Drawer', $cash->name);
        $this->assertSame('25000.00', $cash->opening_balance);
    }

    public function test_duty_manager_can_edit_an_existing_financial_account_too(): void
    {
        $dutyManager = $this->userWithRole('duty_manager');
        $cash = $this->financialAccount('CASH');

        $this->actingAs($dutyManager)->put(route('finance.financial-accounts.update', $cash), [
            'name' => 'Cash',
            'code' => 'CASH',
            'type' => 'cash',
            'opening_balance' => 0,
        ])->assertRedirect();
    }

    public function test_editing_an_account_into_another_accounts_code_is_rejected(): void
    {
        $accountant = $this->userWithRole('accountant');
        $this->seedFinancialAccounts();
        $bank = $this->financialAccount('BANK');

        $response = $this->actingAs($accountant)->put(route('finance.financial-accounts.update', $bank), [
            'name' => 'Bank',
            'code' => 'CASH', // already used by the seeded Cash account
            'type' => 'bank',
        ]);

        $response->assertSessionHasErrors('code');
        $this->assertSame('BANK', $bank->fresh()->code);
    }

    public function test_a_role_without_manage_financial_accounts_cannot_edit_one(): void
    {
        $frontDesk = $this->pinUser('front_desk');
        $cash = $this->financialAccount('CASH');

        $this->actingAs($frontDesk)->put(route('finance.financial-accounts.update', $cash), [
            'name' => 'Should not work',
            'code' => 'CASH',
            'type' => 'cash',
        ])->assertForbidden();

        $this->assertSame('Cash', $cash->fresh()->name);
    }

    /**
     * The way back from deactivate(), which used to be a dead end.
     */
    public function test_a_deactivated_account_can_be_reactivated(): void
    {
        $accountant = $this->userWithRole('accountant');
        $cash = $this->financialAccount('CASH');
        $this->actingAs($accountant)->post(route('finance.financial-accounts.deactivate', $cash));
        $this->assertFalse($cash->fresh()->is_active);

        $this->actingAs($accountant)->post(route('finance.financial-accounts.activate', $cash))->assertRedirect();

        $this->assertTrue($cash->fresh()->is_active);
    }

    public function test_deactivating_an_already_inactive_account_is_rejected(): void
    {
        $accountant = $this->userWithRole('accountant');
        $cash = $this->financialAccount('CASH');

        $this->actingAs($accountant)->post(route('finance.financial-accounts.deactivate', $cash))->assertRedirect();
        $this->assertFalse($cash->fresh()->is_active);

        $response = $this->actingAs($accountant)->post(route('finance.financial-accounts.deactivate', $cash));

        $response->assertSessionHasErrors('error');
    }
}
