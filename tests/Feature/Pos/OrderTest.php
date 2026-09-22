<?php

namespace Tests\Feature\Pos;

use App\Models\Folio;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PosTable;
use App\Models\Room;
use App\Models\Stay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();

        // Zero out tax for these tests so order/folio totals are exact,
        // round numbers — tax computation itself isn't what's under test
        // here (it's exercised directly in SettingsServiceTest).
        \App\Models\Setting::where('key', 'tax_rate_percent')->update(['value' => '0']);

        // Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §21): Bar
        // now refuses any order action without an open shift.
        $this->openShiftFor('BAR');
    }

    private function barStaff()
    {
        return $this->userWithRole('bar_staff', ['auth_type' => 'pin', 'password' => null, 'pin_hash' => bcrypt('1234')]);
    }

    public function test_bar_staff_can_open_add_items_confirm_and_close_an_order_with_cash(): void
    {
        $staff = $this->barStaff();
        $table = PosTable::factory()->create(['outlet' => 'bar', 'status' => 'available']);
        $item = MenuItem::factory()->forOutlet('bar')->create(['price' => 1000]);

        $openResponse = $this->actingAs($staff)->post(route('pos.orders.store', 'bar'), ['pos_table_id' => $table->id]);
        $order = Order::first();
        $openResponse->assertRedirect(route('pos.orders.show', ['bar', $order]));
        $this->assertSame('occupied', $table->fresh()->status);

        $this->actingAs($staff)->post(route('pos.orders.items.store', ['bar', $order]), [
            'menu_item_id' => $item->id,
            'quantity' => 3,
        ])->assertRedirect();

        $order->refresh();
        $this->assertSame('3000.00', $order->subtotal);

        $this->actingAs($staff)->post(route('pos.orders.confirm', ['bar', $order]))->assertRedirect();
        $this->assertSame('confirmed', $order->fresh()->status);

        $this->actingAs($staff)->post(route('pos.orders.close', ['bar', $order]), [
            'financial_account_id' => $this->financialAccount('CASH')->id,
        ])->assertRedirect(route('pos.index', 'bar'));

        $order->refresh();
        $this->assertSame('closed', $order->status);
        $this->assertSame('cash', $order->payment_method);
        $this->assertSame('available', $table->fresh()->status);

        $payment = Payment::where('source_module', 'pos_bar')->first();
        $this->assertNotNull($payment, 'Closing a POS order with a payment method must write to the central payment ledger.');
        $this->assertSame($order->total_amount, $payment->amount);
    }

    public function test_existing_items_are_locked_after_confirmation_but_new_items_can_still_be_added(): void
    {
        $staff = $this->barStaff();
        $item = MenuItem::factory()->forOutlet('bar')->create();
        $order = Order::factory()->create(['outlet' => 'bar', 'status' => 'draft', 'opened_by' => $staff->id]);
        $orderItem = OrderItem::create([
            'order_id' => $order->id, 'menu_item_id' => $item->id, 'item_name' => $item->name,
            'unit_price' => $item->price, 'quantity' => 1, 'line_total' => $item->price, 'added_by' => $staff->id,
        ]);

        $this->actingAs($staff)->post(route('pos.orders.confirm', ['bar', $order]));
        $order->refresh();

        // Cannot remove the existing (now-locked) item.
        $this->actingAs($staff)->post(route('pos.orders.items.remove', ['bar', $order, $orderItem]), [
            'reason' => 'changed my mind',
        ])->assertSessionHasErrors('error');
        $this->assertNull($orderItem->fresh()->voided_at);

        // But a new item can still be appended.
        $item2 = MenuItem::factory()->forOutlet('bar')->create();
        $this->actingAs($staff)->post(route('pos.orders.items.store', ['bar', $order]), [
            'menu_item_id' => $item2->id,
            'quantity' => 1,
        ])->assertRedirect();

        $this->assertSame(2, $order->items()->count());
    }

    public function test_checked_in_guest_order_can_be_deferred_to_their_room_folio(): void
    {
        $staff = $this->barStaff();
        $room = Room::factory()->create(['status' => 'occupied']);
        $stay = Stay::factory()->for($room)->create(['status' => 'active']);
        $folio = Folio::factory()->for($stay)->create(['status' => 'open', 'balance' => 0]);

        $item = MenuItem::factory()->forOutlet('bar')->create(['price' => 2500]);
        $order = Order::factory()->create(['outlet' => 'bar', 'status' => 'draft', 'opened_by' => $staff->id]);
        OrderItem::create([
            'order_id' => $order->id, 'menu_item_id' => $item->id, 'item_name' => $item->name,
            'unit_price' => $item->price, 'quantity' => 2, 'line_total' => 5000, 'added_by' => $staff->id,
        ]);

        $this->actingAs($staff)->post(route('pos.orders.confirm', ['bar', $order]));

        $this->actingAs($staff)->post(route('pos.orders.close', ['bar', $order]), [
            'stay_id' => $stay->id,
        ])->assertRedirect();

        $order->refresh();
        $this->assertSame('closed', $order->status);
        $this->assertSame($stay->id, $order->stay_id);
        $this->assertNull($order->payment_method);

        $folio->refresh();
        $this->assertSame('5000.00', $folio->balance);
        $this->assertDatabaseHas('folio_transactions', [
            'folio_id' => $folio->id,
            'type' => 'bar_charge',
            'amount' => '5000.00',
        ]);

        // Deferred orders do not go through the immediate-payment ledger.
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_cannot_defer_to_folio_for_a_guest_who_is_not_currently_checked_in(): void
    {
        $staff = $this->barStaff();
        $room = Room::factory()->create();
        $closedStay = Stay::factory()->for($room)->create(['status' => 'closed']);
        Folio::factory()->for($closedStay)->create(['status' => 'closed']);

        $item = MenuItem::factory()->forOutlet('bar')->create();
        $order = Order::factory()->create(['outlet' => 'bar', 'status' => 'draft', 'opened_by' => $staff->id]);
        OrderItem::create([
            'order_id' => $order->id, 'menu_item_id' => $item->id, 'item_name' => $item->name,
            'unit_price' => $item->price, 'quantity' => 1, 'line_total' => $item->price, 'added_by' => $staff->id,
        ]);

        $this->actingAs($staff)->post(route('pos.orders.confirm', ['bar', $order]));

        $this->actingAs($staff)->post(route('pos.orders.close', ['bar', $order]), [
            'stay_id' => $closedStay->id,
        ])->assertSessionHasErrors('error');

        $this->assertSame('confirmed', $order->fresh()->status);
    }

    public function test_a_draft_order_cannot_be_closed(): void
    {
        $staff = $this->barStaff();
        $item = MenuItem::factory()->forOutlet('bar')->create(['price' => 1000]);
        $order = Order::factory()->create(['outlet' => 'bar', 'status' => 'draft', 'opened_by' => $staff->id]);
        OrderItem::create([
            'order_id' => $order->id, 'menu_item_id' => $item->id, 'item_name' => $item->name,
            'unit_price' => $item->price, 'quantity' => 1, 'line_total' => $item->price, 'added_by' => $staff->id,
        ]);

        $this->actingAs($staff)->post(route('pos.orders.close', ['bar', $order]), [
            'financial_account_id' => $this->financialAccount('CASH')->id,
        ])->assertSessionHasErrors('error');

        $order->refresh();
        $this->assertSame('draft', $order->status);
        $this->assertNull($order->closed_at);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('folio_transactions', 0);
    }

    public function test_cannot_confirm_an_order_with_no_items(): void
    {
        $staff = $this->barStaff();
        $order = Order::factory()->create(['outlet' => 'bar', 'status' => 'draft', 'opened_by' => $staff->id]);

        $this->actingAs($staff)->post(route('pos.orders.confirm', ['bar', $order]))
            ->assertSessionHasErrors('error');

        $this->assertSame('draft', $order->fresh()->status);
    }

    /**
     * Post-9.8 fix round regression test ("all VAT on food and drinks and
     * even services should be removed, it should not even appear on
     * receipts"): tax_rate_percent is deliberately left in place as a
     * dormant Settings field, so this proves a nonzero value there has no
     * effect on an order's totals — OrderService::recalculateTotals() no
     * longer reads it at all.
     */
    public function test_a_nonzero_tax_rate_percent_setting_has_no_effect_on_order_totals(): void
    {
        \App\Models\Setting::where('key', 'tax_rate_percent')->update(['value' => '19.25']);

        $staff = $this->barStaff();
        $item = MenuItem::factory()->forOutlet('bar')->create(['price' => 1000]);
        $order = Order::factory()->create(['outlet' => 'bar', 'status' => 'draft', 'opened_by' => $staff->id]);

        $this->actingAs($staff)->post(route('pos.orders.items.store', ['bar', $order]), [
            'menu_item_id' => $item->id,
            'quantity' => 2,
        ]);

        $order->refresh();
        $this->assertSame('2000.00', $order->subtotal);
        $this->assertSame('0.00', $order->tax_amount);
        $this->assertSame('2000.00', $order->total_amount);
    }

    public function test_restaurant_staff_cannot_operate_the_bar_pos(): void
    {
        $restaurantStaff = $this->userWithRole('restaurant_staff', ['auth_type' => 'pin', 'password' => null, 'pin_hash' => bcrypt('1234')]);

        $response = $this->actingAs($restaurantStaff)->get(route('pos.index', 'bar'));

        $response->assertForbidden();
    }

    public function test_confirming_and_closing_an_order_writes_audit_log_entries(): void
    {
        $staff = $this->barStaff();
        $item = MenuItem::factory()->forOutlet('bar')->create();
        $order = Order::factory()->create(['outlet' => 'bar', 'status' => 'draft', 'opened_by' => $staff->id]);
        OrderItem::create([
            'order_id' => $order->id, 'menu_item_id' => $item->id, 'item_name' => $item->name,
            'unit_price' => $item->price, 'quantity' => 1, 'line_total' => $item->price, 'added_by' => $staff->id,
        ]);

        $this->actingAs($staff)->post(route('pos.orders.confirm', ['bar', $order]));
        $this->actingAs($staff)->post(route('pos.orders.close', ['bar', $order]), ['financial_account_id' => $this->financialAccount('CASH')->id]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'pos.order_confirmed', 'user_id' => $staff->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'pos.order_closed', 'user_id' => $staff->id]);
    }
}
