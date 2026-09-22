<?php

namespace Tests\Feature\Inventory;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class PurchaseOrderTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    private function product(float $reorderLevel = 5): Product
    {
        $unit = Unit::factory()->create();

        return Product::factory()->create(['unit_id' => $unit->id, 'reorder_level' => $reorderLevel]);
    }

    public function test_full_purchase_order_lifecycle_from_draft_to_received_stock(): void
    {
        $dutyManager = $this->userWithRole('duty_manager');
        $accountant = $this->userWithRole('accountant');
        $supplier = Supplier::factory()->create();
        $product = $this->product();

        $store = $this->actingAs($dutyManager)->post(route('inventory.purchase-orders.store'), [
            'supplier_id' => $supplier->id,
            'product_id' => [$product->id],
            'quantity_ordered' => [20],
            'unit_cost' => [500],
        ]);
        $store->assertRedirect();
        $po = \App\Models\PurchaseOrder::first();
        $this->assertSame('draft', $po->status);

        $this->actingAs($dutyManager)->post(route('inventory.purchase-orders.submit', $po))->assertRedirect();
        $this->assertSame('submitted', $po->fresh()->status);

        // Only the accountant holds approve-purchase-order in this seed.
        $this->actingAs($dutyManager)->post(route('inventory.purchase-orders.approve', $po))->assertForbidden();
        $this->actingAs($accountant)->post(route('inventory.purchase-orders.approve', $po))->assertRedirect();
        $this->assertSame('approved', $po->fresh()->status);

        $poItem = $po->items()->first();
        $this->actingAs($dutyManager)->post(route('inventory.purchase-orders.receive', $po), [
            'purchase_order_item_id' => [$poItem->id],
            'quantity_received' => [20],
        ])->assertRedirect();

        $po->refresh();
        $this->assertSame('received', $po->status);
        $this->assertSame('20.000', $product->quantityOnHand());
        $this->assertDatabaseHas('stock_batches', ['product_id' => $product->id, 'unit_cost' => '500.00']);
    }

    public function test_partial_receiving_keeps_the_po_approved_until_fully_received(): void
    {
        $dutyManager = $this->userWithRole('duty_manager');
        $accountant = $this->userWithRole('accountant');
        $supplier = Supplier::factory()->create();
        $product = $this->product();

        $this->actingAs($dutyManager)->post(route('inventory.purchase-orders.store'), [
            'supplier_id' => $supplier->id,
            'product_id' => [$product->id],
            'quantity_ordered' => [20],
            'unit_cost' => [500],
        ]);
        $po = \App\Models\PurchaseOrder::first();
        $this->actingAs($dutyManager)->post(route('inventory.purchase-orders.submit', $po));
        $this->actingAs($accountant)->post(route('inventory.purchase-orders.approve', $po));

        $poItem = $po->items()->first();

        $this->actingAs($dutyManager)->post(route('inventory.purchase-orders.receive', $po), [
            'purchase_order_item_id' => [$poItem->id],
            'quantity_received' => [12],
        ]);
        $this->assertSame('approved', $po->fresh()->status, 'A partially-received PO must stay approved, not flip to received.');
        $this->assertSame('12.000', $product->quantityOnHand());

        $this->actingAs($dutyManager)->post(route('inventory.purchase-orders.receive', $po), [
            'purchase_order_item_id' => [$poItem->id],
            'quantity_received' => [8],
        ]);
        $this->assertSame('received', $po->fresh()->status);
        $this->assertSame('20.000', $product->quantityOnHand());
    }

    public function test_cannot_approve_a_draft_purchase_order(): void
    {
        $dutyManager = $this->userWithRole('duty_manager');
        $accountant = $this->userWithRole('accountant');
        $supplier = Supplier::factory()->create();
        $product = $this->product();

        $this->actingAs($dutyManager)->post(route('inventory.purchase-orders.store'), [
            'supplier_id' => $supplier->id,
            'product_id' => [$product->id],
            'quantity_ordered' => [5],
            'unit_cost' => [100],
        ]);
        $po = \App\Models\PurchaseOrder::first();

        $this->actingAs($accountant)->post(route('inventory.purchase-orders.approve', $po))
            ->assertSessionHasErrors('error');
        $this->assertSame('draft', $po->fresh()->status);
    }

    public function test_cannot_receive_against_a_purchase_order_that_is_not_yet_approved(): void
    {
        $dutyManager = $this->userWithRole('duty_manager');
        $supplier = Supplier::factory()->create();
        $product = $this->product();

        $this->actingAs($dutyManager)->post(route('inventory.purchase-orders.store'), [
            'supplier_id' => $supplier->id,
            'product_id' => [$product->id],
            'quantity_ordered' => [5],
            'unit_cost' => [100],
        ]);
        $po = \App\Models\PurchaseOrder::first();
        $this->actingAs($dutyManager)->post(route('inventory.purchase-orders.submit', $po));

        $poItem = $po->items()->first();
        $this->actingAs($dutyManager)->post(route('inventory.purchase-orders.receive', $po), [
            'purchase_order_item_id' => [$poItem->id],
            'quantity_received' => [5],
        ])->assertSessionHasErrors('error');

        $this->assertSame('0.000', $product->quantityOnHand());
    }

    /**
     * Line-ownership regression: a purchase_order_item_id from a
     * different, unrelated PO must never be receivable through this PO's
     * receive route — it must not create a goods receipt, move stock, or
     * increment that other line's received quantity.
     */
    public function test_a_line_from_one_purchase_order_cannot_be_received_through_another(): void
    {
        $dutyManager = $this->userWithRole('duty_manager');
        $accountant = $this->userWithRole('accountant');
        $supplier = Supplier::factory()->create();
        $productA = $this->product();
        $productB = $this->product();

        $this->actingAs($dutyManager)->post(route('inventory.purchase-orders.store'), [
            'supplier_id' => $supplier->id,
            'product_id' => [$productA->id],
            'quantity_ordered' => [10],
            'unit_cost' => [100],
        ]);
        $poA = \App\Models\PurchaseOrder::first();
        $this->actingAs($dutyManager)->post(route('inventory.purchase-orders.submit', $poA));
        $this->actingAs($accountant)->post(route('inventory.purchase-orders.approve', $poA));

        $this->actingAs($dutyManager)->post(route('inventory.purchase-orders.store'), [
            'supplier_id' => $supplier->id,
            'product_id' => [$productB->id],
            'quantity_ordered' => [10],
            'unit_cost' => [200],
        ]);
        $poB = \App\Models\PurchaseOrder::whereKeyNot($poA->id)->first();
        $this->actingAs($dutyManager)->post(route('inventory.purchase-orders.submit', $poB));
        $this->actingAs($accountant)->post(route('inventory.purchase-orders.approve', $poB));

        $poBItem = $poB->items()->first();

        // Attempt to receive PO B's line item through PO A's receive route.
        $this->actingAs($dutyManager)->post(route('inventory.purchase-orders.receive', $poA), [
            'purchase_order_item_id' => [$poBItem->id],
            'quantity_received' => [10],
        ])->assertSessionHasErrors('error');

        $this->assertSame('0.000', $productB->quantityOnHand(), 'No stock must move for a mismatched line item.');
        $this->assertSame('0.000', $poBItem->fresh()->quantity_received);
        $this->assertSame('approved', $poA->fresh()->status);
        $this->assertSame('approved', $poB->fresh()->status);
        $this->assertDatabaseCount('goods_receipts', 0);
    }

    /**
     * Phase 9.4 fix (routes/web.php, PHASE9_AUDIT_AND_PLAN.md §3.5): before
     * this fix, inventory.purchase-orders.index was gated only on
     * manage-purchase-orders, which Accountant does not hold — leaving
     * Accountant able to POST an approval but never see a page listing any
     * PO to approve. The index route now also accepts approve-purchase-order.
     */
    public function test_the_accountant_can_reach_the_purchase_orders_list_to_find_something_to_approve(): void
    {
        $accountant = $this->userWithRole('accountant');

        $this->actingAs($accountant)->get(route('inventory.purchase-orders.index'))->assertOk();
    }

    public function test_a_role_with_neither_permission_cannot_reach_the_purchase_orders_list(): void
    {
        $cashier = $this->userWithRole('cashier');

        $this->actingAs($cashier)->get(route('inventory.purchase-orders.index'))->assertForbidden();
    }
}
