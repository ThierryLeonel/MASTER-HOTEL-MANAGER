<?php

namespace Tests\Feature\Pos;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosTable;
use App\Services\Pos\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Security re-audit finding (PHASE9_AUDIT_AND_PLAN.md §9.8, fixed):
 * Pos\OrderController::authorizeOutlet() used to check only the
 * permission implied by the URL's {outlet} segment, never whether the
 * route-bound $order actually belonged to that outlet — since Order is
 * an implicit, unscoped route-model binding (Order::findOrFail($id)).
 * A Bar Staff account could substitute a Restaurant or Front Desk
 * order's numeric ID into a /pos/bar/orders/{id}/... URL and every
 * action would succeed. These tests cover every affected action, plus
 * the adjacent item-ownership gap in removeItem().
 */
class OrderCrossOutletTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();

        // Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §21): Bar
        // now refuses any order action without an open shift — most tests
        // here go through the controller (which enforces the gate right
        // after the outlet/ownership checks this file is actually about),
        // so a shift must already be open for those checks to be reached.
        $this->openShiftFor('BAR');
    }

    private function barStaff()
    {
        return $this->userWithRole('bar_staff', ['auth_type' => 'pin', 'password' => null, 'pin_hash' => bcrypt('1234')]);
    }

    private function restaurantOrder(): Order
    {
        return Order::factory()->create(['outlet' => 'restaurant', 'status' => 'draft']);
    }

    public function test_bar_staff_cannot_view_a_restaurant_order_via_the_bar_url(): void
    {
        $staff = $this->barStaff();
        $order = $this->restaurantOrder();

        $this->actingAs($staff)->get(route('pos.orders.show', ['bar', $order]))
            ->assertNotFound();
    }

    public function test_bar_staff_cannot_add_an_item_to_a_restaurant_order_via_the_bar_url(): void
    {
        $staff = $this->barStaff();
        $order = $this->restaurantOrder();
        $menuItem = MenuItem::factory()->forOutlet('restaurant')->create();

        $this->actingAs($staff)->post(route('pos.orders.items.store', ['bar', $order]), [
            'menu_item_id' => $menuItem->id,
            'quantity' => 1,
        ])->assertNotFound();

        $this->assertSame(0, $order->items()->count());
    }

    public function test_bar_staff_cannot_confirm_close_or_cancel_a_restaurant_order_via_the_bar_url(): void
    {
        $staff = $this->barStaff();
        $order = $this->restaurantOrder();

        $this->actingAs($staff)->post(route('pos.orders.confirm', ['bar', $order]))->assertNotFound();
        $this->actingAs($staff)->post(route('pos.orders.close', ['bar', $order]), ['financial_account_id' => $this->financialAccount('CASH')->id])->assertNotFound();
        $this->actingAs($staff)->post(route('pos.orders.cancel', ['bar', $order]), ['reason' => 'nope'])->assertNotFound();

        $this->assertSame('draft', $order->fresh()->status);
    }

    public function test_bar_staff_cannot_print_a_restaurant_orders_receipt_via_the_bar_url(): void
    {
        $staff = $this->barStaff();
        $order = $this->restaurantOrder();

        $this->actingAs($staff)->get(route('pos.orders.receipt', ['bar', $order]))
            ->assertNotFound();
    }

    public function test_bar_staff_cannot_remove_an_item_belonging_to_a_different_order_even_within_the_same_outlet(): void
    {
        // The item-ownership check, not just the outlet check: {order}
        // and {item} are both independently bound route parameters, so
        // an item from one bar order must not be removable by posting
        // it against a different bar order's URL.
        $staff = $this->barStaff();
        $orderA = Order::factory()->create(['outlet' => 'bar', 'status' => 'draft']);
        $orderB = Order::factory()->create(['outlet' => 'bar', 'status' => 'draft']);
        $menuItem = MenuItem::factory()->forOutlet('bar')->create(['price' => 1000]);
        $item = OrderItem::create([
            'order_id' => $orderB->id, 'menu_item_id' => $menuItem->id, 'item_name' => $menuItem->name,
            'unit_price' => $menuItem->price, 'quantity' => 1, 'line_total' => $menuItem->price, 'added_by' => $staff->id,
        ]);

        $this->actingAs($staff)->post(route('pos.orders.items.remove', ['bar', $orderA, $item]), ['reason' => 'test'])
            ->assertNotFound();

        $this->assertFalse($item->fresh()->isVoided());
    }

    public function test_a_same_outlet_order_is_completely_unaffected_by_the_ownership_check(): void
    {
        // Regression guard: the fix must not break the ordinary case.
        $staff = $this->barStaff();
        $order = Order::factory()->create(['outlet' => 'bar', 'status' => 'draft']);

        $this->actingAs($staff)->get(route('pos.orders.show', ['bar', $order]))
            ->assertOk();
    }

    /**
     * Outlet-ownership invariant (request-validation layer): a table
     * belonging to a different outlet must not be selectable when opening
     * an order — no order should be created at all.
     */
    public function test_cannot_open_a_bar_order_against_a_restaurant_table(): void
    {
        $staff = $this->barStaff();
        $restaurantTable = PosTable::factory()->create(['outlet' => 'restaurant', 'status' => 'available']);

        $this->actingAs($staff)->post(route('pos.orders.store', 'bar'), [
            'pos_table_id' => $restaurantTable->id,
        ])->assertSessionHasErrors('pos_table_id');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame('available', $restaurantTable->fresh()->status);
    }

    /**
     * Outlet-ownership invariant (service layer): the same rule must hold
     * even for a direct service call that bypasses request validation
     * entirely.
     */
    public function test_order_service_open_rejects_a_table_from_a_different_outlet_directly(): void
    {
        $staff = $this->barStaff();
        $restaurantTable = PosTable::factory()->create(['outlet' => 'restaurant', 'status' => 'available']);

        $this->expectException(\App\Exceptions\HmsException::class);

        app(OrderService::class)->open('bar', $staff, $restaurantTable->id);
    }

    /**
     * Outlet-ownership invariant (request-validation layer): a menu item
     * belonging to a different outlet must not be addable to an order.
     */
    public function test_cannot_add_a_restaurant_menu_item_to_a_bar_order(): void
    {
        $staff = $this->barStaff();
        $order = Order::factory()->create(['outlet' => 'bar', 'status' => 'draft', 'opened_by' => $staff->id]);
        $restaurantItem = MenuItem::factory()->forOutlet('restaurant')->create();

        $this->actingAs($staff)->post(route('pos.orders.items.store', ['bar', $order]), [
            'menu_item_id' => $restaurantItem->id,
            'quantity' => 1,
        ])->assertSessionHasErrors('menu_item_id');

        $this->assertSame(0, $order->items()->count());
    }

    /**
     * Outlet-ownership invariant (request-validation layer): an inactive
     * (discontinued) menu item must not be addable to any order, even one
     * at the same outlet.
     */
    public function test_cannot_add_an_inactive_menu_item_to_an_order(): void
    {
        $staff = $this->barStaff();
        $order = Order::factory()->create(['outlet' => 'bar', 'status' => 'draft', 'opened_by' => $staff->id]);
        $inactiveItem = MenuItem::factory()->forOutlet('bar')->create(['is_active' => false]);

        $this->actingAs($staff)->post(route('pos.orders.items.store', ['bar', $order]), [
            'menu_item_id' => $inactiveItem->id,
            'quantity' => 1,
        ])->assertSessionHasErrors('menu_item_id');

        $this->assertSame(0, $order->items()->count());
    }

    /**
     * Outlet-ownership invariant (service layer): the same rule must hold
     * even for a direct service call that bypasses request validation
     * entirely.
     */
    public function test_order_service_add_item_rejects_a_menu_item_from_a_different_outlet_directly(): void
    {
        $staff = $this->barStaff();
        $order = Order::factory()->create(['outlet' => 'bar', 'status' => 'draft', 'opened_by' => $staff->id]);
        $restaurantItem = MenuItem::factory()->forOutlet('restaurant')->create();

        $this->expectException(\App\Exceptions\HmsException::class);

        app(OrderService::class)->addItem($order, $restaurantItem, 1, $staff);
    }
}
