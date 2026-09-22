<?php

namespace App\Services\Inventory;

use App\Exceptions\HmsException;
use App\Models\Product;
use App\Models\StockWastage;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Recording wastage never by itself changes stock on hand — only
 * approval does (same approval-gate shape as MaintenanceTicket in
 * Phase 1). This means a mis-keyed or disputed wastage report never
 * has to be reversed; it's simply rejected and stock was never touched.
 */
class WastageService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly StockService $stock,
    ) {
    }

    public function record(int $productId, float $quantity, string $reason, User $actor, ?int $departmentId = null): StockWastage
    {
        if ($quantity <= 0) {
            throw new HmsException('Wastage quantity must be greater than zero.');
        }

        $wastage = StockWastage::create([
            'wastage_number' => $this->generateNumber(),
            'product_id' => $productId,
            'department_id' => $departmentId,
            'quantity' => $quantity,
            'reason' => $reason,
            'status' => 'pending',
            'recorded_by' => $actor->id,
            'recorded_at' => now(),
        ]);

        $this->audit->log('inventory.wastage_recorded', 'inventory', $wastage, null, $wastage->toArray(), actor: $actor);

        return $wastage;
    }

    public function approve(StockWastage $wastage, User $actor): StockWastage
    {
        if (! $wastage->isPending()) {
            throw new HmsException("Only a pending wastage record can be approved (current status: {$wastage->status}).");
        }

        return DB::transaction(function () use ($wastage, $actor) {
            $product = Product::findOrFail($wastage->product_id);

            $this->stock->issueStock(
                product: $product,
                quantity: (float) $wastage->quantity,
                actor: $actor,
                sourceModule: 'inventory_wastage',
                type: 'wastage',
                sourceReference: $wastage->wastage_number,
                notes: $wastage->reason,
            );

            $wastage->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]);

            $this->audit->log('inventory.wastage_approved', 'inventory', $wastage, null, $wastage->fresh()->toArray(), actor: $actor);

            return $wastage->fresh();
        });
    }

    public function reject(StockWastage $wastage, User $actor, string $reason): StockWastage
    {
        if (! $wastage->isPending()) {
            throw new HmsException("Only a pending wastage record can be rejected (current status: {$wastage->status}).");
        }

        $wastage->update(['status' => 'rejected', 'rejection_reason' => $reason]);

        $this->audit->log('inventory.wastage_rejected', 'inventory', $wastage, null, $wastage->toArray(), $reason, $actor);

        return $wastage->fresh();
    }

    private function generateNumber(): string
    {
        return 'WST-'.now()->format('Ymd').'-'.strtoupper(Str::random(5));
    }
}
