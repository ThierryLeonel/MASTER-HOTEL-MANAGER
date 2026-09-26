<?php

namespace Tests\Feature\Finance;

use App\Services\Finance\FinancialAccountService;
use App\Services\PaymentLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Direct request ("the owner is suppose to be able to edit all
 * transaction[s]"): never an in-place edit — see
 * PaymentLedgerService::void()'s docblock. Owner-only 'void-transactions'
 * permission, one void mechanism covering every kind of transaction since
 * they all flow through the single `payments` ledger.
 */
class TransactionVoidTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
        // 'general_payment' auto-attributes to Front Office, which
        // requires an open shift (PHASE9_AUDIT_AND_PLAN.md §21) — same
        // setup as PaymentModuleTest.
        $this->openShiftFor('FO');
    }

    public function test_owner_can_void_a_payment_and_it_stops_counting_toward_the_account_balance(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $account = $this->financialAccount('CASH');

        $payment = app(PaymentLedgerService::class)->record(
            amount: 20000,
            method: 'cash',
            sourceModule: 'general_payment',
            recordedBy: $owner,
            financialAccountId: $account->id,
            incomeSource: 'Rent',
        );

        $this->assertSame(20000.0, app(FinancialAccountService::class)->currentBalance($account));

        $response = $this->actingAs($owner)->post(route('finance.transactions.void', $payment), [
            'reason' => 'Recorded against the wrong account by mistake',
        ]);

        $response->assertRedirect();
        $payment->refresh();
        $this->assertTrue($payment->isVoided());
        $this->assertSame($owner->id, $payment->voided_by);
        $this->assertSame('Recorded against the wrong account by mistake', $payment->void_reason);
        // The original row is untouched — amount, direction, everything
        // else is exactly as it was posted.
        $this->assertSame('20000.00', $payment->amount);

        $this->assertSame(0.0, app(FinancialAccountService::class)->currentBalance($account), 'A voided payment must stop counting toward the account balance.');
    }

    public function test_a_payment_cannot_be_voided_twice(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $account = $this->financialAccount('CASH');

        $payment = app(PaymentLedgerService::class)->record(
            amount: 5000,
            method: 'cash',
            sourceModule: 'general_payment',
            recordedBy: $owner,
            financialAccountId: $account->id,
            incomeSource: 'Rent',
        );

        $this->actingAs($owner)->post(route('finance.transactions.void', $payment), ['reason' => 'First void']);

        $response = $this->actingAs($owner)->post(route('finance.transactions.void', $payment), ['reason' => 'Second attempt']);

        $response->assertSessionHasErrors('error');
    }

    public function test_voiding_a_payment_requires_a_reason(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $payment = app(PaymentLedgerService::class)->record(
            amount: 5000,
            method: 'cash',
            sourceModule: 'general_payment',
            recordedBy: $owner,
            financialAccountId: $this->financialAccount('CASH')->id,
            incomeSource: 'Rent',
        );

        $response = $this->actingAs($owner)->post(route('finance.transactions.void', $payment), []);

        $response->assertSessionHasErrors('reason');
        $this->assertFalse($payment->fresh()->isVoided());
    }

    /** @dataProvider rolesWithoutVoidTransactions */
    public function test_roles_without_void_transactions_permission_cannot_reach_the_screen(string $role): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $payment = app(PaymentLedgerService::class)->record(
            amount: 1000,
            method: 'cash',
            sourceModule: 'general_payment',
            recordedBy: $owner,
            financialAccountId: $this->financialAccount('CASH')->id,
            incomeSource: 'Rent',
        );

        $user = $this->userWithRole($role, ['auth_type' => 'password_only']);

        $this->actingAs($user)->get(route('finance.transactions.index'))->assertForbidden();
        $this->actingAs($user)->post(route('finance.transactions.void', $payment), ['reason' => 'x'])->assertForbidden();
    }

    public static function rolesWithoutVoidTransactions(): array
    {
        // General Manager deliberately included here: it holds
        // 'manage-users'-style broad access but 'void-transactions', like
        // 'delete-users'/'manage-system-data'/'edit-room-details', is
        // Owner-only.
        return [['general_manager'], ['accountant'], ['duty_manager'], ['cashier']];
    }
}
