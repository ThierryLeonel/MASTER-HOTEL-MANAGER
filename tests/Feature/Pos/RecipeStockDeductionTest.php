<?php

namespace Tests\Feature\Pos;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\RecipeItem;
use App\Models\Setting;
use App\Models\Unit;
use App\Services\Inventory\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Phase 3's link between POS and Inventory: closing an order deducts
 * real stock for any line whose menu item has a recipe (see
 * OrderService::deductRecipeStock()). A menu item with no recipe rows
 * is untouched — this is what keeps Phase 2 outlets backward compatible.
 */
class RecipeStockDeductionTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
        Setting::where('key', 'tax_rate_percent')->update(['value' => '0']);

        // Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §21): Bar
        // now refuses any order action without an open shift.
        $this->openShiftFor('BAR');
    }

    private function barStaff()
    {
        return $this->userWithRole('bar_staff', ['auth_type' => 'pin', 'password' => null, 'pin_hash' => bcrypt('1234')]);
    }

    public function test_closing_an_order_deducts_recipe_stock_via_fifo(): void
    {
        $staff = $this->barStaff();
        $unit = Unit::factory()->create();
        $product = Product::factory()->create(['unit_id' => $unit->id]);
        app(StockService::class)->receiveStock($product, 10, 400, $staff, 'test');

        $menuItem = MenuItem::factory()->forOutlet('bar')->create(['price' => 1000]);
        RecipeItem::create(['menu_item_id' => $menuItem->id, 'product_id' => $product->id, 'quantity_per_unit' => 1]);

        $order = Order::factory()->create(['outlet' => 'bar', 'status' => 'draft', 'opened_by' => $staff->id]);
        OrderItem::create([
            'order_id' => $order->id, 'menu_item_id' => $menuItem->id, 'item_name' => $menuItem->name,
            'unit_price' => $menuItem->price, 'quantity' => 3, 'line_total' => 3000, 'added_by' => $staff->id,
        ]);

        $this->actingAs($staff)->post(route('pos.orders.confirm', ['bar', $order]));

        $this->actingAs($staff)->post(route('pos.orders.close', ['bar', $order]), ['financial_account_id' => $this->financialAccount('CASH')->id])
            ->assertRedirect();

        $this->assertSame('closed', $order->fresh()->status);
        $this->assertSame('7.000', $product->fresh()->quantityOnHand());
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id, 'type' => 'issue', 'direction' => 'out',
            'quantity' => '3.000', 'source_module' => 'pos_bar',
        ]);
    }

    public function test_menu_items_without_a_recipe_are_not_tracked_against_stock(): void
    {
        $staff = $this->barStaff();
        $menuItem = MenuItem::factory()->forOutlet('bar')->create(['price' => 1000]);
        // No RecipeItem configured for this menu item at all.

        $order = Order::factory()->create(['outlet' => 'bar', 'status' => 'draft', 'opened_by' => $staff->id]);
        OrderItem::create([
            'order_id' => $order->id, 'menu_item_id' => $menuItem->id, 'item_name' => $menuItem->name,
            'unit_price' => $menuItem->price, 'quantity' => 2, 'line_total' => 2000, 'added_by' => $staff->id,
        ]);

        $this->actingAs($staff)->post(route('pos.orders.confirm', ['bar', $order]));

        $this->actingAs($staff)->post(route('pos.orders.close', ['bar', $order]), ['financial_account_id' => $this->financialAccount('CASH')->id])
            ->assertRedirect();

        $this->assertSame('closed', $order->fresh()->status);
        $this->assertSame(0, \App\Models\StockMovement::count());
    }

    public function test_closing_an_order_is_blocked_when_recipe_stock_is_insufficient(): void
    {
        $staff = $this->barStaff();
        $unit = Unit::factory()->create();
        $product = Product::factory()->create(['unit_id' => $unit->id]);
        app(StockService::class)->receiveStock($product, 2, 400, $staff, 'test'); // only 2 on hand

        $menuItem = MenuItem::factory()->forOutlet('bar')->create(['price' => 1000]);
        RecipeItem::create(['menu_item_id' => $menuItem->id, 'product_id' => $product->id, 'quantity_per_unit' => 1]);

        $order = Order::factory()->create(['outlet' => 'bar', 'status' => 'draft', 'opened_by' => $staff->id]);
        OrderItem::create([
            'order_id' => $order->id, 'menu_item_id' => $menuItem->id, 'item_name' => $menuItem->name,
            'unit_price' => $menuItem->price, 'quantity' => 5, 'line_total' => 5000, 'added_by' => $staff->id,
        ]);

        $this->actingAs($staff)->post(route('pos.orders.confirm', ['bar', $order]));

        $this->actingAs($staff)->post(route('pos.orders.close', ['bar', $order]), ['financial_account_id' => $this->financialAccount('CASH')->id])
            ->assertSessionHasErrors('error');

        $order->refresh();
        $this->assertSame('confirmed', $order->status, 'A blocked close must leave the order open, not half-settled.');
        $this->assertSame('2.000', $product->fresh()->quantityOnHand());
        $this->assertDatabaseCount('payments', 0);
    }
}
