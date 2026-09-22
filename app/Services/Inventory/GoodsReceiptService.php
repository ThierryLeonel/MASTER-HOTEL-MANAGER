<?php

namespace App\Services\Inventory;

use App\Exceptions\HmsException;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns a physical delivery into real stock: one GoodsReceipt (+ lines)
 * and, for every line, one FIFO stock_batch via StockService. This is
 * the only place (besides a positive stock-count adjustment) new stock
 * enters the system.
 *
 * Either tied to an approved PO (normal path — supports partial
 * deliveries across several receipts, tracked via
 * purchase_order_items.quantity_received) or completely ad-hoc for a
 * small cash purchase that never had a formal PO.
 */
class GoodsReceiptService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly StockService $stock,
    ) {
    }

    /**
     * @param  array<int, array{product_id: int, quantity_received: float, unit_cost: float, purchase_order_item_id?: int|null}>  $items
     */
    public function receive(
        array $items,
        User $actor,
        ?PurchaseOrder $purchaseOrder = null,
        ?int $supplierId = null,
        ?string $notes = null,
    ): GoodsReceipt {
        if (empty($items)) {
            throw new HmsException('A goods receipt must have at least one line item.');
        }

        if ($purchaseOrder && $purchaseOrder->status !== 'approved') {
            throw new HmsException("Cannot receive against a purchase order that is not approved (current status: {$purchaseOrder->status}).");
        }

        return DB::transaction(function () use ($items, $actor, $purchaseOrder, $supplierId, $notes) {
            $receipt = GoodsReceipt::create([
                'receipt_number' => $this->generateNumber(),
                'purchase_order_id' => $purchaseOrder?->id,
                'supplier_id' => $supplierId ?? $purchaseOrder?->supplier_id,
                'received_by' => $actor->id,
                'received_at' => now(),
                'notes' => $notes,
            ]);

            foreach ($items as $item) {
                $product = Product::findOrFail($item['product_id']);
                $poItem = ! empty($item['purchase_order_item_id'])
                    ? PurchaseOrderItem::findOrFail($item['purchase_order_item_id'])
                    : null;

                // Line-ownership invariant: a purchase_order_item_id must
                // belong to the purchase order this receipt is tied to.
                // Enforced here too (not just in the controller) so a
                // direct service call can't receive a line from a
                // different PO against this one before any stock/quantity
                // side effect happens.
                if ($poItem && $purchaseOrder && (int) $poItem->purchase_order_id !== (int) $purchaseOrder->id) {
                    throw new HmsException("Line item does not belong to purchase order {$purchaseOrder->po_number}.");
                }

                if ($poItem) {
                    $outstanding = (float) $poItem->quantityOutstanding();

                    if ($item['quantity_received'] > $outstanding + 0.0005) {
                        throw new HmsException(
                            "Cannot receive {$item['quantity_received']} of {$product->name} — only {$outstanding} is still outstanding on {$poItem->purchaseOrder->po_number}."
                        );
                    }
                }

                $receiptItem = GoodsReceiptItem::create([
                    'goods_receipt_id' => $receipt->id,
                    'purchase_order_item_id' => $poItem?->id,
                    'product_id' => $product->id,
                    'quantity_received' => $item['quantity_received'],
                    'unit_cost' => $item['unit_cost'],
                    'line_total' => round($item['quantity_received'] * $item['unit_cost'], 2),
                ]);

                $this->stock->receiveStock(
                    product: $product,
                    quantity: (float) $item['quantity_received'],
                    unitCost: (float) $item['unit_cost'],
                    actor: $actor,
                    sourceModule: 'inventory_goods_receipt',
                    source: $receiptItem,
                    sourceReference: $receipt->receipt_number,
                );

                if ($poItem) {
                    $poItem->increment('quantity_received', $item['quantity_received']);
                }
            }

            if ($purchaseOrder) {
                $purchaseOrder->refresh();

                if ($purchaseOrder->isFullyReceived()) {
                    $purchaseOrder->update(['status' => 'received', 'received_at' => now()]);
                }
            }

            $this->audit->log('inventory.goods_received', 'inventory', $receipt, null, $receipt->fresh(['items'])->toArray(), actor: $actor);

            return $receipt->fresh(['items']);
        });
    }

    private function generateNumber(): string
    {
        return 'GR-'.now()->format('Ymd').'-'.strtoupper(Str::random(5));
    }
}
