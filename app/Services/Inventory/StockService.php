<?php

namespace App\Services\Inventory;

use App\Exceptions\HmsException;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The single engine for every stock-quantity change in the system
 * (spec section 15 — FIFO costing). Nothing else is allowed to insert
 * into stock_batches or stock_movements directly: Purchase Receiving,
 * Requisitions, Stock Counts, Wastage, and POS recipe consumption all
 * go through receiveStock()/issueStock() here, so "what did this unit
 * of stock actually cost" always has exactly one true answer, traceable
 * to a real batch.
 *
 * FIFO: issueStock() always consumes the oldest still-open batch first
 * (ordered by received_at, then id as a tiebreaker for same-instant
 * receipts). This is the industry-standard costing assumption and the
 * one explicitly required by the spec — it is not configurable, because
 * a system that let the costing method vary per call would make the
 * "true cost of goods" figure meaningless.
 */
class StockService
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function receiveStock(
        Product $product,
        float $quantity,
        float $unitCost,
        User $actor,
        string $sourceModule,
        ?Model $source = null,
        ?string $sourceReference = null,
        ?\DateTimeInterface $receivedAt = null,
        ?string $notes = null,
    ): StockBatch {
        if ($quantity <= 0) {
            throw new HmsException('Quantity received must be greater than zero.');
        }

        return DB::transaction(function () use ($product, $quantity, $unitCost, $actor, $sourceModule, $source, $sourceReference, $receivedAt, $notes) {
            $batch = StockBatch::create([
                'product_id' => $product->id,
                'quantity_received' => $quantity,
                'quantity_remaining' => $quantity,
                'unit_cost' => $unitCost,
                'received_at' => $receivedAt ?? now(),
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
            ]);

            $movement = StockMovement::create([
                'product_id' => $product->id,
                'stock_batch_id' => $batch->id,
                'type' => 'receipt',
                'direction' => 'in',
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'total_cost' => round($quantity * $unitCost, 2),
                'source_module' => $sourceModule,
                'source_reference' => $sourceReference,
                'recorded_by' => $actor->id,
                'recorded_at' => now(),
                'notes' => $notes,
            ]);

            $this->audit->log('inventory.stock_received', $sourceModule, $movement, null, $movement->toArray(), actor: $actor);

            return $batch;
        });
    }

    /**
     * Consume `$quantity` of `$product` from the oldest open batches
     * first. Throws if total stock on hand is insufficient — this
     * service never allows stock to go negative, so every module that
     * calls it must be prepared to catch HmsException and surface it as
     * a real business-rule stop, not silently let a sale/issue happen
     * against stock that doesn't exist.
     *
     * @return array{total_cost: float, movements: StockMovement[]}
     */
    public function issueStock(
        Product $product,
        float $quantity,
        User $actor,
        string $sourceModule,
        string $type = 'issue',
        ?string $sourceReference = null,
        ?string $notes = null,
    ): array {
        if ($quantity <= 0) {
            throw new HmsException('Quantity to issue must be greater than zero.');
        }

        return DB::transaction(function () use ($product, $quantity, $actor, $sourceModule, $type, $sourceReference, $notes) {
            $batches = StockBatch::where('product_id', $product->id)
                ->where('quantity_remaining', '>', 0)
                ->orderBy('received_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $available = $batches->sum('quantity_remaining');

            if (bccomp((string) $available, (string) $quantity, 3) < 0) {
                throw new HmsException(
                    "Insufficient stock for {$product->name}: {$available} {$product->unit->abbreviation} on hand, {$quantity} requested."
                );
            }

            $remainingToIssue = $quantity;
            $totalCost = 0.0;
            $movements = [];

            foreach ($batches as $batch) {
                if ($remainingToIssue <= 0) {
                    break;
                }

                $takeFromBatch = min((float) $batch->quantity_remaining, $remainingToIssue);

                $batch->decrement('quantity_remaining', $takeFromBatch);

                $lineCost = round($takeFromBatch * (float) $batch->unit_cost, 2);
                $totalCost += $lineCost;

                $movements[] = StockMovement::create([
                    'product_id' => $product->id,
                    'stock_batch_id' => $batch->id,
                    'type' => $type,
                    'direction' => 'out',
                    'quantity' => $takeFromBatch,
                    'unit_cost' => $batch->unit_cost,
                    'total_cost' => $lineCost,
                    'source_module' => $sourceModule,
                    'source_reference' => $sourceReference,
                    'recorded_by' => $actor->id,
                    'recorded_at' => now(),
                    'notes' => $notes,
                ]);

                $remainingToIssue = round($remainingToIssue - $takeFromBatch, 3);
            }

            foreach ($movements as $movement) {
                $this->audit->log("inventory.stock_{$type}", $sourceModule, $movement, null, $movement->toArray(), actor: $actor);
            }

            return ['total_cost' => round($totalCost, 2), 'movements' => $movements];
        });
    }

    public function averageUnitCost(Product $product): float
    {
        $latestBatch = StockBatch::where('product_id', $product->id)
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->first();

        return $latestBatch ? (float) $latestBatch->unit_cost : 0.0;
    }
}
