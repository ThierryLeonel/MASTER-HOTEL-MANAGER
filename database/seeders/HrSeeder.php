<?php

namespace Database\Seeders;

use App\Models\EmployeeProfile;
use App\Models\LeaveType;
use App\Models\PayrollDeductionType;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo HR/Payroll data (spec section 40: safe staging data only, no
 * fabricated financial history): an employee profile for every demo
 * user seeded by UserSeeder (so the payroll run demo has real
 * payroll-eligible employees out of the box), two leave types, and two
 * statutory deduction types — one flat-percent, one progressive-bracket
 * — so both calculation paths in PayrollDeductionService have
 * something real to exercise immediately.
 */
class HrSeeder extends Seeder
{
    public function run(): void
    {
        $profiles = [
            ['email' => 'owner@hms.test', 'job_title' => 'Owner', 'basic_salary' => 800000],
            ['email' => 'gm@hms.test', 'job_title' => 'General Manager', 'basic_salary' => 600000],
            ['email' => 'duty@hms.test', 'job_title' => 'Duty Manager', 'basic_salary' => 350000],
            ['email' => 'accountant@hms.test', 'job_title' => 'Accountant', 'basic_salary' => 380000],
            ['email' => 'cashier1@hms.test', 'job_title' => 'Cashier', 'basic_salary' => 150000],
            ['email' => 'frontdesk1@hms.test', 'job_title' => 'Front Desk Agent', 'basic_salary' => 150000],
            ['email' => 'bar1@hms.test', 'job_title' => 'Bartender', 'basic_salary' => 140000],
            ['email' => 'rest1@hms.test', 'job_title' => 'Restaurant Server', 'basic_salary' => 140000],
        ];

        foreach ($profiles as $i => $p) {
            $user = User::where('email', $p['email'])->first();
            if (! $user) {
                continue;
            }

            EmployeeProfile::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'employee_number' => sprintf('EMP-%04d', $i + 1),
                    'job_title' => $p['job_title'],
                    'employment_type' => 'full_time',
                    'hire_date' => now()->subYear()->toDateString(),
                    'basic_salary' => $p['basic_salary'],
                    'payment_method' => 'bank_transfer',
                    'employment_status' => 'active',
                ]
            );
        }

        $leaveTypes = [
            ['name' => 'Annual Leave', 'code' => 'ANNUAL', 'is_paid' => true, 'max_days_per_year' => 18, 'requires_approval' => true],
            ['name' => 'Sick Leave', 'code' => 'SICK', 'is_paid' => true, 'max_days_per_year' => 10, 'requires_approval' => true],
            ['name' => 'Unpaid Leave', 'code' => 'UNPAID', 'is_paid' => false, 'max_days_per_year' => 0, 'requires_approval' => true],
        ];
        foreach ($leaveTypes as $type) {
            LeaveType::firstOrCreate(['code' => $type['code']], $type);
        }

        $cnps = PayrollDeductionType::firstOrCreate(
            ['code' => 'CNPS'],
            ['name' => 'Social Security (CNPS)', 'calculation_method' => 'flat_percent', 'flat_rate_percent' => 4.2, 'display_order' => 1],
        );

        $irpp = PayrollDeductionType::firstOrCreate(
            ['code' => 'IRPP'],
            ['name' => 'Income Tax (IRPP)', 'calculation_method' => 'progressive_brackets', 'display_order' => 2],
        );
        if ($irpp->brackets()->count() === 0) {
            $irpp->brackets()->createMany([
                ['min_amount' => 0, 'max_amount' => 200000, 'rate_percent' => 0, 'display_order' => 1],
                ['min_amount' => 200000, 'max_amount' => 500000, 'rate_percent' => 10, 'display_order' => 2],
                ['min_amount' => 500000, 'max_amount' => null, 'rate_percent' => 20, 'display_order' => 3],
            ]);
        }
    }
}
