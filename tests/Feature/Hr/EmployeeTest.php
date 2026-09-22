<?php

namespace Tests\Feature\Hr;

use App\Models\EmployeeProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class EmployeeTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    public function test_owner_can_onboard_a_staff_member(): void
    {
        $owner = $this->userWithRole('owner');
        $staff = $this->userWithRole('front_desk');

        $this->actingAs($owner)->post(route('hr.employees.store'), [
            'user_id' => $staff->id,
            'job_title' => 'Front Desk Agent',
            'employment_type' => 'full_time',
            'hire_date' => now()->subMonths(2)->toDateString(),
            'basic_salary' => 150000,
            'payment_method' => 'bank_transfer',
        ])->assertRedirect();

        $profile = EmployeeProfile::where('user_id', $staff->id)->first();
        $this->assertNotNull($profile);
        $this->assertSame('active', $profile->employment_status);
        $this->assertNotEmpty($profile->employee_number);
    }

    public function test_resubmitting_for_the_same_user_updates_the_existing_profile_rather_than_duplicating_it(): void
    {
        $owner = $this->userWithRole('owner');
        $staff = $this->userWithRole('front_desk');

        $payload = [
            'user_id' => $staff->id,
            'job_title' => 'Front Desk Agent',
            'employment_type' => 'full_time',
            'hire_date' => now()->subMonths(2)->toDateString(),
            'basic_salary' => 150000,
            'payment_method' => 'bank_transfer',
        ];

        $this->actingAs($owner)->post(route('hr.employees.store'), $payload);
        $this->actingAs($owner)->post(route('hr.employees.store'), array_merge($payload, ['basic_salary' => 175000]));

        $this->assertDatabaseCount('employee_profiles', 1);
        $this->assertSame('175000.00', EmployeeProfile::first()->basic_salary);
    }

    public function test_terminating_an_employee_deactivates_their_login(): void
    {
        $owner = $this->userWithRole('owner');
        $staff = $this->userWithRole('front_desk');

        $this->actingAs($owner)->post(route('hr.employees.store'), [
            'user_id' => $staff->id,
            'job_title' => 'Front Desk Agent',
            'employment_type' => 'full_time',
            'hire_date' => now()->subMonths(2)->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $profile = EmployeeProfile::first();

        $this->actingAs($owner)->post(route('hr.employees.terminate', $profile))->assertRedirect();

        $profile->refresh();
        $this->assertSame('terminated', $profile->employment_status);
        $this->assertNotNull($profile->termination_date);
        $this->assertFalse($staff->fresh()->is_active);
    }

    /**
     * Direct request ("I want the accountant to be able to ... have
     * access to employee, so as to create employee"): the accountant
     * already held HR-adjacent permissions (manage-payroll, view-hr-
     * reports) but not 'manage-employees' itself, so onboarding a new
     * staff member was Owner/General-Manager-only until now.
     */
    public function test_accountant_can_now_onboard_a_staff_member(): void
    {
        $accountant = $this->userWithRole('accountant');
        $staff = $this->userWithRole('front_desk');

        $this->actingAs($accountant)->post(route('hr.employees.store'), [
            'user_id' => $staff->id,
            'job_title' => 'Front Desk Agent',
            'employment_type' => 'full_time',
            'hire_date' => now()->subMonths(2)->toDateString(),
            'basic_salary' => 150000,
            'payment_method' => 'bank_transfer',
        ])->assertRedirect();

        $profile = EmployeeProfile::where('user_id', $staff->id)->first();
        $this->assertNotNull($profile);
        $this->assertSame('active', $profile->employment_status);
    }

    public function test_duty_manager_cannot_manage_employee_profiles(): void
    {
        $dutyManager = $this->userWithRole('duty_manager');
        $staff = $this->userWithRole('front_desk');

        $response = $this->actingAs($dutyManager)->post(route('hr.employees.store'), [
            'user_id' => $staff->id,
            'job_title' => 'Front Desk Agent',
            'employment_type' => 'full_time',
            'hire_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        $response->assertForbidden();
    }
}
