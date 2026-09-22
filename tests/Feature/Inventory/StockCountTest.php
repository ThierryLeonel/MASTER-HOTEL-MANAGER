<?php

namespace Tests\Feature\Inventory;

use App\Models\Product;
use App\Models\Unit;
use App\Services\Inventory\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class StockCountTest extends TestCase
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

    public function test_starting_a_count_snapshots_the_live_system_quantity(): void
    {
        $dutyManager = $this->userWithRole('duty_manager');
        $product = $this->stockedProduct(20);

        $this->actingAs($dutyManager)->post(route('inventory.stock-counts.store'), [
            'product_id' => [$product->id],
        ])->assertRedirect();

        $this->assertDatabaseHas('stock_count_items', ['product_id' => $product->id, 'system_quantity' => '20.000']);
    }

    public function test_completing_a_count_posts_a_positive_variance_as_a_real_adjustment_batch(): void
    {
        $dutyManager = $this->userWithRole('duty_manager');
        $product = $this->stockedProduct(20, 100);

        $this->actingAs($dutyManager)->post(route('inventory.stock-counts.store'), ['product_id' => [$product->id]]);
        $count = \App\Models\StockCount::first();
        $item = $count->items()->first();

        $this->actingAs($dutyManager)->post(route('inventory.stock-counts.items.record', [$count, $item]), [
            'counted_quantity' => 25,
        ])->assertRedirect();

        $this->actingAs($dutyManager)->post(route('inventory.stock-counts.complete', $count))->assertRedirect();

        $this->assertSame('completed', $count->fresh()->status);
        $this->assertSame('25.000', $product->quantityOnHand());
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id, 'type' => 'adjustment', 'direction' => 'in', 'quantity' => '5.000',
        ]);
        $this->assertSame('500.00', $item->fresh()->variance_value); // 5 extra @ last known cost 100
    }

    public function test_completing_a_count_posts_a_negative_variance_as_a_real_fifo_issue(): void
    {
        $dutyManager = $this->userWithRole('duty_manager');
        $product = $this->stockedProduct(20, 100);

        $this->actingAs($dutyManager)->post(route('inventory.stock-counts.store'), ['product_id' => [$product->id]]);
        $count = \App\Models\StockCount::first();
        $item = $count->items()->first();

        $this->actingAs($dutyManager)->post(route('inventory.stock-counts.items.record', [$count, $item]), [
            'counted_quantity' => 15,
        ]);
        $this->actingAs($dutyManager)->post(route('inventory.stock-counts.complete', $count));

        $this->assertSame('15.000', $product->quantityOnHand());
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id, 'type' => 'adjustment', 'direction' => 'out', 'quantity' => '5.000',
        ]);
    }

    public function test_cannot_complete_a_count_with_uncounted_lines(): void
    {
        $dutyManager = $this->userWithRole('duty_manager');
        $product = $this->stockedProduct(20);

        $this->actingAs($dutyManager)->post(route('inventory.stock-counts.store'), ['product_id' => [$product->id]]);
        $count = \App\Models\StockCount::first();

        $this->actingAs($dutyManager)->post(route('inventory.stock-counts.complete', $count))
            ->assertSessionHasErrors('error');
        $this->assertSame('in_progress', $count->fresh()->status);
    }
}
