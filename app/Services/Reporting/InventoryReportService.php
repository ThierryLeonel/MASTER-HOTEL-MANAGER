<?php

namespace App\Services\Reporting;

use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;

/**
 * Reads directly from the FIFO ledger (`stock_batches`/`stock_movements`)
 * built in Phase 3 — a valuation here is always the real sum of what
 * remains in open batches at their real received cost, never a
 * separately-tracked "current value" column that could drift.
 */
class InventoryReportService
{
    /**
     * @return array{lines: array<int, array{product: Product, quantity_on_hand: string, value: float}>, total_value: float}
     */
    public function stockValuation(): array
    {
        $products = Product::where('is_active', true)->with(['unit', 'batches'])->orderBy('name')->get();

        $lines = [];
        $totalValue = 0.0;

        foreach ($products as $product) {
            $quantity = $product->quantityOnHand();
            $value = (float) $product->batches->sum(fn ($batch) => (float) $batch->quantity_remaining * (float) $batch->unit_cost);

            $lines[] = ['product' => $product, 'quantity_on_hand' => $quantity, 'value' => round($value, 2)];
            $totalValue += $value;
        }

        return ['lines' => $lines, 'total_value' => round($totalValue, 2)];
    }

    /**
     * @return array{by_product: array<int, array{product: Product, quantity: string, cost: float}>, total_cost: float}
     */
    public function wastageCost(Carbon $start, Carbon $end): array
    {
        $rows = StockMovement::where('type', 'wastage')
            ->whereBetween('recorded_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->with('product')
            ->get()
            ->groupBy('product_id');

        $byProduct = [];
        $totalCost = 0.0;

        foreach ($rows as $productId => $movements) {
            $quantity = (string) $movements->sum('quantity');
            $cost = (float) $movements->sum('total_cost');
            $byProduct[] = ['product' => $movements->first()->product, 'quantity' => $quantity, 'cost' => round($cost, 2)];
            $totalCost += $cost;
        }

        return ['by_product' => $byProduct, 'total_cost' => round($totalCost, 2)];
    }

    /**
     * @return \Illuminate\Support\Collection<int, Product>
     */
    public function lowStock()
    {
        return Product::where('is_active', true)->with(['unit', 'batches'])->orderBy('name')->get()
            ->filter(fn (Product $p) => $p->isBelowReorderLevel())
            ->values();
    }
}
