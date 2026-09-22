<?php

namespace App\Services\Inventory;

use App\Exceptions\HmsException;
use App\Models\Product;
use App\Models\StockRequisition;
use App\Models\StockRequisitionItem;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A department requesting stock out of the central store. Approval is
 * all-or-nothing: either every line can be fully issued right now, or
 * none of them are — a partially-issued requisition would leave the
 * requester unsure what they actually got without a further UI to
 * reconcile it, so the simpler, safer rule is chosen deliberately.
 */
class RequisitionService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly StockService $stock,
    ) {
    }

    /**
     * @param  array<int, array{product_id: int, quantity_requested: float}>  $items
     */
    public function create(int $departmentId, array $items, User $actor, ?string $notes = null): StockRequisition
    {
        if (empty($items)) {
            throw new HmsException('A requisition must have at least one line item.');
        }

        return DB::transaction(function () use ($departmentId, $items, $actor, $notes) {
            $requisition = StockRequisition::create([
                'requisition_number' => $this->generateNumber(),
                'department_id' => $departmentId,
                'status' => 'pending',
                'requested_by' => $actor->id,
                'notes' => $notes,
            ]);

            foreach ($items as $item) {
                StockRequisitionItem::create([
                    'stock_requisition_id' => $requisition->id,
                    'product_id' => $item['product_id'],
                    'quantity_requested' => $item['quantity_requested'],
                ]);
            }

            $this->audit->log('inventory.requisition_created', 'inventory', $requisition, null, $requisition->fresh(['items'])->toArray(), actor: $actor);

            return $requisition->fresh(['items']);
        });
    }

    public function approve(StockRequisition $requisition, User $actor): StockRequisition
    {
        if (! $requisition->isPending()) {
            throw new HmsException("Only a pending requisition can be approved (current status: {$requisition->status}).");
        }

        return DB::transaction(function () use ($requisition, $actor) {
            foreach ($requisition->items as $item) {
                $product = Product::findOrFail($item->product_id);

                // Any single line failing (insufficient stock) rolls the
                // whole transaction back — nothing is issued at all.
                $this->stock->issueStock(
                    product: $product,
                    quantity: (float) $item->quantity_requested,
                    actor: $actor,
                    sourceModule: 'inventory_requisition',
                    type: 'requisition',
                    sourceReference: $requisition->requisition_number,
                );
            }

            $requisition->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]);

            $this->audit->log('inventory.requisition_approved', 'inventory', $requisition, null, $requisition->fresh()->toArray(), actor: $actor);

            return $requisition->fresh();
        });
    }

    public function reject(StockRequisition $requisition, User $actor, string $reason): StockRequisition
    {
        if (! $requisition->isPending()) {
            throw new HmsException("Only a pending requisition can be rejected (current status: {$requisition->status}).");
        }

        $requisition->update(['status' => 'rejected', 'rejection_reason' => $reason]);

        $this->audit->log('inventory.requisition_rejected', 'inventory', $requisition, null, $requisition->toArray(), $reason, $actor);

        return $requisition->fresh();
    }

    public function cancel(StockRequisition $requisition, User $actor, string $reason): StockRequisition
    {
        if (! $requisition->isPending()) {
            throw new HmsException("Only a pending requisition can be cancelled (current status: {$requisition->status}).");
        }

        $requisition->update(['status' => 'cancelled']);

        $this->audit->log('inventory.requisition_cancelled', 'inventory', $requisition, null, $requisition->toArray(), $reason, $actor);

        return $requisition->fresh();
    }

    private function generateNumber(): string
    {
        return 'REQ-'.now()->format('Ymd').'-'.strtoupper(Str::random(5));
    }
}
