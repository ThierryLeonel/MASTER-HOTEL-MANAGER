<?php

namespace Tests\Feature\Hr;

use App\Models\AttendanceRecord;
use App\Models\EmployeeProfile;
use App\Models\PayrollDeductionType;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Services\Finance\AccountingDayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class PayrollTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    private function periodStart(): Carbon
    {
        return Carbon::today()->startOfMonth();
    }

    private function periodEnd(): Carbon
    {
        return Carbon::today()->startOfMonth()->addDays(9);
    }

    private function eligibleEmployee(float $basicSalary = 260000): \App\Models\User
    {
        $user = $this->userWithRole('front_desk');
        EmployeeProfile::create([
            'user_id' => $user->id,
            'employee_number' => 'EMP-'.$user->id,
            'job_title' => 'Front Desk Agent',
            'employment_type' => 'full_time',
            'hire_date' => now()->subYear()->toDateString(),
            'basic_salary' => $basicSalary,
            'payment_method' => 'bank_transfer',
            'employment_status' => 'active',
        ]);

        return $user;
    }

    private function flatDeduction(float $ratePercent = 10): PayrollDeductionType
    {
        return PayrollDeductionType::create([
            'name' => 'Social Security', 'code' => 'SS', 'calculation_method' => 'flat_percent',
            'flat_rate_percent' => $ratePercent, 'is_active' => true, 'display_order' => 1,
        ]);
    }

    private function createRun($actor): PayrollRun
    {
        $this->actingAs($actor)->post(route('hr.payroll-runs.store'), [
            'period_start' => $this->periodStart()->toDateString(),
            'period_end' => $this->periodEnd()->toDateString(),
        ]);

        return PayrollRun::first();
    }

    public function test_generating_payslips_computes_gross_and_net_correctly(): void
    {
        $accountant = $this->userWithRole('accountant');
        $employee = $this->eligibleEmployee(260000);
        $this->flatDeduction(10);

        // 26 working days per month (seeded default) => daily rate 10000;
        // 2 absent days => 20000 absence deduction.
        AttendanceRecord::create(['user_id' => $employee->id, 'work_date' => $this->periodStart()->toDateString(), 'status' => 'absent']);
        AttendanceRecord::create(['user_id' => $employee->id, 'work_date' => $this->periodStart()->addDay()->toDateString(), 'status' => 'absent']);

        $run = $this->createRun($accountant);
        $this->actingAs($accountant)->post(route('hr.payroll-runs.generate', $run))->assertRedirect();

        $payslip = Payslip::where('payroll_run_id', $run->id)->where('user_id', $employee->id)->first();

        $this->assertSame('260000.00', $payslip->basic_salary);
        $this->assertSame('20000.00', $payslip->absence_deduction);
        $this->assertSame('240000.00', $payslip->gross_pay);
        $this->assertSame('24000.00', $payslip->total_statutory_deductions);
        $this->assertSame('216000.00', $payslip->net_pay);
        $this->assertSame('draft', $payslip->status);
    }

    public function test_regenerating_a_draft_run_replaces_its_payslips(): void
    {
        $accountant = $this->userWithRole('accountant');
        $employee = $this->eligibleEmployee(260000);

        $run = $this->createRun($accountant);
        $this->actingAs($accountant)->post(route('hr.payroll-runs.generate', $run));
        $this->assertSame(1, Payslip::where('payroll_run_id', $run->id)->count());

        // Add an absence after the first generation, then regenerate.
        AttendanceRecord::create(['user_id' => $employee->id, 'work_date' => $this->periodStart()->toDateString(), 'status' => 'absent']);
        $this->actingAs($accountant)->post(route('hr.payroll-runs.generate', $run));

        $this->assertSame(1, Payslip::where('payroll_run_id', $run->id)->count(), 'Regenerating must replace, not duplicate, payslips.');
        $payslip = Payslip::where('payroll_run_id', $run->id)->first();
        $this->assertNotSame('0.00', $payslip->absence_deduction);
    }

    public function test_approving_a_run_locks_it_against_further_regeneration(): void
    {
        $accountant = $this->userWithRole('accountant');
        $owner = $this->userWithRole('owner');
        $this->eligibleEmployee();

        $run = $this->createRun($accountant);
        $this->actingAs($accountant)->post(route('hr.payroll-runs.generate', $run));
        $this->actingAs($owner)->post(route('hr.payroll-runs.approve', $run))->assertRedirect();

        $this->assertSame('approved', $run->fresh()->status);
        $this->assertSame('finalized', Payslip::where('payroll_run_id', $run->id)->first()->status);

        $response = $this->actingAs($accountant)->post(route('hr.payroll-runs.generate', $run));
        $response->assertSessionHasErrors('error');
    }

    public function test_accountant_can_generate_and_pay_but_not_approve(): void
    {
        $accountant = $this->userWithRole('accountant');
        $this->eligibleEmployee();

        $run = $this->createRun($accountant);
        $this->actingAs($accountant)->post(route('hr.payroll-runs.generate', $run));

        $this->actingAs($accountant)->post(route('hr.payroll-runs.approve', $run))->assertForbidden();
    }

    public function test_duty_manager_cannot_create_a_payroll_run(): void
    {
        $dutyManager = $this->userWithRole('duty_manager');

        $response = $this->actingAs($dutyManager)->post(route('hr.payroll-runs.store'), [
            'period_start' => $this->periodStart()->toDateString(),
            'period_end' => $this->periodEnd()->toDateString(),
        ]);

        $response->assertForbidden();
    }

    public function test_paying_an_approved_run_posts_to_the_central_ledger(): void
    {
        $accountant = $this->userWithRole('accountant');
        $owner = $this->userWithRole('owner');
        $employee = $this->eligibleEmployee(260000);

        $run = $this->createRun($accountant);
        $this->actingAs($accountant)->post(route('hr.payroll-runs.generate', $run));
        $this->actingAs($owner)->post(route('hr.payroll-runs.approve', $run));

        $this->actingAs($accountant)->post(route('hr.payroll-runs.pay', $run), ['financial_account_id' => $this->financialAccount('BANK')->id])->assertRedirect();

        $this->assertSame('paid', $run->fresh()->status);
        $payslip = Payslip::where('payroll_run_id', $run->id)->where('user_id', $employee->id)->first();
        $this->assertSame('paid', $payslip->status);

        $this->assertDatabaseHas('payments', [
            'source_module' => 'payroll',
            'direction' => 'out',
            'amount' => $payslip->net_pay,
            'method' => 'bank_transfer',
        ]);
    }

    /**
     * Fourth post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §20, "money not
     * double recorded"): PayrollService::pay() used to check the run's
     * status BEFORE its DB::transaction(), against an unlocked instance,
     * and never re-checked it inside — a double form submission or a
     * retried click racing against the same approved run could post
     * every payslip's net pay to the ledger twice. This can't fully
     * exercise the race itself in a single-threaded test, but it proves
     * the fix's actual guarantee: once a run is paid, calling pay() again
     * (exactly what a retried request would do) fails cleanly and posts
     * nothing further — the same "cannot pay twice" outcome
     * ExpenseService::markPaid()'s identical concurrency fix already
     * guarantees for a single expense.
     */
    public function test_an_already_paid_run_cannot_be_paid_again_and_posts_no_duplicate_payment(): void
    {
        $accountant = $this->userWithRole('accountant');
        $owner = $this->userWithRole('owner');
        $this->eligibleEmployee(260000);

        $run = $this->createRun($accountant);
        $this->actingAs($accountant)->post(route('hr.payroll-runs.generate', $run));
        $this->actingAs($owner)->post(route('hr.payroll-runs.approve', $run));

        app(\App\Services\Hr\PayrollService::class)->pay($run->fresh(), $accountant, 'bank_transfer');
        $this->assertSame(1, \App\Models\Payment::where('source_module', 'payroll')->count());

        $threw = false;
        try {
            app(\App\Services\Hr\PayrollService::class)->pay($run->fresh(), $accountant, 'bank_transfer');
        } catch (\App\Exceptions\HmsException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Paying an already-paid run must throw, not silently succeed.');
        $this->assertSame(1, \App\Models\Payment::where('source_module', 'payroll')->count(), 'A second pay() call must not post a duplicate payment.');
    }

    public function test_a_locked_accounting_day_blocks_paying_a_payroll_run(): void
    {
        $accountant = $this->userWithRole('accountant');
        $owner = $this->userWithRole('owner');
        $this->eligibleEmployee();

        $run = $this->createRun($accountant);
        $this->actingAs($accountant)->post(route('hr.payroll-runs.generate', $run));
        $this->actingAs($owner)->post(route('hr.payroll-runs.approve', $run));

        // Fourth post-9.8 fix round (day-close rebuild): the day-lock
        // gate no longer looks at the legacy whole-hotel CashReconciliation
        // at all — see AccountingDayService::lock()'s own docblock.
        $this->reconcileAllActiveAccountsToday();
        app(AccountingDayService::class)->lock($owner);

        $response = $this->actingAs($accountant)->post(route('hr.payroll-runs.pay', $run), ['financial_account_id' => $this->financialAccount('BANK')->id]);

        $response->assertSessionHasErrors('error');
        $this->assertSame('approved', $run->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_generating_with_no_eligible_employees_fails_cleanly(): void
    {
        $accountant = $this->userWithRole('accountant');
        $run = $this->createRun($accountant);

        $response = $this->actingAs($accountant)->post(route('hr.payroll-runs.generate', $run));

        $response->assertSessionHasErrors('error');
        $this->assertDatabaseCount('payslips', 0);
    }

    /**
     * Broader request ("...edit, delete and update all categories and
     * every input, except financial inputs"): the deduction TYPE/bracket
     * are rate configuration, not a financial transaction — payslip_lines
     * snapshots its own label+amount at generation time with no foreign
     * key back to payroll_deduction_types (see that migration's docblock),
     * so editing/deleting a type here can never rewrite an already-
     * generated payslip.
     */
    public function test_owner_can_update_a_deduction_type(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $type = $this->flatDeduction(10);

        $this->actingAs($owner)->put(route('hr.payroll-deductions.update', $type), [
            'name' => 'Social Security (renamed)',
            'code' => $type->code,
            'calculation_method' => 'flat_percent',
            'flat_rate_percent' => 12,
            'is_active' => '1',
        ])->assertRedirect();

        $type->refresh();
        $this->assertSame('Social Security (renamed)', $type->name);
        $this->assertSame('12.00', $type->flat_rate_percent);
    }

    public function test_owner_can_delete_a_deduction_type_and_its_brackets(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $type = \App\Models\PayrollDeductionType::create([
            'name' => 'Progressive Tax', 'code' => 'PROG', 'calculation_method' => 'progressive_brackets',
            'is_active' => true, 'display_order' => 2,
        ]);
        $bracket = $type->brackets()->create(['min_amount' => 0, 'max_amount' => 100000, 'rate_percent' => 5]);

        $this->actingAs($owner)->delete(route('hr.payroll-deductions.destroy', $type))->assertRedirect();

        $this->assertDatabaseMissing('payroll_deduction_types', ['id' => $type->id]);
        $this->assertDatabaseMissing('payroll_deduction_brackets', ['id' => $bracket->id]);
    }

    public function test_owner_can_update_a_bracket(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $type = \App\Models\PayrollDeductionType::create([
            'name' => 'Progressive Tax', 'code' => 'PROG2', 'calculation_method' => 'progressive_brackets',
            'is_active' => true, 'display_order' => 3,
        ]);
        $bracket = $type->brackets()->create(['min_amount' => 0, 'max_amount' => 100000, 'rate_percent' => 5]);

        $this->actingAs($owner)->put(route('hr.payroll-deductions.brackets.update', $bracket), [
            'min_amount' => 0, 'max_amount' => 150000, 'rate_percent' => 7,
        ])->assertRedirect();

        $this->assertSame('7.00', $bracket->fresh()->rate_percent);
    }

    public function test_a_role_without_manage_employees_cannot_edit_deduction_types(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $type = $this->flatDeduction();

        $this->actingAs($frontDesk)->put(route('hr.payroll-deductions.update', $type), [
            'name' => 'Hacked', 'code' => $type->code, 'calculation_method' => 'flat_percent', 'flat_rate_percent' => 1,
        ])->assertForbidden();
    }
}
