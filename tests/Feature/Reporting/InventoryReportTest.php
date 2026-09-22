<?php

namespace Tests\Feature\Reporting;

use App\Models\Product;
use App\Models\Unit;
use App\Services\Inventory\StockService;
use App\Services\Inventory\WastageService;
use App\Services\Reporting\InventoryReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class InventoryReportTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    private function stockedProduct(float $quantity, float $cost, float $reorderLevel = 5): Product
    {
        $unit = Unit::factory()->create();
        $product = Product::factory()->create(['unit_id' => $unit->id, 'reorder_level' => $reorderLevel]);

        app(StockService::class)->receiveStock($product, $quantity, $cost, $this->userWithRole('owner'), 'test');

        return $product->fresh();
    }

    public function test_stock_valuation_matches_real_fifo_batches(): void
    {
        $product = $this->stockedProduct(20, 500);

        $report = app(InventoryReportService::class)->stockValuation();

        $line = collect($report['lines'])->firstWhere('product.id', $product->id);
        $this->assertNotNull($line);
        $this->assertSame('20.000', $line['quantity_on_hand']);
        $this->assertSame(10000.0, $line['value']);
        $this->assertSame(10000.0, $report['total_value']);
    }

    public function test_wastage_cost_only_counts_approved_wastage(): void
    {
        $product = $this->stockedProduct(20, 500);
        $barStaff = $this->pinUser('bar_staff');
        $dutyManager = $this->userWithRole('duty_manager');

        app(WastageService::class)->record($product->id, 3, 'Broken', $barStaff);
        $wastage = \App\Models\StockWastage::first();
        app(WastageService::class)->approve($wastage, $dutyManager);

        // A second, still-pending wastage must not be counted yet.
        app(WastageService::class)->record($product->id, 2, 'Disputed', $barStaff);

        $report = app(InventoryReportService::class)->wastageCost(Carbon::today(), Carbon::today());

        $this->assertSame(1500.0, $report['total_cost']);
    }

    public function test_low_stock_lists_only_products_below_their_reorder_level(): void
    {
        $low = $this->stockedProduct(2, 500, reorderLevel: 5);
        $healthy = $this->stockedProduct(20, 500, reorderLevel: 5);

        $result = app(InventoryReportService::class)->lowStock();

        $this->assertTrue($result->contains('id', $low->id));
        $this->assertFalse($result->contains('id', $healthy->id));
    }

    public function test_bar_staff_cannot_view_inventory_reports(): void
    {
        $bar = $this->pinUser('bar_staff');

        $this->actingAs($bar)->get(route('reports.inventory'))->assertForbidden();
    }

    public function test_duty_manager_can_view_inventory_reports(): void
    {
        $dutyManager = $this->userWithRole('duty_manager');

        $this->actingAs($dutyManager)->get(route('reports.inventory'))->assertOk();
    }
}
