<?php

namespace Tests\Feature\Finance;

use App\Models\Expense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The seeded default `expense_approval_threshold` is 50000 XAF
 * (database/seeders/SettingsSeeder.php, from .env's
 * HMS_EXPENSE_APPROVAL_THRESHOLD) — these tests use 20000 as a clearly
 * below-threshold amount and 60000 as a clearly at/above-threshold one
 * rather than testing exactly at the boundary, since the boundary
 * value itself is a configurable setting, not a fact about the code.
 */
class ExpenseTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    public function test_duty_manager_can_approve_a_small_expense(): void
    {
        // Two distinct Duty Manager accounts: the security-audit fix in
        // ExpenseService::approve() blocks the same account from both
        // requesting and approving its own expense (see
        // test_a_duty_manager_cannot_approve_their_own_expense_request
        // below), so this test now uses a second approver to exercise the
        // "small expense needs only approve-expense, not
        // approve-large-expense" rule in isolation.
        $requester = $this->userWithRole('duty_manager');
        $approver = $this->userWithRole('duty_manager');

        $this->actingAs($requester)->post(route('finance.expenses.store'), [
            'category' => 'Supplies',
            'description' => 'Cleaning supplies restock',
            'amount' => 20000,
        ])->assertRedirect();

        $expense = Expense::first();
        $this->assertSame('pending', $expense->status);

        $this->actingAs($approver)->post(route('finance.expenses.approve', $expense))->assertRedirect();

        $this->assertSame('approved', $expense->fresh()->status);
        $this->assertSame($approver->id, $expense->fresh()->approved_by);
    }

    public function test_a_duty_manager_cannot_approve_their_own_expense_request(): void
    {
        $dutyManager = $this->userWithRole('duty_manager');

        $this->actingAs($dutyManager)->post(route('finance.expenses.store'), [
            'category' => 'Supplies',
            'description' => 'Cleaning supplies restock',
            'amount' => 20000,
        ]);
        $expense = Expense::first();

        $response = $this->actingAs($dutyManager)->post(route('finance.expenses.approve', $expense));

        $response->assertSessionHasErrors('error');
        $this->assertSame('pending', $expense->fresh()->status, 'The same account that requested an expense must not be able to approve it, even below the large-expense threshold.');
    }

    public function test_duty_manager_cannot_approve_an_expense_at_or_above_the_threshold(): void
    {
        $dutyManager = $this->userWithRole('duty_manager');

        $this->actingAs($dutyManager)->post(route('finance.expenses.store'), [
            'category' => 'Equipment',
            'description' => 'Replacement compressor',
            'amount' => 60000,
        ]);
        $expense = Expense::first();

        $response = $this->actingAs($dutyManager)->post(route('finance.expenses.approve', $expense));

        $response->assertSessionHasErrors('error');
        $this->assertSame('pending', $expense->fresh()->status, 'A large expense must not be approved by someone lacking approve-large-expense.');
    }

    public function test_accountant_can_approve_a_large_expense(): void
    {
        $dutyManager = $this->userWithRole('duty_manager');
        $accountant = $this->userWithRole('accountant');

        $this->actingAs($dutyManager)->post(route('finance.expenses.store'), [
            'category' => 'Equipment',
            'description' => 'Replacement compressor',
            'amount' => 60000,
        ]);
        $expense = Expense::first();

        $this->actingAs($accountant)->post(route('finance.expenses.approve', $expense))->assertRedirect();

        $this->assertSame('approved', $expense->fresh()->status);
    }

    public function test_marking_an_approved_expense_paid_posts_to_the_central_ledger(): void
    {
        $dutyManager = $this->userWithRole('duty_manager');
        $approver = $this->userWithRole('duty_manager');

        $this->actingAs($dutyManager)->post(route('finance.expenses.store'), [
            'category' => 'Supplies',
            'description' => 'Cleaning supplies restock',
            'amount' => 20000,
        ]);
        $expense = Expense::first();
        $this->actingAs($approver)->post(route('finance.expenses.approve', $expense));

        $this->actingAs($dutyManager)->post(route('finance.expenses.pay', $expense), [
            'financial_account_id' => $this->financialAccount('CASH')->id,
        ])->assertRedirect();

        $expense->refresh();
        $this->assertSame('paid', $expense->status);
        $this->assertSame($dutyManager->id, $expense->paid_by);
        $this->assertDatabaseHas('payments', [
            'source_module' => 'finance_expense',
            'source_reference' => $expense->expense_number,
            'direction' => 'out',
            'method' => 'cash',
            'amount' => '20000.00',
        ]);
    }

    /**
     * Fourth post-9.8 fix round (day-close rebuild, PHASE9_AUDIT_AND_PLAN.md
     * §21/§23): the seeded Bank account's `type` is 'bank', which is NOT
     * a valid value in the `payments.method` enum — only 'bank_transfer'
     * is. Before FinancialAccount::paymentMethod() existed,
     * ExpenseService::markPaid() passed `$financialAccount->type`
     * straight into the ledger post, so paying ANY expense from the Bank
     * account threw a hard database error, every time, in production.
     * This is the direct end-to-end regression test for that fix.
     */
    public function test_marking_an_approved_expense_paid_from_the_bank_account_posts_bank_transfer_not_bank(): void
    {
        $dutyManager = $this->userWithRole('duty_manager');
        $approver = $this->userWithRole('duty_manager');

        $this->actingAs($dutyManager)->post(route('finance.expenses.store'), [
            'category' => 'Equipment',
            'description' => 'New water pump',
            'amount' => 45000,
        ]);
        $expense = Expense::first();
        $this->actingAs($approver)->post(route('finance.expenses.approve', $expense));

        $this->actingAs($dutyManager)->post(route('finance.expenses.pay', $expense), [
            'financial_account_id' => $this->financialAccount('BANK')->id,
        ])->assertRedirect();

        $expense->refresh();
        $this->assertSame('paid', $expense->status);
        $this->assertSame('bank_transfer', $expense->payment_method, 'The Bank account\'s type ("bank") must never be written directly — only the translated "bank_transfer" is a valid payment_method value.');
        $this->assertDatabaseHas('payments', [
            'source_module' => 'finance_expense',
            'source_reference' => $expense->expense_number,
            'direction' => 'out',
            'method' => 'bank_transfer',
            'amount' => '45000.00',
        ]);
    }

    /**
     * Follow-up round ("the accountant is should be able to make expense
     * entries too, disbursement entries, payment entries") — the
     * accountant could already approve/pay an expense but never raise
     * one; 'manage-expenses' was added to close that gap.
     */
    public function test_accountant_can_request_an_expense(): void
    {
        $accountant = $this->userWithRole('accountant');

        $this->actingAs($accountant)->post(route('finance.expenses.store'), [
            'category' => 'Supplies',
            'description' => 'Printer paper and toner',
            'amount' => 18000,
        ])->assertRedirect();

        $expense = Expense::first();
        $this->assertNotNull($expense);
        $this->assertSame('pending', $expense->status);
        $this->assertSame($accountant->id, $expense->requested_by);
    }

    /**
     * The self-approval guard (ExpenseService::approve()) is per-user, not
     * per-role — an accountant who raises an expense still cannot approve
     * that same one themselves, exactly like a Duty Manager cannot.
     */
    public function test_an_accountant_still_cannot_approve_their_own_expense_request(): void
    {
        $accountant = $this->userWithRole('accountant');

        $this->actingAs($accountant)->post(route('finance.expenses.store'), [
            'category' => 'Supplies',
            'description' => 'Printer paper and toner',
            'amount' => 18000,
        ]);
        $expense = Expense::first();

        $response = $this->actingAs($accountant)->post(route('finance.expenses.approve', $expense));

        $response->assertSessionHasErrors('error');
        $this->assertSame('pending', $expense->fresh()->status);
    }

    public function test_a_pending_expense_cannot_be_marked_paid(): void
    {
        $dutyManager = $this->userWithRole('duty_manager');

        $this->actingAs($dutyManager)->post(route('finance.expenses.store'), [
            'category' => 'Supplies',
            'description' => 'Cleaning supplies restock',
            'amount' => 20000,
        ]);
        $expense = Expense::first();

        $response = $this->actingAs($dutyManager)->post(route('finance.expenses.pay', $expense), [
            'financial_account_id' => $this->financialAccount('CASH')->id,
        ]);

        $response->assertSessionHasErrors('error');
        $this->assertSame('pending', $expense->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_rejecting_an_expense_requires_a_reason_and_leaves_it_unpaid(): void
    {
        $dutyManager = $this->userWithRole('duty_manager');

        $this->actingAs($dutyManager)->post(route('finance.expenses.store'), [
            'category' => 'Supplies',
            'description' => 'Non-essential purchase',
            'amount' => 15000,
        ]);
        $expense = Expense::first();

        $this->actingAs($dutyManager)->post(route('finance.expenses.reject', $expense), [
            'reason' => 'Not budgeted this month',
        ])->assertRedirect();

        $this->assertSame('rejected', $expense->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_bar_staff_cannot_request_an_expense(): void
    {
        $barStaff = $this->pinUser('bar_staff');

        $response = $this->actingAs($barStaff)->post(route('finance.expenses.store'), [
            'category' => 'Supplies',
            'description' => 'Not authorized',
            'amount' => 5000,
        ]);

        $response->assertForbidden();
    }
}
