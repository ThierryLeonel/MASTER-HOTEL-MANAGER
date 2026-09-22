<?php

namespace Tests\Feature\Reporting;

use App\Models\AttendanceRecord;
use App\Models\EmployeeProfile;
use App\Models\PayrollDeductionType;
use App\Services\Hr\PayrollService;
use App\Services\Reporting\HrReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class HrReportTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    public function test_payroll_cost_only_counts_payslips_actually_paid_in_range(): void
    {
        $accountant = $this->userWithRole('accountant');
        $owner = $this->userWithRole('owner');
        $employee = $this->userWithRole('front_desk');
        EmployeeProfile::create([
            'user_id' => $employee->id, 'employee_number' => 'EMP-9', 'job_title' => 'Agent',
            'employment_type' => 'full_time', 'hire_date' => now()->subYear(), 'basic_salary' => 200000,
            'payment_method' => 'bank_transfer', 'employment_status' => 'active',
        ]);
        PayrollDeductionType::create([
            'name' => 'Social Security', 'code' => 'SS', 'calculation_method' => 'flat_percent',
            'flat_rate_percent' => 10, 'is_active' => true, 'display_order' => 1,
        ]);

        $payroll = app(PayrollService::class);
        $run = $payroll->createRun(Carbon::today()->startOfMonth(), Carbon::today(), $accountant);
        $payroll->generate($run, $accountant);
        // Still draft/approved — must not count as cost yet.
        $reportBeforePay = app(HrReportService::class)->payrollCost(Carbon::today()->startOfMonth(), Carbon::today());
        $this->assertSame(0.0, $reportBeforePay['net']);

        $payroll->approve($run, $owner);
        $payroll->pay($run, $accountant, 'bank_transfer');

        $report = app(HrReportService::class)->payrollCost(Carbon::today()->startOfMonth(), Carbon::today());

        $this->assertSame(1, $report['count']);
        $this->assertSame(200000.0, $report['gross']);
        $this->assertGreaterThan(0.0, $report['net']);
        $this->assertLessThan($report['gross'], $report['net']);
    }

    public function test_attendance_summary_counts_each_status_per_employee(): void
    {
        $employee = $this->userWithRole('front_desk');
        AttendanceRecord::create(['user_id' => $employee->id, 'work_date' => Carbon::today()->toDateString(), 'status' => 'present']);
        AttendanceRecord::create(['user_id' => $employee->id, 'work_date' => Carbon::today()->subDay()->toDateString(), 'status' => 'absent']);

        $summary = app(HrReportService::class)->attendanceSummary(Carbon::today()->subDay(), Carbon::today());
        $row = $summary->firstWhere('user.id', $employee->id);

        $this->assertSame(1, $row['present']);
        $this->assertSame(1, $row['absent']);
    }

    public function test_front_desk_cannot_view_hr_reports(): void
    {
        $frontDesk = $this->userWithRole('front_desk');

        $this->actingAs($frontDesk)->get(route('reports.hr'))->assertForbidden();
    }

    public function test_accountant_can_view_hr_reports(): void
    {
        $accountant = $this->userWithRole('accountant');

        $this->actingAs($accountant)->get(route('reports.hr'))->assertOk();
    }
}
