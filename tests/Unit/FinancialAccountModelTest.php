<?php

namespace Tests\Unit;

use App\Models\FinancialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fourth post-9.8 fix round (day-close rebuild, PHASE9_AUDIT_AND_PLAN.md
 * §21/§23): `financial_accounts.type` and `payments.method` /
 * `cash_disbursements.method` are two different enums that happen to
 * overlap for cash/mobile_money/card — but the seeded "Bank" account's
 * type is 'bank', which is NOT a valid value in either payments column
 * (only 'bank_transfer' is). Every call site that used to pass
 * `$financialAccount->type` straight into a ledger post or a
 * disbursement threw a hard database error for the Bank account,
 * specifically — a genuine, previously-shipped production bug found
 * while investigating the owner's day-close report. paymentMethod() is
 * the fix; this test is the direct regression guard for it.
 */
class FinancialAccountModelTest extends TestCase
{
    use RefreshDatabase;

    private function makeAccount(string $type, string $code): FinancialAccount
    {
        return FinancialAccount::create([
            'name' => ucfirst($type).' Test Account',
            'code' => $code,
            'type' => $type,
            'opening_balance' => 0,
            'is_active' => true,
        ]);
    }

    public function test_bank_type_translates_to_bank_transfer(): void
    {
        $account = $this->makeAccount('bank', 'TESTBANK');

        $this->assertSame('bank_transfer', $account->paymentMethod());
    }

    public function test_every_other_type_passes_through_unchanged(): void
    {
        foreach (['cash', 'mobile_money', 'card'] as $type) {
            $account = $this->makeAccount($type, 'TEST_'.strtoupper($type));

            $this->assertSame($type, $account->paymentMethod());
        }
    }
}
