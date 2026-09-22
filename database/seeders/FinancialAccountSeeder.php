<?php

namespace Database\Seeders;

use App\Models\FinancialAccount;
use Illuminate\Database\Seeder;

/**
 * Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §21): "we need a
 * reconciled cash account, bank account, mtn mobile money, orange money
 * etc." This starter set is NOT the whole list forever — the owner can
 * add more (e.g. a second bank account) from the Financial Accounts
 * screen at any time, exactly like DepartmentSeeder's list already
 * works. All opening balances start at 0 — a real starting balance is
 * something the owner types in once, from the Financial Accounts screen,
 * not fabricated here (spec section 40).
 */
class FinancialAccountSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            ['name' => 'Cash', 'code' => 'CASH', 'type' => 'cash'],
            ['name' => 'Bank', 'code' => 'BANK', 'type' => 'bank'],
            ['name' => 'MTN Mobile Money', 'code' => 'MTN', 'type' => 'mobile_money'],
            ['name' => 'Orange Money', 'code' => 'ORANGE', 'type' => 'mobile_money'],
        ];

        foreach ($accounts as $account) {
            FinancialAccount::firstOrCreate(['code' => $account['code']], $account + ['opening_balance' => 0, 'is_active' => true]);
        }
    }
}
