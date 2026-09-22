<?php

namespace Tests\Feature\Pos;

use App\Exceptions\HmsException;
use App\Models\MenuItem;
use App\Models\OrderDeliveryCorrection;
use App\Models\Setting;
use App\Models\Stay;
use App\Services\Pos\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Feature 4 ("a 500 XAF room-service delivery charge"): the fee is fixed,
 * folded into the order's total exactly once, never treated as a
 * recipe-backed OrderItem (so it never deducts inventory), lockable at
 * confirm(), waivable by front-line staff without freeing the "was this a
 * delivery" fact, and correctable (with a full audit trail) if the mode
 * itself was recorded wrong.
 */
class RoomDeliveryFeeTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
        Setting::where('key', 'tax_rate_percent')->update(['value' => '0']);
        $this->openShiftFor('REST');
    }

    private function restaurantStaff()
    {
        return $this->userWithRole('restaurant_staff');
    }

    private function activeStay(): Stay
    {
        return Stay::factory()->create(['status' => 'active']);
    }

    public function test_opening_a_room_delivery_order_charges_the_fixed_fee_once_items_are_added(): void
    {
        $staff = $this->restaurantStaff();
        $stay = $this->activeStay();
        $menuItem = MenuItem::factory()->forOutlet('restaurant')->create(['price' => 2000]);

        $response = $this->actingAs($staff)->post(route('pos.orders.store', 'restaurant'), [
            'is_room_delivery' => 1,
            'delivery_destination_stay_id' => $stay->id,
        ]);
        $response->assertRedirect();

        $order = \App\Models\Order::where('outlet', 'restaurant')->latest('id')->first();
        $this->assertTrue((bool) $order->is_room_delivery);
        $this->assertSame('500.00', $order->delivery_fee_amount);
        // Total is still 0 before any item is added — nothing has run
        // recalculateTotals() yet, matching an ordinary order's behavior.
        $this->assertSame('0.00', $order->total_amount);

        app(OrderService::class)->addItem($order, $menuItem, 2, $staff);

        $this->assertSame('4500.00', $order->fresh()->total_amount);
    }

    public function test_a_non_delivery_order_is_unaffected(): void
    {
        $staff = $this->restaurantStaff();
        $menuItem = MenuItem::factory()->forOutlet('restaurant')->create(['price' => 2000]);

        $order = app(OrderService::class)->open('restaurant', $staff);
        app(OrderService::class)->addItem($order, $menuItem, 1, $staff);

        $this->assertSame('2000.00', $order->fresh()->total_amount);
        $this->assertFalse((bool) $order->fresh()->is_room_delivery);
    }

    public function test_room_delivery_requires_a_currently_occupied_stay(): void
    {
        $staff = $this->restaurantStaff();
        $closedStay = Stay::factory()->create(['status' => 'closed']);

        $this->expectException(HmsException::class);
        app(OrderService::class)->open('restaurant', $staff, null, null, true, $closedStay->id);
    }

    public function test_waiving_the_delivery_fee_excludes_it_from_the_total_but_keeps_the_record(): void
    {
        $staff = $this->restaurantStaff();
        $stay = $this->activeStay();
        $menuItem = MenuItem::factory()->forOutlet('restaurant')->create(['price' => 2000]);

        $order = app(OrderService::class)->open('restaurant', $staff, null, null, true, $stay->id);
        app(OrderService::class)->addItem($order, $menuItem, 1, $staff);
        $this->assertSame('2500.00', $order->fresh()->total_amount);

        app(OrderService::class)->waiveDeliveryFee($order, $staff, 'Guest complained about the wait.');

        $fresh = $order->fresh();
        $this->assertTrue((bool) $fresh->delivery_fee_waived);
        $this->assertSame('500.00', $fresh->delivery_fee_amount, 'the fee stays on record for reporting');
        $this->assertSame('2000.00', $fresh->total_amount, 'but is excluded from what is actually charged');
    }

    public function test_the_delivery_fee_is_never_double_waived(): void
    {
        $staff = $this->restaurantStaff();
        $stay = $this->activeStay();
        $order = app(OrderService::class)->open('restaurant', $staff, null, null, true, $stay->id);

        app(OrderService::class)->waiveDeliveryFee($order, $staff, 'First waiver.');

        $this->expectException(HmsException::class);
        app(OrderService::class)->waiveDeliveryFee($order, $staff, 'Second attempt.');
    }

    public function test_correcting_the_delivery_mode_leaves_an_audited_correction_row(): void
    {
        $staff = $this->restaurantStaff();
        $stay = $this->activeStay();
        $menuItem = MenuItem::factory()->forOutlet('restaurant')->create(['price' => 2000]);

        // Opened WITHOUT delivery — staff forgot to check the box.
        $order = app(OrderService::class)->open('restaurant', $staff);
        app(OrderService::class)->addItem($order, $menuItem, 1, $staff);
        $this->assertSame('2000.00', $order->fresh()->total_amount);

        app(OrderService::class)->correctDeliveryMode($order, true, $stay->id, 'Staff forgot to mark this as a room delivery.', $staff);

        $fresh = $order->fresh();
        $this->assertTrue((bool) $fresh->is_room_delivery);
        $this->assertSame('2500.00', $fresh->total_amount);

        $this->assertDatabaseHas('order_delivery_corrections', [
            'order_id' => $order->id,
            'previous_is_room_delivery' => false,
            'new_is_room_delivery' => true,
        ]);
        $this->assertSame(1, OrderDeliveryCorrection::where('order_id', $order->id)->count());
    }

    public function test_delivery_mode_cannot_be_corrected_after_the_order_is_closed(): void
    {
        $staff = $this->restaurantStaff();
        $stay = $this->activeStay();
        $menuItem = MenuItem::factory()->forOutlet('restaurant')->create(['price' => 2000]);

        $order = app(OrderService::class)->open('restaurant', $staff);
        app(OrderService::class)->addItem($order, $menuItem, 1, $staff);
        app(OrderService::class)->confirm($order, $staff);
        app(OrderService::class)->close($order->fresh(), $staff, paymentMethod: 'cash', financialAccountId: $this->financialAccount('CASH')->id);

        $this->expectException(HmsException::class);
        app(OrderService::class)->correctDeliveryMode($order->fresh(), true, $stay->id, 'Too late.', $staff);
    }

    /**
     * The requirement is explicit: "do not deduct inventory for the
     * delivery fee itself" — confirmed by closing a real delivery order
     * and checking only the recipe-backed item's stock moved.
     */
    public function test_closing_a_delivery_order_deducts_stock_only_for_real_items_never_the_fee(): void
    {
        $staff = $this->restaurantStaff();
        $stay = $this->activeStay();

        $unit = \App\Models\Unit::factory()->create();
        $product = \App\Models\Product::factory()->create(['unit_id' => $unit->id]);
        app(\App\Services\Inventory\StockService::class)->receiveStock($product, 10, 500, $staff, 'test');

        $menuItem = MenuItem::factory()->forOutlet('restaurant')->create(['price' => 2000]);
        \App\Models\RecipeItem::create(['menu_item_id' => $menuItem->id, 'product_id' => $product->id, 'quantity_per_unit' => 1]);

        $order = app(OrderService::class)->open('restaurant', $staff, null, null, true, $stay->id);
        app(OrderService::class)->addItem($order, $menuItem, 1, $staff);
        app(OrderService::class)->confirm($order->fresh(), $staff);
        $closed = app(OrderService::class)->close($order->fresh(), $staff, paymentMethod: 'cash', financialAccountId: $this->financialAccount('CASH')->id);

        $this->assertSame('2500.00', $closed->total_amount);
        $this->assertSame('9.000', $product->fresh()->quantityOnHand());
        $this->assertSame(1, \App\Models\StockMovement::where('product_id', $product->id)->where('direction', 'out')->count());
    }
}
