<?php

namespace Tests\Feature\Hr;

use App\Models\AttendanceRecord;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class LeaveTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    private function annualLeaveType(int $maxDays = 18): LeaveType
    {
        return LeaveType::create([
            'name' => 'Annual Leave', 'code' => 'ANNUAL', 'is_paid' => true,
            'max_days_per_year' => $maxDays, 'requires_approval' => true, 'is_active' => true,
        ]);
    }

    public function test_an_accountant_can_request_leave_within_their_balance(): void
    {
        // Post-9.8 "view of HR" restriction: request-leave is now held
        // only by Owner, General Manager, and Accountant — see
        // RolesAndPermissionsSeeder. Accountant is used throughout this
        // file (rather than Owner/GM) since it's the one allowed role
        // that doesn't also hold every other permission in the system.
        $type = $this->annualLeaveType();
        $accountant = $this->userWithRole('accountant');

        $this->actingAs($accountant)->post(route('hr.leave-requests.store'), [
            'leave_type_id' => $type->id,
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
            'reason' => 'Family event',
        ])->assertRedirect();

        $leave = LeaveRequest::first();
        $this->assertSame('pending', $leave->status);
        $this->assertSame('3.0', $leave->days_requested);
    }

    public function test_requesting_more_days_than_the_balance_allows_is_rejected(): void
    {
        $type = $this->annualLeaveType(2);
        $accountant = $this->userWithRole('accountant');

        $response = $this->actingAs($accountant)->post(route('hr.leave-requests.store'), [
            'leave_type_id' => $type->id,
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(9)->toDateString(),
            'reason' => 'Too long',
        ]);

        $response->assertSessionHasErrors('error');
        $this->assertDatabaseCount('leave_requests', 0);
    }

    public function test_a_role_without_hr_access_cannot_request_leave(): void
    {
        // Front Desk (and, equally, Bar/Restaurant Staff, Cashier, Duty
        // Manager) held request-leave before the post-9.8 "view of HR"
        // restriction; none of them hold any HR permission now.
        $type = $this->annualLeaveType();
        $frontDesk = $this->pinUser('front_desk');

        $response = $this->actingAs($frontDesk)->post(route('hr.leave-requests.store'), [
            'leave_type_id' => $type->id,
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
            'reason' => 'Family event',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('leave_requests', 0);
    }

    public function test_approving_leave_deducts_balance_and_marks_attendance(): void
    {
        $type = $this->annualLeaveType();
        $accountant = $this->userWithRole('accountant');
        // approve-leave moved from Duty Manager to Owner/General Manager
        // only as part of the same "view of HR" restriction.
        $generalManager = $this->userWithRole('general_manager');

        $start = now()->addDays(5);
        $end = now()->addDays(7);

        $this->actingAs($accountant)->post(route('hr.leave-requests.store'), [
            'leave_type_id' => $type->id,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'reason' => 'Family event',
        ]);
        $leave = LeaveRequest::first();

        $this->actingAs($generalManager)->post(route('hr.leave-requests.approve', $leave))->assertRedirect();

        $leave->refresh();
        $this->assertSame('approved', $leave->status);

        $balance = LeaveBalance::where('user_id', $accountant->id)->where('leave_type_id', $type->id)->first();
        $this->assertSame('3.0', $balance->used_days);

        $this->assertSame(3, AttendanceRecord::where('user_id', $accountant->id)->where('status', 'on_leave')->count());
    }

    public function test_rejecting_leave_leaves_balance_and_attendance_untouched(): void
    {
        $type = $this->annualLeaveType();
        $accountant = $this->userWithRole('accountant');
        $generalManager = $this->userWithRole('general_manager');

        $this->actingAs($accountant)->post(route('hr.leave-requests.store'), [
            'leave_type_id' => $type->id,
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(6)->toDateString(),
            'reason' => 'Disputed',
        ]);
        $leave = LeaveRequest::first();

        $this->actingAs($generalManager)->post(route('hr.leave-requests.reject', $leave), [
            'reason' => 'Short-staffed that week',
        ])->assertRedirect();

        $this->assertSame('rejected', $leave->fresh()->status);
        $this->assertDatabaseCount('leave_balances', 0);
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_an_accountant_cannot_approve_their_own_leave_request(): void
    {
        // Accountant holds request-leave but not approve-leave (unlike
        // General Manager/Owner, who hold both — see
        // test_a_general_manager_cannot_approve_their_own_leave_request
        // below for the business-rule guard that applies to them
        // instead), so this is a plain route-level 403.
        $type = $this->annualLeaveType();
        $accountant = $this->userWithRole('accountant');

        $this->actingAs($accountant)->post(route('hr.leave-requests.store'), [
            'leave_type_id' => $type->id,
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(6)->toDateString(),
            'reason' => 'Family event',
        ]);
        $leave = LeaveRequest::first();

        $this->actingAs($accountant)->post(route('hr.leave-requests.approve', $leave))->assertForbidden();
    }

    public function test_requester_can_cancel_their_own_pending_request(): void
    {
        $type = $this->annualLeaveType();
        $accountant = $this->userWithRole('accountant');

        $this->actingAs($accountant)->post(route('hr.leave-requests.store'), [
            'leave_type_id' => $type->id,
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(6)->toDateString(),
            'reason' => 'Changed my mind',
        ]);
        $leave = LeaveRequest::first();

        $this->actingAs($accountant)->post(route('hr.leave-requests.cancel', $leave))->assertRedirect();

        $this->assertSame('cancelled', $leave->fresh()->status);
    }

    public function test_a_different_employee_cannot_cancel_someone_elses_leave_request(): void
    {
        // Security-audit fix (Phase 7): request-leave was granted to
        // every role as a self-service permission (narrowed post-9.8 to
        // Owner/GM/Accountant only), so before this fix any authenticated
        // user could cancel *any other* employee's pending leave request
        // just by posting to its ID — a textbook IDOR. Two Accountant
        // accounts are used here specifically to show the bug was not
        // about permission level at all — both hold the exact same
        // permission, so only an ownership check can distinguish them.
        $type = $this->annualLeaveType();
        $owner = $this->userWithRole('accountant');
        $bystander = $this->userWithRole('accountant');

        $this->actingAs($owner)->post(route('hr.leave-requests.store'), [
            'leave_type_id' => $type->id,
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(6)->toDateString(),
            'reason' => 'Personal',
        ]);
        $leave = LeaveRequest::first();

        $response = $this->actingAs($bystander)->post(route('hr.leave-requests.cancel', $leave));

        $response->assertSessionHasErrors('error');
        $this->assertSame('pending', $leave->fresh()->status);
    }

    public function test_a_manager_can_still_cancel_someone_elses_leave_request_on_their_behalf(): void
    {
        $type = $this->annualLeaveType();
        $accountant = $this->userWithRole('accountant');
        $generalManager = $this->userWithRole('general_manager');

        $this->actingAs($accountant)->post(route('hr.leave-requests.store'), [
            'leave_type_id' => $type->id,
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(6)->toDateString(),
            'reason' => 'Guest asked to withdraw it for them over the phone',
        ]);
        $leave = LeaveRequest::first();

        $this->actingAs($generalManager)->post(route('hr.leave-requests.cancel', $leave))->assertRedirect();

        $this->assertSame('cancelled', $leave->fresh()->status);
    }

    public function test_a_general_manager_cannot_approve_their_own_leave_request(): void
    {
        // Unlike Accountant (who lacks approve-leave entirely, covered by
        // test_an_accountant_cannot_approve_their_own_leave_request above
        // via a route-level 403), General Manager holds both
        // request-leave and approve-leave (as does Owner). The
        // security-audit fix in LeaveService::approve() is the only thing
        // stopping a single General Manager account from filing and then
        // approving its own leave request. This used to be tested against
        // Duty Manager, which held the same pair of permissions before
        // the post-9.8 "view of HR" restriction removed both from it.
        $type = $this->annualLeaveType();
        $generalManager = $this->userWithRole('general_manager');

        $this->actingAs($generalManager)->post(route('hr.leave-requests.store'), [
            'leave_type_id' => $type->id,
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(6)->toDateString(),
            'reason' => 'Family event',
        ]);
        $leave = LeaveRequest::first();

        $response = $this->actingAs($generalManager)->post(route('hr.leave-requests.approve', $leave));

        $response->assertSessionHasErrors('error');
        $this->assertSame('pending', $leave->fresh()->status);
    }

    public function test_duty_manager_can_no_longer_approve_leave(): void
    {
        // Duty Manager held approve-leave before the post-9.8 "view of
        // HR" restriction; it no longer holds any HR permission, so even
        // a leave request filed by someone else now 403s at the route
        // level for a Duty Manager trying to approve it.
        $type = $this->annualLeaveType();
        $accountant = $this->userWithRole('accountant');
        $dutyManager = $this->userWithRole('duty_manager');

        $this->actingAs($accountant)->post(route('hr.leave-requests.store'), [
            'leave_type_id' => $type->id,
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(6)->toDateString(),
            'reason' => 'Family event',
        ]);
        $leave = LeaveRequest::first();

        $response = $this->actingAs($dutyManager)->post(route('hr.leave-requests.approve', $leave));

        $response->assertForbidden();
    }

    /**
     * Broader request ("...edit, delete and update all categories and
     * every input, except financial inputs"): LeaveType had no edit/delete
     * at all before this round.
     */
    public function test_owner_can_update_a_leave_type(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $type = $this->annualLeaveType();

        $this->actingAs($owner)->put(route('hr.leave-types.update', $type), [
            'name' => 'Annual Leave (renamed)',
            'code' => $type->code,
            'max_days_per_year' => 20,
            'is_active' => '1',
        ])->assertRedirect();

        $type->refresh();
        $this->assertSame('Annual Leave (renamed)', $type->name);
        $this->assertSame(20, $type->max_days_per_year);
    }

    public function test_a_leave_type_with_request_history_cannot_be_deleted(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $type = $this->annualLeaveType();
        $accountant = $this->userWithRole('accountant');
        $this->actingAs($accountant)->post(route('hr.leave-requests.store'), [
            'leave_type_id' => $type->id,
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(6)->toDateString(),
            'reason' => 'History guard',
        ]);

        $response = $this->actingAs($owner)->delete(route('hr.leave-types.destroy', $type));

        $response->assertSessionHasErrors('error');
        $this->assertDatabaseHas('leave_types', ['id' => $type->id]);
    }

    public function test_an_unused_leave_type_can_be_deleted(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $type = $this->annualLeaveType();

        $this->actingAs($owner)->delete(route('hr.leave-types.destroy', $type))->assertRedirect();

        $this->assertDatabaseMissing('leave_types', ['id' => $type->id]);
    }

    public function test_unpaid_leave_type_is_not_balance_tracked(): void
    {
        $unpaid = LeaveType::create([
            'name' => 'Unpaid Leave', 'code' => 'UNPAID', 'is_paid' => false,
            'max_days_per_year' => 0, 'requires_approval' => true, 'is_active' => true,
        ]);
        $accountant = $this->userWithRole('accountant');

        // Far more days than any normal balance would allow — must not
        // be blocked, since max_days_per_year = 0 means untracked.
        $this->actingAs($accountant)->post(route('hr.leave-requests.store'), [
            'leave_type_id' => $unpaid->id,
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(35)->toDateString(),
            'reason' => 'Extended unpaid leave',
        ])->assertRedirect();

        $this->assertDatabaseCount('leave_requests', 1);
    }
}
