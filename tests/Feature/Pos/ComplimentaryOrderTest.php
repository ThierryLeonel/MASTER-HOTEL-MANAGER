<?php

namespace Tests\Feature\Pos;

use App\Models\Folio;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Room;
use App\Models\Stay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * "Complimentary drink orders at the bar" — added at the user's explicit
 * request, scoped (per their own clarification) to PER-ITEM comps within
 * an otherwise-billable order: a comped welcome drink alongside a paid
 * main drink, not a whole-order freebie. Full design decision in
 * PHASE9_AUDIT_AND_PLAN.md §9. Applies to every POS outlet
 * (Bar/Restaurant/Front Desk) since they share one OrderService — these
 * tests use 'bar' as the representative outlet, the same way OrderTest.php
 * does for the ordinary (non-comp) order lifecycle.
 */
class ComplimentaryOrderTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
        \App\Models\Setting::where('key', 'tax_rate_percent')->update(['value' => '0']);

        // Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §21): Bar
        // now refuses any order action without an open shift.
        $this->openShiftFor('BAR');
    }

    private function barStaff()
    {
        return $this->userWithRole('bar_staff', ['auth_type' => 'pin', 'password' => null, 'pin_hash' => bcrypt('1234')]);
    }

    public function test_a_complimentary_item_is_free_while_another_item_on_the_same_order_stays_billable(): void
    {
        $staff = $this->barStaff();
        $order = Order::factory()->create(['outlet' => 'bar', 'status' => 'draft', 'opened_by' => $staff->id]);
        $welcomeDrink = MenuItem::factory()->forOutlet('bar')->create(['price' => 1500]);
        $mainDrink = MenuItem::factory()->forOutlet('bar')->create(['price' => 3000]);

        $this->actingAs($staff)->post(route('pos.orders.items.store', ['bar', $order]), [
            'menu_item_id' => $welcomeDrink->id,
            'quantity' => 1,
            'is_complimentary' => 1,
            'comp_reason' => 'Welcome drink for a returning guest',
        ])->assertRedirect();

        $this->actingAs($staff)->post(route('pos.orders.items.store', ['bar', $order]), [
            'menu_item_id' => $mainDrink->id,
            'quantity' => 1,
        ])->assertRedirect();

        $order->refresh();
        $compedItem = $order->items()->where('menu_item_id', $welcomeDrink->id)->first();
        $paidItem = $order->items()->where('menu_item_id', $mainDrink->id)->first();

        $this->assertTrue((bool) $compedItem->is_complimentary);
        $this->assertSame('0.00', $compedItem->line_total);
        $this->assertSame('Welcome drink for a returning guest', $compedItem->comp_reason);
        // unit_price is preserved for reporting "what it would have cost",
        // even though line_total (what actually gets charged) is zero.
        $this->assertSame('1500.00', $compedItem->unit_price);

        $this->assertFalse((bool) $paidItem->is_complimentary);
        $this->assertSame('3000.00', $paidItem->line_total);

        // The order total reflects only the paid item — recalculateTotals()
        // needed no change at all for this, since it just sums line_total.
        $this->assertSame('3000.00', $order->subtotal);
        $this->assertSame('3000.00', $order->total_amount);
    }

    public function test_marking_an_item_complimentary_without_a_reason_is_rejected(): void
    {
        $staff = $this->barStaff();
        $order = Order::factory()->create(['outlet' => 'bar', 'status' => 'draft', 'opened_by' => $staff->id]);
        $item = MenuItem::factory()->forOutlet('bar')->create();

        $this->actingAs($staff)->post(route('pos.orders.items.store', ['bar', $order]), [
            'menu_item_id' => $item->id,
            'quantity' => 1,
            'is_complimentary' => 1,
        ])->assertSessionHasErrors('comp_reason');

        $this->assertSame(0, $order->items()->count());
    }

    public function test_an_order_that_is_entirely_complimentary_items_can_close_with_no_payment_method(): void
    {
        $staff = $this->barStaff();
        $order = Order::factory()->create(['outlet' => 'bar', 'status' => 'draft', 'opened_by' => $staff->id]);
        $item = MenuItem::factory()->forOutlet('bar')->create(['price' => 2000]);

        $this->actingAs($staff)->post(route('pos.orders.items.store', ['bar', $order]), [
            'menu_item_id' => $item->id,
            'quantity' => 1,
            'is_complimentary' => 1,
            'comp_reason' => "Manager's comp",
        ]);

        $this->actingAs($staff)->post(route('pos.orders.confirm', ['bar', $order]))->assertRedirect();

        // No payment_method, no stay_id — would be rejected for a normal
        // order, but this one totals 0 so there is nothing to collect.
        $this->actingAs($staff)->post(route('pos.orders.close', ['bar', $order]), [])
            ->assertRedirect(route('pos.index', 'bar'));

        $order->refresh();
        $this->assertSame('closed', $order->status);
        $this->assertSame('0.00', $order->total_amount);
        $this->assertNull($order->payment_method);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_non_comp_order_still_requires_a_payment_method_or_a_stay(): void
    {
        // Regression guard: the zero-total exception introduced for
        // fully-comped orders must not loosen the rule for an ordinary
        // (nonzero) order.
        $staff = $this->barStaff();
        $order = Order::factory()->create(['outlet' => 'bar', 'status' => 'draft', 'opened_by' => $staff->id]);
        $item = MenuItem::factory()->forOutlet('bar')->create(['price' => 2000]);
        OrderItem::create([
            'order_id' => $order->id, 'menu_item_id' => $item->id, 'item_name' => $item->name,
            'unit_price' => $item->price, 'quantity' => 1, 'line_total' => $item->price, 'added_by' => $staff->id,
        ]);

        $this->actingAs($staff)->post(route('pos.orders.confirm', ['bar', $order]));

        $this->actingAs($staff)->post(route('pos.orders.close', ['bar', $order]), [])
            ->assertSessionHasErrors('error');

        $this->assertSame('confirmed', $order->fresh()->status);
    }

    public function test_a_comped_item_still_deducts_real_stock_via_its_recipe(): void
    {
        // "Complimentary" waives the price, not the cost — mirrors
        // OrderTest.php's non-comp stock-deduction coverage, just for a
        // comp line instead of a paid one.
        $staff = $this->barStaff();
        $product = \App\Models\Product::factory()->create();
        \App\Models\StockBatch::create([
            'product_id' => $product->id, 'quantity_received' => 10, 'quantity_remaining' => 10,
            'unit_cost' => 500, 'received_at' => now(),
        ]);
        $menuItem = MenuItem::factory()->forOutlet('bar')->create(['price' => 1000]);
        \App\Models\RecipeItem::create(['menu_item_id' => $menuItem->id, 'product_id' => $product->id, 'quantity_per_unit' => 2]);

        $order = Order::factory()->create(['outlet' => 'bar', 'status' => 'draft', 'opened_by' => $staff->id]);
        $this->actingAs($staff)->post(route('pos.orders.items.store', ['bar', $order]), [
            'menu_item_id' => $menuItem->id, 'quantity' => 1, 'is_complimentary' => 1, 'comp_reason' => 'Tasting sample',
        ]);
        $this->actingAs($staff)->post(route('pos.orders.confirm', ['bar', $order]));
        $this->actingAs($staff)->post(route('pos.orders.close', ['bar', $order]), []);

        $this->assertSame(8.0, (float) $product->batches()->sum('quantity_remaining'));
    }

    public function test_comping_an_item_writes_a_distinct_audit_log_entry(): void
    {
        $staff = $this->barStaff();
        $order = Order::factory()->create(['outlet' => 'bar', 'status' => 'draft', 'opened_by' => $staff->id]);
        $item = MenuItem::factory()->forOutlet('bar')->create();

        $this->actingAs($staff)->post(route('pos.orders.items.store', ['bar', $order]), [
            'menu_item_id' => $item->id, 'quantity' => 1, 'is_complimentary' => 1, 'comp_reason' => 'VIP',
        ]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'pos.item_comped', 'user_id' => $staff->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'pos.item_added', 'user_id' => $staff->id]);
    }
}
