<?php

namespace App\Services\Inventory;

use App\Exceptions\HmsException;
use App\Models\Product;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A physical stock count. Starting one snapshots each product's live
 * system quantity (StockCountItem.system_quantity) at that instant, so
 * the variance computed later reflects what the system said *at count
 * time*, unchanged by any stock movement that happens to land while
 * the count is in progress. Completing the count posts one FIFO
 * adjustment per line with a non-zero variance — never a raw column
 * overwrite — so the physical count still leaves a real, costed audit
 * trail rather than silently resetting a number.
 */
class StockCountService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly StockService $stock,
    ) {
    }

    /**
     * @param  array<int, int>  $productIds
     */
    public function start(array $productIds, User $actor, ?string $notes = null): StockCount
    {
        if (empty($productIds)) {
            throw new HmsException('Select at least one product to count.');
        }

        return DB::transaction(function () use ($productIds, $actor, $notes) {
            $count = StockCount::create([
                'count_number' => $this->generateNumber(),
                'status' => 'in_progress',
                'started_by' => $actor->id,
                'started_at' => now(),
                'notes' => $notes,
            ]);

            foreach (Product::whereIn('id', $productIds)->get() as $product) {
                StockCountItem::create([
                    'stock_count_id' => $count->id,
                    'product_id' => $product->id,
                    'system_quantity' => $product->quantityOnHand(),
                ]);
            }

            $this->audit->log('inventory.stock_count_started', 'inventory', $count, null, $count->fresh(['items'])->toArray(), actor: $actor);

            return $count->fresh(['items']);
        });
    }

    public function recordCount(StockCountItem $item, float $countedQuantity, User $actor): StockCountItem
    {
        if (! $item->stockCount->isInProgress()) {
            throw new HmsException('This stock count is no longer in progress.');
        }

        $variance = round($countedQuantity - (float) $item->system_quantity, 3);

        $item->update(['counted_quantity' => $countedQuantity, 'variance' => $variance]);

        $this->audit->log('inventory.stock_count_line_recorded', 'inventory', $item, null, $item->fresh()->toArray(), actor: $actor);

        return $item->fresh();
    }

    /**
     * Posts a real FIFO stock_movement (adjustment_in via receiveStock,
     * or adjustment_out via issueStock) for every line whose counted
     * quantity differs from the system quantity, then locks the count.
     */
    public function complete(StockCount $count, User $actor): StockCount
    {
        if (! $count->isInProgress()) {
            throw new HmsException("Only an in-progress stock count can be completed (current status: {$count->status}).");
        }

        if ($count->items()->whereNull('counted_quantity')->exists()) {
            throw new HmsException('Every line must be counted before the stock count can be finalized.');
        }

        return DB::transaction(function () use ($count, $actor) {
            foreach ($count->items as $item) {
                $variance = (float) $item->variance;

                if (abs($variance) < 0.0005) {
                    $item->update(['variance_value' => 0]);
                    continue;
                }

                $product = Product::findOrFail($item->product_id);
                $unitCost = $this->stock->averageUnitCost($product);

                if ($variance > 0) {
                    $this->stock->receiveStock(
                        product: $product,
                        quantity: $variance,
                        unitCost: $unitCost,
                        actor: $actor,
                        sourceModule: 'inventory_stock_count',
                        source: $item,
                        sourceReference: $count->count_number,
                        notes: 'Positive variance found during stock count.',
                    );
                } else {
                    $this->stock->issueStock(
                        product: $product,
                        quantity: abs($variance),
                        actor: $actor,
                        sourceModule: 'inventory_stock_count',
                        type: 'adjustment',
                        sourceReference: $count->count_number,
                        notes: 'Negative variance found during stock count.',
                    );
                }

                $item->update(['variance_value' => round($variance * $unitCost, 2)]);
            }

            $count->update(['status' => 'completed', 'completed_by' => $actor->id, 'completed_at' => now()]);

            $this->audit->log('inventory.stock_count_completed', 'inventory', $count, null, $count->fresh()->toArray(), actor: $actor);

            return $count->fresh();
        });
    }

    private function generateNumber(): string
    {
        return 'SC-'.now()->format('Ymd').'-'.strtoupper(Str::random(5));
    }
}
