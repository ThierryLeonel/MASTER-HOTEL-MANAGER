<?php

namespace Tests\Feature\Pos;

use App\Models\Folio;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Room;
use App\Models\Stay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Front Desk petty sales (juice, water, snacks, biscuits, etc.) — added
 * post-9.7.4 at the user's explicit request. This deliberately reuses
 * the exact same Bar/Restaurant POS module (OrderController/
 * OrderService/MenuItem/Order) rather than a parallel "till" feature —
 * see PHASE9_AUDIT_AND_PLAN.md §8 for the full design decision. These
 * tests exist to prove that reuse actually works end-to-end for a third
 * outlet value, not just that the permission exists.
 */
class FrontDeskPettySaleTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();

        \App\Models\Setting::where('key', 'tax_rate_percent')->update(['value' => '0']);

        // Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §21): Front
        // Desk now refuses any order action without an open shift.
        $this->openShiftFor('FO');
    }

    private function frontDesk()
    {
        return $this->pinUser('front_desk');
    }

    public function test_front_desk_can_open_add_items_confirm_and_close_a_petty_sale_with_cash(): void
    {
        $staff = $this->frontDesk();
        $water = MenuItem::factory()->forOutlet('front_desk')->create(['price' => 500]);

        // No pos_table_id at all — a reception counter has no seating.
        $openResponse = $this->actingAs($staff)->post(route('pos.orders.store', 'front_desk'), []);
        $order = Order::first();
        $openResponse->assertRedirect(route('pos.orders.show', ['front_desk', $order]));
        $this->assertNull($order->pos_table_id);

        $this->actingAs($staff)->post(route('pos.orders.items.store', ['front_desk', $order]), [
            'menu_item_id' => $water->id,
            'quantity' => 2,
        ])->assertRedirect();

        $order->refresh();
        $this->assertSame('1000.00', $order->subtotal);

        $this->actingAs($staff)->post(route('pos.orders.confirm', ['front_desk', $order]))->assertRedirect();
        $this->actingAs($staff)->post(route('pos.orders.close', ['front_desk', $order]), [
            'financial_account_id' => $this->financialAccount('CASH')->id,
        ])->assertRedirect(route('pos.index', 'front_desk'));

        $order->refresh();
        $this->assertSame('closed', $order->status);
        $this->assertSame('cash', $order->payment_method);

        $payment = Payment::where('source_module', 'pos_front_desk')->first();
        $this->assertNotNull($payment, 'Closing a petty sale with a payment method must write to the central payment ledger.');
        $this->assertSame($order->total_amount, $payment->amount);
    }

    /**
     * Exercises the OrderService::close() chargeType fix directly: it
     * used to be a hardcoded bar/restaurant ternary that would have
     * mis-posted a Front Desk deferral as a "restaurant_charge". This
     * proves it now posts as its own "front_desk_charge" type.
     */
    public function test_a_guests_petty_sale_can_be_deferred_to_their_room_folio_as_a_front_desk_charge(): void
    {
        $staff = $this->frontDesk();
        $room = Room::factory()->create(['status' => 'occupied']);
        $stay = Stay::factory()->for($room)->create(['status' => 'active']);
        $folio = Folio::factory()->for($stay)->create(['status' => 'open', 'balance' => 0]);

        $item = MenuItem::factory()->forOutlet('front_desk')->create(['price' => 700]);
        $order = Order::factory()->create(['outlet' => 'front_desk', 'status' => 'draft', 'opened_by' => $staff->id]);
        \App\Models\OrderItem::create([
            'order_id' => $order->id, 'menu_item_id' => $item->id, 'item_name' => $item->name,
            'unit_price' => $item->price, 'quantity' => 3, 'line_total' => 2100, 'added_by' => $staff->id,
        ]);

        $this->actingAs($staff)->post(route('pos.orders.confirm', ['front_desk', $order]));

        $this->actingAs($staff)->post(route('pos.orders.close', ['front_desk', $order]), [
            'stay_id' => $stay->id,
        ])->assertRedirect();

        $order->refresh();
        $this->assertSame('closed', $order->status);
        $this->assertSame($stay->id, $order->stay_id);

        $folio->refresh();
        $this->assertSame('2100.00', $folio->balance);
        $this->assertDatabaseHas('folio_transactions', [
            'folio_id' => $folio->id,
            'type' => 'front_desk_charge',
            'amount' => '2100.00',
        ]);
    }

    public function test_bar_staff_cannot_operate_the_front_desk_pos(): void
    {
        $barStaff = $this->pinUser('bar_staff');

        $this->actingAs($barStaff)->get(route('pos.index', 'front_desk'))->assertForbidden();
    }

    public function test_front_desk_cannot_operate_the_bar_pos(): void
    {
        $staff = $this->frontDesk();

        $this->actingAs($staff)->get(route('pos.index', 'bar'))->assertForbidden();
    }
}
