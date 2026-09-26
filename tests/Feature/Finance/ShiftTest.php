<?php

namespace Tests\Feature\Finance;

use App\Models\Department;
use App\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Shift-based department cash reconciliation (post-9.8 second fix round,
 * PHASE9_AUDIT_AND_PLAN.md §19) — "not really per day but in shifts
 * because they work in shifts." Third post-9.8 fix round (§21) changed
 * how a shift is opened: no more department dropdown — it's always the
 * logged-in user's own `users.department_id`.
 */
class ShiftTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
        $this->seed(\Database\Seeders\DepartmentSeeder::class);
    }

    public function test_front_desk_can_open_a_shift_take_a_cash_payment_and_close_it_with_zero_variance(): void
    {
        $fo = Department::where('code', 'FO')->first();
        $frontDesk = $this->userWithRole('front_desk', ['department_id' => $fo->id]);

        $this->actingAs($frontDesk)->post(route('finance.shifts.store'), ['opening_cash' => 0])->assertRedirect();

        $shift = Shift::first();
        $this->assertSame('open', $shift->status);
        $this->assertSame($fo->id, $shift->department_id);

        // A cash payment recorded through the general Payments module
        // (source_module 'general_payment') is attributed to Front
        // Office automatically by PaymentLedgerService's resolveDepartmentId().
        $this->actingAs($frontDesk)->post(route('payments.store'), [
            'amount' => 15000,
            'financial_account_id' => $this->financialAccount('CASH')->id,
            'notes' => 'Visitor rent payment',
        ])->assertRedirect();

        $this->actingAs($frontDesk)->post(route('finance.shifts.close', $shift), [
            'declared_cash' => 15000,
        ])->assertRedirect();

        $shift->refresh();
        $this->assertSame('closed', $shift->status);
        $this->assertSame('15000.00', $shift->expected_cash);
        $this->assertSame('15000.00', $shift->declared_cash);
        $this->assertSame('0.00', $shift->variance);
    }

    public function test_a_shift_with_a_cash_shortage_records_a_negative_variance(): void
    {
        $fo = Department::where('code', 'FO')->first();
        $frontDesk = $this->userWithRole('front_desk', ['department_id' => $fo->id]);

        $this->actingAs($frontDesk)->post(route('finance.shifts.store'), ['opening_cash' => 0]);
        $shift = Shift::first();

        $this->actingAs($frontDesk)->post(route('payments.store'), [
            'amount' => 10000,
            'financial_account_id' => $this->financialAccount('CASH')->id,
            'notes' => 'Visitor payment',
        ]);

        $this->actingAs($frontDesk)->post(route('finance.shifts.close', $shift), [
            'declared_cash' => 9000,
        ]);

        $this->assertSame('-1000.00', $shift->fresh()->variance);
    }

    /**
     * "When a shift is been open we should declare the opening balance" —
     * the field is required at the HTTP layer, not just a service default.
     */
    public function test_opening_a_shift_requires_declaring_an_opening_cash_amount(): void
    {
        $fo = Department::where('code', 'FO')->first();
        $frontDesk = $this->userWithRole('front_desk', ['department_id' => $fo->id]);

        $this->actingAs($frontDesk)->post(route('finance.shifts.store'), [])
            ->assertSessionHasErrors('opening_cash');

        $this->assertSame(0, Shift::count());
    }

    /**
     * "So that the system can properly calculate the expected closing and
     * to know the variance at the end" — a non-zero opening float must
     * carry through into expectedCashFor()/close(), not just be stored for
     * display.
     */
    public function test_opening_cash_is_included_in_the_shifts_expected_closing_balance(): void
    {
        $fo = Department::where('code', 'FO')->first();
        $frontDesk = $this->userWithRole('front_desk', ['department_id' => $fo->id]);

        $this->actingAs($frontDesk)->post(route('finance.shifts.store'), ['opening_cash' => 5000])->assertRedirect();
        $shift = Shift::first();
        $this->assertSame('5000.00', $shift->opening_cash);

        $this->actingAs($frontDesk)->post(route('payments.store'), [
            'amount' => 15000,
            'financial_account_id' => $this->financialAccount('CASH')->id,
            'notes' => 'Visitor rent payment',
        ])->assertRedirect();

        $this->actingAs($frontDesk)->post(route('finance.shifts.close', $shift), [
            'declared_cash' => 20000,
        ])->assertRedirect();

        $shift->refresh();
        $this->assertSame('20000.00', $shift->expected_cash);
        $this->assertSame('0.00', $shift->variance);
    }

    public function test_a_department_cannot_have_two_open_shifts_at_once(): void
    {
        $fo = Department::where('code', 'FO')->first();
        $frontDesk = $this->userWithRole('front_desk', ['department_id' => $fo->id]);

        $this->actingAs($frontDesk)->post(route('finance.shifts.store'), ['opening_cash' => 0])->assertRedirect();

        $response = $this->actingAs($frontDesk)->post(route('finance.shifts.store'), ['opening_cash' => 0]);

        $response->assertSessionHasErrors('error');
        $this->assertSame(1, Shift::where('department_id', $fo->id)->where('status', 'open')->count());
    }

    /**
     * Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §21): "let the
     * shift not be a dropdown of the departments but the department
     * should be automatic based on the login" — a Bar Staff account
     * always opens a shift for Bar, regardless of anything else, because
     * there is no longer a department to pick.
     */
    public function test_the_department_is_always_the_logged_in_users_own_department(): void
    {
        $restaurant = Department::where('code', 'REST')->first();
        $bar = Department::where('code', 'BAR')->first();
        $barStaff = $this->userWithRole('bar_staff', ['department_id' => $bar->id]);

        // Even if something tried to pass a different department_id, it's
        // simply not read anymore — the controller derives it from the
        // logged-in user only.
        $this->actingAs($barStaff)->post(route('finance.shifts.store'), [
            'department_id' => $restaurant->id,
            'opening_cash' => 0,
        ])->assertRedirect();

        $shift = Shift::first();
        $this->assertSame($bar->id, $shift->department_id);
        $this->assertNotSame($restaurant->id, $shift->department_id);
    }

    public function test_opening_a_shift_fails_clearly_for_a_user_with_no_department(): void
    {
        $userWithNoDepartment = $this->userWithRole('cashier', ['department_id' => null]);

        $response = $this->actingAs($userWithNoDepartment)->post(route('finance.shifts.store'), ['opening_cash' => 0]);

        $response->assertSessionHasErrors('error');
        $this->assertSame(0, Shift::count());
    }

    /**
     * Fourth post-9.8 fix round ("let shifts not exist for the
     * accountant, and the manager and the duty manager") — these three
     * back-office roles no longer hold `manage-own-shift` at all, so the
     * open/close routes 403 for them outright, regardless of department.
     * They keep `view-shift-reports` (Owner still holds everything, so
     * it isn't tested as a negative case here).
     */
    public function test_accountant_general_manager_and_duty_manager_can_no_longer_open_a_shift(): void
    {
        $fin = Department::where('code', 'FIN')->first();
        $mgmt = Department::where('code', 'MGMT')->first();

        $accountant = $this->userWithRole('accountant', ['department_id' => $fin->id]);
        $generalManager = $this->userWithRole('general_manager', ['department_id' => $mgmt->id]);
        $dutyManager = $this->userWithRole('duty_manager', ['department_id' => $mgmt->id]);

        foreach ([$accountant, $generalManager, $dutyManager] as $user) {
            $this->actingAs($user)->post(route('finance.shifts.store'), [])->assertForbidden();
        }

        $this->assertSame(0, Shift::count());
    }

    /**
     * Losing `manage-own-shift` must not also take away
     * `view-shift-reports` — reviewing every department's shifts is a
     * completely separate permission and stays untouched for all three
     * roles.
     */
    public function test_accountant_general_manager_and_duty_manager_can_still_view_shift_reports(): void
    {
        $accountant = $this->userWithRole('accountant');
        $generalManager = $this->userWithRole('general_manager');
        $dutyManager = $this->userWithRole('duty_manager');

        foreach ([$accountant, $generalManager, $dutyManager] as $user) {
            $this->actingAs($user)->get(route('finance.shifts.index'))->assertOk();
        }
    }
}
