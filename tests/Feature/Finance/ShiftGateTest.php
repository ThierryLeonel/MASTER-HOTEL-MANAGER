<?php

namespace Tests\Feature\Finance;

use App\Models\Expense;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Order;
use App\Models\Room;
use App\Models\Stay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §21) — "make it
 * such that the bar, restaurant, and the front desk (receptionist) can
 * not do any transaction without opening a shift." This file is the
 * dedicated negative-path coverage for App\Services\Finance\
 * ShiftGateService: every other test file that touches a gated action
 * already opens a shift first (via CreatesUsers::openShiftFor()) simply
 * so its own, unrelated assertions still pass — none of them actually
 * assert what happens when a shift is MISSING. That's what's covered
 * here, for all five gated departments, plus confirmation that the
 * deliberately-ungated actions (checkout, expense payouts) still work
 * with no shift open at all, and that opening a shift for one
 * department doesn't accidentally unblock a different one.
 */
class ShiftGateTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
        $this->seed(\Database\Seeders\DepartmentSeeder::class);
    }

    private function openFolio(): Folio
    {
        $stay = Stay::factory()->create(['status' => 'active']);

        return Folio::factory()->for($stay)->create(['status' => 'open', 'balance' => 0]);
    }

    public function test_front_office_blocks_a_folio_payment_without_an_open_shift(): void
    {
        $cashier = $this->userWithRole('cashier');
        $folio = $this->openFolio();

        $response = $this->actingAs($cashier)->post(route('folios.payment', $folio), [
            'amount' => 5000,
            'financial_account_id' => $this->financialAccount('CASH')->id,
        ]);

        $response->assertSessionHasErrors('error');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_front_office_blocks_check_in_without_an_open_shift(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $guest = Guest::factory()->create();
        $room = Room::factory()->create(['status' => 'available']);

        $response = $this->actingAs($frontDesk)->post(route('checkin.walk-in.store'), [
            'guest_id' => $guest->id,
            'room_id' => $room->id,
            'expected_check_out_date' => now()->addDays(2)->toDateString(),
            'agreed_rate' => 30000,
        ]);

        $response->assertSessionHasErrors('error');
        $this->assertSame(0, Stay::count());
        $this->assertSame('available', $room->fresh()->status);
    }

    public function test_front_office_folio_payment_succeeds_once_its_shift_is_open(): void
    {
        $this->openShiftFor('FO');
        $cashier = $this->userWithRole('cashier');
        $folio = $this->openFolio();

        $this->actingAs($cashier)->post(route('folios.payment', $folio), [
            'amount' => 5000,
            'financial_account_id' => $this->financialAccount('CASH')->id,
        ])->assertRedirect();

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_bar_blocks_opening_a_pos_order_without_an_open_shift(): void
    {
        $staff = $this->userWithRole('bar_staff', ['auth_type' => 'pin', 'password' => null, 'pin_hash' => bcrypt('1234')]);

        $response = $this->actingAs($staff)->post(route('pos.orders.store', 'bar'));

        $response->assertSessionHasErrors('error');
        $this->assertSame(0, Order::count());
    }

    public function test_restaurant_blocks_opening_a_pos_order_without_an_open_shift(): void
    {
        $staff = $this->userWithRole('restaurant_staff', ['auth_type' => 'pin', 'password' => null, 'pin_hash' => bcrypt('1234')]);

        $response = $this->actingAs($staff)->post(route('pos.orders.store', 'restaurant'));

        $response->assertSessionHasErrors('error');
        $this->assertSame(0, Order::count());
    }

    public function test_bar_outside_blocks_opening_a_pos_order_without_an_open_shift(): void
    {
        $staff = $this->userWithRole('bar_outside_staff', ['auth_type' => 'pin', 'password' => null, 'pin_hash' => bcrypt('1234')]);

        $response = $this->actingAs($staff)->post(route('pos.orders.store', 'bar_outside'));

        $response->assertSessionHasErrors('error');
        $this->assertSame(0, Order::count());
    }

    public function test_bucareaux_blocks_opening_a_pos_order_without_an_open_shift(): void
    {
        $staff = $this->userWithRole('bucareaux_staff', ['auth_type' => 'pin', 'password' => null, 'pin_hash' => bcrypt('1234')]);

        $response = $this->actingAs($staff)->post(route('pos.orders.store', 'bucareaux'));

        $response->assertSessionHasErrors('error');
        $this->assertSame(0, Order::count());
    }

    /**
     * A shift is department-scoped — opening one for Bar must not also
     * unblock Restaurant. Both outlets are gated, so this isolates that
     * ShiftGateService checks the specific resolved department, not just
     * "is any shift open anywhere."
     */
    public function test_a_shift_open_for_one_gated_department_does_not_unblock_a_different_one(): void
    {
        $this->openShiftFor('BAR');
        $staff = $this->userWithRole('restaurant_staff', ['auth_type' => 'pin', 'password' => null, 'pin_hash' => bcrypt('1234')]);

        $response = $this->actingAs($staff)->post(route('pos.orders.store', 'restaurant'));

        $response->assertSessionHasErrors('error');
        $this->assertSame(0, Order::count());
    }

    /**
     * Spec section 10's "an unpaid balance never blocks checkout"
     * invariant, restated for this round: checkout must never fail just
     * because nobody opened a Front Office shift, unlike check-in above.
     */
    public function test_checkout_is_never_blocked_by_a_missing_shift(): void
    {
        $manager = $this->userWithRole('duty_manager', ['auth_type' => 'password_only']);
        $room = Room::factory()->create(['status' => 'occupied']);
        $stay = Stay::factory()->for($room)->create(['status' => 'active', 'agreed_rate' => 30000]);
        Folio::factory()->for($stay)->create(['status' => 'open', 'balance' => 30000]);
        $manager->givePermissionTo('checkout-guest');

        // Deliberately no openShiftFor() call anywhere in this test.
        $this->actingAs($manager)->post(route('stays.checkout', $stay))->assertRedirect();

        $this->assertSame('closed', $stay->fresh()->status);
    }

    /**
     * Expense::markPaid() passes its own department_id explicitly (see
     * ExpenseService::markPaid()'s docblock), which — per
     * PaymentLedgerService::record() — means the shift gate is never
     * even consulted for it, regardless of which department that is.
     */
    public function test_an_expense_payout_is_never_blocked_by_a_missing_shift(): void
    {
        $requester = $this->userWithRole('duty_manager');
        $approver = $this->userWithRole('duty_manager');

        $this->actingAs($requester)->post(route('finance.expenses.store'), [
            'category' => 'Supplies',
            'description' => 'Cleaning supplies restock',
            'amount' => 20000,
        ]);
        $expense = Expense::first();
        $this->actingAs($approver)->post(route('finance.expenses.approve', $expense));

        // Deliberately no openShiftFor() call anywhere in this test.
        $this->actingAs($requester)->post(route('finance.expenses.pay', $expense), [
            'financial_account_id' => $this->financialAccount('CASH')->id,
        ])->assertRedirect();

        $this->assertSame('paid', $expense->fresh()->status);
    }
}
