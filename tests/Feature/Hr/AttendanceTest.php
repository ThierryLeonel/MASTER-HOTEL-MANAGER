<?php

namespace Tests\Feature\Hr;

use App\Models\AttendanceRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    public function test_an_accountant_can_clock_in_and_out(): void
    {
        // Post-9.8 "view of HR" restriction: clock-own-attendance is now
        // held only by Owner, General Manager, and Accountant — see
        // RolesAndPermissionsSeeder. Accountant is used here (rather than
        // Owner/GM) since it's the one allowed role that doesn't also
        // hold every other permission in the system.
        $accountant = $this->userWithRole('accountant');

        $this->actingAs($accountant)->post(route('hr.attendance.clock-in'))->assertRedirect();

        $record = AttendanceRecord::first();
        $this->assertSame($accountant->id, $record->user_id);
        $this->assertNotNull($record->clock_in_at);
        $this->assertNull($record->clock_out_at);
        $this->assertSame('present', $record->status);

        $this->actingAs($accountant)->post(route('hr.attendance.clock-out'))->assertRedirect();

        $this->assertNotNull($record->fresh()->clock_out_at);
    }

    public function test_cannot_clock_in_twice_in_one_day(): void
    {
        $accountant = $this->userWithRole('accountant');

        $this->actingAs($accountant)->post(route('hr.attendance.clock-in'));
        $response = $this->actingAs($accountant)->post(route('hr.attendance.clock-in'));

        $response->assertSessionHasErrors('error');
        $this->assertDatabaseCount('attendance_records', 1);
    }

    public function test_cannot_clock_out_without_clocking_in(): void
    {
        $accountant = $this->userWithRole('accountant');

        $response = $this->actingAs($accountant)->post(route('hr.attendance.clock-out'));

        $response->assertSessionHasErrors('error');
    }

    public function test_a_role_without_hr_access_cannot_clock_in(): void
    {
        // Post-9.8 "view of HR" restriction: Bar/Restaurant Staff, Front
        // Desk, Cashier, and Duty Manager no longer hold
        // clock-own-attendance at all, so this now 403s at the route
        // level instead of succeeding.
        $bar = $this->pinUser('bar_staff');

        $this->actingAs($bar)->post(route('hr.attendance.clock-in'))->assertForbidden();

        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_a_general_manager_can_manually_record_attendance_for_another_staff_member(): void
    {
        // record-attendance moved from Duty Manager to Owner/General
        // Manager only as part of the same "view of HR" restriction.
        $generalManager = $this->userWithRole('general_manager');
        $bar = $this->pinUser('bar_staff');

        $this->actingAs($generalManager)->post(route('hr.attendance.manual.store'), [
            'user_id' => $bar->id,
            'work_date' => now()->toDateString(),
            'status' => 'absent',
            'notes' => 'No call, no show',
        ])->assertRedirect();

        $record = AttendanceRecord::where('user_id', $bar->id)->first();
        $this->assertSame('absent', $record->status);
        $this->assertSame($generalManager->id, $record->recorded_by);
    }

    public function test_duty_manager_can_no_longer_manually_record_attendance(): void
    {
        // Duty Manager held record-attendance before the post-9.8 "view
        // of HR" restriction; it no longer holds any HR permission.
        $dutyManager = $this->userWithRole('duty_manager');
        $bar = $this->pinUser('bar_staff');

        $response = $this->actingAs($dutyManager)->post(route('hr.attendance.manual.store'), [
            'user_id' => $bar->id,
            'work_date' => now()->toDateString(),
            'status' => 'absent',
        ]);

        $response->assertForbidden();
    }

    public function test_bar_staff_cannot_manually_record_attendance_for_someone_else(): void
    {
        $bar = $this->pinUser('bar_staff');
        $rest = $this->pinUser('restaurant_staff');

        $response = $this->actingAs($bar)->post(route('hr.attendance.manual.store'), [
            'user_id' => $rest->id,
            'work_date' => now()->toDateString(),
            'status' => 'absent',
        ]);

        $response->assertForbidden();
    }

    public function test_a_role_without_hr_access_cannot_view_the_attendance_page(): void
    {
        // Post-9.8: the attendance page itself is gated on
        // clock-own-attendance|record-attendance, neither of which
        // Bar/Restaurant Staff, Front Desk, Cashier, or Duty Manager hold
        // anymore.
        $bar = $this->pinUser('bar_staff');

        $response = $this->actingAs($bar)->get(route('hr.attendance.index'));

        $response->assertForbidden();
    }

    public function test_an_accountant_cannot_see_another_employees_attendance_on_the_shared_roster(): void
    {
        // Security-audit fix (Phase 7), actor updated post-9.8: Accountant
        // now stands in for "holds clock-own-attendance but not
        // record-attendance" (Bar/Restaurant Staff no longer reach this
        // page at all — see the forbidden test above). The full-roster
        // query must still only be shown to a record-attendance holder;
        // an Accountant's own "My attendance today" card is all they get.
        $accountant = $this->userWithRole('accountant');
        $rest = $this->pinUser('restaurant_staff');
        AttendanceRecord::create(['user_id' => $rest->id, 'work_date' => now()->toDateString(), 'status' => 'present']);

        $response = $this->actingAs($accountant)->get(route('hr.attendance.index'));

        $response->assertOk();
        $response->assertDontSee($rest->name);
    }

    public function test_a_general_manager_can_see_the_full_attendance_roster(): void
    {
        $generalManager = $this->userWithRole('general_manager');
        $bar = $this->pinUser('bar_staff');
        AttendanceRecord::create(['user_id' => $bar->id, 'work_date' => now()->toDateString(), 'status' => 'present']);

        $response = $this->actingAs($generalManager)->get(route('hr.attendance.index'));

        $response->assertOk();
        $response->assertSee($bar->name);
    }
}
