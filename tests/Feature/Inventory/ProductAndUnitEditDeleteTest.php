<?php

namespace Tests\Feature\Inventory;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Broader request ("make sure the owner/main user has the access to edit,
 * delete and update all categories and every input, except financial
 * inputs"): Product and Unit could previously only be created, never
 * edited or removed. Neither action ever touches the FIFO stock ledger
 * (quantity/cost) — that stays exactly what "financial inputs" excludes
 * here.
 */
class ProductAndUnitEditDeleteTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    public function test_owner_can_update_a_product(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $unit = Unit::factory()->create();
        $product = Product::factory()->create(['unit_id' => $unit->id, 'name' => 'Old Name']);

        $this->actingAs($owner)->put(route('inventory.products.update', $product), [
            'sku' => $product->sku,
            'name' => 'New Name',
            'unit_id' => $unit->id,
            'is_active' => '0',
        ])->assertRedirect();

        $product->refresh();
        $this->assertSame('New Name', $product->name);
        $this->assertFalse($product->is_active);
    }

    public function test_a_product_with_purchasing_history_cannot_be_deleted(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $product = Product::factory()->create();
        $po = PurchaseOrder::create([
            'po_number' => 'PO-TEST-1', 'supplier_id' => Supplier::factory()->create()->id,
            'status' => 'submitted', 'ordered_by' => $owner->id,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'product_id' => $product->id,
            'quantity_ordered' => 10, 'quantity_received' => 0, 'unit_cost' => 500, 'line_total' => 5000,
        ]);

        $response = $this->actingAs($owner)->delete(route('inventory.products.destroy', $product));

        $response->assertSessionHasErrors('error');
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_an_unused_product_can_be_deleted(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $product = Product::factory()->create();

        $this->actingAs($owner)->delete(route('inventory.products.destroy', $product))->assertRedirect();

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    public function test_a_unit_still_used_by_a_product_cannot_be_deleted(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $unit = Unit::factory()->create();
        Product::factory()->create(['unit_id' => $unit->id]);

        $response = $this->actingAs($owner)->delete(route('inventory.units.destroy', $unit));

        $response->assertSessionHasErrors('error');
        $this->assertDatabaseHas('units', ['id' => $unit->id]);
    }

    public function test_a_role_without_manage_products_cannot_edit_or_delete_a_product(): void
    {
        $cashier = $this->userWithRole('cashier');
        $product = Product::factory()->create();

        $this->actingAs($cashier)->put(route('inventory.products.update', $product), [
            'sku' => $product->sku, 'name' => 'Hacked', 'unit_id' => $product->unit_id,
        ])->assertForbidden();

        $this->actingAs($cashier)->delete(route('inventory.products.destroy', $product))->assertForbidden();
        $this->assertSame($product->name, $product->fresh()->name);
    }
}
