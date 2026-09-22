<?php

namespace Tests\Feature\Inventory;

use App\Models\Product;
use App\Models\Unit;
use App\Services\Inventory\StockService;
use App\Services\Inventory\WastageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Post-9.8 fix round ("there is not supposed to be any wastage recording
 * or module"): rewritten from a permission-matrix test (bar_staff can
 * record, duty_manager can approve/reject) into a "the module is fully
 * hidden" test, per RolesAndPermissionsSeeder's "Wastage module hide"
 * note — 'record-wastage'/'approve-wastage' are no longer granted to
 * ANY role, including Owner/General Manager (who would otherwise hold
 * them automatically via $all). The underlying table/model/service are
 * deliberately left untouched (data preserved, reversible later), so one
 * test below exercises WastageService directly — bypassing the HTTP
 * layer entirely — to prove that mechanism itself still works even
 * though no role can currently reach it through the UI.
 */
class WastageTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    private function stockedProduct(float $quantity, float $cost = 100): Product
    {
        $unit = Unit::factory()->create();
        $product = Product::factory()->create(['unit_id' => $unit->id]);

        app(StockService::class)->receiveStock($product, $quantity, $cost, $this->userWithRole('owner'), 'test');

        return $product->fresh();
    }

    public function test_the_owner_can_no_longer_record_wastage_through_the_ui(): void
    {
        $owner = $this->userWithRole('owner');
        $product = $this->stockedProduct(20);

        $this->actingAs($owner)->post(route('inventory.wastage.store'), [
            'product_id' => $product->id,
            'quantity' => 3,
            'reason' => 'Dropped and broken',
        ])->assertForbidden();

        $this->assertDatabaseCount('stock_wastages', 0);
    }

    public function test_the_general_manager_can_no_longer_view_or_approve_wastage_through_the_ui(): void
    {
        $generalManager = $this->userWithRole('general_manager');
        $product = $this->stockedProduct(20);

        $this->actingAs($generalManager)->get(route('inventory.wastage.index'))->assertForbidden();

        // Created directly via the service (not the now-403'd HTTP route)
        // purely to have a StockWastage row to attempt to approve.
        $wastage = app(WastageService::class)->record($product->id, 1, 'Spoiled', $generalManager);
        $this->actingAs($generalManager)->post(route('inventory.wastage.approve', $wastage))->assertForbidden();
    }

    public function test_bar_and_restaurant_staff_can_no_longer_record_wastage(): void
    {
        $barStaff = $this->pinUser('bar_staff');
        $restaurantStaff = $this->pinUser('restaurant_staff');
        $product = $this->stockedProduct(20);

        $this->actingAs($barStaff)->post(route('inventory.wastage.store'), [
            'product_id' => $product->id, 'quantity' => 1, 'reason' => 'Spoiled',
        ])->assertForbidden();

        $this->actingAs($restaurantStaff)->post(route('inventory.wastage.store'), [
            'product_id' => $product->id, 'quantity' => 1, 'reason' => 'Spoiled',
        ])->assertForbidden();
    }

    public function test_the_wastage_nav_link_never_renders_for_anyone(): void
    {
        $owner = $this->userWithRole('owner');

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertOk();
        $response->assertDontSee(route('inventory.wastage.index'));
    }

    /**
     * Data/mechanism preservation check: WastageService itself (and the
     * stock_wastages table/StockWastage model it writes to) is completely
     * untouched by the hide — only the permission grants and nav link
     * changed. Calling the service directly, bypassing HTTP/permissions
     * entirely, proves the feature still functions in code and could be
     * re-exposed later just by granting the permissions again.
     */
    public function test_wastage_service_itself_still_functions_when_called_directly(): void
    {
        $actor = $this->userWithRole('owner');
        $product = $this->stockedProduct(20, 100);

        $wastage = app(WastageService::class)->record($product->id, 3, 'Dropped and broken', $actor);
        $this->assertSame('pending', $wastage->status);

        app(WastageService::class)->approve($wastage, $actor);

        $this->assertSame('approved', $wastage->fresh()->status);
        $this->assertSame('17.000', $product->quantityOnHand());
    }
}
