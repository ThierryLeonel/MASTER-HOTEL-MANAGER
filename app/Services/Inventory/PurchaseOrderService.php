<?php

namespace App\Services\Inventory;

use App\Exceptions\HmsException;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Purchase order lifecycle: draft -> submitted -> approved -> received
 * (or rejected/cancelled). Nothing here touches stock quantities — that
 * only happens on actual receiving, via GoodsReceiptService +
 * StockService, because a PO is a plan/commitment, not a stock fact.
 */
class PurchaseOrderService
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * @param  array<int, array{product_id: int, quantity_ordered: float, unit_cost: float}>  $items
     */
    public function create(int $supplierId, array $items, User $actor, ?string $notes = null): PurchaseOrder
    {
        if (empty($items)) {
            throw new HmsException('A purchase order must have at least one line item.');
        }

        return DB::transaction(function () use ($supplierId, $items, $actor, $notes) {
            $po = PurchaseOrder::create([
                'po_number' => $this->generateNumber(),
                'supplier_id' => $supplierId,
                'status' => 'draft',
                'ordered_by' => $actor->id,
                'notes' => $notes,
            ]);

            foreach ($items as $item) {
                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'product_id' => $item['product_id'],
                    'quantity_ordered' => $item['quantity_ordered'],
                    'unit_cost' => $item['unit_cost'],
                    'line_total' => round($item['quantity_ordered'] * $item['unit_cost'], 2),
                ]);
            }

            $this->audit->log('inventory.po_created', 'inventory', $po, null, $po->fresh(['items'])->toArray(), actor: $actor);

            return $po->fresh(['items']);
        });
    }

    public function submit(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        if ($po->status !== 'draft') {
            throw new HmsException("Only a draft purchase order can be submitted (current status: {$po->status}).");
        }

        if ($po->items()->doesntExist()) {
            throw new HmsException('Cannot submit a purchase order with no line items.');
        }

        $po->update(['status' => 'submitted', 'submitted_at' => now()]);

        $this->audit->log('inventory.po_submitted', 'inventory', $po, null, $po->toArray(), actor: $actor);

        return $po->fresh();
    }

    public function approve(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        if ($po->status !== 'submitted') {
            throw new HmsException("Only a submitted purchase order can be approved (current status: {$po->status}).");
        }

        $po->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]);

        $this->audit->log('inventory.po_approved', 'inventory', $po, null, $po->toArray(), actor: $actor);

        return $po->fresh();
    }

    public function reject(PurchaseOrder $po, User $actor, string $reason): PurchaseOrder
    {
        if ($po->status !== 'submitted') {
            throw new HmsException("Only a submitted purchase order can be rejected (current status: {$po->status}).");
        }

        $po->update(['status' => 'rejected', 'rejection_reason' => $reason]);

        $this->audit->log('inventory.po_rejected', 'inventory', $po, null, $po->toArray(), $reason, $actor);

        return $po->fresh();
    }

    public function cancel(PurchaseOrder $po, User $actor, string $reason): PurchaseOrder
    {
        if (! in_array($po->status, ['draft', 'submitted'], true)) {
            throw new HmsException("Cannot cancel a purchase order that is already {$po->status}.");
        }

        $po->update(['status' => 'cancelled']);

        $this->audit->log('inventory.po_cancelled', 'inventory', $po, null, $po->toArray(), $reason, $actor);

        return $po->fresh();
    }

    private function generateNumber(): string
    {
        return 'PO-'.now()->format('Ymd').'-'.strtoupper(Str::random(5));
    }
}
