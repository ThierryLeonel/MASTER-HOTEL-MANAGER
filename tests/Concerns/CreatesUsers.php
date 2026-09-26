<?php

namespace Tests\Concerns;

use App\Models\Department;
use App\Models\FinancialAccount;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\FinancialAccountSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;

/**
 * Shared helpers for tests that need a fully-permissioned user of a given
 * role without re-deriving the whole seed data set each time.
 */
trait CreatesUsers
{
    protected function seedRolesAndSettings(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    /**
     * Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §21): Front
     * Office/Bar/Restaurant/Bar Outside/Bucareaux now refuse any
     * transaction without an open shift for that department — every test
     * that exercises one of those transactions needs a real, open shift
     * first. Seeds `departments` (idempotent — `firstOrCreate`, safe to
     * call even if a test already seeded it) and opens a shift for the
     * given `departments.code`, using an Owner actor by default since
     * Owner always holds `manage-own-shift`.
     */
    protected function openShiftFor(string $departmentCode): Shift
    {
        $this->seed(DepartmentSeeder::class);

        $department = Department::where('code', $departmentCode)->firstOrFail();
        $actor = User::factory()->create();
        $actor->assignRole('owner');

        // Opening cash is now a required declared float (see
        // ShiftService::open()'s docblock) — 0 here since these tests are
        // about the transactions posted during the shift, not reconciling
        // a real starting drawer amount.
        return app(\App\Services\Finance\ShiftService::class)->open($department, $actor, 0.0);
    }

    /**
     * Third post-9.8 fix round: seeds the four starter financial accounts
     * (Cash/Bank/MTN Mobile Money/Orange Money) for tests that exercise
     * Expense::markPaid()/CashDisbursementService::disburse() (both now
     * require a `financial_account_id`) or that check payment auto-
     * attribution. Idempotent — safe to call more than once.
     */
    protected function seedFinancialAccounts(): void
    {
        $this->seed(FinancialAccountSeeder::class);
    }

    protected function financialAccount(string $code): FinancialAccount
    {
        $this->seedFinancialAccounts();

        return FinancialAccount::where('code', $code)->firstOrFail();
    }

    /**
     * Fourth post-9.8 fix round (day-close rebuild, PHASE9_AUDIT_AND_PLAN.md
     * §21/§23): AccountingDayService::lock() now requires every active
     * FinancialAccount reconciled for today's business_date (replacing
     * the old whole-hotel, cash-only CashReconciliation gate) — any test
     * that needs to actually lock the day calls this first to satisfy
     * that precondition without each test having to know every seeded
     * account by name. Declares each account's balance as exactly what
     * the ledger already expects (zero variance) unless the caller
     * doesn't care about variance for that test.
     */
    protected function reconcileAllActiveAccountsToday(): void
    {
        $this->seedFinancialAccounts();
        $actor = User::factory()->create();
        $actor->assignRole('owner');

        $service = app(\App\Services\Finance\FinancialAccountService::class);

        foreach (FinancialAccount::where('is_active', true)->get() as $account) {
            $service->reconcile($account, $service->currentBalance($account), $actor);
        }
    }

    protected function userWithRole(string $role, array $attributes = []): User
    {
        /** @var User $user */
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    protected function pinUser(string $role, string $pin = '1234'): User
    {
        return $this->userWithRole($role, [
            'auth_type' => 'pin',
            'password' => null,
            'pin_hash' => bcrypt($pin),
        ]);
    }

    protected function mfaUser(string $role): User
    {
        return $this->userWithRole($role, [
            'auth_type' => 'password_mfa',
        ]);
    }
}
