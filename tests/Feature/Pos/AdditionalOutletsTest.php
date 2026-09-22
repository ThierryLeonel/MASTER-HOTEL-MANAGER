<?php

namespace Tests\Feature\Pos;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Post-9.8 second fix round (PHASE9_AUDIT_AND_PLAN.md §17) — "the bar
 * outside" and "the bucareaux," modeled as two more outlets of the
 * existing outlet-parametrized POS module, exactly like Bar/Restaurant/
 * Front Desk before them.
 */
class AdditionalOutletsTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
        $this->seed(\Database\Seeders\DepartmentSeeder::class);
    }

    public function test_bucareaux_staff_can_open_add_items_and_close_an_order_with_cash(): void
    {
        $this->openShiftFor('BUCA');
        $staff = $this->userWithRole('bucareaux_staff', ['auth_type' => 'pin', 'password' => null, 'pin_hash' => bcrypt('1234')]);
        $item = MenuItem::factory()->forOutlet('bucareaux')->create(['price' => 1000]);

        $this->actingAs($staff)->post(route('pos.orders.store', 'bucareaux'))->assertRedirect();
        $order = Order::first();
        $this->assertSame('bucareaux', $order->outlet);

        $this->actingAs($staff)->post(route('pos.orders.items.store', ['bucareaux', $order]), [
            'menu_item_id' => $item->id,
            'quantity' => 2,
        ])->assertRedirect();

        $this->actingAs($staff)->post(route('pos.orders.confirm', ['bucareaux', $order]))->assertRedirect();

        $this->actingAs($staff)->post(route('pos.orders.close', ['bucareaux', $order]), [
            'financial_account_id' => $this->financialAccount('CASH')->id,
        ])->assertRedirect();

        $order->refresh();
        $this->assertSame('closed', $order->status);

        $payment = Payment::where('reference_type', Order::class)->where('reference_id', $order->id)->first();
        $this->assertNotNull($payment);
        $this->assertSame('pos_bucareaux', $payment->source_module);

        $buca = \App\Models\Department::where('code', 'BUCA')->first();
        $this->assertSame($buca->id, $payment->department_id, 'A Bucareaux sale must be attributed to the Bucareaux department for the reconciliation report.');
    }

    public function test_bar_outside_staff_cannot_operate_the_main_bar_outlet(): void
    {
        $staff = $this->userWithRole('bar_outside_staff', ['auth_type' => 'pin', 'password' => null, 'pin_hash' => bcrypt('1234')]);

        $this->actingAs($staff)->post(route('pos.orders.store', 'bar'))->assertForbidden();
    }

    public function test_bar_staff_cannot_operate_the_bar_outside_outlet(): void
    {
        $staff = $this->userWithRole('bar_staff', ['auth_type' => 'pin', 'password' => null, 'pin_hash' => bcrypt('1234')]);

        $this->actingAs($staff)->post(route('pos.orders.store', 'bar_outside'))->assertForbidden();
    }
}
