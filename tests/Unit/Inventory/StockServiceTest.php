<?php

namespace Tests\Unit\Inventory;

use App\Exceptions\HmsException;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The core FIFO engine every other inventory workflow builds on. These
 * tests exercise it directly (not through HTTP) since it's a pure
 * service with no controller of its own.
 */
class StockServiceTest extends TestCase
{
    use RefreshDatabase;

    private function product(): Product
    {
        $unit = Unit::factory()->create(['abbreviation' => 'btl']);

        return Product::factory()->create(['unit_id' => $unit->id, 'reorder_level' => 5]);
    }

    public function test_issuing_stock_consumes_the_oldest_batch_first_and_computes_correct_cost(): void
    {
        $service = app(StockService::class);
        $actor = User::factory()->create();
        $product = $this->product();

        $oldBatch = $service->receiveStock($product, 10, 100, $actor, 'test', receivedAt: now()->subDays(2));
        $newBatch = $service->receiveStock($product, 10, 150, $actor, 'test', receivedAt: now());

        $result = $service->issueStock($product, 15, $actor, 'test');

        $this->assertSame(1750.0, $result['total_cost']); // 10 @ 100 + 5 @ 150
        $this->assertCount(2, $result['movements']);

        $this->assertSame('0.000', $oldBatch->fresh()->quantity_remaining);
        $this->assertSame('5.000', $newBatch->fresh()->quantity_remaining);
        $this->assertSame('5.000', $product->quantityOnHand());

        $this->assertDatabaseHas('stock_movements', [
            'stock_batch_id' => $oldBatch->id, 'type' => 'issue', 'direction' => 'out', 'quantity' => '10.000',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'stock_batch_id' => $newBatch->id, 'type' => 'issue', 'direction' => 'out', 'quantity' => '5.000',
        ]);
    }

    public function test_issuing_more_than_is_on_hand_throws_and_changes_nothing(): void
    {
        $service = app(StockService::class);
        $actor = User::factory()->create();
        $product = $this->product();

        $batch = $service->receiveStock($product, 5, 100, $actor, 'test');

        $this->expectException(HmsException::class);

        try {
            $service->issueStock($product, 10, $actor, 'test');
        } finally {
            $this->assertSame('5.000', $batch->fresh()->quantity_remaining, 'A failed issue must not partially consume a batch.');
            $this->assertSame(0, StockMovement::where('type', 'issue')->count());
        }
    }

    public function test_receiving_creates_a_real_batch_and_movement(): void
    {
        $service = app(StockService::class);
        $actor = User::factory()->create();
        $product = $this->product();

        $batch = $service->receiveStock($product, 20, 250, $actor, 'test', sourceReference: 'GR-TEST-1');

        $this->assertSame('20.000', $batch->quantity_remaining);
        $this->assertDatabaseHas('stock_movements', [
            'stock_batch_id' => $batch->id, 'type' => 'receipt', 'direction' => 'in',
            'quantity' => '20.000', 'unit_cost' => '250.00', 'total_cost' => '5000.00',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'inventory.stock_received']);
    }
}
