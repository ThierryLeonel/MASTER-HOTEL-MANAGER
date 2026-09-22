<?php

namespace Tests\Feature\Inventory;

use App\Models\Department;
use App\Models\Product;
use App\Models\Unit;
use App\Services\Inventory\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class RequisitionTest extends TestCase
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

    public function test_approving_a_requisition_issues_real_stock_via_fifo(): void
    {
        $barStaff = $this->pinUser('bar_staff');
        $dutyManager = $this->userWithRole('duty_manager');
        $department = Department::create(['name' => 'Bar', 'code' => 'BAR', 'is_active' => true]);
        $product = $this->stockedProduct(20);

        $this->actingAs($barStaff)->post(route('inventory.requisitions.store'), [
            'department_id' => $department->id,
            'product_id' => [$product->id],
            'quantity_requested' => [8],
        ])->assertRedirect();

        $requisition = \App\Models\StockRequisition::first();
        $this->assertSame('pending', $requisition->status);
        $this->assertSame('20.000', $product->quantityOnHand(), 'Requesting must not itself move stock — only approval does.');

        $this->actingAs($dutyManager)->post(route('inventory.requisitions.approve', $requisition))->assertRedirect();

        $this->assertSame('approved', $requisition->fresh()->status);
        $this->assertSame('12.000', $product->quantityOnHand());
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id, 'type' => 'requisition', 'direction' => 'out', 'quantity' => '8.000',
        ]);
    }

    public function test_approval_is_all_or_nothing_when_one_line_has_insufficient_stock(): void
    {
        $barStaff = $this->pinUser('bar_staff');
        $dutyManager = $this->userWithRole('duty_manager');
        $department = Department::create(['name' => 'Bar', 'code' => 'BAR', 'is_active' => true]);

        $plentifulProduct = $this->stockedProduct(20);
        $scarceProduct = $this->stockedProduct(2);

        $this->actingAs($barStaff)->post(route('inventory.requisitions.store'), [
            'department_id' => $department->id,
            'product_id' => [$plentifulProduct->id, $scarceProduct->id],
            'quantity_requested' => [5, 10], // second line exceeds the 2 on hand
        ]);
        $requisition = \App\Models\StockRequisition::first();

        $this->actingAs($dutyManager)->post(route('inventory.requisitions.approve', $requisition))
            ->assertSessionHasErrors('error');

        $this->assertSame('pending', $requisition->fresh()->status);
        $this->assertSame('20.000', $plentifulProduct->quantityOnHand(), 'Nothing should be issued when any single line fails — including lines that had enough stock.');
        $this->assertSame('2.000', $scarceProduct->quantityOnHand());
    }

    public function test_a_non_pending_requisition_cannot_be_approved_twice(): void
    {
        $barStaff = $this->pinUser('bar_staff');
        $dutyManager = $this->userWithRole('duty_manager');
        $department = Department::create(['name' => 'Bar', 'code' => 'BAR', 'is_active' => true]);
        $product = $this->stockedProduct(20);

        $this->actingAs($barStaff)->post(route('inventory.requisitions.store'), [
            'department_id' => $department->id,
            'product_id' => [$product->id],
            'quantity_requested' => [5],
        ]);
        $requisition = \App\Models\StockRequisition::first();
        $this->actingAs($dutyManager)->post(route('inventory.requisitions.approve', $requisition));

        $this->actingAs($dutyManager)->post(route('inventory.requisitions.approve', $requisition))
            ->assertSessionHasErrors('error');
        $this->assertSame('15.000', $product->quantityOnHand(), 'A second approval attempt must not double-issue stock.');
    }

    /**
     * Phase 9.4 fix (routes/web.php, PHASE9_AUDIT_AND_PLAN.md §3.5): before
     * this fix, inventory.requisitions.index was gated only on
     * request-stock, which Duty Manager does not hold — leaving Duty
     * Manager able to POST an approval but never see a page listing any
     * requisition to approve. The index route now also accepts
     * approve-requisition.
     */
    public function test_the_duty_manager_can_reach_the_requisitions_list_to_find_something_to_approve(): void
    {
        $dutyManager = $this->userWithRole('duty_manager');

        $this->actingAs($dutyManager)->get(route('inventory.requisitions.index'))->assertOk();
    }

    public function test_bar_staff_who_only_requests_stock_can_still_reach_the_requisitions_list(): void
    {
        $barStaff = $this->pinUser('bar_staff');

        $this->actingAs($barStaff)->get(route('inventory.requisitions.index'))->assertOk();
    }

    public function test_a_role_with_neither_permission_cannot_reach_the_requisitions_list(): void
    {
        $cashier = $this->userWithRole('cashier');

        $this->actingAs($cashier)->get(route('inventory.requisitions.index'))->assertForbidden();
    }
}
