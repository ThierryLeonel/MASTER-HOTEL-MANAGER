<?php

namespace Tests\Feature\Restaurant;

use App\Exceptions\HmsException;
use App\Models\BreakfastEntitlement;
use App\Models\MenuItem;
use App\Models\Product;
use App\Models\RecipeItem;
use App\Models\Stay;
use App\Models\Unit;
use App\Services\Inventory\StockService;
use App\Services\Restaurant\BreakfastEntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Feature 3 ("one complimentary breakfast per occupied unit per business
 * date") — verifies the redemption itself, the one-per-stay-per-day cap
 * (including the DB-constraint backstop, not just the pre-check), that a
 * reversal restores stock without freeing the slot back up, and that both
 * new actions are actually gated server-side, not just hidden from the
 * nav for a role that lacks them.
 */
class BreakfastEntitlementTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    private function breakfastItemWithStock(int $quantityOnHand = 10): MenuItem
    {
        $unit = Unit::factory()->create();
        $product = Product::factory()->create(['unit_id' => $unit->id]);
        $owner = $this->userWithRole('owner');
        app(StockService::class)->receiveStock($product, $quantityOnHand, 500, $owner, 'test');

        $menuItem = MenuItem::factory()->forOutlet('restaurant')->create(['name' => 'Continental Breakfast']);
        RecipeItem::create(['menu_item_id' => $menuItem->id, 'product_id' => $product->id, 'quantity_per_unit' => 1]);

        return $menuItem;
    }

    public function test_restaurant_staff_can_redeem_a_complimentary_breakfast_for_an_occupied_room(): void
    {
        $staff = $this->userWithRole('restaurant_staff');
        $menuItem = $this->breakfastItemWithStock();
        $stay = Stay::factory()->create(['status' => 'active']);

        $this->actingAs($staff)->post(route('breakfast.redeem', $stay), [
            'menu_item_id' => $menuItem->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('breakfast_entitlements', [
            'stay_id' => $stay->id,
            'menu_item_id' => $menuItem->id,
        ]);

        $product = RecipeItem::where('menu_item_id', $menuItem->id)->first()->product;
        $this->assertSame('9.000', $product->fresh()->quantityOnHand());
    }

    public function test_a_second_redemption_for_the_same_stay_the_same_day_is_refused(): void
    {
        $staff = $this->userWithRole('restaurant_staff');
        $menuItem = $this->breakfastItemWithStock();
        $stay = Stay::factory()->create(['status' => 'active']);

        app(BreakfastEntitlementService::class)->redeem($stay, $menuItem, $staff);

        $this->expectException(HmsException::class);
        app(BreakfastEntitlementService::class)->redeem($stay, $menuItem, $staff);
    }

    /**
     * Exercises the DB unique-constraint backstop directly, not just the
     * pre-check — inserts a second row for the same (stay_id,
     * business_date) bypassing the service entirely, confirming the
     * schema itself refuses it (belt-and-braces, same as defect #18's
     * reversal constraint).
     */
    public function test_the_unique_constraint_itself_refuses_a_second_row_for_the_same_stay_and_day(): void
    {
        $menuItem = $this->breakfastItemWithStock();
        $stay = Stay::factory()->create(['status' => 'active']);
        $staff = $this->userWithRole('restaurant_staff');

        BreakfastEntitlement::create([
            'stay_id' => $stay->id,
            'room_id' => $stay->room_id,
            'business_date' => now()->toDateString(),
            'served_at' => now(),
            'served_by' => $staff->id,
            'menu_item_id' => $menuItem->id,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        BreakfastEntitlement::create([
            'stay_id' => $stay->id,
            'room_id' => $stay->room_id,
            'business_date' => now()->toDateString(),
            'served_at' => now(),
            'served_by' => $staff->id,
            'menu_item_id' => $menuItem->id,
        ]);
    }

    public function test_a_checked_out_stay_cannot_redeem_a_breakfast(): void
    {
        $staff = $this->userWithRole('restaurant_staff');
        $menuItem = $this->breakfastItemWithStock();
        $stay = Stay::factory()->create(['status' => 'closed']);

        $this->expectException(HmsException::class);
        app(BreakfastEntitlementService::class)->redeem($stay, $menuItem, $staff);
    }

    public function test_a_role_without_operate_restaurant_pos_cannot_redeem_a_breakfast(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $menuItem = $this->breakfastItemWithStock();
        $stay = Stay::factory()->create(['status' => 'active']);

        $this->actingAs($frontDesk)->post(route('breakfast.redeem', $stay), [
            'menu_item_id' => $menuItem->id,
        ])->assertForbidden();

        $this->assertDatabaseMissing('breakfast_entitlements', ['stay_id' => $stay->id]);
    }

    public function test_reversing_an_entitlement_restores_stock_but_does_not_free_the_slot(): void
    {
        $staff = $this->userWithRole('restaurant_staff');
        $manager = $this->userWithRole('owner');
        $menuItem = $this->breakfastItemWithStock();
        $stay = Stay::factory()->create(['status' => 'active']);

        $entitlement = app(BreakfastEntitlementService::class)->redeem($stay, $menuItem, $staff);
        $product = RecipeItem::where('menu_item_id', $menuItem->id)->first()->product;
        $this->assertSame('9.000', $product->fresh()->quantityOnHand());

        $this->actingAs($manager)->post(route('breakfast.reverse', $entitlement), [
            'reason' => 'Guest never actually came down for breakfast.',
        ])->assertRedirect();

        $this->assertSame('10.000', $product->fresh()->quantityOnHand());
        $this->assertNotNull($entitlement->fresh()->reversed_at);

        // The requirement is explicit: reversing must not silently restore
        // the day's entitlement — a second redemption for the same stay
        // the same day is still refused.
        $this->expectException(HmsException::class);
        app(BreakfastEntitlementService::class)->redeem($stay->fresh(), $menuItem, $staff);
    }

    public function test_a_role_without_reverse_breakfast_entitlement_cannot_reverse_it(): void
    {
        $staff = $this->userWithRole('restaurant_staff');
        $menuItem = $this->breakfastItemWithStock();
        $stay = Stay::factory()->create(['status' => 'active']);

        $entitlement = app(BreakfastEntitlementService::class)->redeem($stay, $menuItem, $staff);

        $this->actingAs($staff)->post(route('breakfast.reverse', $entitlement), [
            'reason' => 'Trying to self-reverse.',
        ])->assertForbidden();

        $this->assertNull($entitlement->fresh()->reversed_at);
    }
}
